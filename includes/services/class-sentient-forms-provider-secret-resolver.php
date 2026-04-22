<?php
/**
 * Resolve locally managed provider secrets.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Provider_Secret_Resolver
{
    public static function resolve_constant_secret( string $constant_name ): string | WP_Error
    {
        $constant_name = trim( $constant_name );
        if ( '' === $constant_name || ! preg_match( '/^[A-Z][A-Z0-9_]+$/', $constant_name ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_secret_constant',
                __( 'Provider credential constant name is invalid.', 'sentient-forms' )
            );
        }

        if ( defined( $constant_name ) )
        {
            $value = constant( $constant_name );
            return is_scalar( $value ) && '' !== trim( (string) $value )
                ? trim( (string) $value )
                : new WP_Error(
                    'sentient_forms_empty_secret_constant',
                    __( 'Provider credential constant is empty.', 'sentient-forms' )
                );
        }

        $env = getenv( $constant_name );
        if ( is_string( $env ) && '' !== trim( $env ) )
        {
            return trim( $env );
        }

        return new WP_Error(
            'sentient_forms_secret_constant_not_found',
            __( 'Provider credential constant could not be resolved.', 'sentient-forms' )
        );
    }

    public static function is_constant_secret_configured( string $constant_name ): bool
    {
        return ! is_wp_error( self::resolve_constant_secret( $constant_name ) );
    }
}
