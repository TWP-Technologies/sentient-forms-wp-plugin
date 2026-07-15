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

    private const LEGACY_ELEMENTOR_FORM_SOURCE = 'elementor_forms';

    private const ELEMENTOR_FORM_SOURCE = 'elementor_pro_forms';

    private const ASYNC_METADATA_OPTION = 'sentient_forms_async_jobs';

    /**
     * Option namespaces whose keys contain the Form Source identifier.
     *
     * @var array<int, string>
     */
    private const FORM_SOURCE_OPTION_PREFIXES = [
        'sentient_forms_actions_',
        'sentient_forms_form_config_',
    ];

    /**
     * Plugin-owned tables with a form_source column and no source-based unique key.
     *
     * @var array<int, string>
     */
    private const FORM_SOURCE_TABLE_SUFFIXES = [
        'sentient_form_mappings',
        'sentient_submission_ledger',
        'sentient_execution_events',
        'sentient_lead_profiles',
        'sentient_historical_analysis_runs',
        'sentient_lead_scoring_results',
    ];

    /**
     * Migrate active configuration to canonical Form Source lifecycle IDs.
     *
     * @return array<string, int>
     */
    public static function migrate_active_configuration(): array
    {
        $summary = [
            'options_scanned'      => 0,
            'options_updated'      => 0,
            'mapping_rows_scanned' => 0,
            'mapping_rows_updated' => 0,
            'form_source_options_scanned'    => 0,
            'form_source_options_renamed'    => 0,
            'form_source_option_collisions'  => 0,
            'form_source_option_failures'    => 0,
            'form_source_rows_updated'       => 0,
            'form_source_row_collisions'     => 0,
            'async_metadata_jobs_updated'    => 0,
        ];

        self::migrate_option_backed_configuration( $summary );
        self::migrate_local_first_mapping_rows( $summary );
        self::migrate_elementor_option_keys( $summary );
        self::migrate_elementor_async_metadata( $summary );
        self::migrate_elementor_storage_rows( $summary );

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

    /**
     * Move Elementor option keys to the canonical identifier without
     * overwriting a key that has already been written canonically.
     *
     * @param array<string, int> $summary
     */
    private static function migrate_elementor_option_keys( array &$summary ): void
    {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'options' ) )
        {
            return;
        }

        foreach ( self::FORM_SOURCE_OPTION_PREFIXES as $option_prefix )
        {
            $legacy_prefix = $option_prefix . self::LEGACY_ELEMENTOR_FORM_SOURCE . '_';
            $option_keys   = $wpdb->get_col(
                $wpdb->prepare(
                    'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name ASC',
                    $wpdb->options,
                    $wpdb->esc_like( $legacy_prefix ) . '%'
                )
            );
            if ( ! is_array( $option_keys ) )
            {
                continue;
            }

            foreach ( $option_keys as $legacy_key )
            {
                if ( ! is_string( $legacy_key ) || ! str_starts_with( $legacy_key, $legacy_prefix ) )
                {
                    continue;
                }

                $summary['form_source_options_scanned']++;
                $canonical_key = $option_prefix . self::ELEMENTOR_FORM_SOURCE . '_' . substr( $legacy_key, strlen( $legacy_prefix ) );
                $canonical_exists = 1 === (int) $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT COUNT(*) FROM %i WHERE option_name = %s',
                        $wpdb->options,
                        $canonical_key
                    )
                );

                if ( $canonical_exists )
                {
                    delete_option( $legacy_key );
                    $summary['form_source_option_collisions']++;
                    continue;
                }

                $legacy_value = get_option( $legacy_key, null );
                if ( add_option( $canonical_key, $legacy_value, '', false ) )
                {
                    delete_option( $legacy_key );
                    $summary['form_source_options_renamed']++;
                    continue;
                }

                $summary['form_source_option_failures']++;
            }
        }
    }

    /**
     * Rewrite all current plugin-owned storage identities. Canonical data wins
     * the only possible source/form collision in ledger settings.
     *
     * @param array<string, int> $summary
     */
    private static function migrate_elementor_storage_rows( array &$summary ): void
    {
        global $wpdb;

        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) )
        {
            return;
        }

        self::migrate_elementor_ledger_settings_rows( $summary );

        foreach ( self::FORM_SOURCE_TABLE_SUFFIXES as $table_suffix )
        {
            $table = $wpdb->prefix . $table_suffix;
            if ( ! self::table_exists( $table ) )
            {
                continue;
            }

            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET form_source = %s WHERE form_source = %s',
                    $table,
                    self::ELEMENTOR_FORM_SOURCE,
                    self::LEGACY_ELEMENTOR_FORM_SOURCE
                )
            );
            if ( false !== $updated )
            {
                $summary['form_source_rows_updated'] += (int) $updated;
            }
        }

        $async_table = $wpdb->prefix . 'sentient_async_requests';
        if ( self::table_exists( $async_table ) )
        {
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET adapter = %s WHERE adapter = %s',
                    $async_table,
                    self::ELEMENTOR_FORM_SOURCE,
                    self::LEGACY_ELEMENTOR_FORM_SOURCE
                )
            );
            if ( false !== $updated )
            {
                $summary['form_source_rows_updated'] += (int) $updated;
            }
        }
    }

    /**
     * Rewrite durable job metadata without treating arbitrary string values as
     * Form Source identities. Action Scheduler payloads are tolerated at their
     * internal dequeue boundary because its serialized argument store is not a
     * plugin-owned migration surface.
     *
     * @param array<string, int> $summary
     */
    private static function migrate_elementor_async_metadata( array &$summary ): void
    {
        $stored = get_option( self::ASYNC_METADATA_OPTION, null );
        if ( ! is_array( $stored ) )
        {
            return;
        }

        $migrated = self::migrate_elementor_identity_fields( $stored );
        if ( $migrated === $stored )
        {
            return;
        }

        if ( update_option( self::ASYNC_METADATA_OPTION, $migrated, false ) )
        {
            $summary['async_metadata_jobs_updated']++;
            return;
        }

        $summary['form_source_option_failures']++;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private static function migrate_elementor_identity_fields( array $payload ): array
    {
        foreach ( $payload as $key => $value )
        {
            if ( is_string( $key )
                && in_array( $key, [ 'form_source', 'adapter_id' ], true )
                && is_scalar( $value )
                && self::LEGACY_ELEMENTOR_FORM_SOURCE === sanitize_key( (string) $value ) )
            {
                $payload[ $key ] = self::ELEMENTOR_FORM_SOURCE;
                continue;
            }

            if ( is_array( $value ) )
            {
                $payload[ $key ] = self::migrate_elementor_identity_fields( $value );
            }
        }

        return $payload;
    }

    /**
     * Resolve the source/form unique key in ledger settings deterministically.
     * Existing canonical rows are authoritative and remain byte-for-byte intact.
     *
     * @param array<string, int> $summary
     */
    private static function migrate_elementor_ledger_settings_rows( array &$summary ): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sentient_submission_ledger_settings';
        if ( ! self::table_exists( $table ) )
        {
            return;
        }

        $legacy_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, form_id FROM %i WHERE form_source = %s ORDER BY id ASC',
                $table,
                self::LEGACY_ELEMENTOR_FORM_SOURCE
            ),
            ARRAY_A
        );
        if ( ! is_array( $legacy_rows ) )
        {
            return;
        }

        foreach ( $legacy_rows as $legacy_row )
        {
            $legacy_id = absint( $legacy_row['id'] ?? 0 );
            $form_id   = isset( $legacy_row['form_id'] ) && is_scalar( $legacy_row['form_id'] )
                ? sanitize_text_field( (string) $legacy_row['form_id'] )
                : '';
            if ( $legacy_id <= 0 || '' === $form_id )
            {
                continue;
            }

            $canonical_id = absint(
                $wpdb->get_var(
                    $wpdb->prepare(
                        'SELECT id FROM %i WHERE form_source = %s AND form_id = %s LIMIT 1',
                        $table,
                        self::ELEMENTOR_FORM_SOURCE,
                        $form_id
                    )
                )
            );
            if ( $canonical_id > 0 )
            {
                $deleted = $wpdb->delete( $table, [ 'id' => $legacy_id ], [ '%d' ] );
                if ( false !== $deleted )
                {
                    $summary['form_source_row_collisions']++;
                }
                continue;
            }

            $updated = $wpdb->update(
                $table,
                [ 'form_source' => self::ELEMENTOR_FORM_SOURCE ],
                [ 'id' => $legacy_id ],
                [ '%s' ],
                [ '%d' ]
            );
            if ( false !== $updated )
            {
                $summary['form_source_rows_updated'] += (int) $updated;
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
