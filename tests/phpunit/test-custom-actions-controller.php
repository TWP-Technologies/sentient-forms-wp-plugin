<?php

class Tests_Custom_Actions_Controller extends WP_UnitTestCase
{
    private Sentient_Forms_Custom_Actions_Controller $controller;
    private Sentient_Forms_Action_Templates_Repository $templates;

    protected function setUp(): void
    {
        parent::setUp();
        Sentient_Forms_Installer::maybe_upgrade();
        global $wpdb;
        $this->templates = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $this->controller = new Sentient_Forms_Custom_Actions_Controller();
    }

    public function test_create_args_do_not_expose_base_credit_cost(): void
    {
        $args = $this->invoke_private( 'get_create_args' );

        $this->assertIsArray( $args );
        $this->assertArrayNotHasKey( 'base_credit_cost', $args );
    }

    public function test_update_args_do_not_expose_base_credit_cost(): void
    {
        $args = $this->invoke_private( 'get_update_args' );

        $this->assertIsArray( $args );
        $this->assertArrayNotHasKey( 'base_credit_cost', $args );
    }

    public function test_build_create_payload_ignores_base_credit_cost_input(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->set_param( 'template_id', wp_generate_uuid4() );
        $request->set_param( 'code', 'pricing-locked-action' );
        $request->set_param( 'display_name', 'Pricing Locked Action' );
        $request->set_param( 'description', 'Should ignore any cost override input.' );
        $request->set_param( 'prompt_overrides', [ 'tone' => 'strict' ] );
        $request->set_param( 'model_hint', 'openai/gpt-5.5' );
        $request->set_param( 'base_credit_cost', 999 );

        $payload = $this->invoke_private( 'build_create_payload', [ $request ] );

        $this->assertIsArray( $payload );
        $this->assertArrayNotHasKey( 'base_credit_cost', $payload );
        $this->assertSame( 'pricing-locked-action', $payload['code'] ?? null );
        $this->assertSame( 'Pricing Locked Action', $payload['display_name'] ?? null );
        $this->assertSame( 'template_override', $payload['action_kind'] ?? null );
        $this->assertSame( 1, $payload['definition_version'] ?? null );
        $this->assertSame( [ 'after_submission' ], $payload['supported_execution_modes'] ?? [] );
        $this->assertNull( $payload['definition'] ?? null );
        $this->assertNull( $payload['output_contract'] ?? null );
    }

    public function test_build_update_payload_ignores_base_credit_cost_input(): void
    {
        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/custom-actions/test-id' );
        $request->set_param( 'display_name', 'Renamed Action' );
        $request->set_param( 'description', 'Updated description.' );
        $request->set_param( 'prompt_overrides', [ 'strictness' => 'high' ] );
        $request->set_param( 'model_hint', 'google/gemini-3-flash-preview' );
        $request->set_param( 'base_credit_cost', 5 );

        $payload = $this->invoke_private( 'build_update_payload', [ $request ] );

        $this->assertIsArray( $payload );
        $this->assertArrayNotHasKey( 'base_credit_cost', $payload );
        $this->assertSame( 'Renamed Action', $payload['display_name'] ?? null );
        $this->assertSame( 'google/gemini-3-flash-preview', $payload['model_hint'] ?? null );
        $this->assertSame( 'template_override', $payload['action_kind'] ?? null );
        $this->assertSame( 1, $payload['definition_version'] ?? null );
        $this->assertSame( [ 'after_submission' ], $payload['supported_execution_modes'] ?? [] );
        $this->assertNull( $payload['definition'] ?? null );
        $this->assertNull( $payload['output_contract'] ?? null );
    }

    public function test_build_create_payload_requires_definition_for_custom_definition(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->set_param( 'template_id', wp_generate_uuid4() );
        $request->set_param( 'code', 'dag-missing-definition' );
        $request->set_param( 'display_name', 'Missing Definition' );
        $request->set_param( 'action_kind', 'custom_definition' );
        $request->set_param( 'definition_version', 1 );
        $request->set_param( 'supported_execution_modes', [ 'after_submission' ] );

        $payload = $this->invoke_private( 'build_create_payload', [ $request ] );

        $this->assertInstanceOf( WP_Error::class, $payload );
    }

