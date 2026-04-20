<?php

class AjaxNonceIntegrationTest extends WP_Ajax_UnitTestCase {
    protected static $admin_id;
    private $http_mock;

    public static function wpSetUpBeforeClass( $factory ) {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => true ] );
        $plugin = Sentient_Forms_Plugin::instance();
        $admin  = new Sentient_Forms_Admin( $plugin );
        $admin->init();
        $this->http_mock = function ( $preempt, $args, $url ) {
            if ( str_ends_with( $url, '/models' ) ) {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [] ),
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                ];
            }

            return $preempt;
        };
        add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
        $_POST = [];
    }

    protected function tearDown(): void {
        if ( $this->http_mock ) {
            remove_filter( 'pre_http_request', $this->http_mock, 10 );
            $this->http_mock = null;
        }
        parent::tearDown();
    }

    public function test_missing_nonce_rejected() {
        try {
            $this->_handleAjax( 'sentient_forms_test_connection' );
            $this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
        } catch ( WPAjaxDieContinueException $e ) {
            $this->assertEquals( 403, $e->getCode() );
        }
    }

    public function test_valid_nonce_allows_request() {
        $_POST['nonce']   = wp_create_nonce( 'sentient_forms_admin_nonce' );
        $_POST['api_key'] = 'dummy';
        try {
            $this->_handleAjax( 'sentient_forms_test_connection' );
            $this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
        } catch ( WPAjaxDieContinueException $e ) {
            $this->assertEquals( 200, $e->getCode() );
        }
    }

    public function test_flag_disables_nonce_check() {
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );
        $_POST['api_key'] = 'dummy';
        try {
            $this->_handleAjax( 'sentient_forms_test_connection' );
            $this->fail( 'Expected WPAjaxDieContinueException was not thrown' );
        } catch ( WPAjaxDieContinueException $e ) {
            $this->assertEquals( 200, $e->getCode() );
        }
    }
}
