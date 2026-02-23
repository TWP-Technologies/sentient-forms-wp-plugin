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
        add_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        remove_all_filters( 'pre_http_request' );
        parent::tearDown();
    }

    public function test_site_context_route_is_registered(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/site-context', $routes );
    }

    public function test_get_context_returns_null_when_cps_has_no_summary(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_key'    => '0abcdefghijklmnopqrstuvwxy',
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-key-context',
            ]
        );

        $this->mock_http_response(
            'GET',
            '/site-context',
            200,
            [
                'success' => true,
                'data'    => null,
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertNull( $response->get_data() );
    }

    public function test_create_context_propagates_cps_status_code(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_key'    => '0abcdefghijklmnopqrstuvwxy',
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-key-context',
            ]
        );

        $this->mock_http_response(
            'POST',
            '/site-context',
            402,
            [
                'error' => [
                    'message' => 'Insufficient credits remain to execute this action.',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/site-context' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'pii_ack', true );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 402, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'cps_error', $data['code'] ?? null );
    }

    public function test_item_schema_exposes_refresh_entitlement_fields(): void
    {
        $controller = new Sentient_Forms_Site_Context_Controller();
        $schema     = $controller->get_item_schema();

        $properties = $schema['properties'] ?? [];
        $this->assertArrayHasKey( 'free_refresh_available', $properties );
        $this->assertArrayHasKey( 'next_free_refresh_at', $properties );
    }

    private function mock_http_response( string $method, string $path_suffix, int $status, array $body ): void
    {
        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) use ( $method, $path_suffix, $status, $body ) {
                $request_method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
                if ( $request_method !== strtoupper( $method ) )
                {
                    return $preempt;
                }

                if ( ! str_ends_with( $url, $path_suffix ) )
                {
                    return $preempt;
                }

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( $body ),
                    'response' => [
                        'code'    => $status,
                        'message' => $status === 200 ? 'OK' : 'Error',
                    ],
                ];
            },
            10,
            3
        );
    }
}
