<?php

class ManagedExecutionCallbackControllerTest extends WP_UnitTestCase
{
    private Sentient_Forms_Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = Sentient_Forms_Plugin::instance();
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-callback-secret',
            ]
        );
    }

    public function test_receive_callback_verifies_signature_and_dispatches_success(): void
    {
        $controller = new Sentient_Forms_Managed_Execution_Callback_Controller();
        $payload    = [
            'success' => true,
            'data'    => [
                'job_id'               => wp_generate_uuid4(),
                'execution_request_id' => 'req-callback-success',
                'status'               => 'succeeded',
                'context'              => [
                    'action_id'   => 'spam_detection',
                    'form_source' => 'gravity_forms',
                    'form_id'     => '7',
                    'entry_id'    => '77',
                ],
                'result'               => [
                    'result_data' => [
                        'classification' => 'ham',
                        'llm_output'     => 'HAM',
                    ],
                ],
            ],
        ];

        $captured = null;
        $listener = static function ( array $context, array $result ) use ( &$captured ): void {
            $captured = [
                'context' => $context,
                'result'  => $result,
            ];
        };
        add_action( 'sentient_forms_async_success', $listener, 10, 2 );

        $response = $controller->receive_callback( $this->signed_request( $payload ) );
        remove_action( 'sentient_forms_async_success', $listener, 10 );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertIsArray( $captured );
        $this->assertSame( '77', $captured['context']['entry_id'] ?? null );
        $this->assertSame( 'ham', $captured['result']['result_data']['classification'] ?? null );
    }

    public function test_receive_callback_rejects_bad_signature(): void
    {
        $controller = new Sentient_Forms_Managed_Execution_Callback_Controller();
        $request    = $this->signed_request(
            [
                'success' => true,
                'data'    => [
                    'execution_request_id' => 'req-callback-bad-signature',
                    'status'               => 'succeeded',
                    'context'              => [],
                    'result'               => [],
                ],
            ]
        );
        $request->set_header( 'x-sentient-forms-signature', 'bad-signature' );

        $response = $controller->receive_callback( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'sentient_forms_callback_bad_signature', $response->get_error_code() );
    }

    private function signed_request( array $payload ): WP_REST_Request
    {
        $body      = wp_json_encode( $payload );
        $timestamp = time();
        $request_id = (string) ( $payload['data']['execution_request_id'] ?? '' );
        $fingerprint = hash( 'sha256', 'proxy-callback-secret' );
        $signature = hash_hmac( 'sha256', $timestamp . '.' . $request_id . '.' . $body, $fingerprint );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/managed/execution-callback' );
        $request->set_body( $body );
        $request->set_header( 'x-sentient-forms-timestamp', (string) $timestamp );
        $request->set_header( 'x-sentient-forms-execution-request-id', $request_id );
        $request->set_header( 'x-sentient-forms-signature', $signature );

        return $request;
    }
}
