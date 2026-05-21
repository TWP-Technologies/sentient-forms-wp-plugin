<?php
/**
 * Dev-only helper to allow WP HTTP requests to the internal CPS host.
 *
 * WordPress blocks private-network IPs in wp_http_validate_url(); this filter
 * whitelists the docker bridge host(s) we use in the local compose stack.
 */

$env = function_exists( 'wp_get_environment_type' )
    ? wp_get_environment_type()
    : ( getenv( 'WP_ENVIRONMENT_TYPE' ) ?: null );

error_log( '[dev-allow-internal-cps] env=' . var_export( $env, true ) );

if ( 'development' !== $env ) {
    return;
}

add_filter(
    'http_request_host_is_external',
    function ( $is_external, $host, $url ) {
        error_log( sprintf( '[dev-allow-internal-cps] filter host=%s url=%s is_external=%s', $host, $url, var_export( $is_external, true ) ) );
        $allow = array(
            '172.18.0.5',  // cps-api container IP in compose
            'cps-api',     // cps-api container hostname
        );

        if ( in_array( $host, $allow, true ) ) {
            return true;
        }

        return $is_external;
    },
    10,
    3
);

add_filter(
    'sentient_forms_allow_insecure_outbound_url',
    function ( $allowed, $url, $context ) {
        if ( 'service' !== $context ) {
            return $allowed;
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        $allow = array(
            '172.18.0.5',  // cps-api container IP in compose
            'cps-api',     // cps-api container hostname
            'localhost',   // browser/manual localhost smoke paths
        );

        return in_array( $host, $allow, true ) ? true : $allowed;
    },
    10,
    3
);
