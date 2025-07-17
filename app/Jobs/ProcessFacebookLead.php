<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Http;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\MetaAdsTokens;
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
    public function handle() 
    {
        Log::info("Processando lead do Facebook: {$this->leadgenId} para a página: {$this->pageId}");

        try {
            $metaAdsToken = MetaAdsTokens::where('page_id', $this->pageId)->first();

            if (! $metaAdsToken) {
                Log::error("Token de acesso não encontrado para a página do Facebook: {$this->pageId}");
                return;
            }

            $accessToken = $metaAdsToken->access_token;
        
            $graphApiUrl = "https://graph.facebook.com/v19.0/{$this->leadgenId}?access_token={$accessToken}";

            $response = file_get_contents($graphApiUrl);
            $leadData = json_decode($response, true);

            if (! $leadData || ! isset($leadData['field_data'])) {
                Log::error("Não foi possível obter os dados do lead do Facebook para leadgen_id: {$this->leadgenId}", ['response' => $leadData]);
                return;
            }

            $mappedData = $this->mapFacebookLeadData($leadData['field_data']);

            $url = 'https://'.$metaAdsToken->app_url_customer.'/api/webhook/facebook/create-lead';
            $sendLead = Http::post($url, $mappedData);

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
        );

        return $facebookSource->id;
    }
}
