<?php

class CustomActionsControllerTest extends WP_UnitTestCase
{
    protected static int $admin_id;

    /** @var array<int, array{method:string,path:string,response:array,assertion:?callable}> */
    private array $mocked_responses = [];

    /** @var array<int, array{url:string,args:array}> */
    private array $captured_requests = [];

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = (int) $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [] );
        Sentient_Forms_Plugin::instance();
        add_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10, 3 );
    }

    protected function tearDown(): void
    {
        remove_filter( 'pre_http_request', [ $this, 'intercept_http_request' ], 10 );
        $this->mocked_responses = [];
        $this->captured_requests = [];
        update_option( 'sentient_forms_settings', [] );
        parent::tearDown();
    }

    public function intercept_http_request( $preempt, array $args, string $url )
    {
        foreach ( $this->mocked_responses as $index => $mock ) {
            if ( str_contains( $url, $mock['path'] ) && strtoupper( $args['method'] ?? 'GET' ) === $mock['method'] ) {
                $this->captured_requests[] = [
                    'url'  => $url,
                    'args' => $args,
                ];

                if ( is_callable( $mock['assertion'] ?? null ) ) {
                    call_user_func( $mock['assertion'], $args, $url );
                }

                unset( $this->mocked_responses[ $index ] );
                return $mock['response'];
            }
        }

        return $preempt;
    }

    public function test_list_returns_actions_and_quota(): void
    {
        $this->seed_license();

        $this->queue_mock_response(
            'GET',
            '/actions/custom',
            [
                'success' => true,
                'data'    => [
                    'actions' => [
                        [
                            'id'               => 'action-1234',
                            'template_id'      => 'tmpl-1',
                            'code'             => 'alpha',
                            'display_name'     => 'Alpha',
                            'description'      => null,
                            'prompt_overrides' => [],
                            'model_hint'       => null,
                            'base_credit_cost' => null,
                            'status'           => 'active',
                            'archived_at'      => null,
                            'created_at'       => '2025-11-15T00:00:00Z',
                            'updated_at'       => '2025-11-15T01:00:00Z',
                        ],
                    ],
                    'quota'   => [
                        'quota_max'       => 5,
                        'quota_used'      => 1,
                        'quota_remaining' => 4,
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/custom-actions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'status', 'ACTIVE' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertCount( 1, $data['actions'] );
        $this->assertSame( 'alpha', $data['actions'][0]['code'] );
        $this->assertSame( 4, $data['quota']['quota_remaining'] );

        $last_request = $this->get_last_request();
        $this->assertNotNull( $last_request );
        $this->assertStringContainsString( 'status=active', $last_request['url'] );
    }

    public function test_create_sanitizes_code_and_passes_actor_hint(): void
    {
        $this->seed_license();
        $this->queue_mock_response(
            'POST',
            '/actions/custom',
            [
                'success' => true,
                'data'    => [
                    'action' => [
                        'id'               => 'action-xyz',
                        'template_id'      => 'tmpl-99',
                        'code'             => 'betaaction',
                        'display_name'     => 'Beta',
                        'description'      => null,
                        'prompt_overrides' => [],
                        'model_hint'       => null,
                        'base_credit_cost' => null,
                        'status'           => 'active',
                        'archived_at'      => null,
                        'created_at'       => '2025-11-15T00:00:00Z',
                        'updated_at'       => '2025-11-15T00:00:00Z',
                    ],
                    'quota' => [
                        'quota_max'       => 5,
                        'quota_used'      => 2,
                        'quota_remaining' => 3,
                    ],
                ],
            ],
            null,
            201
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'template_id', 'tmpl-99' );
        $request->set_param( 'code', 'Beta Action!' );
        $request->set_param( 'display_name', 'Beta action' );
        $request->set_param( 'prompt_overrides', '{"tone":"friendly"}' );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 201, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'betaaction', $data['action']['code'] );
        $this->assertSame( 3, $data['quota']['quota_remaining'] );

        $last_request = $this->get_last_request();
        $this->assertNotNull( $last_request );
        $body = json_decode( (string) ( $last_request['args']['body'] ?? '' ), true );
        $this->assertSame( 'betaaction', $body['code'] );
        $this->assertSame( 'wp_user:' . self::$admin_id, $body['actor_hint'] );
        $this->assertSame( [ 'tone' => 'friendly' ], $body['prompt_overrides'] );
    }

    public function test_prompt_overrides_requires_json_object(): void
    {
        $this->seed_license();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/custom-actions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'template_id', 'tmpl-1' );
        $request->set_param( 'code', 'example' );
        $request->set_param( 'display_name', 'Example' );
        $request->set_param( 'prompt_overrides', '"not-an-object"' );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
    }

    public function test_status_filter_validation_fails_for_invalid_value(): void
    {
        $this->seed_license();
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/custom-actions' );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_param( 'status', 'pending' );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 400, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'rest_invalid_param', $data['code'] );
    }

    private function seed_license(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_license_data( [
            'license_status' => 'active',
            'proxy_api_key'  => 'proxy-key-123',
            'license_id'     => 'lic-1',
            'site_id'        => 'site-1',
        ] );
    }

    private function queue_mock_response( string $method, string $path, array $body, ?callable $assertion = null, int $status = 200 ): void
    {
        $this->mocked_responses[] = [
            'method'    => strtoupper( $method ),
            'path'      => $path,
            'assertion' => $assertion,
            'response'  => [
                'headers'  => [],
                'body'     => wp_json_encode( $body ),
                'response' => [
                    'code'    => $status,
                    'message' => $status >= 400 ? 'Error' : 'OK',
                ],
            ],
        ];
    }

    /**
     * @return array{url:string,args:array}|null
     */
    private function get_last_request(): ?array
    {
        if ( empty( $this->captured_requests ) ) {
            return null;
        }

        return $this->captured_requests[ array_key_last( $this->captured_requests ) ];
    }
}
