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
        Schema::create('google_ads_reports', function (Blueprint $table) {
            $table->id();
            
            // Identificadores
            $table->string('campaign_budget_id')->nullable()->index();
            $table->string('campaign_id')->nullable()->index();
            $table->string('customer_id')->nullable()->index();
            
            // Orçamento Recomendado (simulações do Google)
            $table->bigInteger('budget_weekly_clicks')->nullable();
            $table->bigInteger('budget_weekly_cost_micros')->nullable();
            $table->bigInteger('budget_weekly_interactions')->nullable();
            $table->bigInteger('budget_weekly_views')->nullable();
            
            // Campanha
            $table->string('campaign_name')->nullable();
            $table->string('campaign_status')->nullable();
            
            // Métricas de Conversão e Valor
            $table->decimal('metrics_all_conversions', 15, 4)->nullable();
            $table->decimal('metrics_all_conversions_from_interactions_rate', 8, 4)->nullable();
            $table->decimal('metrics_all_conversions_value', 15, 4)->nullable();
            $table->decimal('metrics_conversions', 15, 4)->nullable();
            $table->decimal('metrics_conversions_from_interactions_rate', 8, 4)->nullable();
            $table->decimal('metrics_conversions_value', 15, 4)->nullable();
            $table->decimal('metrics_cost_per_all_conversions', 15, 4)->nullable();
            $table->decimal('metrics_cost_per_conversion', 15, 4)->nullable();
            $table->decimal('metrics_value_per_all_conversions', 15, 4)->nullable();
            $table->decimal('metrics_value_per_conversion', 15, 4)->nullable();
            $table->decimal('metrics_view_through_conversions', 15, 4)->nullable();
            $table->decimal('metrics_cross_device_conversions', 15, 4)->nullable();
            
            // Custos e valores (em micros)
            $table->bigInteger('metrics_average_cost')->nullable();
            $table->bigInteger('metrics_average_cpc')->nullable();
            $table->bigInteger('metrics_average_cpe')->nullable();
            $table->bigInteger('metrics_average_cpm')->nullable();
            $table->bigInteger('metrics_average_cpv')->nullable();
            $table->bigInteger('metrics_cost_micros')->nullable();
            
            // Métricas de Engajamento e Cliques
            $table->bigInteger('metrics_clicks')->nullable();
            $table->decimal('metrics_ctr', 8, 4)->nullable();
            $table->bigInteger('metrics_engagements')->nullable();
            $table->decimal('metrics_engagement_rate', 8, 4)->nullable();
            $table->bigInteger('metrics_impressions')->nullable();
            $table->bigInteger('metrics_interactions')->nullable();
            $table->decimal('metrics_interaction_rate', 8, 4)->nullable();
            $table->json('metrics_interaction_event_types')->nullable();
            
            // Métricas de Vídeo
            $table->decimal('metrics_video_view_rate', 8, 4)->nullable();
            $table->bigInteger('metrics_video_views')->nullable();
            
            // Datas
            $table->date('segments_date')->nullable()->index();
            $table->date('_latest_date')->nullable();
            $table->date('_data_date')->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Índices para melhor performance
            $table->index(['segments_date', 'campaign_id']);
            $table->index(['segments_date', 'customer_id']);
            $table->index(['campaign_status', 'segments_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_ads_reports');
    }
}; 