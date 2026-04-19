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
                                'content' => wp_json_encode(
                                    [
                                        'summary' => 'Browser local-first submission completed.',
                                    ]
                                ),
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
