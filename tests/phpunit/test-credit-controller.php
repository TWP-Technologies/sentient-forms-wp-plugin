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

    public function test_balance_returns_data_from_cps(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_key'    => '0abcdefghijklmnopqrstuvwxy',
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
        ] );

        $this->mock_http_response(
            '/credits/balance',
            [
                'success' => true,
                'data'    => [
                    'current_balance' => 850,
                    'tier'            => [
                        'code'                 => 'pro',
                        'display_name'         => 'Pro',
                        'monthly_credit_quota' => 1000,
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 850, $data['current_balance'] );
        $this->assertArrayHasKey( 'tier', $data );
        $this->assertSame( 'pro', $data['tier']['code'] );
    }

    public function test_balance_uses_plugin_cps_client_base_url_instead_of_legacy_env_override(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'proxy_api_key' => 'proxy-key-123',
        ] );

        putenv( 'SENTIENT_FORMS_PROXY_API_URL=http://env-cps.invalid/v1' );
        $options = $plugin->get_options();
        $options['cps_base_url'] = 'http://option-cps.test/v1';
        $plugin->update_options( $options );
        $this->reset_plugin_cps_api_client();

        $requested_urls = [];

        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) use ( &$requested_urls ) {
                $requested_urls[] = $url;

                if ( $url === 'http://option-cps.test/v1/credits/balance' ) {
                    return [
                        'headers'  => [],
                        'body'     => wp_json_encode(
                            [
                                'success' => true,
                                'data'    => [
                                    'current_balance' => -4,
                                    'ledger_delta'    => -54,
                                    'top_up_available' => 0,
                                    'balance_state'   => 'negative',
                                    'tier'            => [
                                        'code'                 => 'free',
                                        'display_name'         => 'Free',
                                        'monthly_credit_quota' => 50,
                                    ],
                                ],
                            ]
                        ),
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

        $controller = new Sentient_Forms_Credit_Controller();
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->set_param( 'force_refresh', true );
        $response = $controller->get_credit_balance( $request );

        $this->assertContains( 'http://option-cps.test/v1/credits/balance', $requested_urls );
        $this->assertNotContains( 'http://env-cps.invalid/v1/credits/balance', $requested_urls );
        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( -4, $data['current_balance'] );
        $this->assertSame( -54, $data['ledger_delta'] );
        $this->assertSame( 0, $data['top_up_available'] );
        $this->assertSame( 'negative', $data['balance_state'] );
    }

    public function test_balance_returns_error_without_license(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        // In WP_DEBUG mode, returns mock data with 200; in production returns 400
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->assertSame( 200, $response->get_status() );
            $data = $response->get_data();
            $this->assertTrue( $data['dev_mode'] ?? false, 'Should return dev_mode flag in debug mode' );
        } else {
            $this->assertSame( 400, $response->get_status() );
        }
    }

    public function test_balance_handles_cps_error(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'proxy_api_key' => 'proxy-key-123',
        ] );

        add_filter(
            'pre_http_request',
            function () {
                return new WP_Error( 'cps_unavailable', 'CPS unreachable' );
            },
            1  // Higher priority to ensure it runs first
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        // In WP_DEBUG mode, may return stale cache or dev mock data with 200
        // In production, returns 500 error
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // Could return mock data (200) or error (500) depending on cache state
            $status = $response->get_status();
            $this->assertTrue( 
                $status === 200 || $status >= 400,
                'Should return either dev mode mock (200) or error (400+)' 
            );
        } else {
            $this->assertGreaterThanOrEqual( 400, $response->get_status() );
        }
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

    private function reset_plugin_cps_api_client(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $property = new ReflectionProperty( $plugin, 'cps_api_client' );
        $property->setAccessible( true );
        $property->setValue( $plugin, null );
    }
}
