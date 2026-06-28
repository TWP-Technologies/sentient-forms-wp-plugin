<?php

if ( ! class_exists( 'Sentient_Forms_Lead_Value_Test_GFAPI' ) && ! class_exists( 'GFAPI' ) )
{
    class GFAPI
    {
        public static array $entries = [];
        public static array $forms = [];
        public static int $get_form_calls = 0;
        public static int $get_entry_calls = 0;

        public static function get_form( $form_id )
        {
            ++self::$get_form_calls;
            return self::$forms[ (int) $form_id ] ?? false;
        }

        public static function get_entry( $entry_id )
        {
            ++self::$get_entry_calls;
            return self::$entries[ (int) $entry_id ] ?? new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
        }

        public static function get_entries( $form_id, $search_criteria = [], $sorting = null, $paging = null )
        {
            return array_values(
                array_filter(
                    self::$entries,
                    static fn ( array $entry ): bool => (int) ( $entry['form_id'] ?? 0 ) === (int) $form_id
                )
            );
        }

        public static function count_entries( $form_id, $search_criteria = [] )
        {
            return count( self::get_entries( $form_id, $search_criteria ) );
        }
    }
}

class Sentient_Forms_Lead_Value_Test_Managed_Proxy_Client extends Sentient_Forms_Managed_Proxy_Client
{
    /** @var array<int, array{proxy_api_key: string, payload: array<string, mixed>}> */
    public array $execute_calls = [];

    public ?string $output_text = null;

    public function execute( string $proxy_api_key, array $payload ): array | WP_Error
    {
        $this->execute_calls[] = [
            'proxy_api_key' => $proxy_api_key,
            'payload'       => $payload,
        ];

        return [
            'execution_request_id' => $payload['execution_request_id'] ?? 'lead-profile-managed-test',
            'model'                => $payload['model'] ?? 'openai/gpt-5.5',
            'status'               => 'succeeded',
            'output'               => [
                'text' => $this->output_text ?? wp_json_encode(
                    [
                        'generated_profile_prompt' => 'Managed augmented profile prompt with current market context and trusted spam guidance.',
                        'grading_rubric'           => [
                            'scale' => [
                                'A'      => 'Managed strong fit',
                                'B'      => 'Managed likely fit',
                                'C'      => 'Managed possible fit',
                                'Reject' => 'Managed reject',
                            ],
                        ],
                        'assistant_questions'      => [
                            [
                                'key'      => 'managed_follow_up',
                                'question' => 'Which current service lines should qualify for urgent handoff?',
                                'why'      => 'The managed profile found service-line ambiguity.',
                            ],
                        ],
                        'improvement_notes'        => [ 'Clarify urgent service lines.' ],
                    ]
                ),
            ],
        ];
    }
}

class Sentient_Forms_Lead_Value_Test_Local_Action_Execution_Service extends Sentient_Forms_Local_Action_Execution_Service
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
    {
        $this->calls[] = [
            'mapping_id' => $mapping_id,
            'form'       => $form,
            'entry'      => $entry,
            'context'    => $context,
        ];

        return [
            'execution_request_id' => (string) ( $context['execution_request_id'] ?? 'historical-test' ),
            'status'               => 'succeeded',
            'provider'             => 'sentient_managed',
            'model'                => 'openrouter/auto',
            'result'               => [
                'structured' => [
                    'grade'           => 'A',
                    'profile_version' => 1,
                ],
            ],
            'effects'              => [
                'applied' => [],
                'skipped' => [],
            ],
        ];
    }
}

