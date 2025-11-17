<?php

class CreditControllerTest extends WP_UnitTestCase
{
    protected static $admin_id;
    private array $http_mocks = [];

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        delete_transient( 'sf_credit_balance_cache' );
        delete_option( 'sentient_forms_credit_balance' );
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [ 'proxy_api_key' => '' ] );
    }

    protected function tearDown(): void
    {
        delete_transient( 'sf_credit_balance_cache' );
        delete_option( 'sentient_forms_credit_balance' );
        foreach ( $this->http_mocks as $mock ) {
            remove_filter( 'pre_http_request', $mock, 10 );
        }
        $this->http_mocks = [];
        parent::tearDown();
    }

    public function test_credit_balance_returns_normalized_payload(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-123',
            ]
        );

        $this->mock_http_response(
            '/credits/balance',
            [
                'success' => true,
                'data'    => [
                    'current_balance' => 321,
                    'ledger_delta'    => -9,
                    'tier'            => [
                        'code'                  => 'starter',
                        'display_name'          => 'Starter',
                        'site_limit'            => 3,
                        'monthly_credit_quota'  => 1000,
                    ],
                ],
            ],
            function ( $args ) {
                $this->assertArrayHasKey( 'X-API-Key', $args['headers'] );
                $this->assertSame( 'proxy-key-123', $args['headers']['X-API-Key'] );
            }
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 321, $data['current_balance'] );
        $this->assertSame( -9, $data['ledger_delta'] );
        $this->assertIsArray( $data['tier'] );
        $this->assertArrayHasKey( 'stale', $data );
        $this->assertFalse( $data['stale'] );
    }

    public function test_credit_balance_returns_stale_when_cps_fails(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-123',
            ]
        );

        update_option(
            'sentient_forms_credit_balance',
            [
                'current_balance' => 111,
                'ledger_delta'    => 0,
                'tier'            => null,
            ]
        );

        $this->mock_http_response(
            '/credits/balance',
            [
                'success' => false,
                'error'   => 'Service unavailable',
            ],
            null,
            500
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertTrue( $data['stale'] );
        $this->assertSame( 111, $data['current_balance'] );
    }

    public function test_credit_balance_requires_proxy_key(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [ 'proxy_api_key' => '' ] );
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/credits/balance' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'missing_api_key', $data['code'] );
    }

    private function mock_http_response( string $path_suffix, array $body, ?callable $assertion = null, int $status = 200 ): void
    {
        $callback = function ( $preempt, $args, $url ) use ( $path_suffix, $body, $assertion, $status ) {
                if ( str_ends_with( $url, $path_suffix ) ) {
                    if ( is_callable( $assertion ) ) {
                        $assertion( $args, $url );
                    }

                    return [
                        'headers'  => [],
                        'body'     => wp_json_encode( $body ),
                        'response' => [
                            'code'    => $status,
                            'message' => 'OK',
                        ],
                    ];
                }

                return $preempt;
            };
        add_filter( 'pre_http_request', $callback, 10, 3 );
        $this->http_mocks[] = $callback;
    }
}