    public function test_build_create_payload_accepts_valid_workflow_definition(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->set_param( 'template_id', wp_generate_uuid4() );
        $request->set_param( 'code', 'dag-valid-definition' );
        $request->set_param( 'display_name', 'Valid Definition' );
        $request->set_param( 'action_kind', 'custom_definition' );
        $request->set_param( 'definition_version', 2 );
        $request->set_param( 'supported_execution_modes', [ 'validation', 'after_submission' ] );
        $request->set_param(
            'definition',
            [
                'workflow' => [
                    'version' => 1,
                    'nodes' => [
                        [
                            'node_id' => 'extract',
                            'kind' => 'llm_step',
                            'prompt_template' => 'Extract entities',
                            'output_key' => 'entities',
                        ],
                        [
                            'node_id' => 'summarize',
                            'kind' => 'transform_step',
                            'output_key' => 'summary',
                        ],
                    ],
                    'edges' => [
                        [
                            'from' => 'extract',
                            'to' => 'summarize',
                        ],
                    ],
                    'max_parallelism' => 4,
                ],
            ]
        );

        $payload = $this->invoke_private( 'build_create_payload', [ $request ] );

        $this->assertIsArray( $payload );
        $this->assertSame( 'custom_definition', $payload['action_kind'] ?? null );
        $this->assertSame( 2, $payload['definition_version'] ?? null );
        $this->assertSame( [ 'validation', 'after_submission' ], $payload['supported_execution_modes'] ?? [] );
        $this->assertIsArray( $payload['definition']['workflow']['nodes'] ?? null );
    }

    public function test_build_update_payload_rejects_workflow_edge_with_unknown_node(): void
    {
        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/custom-actions/test-id' );
        $request->set_param( 'display_name', 'Invalid DAG Update' );
        $request->set_param( 'action_kind', 'custom_definition' );
        $request->set_param( 'definition_version', 1 );
        $request->set_param( 'supported_execution_modes', [ 'after_submission' ] );
        $request->set_param(
            'definition',
            [
                'workflow' => [
                    'nodes' => [
                        [
                            'node_id' => 'extract',
                            'kind' => 'llm_step',
                            'prompt_template' => 'Extract entities',
                            'output_key' => 'entities',
                        ],
                    ],
                    'edges' => [
                        [
                            'from' => 'extract',
                            'to' => 'missing',
                        ],
                    ],
                ],
            ]
        );

        $payload = $this->invoke_private( 'build_update_payload', [ $request ] );

        $this->assertInstanceOf( WP_Error::class, $payload );
    }

