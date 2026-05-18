<?php
/**
 * Generic CPS API client.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Api_Client
 * Lightweight HTTP client that understands CPS envelopes.
 */
class Sentient_Forms_Api_Client
{
    private string $base_url;

    private int $timeout;

    public function __construct( string $base_url, int $timeout = 30 )
    {
        $this->base_url = rtrim( $base_url, '/' );
        $this->timeout  = $timeout;
    }

    /**
     * Perform a POST request.
     *
     * @param string $path    Relative CPS path (e.g., '/license/activate').
     * @param array  $payload JSON serialisable payload.
     * @param array  $options Optional request options. Supported keys:
     *                        - bearer_token: string Authorization bearer token.
     *                        - headers: array Additional headers to merge.
     *
     * @return array|WP_Error Decoded CPS data payload on success, WP_Error on failure.
     */
    public function post( string $path, array $payload, array $options = [] ): WP_Error | array
    {
        $args = [
            'method'      => 'POST',
            'timeout'     => $this->timeout,
            'redirection' => 3,
            'headers'     => $this->build_headers( $options ),
            'body'        => wp_json_encode( $payload ),
        ];

        return $this->request( $path, $args );
    }

    /**
     * Perform a PUT request.
     *
     * @param string $path    Relative CPS path.
     * @param array  $payload Payload to JSON encode.
     * @param array  $options Optional bearer token / headers.
     *
     * @return array|WP_Error
     */
    public function put( string $path, array $payload, array $options = [] ): WP_Error | array
    {
        $args = [
            'method'      => 'PUT',
            'timeout'     => $this->timeout,
            'redirection' => 3,
            'headers'     => $this->build_headers( $options ),
            'body'        => wp_json_encode( $payload ),
        ];

        return $this->request( $path, $args );
    }

    /**
     * Perform a DELETE request. Optionally sends a JSON payload (needed for actor hints).
     *
     * @param string $path    Relative CPS path.
     * @param array  $payload Optional JSON payload.
     * @param array  $options Optional bearer token / headers.
     *
     * @return array|WP_Error
     */
    public function delete( string $path, array $payload = [], array $options = [] ): WP_Error | array
    {
        $has_payload = ! empty( $payload );

        $args = [
            'method'      => 'DELETE',
            'timeout'     => $this->timeout,
            'redirection' => 0,
            'headers'     => $this->build_headers( $options, $has_payload ),
        ];

        if ( $has_payload )
        {
            $args['body'] = wp_json_encode( $payload );
        }

        return $this->request( $path, $args );
    }

    /**
     * Perform a GET request.
     *
     * @param string $path    Relative CPS path.
     * @param array  $options Optional request options (supports bearer_token, headers).
     *
     * @return array|WP_Error
     */
    public function get( string $path, array $options = [] ): WP_Error | array
    {
        $args = [
            'method'      => 'GET',
            'timeout'     => $this->timeout,
            'redirection' => 3,
            'headers'     => $this->build_headers( $options, false ),
        ];

        return $this->request( $path, $args );
    }

    /**
     * Prepare request headers with optional bearer token and overrides.
     *
     * @param array $options                   Request options.
     * @param bool  $include_json_content_type Whether to include application/json content type.
     *
     * @return array
     */
    private function build_headers( array $options, bool $include_json_content_type = true ): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ( $include_json_content_type )
        {
            $headers['Content-Type'] = 'application/json';
        }

        if ( ! empty( $options['bearer_token'] ) )
        {
            $headers['Authorization'] = 'Bearer ' . $options['bearer_token'];
        }

        if ( ! empty( $options['headers'] ) && is_array( $options['headers'] ) )
        {
            $headers = array_merge( $headers, $options['headers'] );
        }

        return $headers;
    }

    private function request( string $path, array $args ): WP_Error | array
    {
        $url = $this->base_url . '/' . ltrim( $path, '/' );
        $response = Sentient_Forms_Url_Policy::remote_request( $url, $args, 'service' );

        return $this->parse_response( $response );
    }

    private function parse_response( WP_Error | array $response ): WP_Error | array
    {
        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            $error_message = json_last_error_msg();
            return new WP_Error(
                'cps_invalid_json',
                sprintf(
                    /* translators: %s: response parsing error message. */
                    __( 'Invalid response from CPS service: %s', 'sentient-forms' ),
                    $error_message
                ),
                [
                    'status'  => $status_code,
                    'payload' => $body,
                ]
            );
        }

        if ( $status_code >= 200 && $status_code < 300 )
        {
            if ( isset( $decoded['success'] ) && $decoded['success'] === true )
            {
                $data = $decoded['data'] ?? $decoded;

                if ( ! is_array( $data ) )
                {
                    return new WP_Error(
                        'cps_invalid_payload',
                        __( 'CPS response missing data payload.', 'sentient-forms' ),
                        [
                            'status'  => $status_code,
                            'payload' => $decoded,
                        ]
                    );
                }

                return $data;
            }

            return new WP_Error(
                'cps_unexpected_response',
                __( 'Unexpected CPS response format.', 'sentient-forms' ),
                [
                    'status'  => $status_code,
                    'payload' => $decoded,
                ]
            );
        }

        $error = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : [];

        $error_code = $error['code'] ?? 'cps_request_failed';
        $message    = $error['message'] ?? __( 'CPS request failed.', 'sentient-forms' );

        return new WP_Error(
            $error_code,
            $message,
            [
                'status'  => $status_code,
                'payload' => $decoded,
            ]
        );
    }
}
