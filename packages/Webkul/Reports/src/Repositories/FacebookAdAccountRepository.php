<?php

namespace Webkul\Reports\Repositories;

use Webkul\Reports\Models\FacebookAdAccount;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class FacebookAdAccountRepository
{
    /**
     * Obtém todas as contas de anúncios
     */
    public function getAll($filters = [])
    {
        $query = FacebookAdAccount::query();
        
        if (isset($filters['status'])) {
            $query->byStatus($filters['status']);
        }
        
        if (isset($filters['country'])) {
            $query->byCountry($filters['country']);
        }
        
        if (isset($filters['currency'])) {
            $query->byCurrency($filters['currency']);
        }
        
        if (isset($filters['owner_id'])) {
            $query->byOwner($filters['owner_id']);
        }
        
        if (isset($filters['active_only']) && $filters['active_only']) {
            $query->active();
        }
        
        return $query->orderBy('created_time', 'desc')->get();
    }

    /**
     * Obtém contas ativas
     */
    public function getActiveAccounts()
    {
        return FacebookAdAccount::active()->orderBy('name')->get();
    }

    /**
     * Obtém contas por país
     */
    public function getAccountsByCountry($countryCode)
    {
        return FacebookAdAccount::byCountry($countryCode)->active()->get();
    }

    /**
     * Obtém contas por proprietário
     */
    public function getAccountsByOwner($ownerId)
    {
        return FacebookAdAccount::byOwner($ownerId)->orderBy('created_time', 'desc')->get();
    }

    /**
     * Obtém estatísticas das contas
     */
    public function getAccountStats()
    {
        return FacebookAdAccount::selectRaw('
            COUNT(*) as total_accounts,
            COUNT(CASE WHEN account_status = 1 THEN 1 END) as active_accounts,
            COUNT(CASE WHEN account_status = 2 THEN 1 END) as paused_accounts,
            COUNT(CASE WHEN balance > 0 THEN 1 END) as accounts_with_balance,
            SUM(amount_spent) as total_amount_spent,
            SUM(balance) as total_balance,
            AVG(age) as average_account_age
        ')->first();
    }

    /**
     * Obtém contas com maior gasto
     */
    public function getTopSpendingAccounts($limit = 10)
    {
        return FacebookAdAccount::select('facebook_id', 'name', 'amount_spent', 'currency', 'account_status')
            ->where('amount_spent', '>', 0)
            ->orderBy('amount_spent', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Obtém contas com saldo baixo
     */
    public function getLowBalanceAccounts($threshold = 1000)
    {
        return FacebookAdAccount::select('facebook_id', 'name', 'balance', 'currency', 'account_status')
            ->where('balance', '<', $threshold)
            ->where('balance', '>', 0)
            ->orderBy('balance', 'asc')
            ->get();
    }

    /**
     * Obtém contas próximas do limite de gastos
     */
    public function getAccountsNearSpendCap($percentage = 80)
    {
        return FacebookAdAccount::select('facebook_id', 'name', 'amount_spent', 'spend_cap', 'currency')
            ->where('spend_cap', '>', 0)
            ->whereRaw('(amount_spent / spend_cap * 100) >= ?', [$percentage])
            ->orderByRaw('(amount_spent / spend_cap * 100) DESC')
            ->get();
    }

    /**
     * Obtém contas por capacidade
     */
    public function getAccountsByCapability($capability)
    {
        return FacebookAdAccount::whereJsonContains('capabilities', $capability)
            ->active()
            ->get();
    }

    /**
     * Obtém contas criadas em um período específico
     */
    public function getAccountsCreatedBetween($startDate, $endDate)
    {
        return FacebookAdAccount::whereBetween('created_time', [$startDate, $endDate])
            ->orderBy('created_time', 'desc')
            ->get();
    }

    /**
     * Obtém contas por moeda
     */
    public function getAccountsByCurrency($currency)
    {
        return FacebookAdAccount::byCurrency($currency)->active()->get();
    }

    /**
     * Obtém estatísticas por país
     */
    public function getStatsByCountry()
    {
        return FacebookAdAccount::selectRaw('
            business_country_code,
            COUNT(*) as total_accounts,
            COUNT(CASE WHEN account_status = 1 THEN 1 END) as active_accounts,
            SUM(amount_spent) as total_amount_spent,
            AVG(amount_spent) as average_amount_spent
        ')
        ->whereNotNull('business_country_code')
        ->groupBy('business_country_code')
        ->orderBy('total_accounts', 'desc')
        ->get();
    }

    /**
     * Obtém estatísticas por moeda
     */
    public function getStatsByCurrency()
    {
        return FacebookAdAccount::selectRaw('
            currency,
            COUNT(*) as total_accounts,
            COUNT(CASE WHEN account_status = 1 THEN 1 END) as active_accounts,
            SUM(amount_spent) as total_amount_spent,
            SUM(balance) as total_balance
        ')
        ->whereNotNull('currency')
        ->groupBy('currency')
        ->orderBy('total_amount_spent', 'desc')
        ->get();
    }

    /**
     * Obtém contas que precisam de atenção
     */
    public function getAccountsNeedingAttention()
    {
        return FacebookAdAccount::where(function ($query) {
            $query->where('account_status', '!=', 1)
                  ->orWhere('balance', '<', 1000)
                  ->orWhereRaw('(amount_spent / spend_cap * 100) >= 90');
        })
        ->orderBy('account_status')
        ->orderBy('balance', 'asc')
        ->get();
    }

    /**
     * Salva ou atualiza uma conta
     */
    public function saveAccount($data)
    {
        $conditions = [
            'facebook_id' => $data['facebook_id'],
        ];
        
        return FacebookAdAccount::updateOrCreate($conditions, $data);
    }

    /**
     * Atualiza o saldo de uma conta
     */
    public function updateBalance($facebookId, $newBalance)
    {
        return FacebookAdAccount::where('facebook_id', $facebookId)
            ->update(['balance' => $newBalance]);
    }

    /**
     * Atualiza o gasto de uma conta
     */
    public function updateAmountSpent($facebookId, $newAmountSpent)
    {
        return FacebookAdAccount::where('facebook_id', $facebookId)
            ->update(['amount_spent' => $newAmountSpent]);
    }

    /**
     * Atualiza o status de uma conta
     */
    public function updateStatus($facebookId, $newStatus)
    {
        return FacebookAdAccount::where('facebook_id', $facebookId)
            ->update(['account_status' => $newStatus]);
    }

    /**
     * Remove contas antigas ou inativas
     */
    public function cleanOldAccounts($daysToKeep = 365)
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);
        
        return FacebookAdAccount::where('created_time', '<', $cutoffDate)
            ->where('account_status', '!=', 1)
            ->delete();
    }

    /**
     * Obtém contas com informações completas
     */
    public function getAccountsWithFullInfo()
    {
        return FacebookAdAccount::select('*')
            ->with('googleAdsReports')
            ->orderBy('name')
            ->get();
    }
} 