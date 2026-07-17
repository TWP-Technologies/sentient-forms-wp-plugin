<?php

class Tests_Managed_Service_Client extends WP_UnitTestCase
{
    /** @var callable|null */
    private $http_mock = null;

    protected function tearDown(): void
    {
        if ( null !== $this->http_mock )
        {
            remove_filter( 'pre_http_request', $this->http_mock, 10 );
            $this->http_mock = null;
        }

        parent::tearDown();
    }

    public function test_activation_posts_to_v2_account_route_without_proxy_auth(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response(
                    [
                        'site_id'       => '22222222-2222-4222-8222-222222222222',
                        'proxy_api_key' => 'proxy-issued',
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->activate_site(
            [
                'license_key'           => 'LIC-TEST',
                'site_url'              => 'https://example.test',
                'local_site_identifier' => 'example-local',
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'proxy-issued', $result['proxy_api_key'] );
        $this->assertSame( 'https://minimal.sentient.test/v2', $client->get_base_url() );
        $this->assertCount( 1, $calls );
        $this->assertSame( 'https://minimal.sentient.test/v2/account/sites/activate', $calls[0]['url'] );
        $this->assertSame( 'POST', $calls[0]['args']['method'] );
        $this->assertArrayNotHasKey( 'Authorization', $calls[0]['args']['headers'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( 'LIC-TEST', $payload['license_key'] );
        $this->assertSame( 'https://example.test', $payload['site_url'] );
        $this->assertSame( 'example-local', $payload['local_site_identifier'] );
    }

    public function test_exact_v1_base_url_fails_closed(): void
    {
        $client = new Sentient_Forms_Managed_Service_Client( 'http://127.0.0.1:3000/v1' );
        $result = $client->activate_site(
            [
                'license_key'           => 'LIC-TEST',
                'site_url'              => 'https://example.test',
                'local_site_identifier' => 'example-local',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_base_url', $result->get_error_code() );
    }

    /**
     * @dataProvider unsupported_managed_base_urls
     */
    public function test_request_fails_closed_for_unsupported_or_hostile_base_url( string $url ): void
    {
        $client = new Sentient_Forms_Managed_Service_Client( $url );
        $result = $client->activate_site(
            [
                'license_key'           => 'LIC-TEST',
                'site_url'              => 'https://example.test',
                'local_site_identifier' => 'example-local',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_base_url', $result->get_error_code() );
        $this->assertStringContainsString( 'managed service URL', $result->get_error_message() );
    }

    public function unsupported_managed_base_urls(): array
    {
        return [
            'unsupported version' => [ 'https://staging-api.sentientforms.com/v3' ],
            'versioned subpath'   => [ 'https://staging-api.sentientforms.com/v1/admin' ],
            'unversioned subpath' => [ 'https://staging-api.sentientforms.com/proxy' ],
            'hostile userinfo'    => [ 'https://api.sentientforms.com@evil.example/v1' ],
            'query injection'     => [ 'https://staging-api.sentientforms.com/v1?target=https://evil.example' ],
        ];
    }

    public function test_billing_state_uses_v2_get_without_body(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response(
                    [
                        'status'  => 'active',
                        'plan'    => [
                            'code' => 'starter',
                        ],
                        'billing' => [
                            'managed_enabled' => true,
                        ],
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test' );
        $result = $client->get_billing_state( 'proxy-secret' );

        $this->assertIsArray( $result );
        $this->assertSame( 'active', $result['status'] );
        $this->assertSame( 'https://minimal.sentient.test/v2/billing/state', $calls[0]['url'] );
        $this->assertSame( 'GET', $calls[0]['args']['method'] );
        $this->assertSame( 'Bearer proxy-secret', $calls[0]['args']['headers']['Authorization'] );
        $this->assertTrue(
            ! isset( $calls[0]['args']['body'] ) || null === $calls[0]['args']['body'] || '' === $calls[0]['args']['body'],
            'Billing state should not send a request body.'
        );
    }

    public function test_base_url_falls_back_to_cps_base_url_resolution(): void
    {
        $filter = static function (): string {
            return 'https://staging-api.sentientforms.com/v2';
        };

        add_filter( 'sentient_forms_cps_base_url', $filter, 10, 2 );

        try
        {
            $client = new Sentient_Forms_Managed_Service_Client();
            $this->assertSame( 'https://staging-api.sentientforms.com/v2', $client->get_base_url() );
        }
        finally
        {
            remove_filter( 'sentient_forms_cps_base_url', $filter, 10 );
        }
    }

    public function test_base_url_defaults_to_production_api(): void
    {
        $previous_managed_url = getenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        $previous_proxy_url   = getenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        $previous_options     = get_option( 'sentient_forms_settings', null );

        putenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        putenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        update_option( 'sentient_forms_settings', [] );

        try
        {
            $client = new Sentient_Forms_Managed_Service_Client();
            $this->assertSame( 'https://api.sentientforms.com/v2', $client->get_base_url() );
        }
        finally
        {
            false === $previous_managed_url
                ? putenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' )
                : putenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL=' . $previous_managed_url );
            false === $previous_proxy_url
                ? putenv( 'SENTIENT_FORMS_PROXY_API_URL' )
                : putenv( 'SENTIENT_FORMS_PROXY_API_URL=' . $previous_proxy_url );

            null === $previous_options
                ? delete_option( 'sentient_forms_settings' )
                : update_option( 'sentient_forms_settings', $previous_options );
        }
    }

    public function test_checkout_posts_sanitized_payload_to_v2_billing_route(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response(
                    [
                        'session_id'   => 'cs_test_123',
                        'checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->create_checkout_session(
            'proxy-secret',
            [
                'checkout_attempt_id'   => '22222222-2222-4222-8222-222222222222',
                'success_url'           => 'https://example.test/success',
                'cancel_url'            => 'https://example.test/cancel',
                'plan_code'             => 'starter',
                'quantity'              => '1',
                'allow_promotion_codes' => '1',
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'cs_test_123', $result['session_id'] );
        $this->assertSame( 'https://minimal.sentient.test/v2/billing/checkout/session', $calls[0]['url'] );
        $this->assertSame( 'Bearer proxy-secret', $calls[0]['args']['headers']['Authorization'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( '22222222-2222-4222-8222-222222222222', $payload['checkout_attempt_id'] );
        $this->assertSame( 'https://example.test/success', $payload['success_url'] );
        $this->assertSame( 'https://example.test/cancel', $payload['cancel_url'] );
        $this->assertSame( 'starter', $payload['plan_code'] );
        $this->assertSame( 1, $payload['quantity'] );
        $this->assertTrue( $payload['allow_promotion_codes'] );
    }

    public function test_checkout_rejects_noncanonical_plan_price_and_quantity_before_http(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = compact( 'args', 'url' );

                return self::success_response( [] );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $base   = [
            'checkout_attempt_id' => '22222222-2222-4222-8222-222222222222',
            'success_url' => 'https://example.test/success',
            'cancel_url'  => 'https://example.test/cancel',
        ];

        foreach (
            [
                $base,
                array_merge( $base, [ 'plan_code' => 'Starter' ] ),
                array_merge( $base, [ 'plan_code' => 'future' ] ),
                array_merge( $base, [ 'plan_code' => 'starter', 'price_id' => 'price_client_owned' ] ),
                array_merge( $base, [ 'plan_code' => 'starter', 'quantity' => 2 ] ),
            ] as $payload
        )
        {
            $result = $client->create_checkout_session( 'proxy-secret', $payload );

            $this->assertWPError( $result );
            $this->assertSame( 'sentient_managed_billing_invalid_payload', $result->get_error_code() );
        }

        $this->assertCount( 0, $calls );
    }

    public function test_top_up_checkout_preserves_positive_multi_pack_quantity(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = compact( 'args', 'url' );

                return self::success_response(
                    [
                        'session_id' => 'cs_top_up_123',
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->create_top_up_checkout_session(
            'proxy-secret',
            [
                'checkout_attempt_id' => '33333333-3333-4333-8333-333333333333',
                'pack_code'  => 'business_1000',
                'success_url' => 'https://example.test/success',
                'cancel_url'  => 'https://example.test/cancel',
                'quantity'    => 2,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'cs_top_up_123', $result['session_id'] );
        $this->assertSame( 'https://minimal.sentient.test/v2/billing/checkout/top-up-session', $calls[0]['url'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( '33333333-3333-4333-8333-333333333333', $payload['checkout_attempt_id'] );
        $this->assertSame( 'business_1000', $payload['pack_code'] );
        $this->assertSame( 2, $payload['quantity'] );
    }

    public function test_checkout_rejects_missing_or_invalid_attempt_identity_before_http(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = compact( 'args', 'url' );
                return self::success_response( [] );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $base   = [
            'plan_code'   => 'starter',
            'success_url' => 'https://example.test/success',
            'cancel_url'  => 'https://example.test/cancel',
        ];

        foreach ( [ $base, array_merge( $base, [ 'checkout_attempt_id' => 'not-a-uuid' ] ) ] as $payload )
        {
            $result = $client->create_checkout_session( 'proxy-secret', $payload );
            $this->assertWPError( $result );
            $this->assertSame( 'sentient_managed_billing_invalid_payload', $result->get_error_code() );
        }

        $this->assertCount( 0, $calls );
    }

    public function test_managed_checkout_start_posts_consent_and_site_identity_without_proxy_auth(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response(
                    [
                        'checkout_intent_id' => '11111111-1111-4111-8111-111111111111',
                        'checkout_url'       => 'https://checkout.stripe.com/c/pay/cs_test_123',
                        'checkout_session_id' => 'cs_test_123',
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->start_managed_checkout(
            [
                'plan_code'                      => 'starter',
                'site_url'                       => 'https://example.test',
                'local_site_identifier'          => 'example-local',
                'success_url'                    => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
                'cancel_url'                     => 'https://example.test/wp-admin/admin.php?page=sentient-forms#/licensing',
                'disclosure_version'             => 'managed-service-v1',
                'accepted_managed_service_terms' => true,
                'require_zdr'                    => true,
                'trial_period_days'              => 14,
                'quantity'                       => 5,
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( '11111111-1111-4111-8111-111111111111', $result['checkout_intent_id'] );
        $this->assertSame( 'https://minimal.sentient.test/v2/account/checkout/start', $calls[0]['url'] );
        $this->assertArrayNotHasKey( 'Authorization', $calls[0]['args']['headers'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( 'starter', $payload['plan_code'] );
        $this->assertSame( 'https://example.test', $payload['site_url'] );
        $this->assertSame( 'example-local', $payload['local_site_identifier'] );
        $this->assertSame( 'managed-service-v1', $payload['disclosure_version'] );
        $this->assertTrue( $payload['accepted_managed_service_terms'] );
        $this->assertTrue( $payload['require_zdr'] );
        $this->assertArrayNotHasKey( 'trial_period_days', $payload );
        $this->assertArrayNotHasKey( 'quantity', $payload );
    }

    public function test_managed_checkout_complete_posts_reference_without_proxy_auth(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response(
                    [
                        'activation_ready' => true,
                        'license_key'      => '0abcdefghjkmnpqrstvwxyz123',
                        'site_id'          => 'site-123',
                        'proxy_api_key'    => 'proxy-issued',
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->complete_managed_checkout(
            [
                'site_url'              => 'https://example.test',
                'local_site_identifier' => 'example-local',
                'checkout_intent_id'    => '11111111-1111-4111-8111-111111111111',
                'checkout_session_id'   => 'cs_test_123',
                'activation_token'      => 'token-123',
            ]
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['activation_ready'] );
        $this->assertSame( 'proxy-issued', $result['proxy_api_key'] );
        $this->assertSame( 'https://minimal.sentient.test/v2/account/checkout/complete', $calls[0]['url'] );
        $this->assertArrayNotHasKey( 'Authorization', $calls[0]['args']['headers'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( 'https://example.test', $payload['site_url'] );
        $this->assertSame( 'example-local', $payload['local_site_identifier'] );
        $this->assertSame( '11111111-1111-4111-8111-111111111111', $payload['checkout_intent_id'] );
        $this->assertSame( 'cs_test_123', $payload['checkout_session_id'] );
        $this->assertSame( 'token-123', $payload['activation_token'] );
    }

    public function test_managed_checkout_complete_requires_activation_token_before_http(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response( [ 'activation_ready' => false ] );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->complete_managed_checkout(
            [
                'site_url'              => 'https://example.test',
                'local_site_identifier' => 'example-local',
                'checkout_intent_id'    => '11111111-1111-4111-8111-111111111111',
                'checkout_session_id'   => 'cs_test_123',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_checkout_missing_activation_token', $result->get_error_code() );
        $this->assertSame( [], $calls );
    }

    public function test_managed_checkout_complete_rejects_non_uuid_intent_before_http(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = compact( 'args', 'url' );

                return self::success_response( [ 'activation_ready' => false ] );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->complete_managed_checkout(
            [
                'site_url'              => 'https://example.test',
                'local_site_identifier' => 'example-local',
                'checkout_intent_id'    => 'mci_123',
                'activation_token'      => 'token-123',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_checkout_invalid_reference', $result->get_error_code() );
        $this->assertSame( [], $calls );
    }

    public function test_portal_session_posts_to_v2_billing_route(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response(
                    [
                        'portal_url' => 'https://billing.stripe.com/p/session/test',
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->create_portal_session(
            'proxy-secret',
            'https://example.test/wp-admin/admin.php?page=sentient-forms',
            'subscription_update',
            'sub_test_123'
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'https://billing.stripe.com/p/session/test', $result['portal_url'] );
        $this->assertSame( 'https://minimal.sentient.test/v2/billing/portal/session', $calls[0]['url'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( 'https://example.test/wp-admin/admin.php?page=sentient-forms', $payload['return_url'] );
        $this->assertSame( 'subscription_update', $payload['flow_type'] );
        $this->assertSame( 'sub_test_123', $payload['subscription_id'] );
    }

    public function test_site_detail_uses_encoded_path_and_bearer_auth(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return self::success_response(
                    [
                        'site_id' => 'site with spaces',
                    ]
                );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->get_site( 'proxy-secret', 'site with spaces' );

        $this->assertIsArray( $result );
        $this->assertSame( 'https://minimal.sentient.test/v2/account/sites/site%20with%20spaces', $calls[0]['url'] );
        $this->assertSame( 'GET', $calls[0]['args']['method'] );
        $this->assertSame( 'Bearer proxy-secret', $calls[0]['args']['headers']['Authorization'] );
    }

    public function test_missing_proxy_key_rejects_protected_billing_before_http_request(): void
    {
        $this->mock_http(
            static function (): WP_Error {
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->get_billing_state( '' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_billing_missing_proxy_key', $result->get_error_code() );
    }

    public function test_shell_error_does_not_expose_proxy_key(): void
    {
        $this->mock_http(
            static function (): array {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 501,
                        'message' => 'Not Implemented',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'success' => false,
                            'error'   => [
                                'code'    => 'billing_state_planned',
                                'message' => 'Minimal service billing state is reserved but not implemented yet.',
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Service_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->get_billing_state( 'proxy-secret' );

        $this->assertWPError( $result );
        $this->assertSame( 'billing_state_planned', $result->get_error_code() );
        $this->assertStringNotContainsString( 'proxy-secret', $result->get_error_message() );
        $this->assertSame( 501, $result->get_error_data()['status'] );
    }

    private function mock_http( callable $callback ): void
    {
        $this->http_mock = $callback;
        add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function success_response( array $data ): array
    {
        return [
            'headers'  => [],
            'response' => [
                'code'    => 200,
                'message' => 'OK',
            ],
            'body'     => wp_json_encode(
                [
                    'success' => true,
                    'data'    => $data,
                ]
            ),
            'cookies'  => [],
        ];
    }
}
