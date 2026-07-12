<?php

if ( ! class_exists( 'GFForms' ) ) {
    class GFForms {}
}

if ( ! class_exists( 'GFAPI' ) ) {
    class GFAPI {
        /** @var array<int,array<string,mixed>> */
        public static array $forms = [];

        public static function get_form( $form_id ) {
            $form_id = (int) $form_id;
            return self::$forms[ $form_id ] ?? false;
        }

        public static function get_entry( $entry_id ) {
            return false;
        }

        public static function get_forms(): array {
            return array_values( self::$forms );
        }

        public static function update_form( $form, $form_id = null ) {
            $form_id = null === $form_id && is_array( $form ) && isset( $form['id'] )
                ? (int) $form['id']
                : (int) $form_id;

            if ( $form_id <= 0 ) {
                return new WP_Error( 'missing_form_id', 'Missing form id.' );
            }

            if ( is_array( $form ) ) {
                $form['id'] = $form_id;
            }

            self::$forms[ $form_id ] = $form;

            return true;
        }
    }
}

final class Sentient_Forms_Test_Suggest_Executor extends Sentient_Forms_Local_Action_Execution_Service {
    public array $calls = [];

    public function __construct() {}

    public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
    {
        return $this->suggest(
            (string) ( $context['central_action_id'] ?? $mapping_id ),
            $form,
            $entry,
            $context,
            is_array( $context['suggestion_context'] ?? null ) ? $context['suggestion_context'] : []
        );
    }

    public function suggest(
        string $central_action_id,
        array $form,
        array $entry,
        array $context,
        array $suggestion_context
    ) {
        $this->calls[] = [
            'central_action_id' => $central_action_id,
            'form' => $form,
            'entry' => $entry,
            'context' => $context,
            'suggestion_context' => $suggestion_context,
        ];

        return [
            'execution_request_id' => $context['execution_request_id'] ?? 'generated',
            'status'               => 'succeeded',
            'provider'             => 'openrouter',
            'model'                => 'openrouter/auto',
            'cached'               => false,
            'result'               => [
                'structured' => [
                    'suggestions' => [
                        [
                            'suggestion_id' => wp_generate_uuid4(),
                            'field_id' => '1',
                            'severity' => 'warning',
                            'message' => 'Add detail',
                            'jump_target_field_id' => '1',
                            'is_suppressed' => false,
                        ],
                    ],
                    'virtual_questions' => [
                        [
                            'question_id' => 'details-url',
                            'question' => 'What URL did this happen on?',
                            'target_field_id' => '1',
                            'required' => true,
                            'answer_type' => 'short_text',
                        ],
                    ],
                    'conditional_decisions' => [],
                ],
            ],
        ];
    }
}

final class Sentient_Forms_Test_Local_Form_Mappings_Repository extends Sentient_Forms_Form_Mappings_Repository {
    /** @var array<int,array<string,mixed>> */
    private array $rows;

    /** @param array<int,array<string,mixed>> $rows */
    public function __construct( array $rows ) {
        $this->rows = $rows;
    }

    public function get( int $id ): ?array {
        return $this->rows[ $id ] ?? null;
    }
}

final class Sentient_Forms_Test_Local_Suggest_Execution_Service extends Sentient_Forms_Local_Action_Execution_Service {
    public array $calls = [];
    public mixed $next_result = null;

    public function __construct() {}

    public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error {
        $this->calls[] = [
            'mapping_id' => $mapping_id,
            'form'       => $form,
            'entry'      => $entry,
            'context'    => $context,
        ];

        if ( null !== $this->next_result ) {
            return $this->next_result;
        }

        return [
            'execution_request_id' => $context['execution_request_id'] ?? 'rt-local-first-test',
            'status'               => 'succeeded',
            'provider'             => 'openrouter',
            'model'                => 'openrouter/auto',
            'cached'               => false,
            'result'               => [
                'structured' => [
                    'suggestions' => [
                        [
                            'suggestion_id'        => 'suggest-visible',
                            'field_id'             => '1',
                            'severity'             => 'warning',
                            'message'              => 'Add the product number and quantity.',
                            'jump_target_field_id' => '1',
                        ],
                        [
                            'suggestion_id'        => 'suggest-hidden',
                            'field_id'             => '4',
                            'severity'             => 'warning',
                            'message'              => 'Hidden field should not render.',
                            'jump_target_field_id' => '4',
                        ],
                    ],
                    'virtual_questions' => [
                        [
                            'question_id'     => 'need-date',
                            'question'        => 'When do you need this quote returned?',
                            'reason'          => 'The site owner can prioritize the request.',
                            'target_field_id' => '1',
                            'required'        => true,
                            'answer_type'     => 'short_text',
                        ],
                    ],
                    'conditional_decisions' => [
                        [
                            'decision_id'   => 'quote-request',
                            'condition_key' => 'quote_request',
                            'met'           => true,
                            'confidence'    => 0.91,
                            'reason'        => 'The visitor asked for a quote.',
                        ],
                    ],
                ],
            ],
        ];
    }
}

