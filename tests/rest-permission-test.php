<?php

class RestNonceIntegrationTest extends WP_UnitTestCase {
    protected static $admin_id;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => true ] );
    }

    public function test_missing_nonce_rejected() {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/settings' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertEquals( 403, $response->get_status() );
    }

    public function test_valid_nonce_allows_request() {
        $nonce   = wp_create_nonce( 'wp_rest' );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/settings' );
        $request->add_header( 'X-WP-Nonce', $nonce );
        $response = rest_get_server()->dispatch( $request );
        $this->assertEquals( 200, $response->get_status() );
    }

    public function test_flag_disables_nonce_check() {
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/settings' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertEquals( 200, $response->get_status() );
    }
}
