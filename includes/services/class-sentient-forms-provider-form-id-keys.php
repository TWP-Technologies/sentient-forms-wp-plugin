<?php
/**
 * Provider-native form ID helpers for option-backed storage.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Normalizes provider form IDs and maps them to collision-free option suffixes.
 */
final class Sentient_Forms_Provider_Form_Id_Keys
{
    private const MAX_FORM_ID_LENGTH = 100;
    private const ENCODED_SUFFIX_PREFIX = 'sfid_';
    private const FORM_ID_PATTERN = '/^[A-Za-z0-9._:%-]+$/';

    public static function normalize( mixed $value ): string
    {
        if ( ! is_scalar( $value ) )
        {
            return '';
        }

        return sanitize_text_field( rawurldecode( trim( (string) $value ) ) );
    }

    public static function is_valid( mixed $value ): bool
    {
        $form_id = self::normalize( $value );
        if ( '' === $form_id || strlen( $form_id ) > self::MAX_FORM_ID_LENGTH )
        {
            return false;
        }

        if ( ctype_digit( $form_id ) )
        {
            return absint( $form_id ) > 0;
        }

        return 1 === preg_match( self::FORM_ID_PATTERN, $form_id );
    }

    public static function option_suffix( mixed $form_id ): string
    {
        $form_id = self::normalize( $form_id );
        if ( '' === $form_id )
        {
            return '0';
        }

        if ( ctype_digit( $form_id ) )
        {
            $numeric_id = absint( $form_id );

            return $numeric_id > 0 ? (string) $numeric_id : '0';
        }

        return self::ENCODED_SUFFIX_PREFIX . self::base64url_encode( $form_id );
    }

    public static function decode_option_suffix( string $form_source, string $suffix ): string
    {
        $suffix = trim( $suffix, '_' );
        if ( '' === $suffix )
        {
            return '';
        }

        if ( str_starts_with( $suffix, self::ENCODED_SUFFIX_PREFIX ) )
        {
            $decoded = self::base64url_decode( substr( $suffix, strlen( self::ENCODED_SUFFIX_PREFIX ) ) );

            return null !== $decoded ? self::normalize( $decoded ) : '';
        }

        return self::decode_legacy_option_suffix( $form_source, $suffix );
    }

    /**
     * @return string[]
     */
    public static function legacy_option_suffixes( string $form_source, mixed $form_id ): array
    {
        $form_id = self::normalize( $form_id );
        if ( '' === $form_id )
        {
            return [];
        }

        $suffixes = [];
        $lossy    = preg_replace( '/[^A-Za-z0-9_-]+/', '_', $form_id );
        $lossy    = is_string( $lossy ) ? trim( $lossy, '_' ) : '';
        if ( '' !== $lossy )
        {
            $suffixes[] = $lossy;
        }

        if (
            self::elementor_forms_slug() === sanitize_key( $form_source )
            && 1 === preg_match( '/^([1-9][0-9]*):([A-Za-z0-9_-]+)$/', $form_id, $matches )
        )
        {
            $suffixes[] = $matches[1] . '_' . $matches[2];
        }

        $current = self::option_suffix( $form_id );

        return array_values(
            array_filter(
                array_unique( $suffixes ),
                static fn ( string $suffix ): bool => '' !== $suffix && $suffix !== $current
            )
        );
    }

    private static function decode_legacy_option_suffix( string $form_source, string $suffix ): string
    {
        if (
            self::elementor_forms_slug() === sanitize_key( $form_source )
            && 1 === preg_match( '/^([1-9][0-9]*)_(.+)$/', $suffix, $matches )
        )
        {
            return sanitize_text_field( $matches[1] . ':' . $matches[2] );
        }

        return sanitize_text_field( $suffix );
    }

    private static function base64url_encode( string $value ): string
    {
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }

    private static function base64url_decode( string $value ): ?string
    {
        if ( '' === $value || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $value ) )
        {
            return null;
        }

        $padded = strtr( $value, '-_', '+/' );
        $padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );
        $decoded = base64_decode( $padded, true );
        if ( ! is_string( $decoded ) )
        {
            return null;
        }

        return self::base64url_encode( $decoded ) === $value ? $decoded : null;
    }

    private static function elementor_forms_slug(): string
    {
        return class_exists( 'Sentient_Forms_Form_Sources' )
            ? Sentient_Forms_Form_Sources::ELEMENTOR_FORMS
            : 'elementor_forms';
    }
}
