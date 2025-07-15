<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Contact\Repositories\OrganizationRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Lead\Repositories\SourceRepository;
use Webkul\Lead\Repositories\TypeRepository;
use Webkul\User\Models\User;

class WebhookLeadController extends Controller
{
    /**
     * Repositório de Leads.
     *
     * @var \Webkul\Lead\Repositories\LeadRepository
     */
    protected $leadRepository;

    /**
     * Repositório de Pessoas.
     *
     * @var \Webkul\Contact\Repositories\PersonRepository
     */
    protected $personRepository;

    /**
     * Repositório de Organizações.
     *
     * @var \Webkul\Contact\Repositories\OrganizationRepository
     */
    protected $organizationRepository;

    /**
     * Repositório de Atributos.
     *
     * @var \Webkul\Attribute\Repositories\AttributeRepository
     */
    protected $attributeRepository;

    /**
     * Repositório de Fontes de Lead.
     *
     * @var \Webkul\Lead\Repositories\SourceRepository
     */
    protected $sourceRepository;

    /**
     * Repositório de Tipos de Lead.
     *
     * @var \Webkul\Lead\Repositories\TypeRepository
     */
    protected $typeRepository;

    /**
     * Construtor do controller.
     *
     * @param \Webkul\Lead\Repositories\LeadRepository          $leadRepository
     * @param \Webkul\Contact\Repositories\PersonRepository     $personRepository
     * @param \Webkul\Contact\Repositories\OrganizationRepository $organizationRepository
     * @param \Webkul\Attribute\Repositories\AttributeRepository  $attributeRepository
     * @param \Webkul\Lead\Repositories\SourceRepository        $sourceRepository
     * @param \Webkul\Lead\Repositories\TypeRepository          $typeRepository
     * @return void
     */
    public function __construct(
        LeadRepository $leadRepository,
        PersonRepository $personRepository,
        OrganizationRepository $organizationRepository,
        AttributeRepository $attributeRepository,
        SourceRepository $sourceRepository,
        TypeRepository $typeRepository
    ) {
        $this->leadRepository = $leadRepository;
        $this->personRepository = $personRepository;
        $this->organizationRepository = $organizationRepository;
        $this->attributeRepository = $attributeRepository;
        $this->sourceRepository = $sourceRepository;
        $this->typeRepository = $typeRepository;
    }

    /**
     * Lida com as requisições de webhook de leads, roteando-as por tipo.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $type O tipo de webhook (ex: 'site', 'google-ads')
     * @return \Illuminate\Http\Response
     */
    public function handle(Request $request, $type)
    {
        Log::info('Requisição recebida no WebhookLeadController@handle.', [
            'type' => $type,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'payload' => $request->all()
        ]);

        switch ($type) {
            case 'site':
                return $this->handleSiteLead($request);
            case 'google-ads':
                return $this->handleGoogleAdsLead($request);
            default:
                Log::warning('Tipo de webhook não suportado recebido: ' . $type);
                return response()->json(['message' => 'Tipo de webhook não suportado.'], 400);
        }
    }

