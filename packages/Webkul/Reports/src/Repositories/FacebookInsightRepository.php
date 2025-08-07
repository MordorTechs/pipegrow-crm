<?php

namespace Webkul\Reports\Repositories;

use Webkul\Reports\Models\FacebookInsight;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class FacebookInsightRepository
{
    /**
     * Obtém insights por período
     */
    public function getInsightsByDateRange($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->orderBy('date_start')->get();
    }

    /**
     * Obtém métricas agregadas por período
     */
    public function getAggregatedMetrics($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            SUM(clicks) as total_clicks,
            SUM(unique_clicks) as total_unique_clicks,
            SUM(spend) as total_spend,
            SUM(link_clicks) as total_link_clicks,
            SUM(inline_link_clicks) as total_inline_link_clicks,
            SUM(post_engagements) as total_post_engagements,
            SUM(page_engagements) as total_page_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc,
            AVG(cpm) as avg_cpm,
            AVG(frequency) as avg_frequency
        ')->first();
    }

    /**
     * Obtém métricas por campanha
     */
    public function getMetricsByCampaign($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            campaign_id,
            campaign_name,
            objective,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            SUM(post_engagements) as total_post_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc,
            AVG(cpm) as avg_cpm
        ')
        ->groupBy('campaign_id', 'campaign_name', 'objective')
        ->orderBy('total_spend', 'desc')
        ->get();
    }

    /**
     * Obtém métricas por conjunto de anúncios
     */
    public function getMetricsByAdSet($startDate, $endDate, $campaignId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($campaignId) {
            $query->byCampaign($campaignId);
        }
        
        return $query->selectRaw('
            ad_set_id,
            ad_set_name,
            campaign_id,
            campaign_name,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            SUM(post_engagements) as total_post_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc
        ')
        ->groupBy('ad_set_id', 'ad_set_name', 'campaign_id', 'campaign_name')
        ->orderBy('total_spend', 'desc')
        ->get();
    }

    /**
     * Obtém métricas por anúncio
     */
    public function getMetricsByAd($startDate, $endDate, $adSetId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adSetId) {
            $query->byAdSet($adSetId);
        }
        
        return $query->selectRaw('
            ad_id,
            ad_name,
            ad_set_id,
            ad_set_name,
            ad_effective_status,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            SUM(post_engagements) as total_post_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc,
            quality_ranking,
            conversion_rate_ranking
        ')
        ->groupBy('ad_id', 'ad_name', 'ad_set_id', 'ad_set_name', 'ad_effective_status', 'quality_ranking', 'conversion_rate_ranking')
        ->orderBy('total_spend', 'desc')
        ->get();
    }

    /**
     * Obtém métricas diárias
     */
    public function getDailyMetrics($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            date_start,
            SUM(impressions) as daily_impressions,
            SUM(reach) as daily_reach,
            SUM(clicks) as daily_clicks,
            SUM(spend) as daily_spend,
            SUM(post_engagements) as daily_post_engagements,
            AVG(ctr) as daily_ctr,
            AVG(cpc) as daily_cpc
        ')
        ->groupBy('date_start')
        ->orderBy('date_start')
        ->get();
    }

    /**
     * Obtém campanhas com melhor performance
     */
    public function getTopPerformingCampaigns($startDate, $endDate, $limit = 10, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            campaign_id,
            campaign_name,
            objective,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            SUM(post_engagements) as total_post_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc,
            CASE 
                WHEN SUM(clicks) > 0 THEN SUM(spend) / SUM(clicks)
                ELSE 0 
            END as cpa
        ')
        ->groupBy('campaign_id', 'campaign_name', 'objective')
        ->having('total_clicks', '>', 0)
        ->orderBy('avg_ctr', 'desc')
        ->limit($limit)
        ->get();
    }

    /**
     * Obtém anúncios com melhor performance
     */
    public function getTopPerformingAds($startDate, $endDate, $limit = 10, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            ad_id,
            ad_name,
            ad_set_name,
            campaign_name,
            SUM(impressions) as total_impressions,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            SUM(post_engagements) as total_post_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc,
            quality_ranking,
            conversion_rate_ranking
        ')
        ->groupBy('ad_id', 'ad_name', 'ad_set_name', 'campaign_name', 'quality_ranking', 'conversion_rate_ranking')
        ->having('total_impressions', '>', 0)
        ->orderBy('avg_ctr', 'desc')
        ->limit($limit)
        ->get();
    }

    /**
     * Obtém estatísticas por objetivo
     */
    public function getStatsByObjective($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            objective,
            COUNT(DISTINCT campaign_id) as total_campaigns,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            SUM(post_engagements) as total_post_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc
        ')
        ->groupBy('objective')
        ->orderBy('total_spend', 'desc')
        ->get();
    }

    /**
     * Obtém estatísticas por região
     */
    public function getStatsByRegion($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            region,
            SUM(impressions) as total_impressions,
            SUM(reach) as total_reach,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            SUM(post_engagements) as total_post_engagements,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc
        ')
        ->whereNotNull('region')
        ->groupBy('region')
        ->orderBy('total_spend', 'desc')
        ->get();
    }

    /**
     * Obtém dados para gráfico de tendência
     */
    public function getTrendData($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            date_start,
            SUM(impressions) as impressions,
            SUM(reach) as reach,
            SUM(clicks) as clicks,
            SUM(spend) as spend,
            SUM(post_engagements) as post_engagements,
            AVG(ctr) as ctr,
            AVG(cpc) as cpc
        ')
        ->groupBy('date_start')
        ->orderBy('date_start')
        ->get();
    }

    /**
     * Obtém anúncios que precisam de atenção
     */
    public function getAdsNeedingAttention($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->selectRaw('
            ad_id,
            ad_name,
            ad_set_name,
            campaign_name,
            ad_effective_status,
            SUM(impressions) as total_impressions,
            SUM(clicks) as total_clicks,
            SUM(spend) as total_spend,
            AVG(ctr) as avg_ctr,
            AVG(cpc) as avg_cpc,
            quality_ranking,
            conversion_rate_ranking
        ')
        ->groupBy('ad_id', 'ad_name', 'ad_set_name', 'campaign_name', 'ad_effective_status', 'quality_ranking', 'conversion_rate_ranking')
        ->where(function ($query) {
            $query->where('ad_effective_status', '!=', 'ACTIVE')
                  ->orWhere('avg_ctr', '<', 0.5)
                  ->orWhere('avg_cpc', '>', 5.0);
        })
        ->orderBy('avg_ctr', 'asc')
        ->get();
    }

    /**
     * Salva dados do insight
     */
    public function saveInsightData($data)
    {
        return FacebookInsight::create($data);
    }

    /**
     * Atualiza dados existentes ou cria novos
     */
    public function updateOrCreateInsight($data)
    {
        $conditions = [
            'ad_id' => $data['ad_id'],
            'date_start' => $data['date_start'],
            'level' => $data['level'],
        ];
        
        return FacebookInsight::updateOrCreate($conditions, $data);
    }

    /**
     * Remove dados antigos
     */
    public function cleanOldData($daysToKeep = 90)
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);
        
        return FacebookInsight::where('date_start', '<', $cutoffDate)->delete();
    }

    /**
     * Obtém insights com informações completas
     */
    public function getInsightsWithFullInfo($startDate, $endDate, $adAccountId = null)
    {
        $query = FacebookInsight::dateRange($startDate, $endDate);
        
        if ($adAccountId) {
            $query->byAdAccount($adAccountId);
        }
        
        return $query->with('facebookAdAccount')
            ->orderBy('date_start', 'desc')
            ->get();
    }
} 