<?php
/**
 * Dev-only OpenRouter mock for local-first browser smoke tests.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

$env = function_exists( 'wp_get_environment_type' )
    ? wp_get_environment_type()
    : ( getenv( 'WP_ENVIRONMENT_TYPE' ) ?: null );

if ( 'development' !== $env )
{
    return;
}

add_filter(
    'pre_http_request',
    function ( $preempt, array $args, string $url ) {
        if ( '1' !== (string) get_option( 'sentient_forms_local_openrouter_smoke_mock_enabled', '' ) )
        {
            return $preempt;
        }

        $urls = get_option( 'sentient_forms_local_openrouter_smoke_http_urls', [] );
        if ( ! is_array( $urls ) )
        {
            $urls = [];
        }

        $urls[] = $url;
        update_option( 'sentient_forms_local_openrouter_smoke_http_urls', array_slice( $urls, -100 ), false );

        if ( false !== strpos( $url, 'sentientforms.com' ) )
        {
            return new WP_Error(
                'sentient_forms_unexpected_remote_request',
                'Local-first OpenRouter browser smoke attempted to call Sentient.'
            );
        }

        if ( false === strpos( $url, 'openrouter.ai/api/v1/chat/completions' ) )
        {
            return $preempt;
        }

        $mode = (string) get_option( 'sentient_forms_local_openrouter_smoke_mode', 'success' );

        if ( 'missing_auth_wp_error' === $mode )
        {
            return new WP_Error(
                'http_request_failed',
                'Missing Authentication header',
                [
                    'status' => 401,
                ]
            );
        }

        if ( 'http_429' === $mode )
        {
            return [
                'headers'  => [],
                'body'     => wp_json_encode(
                    [
                        'error' => [
                            'code'    => 'rate_limited',
                            'message' => 'OpenRouter rate limit reached for the smoke harness.',
                        ],
                    ]
                ),
                'response' => [
                    'code'    => 429,
                    'message' => 'Too Many Requests',
                ],
                'cookies'  => [],
            ];
        }

        if ( 'malformed_json' === $mode )
        {
            return [
                'headers'  => [],
                'body'     => '{not-valid-json',
                'response' => [
                    'code'    => 200,
                    'message' => 'OK',
                ],
                'cookies'  => [],
            ];
        }

        $assistant_content = get_option( 'sentient_forms_local_openrouter_smoke_response_json', [] );
        if ( ! is_array( $assistant_content ) )
        {
            $assistant_content = [];
        }

        if ( empty( $assistant_content ) )
        {
            $assistant_content = [
                'summary' => 'Browser local-first submission completed.',
            ];
        }

        return [
            'headers'  => [],
            'body'     => wp_json_encode(
                [
                    'id'      => 'chatcmpl-local-browser-smoke',
                    'model'   => 'openrouter/auto',
                    'choices' => [
                        [
                            'message'       => [
                                'role'    => 'assistant',
                                'content' => wp_json_encode( $assistant_content ),
                            ],
                            'finish_reason' => 'stop',
                        ],
                    ],
                    'usage'   => [
                        'prompt_tokens'     => 19,
                        'completion_tokens' => 8,
                        'total_tokens'      => 27,
                    ],
                ]
            ),
            'response' => [
                'code'    => 200,
                'message' => 'OK',
            ],
            'cookies'  => [],
        ];
    },
    10,
    3
);
