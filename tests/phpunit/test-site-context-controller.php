<?php
/**
 * Site Context Controller Tests
 *
 * @package SentientForms\Tests
 */

class SiteContextControllerTest extends WP_UnitTestCase
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
        $this->truncate_local_provider_tables();
        add_filter( 'sentient_forms_site_context_generation_action_scheduler_enabled', '__return_false' );
        add_filter( 'sentient_forms_site_context_generation_http_dispatch_enabled', '__return_false' );
        $rest_api = new Sentient_Forms_REST_API();
        $server   = rest_get_server();
        do_action( 'rest_api_init', $server );
    }

    protected function tearDown(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();
        delete_option( 'sentient_forms_site_context' );
        delete_option( 'sentient_forms_site_context_settings' );
        delete_option( 'sentient_forms_site_context_generation_job' );
        delete_option( 'sentient_forms_plugin_settings' );
        wp_clear_scheduled_hook( 'sentient_forms_site_context_refresh' );
        wp_clear_scheduled_hook( 'sentient_forms_site_context_first_generation' );
        wp_clear_scheduled_hook( 'sentient_forms_site_context_manual_generation' );
        $this->truncate_local_provider_tables();
        add_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        remove_all_filters( 'pre_http_request' );
        remove_all_filters( 'sentient_forms_site_context_generation_action_scheduler_enabled' );
        remove_all_filters( 'sentient_forms_site_context_generation_http_dispatch_enabled' );
        parent::tearDown();
    }

    public function test_site_context_route_is_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/site-context', $routes );
    }

    public function test_get_context_returns_empty_context_envelope_without_proxy_key(): void
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $data = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' )->get_data();
        $this->assertNull( $data['context'] ?? null );
        $this->assertSame( 'empty', $data['status'] ?? null );
        $this->assertSame( 'unset', $data['settings']['consent_status'] ?? null );
        $this->assertSame( 'openrouter', $data['settings']['generation_model_selection']['provider'] ?? null );
        $this->assertFalse( $data['generation_access']['can_generate'] ?? true );
        $this->assertSame( 'site_context_generation_consent_required', $data['generation_access']['reason_code'] ?? null );
    }

    public function test_create_context_stores_local_context_without_http_request(): void
    {
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;

                return new WP_Error( 'unexpected_http_call', 'Site context must not call a remote service.' );
            },
            10,
            3
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'pii_ack', true );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'local-site-context', $data['context']['id'] ?? null );
        $this->assertSame( 'local_starter', $data['context']['source'] ?? null );
        $this->assertTrue( $data['context']['pii_ack'] ?? false );
        $this->assertSame( 'granted', $data['settings']['consent_status'] ?? null );
        $this->assertStringContainsString( 'WordPress site', $data['context']['summary_text'] ?? '' );
        $this->assertFalse( $http_called );
    }

    public function test_create_context_cancels_active_generation_job_before_storing_local_context(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context',
            [
                'pii_ack' => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( get_option( 'sentient_forms_site_context_generation_job' ) );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertCount( 0, $calls );
        $this->assertSame( 'local_starter', $data['context']['source'] ?? null );
        $this->assertNull( $data['generation_job'] ?? null );
    }

    public function test_update_context_persists_manual_local_context(): void
    {
        update_option(
            'sentient_forms_site_context',
            [
                'id'                     => 'local-site-context',
                'license_id'             => 'local',
                'summary_text'           => 'Initial context',
                'source'                 => 'local_starter',
                'auto_include'           => true,
                'pii_ack'                => true,
                'free_refresh_available' => true,
                'next_free_refresh_at'   => null,
                'created_at'             => '2026-04-21 00:00:00',
                'updated_at'             => '2026-04-21 00:00:00',
            ],
            false
        );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'summary_text', 'Manual business context' );
        $request->set_param( 'auto_include', false );
        $request->set_param( 'pii_ack', true );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'Manual business context', $data['context']['summary_text'] ?? null );
        $this->assertSame( 'manual', $data['context']['source'] ?? null );
        $this->assertFalse( $data['context']['auto_include'] ?? true );
    }

    public function test_delete_context_withdraws_consent_and_clears_context(): void
    {
        update_option(
            'sentient_forms_site_context',
            [
                'id'                     => 'local-site-context',
                'license_id'             => 'local',
                'summary_text'           => 'Initial context',
                'source'                 => 'manual',
                'auto_include'           => true,
                'pii_ack'                => true,
                'free_refresh_available' => true,
                'next_free_refresh_at'   => null,
                'created_at'             => '2026-04-21 00:00:00',
                'updated_at'             => '2026-04-21 00:00:00',
            ],
            false
        );

        $request = new WP_REST_Request( 'DELETE', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNull( $data['context'] ?? null );
        $this->assertSame( 'declined', $data['settings']['consent_status'] ?? null );
        $this->assertSame( 'declined', $data['status'] ?? null );
    }

    public function test_auto_refresh_schedules_when_consent_is_granted(): void
    {
        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'summary_text', 'Manual business context' );
        $request->set_param( 'consent_status', 'granted' );
        $request->set_param( 'auto_refresh_enabled', true );
        $request->set_param( 'auto_refresh_days', 14 );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['settings']['auto_refresh_enabled'] ?? false );
        $this->assertSame( 14, $data['settings']['auto_refresh_days'] ?? null );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_site_context_refresh' ) );
    }

    public function test_generation_access_requires_paid_route_after_consent(): void
    {
        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text'     => '',
                'consent_status'   => 'granted',
                'auto_include'     => true,
                'pii_ack'          => true,
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertFalse( $data['generation_access']['can_generate'] ?? true );
        $this->assertSame( 'site_context_generation_openrouter_setup_required', $data['generation_access']['reason_code'] ?? null );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );
        $this->assertSame( 1, $this->count_scheduled_hook( 'sentient_forms_site_context_first_generation' ) );

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text'   => '',
                'consent_status' => 'granted',
                'auto_include'   => true,
                'pii_ack'        => true,
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $this->count_scheduled_hook( 'sentient_forms_site_context_first_generation' ) );
    }

    public function test_generate_requires_existing_consent(): void
    {
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;
                return new WP_Error( 'unexpected_http_call', 'Generation should be blocked before HTTP.' );
            },
            10,
            3
        );

        $response = $this->dispatch_site_context_request( 'POST', '/sentient-forms/v1/site-context/generate' );
        $data     = $response->get_data();

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'site_context_generation_consent_required', $data['code'] ?? null );
        $this->assertFalse( $http_called );
    }

    public function test_generate_blocks_free_and_auto_openrouter_models(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $blocked_models = [
            [ 'primary' => 'sf_free', 'provider' => 'openrouter', 'credential_id' => $credential_id ],
            [ 'primary' => 'openrouter/free', 'provider' => 'openrouter', 'credential_id' => $credential_id, 'is_preset' => false ],
            [ 'primary' => 'nvidia/nemotron-3-super-120b-a12b:free', 'provider' => 'openrouter', 'credential_id' => $credential_id, 'is_preset' => false ],
            [ 'primary' => 'openrouter/auto', 'provider' => 'openrouter', 'credential_id' => $credential_id, 'is_preset' => false ],
        ];

        foreach ( $blocked_models as $selection )
        {
            delete_option( 'sentient_forms_site_context_settings' );
            $response = $this->dispatch_site_context_request(
                'POST',
                '/sentient-forms/v1/site-context/generate',
                [
                    'consent_status'             => 'granted',
                    'generation_model_selection' => $selection,
                ]
            );
            $data = $response->get_data();

            $this->assertSame( 400, $response->get_status() );
            $this->assertSame( 'site_context_generation_paid_model_required', $data['code'] ?? null );
        }
    }

    public function test_generation_access_blocks_metadata_known_free_and_non_web_models(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            'example/free-route',
            [
                'id'                   => 'example/free-route',
                'pricing'              => [
                    'prompt'     => '0',
                    'completion' => '0',
                    'web_search' => '0',
                ],
                'supported_parameters' => [ 'web_search_options' ],
                'free'                 => true,
            ]
        );
        $this->cache_openrouter_model(
            'example/text-only-paid',
            [
                'id'                   => 'example/text-only-paid',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                ],
                'supported_parameters' => [ 'tools' ],
                'free'                 => false,
            ]
        );

        $free_response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => '',
                'pii_ack'      => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/free-route',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );
        $free_data = $free_response->get_data();

        $this->assertSame( 200, $free_response->get_status() );
        $this->assertFalse( $free_data['generation_access']['can_generate'] ?? true );
        $this->assertSame( 'site_context_generation_paid_model_required', $free_data['generation_access']['reason_code'] ?? null );

        delete_option( 'sentient_forms_site_context_settings' );

        $text_only_response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => '',
                'pii_ack'      => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/text-only-paid',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );
        $text_only_data = $text_only_response->get_data();

        $this->assertSame( 200, $text_only_response->get_status() );
        $this->assertFalse( $text_only_data['generation_access']['can_generate'] ?? true );
        $this->assertSame( 'site_context_generation_web_capable_model_required', $text_only_data['generation_access']['reason_code'] ?? null );

        $this->cache_openrouter_model(
            'example/web-without-structured-output',
            [
                'id'                   => 'example/web-without-structured-output',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'tools', 'web_search_options' ],
                'free'                 => false,
            ]
        );
        delete_option( 'sentient_forms_site_context_settings' );

        $unstructured_response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => '',
                'pii_ack'      => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/web-without-structured-output',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );
        $unstructured_data = $unstructured_response->get_data();

        $this->assertSame( 200, $unstructured_response->get_status() );
        $this->assertFalse( $unstructured_data['generation_access']['can_generate'] ?? true );
        $this->assertSame( 'site_context_generation_structured_output_model_required', $unstructured_data['generation_access']['reason_code'] ?? null );

        $this->cache_openrouter_model(
            'example/web-json-object-only',
            [
                'id'                   => 'example/web-json-object-only',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'tools', 'web_search_options', 'response_format', 'max_tokens' ],
                'free'                 => false,
            ]
        );
        delete_option( 'sentient_forms_site_context_settings' );

        $json_object_only_response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => '',
                'pii_ack'      => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/web-json-object-only',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );
        $json_object_only_data = $json_object_only_response->get_data();

        $this->assertSame( 200, $json_object_only_response->get_status() );
        $this->assertFalse( $json_object_only_data['generation_access']['can_generate'] ?? true );
        $this->assertSame( 'site_context_generation_structured_output_model_required', $json_object_only_data['generation_access']['reason_code'] ?? null );

        $this->cache_openrouter_model(
            'example/web-capable-after-long-list',
            [
                'id'                   => 'example/web-capable-after-long-list',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                ],
                'supported_parameters' => [
                    'tools',
                    'response_format',
                    'structured_outputs',
                    'reasoning',
                    'include_reasoning',
                    'max_tokens',
                    'temperature',
                    'top_p',
                    'seed',
                    'stop',
                    'logit_bias',
                    'presence_penalty',
                    'web_search_options',
                ],
                'free'                 => false,
            ]
        );
        delete_option( 'sentient_forms_site_context_settings' );

        $long_supported_response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => '',
                'pii_ack'      => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/web-capable-after-long-list',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );
        $long_supported_data = $long_supported_response->get_data();

        $this->assertSame( 200, $long_supported_response->get_status() );
        $this->assertTrue( $long_supported_data['generation_access']['can_generate'] ?? false );
        $this->assertSame( 'ready', $long_supported_data['generation_access']['reason_code'] ?? null );
    }

    public function test_generation_access_allows_ready_openrouter_paid_model(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => '',
                'pii_ack'      => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'reasoning'     => 'high',
                    'tools'         => [
                        'tool_choice' => 'required',
                        'web_search'  => [
                            'mode'        => 'auto',
                            'max_results' => 7,
                        ],
                        'web_fetch'   => [
                            'mode' => 'required',
                        ],
                        'datetime'    => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['generation_access']['can_generate'] ?? false );
        $this->assertSame( 'ready', $data['generation_access']['reason_code'] ?? null );
        $this->assertSame( $credential_id, $data['generation_access']['credential_id'] ?? null );
    }

    public function test_update_context_persists_openrouter_reasoning_and_tool_settings(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $selection     = [
            'primary'       => 'openai/gpt-5.5',
            'provider'      => 'openrouter',
            'credential_id' => $credential_id,
            'is_preset'     => false,
            'reasoning'     => [
                'effort'  => 'high',
                'exclude' => true,
            ],
            'tools'         => [
                'tool_choice' => 'required',
                'web_search'  => [
                    'mode'        => 'auto',
                    'max_results' => 5,
                ],
                'web_fetch'   => [
                    'mode' => 'required',
                ],
                'datetime'    => [
                    'mode' => 'required',
                ],
            ],
        ];

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text'               => 'Manual business context',
                'pii_ack'                    => true,
                'consent_status'             => 'granted',
                'generation_model_selection' => $selection,
            ]
        );

        $this->assertSame( 200, $response->get_status() );

        $get_response = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' );
        $this->assertSame( 200, $get_response->get_status() );
        $data = $get_response->get_data();
        $saved_selection = $data['settings']['generation_model_selection'] ?? [];

        $this->assertSame( 'openai/gpt-5.5', $saved_selection['primary'] ?? null );
        $this->assertSame( 'openrouter', $saved_selection['provider'] ?? null );
        $this->assertSame( $credential_id, $saved_selection['credential_id'] ?? null );
        $this->assertFalse( $saved_selection['is_preset'] ?? true );
        $this->assertSame(
            [
                'effort'  => 'high',
                'exclude' => true,
            ],
            $saved_selection['reasoning'] ?? null
        );
        $this->assertSame( 'required', $saved_selection['tools']['tool_choice'] ?? null );
        $this->assertSame( 'auto', $saved_selection['tools']['web_search']['mode'] ?? null );
        $this->assertSame( 5, $saved_selection['tools']['web_search']['max_results'] ?? null );
        $this->assertSame( 'required', $saved_selection['tools']['web_fetch']['mode'] ?? null );
        $this->assertSame( 'required', $saved_selection['tools']['datetime']['mode'] ?? null );
    }

    public function test_update_context_defaults_empty_server_tools_to_off(): void
    {
        $credential_id = $this->create_openrouter_credential();

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text'               => 'Manual business context',
                'pii_ack'                    => true,
                'consent_status'             => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'web_fetch' => [],
                        'datetime' => [],
                    ],
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );

        $get_response = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' );
        $this->assertSame( 200, $get_response->get_status() );
        $data = $get_response->get_data();
        $saved_selection = $data['settings']['generation_model_selection'] ?? [];

        $this->assertSame( 'off', $saved_selection['tools']['web_fetch']['mode'] ?? null );
        $this->assertSame( 'off', $saved_selection['tools']['datetime']['mode'] ?? null );
    }

    public function test_update_context_does_not_enable_web_fetch_by_default(): void
    {
        $credential_id = $this->create_openrouter_credential();

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text'               => 'Manual business context',
                'pii_ack'                    => true,
                'consent_status'             => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );

        $get_response = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' );
        $this->assertSame( 200, $get_response->get_status() );
        $data = $get_response->get_data();
        $saved_selection = $data['settings']['generation_model_selection'] ?? [];

        $this->assertSame( 'auto', $saved_selection['tools']['tool_choice'] ?? null );
        $this->assertSame( 'required', $saved_selection['tools']['web_search']['mode'] ?? null );
        $this->assertArrayNotHasKey( 'web_fetch', $saved_selection['tools'] ?? [] );
        $this->assertArrayNotHasKey( 'datetime', $saved_selection['tools'] ?? [] );
    }

    public function test_update_context_coerces_unsupported_gpt_latest_server_tools_to_off(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            '~openai/gpt-latest',
            [
                'id'                   => '~openai/gpt-latest',
                'pricing'              => [
                    'prompt'     => '0.000005',
                    'completion' => '0.00003',
                    'web_search' => '0.01',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'tools', 'tool_choice' ],
                'free'                 => false,
            ]
        );

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text'               => 'Manual business context',
                'pii_ack'                    => true,
                'consent_status'             => 'granted',
                'generation_model_selection' => [
                    'primary'       => '~openai/gpt-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                    ],
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $saved_selection = $data['settings']['generation_model_selection'] ?? [];

        $this->assertTrue( $data['generation_access']['can_generate'] ?? false );
        $this->assertSame( 'ready', $data['generation_access']['reason_code'] ?? null );
        $this->assertSame( 'off', $saved_selection['tools']['web_search']['mode'] ?? null );
        $this->assertArrayNotHasKey( 'web_fetch', $saved_selection['tools'] ?? [] );
        $this->assertArrayNotHasKey( 'datetime', $saved_selection['tools'] ?? [] );
    }

    public function test_update_context_drops_reasoning_without_effort_or_token_budget(): void
    {
        $credential_id = $this->create_openrouter_credential();

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text'               => 'Manual business context',
                'pii_ack'                    => true,
                'consent_status'             => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'reasoning'     => [
                        'effort'  => 'superbad',
                        'exclude' => true,
                    ],
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );

        $get_response = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' );
        $this->assertSame( 200, $get_response->get_status() );
        $data = $get_response->get_data();
        $saved_selection = $data['settings']['generation_model_selection'] ?? [];

        $this->assertArrayNotHasKey( 'reasoning', $saved_selection );
    }

    public function test_generation_access_blocks_free_tier_openrouter_credential(): void
    {
        $credential_id = $this->create_openrouter_credential(
            'valid',
            [
                'is_free_tier'    => true,
                'limit_remaining' => 100,
            ]
        );
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;
                return new WP_Error( 'unexpected_http_call', 'Free-tier OpenRouter credential must block before HTTP.' );
            },
            10,
            3
        );

        $params = [
            'summary_text' => '',
            'pii_ack'      => true,
            'consent_status' => 'granted',
            'generation_model_selection' => [
                'primary'       => 'openai/gpt-5.5',
                'provider'      => 'openrouter',
                'credential_id' => $credential_id,
                'is_preset'     => false,
            ],
        ];

        $response = $this->dispatch_site_context_request( 'PUT', '/sentient-forms/v1/site-context', $params );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $data['generation_access']['can_generate'] ?? true );
        $this->assertSame( 'site_context_generation_openrouter_paid_key_required', $data['generation_access']['reason_code'] ?? null );

        $generate_response = $this->dispatch_site_context_request( 'POST', '/sentient-forms/v1/site-context/generate', $params );
        $generate_data     = $generate_response->get_data();

        $this->assertSame( 400, $generate_response->get_status() );
        $this->assertSame( 'site_context_generation_openrouter_paid_key_required', $generate_data['code'] ?? null );
        $this->assertFalse( $http_called );
    }

    public function test_generation_access_allows_ready_managed_service(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-site-context-test',
                'site_id'        => 'site-context-site-id',
            ]
        );
        $this->create_managed_credential();

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => '',
                'pii_ack'      => true,
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'   => 'sf_research',
                    'provider'  => 'sentient_managed',
                    'is_preset' => true,
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['generation_access']['can_generate'] ?? false );
        $this->assertSame( 'ready', $data['generation_access']['reason_code'] ?? null );
    }

    public function test_managed_generation_requires_zdr_privacy_route_when_selection_requires_zdr(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-site-context-test',
                'site_id'        => 'site-context-site-id',
            ]
        );
        $this->create_managed_credential();
        $calls = [];
        $this->mock_managed_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'     => 'sf_research',
                    'provider'    => 'sentient_managed',
                    'is_preset'   => true,
                    'require_zdr' => true,
                    'tools'       => [
                        'tool_choice' => 'auto',
                        'web_search'  => [ 'mode' => 'off' ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'ai_generated', $data['context']['source'] ?? null );
        $this->assertCount( 1, $calls );

        $payload = json_decode( (string) ( $calls[0]['args']['body'] ?? '' ), true );
        $this->assertIsArray( $payload );
        $this->assertSame(
            [
                'schema'          => 'sentient_forms_privacy_route_policy.v1',
                'require_zdr'     => true,
                'data_collection' => 'deny',
            ],
            $payload['privacy_route_policy'] ?? null
        );

        $this->assertSame(
            [
                'schema'              => 'sentient_forms_privacy_route_assertion.v1',
                'zdr_enforced'        => true,
                'data_collection'     => 'deny',
                'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
            ],
            $data['context']['metadata']['privacy_route_assertion'] ?? null
        );
    }

    public function test_global_managed_zdr_setting_requires_site_context_privacy_route(): void
    {
        update_option(
            'sentient_forms_plugin_settings',
            [
                'managed_zdr_required' => true,
            ]
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-site-context-test',
                'site_id'        => 'site-context-site-id',
            ]
        );
        $this->create_managed_credential();
        $calls = [];
        $this->mock_managed_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'   => 'sf_research',
                    'provider'  => 'sentient_managed',
                    'is_preset' => true,
                    'tools'     => [
                        'tool_choice' => 'auto',
                        'web_search'  => [ 'mode' => 'off' ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'ai_generated', $data['context']['source'] ?? null );
        $this->assertCount( 1, $calls );

        $payload = json_decode( (string) ( $calls[0]['args']['body'] ?? '' ), true );
        $this->assertIsArray( $payload );
        $this->assertSame(
            [
                'schema'          => 'sentient_forms_privacy_route_policy.v1',
                'require_zdr'     => true,
                'data_collection' => 'deny',
            ],
            $payload['privacy_route_policy'] ?? null
        );
    }

    public function test_string_false_global_managed_zdr_setting_does_not_require_site_context_privacy_route(): void
    {
        update_option(
            'sentient_forms_plugin_settings',
            [
                'managed_zdr_required' => 'false',
            ]
        );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-site-context-test',
                'site_id'        => 'site-context-site-id',
            ]
        );
        $this->create_managed_credential();
        $calls = [];
        $this->mock_managed_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'   => 'sf_research',
                    'provider'  => 'sentient_managed',
                    'is_preset' => true,
                    'tools'     => [
                        'tool_choice' => 'auto',
                        'web_search'  => [ 'mode' => 'off' ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'ai_generated', $data['context']['source'] ?? null );
        $this->assertCount( 1, $calls );

        $payload = json_decode( (string) ( $calls[0]['args']['body'] ?? '' ), true );
        $this->assertIsArray( $payload );
        $this->assertArrayNotHasKey( 'privacy_route_policy', $payload );
    }

    public function test_managed_generation_normalizes_optional_privacy_route_assertion(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-site-context-test',
                'site_id'        => 'site-context-site-id',
            ]
        );
        $this->create_managed_credential();
        $calls = [];
        $this->mock_managed_site_context_generation(
            $calls,
            [
                'privacy_route_assertion' => [
                    'schema'              => '<b>sentient_forms_privacy_route_assertion.v1</b>',
                    'zdr_enforced'        => true,
                    'data_collection'     => 'deny',
                    'route_policy_schema' => "sentient_forms_privacy_route_policy.v1\n",
                    'untrusted_extra'     => '<script>alert(1)</script>',
                ],
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'   => 'sf_research',
                    'provider'  => 'sentient_managed',
                    'is_preset' => true,
                    'tools'     => [
                        'tool_choice' => 'auto',
                        'web_search'  => [ 'mode' => 'off' ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'ai_generated', $data['context']['source'] ?? null );
        $this->assertSame(
            [
                'schema'              => 'sentient_forms_privacy_route_assertion.v1',
                'zdr_enforced'        => true,
                'data_collection'     => 'deny',
                'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
            ],
            $data['context']['metadata']['privacy_route_assertion'] ?? null
        );
    }

    public function test_managed_generation_preserves_optional_privacy_route_fallback_metadata(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-site-context-test',
                'site_id'        => 'site-context-site-id',
            ]
        );
        $this->create_managed_credential();
        $calls = [];
        $this->mock_managed_site_context_generation(
            $calls,
            [
                'privacy_route_fallback' => [
                    'schema'               => '<b>sentient_forms_privacy_route_fallback.v1</b>',
                    'policy_version'       => "2026-06-managed-zdr-fallback-v1\n",
                    'reason_code'          => 'managed_zdr_primary_route_unavailable',
                    'original_model'       => "openai/gpt-5.5\n",
                    'fallback_model'       => 'google/gemini-3-flash-preview',
                    'executed_model'       => 'google/gemini-3-flash-preview',
                    'attempts'             => 1,
                    'execution_request_id' => 'raw-cps-id-should-not-survive',
                ],
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'   => 'sf_research',
                    'provider'  => 'sentient_managed',
                    'is_preset' => true,
                    'tools'     => [
                        'tool_choice' => 'auto',
                        'web_search'  => [ 'mode' => 'off' ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'ai_generated', $data['context']['source'] ?? null );
        $this->assertSame( 'google/gemini-3-flash-preview', $data['context']['metadata']['model'] ?? null );
        $this->assertSame(
            [
                'schema'         => 'sentient_forms_privacy_route_fallback.v1',
                'policy_version' => '2026-06-managed-zdr-fallback-v1',
                'reason_code'    => 'managed_zdr_primary_route_unavailable',
                'original_model' => 'openai/gpt-5.5',
                'fallback_model' => 'google/gemini-3-flash-preview',
                'executed_model' => 'google/gemini-3-flash-preview',
                'attempts'       => 1,
            ],
            $data['context']['metadata']['privacy_route_fallback'] ?? null
        );
        $this->assertStringNotContainsString( 'raw-cps-id-should-not-survive', wp_json_encode( $data['context']['metadata'] ?? [] ) );
    }

    public function test_managed_generation_fails_when_required_zdr_route_is_not_asserted(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-site-context-test',
                'site_id'        => 'site-context-site-id',
            ]
        );
        $this->create_managed_credential();
        $calls = [];
        $this->mock_managed_site_context_generation(
            $calls,
            [
                'privacy_route_assertion' => [
                    'schema'              => 'sentient_forms_privacy_route_assertion.v1',
                    'zdr_enforced'        => false,
                    'data_collection'     => 'allow',
                    'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
                ],
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'     => 'sf_research',
                    'provider'    => 'sentient_managed',
                    'is_preset'   => true,
                    'require_zdr' => true,
                    'tools'       => [
                        'tool_choice' => 'auto',
                        'web_search'  => [ 'mode' => 'off' ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertCount( 1, $calls );
        $this->assertNull( $data['context'] ?? null );
        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'site_context_generation_managed_privacy_route_not_asserted', $data['generation_job']['code'] ?? null );
    }

    public function test_generate_context_uses_ready_openrouter_paid_model(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'reasoning'     => 'high',
                    'tools'         => [
                        'tool_choice' => 'required',
                        'web_search'  => [
                            'mode'        => 'auto',
                            'max_results' => 7,
                        ],
                        'web_fetch'   => [
                            'mode' => 'required',
                        ],
                        'datetime'    => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );

        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'ai_generated', $data['context']['source'] ?? null );
        $this->assertStringContainsString( 'Acme Plumbing', $data['context']['summary_text'] ?? '' );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );
        $this->assertSame( 'openai/gpt-5.5', $payload['model'] ?? null );
        $this->assertSame( 'json_schema', $payload['response_format']['type'] ?? null );
        $this->assertSame( 'sentient_forms_site_context_generation_v1', $payload['response_format']['json_schema']['name'] ?? null );
        $this->assertTrue( $payload['response_format']['json_schema']['strict'] ?? false );
        $this->assertContains( 'summary_text', $payload['response_format']['json_schema']['schema']['required'] ?? [] );
        $this->assertTrue( $payload['provider']['require_parameters'] ?? false );
        $this->assertGreaterThanOrEqual( 1800, $payload['max_tokens'] ?? 0 );
        $this->assertArrayNotHasKey( 'temperature', $payload );
        $this->assertSame( [ 'effort' => 'high', 'exclude' => true ], $payload['reasoning'] ?? null );
        $this->assertSame( 'required', $payload['tool_choice'] ?? null );
        $this->assertContains( 'openrouter:web_search', array_column( $payload['tools'] ?? [], 'type' ) );
        $this->assertContains( 'openrouter:web_fetch', array_column( $payload['tools'] ?? [], 'type' ) );
        $this->assertContains( 'openrouter:datetime', array_column( $payload['tools'] ?? [], 'type' ) );
        $this->assertSame( 5, $payload['tools'][0]['parameters']['max_results'] ?? null );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );
    }

    public function test_running_manual_generation_job_is_not_claimed_again(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $job = get_option( 'sentient_forms_site_context_generation_job' );
        $this->assertIsArray( $job );
        $job['status']     = 'running';
        $job['started_at'] = current_time( 'mysql' );
        update_option( 'sentient_forms_site_context_generation_job', $job, false );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'running', $data['generation_job']['status'] ?? null );
        $this->assertCount( 0, $calls );
        $this->assertNull( $data['context'] ?? null );
    }

    public function test_manual_generation_does_not_commit_after_worker_ownership_changes(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [],
            static function (): void {
                $job = get_option( 'sentient_forms_site_context_generation_job' );
                $job = is_array( $job ) ? $job : [];
                $job['status']    = 'running';
                $job['worker_id'] = 'different-worker-owns-this-job';
                update_option( 'sentient_forms_site_context_generation_job', $job, false );
            }
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertCount( 1, $calls );
        $this->assertNull( $data['context'] ?? null );
        $this->assertSame( 'running', $data['generation_job']['status'] ?? null );
        $this->assertFalse( get_option( 'sentient_forms_site_context' ) );
    }

    public function test_invalid_manual_save_does_not_cancel_active_generation_job(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $response = $this->dispatch_site_context_request(
            'PUT',
            '/sentient-forms/v1/site-context',
            [
                'summary_text' => str_repeat( 'x', 5001 ),
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertSame( 400, $response->get_status() );
        $job = get_option( 'sentient_forms_site_context_generation_job' );
        $this->assertIsArray( $job );
        $this->assertSame( $job_id, $job['id'] ?? null );
        $this->assertSame( 'queued', $job['status'] ?? null );
    }

    public function test_manual_generation_does_not_commit_after_consent_withdrawal(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [],
            static function (): void {
                $settings = get_option( 'sentient_forms_site_context_settings' );
                $settings = is_array( $settings ) ? $settings : [];
                $settings['consent_status'] = 'declined';
                $settings['declined_at']    = current_time( 'mysql' );
                update_option( 'sentient_forms_site_context_settings', $settings, false );
                delete_option( 'sentient_forms_site_context_generation_job' );
            }
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );
        $settings = get_option( 'sentient_forms_site_context_settings' );

        $this->assertCount( 1, $calls );
        $this->assertNull( $data['context'] ?? null );
        $this->assertNull( $data['generation_job'] ?? null );
        $this->assertSame( 'declined', $settings['consent_status'] ?? null );
        $this->assertFalse( get_option( 'sentient_forms_site_context' ) );
    }

    public function test_manual_generation_does_not_commit_after_manual_context_save(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [],
            function (): void {
                $response = $this->dispatch_site_context_request(
                    'PUT',
                    '/sentient-forms/v1/site-context',
                    [
                        'consent_status' => 'granted',
                        'summary_text'   => 'Manual context saved while generation was running.',
                        'auto_include'   => true,
                        'pii_ack'        => true,
                    ]
                );

                $this->assertSame( 200, $response->get_status() );
            }
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );
        $settings = get_option( 'sentient_forms_site_context_settings' );

        $this->assertCount( 1, $calls );
        $this->assertSame( 'Manual context saved while generation was running.', $data['context']['summary_text'] ?? null );
        $this->assertSame( 'manual', $data['context']['source'] ?? null );
        $this->assertNull( $data['generation_job'] ?? null );
        $this->assertSame( 'granted', $settings['consent_status'] ?? null );
        $this->assertNull( $settings['last_generated_at'] ?? null );
    }

    public function test_generate_context_caps_site_context_web_search_depth(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode'        => 'required',
                            'max_results' => 10,
                        ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );
        $this->assertSame( [ 'openrouter:web_search' ], array_column( $payload['tools'] ?? [], 'type' ) );
        $this->assertSame( 5, $payload['tools'][0]['parameters']['max_results'] ?? null );
        $this->assertSame( 10, $payload['tools'][0]['parameters']['max_total_results'] ?? null );
    }

    public function test_generate_context_queues_background_job_without_calling_openrouter_inline(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        remove_filter( 'sentient_forms_site_context_generation_http_dispatch_enabled', '__return_false' );
        $dispatch_calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, $parsed_args, $url ) use ( &$dispatch_calls ) {
                if ( str_contains( (string) $url, 'admin-ajax.php' ) )
                {
                    $dispatch_calls[] = [
                        'url'  => $url,
                        'args' => $parsed_args,
                    ];
                    return [
                        'headers'  => [],
                        'body'     => '',
                        'response' => [
                            'code'    => 204,
                            'message' => 'No Content',
                        ],
                        'cookies'  => [],
                        'filename' => null,
                    ];
                }

                return new WP_Error( 'unexpected_http_call', 'Manual generation must not call OpenRouter inline.' );
            },
            10,
            3
        );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode'        => 'required',
                            'max_results' => 5,
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNull( $data['context'] ?? null );
        $this->assertSame( 'queued', $data['generation_job']['status'] ?? null );
        $this->assertNotEmpty( $data['generation_job']['id'] ?? null );
        $this->assertSame( 'openrouter', $data['generation_job']['provider'] ?? null );
        $this->assertSame( 'openai/gpt-5.5', $data['generation_job']['model'] ?? null );
        $this->assertContains( 'web_search', $data['generation_job']['tools'] ?? [] );
        $this->assertCount( 1, $dispatch_calls );
        $dispatch_call = $dispatch_calls[0];
        $this->assertStringContainsString( 'admin-ajax.php', (string) $dispatch_call['url'] );
        $this->assertFalse( $dispatch_call['args']['blocking'] ?? true );
        $this->assertLessThanOrEqual( 1, (float) ( $dispatch_call['args']['timeout'] ?? 10 ) );
        $this->assertSame( 'sentient_forms_site_context_manual_generation', $dispatch_call['args']['body']['action'] ?? null );
        $this->assertSame( $data['generation_job']['id'], $dispatch_call['args']['body']['job_id'] ?? null );
        $this->assertNotEmpty( $dispatch_call['args']['body']['token'] ?? null );
        $this->assertSame( 1, $this->count_scheduled_hook( 'sentient_forms_site_context_manual_generation' ) );
    }

    public function test_generate_context_does_not_save_new_settings_while_job_is_active(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( $job_id, $data['generation_job']['id'] ?? null );
        $this->assertSame( 'openai/gpt-5.5', $data['settings']['generation_model_selection']['primary'] ?? null );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertSame( 'openai/gpt-5.5', $settings['generation_model_selection']['primary'] ?? null );
    }

    public function test_generate_context_clears_first_generation_schedule_when_manual_job_is_queued(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        update_option(
            'sentient_forms_site_context_settings',
            [
                'consent_status' => 'granted',
                'consented_at'   => '2026-05-28 00:00:00',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
                'first_generation_started_at'      => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
                'first_generation_attempt_count'   => 0,
                'first_generation_next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ),
            ],
            false
        );
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'sentient_forms_site_context_first_generation' );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'queued', $data['generation_job']['status'] ?? null );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertEmpty( $settings['first_generation_next_attempt_at'] ?? null );
    }

    public function test_generate_context_clears_auto_refresh_schedule_when_manual_job_is_queued(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        update_option(
            'sentient_forms_site_context_settings',
            [
                'consent_status'       => 'granted',
                'consented_at'         => '2026-05-28 00:00:00',
                'auto_refresh_enabled' => true,
                'auto_refresh_days'    => 14,
                'next_refresh_at'      => gmdate( 'Y-m-d H:i:s', time() + MINUTE_IN_SECONDS ),
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ],
            false
        );
        wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'sentient_forms_site_context_refresh' );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            [
                'consent_status'       => 'granted',
                'auto_refresh_enabled' => true,
                'auto_refresh_days'    => 14,
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'queued', $data['generation_job']['status'] ?? null );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_refresh' ) );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertEmpty( $settings['next_refresh_at'] ?? null );
    }

    public function test_scheduled_refresh_skips_while_manual_generation_job_is_active(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status'       => 'granted',
                'auto_refresh_enabled' => true,
                'auto_refresh_days'    => 14,
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        Sentient_Forms_Site_Context_Controller::run_scheduled_refresh();

        $this->assertCount( 0, $calls );
        $this->assertFalse( get_option( 'sentient_forms_site_context' ) );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_site_context_refresh' ) );

        $job = get_option( 'sentient_forms_site_context_generation_job' );
        $this->assertIsArray( $job );
        $this->assertSame( $job_id, $job['id'] ?? null );
        $this->assertSame( 'queued', $job['status'] ?? null );
    }

    public function test_dispatched_generation_validates_token_and_runs_job(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        remove_filter( 'sentient_forms_site_context_generation_http_dispatch_enabled', '__return_false' );
        $dispatch_token = null;
        add_filter(
            'pre_http_request',
            static function ( $preempt, $parsed_args, $url ) use ( &$dispatch_token ) {
                if ( str_contains( (string) $url, 'admin-ajax.php' ) )
                {
                    $dispatch_token = (string) ( $parsed_args['body']['token'] ?? '' );
                    return [
                        'headers'  => [],
                        'body'     => '',
                        'response' => [
                            'code'    => 204,
                            'message' => 'No Content',
                        ],
                        'cookies'  => [],
                        'filename' => null,
                    ];
                }

                return false;
            },
            10,
            3
        );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [
                'model' => 'openai/gpt-5.5',
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertNotSame( '', $dispatch_token );
        $bad_result = Sentient_Forms_Site_Context_Controller::run_dispatched_manual_generation( $job_id, 'wrong-token' );
        $this->assertWPError( $bad_result );
        $this->assertSame( 'site_context_generation_dispatch_forbidden', $bad_result->get_error_code() );

        $good_result = Sentient_Forms_Site_Context_Controller::run_dispatched_manual_generation( $job_id, (string) $dispatch_token );
        $this->assertTrue( $good_result );

        $data = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' )->get_data();
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'openai/gpt-5.5', $data['context']['metadata']['model'] ?? null );
    }

    public function test_stale_queued_generation_job_fails_with_safe_diagnostic(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $job = get_option( 'sentient_forms_site_context_generation_job' );
        $this->assertIsArray( $job );
        $job['requested_at'] = '2000-01-01 00:00:00';
        update_option( 'sentient_forms_site_context_generation_job', $job, false );

        $data = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' )->get_data();

        $this->assertSame( $job_id, $data['generation_job']['id'] ?? null );
        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'site_context_generation_worker_not_started', $data['generation_job']['code'] ?? null );
        $this->assertSame( 'Site Context generation could not start in the background.', $data['generation_job']['error'] ?? null );
        $this->assertSame( 'queued', $data['generation_job']['diagnostics']['previous_status'] ?? null );
        $this->assertArrayNotHasKey( 'settings', $data['generation_job'] ?? [] );
    }

    public function test_fresh_queued_generation_job_is_not_marked_stale_in_site_timezone(): void
    {
        update_option( 'timezone_string', 'America/New_York' );
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $data = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' )->get_data();

        $this->assertSame( $job_id, $data['generation_job']['id'] ?? null );
        $this->assertSame( 'queued', $data['generation_job']['status'] ?? null );
        $this->assertNull( $data['generation_job']['error'] ?? null );
        $this->assertNull( $data['generation_job']['code'] ?? null );
    }

    public function test_stale_generation_job_failure_does_not_overwrite_completed_job(): void
    {
        $stale_job = [
            'id'           => 'stale-job-id',
            'status'       => 'queued',
            'requested_at' => '2000-01-01 00:00:00',
            'started_at'   => null,
            'finished_at'  => null,
            'error'        => null,
            'code'         => null,
            'status_code'  => null,
            'diagnostics'  => [],
        ];
        $completed_job = [
            'id'           => 'stale-job-id',
            'status'       => 'succeeded',
            'requested_at' => '2000-01-01 00:00:00',
            'started_at'   => '2000-01-01 00:00:01',
            'finished_at'  => current_time( 'mysql' ),
            'error'        => null,
            'code'         => null,
            'status_code'  => null,
            'diagnostics'  => [],
        ];
        update_option( 'sentient_forms_site_context_generation_job', $completed_job, false );

        $reads = 0;
        $filter = static function ( $pre ) use ( &$reads, $stale_job ) {
            $reads++;

            return 1 === $reads ? $stale_job : $pre;
        };
        add_filter(
            'pre_option_sentient_forms_site_context_generation_job',
            $filter
        );

        $data = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' )->get_data();
        remove_filter( 'pre_option_sentient_forms_site_context_generation_job', $filter );

        $this->assertSame( 'stale-job-id', $data['generation_job']['id'] ?? null );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertNull( $data['generation_job']['error'] ?? null );
        $this->assertSame( 'succeeded', get_option( 'sentient_forms_site_context_generation_job' )['status'] ?? null );
    }

    public function test_generate_context_omits_unsupported_optional_openrouter_parameters(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            'example/schema-no-optionals',
            [
                'id'                   => 'example/schema-no-optionals',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'web_search_options' ],
                'free'                 => false,
            ]
        );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [
                'model' => 'example/schema-no-optionals',
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/schema-no-optionals',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'reasoning'     => 'high',
                    'tools'         => [
                        'tool_choice' => 'off',
                        'web_search'  => [
                            'mode' => 'off',
                        ],
                        'web_fetch'   => [
                            'mode' => 'off',
                        ],
                        'datetime'    => [
                            'mode' => 'off',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );

        $this->assertSame( 'example/schema-no-optionals', $payload['model'] ?? null );
        $this->assertSame( 'json_schema', $payload['response_format']['type'] ?? null );
        $this->assertTrue( $payload['provider']['require_parameters'] ?? false );
        $this->assertArrayNotHasKey( 'temperature', $payload );
        $this->assertArrayNotHasKey( 'reasoning', $payload );
        $this->assertArrayNotHasKey( 'tools', $payload );
        $this->assertArrayNotHasKey( 'tool_choice', $payload );
        $this->assertArrayNotHasKey( 'web_search_options', $payload );
    }

    public function test_generate_context_omits_default_tools_when_openrouter_model_lacks_tools_parameter_support(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            'example/web-search-options-only',
            [
                'id'                   => 'example/web-search-options-only',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'web_search_options' ],
                'free'                 => false,
            ]
        );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [
                'model' => 'example/web-search-options-only',
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/web-search-options-only',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );

        $this->assertSame( 'example/web-search-options-only', $payload['model'] ?? null );
        $this->assertTrue( $payload['provider']['require_parameters'] ?? false );
        $this->assertArrayNotHasKey( 'tools', $payload );
        $this->assertArrayNotHasKey( 'tool_choice', $payload );
        $this->assertSame( 'medium', $payload['web_search_options']['search_context_size'] ?? null );
    }

    public function test_generate_context_does_not_enable_server_fetch_or_datetime_by_default(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'openai/gpt-5.5' );
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );

        $this->assertSame( 'openai/gpt-5.5', $payload['model'] ?? null );
        $this->assertSame( [ 'openrouter:web_search' ], array_column( $payload['tools'] ?? [], 'type' ) );
        $this->assertArrayNotHasKey( 'tool_choice', $payload );
    }

    public function test_generate_context_omits_tool_choice_when_openrouter_model_lacks_parameter_support(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            'example/tools-without-tool-choice',
            [
                'id'                   => 'example/tools-without-tool-choice',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'tools', 'web_search_options' ],
                'free'                 => false,
            ]
        );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [
                'model' => 'example/tools-without-tool-choice',
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/tools-without-tool-choice',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'required',
                        'web_search'  => [
                            'mode'        => 'required',
                            'max_results' => 4,
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );

        $this->assertSame( 'example/tools-without-tool-choice', $payload['model'] ?? null );
        $this->assertTrue( $payload['provider']['require_parameters'] ?? false );
        $this->assertContains( 'openrouter:web_search', array_column( $payload['tools'] ?? [], 'type' ) );
        $this->assertArrayNotHasKey( 'tool_choice', $payload );
    }

    public function test_generate_context_rejects_required_unsupported_openrouter_server_tools_before_http(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            'example/web-search-server-tool-only',
            [
                'id'                   => 'example/web-search-server-tool-only',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'tools', 'tool_choice' ],
                'openrouter_server_tools' => [
                    'web_search' => true,
                    'web_fetch'  => false,
                    'datetime'   => false,
                ],
                'free'                 => false,
            ]
        );
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;
                return new WP_Error( 'unexpected_http_call', 'Unsupported required tools must block before OpenRouter.' );
            },
            10,
            3
        );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/web-search-server-tool-only',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode'        => 'required',
                            'max_results' => 5,
                        ],
                        'web_fetch'   => [
                            'mode' => 'required',
                        ],
                        'datetime'    => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );
        $data = $response->get_data();

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'site_context_generation_openrouter_tool_unsupported', $data['code'] ?? null );
        $this->assertSame(
            [ 'openrouter:web_fetch', 'openrouter:datetime' ],
            $data['data']['diagnostics']['unsupported_required_tools'] ?? null
        );
        $this->assertFalse( $http_called );
    }

    public function test_generate_context_rejects_required_gpt_latest_web_search_before_http(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            '~openai/gpt-latest',
            [
                'id'                   => '~openai/gpt-latest',
                'pricing'              => [
                    'prompt'     => '0.000005',
                    'completion' => '0.00003',
                    'web_search' => '0.01',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'tools', 'tool_choice' ],
                'free'                 => false,
            ]
        );
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;
                return new WP_Error( 'unexpected_http_call', 'Known-incompatible web search must block before OpenRouter.' );
            },
            10,
            3
        );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => '~openai/gpt-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode'        => 'required',
                            'max_results' => 5,
                        ],
                    ],
                ],
            ]
        );
        $data = $response->get_data();

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'site_context_generation_openrouter_tool_unsupported', $data['code'] ?? null );
        $this->assertSame(
            [ 'openrouter:web_search' ],
            $data['data']['diagnostics']['unsupported_required_tools'] ?? null
        );
        $this->assertFalse( $http_called );
    }

    public function test_generate_context_omits_auto_gpt_latest_web_search_options_from_payload(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            '~openai/gpt-latest',
            [
                'id'                   => '~openai/gpt-latest',
                'pricing'              => [
                    'prompt'     => '0.000005',
                    'completion' => '0.00003',
                    'web_search' => '0.01',
                ],
                'supported_parameters' => [
                    'response_format',
                    'structured_outputs',
                    'max_tokens',
                    'tools',
                    'tool_choice',
                    'web_search_options',
                ],
                'free'                 => false,
            ]
        );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [
                'model' => '~openai/gpt-latest',
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => '~openai/gpt-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode'        => 'auto',
                            'max_results' => 5,
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );

        $this->assertSame( '~openai/gpt-latest', $payload['model'] ?? null );
        $this->assertArrayNotHasKey( 'web_search_options', $payload );
        $this->assertNotContains( 'openrouter:web_search', array_column( $payload['tools'] ?? [], 'type' ) );
    }

    public function test_generate_context_omits_auto_unverified_openrouter_server_tools_from_payload(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            'example/web-search-server-tool-only',
            [
                'id'                   => 'example/web-search-server-tool-only',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'tools', 'tool_choice' ],
                'openrouter_server_tools' => [
                    'web_search' => true,
                    'web_fetch'  => false,
                    'datetime'   => false,
                ],
                'free'                 => false,
            ]
        );
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            null,
            [
                'model' => 'example/web-search-server-tool-only',
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/web-search-server-tool-only',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode'        => 'auto',
                            'max_results' => 6,
                        ],
                        'web_fetch'   => [
                            'mode' => 'auto',
                        ],
                        'datetime'    => [
                            'mode' => 'auto',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );
        $this->assertSame( 'example/web-search-server-tool-only', $payload['model'] ?? null );
        $this->assertSame( [ 'openrouter:web_search' ], array_column( $payload['tools'] ?? [], 'type' ) );
        $this->assertSame( 5, $payload['tools'][0]['parameters']['max_results'] ?? null );
        $this->assertArrayNotHasKey( 'tool_choice', $payload );
    }

    public function test_generate_context_classifies_openrouter_server_tool_failure_safely(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_model(
            'example/web-search-server-tool-only',
            [
                'id'                   => 'example/web-search-server-tool-only',
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'tools', 'tool_choice' ],
                'openrouter_server_tools' => [
                    'web_search' => true,
                    'web_fetch'  => false,
                    'datetime'   => false,
                ],
                'free'                 => false,
            ]
        );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 502,
                        'message' => 'Bad Gateway',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'error' => [
                                'code'     => 502,
                                'message'  => 'Server tool request failed',
                                'metadata' => [
                                    'provider_name' => 'OpenAI',
                                    'raw'           => 'raw provider payload must not be returned',
                                ],
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'example/web-search-server-tool-only',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'site_context_generation_openrouter_server_tool_failed', $data['generation_job']['code'] ?? null );
        $this->assertCount( 1, $calls );
        $diagnostics = $data['generation_job']['diagnostics'] ?? [];
        $this->assertSame( 'openrouter', $diagnostics['route'] ?? null );
        $this->assertSame( 'example/web-search-server-tool-only', $diagnostics['model'] ?? null );
        $this->assertSame( 'json_schema', $diagnostics['response_format'] ?? null );
        $this->assertSame( 'sentient_forms_site_context_generation_v1', $diagnostics['schema_name'] ?? null );
        $this->assertSame( [ 'openrouter:web_search' ], $diagnostics['tool_types'] ?? null );
        $this->assertSame( '502', $diagnostics['provider_error_code'] ?? null );
        $this->assertSame( 502, $diagnostics['provider_status'] ?? null );
        $this->assertArrayNotHasKey( 'payload', $data['generation_job'] ?? [] );
        $this->assertStringNotContainsString( 'raw provider payload', wp_json_encode( $data ) );
    }

    public function test_generate_context_classifies_openrouter_success_status_error_envelope_safely(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'error' => [
                                'code'    => 429,
                                'message' => 'Rate limit exceeded for this route.',
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'site_context_generation_openrouter_request_failed', $data['generation_job']['code'] ?? null );
        $this->assertSame( 'OpenRouter could not complete the Site Context request for the selected model and tool settings.', $data['generation_job']['error'] ?? null );
        $this->assertSame( 429, $data['generation_job']['status_code'] ?? null );
        $this->assertCount( 1, $calls );

        $diagnostics = $data['generation_job']['diagnostics'] ?? [];
        $this->assertSame( 'openrouter', $diagnostics['route'] ?? null );
        $this->assertSame( 'google/gemini-pro-latest', $diagnostics['model'] ?? null );
        $this->assertSame( 'json_schema', $diagnostics['response_format'] ?? null );
        $this->assertSame( 'sentient_forms_site_context_generation_v1', $diagnostics['schema_name'] ?? null );
        $this->assertSame( [ 'openrouter:web_search' ], $diagnostics['tool_types'] ?? null );
        $this->assertSame( '429', $diagnostics['provider_error_code'] ?? null );
        $this->assertSame( 429, $diagnostics['provider_status'] ?? null );
        $this->assertStringNotContainsString( 'Rate limit exceeded', wp_json_encode( $data ) ?: '' );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertSame( 'OpenRouter could not complete the Site Context request for the selected model and tool settings.', $settings['last_error'] ?? null );
    }

    public function test_manual_generation_retries_transient_openrouter_gateway_failure_once(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                if ( 1 === count( $calls ) )
                {
                    return [
                        'headers'  => [],
                        'response' => [
                            'code'    => 200,
                            'message' => 'OK',
                        ],
                        'body'     => wp_json_encode(
                            [
                                'error' => [
                                    'code'    => 504,
                                    'message' => 'Upstream gateway timed out.',
                                ],
                            ]
                        ),
                        'cookies'  => [],
                    ];
                }

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'id'      => 'or-gen-retry-success',
                            'model'   => 'google/gemini-pro-latest',
                            'choices' => [
                                [
                                    'finish_reason' => 'stop',
                                    'message'       => [
                                        'content' => wp_json_encode(
                                            [
                                                'summary_text'         => 'Acme Plumbing serves local homeowners with emergency drain and water heater help.',
                                                'legitimate_inquiries' => [ 'Drain repair', 'Water heater quote' ],
                                                'spam_relevance'       => [ 'Unrelated crypto offers' ],
                                                'source_urls'          => [ 'https://example.test/' ],
                                                'confidence'           => 0.88,
                                                'confidence_notes'     => 'Fixture generated after retry.',
                                            ]
                                        ),
                                    ],
                                ],
                            ],
                            'usage'   => [
                                'total_tokens' => 84,
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertNull( $data['generation_job']['error'] ?? null );
        $this->assertSame( 2, $data['generation_job']['attempts'] ?? null );
        $this->assertSame( 2, $data['generation_job']['max_attempts'] ?? null );
        $this->assertCount( 2, $calls );
        $this->assertSame( 'google/gemini-pro-latest', $data['context']['metadata']['model'] ?? null );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertNull( $settings['last_error'] ?? null );
    }

    public function test_manual_generation_retries_bare_openrouter_gateway_failure_once(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                if ( 1 === count( $calls ) )
                {
                    return [
                        'headers'  => [],
                        'response' => [
                            'code'    => 503,
                            'message' => 'Service Unavailable',
                        ],
                        'body'     => '<html><body>upstream unavailable</body></html>',
                        'cookies'  => [],
                    ];
                }

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'id'      => 'or-gen-retry-bare-success',
                            'model'   => 'google/gemini-pro-latest',
                            'choices' => [
                                [
                                    'finish_reason' => 'stop',
                                    'message'       => [
                                        'content' => wp_json_encode(
                                            [
                                                'summary_text'         => 'Acme Plumbing serves local homeowners with emergency drain and water heater help.',
                                                'legitimate_inquiries' => [ 'Drain repair', 'Water heater quote' ],
                                                'spam_relevance'       => [ 'Unrelated crypto offers' ],
                                                'source_urls'          => [ 'https://example.test/' ],
                                                'confidence'           => 0.88,
                                                'confidence_notes'     => 'Fixture generated after retry.',
                                            ]
                                        ),
                                    ],
                                ],
                            ],
                            'usage'   => [
                                'total_tokens' => 84,
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertNull( $data['generation_job']['error'] ?? null );
        $this->assertSame( 2, $data['generation_job']['attempts'] ?? null );
        $this->assertSame( 2, $data['generation_job']['max_attempts'] ?? null );
        $this->assertCount( 2, $calls );
        $this->assertSame( 'google/gemini-pro-latest', $data['context']['metadata']['model'] ?? null );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertNull( $settings['last_error'] ?? null );
    }

    public function test_manual_generation_retries_openrouter_transport_error_once(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                if ( 1 === count( $calls ) )
                {
                    return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
                }

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'id'      => 'or-gen-retry-transport-success',
                            'model'   => 'google/gemini-pro-latest',
                            'choices' => [
                                [
                                    'finish_reason' => 'stop',
                                    'message'       => [
                                        'content' => wp_json_encode(
                                            [
                                                'summary_text'         => 'Acme Plumbing serves local homeowners with emergency drain and water heater help.',
                                                'legitimate_inquiries' => [ 'Drain repair', 'Water heater quote' ],
                                                'spam_relevance'       => [ 'Unrelated crypto offers' ],
                                                'source_urls'          => [ 'https://example.test/' ],
                                                'confidence'           => 0.88,
                                                'confidence_notes'     => 'Fixture generated after transport retry.',
                                            ]
                                        ),
                                    ],
                                ],
                            ],
                            'usage'   => [
                                'total_tokens' => 84,
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'succeeded', $data['generation_job']['status'] ?? null );
        $this->assertSame( 2, $data['generation_job']['attempts'] ?? null );
        $this->assertCount( 2, $calls );
    }

    public function test_failed_manual_generation_rearms_refresh_and_first_generation_schedules(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );
        add_filter(
            'pre_http_request',
            static function () {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 400,
                        'message' => 'Bad Request',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'error' => [
                                'message' => 'Selected route cannot satisfy this request.',
                                'code'    => 'bad_request',
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            }
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status'       => 'granted',
                'auto_refresh_enabled' => true,
                'auto_refresh_days'    => 14,
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_refresh' ) );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_site_context_refresh' ) );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertNotEmpty( $settings['next_refresh_at'] ?? null );
        $this->assertNotEmpty( $settings['first_generation_next_attempt_at'] ?? null );
    }

    public function test_manual_generation_does_not_retry_after_job_is_canceled(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'google/gemini-pro-latest' );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                if ( 1 === count( $calls ) )
                {
                    delete_option( 'sentient_forms_site_context_generation_job' );
                    return [
                        'headers'  => [],
                        'response' => [
                            'code'    => 503,
                            'message' => 'Service Unavailable',
                        ],
                        'body'     => '<html><body>upstream unavailable</body></html>',
                        'cookies'  => [],
                    ];
                }

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'id'      => 'or-gen-stale-retry-success',
                            'model'   => 'google/gemini-pro-latest',
                            'choices' => [
                                [
                                    'finish_reason' => 'stop',
                                    'message'       => [
                                        'content' => wp_json_encode(
                                            [
                                                'summary_text'         => 'This retry should never commit.',
                                                'legitimate_inquiries' => [ 'Drain repair' ],
                                                'spam_relevance'       => [ 'Unrelated crypto offers' ],
                                                'source_urls'          => [ 'https://example.test/' ],
                                                'confidence'           => 0.88,
                                                'confidence_notes'     => 'Fixture should not be used.',
                                            ]
                                        ),
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'google/gemini-pro-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $data = $this->run_site_context_generation_job( $job_id );

        $this->assertCount( 1, $calls );
        $this->assertNull( $data['generation_job'] ?? null );
        $this->assertNull( $data['context'] ?? null );
        $this->assertFalse( get_option( 'sentient_forms_site_context_generation_job' ) );
        $this->assertFalse( get_option( 'sentient_forms_site_context' ) );
    }

    public function test_generate_context_classifies_openrouter_choice_error_safely(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $this->cache_openrouter_all_server_tool_model( 'anthropic/claude-opus-latest' );
        $calls = [];
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'id'      => 'or-choice-error',
                            'model'   => 'anthropic/claude-opus-latest',
                            'choices' => [
                                [
                                    'finish_reason' => 'error',
                                    'message'       => [
                                        'role'    => 'assistant',
                                        'content' => 'partial output...',
                                    ],
                                    'error'         => [
                                        'code'    => 502,
                                        'message' => 'Provider disconnected mid-stream',
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'anthropic/claude-opus-latest',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                    'tools'         => [
                        'tool_choice' => 'auto',
                        'web_search'  => [
                            'mode' => 'required',
                        ],
                    ],
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'site_context_generation_openrouter_request_failed', $data['generation_job']['code'] ?? null );
        $this->assertSame( 502, $data['generation_job']['status_code'] ?? null );
        $this->assertCount( 1, $calls );

        $diagnostics = $data['generation_job']['diagnostics'] ?? [];
        $this->assertSame( 'anthropic/claude-opus-latest', $diagnostics['model'] ?? null );
        $this->assertSame( 'or-choice-error', $diagnostics['response_id'] ?? null );
        $this->assertSame( 'error', $diagnostics['finish_reason'] ?? null );
        $this->assertSame( [ 'openrouter:web_search' ], $diagnostics['tool_types'] ?? null );
        $this->assertSame( '502', $diagnostics['provider_error_code'] ?? null );
        $this->assertSame( 502, $diagnostics['provider_status'] ?? null );
        $this->assertStringNotContainsString( 'partial output', wp_json_encode( $data ) ?: '' );
        $this->assertStringNotContainsString( 'Provider disconnected', wp_json_encode( $data ) ?: '' );
    }

    /**
     * @dataProvider invalid_openrouter_generation_content_provider
     */
    public function test_generate_context_returns_safe_diagnostics_for_invalid_provider_content( string $provider_content ): void
    {
        $credential_id = $this->create_openrouter_credential();
        $calls = [];
        $this->mock_openrouter_site_context_generation(
            $calls,
            $provider_content,
            [
                'id'            => 'or-gen-invalid-json',
                'model'         => 'openai/gpt-5.5',
                'finish_reason' => 'stop',
                'total_tokens'  => 123,
            ]
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertCount( 0, $calls );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'site_context_generation_invalid_json', $data['generation_job']['code'] ?? null );
        $this->assertCount( 1, $calls );

        $diagnostics = $data['generation_job']['diagnostics'] ?? null;
        $this->assertIsArray( $diagnostics );
        $this->assertSame( 'openrouter', $diagnostics['route'] ?? null );
        $this->assertSame( 'openai/gpt-5.5', $diagnostics['model'] ?? null );
        $this->assertSame( 'or-gen-invalid-json', $diagnostics['response_id'] ?? null );
        $this->assertSame( 'stop', $diagnostics['finish_reason'] ?? null );
        $this->assertSame( 'json_schema', $diagnostics['response_format'] ?? null );
        $this->assertSame( 'sentient_forms_site_context_generation_v1', $diagnostics['schema_name'] ?? null );
        $this->assertSame( strlen( $provider_content ), $diagnostics['content_length'] ?? null );
        $this->assertSame( hash( 'sha256', $provider_content ), $diagnostics['content_sha256'] ?? null );
        $this->assertSame( 123, $diagnostics['usage_total_tokens'] ?? null );
        if ( '' !== $provider_content )
        {
            $this->assertStringNotContainsString( $provider_content, wp_json_encode( $data ) ?: '' );
        }

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertSame( 'The Site Context model did not return valid JSON.', $settings['last_error'] ?? null );
        if ( '' !== $provider_content )
        {
            $this->assertStringNotContainsString( $provider_content, (string) ( $settings['last_error'] ?? '' ) );
        }
    }

    public function invalid_openrouter_generation_content_provider(): array
    {
        return [
            'malformed-json' => [ '{not valid json' ],
            'empty-content'  => [ '' ],
        ];
    }

    public function test_generate_context_preserves_clamped_provider_error_status(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => '{not-json',
                    'cookies'  => [],
                ];
            },
            10,
            3
        );

        $job_id = $this->queue_site_context_generation(
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );

        $this->assertFalse( $http_called );
        $data = $this->run_site_context_generation_job( $job_id );
        $this->assertTrue( $http_called );
        $this->assertSame( 'failed', $data['generation_job']['status'] ?? null );
        $this->assertSame( 'openrouter_invalid_json', $data['generation_job']['code'] ?? null );
        $this->assertSame( 400, $data['generation_job']['status_code'] ?? null );
    }

    public function test_generate_context_rejects_empty_decrypted_openrouter_key_before_http(): void
    {
        $credential_id = $this->create_openrouter_credential_with_encrypted_secret(
            $this->encrypt_raw_provider_secret_for_test( '   ' )
        );
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;
                return new WP_Error( 'unexpected_http_call', 'Blank OpenRouter credentials must block before HTTP.' );
            },
            10,
            3
        );

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            [
                'consent_status' => 'granted',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
            ]
        );
        $data = $response->get_data();

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'sentient_forms_provider_secret_missing', $data['code'] ?? null );
        $this->assertSame( 'Provider credential does not contain a usable stored secret.', $data['message'] ?? null );
        $this->assertFalse( $http_called );
    }

    public function test_scheduled_first_generation_generates_when_paid_route_becomes_ready(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

        update_option(
            'sentient_forms_site_context_settings',
            [
                'consent_status' => 'granted',
                'consented_at'   => '2026-05-28 00:00:00',
                'generation_model_selection' => [
                    'primary'       => 'openai/gpt-5.5',
                    'provider'      => 'openrouter',
                    'credential_id' => $credential_id,
                    'is_preset'     => false,
                ],
                'first_generation_started_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
                'first_generation_attempt_count' => 0,
            ],
            false
        );
        wp_schedule_single_event( time() - 1, 'sentient_forms_site_context_first_generation' );

        Sentient_Forms_Site_Context_Controller::run_scheduled_first_generation();

        $context = get_option( 'sentient_forms_site_context' );
        $this->assertSame( 'ai_generated', $context['source'] ?? null );
        $this->assertStringContainsString( 'Acme Plumbing', $context['summary_text'] ?? '' );
        $this->assertCount( 1, $calls );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertSame( 0, (int) ( $settings['first_generation_attempt_count'] ?? -1 ) );
        $this->assertEmpty( $settings['first_generation_last_error'] ?? null );
        $this->assertEmpty( $settings['first_generation_exhausted_at'] ?? null );
        $this->assertEmpty( $settings['first_generation_next_attempt_at'] ?? null );
    }

    public function test_first_generation_retry_exhausts_after_fifth_blocked_attempt(): void
    {
        update_option(
            'sentient_forms_site_context_settings',
            [
                'consent_status' => 'granted',
                'consented_at'   => '2026-05-28 00:00:00',
                'first_generation_started_at' => gmdate( 'Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS ),
                'first_generation_attempt_count' => 4,
            ],
            false
        );

        Sentient_Forms_Site_Context_Controller::run_scheduled_first_generation();

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertSame( 5, (int) ( $settings['first_generation_attempt_count'] ?? 0 ) );
        $this->assertNotEmpty( $settings['first_generation_exhausted_at'] ?? null );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );
    }

    public function test_first_generation_rearms_after_provider_setup_when_previous_attempts_exhausted(): void
    {
        update_option(
            'sentient_forms_site_context_settings',
            [
                'consent_status' => 'granted',
                'consented_at'   => '2026-05-28 00:00:00',
                'first_generation_started_at' => '2026-05-28 00:00:00',
                'first_generation_attempt_count' => 5,
                'first_generation_last_error' => 'Provider was not ready.',
                'first_generation_exhausted_at' => '2026-05-31 00:00:00',
            ],
            false
        );
        $this->create_openrouter_credential();

        Sentient_Forms_Site_Context_Controller::maybe_rearm_first_generation_after_provider_setup();

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertSame( 0, (int) ( $settings['first_generation_attempt_count'] ?? -1 ) );
        $this->assertEmpty( $settings['first_generation_exhausted_at'] ?? null );
        $this->assertEmpty( $settings['first_generation_last_error'] ?? null );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );
    }

    public function test_scheduled_first_generation_never_overwrites_existing_manual_context(): void
    {
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;
                return new WP_Error( 'unexpected_http_call', 'Existing context must block first generation.' );
            },
            10,
            3
        );

        update_option(
            'sentient_forms_site_context',
            [
                'id'                     => 'local-site-context',
                'license_id'             => 'local',
                'summary_text'           => 'Manual context must survive.',
                'source'                 => 'manual',
                'auto_include'           => true,
                'pii_ack'                => true,
                'free_refresh_available' => true,
                'next_free_refresh_at'   => null,
                'created_at'             => '2026-05-28 00:00:00',
                'updated_at'             => '2026-05-28 00:00:00',
            ],
            false
        );
        update_option(
            'sentient_forms_site_context_settings',
            [
                'consent_status' => 'granted',
                'consented_at'   => '2026-05-28 00:00:00',
                'first_generation_started_at' => '2026-05-28 00:00:00',
                'first_generation_attempt_count' => 3,
                'first_generation_last_error' => 'Provider was not ready.',
                'first_generation_exhausted_at' => '2026-05-31 00:00:00',
            ],
            false
        );

        Sentient_Forms_Site_Context_Controller::run_scheduled_first_generation();

        $context = get_option( 'sentient_forms_site_context' );
        $this->assertSame( 'Manual context must survive.', $context['summary_text'] ?? null );
        $this->assertFalse( $http_called );

        $settings = get_option( 'sentient_forms_site_context_settings' );
        $this->assertSame( 0, (int) ( $settings['first_generation_attempt_count'] ?? -1 ) );
        $this->assertEmpty( $settings['first_generation_last_error'] ?? null );
        $this->assertEmpty( $settings['first_generation_exhausted_at'] ?? null );
        $this->assertEmpty( $settings['first_generation_next_attempt_at'] ?? null );
    }

    public function test_item_schema_exposes_status_fields(): void
    {
        $controller = new Sentient_Forms_Site_Context_Controller();
        $schema     = $controller->get_item_schema();

        $properties = $schema['properties'] ?? [];
        $this->assertArrayHasKey( 'context', $properties );
        $this->assertArrayHasKey( 'settings', $properties );
        $this->assertArrayHasKey( 'status', $properties );
        $this->assertArrayHasKey( 'generation_access', $properties );
        $this->assertArrayHasKey( 'generation_job', $properties );
    }

    private function dispatch_site_context_request( string $method, string $path, array $params = [] ): WP_REST_Response
    {
        $request = new WP_REST_Request( $method, $path );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        foreach ( $params as $key => $value )
        {
            $request->set_param( $key, $value );
        }

        return rest_get_server()->dispatch( $request );
    }

    private function queue_site_context_generation( array $params ): string
    {
        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
            $params
        );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNull( $data['context'] ?? null );
        $this->assertSame( 'queued', $data['generation_job']['status'] ?? null );

        $job_id = (string) ( $data['generation_job']['id'] ?? '' );
        $this->assertNotSame( '', $job_id );

        return $job_id;
    }

    private function run_site_context_generation_job( string $job_id ): array
    {
        Sentient_Forms_Site_Context_Controller::run_scheduled_manual_generation( $job_id );

        $response = $this->dispatch_site_context_request( 'GET', '/sentient-forms/v1/site-context' );
        $this->assertSame( 200, $response->get_status() );

        return $response->get_data();
    }

    private function create_openrouter_credential( string $status = 'valid', array $status_json_overrides = [] ): int
    {
        $vault = new Sentient_Forms_Provider_Credential_Vault();
        $encrypted = $vault->encrypt( 'sk-or-site-context-test' );
        $this->assertIsString( $encrypted );

        return $this->create_openrouter_credential_with_encrypted_secret( $encrypted, $status, $status_json_overrides );
    }

    private function create_openrouter_credential_with_encrypted_secret( string $encrypted_secret, string $status = 'valid', array $status_json_overrides = [] ): int
    {
        $this->assertNotSame( '', $encrypted_secret );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $credential_id = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Site Context OpenRouter',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted_secret,
                'status'            => $status,
                'status_json'       => array_merge(
                    [
                        'label'        => 'Test key',
                        'is_free_tier' => false,
                    ],
                    $status_json_overrides
                ),
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        return $credential_id;
    }

    private function encrypt_raw_provider_secret_for_test( string $secret ): string
    {
        // Intentionally bypass the vault encrypt path, which trims and rejects blank secrets.
        $iv  = random_bytes( 12 );
        $tag = '';
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        $this->assertIsString( $ciphertext );
        $this->assertNotSame( '', $tag );

        $payload = wp_json_encode(
            [
                'version'    => 1,
                'cipher'     => 'aes-256-gcm',
                'iv'         => base64_encode( $iv ),
                'tag'        => base64_encode( $tag ),
                'ciphertext' => base64_encode( $ciphertext ),
            ]
        );

        $this->assertIsString( $payload );

        return $payload;
    }

    private function create_managed_credential( string $status = 'valid' ): int
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $credential_id = $credentials->create(
            [
                'provider'          => 'sentient_managed',
                'label'             => 'Managed Site Context',
                'auth_mode'         => 'sentient_proxy',
                'status'            => $status,
                'status_json'       => [
                    'license_id'        => 'license-site-context-test',
                    'site_id'           => 'site-context-site-id',
                    'license_status'    => 'active',
                    'proxy_key_present' => true,
                    'managed_consent'   => [
                        'state' => 'accepted',
                    ],
                ],
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        return $credential_id;
    }

    private function cache_openrouter_model( string $model_id, array $metadata ): void
    {
        $repository = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $result = $repository->upsert( 'openrouter', $model_id, $metadata, gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

        $this->assertTrue( $result );
    }

    private function cache_openrouter_all_server_tool_model( string $model_id ): void
    {
        $this->cache_openrouter_model(
            $model_id,
            [
                'id'                   => $model_id,
                'pricing'              => [
                    'prompt'     => '0.000001',
                    'completion' => '0.000002',
                    'web_search' => '0.004',
                ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens', 'reasoning', 'tools', 'tool_choice' ],
                'openrouter_server_tools' => [
                    'web_search' => true,
                    'web_fetch'  => true,
                    'datetime'   => true,
                ],
                'free'                 => false,
            ]
        );
    }

    private function count_scheduled_hook( string $hook ): int
    {
        $events = _get_cron_array();
        $count  = 0;

        foreach ( is_array( $events ) ? $events : [] as $timestamp_events )
        {
            if ( ! is_array( $timestamp_events[ $hook ] ?? null ) )
            {
                continue;
            }

            $count += count( $timestamp_events[ $hook ] );
        }

        return $count;
    }

    private function mock_openrouter_site_context_generation( array &$calls, ?string $content_override = null, array $response_overrides = [], ?callable $before_response = null ): void
    {
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls, $content_override, $response_overrides, $before_response ) {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                $content = wp_json_encode(
                    [
                        'summary_text'         => 'Acme Plumbing serves local homeowners with emergency drain and water heater help.',
                        'legitimate_inquiries' => [ 'Drain repair', 'Water heater quote' ],
                        'spam_relevance'       => [ 'Unrelated crypto offers' ],
                        'source_urls'          => [ 'https://example.test/' ],
                        'confidence'           => 0.88,
                        'confidence_notes'     => 'Fixture generated for PHPUnit.',
                    ]
                );
                if ( null !== $content_override )
                {
                    $content = $content_override;
                }

                $body = [
                    'id'      => (string) ( $response_overrides['id'] ?? 'or-gen-success' ),
                    'model'   => (string) ( $response_overrides['model'] ?? 'openai/gpt-5.5' ),
                    'choices' => [
                        [
                            'finish_reason' => (string) ( $response_overrides['finish_reason'] ?? 'stop' ),
                            'message'       => [
                                'content' => $content,
                            ],
                        ],
                    ],
                    'usage'   => [
                        'total_tokens' => (int) ( $response_overrides['total_tokens'] ?? 42 ),
                    ],
                ];

                if ( null !== $before_response )
                {
                    $before_response();
                }

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode( $body ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );
    }

    private function mock_managed_site_context_generation( array &$calls, ?array $data_overrides = null ): void
    {
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls, $data_overrides ) {
                if ( ! str_contains( $url, '/managed/execute' ) )
                {
                    return new WP_Error( 'unexpected_http_call', 'Managed Site Context generation must only call the managed execution endpoint.' );
                }

                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                $content = wp_json_encode(
                    [
                        'summary_text'         => 'Acme Plumbing serves local homeowners with emergency drain and water heater help.',
                        'legitimate_inquiries' => [ 'Drain repair', 'Water heater quote' ],
                        'spam_relevance'       => [ 'Unrelated crypto offers' ],
                        'source_urls'          => [ 'https://example.test/' ],
                        'confidence'           => 0.88,
                        'confidence_notes'     => 'Fixture generated for PHPUnit.',
                    ]
                );

                $data = array_merge(
                    [
                        'execution_request_id'    => 'site_context_managed_test',
                        'provider'                => 'sentient_managed',
                        'model'                   => 'openai/gpt-5.5',
                        'status'                  => 'succeeded',
                        'output'                  => [ 'text' => $content ],
                        'token_usage'             => [
                            'input_tokens'  => 24,
                            'output_tokens' => 18,
                            'total_tokens'  => 42,
                        ],
                        'metering'                => [
                            'event_id'        => '66666666-6666-4666-8666-666666666666',
                            'free_usage'      => false,
                            'debited_credits' => 2,
                        ],
                        'privacy_route_assertion' => [
                            'schema'              => 'sentient_forms_privacy_route_assertion.v1',
                            'zdr_enforced'        => true,
                            'data_collection'     => 'deny',
                            'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
                        ],
                    ],
                    is_array( $data_overrides ) ? $data_overrides : []
                );

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'success' => true,
                            'data'    => $data,
                        ]
                    ),
                    'cookies'  => [],
                ];
            },
            10,
            3
        );
    }

    private function truncate_local_provider_tables(): void
    {
        global $wpdb;

        foreach ( [ 'sentient_provider_credentials', 'sentient_external_service_consents', 'sentient_model_cache' ] as $table )
        {
            $wpdb->query( 'DELETE FROM ' . esc_sql( $wpdb->prefix . $table ) );
        }
    }
}
