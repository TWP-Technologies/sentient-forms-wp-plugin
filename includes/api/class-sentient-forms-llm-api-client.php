<?php
/**
 * LLM API client
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Llm_Api_Client
 * Handles communication with the LLM proxy server
 */
class Sentient_Forms_Llm_Api_Client
{

    /**
     * Proxy API key
     */
    private string $api_key;

    /**
     * Proxy API URL
     */
    private string $api_url;

    /**
     * Constructor
     *
     * @param string      $api_key Proxy API key.
     * @param string|null $api_url Proxy API URL.
     */
    public function __construct( string $api_key, ?string $api_url = null )
    {
        $this->api_key = $api_key;
        $this->api_url = $this->resolve_api_url( $api_url );
    }

    /**
     * Get default API URL
     *
     * @return string
     */
    private function get_default_api_url(): string
    {
        return 'https://api.sentientforms.com/v1';
    }

    /**
     * Resolve API URL with overrides from constants/env/filters.
     */
    private function resolve_api_url( ?string $api_url ): string
    {
        $url = $api_url ?: $this->get_default_api_url();

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

    /**
     * Send a query to the LLM via the proxy
     *
     * @param array $data The data to send.
     *
     * @return array|WP_Error The response or error.
     */
    public function query( array $data ): WP_Error | array
    {
        // Ensure we have an API key
        if ( empty( $this->api_key ) )
        {
            return new WP_Error(
                'missing_api_key', __( 'Missing proxy API key. Please enter your API key in the plugin settings.', 'sentient-forms' ),
            );
        }

        // Prepare the request
        $url  = $this->api_url . '/query';
        $args = [
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $this->api_key,
                'X-Site-URL'   => home_url(),
            ],
            'body'        => wp_json_encode( $data ),
            'cookies'     => [],
        ];

        // Send the request
        $response = $this->safe_remote_request( $url, $args );

        // Check for errors
        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        // Get the response code
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 )
        {
            $error_message = wp_remote_retrieve_response_message( $response );
            $body          = wp_remote_retrieve_body( $response );
            $body_data     = json_decode( $body, true );

            if ( isset( $body_data[ 'error' ] ) )
            {
                $error_message = $body_data[ 'error' ];
            }

            return new WP_Error(
                'api_error',
                sprintf(
                    /* translators: %s: API error message. */
                    __( 'API error: %s', 'sentient-forms' ),
                    $error_message
                ),
                [ 'status' => $response_code ],
            );
        }

