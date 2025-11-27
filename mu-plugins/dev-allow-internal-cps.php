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
