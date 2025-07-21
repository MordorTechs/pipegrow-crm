<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessWhatsappMessage;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    /**
     * Handle WhatsApp webhook verification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function verifyWebhook(Request $request)
    {
        // Token de verificação que você configurou no painel do Facebook para o webhook
        $verifyToken = env('WHATSAPP_WEBHOOK_VERIFY_TOKEN');

        // Obtenha os parâmetros da requisição GET
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        // Verifique se o modo e o token estão corretos
        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                Log::info('WhatsApp Webhook verificado com sucesso!');
                return response($challenge, 200)->header('Content-Type', 'text/plain');
            } else {
                Log::warning('Falha na verificação do WhatsApp Webhook: Token ou modo inválido.');
                return response('Forbidden', 403);
            }
        }

        Log::warning('Requisição de verificação do WhatsApp Webhook inválida.');
        return response('Bad Request', 400);
    }

    /**
     * Handle incoming WhatsApp messages.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        // Log a requisição completa para depuração
        Log::info('Webhook do WhatsApp recebido:', $request->all());

        $body = $request->all();

        // Verifique se o payload contém as informações esperadas do WhatsApp
        if (isset($body['object']) && $body['object'] === 'whatsapp_business_account') {
            foreach ($body['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    if ($change['field'] === 'messages') {
                        foreach ($change['value']['messages'] as $message) {
                            // Despacha o job para processar a mensagem em segundo plano
                            ProcessWhatsappMessage::dispatch($message);
                            Log::info('Job ProcessWhatsappMessage despachado para mensagem:', ['message_id' => $message['id'] ?? 'N/A']);
                        }
                    }
                }
            }
            return response('EVENT_RECEIVED', 200);
        }

        Log::warning('Payload do WhatsApp Webhook inválido ou não contém mensagens.');
        return response('Bad Request', 400);
    }
}
