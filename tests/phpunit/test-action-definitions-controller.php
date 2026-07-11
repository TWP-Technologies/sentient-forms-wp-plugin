<?php

class ActionDefinitionsControllerTest extends WP_UnitTestCase
{
    protected static $admin_id;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        remove_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        Sentient_Forms_Plugin::instance();
        Sentient_Forms_Installer::maybe_upgrade();

        if ( ! class_exists( 'Sentient_Forms_REST_API' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/class-rest-api.php';
        }
        if ( ! trait_exists( 'Sentient_Forms_Permission_Utils_Trait' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/permissions/trait-permission-utils.php';
        }
        if ( ! class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/permissions/class-admin-permission.php';
        }
        if ( ! trait_exists( 'Sentient_Forms_Validation_Utils_Trait' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/validators/trait-validation-utils.php';
        }
        if ( ! class_exists( 'Sentient_Forms_Settings_Validator' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/validators/class-settings-validator.php';
        }

        $rest_api = new Sentient_Forms_REST_API();
        $server   = rest_get_server();
        do_action( 'rest_api_init', $server );
    }

    protected function tearDown(): void
    {
        add_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        parent::tearDown();
    }

    public function test_legacy_cps_template_flag_cannot_override_local_definitions(): void
    {
        $enable_cps_templates = static fn() => true;
        add_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-789',
            ]
        );

        $this->mock_http_response(
            '/actions/templates',
            [
                'success' => true,
                'data'    => [
                    'templates' => [
                        [
                            'id'               => '0a3c9d89-14d3-4e7a-8e1b-c99877e4fb0f',
                            'code'             => 'spam_detection_v1',
                            'display_name'     => 'Spam Analysis',
                            'description'      => 'Detect spam entries using LLMs',
                            'model_hint'       => 'models/gemini-1.5-flash',
                            'base_credit_cost' => 5,
                        ],
                    ],
                ],
            ],
            function ( $args ) {
                $this->assertArrayHasKey( 'Authorization', $args['headers'] );
                $this->assertSame( 'Bearer proxy-key-789', $args['headers']['Authorization'] );
            }
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/actions/definitions', $routes, 'Action definitions route should be registered' );
        $response = rest_get_server()->dispatch( $request );

        remove_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertNotEmpty( $data );
        $definitions = array_values(
            array_filter(
                $data,
                static fn( array $definition ): bool => 'spam_detection_v1' === ( $definition['id'] ?? null )
            )
        );
        $this->assertCount( 1, $definitions );
        $definition = $definitions[0];

        $this->assertSame( 'spam_detection_v1', $definition['id'] );
        $this->assertSame( 'bundled', $definition['source'] );
        $this->assertSame( 'openrouter/auto', $definition['modelHint'] );
        $this->assertNull( $definition['baseCreditCost'] );
        $this->assertArrayHasKey( 'hooks', $definition );
        $this->assertIsArray( $definition['hooks'] );
        $this->assertContains( 'gform_validation', $definition['hooks'] );
        $this->assertContains( 'gform_after_submission', $definition['hooks'] );
        $this->assertArrayHasKey( 'settingsFields', $definition );
    }

    public function test_definitions_do_not_fetch_cps_by_default_even_with_proxy_key(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-789',
            ]
        );

        $http_called = false;
        $callback    = static function () use ( &$http_called ) {
            $http_called = true;

            return new WP_Error( 'unexpected_cps_call', 'Action definitions should not fetch CPS by default.' );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/actions/definitions', $routes, 'Action definitions route should be registered' );
        $response = rest_get_server()->dispatch( $request );

        remove_filter( 'pre_http_request', $callback, 10 );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNotEmpty( $data );
        $this->assertSame( 'bundled', $data[0]['source'] );
        $this->assertFalse( $http_called, 'Proxy keys must not trigger CPS action definition fetches unless legacy CPS templates are explicitly enabled.' );
    }

    public function test_bundled_action_templates_expose_template_ids_and_prompts(): void
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $definition = $this->find_definition_by_id( $response->get_data(), 'spam_detection_v1' );

        $this->assertIsArray( $definition );
        $this->assertNotSame( '', (string) ( $definition['templateId'] ?? '' ) );
        $this->assertSame( 'bundled', $definition['source'] ?? null );
        $this->assertStringContainsString( 'spam classification system', (string) ( $definition['promptTemplate'] ?? '' ) );
        $this->assertSame( 'openrouter/auto', $definition['modelHint'] ?? null );
        $this->assertSame( 'object', $definition['structuredOutputSchema']['type'] ?? null );
        $this->assertArrayHasKey( 'strictness', $definition['overrideSchema'] ?? [] );
        $this->assertContains( 'gform_validation', $definition['hooks'] ?? [] );
        $this->assertContains( 'gform_after_submission', $definition['hooks'] ?? [] );
    }