        // Parse the response
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error( 'json_parse_error', __( 'Error parsing API response', 'sentient-forms' ) );
        }

        return $data;
    }

    /**
     * Validate a license key
     *
     * @param string $license_key License key.
     * @param string $site_url    Site URL.
     *
     * @return array|WP_Error The response or error.
     */
    public function validate_license( string $license_key, string $site_url ): WP_Error | array
    {
        // Prepare the request
        $url  = $this->api_url . '/license/validate';
        $args = [
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '2.0',
            'blocking'    => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $this->api_key,
            ],
            'body'        => wp_json_encode(
                [
                    'license_key' => $license_key,
                    'site_url'    => $site_url,
                ],
            ),
            'cookies'     => [],
        ];

        // Send the request
        $response = $this->safe_remote_request( $url, $args );

        // Check for errors
        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        // Get the response code
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 )
        {
            $error_message = wp_remote_retrieve_response_message( $response );
            $body          = wp_remote_retrieve_body( $response );
            $body_data     = json_decode( $body, true );

            if ( isset( $body_data[ 'error' ] ) )
            {
                $error_message = $body_data[ 'error' ];
            }

            return new WP_Error(
                'license_validation_error',
                sprintf(
                    /* translators: %s: license validation error message. */
                    __( 'License validation error: %s', 'sentient-forms' ),
                    $error_message
                ),
                [ 'status' => $response_code ],
            );
        }

        // Parse the response
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error( 'json_parse_error', __( 'Error parsing license validation response', 'sentient-forms' ) );
        }

        return $data;
    }

    /**
     * Legacy credit balance compatibility shim.
     *
     * @return array|WP_Error The response or error.
     */
    public function get_credit_balance(): WP_Error | array
    {
        return new WP_Error(
            'sentient_forms_credit_balance_retired',
            __( 'The legacy Sentient credit balance route is retired in local-first mode. Use managed billing state for Sentient-managed plan allowance; direct OpenRouter runs are billed by OpenRouter, not Sentient.', 'sentient-forms' ),
            [ 'status' => 410 ],
        );
    }

    /**
     * Get available LLM models
     *
     * @return array|WP_Error The response or error.
     */
    public function get_available_models(): WP_Error | array
    {
        // Ensure we have an API key
        if ( empty( $this->api_key ) )
        {
            return new WP_Error(
                'missing_api_key', __( 'Missing proxy API key. Please enter your API key in the plugin settings.', 'sentient-forms' ),
            );
        }

        // Prepare the request
        $url  = $this->api_url . '/models';
        $args = [
            'method'      => 'GET',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'X-API-Key'  => $this->api_key,
                'X-Site-URL' => home_url(),
            ],
            'cookies'     => [],
        ];

        // Send the request
        $response = $this->safe_remote_request( $url, $args );

        // Check for errors
        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        // Get the response code
        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 )
        {
            $error_message = wp_remote_retrieve_response_message( $response );
            $body          = wp_remote_retrieve_body( $response );
            $body_data     = json_decode( $body, true );

            if ( isset( $body_data[ 'error' ] ) )
            {
                $error_message = $body_data[ 'error' ];
            }

            return new WP_Error(
                'models_error',
                sprintf(
                    /* translators: %s: models API error message. */
                    __( 'Models error: %s', 'sentient-forms' ),
                    $error_message
                ),
                [ 'status' => $response_code ],
            );
        }

        // Parse the response
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error( 'json_parse_error', __( 'Error parsing models response', 'sentient-forms' ) );
        }

        return $data;
    }

    /**
     * Estimate cost for a query
     *
     * @param array $data The data to estimate cost for.
     *
     * @return array|WP_Error The response or error.
     */
    public function estimate_cost( array $data ): WP_Error | array
    {
        if ( empty( $this->api_key ) )
        {
            return new WP_Error(
                'missing_api_key', __( 'Missing proxy API key. Please enter your API key in the plugin settings.', 'sentient-forms' ),
            );
        }

        $url  = $this->api_url . '/estimate';
        $args = [
            'method'      => 'POST',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $this->api_key,
                'X-Site-URL'   => home_url(),
            ],
            'body'        => wp_json_encode( $data ),
            'cookies'     => [],
        ];

        $response = $this->safe_remote_request( $url, $args );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 )
        {
            $error_message = wp_remote_retrieve_response_message( $response );
            $body          = wp_remote_retrieve_body( $response );
            $body_data     = json_decode( $body, true );

            if ( isset( $body_data['error'] ) )
            {
                $error_message = $body_data['error'];
            }

            return new WP_Error(
                'estimate_error',
                sprintf(
                    /* translators: %s: estimate API error message. */
                    __( 'Estimate error: %s', 'sentient-forms' ),
                    $error_message
                ),
                [ 'status' => $response_code ],
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error( 'json_parse_error', __( 'Error parsing estimate response', 'sentient-forms' ) );
        }

        return $data;
    }

    /**
     * Get site context from CPS
     *
     * @return array|null|WP_Error The response or error.
     */
    public function get_site_context(): WP_Error | array | null
    {
        if ( empty( $this->api_key ) )
        {
            return new WP_Error(
                'missing_api_key', __( 'Missing proxy API key.', 'sentient-forms' ),
            );
        }

        $url  = $this->api_url . '/site-context';
        $args = [
            'method'      => 'GET',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'X-API-Key'  => $this->api_key,
                'X-Site-URL' => home_url(),
            ],
            'cookies'     => [],
        ];

        $response = $this->safe_remote_request( $url, $args );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = wp_remote_retrieve_body( $response );
        $data          = json_decode( $body, true );

        if ( $response_code !== 200 )
        {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Failed to fetch site context';
            return new WP_Error(
                'site_context_error', $error_message, [ 'status' => $response_code ],
            );
        }

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error( 'json_parse_error', __( 'Error parsing site context response', 'sentient-forms' ) );
        }

        // Extract data from CPS envelope
        if ( isset( $data['success'] ) && $data['success'] && array_key_exists( 'data', $data ) )
        {
            return $data['data'];
        }

        return $data;
    }

    /**
     * Create or regenerate site context via LLM
     *
     * @param string $site_url URL for the site to generate context for.
     * @param bool   $pii_ack  PII acknowledgment flag.
     *
     * @return array|WP_Error The response or error.
     */
    public function create_site_context( string $site_url, bool $pii_ack ): WP_Error | array
    {
        if ( empty( $this->api_key ) )
        {
            return new WP_Error(
                'missing_api_key', __( 'Missing proxy API key.', 'sentient-forms' ),
            );
        }

        $url  = $this->api_url . '/site-context';
        $args = [
            'method'      => 'POST',
            'timeout'     => 90, // Longer timeout for LLM call
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $this->api_key,
                'X-Site-URL'   => home_url(),
            ],
            'body'        => wp_json_encode( [
                'site_url' => $site_url,
                'pii_ack'  => $pii_ack,
            ] ),
            'cookies'     => [],
        ];

        $response = $this->safe_remote_request( $url, $args );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = wp_remote_retrieve_body( $response );
        $data          = json_decode( $body, true );

        if ( $response_code !== 200 && $response_code !== 201 )
        {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Site context generation failed';
            return new WP_Error(
                'site_context_create_error', $error_message, [ 'status' => $response_code ],
            );
        }

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error( 'json_parse_error', __( 'Error parsing site context response', 'sentient-forms' ) );
        }

        // Extract data from CPS envelope
        if ( isset( $data['success'] ) && $data['success'] && isset( $data['data'] ) )
        {
            return $data['data'];
        }

        return $data;
    }

    /**
     * Update site context (manual edit)
     *
     * @param array $payload Fields to update (summary_text, auto_include, pii_ack).
     *
     * @return array|WP_Error The response or error.
     */
    public function update_site_context( array $payload ): WP_Error | array
    {
        if ( empty( $this->api_key ) )
        {
            return new WP_Error(
                'missing_api_key', __( 'Missing proxy API key.', 'sentient-forms' ),
            );
        }

        $url  = $this->api_url . '/site-context';
        $args = [
            'method'      => 'PUT',
            'timeout'     => 45,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $this->api_key,
                'X-Site-URL'   => home_url(),
            ],
            'body'        => wp_json_encode( $payload ),
            'cookies'     => [],
        ];

        $response = $this->safe_remote_request( $url, $args );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $body          = wp_remote_retrieve_body( $response );
        $data          = json_decode( $body, true );

        if ( $response_code !== 200 )
        {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Site context update failed';
            return new WP_Error(
                'site_context_update_error', $error_message, [ 'status' => $response_code ],
            );
        }

        if ( json_last_error() !== JSON_ERROR_NONE )
        {
            return new WP_Error( 'json_parse_error', __( 'Error parsing site context response', 'sentient-forms' ) );
        }

        // Extract data from CPS envelope
        if ( isset( $data['success'] ) && $data['success'] && isset( $data['data'] ) )
        {
            return $data['data'];
        }

        return $data;
    }

    private function safe_remote_request( string $url, array $args ): WP_Error | array
    {
        return Sentient_Forms_Url_Policy::remote_request( $url, $args, 'service' );
    }
}
