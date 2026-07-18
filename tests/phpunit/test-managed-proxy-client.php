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
    }

    public function test_execute_rejects_unknown_success_envelope_fields_before_unwrapping(): void
    {
        $response                   = json_decode( file_get_contents( __DIR__ . '/../fixtures/managed/execute-success.json' ), true );
        $response['provider_debug'] = 'must-not-escape';

        $this->mock_http(
            static function () use ( $response ): array {
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
            static function () use ( $response ): array {
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
            static function (): array {
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
            static function (): array {
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
            static function (): array {
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
            static function ( $preempt, array $args, string $url ) use ( &$calls ): WP_Error {
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
            static function ( $preempt, array $args, string $url ) use ( &$calls ): WP_Error {
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
            'embedded user info' => [ 'https://api.sentientforms.com@evil.example/v1' ],
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
     * @param array<string, mixed> $payload
     */
    private function mock_execute_response( int $status, array $payload ): void
    {
        $this->mock_http(
            static function () use ( $status, $payload ): array {
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
