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
            
            // --- Tente encontrar a pessoa (contato) para obter o nome e e-mail conhecidos ---
            $person = Person::where('contact_numbers', 'like', '%' . $from . '%')->first();
            $knownContactNameForGemini = 'A ser qualificado';
            $knownContactEmailForGemini = 'A ser qualificado';

            // Chave para o histórico de conversa no cache (baseada no número do remetente)
            $cacheKeyConversation = 'whatsapp_conversation_history_' . $from;
            // Carrega o histórico de conversa do cache
            $conversationHistory = Cache::get($cacheKeyConversation, []);
            Log::info('Histórico de conversa carregado do cache:', ['from' => $from, 'history_length' => count($conversationHistory)]);

            if ($person) {
                $knownContactNameForGemini = $person->name;
                $emails = json_decode($person->emails, true) ?? [];
                if (!empty($emails)) {
                    $knownContactEmailForGemini = $emails[0]['value']; // Pega o primeiro email conhecido
                }
                Log::info('Pessoa existente encontrada para Gemini context:', ['name' => $person->name, 'email' => $knownContactEmailForGemini]);
            } else {
                Log::info('Pessoa não encontrada, Gemini irá começar a qualificação do zero.');
            }

            // --- 1. Pré-atendimento com Gemini 2.5 ---
            // Passamos o nome, email conhecidos e o histórico de conversa para o Gemini
            $geminiResponse = $this->callGeminiAPI($text, $knownContactNameForGemini, $knownContactEmailForGemini, $conversationHistory);
            Log::info('Resposta do Gemini:', ['response' => $geminiResponse]);

            // Usar o nome do Gemini se for específico, caso contrário, usar o inicial ou o padrão
            $contactName = $geminiResponse['contact_name'] ?? null;
            if (empty($contactName) || $contactName === 'A ser qualificado' || $contactName === 'Não mencionado') {
                $contactName = $initialContactName; // Volta para o nome do webhook ou padrão
            }
            
            $contactEmail = $geminiResponse['contact_email'] ?? null;
            // Se o Gemini retornou email 'A ser qualificado' ou 'Não mencionado', consideramos como não encontrado.
            if ($contactEmail === 'A ser qualificado' || $contactEmail === 'Não mencionado') {
                $contactEmail = null;
            }

            $spinData = $geminiResponse['spin_data'] ?? []; // Array associativo com S, P, I, N
            $bantData = $geminiResponse['bant_data'] ?? []; // Array associativo com B, A, N, T
            $preAttendanceText = $geminiResponse['pre_attendance_text'] ?? "Olá! Como posso ajudar você hoje?";


            // --- 2. (Re)Tente encontrar ou criar uma pessoa (contato) após a resposta do Gemini ---
            // Isso é importante caso o Gemini tenha extraído um nome/email que ainda não estava no CRM.
            if (!$person) {
                // Se a pessoa não foi encontrada inicialmente, tenta criar agora com o nome/email do Gemini
                Log::info('Pessoa não encontrada, criando nova pessoa para o número: ' . $from);
                $defaultUser = User::first(); 

                $personData = [
                    'name'            => $contactName, 
                    'contact_numbers' => json_encode([['value' => $from, 'label' => 'mobile']]),
                    'user_id'         => $defaultUser->id ?? null,
                ];

                if ($contactEmail) { // Apenas adiciona email se Gemini retornou algo valido
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
                if ($contactEmail && !in_array($contactEmail, array_column(json_decode($person->emails, true) ?? [], 'value'))) {
                    $emails = json_decode($person->emails, true) ?? [];
                    $emails[] = ['value' => $contactEmail, 'label' => 'work'];
                    $person->update(['emails' => json_encode($emails)]);
                    Log::info('Email da pessoa adicionado/atualizado pelo Gemini: ' . $contactEmail);
                }
                Log::info('Pessoa existente processada: ' . $person->name);
            }

            // --- 3. Lógica para criar ou encontrar um lead associado a esta pessoa ---
            $lead = null;
            $shouldCreateLead = false;

            // Critérios para criar um lead:
            // O nome do contato é mais específico (não é o padrão "Cliente WhatsApp <número>")
            // OU o Gemini conseguiu extrair algum dado de SPIN ou BANT que não seja "Não qualificado" ou vazio.
            if (!str_starts_with($contactName, 'Cliente WhatsApp ') && $contactName !== 'A ser qualificado' && $contactName !== 'Não mencionado') {
                $shouldCreateLead = true;
            } else {
                foreach ($spinData as $key => $value) {
                    if ($value !== 'Não qualificado' && !empty($value)) {
                        $shouldCreateLead = true;
                        break;
                    }
                }
                if (!$shouldCreateLead) {
                    foreach ($bantData as $key => $value) {
                        if ($value !== 'Não qualificado' && !empty($value)) {
                            $shouldCreateLead = true;
                            break;
                        }
                    }
                }
            }

            if ($shouldCreateLead) {
                $lead = Lead::where('person_id', $person->id)
                            ->whereIn('status', ['open', 'new']) // Exemplo: leads em status "aberto" ou "novo"
                            ->first();

                if (!$lead) {
                    Log::info('Critério de criação de lead atendido. Criando novo lead para a pessoa: ' . $person->name);

                    $defaultPipeline = Pipeline::first();
                    $defaultStage = null;

                    if ($defaultPipeline) {
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
            } else {
                Log::info('Critério de criação de lead não atendido. As atividades serão associadas apenas à pessoa por enquanto.');
            }

            // --- 4. Adicionar a mensagem original e a resposta do Gemini como ATIVIDADES ---
            // As atividades serão associadas ao lead se ele existir, caso contrário, apenas à pessoa.
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

            // --- 5. Salvar dados de SPIN e BANT como ATIVIDADES do tipo 'note' ---
            if (!empty($spinData)) {
                $spinNote = "Dados SPIN:\n";
                foreach ($spinData as $key => $value) {
                    if ($value !== 'Não qualificado' && !empty($value)) { // Salvar apenas dados qualificados
                        $spinNote .= ucfirst($key) . ": " . $value . "\n";
                    }
                }
                if (strlen($spinNote) > 13) { // Se houver algo além do cabeçalho
                    $activityData['title'] = 'Qualificação SPIN';
                    $activityData['description'] = $spinNote;
                    $activityData['type'] = 'note';
                    $activityData['schedule_from'] = now();
                    $activityData['schedule_to'] = now();

                    if ($lead) {
                        $activityData['lead_id'] = $lead->id;
                    } else {
                        unset($activityData['lead_id']); // Garante que não há lead_id se o lead não foi criado
                    }
                    Activity::create($activityData);
                    Log::info('Dados SPIN adicionados como nota de atividade.');
                }
            }

            if (!empty($bantData)) {
                $bantNote = "Dados BANT:\n";
                foreach ($bantData as $key => $value) {
                    if ($value !== 'Não qualificado' && !empty($value)) { // Salvar apenas dados qualificados
                        $bantNote .= ucfirst($key) . ": " . $value . "\n";
                    }
                }
                if (strlen($bantNote) > 13) { // Se houver algo além do cabeçalho
                    $activityData['title'] = 'Qualificação BANT';
                    $activityData['description'] = $bantNote;
                    $activityData['type'] = 'note';
                    $activityData['schedule_from'] = now();
                    $activityData['schedule_to'] = now();

                    if ($lead) {
                        $activityData['lead_id'] = $lead->id;
                    } else {
                        unset($activityData['lead_id']); // Garante que não há lead_id se o lead não foi criado
                    }
                    Activity::create($activityData);
                    Log::info('Dados BANT adicionados como nota de atividade.');
                }
            }

            Log::info('Processamento da mensagem do WhatsApp concluído.', [
                'lead_id' => $lead->id ?? 'N/A (Lead não criado)',
                'person_id' => $person->id,
                'from' => $from,
                'message' => $text
            ]);

            // --- 6. Enviar resposta de volta para o WhatsApp ---
            $this->sendWhatsappMessage($from, $preAttendanceText);

            // --- 7. Salvar o histórico da conversa no cache ---
            // Adiciona a mensagem do usuário e a resposta do modelo ao histórico
            $conversationHistory[] = ['role' => 'user', 'parts' => [['text' => $text]]];
            $conversationHistory[] = ['role' => 'model', 'parts' => [['text' => $preAttendanceText]]];
            
            // Define um tempo de vida para o cache (ex: 60 minutos)
            Cache::put($cacheKeyConversation, $conversationHistory, now()->addMinutes(60)); 
            Log::info('Histórico da conversa atualizado no cache.', ['from' => $from, 'history_length' => count($conversationHistory)]);

            // Marca a mensagem de webhook como processada no cache de idempotência
            if ($messageId) {
                Cache::put($cacheKeyIdempotency, true, now()->addMinutes(60)); // Armazena por 60 minutos
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
     * Faz a chamada à API do Gemini 2.5 para pré-atendimento.
     *
     * @param string $message O texto da mensagem do usuário.
     * @param string $knownContactName O nome do contato já conhecido (do CRM).
     * @param string $knownContactEmail O email do contato já conhecido (do CRM).
     * @param array $conversationHistory O histórico da conversa com o Gemini.
     * @return array A resposta processada do Gemini.
     */
    protected function callGeminiAPI(string $message, string $knownContactName, string $knownContactEmail, array $conversationHistory): array
    {
        $apiKey = env('GEMINI_API_KEY');
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

        // Define o contexto atual para o Gemini
        $contextualPrompt = "Contexto atual: ";
        $nameKnown = !($knownContactName === 'A ser qualificado' || str_starts_with($knownContactName, 'Cliente WhatsApp'));
        $emailKnown = !($knownContactEmail === 'A ser qualificado' || $knownContactEmail === 'Não mencionado');

        if ($nameKnown) {
            $contextualPrompt .= "O nome do cliente JÁ é conhecido e é '{$knownContactName}'. ";
        } else {
            $contextualPrompt .= "O nome do cliente AINDA NÃO é conhecido. ";
        }

        if ($emailKnown) {
            $contextualPrompt .= "O e-mail do cliente JÁ é conhecido e é '{$knownContactEmail}'.";
        } else {
            $contextualPrompt .= "O e-mail do cliente AINDA NÃO é conhecido.";
        }

        // Prompt aprimorado para guiar o fluxo da conversa: Nome > SPIN/BANT > Email
        // Este prompt é a instrução "primária" para o modelo, enviada no primeiro turno do histórico.
        $systemInstructionText = "Você é um assistente de pré-atendimento de vendas. Seu objetivo é qualificar leads pelo WhatsApp, seguindo a seguinte ordem de prioridade para coletar informações:
        1.  **Nome completo do cliente**: Peça o nome se ainda não o tiver.
        2.  **Qualificação SPIN/BANT**: Faça perguntas baseadas em SPIN (Situação, Problema, Implicação, Necessidade de Solução) e BANT (Budget, Authority, Need, Timeline) para entender as necessidades do cliente.
        3.  **Endereço de e-mail do cliente**: Peça o e-mail por último, após alguma qualificação inicial.

        {$contextualPrompt}

        Com base na análise da mensagem e do contexto atual, crie um texto de pré-atendimento amigável e profissional para o cliente ('pre_attendance_text'), seguindo ESTA lógica de prioridade estrita para a PRÓXIMA pergunta:
        - SE o nome do cliente NÃO é conhecido:
            - SE a mensagem ATUAL contém o nome do cliente: o 'pre_attendance_text' DEVE agradecer pelo nome e FAZER UMA PERGUNTA DE QUALIFICAÇÃO (SPIN/BANT).
            - CASO CONTRÁRIO (a mensagem ATUAL NÃO contém o nome): o 'pre_attendance_text' DEVE pedir o nome completo.
        - SENÃO (o nome do cliente JÁ é conhecido):
            - SE a mensagem ATUAL CONTÉM informações de qualificação (SPIN/BANT): o 'pre_attendance_text' DEVE reconhecer a informação e, SE o e-mail NÃO é conhecido, pedir o e-mail. CASO CONTRÁRIO, se o e-mail JÁ é conhecido, o 'pre_attendance_text' deve oferecer ajuda adicional.
            - CASO CONTRÁRIO (a mensagem ATUAL NÃO contém informações de qualificação): o 'pre_attendance_text' DEVE fazer uma pergunta de qualificação (SPIN/BANT).
        
        Sua resposta DEVE ser APENAS um objeto JSON válido, sem texto adicional, formatação, ou caracteres extras antes ou depois do JSON. As chaves do JSON devem ser:
        - 'pre_attendance_text': O texto de pré-atendimento para o cliente.
        - 'contact_name': O nome completo do cliente que você conseguiu extrair da mensagem ATUAL. Se não encontrar um nome claro na mensagem ATUAL, use o valor do CONTEXTO ATUAL ('{$knownContactName}').
        - 'contact_email': O e-mail do cliente que você conseguiu extrair da mensagem ATUAL. Se não encontrar, use o valor do CONTEXTO ATUAL ('{$knownContactEmail}').
        - 'spin_data': Um objeto JSON com as chaves 'situacao', 'problema', 'implicacao', 'necessidade'. Mantenha as descrições CONCISAS (no máximo 1 frase) ou use 'Não qualificado' se a informação não for clara na mensagem ATUAL.
        - 'bant_data': Um objeto JSON com as chaves 'budget', 'authority', 'need', 'timeline'. Mantenha as descrições CONCISAS (no máximo 1 frase) ou use 'Não qualificado' se a informação não for clara na mensagem ATUAL.
        ";

        // Constrói o array 'contents' para a API do Gemini
        $contents = [];

        // Adiciona a instrução do sistema como o primeiro turno 'user' se o histórico estiver vazio
        // ou se o histórico não começar com a instrução do sistema (para garantir que ela esteja sempre lá)
        if (empty($conversationHistory) || ($conversationHistory[0]['role'] !== 'user' || $conversationHistory[0]['parts'][0]['text'] !== $systemInstructionText)) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => $systemInstructionText]]];
        }
        
        // Adiciona os turnos existentes do histórico (se houver e se já não adicionamos a instrução)
        foreach ($conversationHistory as $turn) {
            // Evita adicionar a instrução do sistema novamente se ela já foi adicionada no início
            if ($turn['role'] === 'user' && $turn['parts'][0]['text'] === $systemInstructionText && !empty($contents)) {
                continue;
            }
            $contents[] = $turn;
        }

        // Adiciona a mensagem atual do usuário como o último turno 'user'
        $contents[] = ['role' => 'user', 'parts' => [['text' => "Mensagem do cliente: \"{$message}\""]]];

        try {
            $response = Http::timeout(60)->post($apiUrl, [ // Aumentado o tempo limite para 60 segundos
                'contents' => $contents,
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

                    // Remover caracteres de controle inválidos e espaços/quebras de linha extras ANTES de procurar as chaves JSON
                    $jsonString = preg_replace('/[[:cntrl:]]/', '', $jsonString); // Remove caracteres de controle
                    $jsonString = trim($jsonString); // Remove espaços em branco (incluindo quebras de linha) do início e fim

                    // Tentar encontrar o início e o fim do objeto JSON
                    $jsonStart = strpos($jsonString, '{');
                    $jsonEnd = strrpos($jsonString, '}');

                    if ($jsonStart !== false && $jsonEnd !== false) {
                        $jsonString = substr($jsonString, $jsonStart, $jsonEnd - $jsonStart + 1);
                    } else {
                        Log::warning('Não foi possível encontrar um objeto JSON completo na resposta do Gemini.', ['raw_gemini_response_text' => $jsonString]);
                        return $this->getDefaultGeminiResponse();
                    }

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
            'pre_attendance_text' => "Olá! Recebemos sua mensagem. Para que eu possa te ajudar melhor, poderia me dizer qual é o seu nome completo?", // Alterado para pedir apenas o nome
            'contact_name'        => 'A ser qualificado', // Usar "A ser qualificado" como placeholder padrão
            'contact_email'       => 'A ser qualificado', // Usar "A ser qualificado" como placeholder padrão
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