    /**
     * Lida com leads provenientes do formulário do site.
     * URL: /api/webhook/leads
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleSiteLead(Request $request)
    {
        Log::info('Webhook de leads do site recebido.', $request->all());

        $validator = Validator::make($request->all(), [
            'title'             => 'required|string|max:255',
            'email'             => 'required|email|max:255',
            'phone'             => 'nullable|string',
            'name'              => 'required|string|max:255',
            'organization_name' => 'nullable|string|max:255',
            'message'           => 'nullable|string',
            'source'            => 'nullable|string',
            'pipeline_stage_id' => 'nullable|integer|exists:lead_pipeline_stages,id',
            'lead_type_id'      => 'nullable|integer|exists:lead_types,id',
            'lead_source_id'    => 'nullable|integer|exists:lead_sources,id',
        ]);

        if ($validator->fails()) {
            Log::error('Erro de validação do webhook de leads do site:', $validator->errors()->toArray());
            return response()->json([
                'message' => 'Dados de entrada inválidos para o lead do site.',
                'errors'  => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        try {
            $organizationId = null;
            if (! empty($data['organization_name'])) {
                $organizationData = [
                    'name'        => $data['organization_name'],
                    'user_id'     => $this->randomUserId(),
                    'entity_type' => 'organizations'
                ];
                $organization = $this->organizationRepository->firstOrCreate(
                    ['name' => $data['organization_name']],
                    $organizationData
                );
                $organizationId = $organization->id;
            }

            $person = $this->personRepository->findOneWhere([['emails', 'LIKE', '%"value":"' . $data['email'] . '"%']]);

            if (! $person) { 
                $personData = [
                    'name'            => $data['name'],
                    'emails'          => [['value' => $data['email'], 'label' => 'work']],
                    'contact_numbers' => ! empty($data['phone']) ? [['value' => $data['phone'], 'label' => 'mobile']] : null,
                    'organization_id' => $organizationId,
                    'user_id'         => $this->randomUserId(),
                    'entity_type'     => 'persons'
                ];
                $person = $this->personRepository->create($personData);
            } else {
                $person->name = $data['name'];
                $existingEmails = collect($person->emails);
                if (!$existingEmails->contains('value', $data['email'])) {
                    $person->emails = $existingEmails->push(['value' => $data['email'], 'label' => 'work'])->toArray();
                }

                $existingPhones = collect($person->contact_numbers);
                if (!empty($data['phone']) && !$existingPhones->contains('value', $data['phone'])) {
                    $person->contact_numbers = $existingPhones->push(['value' => $data['phone'], 'label' => 'mobile'])->toArray();
                }
                $person->save();
            }

            $leadData = [
                'title'                  => $data['title'],
                'person_id'              => $person->id,
                'lead_pipeline_stage_id' => $data['pipeline_stage_id'] ?? $this->getDefaultLeadStageId(), 
                'lead_type_id'           => $data['lead_type_id'] ?? $this->getTypeIdByName('Novo Negócio'),
                'lead_source_id'         => $data['lead_source_id'] ?? $this->getSourceIdByName($data['source'] ?? 'Website'),
                'user_id'                => $this->randomUserId(),
                'description'            => $data['message'] ?? null,
                'entity_type'            => 'leads',
                'expected_close_date'    => now()->addDays(30)->format('Y-m-d'),
            ];

            $lead = $this->leadRepository->create($leadData);

            Log::info('Lead do site criado com sucesso via webhook:', ['lead_id' => $lead->id]);

            return response()->json(['message' => 'Lead do site criado com sucesso via webhook.', 'lead_id' => $lead->id], 200);

        } catch (\Exception $e) {
            Log::error('Erro inesperado no webhook de leads do site: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'message' => 'Erro interno do servidor ao processar o webhook do site.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lida com leads provenientes do Google Ads.
     * URL: /api/webhook/leads/google-ads
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleGoogleAdsLead(Request $request)
    {
        try {
            $data = $request->json()->all();

            Log::info('Google Ads Webhook Data: ' . json_encode($data));

            if (isset($data['is_test']) && $data['is_test']) {
                Log::info('Recebido um lead de teste do Google Ads. Não criando um lead no CRM.');
                return response()->json(['message' => 'Lead de teste do Google Ads recebido com sucesso. Nenhum lead criado.'], 200);
            }

            $fullName = null;
            $email = null;
            $phoneNumber = null;

            foreach ($data['user_column_data'] as $column) {
                switch ($column['column_id']) {
                    case 'FULL_NAME':
                        $fullName = $column['string_value'];
                        break;
                    case 'EMAIL':
                        $email = $column['string_value'];
                        break;
                    case 'PHONE_NUMBER':
                        $phoneNumber = $column['string_value'];
                        break;
                }
            }

            if (empty($fullName) || empty($email)) {
                Log::error('Campos obrigatórios ausentes para o lead do Google Ads: FULL_NAME ou EMAIL.');
                return response()->json(['message' => 'Nome Completo e Email são obrigatórios para leads do Google Ads.'], 400);
            }

            $nameParts = explode(' ', $fullName, 2);
            $firstName = $nameParts[0];
            $lastName = $nameParts[1] ?? '';

            $person = $this->personRepository->findOneWhere([
                ['emails', 'LIKE', '%"value":"' . $email . '"%']
            ]);

            if (! $person) {
                $person = $this->personRepository->create([
                    'emails'          => [['value' => $email, 'label' => 'work']],
                    'contact_numbers' => $phoneNumber ? [['value' => $phoneNumber, 'label' => 'work']] : [],
                    'entity_type'     => 'persons'
                ]);
            } else {
                $existingPhones = collect($person->contact_numbers);
                if ($phoneNumber && ! $existingPhones->contains('value', $phoneNumber)) {
                    $person->contact_numbers = $existingPhones->push(['value' => $phoneNumber, 'label' => 'work'])->toArray();
                    $person->save();
                }
            }

            $leadData = [
                'title'            => 'Google Ads Lead: ' . $fullName,
                'description'      => 'Lead recebido do Formulário do Google Ads. ID da Campanha: ' . ($data['campaign_id'] ?? 'N/A') . ', ID do Grupo de Anúncios: ' . ($data['adgroup_id'] ?? 'N/A') . ', ID do Criativo: ' . ($data['creative_id'] ?? 'N/A'),
                'lead_stage_id'    => $this->getDefaultLeadStageId(),
                'lead_pipeline_id' => $this->getDefaultLeadPipelineId(),
                'person_id'        => $person->id,
                'user_id'          => $this->randomUserId(),
                'lead_source_id'   => $this->getSourceIdByName('Google Ads'),
                'lead_type_id'     => $this->getTypeIdByName('New Business'),
                'expected_close_date' => now()->addDays(30)->format('Y-m-d'),
                'lead_products'    => [],
                'entity_type'      => 'leads',
                'custom_attributes' => [
                    'google_lead_id' => $data['lead_id'] ?? null,
                    'gcl_id'         => $data['gcl_id'] ?? null,
                    'form_id'        => $data['form_id'] ?? null,
                    'campaign_id'    => $data['campaign_id'] ?? null,
                    'adgroup_id'     => $data['adgroup_id'] ?? null,
                    'creative_id'    => $data['creative_id'] ?? null,
                ],
            ];

            // Cria o lead
            $lead = $this->leadRepository->create($leadData);

            Log::info('Lead do Google Ads criado com sucesso.', ['lead_id' => $lead->id]);

            return response()->json(['message' => 'Lead do Google Ads criado com sucesso.', 'lead' => $lead], 200);

        } catch (\Exception $e) {
            // Loga mensagem de erro detalhada para o webhook do Google Ads
            Log::error('Erro ao processar o webhook do Google Ads: ' . $e->getMessage() . ' em ' . $e->getFile() . ' na linha ' . $e->getLine());
            return response()->json(['message' => 'Erro ao processar o lead do Google Ads: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Helper para obter o ID do estágio de lead padrão.
     * Busca o primeiro estágio do pipeline padrão.
     *
     * @return int|null
     */
    protected function getDefaultLeadStageId()
    {
        $pipeline = $this->leadRepository->getDefaultPipeline();
        if ($pipeline && $pipeline->stages->isNotEmpty()) {
            return $pipeline->stages->first()->id;
        }

        Log::warning('Nenhum pipeline ou estágio padrão encontrado. Retornando null para lead_stage_id.');
        return null;
    }

