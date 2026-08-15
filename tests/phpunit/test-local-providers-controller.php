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
        wp_clear_scheduled_hook( 'sentient_forms_openrouter_model_catalog_refresh' );
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
        wp_clear_scheduled_hook( 'sentient_forms_openrouter_model_catalog_refresh' );
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

        $this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
        $data = $response->get_data();
        $this->assertTrue( $data['deleted'] );
        $this->assertSame( $id, $data['credential']['id'] );
        $this->assertSame( 'Delete Me', $data['credential']['label'] );
        $this->assertArrayNotHasKey( 'encrypted_secret', $data['credential'] );
        $this->assertStringNotContainsString( $secret, wp_json_encode( $data ) );
        $this->assertNull( $credentials->get( $id ) );
    }

    public function test_delete_credential_refuses_an_active_mapping_reference(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $mappings    = new Sentient_Forms_Form_Mappings_Repository( $GLOBALS['wpdb'] );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Referenced OpenRouter key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => 'credential-delete-guard',
                'hook'                => 'validation',
                'action_kind'         => 'custom_action',
                'action_id'           => 991001,
                'input_bindings_json' => [],
                'execution_mode'      => 'sync',
                'settings_json'       => [
                    'model_selection' => [
                        'provider'      => 'openrouter',
                        'credential_id' => $id,
                    ],
                ],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 409, $response->get_status() );
            $data = $response->get_data();
            $this->assertSame( 'sentient_forms_credential_in_use', $data['code'] );
            $this->assertSame( $mapping_id, $data['data']['references'][0]['id'] ?? null );
            $this->assertSame( 'form_mapping', $data['data']['references'][0]['type'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $mappings->delete( $mapping_id );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_an_active_mapping_backup_reference(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $mappings    = new Sentient_Forms_Form_Mappings_Repository( $GLOBALS['wpdb'] );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Referenced mapping fallback key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => 'credential-delete-backup-guard',
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => 991002,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'settings_json'       => [
                    'model_selection' => [
                        'provider'             => 'sentient_managed',
                        'backup_credential_id' => (string) $id,
                    ],
                ],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 409 );

            $this->assertSame( 'sentient_forms_credential_in_use', $data['code'] ?? null );
            $this->assertSame( $mapping_id, $data['data']['references'][0]['id'] ?? null );
            $this->assertSame( 'form_mapping', $data['data']['references'][0]['type'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $mappings->delete( $mapping_id );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_custom_action_definition_and_backup_references(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $actions     = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Referenced custom Action key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $action_id = $actions->create(
            [
                'code'                 => 'credential_delete_guard_' . wp_generate_password( 8, false ),
                'display_name'         => 'Credential delete guard Action',
                'definition_json'      => [
                    'provider'      => 'openrouter',
                    'credential_id' => $id,
                ],
                'model_selection_json' => [
                    'provider'             => 'sentient_managed',
                    'backup_credential_id' => (string) $id,
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 409, $response->get_status() );
            $data = $response->get_data();
            $this->assertSame( 'sentient_forms_credential_in_use', $data['code'] );
            $this->assertSame( $action_id, $data['data']['references'][0]['id'] ?? null );
            $this->assertSame( 'custom_action', $data['data']['references'][0]['type'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->delete( $wpdb->prefix . 'sentient_custom_actions', [ 'id' => $action_id ], [ '%d' ] );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_malformed_custom_action_authority_json(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $actions     = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $id          = $this->create_delete_guard_credential( $credentials, 'Malformed Action authority key' );
        $action_id   = $actions->create(
            [
                'code'                 => 'malformed_authority_' . wp_generate_password( 8, false ),
                'display_name'         => 'Malformed authority fixture',
                'definition_json'      => [ 'provider' => 'openrouter' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );
        $wpdb->update(
            $wpdb->prefix . 'sentient_custom_actions',
            [ 'model_selection_json' => '{malformed' ],
            [ 'id' => $action_id ],
            [ '%s' ],
            [ '%d' ]
        );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $data['code'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );

            $wpdb->update(
                $wpdb->prefix . 'sentient_custom_actions',
                [ 'model_selection_json' => 'null' ],
                [ 'id' => $action_id ],
                [ '%s' ],
                [ '%d' ]
            );
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $data['code'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->delete( $wpdb->prefix . 'sentient_custom_actions', [ 'id' => $action_id ], [ '%d' ] );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_malformed_mapping_authority_json(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $mappings    = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $id          = $this->create_delete_guard_credential( $credentials, 'Malformed mapping authority key' );
        $mapping_id  = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => 'malformed-authority',
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => 991003,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'settings_json'       => [ 'model_selection' => [ 'provider' => 'openrouter' ] ],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );
        $wpdb->update(
            $wpdb->prefix . 'sentient_form_mappings',
            [ 'settings_json' => '{malformed' ],
            [ 'id' => $mapping_id ],
            [ '%s' ],
            [ '%d' ]
        );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $data['code'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );

            $wpdb->update(
                $wpdb->prefix . 'sentient_form_mappings',
                [ 'settings_json' => 'null' ],
                [ 'id' => $mapping_id ],
                [ '%s' ],
                [ '%d' ]
            );
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $data['code'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->delete( $wpdb->prefix . 'sentient_form_mappings', [ 'id' => $mapping_id ], [ '%d' ] );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_an_isolated_custom_action_definition_reference(): void
    {
        $this->assert_custom_action_reference_blocks_delete(
            [ 'credential_id' => '{credential_id}' ],
            [ 'provider' => 'openrouter' ]
        );
    }

    public function test_delete_credential_refuses_an_isolated_custom_action_primary_reference(): void
    {
        $this->assert_custom_action_reference_blocks_delete(
            [],
            [
                'provider'   => 'openrouter',
                'selection'  => [ 'credential_id' => '{credential_id}' ],
            ]
        );
    }

    public function test_delete_credential_refuses_an_isolated_custom_action_backup_reference(): void
    {
        $this->assert_custom_action_reference_blocks_delete(
            [],
            [
                'provider'             => 'sentient_managed',
                'backup_credential_id' => '{credential_id}',
            ]
        );
    }

    public function test_delete_credential_refuses_site_context_settings_and_active_job_references(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Referenced Site Context key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        update_option(
            'sentient_forms_site_context_settings',
            [
                'generation_model_selection' => [
                    'provider'      => 'openrouter',
                    'credential_id' => $id,
                ],
            ],
            false
        );
        update_option(
            'sentient_forms_site_context_generation_job',
            [
                'job_id'   => 'credential-delete-guard-job',
                'status'   => 'queued',
                'settings' => [
                    'generation_model_selection' => [
                        'provider'      => 'openrouter',
                        'credential_id' => (string) $id,
                    ],
                ],
            ],
            false
        );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 409, $response->get_status() );
            $data       = $response->get_data();
            $references = $data['data']['references'] ?? [];
            $types      = wp_list_pluck( $references, 'type' );

            $this->assertSame( 'sentient_forms_credential_in_use', $data['code'] );
            $this->assertContains( 'site_context_settings', $types );
            $this->assertContains( 'site_context_generation_job', $types );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            delete_option( 'sentient_forms_site_context_settings' );
            delete_option( 'sentient_forms_site_context_generation_job' );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_an_isolated_site_context_settings_reference(): void
    {
        $this->assert_site_context_option_reference_blocks_delete(
            'sentient_forms_site_context_settings',
            [
                'generation_model_selection' => [
                    'provider'      => 'openrouter',
                    'credential_id' => '{credential_id}',
                ],
            ],
            'site_context_settings'
        );
    }

    public function test_delete_credential_refuses_an_isolated_active_site_context_job_reference(): void
    {
        $this->assert_site_context_option_reference_blocks_delete(
            'sentient_forms_site_context_generation_job',
            [
                'status'   => 'running',
                'settings' => [
                    'generation_model_selection' => [
                        'provider'      => 'openrouter',
                        'credential_id' => '{credential_id}',
                    ],
                ],
            ],
            'site_context_generation_job'
        );
    }

    public function test_delete_credential_refuses_a_pending_local_mapping_execution(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Queued execution key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $payload = [
            'execution_request_id' => 'credential-delete-guard-' . wp_generate_uuid4(),
            'extended_args_fixture' => str_repeat( 'x', 300 ),
            'context'              => [
                'settings' => [
                    'model_selection' => [
                        'provider'      => 'openrouter',
                        'credential_id' => $id,
                    ],
                ],
            ],
        ];
        $action_id = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
            [ $payload ],
            'sentient_forms_async'
        );
        $this->assertIsInt( $action_id );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 409, $response->get_status() );
            $data       = $response->get_data();
            $references = $data['data']['references'] ?? [];

            $this->assertSame( 'sentient_forms_credential_in_use', $data['code'] );
            $this->assertSame( 'scheduled_execution', $references[0]['type'] ?? null );
            $this->assertSame( $action_id, $references[0]['id'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            as_unschedule_action( Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK, [ $payload ], 'sentient_forms_async' );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_a_durable_queued_execution_after_scheduler_dequeue(): void
    {
        global $wpdb;

        $credentials  = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id           = $this->create_delete_guard_credential( $credentials, 'Durable queued execution key' );
        $request_hash = 'credential-delete-durable-' . wp_generate_uuid4();
        $recorded     = ( new Sentient_Forms_Async_Request_Store( $wpdb ) )->record(
            $request_hash,
            [
                'action_id'        => 'local_mapping_991',
                'adapter'          => 'gravity_forms',
                'status'           => 'queued',
                'payload_digest'   => hash( 'sha256', $request_hash ),
                'authority_payload' => [
                    'credentials' => [ [ 'credential_id' => $id ] ],
                ],
            ]
        );
        $this->assertTrue( $recorded );

        try
        {
            $data       = $this->dispatch_credential_delete( $id, 409 );
            $references = $data['data']['references'] ?? [];
            $this->assertContains( 'queued_execution', wp_list_pluck( $references, 'type' ) );
            $this->assertContains( $request_hash, wp_list_pluck( $references, 'id' ) );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->delete(
                $wpdb->prefix . 'sentient_async_requests',
                [ 'request_hash' => $request_hash ],
                [ '%s' ]
            );
            $credentials->delete( $id );
        }
    }

    public function test_queued_execution_admission_rejects_a_deleted_credential(): void
    {
        global $wpdb;

        $credentials  = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id           = $this->create_delete_guard_credential( $credentials, 'Stale queued execution key' );
        $request_hash = 'credential-delete-stale-queue-' . wp_generate_uuid4();
        $this->assertTrue( $credentials->delete( $id ) );

        $recorded = ( new Sentient_Forms_Async_Request_Store( $wpdb ) )->record(
            $request_hash,
            [
                'action_id'         => 'local_mapping_993',
                'adapter'           => 'gravity_forms',
                'status'            => 'queued',
                'payload_digest'    => hash( 'sha256', $request_hash ),
                'authority_payload' => [
                    'credentials' => [ [ 'credential_id' => $id ] ],
                ],
            ]
        );

        $this->assertInstanceOf( WP_Error::class, $recorded );
        $this->assertSame( 'sentient_forms_async_authority_credential_unavailable', $recorded->get_error_code() );
        $this->assertNull( ( new Sentient_Forms_Async_Request_Store( $wpdb ) )->get( $request_hash ) );
    }

    public function test_delete_credential_refuses_an_active_synchronous_execution(): void
    {
        global $wpdb;

        $credentials  = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id           = $this->create_delete_guard_credential( $credentials, 'Active synchronous execution key' );
        $request_hash = 'active-sync-' . wp_generate_uuid4();
        $store        = new Sentient_Forms_Async_Request_Store( $wpdb );
        $claimed      = $store->claim_execution(
            $request_hash,
            [
                'action_id'         => 'local_mapping_994',
                'adapter'           => 'gravity_forms',
                'payload_digest'    => hash( 'sha256', $request_hash ),
                'authority_payload' => [
                    'credentials' => [ [ 'credential_id' => $id ] ],
                ],
            ]
        );
        $this->assertSame( 'claimed', $claimed['state'] ?? null );

        try
        {
            $data       = $this->dispatch_credential_delete( $id, 409 );
            $references = $data['data']['references'] ?? [];
            $this->assertContains( 'active_execution', wp_list_pluck( $references, 'type' ) );
            $this->assertContains( $request_hash, wp_list_pluck( $references, 'id' ) );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $store->finish_execution( $request_hash, 'success', null, 'accepted_sync' );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_missing_durable_authority_payload(): void
    {
        global $wpdb;

        $credentials  = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id           = $this->create_delete_guard_credential( $credentials, 'Legacy durable execution key' );
        $request_hash = 'credential-delete-missing-authority-' . wp_generate_uuid4();
        $recorded     = ( new Sentient_Forms_Async_Request_Store( $wpdb ) )->record(
            $request_hash,
            [
                'action_id'      => 'local_mapping_992',
                'adapter'        => 'gravity_forms',
                'status'         => 'queued',
                'payload_digest' => hash( 'sha256', $request_hash ),
            ]
        );
        $this->assertTrue( $recorded );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $data['code'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->delete(
                $wpdb->prefix . 'sentient_async_requests',
                [ 'request_hash' => $request_hash ],
                [ '%s' ]
            );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_malformed_durable_authority_payload(): void
    {
        global $wpdb;

        $credentials  = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id           = $this->create_delete_guard_credential( $credentials, 'Malformed durable execution key' );
        $request_hash = 'malformed-authority-' . wp_generate_uuid4();
        $recorded     = ( new Sentient_Forms_Async_Request_Store( $wpdb ) )->record(
            $request_hash,
            [
                'action_id'         => 'local_mapping_993',
                'adapter'           => 'gravity_forms',
                'status'            => 'running',
                'payload_digest'    => hash( 'sha256', $request_hash ),
                'authority_payload' => [ 'credentials' => [] ],
            ]
        );
        $this->assertTrue( $recorded );
        $this->assertNotFalse(
            $wpdb->update(
                $wpdb->prefix . 'sentient_async_requests',
                [ 'telemetry_payload' => wp_json_encode( 'malformed-scalar' ) ],
                [ 'request_hash' => $request_hash ],
                [ '%s' ],
                [ '%s' ]
            )
        );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $data['code'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->delete(
                $wpdb->prefix . 'sentient_async_requests',
                [ 'request_hash' => $request_hash ],
                [ '%s' ]
            );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_nontransactional_async_authority_store(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id          = $this->create_delete_guard_credential( $credentials, 'Nontransactional async authority' );
        $table       = $wpdb->prefix . 'sentient_async_requests';
        $this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) ) );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_nontransactional_credential_reference_store', $data['code'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_nontransactional_options_authority_store(): void
    {
        global $wpdb;

        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id             = $this->create_delete_guard_credential( $credentials, 'Nontransactional options authority' );
        $original_table = $wpdb->options;
        $fixture_table  = $wpdb->prefix . 'options_myisam_credential_guard_fixture';
        $this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $fixture_table ) ) );
        $this->assertNotFalse(
            $wpdb->query(
                $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $fixture_table, $original_table )
            )
        );
        $this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $fixture_table ) ) );
        $wpdb->options = $fixture_table;

        try
        {
            $service = new Sentient_Forms_Local_Action_Model_Selection_Service();
            $result  = $service->delete_credential_if_unreferenced( $id );
            $this->assertWPError( $result );
            $this->assertSame( 'sentient_forms_nontransactional_credential_reference_store', $result->get_error_code() );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->options = $original_table;
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $fixture_table ) );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_nontransactional_action_scheduler_store(): void
    {
        global $wpdb;

        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id             = $this->create_delete_guard_credential( $credentials, 'Nontransactional scheduler authority' );
        $original_table = $wpdb->actionscheduler_actions;
        $fixture_table  = $wpdb->prefix . 'actionscheduler_actions_myisam_credential_guard_fixture';
        $this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $fixture_table ) ) );
        $this->assertNotFalse(
            $wpdb->query(
                $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $fixture_table, $original_table )
            )
        );
        $this->assertNotFalse( $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $fixture_table ) ) );
        $wpdb->actionscheduler_actions = $fixture_table;

        try
        {
            $service = new Sentient_Forms_Local_Action_Model_Selection_Service();
            $result  = $service->delete_credential_if_unreferenced( $id );
            $this->assertWPError( $result );
            $this->assertSame( 'sentient_forms_nontransactional_credential_reference_store', $result->get_error_code() );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->actionscheduler_actions = $original_table;
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $fixture_table ) );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_a_wp_cron_fallback_execution(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'WP-Cron fallback execution key' );
        $payload     = $this->local_mapping_payload_with_credential( $id );
        $timestamp   = time() + HOUR_IN_SECONDS;
        $scheduled   = wp_schedule_single_event(
            $timestamp,
            Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
            [ $payload ]
        );
        $this->assertTrue( $scheduled );

        try
        {
            $data       = $this->dispatch_credential_delete( $id, 409 );
            $references = $data['data']['references'] ?? [];
            $this->assertContains( 'wp_cron_execution', wp_list_pluck( $references, 'type' ) );
            $this->assertContains( (string) $timestamp, wp_list_pluck( $references, 'id' ) );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            wp_unschedule_event(
                $timestamp,
                Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
                [ $payload ]
            );
            $credentials->delete( $id );
        }
    }

    public function test_wp_cron_credential_reference_scan_fails_closed_for_malformed_authority(): void
    {
        $service = new Sentient_Forms_Local_Action_Model_Selection_Service();
        $method  = new ReflectionMethod( $service, 'wp_cron_execution_credential_references' );
        $previous_cron = get_option( 'cron', null );
        update_option( 'cron', 'malformed-cron-authority', false );

        try
        {
            $result = $method->invoke( $service, 123 );
            $this->assertWPError( $result );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $result->get_error_code() );
        }
        finally
        {
            if ( null === $previous_cron )
            {
                delete_option( 'cron' );
            }
            else
            {
                update_option( 'cron', $previous_cron, false );
            }
        }
    }

    public function test_wp_cron_credential_reference_scan_fails_closed_for_malformed_target_event(): void
    {
        $service = new Sentient_Forms_Local_Action_Model_Selection_Service();
        $method  = new ReflectionMethod( $service, 'wp_cron_execution_credential_references' );
        $previous_cron = get_option( 'cron', null );
        update_option(
            'cron',
            [
                time() + HOUR_IN_SECONDS => [
                    Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK => [
                        'malformed-event' => [ 'args' => 'not-an-array' ],
                    ],
                ],
                'version' => 2,
            ],
            false
        );

        try
        {
            $result = $method->invoke( $service, 123 );
            $this->assertWPError( $result );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $result->get_error_code() );
        }
        finally
        {
            if ( null === $previous_cron )
            {
                delete_option( 'cron' );
            }
            else
            {
                update_option( 'cron', $previous_cron, false );
            }
        }
    }

    public function test_scheduler_authority_distinguishes_wp_cron_only_from_broken_action_scheduler(): void
    {
        $service = new Sentient_Forms_Local_Action_Model_Selection_Service();
        $method  = new ReflectionMethod( $service, 'action_scheduler_authority_state' );

        $this->assertSame( 'wp_cron_only', $method->invoke( $service, false, false ) );
        $this->assertSame( 'unavailable', $method->invoke( $service, false, true ) );
        $this->assertSame( 'ready', $method->invoke( $service, true, false ) );
        $this->assertSame( 'ready', $method->invoke( $service, true, true ) );
    }

    public function test_scheduler_table_remains_authority_without_runtime_symbols(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Dormant scheduler authority key' );
        $payload     = $this->local_mapping_payload_with_credential( $id );
        $action_id   = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
            [ $payload ],
            'sentient_forms_async'
        );
        $this->assertIsInt( $action_id );

        try
        {
            $service = new Sentient_Forms_Local_Action_Model_Selection_Service();
            $method  = new ReflectionMethod( $service, 'scheduled_execution_credential_references' );
            $method->setAccessible( true );
            $references = $method->invoke( $service, $id, false, false );

            $this->assertIsArray( $references );
            $this->assertSame( $action_id, $references[0]['id'] ?? null );
            $this->assertSame( 'scheduled_execution', $references[0]['type'] ?? null );
            $this->assertSame( 'pending', $references[0]['status'] ?? null );
        }
        finally
        {
            ActionScheduler::store()->delete_action( $action_id );
            $credentials->delete( $id );
        }
    }

    public function test_missing_action_scheduler_table_fails_closed_when_runtime_is_active(): void
    {
        global $wpdb;

        $service       = new Sentient_Forms_Local_Action_Model_Selection_Service();
        $method        = new ReflectionMethod( $service, 'scheduled_execution_credential_references' );
        $original_table = $wpdb->actionscheduler_actions;
        $wpdb->actionscheduler_actions = $wpdb->prefix . 'missing_actionscheduler_authority';

        try
        {
            $result = $method->invoke( $service, 123, true, true );
            $this->assertWPError( $result );
            $this->assertSame( 'sentient_forms_credential_reference_check_unavailable', $result->get_error_code() );
        }
        finally
        {
            $wpdb->actionscheduler_actions = $original_table;
        }
    }

    public function test_unreadable_action_scheduler_schema_fails_closed_without_runtime_symbols(): void
    {
        global $wpdb;

        $service = new Sentient_Forms_Local_Action_Model_Selection_Service();
        $method  = new ReflectionMethod( $service, 'scheduled_execution_credential_references' );
        $fail_schema_read = static function ( string $query ): string {
            return str_starts_with( trim( $query ), 'SELECT 1 FROM ' )
                ? 'SENTIENT FORMS FORCED SCHEDULER SCHEMA FAILURE'
                : $query;
        };
        add_filter( 'query', $fail_schema_read );
        $suppressed = $wpdb->suppress_errors( true );

        try
        {
            $result = $method->invoke( $service, 123, false, false );
            $this->assertInstanceOf( WP_Error::class, $result );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $result->get_error_code() );
        }
        finally
        {
            $wpdb->suppress_errors( $suppressed );
            remove_filter( 'query', $fail_schema_read );
        }
    }

    public function test_legacy_action_scheduler_table_without_extended_args_is_scanned(): void
    {
        global $wpdb;

        $service        = new Sentient_Forms_Local_Action_Model_Selection_Service();
        $method         = new ReflectionMethod( $service, 'scheduled_execution_credential_references' );
        $original_table = $wpdb->actionscheduler_actions;
        $fixture_table  = $wpdb->prefix . 'actionscheduler_actions_legacy_authority';
        $credential_id  = 123;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Isolated legacy-schema compatibility fixture.
        $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $fixture_table ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Isolated legacy-schema compatibility fixture.
        $created = $wpdb->query(
            $wpdb->prepare(
                'CREATE TABLE %i ('
                    . 'action_id bigint unsigned NOT NULL AUTO_INCREMENT, '
                    . 'hook varchar(191) NOT NULL, '
                    . 'status varchar(20) NOT NULL, '
                    . 'args longtext DEFAULT NULL, '
                    . 'PRIMARY KEY (action_id)'
                    . ') ENGINE=InnoDB',
                $fixture_table
            )
        );
        $this->assertNotFalse( $created );
        $inserted = $wpdb->insert(
            $fixture_table,
            [
                'hook'   => Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
                'status' => 'pending',
                'args'   => wp_json_encode( [ 'credential_id' => $credential_id ] ),
            ],
            [ '%s', '%s', '%s' ]
        );
        $this->assertSame( 1, $inserted );
        $action_id = (int) $wpdb->insert_id;
        $wpdb->actionscheduler_actions = $fixture_table;

        try
        {
            $stored = $wpdb->get_row(
                $wpdb->prepare( 'SELECT action_id, hook, status, args FROM %i WHERE action_id = %d', $fixture_table, $action_id ),
                ARRAY_A
            );
            $this->assertIsArray( $stored );
            $this->assertSame( $credential_id, json_decode( (string) ( $stored['args'] ?? '' ), true )['credential_id'] ?? null );
            $references = $method->invoke( $service, $credential_id, false, false );

            $this->assertIsArray( $references );
            $this->assertSame( $action_id, $references[0]['id'] ?? null );
            $this->assertSame( 'scheduled_execution', $references[0]['type'] ?? null );
            $this->assertSame( 'pending', $references[0]['status'] ?? null );
        }
        finally
        {
            $wpdb->actionscheduler_actions = $original_table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Isolated legacy-schema compatibility fixture cleanup.
            $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $fixture_table ) );
        }
    }

    public function test_non_transactional_action_scheduler_store_fails_closed(): void
    {
        $service        = new Sentient_Forms_Local_Action_Model_Selection_Service();
        $method         = new ReflectionMethod( $service, 'scheduled_execution_credential_references' );
        $store_property = new ReflectionProperty( ActionScheduler_Store::class, 'store' );
        $original_store = $store_property->getValue();
        $store_property->setValue( null, new ActionScheduler_wpPostStore() );

        try
        {
            $result = $method->invoke( $service, 123, true, true );
            $this->assertWPError( $result );
            $this->assertSame( 'sentient_forms_credential_reference_check_unavailable', $result->get_error_code() );
        }
        finally
        {
            $store_property->setValue( null, $original_store );
        }
    }

    public function test_delete_credential_refuses_a_pending_evaluation_execution(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Pending evaluation key' );
        $payload     = $this->local_mapping_payload_with_credential( $id );
        $action_id   = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            'sentient_forms_evaluate_action',
            [ $payload ],
            'sentient_forms_async'
        );
        $this->assertIsInt( $action_id );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 409 );
            $this->assertContains( $action_id, wp_list_pluck( $data['data']['references'] ?? [], 'id' ) );
        }
        finally
        {
            ActionScheduler::store()->delete_action( $action_id );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_a_running_local_mapping_execution(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Running execution key' );
        $payload     = $this->local_mapping_payload_with_credential( $id );
        $action_id   = as_schedule_single_action(
            time() - MINUTE_IN_SECONDS,
            Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
            [ $payload ],
            'sentient_forms_async'
        );
        $this->assertIsInt( $action_id );

        ActionScheduler::store()->log_execution( $action_id );
        $this->assertSame( ActionScheduler_Store::STATUS_RUNNING, ActionScheduler::store()->get_status( $action_id ) );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 409 );
            $this->assertSame( 'scheduled_execution', $data['data']['references'][0]['type'] ?? null );
            $this->assertSame( 'in-progress', $data['data']['references'][0]['status'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            ActionScheduler::store()->delete_action( $action_id );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_scans_the_scheduler_table_without_a_runtime_table_property(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id          = $this->create_delete_guard_credential( $credentials, 'Uninitialized scheduler key' );
        $payload     = $this->local_mapping_payload_with_credential( $id );
        $action_id   = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
            [ $payload ],
            'sentient_forms_async'
        );
        $this->assertIsInt( $action_id );
        $actions_table = $wpdb->actionscheduler_actions;
        $wpdb->actionscheduler_actions = '';

        try
        {
            $data = $this->dispatch_credential_delete( $id, 409 );
            $this->assertContains( $action_id, wp_list_pluck( $data['data']['references'] ?? [], 'id' ) );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->actionscheduler_actions = $actions_table;
            ActionScheduler::store()->delete_action( $action_id );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_scans_a_second_scheduler_page(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Second scheduler page key' );
        $action_ids  = [];

        try
        {
            for ( $index = 0; $index < 100; $index++ )
            {
                $action_ids[] = as_schedule_single_action(
                    time() + HOUR_IN_SECONDS,
                    Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
                    [ [ 'context' => [ 'index' => $index ] ] ],
                    'sentient_forms_async'
                );
            }
            $action_ids[] = as_schedule_single_action(
                time() + HOUR_IN_SECONDS,
                Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
                [ $this->local_mapping_payload_with_credential( $id ) ],
                'sentient_forms_async'
            );

            $data       = $this->dispatch_credential_delete( $id, 409 );
            $references = $data['data']['references'] ?? [];
            $this->assertContains( end( $action_ids ), wp_list_pluck( $references, 'id' ) );
        }
        finally
        {
            foreach ( $action_ids as $action_id )
            {
                if ( is_int( $action_id ) && $action_id > 0 )
                {
                    ActionScheduler::store()->delete_action( $action_id );
                }
            }
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_when_scheduler_authority_cannot_be_read(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id          = $this->create_delete_guard_credential( $credentials, 'Unreadable scheduler key' );
        $table       = $wpdb->actionscheduler_actions;
        $wpdb->actionscheduler_actions = $wpdb->options;
        $suppress_errors = $wpdb->suppress_errors( true );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 503, $response->get_status() );
            $this->assertSame(
                'sentient_forms_credential_reference_check_failed',
                $response->get_data()['code'] ?? null
            );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->actionscheduler_actions = $table;
            $wpdb->suppress_errors( $suppress_errors );
            $wpdb->last_error = '';
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_an_option_backed_action_default_reference(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Option-backed Action default key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $option_name = 'sentient_forms_action_defaults_credential_delete_guard';
        update_option(
            $option_name,
            [
                'model_selection' => [
                    'provider'             => 'sentient_managed',
                    'backup_credential_id' => (string) $id,
                ],
            ],
            false
        );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 409, $response->get_status() );
            $data       = $response->get_data();
            $references = $data['data']['references'] ?? [];

            $this->assertSame( 'sentient_forms_credential_in_use', $data['code'] );
            $this->assertContains( 'configuration_option', wp_list_pluck( $references, 'type' ) );
            $this->assertContains( $option_name, wp_list_pluck( $references, 'id' ) );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            delete_option( $option_name );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_fails_closed_for_a_non_array_configuration_option(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Malformed option guard key' );
        $option_name = 'sentient_forms_action_defaults_malformed_guard';
        update_option( $option_name, wp_json_encode( [ 'credential_id' => $id ] ), false );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 503 );
            $this->assertSame( 'sentient_forms_credential_reference_check_failed', $data['code'] ?? null );
            $this->assertSame( 'options', $data['data']['reference_source'] ?? null );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            delete_option( $option_name );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_refuses_a_legacy_zero_padded_runtime_id(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Zero-padded legacy key' );
        $option_name = 'sentient_forms_action_defaults_credential_delete_zero_padded';
        update_option(
            $option_name,
            [ 'model_selection' => [ 'credential_id' => '00' . $id ] ],
            false
        );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 409 );
            $this->assertContains( $option_name, wp_list_pluck( $data['data']['references'] ?? [], 'id' ) );
        }
        finally
        {
            delete_option( $option_name );
            $credentials->delete( $id );
        }
    }

    /**
     * @dataProvider credential_option_prefix_provider
     */
    public function test_delete_credential_refuses_each_option_backed_authority_prefix( string $option_prefix ): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Option authority key' );
        $option_name = $option_prefix . 'credential_delete_guard_' . wp_generate_password( 6, false );
        update_option(
            $option_name,
            [ 'model_selection' => [ 'credential_id' => (string) $id ] ],
            false
        );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 409 );
            $this->assertContains( sanitize_key( $option_name ), wp_list_pluck( $data['data']['references'] ?? [], 'id' ) );
        }
        finally
        {
            delete_option( $option_name );
            $credentials->delete( $id );
        }
    }

    public function credential_option_prefix_provider(): array
    {
        return [
            'form config'     => [ 'sentient_forms_form_config_' ],
            'action defaults' => [ 'sentient_forms_action_defaults_' ],
            'actions'         => [ 'sentient_forms_actions_' ],
            'gravity forms'   => [ 'sentient_forms_gravity_forms_' ],
        ];
    }

    public function test_delete_credential_allows_historical_jobs_and_nonsemantic_numeric_matches(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Deletable historical reference key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $option_name = 'sentient_forms_action_defaults_credential_delete_nonreference';
        update_option(
            $option_name,
            [
                'model_selection' => [
                    'credential_id' => $id . '-not-an-id',
                ],
            ],
            false
        );
        update_option(
            'sentient_forms_site_context_generation_job',
            [
                'status'   => 'completed',
                'settings' => [
                    'generation_model_selection' => [ 'credential_id' => $id ],
                ],
            ],
            false
        );

        $payload = [
            'context' => [
                'settings' => [
                    'model_selection' => [ 'credential_id' => $id ],
                ],
            ],
        ];
        $action_id = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
            [ $payload ],
            'sentient_forms_async'
        );
        $this->assertIsInt( $action_id );
        ActionScheduler::store()->mark_complete( $action_id );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 200, $response->get_status() );
            $this->assertNull( $credentials->get( $id ) );
        }
        finally
        {
            ActionScheduler::store()->delete_action( $action_id );
            delete_option( $option_name );
            delete_option( 'sentient_forms_site_context_generation_job' );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_rolls_back_when_storage_refuses_the_delete(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Rollback-protected key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $trigger_name      = $wpdb->prefix . 'sentient_test_credential_delete_guard';
        $credentials_table = $wpdb->prefix . 'sentient_provider_credentials';
        $wpdb->query( $wpdb->prepare( 'DROP TRIGGER IF EXISTS %i', $trigger_name ) );
        $created = $wpdb->query(
            $wpdb->prepare(
                'CREATE TRIGGER %i BEFORE DELETE ON %i FOR EACH ROW SIGNAL SQLSTATE %s SET MESSAGE_TEXT = %s',
                $trigger_name,
                $credentials_table,
                '45000',
                'forced credential delete failure'
            )
        );
        $this->assertNotFalse( $created );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 500, $response->get_status() );
            $data = $response->get_data();
            $this->assertSame( 'sentient_forms_db_delete_failed', $data['code'] );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->query( $wpdb->prepare( 'DROP TRIGGER IF EXISTS %i', $trigger_name ) );
            $credentials->delete( $id );
        }
    }

    public function test_delete_credential_ignores_nonruntime_definition_fields(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $actions     = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $id          = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Definition schema number key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-test-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );

        $action_id = $actions->create(
            [
                'code'            => 'credential_delete_nonruntime_definition_' . wp_generate_password( 8, false ),
                'display_name'    => 'Nonruntime definition field',
                'definition_json' => [
                    'structured_output_schema' => [
                        'properties' => [
                            'credential_id' => $id,
                        ],
                    ],
                ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        try
        {
            $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $id ) );
            $response = rest_get_server()->dispatch( $request );

            $this->assertSame( 200, $response->get_status() );
            $this->assertNull( $credentials->get( $id ) );
        }
        finally
        {
            $wpdb->delete( $wpdb->prefix . 'sentient_custom_actions', [ 'id' => $action_id ], [ '%d' ] );
            $credentials->delete( $id );
        }
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

    public function test_list_openrouter_models_filters_zdr_before_applying_limit(): void
    {
        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'aaa/non-zdr-first',
                [
                    'id'             => 'aaa/non-zdr-first',
                    'name'           => 'Non-ZDR first model',
                    'free'           => false,
                    'zdr_eligible'   => false,
                    'zdr_source'     => 'openrouter_models_zdr_filter',
                    'zdr_checked_at' => gmdate( 'Y-m-d H:i:s' ),
                ],
                $expires_at
            )
        );
        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'zzz/zdr-second',
                [
                    'id'             => 'zzz/zdr-second',
                    'name'           => 'ZDR second model',
                    'free'           => false,
                    'zdr_eligible'   => true,
                    'zdr_source'     => 'openrouter_models_zdr_filter',
                    'zdr_checked_at' => gmdate( 'Y-m-d H:i:s' ),
                ],
                $expires_at
            )
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/openrouter/models' );
        $request->set_query_params(
            [
                'zdr_only' => true,
                'limit'    => 1,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 1, $data['total_returned'] );
        $this->assertSame( 'zzz/zdr-second', $data['models'][0]['id'] ?? null );
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

    public function test_list_openrouter_models_reports_refresh_consent_when_newer_openrouter_consent_is_for_another_action(): void
    {
        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openai/gpt-oss-20b:free',
                [
                    'id'     => 'openai/gpt-oss-20b:free',
                    'name'   => 'OpenAI: GPT OSS 20B',
                    'free'   => true,
                    'pricing' => [
                        'prompt'     => '0',
                        'completion' => '0',
                    ],
                ],
                $expires_at
            )
        );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $refresh_consent_id = $consents->record(
            'openrouter',
            '2026-04-18',
            self::$admin_id,
            [
                'action' => 'refresh_models',
            ]
        );
        $this->assertIsInt( $refresh_consent_id );

        $validate_consent_id = $consents->record(
            'openrouter',
            '2026-04-19',
            self::$admin_id,
            [
                'action' => 'validate_key',
            ]
        );
        $this->assertIsInt( $validate_consent_id );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/openrouter/models' );
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'accepted', $data['refresh_consent']['state'] ?? null );
        $this->assertSame( '2026-04-18', $data['refresh_consent']['disclosure_version'] ?? null );
        $this->assertSame( $refresh_consent_id, $data['refresh_consent']['consent_id'] ?? null );
    }

    public function test_list_openrouter_models_reports_refresh_consent_beyond_recent_provider_rows_with_bounded_lookup(): void
    {
        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openai/gpt-oss-20b:free',
                [
                    'id'     => 'openai/gpt-oss-20b:free',
                    'name'   => 'OpenAI: GPT OSS 20B',
                    'free'   => true,
                    'pricing' => [
                        'prompt'     => '0',
                        'completion' => '0',
                    ],
                ],
                $expires_at
            )
        );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $refresh_consent_id = $consents->record(
            'openrouter',
            '2026-04-18',
            self::$admin_id,
            [
                'action' => 'refresh_models',
            ]
        );
        $this->assertIsInt( $refresh_consent_id );

        for ( $index = 0; $index < 26; $index++ )
        {
            $this->assertIsInt(
                $consents->record(
                    'openrouter',
                    '2026-04-19',
                    self::$admin_id,
                    [
                        'action' => 'validate_key',
                        'index'  => $index,
                    ]
                )
            );
        }

        $consent_queries = [];
        $query_logger    = static function ( string $query ) use ( &$consent_queries ): string {
            if ( str_starts_with( ltrim( $query ), 'SELECT' ) && str_contains( $query, 'sentient_external_service_consents' ) )
            {
                $consent_queries[] = $query;
            }

            return $query;
        };

        add_filter( 'query', $query_logger );

        try
        {
            $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/openrouter/models' );
            $response = rest_get_server()->dispatch( $request );
        }
        finally
        {
            remove_filter( 'query', $query_logger );
        }

        $data = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'accepted', $data['refresh_consent']['state'] ?? null );
        $this->assertSame( $refresh_consent_id, $data['refresh_consent']['consent_id'] ?? null );
        $this->assertNotEmpty( $consent_queries );

        $latest_refresh_consent_query = current(
            array_filter(
                $consent_queries,
                static fn ( string $query ): bool => str_contains( $query, 'metadata_json LIKE' )
            )
        ) ?: '';

        $this->assertNotSame( '', $latest_refresh_consent_query );
        $this->assertStringContainsString( 'LIMIT 25', $latest_refresh_consent_query );
    }

    public function test_list_openrouter_models_ignores_nested_refresh_action_when_finding_latest_top_level_refresh_consent(): void
    {
        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openai/gpt-oss-20b:free',
                [
                    'id'     => 'openai/gpt-oss-20b:free',
                    'name'   => 'OpenAI: GPT OSS 20B',
                    'free'   => true,
                    'pricing' => [
                        'prompt'     => '0',
                        'completion' => '0',
                    ],
                ],
                $expires_at
            )
        );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $GLOBALS['wpdb'] );
        $refresh_consent_id = $consents->record(
            'openrouter',
            '2026-04-18',
            self::$admin_id,
            [
                'action' => 'refresh_models',
            ]
        );
        $this->assertIsInt( $refresh_consent_id );

        $shadow_consent_id = $consents->record(
            'openrouter',
            '2026-04-19',
            self::$admin_id,
            [
                'context' => [
                    'action' => 'refresh_models',
                ],
                'action'  => 'validate_key',
            ]
        );
        $this->assertIsInt( $shadow_consent_id );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/providers/openrouter/models' );
        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'accepted', $data['refresh_consent']['state'] ?? null );
        $this->assertSame( $refresh_consent_id, $data['refresh_consent']['consent_id'] ?? null );
    }

    public function test_refresh_openrouter_models_schedules_one_daily_catalog_refresh_after_consent(): void
    {
        $this->mock_openrouter_models_response();

        $this->assertFalse( wp_next_scheduled( 'sentient_forms_openrouter_model_catalog_refresh' ) );

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
        $first_timestamp = wp_next_scheduled( 'sentient_forms_openrouter_model_catalog_refresh' );
        $this->assertIsInt( $first_timestamp );

        Sentient_Forms_OpenRouter_Model_Catalog_Refresh_Cron::sync_schedule();

        $this->assertSame( $first_timestamp, wp_next_scheduled( 'sentient_forms_openrouter_model_catalog_refresh' ) );
    }

    public function test_openrouter_model_catalog_cron_refreshes_cache_after_refresh_consent(): void
    {
        global $wpdb;

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $consent_id = $consents->record(
            'openrouter',
            '2026-04-18',
            self::$admin_id,
            [
                'action' => 'refresh_models',
            ]
        );
        $this->assertIsInt( $consent_id );

        $external_call_count = 0;
        $this->mock_openrouter_models_response(
            function () use ( &$external_call_count ): void {
                ++$external_call_count;
            }
        );

        Sentient_Forms_OpenRouter_Model_Catalog_Refresh_Cron::refresh();

        $this->assertSame( 2, $external_call_count );

        $models = new Sentient_Forms_Model_Cache_Repository( $wpdb );
        $model  = $models->get( 'openrouter', 'openai/gpt-oss-20b:free' );
        $this->assertIsArray( $model );
        $this->assertTrue( $model['metadata_json']['free'] );

        $consent_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sentient_external_service_consents" );
        $this->assertSame( 1, $consent_count );
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

    public function test_refresh_openrouter_models_preserves_catalog_when_zdr_catalog_fails(): void
    {
        $calls = [];
        $this->mock_openrouter_models_response(
            static function ( array $args, string $url ) use ( &$calls ): ?array {
                $calls[] = $url;
                if ( str_contains( $url, 'zdr=true' ) )
                {
                    return [
                        'headers'  => [ 'content-type' => 'application/json' ],
                        'body'     => wp_json_encode( [ 'error' => [ 'message' => 'Temporary unavailable' ] ] ),
                        'response' => [
                            'code'    => 503,
                            'message' => 'Service Unavailable',
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

        $data = $response->get_data();
        $this->assertSame( 2, $data['total_cached'] );
        $this->assertSame( 'openrouter_zdr_models_unavailable', $data['warnings'][0]['code'] ?? null );
        $this->assertStringContainsString( 'ZDR eligibility could not be verified', $data['warnings'][0]['message'] ?? '' );

        $models = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $cached = $models->list( 'openrouter', true );
        $this->assertCount( 2, $cached );

        $free_model = $models->get( 'openrouter', 'openai/gpt-oss-20b:free' );
        $this->assertIsArray( $free_model );
        $this->assertArrayNotHasKey( 'zdr_eligible', $free_model['metadata_json'] );
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

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $selection
     */
    private function assert_custom_action_reference_blocks_delete( array $definition, array $selection ): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $actions     = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $id          = $this->create_delete_guard_credential( $credentials, 'Isolated custom Action key' );
        $replace_id  = static function( mixed $value ) use ( &$replace_id, $id ): mixed
        {
            if ( is_array( $value ) )
            {
                return array_map( $replace_id, $value );
            }

            return '{credential_id}' === $value ? (string) $id : $value;
        };
        $action_id = $actions->create(
            [
                'code'                 => 'credential_delete_isolated_' . wp_generate_password( 8, false ),
                'display_name'         => 'Isolated credential reference',
                'definition_json'      => $replace_id( $definition ),
                'model_selection_json' => $replace_id( $selection ),
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        try
        {
            $before = $credentials->get( $id );
            $data   = $this->dispatch_credential_delete( $id, 409 );
            $after  = $credentials->get( $id );

            $this->assertSame( 'custom_action', $data['data']['references'][0]['type'] ?? null );
            $this->assertSame( $action_id, $data['data']['references'][0]['id'] ?? null );
            $this->assertSame( $before['encrypted_secret'] ?? null, $after['encrypted_secret'] ?? null );
        }
        finally
        {
            $wpdb->delete( $wpdb->prefix . 'sentient_custom_actions', [ 'id' => $action_id ], [ '%d' ] );
            $credentials->delete( $id );
        }
    }

    /** @param array<string, mixed> $value */
    private function assert_site_context_option_reference_blocks_delete( string $option_name, array $value, string $expected_type ): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $GLOBALS['wpdb'] );
        $id          = $this->create_delete_guard_credential( $credentials, 'Isolated Site Context key' );
        $encoded     = str_replace( '"{credential_id}"', (string) $id, (string) wp_json_encode( $value ) );
        $value       = json_decode( $encoded, true );
        $this->assertIsArray( $value );
        update_option( $option_name, $value, false );

        try
        {
            $data = $this->dispatch_credential_delete( $id, 409 );
            $this->assertContains( $expected_type, wp_list_pluck( $data['data']['references'] ?? [], 'type' ) );
            $this->assertIsArray( $credentials->get( $id ) );
        }
        finally
        {
            delete_option( $option_name );
            $credentials->delete( $id );
        }
    }

    private function create_delete_guard_credential(
        Sentient_Forms_Provider_Credentials_Repository $credentials,
        string $label
    ): int
    {
        $id = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => $label,
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => 'encrypted-delete-guard-secret',
                'status'            => 'valid',
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );
        $this->assertIsInt( $id );
        return $id;
    }

    /** @return array<string, mixed> */
    private function local_mapping_payload_with_credential( int $credential_id ): array
    {
        return [
            'execution_request_id' => 'credential-delete-guard-' . wp_generate_uuid4(),
            'context'              => [
                'settings' => [
                    'model_selection' => [
                        'provider'      => 'openrouter',
                        'credential_id' => $credential_id,
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function dispatch_credential_delete( int $credential_id, int $expected_status ): array
    {
        $request  = $this->add_rest_nonce( new WP_REST_Request( 'DELETE', '/sentient-forms/v1/local/providers/credentials/' . $credential_id ) );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( $expected_status, $response->get_status() );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        if ( 409 === $expected_status )
        {
            $this->assertSame( 'sentient_forms_credential_in_use', $data['code'] ?? null );
        }
        return $data;
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

        foreach (
            [
                'sentient_provider_credentials',
                'sentient_external_service_consents',
                'sentient_model_cache',
                'sentient_form_mappings',
                'sentient_custom_actions',
                'sentient_async_requests',
            ] as $table
        )
        {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
        }

        foreach (
            [
                'sentient_forms_site_context_settings',
                'sentient_forms_site_context_generation_job',
            ] as $option_name
        )
        {
            delete_option( $option_name );
        }

        foreach (
            [
                'sentient_forms_form_config_',
                'sentient_forms_action_defaults_',
                'sentient_forms_actions_',
                'sentient_forms_gravity_forms_',
            ] as $option_prefix
        )
        {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE option_name LIKE %s',
                    $wpdb->options,
                    $wpdb->esc_like( $option_prefix ) . '%'
                )
            );
        }

        wp_clear_scheduled_hook( Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK );
        if ( function_exists( 'as_unschedule_all_actions' ) )
        {
            as_unschedule_all_actions( Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK, [], 'sentient_forms_async' );
        }
    }
}
