<?php

class Tests_Local_Providers_Controller extends WP_UnitTestCase
{
    private static int $admin_id;

    /** @var array<int, callable> */
    private array $http_filters = [];

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
        $this->truncate_local_provider_tables();

        add_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        $rest_api = new Sentient_Forms_REST_API();
        do_action( 'rest_api_init', rest_get_server() );
    }

    protected function tearDown(): void
    {
        remove_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );

        foreach ( $this->http_filters as $callback )
        {
            remove_filter( 'pre_http_request', $callback, 10 );
        }

        $this->http_filters = [];
        parent::tearDown();
    }

    public function controller_classes(): array
    {
        return [ Sentient_Forms_Local_Providers_Controller::class ];
    }

    public function test_validate_openrouter_key_records_consent_without_saving_by_default(): void
    {
        $secret = 'sk-or-local-test-secret';
        $this->mock_openrouter_key_response();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/validate' );
        $request->set_body_params(
            [
                'api_key'                         => $secret,
                'disclosure_version'              => '2026-04-16',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'openrouter', $data['provider'] );
        $this->assertSame( 'valid', $data['status'] );
        $this->assertNull( $data['credential_id'] );
        $this->assertTrue( $data['consent_recorded'] );
        $this->assertSame( 875, $data['key_status']['limit_remaining'] );
        $this->assertStringNotContainsString( $secret, wp_json_encode( $data ) );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $this->assertSame( [], $credentials->list() );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $latest   = $consents->latest_for_provider( 'openrouter' );
        $this->assertIsArray( $latest );
        $this->assertSame( '2026-04-16', $latest['disclosure_version'] );
    }

    public function test_validate_openrouter_key_can_save_encrypted_credential(): void
    {
        $secret = 'sk-or-local-save-secret';
        $this->mock_openrouter_key_response();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/validate' );
        $request->set_body_params(
            [
                'api_key'                         => $secret,
                'label'                           => 'Primary OpenRouter',
                'save'                            => true,
                'disclosure_version'              => '2026-04-16',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertIsInt( $data['credential_id'] );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $row         = $credentials->get( $data['credential_id'] );
        $this->assertIsArray( $row );
        $this->assertSame( 'openrouter', $row['provider'] );
        $this->assertSame( 'Primary OpenRouter', $row['label'] );
        $this->assertSame( 'manual_key', $row['auth_mode'] );
        $this->assertSame( 'valid', $row['status'] );
        $this->assertNotEmpty( $row['encrypted_secret'] );
        $this->assertStringNotContainsString( $secret, $row['encrypted_secret'] );

        $vault = new Sentient_Forms_Provider_Credential_Vault();
        $this->assertSame( $secret, $vault->decrypt( $row['encrypted_secret'] ) );

        $list_request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/credentials' );
        $list_response = rest_get_server()->dispatch( $list_request );
        $this->assertSame( 200, $list_response->get_status() );
        $list_data = $list_response->get_data();
        $this->assertCount( 1, $list_data );
        $this->assertTrue( $list_data[0]['secret_configured'] );
        $this->assertArrayNotHasKey( 'encrypted_secret', $list_data[0] );
        $this->assertStringNotContainsString( $secret, wp_json_encode( $list_data ) );
    }

    public function test_validate_openrouter_key_requires_consent_before_external_call(): void
    {
        $external_call_count = 0;
        $this->mock_openrouter_key_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/validate' );
        $request->set_body_params(
            [
                'api_key'                         => 'sk-or-consent-blocked',
                'disclosure_version'              => '2026-04-16',
                'accepted_external_service_terms' => false,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 0, $external_call_count );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $this->assertNull( $consents->latest_for_provider( 'openrouter' ) );
    }

    public function test_list_openrouter_models_reads_local_cache_without_external_call(): void
    {
        $external_call_count = 0;
        $this->mock_openrouter_models_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openai/gpt-oss-20b:free',
                [
                    'id'                   => 'openai/gpt-oss-20b:free',
                    'name'                 => 'OpenAI: GPT OSS 20B (free)',
                    'free'                 => true,
                    'context_length'       => 131072,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format' ],
                    'pricing'              => [
                        'prompt'     => '0',
                        'completion' => '0',
                        'request'    => '0',
                    ],
                ],
                $expires_at
            )
        );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'anthropic/claude-sonnet-4.5',
                [
                    'id'     => 'anthropic/claude-sonnet-4.5',
                    'name'   => 'Anthropic: Claude Sonnet 4.5',
                    'free'   => false,
                    'pricing' => [
                        'prompt'     => '0.000003',
                        'completion' => '0.000015',
                    ],
                ],
                $expires_at
            )
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/openrouter/models' );
        $request->set_query_params( [ 'free_only' => true ] );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 0, $external_call_count );

        $data = $response->get_data();
        $this->assertSame( 'openrouter', $data['provider'] );
        $this->assertSame( 2, $data['total_cached'] );
        $this->assertSame( 1, $data['total_returned'] );
        $this->assertSame( 1, $data['free_count'] );
        $this->assertSame( 'openai/gpt-oss-20b:free', $data['models'][0]['id'] );
        $this->assertTrue( $data['models'][0]['free'] );
    }

    public function test_refresh_openrouter_models_requires_consent_before_external_call(): void
    {
        $external_call_count = 0;
        $this->mock_openrouter_models_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/models/refresh' );
        $request->set_body_params(
            [
                'disclosure_version'              => '2026-04-18',
                'accepted_external_service_terms' => false,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 0, $external_call_count );

        $models = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $this->assertSame( [], $models->list( 'openrouter', true ) );
    }

    public function test_refresh_openrouter_models_records_consent_and_caches_catalog(): void
    {
        $external_call_count = 0;
        $this->mock_openrouter_models_response(
            function ( array $args, string $url ) use ( &$external_call_count ): void {
                ++$external_call_count;
                $this->assertStringContainsString( '/models?output_modalities=text', $url );
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/models/refresh' );
        $request->set_body_params(
            [
                'disclosure_version'              => '2026-04-18',
                'accepted_external_service_terms' => true,
                'output_modalities'               => 'text',
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $external_call_count );

        $data = $response->get_data();
        $this->assertSame( 'openrouter', $data['provider'] );
        $this->assertTrue( $data['consent_recorded'] );
        $this->assertSame( 2, $data['total_cached'] );
        $this->assertSame( 1, $data['free_count'] );
        $this->assertSame( 0, $data['stale_count'] );

        $models = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $model  = $models->get( 'openrouter', 'openai/gpt-oss-20b:free' );
        $this->assertIsArray( $model );
        $this->assertTrue( $model['metadata_json']['free'] );
        $this->assertContains( 'structured_outputs', $model['metadata_json']['supported_parameters'] );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $latest   = $consents->latest_for_provider( 'openrouter' );
        $this->assertIsArray( $latest );
        $this->assertSame( '2026-04-18', $latest['disclosure_version'] );
    }

    private function mock_openrouter_key_response( ?callable $on_request = null ): void
    {
        $callback = function ( $preempt, $args, $url ) use ( $on_request ) {
            if ( ! str_ends_with( $url, '/key' ) )
            {
                return $preempt;
            }

            if ( is_callable( $on_request ) )
            {
                $on_request( $args, $url );
            }

            return [
                'headers'  => [ 'content-type' => 'application/json' ],
                'body'     => file_get_contents( dirname( __DIR__ ) . '/fixtures/openrouter/key-success.json' ),
                'response' => [
                    'code'    => 200,
                    'message' => 'OK',
                ],
                'cookies'  => [],
            ];
        };

        $this->http_filters[] = $callback;
        add_filter( 'pre_http_request', $callback, 10, 3 );
    }

    private function mock_openrouter_models_response( ?callable $on_request = null ): void
    {
        $callback = function ( $preempt, $args, $url ) use ( $on_request ) {
            if ( ! str_contains( $url, '/models' ) )
            {
                return $preempt;
            }

            if ( is_callable( $on_request ) )
            {
                $on_request( $args, $url );
            }

            return [
                'headers'  => [ 'content-type' => 'application/json' ],
                'body'     => file_get_contents( dirname( __DIR__ ) . '/fixtures/openrouter/models-success.json' ),
                'response' => [
                    'code'    => 200,
                    'message' => 'OK',
                ],
                'cookies'  => [],
            ];
        };

        $this->http_filters[] = $callback;
        add_filter( 'pre_http_request', $callback, 10, 3 );
    }

    private function truncate_local_provider_tables(): void
    {
        global $wpdb;

        foreach ( [ 'sentient_provider_credentials', 'sentient_external_service_consents', 'sentient_model_cache' ] as $table )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }
}
