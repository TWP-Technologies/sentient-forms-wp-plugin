<?php
/**
 * Normalizes source-neutral native effect outcomes.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Native_Effect_Outcomes
{
    private const STATUS_PRIORITY = [
        'applied'     => 1,
        'skipped'     => 2,
        'failed'      => 3,
        'unsupported' => 4,
    ];

    /**
     * Convert the execution-service effect summary to the canonical outcome shape.
     *
     * @param array<string, mixed> $effects
     *
     * @return array<int, array{effect: string, status: string, reason: string}>
     */
    public static function from_execution_effects( array $effects ): array
    {
        $outcomes = [];
        foreach ( array_keys( self::STATUS_PRIORITY ) as $status )
        {
            $items = isset( $effects[ $status ] ) && is_array( $effects[ $status ] )
                ? $effects[ $status ]
                : [];
            foreach ( $items as $item )
            {
                $effect = is_scalar( $item )
                    ? self::normalize_effect( (string) $item )
                    : self::normalize_effect( is_array( $item ) ? (string) ( $item['effect'] ?? '' ) : '' );
                if ( '' === $effect )
                {
                    continue;
                }

                $reason = is_array( $item ) && is_scalar( $item['reason'] ?? null )
                    ? sanitize_key( (string) $item['reason'] )
                    : '';
                $outcomes[] = [
                    'effect' => $effect,
                    'status' => $status,
                    'reason' => $reason,
                ];
            }
        }

        return self::merge( $outcomes );
    }

    /**
     * Read effect outcomes from a normalized execution result.
     *
     * @param array<string, mixed> $result
     *
     * @return array<int, array{effect: string, status: string, reason: string}>
     */
    public static function from_execution_result( array $result ): array
    {
        $payload = isset( $result['result'] ) && is_array( $result['result'] )
            ? $result['result']
            : $result;
        $declared = isset( $payload['native_effect_outcomes'] ) && is_array( $payload['native_effect_outcomes'] )
            ? $payload['native_effect_outcomes']
            : ( isset( $result['native_effect_outcomes'] ) && is_array( $result['native_effect_outcomes'] )
                ? $result['native_effect_outcomes']
                : [] );
        $effects = isset( $result['effects'] ) && is_array( $result['effects'] )
            ? $result['effects']
            : ( isset( $payload['effects'] ) && is_array( $payload['effects'] ) ? $payload['effects'] : [] );

        return self::merge( self::from_execution_effects( $effects ), $declared );
    }

    /**
     * Merge outcome sets using the strictest terminal state for each effect.
     *
     * @param array<int, array<string, mixed>> ...$sets
     *
     * @return array<int, array{effect: string, status: string, reason: string}>
     */
    public static function merge( array ...$sets ): array
    {
        $merged = [];
        foreach ( $sets as $set )
        {
            foreach ( $set as $candidate )
            {
                if ( ! is_array( $candidate ) )
                {
                    continue;
                }

                $effect = self::normalize_effect( (string) ( $candidate['effect'] ?? '' ) );
                $status = sanitize_key( (string) ( $candidate['status'] ?? '' ) );
                if ( '' === $effect || ! isset( self::STATUS_PRIORITY[ $status ] ) )
                {
                    continue;
                }

                $reason = is_scalar( $candidate['reason'] ?? null )
                    ? sanitize_key( (string) $candidate['reason'] )
                    : '';
                $current = $merged[ $effect ] ?? null;
                if (
                    is_array( $current )
                    && self::STATUS_PRIORITY[ $current['status'] ] > self::STATUS_PRIORITY[ $status ]
                )
                {
                    continue;
                }

                $merged[ $effect ] = [
                    'effect' => $effect,
                    'status' => $status,
                    'reason' => $reason,
                ];
            }
        }

        ksort( $merged );

        return array_values( $merged );
    }

    private static function normalize_effect( string $effect ): string
    {
        $effect = strtolower( trim( $effect ) );

        return (string) preg_replace( '/[^a-z0-9_:-]/', '', $effect );
    }
}
