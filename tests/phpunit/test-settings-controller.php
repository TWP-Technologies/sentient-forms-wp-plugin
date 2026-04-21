<?php

class Tests_Settings_Controller extends WP_UnitTestCase
{
    private static int $admin_id;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = (int) $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );
        delete_option( 'sentient_forms_plugin_settings' );
        delete_option( 'sentient_forms_execution_event_retention_days' );
        delete_option( 'sentient_forms_delete_data_on_uninstall' );
    }

    protected function tearDown(): void
    {
        delete_option( 'sentient_forms_settings' );
        delete_option( 'sentient_forms_plugin_settings' );
        delete_option( 'sentient_forms_execution_event_retention_days' );
        delete_option( 'sentient_forms_delete_data_on_uninstall' );

        parent::tearDown();
    }

    public function test_settings_response_includes_local_retention_controls(): void
    {
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/sentient-forms/v1/settings' ) );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 90, $data['execution_event_retention_days'] );
        $this->assertFalse( $data['delete_data_on_uninstall'] );
        $this->assertFalse( $data['execution_global_disabled'] );
        $this->assertSame( [], $data['execution_provider_disabled'] );
    }

    public function test_settings_update_persists_local_retention_controls_in_governance_options(): void
    {
        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/settings' );
        $request->set_body_params(
            [
                'enable_logging'                 => false,
                'execution_event_retention_days' => 30,
                'delete_data_on_uninstall'       => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertSame( 30, $data['settings']['execution_event_retention_days'] );
        $this->assertTrue( $data['settings']['delete_data_on_uninstall'] );

        $plugin_settings = get_option( 'sentient_forms_plugin_settings', [] );
        $this->assertIsArray( $plugin_settings );
        $this->assertArrayNotHasKey( 'execution_event_retention_days', $plugin_settings );
        $this->assertArrayNotHasKey( 'delete_data_on_uninstall', $plugin_settings );
        $this->assertFalse( $plugin_settings['enable_logging'] );

        $this->assertSame( 30, Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days() );
        $this->assertTrue( Sentient_Forms_Local_Data_Governance::delete_data_on_uninstall_enabled() );
    }

    public function test_settings_update_rejects_unsupported_retention_window(): void
    {
        update_option( 'sentient_forms_execution_event_retention_days', 90 );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/settings' );
        $request->set_body_params(
            [
                'execution_event_retention_days' => 365,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 90, Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days() );
    }
}
