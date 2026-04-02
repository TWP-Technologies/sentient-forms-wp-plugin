<?php

class ExecutionStatusControllerTest extends WP_UnitTestCase
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
        Sentient_Forms_Plugin::instance()->clear_license_data();
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

    public function test_status_route_proxies_cps_response(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-status-123',
            ]
        );

        $mapping_id = '123e4567-e89b-12d3-a456-426614174000';

        $this->mock_http_response(
            'GET',
            '/execution-status/' . $mapping_id . '/42',
            200,
            [
                'success' => true,
                'data'    => [
                    'id'             => 'audit-1',
                    'mapping_id'     => $mapping_id,
                    'entry_id'       => 42,
                    'status'         => 'succeeded',
                    'result_summary' => 'Completed.',
                    'error_message'  => null,
                    'credit_cost'    => 5,
                    'started_at'     => '2026-03-21T08:00:00Z',
                    'completed_at'   => '2026-03-21T08:00:01Z',
                    'created_at'     => '2026-03-21T08:00:00Z',
                ],
            ],
            function ( $args ) {
                $this->assertSame( 'Bearer proxy-status-123', $args['headers']['Authorization'] ?? null );
            }
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/execution-status/' . $mapping_id . '/42' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'succeeded', $data['status'] ?? null );
        $this->assertSame( 5, $data['credit_cost'] ?? null );
    }

    public function test_status_route_propagates_cps_http_status(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-status-404',
            ]
        );

        $mapping_id = '123e4567-e89b-12d3-a456-426614174111';

        $this->mock_http_response(
            'GET',
            '/execution-status/' . $mapping_id . '/99',
            404,
            [
                'success' => false,
                'error'   => [
                    'code'    => 'not_found',
                    'message' => 'Not Found',
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/execution-status/' . $mapping_id . '/99' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 404, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'cps_error', $data['code'] ?? null );
    }

    private function mock_http_response( string $method, string $path_suffix, int $status, array $body, ?callable $assertion = null ): void
    {
        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) use ( $method, $path_suffix, $status, $body, $assertion ) {
                $request_method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
                if ( $request_method !== strtoupper( $method ) )
                {
                    return $preempt;
                }

                if ( ! str_ends_with( $url, $path_suffix ) )
                {
                    return $preempt;
                }

                if ( is_callable( $assertion ) )
                {
                    $assertion( $args, $url );
                }

                return [
                    'headers'  => [],
                    'body'     => wp_json_encode( $body ),
                    'response' => [
                        'code'    => $status,
                        'message' => $status >= 200 && $status < 300 ? 'OK' : 'Error',
                    ],
                ];
            },
            10,
            3
        );
    }
}
