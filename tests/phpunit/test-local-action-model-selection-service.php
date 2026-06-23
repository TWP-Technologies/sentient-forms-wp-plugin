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
}
