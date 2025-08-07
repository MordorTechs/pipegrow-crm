<?php

namespace Webkul\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FacebookAdAccount extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'facebook_ad_accounts';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'facebook_id',
        'target',
        'account_id',
        'account_status',
        'age',
        'amount_spent',
        'balance',
        'business_city',
        'business_country_code',
        'business_name',
        'business_state',
        'business_street',
        'business_street2',
        'business_zip',
        'capabilities',
        'created_time',
        'currency',
        'min_campaign_group_spend_cap',
        'name',
        'offsite_pixels_tos_accepted',
        'owner_id',
        'spend_cap',
        'timezone_id',
        'timezone_name',
        'timezone_offset_hours_utc',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'created_time' => 'datetime',
        'capabilities' => 'array',
        'offsite_pixels_tos_accepted' => 'boolean',
        'amount_spent' => 'integer',
        'balance' => 'integer',
        'min_campaign_group_spend_cap' => 'integer',
        'spend_cap' => 'integer',
        'account_status' => 'integer',
        'age' => 'integer',
        'timezone_id' => 'integer',
        'timezone_offset_hours_utc' => 'decimal:1',
    ];

    /**
     * Scope para filtrar por status da conta
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('account_status', $status);
    }

    /**
     * Scope para filtrar por país
     */
    public function scopeByCountry($query, $countryCode)
    {
        return $query->where('business_country_code', $countryCode);
    }

    /**
     * Scope para filtrar por moeda
     */
    public function scopeByCurrency($query, $currency)
    {
        return $query->where('currency', $currency);
    }

    /**
     * Scope para filtrar por proprietário
     */
    public function scopeByOwner($query, $ownerId)
    {
        return $query->where('owner_id', $ownerId);
    }

    /**
     * Scope para contas ativas
     */
    public function scopeActive($query)
    {
        return $query->where('account_status', 1);
    }

    /**
     * Scope para contas pausadas
     */
    public function scopePaused($query)
    {
        return $query->where('account_status', 2);
    }

    /**
     * Converte amount_spent de centavos para valor monetário
     */
    public function getAmountSpentFormattedAttribute()
    {
        return $this->amount_spent ? $this->amount_spent / 100 : 0;
    }

    /**
     * Converte balance de centavos para valor monetário
     */
    public function getBalanceFormattedAttribute()
    {
        return $this->balance ? $this->balance / 100 : 0;
    }

    /**
     * Converte spend_cap de centavos para valor monetário
     */
    public function getSpendCapFormattedAttribute()
    {
        return $this->spend_cap ? $this->spend_cap / 100 : 0;
    }

    /**
     * Converte min_campaign_group_spend_cap de centavos para valor monetário
     */
    public function getMinCampaignGroupSpendCapFormattedAttribute()
    {
        return $this->min_campaign_group_spend_cap ? $this->min_campaign_group_spend_cap / 100 : 0;
    }

    /**
     * Obtém o status da conta em formato legível
     */
    public function getStatusTextAttribute()
    {
        $statuses = [
            1 => 'Ativa',
            2 => 'Pausada',
            3 => 'Desabilitada',
            4 => 'Pendente',
            5 => 'Rejeitada',
            6 => 'Em Revisão',
            7 => 'Suspensa',
            8 => 'Removida',
        ];

        return $statuses[$this->account_status] ?? 'Desconhecido';
    }

    /**
     * Verifica se a conta está ativa
     */
    public function getIsActiveAttribute()
    {
        return $this->account_status === 1;
    }

    /**
     * Verifica se a conta tem saldo suficiente
     */
    public function getHasBalanceAttribute()
    {
        return $this->balance > 0;
    }

    /**
     * Calcula o percentual de uso do limite de gastos
     */
    public function getSpendCapUsagePercentageAttribute()
    {
        if (!$this->spend_cap || $this->spend_cap <= 0) {
            return 0;
        }

        return ($this->amount_spent / $this->spend_cap) * 100;
    }

    /**
     * Obtém o endereço completo da empresa
     */
    public function getFullBusinessAddressAttribute()
    {
        $address = [];
        
        if ($this->business_street) {
            $address[] = $this->business_street;
        }
        
        if ($this->business_street2) {
            $address[] = $this->business_street2;
        }
        
        if ($this->business_city) {
            $cityState = $this->business_city;
            if ($this->business_state) {
                $cityState .= ', ' . $this->business_state;
            }
            $address[] = $cityState;
        }
        
        if ($this->business_zip) {
            $address[] = $this->business_zip;
        }
        
        return implode(', ', $address);
    }

    /**
     * Verifica se a conta tem determinada capacidade
     */
    public function hasCapability($capability)
    {
        return in_array($capability, $this->capabilities ?? []);
    }

    /**
     * Obtém todas as capacidades da conta
     */
    public function getCapabilitiesListAttribute()
    {
        return $this->capabilities ?? [];
    }

    /**
     * Relacionamento com relatórios do Google Ads (se necessário)
     */
    public function googleAdsReports()
    {
        return $this->hasMany(GoogleAdsReport::class, 'customer_id', 'account_id');
    }
} 