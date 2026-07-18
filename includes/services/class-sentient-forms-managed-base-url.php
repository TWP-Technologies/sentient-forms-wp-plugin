<?php
/**
 * Managed-service base URL normalization.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Managed_Base_Url
{
    public static function normalize( string $url ): string
    {
        $url   = trim( $url );
        $parts = wp_parse_url( $url );

        if ( ! is_array( $parts ) )
        {
            throw new InvalidArgumentException( 'Managed-service base URL is invalid.' );
        }

        $scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
        $host   = (string) ( $parts['host'] ?? '' );
        if (
            ! in_array( $scheme, [ 'http', 'https' ], true )
            || '' === $host
            || isset( $parts['user'] )
            || isset( $parts['pass'] )
            || isset( $parts['query'] )
            || isset( $parts['fragment'] )
        )
        {
            throw new InvalidArgumentException( 'Managed-service base URL must be an HTTP origin without credentials, query parameters, or fragments.' );
        }

        $path = '/' . ltrim( (string) ( $parts['path'] ?? '' ), '/' );
        $path = untrailingslashit( $path );
        if ( '' === $path || '/' === $path )
        {
            $path = '/v2';
        }
        elseif ( '/v2' !== $path )
        {
            throw new InvalidArgumentException( 'Managed-service base URL must be a bare origin or use the exact /v2 path.' );
        }

        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port . $path;
    }
}
