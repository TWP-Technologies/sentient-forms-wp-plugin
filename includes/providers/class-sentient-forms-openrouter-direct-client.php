<?php
/**
 * Direct OpenRouter provider client using WordPress HTTP APIs.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_OpenRouter_Direct_Client implements Sentient_Forms_Provider_Client_Interface
{
    private const DEFAULT_BASE_URL = 'https://openrouter.ai/api/v1';

    public function __construct( private int $timeout = 30 )
    {
    }

    public function validate_key( string $api_key ): array | WP_Error
    {
        return $this->request(
            'GET',
            '/key',
            $api_key,
            null,
            [
                'timeout' => min( $this->timeout, 15 ),
            ]
        );
    }

    public function chat_completion( string $api_key, array $payload, array $options = [] ): array | WP_Error
    {
        if ( empty( $payload['messages'] ) || ! is_array( $payload['messages'] ) )
        {
            return new WP_Error(
                'openrouter_missing_messages',
                __( 'OpenRouter chat completions require a messages array.', 'sentient-forms' )
            );
        }

        return $this->request( 'POST', '/chat/completions', $api_key, $payload, $options );
    }

    public function list_models( array $options = [] ): array | WP_Error
    {
        $query = [];

        foreach ( [ 'output_modalities', 'supported_parameters' ] as $query_key )
        {
            if ( isset( $options[ $query_key ] ) && '' !== trim( (string) $options[ $query_key ] ) )
            {
                $query[ $query_key ] = sanitize_text_field( (string) $options[ $query_key ] );
            }
        }

        $path = '/models';
        if ( ! empty( $query ) )
        {
            $path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        return $this->request(
            'GET',
            $path,
            '',
            null,
            [
                'requires_auth' => false,
                'timeout'       => min( $this->timeout, 15 ),
            ]
        );
    }

    private function request( string $method, string $path, string $api_key, ?array $payload = null, array $options = [] ): array | WP_Error
    {
        $api_key       = trim( $api_key );
        $requires_auth = $options['requires_auth'] ?? true;

        if ( $requires_auth && '' === $api_key )
        {
            return new WP_Error( 'openrouter_missing_api_key', __( 'OpenRouter API key is required.', 'sentient-forms' ) );
        }

        $headers = [
            'Accept'              => 'application/json',
            'HTTP-Referer'        => home_url( '/' ),
            'X-OpenRouter-Title'  => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
        ];

        if ( '' !== $api_key )
        {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        $args = [
            'method'      => $method,
            'timeout'     => isset( $options['timeout'] ) ? max( 1, (int) $options['timeout'] ) : $this->timeout,
            'redirection' => 3,
            'headers'     => $headers,
        ];

        if ( null !== $payload )
        {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $payload );
        }

        $base_url = (string) apply_filters( 'sentient_forms_openrouter_base_url', self::DEFAULT_BASE_URL );
        $url      = rtrim( $base_url, '/' ) . '/' . ltrim( $path, '/' );
        $response = Sentient_Forms_Url_Policy::remote_request( $url, $args, 'service' );

        return $this->parse_response( $response );
    }

    private function parse_response( WP_Error | array $response ): array | WP_Error
    {
        if ( is_wp_error( $response ) )
        {
            return new WP_Error(
                'openrouter_http_error',
                $response->get_error_message(),
                $response->get_error_data()
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $decoded     = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error(
                'openrouter_invalid_json',
                sprintf(
                    /* translators: %s: JSON parser error. */
                    __( 'Invalid response from OpenRouter: %s', 'sentient-forms' ),
                    json_last_error_msg()
                ),
                [
                    'status' => $status_code,
                ]
            );
        }

        if ( $status_code >= 200 && $status_code < 300 )
        {
            return is_array( $decoded ) ? $decoded : [];
        }

        $error = isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? $decoded['error'] : [];
        $code  = isset( $error['code'] ) ? sanitize_key( (string) $error['code'] ) : 'openrouter_request_failed';
        $message = isset( $error['message'] )
            ? sanitize_text_field( (string) $error['message'] )
            : __( 'OpenRouter request failed.', 'sentient-forms' );

        return new WP_Error(
            $code,
            $message,
            [
                'status'  => $status_code,
                'payload' => $decoded,
            ]
        );
    }
}