    public function test_create_custom_action_uses_local_repository_when_legacy_cps_disabled(): void
    {
        $template_id = $this->create_local_template();
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->set_param( 'template_id', (string) $template_id );
        $request->set_param( 'code', 'local-create-' . substr( md5( (string) wp_rand() ), 0, 8 ) );
        $request->set_param( 'display_name', 'Local Create Action' );
        $request->set_param( 'description', 'Created through the legacy custom-actions route locally.' );
        $request->set_param( 'prompt_overrides', [ 'custom_instructions' => 'Summarize and add an entry note.' ] );
        $request->set_param( 'model_hint', 'sf_quality' );
        $request->set_param(
            'model_selection',
            [
                'primary'   => 'sf_quality',
                'backup'    => 'openrouter/free',
                'is_preset' => true,
                'reasoning' => 'medium',
            ]
        );
        $request->set_param(
            'definition',
            [
                'prompt_template' => 'Summarize {{form.title}}: {{entry}}',
                'execution_defaults' => [
                    'post_execution_actions' => [
                        [
                            'type'    => 'entry_note',
                            'message' => 'Result: {{llm_output}}',
                        ],
                    ],
                ],
            ]
        );
        $request->set_param(
            'output_contract',
            [
                'schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'summary' => [
                            'type' => 'string',
                        ],
                    ],
                ],
            ]
        );

        $response = $this->controller->create_custom_action( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( (string) $template_id, $data['action']['template_id'] ?? null );
        $this->assertSame( 'local', $data['action']['definition']['provider'] ?? 'local' );
        $this->assertSame( 'Summarize {{form.title}}: {{entry}}', $data['action']['definition']['prompt_template'] ?? null );
        $this->assertSame( 'Result: {{llm_output}}', $data['action']['definition']['execution_defaults']['post_execution_actions'][0]['message'] ?? null );
        $this->assertSame( 'object', $data['action']['output_contract']['schema']['type'] ?? null );
        $this->assertSame( 'sf_quality', $data['action']['model_selection']['primary'] ?? null );
        $this->assertSame( 'openrouter/free', $data['action']['model_selection']['backup'] ?? null );
        $this->assertTrue( $data['action']['model_selection']['is_preset'] ?? false );
        $this->assertSame( 'medium', $data['action']['model_selection']['reasoning'] ?? null );
        $this->assertGreaterThan( 0, $data['quota']['quota_remaining'] ?? 0 );
    }

    public function test_update_archive_and_reactivate_local_custom_action_with_numeric_route_id(): void
    {
        $create_response = $this->create_local_custom_action_response();
        $created = $create_response->get_data();
        $action_id = (string) ( $created['action']['id'] ?? '' );
        $this->assertNotSame( '', $action_id );

        $update = new WP_REST_Request( 'PUT', '/sentient-forms/v1/custom-actions/' . $action_id );
        $update->set_param( 'id', $action_id );
        $update->set_param( 'display_name', 'Renamed Local Action' );
        $update->set_param( 'description', 'Updated local description.' );
        $update->set_param( 'model_hint', 'openrouter/auto' );
        $update->set_param(
            'definition',
            [
                'prompt_template' => 'Updated {{entry}}',
            ]
        );

        $update_response = $this->controller->update_custom_action( $update );
        $this->assertInstanceOf( WP_REST_Response::class, $update_response );
        $this->assertSame( 'Renamed Local Action', $update_response->get_data()['action']['display_name'] ?? null );
        $this->assertSame( 'Updated {{entry}}', $update_response->get_data()['action']['definition']['prompt_template'] ?? null );

        $archive = new WP_REST_Request( 'DELETE', '/sentient-forms/v1/custom-actions/' . $action_id );
        $archive->set_param( 'id', $action_id );
        $archive_response = $this->controller->archive_custom_action( $archive );
        $this->assertInstanceOf( WP_REST_Response::class, $archive_response );
        $this->assertSame( 'archived', $archive_response->get_data()['action']['status'] ?? null );

        $reactivate = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions/' . $action_id . '/reactivate' );
        $reactivate->set_param( 'id', $action_id );
        $reactivate_response = $this->controller->reactivate_custom_action( $reactivate );
        $this->assertInstanceOf( WP_REST_Response::class, $reactivate_response );
        $this->assertSame( 'active', $reactivate_response->get_data()['action']['status'] ?? null );
    }

    private function create_local_template(): int
    {
        $template_id = $this->templates->upsert_by_code(
            [
                'source'          => 'test',
                'code'            => 'custom_action_controller_template_' . substr( md5( (string) wp_rand() ), 0, 8 ),
                'display_name'    => 'Controller Template',
                'description'     => 'Controller test template.',
                'prompt_template' => 'Summarize {{entry}}',
                'default_model'   => 'openrouter/auto',
                'version'         => 'test',
                'is_active'       => true,
            ]
        );

        $this->assertIsInt( $template_id );

        return $template_id;
    }

    private function create_local_custom_action_response(): WP_REST_Response
    {
        $template_id = $this->create_local_template();
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->set_param( 'template_id', (string) $template_id );
        $request->set_param( 'code', 'local-route-' . substr( md5( (string) wp_rand() ), 0, 8 ) );
        $request->set_param( 'display_name', 'Local Route Action' );
        $request->set_param( 'definition', [ 'prompt_template' => 'Route {{entry}}' ] );

        $response = $this->controller->create_custom_action( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        return $response;
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invoke_private( string $method, array $args = [] ): mixed
    {
        $reflection = new ReflectionMethod( $this->controller, $method );
        $reflection->setAccessible( true );

        return $reflection->invokeArgs( $this->controller, $args );
    }
}
