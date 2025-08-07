<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('facebook_insights', function (Blueprint $table) {
            $table->id();
            
            // Identificação da Conta e Campanha
            $table->string('target')->nullable()->index(); // ID completo da conta de anúncios (com prefixo act_)
            $table->string('ad_account_id')->nullable()->index();
            $table->string('ad_account_name')->nullable();
            $table->string('campaign_id')->nullable()->index();
            $table->string('campaign_name')->nullable();
            $table->string('ad_set_id')->nullable()->index();
            $table->string('ad_set_name')->nullable();
            $table->string('ad_id')->nullable()->index();
            $table->string('ad_name')->nullable();
            
            // Datas e Configurações
            $table->date('date_start')->nullable()->index();
            $table->date('date_end')->nullable()->index();
            $table->string('time_increment')->nullable(); // Agrupamento de tempo (ex: diário, semanal)
            $table->string('level')->nullable(); // Nível do dado (ex: ad, adset, campaign)
            $table->string('date_preset')->nullable(); // Filtro de data predefinido (se usado)
            $table->boolean('use_async')->nullable(); // Indica se a coleta foi assíncrona
            
            // Objetivo e Tipo de Compra
            $table->string('objective')->nullable(); // Objetivo da campanha (ex: conversões, vendas)
            $table->string('buying_type')->nullable(); // Tipo de compra (geralmente AUCTION)
            
            // Métricas de Desempenho
            $table->bigInteger('impressions')->nullable();
            $table->bigInteger('reach')->nullable(); // Alcance único
            $table->bigInteger('clicks')->nullable(); // Cliques totais
            $table->bigInteger('unique_clicks')->nullable(); // Cliques únicos
            $table->decimal('ctr', 8, 4)->nullable(); // Taxa de cliques (Click-Through Rate)
            $table->decimal('unique_ctr', 8, 4)->nullable(); // CTR considerando apenas usuários únicos
            $table->decimal('frequency', 8, 4)->nullable(); // Frequência média de exibição por pessoa
            $table->decimal('cpc', 15, 4)->nullable(); // Custo por clique
            $table->decimal('cpm', 15, 4)->nullable(); // Custo por mil impressões
            $table->decimal('cpp', 15, 4)->nullable(); // Custo por compra (Cost per Purchase)
            $table->decimal('spend', 15, 4)->nullable(); // Valor gasto no anúncio
            
            // Engajamento e Cliques
            $table->bigInteger('link_clicks')->nullable(); // Cliques em links externos
            $table->bigInteger('inline_link_clicks')->nullable(); // Cliques em links no próprio anúncio
            $table->bigInteger('inline_post_engagement')->nullable(); // Engajamentos no post do anúncio
            $table->bigInteger('page_engagements')->nullable(); // Engajamentos na página a partir do anúncio
            $table->bigInteger('post_engagements')->nullable(); // Interações com o post (likes, comentários etc.)
            $table->bigInteger('unique_inline_link_clicks')->nullable(); // Cliques únicos em links internos
            
            // Custo por Ação
            $table->decimal('cost_per_inline_link_click', 15, 4)->nullable(); // Custo por clique em link interno
            $table->decimal('cost_per_inline_post_engagement', 15, 4)->nullable(); // Custo por engajamento no post
            $table->decimal('cost_per_unique_click', 15, 4)->nullable(); // Custo por clique único
            $table->decimal('cost_per_unique_inline_link_click', 15, 4)->nullable(); // Custo por clique interno único
            $table->decimal('cost_per_estimated_ad_recallers', 15, 4)->nullable(); // Estimativa de custo por lembrança do anúncio
            
            // Outros
            $table->string('region')->nullable(); // Região geográfica do público
            $table->string('ad_effective_status')->nullable(); // Status do anúncio (se ativo, pausado etc.)
            $table->string('quality_ranking')->nullable(); // Rankings de qualidade do anúncio
            $table->string('conversion_rate_ranking')->nullable(); // Rankings de conversão do anúncio
            
            // Timestamps
            $table->timestamps();
            
            // Índices para melhor performance
            $table->index(['date_start', 'date_end']);
            $table->index(['campaign_id', 'date_start']);
            $table->index(['ad_set_id', 'date_start']);
            $table->index(['ad_id', 'date_start']);
            $table->index(['target', 'level']);
            $table->index(['objective', 'date_start']);
            $table->index(['ad_effective_status', 'date_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_insights');
    }
}; 