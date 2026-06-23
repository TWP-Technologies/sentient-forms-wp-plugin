<?php
/**
 * Active Form Source configuration migration.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Active configuration migration discovers plugin-owned option names directly during installer/service repair. SQL is prepared and table names are WordPress-provided.
final class Sentient_Forms_Form_Source_Config_Migrator
{
    private const OPTION_PREFIX = 'sentient_forms_actions_';

    /**
     * Migrate active configuration to canonical Form Source lifecycle IDs.
     *
     * @return array{options_scanned: int, options_updated: int, mapping_rows_scanned: int, mapping_rows_updated: int}
     */
    public static function migrate_active_configuration(): array
    {
        $summary = [
            'options_scanned'      => 0,
            'options_updated'      => 0,
            'mapping_rows_scanned' => 0,
            'mapping_rows_updated' => 0,
        ];

        self::migrate_option_backed_configuration( $summary );
        self::migrate_local_first_mapping_rows( $summary );

        return $summary;
    }

    /**
     * @param array{options_scanned: int, options_updated: int, mapping_rows_scanned: int, mapping_rows_updated: int} $summary
     */
    private static function migrate_option_backed_configuration( array &$summary ): void
    {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'options' ) )
        {
            return;
        }

        $like = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
        $option_keys = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s',
                $wpdb->options,
                $like
            )
        );
        if ( ! is_array( $option_keys ) )
        {
            return;
        }

        foreach ( $option_keys as $option_key )
        {
            if ( ! is_string( $option_key ) || '' === $option_key )
            {
                continue;
            }

            $stored = get_option( $option_key, null );
            if ( ! is_array( $stored ) )
            {
                continue;
            }

            $summary['options_scanned']++;
            $migrated = self::migrate_option_payload( $stored );
            if ( $migrated === $stored )
            {
                continue;
            }

            update_option( $option_key, $migrated, false );
            $summary['options_updated']++;
        }
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    private static function migrate_option_payload( array $stored ): array
    {
        if ( isset( $stored['actions'] ) && is_array( $stored['actions'] ) )
        {
            $stored['actions'] = self::migrate_mapping_collection( $stored['actions'] );
            return $stored;
        }

        return self::migrate_mapping_collection( $stored );
    }

    /**
     * @param array<string, mixed> $collection
     *
     * @return array<string, mixed>
     */
    private static function migrate_mapping_collection( array $collection ): array
    {
        foreach ( $collection as $key => $value )
        {
            if ( ! is_array( $value ) || ! self::looks_like_mapping( $value ) )
            {
                continue;
            }

            $collection[ $key ] = self::migrate_mapping_payload( $value );
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private static function looks_like_mapping( array $mapping ): bool
    {
        return isset( $mapping['central_action_id'] )
            || isset( $mapping['local_mapping_id'] )
            || isset( $mapping['trigger_hooks'] )
            || isset( $mapping['settings'] );
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return array<string, mixed>
     */
    private static function migrate_mapping_payload( array $mapping ): array
    {
        if ( isset( $mapping['trigger_hooks'] ) && is_array( $mapping['trigger_hooks'] ) )
        {
            $mapping['trigger_hooks'] = Sentient_Forms_Form_Source_Lifecycles::normalize_many( $mapping['trigger_hooks'] );
        }

        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) )
        {
            $mapping['settings'] = self::migrate_settings_payload( $mapping['settings'] );
        }

        return $mapping;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private static function migrate_settings_payload( array $settings ): array
    {
        if ( isset( $settings['trigger_hooks'] ) && is_array( $settings['trigger_hooks'] ) )
        {
            $settings['trigger_hooks'] = Sentient_Forms_Form_Source_Lifecycles::normalize_many( $settings['trigger_hooks'] );
        }

        if ( isset( $settings['trigger_sources'] ) && is_array( $settings['trigger_sources'] ) )
        {
            $settings['trigger_sources'] = Sentient_Forms_Form_Source_Lifecycles::normalize_keyed_array(
                $settings['trigger_sources']
            );
        }

        return $settings;
    }

    /**
     * @param array{options_scanned: int, options_updated: int, mapping_rows_scanned: int, mapping_rows_updated: int} $summary
     */
    private static function migrate_local_first_mapping_rows( array &$summary ): void
    {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) )
        {
            return;
        }

        $table = $wpdb->prefix . 'sentient_form_mappings';
        if ( ! self::table_exists( $table ) )
        {
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, hook, settings_json FROM %i ORDER BY id ASC',
                $table
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) )
        {
            return;
        }

        foreach ( $rows as $row )
        {
            $id = absint( $row['id'] ?? 0 );
            if ( $id <= 0 )
            {
                continue;
            }

            $summary['mapping_rows_scanned']++;

            $fields  = [];
            $formats = [];

            $current_hook = isset( $row['hook'] ) && is_scalar( $row['hook'] )
                ? sanitize_key( (string) $row['hook'] )
                : '';
            $canonical_hook = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $current_hook );
            if ( null !== $canonical_hook && $canonical_hook !== $current_hook )
            {
                $fields['hook'] = $canonical_hook;
                $formats[]      = '%s';
            }

            $raw_settings = isset( $row['settings_json'] ) && is_string( $row['settings_json'] )
                ? $row['settings_json']
                : '';
            $settings = '' === $raw_settings ? null : json_decode( $raw_settings, true );
            if ( is_array( $settings ) )
            {
                $migrated_settings = self::migrate_settings_payload( $settings );
                if ( $migrated_settings !== $settings )
                {
                    $encoded = wp_json_encode( $migrated_settings );
                    if ( false !== $encoded )
                    {
                        $fields['settings_json'] = $encoded;
                        $formats[]               = '%s';
                    }
                }
            }

            if ( [] === $fields )
            {
                continue;
            }

            $fields['updated_at'] = current_time( 'mysql', true );
            $formats[]            = '%s';

            $updated = $wpdb->update(
                $table,
                $fields,
                [ 'id' => $id ],
                $formats,
                [ '%d' ]
            );

            if ( false !== $updated )
            {
                $summary['mapping_rows_updated']++;
            }
        }
    }

    private static function table_exists( string $table_name ): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        $found    = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table_name ) );
        $wpdb->suppress_errors( $suppress );

        return ! empty( $found );
    }
}
