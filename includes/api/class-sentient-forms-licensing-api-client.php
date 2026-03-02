<?php
/**
 * Licensing API client.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Licensing_Api_Client
 * Handles CPS licensing activation and deactivation calls.
 */
class Sentient_Forms_Licensing_Api_Client
{
    private string $api_url;

    private int $timeout;

    public function __construct( ?string $api_url = null, int $timeout = 30 )
    {
        $this->api_url = $this->resolve_api_url( $api_url );
        $this->timeout = $timeout;
    }

    public function activate_license( string $license_key, string $site_url, string $local_site_identifier ): WP_Error | array
    {
        $payload = [
            'license_key'            => $license_key,
            'site_url'               => $site_url,
            'local_site_identifier'  => $local_site_identifier,
        ];

        $response = wp_remote_post(
            $this->api_url . '/license/activate',
            $this->build_request_args( $payload )
        );

        return $this->parse_response( $response );
    }

    public function bootstrap_license( string $site_url, string $local_site_identifier ): WP_Error | array
    {
        $payload = [
            'site_url'              => $site_url,
            'local_site_identifier' => $local_site_identifier,
        ];

        $response = wp_remote_post(
            $this->api_url . '/license/bootstrap',
            $this->build_request_args( $payload )
        );

        return $this->parse_response( $response );
    }

    public function deactivate_license( string $proxy_api_key, string $license_id, string $site_id ): WP_Error | array
    {
        $payload = [
            'license_id' => $license_id,
            'site_id'    => $site_id,
        ];

        $response = wp_remote_post(
            $this->api_url . '/license/deactivate',
            $this->build_request_args( $payload, $proxy_api_key )
        );

        return $this->parse_response( $response );
    }

    public function create_checkout_session( string $proxy_api_key, array $payload ): WP_Error | array
    {
        $response = wp_remote_post(
            $this->api_url . '/billing/checkout/session',
            $this->build_request_args( $payload, $proxy_api_key )
        );

        return $this->parse_response( $response );
    }

    public function create_portal_session( string $proxy_api_key, string $return_url ): WP_Error | array
    {
        $payload = [
            'return_url' => $return_url,
        ];

        $response = wp_remote_post(
            $this->api_url . '/billing/portal/session',
            $this->build_request_args( $payload, $proxy_api_key )
        );

        return $this->parse_response( $response );
    }

    public function get_billing_state( string $proxy_api_key ): WP_Error | array
    {
        $response = wp_remote_get(
            $this->api_url . '/billing/state',
            $this->build_request_args( [], $proxy_api_key, 'GET' )
        );

        return $this->parse_response( $response );
    }

    private function build_request_args( array $payload, string $proxy_api_key = '', string $method = 'POST' ): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        if ( ! empty( $proxy_api_key ) )
        {
            $headers['X-API-Key'] = $proxy_api_key;
        }

        return [
            'method'      => $method,
            'timeout'     => $this->timeout,
            'redirection' => 3,
            'headers'     => $headers,
            'body'        => 'GET' === strtoupper( $method ) ? null : wp_json_encode( $payload ),
        ];
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
            return new WP_Error( 'license_invalid_json', sprintf( __( 'Invalid response from licensing service: %s', 'sentient-forms' ), $error_message ), [ 'status' => $status_code ] );
        }

        if ( $status_code >= 200 && $status_code < 300 )
        {
            return $decoded;
        }

        $error_code = $decoded['error_code']
            ?? ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? ( $decoded['error']['code'] ?? null ) : null )
            ?? 'license_activation_failed';
        $error_message = $decoded['message']
            ?? ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ? ( $decoded['error']['message'] ?? null ) : null )
            ?? __( 'Unable to complete licensing request.', 'sentient-forms' );

        return new WP_Error( $error_code, $error_message, [ 'status' => $status_code, 'payload' => $decoded ] );
    }

    /**
     * Resolve API base with overrides (constant/env/filter) and enforce /v1 suffix.
     */
    private function resolve_api_url( ?string $api_url ): string
    {
        $url = $api_url ?: 'https://api.sentientforms.com/v1';

        if ( defined( 'SENTIENT_FORMS_PROXY_API_URL' ) && is_string( constant( 'SENTIENT_FORMS_PROXY_API_URL' ) ) ) {
            $url = constant( 'SENTIENT_FORMS_PROXY_API_URL' );
        } elseif ( getenv( 'SENTIENT_FORMS_PROXY_API_URL' ) ) {
            $url = (string) getenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        }

        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'sentient_forms_proxy_api_url', $url );
            if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
                $url = $filtered;
            }
        }

        $url = rtrim( trim( $url ), '/' );
        if ( substr( $url, -3 ) !== '/v1' ) {
            $url .= '/v1';
        }

        return $url;
    }
}
