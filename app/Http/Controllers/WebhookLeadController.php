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
use Webkul\Lead\Models\Lead;
use Webkul\Contact\Models\Person;
use Webkul\Contact\Models\Organization;
use Webkul\User\Models\User; // Adicionado: Para usar o modelo User no randomUserId()
use Illuminate\Support\Collection;

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
            'payload' => $request->all() // Loga o payload completo para depuração
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

            // Encontrar ou Criar Pessoa
            $person = $this->findOrCreatePerson($data['name'], $data['email'], $data['phone'] ?? null, $organizationId);

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

            // Encontrar ou Criar Pessoa
            $person = $this->findOrCreatePerson($fullName, $email, $phoneNumber);

            $leadData = [
                'title'            => 'Google Ads Lead: ' . $fullName,
                'description'      => 'Lead recebido do Formulário do Google Ads. ID da Campanha: ' . ($data['campaign_id'] ?? 'N/A') . ', ID do Grupo de Anúncios: ' . ($data['adgroup_id'] ?? 'N/A') . ', ID do Criativo: ' . ($data['creative_id'] ?? 'N/A'),
                'lead_stage_id'    => $this->getDefaultLeadStageId(),
                'lead_pipeline_id' => $this->getDefaultLeadPipelineId(),
                'person_id'        => $person->id,
                'user_id'          => $this->randomUserId(), // Usa o helper randomUserId()
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

            $lead = $this->leadRepository->create($leadData);

            Log::info('Lead do Google Ads criado com sucesso.', ['lead_id' => $lead->id]);

            return response()->json(['message' => 'Lead do Google Ads criado com sucesso.', 'lead' => $lead], 200);

        } catch (\Exception $e) {
            Log::error('Erro ao processar o webhook do Google Ads: ' . $e->getMessage() . ' em ' . $e->getFile() . ' na linha ' . $e->getLine());
            return response()->json(['message' => 'Erro ao processar o lead do Google Ads: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Helper para encontrar ou criar uma pessoa, priorizando a busca por email.
     *
     * @param string $name
     * @param string $email
     * @param string|null $phone
     * @param int|null $organizationId
     * @return \Webkul\Contact\Models\Person
     */
    protected function findOrCreatePerson($name, $email, $phone = null, $organizationId = null)
    {
        // 1. Tentar encontrar a pessoa pelo email
        $person = $this->personRepository->findOneWhere([
            ['emails', 'LIKE', '%"value":"' . $email . '"%']
        ]);

        // Gerar o unique_id esperado para esta requisição
        $incomingPersonUniqueId = $this->generatePersonUniqueId($email, $phone);

        if (! $person) {
            // Se a pessoa NÃO foi encontrada por email, criar uma nova
            $personData = [
                'name'            => $name,
                'emails'          => [['value' => $email, 'label' => 'work']],
                'contact_numbers' => $phone ? [['value' => $phone, 'label' => 'work']] : [],
                'organization_id' => $organizationId,
                'user_id'         => $this->randomUserId(), // Usa o helper randomUserId()
                'entity_type'     => 'persons',
                'unique_id'       => $incomingPersonUniqueId, // Define o unique_id explicitamente na criação
            ];
            $person = $this->personRepository->create($personData);
        } else {
            // Se a pessoa FOI encontrada por email, atualizar seus dados
            // Atualiza nome se diferente
            if ($person->name !== $name) {
                $person->name = $name;
            }

            // Atualiza emails se o novo email não estiver presente (geralmente não deveria acontecer se encontrado por email)
            $existingEmails = collect($person->emails);
            if ($email && ! $existingEmails->contains('value', $email)) {
                $person->emails = $existingEmails->push(['value' => $email, 'label' => 'work'])->toArray();
            }

            // Atualiza números de contato se o novo número não estiver presente
            $existingPhones = collect($person->contact_numbers);
            if ($phone && ! $existingPhones->contains('value', $phone)) {
                $person->contact_numbers = $existingPhones->push(['value' => $phone, 'label' => 'work'])->toArray();
            }

            // Garante que o unique_id do registro existente seja o que esperamos para esta combinação email+phone
            // Isso é crucial se o unique_id no DB foi gerado de forma diferente antes, ou se o telefone foi adicionado/removido.
            $person->unique_id = $incomingPersonUniqueId;

            $person->save();
        }

        return $person;
    }

    /**
     * Helper para gerar o unique_id de uma pessoa.
     * Assume o formato 'email|phone_number'.
     *
     * @param string $email
     * @param string|null $phoneNumber
     * @return string
     */
    protected function generatePersonUniqueId($email, $phoneNumber = null)
    {
        // Certifique-se de que o número de telefone é uma string vazia se for null,
        // para evitar que o unique_id seja 'email|'
        return $email . '|' . ($phoneNumber ?? '');
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
        // Garante que haja um pipeline e estágios antes de tentar acessar
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
            // Cria a fonte se não existir
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
            // Cria o tipo se não existir
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

    /**
     * Retorna um ID de usuário aleatório para atribuição padrão.
     *
     * @return int|null
     */
    private function randomUserId()
    {
        // Tenta encontrar um usuário aleatório. Se não houver usuários, retorna null.
        return User::inRandomOrder()->value('id');
    }
}
