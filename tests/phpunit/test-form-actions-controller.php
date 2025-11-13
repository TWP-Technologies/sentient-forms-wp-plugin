<?php

class Tests_Form_Actions_Controller extends WP_UnitTestCase {
    private Sentient_Forms_Form_Actions_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        $this->controller = new Sentient_Forms_Form_Actions_Controller();
    }

    public function test_validate_trigger_hooks_accepts_allowed_values(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'gform_validation', 'gform_after_submission' ],
            $request,
            'trigger_hooks'
        );

        $this->assertTrue( $result );
    }

    public function test_validate_trigger_hooks_rejects_unknown_hook(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'gform_bogus_hook' ],
            $request,
            'trigger_hooks'
        );

        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_hook', $result->get_error_code() );
    }

    public function test_add_form_action_sanitizes_trigger_hooks(): void {
        delete_option( 'sentient_forms_actions_gravity_forms_1' );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation', 'evil_hook', 'gform_validation' ] );

        $response = $this->controller->add_form_action( $request );
        $data     = $response->get_data();

        $this->assertSame( [ 'gform_validation' ], $data['trigger_hooks'] );
    }
}
