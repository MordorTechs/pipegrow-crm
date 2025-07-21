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
use Illuminate\Support\Facades\Http; // For making HTTP requests to Gemini API
use Exception; // For error handling

class ProcessWhatsappMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $messageData;

    /**
     * Create a new job instance.
     *
     * @param array $messageData The incoming WhatsApp message data.
     * @return void
     */
    public function __construct(array $messageData)
    {
        $this->messageData = $messageData;
    }

    /**
     * Execute the job.
     *
     * This method handles the incoming WhatsApp message, manages the conversation state
     * for lead qualification (SPIN/BANT), interacts with the Gemini API for personalized
     * responses, and conditionally creates a lead in the database.
     *
     * @return void
     */
    public function handle(): void
    {
        $from = $this->messageData['from'];
        $text = $this->messageData['text'];

        Log::info("ProcessWhatsappMessage: Received message from {$from}: {$text}");

        // Find or create a WhatsApp session for the user
        $session = WhatsappSession::firstOrCreate(
            ['phone_number' => $from],
            [
                'conversation_history' => [],
                'qualification_data'   => [],
                'current_stage'        => 'initial',
            ]
        );

        // Append the user's message to the conversation history
        $history = $session->conversation_history ?? [];
        $history[] = ['role' => 'user', 'parts' => [['text' => $text]]];
        $session->conversation_history = $history;

        $responseMessage = '';

        try {
            // Determine the next step based on the current stage
            switch ($session->current_stage) {
                case 'initial':
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

                    // All qualification data collected, now process and create lead
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
                    // Fallback for unexpected states, use Gemini for general response
                    $responseMessage = $this->getGeminiPersonalizedResponse($history, "Desculpe, não entendi. Poderia reformular ou me dizer como posso ajudar?");
                    break;
            }
        } catch (Exception $e) {
            Log::error("Error processing WhatsApp message for {$from}: " . $e->getMessage());
            $responseMessage = "Desculpe, ocorreu um erro ao processar sua solicitação. Por favor, tente novamente mais tarde.";
        }

        // Save the updated session state
        $session->save();

        // Send the response back to the user
        $this->sendWhatsappMessage($from, $responseMessage);
    }

    /**
     * Sends a WhatsApp message to the specified recipient.
     * This method would typically interact with a WhatsApp API (e.g., Meta's Cloud API, Twilio, etc.).
     *
     * @param string $to The recipient's phone number.
     * @param string $message The message to send.
     * @return void
     */
    private function sendWhatsappMessage(string $to, string $message): void
    {
        // IMPORTANT: Replace this with your actual WhatsApp API integration logic.
        // This is a placeholder. You'll need to configure your WhatsApp Business API
        // or a third-party provider (like Twilio, Vonage, etc.) here.
        // Example using a hypothetical WhatsApp API endpoint:
        /*
        try {
            Http::post('YOUR_WHATSAPP_API_ENDPOINT', [
                'to'      => $to,
                'message' => $message,
                'token'   => 'YOUR_WHATSAPP_API_TOKEN',
            ]);
            Log::info("WhatsApp message sent to {$to}: {$message}");
        } catch (Exception $e) {
            Log::error("Failed to send WhatsApp message to {$to}: " . $e->getMessage());
        }
        */

        // For demonstration, we'll just log it. In a real application, this sends the message.
        Log::info("Simulated WhatsApp message sent to {$to}: {$message}");
    }

    /**
     * Asks a specific SPIN question based on the current stage.
     *
     * @param string $type 'S', 'P', 'I', or 'N' for Situation, Problem, Implication, Need-payoff.
     * @param WhatsappSession $session The current WhatsApp session.
     * @return string The question to ask.
     */
    private function askSpinQuestion(string $type, WhatsappSession $session): string
    {
        $qualificationData = $session->qualification_data ?? [];
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

        // Use Gemini to make the question more personalized based on previous context
        if (! empty($session->conversation_history)) {
            $chatHistory = $session->conversation_history;
            $chatHistory[] = ['role' => 'user', 'parts' => [['text' => "Com base na nossa conversa até agora, formule a seguinte pergunta de qualificação SPIN de forma mais personalizada e engajadora, mantendo o foco na etapa '{$type}': \"{$prompt}\""]]];
            return $this->getGeminiPersonalizedResponse($chatHistory, $prompt);
        }

        return $prompt;
    }

    /**
     * Asks a specific BANT question based on the current stage.
     *
     * @param string $type 'B', 'A', 'N', or 'T' for Budget, Authority, Need, Timeline.
     * @param WhatsappSession $session The current WhatsApp session.
     * @return string The question to ask.
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

        // Use Gemini to make the question more personalized based on previous context
        if (! empty($session->conversation_history)) {
            $chatHistory = $session->conversation_history;
            $chatHistory[] = ['role' => 'user', 'parts' => [['text' => "Com base na nossa conversa até agora, formule a seguinte pergunta de qualificação BANT de forma mais personalizada e engajadora, mantendo o foco na etapa '{$type}': \"{$prompt}\""]]];
            return $this->getGeminiPersonalizedResponse($chatHistory, $prompt);
        }

        return $prompt;
    }

    /**
     * Evaluates the collected qualification data to determine if a lead is qualified.
     * This is a simplified example; real-world logic would be more complex.
     *
     * @param array $data The collected qualification data.
     * @return array Contains 'qualified' (boolean) and 'summary' (string).
     */
    private function evaluateQualification(array $data): array
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
            // A more sophisticated check would involve parsing budget amounts
            $qualified = false;
            $summary .= "- Orçamento: Não definido ou insuficiente.\n";
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
     *
     * @param string $phoneNumber The phone number of the lead.
     * @param array $qualificationData The collected qualification data.
     * @return Lead The newly created Lead model instance.
     */
    private function createLeadFromQualificationData(string $phoneNumber, array $qualificationData): Lead
    {
        // Extract relevant data for lead creation
        $name = $qualificationData['spin_situation'] ?? 'Lead WhatsApp'; // Use situation as a basic name
        $email = $qualificationData['email'] ?? "{$phoneNumber}@whatsapp.com"; // Assuming email might be collected or default
        $source = $qualificationData['source'] ?? 'WhatsApp'; // Assuming source might be collected or default

        // You'll need to map your qualification data to your Lead model's fillable fields.
        // This is a placeholder and should be adjusted to your actual Lead model structure.
        $lead = Lead::create([
            'title'              => 'Novo Lead Qualificado via WhatsApp',
            'description'        => $this->formatQualificationDataForDescription($qualificationData),
            'lead_pipeline_id'   => 1, // Replace with actual pipeline ID
            'lead_pipeline_stage_id' => 1, // Replace with actual initial stage ID
            'user_id'            => 1, // Assign to a default user or implement user assignment logic
            'person_id'          => null, // Create or link a person if needed
            'organization_id'    => null, // Create or link an organization if needed
            'lead_source_id'     => $this->getLeadSourceId($source), // Helper to get source ID
            'lead_type_id'       => $this->getLeadTypeId('default'), // Helper to get type ID
            'expected_close_date' => now()->addDays(30), // Example: 30 days from now
            'lead_value'         => 0, // Initial value, can be updated later
            'status'             => 'open',
            'created_at'         => now(),
            'updated_at'         => now(),
            // Add any other required fields for your Lead model
        ]);

        Log::info("Lead created successfully for {$phoneNumber} with ID: {$lead->id}");

        return $lead;
    }

    /**
     * Formats the qualification data into a readable description for the lead.
     *
     * @param array $data The collected qualification data.
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
     * Helper to get Lead Source ID. Replace with your actual logic.
     *
     * @param string $sourceName
     * @return int
     */
    private function getLeadSourceId(string $sourceName): int
    {
        // Example: Fetch from database or use a default
        // return \Webkul\Lead\Models\Source::where('name', $sourceName)->first()->id ?? 1;
        return 1; // Placeholder: Assume ID 1 for 'WhatsApp' source
    }

    /**
     * Helper to get Lead Type ID. Replace with your actual logic.
     *
     * @param string $typeName
     * @return int
     */
    private function getLeadTypeId(string $typeName): int
    {
        // Example: Fetch from database or use a default
        // return \Webkul\Lead\Models\Type::where('name', $typeName)->first()->id ?? 1;
        return 1; // Placeholder: Assume ID 1 for 'Default' type
    }

    /**
     * Interacts with the Gemini API to get a personalized response.
     *
     * @param array $chatHistory The conversation history to send to Gemini.
     * @param string $fallbackMessage A message to return if Gemini API fails.
     * @return string The personalized response from Gemini or the fallback message.
     */
    private function getGeminiPersonalizedResponse(array $chatHistory, string $fallbackMessage): string
    {
        try {
            $apiKey = ""; // Leave this as-is. Canvas will automatically provide it.
            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

            // Ensure the chat history format matches Gemini's expected 'contents' structure
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
                Log::warning("Gemini API call failed or returned unexpected structure: " . json_encode($result));
                return $fallbackMessage;
            }
        } catch (Exception $e) {
            Log::error("Error calling Gemini API: " . $e->getMessage());
            return $fallbackMessage;
        }
    }
}
