<?php

class Tests_Local_Action_Model_Selection_Service extends WP_UnitTestCase
{
    private wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        Sentient_Forms_Installer::maybe_upgrade();
        $this->wpdb->query( "TRUNCATE TABLE {$this->wpdb->prefix}sentient_model_cache" );
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'license_id'     => 'license-managed-model-selection-test',
                'site_id'        => '33333333-3333-4333-8333-333333333333',
                'proxy_api_key'  => 'proxy-model-selection-test',
                'tier'           => 'pro',
            ]
        );
    }

    protected function tearDown(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();
        parent::tearDown();
    }

    public function test_realtime_bundled_auto_default_repairs_to_fast_managed_preset(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );

        $managed_credential_id = $credentials->create(
            [
                'provider'  => 'sentient_managed',
                'label'     => 'Managed service',
                'auth_mode' => 'sentient_proxy',
                'status'    => 'valid',
            ]
        );
        $this->assertIsInt( $managed_credential_id );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'bundled__clarification_assistant_v1',
                'display_name'         => 'Realtime Clarification Assistant',
                'definition_json'      => [
                    'template_code' => 'clarification_assistant_v1',
                ],
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'openrouter/auto',
                    'credential_id' => 999,
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $service  = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $repaired = $service->repair_action_model_selection( $custom_actions->get( $action_id ) );

        $this->assertTrue( $repaired );

        $selection = $custom_actions->get( $action_id )['model_selection_json'] ?? [];
        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, $selection['credential_id'] ?? null );
        $this->assertSame( '~google/gemini-flash-latest', $selection['model'] ?? null );
        $this->assertSame( 'sf_realtime', $selection['selection']['primary'] ?? null );
        $this->assertTrue( $selection['selection']['is_preset'] ?? false );
    }

    public function test_realtime_bundled_nested_openrouter_preset_repairs_to_managed_when_direct_provider_missing(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );

        $managed_credential_id = $credentials->create(
            [
                'provider'  => 'sentient_managed',
                'label'     => 'Managed service',
                'auth_mode' => 'sentient_proxy',
                'status'    => 'valid',
            ]
        );
        $this->assertIsInt( $managed_credential_id );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'bundled__clarification_assistant_v1',
                'display_name'         => 'Realtime Clarification Assistant',
                'definition_json'      => [
                    'template_code' => 'clarification_assistant_v1',
                ],
                'model_selection_json' => [
                    'provider'  => 'openrouter',
                    'model'     => '~google/gemini-flash-latest',
                    'selection' => [
                        'primary'   => 'sf_realtime',
                        'provider'  => 'openrouter',
                        'is_preset' => true,
                    ],
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $service  = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $repaired = $service->repair_action_model_selection( $custom_actions->get( $action_id ) );

        $this->assertTrue( $repaired );

        $selection = $custom_actions->get( $action_id )['model_selection_json'] ?? [];
        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, $selection['credential_id'] ?? null );
        $this->assertSame( 'sentient_managed', $selection['selection']['provider'] ?? null );
        $this->assertSame( $managed_credential_id, $selection['selection']['credential_id'] ?? null );
        $this->assertSame( 'sf_realtime', $selection['selection']['primary'] ?? null );
        $this->assertTrue( $selection['selection']['is_preset'] ?? false );
    }

    public function test_bundled_openrouter_default_repairs_to_managed_default_when_direct_provider_missing(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );

        $managed_credential_id = $credentials->create(
            [
                'provider'  => 'sentient_managed',
                'label'     => 'Managed service',
                'auth_mode' => 'sentient_proxy',
                'status'    => 'valid',
            ]
        );
        $this->assertIsInt( $managed_credential_id );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'bundled__spam_detection_v1',
                'display_name'         => 'Spam Detection',
                'definition_json'      => [
                    'template_code' => 'spam_detection_v1',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $service  = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $repaired = $service->repair_action_model_selection( $custom_actions->get( $action_id ) );

        $this->assertTrue( $repaired );

        $selection = $custom_actions->get( $action_id )['model_selection_json'] ?? [];
        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, $selection['credential_id'] ?? null );
        $this->assertSame( 'sentient_managed', $selection['selection']['provider'] ?? null );
        $this->assertSame( $managed_credential_id, $selection['selection']['credential_id'] ?? null );
        $this->assertSame( 'sf_default', $selection['selection']['primary'] ?? null );
        $this->assertTrue( $selection['selection']['is_preset'] ?? false );
    }

    public function test_bundled_openrouter_default_repairs_to_managed_primary_and_openrouter_backup_when_both_are_ready(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );
        $vault          = new Sentient_Forms_Provider_Credential_Vault();

        $openrouter_secret    = $vault->encrypt( 'sk-or-ready-backup' );
        $openrouter_credential_id = $credentials->create(
            [
                'provider'         => 'openrouter',
                'label'            => 'Direct OpenRouter',
                'auth_mode'        => 'manual_key',
                'encrypted_secret' => $openrouter_secret,
                'status'           => 'valid',
            ]
        );
        $this->assertIsInt( $openrouter_credential_id );

        $managed_credential_id = $credentials->create(
            [
                'provider'  => 'sentient_managed',
                'label'     => 'Managed service',
                'auth_mode' => 'sentient_proxy',
                'status'    => 'valid',
            ]
        );
        $this->assertIsInt( $managed_credential_id );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'bundled__spam_detection_v1',
                'display_name'         => 'Spam Detection',
                'definition_json'      => [
                    'template_code' => 'spam_detection_v1',
                ],
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'openrouter/auto',
                    'credential_id' => $openrouter_credential_id,
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $service  = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $repaired = $service->repair_action_model_selection( $custom_actions->get( $action_id ) );

        $this->assertTrue( $repaired );

        $selection = $custom_actions->get( $action_id )['model_selection_json'] ?? [];
        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, $selection['credential_id'] ?? null );
        $this->assertSame( 'sf_default', $selection['selection']['primary'] ?? null );
        $this->assertSame( 'openrouter', $selection['backup_provider'] ?? null );
        $this->assertSame( $openrouter_credential_id, $selection['backup_credential_id'] ?? null );
        $this->assertSame( 'openrouter/auto', $selection['backup_model'] ?? null );
    }

    public function test_bundled_openrouter_repair_preserves_explicit_backup_model_and_credential(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );
        $vault          = new Sentient_Forms_Provider_Credential_Vault();

        $preferred_secret = $vault->encrypt( 'sk-or-preferred-backup' );
        $other_secret     = $vault->encrypt( 'sk-or-other-backup' );
        $this->assertIsString( $preferred_secret );
        $this->assertIsString( $other_secret );

        $preferred_openrouter_credential_id = $credentials->create(
            [
                'provider'         => 'openrouter',
                'label'            => 'Preferred Direct OpenRouter',
                'auth_mode'        => 'manual_key',
                'encrypted_secret' => $preferred_secret,
                'status'           => 'valid',
            ]
        );
        $this->assertIsInt( $preferred_openrouter_credential_id );

        $other_openrouter_credential_id = $credentials->create(
            [
                'provider'         => 'openrouter',
                'label'            => 'Other Direct OpenRouter',
                'auth_mode'        => 'manual_key',
                'encrypted_secret' => $other_secret,
                'status'           => 'valid',
            ]
        );
        $this->assertIsInt( $other_openrouter_credential_id );

        $managed_credential_id = $credentials->create(
            [
                'provider'  => 'sentient_managed',
                'label'     => 'Managed service',
                'auth_mode' => 'sentient_proxy',
                'status'    => 'valid',
            ]
        );
        $this->assertIsInt( $managed_credential_id );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'bundled__spam_detection_v1',
                'display_name'         => 'Spam Detection',
                'definition_json'      => [
                    'template_code' => 'spam_detection_v1',
                ],
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'google/gemini-3-flash-preview',
                    'credential_id' => $preferred_openrouter_credential_id,
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $service  = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $repaired = $service->repair_action_model_selection( $custom_actions->get( $action_id ) );

        $this->assertTrue( $repaired );

        $selection = $custom_actions->get( $action_id )['model_selection_json'] ?? [];
        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, $selection['credential_id'] ?? null );
        $this->assertSame( 'openrouter', $selection['backup_provider'] ?? null );
        $this->assertSame( $preferred_openrouter_credential_id, $selection['backup_credential_id'] ?? null );
        $this->assertSame( 'google/gemini-3-flash-preview', $selection['backup_model'] ?? null );
    }

    public function test_bundled_openrouter_repair_requires_active_managed_account(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );

        $managed_credential_id = $credentials->create(
            [
                'provider'  => 'sentient_managed',
                'label'     => 'Managed service',
                'auth_mode' => 'sentient_proxy',
                'status'    => 'valid',
            ]
        );
        $this->assertIsInt( $managed_credential_id );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'bundled__spam_detection_v1',
                'display_name'         => 'Spam Detection',
                'definition_json'      => [
                    'template_code' => 'spam_detection_v1',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $service  = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $repaired = $service->repair_action_model_selection( $custom_actions->get( $action_id ) );

        $this->assertFalse( $repaired );

        $selection = $custom_actions->get( $action_id )['model_selection_json'] ?? [];
        $this->assertSame( 'openrouter', $selection['provider'] ?? null );
        $this->assertSame( 'openrouter/auto', $selection['model'] ?? null );
        $this->assertArrayNotHasKey( 'backup_provider', $selection );
    }

    public function test_managed_runtime_zdr_preset_resolves_to_zdr_safe_default(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );
        $expires_at     = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
        $checked_at     = gmdate( 'Y-m-d H:i:s' );

        $this->assertTrue(
            $model_cache->upsert(
                'openrouter',
                'openai/gpt-5.5',
                [
                    'id'                   => 'openai/gpt-5.5',
                    'name'                 => 'OpenAI: GPT-5.5',
                    'free'                 => false,
                    'context_length'       => 1050000,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format', 'structured_outputs' ],
                    'pricing'              => [
                        'prompt'     => '0.000005',
                        'completion' => '0.00003',
                    ],
                    'zdr_eligible'         => false,
                    'zdr_source'           => 'openrouter_models_zdr_filter',
                    'zdr_checked_at'       => $checked_at,
                ],
                $expires_at
            )
        );

        $this->assertTrue(
            $model_cache->upsert(
                'openrouter',
                'google/gemini-3-flash-preview',
                [
                    'id'                   => 'google/gemini-3-flash-preview',
                    'name'                 => 'Google: Gemini 3 Flash Preview',
                    'free'                 => false,
                    'context_length'       => 1048576,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format', 'structured_outputs' ],
                    'pricing'              => [
                        'prompt'     => '0.0000005',
                        'completion' => '0.000003',
                    ],
                    'recommended_for'      => [ 'General purpose', 'Speed', 'Structured output' ],
                    'zdr_eligible'         => true,
                    'zdr_source'           => 'openrouter_models_zdr_filter',
                    'zdr_checked_at'       => $checked_at,
                ],
                $expires_at
            )
        );

        $service   = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $selection = $service->prepare_model_selection_for_execution(
            [
                'model_selection_json' => [
                    'provider' => 'sentient_managed',
                    'model'    => 'sf_default',
                ],
            ],
            [
                'settings' => [
                    'model_selection' => [
                        'provider'    => 'sentient_managed',
                        'primary'     => 'sf_default',
                        'is_preset'   => true,
                        'require_zdr' => true,
                    ],
                ],
            ]
        );

        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertTrue( $selection['require_zdr'] ?? false );
        $this->assertSame( 'google/gemini-3-flash-preview', $selection['model'] ?? null );
        $this->assertSame( 'sf_default', $selection['selection']['primary'] ?? null );
    }

    public function test_global_managed_zdr_setting_resolves_managed_preset_to_zdr_safe_default(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );
        $expires_at     = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
        $checked_at     = gmdate( 'Y-m-d H:i:s' );
        $had_settings   = false !== get_option( 'sentient_forms_plugin_settings', false );
        $old_settings   = get_option( 'sentient_forms_plugin_settings', [] );

        $this->assertTrue(
            $model_cache->upsert(
                'openrouter',
                'openai/gpt-5.5',
                [
                    'id'                   => 'openai/gpt-5.5',
                    'name'                 => 'OpenAI: GPT-5.5',
                    'free'                 => false,
                    'context_length'       => 1050000,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format', 'structured_outputs' ],
                    'pricing'              => [
                        'prompt'     => '0.000005',
                        'completion' => '0.00003',
                    ],
                    'zdr_eligible'         => false,
                    'zdr_source'           => 'openrouter_models_zdr_filter',
                    'zdr_checked_at'       => $checked_at,
                ],
                $expires_at
            )
        );
        $this->assertTrue(
            $model_cache->upsert(
                'openrouter',
                'google/gemini-3-flash-preview',
                [
                    'id'                   => 'google/gemini-3-flash-preview',
                    'name'                 => 'Google: Gemini 3 Flash Preview',
                    'free'                 => false,
                    'context_length'       => 1048576,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format', 'structured_outputs' ],
                    'pricing'              => [
                        'prompt'     => '0.0000005',
                        'completion' => '0.000003',
                    ],
                    'recommended_for'      => [ 'General purpose', 'Speed', 'Structured output' ],
                    'zdr_eligible'         => true,
                    'zdr_source'           => 'openrouter_models_zdr_filter',
                    'zdr_checked_at'       => $checked_at,
                ],
                $expires_at
            )
        );

        try
        {
            update_option( 'sentient_forms_plugin_settings', [ 'managed_zdr_required' => true ] );

            $service   = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
            $selection = $service->prepare_model_selection_for_execution(
                [
                    'model_selection_json' => [
                        'provider' => 'sentient_managed',
                        'model'    => 'sf_default',
                    ],
                ],
                [
                    'settings' => [
                        'model_selection' => [
                            'provider'  => 'sentient_managed',
                            'primary'   => 'sf_default',
                            'is_preset' => true,
                        ],
                    ],
                ]
            );

            $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
            $this->assertSame( 'google/gemini-3-flash-preview', $selection['model'] ?? null );
            $this->assertFalse( $selection['selection']['require_zdr'] ?? false );
        }
        finally
        {
            if ( $had_settings )
            {
                update_option( 'sentient_forms_plugin_settings', $old_settings );
            }
            else
            {
                delete_option( 'sentient_forms_plugin_settings' );
            }
        }
    }

    public function test_saved_nested_managed_zdr_selection_resolves_to_zdr_safe_default(): void
    {
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $credentials    = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $model_cache    = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );
        $expires_at     = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
        $checked_at     = gmdate( 'Y-m-d H:i:s' );

        $this->assertTrue(
            $model_cache->upsert(
                'openrouter',
                'openai/gpt-5.5',
                [
                    'id'             => 'openai/gpt-5.5',
                    'name'           => 'OpenAI: GPT-5.5',
                    'free'           => false,
                    'pricing'        => [
                        'prompt'     => '0.000005',
                        'completion' => '0.00003',
                    ],
                    'zdr_eligible'   => false,
                    'zdr_source'     => 'openrouter_models_zdr_filter',
                    'zdr_checked_at' => $checked_at,
                ],
                $expires_at
            )
        );
        $this->assertTrue(
            $model_cache->upsert(
                'openrouter',
                'google/gemini-3-flash-preview',
                [
                    'id'             => 'google/gemini-3-flash-preview',
                    'name'           => 'Google: Gemini 3 Flash Preview',
                    'free'           => false,
                    'pricing'        => [
                        'prompt'     => '0.0000005',
                        'completion' => '0.000003',
                    ],
                    'zdr_eligible'   => true,
                    'zdr_source'     => 'openrouter_models_zdr_filter',
                    'zdr_checked_at' => $checked_at,
                ],
                $expires_at
            )
        );

        $service   = new Sentient_Forms_Local_Action_Model_Selection_Service( $custom_actions, $credentials, $mappings, $model_cache );
        $selection = $service->prepare_model_selection_for_action(
            [
                'model_selection_json' => [
                    'provider'  => 'sentient_managed',
                    'model'     => 'sf_default',
                    'selection' => [
                        'primary'     => 'sf_default',
                        'is_preset'   => true,
                        'require_zdr' => 'true',
                    ],
                ],
            ]
        );

        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertTrue( $selection['require_zdr'] ?? false );
        $this->assertTrue( $selection['selection']['require_zdr'] ?? false );
        $this->assertSame( 'google/gemini-3-flash-preview', $selection['model'] ?? null );
    }
}
