<?php

class AjaxNonceIntegrationTest extends WP_Ajax_UnitTestCase {
    protected static $admin_id;

class AjaxNonceIntegrationTest extends WP_Ajax_UnitTestCase {
    protected static $admin_id;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => true ] );
        $_POST = [];
    }

    public function test_missing_nonce_rejected() {
        try {
            $this->_handleAjax( 'sentient_forms_test_connection' );
        } catch ( WPAjaxDieContinueException $e ) {
            $this->assertEquals( 403, $e->getCode() );
        }
    }

    public function test_valid_nonce_allows_request() {
        $_POST['nonce']   = wp_create_nonce( 'sentient_forms_admin_nonce' );
        $_POST['api_key'] = 'dummy';
        try {
            $this->_handleAjax( 'sentient_forms_test_connection' );
        } catch ( WPAjaxDieContinueException $e ) {
            $this->assertEquals( 200, $e->getCode() );
        }
    }

    public function test_flag_disables_nonce_check() {
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );
        $_POST['api_key'] = 'dummy';
        try {
            $this->_handleAjax( 'sentient_forms_test_connection' );
        } catch ( WPAjaxDieContinueException $e ) {
            $this->assertEquals( 200, $e->getCode() );
        }
    }
}
