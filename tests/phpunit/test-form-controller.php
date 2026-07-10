<?php

class Tests_Form_Controller extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_elementor_pro_form_submissions_api_available' );
        delete_option( 'sentient_forms_actions_contact_form_7_55' );
        delete_option( 'sentient_forms_actions_elementor_pro_forms_123' );
        delete_option( 'sentient_forms_actions_elementor_pro_forms_123_formabc' );
        delete_option( 'sentient_forms_actions_elementor_pro_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( '123:formabc' ) );

        parent::tearDown();
    }

    public function test_contact_form_7_settings_endpoint_returns_stored_settings(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        update_option(
            'sentient_forms_actions_contact_form_7_55',
            [
                'enabled'     => true,
                'map_summary' => [
                    'local_mapping_id'           => 'map_summary',
                    'central_action_id'          => 'entry_evaluation',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                ],
            ],
            false
        );

        $controller = new Sentient_Forms_Form_Controller();
        $request    = new WP_REST_Request( 'GET', '/sentient-forms/v1/contact_form_7/forms/55' );
        $request->set_param( 'form_source_slug', 'contact_form_7' );
        $request->set_param( 'form_id', 55 );

        $response = $controller->endpoint_get_form_settings( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertTrue( $data['enabled'] ?? false );
        $this->assertSame( 'entry_evaluation', $data['map_summary']['central_action_id'] ?? null );
        $this->assertSame( [ 'after_submission' ], $data['map_summary']['trigger_hooks'] ?? null );
    }

    public function test_contact_form_7_settings_endpoint_updates_stored_settings_without_dropping_mappings(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        update_option(
            'sentient_forms_actions_contact_form_7_55',
            [
                'enabled'     => true,
                'map_summary' => [
                    'local_mapping_id'           => 'map_summary',
                    'central_action_id'          => 'entry_evaluation',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                ],
            ],
            false
        );

        $controller = new Sentient_Forms_Form_Controller();
        $request    = new WP_REST_Request( 'PUT', '/sentient-forms/v1/contact_form_7/forms/55' );
        $request->set_param( 'form_source_slug', 'contact_form_7' );
        $request->set_param( 'form_id', 55 );
        $request->set_param( 'enabled', false );

        $response = $controller->endpoint_update_form_settings( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $data = $response->get_data();
        $this->assertTrue( $data['success'] ?? false );
        $this->assertFalse( $data['settings']['enabled'] ?? true );
        $this->assertSame( 'entry_evaluation', $data['settings']['map_summary']['central_action_id'] ?? null );

        $stored = get_option( 'sentient_forms_actions_contact_form_7_55', [] );
        $this->assertFalse( $stored['enabled'] ?? true );
        $this->assertSame( [ 'after_submission' ], $stored['map_summary']['trigger_hooks'] ?? null );
    }

    public function test_elementor_settings_update_rejects_free_elementor_requires_pro_state(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_false' );
        update_option(
            'sentient_forms_actions_elementor_pro_forms_123',
            [
                'enabled' => true,
            ],
            false
        );

        $controller = new Sentient_Forms_Form_Controller();
        $request    = new WP_REST_Request( 'PUT', '/sentient-forms/v1/elementor_pro_forms/forms/123' );
        $request->set_param( 'form_source_slug', 'elementor_pro_forms' );
        $request->set_param( 'form_id', 123 );
        $request->set_param( 'enabled', false );

        $response = $controller->endpoint_update_form_settings( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_form_source_unavailable', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] ?? null );
        $this->assertStringContainsString( 'Elementor Pro Forms', $response->get_error_message() );

        $stored = get_option( 'sentient_forms_actions_elementor_pro_forms_123', [] );
        $this->assertTrue( $stored['enabled'] ?? false );
    }

    public function test_elementor_settings_route_preserves_provider_native_form_id(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        update_option(
            'sentient_forms_actions_elementor_pro_forms_123_formabc',
            [
                'enabled'     => true,
                'map_summary' => [
                    'local_mapping_id'           => 'map_summary',
                    'central_action_id'          => 'entry_summary_v1',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                ],
            ],
            false
        );
        update_option( 'sentient_forms_actions_elementor_pro_forms_123', [ 'enabled' => true ], false );

        $controller = new Sentient_Forms_Form_Controller();
        add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
        do_action( 'rest_api_init' );
        remove_action( 'rest_api_init', [ $controller, 'register_routes' ] );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/elementor_pro_forms/forms/123:formabc' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'enabled', false );

        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $data['success'] ?? false );
        $this->assertFalse( $data['settings']['enabled'] ?? true );
        $this->assertSame( 'entry_summary_v1', $data['settings']['map_summary']['central_action_id'] ?? null );

        $stored_opaque = get_option(
            'sentient_forms_actions_elementor_pro_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( '123:formabc' ),
            []
        );
        $stored_numeric = get_option( 'sentient_forms_actions_elementor_pro_forms_123', [] );

        $this->assertFalse( $stored_opaque['enabled'] ?? true );
        $this->assertSame( 'entry_summary_v1', $stored_opaque['map_summary']['central_action_id'] ?? null );
        $this->assertTrue( $stored_numeric['enabled'] ?? false );
    }
}
