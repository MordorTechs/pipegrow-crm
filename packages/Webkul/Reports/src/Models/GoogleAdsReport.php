<?php

namespace Webkul\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GoogleAdsReport extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'google_ads_reports';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        // Identificadores
        'campaign_budget_id',
        'campaign_id',
        'customer_id',
        
        // Orçamento Recomendado
        'budget_weekly_clicks',
        'budget_weekly_cost_micros',
        'budget_weekly_interactions',
        'budget_weekly_views',
        
        // Campanha
        'campaign_name',
        'campaign_status',
        
        // Métricas de Conversão e Valor
        'metrics_all_conversions',
        'metrics_all_conversions_from_interactions_rate',
        'metrics_all_conversions_value',
        'metrics_conversions',
        'metrics_conversions_from_interactions_rate',
        'metrics_conversions_value',
        'metrics_cost_per_all_conversions',
        'metrics_cost_per_conversion',
        'metrics_value_per_all_conversions',
        'metrics_value_per_conversion',
        'metrics_view_through_conversions',
        'metrics_cross_device_conversions',
        
        // Custos e valores (em micros)
        'metrics_average_cost',
        'metrics_average_cpc',
        'metrics_average_cpe',
        'metrics_average_cpm',
        'metrics_average_cpv',
        'metrics_cost_micros',
        
        // Métricas de Engajamento e Cliques
        'metrics_clicks',
        'metrics_ctr',
        'metrics_engagements',
        'metrics_engagement_rate',
        'metrics_impressions',
        'metrics_interactions',
        'metrics_interaction_rate',
        'metrics_interaction_event_types',
        
        // Métricas de Vídeo
        'metrics_video_view_rate',
        'metrics_video_views',
        
        // Datas
        'segments_date',
        '_latest_date',
        '_data_date',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'segments_date' => 'date',
        '_latest_date' => 'date',
        '_data_date' => 'date',
        'metrics_interaction_event_types' => 'array',
        'metrics_all_conversions' => 'decimal:4',
        'metrics_all_conversions_from_interactions_rate' => 'decimal:4',
        'metrics_all_conversions_value' => 'decimal:4',
        'metrics_conversions' => 'decimal:4',
        'metrics_conversions_from_interactions_rate' => 'decimal:4',
        'metrics_conversions_value' => 'decimal:4',
        'metrics_cost_per_all_conversions' => 'decimal:4',
        'metrics_cost_per_conversion' => 'decimal:4',
        'metrics_value_per_all_conversions' => 'decimal:4',
        'metrics_value_per_conversion' => 'decimal:4',
        'metrics_view_through_conversions' => 'decimal:4',
        'metrics_cross_device_conversions' => 'decimal:4',
        'metrics_ctr' => 'decimal:4',
        'metrics_engagement_rate' => 'decimal:4',
        'metrics_interaction_rate' => 'decimal:4',
        'metrics_video_view_rate' => 'decimal:4',
    ];

    /**
     * Scope para filtrar por período de data
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('segments_date', [$startDate, $endDate]);
    }

    /**
     * Scope para filtrar por campanha
     */
    public function scopeByCampaign($query, $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    /**
     * Scope para filtrar por cliente
     */
    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * Scope para filtrar por status da campanha
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('campaign_status', $status);
    }

    /**
     * Converte micros para valor monetário
     */
    public function getCostAttribute()
    {
        return $this->metrics_cost_micros ? $this->metrics_cost_micros / 1000000 : 0;
    }

    /**
     * Converte micros para CPC
     */
    public function getCpcAttribute()
    {
        return $this->metrics_average_cpc ? $this->metrics_average_cpc / 1000000 : 0;
    }

    /**
     * Converte micros para CPM
     */
    public function getCpmAttribute()
    {
        return $this->metrics_average_cpm ? $this->metrics_average_cpm / 1000000 : 0;
    }

    /**
     * Calcula o ROI (Return on Investment)
     */
    public function getRoiAttribute()
    {
        if ($this->metrics_cost_micros && $this->metrics_all_conversions_value) {
            $cost = $this->metrics_cost_micros / 1000000;
            return (($this->metrics_all_conversions_value - $cost) / $cost) * 100;
        }
        
        return 0;
    }

    /**
     * Calcula o CPA (Cost Per Acquisition)
     */
    public function getCpaAttribute()
    {
        if ($this->metrics_all_conversions && $this->metrics_cost_micros) {
            $cost = $this->metrics_cost_micros / 1000000;
            return $cost / $this->metrics_all_conversions;
        }
        
        return 0;
    }
} 