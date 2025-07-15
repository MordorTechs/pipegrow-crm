<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Contact\Repositories\OrganizationRepository;
use Webkul\Lead\Models\Lead; // Importe o modelo Lead
use Webkul\Contact\Models\Person; // Importe o modelo Person
use Webkul\Contact\Models\Organization; // Importe o modelo Organization

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
     * Construtor do controller.
     *
     * @param \Webkul\Lead\Repositories\LeadRepository        $leadRepository
     * @param \Webkul\Contact\Repositories\PersonRepository   $personRepository
     * @param \Webkul\Contact\Repositories\OrganizationRepository $organizationRepository
     * @return void
     */
    public function __construct(
        LeadRepository $leadRepository,
        PersonRepository $personRepository,
        OrganizationRepository $organizationRepository
    ) {
        $this->leadRepository = $leadRepository;
        $this->personRepository = $personRepository;
        $this->organizationRepository = $organizationRepository;
    }

    /**
     * Handle the incoming webhook request for leads.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        Log::info('Webhook de leads recebido.', $request->all());

        // 1. Defina as regras de validação para os dados do webhook
        $validator = Validator::make($request->all(), [
            'title'       => 'required|string|max:255',
            'email'       => 'required|email|max:255',
            'phone'       => 'nullable|string',
            'name'        => 'required|string|max:255',
            'organization_name' => 'nullable|string|max:255',
            'message'     => 'nullable|string',
            'source'      => 'nullable|string',
            'pipeline_stage_id' => 'nullable|integer|exists:lead_pipeline_stages,id',
            'lead_type_id' => 'nullable|integer|exists:lead_types,id',
            'lead_source_id' => 'nullable|integer|exists:lead_sources,id',
            // Adicione outras regras para campos personalizados do seu CRM
        ]);

        if ($validator->fails()) {
            Log::error('Erro de validação do webhook de leads:', $validator->errors()->toArray());
            return response()->json([
                'message' => 'Dados de entrada inválidos.',
                'errors'  => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();

        try {
            // 2. Encontrar ou Criar Organização (Opcional)
            $organizationId = null;
            if (! empty($data['organization_name'])) {
                $organizationData = [
                    'name'      => $data['organization_name'],
                    'user_id'   => 1, // Atribua a um usuário padrão
                    'entity_type' => 'organizations' // Adiciona entity_type diretamente aos dados
                ];
                $organization = $this->organizationRepository->firstOrCreate(
                    ['name' => $data['organization_name']],
                    $organizationData
                );
                $organizationId = $organization->id;
            }

            // 3. Encontrar ou Criar Pessoa
            $person = $this->personRepository->findOneWhere([['emails', 'LIKE', '%"value":"' . $data['email'] . '"%']]);

            if (! $person) { // Se a pessoa não foi encontrada
                $personData = [
                    'name'            => $data['name'],
                    'emails'          => [['value' => $data['email'], 'label' => 'work']],
                    'contact_numbers' => ! empty($data['phone']) ? [['value' => $data['phone'], 'label' => 'mobile']] : null,
                    'organization_id' => $organizationId,
                    'user_id'         => 1, // Atribua a um usuário padrão
                    'entity_type'     => 'persons' // Adiciona entity_type diretamente aos dados
                ];
                $person = $this->personRepository->create($personData);
            } else {
                // Se a pessoa já existe, você pode querer atualizar alguns dados
                $person->name = $data['name'];
                // Atualizar emails e telefones de forma inteligente para não duplicar
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

            // 4. Criar o Lead
            $leadData = [
                'title'       => $data['title'],
                'person_id'   => $person->id,
                'lead_pipeline_stage_id' => $data['pipeline_stage_id'] ?? 1,
                'lead_type_id' => $data['lead_type_id'] ?? 1,
                'lead_source_id' => $data['lead_source_id'] ?? 1,
                'user_id'     => 1,
                'description' => $data['message'] ?? null,
                'entity_type' => 'leads' // Adiciona entity_type diretamente aos dados
            ];

            $lead = $this->leadRepository->create($leadData);

            Log::info('Lead criado com sucesso via webhook:', ['lead_id' => $lead->id]);

            return response()->json(['message' => 'Lead criado com sucesso via webhook.', 'lead_id' => $lead->id], 200);

        } catch (\Exception $e) {
            Log::error('Erro inesperado no webhook de leads: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'message' => 'Erro interno do servidor ao processar o webhook.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
