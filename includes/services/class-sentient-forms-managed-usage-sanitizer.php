<?php
/**
 * Customer-facing managed-service usage sanitization.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Upgrade repair scans and rewrites plugin-owned local-first tables/options to remove managed-service private billing fields.
class Sentient_Forms_Managed_Usage_Sanitizer
{
    private const MANAGED_PROVIDERS = [
        'sentient_managed'                => true,
        'sentient_forms'                  => true,
        'sentient_forms_managed'          => true,
        'sentient_forms_managed_metering' => true,
        'sentient_forms_metering'         => true,
    ];

    private const PRIVATE_MANAGED_KEYS = [
        'amount_usd'                => true,
        'billed_amount_microusd'    => true,
        'total_billed_micro_usd'    => true,
        'currency'                  => true,
        'provider_cost'             => true,
        'provider_payload'          => true,
    ];

    private const EMPTY_AFTER_SCRUB_CONTAINER_KEYS = [
        'billing' => true,
        'cost'    => true,
        'details' => true,
    ];

    public static function is_managed_provider( mixed $provider ): bool
    {
        if ( ! is_scalar( $provider ) )
        {
            return false;
        }

        $provider_key = sanitize_key( (string) $provider );
        return isset( self::MANAGED_PROVIDERS[ $provider_key ] );
    }

    public static function is_managed_payload( mixed $payload ): bool
    {
        if ( ! is_array( $payload ) )
        {
            return false;
        }

        foreach ( [ 'provider', 'route', 'source', 'estimate_source' ] as $key )
        {
            if ( self::is_managed_provider( $payload[ $key ] ?? null ) )
            {
                return true;
            }
        }

        if ( isset( $payload['metering'] ) && is_array( $payload['metering'] ) )
        {
            return self::is_managed_payload( $payload['metering'] )
                || isset( $payload['metering']['debited_credits'] )
                || isset( $payload['metering']['billed_amount_microusd'] )
                || isset( $payload['metering']['total_billed_micro_usd'] );
        }

        if ( isset( $payload['usage_cost'] ) && is_array( $payload['usage_cost'] ) )
        {
            return self::is_managed_payload( $payload['usage_cost'] );
        }

        if ( isset( $payload['pricing'] ) && is_array( $payload['pricing'] ) )
        {
            return self::is_managed_payload( $payload['pricing'] )
                || 'sentient_credits' === sanitize_key( (string) ( $payload['pricing']['kind'] ?? '' ) );
        }

        return 'sentient_credits' === sanitize_key( (string) ( $payload['kind'] ?? '' ) );
    }

    public static function sanitize_for_managed_context( mixed $value ): mixed
    {
        if ( ! is_array( $value ) )
        {
            return $value;
        }

        $sanitized = [];
        foreach ( $value as $key => $child )
        {
            if ( is_string( $key ) && isset( self::PRIVATE_MANAGED_KEYS[ $key ] ) )
            {
                continue;
            }

            $child = self::sanitize_for_managed_context( $child );
            if ( is_string( $key ) && isset( self::EMPTY_AFTER_SCRUB_CONTAINER_KEYS[ $key ] ) && is_array( $child ) && [] === $child )
            {
                continue;
            }

            $sanitized[ $key ] = $child;
        }

        return $sanitized;
    }

    public static function sanitize_if_managed( mixed $value, mixed $provider = null ): mixed
    {
        if ( self::is_managed_provider( $provider ) || self::is_managed_payload( $value ) )
        {
            return self::sanitize_for_managed_context( $value );
        }

        return $value;
    }

    public static function sanitize_billing_state( array $payload ): array
    {
        if ( isset( $payload['managed_usage'] ) && is_array( $payload['managed_usage'] ) )
        {
            $payload['managed_usage'] = self::sanitize_for_managed_context( $payload['managed_usage'] );
        }

        return $payload;
    }

    public static function sanitize_event_fields( array $event ): array
    {
        $provider = $event['provider'] ?? null;
        if ( ! self::is_managed_provider( $provider ) )
        {
            return $event;
        }

        foreach ( [ 'cost_json', 'result_json', 'details', 'pricing', 'usage_cost' ] as $key )
        {
            if ( isset( $event[ $key ] ) && is_array( $event[ $key ] ) )
            {
                $event[ $key ] = self::sanitize_for_managed_context( $event[ $key ] );
            }
        }

        return $event;
    }

    public static function scrub_local_storage(): array
    {
        $summary = [
            'execution_events_scanned' => 0,
            'execution_events_updated' => 0,
            'site_context_updated'     => false,
            'legacy_log_updated'       => false,
        ];

        global $wpdb;
        $table = $wpdb->prefix . 'sentient_execution_events';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( is_string( $table_exists ) && '' !== $table_exists )
        {
            $like_micro    = '%' . $wpdb->esc_like( 'microusd' ) . '%';
            $like_currency = '%' . $wpdb->esc_like( '"currency"' ) . '%';
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, cost_json, result_json FROM %i
                    WHERE provider IN ('sentient_managed', 'sentient_forms', 'sentient_forms_managed')
                      AND (cost_json LIKE %s OR cost_json LIKE %s OR result_json LIKE %s OR result_json LIKE %s)",
                    $table,
                    $like_micro,
                    $like_currency,
                    $like_micro,
                    $like_currency
                ),
                ARRAY_A
            ) ?: [];

            foreach ( $rows as $row )
            {
                $summary['execution_events_scanned']++;
                $cost_result   = self::sanitize_json_string( $row['cost_json'] ?? null );
                $result_result = self::sanitize_json_string( $row['result_json'] ?? null );
                if ( ! $cost_result['changed'] && ! $result_result['changed'] )
                {
                    continue;
                }

                $updated = $wpdb->update(
                    $table,
                    [
                        'cost_json'   => $cost_result['json'],
                        'result_json' => $result_result['json'],
                    ],
                    [ 'id' => absint( $row['id'] ?? 0 ) ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                if ( false !== $updated )
                {
                    $summary['execution_events_updated']++;
                }
            }
        }

        $context = get_option( 'sentient_forms_site_context', null );
        if ( is_array( $context ) && isset( $context['metadata'] ) && is_array( $context['metadata'] ) && self::is_managed_provider( $context['metadata']['route'] ?? null ) )
        {
            $sanitized = self::sanitize_for_managed_context( $context );
            if ( $sanitized !== $context )
            {
                update_option( 'sentient_forms_site_context', $sanitized, false );
                $summary['site_context_updated'] = true;
            }
        }

        $legacy_log = get_option( 'sentient_forms_action_log', null );
        if ( is_array( $legacy_log ) )
        {
            $sanitized_log = [];
            foreach ( $legacy_log as $entry )
            {
                $sanitized_log[] = is_array( $entry ) && self::is_managed_payload( $entry )
                    ? self::sanitize_for_managed_context( $entry )
                    : $entry;
            }

            if ( $sanitized_log !== $legacy_log )
            {
                update_option( 'sentient_forms_action_log', $sanitized_log, false );
                $summary['legacy_log_updated'] = true;
            }
        }

        return $summary;
    }

    /**
     * @return array{json: string|null, changed: bool}
     */
    private static function sanitize_json_string( mixed $json ): array
    {
        if ( ! is_string( $json ) || '' === trim( $json ) )
        {
            return [
                'json'    => is_string( $json ) ? $json : null,
                'changed' => false,
            ];
        }

        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) )
        {
            return [
                'json'    => $json,
                'changed' => false,
            ];
        }

        $sanitized = self::sanitize_for_managed_context( $decoded );
        return [
            'json'    => (string) wp_json_encode( $sanitized ),
            'changed' => $sanitized !== $decoded,
        ];
    }
}
