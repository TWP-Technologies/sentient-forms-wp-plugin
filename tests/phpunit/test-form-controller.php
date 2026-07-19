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
        delete_option( 'sentient_forms_actions_elementor_pro_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( '123:formabc' ) );
        delete_option( 'sentient_forms_actions_contact_form_7_755' );
        delete_option( 'sentient_forms_actions_wpforms_756' );
        delete_option( 'sentient_forms_actions_gravity_forms_757' );
        delete_option( 'sentient_forms_actions_elementor_pro_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( '758:direct' ) );

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

    public function test_form_settings_update_cannot_restore_legacy_mapping_during_authority_cutover(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        $option_key = 'sentient_forms_actions_contact_form_7_55';
        update_option(
            $option_key,
            [
                'enabled'     => true,
                'map_summary' => [
                    'local_mapping_id'           => 'map_summary',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [],
                ],
            ],
            false
        );

        $nested_migration = null;
        $run_migration_before_stale_write = static function ( mixed $value ) use ( &$nested_migration ): mixed {
            $nested_migration = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
            return $value;
        };
        add_filter( 'pre_update_option_' . $option_key, $run_migration_before_stale_write );
        try
        {
            $controller = new Sentient_Forms_Form_Controller();
            $request    = new WP_REST_Request( 'PUT', '/sentient-forms/v1/contact_form_7/forms/55' );
            $request->set_param( 'form_source_slug', 'contact_form_7' );
            $request->set_param( 'form_id', 55 );
            $request->set_param( 'enabled', false );
            $response = $controller->endpoint_update_form_settings( $request );
        }
        finally
        {
            remove_filter( 'pre_update_option_' . $option_key, $run_migration_before_stale_write );
        }

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 0, $nested_migration['migration_complete'] ?? null );
        $this->assertArrayHasKey( 'map_summary', get_option( $option_key ) );
        $this->assertFalse( get_option( $option_key )['enabled'] ?? true );

        $completed = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $completed['migration_complete'] ?? null );
        $this->assertSame( [ 'enabled' => false ], get_option( $option_key ) );
    }

    public function test_direct_adapter_setting_writers_hold_the_action_authority_fence(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $cases  = [
            'Contact Form 7' => [
                new Sentient_Forms_Contact_Form_7_Adapter( $plugin ),
                755,
                'sentient_forms_actions_contact_form_7_755',
            ],
            'WPForms' => [
                new Sentient_Forms_WPForms_Adapter( $plugin ),
                756,
                'sentient_forms_actions_wpforms_756',
            ],
            'Gravity Forms' => [
                new Sentient_Forms_Gravity_Forms_Adapter( $plugin ),
                757,
                'sentient_forms_actions_gravity_forms_757',
            ],
            'Elementor Pro Forms' => [
                new Sentient_Forms_Elementor_Forms_Adapter( $plugin ),
                '758:direct',
                'sentient_forms_actions_elementor_pro_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( '758:direct' ),
            ],
        ];

        foreach ( $cases as $label => [ $adapter, $form_id, $option_key ] )
        {
            update_option( $option_key, [ 'enabled' => true ], false );
            $nested_migration = null;
            $run_migration_before_write = static function ( mixed $value ) use ( &$nested_migration ): mixed {
                $nested_migration = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
                return $value;
            };
            add_filter( 'pre_update_option_' . $option_key, $run_migration_before_write );
            try
            {
                $result = $adapter->update_form_settings( $form_id, [ 'enabled' => false ] );
            }
            finally
            {
                remove_filter( 'pre_update_option_' . $option_key, $run_migration_before_write );
            }

            $this->assertNotWPError( $result, $label );
            $this->assertTrue( $result, $label );
            $this->assertSame( 0, $nested_migration['migration_complete'] ?? null, $label );
            $this->assertFalse( get_option( $option_key )['enabled'] ?? true, $label );
        }
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

        $opaque_option_key = 'sentient_forms_actions_elementor_pro_forms_'
            . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( '123:formabc' );
        update_option( $opaque_option_key, [ 'enabled' => true ], false );
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

        $stored_opaque  = get_option( $opaque_option_key, [] );
        $stored_numeric = get_option( 'sentient_forms_actions_elementor_pro_forms_123', [] );

        $this->assertFalse( $stored_opaque['enabled'] ?? true );
        $this->assertTrue( $stored_numeric['enabled'] ?? false );
    }
}
