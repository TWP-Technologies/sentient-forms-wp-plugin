<?php
/**
 * Outbound URL validation helpers.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Url_Policy
{
    private const TRUSTED_SERVICE_HOSTS = [
        'api.sentientforms.com',
        'staging-api.sentientforms.com',
        'openrouter.ai',
    ];

    /**
     * Validate an outbound URL before it reaches the WordPress HTTP API.
     *
     * @param string $url     Candidate outbound URL.
     * @param string $context Request context: service or webhook.
     *
     * @return string|WP_Error Sanitized URL, or validation error.
     */
    public static function validate_outbound_url( string $url, string $context = 'service' ): string | WP_Error
    {
        $url = trim( $url );
        if ( '' === $url )
        {
            return self::error( 'sentient_forms_empty_outbound_url', __( 'Outbound URL is empty.', 'sentient-forms' ) );
        }

        $sanitized = esc_url_raw( $url, [ 'http', 'https' ] );
        if ( '' === $sanitized )
        {
            return self::error( 'sentient_forms_invalid_outbound_url', __( 'Outbound URL must use http or https.', 'sentient-forms' ) );
        }

        $parts = wp_parse_url( $sanitized );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) )
        {
            return self::error( 'sentient_forms_invalid_outbound_url', __( 'Outbound URL must include a host.', 'sentient-forms' ) );
        }

        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) )
        {
            return self::error( 'sentient_forms_outbound_url_credentials', __( 'Outbound URLs must not include embedded credentials.', 'sentient-forms' ) );
        }

        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) )
        {
            return self::error( 'sentient_forms_invalid_outbound_url_scheme', __( 'Outbound URL must use http or https.', 'sentient-forms' ) );
        }

        if ( 'service' === $context && 'https' !== $scheme && ! self::allow_insecure_development_url( $sanitized, $context ) )
        {
            return self::error( 'sentient_forms_service_url_requires_https', __( 'Service URLs must use https.', 'sentient-forms' ) );
        }

        $host = strtolower( trim( (string) $parts['host'], " \t\n\r\0\x0B." ) );
        if ( '' === $host || preg_match( '/[:#?\[\]]/', $host ) )
        {
            return self::error( 'sentient_forms_invalid_outbound_url_host', __( 'Outbound URL host is invalid.', 'sentient-forms' ) );
        }

        if ( self::is_local_or_private_host( $host ) && ! self::allow_insecure_development_url( $sanitized, $context ) )
        {
            return self::error( 'sentient_forms_outbound_url_private_host', __( 'Outbound URL must not target localhost or private network hosts.', 'sentient-forms' ) );
        }

        if ( 'service' === $context && ! self::is_trusted_service_host( $host ) && ! self::allow_insecure_development_url( $sanitized, $context ) )
        {
            return self::error( 'sentient_forms_untrusted_service_host', __( 'Service URL host is not trusted for Sentient Forms outbound requests.', 'sentient-forms' ) );
        }

        return $sanitized;
    }

    /**
     * Send an outbound request after applying Sentient Forms URL policy.
     *
     * The development escape hatch intentionally permits local/private CPS hosts.
     * WordPress' safe HTTP wrapper blocks those hosts even after our policy allows
     * them, so use the normal HTTP transport only for that explicit dev case.
     *
     * @param string $url     Candidate outbound URL.
     * @param array  $args    WordPress HTTP API arguments.
     * @param string $context Request context: service or webhook.
     *
     * @return array|WP_Error WordPress HTTP response, or validation/transport error.
     */
    public static function remote_request( string $url, array $args = [], string $context = 'service' ): array | WP_Error
    {
        $validated = self::validate_outbound_url( $url, $context );
        if ( is_wp_error( $validated ) )
        {
            return $validated;
        }

        if ( self::should_use_development_http_transport( $validated, $context ) )
        {
            $args['reject_unsafe_urls'] = false;
            return wp_remote_request( $validated, $args );
        }

        $args['reject_unsafe_urls'] = true;
        return wp_safe_remote_request( $validated, $args );
    }

    /**
     * Send a POST request after applying Sentient Forms URL policy.
     *
     * @param string $url     Candidate outbound URL.
     * @param array  $args    WordPress HTTP API arguments.
     * @param string $context Request context: service or webhook.
     *
     * @return array|WP_Error WordPress HTTP response, or validation/transport error.
     */
    public static function remote_post( string $url, array $args = [], string $context = 'service' ): array | WP_Error
    {
        $args['method'] = 'POST';
        return self::remote_request( $url, $args, $context );
    }

    private static function is_trusted_service_host( string $host ): bool
    {
        $trusted_hosts = (array) apply_filters( 'sentient_forms_trusted_service_hosts', self::TRUSTED_SERVICE_HOSTS );
        $trusted_hosts = array_map(
            static fn ( mixed $item ): string => strtolower( trim( (string) $item, " \t\n\r\0\x0B." ) ),
            $trusted_hosts
        );

        return in_array( $host, $trusted_hosts, true );
    }

    private static function allow_insecure_development_url( string $url, string $context ): bool
    {
        $allowed = defined( 'SENTIENT_FORMS_ALLOW_INSECURE_OUTBOUND_URLS' )
            && true === constant( 'SENTIENT_FORMS_ALLOW_INSECURE_OUTBOUND_URLS' );

        return (bool) apply_filters( 'sentient_forms_allow_insecure_outbound_url', $allowed, $url, $context );
    }

    private static function should_use_development_http_transport( string $url, string $context ): bool
    {
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) )
        {
            return false;
        }

        $host = strtolower( trim( (string) $parts['host'], " \t\n\r\0\x0B." ) );

        return self::is_local_or_private_host( $host )
            && self::allow_insecure_development_url( $url, $context );
    }

    private static function is_local_or_private_host( string $host ): bool
    {
        if ( in_array( $host, [ 'localhost', 'localhost.localdomain' ], true ) )
        {
            return true;
        }

        if ( str_ends_with( $host, '.localhost' ) || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) )
        {
            return true;
        }

        $ip = trim( $host, '[]' );
        if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) )
        {
            return false;
        }

        return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
    }

    private static function error( string $code, string $message ): WP_Error
    {
        return new WP_Error(
            $code,
            $message,
            [
                'status' => 400,
            ]
        );
    }
}
