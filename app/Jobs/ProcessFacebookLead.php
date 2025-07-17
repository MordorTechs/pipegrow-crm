<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\MetaAdsTokens;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Contact\Repositories\OrganizationRepository;
use Exception;
use Illuminate\Support\Facades\Log;

class ProcessFacebookLead implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * O ID do lead gerado pelo Facebook.
     *
     * @var string
     */
    protected $leadgenId;

    /**
     * O ID da página do Facebook.
     *
     * @var string
     */
    protected $pageId;

    /**
     * Cria uma nova instância do job.
     *
     * @param string $leadgenId
     * @param string $pageId
     * @return void
     */
    public function __construct(string $leadgenId, string $pageId)
    {
        $this->leadgenId = $leadgenId;
        $this->pageId = $pageId;
    }

    /**
     * Executa o job.
     *
     * @param \Webkul\Lead\Repositories\LeadRepository $leadRepository
     * @param \Webkul\Contact\Repositories\PersonRepository $personRepository
     * @param \Webkul\Contact\Repositories\OrganizationRepository $organizationRepository
     * @return void
     */
    public function handle(
        LeadRepository $leadRepository,
        PersonRepository $personRepository,
        OrganizationRepository $organizationRepository
    ) {
        Log::info("Processando lead do Facebook: {$this->leadgenId} para a página: {$this->pageId}");

        try {
            // 1. Obter o token de acesso da Meta Ads
            $metaAdsToken = MetaAdsTokens::where('page_id', $this->pageId)->first();

            if (! $metaAdsToken) {
                Log::error("Token de acesso não encontrado para a página do Facebook: {$this->pageId}");
                return;
            }

            $accessToken = $metaAdsToken->access_token;

            // 2. Consultar os detalhes do lead usando a API Graph do Facebook
            $graphApiUrl = "https://graph.facebook.com/v19.0/{$this->leadgenId}?access_token={$accessToken}";

            $response = file_get_contents($graphApiUrl);
            $leadData = json_decode($response, true);

            if (! $leadData || ! isset($leadData['field_data'])) {
                Log::error("Não foi possível obter os dados do lead do Facebook para leadgen_id: {$this->leadgenId}", ['response' => $leadData]);
                return;
            }

            $mappedData = $this->mapFacebookLeadData($leadData['field_data']);

            // 3. Criar ou atualizar a pessoa
            $person = $personRepository->firstOrCreate([
                'emails' => [['value' => $mappedData['email'], 'label' => 'work']]
            ], [
                'name' => $mappedData['full_name'],
                'emails' => [['value' => $mappedData['email'], 'label' => 'work']],
                'contact_numbers' => [['value' => $mappedData['phone_number'], 'label' => 'work']],
                'organization_id' => null, // Pode ser preenchido se houver lógica para organizações
                'lead_owner_id' => $metaAdsToken->user_id, // Atribuir ao usuário que configurou a integração
            ]);

            // 4. Criar um novo lead no CRM
            $lead = $leadRepository->create([
                'title' => 'Lead do Facebook: ' . $mappedData['full_name'],
                'lead_pipeline_id' => 1, // Substitua pelo ID do pipeline padrão ou configure dinamicamente
                'lead_stage_id' => 1,    // Substitua pelo ID do estágio padrão ou configure dinamicamente
                'lead_source_id' => $this->getFacebookLeadSourceId(), // Obter ID da fonte "Facebook"
                'person_id' => $person->id,
                'user_id' => $metaAdsToken->user_id, // Atribuir ao usuário que configurou a integração
                'expected_close_date' => now()->addDays(7), // Exemplo: 7 dias a partir de agora
                'lead_value' => 0, // Pode ser atualizado se o Facebook fornecer valor
                'description' => $mappedData['message'] ?? 'Lead gerado via Facebook Ads.',
            ]);

            Log::info("Lead do Facebook criado com sucesso: {$lead->id}", ['lead_data' => $mappedData]);

        } catch (Exception $e) {
            Log::error("Erro ao processar lead do Facebook: {$e->getMessage()}", [
                'leadgen_id' => $this->leadgenId,
                'page_id' => $this->pageId,
                'exception' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Mapeia os dados do lead do Facebook para o formato do CRM.
     *
     * @param array $fieldData
     * @return array
     */
    protected function mapFacebookLeadData(array $fieldData): array
    {
        $mapped = [
            'full_name' => '',
            'email' => '',
            'phone_number' => '',
            'message' => '',
        ];

        foreach ($fieldData as $field) {
            switch ($field['name']) {
                case 'full_name':
                    $mapped['full_name'] = $field['values'][0] ?? '';
                    break;
                case 'email':
                    $mapped['email'] = $field['values'][0] ?? '';
                    break;
                case 'phone_number':
                    $mapped['phone_number'] = $field['values'][0] ?? '';
                    break;
                case 'message':
                    $mapped['message'] = $field['values'][0] ?? '';
                    break;
                // Adicione outros campos conforme necessário para mapeamento
            }
        }

        return $mapped;
    }

    /**
     * Obtém o ID da fonte "Facebook" do banco de dados.
     * Se não existir, cria uma nova.
     *
     * @return int
     */
    protected function getFacebookLeadSourceId(): int
    {
        $sourceRepository = app('Webkul\Lead\Repositories\SourceRepository');

        $facebookSource = $sourceRepository->firstOrCreate(
            ['name' => 'Facebook Ads'],
            ['name' => 'Facebook Ads']
        );

        return $facebookSource->id;
    }
}