class Tests_Lead_Value_Controller extends WP_UnitTestCase
{
    private static int $admin_id;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = (int) $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user( self::$admin_id );

        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_tables();
        $this->reset_options();
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );

        add_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        new Sentient_Forms_REST_API();
        do_action( 'rest_api_init', rest_get_server() );
    }

    protected function tearDown(): void
    {
        remove_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        $this->reset_options();
        parent::tearDown();
    }

    public function controller_classes(): array
    {
        return [ Sentient_Forms_Lead_Value_Controller::class ];
    }

    public function test_profile_generation_requires_consent_context_guidance_and_criteria(): void
    {
        $created = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/profile',
            [
                'lead_profile_consent' => true,
                'good_lead_criteria'   => [
                    'summary_text' => 'A good lead has a real business need, a reachable email address, service-area fit, and clear intent to discuss a project.',
                ],
                'bad_lead_criteria'    => [
                    'summary_text' => 'A bad lead is irrelevant, spam-like, abusive, outside the service area, impossible to contact, or only asking for unrelated backlinks.',
                ],
            ],
            201
        );

        $this->assertFalse( $created['readiness']['ready'] );
        $this->assertContains( 'site_context', wp_list_pluck( $created['readiness']['blockers'], 'key' ) );
        $this->assertContains( 'spam_guidance_positive', wp_list_pluck( $created['readiness']['blockers'], 'key' ) );

        $this->seed_ready_site_context();
        $this->seed_spam_guidance();

        $generated = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/profiles/' . $created['profile']['id'] . '/generate',
            [ 'lead_profile_consent' => true ]
        );

        $this->assertTrue( $generated['readiness']['ready'] );
        $this->assertSame( 'active', $generated['profile']['status'] );
        $this->assertSame( [ 'A', 'B', 'C', 'Reject' ], array_keys( $generated['profile']['grading_rubric']['scale'] ) );
        $this->assertStringContainsString( 'TRUSTED_SITE_CONTEXT', $generated['profile']['generated_profile_prompt'] );
        $this->assertSame( 'local_readiness_grounded_profile_v1', $generated['profile']['generation_metadata']['generation_mode'] );
    }

    public function test_profile_generation_uses_managed_reasoning_tools_when_account_is_active(): void
    {
        $this->seed_ready_site_context();
        $this->seed_spam_guidance();

        $created = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/profile',
            [
                'lead_profile_consent' => true,
                'good_lead_criteria'   => [
                    'summary_text' => 'A good lead has a real business need, a reachable email address, service-area fit, and clear intent to discuss a project.',
                ],
                'bad_lead_criteria'    => [
                    'summary_text' => 'A bad lead is irrelevant, spam-like, abusive, outside the service area, impossible to contact, or only asking for unrelated backlinks.',
                ],
            ],
            201
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'site_id'        => '11111111-1111-4111-8111-111111111111',
                'proxy_api_key'  => 'proxy-profile-generation-test',
            ]
        );
        $this->record_managed_proxy_consent();

        $managed_proxy = new Sentient_Forms_Lead_Value_Test_Managed_Proxy_Client();
        $controller    = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            null,
            null,
            null,
            null,
            $managed_proxy
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/profiles/' . $created['profile']['id'] . '/generate' );
        $request->set_param( 'id', (int) $created['profile']['id'] );
        $request->set_param( 'lead_profile_consent', true );
        $response = $controller->generate_profile( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'managed_augmented_profile_v1', $data['profile']['generation_metadata']['generation_mode'] );
        $this->assertStringContainsString( 'Managed augmented profile prompt', $data['profile']['generated_profile_prompt'] );
        $this->assertSame( 'Managed strong fit', $data['profile']['grading_rubric']['scale']['A'] );
        $this->assertNotEmpty( $data['profile']['assistant']['questions'] );

        $this->assertCount( 1, $managed_proxy->execute_calls );
        $payload = $managed_proxy->execute_calls[0]['payload'];
        $this->assertSame( [ 'effort' => 'xhigh', 'exclude' => true ], $payload['reasoning'] );
        $this->assertContains( [ 'type' => 'openrouter:web_fetch' ], $payload['tools'] );
        $this->assertSame( 'openrouter:web_search', $payload['tools'][0]['type'] ?? null );
        $this->assertSame( 'auto', $payload['tool_choice'] ?? null );
        $this->assertSame( 6000, $payload['max_output_tokens'] );
    }

    public function test_profile_generation_extracts_wrapped_managed_json(): void
    {
        $this->seed_ready_site_context();
        $this->seed_spam_guidance();

        $created = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/profile',
            [
                'lead_profile_consent' => true,
                'good_lead_criteria'   => [
                    'summary_text' => 'A good lead has a real business need, a reachable email address, service-area fit, and clear intent to discuss a project.',
                ],
                'bad_lead_criteria'    => [
                    'summary_text' => 'A bad lead is irrelevant, spam-like, abusive, outside the service area, impossible to contact, or only asking for unrelated backlinks.',
                ],
            ],
            201
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'site_id'        => '11111111-1111-4111-8111-111111111111',
                'proxy_api_key'  => 'proxy-profile-generation-test',
            ]
        );
        $this->record_managed_proxy_consent();

        $managed_proxy              = new Sentient_Forms_Lead_Value_Test_Managed_Proxy_Client();
        $managed_proxy->output_text = 'Here is the requested JSON: '
            . wp_json_encode(
                [
                    'generated_profile_prompt' => 'Wrapped managed profile prompt.',
                    'grading_rubric'           => [
                        'scale' => [
                            'A'      => 'Wrapped strong fit',
                            'B'      => 'Wrapped likely fit',
                            'C'      => 'Wrapped possible fit',
                            'Reject' => 'Wrapped reject',
                        ],
                    ],
                    'assistant_questions'      => [],
                    'improvement_notes'        => [],
                ]
            );
        $controller = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            null,
            null,
            null,
            null,
            $managed_proxy
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/profiles/' . $created['profile']['id'] . '/generate' );
        $request->set_param( 'id', (int) $created['profile']['id'] );
        $request->set_param( 'lead_profile_consent', true );
        $response = $controller->generate_profile( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'managed_augmented_profile_v1', $data['profile']['generation_metadata']['generation_mode'] );
        $this->assertSame( 'Wrapped managed profile prompt.', $data['profile']['generated_profile_prompt'] );
        $this->assertSame( 'Wrapped strong fit', $data['profile']['grading_rubric']['scale']['A'] );
    }

    public function test_profile_generation_skips_managed_reasoning_after_managed_consent_revocation(): void
    {
        $this->seed_ready_site_context();
        $this->seed_spam_guidance();

        $created = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/profile',
            [
                'lead_profile_consent' => true,
                'good_lead_criteria'   => [
                    'summary_text' => 'A good lead has a real business need, a reachable email address, service-area fit, and clear intent to discuss a project.',
                ],
                'bad_lead_criteria'    => [
                    'summary_text' => 'A bad lead is irrelevant, spam-like, abusive, outside the service area, impossible to contact, or only asking for unrelated backlinks.',
                ],
            ],
            201
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'site_id'        => '11111111-1111-4111-8111-111111111111',
                'proxy_api_key'  => 'proxy-profile-generation-test',
            ]
        );
        $this->record_managed_proxy_consent( 'revoke_managed_proxy' );

        $managed_proxy = new Sentient_Forms_Lead_Value_Test_Managed_Proxy_Client();
        $controller    = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            null,
            null,
            null,
            null,
            $managed_proxy
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/profiles/' . $created['profile']['id'] . '/generate' );
        $request->set_param( 'id', (int) $created['profile']['id'] );
        $request->set_param( 'lead_profile_consent', true );
        $response = $controller->generate_profile( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'local_readiness_grounded_profile_v1', $data['profile']['generation_metadata']['generation_mode'] );
        $this->assertSame( 'skipped', $data['profile']['generation_metadata']['llm_augmentation']['status'] ?? null );
        $this->assertSame(
            'sentient_forms_external_service_consent_revoked',
            $data['profile']['generation_metadata']['llm_augmentation']['reason'] ?? null
        );
        $this->assertCount( 0, $managed_proxy->execute_calls );
    }

    public function test_profile_generation_requires_managed_consent_record_before_proxy_augmentation(): void
    {
        $this->seed_ready_site_context();
        $this->seed_spam_guidance();

        $created = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/profile',
            [
                'lead_profile_consent' => true,
                'good_lead_criteria'   => [
                    'summary_text' => 'A good lead has a real business need, a reachable email address, service-area fit, and clear intent to discuss a project.',
                ],
                'bad_lead_criteria'    => [
                    'summary_text' => 'A bad lead is irrelevant, spam-like, abusive, outside the service area, impossible to contact, or only asking for unrelated backlinks.',
                ],
            ],
            201
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'site_id'        => '11111111-1111-4111-8111-111111111111',
                'proxy_api_key'  => 'proxy-profile-generation-test',
            ]
        );

        $managed_proxy = new Sentient_Forms_Lead_Value_Test_Managed_Proxy_Client();
        $controller    = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            null,
            null,
            null,
            null,
            $managed_proxy
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/profiles/' . $created['profile']['id'] . '/generate' );
        $request->set_param( 'id', (int) $created['profile']['id'] );
        $request->set_param( 'lead_profile_consent', true );
        $response = $controller->generate_profile( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'local_readiness_grounded_profile_v1', $data['profile']['generation_metadata']['generation_mode'] );
        $this->assertSame( 'skipped', $data['profile']['generation_metadata']['llm_augmentation']['status'] ?? null );
        $this->assertSame(
            'sentient_forms_external_service_consent_required',
            $data['profile']['generation_metadata']['llm_augmentation']['reason'] ?? null
        );
        $this->assertCount( 0, $managed_proxy->execute_calls );
    }

    public function test_profile_generation_does_not_treat_checkout_consent_after_revocation_as_proxy_acceptance(): void
    {
        $this->seed_ready_site_context();
        $this->seed_spam_guidance();

        $created = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/profile',
            [
                'lead_profile_consent' => true,
                'good_lead_criteria'   => [
                    'summary_text' => 'A good lead has a real business need, a reachable email address, service-area fit, and clear intent to discuss a project.',
                ],
                'bad_lead_criteria'    => [
                    'summary_text' => 'A bad lead is irrelevant, spam-like, abusive, outside the service area, impossible to contact, or only asking for unrelated backlinks.',
                ],
            ],
            201
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'site_id'        => '11111111-1111-4111-8111-111111111111',
                'proxy_api_key'  => 'proxy-profile-generation-test',
            ]
        );
        $this->record_managed_proxy_consent( 'revoke_managed_proxy' );
        $this->record_managed_proxy_consent( 'managed_checkout_start' );

        $managed_proxy = new Sentient_Forms_Lead_Value_Test_Managed_Proxy_Client();
        $controller    = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            null,
            null,
            null,
            null,
            $managed_proxy
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/profiles/' . $created['profile']['id'] . '/generate' );
        $request->set_param( 'id', (int) $created['profile']['id'] );
        $request->set_param( 'lead_profile_consent', true );
        $response = $controller->generate_profile( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'local_readiness_grounded_profile_v1', $data['profile']['generation_metadata']['generation_mode'] );
        $this->assertSame( 'skipped', $data['profile']['generation_metadata']['llm_augmentation']['status'] ?? null );
        $this->assertSame(
            'sentient_forms_external_service_consent_required',
            $data['profile']['generation_metadata']['llm_augmentation']['reason'] ?? null
        );
        $this->assertCount( 0, $managed_proxy->execute_calls );
    }

    public function test_profile_generation_can_queue_async_job_without_blocking_managed_request(): void
    {
        $this->seed_ready_site_context();
        $this->seed_spam_guidance();

        $created = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/profile',
            [
                'lead_profile_consent' => true,
                'good_lead_criteria'   => [
                    'summary_text' => 'A good lead has a real business need, a reachable email address, service-area fit, and clear intent to discuss a project.',
                ],
                'bad_lead_criteria'    => [
                    'summary_text' => 'A bad lead is irrelevant, spam-like, abusive, outside the service area, impossible to contact, or only asking for unrelated backlinks.',
                ],
            ],
            201
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'site_id'        => '11111111-1111-4111-8111-111111111111',
                'proxy_api_key'  => 'proxy-profile-generation-test',
            ]
        );

        $managed_proxy = new Sentient_Forms_Lead_Value_Test_Managed_Proxy_Client();
        $controller    = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            null,
            null,
            null,
            null,
            $managed_proxy
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/profiles/' . $created['profile']['id'] . '/generate' );
        $request->set_param( 'id', (int) $created['profile']['id'] );
        $request->set_param( 'lead_profile_consent', true );
        $request->set_param( 'async', true );
        $response = $controller->generate_profile( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $this->assertSame( 202, $response->get_status() );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( 'queued', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'queued', $data['profile']['generation_metadata']['llm_augmentation']['status'] ?? null );
        $this->assertSame( [], $managed_proxy->execute_calls );
    }

    public function test_historical_dry_run_estimates_credits_without_execution(): void
    {
        $run = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/historical-runs',
            [
                'action_code' => 'lead_grading_v1',
                'entry_ids'   => [ 1001, 1002, 1003 ],
                'dry_run'     => true,
            ],
            201
        );

        $this->assertSame( 'preview_ready', $run['run']['status'] );
        $this->assertSame( 3, $run['run']['estimated_entry_count'] );
        $this->assertSame( 9, $run['run']['estimated_managed_credits'] );
        $this->assertTrue( $run['run']['dry_run'] );

        $started = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/historical-runs/' . $run['run']['id'] . '/start',
            [ 'confirm_costs' => false ]
        );

        $this->assertSame( 'preview_ready', $started['run']['status'] );
        $this->assertSame( 0, $started['run']['progress']['processed'] );
    }

    public function test_non_gravity_historical_preview_does_not_estimate_ledger_runs_as_executable(): void
    {
        global $wpdb;

        $ledger  = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created = $ledger->create(
            [
                'submission_uuid'     => '33333333-4444-4555-8666-777777777777',
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'logical_fields_json' => [
                    'name' => [
                        'label' => 'Name',
                        'value' => 'Ada Buyer',
                    ],
                ],
            ]
        );
        $this->assertIsInt( $created );

        $run = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/contact_form_7/42/historical-runs',
            [
                'action_code' => 'lead_grading_v1',
                'dry_run'     => true,
            ],
            201
        );

        $this->assertSame( 'preview_ready', $run['run']['status'] );
        $this->assertSame( 0, $run['run']['estimated_entry_count'] );
        $this->assertSame( 0, $run['run']['estimated_managed_credits'] );
    }

    public function test_non_gravity_historical_preview_rejects_selected_entries_up_front(): void
    {
        $this->reset_gfapi_lookup_counters();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/forms/contact_form_7/42/historical-runs' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'form_source', 'contact_form_7' );
        $request->set_param( 'form_id', '42' );
        $request->set_body_params(
            [
                'action_code' => 'lead_grading_v1',
                'entry_ids'   => [ 1001 ],
                'dry_run'     => true,
            ]
        );

        $response = ( new Sentient_Forms_Lead_Value_Controller() )->create_historical_run( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'sentient_forms_historical_non_gravity_unsupported', $response->get_error_code() );
        $this->assertSame( 0, GFAPI::$get_form_calls );
        $this->assertSame( 0, GFAPI::$get_entry_calls );
    }

    public function test_non_gravity_historical_execution_is_rejected_before_gf_entry_lookup(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'            => 'lead_grading_v1',
                'display_name'    => 'Lead Scoring',
                'definition_json' => [
                    'template_code' => 'lead_grading_v1',
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $mapping_id = $mappings->create(
            [
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'hook'                => 'wpcf7_mail_sent',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'sync',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        GFAPI::$forms = [
            42 => [
                'id'    => 42,
                'title' => 'Wrong Provider GF Form',
            ],
        ];
        GFAPI::$entries = [
            1001 => [
                'id'      => 1001,
                'form_id' => 42,
                '1'       => 'Gravity overlap',
            ],
        ];
        $this->reset_gfapi_lookup_counters();

        $historical_runs = new Sentient_Forms_Historical_Analysis_Runs_Repository( $wpdb );
        $run_id = $historical_runs->create(
            [
                'form_source'               => 'contact_form_7',
                'form_id'                   => '42',
                'action_code'               => 'lead_grading_v1',
                'selected_entry_ids_json'   => [ 1001 ],
                'estimated_entry_count'     => 1,
                'estimated_managed_credits' => 3,
                'dry_run'                   => false,
                'status'                    => 'preview_ready',
                'progress_json'             => [
                    'processed' => 0,
                    'total'     => 1,
                    'errors'    => [],
                ],
                'created_by_user_id'        => get_current_user_id() ?: null,
            ]
        );
        $this->assertIsInt( $run_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/historical-runs/' . $run_id . '/start' );
        $request->set_param( 'id', $run_id );
        $request->set_param( 'confirm_costs', true );

        $controller = new Sentient_Forms_Lead_Value_Controller();
        $response   = $controller->start_historical_run( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'sentient_forms_historical_non_gravity_unsupported', $response->get_error_code() );
        $this->assertSame( 0, GFAPI::$get_form_calls );
        $this->assertSame( 0, GFAPI::$get_entry_calls );
    }

    public function test_historical_confirmed_run_executes_entries_once_and_completed_start_is_idempotent(): void
    {
        global $wpdb;

        GFAPI::$forms = [
            7 => [
                'id'    => 7,
                'title' => 'Lead Scoring Form',
            ],
        ];
        GFAPI::$entries = [
            1001 => [
                'id'      => 1001,
                'form_id' => 7,
                '1'       => 'Ada Buyer',
            ],
            1002 => [
                'id'      => 1002,
                'form_id' => 7,
                '1'       => 'Grace Buyer',
            ],
        ];

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'            => 'lead_grading_v1',
                'display_name'    => 'Lead Scoring',
                'definition_json' => [
                    'template_code' => 'lead_grading_v1',
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'sync',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $run = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/lead-value/forms/gravity_forms/7/historical-runs',
            [
                'action_code' => 'lead_grading_v1',
                'entry_ids'   => [ 1001, 1002 ],
                'dry_run'     => false,
            ],
            201
        );

        $local_execution = new Sentient_Forms_Lead_Value_Test_Local_Action_Execution_Service();
        $controller      = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            null,
            null,
            null,
            $local_execution
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/historical-runs/' . $run['run']['id'] . '/start' );
        $request->set_param( 'id', (int) $run['run']['id'] );
        $request->set_param( 'confirm_costs', true );
        $response = $controller->start_historical_run( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'completed', $data['run']['status'] );
        $this->assertSame( 2, $data['run']['progress']['processed'] );
        $this->assertCount( 2, $local_execution->calls );

        $second_response = $controller->start_historical_run( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $second_response );
        $second_data = $second_response->get_data();
        $this->assertSame( 'completed', $second_data['run']['status'] );
        $this->assertSame( 'Historical run is already completed.', $second_data['message'] );
        $this->assertCount( 2, $local_execution->calls );
    }

    public function test_search_entries_returns_submission_ledger_rows_for_non_gravity_sources(): void
    {
        global $wpdb;

        $submission_uuid = '11111111-2222-4333-8444-555555555555';
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created         = $ledger->create(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'logical_fields_json' => [
                    'your_name'  => 'Ada Buyer',
                    'your_email' => 'ada@example.test',
                    'message'    => 'Pricing request for automation.',
                ],
            ]
        );
        $this->assertIsInt( $created );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/lead-value/forms/contact_form_7/42/entries/search' );
        $request->set_param( 'form_source', 'contact_form_7' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'q', 'pricing' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

        $data = $response->get_data();
        $this->assertIsArray( $data );

        $this->assertSame( 'contact_form_7', $data['form_source'] );
        $this->assertSame( 42, $data['form_id'] );
        $this->assertSame( $submission_uuid, $data['entries'][0]['id'] ?? null );
        $this->assertSame( $submission_uuid, $data['entries'][0]['submission_uuid'] ?? null );
        $this->assertSame( 'Ada Buyer', $data['entries'][0]['field_summary'][0]['value'] ?? null );
    }

    public function test_search_entries_scans_past_first_submission_ledger_page(): void
    {
        global $wpdb;

        $matching_uuid = '11111111-2222-4333-8444-000000000001';
        $ledger        = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created       = $ledger->create(
            [
                'submission_uuid'     => $matching_uuid,
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'logical_fields_json' => [
                    'your_name' => 'Needle Buyer',
                    'message'   => 'Needle project request for automation.',
                ],
            ]
        );
        $this->assertIsInt( $created );

        for ( $i = 2; $i <= 106; ++$i )
        {
            $created = $ledger->create(
                [
                    'submission_uuid'     => sprintf( '11111111-2222-4333-8444-%012d', $i ),
                    'form_source'         => 'contact_form_7',
                    'form_id'             => '42',
                    'logical_fields_json' => [
                        'your_name' => 'Routine Buyer ' . $i,
                        'message'   => 'Routine nonmatching request.',
                    ],
                ]
            );
            $this->assertIsInt( $created );
        }

        $first_page = $ledger->list_for_form( 'contact_form_7', '42', 100, 0 );
        $this->assertNotContains( $matching_uuid, wp_list_pluck( $first_page, 'submission_uuid' ) );
        $second_page = $ledger->list_for_form( 'contact_form_7', '42', 100, 100 );
        $this->assertContains( $matching_uuid, wp_list_pluck( $second_page, 'submission_uuid' ) );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/lead-value/forms/contact_form_7/42/entries/search' );
        $request->set_param( 'form_source', 'contact_form_7' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'q', 'needle' );
        $request->set_param( 'limit', 1 );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( $matching_uuid, $data['entries'][0]['submission_uuid'] ?? null );
        $this->assertSame( 'Needle Buyer', $data['entries'][0]['field_summary'][0]['value'] ?? null );
    }

    public function test_search_entries_matches_submission_ledger_fields_beyond_preview_limit(): void
    {
        global $wpdb;

        $submission_uuid = '11111111-2222-4333-8444-999999999999';
        $logical_fields  = [];
        for ( $i = 1; $i <= 13; ++$i )
        {
            $logical_fields[ 'field_' . $i ] = 13 === $i
                ? 'Deep search needle beyond preview.'
                : 'Routine preview value ' . $i;
        }

        $ledger  = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created = $ledger->create(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'logical_fields_json' => $logical_fields,
            ]
        );
        $this->assertIsInt( $created );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/lead-value/forms/contact_form_7/42/entries/search' );
        $request->set_param( 'form_source', 'contact_form_7' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'q', 'deep search needle' );
        $request->set_param( 'limit', 1 );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertSame( $submission_uuid, $data['entries'][0]['submission_uuid'] ?? null );
        $this->assertCount( 12, $data['entries'][0]['field_summary'] ?? [] );
        $this->assertNotContains( 'field_13', wp_list_pluck( $data['entries'][0]['field_summary'] ?? [], 'field_id' ) );
    }

    public function test_manual_suggested_reply_accepts_ledger_submission_uuid_for_non_gravity_sources(): void
    {
        global $wpdb;

        $submission_uuid = '22222222-3333-4444-8555-666666666666';
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created         = $ledger->create(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'logical_fields_json' => [
                    'your_name' => 'Grace Buyer',
                    'message'   => 'Can you help with a multi-location intake workflow?',
                ],
            ]
        );
        $this->assertIsInt( $created );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'            => 'suggested_reply_v1',
                'display_name'    => 'Suggested Reply',
                'definition_json' => [
                    'template_code' => 'suggested_reply_v1',
                ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mappings   = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $mapping_id = $mappings->create(
            [
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'hook'                => 'wpcf7_mail_sent',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $local_execution = new Sentient_Forms_Lead_Value_Test_Local_Action_Execution_Service();
        $controller      = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            $mappings,
            $custom_actions,
            null,
            $local_execution
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/forms/contact_form_7/42/entries/' . $submission_uuid . '/suggested-reply' );
        $request->set_param( 'form_source', 'contact_form_7' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'entry_id', $submission_uuid );

        $response = $controller->generate_entry_suggested_reply( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 202, $response->get_status() );
        $this->assertSame( $mapping_id, $local_execution->calls[0]['mapping_id'] ?? null );
        $this->assertSame( $submission_uuid, $local_execution->calls[0]['entry']['submission_uuid'] ?? null );
        $this->assertSame( $submission_uuid, $local_execution->calls[0]['context']['submission_uuid'] ?? null );
        $this->assertSame( 'manual:suggested_reply_v1:contact_form_7:42:' . $submission_uuid, $data['execution']['execution_request_id'] ?? null );
    }

    public function test_manual_suggested_reply_rejects_invalid_gravity_form_entry_pair(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'            => 'suggested_reply_v1',
                'display_name'    => 'Suggested Reply',
                'definition_json' => [
                    'template_code' => 'suggested_reply_v1',
                ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mappings   = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $local_execution = new Sentient_Forms_Lead_Value_Test_Local_Action_Execution_Service();
        $controller      = new Sentient_Forms_Lead_Value_Controller(
            null,
            null,
            $mappings,
            $custom_actions,
            null,
            $local_execution
        );

        GFAPI::$forms   = [];
        GFAPI::$entries = [
            99 => [
                'id'      => 99,
                'form_id' => 7,
            ],
        ];

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/lead-value/forms/gravity_forms/7/entries/99/suggested-reply' );
        $request->set_param( 'form_source', 'gravity_forms' );
        $request->set_param( 'form_id', 7 );
        $request->set_param( 'entry_id', 99 );
        $missing_form = $controller->generate_entry_suggested_reply( $request );
        $this->assertWPError( $missing_form );
        $this->assertSame( 'sentient_forms_gf_form_missing', $missing_form->get_error_code() );

        GFAPI::$forms = [
            7 => [
                'id'    => 7,
                'title' => 'Lead intake',
            ],
        ];
        GFAPI::$entries[99]['form_id'] = 8;

        $mismatch = $controller->generate_entry_suggested_reply( $request );
        $this->assertWPError( $mismatch );
        $this->assertSame( 'sentient_forms_gf_entry_form_mismatch', $mismatch->get_error_code() );
        $this->assertSame( [], $local_execution->calls );
    }

    public function test_dashboard_counts_grades_from_execution_events(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'lead-grading-a',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '10',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
                'result_json'          => [
                    'result' => [
                        'structured' => [
                            'grade' => 'A',
                        ],
                    ],
                ],
            ]
        );
        $events->record(
            [
                'execution_request_id' => 'lead-grading-reject',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '11',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
                'result_json'          => [
                    'result' => [
                        'structured' => [
                            'grade' => 'Reject',
                        ],
                    ],
                ],
            ]
        );

        $dashboard = $this->dispatch_json( 'GET', '/sentient-forms/v1/lead-value/forms/gravity_forms/7/dashboard' );

        $this->assertSame( 2, $dashboard['event_count'] );
        $this->assertSame( 1, $dashboard['grades']['A'] );
        $this->assertSame( 1, $dashboard['grades']['Reject'] );
    }

    public function test_dashboard_recovers_managed_non_gravity_lead_result_data(): void
    {
        global $wpdb;

        $submission_uuid = '55555555-6666-4777-8888-999999999999';
        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created = $ledger->create(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'contact_form_7',
                'form_id'             => '42',
                'logical_fields_json' => [
                    'name' => 'Ada Buyer',
                ],
            ]
        );
        $this->assertIsInt( $created );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'managed-opaque-request',
                'form_source'          => 'contact_form_7',
                'form_id'              => '42',
                'submission_uuid'      => $submission_uuid,
                'provider'             => 'sentient_managed',
                'status'               => 'succeeded',
                'result_json'          => [
                    'central_action_id' => 'lead_grading_v1',
                    'action_name_label' => 'Lead Scoring',
                    'result_data'       => [
                        'structured_output' => [
                            'grade'                => 'A',
                            'confidence'           => 0.91,
                            'profile_version'      => 2,
                            'fit_summary'          => 'Strong fit for a managed follow-up.',
                            'intent_summary'       => 'Ready to talk with sales.',
                            'recommended_priority' => 'high',
                            'justification'        => 'The submission has urgency and clear contact details.',
                        ],
                    ],
                ],
            ]
        );

        $dashboard = $this->dispatch_json( 'GET', '/sentient-forms/v1/lead-value/forms/contact_form_7/42/dashboard' );

        $this->assertSame( 1, $dashboard['grades']['A'] );
        $this->assertSame( 1, $dashboard['metrics']['scored_leads'] );
        $this->assertSame( $submission_uuid, $dashboard['entries'][0]['entry_id'] );
        $this->assertSame( 'A', $dashboard['entries'][0]['grade'] );
        $this->assertSame( 2, $dashboard['entries'][0]['profile_version'] );
        $this->assertSame( 'Ada Buyer', $dashboard['entries'][0]['entry_snapshot']['field_summary'][0]['value'] );
    }

    public function test_aggregate_dashboard_combines_stored_grades_replies_and_setup_forms(): void
    {
        global $wpdb;

        $results = new Sentient_Forms_Lead_Scoring_Results_Repository( $wpdb );
        $grade_id = $results->upsert_from_execution(
            [
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'form_title'           => 'Lead intake',
                'entry_id'             => '1001',
                'action_code'          => 'lead_grading_v1',
                'execution_request_id' => 'lead-grading:1001',
                'lead_profile_id'      => 44,
                'profile_version'      => 5,
                'grade'                => 'A',
                'confidence'           => 0.94,
                'priority'             => 'urgent',
                'justification'        => 'The entry matches the current setup and names a paid project.',
                'entry_snapshot'       => [
                    'field_summary' => [
                        [
                            'field_id' => '1',
                            'label'    => 'Name',
                            'value'    => 'Ada Buyer',
                        ],
                    ],
                ],
            ]
        );
        $this->assertIsInt( $grade_id );

        $reply_id = $results->upsert_from_execution(
            [
                'form_source'           => 'gravity_forms',
                'form_id'               => '7',
                'form_title'            => 'Lead intake',
                'entry_id'              => '1001',
                'action_code'           => 'suggested_reply_v1',
                'execution_request_id'  => 'suggested-reply:1001',
                'profile_version'       => 5,
                'next_best_action'      => 'Send to sales for same-day follow-up.',
                'suggested_reply_draft' => 'Thanks for reaching out. We can help with that project.',
                'reply_rationale'       => 'The lead is specific and urgent.',
            ]
        );
        $this->assertIsInt( $reply_id );

        $profiles = new Sentient_Forms_Lead_Profiles_Repository( $wpdb );
        $setup_only_profile_id = $profiles->save(
            [
                'form_source'             => 'gravity_forms',
                'form_id'                 => '99',
                'status'                  => 'draft',
                'profile_version'         => 1,
                'good_lead_criteria_json' => [ 'summary_text' => 'Good leads are relevant.' ],
                'bad_lead_criteria_json'  => [ 'summary_text' => 'Bad leads are spam.' ],
            ]
        );
        $this->assertIsInt( $setup_only_profile_id );

        $dashboard = $this->dispatch_json( 'GET', '/sentient-forms/v1/lead-value/dashboard' );

        $this->assertSame( 1, $dashboard['metrics']['scored_leads'] );
        $this->assertSame( 1, $dashboard['metrics']['priority_leads'] );
        $this->assertSame( 1, $dashboard['metrics']['reply_drafts'] );
        $this->assertSame( 1, $dashboard['entry_total'] );
        $this->assertSame( '1001', $dashboard['entries'][0]['entry_id'] );
        $this->assertSame( 'A', $dashboard['entries'][0]['grade'] );
        $this->assertSame( 'Send to sales for same-day follow-up.', $dashboard['entries'][0]['next_best_action'] );
        $form_ids = wp_list_pluck( $dashboard['forms'], 'form_id' );
        $this->assertContains( '7', $form_ids );
        $this->assertContains( '99', $form_ids );
    }

    public function test_dashboards_hydrate_missing_entry_preview_from_gravity_forms(): void
    {
        global $wpdb;

        GFAPI::$forms = [
            7 => [
                'id'     => 7,
                'title'  => 'Lead intake',
                'fields' => [
                    [
                        'id'    => 1,
                        'label' => 'Name',
                    ],
                    [
                        'id'    => 2,
                        'label' => 'Project Details',
                    ],
                ],
            ],
        ];
        GFAPI::$entries = [
            1002 => [
                'id'           => 1002,
                'form_id'      => 7,
                'date_created' => '2026-05-13 08:15:00',
                'status'       => 'active',
                '1'            => 'Grace Buyer',
                '2'            => 'We need paid implementation help with a multi-location intake workflow.',
            ],
        ];

        $results = new Sentient_Forms_Lead_Scoring_Results_Repository( $wpdb );
        $results->upsert_from_execution(
            [
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'form_title'           => 'Lead intake',
                'entry_id'             => '1002',
                'action_code'          => 'lead_grading_v1',
                'execution_request_id' => 'lead-grading:1002',
                'profile_version'      => 6,
                'grade'                => 'A',
                'priority'             => 'high',
                'justification'        => 'The entry describes a relevant paid project.',
                'entry_snapshot'       => [],
            ]
        );

        $aggregate = $this->dispatch_json( 'GET', '/sentient-forms/v1/lead-value/dashboard' );
        $form      = $this->dispatch_json( 'GET', '/sentient-forms/v1/lead-value/forms/gravity_forms/7/dashboard' );

        foreach ( [ $aggregate, $form ] as $dashboard )
        {
            $this->assertSame( '1002', $dashboard['entries'][0]['entry_id'] );
            $this->assertSame( 'active', $dashboard['entries'][0]['entry_snapshot']['status'] );
            $this->assertSame( '2026-05-13 08:15:00', $dashboard['entries'][0]['entry_snapshot']['date_created'] );
            $this->assertSame( 'Name', $dashboard['entries'][0]['entry_snapshot']['field_summary'][0]['label'] );
            $this->assertSame( 'Grace Buyer', $dashboard['entries'][0]['entry_snapshot']['field_summary'][0]['value'] );
            $this->assertSame( 'Project Details', $dashboard['entries'][0]['entry_snapshot']['field_summary'][1]['label'] );
        }
    }

    private function seed_ready_site_context(): void
    {
        update_option(
            'sentient_forms_site_context',
            [
                'summary_text' => str_repeat(
                    'This business serves commercial clients seeking website strategy, form automation, qualified project inquiries, practical support, and responsive follow up. ',
                    8
                ),
                'pii_ack'      => true,
                'auto_include' => true,
                'source'       => 'manual',
                'updated_at'   => '2026-05-10 18:00:00',
            ],
            false
        );
        update_option(
            'sentient_forms_site_context_settings',
            [
                'consent_status' => 'granted',
                'consented_at'   => '2026-05-10 18:00:00',
            ],
            false
        );
    }

    private function seed_spam_guidance(): void
    {
        $positive = [
            [ 'text' => 'I need a quote for a new service landing page.', 'rationale' => 'Real project request.' ],
            [ 'text' => 'Can your team help fix our broken contact form?', 'rationale' => 'Support or service inquiry.' ],
            [ 'text' => 'We are comparing agencies for a site rebuild.', 'rationale' => 'Buying-stage lead.' ],
        ];
        $negative = [
            [ 'text' => 'Buy cheap backlinks now.', 'rationale' => 'Spam solicitation.' ],
            [ 'text' => 'Crypto investment partnership guaranteed profit.', 'rationale' => 'Unrelated scam.' ],
            [ 'text' => 'asdf test http://spam.example', 'rationale' => 'Low-effort suspicious entry.' ],
        ];

        update_option(
            'sentient_forms_form_config_gravity_forms_7',
            [
                'spam_detection_v1' => [
                    'spam_positive_examples' => $positive,
                    'spam_negative_examples' => $negative,
                ],
            ],
            false
        );
    }

    private function dispatch_json( string $method, string $route, array $body = [], int $expected_status = 200 ): array
    {
        $request = new WP_REST_Request( $method, $route );
        if ( in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) )
        {
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        }

        if ( [] !== $body )
        {
            $request->set_body_params( $body );
        }

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( $expected_status, $response->get_status(), wp_json_encode( $response->get_data() ) );

        $data = $response->get_data();
        $this->assertIsArray( $data );

        return $data;
    }

    private function truncate_tables(): void
    {
        global $wpdb;

        foreach (
            [
                'sentient_provider_credentials',
                'sentient_action_templates',
                'sentient_custom_actions',
                'sentient_form_mappings',
                'sentient_execution_events',
                'sentient_external_service_consents',
                'sentient_lead_profiles',
                'sentient_lead_scoring_results',
                'sentient_historical_analysis_runs',
                'sentient_migration_runs',
                'sentient_model_cache',
                'sentient_submission_ledger_settings',
                'sentient_submission_ledger',
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }

    private function reset_gfapi_lookup_counters(): void
    {
        $this->assertTrue( property_exists( GFAPI::class, 'get_form_calls' ) );
        $this->assertTrue( property_exists( GFAPI::class, 'get_entry_calls' ) );

        GFAPI::$get_form_calls  = 0;
        GFAPI::$get_entry_calls = 0;
    }

    private function reset_options(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();
        delete_option( 'sentient_forms_site_context' );
        delete_option( 'sentient_forms_site_context_settings' );
        delete_option( 'sentient_forms_form_config_gravity_forms_7' );
    }

    private function record_managed_proxy_consent( string $action = 'setup_managed_proxy' ): void
    {
        global $wpdb;

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $metadata = [ 'action' => $action ];
        if ( 'setup_managed_proxy' === $action )
        {
            $metadata['managed_proxy_selected'] = true;
        }
        elseif ( 'revoke_managed_proxy' === $action )
        {
            $metadata['managed_proxy_selected'] = false;
        }

        $recorded = $consents->record(
            'sentient_managed',
            '2026-04-managed-proxy-v1',
            self::$admin_id,
            $metadata
        );

        $this->assertIsInt( $recorded );
    }
}
