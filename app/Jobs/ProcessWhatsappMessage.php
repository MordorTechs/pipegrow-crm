<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http; // Para fazer requisições HTTP (para Gemini e WhatsApp)

// Importe os modelos necessários para interagir com o CRM
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\Lead\Models\Source;
use Webkul\Lead\Models\Type;
use Webkul\Lead\Models\Pipeline;
use Webkul\Lead\Models\Stage;
use Webkul\User\Models\User;
use Webkul\Activity\Models\Activity; // Usaremos o modelo Activity diretamente para criar a atividade

class ProcessWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageData;

    /**
     * Create a new job instance.
     *
     * @param array $messageData O payload da mensagem do WhatsApp.
     * @return void
     */
    public function __construct(array $messageData)
    {
        $this->messageData = $messageData;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('Processando mensagem do WhatsApp:', $this->messageData);

        try {
            $from = $this->messageData['from'] ?? null; // Número de telefone do remetente
            $messageId = $this->messageData['id'] ?? null; // ID da mensagem
            $text = $this->messageData['text']['body'] ?? null; // Conteúdo da mensagem de texto
            $timestamp = $this->messageData['timestamp'] ?? null; // Timestamp da mensagem

            if (!$from || !$text) {
                Log::warning('Mensagem do WhatsApp ignorada: Remetente ou texto ausente.', $this->messageData);
                return;
            }

            // --- Extrair nome do contato do payload do webhook (se disponível) ---
            $initialContactName = $this->messageData['contacts'][0]['profile']['name'] ?? ('Cliente WhatsApp ' . $from);

            // --- 1. Pré-atendimento com Gemini 2.5 ---
            $geminiResponse = $this->callGeminiAPI($text);
            Log::info('Resposta do Gemini:', ['response' => $geminiResponse]);

            // Usar o nome do Gemini se for mais específico que o nome inicial, ou se o nome inicial for o padrão
            $contactName = $geminiResponse['contact_name'] ?? $initialContactName;
            if ($contactName === 'Não mencionado' || $contactName === 'A ser qualificado') {
                $contactName = $initialContactName;
            }
            
            $contactEmail = $geminiResponse['contact_email'] ?? null;
            $spinData = $geminiResponse['spin_data'] ?? []; // Array associativo com S, P, I, N
            $bantData = $geminiResponse['bant_data'] ?? []; // Array associativo com B, A, N, T
            $preAttendanceText = $geminiResponse['pre_attendance_text'] ?? "Olá! Como posso ajudar você hoje?";


            // --- 2. Tente encontrar ou criar uma pessoa (contato) ---
            $person = Person::where('contact_numbers', 'like', '%' . $from . '%')->first();

            if (!$person) {
                Log::info('Pessoa não encontrada, criando nova pessoa para o número: ' . $from);
                $defaultUser = User::first(); // Você pode definir uma lógica mais robusta para encontrar o usuário padrão

                $personData = [
                    'name'            => $contactName, // Usar o nome extraído ou do Gemini
                    'contact_numbers' => json_encode([['value' => $from, 'label' => 'mobile']]),
                    'user_id'         => $defaultUser->id ?? null,
                ];

                if ($contactEmail) {
                    $personData['emails'] = json_encode([['value' => $contactEmail, 'label' => 'work']]);
                } else {
                    $personData['emails'] = json_encode([]);
                }

                $person = Person::create($personData);

                if (!$defaultUser) {
                    Log::warning('Nenhum usuário padrão encontrado para atribuir a nova pessoa. A pessoa foi criada sem atribuição de usuário.');
                }
            } else {
                // Atualizar nome e email se o Gemini forneceu informações mais detalhadas
                // Apenas atualiza se o nome atual for o padrão 'Cliente WhatsApp <número>' ou se o Gemini retornou um nome mais específico
                if ($contactName !== $person->name && ($person->name === ('Cliente WhatsApp ' . $from) || $contactName !== ('Cliente WhatsApp ' . $from))) {
                    $person->update(['name' => $contactName]);
                }
                if ($contactEmail && !in_array($contactEmail, array_column(json_decode($person->emails, true) ?? [], 'value'))) {
                    $emails = json_decode($person->emails, true) ?? [];
                    $emails[] = ['value' => $contactEmail, 'label' => 'work'];
                    $person->update(['emails' => json_encode($emails)]);
                }
                Log::info('Pessoa existente encontrada e atualizada (se necessário): ' . $person->name);
            }

            // --- 3. Tente encontrar ou criar um lead associado a esta pessoa ---
            $lead = Lead::where('person_id', $person->id)
                        ->whereIn('status', ['open', 'new']) // Exemplo: leads em status "aberto" ou "novo"
                        ->first();

            if (!$lead) {
                Log::info('Lead não encontrado ou não aberto, criando novo lead para a pessoa: ' . $person->name);

                $defaultPipeline = Pipeline::first();
                $defaultStage = null;

                if ($defaultPipeline) {
                    // Usar 'lead_pipeline_id' para buscar a stage
                    $defaultStage = Stage::where('lead_pipeline_id', $defaultPipeline->id)->orderBy('sort_order')->first();
                }

                $whatsappSource = Source::firstOrCreate(['name' => 'WhatsApp'], ['code' => 'whatsapp']);
                $defaultType = Type::first();

                $lead = Lead::create([
                    'title'               => 'Lead WhatsApp de ' . $contactName, // Usar o nome extraído ou do Gemini no título do lead
                    'lead_pipeline_id'    => $defaultPipeline->id ?? null,
                    'lead_pipeline_stage_id' => $defaultStage->id ?? null,
                    'lead_source_id'      => $whatsappSource->id ?? null,
                    'lead_type_id'        => $defaultType->id ?? null,
                    'user_id'             => $person->user_id,
                    'person_id'           => $person->id,
                    'expected_close_date' => now()->addDays(7),
                    'status'              => 'new',
                    'lead_value'          => 0,
                ]);

                if (!$defaultPipeline || !$defaultStage || !$defaultType) {
                    Log::warning('Pipeline, Stage ou Type padrão não encontrados. O lead foi criado com valores padrão ou nulos.');
                }
            } else {
                Log::info('Lead existente encontrado para a pessoa: ' . $person->name);
            }

            // --- 4. Adicionar a mensagem original e a resposta do Gemini como ATIVIDADES ao lead ---
            // Criar uma nova atividade para a mensagem original do WhatsApp
            Activity::create([
                'title'       => 'Mensagem WhatsApp Recebida',
                'description' => 'Mensagem original de ' . $from . ': ' . $text,
                'type'        => 'whatsapp_message', // Um novo tipo de atividade para mensagens do WhatsApp
                'lead_id'     => $lead->id,
                'person_id'   => $person->id,
                'user_id'     => $person->user_id,
                'is_done'     => 1, // Considerar a mensagem recebida como uma atividade "concluída"
                'schedule_from' => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now(),
                'schedule_to'   => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now(),
            ]);
            Log::info('Mensagem original do WhatsApp adicionada como atividade ao lead.');

            // Criar uma nova atividade para a resposta do Gemini (pré-atendimento)
            Activity::create([
                'title'       => 'Resposta Automática (Gemini)',
                'description' => 'Resposta do Gemini (pré-atendimento): ' . $preAttendanceText,
                'type'        => 'whatsapp_message_auto_response', // Outro tipo de atividade para respostas automáticas
                'lead_id'     => $lead->id,
                'person_id'   => $person->id,
                'user_id'     => $person->user_id,
                'is_done'     => 1,
                'schedule_from' => now(),
                'schedule_to'   => now(),
            ]);
            Log::info('Resposta do Gemini adicionada como atividade ao lead.');

            // --- 5. Salvar dados de SPIN e BANT como NOTAS (ainda pode usar notas se preferir um formato mais livre) ---
            // Ou você pode criar atividades separadas para cada um, ou até mesmo campos personalizados no Lead.
            if (!empty($spinData)) {
                $spinNote = "Dados SPIN:\n";
                foreach ($spinData as $key => $value) {
                    $spinNote .= ucfirst($key) . ": " . $value . "\n";
                }
                Activity::create([
                    'title'       => 'Qualificação SPIN',
                    'description' => $spinNote,
                    'type'        => 'note', // Usar o tipo 'note' para isso
                    'lead_id'     => $lead->id,
                    'person_id'   => $person->id,
                    'user_id'     => $person->user_id,
                    'is_done'     => 1,
                    'schedule_from' => now(),
                    'schedule_to'   => now(),
                ]);
                Log::info('Dados SPIN adicionados como nota de atividade ao lead.');
            }

            if (!empty($bantData)) {
                $bantNote = "Dados BANT:\n";
                foreach ($bantData as $key => $value) {
                    $bantNote .= ucfirst($key) . ": " . $value . "\n";
                }
                Activity::create([
                    'title'       => 'Qualificação BANT',
                    'description' => $bantNote,
                    'type'        => 'note', // Usar o tipo 'note' para isso
                    'lead_id'     => $lead->id,
                    'person_id'   => $person->id,
                    'user_id'     => $person->user_id,
                    'is_done'     => 1,
                    'schedule_from' => now(),
                    'schedule_to'   => now(),
                ]);
                Log::info('Dados BANT adicionados como nota de atividade ao lead.');
            }


            Log::info('Mensagem do WhatsApp processada, pessoa e lead atualizados/criados e atividades registradas.', [
                'lead_id' => $lead->id,
                'person_id' => $person->id,
                'from' => $from,
                'message' => $text
            ]);

            // --- 6. Enviar resposta de volta para o WhatsApp ---
            $this->sendWhatsappMessage($from, $preAttendanceText);

        } catch (\Exception $e) {
            Log::error('Erro ao processar mensagem do WhatsApp: ' . $e->getMessage(), [
                'message_data' => $this->messageData,
                'exception' => $e
            ]);
        }
    }

    /**
     * Faz a chamada à API do Gemini 2.5 para pré-atendimento.
     *
     * @param string $message O texto da mensagem do usuário.
     * @return array A resposta processada do Gemini.
     */
    protected function callGeminiAPI(string $message): array
    {
        $apiKey = env('GEMINI_API_KEY');
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

        // Prompt aprimorado para ser mais explícito sobre a saída JSON pura
        $prompt = "Você é um assistente de pré-atendimento de vendas. Analise a seguinte mensagem de um cliente via WhatsApp. Extraia as seguintes informações:
        - Nome completo do cliente (se disponível)
        - Endereço de e-mail do cliente (se disponível)
        - Elementos da metodologia SPIN (Situação, Problema, Implicação, Necessidade de Solução)
        - Elementos da metodologia BANT (Budget, Authority, Need, Timeline)

        Com base na análise, crie um texto de pré-atendimento amigável e profissional para o cliente, buscando mais informações para qualificá-lo.

        Sua resposta DEVE ser APENAS um objeto JSON válido, sem texto adicional, formatação, ou caracteres extras antes ou depois do JSON. As chaves do JSON devem ser:
        - 'pre_attendance_text': O texto de pré-atendimento para o cliente.
        - 'contact_name': O nome completo do cliente.
        - 'contact_email': O e-mail do cliente.
        - 'spin_data': Um objeto JSON com as chaves 'situacao', 'problema', 'implicacao', 'necessidade'.
        - 'bant_data': Um objeto JSON com as chaves 'budget', 'authority', 'need', 'timeline'.

        Se alguma informação não for encontrada, use 'Não mencionado' ou 'A ser qualificado'.

        Mensagem do cliente: \"{$message}\"";

        try {
            $response = Http::timeout(60)->post($apiUrl, [ // Aumentado o tempo limite para 60 segundos
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'responseMimeType' => "application/json",
                    "responseSchema" => [
                        "type" => "OBJECT",
                        "properties" => [
                            "pre_attendance_text" => ["type" => "STRING"],
                            "contact_name" => ["type" => "STRING"],
                            "contact_email" => ["type" => "STRING"],
                            "spin_data" => [
                                "type" => "OBJECT",
                                "properties" => [
                                    "situacao" => ["type" => "STRING"],
                                    "problema" => ["type" => "STRING"],
                                    "implicacao" => ["type" => "STRING"],
                                    "necessidade" => ["type" => "STRING"],
                                ],
                            ],
                            "bant_data" => [
                                "type" => "OBJECT",
                                "properties" => [
                                    "budget" => ["type" => "STRING"],
                                    "authority" => ["type" => "STRING"],
                                    "need" => ["type" => "STRING"],
                                    "timeline" => ["type" => "STRING"],
                                ],
                            ],
                        ],
                        "propertyOrdering" => [
                            "pre_attendance_text", "contact_name", "contact_email", "spin_data", "bant_data"
                        ],
                    ],
                ],
            ]);

            if ($response->successful()) {
                $result = $response->json();
                // O Gemini pode retornar a resposta JSON dentro de uma string de texto.
                // Precisamos garantir que estamos pegando o JSON correto e limpá-lo.
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $jsonString = $result['candidates'][0]['content']['parts'][0]['text'];

                    // Tentar encontrar o início e o fim do objeto JSON
                    $jsonStart = strpos($jsonString, '{');
                    $jsonEnd = strrpos($jsonString, '}');

                    if ($jsonStart !== false && $jsonEnd !== false) {
                        $jsonString = substr($jsonString, $jsonStart, $jsonEnd - $jsonStart + 1);
                    } else {
                        Log::warning('Não foi possível encontrar um objeto JSON completo na resposta do Gemini.', ['raw_gemini_response_text' => $jsonString]);
                        return $this->getDefaultGeminiResponse();
                    }

                    // Remover caracteres de controle inválidos e espaços/quebras de linha extras
                    $jsonString = preg_replace('/[[:cntrl:]]/', '', $jsonString); // Remove caracteres de controle
                    $jsonString = trim($jsonString); // Remove espaços em branco (incluindo quebras de linha) do início e fim

                    $parsedJson = json_decode($jsonString, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $parsedJson;
                    } else {
                        Log::error('Erro ao decodificar JSON da resposta do Gemini: ' . json_last_error_msg(), ['json_string_after_cleaning' => $jsonString]);
                        return $this->getDefaultGeminiResponse();
                    }
                }
            } else {
                Log::error('Falha na chamada à API do Gemini:', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exceção ao chamar a API do Gemini: ' . $e->getMessage());
        }

        return $this->getDefaultGeminiResponse();
    }

    /**
     * Retorna uma resposta padrão do Gemini em caso de falha.
     *
     * @return array
     */
    protected function getDefaultGeminiResponse(): array
    {
        return [
            'pre_attendance_text' => "Olá! Recebemos sua mensagem. Para que eu possa te ajudar melhor, poderia me dizer qual é o seu nome e um e-mail para contato?",
            'contact_name'        => 'Não mencionado',
            'contact_email'       => 'Não mencionado',
            'spin_data'           => [
                'situacao'   => 'Não qualificado',
                'problema'   => 'Não qualificado',
                'implicacao' => 'Não qualificado',
                'necessidade' => 'Não qualificado'
            ],
            'bant_data'           => [
                'budget'    => 'Não qualificado',
                'authority' => 'Não qualificado',
                'need'      => 'Não qualificado',
                'timeline'  => 'Não qualificado'
            ]
        ];
    }

    /**
     * Envia uma mensagem de volta para o WhatsApp.
     *
     * @param string $to O número de telefone do destinatário.
     * @param string $message O texto da mensagem a ser enviada.
     * @return void
     */
    protected function sendWhatsappMessage(string $to, string $message): void
    {
        $accessToken = env('WHATSAPP_ACCESS_TOKEN');
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID'); // ID do seu número de telefone do WhatsApp Business API

        // Adicionado log para depuração
        Log::info('Tentando enviar mensagem WhatsApp com:', [
            'to' => $to,
            'phoneNumberId' => $phoneNumberId,
            'accessToken_present' => !empty($accessToken) // Apenas verifica se está presente, não loga o token completo
        ]);

        if (!$accessToken || !$phoneNumberId) {
            Log::error('Erro: WHATSAPP_ACCESS_TOKEN ou WHATSAPP_PHONE_NUMBER_ID não configurados no .env. Não foi possível enviar a mensagem de resposta.');
            return;
        }

        $url = "https://graph.facebook.com/v19.0/{$phoneNumberId}/messages"; // Use a versão mais recente da API se necessário

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])->post($url, [
                'messaging_product' => 'whatsapp',
                'to'                => $to,
                'type'              => 'text',
                'text'              => ['body' => $message],
            ]);

            if ($response->successful()) {
                Log::info('Mensagem do WhatsApp enviada com sucesso para ' . $to, ['response' => $response->json()]);
            } else {
                Log::error('Falha ao enviar mensagem do WhatsApp para ' . $to, [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exceção ao enviar mensagem do WhatsApp: ' . $e->getMessage(), ['to' => $to]);
        }
    }
}
