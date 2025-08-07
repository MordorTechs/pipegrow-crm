<?php

namespace Webkul\Reports\Repositories;

use Webkul\Reports\Models\GoogleAdsReport;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class GoogleAdsReportRepository
{
    /**
     * Obtém relatórios por período
     */
    public function getReportsByDateRange($startDate, $endDate, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->orderBy('segments_date')->get();
    }

    /**
     * Obtém métricas agregadas por período
     */
    public function getAggregatedMetrics($startDate, $endDate, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->selectRaw('
            SUM(metrics_impressions) as total_impressions,
            SUM(metrics_clicks) as total_clicks,
            SUM(metrics_cost_micros) as total_cost_micros,
            SUM(metrics_all_conversions) as total_conversions,
            SUM(metrics_all_conversions_value) as total_conversion_value,
            SUM(metrics_engagements) as total_engagements,
            SUM(metrics_video_views) as total_video_views,
            AVG(metrics_ctr) as avg_ctr,
            AVG(metrics_engagement_rate) as avg_engagement_rate,
            AVG(metrics_video_view_rate) as avg_video_view_rate
        ')->first();
    }

    /**
     * Obtém métricas por campanha
     */
    public function getMetricsByCampaign($startDate, $endDate, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->selectRaw('
            campaign_id,
            campaign_name,
            campaign_status,
            SUM(metrics_impressions) as total_impressions,
            SUM(metrics_clicks) as total_clicks,
            SUM(metrics_cost_micros) as total_cost_micros,
            SUM(metrics_all_conversions) as total_conversions,
            SUM(metrics_all_conversions_value) as total_conversion_value,
            AVG(metrics_ctr) as avg_ctr,
            AVG(metrics_cost_per_conversion) as avg_cpa
        ')
        ->groupBy('campaign_id', 'campaign_name', 'campaign_status')
        ->orderBy('total_cost_micros', 'desc')
        ->get();
    }

    /**
     * Obtém métricas diárias
     */
    public function getDailyMetrics($startDate, $endDate, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->selectRaw('
            segments_date,
            SUM(metrics_impressions) as daily_impressions,
            SUM(metrics_clicks) as daily_clicks,
            SUM(metrics_cost_micros) as daily_cost_micros,
            SUM(metrics_all_conversions) as daily_conversions,
            SUM(metrics_all_conversions_value) as daily_conversion_value,
            AVG(metrics_ctr) as daily_ctr
        ')
        ->groupBy('segments_date')
        ->orderBy('segments_date')
        ->get();
    }

    /**
     * Obtém campanhas com melhor performance
     */
    public function getTopPerformingCampaigns($startDate, $endDate, $limit = 10, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->selectRaw('
            campaign_id,
            campaign_name,
            SUM(metrics_impressions) as total_impressions,
            SUM(metrics_clicks) as total_clicks,
            SUM(metrics_cost_micros) as total_cost_micros,
            SUM(metrics_all_conversions) as total_conversions,
            SUM(metrics_all_conversions_value) as total_conversion_value,
            AVG(metrics_ctr) as avg_ctr,
            CASE 
                WHEN SUM(metrics_cost_micros) > 0 AND SUM(metrics_all_conversions_value) > 0 
                THEN ((SUM(metrics_all_conversions_value) - (SUM(metrics_cost_micros) / 1000000)) / (SUM(metrics_cost_micros) / 1000000)) * 100
                ELSE 0 
            END as roi
        ')
        ->groupBy('campaign_id', 'campaign_name')
        ->having('total_conversions', '>', 0)
        ->orderBy('roi', 'desc')
        ->limit($limit)
        ->get();
    }

    /**
     * Obtém campanhas com pior performance
     */
    public function getWorstPerformingCampaigns($startDate, $endDate, $limit = 10, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->selectRaw('
            campaign_id,
            campaign_name,
            SUM(metrics_impressions) as total_impressions,
            SUM(metrics_clicks) as total_clicks,
            SUM(metrics_cost_micros) as total_cost_micros,
            SUM(metrics_all_conversions) as total_conversions,
            AVG(metrics_ctr) as avg_ctr,
            CASE 
                WHEN SUM(metrics_all_conversions) > 0 
                THEN (SUM(metrics_cost_micros) / 1000000) / SUM(metrics_all_conversions)
                ELSE 0 
            END as cpa
        ')
        ->groupBy('campaign_id', 'campaign_name')
        ->having('total_cost_micros', '>', 0)
        ->orderBy('cpa', 'desc')
        ->limit($limit)
        ->get();
    }

    /**
     * Obtém estatísticas de conversão
     */
    public function getConversionStats($startDate, $endDate, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->selectRaw('
            SUM(metrics_all_conversions) as total_conversions,
            SUM(metrics_view_through_conversions) as view_through_conversions,
            SUM(metrics_cross_device_conversions) as cross_device_conversions,
            SUM(metrics_all_conversions_value) as total_conversion_value,
            AVG(metrics_cost_per_all_conversions) as avg_cost_per_conversion,
            AVG(metrics_value_per_all_conversions) as avg_value_per_conversion
        ')->first();
    }

    /**
     * Obtém dados para gráfico de tendência
     */
    public function getTrendData($startDate, $endDate, $customerId = null)
    {
        $query = GoogleAdsReport::dateRange($startDate, $endDate);
        
        if ($customerId) {
            $query->byCustomer($customerId);
        }
        
        return $query->selectRaw('
            segments_date,
            SUM(metrics_impressions) as impressions,
            SUM(metrics_clicks) as clicks,
            SUM(metrics_cost_micros) as cost_micros,
            SUM(metrics_all_conversions) as conversions,
            SUM(metrics_all_conversions_value) as conversion_value,
            AVG(metrics_ctr) as ctr,
            AVG(metrics_cost_per_conversion) as cpa
        ')
        ->groupBy('segments_date')
        ->orderBy('segments_date')
        ->get();
    }

    /**
     * Salva dados do relatório
     */
    public function saveReportData($data)
    {
        return GoogleAdsReport::create($data);
    }

    /**
     * Atualiza dados existentes ou cria novos
     */
    public function updateOrCreateReport($data)
    {
        $conditions = [
            'campaign_id' => $data['campaign_id'],
            'segments_date' => $data['segments_date'],
        ];
        
        return GoogleAdsReport::updateOrCreate($conditions, $data);
    }

    /**
     * Remove dados antigos
     */
    public function cleanOldData($daysToKeep = 90)
    {
        $cutoffDate = Carbon::now()->subDays($daysToKeep);
        
        return GoogleAdsReport::where('segments_date', '<', $cutoffDate)->delete();
    }
} 