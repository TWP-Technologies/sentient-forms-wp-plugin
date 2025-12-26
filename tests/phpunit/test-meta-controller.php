<?php
/**
 * Meta Controller Tests
 *
 * @package SentientForms\Tests
 */

class MetaControllerTest extends WP_UnitTestCase
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
        parent::tearDown();
    }

    public function test_capabilities_route_is_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/meta/capabilities', $routes, 'Capabilities route should be registered' );
    }

    public function test_capabilities_returns_flags_with_license(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_key'    => '0abcdefghijklmnopqrstuvwxy',
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
        ] );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/meta/capabilities' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertTrue( $data['supports_custom_actions'] );
        $this->assertTrue( $data['supports_status'] );
        $this->assertTrue( $data['supports_credits'] );
        $this->assertArrayHasKey( 'cps_version', $data );
    }

    public function test_capabilities_returns_limited_without_license(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/meta/capabilities' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertFalse( $data['supports_custom_actions'] );
        $this->assertTrue( $data['supports_status'] );
        $this->assertFalse( $data['supports_credits'] );
    }

    public function test_capabilities_requires_authentication(): void
    {
        wp_set_current_user( 0 );
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/meta/capabilities' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 401, $response->get_status() );
    }
}
