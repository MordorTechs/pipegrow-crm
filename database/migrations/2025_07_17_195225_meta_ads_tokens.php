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
        Schema::create('meta_ads_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('app_url_customer');
            $table->string('access_token')->nullable();
            $table->string('page_id')->nullable();
            $table->string('page_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
