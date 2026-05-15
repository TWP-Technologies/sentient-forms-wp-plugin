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
        $this->assertArrayHasKey( '/sentient-forms/v1/license/managed-checkout/start', $routes, 'Managed checkout start route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/managed-checkout/complete', $routes, 'Managed checkout complete route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/checkout-session', $routes, 'Checkout-session route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/subscription-change', $routes, 'Subscription-change route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/top-up-session', $routes, 'Top-up-session route should be registered' );
        $this->assertArrayHasKey( '/sentient-forms/v1/license/billing/portal-session', $routes, 'Portal-session route should be registered' );
    }

    public function test_cps_base_url_defaults_to_production_api(): void
    {
        $plugin           = Sentient_Forms_Plugin::instance();
        $previous_options = $plugin->get_options();

        $plugin->update_options( [] );

        try
        {
            $this->assertSame( 'https://api.sentientforms.com/v1', $plugin->get_cps_base_url_value() );
        }
        finally
        {
            $plugin->update_options( $previous_options );
        }
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
            '/v2/account/sites/activate',
            [
                'success' => true,
                'data'    => [
                    'service'       => 'sentient-managed',
                    'license_id'    => 'lic-uuid-123',
                    'site_id'       => 'site-uuid-456',
                    'proxy_api_key' => 'new-proxy-key-789',
                    'status'        => 'active',
                    'tier'          => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1500,
                    ],
                    'expiry_date'   => '2025-12-31T23:59:59Z',
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'POST', $args['method'] ?? null );
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );

                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( '0abcdefghjkmnpqrstvwxyz123', $body['license_key'] ?? null );
                $this->assertSame( home_url(), $body['site_url'] ?? null );
                $this->assertNotEmpty( $body['local_site_identifier'] ?? '' );
            }
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
        $this->assertSame( 'starter', $data['tier']['code'] ?? null );
    }

    public function test_start_managed_checkout_records_consent_and_does_not_require_proxy_key(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/start',
            [
                'success' => true,
                'data'    => [
                    'checkout_intent_id'  => 'mci_123',
                    'checkout_session_id' => 'cs_test_123',
                    'checkout_url'        => 'https://checkout.stripe.com/c/pay/cs_test_123',
                    'plan_code'           => 'starter',
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'POST', $args['method'] ?? null );
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );

                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( 'starter', $body['plan_code'] ?? null );
                $this->assertSame( home_url(), $body['site_url'] ?? null );
                $this->assertNotEmpty( $body['local_site_identifier'] ?? '' );
                $this->assertSame( 'managed-service-v1', $body['disclosure_version'] ?? null );
                $this->assertTrue( $body['accepted_managed_service_terms'] ?? false );
                $this->assertTrue( $body['require_zdr'] ?? false );
                $this->assertArrayNotHasKey( 'trial_period_days', $body );
                $this->assertArrayNotHasKey( 'quantity', $body );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'cancel_url'                     => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => true,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'mci_123', $data['checkout_intent_id'] );
        $this->assertTrue( $data['consent_recorded'] );
        $this->assertNotEmpty( $data['consent_id'] );
    }

    public function test_managed_checkout_uses_same_local_site_identifier_for_start_and_complete(): void
    {
        Sentient_Forms_Plugin::instance()->update_options( [] );

        $requests = [];
        $handler  = function ( $preempt, $args, $url ) use ( &$requests ) {
            if ( str_ends_with( $url, '/v2/account/checkout/start' ) ) {
                $requests['start'] = json_decode( (string) ( $args['body'] ?? '' ), true );

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [
                        'success' => true,
                        'data'    => [
                            'checkout_intent_id'  => 'mci_stable_local',
                            'checkout_session_id' => 'cs_test_stable_local',
                            'checkout_url'        => 'https://checkout.stripe.com/c/pay/cs_test_stable_local',
                            'plan_code'           => 'starter',
                        ],
                    ] ),
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                ];
            }

            if ( str_ends_with( $url, '/v2/account/checkout/complete' ) ) {
                $requests['complete'] = json_decode( (string) ( $args['body'] ?? '' ), true );

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( [
                        'success' => true,
                        'data'    => [
                            'activation_ready' => false,
                            'status'           => 'fulfilled',
                            'message'          => 'Waiting for the Stripe webhook.',
                        ],
                    ] ),
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                ];
            }

            return $preempt;
        };
        add_filter( 'pre_http_request', $handler, 1, 3 );

        $start_request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
        $start_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $start_request->add_header( 'Content-Type', 'application/json' );
        $start_request->set_body( wp_json_encode( [
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'cancel_url'                     => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => true,
        ] ) );
        $start_response = rest_get_server()->dispatch( $start_request );

        $complete_request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $complete_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $complete_request->add_header( 'Content-Type', 'application/json' );
        $complete_request->set_body( wp_json_encode( [
            'checkout_intent_id'  => 'mci_stable_local',
            'checkout_session_id' => 'cs_test_stable_local',
            'activation_token'    => 'token-stable-local',
        ] ) );
        $complete_response = rest_get_server()->dispatch( $complete_request );
        remove_filter( 'pre_http_request', $handler, 1 );

        $this->assertSame( 200, $start_response->get_status() );
        $this->assertSame( 200, $complete_response->get_status() );
        $this->assertNotEmpty( $requests['start']['local_site_identifier'] ?? '' );
        $this->assertSame(
            $requests['start']['local_site_identifier'],
            $requests['complete']['local_site_identifier'] ?? null,
            'Managed checkout completion must use the same durable local site identifier as checkout start.'
        );

        $options = Sentient_Forms_Plugin::instance()->get_options();
        $this->assertSame(
            $requests['start']['local_site_identifier'],
            $options['license']['local_site_identifier'] ?? null,
            'The generated local site identifier must be persisted before returning from checkout start.'
        );
    }

    public function test_start_managed_checkout_requires_disclosure_consent_before_remote_call(): void
    {
        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Managed checkout must not call the remote service without consent: ' . $url );
            return $preempt;
        };
        add_filter( 'pre_http_request', $guard, 1, 3 );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/success',
            'cancel_url'                     => 'https://example.test/cancel',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => false,
        ] ) );
        $response = rest_get_server()->dispatch( $request );
        remove_filter( 'pre_http_request', $guard, 1 );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_managed_checkout_consent_required', $data['code'] ?? null );
    }

    public function test_complete_managed_checkout_stores_license_and_enables_managed_provider(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => true,
                'data'    => [
                    'activation_ready' => true,
                    'service'          => 'sentient-managed',
                    'license_key'      => '0abcdefghjkmnpqrstvwxyz123',
                    'license_id'       => 'lic-managed-123',
                    'site_id'          => 'site-managed-456',
                    'proxy_api_key'    => 'managed-proxy-key',
                    'status'           => 'active',
                    'tier'             => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1500,
                    ],
                    'expires_at'       => null,
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'POST', $args['method'] ?? null );
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );

                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( home_url(), $body['site_url'] ?? null );
                $this->assertNotEmpty( $body['local_site_identifier'] ?? '' );
                $this->assertSame( 'mci_123', $body['checkout_intent_id'] ?? null );
                $this->assertSame( 'cs_test_123', $body['checkout_session_id'] ?? null );
                $this->assertSame( 'token-123', $body['activation_token'] ?? null );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id'  => 'mci_123',
            'checkout_session_id' => 'cs_test_123',
            'activation_token'    => 'token-123',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['activation_ready'] );
        $this->assertTrue( $data['managed_provider_ready'] );
        $this->assertNotEmpty( $data['credential_id'] );

        $license = Sentient_Forms_Plugin::instance()->get_license_data();
        $this->assertSame( 'active', $license['license_status'] );
        $this->assertSame( 'managed-proxy-key', $license['proxy_api_key'] );
        $this->assertSame( 'lic-managed-123', $license['license_id'] );
        $this->assertSame( 'site-managed-456', $license['site_id'] );

        global $wpdb;
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $credential = $credentials->find_by_provider_auth_mode( 'sentient_managed', 'sentient_proxy' );
        $this->assertIsArray( $credential );
        $this->assertSame( 'valid', $credential['status'] );
        $this->assertTrue( $credential['status_json']['proxy_key_present'] ?? false );
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

    public function test_bootstrap_license_without_stored_key_returns_local_inactive_state(): void
    {
        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Bootstrap without a stored license key should not call the remote service: ' . $url );
            return $preempt;
        };
        add_filter(
            'pre_http_request',
            $guard,
            1,
            3
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/bootstrap' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );
        remove_filter( 'pre_http_request', $guard, 1 );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'inactive', $data['status'] );
        $this->assertFalse( $data['proxy_key_present'] );
        $this->assertNull( $data['tier'] );
    }

    public function test_bootstrap_license_with_invalid_stored_key_returns_local_inactive_state(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data( [
            'license_key'    => 'preserve-license',
            'license_status' => 'inactive',
            'proxy_api_key'  => '',
        ] );

        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Bootstrap with an invalid stored license key should not call the remote service: ' . $url );
            return $preempt;
        };
        add_filter(
            'pre_http_request',
            $guard,
            1,
            3
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/bootstrap' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );
        remove_filter( 'pre_http_request', $guard, 1 );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'inactive', $data['status'] );
        $this->assertFalse( $data['proxy_key_present'] );
        $this->assertNull( $data['tier'] );
    }

    public function test_bootstrap_license_with_stored_key_uses_v2_activation(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data( [
            'license_key'    => '0abcdefghjkmnpqrstvwxyz123',
            'license_status' => 'inactive',
            'proxy_api_key'  => '',
        ] );

        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'success' => true,
                'data'    => [
                    'service'       => 'sentient-managed',
                    'license_id'    => 'lic-free-123',
                    'site_id'       => 'site-free-456',
                    'proxy_api_key' => 'bootstrap-proxy-key',
                    'status'        => 'active',
                    'tier'          => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1500,
                    ],
                    'expiry_date'   => null,
                ],
            ],
            function ( array $args ): void {
                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( '0abcdefghjkmnpqrstvwxyz123', $body['license_key'] ?? null );
                $this->assertSame( home_url(), $body['site_url'] ?? null );
                $this->assertNotEmpty( $body['local_site_identifier'] ?? '' );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/bootstrap' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'active', $data['status'] );
        $this->assertTrue( $data['proxy_key_present'] );
        $this->assertSame( 'starter', $data['tier']['code'] ?? null );
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
            '/v2/billing/state',
            [
                'success' => true,
                'data'    => [
                    'service' => 'sentient-managed',
                    'status'  => 'trial',
                    'plan'    => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1500,
                    ],
                    'billing' => [
                        'provider'        => 'stripe',
                        'managed_enabled' => true,
                        'customer_id'     => 'cus_test_123',
                        'subscription'    => [
                            'provider_subscription_id' => 'sub_test_123',
                            'status'                   => 'trialing',
                            'quantity'                 => 1,
                        ],
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
                    'managed_usage' => [
                        'execution_count' => 0,
                        'succeeded_count' => 0,
                        'failed_count'    => 0,
                        'token_usage'     => [
                            'input_tokens'  => 0,
                            'output_tokens' => 0,
                            'total_tokens'  => 0,
                        ],
                        'billing'         => [
                            'billed_amount_microusd' => 0,
                            'currency'               => 'USD',
                        ],
                    ],
                    'billing_boundary' => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'GET', $args['method'] ?? null );
                $this->assertSame( 'Bearer proxy-key-123', $args['headers']['Authorization'] ?? null );
                $this->assertArrayNotHasKey( 'X-API-Key', $args['headers'] ?? [] );
                $this->assertTrue(
                    ! isset( $args['body'] ) || null === $args['body'] || '' === $args['body'],
                    'Managed billing state should not send a request body.'
                );
            }
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient-managed', $data['service'] );
        $this->assertSame( 'stripe', $data['billing']['provider'] );
        $this->assertTrue( $data['billing']['managed_enabled'] );
        $this->assertSame( 1, $data['allocation']['allowed_sites'] );
        $this->assertFalse( $data['allocation']['blocked_new_activations'] );
        $this->assertSame( 'trial', $data['status'] );
        $this->assertSame( 'starter', $data['plan']['code'] );
        $this->assertSame( 0, $data['managed_usage']['execution_count'] );
        $this->assertFalse( $data['billing_boundary']['direct_openrouter_billed_by_sentient'] );
        $this->assertTrue( $data['billing_boundary']['managed_proxy_billed_by_sentient'] );

        $updated_license = $plugin->get_license_data();
        $this->assertSame( 'trial', $updated_license['license_status'] );
        $this->assertSame( 'starter', $updated_license['tier']['code'] ?? null );
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
            '/v2/billing/checkout/session',
            [
                'success' => true,
                'data'    => [
                    'session_id'   => 'cs_test_123',
                    'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                    'customer_id'  => 'cus_test_123',
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'POST', $args['method'] ?? null );
                $this->assertSame( 'Bearer proxy-key-123', $args['headers']['Authorization'] ?? null );
                $this->assertArrayNotHasKey( 'X-API-Key', $args['headers'] ?? [] );

                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( 'starter', $body['plan_code'] ?? null );
                $this->assertArrayNotHasKey( 'trial_period_days', $body );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/checkout-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'plan_code'         => 'starter',
            'success_url'       => 'https://example.test/success',
            'cancel_url'        => 'https://example.test/cancel',
            'trial_period_days' => 14,
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

    public function test_change_subscription_returns_portal_required_error(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Subscription-change should not call the legacy billing service: ' . $url );
            return $preempt;
        };
        add_filter( 'pre_http_request', $guard, 1, 3 );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/subscription-change' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'plan_code'     => 'business',
            'change_timing' => 'start_next_cycle',
            'quantity'      => 1,
        ] ) );
        $response = rest_get_server()->dispatch( $request );
        remove_filter( 'pre_http_request', $guard, 1 );

        $this->assertSame( 410, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'managed_subscription_change_uses_portal', $data['code'] ?? null );
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
            '/v2/billing/portal/session',
            [
                'success' => true,
                'data'    => [
                    'session_id' => 'bps_test_123',
                    'portal_url' => 'https://billing.stripe.com/p/session/bps_test_123',
                    'customer_id' => 'cus_test_123',
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'Bearer proxy-key-123', $args['headers']['Authorization'] ?? null );
                $this->assertArrayNotHasKey( 'X-API-Key', $args['headers'] ?? [] );
            }
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

    public function test_create_portal_session_passes_flow_type_and_subscription_id(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $this->mock_http_response(
            '/v2/billing/portal/session',
            [
                'success' => true,
                'data'    => [
                    'session_id'  => 'bps_test_456',
                    'portal_url'  => 'https://billing.stripe.com/p/session/bps_test_456',
                    'customer_id' => 'cus_test_123',
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'Bearer proxy-key-123', $args['headers']['Authorization'] ?? null );
                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( 'https://example.test/licensing', $body['return_url'] ?? null );
                $this->assertSame( 'subscription_update', $body['flow_type'] ?? null );
                $this->assertSame( 'sub_test_123', $body['subscription_id'] ?? null );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/portal-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'return_url'      => 'https://example.test/licensing',
            'flow_type'       => 'subscription_update',
            'subscription_id' => 'sub_test_123',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'bps_test_456', $data['session_id'] );
    }

    public function test_create_top_up_checkout_session_returns_unsupported(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
        ] );

        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Top-up checkout should not call the legacy billing service: ' . $url );
            return $preempt;
        };
        add_filter( 'pre_http_request', $guard, 1, 3 );

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
        remove_filter( 'pre_http_request', $guard, 1 );

        $this->assertSame( 410, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'managed_top_up_unsupported', $data['code'] ?? null );
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
            '/v2/account/sites/deactivate',
            [
                'success' => true,
                'data'    => [
                    'service'    => 'sentient-managed',
                    'status'     => 'inactive',
                    'license_id' => 'lic-uuid-123',
                    'site_id'    => 'site-uuid-456',
                    'message'    => 'Site deactivated successfully.',
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'POST', $args['method'] ?? null );
                $this->assertSame( 'Bearer proxy-key-to-deactivate', $args['headers']['Authorization'] ?? null );

                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( 'site-uuid-456', $body['site_id'] ?? null );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/deactivate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();

        $this->assertSame( 'inactive', $data['status'] );
    }

    public function test_deactivate_without_site_id_returns_error(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-to-deactivate',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => '',
        ] );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/deactivate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'no_active_site', $data['code'] ?? null );
    }

    public function test_deactivate_without_license_returns_error(): void
    {
        Sentient_Forms_Plugin::instance()->clear_license_data();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/deactivate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
    }

    private function mock_http_response( string $path_suffix, array | string $body, ?callable $assert_request = null, int $status_code = 200 ): void
    {
        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) use ( $path_suffix, $body, $assert_request, $status_code ) {
                if ( str_ends_with( $url, $path_suffix ) ) {
                    if ( is_callable( $assert_request ) ) {
                        $assert_request( is_array( $args ) ? $args : [] );
                    }
                    return [
                        'headers'  => [],
                        'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
                        'response' => [
                            'code'    => $status_code,
                            'message' => $status_code >= 400 ? 'Error' : 'OK',
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
