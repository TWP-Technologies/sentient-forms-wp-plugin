<?php
/**
 * Form Source lifecycle normalization.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Shared lifecycle IDs for Form Source adapters.
 */
final class Sentient_Forms_Form_Source_Lifecycles
{
    public const VALIDATION = 'validation';
    public const AFTER_SUBMISSION = 'after_submission';
    public const REAL_TIME = 'real_time';

    /**
     * @return array<int, string>
     */
    public static function canonical_ids(): array
    {
        return [
            self::VALIDATION,
            self::AFTER_SUBMISSION,
            self::REAL_TIME,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function legacy_aliases(): array
    {
        return [
            'gform_validation'         => self::VALIDATION,
            'gform_after_submission'   => self::AFTER_SUBMISSION,
            'wpcf7_mail_sent'          => self::AFTER_SUBMISSION,
            'wpforms_process_complete' => self::AFTER_SUBMISSION,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function accepted_input_ids(): array
    {
        return array_values(
            array_unique(
                array_merge(
                    self::canonical_ids(),
                    array_keys( self::legacy_aliases() )
                )
            )
        );
    }

    public static function normalize_id( mixed $value ): ?string
    {
        if ( ! is_scalar( $value ) )
        {
            return null;
        }

        $key = sanitize_key( (string) $value );
        if ( in_array( $key, self::canonical_ids(), true ) )
        {
            return $key;
        }

        return self::legacy_aliases()[ $key ] ?? null;
    }

    /**
     * @param array<int, mixed> $values
     *
     * @return array<int, string>
     */
    public static function normalize_many( array $values ): array
    {
        $normalized = [];

        foreach ( $values as $value )
        {
            $lifecycle_id = self::normalize_id( $value );
            if ( null !== $lifecycle_id )
            {
                $normalized[] = $lifecycle_id;
            }
        }

        return array_values( array_unique( $normalized ) );
    }

    public static function is_accepted_input( mixed $value ): bool
    {
        return null !== self::normalize_id( $value );
    }

    /**
     * Normalize array keys that represent lifecycle IDs.
     *
     * @param array<string|int, mixed> $values
     *
     * @return array<string, mixed>
     */
    public static function normalize_keyed_array( array $values ): array
    {
        $normalized = [];

        foreach ( $values as $key => $value )
        {
            $lifecycle_id = self::normalize_id( $key );
            if ( null !== $lifecycle_id )
            {
                $normalized[ $lifecycle_id ] = $value;
            }
        }

        return $normalized;
    }
}
