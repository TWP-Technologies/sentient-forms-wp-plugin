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
        delete_option( 'sentient_forms_store_full_ai_outputs' );
        delete_option( 'sentient_forms_privacy_setup_profile' );
        delete_option( 'sentient_forms_privacy_setup_completed_at' );
    }

    protected function tearDown(): void
    {
        delete_option( 'sentient_forms_settings' );
        delete_option( 'sentient_forms_plugin_settings' );
        delete_option( 'sentient_forms_execution_event_retention_days' );
        delete_option( 'sentient_forms_delete_data_on_uninstall' );
        delete_option( 'sentient_forms_store_full_ai_outputs' );
        delete_option( 'sentient_forms_privacy_setup_profile' );
        delete_option( 'sentient_forms_privacy_setup_completed_at' );

        parent::tearDown();
    }

    private function add_rest_nonce( WP_REST_Request $request ): WP_REST_Request
    {
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        return $request;
    }

    public function test_settings_response_includes_local_retention_controls(): void
    {
        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/sentient-forms/v1/settings' ) );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 90, $data['execution_event_retention_days'] );
        $this->assertTrue( $data['delete_data_on_uninstall'] );
        $this->assertFalse( $data['store_full_ai_outputs'] );
        $this->assertSame( 'balanced', $data['privacy_setup_profile'] );
        $this->assertNull( $data['privacy_setup_completed_at'] );
        $this->assertFalse( $data['execution_global_disabled'] );
        $this->assertSame( [], $data['execution_provider_disabled'] );
    }

    public function test_settings_update_persists_local_retention_controls_in_governance_options(): void
    {
        $request = $this->add_rest_nonce( new WP_REST_Request( 'PUT', '/sentient-forms/v1/settings' ) );
        $request->set_body_params(
            [
                'enable_logging'                 => false,
                'execution_event_retention_days' => 30,
                'delete_data_on_uninstall'       => true,
                'store_full_ai_outputs'          => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );
        $this->assertSame( 30, $data['settings']['execution_event_retention_days'] );
        $this->assertTrue( $data['settings']['delete_data_on_uninstall'] );
        $this->assertTrue( $data['settings']['store_full_ai_outputs'] );

        $plugin_settings = get_option( 'sentient_forms_plugin_settings', [] );
        $this->assertIsArray( $plugin_settings );
        $this->assertArrayNotHasKey( 'execution_event_retention_days', $plugin_settings );
        $this->assertArrayNotHasKey( 'delete_data_on_uninstall', $plugin_settings );
        $this->assertArrayNotHasKey( 'store_full_ai_outputs', $plugin_settings );
        $this->assertFalse( $plugin_settings['enable_logging'] );

        $this->assertSame( 30, Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days() );
        $this->assertTrue( Sentient_Forms_Local_Data_Governance::delete_data_on_uninstall_enabled() );
        $this->assertTrue( Sentient_Forms_Local_Data_Governance::store_full_ai_outputs_enabled() );
    }

    public function test_nonce_disable_option_does_not_bypass_mutating_rest_nonce(): void
    {
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/settings' );
        $request->set_body_params(
            [
                'enable_logging' => true,
            ]
        );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_settings_update_rejects_unsupported_retention_window(): void
    {
        update_option( 'sentient_forms_execution_event_retention_days', 90 );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'PUT', '/sentient-forms/v1/settings' ) );
        $request->set_body_params(
            [
                'execution_event_retention_days' => 365,
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 90, Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days() );
    }

    public function test_settings_update_applies_privacy_setup_profile_defaults(): void
    {
        $request = $this->add_rest_nonce( new WP_REST_Request( 'PUT', '/sentient-forms/v1/settings' ) );
        $request->set_body_params(
            [
                'privacy_setup_profile' => 'maximum_visibility',
            ]
        );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'maximum_visibility', $data['settings']['privacy_setup_profile'] );
        $this->assertSame( 180, $data['settings']['execution_event_retention_days'] );
        $this->assertTrue( $data['settings']['delete_data_on_uninstall'] );
        $this->assertTrue( $data['settings']['store_full_ai_outputs'] );
        $this->assertNotNull( $data['settings']['privacy_setup_completed_at'] );

        $plugin_settings = get_option( 'sentient_forms_plugin_settings', [] );
        $this->assertTrue( $plugin_settings['enable_logging'] );
    }

    public function test_settings_update_applies_privacy_setup_profile_defaults_from_json_body(): void
    {
        update_option( 'sentient_forms_execution_event_retention_days', 30 );
        update_option( 'sentient_forms_delete_data_on_uninstall', false );

        $request = $this->add_rest_nonce( new WP_REST_Request( 'PUT', '/sentient-forms/v1/settings' ) );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body(
            wp_json_encode(
                [
                    'privacy_setup_profile' => 'balanced',
                ]
            )
        );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'balanced', $data['settings']['privacy_setup_profile'] );
        $this->assertSame( 90, $data['settings']['execution_event_retention_days'] );
        $this->assertTrue( $data['settings']['delete_data_on_uninstall'] );
        $this->assertFalse( $data['settings']['store_full_ai_outputs'] );
        $this->assertNotNull( $data['settings']['privacy_setup_completed_at'] );
    }
}
