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
        Sentient_Forms_Plugin::instance()->clear_license_data();

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
        Sentient_Forms_Plugin::instance()->clear_license_data();
        parent::tearDown();
    }

    public function controller_classes(): array
    {
        return [ Sentient_Forms_Local_Providers_Controller::class ];
    }

    private function add_rest_nonce( WP_REST_Request $request ): WP_REST_Request
    {
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        return $request;
    }

    public function test_validate_openrouter_key_records_consent_without_saving_by_default(): void
    {
        $secret = 'sk-or-local-test-secret';
        $this->mock_openrouter_key_response();

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/validate' ) );
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

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/validate' ) );
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

    public function test_save_openrouter_constant_can_store_server_secret_reference(): void
    {
        $constant_name = 'SENTIENT_FORMS_OPENROUTER_TEST_SERVER_SECRET';
        $secret        = 'sk-or-local-constant-secret';
        putenv( $constant_name . '=' . $secret );

        try
        {
            $this->mock_openrouter_key_response();

            $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/constant' ) );
            $request->set_body_params(
                [
                    'constant_name'                  => $constant_name,
                    'label'                          => 'OpenRouter server secret',
                    'disclosure_version'             => '2026-04-22',
                    'accepted_external_service_terms' => true,
                ]
            );

            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 200, $response->get_status() );
            $data = $response->get_data();
            $this->assertSame( 'openrouter', $data['provider'] );
            $this->assertSame( 'valid', $data['status'] );
            $this->assertSame( 'constant', $data['auth_mode'] );
            $this->assertSame( $constant_name, $data['constant_name'] );
            $this->assertIsInt( $data['credential_id'] );

            $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
            $row         = $credentials->get( $data['credential_id'] );
            $this->assertIsArray( $row );
            $this->assertSame( 'openrouter', $row['provider'] );
            $this->assertSame( 'OpenRouter server secret', $row['label'] );
            $this->assertSame( 'constant', $row['auth_mode'] );
            $this->assertSame( $constant_name, $row['constant_name'] );
            $this->assertEmpty( $row['encrypted_secret'] );

            $list_request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/credentials' );
            $list_response = rest_get_server()->dispatch( $list_request );
            $this->assertSame( 200, $list_response->get_status() );
            $list_data = $list_response->get_data();
            $this->assertCount( 1, $list_data );
            $this->assertTrue( $list_data[0]['secret_configured'] );
            $this->assertSame( $constant_name, $list_data[0]['constant_name'] );
            $this->assertStringNotContainsString( $secret, wp_json_encode( $list_data ) );
        }
        finally
        {
            putenv( $constant_name );
        }
    }

    /**
     * Lowercase submissions should be stored and displayed as PHP constant names.
     */
    public function test_save_openrouter_constant_normalizes_constant_name_before_storage(): void
    {
        $constant_name = 'SENTIENT_FORMS_OPENROUTER_TEST_MIXED_CASE_SECRET';
        $submitted     = 'sentient_forms_openrouter_test_mixed_case_secret';
        $secret        = 'sk-or-local-mixed-case-secret';
        putenv( $constant_name . '=' . $secret );

        try
        {
            $this->mock_openrouter_key_response(
                function ( array $args ) use ( $secret ): void {
                    $this->assertSame( 'Bearer ' . $secret, $args['headers']['Authorization'] );
                }
            );

            $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/constant' ) );
            $request->set_body_params(
                [
                    'constant_name'                  => $submitted,
                    'label'                          => 'OpenRouter server secret',
                    'disclosure_version'             => '2026-04-22',
                    'accepted_external_service_terms' => true,
                ]
            );

            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 200, $response->get_status() );
            $data = $response->get_data();
            $this->assertSame( $constant_name, $data['constant_name'] );

            $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
            $row         = $credentials->get( $data['credential_id'] );
            $this->assertIsArray( $row );
            $this->assertSame( $constant_name, $row['constant_name'] );

            $list_request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/credentials' );
            $list_response = rest_get_server()->dispatch( $list_request );
            $this->assertSame( 200, $list_response->get_status() );
            $list_data = $list_response->get_data();
            $this->assertSame( $constant_name, $list_data[0]['constant_name'] );
        }
        finally
        {
            putenv( $constant_name );
        }
    }

    public function test_save_openrouter_constant_requires_resolvable_secret(): void
    {
        $constant_name = 'SENTIENT_FORMS_OPENROUTER_TEST_MISSING_SECRET';
        putenv( $constant_name );

        $external_call_count = 0;
        $this->mock_openrouter_key_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/constant' ) );
        $request->set_body_params(
            [
                'constant_name'                  => $constant_name,
                'disclosure_version'             => '2026-04-22',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'sentient_forms_secret_constant_not_found', $response->get_data()['code'] );
        $this->assertSame( 0, $external_call_count );
    }

    /**
     * WordPress auth secrets must not be read, persisted, or sent for validation.
     */
    public function test_save_openrouter_constant_rejects_wordpress_auth_secret_without_consent_record_or_external_call(): void
    {
        $external_call_count = 0;
        $this->mock_openrouter_key_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/constant' ) );
        $request->set_body_params(
            [
                'constant_name'                  => 'AUTH_KEY',
                'disclosure_version'             => '2026-04-22',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'sentient_forms_disallowed_secret_constant', $response->get_data()['code'] );
        $this->assertSame( 0, $external_call_count );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $this->assertSame( [], $credentials->list() );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $this->assertNull( $consents->latest_for_provider( 'openrouter' ) );
    }

    /**
     * Database constants should get the explicit WordPress credential rejection path.
     */
    public function test_save_openrouter_constant_rejects_database_secret_with_specific_error(): void
    {
        $external_call_count = 0;
        $this->mock_openrouter_key_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/constant' ) );
        $request->set_body_params(
            [
                'constant_name'                  => 'DB_HOST',
                'disclosure_version'             => '2026-04-22',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_forms_disallowed_secret_constant', $data['code'] );
        $this->assertStringContainsString( 'database credentials', $data['message'] );
        $this->assertSame( 0, $external_call_count );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $this->assertSame( [], $credentials->list() );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $this->assertNull( $consents->latest_for_provider( 'openrouter' ) );
    }

    /**
     * Arbitrary OpenRouter-looking names must still use the plugin-owned prefix.
     */
    public function test_save_openrouter_constant_requires_sentient_forms_openrouter_prefix(): void
    {
        $constant_name = 'OPENROUTER_API_KEY';
        putenv( $constant_name . '=sk-or-prefix-should-not-be-used' );

        $external_call_count = 0;
        $this->mock_openrouter_key_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        try
        {
            $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/constant' ) );
            $request->set_body_params(
                [
                    'constant_name'                  => $constant_name,
                    'disclosure_version'             => '2026-04-22',
                    'accepted_external_service_terms' => true,
                ]
            );

            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 400, $response->get_status() );
            $this->assertSame( 'sentient_forms_disallowed_secret_constant', $response->get_data()['code'] );
            $this->assertSame( 0, $external_call_count );

            $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
            $this->assertSame( [], $credentials->list() );

            $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
            $this->assertNull( $consents->latest_for_provider( 'openrouter' ) );
        }
        finally
        {
            putenv( $constant_name );
        }
    }

    /**
     * Legacy rows that reference disallowed constants stay visible but unconfigured.
     */
    public function test_list_credentials_marks_disallowed_constant_unconfigured(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $credential_id = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Legacy unsafe server secret',
                'auth_mode'         => 'constant',
                'constant_name'     => 'AUTH_SALT',
                'status'            => 'valid',
                'status_json'       => [ 'label' => 'legacy' ],
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        $list_request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/credentials' );
        $list_response = rest_get_server()->dispatch( $list_request );

        $this->assertSame( 200, $list_response->get_status() );
        $list_data = $list_response->get_data();
        $this->assertCount( 1, $list_data );
        $this->assertSame( 'AUTH_SALT', $list_data[0]['constant_name'] );
        $this->assertFalse( $list_data[0]['secret_configured'] );
    }

    public function test_delete_credential_removes_saved_openrouter_key_without_secret_leak(): void
    {
        $secret      = 'sk-or-delete-secret';
        $vault       = new Sentient_Forms_Provider_Credential_Vault();
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );

        $encrypted = $vault->encrypt( $secret );
        $this->assertIsString( $encrypted );

        $id = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Delete Me',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted,
                'status'            => 'valid',
                'status_json'       => [ 'label' => 'delete me' ],
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['deleted'] );
        $this->assertSame( $id, $data['credential']['id'] );
        $this->assertSame( 'Delete Me', $data['credential']['label'] );
        $this->assertArrayNotHasKey( 'encrypted_secret', $data['credential'] );
        $this->assertStringNotContainsString( $secret, wp_json_encode( $data ) );
        $this->assertNull( $credentials->get( $id ) );
    }

    public function test_delete_credential_returns_not_found_for_missing_row(): void
    {
        $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/999999' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'sentient_forms_credential_not_found', $response->get_data()['code'] );
    }

    public function test_list_credentials_redacts_secret_like_status_values(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $credentials->create(
            [
                'provider'    => 'openrouter',
                'label'       => 'Status leak',
                'auth_mode'   => 'constant',
                'status'      => 'invalid',
                'status_json' => [
                    'diagnostic_hint' => 'provider echoed sk-or-status-leak-123456',
                    'request_note'    => 'provider echoed Authorization: Bearer provider-bearer-token-123456',
                    'authorization'   => 'Bearer provider-authorization-token-123456',
                    'encrypted_blob'  => 'encrypted provider diagnostic payload',
                ],
            ]
        );

        $this->assertIsInt( $id );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/credentials' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $json = (string) wp_json_encode( $response->get_data() );
        $this->assertStringNotContainsString( 'sk-or-status-leak-123456', $json );
        $this->assertStringNotContainsString( 'provider-bearer-token-123456', $json );
        $this->assertStringNotContainsString( 'provider-authorization-token-123456', $json );
        $this->assertStringNotContainsString( 'encrypted provider diagnostic payload', $json );
        $this->assertStringContainsString( 'sk-or-[redacted]', $json );
        $this->assertStringContainsString( 'Bearer [redacted]', $json );
    }

    public function test_validate_openrouter_key_requires_consent_before_external_call(): void
    {
        $external_call_count = 0;
        $this->mock_openrouter_key_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/validate' ) );
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
                'openai/gpt-5.5',
                [
                    'id'     => 'openai/gpt-5.5',
                    'name'   => 'OpenAI: GPT-5.5',
                    'free'   => false,
                    'pricing' => [
                        'prompt'     => '0.000005',
                        'completion' => '0.00003',
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

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/models/refresh' ) );
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
                if ( str_contains( $url, 'zdr=true' ) )
                {
                    $this->assertStringContainsString( '/models?zdr=true', $url );
                }
                else
                {
                    $this->assertStringContainsString( '/models?output_modalities=text', $url );
                }
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
            }
        );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/models/refresh' ) );
        $request->set_body_params(
            [
                'disclosure_version'              => '2026-04-18',
                'accepted_external_service_terms' => true,
                'output_modalities'               => 'text',
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 2, $external_call_count );

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

    public function test_refresh_openrouter_models_cross_references_zdr_filtered_catalog(): void
    {
        $calls = [];
        $this->mock_openrouter_models_response(
            function ( array $args, string $url ) use ( &$calls ): ?array {
                $calls[] = $url;
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] );

                if ( str_contains( $url, 'zdr=true' ) )
                {
                    return [
                        'headers'  => [ 'content-type' => 'application/json' ],
                        'body'     => file_get_contents( dirname( __DIR__ ) . '/fixtures/openrouter/models-zdr-success.json' ),
                        'response' => [
                            'code'    => 200,
                            'message' => 'OK',
                        ],
                        'cookies'  => [],
                    ];
                }

                return null;
            }
        );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/openrouter/models/refresh' ) );
        $request->set_body_params(
            [
                'disclosure_version'              => '2026-04-18',
                'accepted_external_service_terms' => true,
                'output_modalities'               => 'text',
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 2, $calls );
        $this->assertStringContainsString( '/models?output_modalities=text', $calls[0] );
        $this->assertStringContainsString( '/models?zdr=true', $calls[1] );

        $data = $response->get_data();
        $models_by_id = [];
        foreach ( $data['models'] as $model )
        {
            $models_by_id[ $model['id'] ] = $model;
        }

        $this->assertTrue( $models_by_id['openai/gpt-5.5']['zdr_eligible'] );
        $this->assertSame( 'openrouter_models_zdr_filter', $models_by_id['openai/gpt-5.5']['zdr_source'] );
        $this->assertIsString( $models_by_id['openai/gpt-5.5']['zdr_checked_at'] );
        $this->assertContains( 'zdr', $models_by_id['openai/gpt-5.5']['tags'] );

        $this->assertFalse( $models_by_id['openai/gpt-oss-20b:free']['zdr_eligible'] );
        $this->assertSame( 'openrouter_models_zdr_filter', $models_by_id['openai/gpt-oss-20b:free']['zdr_source'] );
        $this->assertNotContains( 'zdr', $models_by_id['openai/gpt-oss-20b:free']['tags'] );

        $models = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $zdr_model = $models->get( 'openrouter', 'openai/gpt-5.5' );
        $this->assertIsArray( $zdr_model );
        $this->assertTrue( $zdr_model['metadata_json']['zdr_eligible'] );
        $this->assertSame( 'openrouter_models_zdr_filter', $zdr_model['metadata_json']['zdr_source'] );
    }

    public function test_setup_sentient_managed_requires_consent_before_local_writes(): void
    {
        $this->set_active_managed_license();

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/sentient-managed/setup' ) );
        $request->set_body_params(
            [
                'label'                           => 'Sentient Forms managed service',
                'disclosure_version'              => '2026-04-sentient-managed-proxy-v1',
                'accepted_external_service_terms' => false,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $this->assertSame( [], $credentials->list() );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $this->assertNull( $consents->latest_for_provider( 'sentient_managed' ) );
    }

    public function test_setup_sentient_managed_requires_active_managed_account(): void
    {
        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/sentient-managed/setup' ) );
        $request->set_body_params(
            [
                'label'                           => 'Sentient Forms managed service',
                'disclosure_version'              => '2026-04-sentient-managed-proxy-v1',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_forms_sentient_managed_account_required', $data['code'] );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $this->assertSame( [], $credentials->list() );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $this->assertNull( $consents->latest_for_provider( 'sentient_managed' ) );
    }

    public function test_setup_sentient_managed_creates_proxy_credential_and_consent_without_key_leak(): void
    {
        $this->set_active_managed_license();
        $secret = 'proxy-secret-managed';

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/sentient-managed/setup' ) );
        $request->set_body_params(
            [
                'label'                           => 'Primary Sentient Forms managed service',
                'disclosure_version'              => '2026-04-sentient-managed-proxy-v1',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'sentient_managed', $data['provider'] );
        $this->assertSame( 'valid', $data['status'] );
        $this->assertIsInt( $data['credential_id'] );
        $this->assertTrue( $data['consent_recorded'] );
        $this->assertSame( 'license-managed-test', $data['account']['license_id'] );
        $this->assertSame( '11111111-1111-4111-8111-111111111111', $data['account']['site_id'] );
        $this->assertTrue( $data['account']['proxy_key_present'] );
        $this->assertTrue( $data['billing_boundary']['managed_proxy_billed_by_sentient'] );
        $this->assertFalse( $data['billing_boundary']['direct_openrouter_billed_by_sentient'] );
        $this->assertStringNotContainsString( $secret, wp_json_encode( $data ) );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $row         = $credentials->get( $data['credential_id'] );

        $this->assertIsArray( $row );
        $this->assertSame( 'sentient_managed', $row['provider'] );
        $this->assertSame( 'sentient_proxy', $row['auth_mode'] );
        $this->assertSame( 'valid', $row['status'] );
        $this->assertEmpty( $row['encrypted_secret'] );
        $this->assertSame( 'license-managed-test', $row['status_json']['license_id'] );
        $this->assertSame( '11111111-1111-4111-8111-111111111111', $row['status_json']['site_id'] );
        $this->assertTrue( $row['status_json']['proxy_key_present'] );

        $list_request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/credentials' );
        $list_response = rest_get_server()->dispatch( $list_request );
        $this->assertSame( 200, $list_response->get_status() );
        $list_data = $list_response->get_data();
        $this->assertCount( 1, $list_data );
        $this->assertSame( 'sentient_managed', $list_data[0]['provider'] );
        $this->assertSame( 'sentient_proxy', $list_data[0]['auth_mode'] );
        $this->assertTrue( $list_data[0]['secret_configured'] );
        $this->assertArrayNotHasKey( 'encrypted_secret', $list_data[0] );
        $this->assertStringNotContainsString( $secret, wp_json_encode( $list_data ) );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $latest   = $consents->latest_for_provider( 'sentient_managed' );
        $this->assertIsArray( $latest );
        $this->assertSame( '2026-04-sentient-managed-proxy-v1', $latest['disclosure_version'] );
        $this->assertSame( 'setup_managed_proxy', $latest['metadata_json']['action'] );
        $this->assertTrue( $latest['metadata_json']['managed_proxy_selected'] );
    }

    public function test_setup_sentient_managed_reuses_existing_proxy_credential(): void
    {
        $this->set_active_managed_license();

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );

        $first_id = $credentials->create(
            [
                'provider'          => 'sentient_managed',
                'label'             => 'Existing managed service',
                'auth_mode'         => 'sentient_proxy',
                'status'            => 'disabled',
                'status_json'       => [ 'proxy_key_present' => false ],
                'last_validated_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
            ]
        );
        $this->assertIsInt( $first_id );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'POST', '/sentient-forms/v1/local/providers/sentient-managed/setup' ) );
        $request->set_body_params(
            [
                'label'                           => 'Updated label is not needed for existing proxy',
                'disclosure_version'              => '2026-04-sentient-managed-proxy-v1',
                'accepted_external_service_terms' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( $first_id, $data['credential_id'] );

        $rows = $credentials->list();
        $this->assertCount( 1, $rows );
        $this->assertSame( 'valid', $rows[0]['status'] );
        $this->assertSame( 'license-managed-test', $rows[0]['status_json']['license_id'] );
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
                $response = $on_request( $args, $url );
                if ( is_array( $response ) )
                {
                    return $response;
                }
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
                $response = $on_request( $args, $url );
                if ( is_array( $response ) )
                {
                    return $response;
                }
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

    private function set_active_managed_license(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_key'           => 'LIC-MANAGED-TEST',
                'license_status'        => 'active',
                'license_id'            => 'license-managed-test',
                'site_id'               => '11111111-1111-4111-8111-111111111111',
                'proxy_api_key'         => 'proxy-secret-managed',
                'tier'                  => 'starter',
                'expiry_date'           => '2030-01-01',
                'last_synced'           => current_time( 'mysql' ),
                'local_site_identifier' => 'local-managed-test',
            ]
        );
        update_option(
            'sentient_forms_settings',
            array_merge(
                Sentient_Forms_Plugin::instance()->get_options(),
                [ 'enforce_nonce_verification' => false ]
            )
        );
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
