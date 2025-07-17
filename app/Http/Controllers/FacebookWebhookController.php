<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log; // Importar a classe Log para depuração

class FacebookWebhookController extends Controller
{
    /**
     * Handle Facebook Webhook verification requests.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verify(Request $request)
    {
        // Token de verificação que você definiu no Painel de Aplicativos do Facebook
        // É ALTAMENTE RECOMENDADO armazenar este token em seu arquivo .env
        // Exemplo: FACEBOOK_WEBHOOK_VERIFY_TOKEN="seu_token_secreto_aqui"
        $verifyToken = env('FACEBOOK_WEBHOOK_VERIFY_TOKEN');

        // Obter parâmetros da string de consulta.
        // O PHP converte automaticamente 'hub.mode' para 'hub_mode', etc.
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Log para depuração para ver os valores recebidos
        Log::info('Facebook Webhook Verification Request:', [
            'hub_mode'        => $mode,
            'hub_verify_token' => $token,
            'hub_challenge'   => $challenge,
        ]);

        // Verificar se o modo é 'subscribe' e o token corresponde
        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                // Sucesso na verificação, retorna o desafio
                Log::info('Facebook Webhook Verified Successfully.');
                return response($challenge, 200);
            } else {
                // Token ou modo inválido
                Log::warning('Facebook Webhook Verification Failed: Invalid token or mode.', [
                    'expected_token' => $verifyToken,
                    'received_token' => $token,
                    'received_mode'  => $mode,
                ]);
                return response('Forbidden', 403);
            }
        }

        // Requisição inválida se os parâmetros não estiverem presentes
        Log::error('Facebook Webhook Verification Failed: Missing parameters.');
        return response('Bad Request', 400);
    }

    /**
     * Handle incoming Facebook Webhook events.
     * This method will receive the actual event data from Facebook.
     * You will need to implement the logic to process the webhook payload here.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handle(Request $request)
    {
        // Log o payload completo para depuração
        Log::info('Facebook Webhook Event Received:', $request->all());

        // Implemente sua lógica de processamento de webhook aqui.
        // Por exemplo, você pode querer verificar a assinatura da requisição
        // para garantir que ela vem do Facebook e então processar os dados.

        // Exemplo de como acessar dados do payload:
        // $entry = $request->input('entry');
        // foreach ($entry as $data) {
        //     // Processar cada entrada de dados
        // }

        // Retorna uma resposta 200 OK para o Facebook para indicar que o webhook foi recebido
        return response('EVENT_RECEIVED', 200);
    }
}
