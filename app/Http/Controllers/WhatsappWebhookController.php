<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Jobs\ProcessWhatsappMessage;
use Illuminate\Support\Facades\Log;
use Webkul\Contact\Repositories\PersonRepository; // Import PersonRepository
use Webkul\Lead\Repositories\LeadRepository;     // Import LeadRepository
use Webkul\Lead\Repositories\SourceRepository;   // Import SourceRepository

class WhatsappWebhookController extends Controller
{
    /**
     * @var \Webkul\Contact\Repositories\PersonRepository
     */
    protected PersonRepository $personRepository;

    /**
     * @var \Webkul\Lead\Repositories\LeadRepository
     */
    protected LeadRepository $leadRepository;

    /**
     * @var \Webkul\Lead\Repositories\SourceRepository
     */
    protected SourceRepository $sourceRepository;

    /**
     * Create a new controller instance.
     *
     * @param \Webkul\Contact\Repositories\PersonRepository $personRepository
     * @param \Webkul\Lead\Repositories\LeadRepository $leadRepository
     * @param \Webkul\Lead\Repositories\SourceRepository $sourceRepository
     * @return void
     */
    public function __construct(
        PersonRepository $personRepository,
        LeadRepository $leadRepository,
        SourceRepository $sourceRepository
    ) {
        $this->personRepository = $personRepository;
        $this->leadRepository   = $leadRepository;
        $this->sourceRepository = $sourceRepository;
    }

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
                    // Adiciona verificação para a chave 'value' e 'messages' ou 'statuses'
                    if (isset($change['value'])) {
                        if ($change['field'] === 'messages' && isset($change['value']['messages'])) {
                            foreach ($change['value']['messages'] as $message) {
                                // Despacha o job para processar a mensagem em segundo plano
                                ProcessWhatsappMessage::dispatch(
                                    $message,
                                    $this->personRepository,
                                    $this->leadRepository,
                                    $this->sourceRepository
                                );
                                Log::info('Job ProcessWhatsappMessage despachado para mensagem:', ['message_id' => $message['id'] ?? 'N/A']);
                            }
                        } elseif ($change['field'] === 'messages' && isset($change['value']['statuses'])) {
                            // Este bloco lida com notificações de status (entregue, lido)
                            foreach ($change['value']['statuses'] as $status) {
                                Log::info('Notificação de status do WhatsApp recebida:', ['status_id' => $status['id'] ?? 'N/A', 'status' => $status['status'] ?? 'N/A']);
                                // Você pode adicionar lógica para processar status aqui, se necessário
                            }
                        }
                    }
                }
            }
            return response('EVENT_RECEIVED', 200);
        }

        Log::warning('Payload do WhatsApp Webhook inválido ou não contém dados esperados.');
        return response('Bad Request', 400);
    }
}
