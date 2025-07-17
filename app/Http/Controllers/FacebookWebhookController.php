<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessFacebookLead; // Importar o Job
use App\Models\MetaAdsTokens; // Importar o modelo MetaAdsTokens

class FacebookWebhookController extends Controller
{
    /**
     * Lida com a verificação do webhook do Facebook.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function verifyWebhook(Request $request)
    {
        $verifyToken = env('FACEBOOK_WEBHOOK_VERIFY_TOKEN');
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                Log::info('Webhook verificado com sucesso!');
                return response($challenge, 200);
            } else {
                return response('Token de verificação incorreto', 403);
            }
        }

        return response('Parâmetros ausentes', 400);
    }

    /**
     * Lida com os dados recebidos do webhook do Facebook.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        Log::info('Dados do Webhook do Facebook recebidos:', $data);

        // Processar apenas as entradas de leadgen
        if (isset($data['object']) && $data['object'] === 'page') {
            foreach ($data['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    if ($change['field'] === 'leadgen' && $change['value']['item'] === 'leadgen') {
                        $leadgenId = $change['value']['leadgen_id'];
                        $pageId = $change['value']['page_id'];

                        // Despachar o job para processar o lead em segundo plano
                        ProcessFacebookLead::dispatch($leadgenId, $pageId);

                        Log::info("Job ProcessFacebookLead despachado para leadgen_id: {$leadgenId}");
                    }
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }
}