    /**
     * Helper para obter o ID do pipeline de lead padrão.
     *
     * @return int|null
     */
    protected function getDefaultLeadPipelineId()
    {
        $pipeline = $this->leadRepository->getDefaultPipeline();
        return $pipeline ? $pipeline->id : null;
    }

    /**
     * Helper para obter o ID da fonte pelo nome, criando-a se não existir.
     *
     * @param string $name
     * @return int|null
     */
    protected function getSourceIdByName($name)
    {
        $source = $this->sourceRepository->findOneByField('name', $name);
        if (! $source) {
            try {
                $source = $this->sourceRepository->create(['name' => $name]);
                Log::info("Fonte de lead '{$name}' criada automaticamente.");
            } catch (\Exception $e) {
                Log::error("Erro ao criar a fonte de lead '{$name}': " . $e->getMessage());
                return null;
            }
        }
        return $source ? $source->id : null;
    }

    /**
     * Helper para obter o ID do tipo pelo nome, criando-o se não existir.
     *
     * @param string $name
     * @return int|null
     */
    protected function getTypeIdByName($name)
    {
        $type = $this->typeRepository->findOneByField('name', $name);
        if (! $type) {
            try {
                $type = $this->typeRepository->create(['name' => $name]);
                Log::info("Tipo de lead '{$name}' criado automaticamente.");
            } catch (\Exception $e) {
                Log::error("Erro ao criar o tipo de lead '{$name}': " . $e->getMessage());
                return null;
            }
        }
        return $type ? $type->id : null;
    }

    private function randomUserId()
    {
        return User::inRandomOrder()->value('id');
    }
}