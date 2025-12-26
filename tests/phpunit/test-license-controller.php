<?php
/**
 * License Controller Tests
 *
 * @package SentientForms\Tests
 */

class LicenseControllerTest extends WP_UnitTestCase
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
        $this->load_rest_dependencies();
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

    private function load_rest_dependencies(): void
    {
        $includes = dirname( __DIR__, 2 ) . '/includes';
        $deps = [
            'rest-api/class-rest-api.php',
            'rest-api/permissions/trait-permission-utils.php',
            'rest-api/permissions/class-admin-permission.php',
            'rest-api/validators/trait-validation-utils.php',
        ];
        foreach ( $deps as $file ) {
            $path = $includes . '/' . $file;
            if ( file_exists( $path ) && ! class_exists( basename( $file, '.php' ) ) ) {
                require_once $path;
            }
        }
    }

    public function test_license_route_is_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/license', $routes, 'License route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/activate', $routes, 'Activate route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/deactivate', $routes, 'Deactivate route should be registered' );
    }

    public function test_get_license_info_returns_masked_key(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_key'    => '1234567890abcdefghijklmnop',
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'tier'           => 'pro',
        ] );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'active', $data['status'] );
        $this->assertTrue( $data['proxy_key_present'] );
        $this->assertSame( 'pro', $data['tier'] );
        $this->assertStringContainsString( '****', $data['license_key_masked'] );
        $this->assertStringStartsWith( '1234', $data['license_key_masked'] );
    }

    public function test_get_license_info_returns_inactive_without_license(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'inactive', $data['status'] );
        $this->assertFalse( $data['proxy_key_present'] );
    }

    public function test_activate_license_success(): void
    {
        $this->mock_http_response(
            '/licensing/activate',
            [
                'success' => true,
                'data'    => [
                    'license_id'    => 'lic-uuid-123',
                    'site_id'       => 'site-uuid-456',
                    'proxy_api_key' => 'new-proxy-key-789',
                    'status'        => 'active',
                    'tier'          => 'starter',
                    'expiry_date'   => '2025-12-31T23:59:59Z',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/activate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'license_key' => '0abcdefghijklmnopqrstuvwxy',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'active', $data['status'] );
        $this->assertTrue( $data['proxy_key_present'] );
    }

    public function test_activate_license_invalid_format_is_rejected(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/activate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'license_key' => 'invalid-short-key',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_deactivate_license_clears_data(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_key'    => '0abcdefghijklmnopqrstuvwxy',
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-to-deactivate',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/licensing/deactivate',
            [
                'success' => true,
                'message' => 'License deactivated successfully.',
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/deactivate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'inactive', $data['status'] );
    }

    public function test_deactivate_without_license_returns_error(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/deactivate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
    }

    private function mock_http_response( string $path_suffix, array $body ): void
    {
        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) use ( $path_suffix, $body ) {
                if ( str_ends_with( $url, $path_suffix ) ) {
                    return [
                        'headers'  => [],
                        'body'     => wp_json_encode( $body ),
                        'response' => [
                            'code'    => 200,
                            'message' => 'OK',
                        ],
                    ];
                }
                return $preempt;
            },
            10,
            3
        );
    }
}
