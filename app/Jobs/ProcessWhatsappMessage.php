<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http; // Para fazer requisições HTTP (para Gemini e WhatsApp)
use Illuminate\Support\Facades\Cache; // Para usar o sistema de cache do Laravel

// Importe os modelos necessários para interagir com o CRM
use Webkul\Contact\Models\Person;
use Webkul\Contact\Models\Organization; // Adicionado para lidar com o nome da empresa
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
     * Cria uma nova instância do job.
     *
     * @param array $messageData O payload da mensagem do WhatsApp.
     * @return void
     */
    public function __construct(array $messageData)
    {
        $this->messageData = $messageData;
    }

    /**
     * Executa o job.
     *
     * @return void
     */
    public function handle()
    {
        // Chave de idempotência para evitar processamento duplicado
        $messageId = $this->messageData['id'] ?? null;
        $cacheKeyIdempotency = 'whatsapp_message_processed_' . $messageId;

        if ($messageId && Cache::has($cacheKeyIdempotency)) {
            Log::info('Mensagem do WhatsApp já processada (idempotência): ' . $messageId);
            return;
        }

        Log::info('Processando mensagem do WhatsApp:', $this->messageData);

        try {
            $from = $this->messageData['from'] ?? null; // Número de telefone do remetente
            $text = $this->messageData['text']['body'] ?? null; // Conteúdo da mensagem de texto
            $timestamp = $this->messageData['timestamp'] ?? null; // Timestamp da mensagem

            if (!$from || !$text) {
                Log::warning('Mensagem do WhatsApp ignorada: Remetente ou texto ausente.', $this->messageData);
                return;
            }

            // --- Formatar número de telefone brasileiro se necessário ---
            $originalFrom = $from;
            $from = $this->formatBrazilianPhoneNumber($from);
            Log::info('Número de telefone formatado:', ['original' => $originalFrom, 'formatted' => $from]);

            // --- Gerenciamento de estado da conversa ---
            $cacheKeyConversationState = 'whatsapp_conversation_state_' . $from;
            $conversationState = Cache::get($cacheKeyConversationState, 'initial_greeting'); // Estado inicial: saudação

            // --- Tente encontrar a pessoa (contato) no CRM ---
            $person = Person::where('contact_numbers', 'like', '%' . $from . '%')->first();
            
            // Prepara o contexto para o Gemini com base nos dados ATUAIS da pessoa (se existir)
            $knownContactNameForGemini = optional($person)->name ?? 'Não conhecido';
            $knownContactEmailForGemini = (json_decode(optional($person)->emails, true)[0]['value'] ?? null) ?? 'Não conhecido';
            $knownContactCompanyForGemini = optional(optional($person)->organization)->name ?? 'Não conhecido';

            Log::info('Pessoa existente encontrada para Gemini context:', [
                'name' => $knownContactNameForGemini,
                'email' => $knownContactEmailForGemini,
                'company' => $knownContactCompanyForGemini
            ]);
            
            // --- 1. Chamada ao Gemini 2.5 para extração de dados da última mensagem ---
            $geminiResponse = $this->callGeminiAPI($text, $knownContactNameForGemini, $knownContactEmailForGemini, $knownContactCompanyForGemini, $from, $conversationState);
            Log::info('Resposta do Gemini:', ['response' => $geminiResponse]);

            $extractedContactName = $geminiResponse['contact_name'] ?? null;
            $extractedContactEmail = $geminiResponse['contact_email'] ?? null;
            $extractedContactCompany = $geminiResponse['contact_company'] ?? null;
            
            // Garante que os dados extraídos sejam strings vazias se não forem válidos
            $extractedContactEmail = (empty($extractedContactEmail) || $extractedContactEmail === 'Não conhecido' || $extractedContactEmail === 'Não mencionado') ? '' : $extractedContactEmail;
            $extractedContactCompany = (empty($extractedContactCompany) || $extractedContactCompany === 'Não conhecido' || $extractedContactCompany === 'Não mencionado') ? '' : $extractedContactCompany;

            // --- Determine os dados mais atualizados da pessoa (priorizando extração do Gemini) ---
            $currentPersonName = optional($person)->name;
            $currentPersonCompany = optional(optional($person)->organization)->name;
            $currentPersonEmail = (json_decode(optional($person)->emails, true)[0]['value'] ?? null);

            // Se o Gemini extraiu um nome mais específico, usa-o
            if (!empty($extractedContactName) && $extractedContactName !== $currentPersonName && !str_starts_with($extractedContactName, 'Cliente WhatsApp ')) {
                $currentPersonName = $extractedContactName;
            } elseif (empty($currentPersonName) || str_starts_with($currentPersonName, 'Cliente WhatsApp ')) {
                $currentPersonName = $extractedContactName; // Usa nome extraído se o atual é placeholder
            }

            // Se o Gemini extraiu uma empresa mais específica, usa-a
            if (!empty($extractedContactCompany) && $extractedContactCompany !== $currentPersonCompany) {
                $currentPersonCompany = $extractedContactCompany;
            }

            // Se o Gemini extraiu um email mais específico, usa-o
            if (!empty($extractedContactEmail) && $extractedContactEmail !== $currentPersonEmail) {
                $currentPersonEmail = $extractedContactEmail;
            }

            // --- Lógica para determinar a próxima mensagem e o próximo estado ---
            $preAttendanceText = '';
            $nextState = $conversationState;
            $lead = null; // Inicializa $lead como null, será preenchido se o lead for criado/encontrado

            // Define o texto de pré-atendimento e o próximo estado
            if ($conversationState === 'initial_greeting') {
                $preAttendanceText = "Olá! Bem-vindo(a) à PipeGrow CRM.";
                $nextState = 'awaiting_name';
            } elseif ($conversationState === 'awaiting_name') {
                if (!empty($currentPersonName) && !str_starts_with($currentPersonName, 'Cliente WhatsApp ')) {
                    // Nome foi fornecido, agora perguntar a empresa
                    $preAttendanceText = "Olá, " . $currentPersonName . "! Qual o nome da empresa que você representa?";
                    $nextState = 'awaiting_company';
                } else {
                    // Ainda aguardando o nome (caso o Gemini não tenha extraído ou a resposta foi genérica)
                    $preAttendanceText = "Olá! Qual é o seu nome completo?";
                    $nextState = 'awaiting_name';
                }
            } elseif ($conversationState === 'awaiting_company') {
                if (!empty($currentPersonCompany)) {
                    // Empresa foi fornecida, finalizar e criar o lead
                    $preAttendanceText = "Ótimo, " . $currentPersonName . " da " . $currentPersonCompany . "! Um especialista da PipeGrow CRM entrará em contato em breve para entender melhor suas necessidades. Obrigado!";
                    $nextState = 'completed';

                    // --- CRIAÇÃO/ATUALIZAÇÃO DE PERSON E LEAD AQUI (APENAS SE COMPLETED) ---
                    if (!$person) {
                        // Se a pessoa não existe, cria agora com todas as informações
                        Log::info('Atendimento concluído. Criando nova pessoa.');
                        $defaultUser = User::first();
                        $personData = [
                            'name'            => $currentPersonName,
                            'contact_numbers' => json_encode([['value' => $from, 'label' => 'mobile']]),
                            'user_id'         => $defaultUser->id ?? null,
                            'emails'          => json_encode(!empty($currentPersonEmail) ? [['value' => $currentPersonEmail, 'label' => 'work']] : []),
                        ];
                        $person = Person::create($personData);
                        if (!$defaultUser) {
                            Log::warning('Nenhum usuário padrão encontrado para atribuir a nova pessoa. A pessoa foi criada sem atribuição de usuário.');
                        }
                    } else {
                        // Se a pessoa já existe, atualiza com as informações mais recentes
                        Log::info('Atendimento concluído. Atualizando pessoa existente.');
                        $updatePersonData = [];
                        if ($person->name !== $currentPersonName) {
                            $updatePersonData['name'] = $currentPersonName;
                        }
                        if (!empty($currentPersonEmail) && (json_decode($person->emails, true)[0]['value'] ?? null) !== $currentPersonEmail) {
                            $emails = json_decode($person->emails, true) ?? [];
                            $emails[] = ['value' => $currentPersonEmail, 'label' => 'work'];
                            $updatePersonData['emails'] = json_encode($emails);
                        }
                        if (!empty($updatePersonData)) {
                            $person->update($updatePersonData);
                        }
                    }

                    // Associar organização à pessoa (se houver)
                    if (!empty($currentPersonCompany)) {
                        $organization = Organization::firstOrCreate(['name' => $currentPersonCompany]);
                        if (optional($person)->organization_id !== $organization->id) {
                            optional($person)->update(['organization_id' => $organization->id]);
                            Log::info('Pessoa associada à organização: ' . $organization->name);
                        }
                    }

                    // Criar ou atualizar o lead
                    $lead = Lead::where('person_id', optional($person)->id)
                                ->whereIn('status', ['open', 'new'])
                                ->first();

                    if (!$lead) {
                        Log::info('Criando novo lead para a pessoa: ' . optional($person)->name);
                        $whatsappSource = Source::firstOrCreate(['name' => 'WhatsApp'], ['code' => 'whatsapp']);
                        
                        $leadTitle = 'Lead WhatsApp de ' . optional($person)->name . ($currentPersonCompany ? ' (' . $currentPersonCompany . ')' : '');

                        $lead = Lead::create([
                            'title'               => $leadTitle,
                            'lead_pipeline_id'    => 1,
                            'lead_pipeline_stage_id' => 1,
                            'lead_source_id'      => $whatsappSource->id ?? null,
                            'lead_type_id'        => 1,
                            'user_id'             => optional($person)->user_id,
                            'person_id'           => optional($person)->id,
                            'expected_close_date' => now()->addDays(7),
                            'status'              => 'new',
                            'lead_value'          => 0,
                            'description'         => $text,
                        ]);
                        Log::info('Novo lead criado:', ['lead_id' => $lead->id]);
                    } else {
                        Log::info('Lead existente encontrado, atualizando título se necessário:', ['lead_id' => $lead->id]);
                        if ($currentPersonCompany && !str_contains($lead->title, $currentPersonCompany)) {
                            $lead->update(['title' => 'Lead WhatsApp de ' . optional($person)->name . ' (' . $currentPersonCompany . ')']);
                        }
                    }
                } else {
                    // Ainda aguardando o nome da empresa
                    $preAttendanceText = "Olá, " . $currentPersonName . "! Qual o nome da empresa que você representa?";
                    $nextState = 'awaiting_company';
                }
            } elseif ($conversationState === 'completed') {
                // Se o estado já está completo, apenas confirma o recebimento da mensagem
                $preAttendanceText = "Olá novamente, " . $currentPersonName . "! Já recebemos suas informações. Um especialista entrará em contato em breve para te ajudar.";
                // Tenta encontrar o lead para logar corretamente
                $lead = Lead::where('person_id', optional($person)->id)->first();
            } else {
                // Estado desconhecido (fallback), volta para a saudação inicial
                $preAttendanceText = "Olá! Bem-vindo(a) à PipeGrow CRM. Qual é o seu nome completo?";
                $nextState = 'awaiting_name';
            }

            // Salva o próximo estado da conversa no cache
            Cache::put($cacheKeyConversationState, $nextState, now()->addMinutes(60));


            // --- 4. Adicionar a mensagem original e a resposta do assistente como ATIVIDADES ---
            $activityData = [
                'type'          => 'whatsapp_message',
                'description'   => 'Mensagem original de ' . $from . ': ' . $text,
                'person_id'     => optional($person)->id, // Usar optional para pessoa pode ser null
                'user_id'       => optional($person)->user_id, // Usar optional para user_id
                'is_done'       => 1,
                'schedule_from' => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now(),
                'schedule_to'   => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now(),
            ];

            if ($lead) { // Verifica se $lead foi definido (se o atendimento foi concluído)
                $activityData['title'] = 'Mensagem WhatsApp Recebida (Lead: ' . $lead->title . ')';
                $activityData['lead_id'] = $lead->id;
            } else {
                $activityData['title'] = 'Mensagem WhatsApp Recebida';
                // Se o lead ainda não foi criado, remove o lead_id para evitar erro
                unset($activityData['lead_id']); 
            }
            Activity::create($activityData);
            Log::info('Mensagem original do WhatsApp adicionada como atividade.');

            $activityData['type'] = 'whatsapp_message_auto_response';
            $activityData['description'] = 'Resposta do assistente: ' . $preAttendanceText;
            $activityData['schedule_from'] = now();
            $activityData['schedule_to'] = now();

            if ($lead) { // Verifica se $lead foi definido
                $activityData['title'] = 'Resposta Automática (Lead: ' . $lead->title . ')';
                $activityData['lead_id'] = $lead->id;
            } else {
                $activityData['title'] = 'Resposta Automática';
                // Se o lead ainda não foi criado, remove o lead_id para evitar erro
                unset($activityData['lead_id']);
            }
            Activity::create($activityData);
            Log::info('Resposta do assistente adicionada como atividade.');

            Log::info('Processamento da mensagem do WhatsApp concluído.', [
                'lead_id' => optional($lead)->id ?? 'N/A (Lead não criado)',
                'person_id' => optional($person)->id ?? 'N/A (Pessoa não criada)',
                'from' => $from,
                'message' => $text,
                'current_state' => $nextState
            ]);

            // --- 6. Enviar resposta de volta para o WhatsApp ---
            $this->sendWhatsappMessage($from, $preAttendanceText);

            // Marca a mensagem de webhook como processada no cache de idempotência
            if ($messageId) {
                Cache::put($cacheKeyIdempotency, true, now()->addMinutes(60));
            }

        } catch (\Exception $e) {
            Log::error('Erro ao processar mensagem do WhatsApp: ' . $e->getMessage(), [
                'message_data' => $this->messageData,
                'exception' => $e
            ]);
        }
    }

    /**
     * Formata um número de telefone brasileiro para incluir o '9' adicional, se necessário.
     *
     * @param string $phoneNumber O número de telefone a ser formatado.
     * @return string O número de telefone formatado.
     */
    protected function formatBrazilianPhoneNumber(string $phoneNumber): string
    {
        Log::info('formatBrazilianPhoneNumber: Input received', ['phoneNumber' => $phoneNumber]);
        // Remove tudo que não for dígito
        $cleanedNumber = preg_replace('/\D/', '', $phoneNumber);
        Log::info('formatBrazilianPhoneNumber: Cleaned number', ['cleanedNumber' => $cleanedNumber]);

        // Verifica se é um número brasileiro (começa com 55)
        if (str_starts_with($cleanedNumber, '55')) {
            $ddd = substr($cleanedNumber, 2, 2); // Pega o DDD (ex: 62)
            $localNumber = substr($cleanedNumber, 4); // Pega o restante do número
            Log::info('formatBrazilianPhoneNumber: Brazilian number detected', ['ddd' => $ddd, 'localNumber' => $localNumber]);

            // Celulares brasileiros têm 9 dígitos após o DDD. Se o número local tem 8, adicionamos o '9'.
            // Ex: 556281234567 (12 dígitos) -> 5562981234567 (13 dígitos)
            // Ex: 5562994123173 (13 dígitos) - já está ok
            if (strlen($localNumber) === 8) {
                $formattedNumber = '55' . $ddd . '9' . $localNumber;
                Log::info('formatBrazilianPhoneNumber: Added 9th digit', ['formattedNumber' => $formattedNumber]);
                return $formattedNumber;
            }
        }

        Log::info('formatBrazilianPhoneNumber: No 9th digit added or not Brazilian', ['finalNumber' => $cleanedNumber]);
        return $cleanedNumber; // Retorna o número limpo se não for brasileiro ou já estiver formatado
    }

    /**
     * Faz a chamada à API do Gemini 2.5 para extrair dados com base no estado da conversa.
     *
     * @param string $message O texto da mensagem do usuário.
     * @param string $knownContactName O nome do contato já conhecido (do CRM).
     * @param string $knownContactEmail O email do contato já conhecido (do CRM).
     * @param string $knownContactCompany O nome da empresa do contato já conhecido (do CRM).
     * @param string $from O número de telefone formatado do remetente (para chave de cache).
     * @param string $conversationState O estado atual da conversa (e.g., 'awaiting_name', 'awaiting_company').
     * @return array A resposta processada do Gemini.
     */
    protected function callGeminiAPI(string $message, string $knownContactName, string $knownContactEmail, string $knownContactCompany, string $from, string $conversationState): array
    {
        $apiKey = env('GEMINI_API_KEY');
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

        // Chave para o histórico de conversa no cache (baseada no número do remetente)
        $cacheKeyConversation = 'whatsapp_conversation_history_' . $from;
        // Carrega o histórico de conversa do cache
        $conversationHistory = Cache::get($cacheKeyConversation, []);
        Log::info('Histórico de conversa carregado do cache em callGeminiAPI:', ['from' => $from, 'history_length' => count($conversationHistory)]);

        // Adiciona a mensagem atual do usuário ao histórico ANTES de enviar para o Gemini
        $conversationHistory[] = ['role' => 'user', 'parts' => [['text' => $message]]];
        Log::info('Mensagem do usuário adicionada ao histórico ANTES da chamada Gemini.', ['from' => $from, 'history_length' => count($conversationHistory)]);

        // Define a instrução do sistema com base no estado atual da conversa
        $systemInstructionText = "Você é um assistente de pré-atendimento de vendas via WhatsApp para a PipeGrow CRM. Seu objetivo é extrair informações específicas do cliente com base no estado atual da conversa.

        Instruções gerais:
        - Sua resposta DEVE ser APENAS um objeto JSON válido e COMPLETO.
        - Certifique-se de que TODAS as chaves JSON esperadas (contact_name, contact_email, contact_company) estejam presentes.
        - O valor de 'contact_email' DEVE ser uma string vazia (\" \").
        - O valor de 'contact_name' DEVE ser o nome completo do cliente, extraído da *última mensagem do cliente* se fornecido, OU o 'knownContactName' do contexto se não houver um novo nome na última mensagem. Se 'knownContactName' for 'Não conhecido', então use \"\".
        - O valor de 'contact_company' DEVE ser o nome da empresa do cliente, extraído da *última mensagem do cliente* se fornecido, OU o 'knownContactCompany' do contexto se não houver um novo nome de empresa na última mensagem. Se 'knownContactCompany' for 'Não conhecido', então use \"\".
        - O campo 'pre_attendance_text' DEVE ser uma string vazia (\" \"). O texto da resposta ao cliente será gerado no backend.

        Contexto atual do cliente (informações já conhecidas do CRM):
        - Nome: '" . ($knownContactName === 'Não conhecido' ? '' : $knownContactName) . "'
        - Empresa: '" . ($knownContactCompany === 'Não conhecido' ? '' : $knownContactCompany) . "'
        - Estado da conversa: '{$conversationState}'

        Com base na última mensagem do cliente: \"{$message}\", extraia a informação relevante para o estado '{$conversationState}' e preencha o JSON.

        Lógica de extração baseada no estado:
        - Se o estado for 'awaiting_name' ou 'initial_greeting': Tente extrair o nome completo do cliente da última mensagem.
        - Se o estado for 'awaiting_company': Tente extrair o nome da empresa da última mensagem.
        - Se o estado for 'completed' ou outro: Apenas extraia qualquer nome ou empresa que possa ser fornecido, mesmo que o estado já seja 'completed'.

        A estrutura JSON COMPLETA esperada é:
        {
            \"pre_attendance_text\": \"\",
            \"contact_name\": \"<nome do contato extraído ou o nome conhecido, ou \"\">\",
            \"contact_email\": \"\",
            \"contact_company\": \"<nome da empresa extraído ou o nome da empresa conhecida, ou \"\">\"
        }
        ";

        // Constrói o array 'contents' para a API do Gemini
        // Adiciona a instrução do sistema como o primeiro turno 'user' se o histórico estiver vazio ou se a instrução mudou
        if (empty($conversationHistory) || !isset($conversationHistory[0]['parts'][0]['text']) || $conversationHistory[0]['parts'][0]['text'] !== $systemInstructionText) {
            array_unshift($conversationHistory, ['role' => 'user', 'parts' => [['text' => $systemInstructionText]]]);
        }
        
        // Usa o histórico de conversa (que agora inclui a mensagem do usuário) para construir o payload para o Gemini
        $contents = $conversationHistory;

        try {
            $response = Http::timeout(60)->post($apiUrl, [
                'contents' => $contents,
                'generationConfig' => [
                    'responseMimeType' => "application/json",
                    "responseSchema" => [
                        "type" => "OBJECT",
                        "properties" => [
                            "pre_attendance_text" => ["type" => "STRING"],
                            "contact_name" => ["type" => "STRING"],
                            "contact_email" => ["type" => "STRING"],
                            "contact_company" => ["type" => "STRING"],
                        ],
                        "propertyOrdering" => [
                            "pre_attendance_text", "contact_name", "contact_email", "contact_company"
                        ],
                    ],
                ],
            ]);

            if ($response->successful()) {
                $result = $response->json();
                if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                    $jsonString = $result['candidates'][0]['content']['parts'][0]['text'];
                    $jsonString = preg_replace('/[[:cntrl:]]/', '', $jsonString);
                    $jsonString = trim($jsonString);

                    $jsonStart = strpos($jsonString, '{');
                    $jsonEnd = strrpos($jsonString, '}');

                    if ($jsonStart !== false && $jsonEnd !== false) {
                        $jsonString = substr($jsonString, $jsonStart, $jsonEnd - $jsonStart + 1);
                    } else {
                        Log::warning('Não foi possível encontrar um objeto JSON completo na resposta do Gemini.', ['raw_gemini_response_text' => $jsonString]);
                        // Se o JSON for inválido, ainda salvamos o histórico com a mensagem do usuário
                        Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
                        return $this->getDefaultGeminiResponse();
                    }

                    $parsedJson = json_decode($jsonString, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        // Adiciona a resposta do modelo ao histórico
                        // O pre_attendance_text do Gemini agora será vazio, mas ainda o adicionamos para manter a estrutura.
                        $conversationHistory[] = ['role' => 'model', 'parts' => [['text' => $parsedJson['pre_attendance_text']]]];
                        Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
                        Log::info('Histórico da conversa atualizado no cache em callGeminiAPI.', ['from' => $from, 'history_length' => count($conversationHistory)]);

                        return $parsedJson;
                    } else {
                        Log::error('Erro ao decodificar JSON da resposta do Gemini: ' . json_last_error_msg(), ['json_string_after_cleaning' => $jsonString]);
                        // Se o JSON for inválido, ainda salvamos o histórico com o histórico atual
                        Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
                        return $this->getDefaultGeminiResponse();
                    }
                }
            } else {
                Log::error('Falha na chamada à API do Gemini:', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                // Se a chamada à API falhar, ainda salvamos o histórico com o histórico atual
                Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
            }
        } catch (\Exception $e) {
            Log::error('Exceção ao chamar a API do Gemini: ' . $e->getMessage());
            // Se ocorrer uma exceção, ainda salvamos o histórico com o histórico atual
            Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
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
            'pre_attendance_text' => '', // Agora vazio, pois o backend gerencia a saudação
            'contact_name'        => 'Não conhecido',
            'contact_email'       => '',
            'contact_company'     => '',
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
        $phoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID');

        Log::info('Tentando enviar mensagem WhatsApp com:', [
            'to' => $to,
            'phoneNumberId' => $phoneNumberId,
            'accessToken_present' => !empty($accessToken)
        ]);

        if (!$accessToken || !$phoneNumberId) {
            Log::error('Erro: WHATSAPP_ACCESS_TOKEN ou WHATSAPP_PHONE_NUMBER_ID não configurados no .env. Não foi possível enviar a mensagem de resposta.');
            return;
        }

        $url = "https://graph.facebook.com/v19.0/{$phoneNumberId}/messages";

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
