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
                'temperature'          => '0.2',
                'max_output_tokens'    => '512',
                'reasoning'            => [
                    'effort'  => 'high',
                    'exclude' => false,
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
        $this->assertArrayNotHasKey( 'managed_capability_policy', $payload );
    }

    public function test_execute_sends_only_a_satisfied_nonempty_managed_capability_policy(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [ 'args' => $args, 'url' => $url ];

                return [
                    'headers'  => [],
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'                  => '22222222-2222-4222-8222-222222222222',
                'execution_request_id'     => 'managed-capabilities-1',
                'model'                    => 'openai/gpt-4.1-mini',
                'prompt'                   => 'Research and summarize this entry.',
                'max_output_tokens'        => 512,
                'tools'                    => [ [ 'type' => 'openrouter:web_search' ] ],
                'tool_choice'              => 'required',
                'privacy_route_policy'     => [
                    'schema'          => 'sentient_forms_privacy_route_policy.v1',
                    'require_zdr'     => true,
                    'data_collection' => 'deny',
                ],
                'managed_capability_policy' => [
                    'required_capabilities' => [ 'server_tools', 'web_search', 'privacy_zdr', 'bounded_output' ],
                    'schema'                => 'sentient_forms_managed_capability_policy.v1',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $calls );
        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame(
            [
                'schema'                => 'sentient_forms_managed_capability_policy.v1',
                'required_capabilities' => [ 'server_tools', 'web_search', 'privacy_zdr', 'bounded_output' ],
            ],
            $payload['managed_capability_policy'] ?? null
        );
    }

    /**
     * @dataProvider invalid_managed_capability_policies
     */
    public function test_execute_rejects_unknown_or_unsatisfied_managed_capability_policy_before_transport(
        array $policy,
        string $expected_error
    ): void
    {
        $calls = 0;
        $this->mock_http(
            static function () use ( &$calls ): WP_Error {
                ++$calls;
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $client = new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' );
        $result = $client->execute(
            'proxy-secret',
            [
                'site_id'                  => '22222222-2222-4222-8222-222222222222',
                'execution_request_id'     => 'managed-capabilities-invalid',
                'model'                    => 'openai/gpt-4.1-mini',
                'prompt'                   => 'Summarize this entry.',
                'managed_capability_policy' => $policy,
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( $expected_error, $result->get_error_code() );
        $this->assertSame( 0, $calls );
    }

    public function invalid_managed_capability_policies(): array
    {
        return [
            'unknown capability' => [
                [
                    'schema'                => 'sentient_forms_managed_capability_policy.v1',
                    'required_capabilities' => [ 'wordpress_action_semantics' ],
                ],
                'sentient_managed_unknown_capability',
            ],
            'request does not enable capability' => [
                [
                    'schema'                => 'sentient_forms_managed_capability_policy.v1',
                    'required_capabilities' => [ 'privacy_zdr' ],
                ],
                'sentient_managed_unsatisfied_capability',
            ],
        ];
    }

    public function test_execute_normalizes_canonical_empty_capability_policy_to_wire_omission(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = [ 'args' => $args, 'url' => $url ];
                return [
                    'headers'  => [],
                    'response' => [ 'code' => 200, 'message' => 'OK' ],
                    'body'     => file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ),
                    'cookies'  => [],
                ];
            }
        );

        $result = ( new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' ) )->execute(
            'proxy-secret',
            [
                'site_id'                  => '22222222-2222-4222-8222-222222222222',
                'execution_request_id'     => 'managed-capabilities-empty',
                'model'                    => 'openai/gpt-4.1-mini',
                'prompt'                   => 'Summarize this entry.',
                'managed_capability_policy' => [
                    'schema'                => 'sentient_forms_managed_capability_policy.v1',
                    'required_capabilities' => [],
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $calls );
        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertArrayNotHasKey( 'managed_capability_policy', $payload );
    }

    /**
     * @dataProvider invalid_managed_tool_choices
     */
    public function test_execute_rejects_invalid_tool_choice_before_capability_satisfiability_and_transport(
        mixed $tool_choice
    ): void
    {
        $calls = 0;
        $this->mock_http(
            static function () use ( &$calls ): WP_Error {
                ++$calls;
                return new WP_Error( 'unexpected_http', 'No HTTP request should be made.' );
            }
        );

        $result = ( new Sentient_Forms_Managed_Proxy_Client( 'https://minimal.sentient.test/v2' ) )->execute(
            'proxy-secret',
            [
                'site_id'                  => '22222222-2222-4222-8222-222222222222',
                'execution_request_id'     => 'managed-invalid-tool-choice',
                'model'                    => 'openai/gpt-4.1-mini',
                'prompt'                   => 'Research this entry.',
                'tools'                    => [ [ 'type' => 'openrouter:web_search' ] ],
                'tool_choice'              => $tool_choice,
                'managed_capability_policy' => [
                    'schema'                => 'sentient_forms_managed_capability_policy.v1',
                    'required_capabilities' => [ 'server_tools' ],
                ],
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_tool_choice', $result->get_error_code() );
        $this->assertSame( 0, $calls );
    }

    public function invalid_managed_tool_choices(): array
    {
        return [
            'unsupported scalar' => [ 'off' ],
            'non scalar'         => [ [ 'required' ] ],
        ];
    }

    public function test_exact_v1_base_url_fails_closed(): void
    {
        $client = new Sentient_Forms_Managed_Proxy_Client( 'http://127.0.0.1:3000/v1' );
        $result = $client->health();

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_base_url', $result->get_error_code() );
    }

    /**
     * @dataProvider unsupported_managed_base_urls
     */
    public function test_request_fails_closed_for_unsupported_or_hostile_base_url( string $url ): void
    {
        $client = new Sentient_Forms_Managed_Proxy_Client( $url );
        $result = $client->health();

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

    public function test_execute_can_send_managed_privacy_route_policy(): void
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

    public function test_base_url_falls_back_to_cps_base_url_resolution(): void
    {
        $previous_managed_url = getenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        $previous_proxy_url   = getenv( 'SENTIENT_FORMS_PROXY_API_URL' );

        $filter = static function (): string {
            return 'https://staging-api.sentientforms.com/v2';
        };

        putenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        putenv( 'SENTIENT_FORMS_PROXY_API_URL' );

        add_filter( 'sentient_forms_cps_base_url', $filter, 10, 2 );

        try
        {
            $client = new Sentient_Forms_Managed_Proxy_Client();
            $this->assertSame( 'https://staging-api.sentientforms.com/v2', $client->get_base_url() );
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

    public function test_execute_preserves_distinct_schema_valid_metadata_keys(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = compact( 'args', 'url' );

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
                'execution_request_id' => 'managed-req-metadata-keys',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
                'metadata'             => [
                    'A B'    => 'first',
                    'ab'     => 'second',
                    'résumé' => 'visible-unicode',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $payload = json_decode( $calls[0]['args']['body'], true );
        $this->assertSame( 'first', $payload['metadata']['A B'] );
        $this->assertSame( 'second', $payload['metadata']['ab'] );
        $this->assertSame( 'visible-unicode', $payload['metadata']['résumé'] );
    }

    public function test_execute_rejects_unicode_whitespace_metadata_key_before_http_request(): void
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
                'execution_request_id' => 'managed-req-unicode-space',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
                'metadata'             => [
                    "\u{00A0}" => 'value',
                ],
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_managed_invalid_metadata_key', $result->get_error_code() );
    }

    public function test_execute_preserves_numeric_metadata_keys_as_an_object(): void
    {
        $calls = [];
        $this->mock_http(
            static function ( $preempt, array $args, string $url ) use ( &$calls ): array {
                $calls[] = compact( 'args', 'url' );

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
                'execution_request_id' => 'managed-req-numeric-keys',
                'model'                => 'openai/gpt-4.1-mini',
                'prompt'               => 'Summarize this entry.',
                'metadata'             => [
                    '0' => 'zero',
                    '1' => 'one',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $payload = json_decode( $calls[0]['args']['body'] );
        $this->assertInstanceOf( stdClass::class, $payload->metadata );
        $metadata = get_object_vars( $payload->metadata );
        $this->assertSame( 'zero', $metadata['0'] );
        $this->assertSame( 'one', $metadata['1'] );
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
    }

    private function mock_http( callable $callback ): void
    {
        $this->http_mock = $callback;
        add_filter( 'pre_http_request', $this->http_mock, 10, 3 );
    }
}
