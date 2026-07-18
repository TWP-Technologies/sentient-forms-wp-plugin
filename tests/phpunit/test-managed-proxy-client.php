<?php

class Tests_Managed_Proxy_Client extends WP_UnitTestCase
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

    public function test_execute_posts_managed_payload_to_v2_service_with_bearer_auth(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array
            {
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
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'action_code'          => 'entry_summary_v1',
                'prompt'               => "Summarize this entry.\nKeep it short.",
                'output_contract'      => [
                    'schema' => [
                        'type' => 'object',
                    ],
                ],
                'metadata'             => [
                    'mapping_id'     => 123,
                    'entry_id'       => '99',
                    'plugin_version' => '0.1.0-test',
                ],
                'temperature'          => 0.2,
                'max_output_tokens'    => 512,
                'timeout_seconds'      => 30,
                'reasoning'            => [
                    'effort'  => 'high',
                    'exclude' => true,
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'succeeded', $result['status'] );
        $this->assertArrayNotHasKey( 'billed_amount_microusd', $result['metering'] );
        $this->assertArrayNotHasKey( 'currency', $result['metering'] );
        $this->assertSame( 'https://minimal.sentient.test/v2', $client->get_base_url() );
        $this->assertCount( 1, $calls );
        $this->assertSame( 'https://minimal.sentient.test/v2/managed/execute', $calls[0]['url'] );
        $this->assertSame( 'POST', $calls[0]['args']['method'] );
        $this->assertSame( 'application/json', $calls[0]['args']['headers']['Content-Type'] );
        $this->assertSame( 'Bearer proxy-secret', $calls[0]['args']['headers']['Authorization'] );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( 'sentient_managed', $payload['provider'] );
        $this->assertSame( '22222222-2222-4222-8222-222222222222', $payload['site_id'] );
        $this->assertSame( 'managed-req-1', $payload['execution_request_id'] );
        $this->assertSame( "Summarize this entry.\nKeep it short.", $payload['prompt'] );
        $this->assertArrayNotHasKey( 'input', $payload );
        $this->assertSame( 0.2, $payload['temperature'] );
        $this->assertSame( 512, $payload['max_output_tokens'] );
        $this->assertSame( [ 'effort' => 'high', 'exclude' => true ], $payload['reasoning'] );
        $this->assertSame( 123, $payload['metadata']['mapping_id'] );
        $this->assertSame( '99', $payload['metadata']['entry_id'] );
    }

    public function test_execute_sends_only_a_satisfied_nonempty_managed_capability_policy(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array
            {
                $calls[] = [ 'args' => $args, 'url' => $url ];

                return [
                    'headers'  => [],
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $payload = $this->valid_execute_payload();
        $payload['max_output_tokens'] = 512;
        $payload['tools'] = [
            [
                'type'       => 'openrouter:web_search',
                'parameters' => [
                    'search_context_size' => 'medium',
                    'sources'             => [ 'web' ],
                ],
            ],
        ];
        $payload['tool_choice'] = 'required';
        $payload['privacy_route_policy'] = [
            'schema'          => 'sentient_forms_privacy_route_policy.v1',
            'require_zdr'     => true,
            'data_collection' => 'deny',
        ];
        $payload['managed_capability_policy'] = [
            'schema'                => 'sentient_forms_managed_capability_policy.v1',
            'required_capabilities' => [ 'server_tools', 'web_search', 'privacy_zdr', 'bounded_output' ],
        ];

        $result = ( new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' ) )
            ->execute( 'proxy-secret', $payload );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $calls );
        $sent = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( $payload['managed_capability_policy'], $sent['managed_capability_policy'] ?? null );
        $this->assertSame( $payload['tools'], $sent['tools'] ?? null );
    }

    public function test_execute_preserves_explicit_empty_json_object_identity(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args ) use ( &$calls ): array
            {
                $calls[] = $args;
                return [
                    'headers'  => [],
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $payload = $this->valid_execute_payload();
        $payload['output_contract'] = new stdClass();
        $payload['metadata'] = new stdClass();

        $result = ( new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' ) )
            ->execute( 'proxy-secret', $payload );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $calls );
        $this->assertMatchesRegularExpression( '/"output_contract"\s*:\s*\{\}/', $calls[0]['body'] );
        $this->assertMatchesRegularExpression( '/"metadata"\s*:\s*\{\}/', $calls[0]['body'] );
    }

    public function test_execute_accepts_cps_application_execution_request_id_grammar(): void
    {
        $calls    = [];
        $response = json_decode( file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ), true );
        $response['data']['execution_request_id'] = 'Az09-_.:/@';
        $this->mock_http(
            static function ( $preempt, array $args ) use ( &$calls, $response ): array
            {
                $calls[] = $args;
                return [
                    'headers'  => [],
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body'     => wp_json_encode( $response ),
                    'cookies'  => [],
                ];
            }
        );

        $payload                         = $this->valid_execute_payload();
        $payload['execution_request_id'] = 'Az09-_.:/@';
        $result                          = ( new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' ) )
            ->execute( 'proxy-secret', $payload );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $calls );
        $sent = json_decode( $calls[0]['body'], true );
        $this->assertSame( 'Az09-_.:/@', $sent['execution_request_id'] ?? null );
    }

    /**
     * @dataProvider nonconcordant_v2_request_field_provider
     *
     * @param array<string, mixed> $overrides
     */
    public function test_execute_rejects_nonconcordant_v2_request_field_before_http(
        array $overrides,
        string $expected_error
    ): void
    {
        $calls = 0;
        $this->mock_http(
            static function () use ( &$calls ): WP_Error
            {
                ++$calls;
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $result = ( new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' ) )->execute(
            'proxy-secret',
            array_replace( $this->valid_execute_payload(), $overrides )
        );

        $this->assertWPError( $result );
        $this->assertSame( $expected_error, $result->get_error_code() );
        $this->assertSame( 0, $calls );
    }

    /**
     * @return array<string, array{0:array<string, mixed>,1:string}>
     */
    public static function nonconcordant_v2_request_field_provider(): array
    {
        return [
            'site id must be a UUID string' => [ [ 'site_id' => 123 ], 'sentient_managed_invalid_payload' ],
            'site id must be a UUID' => [ [ 'site_id' => 'not-a-uuid' ], 'sentient_managed_invalid_site_id' ],
            'request id must be a string' => [ [ 'execution_request_id' => 123 ], 'sentient_managed_invalid_payload' ],
            'request id cannot be empty' => [ [ 'execution_request_id' => '' ], 'sentient_managed_invalid_execution_request_id' ],
            'request id cannot exceed the schema maximum' => [ [ 'execution_request_id' => str_repeat( 'x', 129 ) ], 'sentient_managed_invalid_execution_request_id' ],
            'request id cannot contain spaces' => [ [ 'execution_request_id' => 'managed request' ], 'sentient_managed_invalid_execution_request_id' ],
            'request id cannot contain unsupported punctuation' => [ [ 'execution_request_id' => 'managed+request' ], 'sentient_managed_invalid_execution_request_id' ],
            'request id cannot contain non-ASCII characters' => [ [ 'execution_request_id' => 'managed-ü' ], 'sentient_managed_invalid_execution_request_id' ],
            'model must be a string' => [ [ 'model' => 123 ], 'sentient_managed_invalid_payload' ],
            'model must not contain spaces' => [ [ 'model' => 'openai / model' ], 'sentient_managed_invalid_model' ],
            'prompt must be a string' => [ [ 'prompt' => 123 ], 'sentient_managed_invalid_payload' ],
            'prompt cannot contain only whitespace' => [ [ 'prompt' => " \t\n" ], 'sentient_managed_missing_prompt' ],
            'action code must be a string' => [ [ 'action_code' => 123 ], 'sentient_managed_invalid_action_code' ],
            'action code cannot be null' => [ [ 'action_code' => null ], 'sentient_managed_invalid_action_code' ],
            'action code cannot contain only whitespace' => [ [ 'action_code' => " \t\n" ], 'sentient_managed_invalid_action_code' ],
            'action code must be valid UTF-8' => [ [ 'action_code' => "\xFF" ], 'sentient_managed_invalid_action_code' ],
            'output contract must be an object' => [ [ 'output_contract' => [ 'list-value' ] ], 'sentient_managed_invalid_output_contract' ],
            'output contract cannot be null' => [ [ 'output_contract' => null ], 'sentient_managed_invalid_output_contract' ],
            'output contract must contain finite JSON values' => [ [ 'output_contract' => [ 'limit' => INF ] ], 'sentient_managed_invalid_output_contract' ],
            'temperature must be a number' => [ [ 'temperature' => '0.2' ], 'sentient_managed_invalid_temperature' ],
            'temperature cannot be null' => [ [ 'temperature' => null ], 'sentient_managed_invalid_temperature' ],
            'temperature must be finite' => [ [ 'temperature' => INF ], 'sentient_managed_invalid_temperature' ],
            'maximum output tokens must be an integer' => [ [ 'max_output_tokens' => '512' ], 'sentient_managed_invalid_max_output_tokens' ],
            'maximum output tokens cannot be null' => [ [ 'max_output_tokens' => null ], 'sentient_managed_invalid_max_output_tokens' ],
            'timeout must be an integer' => [ [ 'timeout_seconds' => 30.0 ], 'sentient_managed_invalid_timeout_seconds' ],
            'timeout cannot be null' => [ [ 'timeout_seconds' => null ], 'sentient_managed_invalid_timeout_seconds' ],
            'metadata must be an object' => [ [ 'metadata' => [] ], 'sentient_managed_metadata_not_identifier_only' ],
            'metadata cannot be null' => [ [ 'metadata' => null ], 'sentient_managed_metadata_not_identifier_only' ],
            'metadata numbers must be finite' => [ [ 'metadata' => [ 'score' => INF ] ], 'sentient_managed_metadata_not_identifier_only' ],
            'metadata keys require a Unicode non-whitespace character' => [ [ 'metadata' => [ "\u{2003}" => 'value' ] ], 'sentient_managed_invalid_metadata_key' ],
            'metadata keys cannot exceed the CPS byte limit' => [ [ 'metadata' => [ str_repeat( 'ü', 33 ) => 'value' ] ], 'sentient_managed_invalid_metadata_key' ],
            'reasoning rejects unknown fields' => [ [ 'reasoning' => [ 'effort' => 'low', 'unknown' => true ] ], 'sentient_managed_invalid_reasoning' ],
            'reasoning cannot be null' => [ [ 'reasoning' => null ], 'sentient_managed_invalid_reasoning' ],
            'reasoning exclusion must be true' => [ [ 'reasoning' => [ 'effort' => 'low', 'exclude' => false ] ], 'sentient_managed_invalid_reasoning' ],
            'privacy policy rejects unknown fields' => [
                [
                    'privacy_route_policy' => [
                        'schema'          => 'sentient_forms_privacy_route_policy.v1',
                        'require_zdr'     => true,
                        'data_collection' => 'deny',
                        'unknown'         => true,
                    ],
                ],
                'sentient_managed_invalid_privacy_route_policy',
            ],
            'privacy policy cannot be null' => [ [ 'privacy_route_policy' => null ], 'sentient_managed_invalid_privacy_route_policy' ],
            'tools are capped at four' => [
                [
                    'tools' => array_fill( 0, 5, [ 'type' => 'openrouter:web_search' ] ),
                ],
                'sentient_managed_invalid_tools',
            ],
            'tools cannot be null' => [ [ 'tools' => null ], 'sentient_managed_invalid_tools' ],
            'tool choice must be exact' => [ [ 'tool_choice' => 'off' ], 'sentient_managed_invalid_tool_choice' ],
            'tool choice cannot be null' => [ [ 'tool_choice' => null ], 'sentient_managed_invalid_tool_choice' ],
            'required tool choice requires tools' => [ [ 'tool_choice' => 'required' ], 'sentient_managed_invalid_tool_choice' ],
            'capability policy cannot be empty' => [
                [
                    'managed_capability_policy' => [
                        'schema'                => 'sentient_forms_managed_capability_policy.v1',
                        'required_capabilities' => [],
                    ],
                ],
                'sentient_managed_invalid_capability_policy',
            ],
            'capability policy cannot be null' => [ [ 'managed_capability_policy' => null ], 'sentient_managed_invalid_capability_policy' ],
            'capability must be from canonical vocabulary' => [
                [
                    'managed_capability_policy' => [
                        'schema'                => 'sentient_forms_managed_capability_policy.v1',
                        'required_capabilities' => [ 'wordpress_action_semantics' ],
                    ],
                ],
                'sentient_managed_unknown_capability',
            ],
            'capability policy rejects duplicate requirements' => [
                [
                    'managed_capability_policy' => [
                        'schema'                => 'sentient_forms_managed_capability_policy.v1',
                        'required_capabilities' => [ 'server_tools', 'server_tools' ],
                    ],
                ],
                'sentient_managed_invalid_capability_policy',
            ],
            'capability policy rejects unknown keys' => [
                [
                    'managed_capability_policy' => [
                        'schema'                => 'sentient_forms_managed_capability_policy.v1',
                        'required_capabilities' => [ 'bounded_output' ],
                        'unknown'               => true,
                    ],
                ],
                'sentient_managed_invalid_capability_policy',
            ],
            'capability must be satisfied by request evidence' => [
                [
                    'managed_capability_policy' => [
                        'schema'                => 'sentient_forms_managed_capability_policy.v1',
                        'required_capabilities' => [ 'bounded_output' ],
                    ],
                ],
                'sentient_managed_unsatisfied_capability',
            ],
        ];
    }

    public function test_execute_rejects_unknown_success_envelope_fields_before_unwrapping(): void
    {
        $response                   = json_decode( file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ), true );
        $response['provider_debug'] = 'must-not-escape';

        $this->mock_http(
            static function () use ( $response ): array
            {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode( $response ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
        $this->assertNull( $result->get_error_data() );
        $this->assertStringNotContainsString( 'must-not-escape', wp_json_encode( $result->get_error_data() ) );
    }

    public function test_execute_rejects_success_for_a_different_execution_request(): void
    {
        $response                                    = json_decode( file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ), true );
        $response['data']['execution_request_id']    = 'different-managed-request';

        $this->mock_http(
            static function () use ( $response ): array
            {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode( $response ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
        $this->assertNull( $result->get_error_data() );
    }

    public function test_execute_rejects_malformed_succeeded_response(): void
    {
        $this->mock_http(
            static function (): array
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
                            'data'    => [
                                'execution_request_id' => 'managed-malformed-success',
                                'status'               => 'succeeded',
                                'metering'             => [
                                    'event_id'               => '11111111-1111-4111-8111-111111111111',
                                    'free_usage'             => false,
                                    'pricing_policy_version' => 'test-policy',
                                    'debited_credits'        => 1,
                                ],
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-malformed-success',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
        $this->assertSame( 'managed-malformed-success', $result->get_error_data()['execution_request_id'] ?? null );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    public function test_execute_preserves_settled_recovery_unavailable_error_without_remote_payload(): void
    {
        $this->mock_http(
            static function (): array
            {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 503,
                        'message' => 'Service Unavailable',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'success' => false,
                            'error'   => [
                                'code'    => 'settled_recovery_unavailable',
                                'message' => 'Managed execution settled, but its response payload is unavailable for replay.',
                                'meta'    => [
                                    'execution_request_id' => 'managed-historical-settled',
                                ],
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-historical-settled',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'settled_recovery_unavailable', $result->get_error_code() );
        $this->assertSame( 503, $result->get_error_data()['status'] ?? null );
        $this->assertSame(
            'managed-historical-settled',
            $result->get_error_data()['execution_request_id'] ?? null
        );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    public function test_execute_normalizes_privacy_route_failure_without_remote_payload(): void
    {
        $this->mock_http(
            static function (): array
            {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 503,
                        'message' => 'Service Unavailable',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'success' => false,
                            'error'   => [
                                'code'    => 'managed_privacy_route_unavailable',
                                'message' => 'No ZDR-safe managed route was available, so Sentient Forms did not run this action without ZDR.',
                                'meta'    => [
                                    'privacy_route_failure' => [
                                        'schema'         => 'sentient_forms_privacy_route_failure.v1',
                                        'policy_version' => 'managed-zdr-v1',
                                        'reason_code'    => 'managed_zdr_route_unavailable',
                                        'selected_model' => 'google/gemini-3-flash-preview',
                                    ],
                                ],
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-privacy-failure',
                'model'                => 'google/gemini-3-flash-preview',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'managed_privacy_route_unavailable', $result->get_error_code() );
        $this->assertSame( 503, $result->get_error_data()['status'] ?? null );
        $this->assertSame( 'managed-privacy-failure', $result->get_error_data()['execution_request_id'] ?? null );
        $this->assertSame(
            'google/gemini-3-flash-preview',
            $result->get_error_data()['privacy_route_failure']['selected_model'] ?? null
        );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    public function test_execute_rejects_legacy_digest_only_settled_success(): void
    {
        $this->mock_execute_response(
            200,
            [
                'success' => true,
                'data'    => [
                    'execution_request_id' => 'managed-legacy-settled',
                    'status'               => 'settled',
                    'replay'               => true,
                    'response_digest'      => 'sha256:' . str_repeat( 'a', 64 ),
                    'metering'             => [
                        'event_id'               => '11111111-1111-4111-8111-111111111111',
                        'free_usage'             => false,
                        'pricing_policy_version' => 'test-policy',
                        'debited_credits'        => 1,
                    ],
                ],
            ]
        );

        $result = $this->execute_minimal_request( 'managed-legacy-settled' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
        $this->assertSame( 'managed-legacy-settled', $result->get_error_data()['execution_request_id'] ?? null );
        $this->assertArrayNotHasKey( 'response_digest', $result->get_error_data() );
    }

    /**
     * @dataProvider managed_currency_field_provider
     */
    public function test_execute_rejects_managed_currency_in_succeeded_response( string $field, mixed $value ): void
    {
        $response = self::canonical_execute_envelope( 'managed-currency-rejected' );
        $response['data']['metering'][ $field ] = $value;
        $this->mock_execute_response( 200, $response );

        $result = $this->execute_minimal_request( 'managed-currency-rejected' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public function managed_currency_field_provider(): array
    {
        return [
            'currency'                => [ 'currency', 'USD' ],
            'billed amount microusd'  => [ 'billed_amount_microusd', 1250 ],
        ];
    }

    public function test_execute_accepts_provider_authoritative_token_total(): void
    {
        $response                                        = self::canonical_execute_envelope( 'managed-provider-token-total' );
        $response['data']['token_usage']['total_tokens'] = 23;
        $this->mock_execute_response( 200, $response );

        $result = $this->execute_minimal_request( 'managed-provider-token-total' );

        $this->assertIsArray( $result );
        $this->assertSame( 23, $result['token_usage']['total_tokens'] );
    }

    public function test_execute_accepts_schema_valid_pricing_policy_version(): void
    {
        $response                                                     = self::canonical_execute_envelope( 'managed-pricing-policy-version' );
        $response['data']['metering']['pricing_policy_version']       = 'managed.v2:/@-';
        $this->mock_execute_response( 200, $response );

        $result = $this->execute_minimal_request( 'managed-pricing-policy-version' );

        $this->assertIsArray( $result );
        $this->assertSame( 'managed.v2:/@-', $result['metering']['pricing_policy_version'] );
    }

    /**
     * @dataProvider invalid_managed_pricing_policy_version_provider
     */
    public function test_execute_rejects_nonconcordant_pricing_policy_version( string $pricing_policy_version ): void
    {
        $response                                               = self::canonical_execute_envelope( 'managed-invalid-pricing-policy-version' );
        $response['data']['metering']['pricing_policy_version'] = $pricing_policy_version;
        $this->mock_execute_response( 200, $response );

        $result = $this->execute_minimal_request( 'managed-invalid-pricing-policy-version' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    /**
     * @return array<string, array{string}>
     */
    public function invalid_managed_pricing_policy_version_provider(): array
    {
        return [
            'empty'                   => [ '' ],
            'whitespace'              => [ 'managed policy' ],
            'unsupported punctuation' => [ 'managed#policy' ],
            'non-ASCII'               => [ 'managed-ü' ],
            'too long'                => [ str_repeat( 'x', 129 ) ],
        ];
    }

    public function test_execute_rejects_explicit_null_optional_privacy_contracts(): void
    {
        $response                                      = self::canonical_execute_envelope( 'managed-null-privacy-contracts' );
        $response['data']['privacy_route_assertion']   = null;
        $response['data']['privacy_route_fallback']    = null;
        $this->mock_execute_response( 200, $response );

        $result = $this->execute_minimal_request( 'managed-null-privacy-contracts' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
    }

    public function test_execute_accepts_schema_valid_privacy_fallback_contract(): void
    {
        $response = self::canonical_execute_envelope( 'managed-privacy-fallback' );
        $response['data']['model'] = 'google/gemini-2.5-flash-preview';
        $response['data']['privacy_route_assertion'] = [
            'schema'              => 'sentient_forms_privacy_route_assertion.v1',
            'zdr_enforced'        => true,
            'data_collection'     => 'deny',
            'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
        ];
        $response['data']['privacy_route_fallback'] = [
            'schema'          => 'sentient_forms_privacy_route_fallback.v1',
            'policy_version'  => 'managed-zdr-v1',
            'reason_code'     => 'managed_zdr_primary_route_unavailable',
            'original_model'  => 'google/gemini-3-flash-preview',
            'fallback_model'  => 'google/gemini-2.5-flash-preview',
            'executed_model'  => 'google/gemini-2.5-flash-preview',
            'attempts'        => 2,
        ];
        $this->mock_execute_response( 200, $response );

        $result = $this->execute_minimal_request( 'managed-privacy-fallback' );

        $this->assertIsArray( $result );
        $this->assertSame( 2, $result['privacy_route_fallback']['attempts'] );
        $this->assertTrue( $result['privacy_route_assertion']['zdr_enforced'] );
    }

    public function test_execute_rejects_privacy_fallback_when_executed_model_differs_from_response(): void
    {
        $response = self::canonical_execute_envelope( 'managed-privacy-fallback-model-mismatch' );
        $response['data']['privacy_route_assertion'] = [
            'schema'              => 'sentient_forms_privacy_route_assertion.v1',
            'zdr_enforced'        => true,
            'data_collection'     => 'deny',
            'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
        ];
        $response['data']['privacy_route_fallback'] = [
            'schema'          => 'sentient_forms_privacy_route_fallback.v1',
            'policy_version'  => 'managed-zdr-v1',
            'reason_code'     => 'managed_zdr_primary_route_unavailable',
            'original_model'  => 'google/gemini-3-flash-preview',
            'fallback_model'  => 'google/gemini-2.5-flash-preview',
            'executed_model'  => 'google/gemini-2.5-flash-preview',
            'attempts'        => 2,
        ];
        $this->mock_execute_response( 200, $response );

        $result = $this->execute_minimal_request( 'managed-privacy-fallback-model-mismatch' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_response', $result->get_error_code() );
    }

    /**
     * @dataProvider managed_lifecycle_error_provider
     */
    public function test_execute_normalizes_stable_lifecycle_errors_without_remote_payload(
        string $code,
        int $status
    ): void
    {
        $execution_request_id = 'managed-' . str_replace( '_', '-', $code );
        $this->mock_execute_response(
            $status,
            [
                'success' => false,
                'error'   => [
                    'code'    => $code,
                    'message' => 'Managed execution has not reached a replayable terminal response.',
                    'meta'    => [
                        'execution_request_id' => $execution_request_id,
                    ],
                ],
            ]
        );

        $result = $this->execute_minimal_request( $execution_request_id );

        $this->assertWPError( $result );
        $this->assertSame( $code, $result->get_error_code() );
        $this->assertSame( $status, $result->get_error_data()['status'] ?? null );
        $this->assertSame( $execution_request_id, $result->get_error_data()['execution_request_id'] ?? null );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    /**
     * @return array<string, array{string, int}>
     */
    public function managed_lifecycle_error_provider(): array
    {
        return [
            'in progress'        => [ 'in_progress', 409 ],
            'digest conflict'    => [ 'digest_conflict', 409 ],
            'settlement pending' => [ 'settlement_pending', 503 ],
            'indeterminate'      => [ 'indeterminate', 503 ],
        ];
    }

    /**
     * @dataProvider correlated_managed_error_provider
     */
    public function test_execute_rejects_managed_error_for_a_different_execution_request(
        string $code,
        int $status,
        string $message
    ): void
    {
        $this->mock_execute_response(
            $status,
            [
                'success' => false,
                'error'   => [
                    'code'    => $code,
                    'message' => $message,
                    'meta'    => [
                        'execution_request_id' => 'different-managed-request',
                    ],
                ],
            ]
        );

        $result = $this->execute_minimal_request( 'managed-original-request' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_error', $result->get_error_code() );
        $this->assertSame( $status, $result->get_error_data()['status'] ?? null );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public function correlated_managed_error_provider(): array
    {
        $active_message = 'Managed execution has not reached a replayable terminal response.';

        return [
            'in progress'                  => [ 'in_progress', 409, $active_message ],
            'digest conflict'              => [ 'digest_conflict', 409, $active_message ],
            'settlement pending'           => [ 'settlement_pending', 503, $active_message ],
            'indeterminate'                => [ 'indeterminate', 503, $active_message ],
            'settled recovery unavailable' => [
                'settled_recovery_unavailable',
                503,
                'Managed execution settled, but its response payload is unavailable for replay.',
            ],
        ];
    }

    public function test_execute_rejects_generic_error_with_reserved_managed_metadata(): void
    {
        $this->mock_execute_response(
            422,
            [
                'success' => false,
                'error'   => [
                    'code'    => 'managed_request_rejected',
                    'message' => 'Managed request rejected.',
                    'meta'    => [
                        'execution_request_id' => 'managed-reserved-meta',
                    ],
                ],
            ]
        );

        $result = $this->execute_minimal_request( 'managed-reserved-meta' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_error', $result->get_error_code() );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    public function test_execute_accepts_schema_valid_generic_error_with_empty_object_metadata(): void
    {
        $this->mock_execute_response(
            422,
            [
                'success' => false,
                'error'   => [
                    'code'    => 'managed_request_rejected',
                    'message' => 'Managed request rejected.',
                    'meta'    => (object) [],
                ],
            ]
        );

        $result = $this->execute_minimal_request( 'managed-empty-object-meta' );

        $this->assertWPError( $result );
        $this->assertSame( 'managed_request_rejected', $result->get_error_code() );
        $this->assertSame( 422, $result->get_error_data()['status'] ?? null );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    public function test_execute_accepts_schema_valid_unbounded_generic_error_strings(): void
    {
        $code    = str_repeat( 'c', 129 );
        $message = str_repeat( 'm', 513 );
        $this->mock_execute_response(
            422,
            [
                'success' => false,
                'error'   => [
                    'code'    => $code,
                    'message' => $message,
                ],
            ]
        );

        $result = $this->execute_minimal_request( 'managed-unbounded-generic-error' );

        $this->assertWPError( $result );
        $this->assertSame( $code, $result->get_error_code() );
        $this->assertSame( $message, $result->get_error_message() );
        $this->assertSame( 422, $result->get_error_data()['status'] ?? null );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    /**
     * @dataProvider malformed_managed_execute_error_provider
     *
     * @param array<string, mixed> $payload
     */
    public function test_execute_rejects_malformed_managed_errors_without_remote_payload(
        int $status,
        array $payload
    ): void
    {
        $this->mock_execute_response( $status, $payload );

        $result = $this->execute_minimal_request( 'managed-malformed-error' );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_execute_error', $result->get_error_code() );
        $data = $result->get_error_data();
        if ( is_array( $data ) )
        {
            $this->assertArrayNotHasKey( 'payload', $data );
        }
    }

    /**
     * @return array<string, array{int, array<string, mixed>}>
     */
    public function malformed_managed_execute_error_provider(): array
    {
        $lifecycle = [
            'success' => false,
            'error'   => [
                'code'    => 'in_progress',
                'message' => 'Managed execution has not reached a replayable terminal response.',
                'meta'    => [
                    'execution_request_id' => 'managed-malformed-error',
                ],
            ],
        ];
        $privacy = [
            'success' => false,
            'error'   => [
                'code'    => 'managed_privacy_route_unavailable',
                'message' => 'No ZDR-safe managed route was available, so Sentient Forms did not run this action without ZDR.',
                'meta'    => [
                    'privacy_route_failure' => [
                        'schema'         => 'sentient_forms_privacy_route_failure.v1',
                        'policy_version' => 'managed-zdr-v1',
                        'reason_code'    => 'managed_zdr_route_unavailable',
                        'selected_model' => 'openai/gpt-4.1-mini',
                    ],
                ],
            ],
        ];

        $wrong_lifecycle_status = $lifecycle;
        $wrong_lifecycle_message = $lifecycle;
        $wrong_lifecycle_message['error']['message'] = 'Still processing.';
        $extra_lifecycle_meta = $lifecycle;
        $extra_lifecycle_meta['error']['meta']['provider_attempt_id'] = 'must-not-escape';
        $wrong_privacy_status = $privacy;
        $extra_privacy_failure_field = $privacy;
        $extra_privacy_failure_field['error']['meta']['privacy_route_failure']['provider_debug'] = 'must-not-escape';

        return [
            'wrong lifecycle status'       => [ 503, $wrong_lifecycle_status ],
            'wrong lifecycle message'      => [ 409, $wrong_lifecycle_message ],
            'extra lifecycle metadata'     => [ 409, $extra_lifecycle_meta ],
            'wrong privacy status'         => [ 422, $wrong_privacy_status ],
            'extra privacy failure field'  => [ 503, $extra_privacy_failure_field ],
            'generic list metadata'        => [
                422,
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'managed_request_rejected',
                        'message' => 'Managed request rejected.',
                        'meta'    => [ 'must-not-escape' ],
                    ],
                ],
            ],
            'generic empty-list metadata'  => [
                422,
                [
                    'success' => false,
                    'error'   => [
                        'code'    => 'managed_request_rejected',
                        'message' => 'Managed request rejected.',
                        'meta'    => [],
                    ],
                ],
            ],
            'generic unexpected error key' => [
                422,
                [
                    'success' => false,
                    'error'   => [
                        'code'           => 'managed_request_rejected',
                        'message'        => 'Managed request rejected.',
                        'provider_debug' => 'must-not-escape',
                    ],
                ],
            ],
            'generic empty code'           => [
                422,
                [
                    'success' => false,
                    'error'   => [
                        'code'    => '',
                        'message' => 'Managed request rejected.',
                    ],
                ],
            ],
        ];
    }

    public function test_exact_v1_base_url_fails_closed_before_http_request(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): WP_Error
            {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v1' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_base_url', $result->get_error_code() );
        $this->assertSame( [], $calls );
    }

    /**
     * @dataProvider unsupported_base_url_provider
     */
    public function test_request_fails_closed_for_unsupported_or_hostile_base_url( string $base_url ): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): WP_Error
            {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( $base_url );
        $result = $client->health();

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_base_url', $result->get_error_code() );
        $this->assertSame( [], $calls );
    }

    /**
     * @return array<string, array{string}>
     */
    public function unsupported_base_url_provider(): array
    {
        return [
            'future version'     => [ 'https://staging-api.sentientforms.com/v3' ],
            'legacy admin path'  => [ 'https://staging-api.sentientforms.com/v1/admin' ],
            'arbitrary path'     => [ 'https://staging-api.sentientforms.com/proxy' ],
            'embedded user info' => [ 'https://user@staging-api.sentientforms.com/v2' ],
            'query string'       => [ 'https://staging-api.sentientforms.com/v2?target=https://evil.example' ],
            'fragment'           => [ 'https://staging-api.sentientforms.com/v2#credentials' ],
            'unsupported scheme' => [ 'ftp://staging-api.sentientforms.com/v2' ],
            'relative URL'       => [ '/v2' ],
        ];
    }

    public function test_execute_can_send_managed_privacy_route_policy(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [
                    'args' => $args,
                    'url'  => $url,
                ];

                $response                                 = json_decode( file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ), true );
                $response['data']['execution_request_id'] = 'managed-zdr-req-1';

                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 200,
                        'message' => 'OK',
                    ],
                    'body'     => wp_json_encode( $response ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-zdr-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
                'privacy_route_policy' => [
                    'schema'          => 'sentient_forms_privacy_route_policy.v1',
                    'require_zdr'     => true,
                    'data_collection' => 'deny',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $calls );

        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame(
            [
                'schema'          => 'sentient_forms_privacy_route_policy.v1',
                'require_zdr'     => true,
                'data_collection' => 'deny',
            ],
            $payload['privacy_route_policy']
        );
    }

    public function test_execute_rejects_invalid_reasoning_before_http_request(): void
    {
        $this->mock_http(
            static function (): WP_Error {
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
                'reasoning'            => [
                    'effort' => 'extreme',
                ],
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_reasoning', $result->get_error_code() );
    }

    public function test_execute_rejects_raw_input_field_before_http_request(): void
    {
        $this->mock_http(
            static function (): WP_Error {
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
                'input'                => [
                    'email' => 'ada@example.test',
                ],
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_unsupported_payload_field', $result->get_error_code() );
        $this->assertSame( [ 'input' ], $result->get_error_data()['unsupported_fields'] );
    }

    public function test_base_url_ignores_legacy_generic_cps_resolution(): void
    {
        $previous_managed_url = getenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        $previous_proxy_url   = getenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        $previous_options     = get_option( 'sentient_forms_settings', null );

        $filter = static function (): string
        {
            return 'https://staging-api.sentientforms.com/v2';
        };

        putenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        putenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        update_option( 'sentient_forms_settings', [] );

        add_filter( 'sentient_forms_cps_base_url', $filter, 10, 2 );

        try
        {
            $client = new Sentient_Forms_Managed_Proxy_Client();
            $this->assertSame( 'https://api.sentientforms.com/v2', $client->get_base_url() );
        }
        finally
        {
            remove_filter( 'sentient_forms_cps_base_url', $filter, 10 );
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
            $client = new Sentient_Forms_Managed_Proxy_Client();
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

    public function test_execute_rejects_nested_metadata_before_http_request(): void
    {
        $this->mock_http(
            static function (): WP_Error {
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
                'metadata'             => [
                    'entry_payload' => [
                        'email' => 'ada@example.test',
                    ],
                ],
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_metadata_not_identifier_only', $result->get_error_code() );
        $this->assertSame( 'entry_payload', $result->get_error_data()['metadata_key'] );
    }

    public function test_execute_rejects_missing_proxy_key_before_http_request(): void
    {
        $this->mock_http(
            static function (): WP_Error {
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            '',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_missing_proxy_key', $result->get_error_code() );
    }

    public function test_execute_rejects_non_managed_provider_before_http_request(): void
    {
        $this->mock_http(
            static function (): WP_Error {
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'provider'             => 'openrouter',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_provider_required', $result->get_error_code() );
    }

    public function test_metering_summary_uses_get_without_body(): void
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
                    'body'     => wp_json_encode(
                        [
                            'success' => true,
                            'data'    => [
                                'site_id'         => '22222222-2222-4222-8222-222222222222',
                                'execution_count' => 2,
                                'succeeded_count' => 1,
                                'failed_count'    => 1,
                                'token_usage'     => [
                                    'input_tokens'  => 10,
                                    'output_tokens' => 5,
                                    'total_tokens'  => 15,
                                ],
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test' );
        $result = $client->get_metering_summary( 'proxy-secret', '22222222-2222-4222-8222-222222222222' );

        $this->assertIsArray( $result );
        $this->assertSame( 2, $result['execution_count'] );
        $this->assertArrayNotHasKey( 'billing', $result );
        $this->assertCount( 1, $calls );
        $this->assertSame(
            'https://minimal.sentient.test/v2/metering/summary?site_id=22222222-2222-4222-8222-222222222222',
            $calls[0]['url']
        );
        $this->assertSame( 'GET', $calls[0]['args']['method'] );
        $this->assertSame( 'Bearer proxy-secret', $calls[0]['args']['headers']['Authorization'] );
        $this->assertTrue(
            ! isset( $calls[0]['args']['body'] ) || null === $calls[0]['args']['body'] || '' === $calls[0]['args']['body'],
            'Metering summary should not send a request body.'
        );
    }

    public function test_remote_error_does_not_expose_proxy_key_in_message(): void
    {
        $this->mock_http(
            static function (): array {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => 403,
                        'message' => 'Forbidden',
                    ],
                    'body'     => wp_json_encode(
                        [
                            'success' => false,
                            'error'   => [
                                'code'    => 'managed_site_mismatch',
                                'message' => 'site_id does not match the authenticated site context.',
                            ],
                        ]
                    ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => 'managed-req-1',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'managed_site_mismatch', $result->get_error_code() );
        $this->assertStringNotContainsString( 'proxy-secret', $result->get_error_message() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
        $this->assertArrayNotHasKey( 'payload', $result->get_error_data() );
    }

    private function mock_http( callable $callback ): void
    {
        $this->http_mock = $callback;
        add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
    }

    /**
     * @return array<string, mixed>
     */
    private function valid_execute_payload(): array
    {
        return [
            'site_id'              => '22222222-2222-4222-8222-222222222222',
            'execution_request_id' => 'managed-req-1',
            'model'                => 'openai/gpt-4.1-mini',
            'prompt'               => 'Summarize this entry.',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function mock_execute_response( int $status, array $payload ): void
    {
        $this->mock_http(
            static function () use ( $status, $payload ): array
            {
                return [
                    'headers'  => [],
                    'response' => [
                        'code'    => $status,
                        'message' => $status >= 400 ? 'Request Failed' : 'OK',
                    ],
                    'body'     => wp_json_encode( $payload ),
                    'cookies'  => [],
                ];
            }
        );
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function execute_minimal_request( string $execution_request_id ): array | WP_Error
    {
        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        return $client->execute(
            'proxy-secret',
            [
                'site_id'              => '22222222-2222-4222-8222-222222222222',
                'execution_request_id' => $execution_request_id,
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function canonical_execute_envelope( string $execution_request_id ): array
    {
        $response = json_decode( file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ), true );
        $response['data']['execution_request_id'] = $execution_request_id;
        return $response;
    }
}
