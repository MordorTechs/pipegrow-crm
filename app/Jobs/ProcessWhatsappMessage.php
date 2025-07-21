<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Models\WhatsappSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http; // Para fazer requisições HTTP para a API do Gemini
use Exception; // Para tratamento de erros

class ProcessWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageData;

    /**
     * Cria uma nova instância do job.
     *
     * @param array $messageData Os dados da mensagem de WhatsApp recebida.
     * @return void
     */
    public function __construct(array $messageData)
    {
        $this->messageData = $messageData;
    }

    /**
     * Executa o job.
     *
     * Este método lida com a mensagem de WhatsApp recebida, gerencia o estado da conversa
     * para qualificação de leads (SPIN/BANT), interage com a API do Gemini para respostas
     * personalizadas e cria condicionalmente um lead na base de dados.
     *
     * @return void
     */
    public function handle(): void
    {
        // Acesso correto ao número de telefone do remetente
        $from = $this->messageData['from'];

        // CORREÇÃO: Acessar o 'body' da mensagem de texto
        // O log mostra que $this->messageData['text'] é um array, e a mensagem está em ['text']['body']
        $text = $this->messageData['text']['body'] ?? null;

        // Se a mensagem não tiver um corpo de texto (ex: imagem, vídeo, etc.), podemos ignorar ou lidar de outra forma
        if (empty($text)) {
            Log::warning("ProcessWhatsappMessage: Mensagem não textual ou texto vazio recebido de {$from}. Ignorando.");
            return;
        }

        Log::info("ProcessWhatsappMessage: Mensagem recebida de {$from}: {$text}");

        // Encontra ou cria uma sessão de WhatsApp para o utilizador
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $from],
            [
                'conversation_history' => [],
                'qualification_data'   => [],
                'current_stage'        => 'ask_name', // Novo estágio inicial: pedir nome
            ]
        );

        // A lógica para redefinir a conversa com base em palavras-chave foi removida.
        // A sessão agora manterá o histórico e o estado continuamente.


        // Adiciona a mensagem do utilizador ao histórico da conversa
        $history = $session->conversation_history ?? [];
        $history[] = ['role' => 'user', 'parts' => [['text' => $text]]];
        $session->conversation_history = $history;

        $responseMessage = '';

        try {
            // Determina o próximo passo com base na fase atual
            switch ($session->current_stage) {
                case 'ask_name':
                    if (! empty($session->qualification_data['name'])) {
                        // O nome já foi coletado.
                        // Prossegue diretamente para a pergunta SPIN 'S'.
                        $name = $session->qualification_data['name'];
                        $responseMessage = "Olá novamente, {$name}! ";
                        $responseMessage .= $this->askSpinQuestion('S', $session); // Passa o nome para a pergunta
                        $session->current_stage = 'spin_s';
                    } else {
                        // O usuário respondeu ao pedido de nome
                        $session->qualification_data = array_merge($session->qualification_data, ['name' => $text]); // Assume que a primeira resposta é o nome
                        $name = $session->qualification_data['name'];
                        $responseMessage = "Obrigado, {$name}! ";
                        $responseMessage .= $this->askSpinQuestion('S', $session); // Passa o nome para a pergunta
                        $session->current_stage = 'spin_s';
                    }
                    break;

                case 'initial': // Este caso agora só será atingido se 'ask_name' não for o primeiro estágio
                    // Isso pode ser um fallback, mas com 'ask_name' como inicial, 'initial' não deve ser o primeiro.
                    // Mantido para compatibilidade, mas a lógica de 'ask_name' é prioritária.
                    // Se por algum motivo cair aqui, ainda queremos a pergunta S do SPIN.
                    $responseMessage = $this->askSpinQuestion('S', $session);
                    $session->current_stage = 'spin_s';
                    break;

                case 'spin_s':
                    $session->qualification_data = array_merge($session->qualification_data, ['spin_situation' => $text]);
                    $responseMessage = $this->askSpinQuestion('P', $session);
                    $session->current_stage = 'spin_p';
                    break;

                case 'spin_p':
                    $session->qualification_data = array_merge($session->qualification_data, ['spin_problem' => $text]);
                    $responseMessage = $this->askSpinQuestion('I', $session);
                    $session->current_stage = 'spin_i';
                    break;

                case 'spin_i':
                    $session->qualification_data = array_merge($session->qualification_data, ['spin_implication' => $text]);
                    $responseMessage = $this->askSpinQuestion('N', $session);
                    $session->current_stage = 'spin_n';
                    break;

                case 'spin_n':
                    $session->qualification_data = array_merge($session->qualification_data, ['spin_need_payoff' => $text]);
                    $responseMessage = $this->askBantQuestion('B', $session);
                    $session->current_stage = 'bant_b';
                    break;

                case 'bant_b':
                    $session->qualification_data = array_merge($session->qualification_data, ['bant_budget' => $text]);
                    $responseMessage = $this->askBantQuestion('A', $session);
                    $session->current_stage = 'bant_a';
                    break;

                case 'bant_a':
                    $session->qualification_data = array_merge($session->qualification_data, ['bant_authority' => $text]);
                    $responseMessage = $this->askBantQuestion('N', $session);
                    $session->current_stage = 'bant_n';
                    break;

                case 'bant_n':
                    $session->qualification_data = array_merge($session->qualification_data, ['bant_need' => $text]);
                    $responseMessage = $this->askBantQuestion('T', $session);
                    $session->current_stage = 'bant_t';
                    break;

                case 'bant_t':
                    $session->qualification_data = array_merge($session->qualification_data, ['bant_timeline' => $text]);

                    // Todos os dados de qualificação recolhidos, agora processa e cria o lead
                    $qualificationResult = $this->evaluateQualification($session->qualification_data);

                    if ($qualificationResult['qualified']) {
                        $lead = $this->createLeadFromQualificationData($from, $session->qualification_data);
                        $session->lead_id = $lead->id;
                        $session->current_stage = 'qualified';
                        $responseMessage = "Excelente! Com base nas suas respostas, criamos um novo lead para você. Seu ID de lead é: {$lead->id}. Em breve um de nossos especialistas entrará em contato para dar continuidade ao atendimento. " . $qualificationResult['summary'];
                    } else {
                        $session->current_stage = 'unqualified';
                        $responseMessage = "Agradecemos o seu interesse. No momento, não conseguimos prosseguir com a criação do lead com as informações fornecidas. " . $qualificationResult['summary'] . " Se desejar, podemos tentar novamente ou fornecer mais informações.";
                    }
                    break;

                case 'qualified':
                    $responseMessage = $this->getGeminiPersonalizedResponse($history, "O lead já foi criado. Como posso ajudar com outras dúvidas sobre o seu lead {$session->lead_id}?");
                    break;

                case 'unqualified':
                    $responseMessage = $this->getGeminiPersonalizedResponse($history, "O atendimento foi concluído, mas o lead não foi criado. Como posso ajudar com outras informações ou tentar novamente a qualificação?");
                    break;

                default:
                    // Fallback para estados inesperados, usa Gemini para resposta geral
                    $responseMessage = $this->getGeminiPersonalizedResponse($history, "Desculpe, não entendi. Poderia reformular ou me dizer como posso ajudar?");
                    break;
            }
        } catch (Exception $e) {
            Log::error("Erro ao processar mensagem de WhatsApp para {$from}: " . $e->getMessage());
            $responseMessage = "Desculpe, ocorreu um erro ao processar sua solicitação. Por favor, tente novamente mais tarde.";
        }

        // Salva o estado atualizado da sessão
        $session->save();

        // Envia a resposta de volta ao utilizador
        $this->sendWhatsappMessage($from, $responseMessage);
    }

    /**
     * Envia uma mensagem de WhatsApp para o destinatário especificado.
     * Este método normalmente interagiria com uma API de WhatsApp (ex: Cloud API da Meta, Twilio, etc.).
     *
     * @param string $to O número de telefone do destinatário.
     * @param string $message A mensagem a enviar.
     * @return void
     */
    private function sendWhatsappMessage(string $to, string $message): void
    {
        // Normaliza o número de telefone para garantir o formato correto (especialmente para números brasileiros)
        $normalizedTo = $this->normalizeBrazilianPhoneNumber($to);

        try {
            // Exemplo de como usar a API da Meta WhatsApp Business
            // Você precisará do seu Token de Acesso Permanente e do ID do seu Número de Telefone
            $whatsappApiUrl = "https://graph.facebook.com/v19.0/" . env('WHATSAPP_PHONE_NUMBER_ID') . "/messages";
            $accessToken = env('WHATSAPP_ACCESS_TOKEN');

            if (empty($whatsappApiUrl) || empty($accessToken)) {
                Log::error("WHATSAPP_PHONE_NUMBER_ID ou WHATSAPP_ACCESS_TOKEN não configurados no .env.");
                // Em um ambiente de produção, você pode querer lançar uma exceção ou ter um fallback
                Log::info("Mensagem de WhatsApp SIMULADA enviada para {$normalizedTo}: {$message}");
                return;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])->post($whatsappApiUrl, [
                'messaging_product' => 'whatsapp',
                'to'                => $normalizedTo, // Usa o número normalizado aqui
                'type'              => 'text',
                'text'              => [
                    'body' => $message,
                ],
            ]);

            if ($response->successful()) {
                Log::info("Mensagem de WhatsApp REAL enviada para {$normalizedTo}: {$message}");
            } else {
                Log::error("Falha ao enviar mensagem de WhatsApp para {$normalizedTo}. Resposta: " . $response->body());
                // Fallback para log se o envio real falhar
                Log::info("Mensagem de WhatsApp SIMULADA enviada para {$normalizedTo}: {$message}");
            }
        } catch (Exception $e) {
            Log::error("Erro ao tentar enviar mensagem de WhatsApp REAL para {$normalizedTo}: " . $e->getMessage());
            // Fallback para log em caso de exceção na chamada HTTP
            Log::info("Mensagem de WhatsApp SIMULADA enviada para {$normalizedTo}: {$message}");
        }
    }

    /**
     * Normaliza números de telefone brasileiros, adicionando o '9' se estiver em falta.
     * Assume que o número já vem com o código do país (55) e o DDD.
     *
     * @param string $phoneNumber O número de telefone a normalizar.
     * @return string O número de telefone normalizado.
     */
    private function normalizeBrazilianPhoneNumber(string $phoneNumber): string
    {
        // Remove caracteres não numéricos
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Verifica se é um número brasileiro (começa com 55)
        if (substr($phoneNumber, 0, 2) === '55') {
            // Extrai o DDD (2 dígitos após o 55)
            $ddd = substr($phoneNumber, 2, 2);
            // Extrai o restante do número (8 ou 9 dígitos)
            $numberWithoutDdd = substr($phoneNumber, 4);

            // Lista simplificada de DDDs de celular no Brasil
            $mobileDdds = [
                '11', '12', '13', '14', '15', '16', '17', '18', '19',
                '21', '22', '24', '27', '28',
                '31', '32', '33', '34', '35', '37', '38',
                '41', '42', '43', '44', '45', '46', '47', '48', '49',
                '51', '53', '54', '55',
                '61', '62', '63', '64', '65', '66', '67', '68', '69',
                '71', '73', '74', '75', '77', '79',
                '81', '82', '83', '84', '85', '86', '87', '88', '89',
                '91', '92', '93', '94', '95', '96', '97', '98', '99'
            ];

            // Se o DDD for de celular E o número (após o DDD) tiver 8 dígitos,
            // então adicionamos o '9' na frente desses 8 dígitos.
            // Isso cobre casos como 556294123173 (8 dígitos após o DDD) que deve virar 5562994123173.
            if (in_array($ddd, $mobileDdds) && strlen($numberWithoutDdd) === 8) {
                $phoneNumber = '55' . $ddd . '9' . $numberWithoutDdd;
            }
        }

        return $phoneNumber;
    }

    /**
     * Faz uma pergunta SPIN específica com base na fase atual.
     *
     * @param string $type 'S', 'P', 'I', ou 'N' para Situação, Problema, Implicação, Necessidade-solução.
     * @param WhatsappSession $session A sessão de WhatsApp atual.
     * @return string A pergunta a ser feita.
     */
    private function askSpinQuestion(string $type, WhatsappSession $session): string
    {
        $prompt = '';

        switch ($type) {
            case 'S':
                $prompt = "Olá! Para começarmos, poderia me descrever a *Situação* atual da sua empresa ou do seu desafio? O que você está fazendo atualmente?";
                break;
            case 'P':
                $prompt = "Entendi a situação. Agora, qual é o *Problema* ou a dificuldade que você está enfrentando com a situação atual?";
                break;
            case 'I':
                $prompt = "Compreendo o problema. Quais são as *Implicações* desse problema para o seu negócio? Como ele afeta seus resultados ou operações?";
                break;
            case 'N':
                $prompt = "Certo. Agora, qual é a sua *Necessidade de Solução*? Como a resolução desse problema impactaria positivamente o seu trabalho ou empresa?";
                break;
        }

        // Para a pergunta de Situação (S), retornamos o prompt padrão sem personalização do Gemini.
        // Isso garante que a primeira pergunta do SPIN é sempre a esperada e direta.
        if ($type === 'S') {
            return $prompt;
        }

        // Para as fases seguintes (P, I, N), usamos Gemini para personalizar a pergunta.
        // O chatHistory já inclui a última mensagem do usuário.
        $chatHistory = $session->conversation_history ?? [];
        $name = $session->qualification_data['name'] ?? 'cliente'; // Pega o nome para personalizar o prompt
        $chatHistory[] = [
            'role' => 'user',
            'parts' => [[
                'text' => "Como um SDR do PipeGrow CRM, qualifique leads usando o framework SPIN. Com base na nossa conversa até agora com {$name}, reescreva de forma natural e direta a seguinte pergunta para a etapa '{$type}': \"{$prompt}\". Sua resposta deve ser APENAS a pergunta reescrita, sem comentários adicionais ou introduções."
            ]]
        ];
        return $this->getGeminiPersonalizedResponse($chatHistory, $prompt);
    }

    /**
     * Faz uma pergunta BANT específica com base na fase atual.
     *
     * @param string $type 'B', 'A', 'N', ou 'T' para Orçamento, Autoridade, Necessidade, Prazo.
     * @param WhatsappSession $session A sessão de WhatsApp atual.
     * @return string A pergunta a ser feita.
     */
    private function askBantQuestion(string $type, WhatsappSession $session): string
    {
        $qualificationData = $session->qualification_data ?? [];
        $prompt = '';

        switch ($type) {
            case 'B':
                $prompt = "Agora, vamos falar sobre o *Orçamento (Budget)*. Você tem um orçamento definido ou uma ideia de investimento para essa solução?";
                break;
            case 'A':
                $prompt = "Perfeito. Quem tem a *Autoridade* para tomar a decisão final sobre a aquisição dessa solução?";
                break;
            case 'N':
                $prompt = "Entendido. Qual é a *Necessidade* específica que você espera que nossa solução atenda? Qual o principal objetivo?";
                break;
            case 'T':
                $prompt = "Por fim, qual é o *Prazo (Timeline)* esperado para implementar essa solução? Você tem uma data em mente?";
                break;
        }

        // Para as fases BANT, sempre usamos Gemini para personalizar a pergunta,
        // pois elas vêm após as perguntas SPIN iniciais.
        $chatHistory = $session->conversation_history ?? [];
        $name = $session->qualification_data['name'] ?? 'cliente'; // Pega o nome para personalizar o prompt
        $chatHistory[] = [
            'role' => 'user',
            'parts' => [[
                'text' => "Como um SDR do PipeGrow CRM, qualifique leads usando o framework BANT. Com base na nossa conversa até agora com {$name}, reescreva de forma natural e direta a seguinte pergunta para a etapa '{$type}': \"{$prompt}\". Sua resposta deve ser APENAS a pergunta reescrita, sem comentários adicionais ou introduções."
            ]]
        ];
        return $this->getGeminiPersonalizedResponse($chatHistory, $prompt);
    }

    /**
     * Avalia os dados de qualificação recolhidos para determinar se um lead está qualificado.
     * Este é um exemplo simplificado; a lógica do mundo real seria mais complexa.
     *
     * @param array $data Os dados de qualificação recolhidos.
     * @return array Contém 'qualified' (booleano) e 'summary' (string).
     */
    private function evaluateQualification(array $data): array
    {
        $qualified = true;
        $summary = "Resumo da qualificação:\n";

        // Lógica básica de qualificação (pode ser expandida)
        if (empty($data['spin_situation'])) {
            $qualified = false;
            $summary .= "- Situação: Não fornecida.\n";
        } else {
            $summary .= "- Situação: " . $data['spin_situation'] . "\n";
        }

        if (empty($data['spin_problem'])) {
            $qualified = false;
            $summary .= "- Problema: Não fornecido.\n";
        } else {
            $summary .= "- Problema: " . $data['spin_problem'] . "\n";
        }

        if (empty($data['bant_budget']) || strtolower($data['bant_budget']) === 'não tenho') {
            // Uma verificação mais sofisticada envolveria a análise de valores de orçamento
            $qualified = false;
            $summary .= "- Orçamento: " . $data['bant_budget'] . "\n";
        } else {
            $summary .= "- Orçamento: " . $data['bant_budget'] . "\n";
        }

        if (empty($data['bant_authority']) || strtolower($data['bant_authority']) === 'não sei') {
            $qualified = false;
            $summary .= "- Autoridade: Não identificada.\n";
        } else {
            $summary .= "- Autoridade: " . $data['bant_authority'] . "\n";
        }

        if (empty($data['bant_need'])) {
            $qualified = false;
            $summary .= "- Necessidade (BANT): Não clara.\n";
        } else {
            $summary .= "- Necessidade (BANT): " . $data['bant_need'] . "\n";
        }

        if (empty($data['bant_timeline'])) {
            $qualified = false;
            $summary .= "- Prazo: Não definido.\n";
        } else {
            $summary .= "- Prazo: " . $data['bant_timeline'] . "\n";
        }

        // Adicione mais lógica complexa aqui com base nos seus critérios de qualificação específicos
        // Por exemplo, palavras-chave nas respostas, intervalos de orçamento específicos, etc.

        return [
            'qualified' => $qualified,
            'summary'   => $summary,
        ];
    }

    /**
     * Cria um novo lead na base de dados a partir dos dados qualificados.
     *
     * @param string $phoneNumber O número de telefone do lead.
     * @param array $qualificationData Os dados de qualificação recolhidos.
     * @return Lead A instância do modelo Lead recém-criada.
     */
    private function createLeadFromQualificationData(string $phoneNumber, array $qualificationData): Lead
    {
        // Extrai dados relevantes para a criação do lead
        $name = $qualificationData['name'] ?? 'Lead WhatsApp'; // Usa o nome coletado
        $email = $qualificationData['email'] ?? "{$phoneNumber}@whatsapp.com"; // Assume que o email pode ser recolhido ou usa um padrão
        $source = $qualificationData['source'] ?? 'WhatsApp'; // Assume que a fonte pode ser recolhida ou usa um padrão

        // Você precisará mapear os seus dados de qualificação para os campos preenchíveis do seu modelo Lead.
        // Isto é um placeholder e deve ser ajustado à estrutura real do seu modelo Lead.
        $lead = Lead::create([
            'title'              => 'Novo Lead Qualificado via WhatsApp',
            'description'        => $this->formatQualificationDataForDescription($qualificationData),
            'lead_pipeline_id'   => 1, // Substitua pelo ID real do pipeline
            'lead_pipeline_stage_id' => 1, // Substitua pelo ID real da fase inicial
            'user_id'            => 1, // Atribui a um utilizador padrão ou implementa lógica de atribuição de utilizador
            'person_id'          => null, // Cria ou liga uma pessoa se necessário
            'organization_id'    => null, // Cria ou liga uma organização se necessário
            'lead_source_id'     => $this->getLeadSourceId($source), // Função auxiliar para obter o ID da fonte
            'lead_type_id'       => $this->getLeadTypeId('default'), // Função auxiliar para obter o ID do tipo
            'expected_close_date' => now()->addDays(30), // Exemplo: 30 dias a partir de agora
            'lead_value'         => 0, // Valor inicial, pode ser atualizado mais tarde
            'status'             => 'open',
            'created_at'         => now(),
            'updated_at'         => now(),
            // Adicione quaisquer outros campos obrigatórios para o seu modelo Lead
        ]);

        Log::info("Lead criado com sucesso para {$phoneNumber} com ID: {$lead->id}");

        return $lead;
    }

    /**
     * Formata os dados de qualificação numa descrição legível para o lead.
     *
     * @param array $data Os dados de qualificação recolhidos.
     * @return string
     */
    private function formatQualificationDataForDescription(array $data): string
    {
        $description = "Dados de Qualificação (SPIN/BANT) via WhatsApp:\n\n";
        foreach ($data as $key => $value) {
            $description .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
        }
        return $description;
    }

    /**
     * Função auxiliar para obter o ID da Fonte do Lead. Substitua pela sua lógica real.
     *
     * @param string $sourceName
     * @return int
     */
    private function getLeadSourceId(string $sourceName): int 
    {
        // Exemplo: Buscar da base de dados ou usar um padrão
        // return \Webkul\Lead\Models\Source::where('name', $sourceName)->first()->id ?? 1;
        return 1; // Placeholder: Assume ID 1 para a fonte 'WhatsApp'
    }

    /**
     * Função auxiliar para obter o ID do Tipo de Lead. Substitua pela sua lógica real.
     *
     * @param string $typeName
     * @return int
     */
    private function getLeadTypeId(string $typeName): int
    {
        // Exemplo: Buscar da base de dados ou usar um padrão
        // return \Webkul\Lead\Models\Type::where('name', $typeName)->first()->id ?? 1;
        return 1; // Placeholder: Assume ID 1 para o tipo 'Padrão'
    }

    /**
     * Interage com a API do Gemini para obter uma resposta personalizada.
     *
     * @param array $chatHistory O histórico da conversa a enviar para o Gemini.
     * @param string $fallbackMessage Uma mensagem a retornar se a API do Gemini falhar.
     * @return string A resposta personalizada do Gemini ou a mensagem de fallback.
     */
    private function getGeminiPersonalizedResponse(array $chatHistory, string $fallbackMessage): string
    {
        try {
            // Lendo a chave da API do Gemini do arquivo .env
            $apiKey = env('GEMINI_API_KEY'); 
            
            // Verifique se a chave da API foi carregada
            if (empty($apiKey)) {
                Log::error("GEMINI_API_KEY não configurada no arquivo .env.");
                return $fallbackMessage;
            }

            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

            // Garante que o formato do histórico de chat corresponde à estrutura 'contents' esperada pelo Gemini
            $payload = [
                'contents' => $chatHistory,
                'generationConfig' => [
                    'temperature' => 0.7, // Ajusta a criatividade conforme necessário
                    'topP'        => 0.95,
                    'topK'        => 40,
                ],
            ];

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post($apiUrl, $payload);

            $result = $response->json();

            if ($response->successful() && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
                return $result['candidates'][0]['content']['parts'][0]['text'];
            } else {
                Log::warning("A chamada à API do Gemini falhou ou retornou uma estrutura inesperada: " . json_encode($result));
                return $fallbackMessage;
            }
        } catch (Exception $e) {
            Log::error("Erro ao chamar a API do Gemini: " . $e->getMessage());
            return $fallbackMessage;
        }
    }
}
