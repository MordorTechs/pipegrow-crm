<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Jobs\ProcessFacebookLead;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Contact\Repositories\OrganizationRepository;
use Webkul\User\Models\User;

class FacebookWebhookController extends Controller
{
    public $organizationRepository;
    public $personRepository;
    public $leadRepository;

    public function __construct(
        LeadRepository $leadRepository,
        PersonRepository $personRepository,
        OrganizationRepository $organizationRepository) 
    {
        $this->leadRepository = $leadRepository;
        $this->personRepository = $personRepository;
        $this->organizationRepository = $organizationRepository;
    }
    /**
     * Lida com a verificação do webhook do Facebook.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function verifyWebhook(Request $request)
    {
        $verifyToken = env('FACEBOOK_WEBHOOK_VERIFY_TOKEN');
        $mode = $request->input('hub_mode');
        $token = $request->input('hub_verify_token');
        $challenge = $request->input('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                Log::info('Webhook verificado com sucesso!');
                return response($challenge, 200);
            } else {
                return response('Token de verificação incorreto', 403);
            }
        }

        return response('Parâmetros ausentes', 400);
    }

    /**
     * Lida com os dados recebidos do webhook do Facebook.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        Log::info('Dados do Webhook do Facebook recebidos:', $data);

        if (isset($data['object']) && $data['object'] === 'page') {
            foreach ($data['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    if ($change['field'] === 'leadgen' && isset($change['value']['leadgen_id'])) {
                        $leadgenId = $change['value']['leadgen_id'];
                        $pageId = $change['value']['page_id'];
                        ProcessFacebookLead::dispatch($leadgenId, $pageId);

                        Log::info("Job ProcessFacebookLead despachado para leadgen_id: {$leadgenId}");
                    }
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    public function createlead(Request $request)
    {
        $lead = json_decode($request->getContent(), true); 

        $existingPerson = $this->personRepository->whereJsonContains('emails', [['value' => $lead['email'], 'label' => 'work']])->first();

        $data = [
            'name' => $lead['full_name'],
            'emails' => [['value' => $lead['email'], 'label' => 'work']],
            'contact_numbers' => [['value' => $lead['phone_number'], 'label' => 'work']],
            'organization_id' => null,
            'lead_owner_id' => User::inRandomOrder()->value('id'),
        ];

        if ($existingPerson) {
            $existingPerson->update($data);
            $person = $existingPerson;
        } else {
            $person = $this->personRepository->create($data);
        }


        $lead = $this->leadRepository->create([
            'title' => 'Lead do Facebook: ' . $lead['full_name'],
            'lead_pipeline_id' => 1,
            'lead_stage_id' => 1,
            'lead_source_id' => $this->getFacebookLeadSourceId(),
            'person_id' => $person->id,
            'user_id' => null,
            'expected_close_date' => now()->addDays(7),
            'lead_value' => 0,
            'description' => $lead['message'] ?? 'Lead gerado via Facebook Ads.',
            'lead_type_id' => 1,
        ]);
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
