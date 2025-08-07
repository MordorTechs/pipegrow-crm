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
        Schema::create('facebook_ad_accounts', function (Blueprint $table) {
            $table->id();
            
            // ID da conta de anúncios no formato act_#########
            $table->string('facebook_id')->unique()->index();
            
            // Campo possivelmente reservado para informações futuras ou customizações
            $table->string('target')->nullable();
            
            // ID numérico da conta de anúncios
            $table->string('account_id')->nullable()->index();
            
            // Status da conta: 1 = ativa, 2 = pausada, etc.
            $table->integer('account_status')->nullable()->index();
            
            // Idade da conta em dias
            $table->integer('age')->nullable();
            
            // Valor total gasto (em centavos da moeda local)
            $table->bigInteger('amount_spent')->nullable();
            
            // Saldo atual da conta (em centavos)
            $table->bigInteger('balance')->nullable();
            
            // Informações da empresa
            $table->string('business_city')->nullable();
            $table->string('business_country_code', 2)->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_state')->nullable();
            $table->string('business_street')->nullable();
            $table->string('business_street2')->nullable();
            $table->string('business_zip')->nullable();
            
            // Lista de recursos que a conta tem habilitados
            $table->json('capabilities')->nullable();
            
            // Data/hora de criação da conta de anúncios
            $table->timestamp('created_time')->nullable();
            
            // Moeda da conta (ex: BRL para reais)
            $table->string('currency', 3)->nullable();
            
            // Gasto mínimo permitido para grupo de campanhas
            $table->bigInteger('min_campaign_group_spend_cap')->nullable();
            
            // Nome da conta de anúncios
            $table->string('name')->nullable();
            
            // Indica se os termos de uso de pixels externos foram aceitos
            $table->boolean('offsite_pixels_tos_accepted')->nullable();
            
            // ID do usuário proprietário da conta
            $table->string('owner_id')->nullable()->index();
            
            // Limite máximo de gastos definido na conta
            $table->bigInteger('spend_cap')->nullable();
            
            // Informações de fuso horário
            $table->integer('timezone_id')->nullable();
            $table->string('timezone_name')->nullable();
            $table->decimal('timezone_offset_hours_utc', 4, 1)->nullable();
            
            // Timestamps
            $table->timestamps();
            
            // Índices para melhor performance
            $table->index(['account_status', 'created_time']);
            $table->index(['business_country_code', 'account_status']);
            $table->index(['owner_id', 'account_status']);
            $table->index(['currency', 'amount_spent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_ad_accounts');
    }
}; 