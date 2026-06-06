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
    /**
     * Only provider-owned server secret names may be resolved.
     *
     * Arbitrary constants such as AUTH_KEY or DB_PASSWORD are not provider
     * credentials and must never be read or sent to an external validation API.
     */
    private const ALLOWED_SECRET_PREFIXES = [
        'SENTIENT_FORMS_OPENROUTER_',
    ];

    /**
     * WordPress-owned constants that must never be used as provider secrets.
     */
    private const DISALLOWED_SECRET_NAMES = [
        'AUTH_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_KEY',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_KEY',
        'LOGGED_IN_SALT',
        'NONCE_KEY',
        'NONCE_SALT',
        'SECRET_KEY',
        'SECRET_SALT',
        'DB_CHARSET',
        'DB_COLLATE',
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'COOKIEHASH',
        'USER_COOKIE',
        'PASS_COOKIE',
        'AUTH_COOKIE',
        'SECURE_AUTH_COOKIE',
        'LOGGED_IN_COOKIE',
        'TEST_COOKIE',
        'COOKIE_DOMAIN',
        'COOKIEPATH',
        'SITECOOKIEPATH',
        'ADMIN_COOKIE_PATH',
        'PLUGINS_COOKIE_PATH',
        'WP_HOME',
        'WP_SITEURL',
    ];

    /**
     * Resolve a provider-owned secret from a constant or matching environment variable.
     */
    public static function resolve_constant_secret( string $constant_name ): string | WP_Error
    {
        $constant_name = self::normalize_constant_name( $constant_name );
        $validation    = self::validate_constant_name( $constant_name );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
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

    /**
     * Validate that a server secret name belongs to Sentient Forms provider configuration.
     */
    public static function validate_constant_name( string $constant_name ): true | WP_Error
    {
        $constant_name = self::normalize_constant_name( $constant_name );
        if ( ! self::has_valid_constant_name_format( $constant_name ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_secret_constant',
                __( 'Provider credential constant name is invalid.', 'sentient-forms' )
            );
        }

        if ( in_array( $constant_name, self::DISALLOWED_SECRET_NAMES, true ) )
        {
            return new WP_Error(
                'sentient_forms_disallowed_secret_constant',
                __( 'WordPress security keys, salts, cookies, site URLs, and database credentials cannot be used as provider credentials.', 'sentient-forms' )
            );
        }

        foreach ( self::ALLOWED_SECRET_PREFIXES as $prefix )
        {
            if ( str_starts_with( $constant_name, $prefix ) )
            {
                return true;
            }
        }

        return new WP_Error(
            'sentient_forms_disallowed_secret_constant',
            sprintf(
                /* translators: %s: comma-separated list of required constant name prefixes. */
                __( 'Provider credential constant names must start with one of the following prefixes: %s.', 'sentient-forms' ),
                implode( ', ', self::ALLOWED_SECRET_PREFIXES )
            )
        );
    }

    /**
     * Check the generic constant-name syntax before provider ownership policy.
     */
    public static function has_valid_constant_name_format( string $constant_name ): bool
    {
        $constant_name = self::normalize_constant_name( $constant_name );

        return '' !== $constant_name && 1 === preg_match( '/^[A-Z][A-Z0-9_]+$/', $constant_name );
    }

    /**
     * Check whether a provider-owned server secret can currently be resolved.
     */
    public static function is_constant_secret_configured( string $constant_name ): bool
    {
        return ! is_wp_error( self::resolve_constant_secret( $constant_name ) );
    }

    /**
     * Normalize server secret names before validation and lookup.
     */
    private static function normalize_constant_name( string $constant_name ): string
    {
        return strtoupper( trim( $constant_name ) );
    }
}
