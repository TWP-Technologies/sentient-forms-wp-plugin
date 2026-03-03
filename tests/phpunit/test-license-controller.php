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
        $this->assertArrayHasKey( '/sentient-forms/v1/license/bootstrap', $routes, 'Bootstrap route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing-state', $routes, 'Billing-state route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/checkout-session', $routes, 'Checkout-session route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/subscription-change', $routes, 'Subscription-change route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/top-up-session', $routes, 'Top-up-session route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/portal-session', $routes, 'Portal-session route should be registered' );
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
            '/license/activate',
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
            'license_key' => '0abcdefghjkmnpqrstvwxyz123',
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

    public function test_bootstrap_license_success(): void
    {
        $this->mock_http_response(
            '/license/bootstrap',
            [
                'success' => true,
                'data'    => [
                    'license_id'    => 'lic-free-123',
                    'site_id'       => 'site-free-456',
                    'proxy_api_key' => 'bootstrap-proxy-key',
                    'status'        => 'active',
                    'tier'          => 'free',
                    'expiry_date'   => null,
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/bootstrap' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'active', $data['status'] );
        $this->assertTrue( $data['proxy_key_present'] );
        $this->assertSame( 'free', $data['tier'] );
    }

    public function test_get_billing_state_success(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/billing/state',
            [
                'success' => true,
                'data'    => [
                    'provider' => 'stripe',
                    'credits'  => [
                        'current_balance' => 100,
                        'tier_quota'      => 100,
                        'ledger_delta'    => 0,
                    ],
                    'allocation' => [
                        'seat_quantity'            => 1,
                        'tier_site_limit'          => 1,
                        'allowed_sites'            => 1,
                        'active_sites'             => 1,
                        'over_limit'               => false,
                        'blocked_new_activations'  => false,
                        'grace_expires_at'         => null,
                        'capacity_policy'          => 'tier_x_quantity_v1',
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'stripe', $data['provider'] );
        $this->assertSame( 100, $data['credits']['current_balance'] );
        $this->assertSame( 1, $data['allocation']['allowed_sites'] );
        $this->assertFalse( $data['allocation']['blocked_new_activations'] );
    }

    public function test_create_checkout_session_with_plan_code(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/billing/checkout/session',
            [
                'success' => true,
                'data'    => [
                    'session_id'   => 'cs_test_123',
                    'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                    'customer_id'  => 'cus_test_123',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/checkout-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'plan_code'   => 'starter',
            'success_url' => 'https://example.test/success',
            'cancel_url'  => 'https://example.test/cancel',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'cs_test_123', $data['session_id'] );
    }

    public function test_create_checkout_session_requires_price_or_plan(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/checkout-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'success_url' => 'https://example.test/success',
            'cancel_url'  => 'https://example.test/cancel',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'invalid_request', $data['code'] ?? null );
    }

    public function test_change_subscription_success(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/billing/subscription/change',
            [
                'success' => true,
                'data'    => [
                    'provider_subscription_id'  => 'sub_test_123',
                    'provider_price_id'         => 'price_business_monthly',
                    'plan_code'                 => 'business',
                    'change_timing'             => 'start_next_cycle',
                    'effective_at'              => '2030-02-01T00:00:00Z',
                    'renewal_grant_applied'     => false,
                    'carryover_grant_applied'   => false,
                    'carryover_credits_granted' => 0,
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/subscription-change' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'plan_code'     => 'business',
            'change_timing' => 'start_next_cycle',
            'quantity'      => 1,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'business', $data['plan_code'] );
        $this->assertSame( 'start_next_cycle', $data['change_timing'] );
    }

    public function test_create_portal_session_success(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/billing/portal/session',
            [
                'success' => true,
                'data'    => [
                    'session_id' => 'bps_test_123',
                    'portal_url' => 'https://billing.stripe.com/p/session/bps_test_123',
                    'customer_id' => 'cus_test_123',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/portal-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'return_url' => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'bps_test_123', $data['session_id'] );
        $this->assertStringContainsString( 'billing.stripe.com', $data['portal_url'] );
    }

    public function test_create_top_up_checkout_session_success(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/billing/checkout/top-up-session',
            [
                'success' => true,
                'data'    => [
                    'session_id'      => 'cs_test_topup_123',
                    'checkout_url'    => 'https://checkout.stripe.com/c/pay/cs_test_topup_123',
                    'customer_id'     => 'cus_test_123',
                    'top_up_credits'  => 5000,
                    'pack_code'       => 'top_up_small',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/top-up-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'pack_code'   => 'top_up_small',
            'success_url' => 'https://example.test/success',
            'cancel_url'  => 'https://example.test/cancel',
            'quantity'    => 1,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'cs_test_topup_123', $data['session_id'] );
        $this->assertSame( 5000, $data['top_up_credits'] );
    }

    public function test_get_billing_state_requires_active_proxy_key(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'no_active_license', $data['code'] ?? null );
    }

    public function test_deactivate_license_clears_data(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_key'    => '0abcdefghjkmnpqrstvwxyz123',
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-to-deactivate',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/license/deactivate',
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
            1,  // Highest priority to ensure mock fires first
            3
        );
    }
}
