<?php

namespace Webkul\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FacebookInsight extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'facebook_insights';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        // Identificação da Conta e Campanha
        'target',
        'ad_account_id',
        'ad_account_name',
        'campaign_id',
        'campaign_name',
        'ad_set_id',
        'ad_set_name',
        'ad_id',
        'ad_name',
        
        // Datas e Configurações
        'date_start',
        'date_end',
        'time_increment',
        'level',
        'date_preset',
        'use_async',
        
        // Objetivo e Tipo de Compra
        'objective',
        'buying_type',
        
        // Métricas de Desempenho
        'impressions',
        'reach',
        'clicks',
        'unique_clicks',
        'ctr',
        'unique_ctr',
        'frequency',
        'cpc',
        'cpm',
        'cpp',
        'spend',
        
        // Engajamento e Cliques
        'link_clicks',
        'inline_link_clicks',
        'inline_post_engagement',
        'page_engagements',
        'post_engagements',
        'unique_inline_link_clicks',
        
        // Custo por Ação
        'cost_per_inline_link_click',
        'cost_per_inline_post_engagement',
        'cost_per_unique_click',
        'cost_per_unique_inline_link_click',
        'cost_per_estimated_ad_recallers',
        
        // Outros
        'region',
        'ad_effective_status',
        'quality_ranking',
        'conversion_rate_ranking',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'use_async' => 'boolean',
        'impressions' => 'integer',
        'reach' => 'integer',
        'clicks' => 'integer',
        'unique_clicks' => 'integer',
        'ctr' => 'decimal:4',
        'unique_ctr' => 'decimal:4',
        'frequency' => 'decimal:4',
        'cpc' => 'decimal:4',
        'cpm' => 'decimal:4',
        'cpp' => 'decimal:4',
        'spend' => 'decimal:4',
        'link_clicks' => 'integer',
        'inline_link_clicks' => 'integer',
        'inline_post_engagement' => 'integer',
        'page_engagements' => 'integer',
        'post_engagements' => 'integer',
        'unique_inline_link_clicks' => 'integer',
        'cost_per_inline_link_click' => 'decimal:4',
        'cost_per_inline_post_engagement' => 'decimal:4',
        'cost_per_unique_click' => 'decimal:4',
        'cost_per_unique_inline_link_click' => 'decimal:4',
        'cost_per_estimated_ad_recallers' => 'decimal:4',
    ];

    /**
     * Scope para filtrar por período de data
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date_start', [$startDate, $endDate]);
    }

    /**
     * Scope para filtrar por campanha
     */
    public function scopeByCampaign($query, $campaignId)
    {
        return $query->where('campaign_id', $campaignId);
    }

    /**
     * Scope para filtrar por conjunto de anúncios
     */
    public function scopeByAdSet($query, $adSetId)
    {
        return $query->where('ad_set_id', $adSetId);
    }

    /**
     * Scope para filtrar por anúncio
     */
    public function scopeByAd($query, $adId)
    {
        return $query->where('ad_id', $adId);
    }

    /**
     * Scope para filtrar por conta de anúncios
     */
    public function scopeByAdAccount($query, $adAccountId)
    {
        return $query->where('ad_account_id', $adAccountId);
    }

    /**
     * Scope para filtrar por nível
     */
    public function scopeByLevel($query, $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope para filtrar por objetivo
     */
    public function scopeByObjective($query, $objective)
    {
        return $query->where('objective', $objective);
    }

    /**
     * Scope para filtrar por status do anúncio
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('ad_effective_status', $status);
    }

    /**
     * Scope para filtrar por região
     */
    public function scopeByRegion($query, $region)
    {
        return $query->where('region', $region);
    }

    /**
     * Calcula o ROI (Return on Investment)
     */
    public function getRoiAttribute()
    {
        if ($this->spend && $this->spend > 0) {
            // Assumindo que temos dados de conversão ou valor
            // Esta é uma implementação básica, pode ser expandida
            return 0; // Placeholder
        }
        
        return 0;
    }

    /**
     * Calcula o CPA (Cost Per Acquisition)
     */
    public function getCpaAttribute()
    {
        if ($this->clicks && $this->spend) {
            return $this->spend / $this->clicks;
        }
        
        return 0;
    }

    /**
     * Calcula o CPL (Cost Per Lead)
     */
    public function getCplAttribute()
    {
        if ($this->link_clicks && $this->spend) {
            return $this->spend / $this->link_clicks;
        }
        
        return 0;
    }

    /**
     * Calcula a taxa de engajamento
     */
    public function getEngagementRateAttribute()
    {
        if ($this->impressions && $this->impressions > 0) {
            $totalEngagement = ($this->post_engagements ?? 0) + ($this->page_engagements ?? 0);
            return ($totalEngagement / $this->impressions) * 100;
        }
        
        return 0;
    }

    /**
     * Calcula a taxa de cliques em links
     */
    public function getLinkClickRateAttribute()
    {
        if ($this->impressions && $this->impressions > 0) {
            return ($this->link_clicks / $this->impressions) * 100;
        }
        
        return 0;
    }

    /**
     * Calcula a taxa de cliques internos
     */
    public function getInlineLinkClickRateAttribute()
    {
        if ($this->impressions && $this->impressions > 0) {
            return ($this->inline_link_clicks / $this->impressions) * 100;
        }
        
        return 0;
    }

    /**
     * Calcula o custo por impressão
     */
    public function getCpiAttribute()
    {
        if ($this->impressions && $this->spend) {
            return $this->spend / $this->impressions;
        }
        
        return 0;
    }

    /**
     * Calcula o custo por alcance
     */
    public function getCprAttribute()
    {
        if ($this->reach && $this->spend) {
            return $this->spend / $this->reach;
        }
        
        return 0;
    }

    /**
     * Obtém o total de engajamentos
     */
    public function getTotalEngagementsAttribute()
    {
        return ($this->post_engagements ?? 0) + 
               ($this->page_engagements ?? 0) + 
               ($this->inline_post_engagement ?? 0);
    }

    /**
     * Obtém o total de cliques
     */
    public function getTotalClicksAttribute()
    {
        return ($this->clicks ?? 0) + 
               ($this->link_clicks ?? 0) + 
               ($this->inline_link_clicks ?? 0);
    }

    /**
     * Verifica se o anúncio está ativo
     */
    public function getIsActiveAttribute()
    {
        return $this->ad_effective_status === 'ACTIVE';
    }

    /**
     * Obtém o status do anúncio em formato legível
     */
    public function getStatusTextAttribute()
    {
        $statuses = [
            'ACTIVE' => 'Ativo',
            'PAUSED' => 'Pausado',
            'DELETED' => 'Deletado',
            'ARCHIVED' => 'Arquivado',
            'PENDING_REVIEW' => 'Em Revisão',
            'DISAPPROVED' => 'Reprovado',
            'PREAPPROVED' => 'Pré-aprovado',
            'PENDING_BILLING_INFO' => 'Aguardando Informações de Cobrança',
            'CAPTCHA' => 'Captcha',
            'ADS_EDIT_PENDING' => 'Edição Pendente',
            'IN_PROCESS' => 'Em Processo',
            'WITH_ISSUES' => 'Com Problemas',
        ];

        return $statuses[$this->ad_effective_status] ?? $this->ad_effective_status;
    }

    /**
     * Relacionamento com conta de anúncios do Facebook
     */
    public function facebookAdAccount()
    {
        return $this->belongsTo(FacebookAdAccount::class, 'ad_account_id', 'account_id');
    }

    /**
     * Relacionamento com relatórios do Google Ads (se necessário)
     */
    public function googleAdsReports()
    {
        return $this->hasMany(GoogleAdsReport::class, 'customer_id', 'ad_account_id');
    }
} 