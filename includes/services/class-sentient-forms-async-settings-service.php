<?php
/**
 * Manages async retry/backoff settings for Sentient Forms.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Async_Settings_Service
{
    private const OPTION = 'sentient_forms_async_settings';

    private const DEFAULTS = [
        'max_attempts'       => 3,
        'base_delay_seconds' => 60,
        'max_delay_seconds'  => HOUR_IN_SECONDS,
        'updated_at'         => null,
        'updated_by'         => null,
    ];

    public function get_settings(): array
    {
        $stored = get_option( self::OPTION, [] );
        $settings = wp_parse_args( is_array( $stored ) ? $stored : [], self::DEFAULTS );

        return $this->normalize( $settings );
    }

    public function update_settings( array $settings, ?string $actor = null ): array
    {
        $current  = $this->get_settings();
        $payload  = array_merge( $current, $settings );
        $payload  = $this->normalize( $payload );
        $payload['updated_at'] = time();
        $payload['updated_by'] = $actor ?? $payload['updated_by'];

        update_option( self::OPTION, $payload, false );

        return $payload;
    }

    private function normalize( array $settings ): array
    {
        $max_attempts = isset( $settings['max_attempts'] ) ? max( 1, (int) $settings['max_attempts'] ) : self::DEFAULTS['max_attempts'];
        $base_delay   = isset( $settings['base_delay_seconds'] ) ? max( 5, (int) $settings['base_delay_seconds'] ) : self::DEFAULTS['base_delay_seconds'];
        $max_delay    = isset( $settings['max_delay_seconds'] ) ? max( $base_delay, (int) $settings['max_delay_seconds'] ) : self::DEFAULTS['max_delay_seconds'];

        return [
            'max_attempts'       => $max_attempts,
            'base_delay_seconds' => $base_delay,
            'max_delay_seconds'  => $max_delay,
            'updated_at'         => $settings['updated_at'] ?? null,
            'updated_by'         => $settings['updated_by'] ?? null,
        ];
    }
}
