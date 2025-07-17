<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebhookLeadController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
| For webhooks, generally, you might not want to apply 'auth:sanctum'
| middleware directly if the webhook source doesn't provide a token.
| Instead, implement custom security measures within the controller
| like checking a shared secret or IP whitelisting.
|
*/

// Rota de exemplo para usuário autenticado (mantida do original)
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Endpoint para leads do site (URL: /api/webhook/leads)
Route::post('webhook/leads', [WebhookLeadController::class, 'handleSiteLead']);

// Novo endpoint para leads do Google Ads (URL: /api/webhook/leads/google-ads)
Route::post('webhook/leads/google-ads', [WebhookLeadController::class, 'handleGoogleAdsLead']);

// Rota para verificação do webhook do Facebook (GET)
Route::get('/webhook/facebook', [FacebookWebhookController::class, 'verify']);

// Rota para lidar com os eventos do webhook do Facebook (POST)
Route::post('/webhook/facebook', [FacebookWebhookController::class, 'handle']);