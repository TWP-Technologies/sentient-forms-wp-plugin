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
    private const COMPLETION_OPTION = 'sentient_forms_managed_usage_sanitizer_version';
    private const VERSION = '1';

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

    public static function scrub_local_storage(): array | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            static fn(): array | WP_Error => self::scrub_local_storage_locked()
        );
    }

    /**
     * Run the historical storage scrub once for the current sanitizer version.
     *
     * @return array<string, int|bool>|WP_Error
     */
    public static function maybe_scrub_local_storage(): array | WP_Error
    {
        if ( self::is_complete() )
        {
            return [ 'skipped' => true ];
        }

        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            static function (): array | WP_Error {
                if ( self::is_complete() )
                {
                    return [ 'skipped' => true ];
                }

                $summary = self::scrub_local_storage_locked();
                if ( is_wp_error( $summary ) )
                {
                    return $summary;
                }

                $checkpoint = self::update_option_verified(
                    self::COMPLETION_OPTION,
                    self::VERSION,
                    'sentient_forms_managed_usage_sanitizer_checkpoint_failed'
                );
                if ( is_wp_error( $checkpoint ) )
                {
                    return $checkpoint;
                }

                $summary['skipped'] = false;
                return $summary;
            }
        );
    }

    /** Scrub plugin-owned storage after the shared local-state fence is held. */
    private static function scrub_local_storage_locked(): array | WP_Error
    {
        $summary = [
            'execution_events_scanned' => 0,
            'execution_events_updated' => 0,
            'site_context_updated'     => false,
            'legacy_log_updated'       => false,
        ];

        $events = self::scrub_execution_events_locked();
        if ( is_wp_error( $events ) )
        {
            return $events;
        }
        $summary['execution_events_scanned'] = $events['scanned'];
        $summary['execution_events_updated'] = $events['updated'];

        $site_context = self::scrub_site_context_locked();
        if ( is_wp_error( $site_context ) )
        {
            return $site_context;
        }
        $summary['site_context_updated'] = $site_context;

        $legacy_log = self::scrub_legacy_log_locked();
        if ( is_wp_error( $legacy_log ) )
        {
            return $legacy_log;
        }
        $summary['legacy_log_updated'] = $legacy_log;

        return $summary;
    }

    /** @return array{scanned:int,updated:int}|WP_Error */
    private static function scrub_execution_events_locked(): array | WP_Error
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sentient_execution_events';
        $wpdb->last_error = '';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! is_string( $table_exists ) || '' === $table_exists )
        {
            if ( '' !== (string) $wpdb->last_error )
            {
                return self::storage_error( 'sentient_forms_managed_usage_sanitizer_table_read_failed' );
            }

            return [ 'scanned' => 0, 'updated' => 0 ];
        }

        $providers             = array_keys( self::MANAGED_PROVIDERS );
        $provider_placeholders = implode( ', ', array_fill( 0, count( $providers ), '%s' ) );
        $candidate_predicates  = [];
        $candidate_values      = [];
        foreach ( array_keys( self::PRIVATE_MANAGED_KEYS ) as $private_key )
        {
            $candidate_predicates[] = '(cost_json LIKE %s OR result_json LIKE %s)';
            $pattern                = '%' . $wpdb->esc_like( '"' . $private_key . '"' ) . '%';
            $candidate_values[]     = $pattern;
            $candidate_values[]     = $pattern;
        }

        $query = "SELECT id, cost_json, result_json FROM %i WHERE provider IN ($provider_placeholders) AND (" . implode( ' OR ', $candidate_predicates ) . ')';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only internally generated placeholders are interpolated; identifiers and values remain bound through wpdb::prepare().
        $prepared_query = $wpdb->prepare( $query, $table, ...$providers, ...$candidate_values );
        $wpdb->last_error = '';
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared immediately above from fixed predicates and bound values.
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above from fixed predicates and bound values.
            $prepared_query,
            ARRAY_A
        );
        if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) )
        {
            return self::storage_error( 'sentient_forms_managed_usage_sanitizer_event_read_failed' );
        }

        $updated_count = 0;
        foreach ( $rows as $row )
        {
            $cost_result = self::sanitize_json_string( $row['cost_json'] ?? null );
            if ( is_wp_error( $cost_result ) )
            {
                return $cost_result;
            }
            $result_result = self::sanitize_json_string( $row['result_json'] ?? null );
            if ( is_wp_error( $result_result ) )
            {
                return $result_result;
            }
            if ( ! $cost_result['changed'] && ! $result_result['changed'] )
            {
                continue;
            }

            $row_id = absint( $row['id'] ?? 0 );
            $wpdb->last_error = '';
            $updated = $wpdb->update(
                $table,
                [
                    'cost_json'   => $cost_result['json'],
                    'result_json' => $result_result['json'],
                ],
                [ 'id' => $row_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );
            if ( false === $updated )
            {
                return self::storage_error( 'sentient_forms_managed_usage_sanitizer_event_update_failed' );
            }
            if ( 0 === $updated )
            {
                $persisted = $wpdb->get_row(
                    $wpdb->prepare( 'SELECT cost_json, result_json FROM %i WHERE id = %d', $table, $row_id ),
                    ARRAY_A
                );
                if (
                    ! is_array( $persisted )
                    || $cost_result['json'] !== ( $persisted['cost_json'] ?? null )
                    || $result_result['json'] !== ( $persisted['result_json'] ?? null )
                )
                {
                    return self::storage_error( 'sentient_forms_managed_usage_sanitizer_event_update_failed' );
                }
            }

            ++$updated_count;
        }

        return [ 'scanned' => count( $rows ), 'updated' => $updated_count ];
    }

    private static function scrub_site_context_locked(): bool | WP_Error
    {
        $context = get_option( 'sentient_forms_site_context', null );
        if ( ! is_array( $context ) || ! isset( $context['metadata'] ) || ! is_array( $context['metadata'] ) || ! self::is_managed_provider( $context['metadata']['route'] ?? null ) )
        {
            return false;
        }

        $sanitized = self::sanitize_for_managed_context( $context );
        if ( $sanitized === $context )
        {
            return false;
        }

        $updated = self::update_option_verified(
            'sentient_forms_site_context',
            $sanitized,
            'sentient_forms_managed_usage_sanitizer_site_context_update_failed'
        );
        return is_wp_error( $updated ) ? $updated : true;
    }

    private static function scrub_legacy_log_locked(): bool | WP_Error
    {
        $legacy_log = get_option( 'sentient_forms_action_log', null );
        if ( ! is_array( $legacy_log ) )
        {
            return false;
        }

        $sanitized_log = [];
        foreach ( $legacy_log as $entry )
        {
            $sanitized_log[] = is_array( $entry ) && self::is_managed_payload( $entry )
                ? self::sanitize_for_managed_context( $entry )
                : $entry;
        }
        if ( $sanitized_log === $legacy_log )
        {
            return false;
        }

        $updated = self::update_option_verified(
            'sentient_forms_action_log',
            $sanitized_log,
            'sentient_forms_managed_usage_sanitizer_legacy_log_update_failed'
        );
        return is_wp_error( $updated ) ? $updated : true;
    }

    private static function update_option_verified( string $option_name, mixed $value, string $error_code ): true | WP_Error
    {
        if ( update_option( $option_name, $value, false ) || $value === get_option( $option_name, null ) )
        {
            return true;
        }

        return new WP_Error(
            $error_code,
            __( 'Managed usage storage cleanup could not be durably persisted.', 'sentient-forms' )
        );
    }

    private static function is_complete(): bool
    {
        return self::VERSION === (string) get_option( self::COMPLETION_OPTION, '' );
    }

    private static function storage_error( string $error_code ): WP_Error
    {
        return new WP_Error(
            $error_code,
            __( 'Managed usage storage cleanup could not be completed safely.', 'sentient-forms' )
        );
    }

    /**
     * @return array{json: string|null, changed: bool}|WP_Error
     */
    private static function sanitize_json_string( mixed $json ): array | WP_Error
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
            foreach ( array_keys( self::PRIVATE_MANAGED_KEYS ) as $private_key )
            {
                if ( str_contains( $json, '"' . $private_key . '"' ) )
                {
                    return self::storage_error( 'sentient_forms_managed_usage_sanitizer_invalid_json' );
                }
            }

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
