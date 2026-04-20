<?php

class ActionDefinitionsControllerTest extends WP_UnitTestCase
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

        if ( ! class_exists( 'Sentient_Forms_REST_API' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/class-rest-api.php';
        }
        if ( ! trait_exists( 'Trait_Sentient_Forms_Permission_Utils' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/permissions/trait-permission-utils.php';
        }
        if ( ! class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/permissions/class-admin-permission.php';
        }
        if ( ! trait_exists( 'Trait_Sentient_Forms_Validation_Utils' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/validators/trait-validation-utils.php';
        }
        if ( ! class_exists( 'Sentient_Forms_Settings_Validator' ) )
        {
            require_once dirname( __DIR__, 2 ) . '/includes/rest-api/validators/class-settings-validator.php';
        }

        $rest_api = new Sentient_Forms_REST_API();
        $server   = rest_get_server();
        do_action( 'rest_api_init', $server );
    }

    protected function tearDown(): void
    {
        add_filter( 'sentient_forms_rest_api_controller_classes', '__return_empty_array' );
        parent::tearDown();
    }

    public function test_definitions_from_cps_are_normalized(): void
    {
        $enable_cps_templates = static fn() => true;
        add_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-789',
            ]
        );

        $this->mock_http_response(
            '/actions/templates',
            [
                'success' => true,
                'data'    => [
                    'templates' => [
                        [
                            'id'               => '0a3c9d89-14d3-4e7a-8e1b-c99877e4fb0f',
                            'code'             => 'spam_detection_v1',
                            'display_name'     => 'Spam Analysis',
                            'description'      => 'Detect spam entries using LLMs',
                            'model_hint'       => 'models/gemini-1.5-flash',
                            'base_credit_cost' => 5,
                        ],
                    ],
                ],
            ],
            function ( $args ) {
                $this->assertArrayHasKey( 'Authorization', $args['headers'] );
                $this->assertSame( 'Bearer proxy-key-789', $args['headers']['Authorization'] );
            }
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/actions/definitions', $routes, 'Action definitions route should be registered' );
        $response = rest_get_server()->dispatch( $request );

        remove_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertIsArray( $data );
        $this->assertNotEmpty( $data );
        $definition = $data[0];

        $this->assertSame( 'spam_detection_v1', $definition['id'] );
        $this->assertSame( 'Spam Analysis', $definition['label'] );
        $this->assertSame( 'cps', $definition['source'] );
        $this->assertSame( 'models/gemini-1.5-flash', $definition['modelHint'] );
        $this->assertSame( 5, $definition['baseCreditCost'] );
        $this->assertArrayHasKey( 'hooks', $definition );
        $this->assertIsArray( $definition['hooks'] );
        $this->assertContains( 'gform_validation', $definition['hooks'] );
        $this->assertContains( 'gform_after_submission', $definition['hooks'] );
        $this->assertArrayHasKey( 'settingsFields', $definition );
    }

    public function test_definitions_do_not_fetch_cps_by_default_even_with_proxy_key(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-789',
            ]
        );

        $http_called = false;
        $callback    = static function () use ( &$http_called ) {
            $http_called = true;

            return new WP_Error( 'unexpected_cps_call', 'Action definitions should not fetch CPS by default.' );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/actions/definitions', $routes, 'Action definitions route should be registered' );
        $response = rest_get_server()->dispatch( $request );

        remove_filter( 'pre_http_request', $callback, 10 );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNotEmpty( $data );
        $this->assertSame( 'local', $data[0]['source'] );
        $this->assertFalse( $http_called, 'Proxy keys must not trigger CPS action definition fetches unless legacy CPS templates are explicitly enabled.' );
    }

    public function test_definitions_fall_back_to_local_registry_when_cps_unavailable(): void
    {
        $enable_cps_templates = static fn() => true;
        add_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $callback = function () {
            return new WP_Error( 'cps_unreachable', 'CPS unreachable' );
        };

        add_filter( 'pre_http_request', $callback, 10, 3 );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/definitions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey( '/sentient-forms/v1/actions/definitions', $routes, 'Action definitions route should be registered' );
        $response = rest_get_server()->dispatch( $request );

        remove_filter( 'pre_http_request', $callback, 10 );
        remove_filter( 'sentient_forms_enable_legacy_cps_action_templates', $enable_cps_templates );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertNotEmpty( $data );
        $this->assertSame( 'local', $data[0]['source'] );
    }

    private function mock_http_response( string $path_suffix, array $body, ?callable $assertion = null ): void
    {
        add_filter(
            'pre_http_request',
            function ( $preempt, $args, $url ) use ( $path_suffix, $body, $assertion ) {
                if ( str_ends_with( $url, $path_suffix ) )
                {
                    if ( is_callable( $assertion ) )
                    {
                        $assertion( $args, $url );
                    }

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
