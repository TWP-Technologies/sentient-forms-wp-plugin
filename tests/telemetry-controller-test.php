<?php

class TelemetryControllerTest extends WP_UnitTestCase
{
    protected static int $admin_id;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [] );
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_key'   => '',
            'proxy_api_key' => '',
        ] );
        $plugin->set_telemetry_settings( [] );
    }

    protected function tearDown(): void
    {
        update_option( 'sentient_forms_settings', [] );
        parent::tearDown();
    }

    public function test_get_settings_returns_defaults(): void
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/telemetry' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertArrayHasKey( 'telemetry_opt_in', $data );
        $this->assertFalse( $data['telemetry_opt_in'] );
    }

    public function test_update_syncs_with_cps(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_key'    => 'LIC-EXAMPLE',
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-123',
            'license_id'     => 'lic-1',
            'site_id'        => 'site-1',
        ] );

        $this->mock_cps_response( [
            'success' => true,
            'data'    => [
                'telemetry_opt_in' => true,
                'updated_at'       => '2025-11-15T10:00:00Z',
            ],
        ] );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/telemetry' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'telemetry_opt_in', true );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertTrue( $data['telemetry_opt_in'] );
        $this->assertSame( '2025-11-15T10:00:00Z', $data['remote_updated_at'] );

        $settings = $plugin->get_telemetry_settings();
        $this->assertTrue( $settings['telemetry_opt_in'] );
        $this->assertNull( $settings['last_error'] );
    }

    public function test_update_requires_proxy_key(): void
    {
        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/telemetry' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'telemetry_opt_in', true );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'cps_missing_proxy_key', $response->get_data()['code'] );
    }

    private function mock_cps_response( array $body ): void
    {
        add_filter(
            'pre_http_request',
            static function ( $preempt, $args, $url ) use ( $body ) {
                if ( str_contains( $url, '/sites/telemetry' ) ) {
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
