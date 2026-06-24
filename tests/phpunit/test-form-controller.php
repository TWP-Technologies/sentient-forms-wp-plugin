<?php

class Tests_Form_Controller extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        delete_option( 'sentient_forms_actions_contact_form_7_55' );

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
}
