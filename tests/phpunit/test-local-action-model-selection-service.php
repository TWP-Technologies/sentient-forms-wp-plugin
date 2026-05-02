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
        $this->assertSame( 'google/gemini-3-flash-preview', $selection['model'] ?? null );
        $this->assertSame( 'sf_realtime', $selection['selection']['primary'] ?? null );
        $this->assertTrue( $selection['selection']['is_preset'] ?? false );
    }
}
