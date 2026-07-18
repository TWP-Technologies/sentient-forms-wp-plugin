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
                    'license_id'    => '11111111-1111-4111-8111-111111111111',
                    'site_id'       => '22222222-2222-4222-8222-222222222222',
                    'proxy_api_key' => 'new-proxy-key-789',
                    'status'        => 'active',
                    'site_url'      => home_url(),
                    'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                    'tier'          => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1000,
                    ],
                    'expiry_date'   => '2025-12-31',
                    'billing_boundary' => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
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

    public function test_activate_license_rejects_mismatched_response_identity_before_mutation(): void
    {
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $payload = $this->managed_activation_payload();
        $payload['site_url'] = 'https://other-site.example/';
        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/activate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'license_key' => '0abcdefghjkmnpqrstvwxyz123',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_identity', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
    }

    public function test_activate_license_accepts_cps_equivalent_site_url_identity(): void
    {
        $payload = $this->managed_activation_payload();
        $payload['site_url'] = 'https://example.test/';
        $payload['local_site_identifier'] = Sentient_Forms_Plugin::instance()->get_local_site_identifier();
        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/activate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'license_key' => '0abcdefghjkmnpqrstvwxyz123',
            'site_url'    => 'HTTPS://EXAMPLE.TEST:443',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $response->get_data()['proxy_key_present'] ?? false );
    }

    public function test_activate_license_rejects_noncanonical_expiry_before_mutation(): void
    {
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'success' => true,
                'data'    => [
                    'service'       => 'sentient-managed',
                    'license_id'    => '11111111-1111-4111-8111-111111111111',
                    'site_id'       => '22222222-2222-4222-8222-222222222222',
                    'proxy_api_key' => 'must-not-be-stored',
                    'status'        => 'active',
                    'site_url'      => home_url(),
                    'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                    'tier'          => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1000,
                    ],
                    'expiry_date'   => '2025-12-31T23:59:59Z',
                    'billing_boundary' => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
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

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_expiry', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
    }

    public function test_activate_license_rejects_unsuccessful_envelope_before_mutation(): void
    {
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'success' => false,
                'data'    => $this->managed_activation_payload(),
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/activate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'license_key' => '0abcdefghjkmnpqrstvwxyz123',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_response', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
    }

    public function test_activate_license_rejects_noncanonical_status_before_mutation(): void
    {
        $before  = Sentient_Forms_Plugin::instance()->get_license_data();
        $payload = $this->managed_activation_payload();
        $payload['status'] = 'provisioning';
        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/activate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'license_key' => '0abcdefghjkmnpqrstvwxyz123',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_response', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
    }

    public function test_start_managed_checkout_records_consent_and_does_not_require_proxy_key(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/start',
            [
                'success' => true,
                'data'    => [
                    'service'             => 'sentient-managed',
                    'status'              => 'open',
                    'checkout_intent_id'  => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                    'checkout_session_id' => 'cs_test_123',
                    'checkout_url'        => 'https://checkout.stripe.com/c/pay/cs_test_123',
                    'plan_code'           => 'starter',
                    'billing_interval'    => 'monthly',
                    'billing_boundary'    => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'POST', $args['method'] ?? null );
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );

                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( '44444444-4444-4444-8444-444444444444', $body['checkout_attempt_id'] ?? null );
                $this->assertSame( 'starter', $body['plan_code'] ?? null );
                $this->assertSame( 'monthly', $body['billing_interval'] ?? null );
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
            'checkout_attempt_id'             => '44444444-4444-4444-8444-444444444444',
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'cancel_url'                     => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => true,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', $data['checkout_intent_id'] );
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
                            'service'             => 'sentient-managed',
                            'status'              => 'open',
                            'checkout_intent_id'  => '33333333-3333-4333-8333-333333333333',
                            'checkout_session_id' => 'cs_test_stable_local',
                            'checkout_url'        => 'https://checkout.stripe.com/c/pay/cs_test_stable_local',
                            'plan_code'           => 'starter',
                            'billing_interval'    => 'monthly',
                            'billing_boundary'    => [
                                'direct_openrouter_billed_by_sentient' => false,
                                'managed_proxy_billed_by_sentient'     => true,
                            ],
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
                            'service'          => 'sentient-managed',
                            'activation_ready' => false,
                            'status'           => 'pending',
                            'pending_reason'   => 'Stripe has not finished provisioning this checkout yet. Retry shortly.',
                            'site_url'         => $requests['complete']['site_url'],
                            'local_site_identifier' => $requests['complete']['local_site_identifier'],
                            'billing_boundary' => [
                                'direct_openrouter_billed_by_sentient' => false,
                                'managed_proxy_billed_by_sentient'     => true,
                            ],
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
            'checkout_attempt_id'             => '44444444-4444-4444-8444-444444444444',
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
            'checkout_intent_id'  => '33333333-3333-4333-8333-333333333333',
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

    public function test_start_managed_checkout_rejects_noncanonical_response(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/start',
            [
                'success' => true,
                'data'    => [
                    'checkout_intent_id'  => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                    'checkout_session_id' => 'cs_test_missing_contract_fields',
                    'checkout_url'        => 'https://checkout.stripe.com/c/pay/cs_test_missing_contract_fields',
                    'plan_code'           => 'starter',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_attempt_id'             => '44444444-4444-4444-8444-444444444444',
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'cancel_url'                     => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => true,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
    }

    public function test_start_managed_checkout_rejects_mismatched_response_plan(): void
    {
        $payload = $this->managed_checkout_start_payload();
        $payload['plan_code'] = 'pro';
        $this->mock_http_response(
            '/v2/account/checkout/start',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_attempt_id'             => '44444444-4444-4444-8444-444444444444',
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/success',
            'cancel_url'                     => 'https://example.test/cancel',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => true,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
    }

    public function test_start_managed_checkout_rejects_non_string_response_field(): void
    {
        $payload = $this->managed_checkout_start_payload();
        $payload['checkout_session_id'] = 123;
        $this->mock_http_response(
            '/v2/account/checkout/start',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_attempt_id'             => '44444444-4444-4444-8444-444444444444',
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/success',
            'cancel_url'                     => 'https://example.test/cancel',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => true,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
    }

    public function test_start_managed_checkout_rejects_missing_success_envelope(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/start',
            [
                'data' => $this->managed_checkout_start_payload(),
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_attempt_id'             => '44444444-4444-4444-8444-444444444444',
            'plan_code'                      => 'starter',
            'success_url'                    => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'cancel_url'                     => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
            'disclosure_version'             => 'managed-service-v1',
            'accepted_managed_service_terms' => true,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
    }

    public function test_complete_managed_checkout_rejects_noncanonical_pending_response(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => true,
                'data'    => [
                    'activation_ready' => false,
                    'status'           => 'pending',
                    'message'          => 'Waiting for Stripe.',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id' => '33333333-3333-4333-8333-333333333333',
            'activation_token'   => 'token-noncanonical-pending',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
    }

    public function test_complete_managed_checkout_rejects_mismatched_pending_identity(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => true,
                'data'    => [
                    'service'               => 'sentient-managed',
                    'activation_ready'      => false,
                    'status'                => 'pending',
                    'pending_reason'        => 'Managed execution setup is still synchronizing.',
                    'site_url'              => 'https://other-site.example/',
                    'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                    'billing_boundary'      => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id' => '33333333-3333-4333-8333-333333333333',
            'activation_token'   => 'token-mismatched-pending',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
    }

    public function test_complete_managed_checkout_rejects_non_string_pending_field(): void
    {
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => true,
                'data'    => [
                    'service'               => 'sentient-managed',
                    'activation_ready'      => false,
                    'status'                => 'pending',
                    'pending_reason'        => 123,
                    'site_url'              => home_url(),
                    'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                    'billing_boundary'      => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id' => '33333333-3333-4333-8333-333333333333',
            'activation_token'   => 'token-non-string-pending',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
    }

    public function test_complete_managed_checkout_rejects_unsuccessful_envelope_before_mutation(): void
    {
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => false,
                'data'    => array_merge(
                    $this->managed_activation_payload(),
                    [
                        'activation_ready' => true,
                        'license_key'      => '0abcdefghjkmnpqrstvwxyz123',
                    ]
                ),
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id' => '33333333-3333-4333-8333-333333333333',
            'activation_token'   => 'token-unsuccessful-envelope',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_checkout_invalid_response', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
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
            'checkout_attempt_id'             => '55555555-5555-4555-8555-555555555555',
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

    public function test_start_managed_checkout_rejects_annual_interval_before_remote_call(): void
    {
        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Annual checkout should be rejected before remote checkout start: ' . $url );
            return $preempt;
        };
        add_filter( 'pre_http_request', $guard, 1, 3 );

        try
        {
            $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/start' );
            $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $request->add_header( 'Content-Type', 'application/json' );
            $request->set_body( wp_json_encode( [
                'checkout_attempt_id'             => '66666666-6666-4666-8666-666666666666',
                'plan_code'                      => 'starter',
                'billing_interval'               => 'annual',
                'success_url'                    => 'https://example.test/success',
                'cancel_url'                     => 'https://example.test/cancel',
                'disclosure_version'             => 'managed-service-v1',
                'accepted_managed_service_terms' => true,
            ] ) );
            $response = rest_get_server()->dispatch( $request );
        }
        finally
        {
            remove_filter( 'pre_http_request', $guard, 1 );
        }

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_managed_checkout_invalid_interval', $data['code'] ?? null );
    }

    public function test_checkout_routes_reject_invalid_attempt_identity_before_remote_call(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
        ] );

        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Invalid checkout attempts must not call the remote service: ' . $url );
            return $preempt;
        };
        add_filter( 'pre_http_request', $guard, 1, 3 );

        try
        {
            $requests = [
                [
                    'route' => '/sentient-forms/v1/license/managed-checkout/start',
                    'body'  => [
                        'checkout_attempt_id'             => 'not-a-uuid',
                        'plan_code'                       => 'starter',
                        'success_url'                     => 'https://example.test/success',
                        'cancel_url'                      => 'https://example.test/cancel',
                        'disclosure_version'              => 'managed-service-v1',
                        'accepted_managed_service_terms'  => true,
                    ],
                ],
                [
                    'route' => '/sentient-forms/v1/license/billing/checkout-session',
                    'body'  => [
                        'checkout_attempt_id' => 'not-a-uuid',
                        'plan_code'           => 'starter',
                        'success_url'         => 'https://example.test/success',
                        'cancel_url'          => 'https://example.test/cancel',
                    ],
                ],
                [
                    'route' => '/sentient-forms/v1/license/billing/top-up-session',
                    'body'  => [
                        'checkout_attempt_id' => 'not-a-uuid',
                        'pack_code'           => 'top_up_small',
                        'success_url'         => 'https://example.test/success',
                        'cancel_url'          => 'https://example.test/cancel',
                    ],
                ],
            ];

            foreach ( $requests as $request_fixture )
            {
                $request = new WP_REST_Request( 'POST', $request_fixture['route'] );
                $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
                $request->add_header( 'Content-Type', 'application/json' );
                $request->set_body( wp_json_encode( $request_fixture['body'] ) );
                $response = rest_get_server()->dispatch( $request );

                $this->assertSame( 400, $response->get_status(), $request_fixture['route'] );
                $this->assertSame( 'rest_invalid_param', $response->get_data()['code'] ?? null, $request_fixture['route'] );
            }
        }
        finally
        {
            remove_filter( 'pre_http_request', $guard, 1 );
        }
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
                    'license_id'       => '77777777-7777-4777-8777-777777777777',
                    'site_id'          => '88888888-8888-4888-8888-888888888888',
                    'proxy_api_key'    => 'managed-proxy-key',
                    'status'           => 'active',
                    'site_url'         => home_url(),
                    'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                    'tier'             => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1000,
                    ],
                    'billing_boundary' => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'POST', $args['method'] ?? null );
                $this->assertArrayNotHasKey( 'Authorization', $args['headers'] ?? [] );

                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( home_url(), $body['site_url'] ?? null );
                $this->assertNotEmpty( $body['local_site_identifier'] ?? '' );
                $this->assertSame( '99999999-9999-4999-8999-999999999999', $body['checkout_intent_id'] ?? null );
                $this->assertSame( 'cs_test_123', $body['checkout_session_id'] ?? null );
                $this->assertSame( 'token-123', $body['activation_token'] ?? null );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id'  => '99999999-9999-4999-8999-999999999999',
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
        $this->assertSame( '77777777-7777-4777-8777-777777777777', $license['license_id'] );
        $this->assertSame( '88888888-8888-4888-8888-888888888888', $license['site_id'] );

        global $wpdb;
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $credential = $credentials->find_by_provider_auth_mode( 'sentient_managed', 'sentient_proxy' );
        $this->assertIsArray( $credential );
        $this->assertSame( 'valid', $credential['status'] );
        $this->assertTrue( $credential['status_json']['proxy_key_present'] ?? false );
    }

    public function test_complete_managed_checkout_requires_activation_token_before_remote_call(): void
    {
        $guard = function ( $preempt, $args, $url ) {
            $this->fail( 'Managed checkout completion must not call CPS without an activation token: ' . $url );
            return $preempt;
        };
        add_filter( 'pre_http_request', $guard, 1, 3 );

        try
        {
            $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
            $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $request->add_header( 'Content-Type', 'application/json' );
            $request->set_body( wp_json_encode( [
                'checkout_intent_id' => '77777777-7777-4777-8777-777777777777',
            ] ) );
            $response = rest_get_server()->dispatch( $request );
        }
        finally
        {
            remove_filter( 'pre_http_request', $guard, 1 );
        }

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] ?? null );
    }

    public function test_complete_managed_checkout_rejects_non_uuid_site_identity_without_mutation(): void
    {
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => true,
                'data'    => [
                    'activation_ready' => true,
                    'service'          => 'sentient-managed',
                    'license_key'      => '0abcdefghjkmnpqrstvwxyz123',
                    'license_id'       => '88888888-8888-4888-8888-888888888888',
                    'site_id'          => 'legacy-site-id',
                    'proxy_api_key'    => 'must-not-be-stored',
                    'status'           => 'active',
                    'tier'             => [ 'code' => 'starter' ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id' => '99999999-9999-4999-8999-999999999999',
            'activation_token'   => 'token-invalid-identity',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_identity', $response->get_data()['code'] ?? null );

        $license = Sentient_Forms_Plugin::instance()->get_license_data();
        $this->assertSame( $before['proxy_api_key'] ?? '', $license['proxy_api_key'] ?? '' );
        $this->assertSame( $before['site_id'] ?? '', $license['site_id'] ?? '' );
    }

    public function test_complete_managed_checkout_rejects_mismatched_ready_identity_without_mutation(): void
    {
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $payload = array_merge(
            $this->managed_activation_payload(),
            [
                'activation_ready' => true,
                'license_key'      => '0abcdefghjkmnpqrstvwxyz123',
                'site_url'        => 'https://other-site.example/',
            ]
        );
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id' => '99999999-9999-4999-8999-999999999999',
            'activation_token'   => 'token-mismatched-ready',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_identity', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
    }

    public function test_complete_managed_checkout_rejects_non_string_ready_fields_without_mutation(): void
    {
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $payload = array_merge(
            $this->managed_activation_payload(),
            [
                'activation_ready' => true,
                'license_key'      => '0abcdefghjkmnpqrstvwxyz123',
                'proxy_api_key'    => true,
            ]
        );
        $payload['tier']['display_name'] = true;
        $this->mock_http_response(
            '/v2/account/checkout/complete',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/managed-checkout/complete' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_intent_id' => '99999999-9999-4999-8999-999999999999',
            'activation_token'   => 'token-non-string-ready',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_response', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
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
                    'license_id'    => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'site_id'       => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                    'proxy_api_key' => 'bootstrap-proxy-key',
                    'status'        => 'active',
                    'site_url'      => home_url(),
                    'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                    'tier'          => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1000,
                    ],
                    'billing_boundary' => [
                        'direct_openrouter_billed_by_sentient' => false,
                        'managed_proxy_billed_by_sentient'     => true,
                    ],
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

    public function test_bootstrap_license_rejects_missing_success_envelope_before_mutation(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data( [
            'license_key'    => '0abcdefghjkmnpqrstvwxyz123',
            'license_status' => 'inactive',
            'proxy_api_key'  => '',
        ] );
        $before = Sentient_Forms_Plugin::instance()->get_license_data();
        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'data' => $this->managed_activation_payload(),
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/bootstrap' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_response', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
    }

    public function test_bootstrap_license_rejects_noncanonical_status_before_mutation(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data( [
            'license_key'    => '0abcdefghjkmnpqrstvwxyz123',
            'license_status' => 'inactive',
            'proxy_api_key'  => '',
        ] );
        $before  = Sentient_Forms_Plugin::instance()->get_license_data();
        $payload = $this->managed_activation_payload();
        $payload['status'] = 'provisioning';
        $this->mock_http_response(
            '/v2/account/sites/activate',
            [
                'success' => true,
                'data'    => $payload,
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/bootstrap' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'sentient_managed_activation_invalid_response', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
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
                        'monthly_credit_quota' => 1000,
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
                        'capacity_policy'          => 'tier_allowance_v2',
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
        $this->assertArrayNotHasKey( 'billing', $data['managed_usage'] );
        $this->assertStringNotContainsString( 'microusd', wp_json_encode( $data['managed_usage'] ) );
        $this->assertStringNotContainsString( '"currency"', wp_json_encode( $data['managed_usage'] ) );
        $this->assertFalse( $data['billing_boundary']['direct_openrouter_billed_by_sentient'] );
        $this->assertTrue( $data['billing_boundary']['managed_proxy_billed_by_sentient'] );

        $updated_license = $plugin->get_license_data();
        $this->assertSame( 'trial', $updated_license['license_status'] );
        $this->assertSame( 'starter', $updated_license['tier']['code'] ?? null );
    }

    public function test_get_billing_state_uses_short_lived_cache_until_forced_refresh(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-cache',
            'license_id'     => 'lic-cache-123',
            'site_id'        => 'site-cache-456',
        ] );

        delete_transient( 'sentient_forms_billing_state_' . md5( 'proxy-key-cache' ) );
        delete_transient( 'sentient_forms_billing_state_stale_' . md5( 'proxy-key-cache' ) );

        $calls = 0;
        $this->mock_http_response(
            '/v2/billing/state',
            [
                'success' => true,
                'data'    => [
                    'service' => 'sentient-managed',
                    'status'  => 'active',
                    'plan'    => [
                        'code'         => 'starter',
                        'display_name' => 'Starter',
                    ],
                    'billing' => [
                        'provider'        => 'stripe',
                        'managed_enabled' => true,
                    ],
                ],
            ],
            function () use ( &$calls ): void {
                $calls++;
            }
        );

        $first_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
        $first_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $first_response = rest_get_server()->dispatch( $first_request );

        $second_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
        $second_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $second_response = rest_get_server()->dispatch( $second_request );

        $force_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
        $force_request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $force_request->set_param( 'force_refresh', true );
        $force_response = rest_get_server()->dispatch( $force_request );

        $this->assertSame( 200, $first_response->get_status() );
        $this->assertSame( 200, $second_response->get_status() );
        $this->assertSame( 200, $force_response->get_status() );
        $this->assertSame( 2, $calls, 'Second read should use transient cache; force_refresh should bypass it.' );
        $this->assertFalse( $second_response->get_data()['stale'] ?? true );
        $this->assertSame( $first_response->get_data()['cached_at'] ?? null, $second_response->get_data()['cached_at'] ?? null );
    }

    public function test_get_billing_state_returns_stale_snapshot_when_remote_state_fails(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-stale',
            'license_id'     => 'lic-stale-123',
            'site_id'        => 'site-stale-456',
        ] );

        delete_transient( 'sentient_forms_billing_state_' . md5( 'proxy-key-stale' ) );
        set_transient(
            'sentient_forms_billing_state_stale_' . md5( 'proxy-key-stale' ),
            [
                'service'   => 'sentient-managed',
                'status'    => 'active',
                'plan'      => [
                    'code' => 'starter',
                ],
                'cached_at' => '2026-05-29T12:00:00+00:00',
                'stale'     => false,
            ],
            DAY_IN_SECONDS
        );

        $failure_filter = static function ( $preempt, $_args, $url ) {
            if ( str_ends_with( $url, '/v2/billing/state' ) ) {
                return new WP_Error( 'http_request_failed', 'CPS is temporarily unavailable.' );
            }

            return $preempt;
        };
        add_filter( 'pre_http_request', $failure_filter, 1, 3 );

        try {
            $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
            $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $response = rest_get_server()->dispatch( $request );
        } finally {
            remove_filter( 'pre_http_request', $failure_filter, 1 );
        }

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'starter', $data['plan']['code'] ?? null );
        $this->assertTrue( $data['stale'] ?? false );
        $this->assertSame( 'http_request_failed', $data['last_error_code'] ?? null );
    }

    public function test_get_billing_state_does_not_return_stale_snapshot_for_cps_auth_error(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-revoked',
            'license_id'     => 'lic-revoked-123',
            'site_id'        => 'site-revoked-456',
        ] );

        delete_transient( 'sentient_forms_billing_state_' . md5( 'proxy-key-revoked' ) );
        set_transient(
            'sentient_forms_billing_state_stale_' . md5( 'proxy-key-revoked' ),
            [
                'service'   => 'sentient-managed',
                'status'    => 'active',
                'plan'      => [
                    'code' => 'starter',
                ],
                'cached_at' => '2026-05-29T12:00:00+00:00',
                'stale'     => false,
            ],
            DAY_IN_SECONDS
        );

        $failure_filter = static function ( $preempt, $_args, $url ) {
            if ( str_ends_with( $url, '/v2/billing/state' ) ) {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'success' => false,
                            'error'   => [
                                'code'    => 'sentient_managed_license_revoked',
                                'message' => 'Managed-service license has been revoked.',
                            ],
                        ]
                    ),
                    'response' => [
                        'code'    => 401,
                        'message' => 'Unauthorized',
                    ],
                ];
            }

            return $preempt;
        };
        add_filter( 'pre_http_request', $failure_filter, 999, 3 );

        try {
            $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/license/billing-state' );
            $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $response = rest_get_server()->dispatch( $request );
        } finally {
            remove_filter( 'pre_http_request', $failure_filter, 999 );
            delete_transient( 'sentient_forms_billing_state_' . md5( 'proxy-key-revoked' ) );
            delete_transient( 'sentient_forms_billing_state_stale_' . md5( 'proxy-key-revoked' ) );
        }

        $this->assertSame( 401, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_managed_license_revoked', $data['code'] ?? null );
        $this->assertArrayNotHasKey( 'stale', $data );
        $this->assertArrayNotHasKey( 'last_error_code', $data );
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
                $this->assertSame( '55555555-5555-4555-8555-555555555555', $body['checkout_attempt_id'] ?? null );
                $this->assertSame( 'starter', $body['plan_code'] ?? null );
                $this->assertArrayNotHasKey( 'trial_period_days', $body );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/checkout-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_attempt_id' => '55555555-5555-4555-8555-555555555555',
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

    public function test_create_checkout_session_requires_plan(): void
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
            'checkout_attempt_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'success_url'         => 'https://example.test/success',
            'cancel_url'          => 'https://example.test/cancel',
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'rest_missing_callback_param', $data['code'] ?? null );
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

    public function test_create_top_up_checkout_session_proxies_business_pack(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
            'tier'           => 'business',
        ] );

        $this->mock_http_response(
            '/v2/billing/state',
            [
                'success' => true,
                'data'    => [
                    'license_status' => 'active',
                    'tier'           => [
                        'code'                 => 'business',
                        'display_name'         => 'Business',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 10000,
                    ],
                    'subscription'   => [
                        'provider_subscription_id' => 'sub_business_123',
                        'status'                   => 'active',
                    ],
                ],
            ]
        );

        $this->mock_http_response(
            '/v2/billing/checkout/top-up-session',
            [
                'success' => true,
                'data'    => [
                    'session_id'     => 'cs_top_up_test_123',
                    'checkout_url'   => 'https://checkout.stripe.com/c/pay/cs_top_up_test_123',
                    'customer_id'    => 'cus_test_123',
                    'top_up_credits' => 1000,
                    'pack_code'      => 'top_up_small',
                ],
            ],
            function ( array $args ): void {
                $this->assertSame( 'Bearer proxy-key-123', $args['headers']['Authorization'] ?? null );
                $body = json_decode( (string) ( $args['body'] ?? '' ), true );
                $this->assertIsArray( $body );
                $this->assertSame( '66666666-6666-4666-8666-666666666666', $body['checkout_attempt_id'] ?? null );
                $this->assertSame( 'top_up_small', $body['pack_code'] ?? null );
                $this->assertSame( 'https://example.test/success', $body['success_url'] ?? null );
                $this->assertSame( 'https://example.test/cancel', $body['cancel_url'] ?? null );
                $this->assertSame( 1, $body['quantity'] ?? null );
            }
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/top-up-session' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->add_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [
            'checkout_attempt_id' => '66666666-6666-4666-8666-666666666666',
            'pack_code'   => 'top_up_small',
            'success_url' => 'https://example.test/success',
            'cancel_url'  => 'https://example.test/cancel',
            'quantity'    => 1,
        ] ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'cs_top_up_test_123', $data['session_id'] ?? null );
        $this->assertSame( 1000, $data['top_up_credits'] ?? null );
    }

    public function test_create_top_up_checkout_session_rejects_non_business_plan_before_proxying(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-uuid-123',
            'site_id'        => 'site-uuid-456',
            'tier'           => 'starter',
        ] );

        $this->mock_http_response(
            '/v2/billing/state',
            [
                'success' => true,
                'data'    => [
                    'license_status' => 'active',
                    'tier'           => [
                        'code'                 => 'starter',
                        'display_name'         => 'Starter',
                        'site_limit'           => 1,
                        'monthly_credit_quota' => 1000,
                    ],
                    'subscription'   => [
                        'provider_subscription_id' => 'sub_starter_123',
                        'status'                   => 'active',
                    ],
                ],
            ]
        );

        $guard = function ( $preempt, $args, $url ) {
            if ( str_ends_with( $url, '/v2/billing/checkout/top-up-session' ) ) {
                $this->fail( 'Starter top-up checkout should be rejected before proxying to CPS.' );
            }

            return $preempt;
        };
        add_filter( 'pre_http_request', $guard, 1, 3 );

        try
        {
            $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/billing/top-up-session' );
            $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
            $request->add_header( 'Content-Type', 'application/json' );
            $request->set_body( wp_json_encode( [
                'checkout_attempt_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
                'pack_code'            => 'top_up_small',
                'success_url'          => 'https://example.test/success',
                'cancel_url'           => 'https://example.test/cancel',
                'quantity'             => 1,
            ] ) );
            $response = rest_get_server()->dispatch( $request );
        }
        finally
        {
            remove_filter( 'pre_http_request', $guard, 1 );
        }

        $this->assertSame( 403, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'managed_top_up_business_required', $data['code'] ?? null );
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

    public function test_deactivate_license_maps_malformed_success_to_gateway_error_without_mutation(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data( [
            'license_key'    => '0abcdefghjkmnpqrstvwxyz123',
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-to-preserve',
            'license_id'     => '11111111-1111-4111-8111-111111111111',
            'site_id'        => '22222222-2222-4222-8222-222222222222',
        ] );
        $before = Sentient_Forms_Plugin::instance()->get_license_data();

        $this->mock_http_response(
            '/v2/account/sites/deactivate',
            [
                'success' => false,
                'data'    => [
                    'status' => 'inactive',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/deactivate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 502, $response->get_status() );
        $this->assertSame( 'cps_unexpected_response', $response->get_data()['code'] ?? null );
        $this->assertSame( $before, Sentient_Forms_Plugin::instance()->get_license_data() );
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

    private function managed_activation_payload(): array
    {
        return [
            'service'               => 'sentient-managed',
            'license_id'            => '11111111-1111-4111-8111-111111111111',
            'site_id'               => '22222222-2222-4222-8222-222222222222',
            'proxy_api_key'         => 'must-not-be-stored',
            'status'                => 'active',
            'site_url'              => home_url(),
            'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
            'tier'                  => [
                'code'                 => 'starter',
                'display_name'         => 'Starter',
                'site_limit'           => 1,
                'monthly_credit_quota' => 1000,
            ],
            'billing_boundary'      => [
                'direct_openrouter_billed_by_sentient' => false,
                'managed_proxy_billed_by_sentient'     => true,
            ],
        ];
    }

    private function managed_checkout_start_payload(): array
    {
        return [
            'service'             => 'sentient-managed',
            'status'              => 'open',
            'checkout_intent_id'  => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'checkout_session_id' => 'cs_test_123',
            'checkout_url'        => 'https://checkout.stripe.com/c/pay/cs_test_123',
            'plan_code'           => 'starter',
            'billing_interval'    => 'monthly',
            'billing_boundary'    => [
                'direct_openrouter_billed_by_sentient' => false,
                'managed_proxy_billed_by_sentient'     => true,
            ],
        ];
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
