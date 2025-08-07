<?php

namespace Webkul\Reports\Repositories;

use Webkul\Reports\Models\FacebookInsightAction;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class FacebookInsightActionRepository
{
    /**
     * Obtém ações por período
     */
    public function getActionsByDateRange($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->orderBy('date_start')->get();
    }

    /**
     * Obtém métricas agregadas de ações por período
     */
    public function getAggregatedActionMetrics($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions,
            COUNT(DISTINCT action_converted_product_id) as unique_products_converted
        ')->first();
    }

    /**
     * Obtém métricas de ações por campanha
     */
    public function getActionMetricsByCampaign($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            campaign_id,
            campaign_name,
            action_collection,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions,
            COUNT(DISTINCT action_converted_product_id) as unique_products_converted
        ')
        ->groupBy('campaign_id', 'campaign_name', 'action_collection')
        ->orderBy('total_action_value', 'desc')
        ->get();
    }

    /**
     * Obtém métricas de ações por conjunto de anúncios
     */
    public function getActionMetricsByAdSet($startDate, $endDate, $campaignId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($campaignId) {
            $query->byCampaign($campaignId);
        }
        
        return $query->selectRaw('
            ad_set_id,
            ad_set_name,
            campaign_id,
            campaign_name,
            action_collection,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions
        ')
        ->groupBy('ad_set_id', 'ad_set_name', 'campaign_id', 'campaign_name', 'action_collection')
        ->orderBy('total_action_value', 'desc')
        ->get();
    }

    /**
     * Obtém métricas de ações por anúncio
     */
    public function getActionMetricsByAd($startDate, $endDate, $adSetId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adSetId) {
            $query->byAdSet($adSetId);
        }
        
        return $query->selectRaw('
            ad_id,
            ad_name,
            ad_set_id,
            ad_set_name,
            campaign_name,
            ad_effective_status,
            action_collection,
            action_attribution_windows,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions,
            action_converted_product_id
        ')
        ->groupBy('ad_id', 'ad_name', 'ad_set_id', 'ad_set_name', 'campaign_name', 'ad_effective_status', 'action_collection', 'action_attribution_windows', 'action_converted_product_id')
        ->orderBy('total_action_value', 'desc')
        ->get();
    }

    /**
     * Obtém métricas de ações diárias
     */
    public function getDailyActionMetrics($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            date_start,
            action_collection,
            SUM(action_value) as daily_action_value,
            SUM(action_1d_click) as daily_1d_click_actions,
            SUM(action_7d_click) as daily_7d_click_actions,
            SUM(action_28d_click) as daily_28d_click_actions,
            SUM(action_1d_view) as daily_1d_view_actions,
            SUM(action_7d_view) as daily_7d_view_actions,
            SUM(action_28d_view) as daily_28d_view_actions,
            SUM(action_dda) as daily_dda_actions
        ')
        ->groupBy('date_start', 'action_collection')
        ->orderBy('date_start')
        ->get();
    }

    /**
     * Obtém campanhas com melhor performance de ações
     */
    public function getTopPerformingActionCampaigns($startDate, $endDate, $limit = 10, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            campaign_id,
            campaign_name,
            action_collection,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions
        ')
        ->groupBy('campaign_id', 'campaign_name', 'action_collection')
        ->having('total_action_value', '>', 0)
        ->orderBy('total_action_value', 'desc')
        ->limit($limit)
        ->get();
    }

    /**
     * Obtém anúncios com melhor performance de ações
     */
    public function getTopPerformingActionAds($startDate, $endDate, $limit = 10, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            ad_id,
            ad_name,
            ad_set_name,
            campaign_name,
            action_collection,
            ad_effective_status,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions
        ')
        ->groupBy('ad_id', 'ad_name', 'ad_set_name', 'campaign_name', 'action_collection', 'ad_effective_status')
        ->having('total_action_value', '>', 0)
        ->orderBy('total_action_value', 'desc')
        ->limit($limit)
        ->get();
    }

    /**
     * Obtém estatísticas por tipo de ação
     */
    public function getStatsByActionCollection($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            action_collection,
            COUNT(DISTINCT campaign_id) as total_campaigns,
            COUNT(DISTINCT ad_id) as total_ads,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions,
            COUNT(DISTINCT action_converted_product_id) as unique_products_converted
        ')
        ->groupBy('action_collection')
        ->orderBy('total_action_value', 'desc')
        ->get();
    }

    /**
     * Obtém estatísticas por janela de atribuição
     */
    public function getStatsByAttributionWindow($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            action_attribution_windows,
            action_collection,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions
        ')
        ->whereNotNull('action_attribution_windows')
        ->groupBy('action_attribution_windows', 'action_collection')
        ->orderBy('total_action_value', 'desc')
        ->get();
    }

    /**
     * Obtém dados para gráfico de tendência de ações
     */
    public function getActionTrendData($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            date_start,
            action_collection,
            SUM(action_value) as action_value,
            SUM(action_1d_click) as action_1d_click,
            SUM(action_7d_click) as action_7d_click,
            SUM(action_28d_click) as action_28d_click,
            SUM(action_1d_view) as action_1d_view,
            SUM(action_7d_view) as action_7d_view,
            SUM(action_28d_view) as action_28d_view,
            SUM(action_dda) as action_dda
        ')
        ->groupBy('date_start', 'action_collection')
        ->orderBy('date_start')
        ->get();
    }

    /**
     * Obtém produtos mais convertidos
     */
    public function getTopConvertedProducts($startDate, $endDate, $limit = 10, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            action_converted_product_id,
            action_collection,
            SUM(action_value) as total_action_value,
            SUM(action_1d_click) as total_1d_click_actions,
            SUM(action_7d_click) as total_7d_click_actions,
            SUM(action_28d_click) as total_28d_click_actions,
            SUM(action_1d_view) as total_1d_view_actions,
            SUM(action_7d_view) as total_7d_view_actions,
            SUM(action_28d_view) as total_28d_view_actions,
            SUM(action_dda) as total_dda_actions
        ')
        ->whereNotNull('action_converted_product_id')
        ->groupBy('action_converted_product_id', 'action_collection')
        ->having('total_action_value', '>', 0)
        ->orderBy('total_action_value', 'desc')
        ->limit($limit)
        ->get();
    }

    /**
     * Obtém anúncios com ações DDA (Data-Driven Attribution)
     */
    public function getAdsWithDdaActions($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            ad_id,
            ad_name,
            ad_set_name,
            campaign_name,
            action_collection,
            SUM(action_dda) as total_dda_actions,
            SUM(action_value) as total_action_value,
            AVG(CASE WHEN action_dda > 0 THEN action_value / action_dda ELSE 0 END) as avg_dda_value
        ')
        ->groupBy('ad_id', 'ad_name', 'ad_set_name', 'campaign_name', 'action_collection')
        ->having('total_dda_actions', '>', 0)
        ->orderBy('total_dda_actions', 'desc')
        ->get();
    }

    /**
     * Salva dados de ação
     */
    public function saveActionData($data)
    {
        return FacebookInsightAction::create($data);
    }

    /**
     * Atualiza dados existentes ou cria novos
     */
    public function updateOrCreateAction($data)
    {
        $conditions = [
            'ad_id' => $data['ad_id'],
            'date_start' => $data['date_start'],
            'level' => $data['level'],
            'action_collection' => $data['action_collection'],
        ];
        
        return FacebookInsightAction::updateOrCreate($conditions, $data);
    }

    /**
     * Remove dados antigos
     */
    public function cleanOldData($daysToKeep = 90)
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);
        
        return FacebookInsightAction::where('date_start', '<', $cutoffDate)->delete();
    }

    /**
     * Obtém ações com informações completas
     */
    public function getActionsWithFullInfo($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsightAction::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->with(['facebookAdAccount', 'facebookInsight'])
            ->orderBy('date_start', 'desc')
            ->get();
    }
} 