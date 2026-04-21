<?php
/**
 * Site Context Controller Tests
 *
 * @package SentientForms\Tests
 */

class SiteContextControllerTest extends WP_UnitTestCase
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
        delete_option( 'sentient_forms_site_context' );
        add_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        remove_all_filters( 'pre_http_request' );
        parent::tearDown();
    }

    public function test_site_context_route_is_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/site-context', $routes );
    }

    public function test_get_context_returns_empty_context_envelope_without_proxy_key(): void
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( [ 'context' => null ], $response->get_data() );
    }

    public function test_create_context_stores_local_context_without_http_request(): void
    {
        $http_called = false;
        add_filter(
            'pre_http_request',
            static function () use ( &$http_called ) {
                $http_called = true;

                return new WP_Error( 'unexpected_http_call', 'Site context must not call a remote service.' );
            },
            10,
            3
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'pii_ack', true );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'local-site-context', $data['id'] ?? null );
        $this->assertSame( 'local_starter', $data['source'] ?? null );
        $this->assertTrue( $data['pii_ack'] ?? false );
        $this->assertStringContainsString( 'WordPress site', $data['summary_text'] ?? '' );
        $this->assertFalse( $http_called );
    }

    public function test_update_context_persists_manual_local_context(): void
    {
        update_option(
            'sentient_forms_site_context',
            [
                'id'                     => 'local-site-context',
                'license_id'             => 'local',
                'summary_text'           => 'Initial context',
                'source'                 => 'local_starter',
                'auto_include'           => true,
                'pii_ack'                => true,
                'free_refresh_available' => true,
                'next_free_refresh_at'   => null,
                'created_at'             => '2026-04-21 00:00:00',
                'updated_at'             => '2026-04-21 00:00:00',
            ],
            false
        );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'summary_text', 'Manual business context' );
        $request->set_param( 'auto_include', false );
        $request->set_param( 'pii_ack', true );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'Manual business context', $data['summary_text'] ?? null );
        $this->assertSame( 'manual', $data['source'] ?? null );
        $this->assertFalse( $data['auto_include'] ?? true );
    }

    public function test_item_schema_exposes_refresh_entitlement_fields(): void
    {
        $controller = new Sentient_Forms_Site_Context_Controller();
        $schema     = $controller->get_item_schema();

        $properties = $schema['properties'] ?? [];
        $this->assertArrayHasKey( 'free_refresh_available', $properties );
        $this->assertArrayHasKey( 'next_free_refresh_at', $properties );
    }
}
