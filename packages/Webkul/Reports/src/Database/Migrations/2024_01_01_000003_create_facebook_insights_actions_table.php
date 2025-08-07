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
        Schema::create('facebook_insights_actions', function (Blueprint $table) {
            $table->id();
            
            // Identificação da Conta e Campanha
            $table->string('target')->nullable()->index(); // ID completo da conta de anúncios, no formato act_XXXXXXXXXXXXX
            $table->string('ad_account_id')->nullable()->index();
            $table->string('ad_account_name')->nullable(); // Nome da conta de anúncios (empresa ou marca anunciando)
            $table->string('campaign_id')->nullable()->index(); // ID único da campanha
            $table->string('campaign_name')->nullable(); // Nome atribuído à campanha
            $table->string('ad_set_id')->nullable()->index(); // ID do conjunto de anúncios
            $table->string('ad_set_name')->nullable(); // Nome atribuído ao conjunto de anúncios
            $table->string('ad_id')->nullable()->index(); // ID do anúncio individual
            $table->string('ad_name')->nullable(); // Nome do anúncio (às vezes igual ao ID)
            
            // Período e Nível de Consulta
            $table->date('date_start')->nullable()->index(); // Data inicial da análise (normalmente um único dia)
            $table->date('date_end')->nullable()->index(); // Data final da análise
            $table->string('time_increment')->nullable(); // Intervalo de tempo usado no agrupamento (ex: diário)
            $table->string('level')->nullable(); // Nível de dados retornados: ad, adset ou campaign
            
            // Parâmetros Técnicos
            $table->string('date_preset')->nullable(); // Intervalo de datas predefinido (se usado, substitui DateStart/End)
            $table->boolean('use_async')->nullable(); // Indica se a coleta foi assíncrona (true/false)
            $table->string('action_attribution_windows')->nullable(); // Janela(s) de atribuição usadas na coleta (ex: 1d_click, 7d_view)
            $table->string('action_collection')->nullable(); // Tipo específico de ações coletadas (ex: purchase, lead, etc.)
            
            // Métricas de Ações Atribuídas
            $table->decimal('action_value', 15, 4)->nullable(); // Valor total atribuído à ação (ex: somatório de valores de conversões)
            $table->bigInteger('action_1d_click')->nullable(); // Número de ações atribuídas até 1 dia após o clique
            $table->bigInteger('action_1d_view')->nullable(); // Número de ações atribuídas até 1 dia após a visualização
            $table->bigInteger('action_7d_click')->nullable(); // Ações atribuídas em até 7 dias após o clique
            $table->bigInteger('action_7d_view')->nullable(); // Ações atribuídas em até 7 dias após visualização
            $table->bigInteger('action_28d_click')->nullable(); // Ações atribuídas em até 28 dias após o clique
            $table->bigInteger('action_28d_view')->nullable(); // Ações atribuídas em até 28 dias após a visualização
            $table->bigInteger('action_dda')->nullable(); // Ações atribuídas via modelo de atribuição baseado em dados (Data-Driven Attribution)
            $table->string('action_converted_product_id')->nullable(); // ID do produto convertido (em caso de catálogos ou ecommerce)
            
            // Status do Anúncio
            $table->string('ad_effective_status')->nullable(); // Status atual do anúncio (ativo, pausado, rejeitado, etc.) – pode estar null se a coleta focou só nas ações
            
            // Timestamps
            $table->timestamps();
            
            // Índices para melhor performance
            $table->index(['date_start', 'date_end']);
            $table->index(['campaign_id', 'date_start']);
            $table->index(['ad_set_id', 'date_start']);
            $table->index(['ad_id', 'date_start']);
            $table->index(['target', 'level']);
            $table->index(['action_collection', 'date_start']);
            $table->index(['ad_effective_status', 'date_start']);
            $table->index(['action_attribution_windows', 'date_start'], 'fb_insights_actions_attr_window_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_insights_actions');
    }
}; 