<?php

class Tests_OpenRouter_Direct_Client extends WP_UnitTestCase
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

    public function test_validate_key_uses_openrouter_key_endpoint_and_bearer_auth(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/openrouter/key-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_OpenRouter_Direct_Client();
        $result = $client->validate_key( 'sk-or-test' );

        $this->assertIsArray( $result );
        $this->assertSame( 875, $result['data']['limit_remaining'] );
        $this->assertCount( 1, $calls );
        $this->assertSame( 'https://openrouter.ai/api/v1/key', $calls[0]['url'] );
        $this->assertSame( 'GET', $calls[0]['args']['method'] );
        $this->assertSame( 'Bearer sk-or-test', $calls[0]['args']['headers']['Authorization'] );
        $this->assertTrue(
            ! isset( $calls[0]['args']['body'] ) || null === $calls[0]['args']['body'] || '' === $calls[0]['args']['body'],
            'GET key validation should not send a request body.'
        );
    }

    public function test_chat_completion_posts_payload_with_required_headers(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/openrouter/chat-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_OpenRouter_Direct_Client();
        $result = $client->chat_completion(
            'sk-or-chat',
            [
                'model'    => 'openrouter/auto',
                'messages' => [
                    [
                        'role'    => 'user',
                        'content' => 'Classify this message.',
                    ],
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'OpenRouter', $result['provider'] );
        $this->assertSame( 19, $result['usage']['total_tokens'] );
        $this->assertCount( 1, $calls );
        $this->assertSame( 'https://openrouter.ai/api/v1/chat/completions', $calls[0]['url'] );
        $this->assertSame( 'POST', $calls[0]['args']['method'] );
        $this->assertSame( 'application/json', $calls[0]['args']['headers']['Content-Type'] );
        $this->assertSame( 'Bearer sk-or-chat', $calls[0]['args']['headers']['Authorization'] );
        $this->assertNotEmpty( $calls[0]['args']['headers']['HTTP-Referer'] );
        $this->assertNotEmpty( $calls[0]['args']['headers']['X-OpenRouter-Title'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( 'openrouter/auto', $payload['model'] );
    }

    public function test_list_models_uses_public_models_endpoint_without_bearer_auth(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/openrouter/models-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_OpenRouter_Direct_Client();
        $result = $client->list_models(
            [
                'output_modalities'    => 'text',
                'supported_parameters' => 'response_format',
            ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 2, $result['data'] );
        $this->assertCount( 1, $calls );
        $this->assertSame( 'https://openrouter.ai/api/v1/models?output_modalities=text&supported_parameters=response_format', $calls[0]['url'] );
        $this->assertSame( 'GET', $calls[0]['args']['method'] );
        $this->assertArrayNotHasKey( 'Authorization', $calls[0]['args']['headers'] );
    }

    public function test_openrouter_error_response_is_returned_without_exposing_key(): void
    {
        $this->mock_http(
            static function (): array {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 401,
                        'message' => 'Unauthorized',
                    ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/openrouter/error-unauthorized.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_OpenRouter_Direct_Client();
        $result = $client->validate_key( 'sk-or-secret' );

        $this->assertWPError( $result );
        $this->assertSame( 'invalid_api_key', $result->get_error_code() );
        $this->assertStringNotContainsString( 'sk-or-secret', $result->get_error_message() );
        $this->assertSame( 401, $result->get_error_data()['status'] );
    }

    public function test_chat_completion_requires_messages_array(): void
    {
        $client = new Sentient_Forms_OpenRouter_Direct_Client();
        $result = $client->chat_completion( 'sk-or-test', [ 'model' => 'openrouter/auto' ] );

        $this->assertWPError( $result );
        $this->assertSame( 'openrouter_missing_messages', $result->get_error_code() );
    }

    private function mock_http( callable $callback ): void
    {
        $this->http_mock = $callback;
        add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
    }
}