    public function test_bundled_action_definitions_hide_legacy_registry_entries_when_seeded_templates_exist(): void
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $ids = array_column( $response->get_data(), 'id' );

        $this->assertContains( 'spam_detection_v1', $ids );
        $this->assertContains( 'content_validation_v1', $ids );
        $this->assertContains( 'entry_summary_v1', $ids );
        $this->assertNotContains( 'spam_analysis', $ids );
        $this->assertNotContains( 'entry_evaluation', $ids );
    }

    public function test_all_bundled_action_templates_are_available_through_rest_definitions(): void
    {
        $expected_codes = [
            'spam_detection_v1',
            'content_validation_v1',
            'entry_summary_v1',
            'sentiment_urgency_v1',
            'missing_information_v1',
            'pain_point_intent_v1',
            'routing_recommendation_v1',
            'toxicity_moderation_v1',
            'lead_grading_v1',
            'suggested_reply_v1',
            'clarification_assistant_v1',
        ];

        $this->assertSame( $expected_codes, Sentient_Forms_Bundled_Action_Templates::codes() );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $definitions = $response->get_data();
        $ids         = array_column( $definitions, 'id' );

        $this->assertSame( $expected_codes, $ids );

        foreach ( $expected_codes as $code )
        {
            $this->assertContains( $code, $ids, "Missing bundled REST definition for {$code}." );
            $definition = $this->find_definition_by_id( $definitions, $code );
            $this->assertIsArray( $definition );
            $this->assertSame( 'bundled', $definition['source'] ?? null );
            $this->assertNotSame( '', (string) ( $definition['label'] ?? '' ) );
            $this->assertNotSame( '', (string) ( $definition['promptTemplate'] ?? '' ) );
            $this->assertArrayHasKey( 'structuredOutputSchema', $definition );
            $template = Sentient_Forms_Bundled_Action_Templates::get( $code );
            $this->assertIsArray( $template );
            if ( is_array( $template['structured_output_schema'] ?? null ) )
            {
                $this->assertIsArray( $definition['structuredOutputSchema'] );
            }
            else
            {
                $this->assertNull( $definition['structuredOutputSchema'] );
            }
            $this->assertIsArray( $definition['hooks'] ?? null );
        }
    }

    public function test_bundled_definition_executable_fields_come_from_code_not_tampered_database_values(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sentient_action_templates';
        $wpdb->update(
            $table,
            [
                'display_name'             => 'Tampered label',
                'description'              => 'Tampered description',
                'prompt_template'          => 'Tampered prompt',
                'default_model'            => 'tampered/model',
                'structured_output_schema' => wp_json_encode( [ 'type' => 'string' ] ),
                'override_schema'          => wp_json_encode( [ 'tampered' => true ] ),
            ],
            [ 'code' => 'spam_detection_v1' ]
        );

        $response   = $this->dispatch_definitions_request();
        $definition = $this->find_definition_by_id( $response->get_data(), 'spam_detection_v1' );
        $canonical  = Sentient_Forms_Bundled_Action_Templates::get( 'spam_detection_v1' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertIsArray( $definition );
        $this->assertSame( $canonical['display_name'], $definition['label'] ?? null );
        $this->assertSame( $canonical['description'], $definition['description'] ?? null );
        $this->assertSame( $canonical['prompt_template'], $definition['promptTemplate'] ?? null );
        $this->assertSame( $canonical['default_model'], $definition['modelHint'] ?? null );
        $this->assertSame( $canonical['structured_output_schema'], $definition['structuredOutputSchema'] ?? null );
        $this->assertSame( $canonical['override_schema'], $definition['overrideSchema'] ?? null );
    }

    public function test_extra_bundled_database_definition_fails_closed(): void
    {
        global $wpdb;
        $repository = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $extra_id   = $repository->upsert_by_code(
            [
                'source'          => 'bundled',
                'code'            => 'unexpected_bundled_action_v1',
                'display_name'    => 'Unexpected bundled Action',
                'prompt_template' => 'This row must not extend the code-owned catalog.',
                'is_active'       => true,
            ]
        );
        $this->assertIsInt( $extra_id );

        $response = $this->dispatch_definitions_request();

        $this->assertSame( 503, $response->get_status() );
        $this->assertSame( 'sentient_forms_bundled_action_catalog_unavailable', $response->get_data()['code'] ?? null );
    }

    public function test_partial_bundled_database_identity_fails_closed(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sentient_action_templates';
        $wpdb->update( $table, [ 'code' => '' ], [ 'code' => 'spam_detection_v1' ] );

        $response = $this->dispatch_definitions_request();

        $this->assertSame( 503, $response->get_status() );
        $this->assertSame( 'sentient_forms_bundled_action_catalog_unavailable', $response->get_data()['code'] ?? null );
    }

    public function test_missing_bundled_catalog_fails_closed_instead_of_substituting_imported_templates(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sentient_action_templates';
        $wpdb->update( $table, [ 'is_active' => 0 ], [ 'source' => 'bundled' ], [ '%d' ], [ '%s' ] );

        $repository = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $imported_id = $repository->upsert_by_code(
            [
                'source'          => 'imported',
                'code'            => 'imported_must_not_replace_catalog',
                'display_name'    => 'Imported replacement',
                'prompt_template' => 'Do not expose as the bundled catalog.',
                'is_active'       => true,
            ]
        );
        $this->assertIsInt( $imported_id );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 503, $response->get_status() );
        $this->assertSame( 'sentient_forms_bundled_action_catalog_unavailable', $response->get_data()['code'] ?? null );
        $this->assertStringNotContainsString( 'imported_must_not_replace_catalog', wp_json_encode( $response->get_data() ) );
    }

    public function test_definitions_fall_back_to_local_registry_when_cps_unavailable(): void
    {
        $enable_cps_templates = static fn() => true;
        add_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $callback = function () {
            return new WP_Error( 'cps_unreachable', 'CPS unreachable' );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/actions/definitions', $routes, 'Action definitions route should be registered' );
        $response = rest_get_server()->dispatch( $request );

        remove_filter( 'pre_http_request', $callback, 10 );
        remove_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNotEmpty( $data );
        $this->assertSame( 'bundled', $data[0]['source'] );
    }

    private function mock_http_response( string $path_suffix, array $body, ?callable $assertion = null ): void
    {
        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) use ( $path_suffix, $body, $assertion ) {
                if ( str_ends_with( $url, $path_suffix ) )
                {
                    if ( is_callable( $assertion ) )
                    {
                        $assertion( $args, $url );
                    }

                    return [
                        'headers'  => [],
                        'body'     => wp_json_encode( $body ),
                        'response' => [
                            'code'    => 200,
                            'message' => 'OK',
                        ],
                    ];
                }

                return $preempt;
            },
            10,
            3
        );
    }

    private function dispatch_definitions_request(): WP_REST_Response
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        return rest_get_server()->dispatch( $request );
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     */
    private function find_definition_by_id( array $definitions, string $id ): ?array
    {
        foreach ( $definitions as $definition )
        {
            if ( $id === (string) ( $definition['id'] ?? '' ) )
            {
                return $definition;
            }
        }

        return null;
    }
}
