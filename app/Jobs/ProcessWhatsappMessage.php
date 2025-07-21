<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Exception;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Lead\Repositories\SourceRepository;

class ProcessWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageData;

    protected PersonRepository $personRepository;

    protected LeadRepository $leadRepository;

    protected SourceRepository $sourceRepository;

    /**
     * Create a new job instance.
     *
     * @param array $messageData The received WhatsApp message data.
     * @return void
     */
    public function __construct(array $messageData)
    {
        $this->messageData = $messageData;
    }

    /**
     * Execute the job.
     *
     * This method handles the received WhatsApp message, manages the conversation state
     * for lead qualification (SPIN/BANT), interacts with the Gemini API for personalized
     * responses, and conditionally creates a lead in the database.
     *
     * @param \Webkul\Contact\Repositories\PersonRepository $personRepository
     * @param \Webkul\Lead\Repositories\LeadRepository $leadRepository
     * @param \Webkul\Lead\Repositories\SourceRepository $sourceRepository
     * @return void
     */
    public function handle(
        PersonRepository $personRepository,
        LeadRepository $leadRepository,
        SourceRepository $sourceRepository
    ): void
    {
        // Assign repositories from method injection
        $this->personRepository = $personRepository;
        $this->leadRepository   = $leadRepository;
        $this->sourceRepository = $sourceRepository;

        // Correct access to the sender's phone number
        $from = $this->messageData['from'];

        // Access the 'body' of the text message
        $text = $this->messageData['text']['body'] ?? null;

        // If the message has no text body (e.g., image, video, etc.), we can ignore or handle it differently
        if (empty($text)) {
            Log::warning("ProcessWhatsappMessage: Non-textual message or empty text received from {$from}. Ignorando.");
            return;
        }

        Log::info("ProcessWhatsappMessage: Message received from {$from}: {$text}");

        // Find or create a WhatsApp session for the user
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $from],
            [
                'conversation_history' => [],
                'qualification_data'   => [],
                'current_stage'        => 'initial_greeting', // Changed initial stage
            ]
        );

        // Add the user's message to the conversation history
        $history = $session->conversation_history ?? [];
        $history[] = ['role' => 'user', 'parts' => [['text' => $text]]];
        $session->conversation_history = $history;

        $responseMessage = '';

        try {
            // Check for "não sei" or similar evasive answers before processing
            $normalizedText = mb_strtolower(trim($text));
            $empatheticResponseNeeded = false;

            if (in_array($normalizedText, ['não sei', 'nao sei', 'não tenho certeza', 'nao tenho certeza', 'não sei dizer', 'nao sei dizer'])) {
                $empatheticResponseNeeded = true;
            }

            // Special handling for the very first message to ensure proper greeting
            if ($session->current_stage === 'initial_greeting') {
                $responseMessage = "Olá! Sou o assistente virtual do PipeGrow Ads. Para começarmos, qual é o seu nome?";
                $session->current_stage = 'awaiting_name_response';
            } elseif ($empatheticResponseNeeded && $session->current_stage !== 'awaiting_name_response') { // Don't apply empathy for initial name request
                $name = $session->qualification_data['name'] ?? 'cliente';
                $responseMessage = "Compreendo, {$name}. É normal ter dúvidas. Poderia tentar descrever com outras palavras ou me dar um exemplo? Ou talvez eu possa reformular a pergunta de outra forma.";
                // Do NOT change $session->current_stage here, so the same question is implicitly re-asked.
            } else {
                // Normal flow based on current_stage
                switch ($session->current_stage) {
                    case 'awaiting_name_response':
                        // Only process name if it's not a generic greeting (e.g., "Olá")
                        if (mb_strtolower(trim($text)) !== 'olá' && mb_strtolower(trim($text)) !== 'ola') {
                            $qualificationData = $session->qualification_data; // Get a mutable copy
                            $qualificationData['name'] = $text;
                            $session->qualification_data = $qualificationData; // Reassign the modified copy

                            $name = $session->qualification_data['name'];
                            $responseMessage = "Obrigado, {$name}! Para te ajudar melhor, poderia me descrever a *Situação* atual da sua empresa ou do seu desafio? O que você está fazendo atualmente?";
                            $session->current_stage = 'spin_s';
                        } else {
                            // If user just repeated "Olá" while we expected a name, re-ask for name
                            $responseMessage = "Olá novamente! Para que eu possa te ajudar, preciso do seu nome. Poderia me dizer qual é?";
                            // Keep current_stage as 'awaiting_name_response'
                        }
                        break;

                    case 'spin_s':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['spin_situation'] = $text;
                        $session->qualification_data = $qualificationData;
                        $responseMessage = $this->askSpinQuestion('P', $session);
                        $session->current_stage = 'spin_p';
                        break;

                    case 'spin_p':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['spin_problem'] = $text;
                        $session->qualification_data = $qualificationData;
                        $responseMessage = $this->askSpinQuestion('I', $session);
                        $session->current_stage = 'spin_i';
                        break;

                    case 'spin_i':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['spin_implication'] = $text;
                        $session->qualification_data = $qualificationData;
                        $responseMessage = $this->askSpinQuestion('N', $session);
                        $session->current_stage = 'spin_n';
                        break;

                    case 'spin_n':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['spin_need_payoff'] = $text;
                        $session->qualification_data = $qualificationData;
                        $responseMessage = $this->askBantQuestion('B', $session);
                        $session->current_stage = 'bant_b';
                        break;

                    case 'bant_b':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['bant_budget'] = $text;
                        $session->qualification_data = $qualificationData;
                        $responseMessage = $this->askBantQuestion('A', $session);
                        $session->current_stage = 'bant_a';
                        break;

                    case 'bant_a':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['bant_authority'] = $text;
                        $session->qualification_data = $qualificationData;
                        $responseMessage = $this->askBantQuestion('N', $session);
                        $session->current_stage = 'bant_n';
                        break;

                    case 'bant_n':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['bant_need'] = $text;
                        $session->qualification_data = $qualificationData;
                        $responseMessage = $this->askBantQuestion('T', $session);
                        $session->current_stage = 'bant_t';
                        break;

                    case 'bant_t':
                        $qualificationData = $session->qualification_data;
                        $qualificationData['bant_timeline'] = $text;
                        $session->qualification_data = $qualificationData;

                        // All qualification data collected, now process and create the lead
                        $qualificationResult = $this->evaluateQualification($session->qualification_data);

                        if ($qualificationResult['qualified']) {
                            $lead = $this->createLeadFromQualificationData($from, $session->qualification_data);
                            $session->lead_id = $lead->id;
                            $session->current_stage = 'qualified';
                            // Modified response message for qualified lead: removed summary
                            $responseMessage = "Excelente! Agradecemos o seu tempo. Em breve um de nossos especialistas entrará em contato para dar continuidade ao atendimento.";
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
                        // Fallback for unexpected states, use Gemini for general response
                        $responseMessage = $this->getGeminiPersonalizedResponse($history, "Desculpe, não entendi. Poderia reformular ou me dizer como posso ajudar?");
                        break;
                }
            }
        } catch (Exception $e) {
            Log::error("Error processing WhatsApp message for {$from}: " . $e->getMessage());
            $responseMessage = "Desculpe, ocorreu um erro ao processar sua solicitação. Por favor, tente novamente mais tarde.";
        }

        // Save the updated session state
        $session->save();

        // Removed the sleep(2) here
        // sleep(2); // Wait for 2 seconds

        // Send the response back to the user
        $this->sendWhatsappMessage($from, $responseMessage);
    }

    /**
     * Sends a WhatsApp message to the specified recipient.
     * This method would typically interact with a WhatsApp API (e.g., Meta Cloud API, Twilio, etc.).
     *
     * @param string $to The recipient's phone number.
     * @param string $message The message to send.
     * @return void
     */
    private function sendWhatsappMessage(string $to, string $message): void
    {
        // Normalize the phone number to ensure the correct format (especialy for Brazilian numbers)
        $normalizedTo = $this->normalizeBrazilianPhoneNumber($to);

        try {
            // Example of how to use the Meta WhatsApp Business API
            // You will need your Permanent Access Token and your Phone Number ID
            $whatsappApiUrl = "https://graph.facebook.com/v19.0/" . env('WHATSAPP_PHONE_NUMBER_ID') . "/messages";
            $accessToken = env('WHATSAPP_ACCESS_TOKEN');

            if (empty($whatsappApiUrl) || empty($accessToken)) {
                Log::error("WHATSAPP_PHONE_NUMBER_ID or WHATSAPP_ACCESS_TOKEN not configured in .env.");
                // In a production environment, you might want to throw an exception or have a fallback
                Log::info("SIMULATED WhatsApp message sent to {$normalizedTo}: {$message}");
                return;
            }

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ])->post($whatsappApiUrl, [
                'messaging_product' => 'whatsapp',
                'to'                => $normalizedTo, // Use the normalized number here
                'type'              => 'text',
                'text'              => [
                    'body' => $message,
                ],
            ]);

            if ($response->successful()) {
                Log::info("REAL WhatsApp message sent to {$normalizedTo}: {$message}");
            } else {
                Log::error("Failed to send WhatsApp message to {$normalizedTo}. Resposta: " . $response->body());
                // Fallback to log if the real send fails
                Log::info("Mensagem de WhatsApp SIMULADA enviada para {$normalizedTo}: {$message}");
            }
        } catch (Exception $e) {
            Log::error("Error trying to send REAL WhatsApp message to {$normalizedTo}: " . $e->getMessage());
            // Fallback to log in case of an HTTP call exception
            Log::info("Mensagem de WhatsApp SIMULADA enviada para {$normalizedTo}: {$message}");
        }
    }

    /**
     * Normalizes Brazilian phone numbers, adding '9' if missing.
     * Assumes the number already comes with the country code (55) and the DDD.
     *
     * @param string $phoneNumber The phone number to normalize.
     * @return string The normalized phone number.
     */
    public function normalizeBrazilianPhoneNumber(string $phoneNumber): string
    {
        // Remove non-numeric characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Check if it's a Brazilian number (starts with 55)
        if (substr($phoneNumber, 0, 2) === '55') {
            // Extrai o DDD (2 dígitos após o 55)
            $ddd = substr($phoneNumber, 2, 2);
            // Extrai o restante do número (8 ou 9 dígitos)
            $numberWithoutDdd = substr($phoneNumber, 4);

            // Simplified list of mobile DDDs in Brazil
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

            // If the DDD is for mobile AND the number (after DDD) has 8 digits,
            // then we add '9' in front of those 8 digits.
            // This covers cases like 556294123173 (8 digits after DDD) which should become 5562994123173.
            if (in_array($ddd, $mobileDdds) && strlen($numberWithoutDdd) === 8) {
                $phoneNumber = '55' . $ddd . '9' . $numberWithoutDdd;
            }
        }

        return $phoneNumber;
    }

    /**
     * Asks a specific SPIN question based on the current phase.
     *
     * @param string $type 'S', 'P', 'I', or 'N' for Situation, Problem, Implication, Need-payoff.
     * @param WhatsappSession $session The current WhatsApp session.
     * @return string The question to be asked.
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

        // For the Situation (S) question, we return the default prompt without Gemini personalization.
        // This ensures that the first SPIN question is always the expected and direct one.
        if ($type === 'S') {
            return $prompt;
        }

        // For the subsequent phases (P, I, N), we use Gemini to personalize the question.
        // The chatHistory already includes the last user message.
        $chatHistory = $session->conversation_history ?? [];
        $name = $session->qualification_data['name'] ?? 'cliente'; // Get the name to personalize the prompt
        $chatHistory[] = [
            'role' => 'user',
            'parts' => [[
                'text' => "Como um SDR do PipeGrow Ads, qualifique leads usando o framework SPIN. Com base na nossa conversa até agora com {$name}, reescreva de forma natural e direta a seguinte pergunta para a etapa '{$type}': \"{$prompt}\". Sua resposta deve ser APENAS a pergunta reescrita, sem comentários adicionais ou introduções."
            ]]
        ];
        return $this->getGeminiPersonalizedResponse($chatHistory, $prompt);
    }

    /**
     * Asks a specific BANT question based on the current phase.
     *
     * @param string $type 'B', 'A', 'N', or 'T' for Budget, Authority, Need, Timeline.
     * @param WhatsappSession $session The current WhatsApp session.
     * @return string The question to be asked.
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

        // For BANT phases, we always use Gemini to personalize the question,
        // as they come after the initial SPIN questions.
        $chatHistory = $session->conversation_history ?? [];
        $name = $session->qualification_data['name'] ?? 'cliente'; // Get the name to personalize the prompt
        $chatHistory[] = [
            'role' => 'user',
            'parts' => [[
                'text' => "Como um SDR do PipeGrow Ads, qualifique leads usando o framework BANT. Com base na nossa conversa até agora com {$name}, reescreva de forma natural e direta a seguinte pergunta para a etapa '{$type}': \"{$prompt}\". Sua resposta deve ser APENAS a pergunta reescrita, sem comentários adicionais ou introduções."
            ]]
        ];
        return $this->getGeminiPersonalizedResponse($chatHistory, $prompt);
    }

    /**
     * Evaluates the collected qualification data to determine if a lead is qualified.
     * This is a simplified example; real-world logic would be more complex.
     *
     * @param array $data The collected qualification data.
     * @return array Contém 'qualified' (booleano) e 'summary' (string).
     */
    public function evaluateQualification(array $data): array
    {
        $qualified = true;
        $summary = "Resumo da qualificação:\n";

        // Basic qualification logic (can be expanded)
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
            // A more sophisticated check would involve analyzing budget values
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

        // Add more complex logic here based on your specific qualification criteria
        // For example, keywords in responses, specific budget ranges, etc.

        return [
            'qualified' => $qualified,
            'summary'   => $summary,
        ];
    }

    /**
     * Creates a new lead in the database from the qualified data.
     * This method now uses the logic provided by the user, adapted for a Job context.
     *
     * @param string $phoneNumber The lead's phone number.
     * @param array $qualificationData The collected qualification data.
     * @return \Webkul\Lead\Contracts\Lead The newly created Lead model instance.
     */
    private function createLeadFromQualificationData(string $phoneNumber, array $qualificationData): \Webkul\Lead\Contracts\Lead
    {
        // Adapt qualificationData to the structure expected by the provided createlead logic
        $leadData = [
            'full_name'    => $qualificationData['name'] ?? 'Lead WhatsApp',
            'email'        => $qualificationData['email'] ?? "{$phoneNumber}@whatsapp.com",
            'phone_number' => $this->normalizeBrazilianPhoneNumber($phoneNumber),
            'message'      => $this->formatQualificationDataForDescription($qualificationData),
        ];

        // Add 'entity_type' for Person creation/update
        $personData = [
            'name'            => $leadData['full_name'],
            'emails'          => [['value' => $leadData['email'], 'label' => 'work']],
            'contact_numbers' => [['value' => $leadData['phone_number'], 'label' => 'work']],
            'organization_id' => null,
            'lead_owner_id'   => User::inRandomOrder()->value('id'), // Assign a random user as owner
            'entity_type'     => 'persons', // Add entity_type for Person
        ];

        $existingPerson = $this->personRepository->whereJsonContains('emails', [['value' => $leadData['email'], 'label' => 'work']])->first();

        if ($existingPerson) {
            $this->personRepository->update($personData, $existingPerson->id);
            $person = $existingPerson;
        } else {
            $person = $this->personRepository->create($personData);
        }

        // Add 'entity_type' for Lead creation
        $lead = $this->leadRepository->create([
            'title'             => 'Lead do WhatsApp: ' . $leadData['full_name'],
            'lead_pipeline_id'  => 1, // Assuming default pipeline ID 1
            'lead_stage_id'     => 1, // Assuming default stage ID 1
            'lead_source_id'    => $this->getWhatsappLeadSourceId(), // Get WhatsApp specific source ID
            'person_id'         => $person->id,
            'user_id'           => null, // As per the provided logic, user_id is null
            'expected_close_date' => now()->addDays(7),
            'lead_value'        => 0,
            'description'       => $leadData['message'],
            'lead_type_id'      => 1, // Assuming default lead type ID 1
            'entity_type'       => 'leads', // Add entity_type for Lead
        ]);

        Log::info("Lead created successfully for {$phoneNumber} with ID: {$lead->id}");

        return $lead;
    }

    /**
     * Helper function to get the Lead Source ID for WhatsApp.
     * You might want to create a 'WhatsApp' source in your database and retrieve its ID.
     *
     * @return int
     */
    private function getWhatsappLeadSourceId(): int
    {
        // Attempt to find the 'WhatsApp' source in the database
        $source = $this->sourceRepository->findOneByField('name', 'WhatsApp');

        // If found, return its ID, otherwise return a default ID (e.g., 1 for 'Default' or 'Other')
        return $source->id ?? 1;
    }

    /**
     * Helper function to get the Lead Type ID. Replace with your actual logic.
     *
     * @param string $typeName
     * @return int
     */
    private function getLeadTypeId(string $typeName): int
    {
        // Example: Fetch from the database or use a default
        // return \Webkul\Lead\Models\Type::where('name', $typeName)->first()->id ?? 1;
        return 1; // Placeholder: Assumes ID 1 for the 'Default' type
    }

    /**
     * Interacts with the Gemini API to get a personalized response.
     *
     * @param array $chatHistory The conversation history to send to Gemini.
     * @param string $fallbackMessage A message to return if the Gemini API fails.
     * @return string The personalized response from Gemini or the fallback message.
     */
    private function getGeminiPersonalizedResponse(array $chatHistory, string $fallbackMessage): string
    {
        try {
            // Reading the Gemini API key from the .env file
            $apiKey = env('GEMINI_API_KEY'); 
            
            // Check if the API key was loaded
            if (empty($apiKey)) {
                Log::error("GEMINI_API_KEY not configured in the .env file.");
                return $fallbackMessage;
            }

            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

            // Ensure the chat history format matches the 'contents' structure expected by Gemini
            $payload = [
                'contents' => $chatHistory,
                'generationConfig' => [
                    'temperature' => 0.7, // Adjust creativity as needed
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
                Log::warning("Gemini API call failed or returned an unexpected structure: " . json_encode($result));
                return $fallbackMessage;
            }
        } catch (Exception $e) {
            Log::error("Error calling Gemini API: " . $e->getMessage());
            return $fallbackMessage;
        }
    }

    /**
     * Formats the qualification data into a readable description for the lead.
     *
     * @param array $data The collected qualification data.
     * @return string
     */
    public function formatQualificationDataForDescription(array $data): string
    {
        $description = "Dados de Qualificação (SPIN/BANT) via WhatsApp:\n\n";
        foreach ($data as $key => $value) {
            // Filter out the problematic string
            if ($value === "Over 9 levels deep, aborting normalization") {
                $value = "N/A"; // Or an empty string, or a more appropriate placeholder
            }
            $description .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
        }
        return $description;
    }
}