class Tests_Form_Suggestions_Controller extends WP_UnitTestCase {
    private Sentient_Forms_Form_Suggestions_Controller $controller;
    private Sentient_Forms_Plugin $plugin;
    private int $mapping_id;
    private string $mapping_key;

    protected function setUp(): void {
        parent::setUp();
        $this->plugin = Sentient_Forms_Plugin::instance();
        global $wpdb;
        foreach ( [ 'sentient_execution_events', 'sentient_async_requests', 'sentient_form_mappings', 'sentient_custom_actions', 'sentient_action_templates' ] as $table ) {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
        $catalog = Sentient_Forms_Bundled_Action_Templates::get( 'clarification_assistant_v1' );
        $this->assertIsArray( $catalog );
        $template_id = ( new Sentient_Forms_Action_Templates_Repository( $wpdb ) )->upsert_by_code(
            [
                'source'                   => 'bundled',
                'code'                     => 'clarification_assistant_v1',
                'display_name'             => $catalog['display_name'],
                'description'              => $catalog['description'] ?? null,
                'prompt_template'          => $catalog['prompt_template'],
                'default_model'            => $catalog['default_model'] ?? null,
                'structured_output_schema' => $catalog['structured_output_schema'] ?? null,
                'override_schema'          => $catalog['override_schema'] ?? null,
                'version'                  => $catalog['version'] ?? '1',
                'is_active'                => true,
            ]
        );
        $this->assertIsInt( $template_id );
        $actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id = $actions->create(
            [
                'code' => Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'clarification_assistant_v1' ),
                'display_name' => 'Realtime Clarification Assistant',
                'template_id' => $template_id,
                'definition_json' => Sentient_Forms_Bundled_Action_Templates::linkage_definition( 'clarification_assistant_v1' ),
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status' => 'active',
            ]
        );
        $this->assertIsInt( $action_id );
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->mapping_id = $mappings->create(
            [
                'form_source' => 'gravity_forms', 'form_id' => '42', 'hook' => 'real_time',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'real_time', 'enabled' => true,
            ]
        );
        $this->assertIsInt( $this->mapping_id );
        $this->mapping_key = 'local_first_' . $this->mapping_id;
        $this->controller = new Sentient_Forms_Form_Suggestions_Controller( $mappings, new Sentient_Forms_Test_Suggest_Executor() );

        GFAPI::$forms = [
            42 => [
                'id' => 42,
                'title' => 'Realtime Test Form',
                'fields' => [
                    (object) [
                        'id' => 1,
                        'label' => 'Name',
                        'type' => 'text',
                        'pageNumber' => 1,
                    ],
                        (object) [
                            'id' => 4,
                            'label' => 'Notes',
                            'type' => 'textarea',
                            'pageNumber' => 2,
                        ],
                        (object) [
                            'id' => 9,
                            'label' => 'Internal routing',
                            'type' => 'hidden',
                            'pageNumber' => 1,
                        ],
                        (object) [
                            'id' => 10,
                            'label' => 'Internal note',
                            'type' => 'text',
                            'visibility' => 'administrative',
                            'pageNumber' => 1,
                        ],
                        (object) [
                            'id' => 11,
                            'label' => 'Private tracking',
                            'type' => 'text',
                            'visibility' => 'hidden',
                            'pageNumber' => 1,
                        ],
                    ],
                ],
        ];

        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'actions' => [
                    [
                        'id' => $this->mapping_key,
                        'local_form_mapping_id' => $this->mapping_id,
                        'central_action_id' => 'clarification_assistant_v1',
                        'action_name_label' => 'Realtime Summary',
                        'action_type_indicator' => 'local_first',
                        'is_action_enabled_for_form' => true,
                        'settings' => [
                            'execution_mode' => 'real_time',
                            'realtime_settings' => [
                                'checkpoint_field_ids' => [ '1' ],
                                'debounce_ms' => 700,
                                'cooldown_ms' => 9000,
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    protected function tearDown(): void {
        delete_option( 'sentient_forms_actions_gravity_forms_42' );
        delete_option( 'sentient_forms_form_config_gravity_forms_42' );
        delete_transient( 'sentient_forms_rt_suggest_rl_' . md5( '42|unknown' ) );
        delete_transient( 'sentient_forms_rt_suggest_rl_' . md5( '42|203.0.113.10' ) );
        delete_transient( 'sentient_forms_rt_suggest_rl_' . md5( '999|unknown' ) );
        delete_transient( 'sentient_forms_rt_config_rl_' . md5( '42|unknown' ) );
        delete_transient( 'sentient_forms_rt_config_rl_' . md5( '42|203.0.113.10' ) );
        delete_transient( 'sentient_forms_rt_config_rl_' . md5( '999|unknown' ) );
        unset( $_SERVER['REMOTE_ADDR'] );
        GFAPI::$forms = [];
        global $wpdb;
        foreach ( [ 'sentient_execution_events', 'sentient_async_requests', 'sentient_form_mappings', 'sentient_custom_actions' ] as $table ) {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
        parent::tearDown();
    }

    private function set_controller_executor( Sentient_Forms_Local_Action_Execution_Service $executor ): void
    {
        $property = new ReflectionProperty( Sentient_Forms_Form_Suggestions_Controller::class, 'local_execution' );
        $property->setValue( $this->controller, $executor );
        $this->assertSame( $executor, $property->getValue( $this->controller ) );
    }

    private function authorize_runtime_config_request( WP_REST_Request $request, int $form_id = 42 ): void {
        $request->set_header(
            'X-Sentient-Forms-Runtime-Config-Token',
            Sentient_Forms_Gravity_Forms_Adapter::build_realtime_runtime_config_token( 'gravity_forms', $form_id )
        );
    }

    public function test_permission_callback_public_nonce_validates_form_scoped_nonce(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_id', 42 );
        $request->set_header( 'X-Sentient-Forms-Suggest-Nonce', wp_create_nonce( 'sentient_forms_realtime_suggest_42' ) );

        $this->assertTrue( $this->controller->permission_callback_public_nonce( $request ) );
    }

    public function test_permission_callback_public_nonce_rejects_missing_nonce(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_id', 42 );

        $this->assertFalse( $this->controller->permission_callback_public_nonce( $request ) );
    }

    public function test_rest_dispatch_prefers_suggest_route_over_local_mapping_item_route(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_header( 'X-Sentient-Forms-Suggest-Nonce', wp_create_nonce( 'sentient_forms_realtime_suggest_42' ) );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'execution_request_id', 'rt-route-dispatch-42' );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );
        $request->set_param( 'request_reason', 'manual_refresh' );
        $request->set_param(
            'panel_state',
            [
                'virtual_questions' => [
                    [
                        'question_id' => 'affected-url',
                        'question' => 'What page URL did this happen on?',
                        'answer' => 'https://example.test/pricing',
                        'completed' => true,
                    ],
                ],
            ]
        );
        $request->set_param(
            'future_field_manifest',
            [
                [
                    'field_id' => '4',
                    'type' => 'textarea',
                    'page_index' => 2,
                ],
            ]
        );

        $response = rest_do_request( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 502, $response->get_status() );
        $data = $response->get_data();
        $this->assertNotSame( 'rest_invalid_param', $data['code'] ?? null );
        $this->assertNotSame( 409, $response->get_status() );
    }

    public function test_runtime_config_endpoint_returns_fresh_no_store_mapping_config(): void {
        update_option(
            'sentient_forms_form_config_gravity_forms_42',
            [
                'clarification_assistant_v1' => [
                    'realtime_settings' => [
                        'initial_panel_state' => 'minimized',
                    ],
                ],
            ]
        );
        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'actions' => [
                    [
                        'id'                         => 'local_first_42',
                        'central_action_id'          => 'clarification_assistant_v1',
                        'action_name_label'          => 'Realtime Clarification Assistant',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'settings'                   => [
                            'execution_mode'      => 'real_time',
                            'realtime_settings'   => [
                                'checkpoint_field_ids' => [ '1' ],
                                'initial_panel_state'  => 'hidden_until_interaction',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $this->authorize_runtime_config_request( $request );

        $response = rest_do_request( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $response->get_headers()['Cache-Control'] ?? null
        );
        $this->assertSame( 'no-cache', $response->get_headers()['Pragma'] ?? null );

        $data = $response->get_data();
        $this->assertSame( 42, $data['form_id'] ?? null );
        $this->assertSame( 'gravity_forms', $data['source'] ?? null );
        $this->assertSame( 'hidden_until_interaction', $data['initial_panel_state'] ?? null );
        $this->assertSame( 'local_first_42', $data['mappings'][0]['mapping_id'] ?? null );
        $this->assertSame( 'hidden_until_interaction', $data['mappings'][0]['initial_panel_state'] ?? null );
        $this->assertIsString( $data['nonce'] ?? null );
        $this->assertArrayNotHasKey( 'rest_nonce', $data );
        $this->assertIsInt( $data['config_generated_at'] ?? null );
        $this->assertGreaterThan( $data['config_generated_at'], $data['config_expires_at'] ?? 0 );
        $this->assertStringContainsString(
            '/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config',
            $data['runtime_config_endpoint_url'] ?? ''
        );
        $this->assertStringContainsString(
            '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest',
            $data['suggest_endpoint_url'] ?? ''
        );
    }

    public function test_runtime_config_endpoint_rejects_unsupported_form_source(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/wpforms/forms/42/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'wpforms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_runtime_config( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_invalid_form_source', $response->get_error_code() );
        $this->assertSame( 400, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_runtime_config_route_reports_elementor_provider_native_ids_as_unsupported(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/91:formabc/actions/runtime-config' );

        $response = rest_do_request( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_invalid_form_source', $response->get_data()['code'] ?? null );
        $this->assertStringContainsString( 'Gravity Forms', $response->get_data()['message'] ?? '' );
    }

    public function test_suggest_route_reports_elementor_provider_native_ids_as_unsupported(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/elementor_pro_forms/forms/91:formabc/actions/suggest' );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ 'email' => 'lead@example.test' ] );
        $request->set_param( 'visible_field_ids', [ 'email' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 1 );

        $response = rest_do_request( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_invalid_form_source', $response->get_data()['code'] ?? null );
        $this->assertStringContainsString( 'Gravity Forms', $response->get_data()['message'] ?? '' );
    }

    public function test_runtime_config_endpoint_rejects_invalid_form_id(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/0/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 0 );

        $response = $this->controller->get_runtime_config( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_invalid_form_id', $response->get_error_code() );
        $this->assertSame( 400, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_runtime_config_endpoint_rejects_missing_bootstrap_token(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_runtime_config( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_invalid_runtime_config_token', $response->get_error_code() );
        $this->assertSame( 403, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_runtime_config_endpoint_rejects_invalid_bootstrap_token(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_header( 'X-Sentient-Forms-Runtime-Config-Token', 'invalid-token' );

        $response = $this->controller->get_runtime_config( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_invalid_runtime_config_token', $response->get_error_code() );
        $this->assertSame( 403, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_runtime_config_route_rejects_invalid_form_id_during_dispatch(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/0/actions/runtime-config' );

        $response = rest_do_request( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null );
    }

    public function test_runtime_config_endpoint_returns_unavailable_when_adapter_is_missing(): void {
        $registry = $this->plugin->get_form_adapter_registry();
        $adapters_property = new ReflectionProperty( $registry, 'adapters' );
        $adapters_property->setAccessible( true );
        $original_adapters = $adapters_property->getValue( $registry );
        $adapters_property->setValue( $registry, [] );

        try {
            $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config' );
            $request->set_param( 'form_source_slug', 'gravity_forms' );
            $request->set_param( 'form_id', 42 );
            $this->authorize_runtime_config_request( $request );

            $response = $this->controller->get_runtime_config( $request );
        } finally {
            $adapters_property->setValue( $registry, $original_adapters );
        }

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_form_source_unavailable', $response->get_error_code() );
        $this->assertSame( 503, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_runtime_config_endpoint_returns_not_found_when_form_has_no_runtime_config(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/999/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 999 );
        $this->authorize_runtime_config_request( $request, 999 );

        $response = $this->controller->get_runtime_config( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_realtime_runtime_config_not_found', $response->get_error_code() );
        $this->assertSame( 404, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_runtime_config_endpoint_enforces_rate_limit_per_form_and_ip(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        set_transient( 'sentient_forms_rt_config_rl_' . md5( '42|203.0.113.10' ), 300, MINUTE_IN_SECONDS );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $this->authorize_runtime_config_request( $request );

        $response = $this->controller->get_runtime_config( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_too_many_requests', $response->get_error_code() );
        $this->assertSame( 429, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_runtime_config_endpoint_does_not_consume_suggestion_rate_limit_bucket(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $suggest_key = 'sentient_forms_rt_suggest_rl_' . md5( '42|203.0.113.10' );
        $config_key  = 'sentient_forms_rt_config_rl_' . md5( '42|203.0.113.10' );
        set_transient( $suggest_key, 60, MINUTE_IN_SECONDS );
        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'actions' => [
                    [
                        'id'                         => 'local_first_42',
                        'central_action_id'          => 'clarification_assistant_v1',
                        'action_name_label'          => 'Realtime Clarification Assistant',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'settings'                   => [
                            'execution_mode'    => 'real_time',
                            'realtime_settings' => [
                                'checkpoint_field_ids' => [ '1' ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/runtime-config' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $this->authorize_runtime_config_request( $request );

        $response = $this->controller->get_runtime_config( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 60, get_transient( $suggest_key ) );
        $this->assertSame( 1, get_transient( $config_key ) );
    }

    public function test_runtime_config_no_store_filter_applies_to_error_responses(): void {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/999/actions/runtime-config' );
        $response = new WP_REST_Response(
            [
                'code'    => 'rest_realtime_runtime_config_not_found',
                'message' => 'Real-time runtime config was not found for this form.',
                'data'    => [ 'status' => 404 ],
            ],
            404
        );

        $filtered = $this->controller->maybe_add_runtime_config_no_store_headers( $response, rest_get_server(), $request );

        $this->assertSame(
            'no-store, no-cache, must-revalidate, max-age=0',
            $filtered->get_headers()['Cache-Control'] ?? null
        );
        $this->assertSame( 'no-cache', $filtered->get_headers()['Pragma'] ?? null );
    }

    public function test_suggest_endpoint_executes_realtime_mapping_via_action_executor(): void {
        $stub_executor = new Sentient_Forms_Test_Suggest_Executor();
        $this->set_controller_executor( $stub_executor );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'execution_request_id', 'rt-request-42' );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );
        $request->set_param( 'request_reason', 'manual_refresh' );
        $request->set_param(
            'panel_state',
            [
                'virtual_questions' => [
                    [
                        'question_id' => 'affected-url',
                        'question' => 'What page URL did this happen on?',
                        'answer' => 'https://example.test/pricing',
                        'completed' => true,
                    ],
                ],
            ]
        );
        $request->set_param(
            'future_field_manifest',
            [
                [
                    'field_id' => '4',
                    'type' => 'textarea',
                    'page_index' => 2,
                ],
            ]
        );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertCount( 1, $data['suggestions'] ?? [] );
        $this->assertSame( 'What URL did this happen on?', $data['virtual_questions'][0]['question'] ?? null );
        $this->assertCount( 1, $stub_executor->calls, (string) wp_json_encode( $data ) );
        $this->assertSame( (string) $this->mapping_id, $stub_executor->calls[0]['central_action_id'] );
        $this->assertSame( 'rt-request-42', $stub_executor->calls[0]['context']['execution_request_id'] ?? null );
        $this->assertSame( [ '1' ], $stub_executor->calls[0]['suggestion_context']['visible_field_ids'] ?? [] );
        $this->assertSame( 'manual_refresh', $stub_executor->calls[0]['suggestion_context']['request_reason'] ?? null );
        $this->assertSame(
            'https://example.test/pricing',
            $stub_executor->calls[0]['suggestion_context']['panel_state']['virtual_questions'][0]['answer'] ?? null
        );
        $this->assertTrue( $stub_executor->calls[0]['context']['suggestion_context']['panel_state']['virtual_questions'][0]['completed'] ?? false );
    }

    public function test_suggest_endpoint_strips_hidden_values_by_default_and_keeps_label_context(): void {
        $stub_executor = new Sentient_Forms_Test_Suggest_Executor();
        $this->set_controller_executor( $stub_executor );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello', '9' => 'route-secret' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );
        $request->set_param(
            'supplemental_field_context',
            [
                [
                    'field_id' => '9',
                    'label' => 'Attacker label',
                    'type' => 'hidden',
                    'page_index' => 1,
                    'hidden' => true,
                    'value' => 'route-secret',
                ],
            ]
        );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertCount( 1, $stub_executor->calls );
        $context = $stub_executor->calls[0]['suggestion_context'];
        $this->assertSame( [ '1' => 'hello' ], $context['all_known_field_values'] ?? [] );
        $this->assertSame( [ '1' => 'hello' ], $stub_executor->calls[0]['entry'] );
        $this->assertSame( 'label_hidden', $context['hidden_field_exposure_mode'] ?? null );
        $this->assertSame( '9', $context['supplemental_field_context'][0]['field_id'] ?? null );
        $this->assertSame( 'Internal routing', $context['supplemental_field_context'][0]['label'] ?? null );
        $this->assertTrue( $context['supplemental_field_context'][0]['hidden'] ?? false );
        $this->assertArrayNotHasKey( 'value', $context['supplemental_field_context'][0] ?? [] );
    }

    public function test_suggest_endpoint_does_not_trust_client_visible_field_ids_for_hidden_fields(): void {
        $stub_executor = new Sentient_Forms_Test_Suggest_Executor();
        $this->set_controller_executor( $stub_executor );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello', '9' => 'route-secret' ] );
        $request->set_param( 'visible_field_ids', [ '1', '9' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertCount( 1, $stub_executor->calls );
        $context = $stub_executor->calls[0]['suggestion_context'];
        $this->assertSame( [ '1' ], $context['visible_field_ids'] ?? [] );
        $this->assertSame( [ '1' => 'hello' ], $context['all_known_field_values'] ?? [] );
        $this->assertSame( [ '1' => 'hello' ], $stub_executor->calls[0]['entry'] );
        $this->assertSame( '9', $context['supplemental_field_context'][0]['field_id'] ?? null );
        $this->assertArrayNotHasKey( 'value', $context['supplemental_field_context'][0] ?? [] );
    }

    public function test_suggest_endpoint_keeps_prior_page_visible_values_on_later_pages(): void {
        $stub_executor = new Sentient_Forms_Test_Suggest_Executor();
        $this->set_controller_executor( $stub_executor );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param(
            'all_known_field_values',
            [
                '1'  => 'Need a quote for machined aluminum brackets',
                '4'  => 'Need 500 pieces in two weeks',
                '9'  => 'route-secret',
                '10' => 'admin-only',
            ]
        );
        $request->set_param( 'visible_field_ids', [ '4' ] );
        $request->set_param( 'current_page_index', 2 );
        $request->set_param( 'total_pages', 2 );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertCount( 1, $stub_executor->calls );
        $context = $stub_executor->calls[0]['suggestion_context'];
        $this->assertSame( [ '4' ], $context['visible_field_ids'] ?? [] );
        $this->assertSame(
            [
                '1' => 'Need a quote for machined aluminum brackets',
                '4' => 'Need 500 pieces in two weeks',
            ],
            $context['all_known_field_values'] ?? []
        );
        $this->assertSame(
            [
                '1' => 'Need a quote for machined aluminum brackets',
                '4' => 'Need 500 pieces in two weeks',
            ],
            $stub_executor->calls[0]['entry']
        );
        $this->assertContains( '9', array_column( $context['supplemental_field_context'] ?? [], 'field_id' ) );
        $this->assertContains( '10', array_column( $context['supplemental_field_context'] ?? [], 'field_id' ) );
    }

    public function test_suggest_endpoint_does_not_trust_client_visible_field_ids_for_gf_visibility_hidden_fields(): void {
        $stub_executor = new Sentient_Forms_Test_Suggest_Executor();
        $this->set_controller_executor( $stub_executor );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello', '10' => 'admin-only', '11' => 'private-tracking' ] );
        $request->set_param( 'visible_field_ids', [ '1', '10', '11' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertCount( 1, $stub_executor->calls );
        $context = $stub_executor->calls[0]['suggestion_context'];
        $this->assertSame( [ '1' ], $context['visible_field_ids'] ?? [] );
        $this->assertSame( [ '1' => 'hello' ], $context['all_known_field_values'] ?? [] );
        $this->assertSame( [ '1' => 'hello' ], $stub_executor->calls[0]['entry'] );
        $this->assertSame( [ '10', '11' ], array_column( $context['supplemental_field_context'] ?? [], 'field_id' ) );
        $this->assertArrayNotHasKey( 'value', $context['supplemental_field_context'][0] ?? [] );
        $this->assertArrayNotHasKey( 'value', $context['supplemental_field_context'][1] ?? [] );
    }

    public function test_suggest_endpoint_allows_hidden_values_when_mapping_policy_allows_them(): void {
        $settings = get_option( 'sentient_forms_actions_gravity_forms_42' );
        $settings['actions'][0]['settings']['realtime_settings']['hidden_field_exposure_mode'] = 'label_hidden_value';
        update_option( 'sentient_forms_actions_gravity_forms_42', $settings );

        $stub_executor = new Sentient_Forms_Test_Suggest_Executor();
        $this->set_controller_executor( $stub_executor );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello', '9' => 'route-secret' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );
        $request->set_param(
            'supplemental_field_context',
            [
                [
                    'field_id' => '9',
                    'value' => 'route-secret',
                ],
            ]
        );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $context = $stub_executor->calls[0]['suggestion_context'];
        $this->assertSame( [ '1' => 'hello', '9' => 'route-secret' ], $context['all_known_field_values'] ?? [] );
        $this->assertSame( [ '1' => 'hello', '9' => 'route-secret' ], $stub_executor->calls[0]['entry'] );
        $this->assertSame( 'route-secret', $context['supplemental_field_context'][0]['value'] ?? null );
    }

    public function test_suggest_endpoint_executes_local_first_realtime_mapping_without_cps_fallback(): void {
        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'actions' => [
                    [
                        'id' => 'local_first_99',
                        'central_action_id' => 'clarification_assistant_v1',
                        'action_name_label' => 'Realtime Clarification Assistant',
                        'action_type_indicator' => 'master',
                        'is_action_enabled_for_form' => true,
                        'settings' => [
                            'execution_mode' => 'real_time',
                            'realtime_settings' => [
                                'checkpoint_field_ids' => [ '1' ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $legacy_executor = new Sentient_Forms_Test_Suggest_Executor();

        $local_repository = new Sentient_Forms_Test_Local_Form_Mappings_Repository(
            [
                99 => [
                    'id' => 99,
                    'form_source' => 'gravity_forms',
                    'form_id' => '42',
                    'hook' => 'real_time',
                    'execution_mode' => 'sync',
                    'enabled' => 1,
                ],
            ]
        );
        $local_execution = new Sentient_Forms_Test_Local_Suggest_Execution_Service();
        $controller = new Sentient_Forms_Form_Suggestions_Controller( $local_repository, $local_execution );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', 'local_first_99' );
        $request->set_param( 'execution_request_id', 'rt-local-first-42' );
        $request->set_param( 'all_known_field_values', [ '1' => 'Quote product 183671 at 500pcs', '9' => 'route-secret' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );
        $request->set_param(
            'future_field_manifest',
            [
                [
                    'field_id' => '4',
                    'type' => 'textarea',
                    'page_index' => 2,
                ],
            ]
        );

        $response = $controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertCount( 1, $local_execution->calls );
        $this->assertSame( 99, $local_execution->calls[0]['mapping_id'] );
        $this->assertSame( 'real_time', $local_execution->calls[0]['context']['hook'] ?? null );
        $this->assertSame( [ '1' => 'Quote product 183671 at 500pcs' ], $local_execution->calls[0]['entry'] );
        $this->assertSame( [], $legacy_executor->calls );
        $this->assertSame( 'Add the product number and quantity.', $data['suggestions'][0]['message'] ?? null );
        $this->assertCount( 1, $data['suggestions'] ?? [] );
        $this->assertSame( 'When do you need this quote returned?', $data['virtual_questions'][0]['question'] ?? null );
        $this->assertSame( 'quote_request', $data['conditional_decisions'][0]['condition_key'] ?? null );
        $this->assertSame( 'rt-local-first-42', $data['meta']['execution_request_id'] ?? null );
    }

    public function test_suggest_endpoint_maps_local_schema_errors_to_unprocessable_json_error(): void {
        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'actions' => [
                    [
                        'id' => 'local_first_99',
                        'central_action_id' => 'clarification_assistant_v1',
                        'action_name_label' => 'Realtime Clarification Assistant',
                        'action_type_indicator' => 'master',
                        'is_action_enabled_for_form' => true,
                        'settings' => [
                            'execution_mode' => 'real_time',
                            'realtime_settings' => [
                                'checkpoint_field_ids' => [ '1' ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $legacy_executor = new Sentient_Forms_Test_Suggest_Executor();

        $local_repository = new Sentient_Forms_Test_Local_Form_Mappings_Repository(
            [
                99 => [
                    'id' => 99,
                    'form_source' => 'gravity_forms',
                    'form_id' => '42',
                    'hook' => 'real_time',
                    'execution_mode' => 'real_time',
                    'enabled' => 1,
                ],
            ]
        );
        $local_execution = new Sentient_Forms_Test_Local_Suggest_Execution_Service();
        $local_execution->next_result = new WP_Error(
            'sentient_forms_structured_output_validation_failed',
            'The provider response did not match the local action schema: virtual_questions is a required property of structured_output.',
            [ 'schema_source' => 'template' ]
        );
        $controller = new Sentient_Forms_Form_Suggestions_Controller( $local_repository, $local_execution );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', 'local_first_99' );
        $request->set_param( 'all_known_field_values', [ '1' => 'Quote product 183671 at 500pcs' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );

        $response = $controller->suggest( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'sentient_forms_structured_output_validation_failed', $response->get_error_code() );
        $this->assertSame( 'Suggestions are temporarily unavailable. Try again shortly.', $response->get_error_message() );
        $this->assertStringNotContainsString( 'virtual_questions', $response->get_error_message() );
        $this->assertStringNotContainsString( 'structured_output', $response->get_error_message() );
        $this->assertSame( 422, (int) ( $response->get_error_data()['status'] ?? 0 ) );
        $this->assertSame( 'template', $response->get_error_data()['schema_source'] ?? null );
        $this->assertCount( 1, $local_execution->calls );
        $this->assertSame( [], $legacy_executor->calls );
    }

    public function test_suggest_endpoint_preserves_local_execution_error_status(): void {
        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'actions' => [
                    [
                        'id' => 'local_first_99',
                        'central_action_id' => 'clarification_assistant_v1',
                        'action_name_label' => 'Realtime Clarification Assistant',
                        'action_type_indicator' => 'master',
                        'is_action_enabled_for_form' => true,
                        'settings' => [
                            'execution_mode' => 'real_time',
                            'realtime_settings' => [
                                'checkpoint_field_ids' => [ '1' ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $local_repository = new Sentient_Forms_Test_Local_Form_Mappings_Repository(
            [
                99 => [
                    'id' => 99,
                    'form_source' => 'gravity_forms',
                    'form_id' => '42',
                    'hook' => 'real_time',
                    'execution_mode' => 'real_time',
                    'enabled' => 1,
                ],
            ]
        );
        $local_execution = new Sentient_Forms_Test_Local_Suggest_Execution_Service();
        $local_execution->next_result = new WP_Error(
            'sentient_forms_provider_rate_limited',
            'Provider rate limit exceeded.',
            [ 'status' => 429 ]
        );
        $controller = new Sentient_Forms_Form_Suggestions_Controller( $local_repository, $local_execution );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', 'local_first_99' );
        $request->set_param( 'all_known_field_values', [ '1' => 'Quote product 183671 at 500pcs' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );

        $response = $controller->suggest( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'sentient_forms_provider_rate_limited', $response->get_error_code() );
        $this->assertSame( 429, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_suggest_endpoint_falls_back_to_known_values_for_visible_fields_and_builds_future_manifest(): void {
        $stub_executor = new Sentient_Forms_Test_Suggest_Executor();
        $this->set_controller_executor( $stub_executor );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello', '2' => 'world' ] );
        $request->set_param( 'visible_field_ids', [] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 2 );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertCount( 1, $stub_executor->calls );
        $context = $stub_executor->calls[0]['suggestion_context'];
        $this->assertSame( [ '1', '2' ], $context['visible_field_ids'] ?? [] );
        $this->assertNotEmpty( $context['future_field_manifest'] ?? [] );
        $this->assertSame( '4', $context['future_field_manifest'][0]['field_id'] ?? null );
        $this->assertSame( 2, $context['future_field_manifest'][0]['page_index'] ?? null );
    }

    public function test_suggest_endpoint_returns_not_found_for_non_realtime_mapping(): void {
        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'actions' => [
                    [
                        'id' => $this->mapping_key,
                        'local_form_mapping_id' => $this->mapping_id,
                        'central_action_id' => 'clarification_assistant_v1',
                        'is_action_enabled_for_form' => true,
                        'settings' => [
                            'execution_mode' => 'after_submission',
                        ],
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 1 );

        $controller = new Sentient_Forms_Form_Suggestions_Controller(
            new Sentient_Forms_Test_Local_Form_Mappings_Repository(
                [
                    $this->mapping_id => [
                        'id' => $this->mapping_id,
                        'form_source' => 'gravity_forms',
                        'form_id' => '42',
                        'hook' => 'after_submission',
                        'execution_mode' => 'async',
                        'enabled' => 1,
                    ],
                ]
            ),
            new Sentient_Forms_Test_Suggest_Executor()
        );
        $response = $controller->suggest( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'sentient_forms_local_mapping_mismatch', $response->get_error_code() );
        $this->assertSame( 404, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }

    public function test_suggest_endpoint_enforces_rate_limit_per_form_and_ip(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        set_transient( 'sentient_forms_rt_suggest_rl_' . md5( '42|203.0.113.10' ), 120, MINUTE_IN_SECONDS );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );
        $request->set_param( 'mapping_id', $this->mapping_key );
        $request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
        $request->set_param( 'visible_field_ids', [ '1' ] );
        $request->set_param( 'current_page_index', 1 );
        $request->set_param( 'total_pages', 1 );

        $response = $this->controller->suggest( $request );

        $this->assertInstanceOf( WP_Error::class, $response );
        $this->assertSame( 'rest_too_many_requests', $response->get_error_code() );
        $this->assertSame( 429, (int) ( $response->get_error_data()['status'] ?? 0 ) );
    }
}
