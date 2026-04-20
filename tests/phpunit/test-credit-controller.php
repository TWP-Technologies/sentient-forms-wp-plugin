<?php
/**
 * Credit Controller Tests
 *
 * @package SentientForms\Tests
 */

class CreditControllerTest extends WP_UnitTestCase
{
    protected static $admin_id;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        remove_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        Sentient_Forms_Plugin::instance();
        $rest_api = new Sentient_Forms_REST_API();
        $server   = rest_get_server();
        do_action( 'rest_api_init', $server );
    }

    protected function tearDown(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();
        add_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        remove_all_filters( 'pre_http_request' );  // Clean up HTTP mocks between tests
        delete_option( 'sentient_forms_settings' );
        putenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        parent::tearDown();
    }

    public function test_balance_route_is_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/credits/balance', $routes, 'Credits balance route should be registered' );
    }

    public function test_balance_route_returns_retired_compatibility_error(): void
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 410, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_forms_credit_balance_retired', $data['code'] ?? null );
        $this->assertStringContainsString( 'local-first', $data['message'] ?? '' );
    }

    public function test_balance_route_does_not_call_cps_even_when_license_exists(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'proxy_api_key' => 'proxy-key-123',
        ] );

        add_filter(
            'pre_http_request',
            function () {
                $this->fail( 'Retired credit balance route must not perform outbound HTTP requests.' );
            },
            10
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 410, $response->get_status() );
    }

    public function test_direct_controller_method_returns_retired_error(): void
    {
        $controller = new Sentient_Forms_Credit_Controller();
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $response = $controller->get_credit_balance( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'sentient_forms_credit_balance_retired', $response->get_error_code() );
        $this->assertSame( 410, $response->get_error_data()['status'] ?? null );
    }
}
