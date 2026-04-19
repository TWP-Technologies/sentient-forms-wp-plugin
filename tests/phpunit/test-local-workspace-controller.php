<?php

class Tests_Local_Workspace_Controller extends WP_UnitTestCase
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
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );

        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_workspace_tables();

        add_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        $rest_api = new Sentient_Forms_REST_API();
        do_action( 'rest_api_init', rest_get_server() );
    }

    protected function tearDown(): void
    {
        remove_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        parent::tearDown();
    }

    public function controller_classes(): array
    {
        return [ Sentient_Forms_Local_Workspace_Controller::class ];
    }

    public function test_local_templates_custom_actions_mappings_and_events_round_trip(): void
    {
        $template = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/action-templates',
            [
                'source'                   => 'bundled',
                'code'                     => 'spam_triage_v1',
                'display_name'             => 'Spam Triage',
                'prompt_template'          => 'Classify {{entry}}.',
                'default_model'            => 'openrouter/auto',
                'structured_output_schema' => [ 'type' => 'object' ],
                'version'                  => '1.0.0',
                'is_active'                => true,
            ],
            201
        );

        $this->assertSame( 'spam_triage_v1', $template['code'] );
        $this->assertSame( [ 'type' => 'object' ], $template['structured_output_schema'] );

        $templates = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/action-templates' );
        $this->assertCount( 1, $templates );

        $custom_action = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/custom-actions',
            [
                'template_id'          => $template['id'],
                'code'                 => 'contact_spam_triage',
                'display_name'         => 'Contact Spam Triage',
                'definition_json'      => [
                    'prompt' => 'Classify contact form entry.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ],
            201
        );

        $this->assertSame( 'contact_spam_triage', $custom_action['code'] );
        $this->assertSame( 'openrouter', $custom_action['model_selection_json']['provider'] );

        $mapping = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/form-mappings',
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $custom_action['id'],
                'input_bindings_json' => [
                    'email' => '3',
                ],
                'effect_mapping_json' => [
                    'entry_note' => true,
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ],
            201
        );

        $this->assertSame( 'gravity_forms', $mapping['form_source'] );
        $this->assertSame( [ 'email' => '3' ], $mapping['input_bindings_json'] );

        $mappings = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/form-mappings?form_source=gravity_forms&form_id=7' );
        $this->assertCount( 1, $mappings );
        $this->assertSame( $mapping['id'], $mappings[0]['id'] );

        $event = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/execution-events',
            [
                'execution_request_id' => 'request-123',
                'mapping_id'           => $mapping['id'],
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '99',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'queued',
                'token_usage_json'     => [
                    'input'  => 10,
                    'output' => 5,
                ],
            ],
            201
        );

        $this->assertSame( 'queued', $event['status'] );

        $updated_event = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/execution-events',
            [
                'execution_request_id' => 'request-123',
                'mapping_id'           => $mapping['id'],
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
                'result_json'          => [
                    'classification' => 'ham',
                ],
            ],
            201
        );

        $this->assertSame( $event['id'], $updated_event['id'] );
        $this->assertSame( 'succeeded', $updated_event['status'] );
        $this->assertSame( 'ham', $updated_event['result_json']['classification'] );

        $events = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/execution-events?limit=10' );
        $this->assertCount( 1, $events );
        $this->assertSame( 'request-123', $events[0]['execution_request_id'] );

        $support_bundle = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/support-bundle' );
        $this->assertArrayHasKey( 'local_tables', $support_bundle );
        $this->assertGreaterThanOrEqual( 1, $support_bundle['local_tables']['sentient_execution_events'] );
        $this->assertSame( 'request-123', $support_bundle['execution_summary']['recent'][0]['execution_request_id'] );
        $this->assertArrayNotHasKey( 'result_json', $support_bundle['execution_summary']['recent'][0] );
        $this->assertTrue( $support_bundle['execution_summary']['recent'][0]['has_result'] );
    }

    public function test_mapping_list_requires_form_filter(): void
    {
        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/form-mappings' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_execute_form_mapping_delegates_to_local_execution_service(): void
    {
        $service    = new Sentient_Forms_Test_Local_Action_Execution_Service(
            [
                'execution_request_id' => 'local-request-1',
                'status'               => 'succeeded',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'result'               => [
                    'content' => 'Test run complete.',
                ],
            ]
        );
        $controller = new Sentient_Forms_Local_Workspace_Controller( null, null, null, null, $service );
        $request    = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/form-mappings/42/execute-test' );
        $request->set_url_params( [ 'id' => 42 ] );
        $request->set_body_params(
            [
                'form'    => [ 'id' => 7, 'title' => 'Contact' ],
                'entry'   => [ 'id' => 99, '1' => 'Ada' ],
                'context' => [ 'hook' => 'gform_after_submission' ],
            ]
        );

        $response = $controller->execute_form_mapping( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'local-request-1', $response->get_data()['execution_request_id'] );
        $this->assertCount( 1, $service->calls );
        $this->assertSame( 42, $service->calls[0]['mapping_id'] );
        $this->assertSame( 'Contact', $service->calls[0]['form']['title'] );
        $this->assertSame( 'Ada', $service->calls[0]['entry']['1'] );
        $this->assertSame( 'gform_after_submission', $service->calls[0]['context']['hook'] );
    }

    public function test_execute_form_mapping_requires_form_object(): void
    {
        $controller = new Sentient_Forms_Local_Workspace_Controller(
            null,
            null,
            null,
            null,
            new Sentient_Forms_Test_Local_Action_Execution_Service( [] )
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/form-mappings/42/execute-test' );
        $request->set_url_params( [ 'id' => 42 ] );
        $request->set_body_params( [ 'entry' => [] ] );

        $response = $controller->execute_form_mapping( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'sentient_forms_missing_test_form', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] );
    }

    private function dispatch_json( string $method, string $route, array $body = [], int $expected_status = 200 ): array
    {
        $query = [];
        if ( str_contains( $route, '?' ) )
        {
            $parts = wp_parse_url( $route );
            $route = $parts['path'] ?? $route;
            if ( isset( $parts['query'] ) )
            {
                parse_str( $parts['query'], $query );
            }
        }

        $request = new WP_REST_Request( $method, $route );
        if ( [] !== $query )
        {
            $request->set_query_params( $query );
        }

        if ( [] !== $body )
        {
            $request->set_body_params( $body );
        }

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( $expected_status, $response->get_status() );

        $data = $response->get_data();
        $this->assertIsArray( $data );

        return $data;
    }

    private function truncate_local_workspace_tables(): void
    {
        global $wpdb;

        foreach (
            [
                'sentient_action_templates',
                'sentient_custom_actions',
                'sentient_form_mappings',
                'sentient_execution_events',
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }
}

class Sentient_Forms_Test_Local_Action_Execution_Service extends Sentient_Forms_Local_Action_Execution_Service
{
    /** @var array<int, array{mapping_id: int, form: array<string, mixed>, entry: array<string, mixed>, context: array<string, mixed>}> */
    public array $calls = [];

    public function __construct( private array | WP_Error $response )
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

        return $this->response;
    }
}
