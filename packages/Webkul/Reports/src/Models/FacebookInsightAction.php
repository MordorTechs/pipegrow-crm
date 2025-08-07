<?php

namespace Webkul\Reports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FacebookInsightAction extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'facebook_insights_actions';

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
        
        // Período e Nível de Consulta
        'date_start',
        'date_end',
        'time_increment',
        'level',
        
        // Parâmetros Técnicos
        'date_preset',
        'use_async',
        'action_attribution_windows',
        'action_collection',
        
        // Métricas de Ações Atribuídas
        'action_value',
        'action_1d_click',
        'action_1d_view',
        'action_7d_click',
        'action_7d_view',
        'action_28d_click',
        'action_28d_view',
        'action_dda',
        'action_converted_product_id',
        
        // Status do Anúncio
        'ad_effective_status',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'date_start' => 'date',
        'date_end' => 'date',
        'use_async' => 'boolean',
        'action_value' => 'decimal:4',
        'action_1d_click' => 'integer',
        'action_1d_view' => 'integer',
        'action_7d_click' => 'integer',
        'action_7d_view' => 'integer',
        'action_28d_click' => 'integer',
        'action_28d_view' => 'integer',
        'action_dda' => 'integer',
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
     * Scope para filtrar por tipo de ação
     */
    public function scopeByActionCollection($query, $actionCollection)
    {
        return $query->where('action_collection', $actionCollection);
    }

    /**
     * Scope para filtrar por janela de atribuição
     */
    public function scopeByAttributionWindow($query, $attributionWindow)
    {
        return $query->where('action_attribution_windows', $attributionWindow);
    }

    /**
     * Scope para filtrar por status do anúncio
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('ad_effective_status', $status);
    }

    /**
     * Calcula o total de ações por clique
     */
    public function getTotalClickActionsAttribute()
    {
        return ($this->action_1d_click ?? 0) + 
               ($this->action_7d_click ?? 0) + 
               ($this->action_28d_click ?? 0);
    }

    /**
     * Calcula o total de ações por visualização
     */
    public function getTotalViewActionsAttribute()
    {
        return ($this->action_1d_view ?? 0) + 
               ($this->action_7d_view ?? 0) + 
               ($this->action_28d_view ?? 0);
    }

    /**
     * Calcula o total de ações (clique + visualização)
     */
    public function getTotalActionsAttribute()
    {
        return $this->total_click_actions + $this->total_view_actions;
    }

    /**
     * Calcula a taxa de conversão por clique (1 dia)
     */
    public function getClickConversionRate1dAttribute()
    {
        // Assumindo que temos dados de cliques do FacebookInsight
        // Esta é uma implementação básica, pode ser expandida
        return 0; // Placeholder
    }

    /**
     * Calcula a taxa de conversão por visualização (1 dia)
     */
    public function getViewConversionRate1dAttribute()
    {
        // Assumindo que temos dados de impressões do FacebookInsight
        // Esta é uma implementação básica, pode ser expandida
        return 0; // Placeholder
    }

    /**
     * Calcula o valor médio por ação
     */
    public function getAverageActionValueAttribute()
    {
        if ($this->total_actions && $this->action_value) {
            return $this->action_value / $this->total_actions;
        }
        
        return 0;
    }

    /**
     * Calcula o ROI baseado no valor das ações
     */
    public function getRoiAttribute()
    {
        // Assumindo que temos dados de gastos do FacebookInsight
        // Esta é uma implementação básica, pode ser expandida
        return 0; // Placeholder
    }

    /**
     * Calcula o CPA (Cost Per Action)
     */
    public function getCpaAttribute()
    {
        // Assumindo que temos dados de gastos do FacebookInsight
        // Esta é uma implementação básica, pode ser expandida
        return 0; // Placeholder
    }

    /**
     * Obtém a janela de atribuição mais efetiva
     */
    public function getMostEffectiveAttributionWindowAttribute()
    {
        $windows = [
            '1d_click' => $this->action_1d_click ?? 0,
            '7d_click' => $this->action_7d_click ?? 0,
            '28d_click' => $this->action_28d_click ?? 0,
            '1d_view' => $this->action_1d_view ?? 0,
            '7d_view' => $this->action_7d_view ?? 0,
            '28d_view' => $this->action_28d_view ?? 0,
        ];
        
        return array_keys($windows, max($windows))[0] ?? null;
    }

    /**
     * Verifica se tem ações DDA (Data-Driven Attribution)
     */
    public function getHasDdaActionsAttribute()
    {
        return ($this->action_dda ?? 0) > 0;
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
     * Verifica se o anúncio está ativo
     */
    public function getIsActiveAttribute()
    {
        return $this->ad_effective_status === 'ACTIVE';
    }

    /**
     * Relacionamento com conta de anúncios do Facebook
     */
    public function facebookAdAccount()
    {
        return $this->belongsTo(FacebookAdAccount::class, 'ad_account_id', 'account_id');
    }

    /**
     * Relacionamento com insights do Facebook
     */
    public function facebookInsight()
    {
        return $this->belongsTo(FacebookInsight::class, 'ad_id', 'ad_id');
    }

    /**
     * Relacionamento com relatórios do Google Ads (se necessário)
     */
    public function googleAdsReports()
    {
        return $this->hasMany(GoogleAdsReport::class, 'customer_id', 'ad_account_id');
    }
} 