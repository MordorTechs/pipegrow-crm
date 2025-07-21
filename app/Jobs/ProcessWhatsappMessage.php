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

            // --- Extrair nome do contato do payload do webhook (se disponível) ---
            $initialContactName = $this->messageData['contacts'][0]['profile']['name'] ?? ('Cliente WhatsApp ' . $from);
            
            // --- Tente encontrar a pessoa (contato) para obter o nome, e-mail e empresa conhecidos ---
            $person = Person::where('contact_numbers', 'like', '%' . $from . '%')->first();
            $knownContactNameForGemini = 'Não conhecido';
            $knownContactEmailForGemini = 'Não conhecido';
            $knownContactCompanyForGemini = 'Não conhecido'; // Adicionado para o nome da empresa

            if ($person) {
                $knownContactNameForGemini = $person->name;
                $emails = json_decode($person->emails, true) ?? [];
                if (!empty($emails)) {
                    $knownContactEmailForGemini = $emails[0]['value'];
                }
                if ($person->organization) {
                    $knownContactCompanyForGemini = $person->organization->name;
                }
                Log::info('Pessoa existente encontrada para Gemini context:', [
                    'name' => $person->name,
                    'email' => $knownContactEmailForGemini,
                    'company' => $knownContactCompanyForGemini
                ]);
            } else {
                Log::info('Pessoa não encontrada, Gemini irá começar a qualificação do zero.');
            }

            // --- 1. Pré-atendimento com Gemini 2.5 ---
            // Passamos o nome, email e empresa conhecidos e o número 'from' para o Gemini gerenciar o histórico
            $geminiResponse = $this->callGeminiAPI($text, $knownContactNameForGemini, $knownContactEmailForGemini, $knownContactCompanyForGemini, $from);
            Log::info('Resposta do Gemini:', ['response' => $geminiResponse]);

            // Usar o nome do Gemini se for específico, caso contrário, usar o inicial ou o padrão
            $contactName = $geminiResponse['contact_name'] ?? null;
            if (empty($contactName) || $contactName === 'Não conhecido' || $contactName === 'Não mencionado') {
                $contactName = $initialContactName; // Volta para o nome do webhook ou padrão
            }
            
            // Garante que contactEmail seja uma string vazia se não for válido
            $contactEmail = $geminiResponse['contact_email'] ?? '';
            if ($contactEmail === 'Não conhecido' || $contactEmail === 'Não mencionado') {
                $contactEmail = '';
            }

            // Garante que contactCompany seja uma string vazia se não for válido
            $contactCompany = $geminiResponse['contact_company'] ?? '';
            if (empty($contactCompany) || $contactCompany === 'Não conhecido' || $contactCompany === 'Não mencionado') {
                $contactCompany = '';
            }
            
            $preAttendanceText = $geminiResponse['pre_attendance_text'] ?? "Olá! Como posso ajudar você hoje?";


            // --- 2. (Re)Tente encontrar ou criar uma pessoa (contato) após a resposta do Gemini ---
            // Isso é importante caso o Gemini tenha extraído um nome/email/empresa que ainda não estava no CRM.
            if (!$person) {
                Log::info('Pessoa não encontrada, criando nova pessoa para o número: ' . $from);
                $defaultUser = User::first(); 

                $personData = [
                    'name'            => $contactName, 
                    // Garante que contact_numbers seja um JSON array, mesmo que vazio
                    'contact_numbers' => json_encode([['value' => $from, 'label' => 'mobile']]),
                    'user_id'         => $defaultUser->id ?? null,
                ];

                // Garante que emails seja um JSON array, mesmo que vazio
                if (!empty($contactEmail)) {
                    $personData['emails'] = json_encode([['value' => $contactEmail, 'label' => 'work']]);
                } else {
                    $personData['emails'] = json_encode([]);
                }

                $person = Person::create($personData);

                if (!$defaultUser) {
                    Log::warning('Nenhum usuário padrão encontrado para atribuir a nova pessoa. A pessoa foi criada sem atribuição de usuário.');
                }
            } else {
                // Se a pessoa já existia, tenta atualizar o nome se o Gemini forneceu um nome mais específico
                if ($contactName !== $person->name && !str_starts_with($contactName, 'Cliente WhatsApp ')) {
                    $person->update(['name' => $contactName]);
                    Log::info('Nome da pessoa atualizado pelo Gemini: ' . $contactName);
                }
                // Tenta atualizar o email se o Gemini forneceu um email e ele ainda não existe
                if (!empty($contactEmail) && !in_array($contactEmail, array_column(json_decode($person->emails, true) ?? [], 'value'))) {
                    $emails = json_decode($person->emails, true) ?? [];
                    $emails[] = ['value' => $contactEmail, 'label' => 'work'];
                    $person->update(['emails' => json_encode($emails)]);
                    Log::info('Email da pessoa adicionado/atualizado pelo Gemini: ' . $contactEmail);
                }
                // Tenta atualizar a organização se o Gemini forneceu um nome de empresa e ele ainda não está associado
                if (!empty($contactCompany) && (!$person->organization || $person->organization->name !== $contactCompany)) {
                    $organization = Organization::firstOrCreate(['name' => $contactCompany]);
                    $person->update(['organization_id' => $organization->id]);
                    Log::info('Pessoa associada à organização: ' . $organization->name);
                }
                Log::info('Pessoa existente processada: ' . $person->name);
            }

            // --- Lidar com o nome da empresa (já feito no bloco acima) ---


            // --- 3. Lógica para criar ou encontrar um lead associado a esta pessoa ---
            $lead = Lead::where('person_id', $person->id)
                        ->whereIn('status', ['open', 'new'])
                        ->first();

            if (!$lead) {
                Log::info('Criando novo lead para a pessoa: ' . $person->name);

                $defaultPipeline = Pipeline::first();
                $defaultStage = null;

                if ($defaultPipeline) {
                    $defaultStage = Stage::where('lead_pipeline_id', $defaultPipeline->id)->orderBy('sort_order')->first();
                }

                $whatsappSource = Source::firstOrCreate(['name' => 'WhatsApp'], ['code' => 'whatsapp']);
                $defaultType = Type::first();

                $lead = Lead::create([
                    'title'               => 'Lead WhatsApp de ' . $contactName . ($contactCompany ? ' (' . $contactCompany . ')' : ''),
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
                // Atualiza o título do lead se o nome da empresa for coletado posteriormente
                if ($contactCompany && !str_contains($lead->title, $contactCompany)) {
                    $lead->update(['title' => 'Lead WhatsApp de ' . $contactName . ' (' . $contactCompany . ')']);
                }
            }

            // --- 4. Adicionar a mensagem original e a resposta do Gemini como ATIVIDADES ---
            $activityData = [
                'type'          => 'whatsapp_message',
                'description'   => 'Mensagem original de ' . $from . ': ' . $text,
                'person_id'     => $person->id,
                'user_id'       => $person->user_id,
                'is_done'       => 1,
                'schedule_from' => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now(),
                'schedule_to'   => $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp) : now(),
            ];

            if ($lead) {
                $activityData['title'] = 'Mensagem WhatsApp Recebida (Lead: ' . $lead->title . ')';
                $activityData['lead_id'] = $lead->id;
            } else {
                $activityData['title'] = 'Mensagem WhatsApp Recebida';
            }
            Activity::create($activityData);
            Log::info('Mensagem original do WhatsApp adicionada como atividade.');

            $activityData['type'] = 'whatsapp_message_auto_response';
            $activityData['description'] = 'Resposta do Gemini (pré-atendimento): ' . $preAttendanceText;
            $activityData['schedule_from'] = now();
            $activityData['schedule_to'] = now();

            if ($lead) {
                $activityData['title'] = 'Resposta Automática (Gemini) (Lead: ' . $lead->title . ')';
            } else {
                $activityData['title'] = 'Resposta Automática (Gemini)';
            }
            Activity::create($activityData);
            Log::info('Resposta do Gemini adicionada como atividade.');

            // --- Removida a lógica de salvar dados de SPIN e BANT como ATIVIDADES ---

            Log::info('Processamento da mensagem do WhatsApp concluído.', [
                'lead_id' => $lead->id ?? 'N/A (Lead não criado)',
                'person_id' => $person->id,
                'from' => $from,
                'message' => $text
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
     * Faz a chamada à API do Gemini 2.5 para pré-atendimento e qualificação.
     *
     * @param string $message O texto da mensagem do usuário.
     * @param string $knownContactName O nome do contato já conhecido (do CRM).
     * @param string $knownContactEmail O email do contato já conhecido (do CRM).
     * @param string $knownContactCompany O nome da empresa do contato já conhecido (do CRM).
     * @param string $from O número de telefone formatado do remetente (para chave de cache).
     * @return array A resposta processada do Gemini.
     */
    protected function callGeminiAPI(string $message, string $knownContactName, string $knownContactEmail, string $knownContactCompany, string $from): array
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

        $systemInstructionText = "Você é um assistente de pré-atendimento de vendas via WhatsApp para a PipeGrow CRM. Seu objetivo é coletar o nome do cliente e o nome da empresa.

        Instruções gerais:
        - Sua resposta DEVE ser APENAS um objeto JSON válido e COMPLETO.
        - Certifique-se de que TODAS as chaves JSON esperadas (pre_attendance_text, contact_name, contact_email, contact_company) estejam presentes.
        - Os valores de 'contact_email' devem ser uma string vazia (\" \").
        - Os valores de 'contact_name' e 'contact_company' devem ser o dado qualificado ou \"\" se não obtido.
        - Mantenha a conversa fluida e natural, fazendo UMA pergunta por vez no 'pre_attendance_text'.

        Contexto atual do cliente (informações já conhecidas):
        - Nome: '" . ($knownContactName === 'Não conhecido' ? '' : $knownContactName) . "'
        - Empresa: '" . ($knownContactCompany === 'Não conhecido' ? '' : $knownContactCompany) . "'

        Com base no contexto e na última mensagem do cliente: \"{$message}\", determine a próxima ação e preencha o JSON.

        Lógica para 'pre_attendance_text' (a mensagem para o cliente):
        1. Se o nome do cliente no contexto for vazio: Pergunte o nome completo.
        2. Se o nome do cliente for conhecido, mas a empresa no contexto for vazia: Pergunte o nome da empresa.
        3. Se nome e empresa forem conhecidos: Informe que um especialista entrará em contato em breve.

        A estrutura JSON COMPLETA esperada é:
        {
            \"pre_attendance_text\": \"<texto de pré-atendimento, contendo a próxima pergunta ou a finalização>\",
            \"contact_name\": \"<nome do contato ou \"\">\",
            \"contact_email\": \"\",
            \"contact_company\": \"<nome da empresa ou \"\">\"
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
                            "contact_company" => ["type" => "STRING"], // Adicionado o nome da empresa
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
                        $conversationHistory[] = ['role' => 'model', 'parts' => [['text' => $parsedJson['pre_attendance_text']]]];
                        Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
                        Log::info('Histórico da conversa atualizado no cache em callGeminiAPI.', ['from' => $from, 'history_length' => count($conversationHistory)]);

                        return $parsedJson;
                    } else {
                        Log::error('Erro ao decodificar JSON da resposta do Gemini: ' . json_last_error_msg(), ['json_string_after_cleaning' => $jsonString]);
                        // Se o JSON for inválido, ainda salvamos o histórico com a mensagem do usuário
                        Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
                        return $this->getDefaultGeminiResponse();
                    }
                }
            } else {
                Log::error('Falha na chamada à API do Gemini:', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                // Se a chamada à API falhar, ainda salvamos o histórico com a mensagem do usuário
                Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60));
            }
        } catch (\Exception $e) {
            Log::error('Exceção ao chamar a API do Gemini: ' . $e->getMessage());
            // Se ocorrer uma exceção, ainda salvamos o histórico com a mensagem do usuário
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
            'pre_attendance_text' => "Olá! Recebemos sua mensagem. Houve um pequeno problema na minha resposta, mas não se preocupe, um membro da nossa equipe entrará em contato em breve para te ajudar!",
            'contact_name'        => 'Não conhecido',
            'contact_email'       => '', // Alterado para string vazia
            'contact_company'     => '', // Alterado para string vazia
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
