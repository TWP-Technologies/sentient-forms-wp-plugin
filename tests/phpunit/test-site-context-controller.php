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
        $rest_api = new Sentient_Forms_REST_API();
        $server   = rest_get_server();
        do_action( 'rest_api_init', $server );
    }

    protected function tearDown(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();
        delete_option( 'sentient_forms_site_context' );
        delete_option( 'sentient_forms_site_context_settings' );
        wp_clear_scheduled_hook( 'sentient_forms_site_context_refresh' );
        wp_clear_scheduled_hook( 'sentient_forms_site_context_first_generation' );
        $this->truncate_local_provider_tables();
        add_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        remove_all_filters( 'pre_http_request' );
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

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
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
                    'max_results' => 7,
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
        $this->assertSame( 7, $saved_selection['tools']['web_search']['max_results'] ?? null );
        $this->assertSame( 'required', $saved_selection['tools']['web_fetch']['mode'] ?? null );
        $this->assertSame( 'required', $saved_selection['tools']['datetime']['mode'] ?? null );
    }

    public function test_update_context_defaults_empty_datetime_tool_to_off(): void
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

        $this->assertSame( 'off', $saved_selection['tools']['datetime']['mode'] ?? null );
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

    public function test_generate_context_uses_ready_openrouter_paid_model(): void
    {
        $credential_id = $this->create_openrouter_credential();
        $calls = [];
        $this->mock_openrouter_site_context_generation( $calls );

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
        $this->assertSame( 'ai_generated', $data['context']['source'] ?? null );
        $this->assertStringContainsString( 'Acme Plumbing', $data['context']['summary_text'] ?? '' );
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
        $this->assertSame( 7, $payload['tools'][0]['parameters']['max_results'] ?? null );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_site_context_first_generation' ) );
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

        $response = $this->dispatch_site_context_request(
            'POST',
            '/sentient-forms/v1/site-context/generate',
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

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $calls );
        $payload = json_decode( (string) $calls[0]['args']['body'], true );

        $this->assertSame( 'example/schema-no-optionals', $payload['model'] ?? null );
        $this->assertSame( 'json_schema', $payload['response_format']['type'] ?? null );
        $this->assertTrue( $payload['provider']['require_parameters'] ?? false );
        $this->assertArrayNotHasKey( 'temperature', $payload );
        $this->assertArrayNotHasKey( 'reasoning', $payload );
        $this->assertArrayNotHasKey( 'tools', $payload );
        $this->assertArrayNotHasKey( 'tool_choice', $payload );
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

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'site_context_generation_invalid_json', $data['code'] ?? null );
        $this->assertCount( 1, $calls );

        $diagnostics = $data['data']['diagnostics'] ?? null;
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

        $this->assertTrue( $http_called );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'openrouter_invalid_json', $data['code'] ?? null );
        $this->assertSame( 400, $data['data']['status'] ?? null );
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

    private function mock_openrouter_site_context_generation( array &$calls, ?string $content_override = null, array $response_overrides = [] ): void
    {
        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ) use ( &$calls, $content_override, $response_overrides ) {
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

    private function truncate_local_provider_tables(): void
    {
        global $wpdb;

        foreach ( [ 'sentient_provider_credentials', 'sentient_external_service_consents', 'sentient_model_cache' ] as $table )
        {
            $wpdb->query( 'DELETE FROM ' . esc_sql( $wpdb->prefix . $table ) );
        }
    }
}
