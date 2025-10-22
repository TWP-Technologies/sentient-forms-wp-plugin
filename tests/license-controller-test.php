<?php

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
        update_option( 'sentient_forms_settings', [] );
        Sentient_Forms_Plugin::instance();
    }

    protected function tearDown(): void
    {
        update_option( 'sentient_forms_settings', [] );
        parent::tearDown();
    }

    public function test_activate_license_persists_credentials(): void
    {
        $license_key = '01HY3ZABCD1EFGHJKLMNPQRSTV';

        $this->mock_http_response(
            '/license/activate',
            [
                'success' => true,
                'data'    => [
                    'status'        => 'active',
                    'proxy_api_key' => 'proxy-abc',
                    'tier'          => 'starter',
                    'expiry_date'   => '2030-01-01T00:00:00Z',
                    'license_id'    => 'lic-1',
                    'site_id'       => 'site-1',
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/activate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'license_key', $license_key );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'active', $response->get_data()['status'] );

        $plugin       = Sentient_Forms_Plugin::instance();
        $license_data = $plugin->get_license_data();

        $this->assertSame( 'proxy-abc', $plugin->get_proxy_api_key() );
        $this->assertSame( 'active', $plugin->get_license_status() );
        $this->assertSame( 'starter', $license_data['tier'] );
        $this->assertSame( 'lic-1', $license_data['license_id'] );
        $this->assertSame( 'site-1', $license_data['site_id'] );
    }

    public function test_deactivate_license_clears_credentials(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data(
            [
                'license_key'    => '01HY3ZABCD1EFGHJKLMNPQRSTV',
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-abc',
                'license_id'     => 'lic-1',
                'site_id'        => 'site-1',
            ]
        );

        $this->mock_http_response( '/license/deactivate', [ 'success' => true ] );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/license/deactivate' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'inactive', $response->get_data()['status'] );

        $license_data = $plugin->get_license_data();
        $this->assertSame( '', $plugin->get_proxy_api_key() );
        $this->assertSame( 'inactive', $plugin->get_license_status() );
        $this->assertSame( '', $license_data['license_id'] );
        $this->assertSame( '', $license_data['site_id'] );
    }

    private function mock_http_response( string $path_suffix, array $body ): void
    {
        add_filter(
            'pre_http_request',
            static function ( $preempt, $args, $url ) use ( $path_suffix, $body ) {
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
