<?php
/**
 * Handles plugin installation/upgrade tasks (DB tables, options).
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Installer
{
    private const OPTION_DB_VERSION = 'sentient_forms_db_version';
    private const OPTION_SETTINGS = 'sentient_forms_settings';
    private const OPTION_ACTION_RESULTS_RETIREMENT_VERSION = 'sentient_forms_action_results_retirement_version';
    private const ACTION_RESULTS_RETIREMENT_VERSION = '2026.07.18.v1';
    private const ACTION_RESULTS_RETIREMENT_MAX_ATTEMPTS = 5;
    private const OPTION_NATIVE_CORRELATION_CURSOR = 'sentient_forms_native_correlation_cursor';
    private const OPTION_NATIVE_CORRELATION_BACKFILL_VERSION = 'sentient_forms_native_correlation_backfill_version';
    private const NATIVE_CORRELATION_BACKFILL_VERSION = 'v1';
    private const OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION = 'sentient_forms_submission_ledger_retention_backfill_version';
    private const OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_SNAPSHOT = 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1';
    private const OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR = 'sentient_forms_submission_ledger_retention_backfill_cursor_v1';
    private const SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION = '2026.07.10.v1';
    private const SUBMISSION_LEDGER_RETENTION_BACKFILL_BATCH_SIZE = 500;
    private const SUBMISSION_LEDGER_RETENTION_BACKFILL_MAX_BATCHES = 4;

    public static function activate( bool $network_wide = false ): void
    {
        if ( $network_wide && is_multisite() )
        {
            self::run_for_each_site( [ self::class, 'activate_current_site' ] );
            return;
        }

        self::activate_current_site();
    }

    public static function deactivate( bool $network_wide = false ): void
    {
        if ( $network_wide && is_multisite() )
        {
            self::run_for_each_site( [ self::class, 'deactivate_current_site' ] );
            return;
        }

        self::deactivate_current_site();
    }

    public static function uninstall(): void
    {
        if ( is_multisite() )
        {
            $site_ids = function_exists( 'get_sites' )
                ? get_sites(
                    [
                        'fields' => 'ids',
                        'number' => 0,
                    ]
                )
                : [ get_current_blog_id() ];

            foreach ( $site_ids as $site_id )
            {
                self::run_for_site(
                    (int) $site_id,
                    static function (): void {
                        self::uninstall_current_site();
                    }
                );
            }
            return;
        }

        self::uninstall_current_site();
    }

    public static function initialize_new_site( WP_Site $site ): void
    {
        self::run_for_site( (int) $site->blog_id, [ self::class, 'activate_current_site' ] );
    }

    public static function is_network_active(): bool
    {
        if ( ! is_multisite() || ! defined( 'SENTIENT_FORMS_PLUGIN_FILE' ) )
        {
            return false;
        }

        $plugin_basename = plugin_basename( SENTIENT_FORMS_PLUGIN_FILE );
        $network_plugins = (array) get_site_option( 'active_sitewide_plugins', [] );

        if ( isset( $network_plugins[ $plugin_basename ] ) )
        {
            return true;
        }

        if ( ! function_exists( 'is_plugin_active_for_network' ) )
        {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active_for_network( $plugin_basename );
    }

    public static function maybe_upgrade( bool $repair_missing_tables = false ): void
    {
        $current = get_option( self::OPTION_DB_VERSION, '' );
        $tables_ready = self::local_first_tables_exist();
        $needs_db_version_update = $current !== SENTIENT_FORMS_DB_VERSION;
        $should_run_form_source_config_migration = $repair_missing_tables || $current !== SENTIENT_FORMS_DB_VERSION;

        if ( $needs_db_version_update || ( $repair_missing_tables && ! $tables_ready ) )
        {
            self::create_async_requests_table();
            self::create_local_first_tables();
            $tables_ready = self::local_first_tables_exist();
        }

        if ( ! $tables_ready )
        {
            return;
        }

        $submission_ledger_retention_backfill_complete = self::backfill_submission_ledger_retention();

        $action_results_retirement_complete = self::retire_option_backed_action_results();

        $native_correlation_backfill_complete = self::native_correlation_backfill_is_complete();
        if ( $native_correlation_backfill_complete && false !== get_option( self::OPTION_NATIVE_CORRELATION_CURSOR, false ) )
        {
            delete_option( self::OPTION_NATIVE_CORRELATION_CURSOR );
        }
        $native_correlation_backfill_needed = ! $native_correlation_backfill_complete;
        $native_correlation_schema_ready    = true;
        if ( $needs_db_version_update || $native_correlation_backfill_needed )
        {
            $native_correlation_schema_ready = self::submission_ledger_native_correlation_schema_ready();
        }
        if ( $native_correlation_backfill_needed && $native_correlation_schema_ready )
        {
            self::backfill_submission_ledger_native_correlations();
        }

        self::seed_bundled_action_templates();
        $form_source_config_migration_complete = true;
        if ( $should_run_form_source_config_migration )
        {
            if ( ! class_exists( 'Sentient_Forms_Form_Source_Config_Migrator' ) )
            {
                $form_source_config_migration_complete = false;
            }
            else
            {
                $migration_summary = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
                $form_source_config_migration_complete = 1 === (int) ( $migration_summary['migration_complete'] ?? 0 );
            }
        }
        self::repair_local_first_action_integrity();
        Sentient_Forms_Managed_Usage_Sanitizer::scrub_local_storage();

        if (
            $needs_db_version_update
            && $action_results_retirement_complete
            && $submission_ledger_retention_backfill_complete
            && $form_source_config_migration_complete
            && $native_correlation_schema_ready
        )
        {
            update_option( self::OPTION_DB_VERSION, SENTIENT_FORMS_DB_VERSION );
        }
    }

    /**
     * Remove the superseded rolling result cache after canonical execution
     * events became the only runtime result history.
     */
    private static function retire_option_backed_action_results(): bool
    {
        global $wpdb;

        if ( self::ACTION_RESULTS_RETIREMENT_VERSION === get_option( self::OPTION_ACTION_RESULTS_RETIREMENT_VERSION, '' ) )
        {
            return true;
        }

        for ( $attempt = 0; $attempt < self::ACTION_RESULTS_RETIREMENT_MAX_ATTEMPTS; $attempt++ )
        {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retirement must read durable option bytes outside potentially stale option caches so the subsequent byte-exact compare-and-swap cannot overwrite concurrent settings changes.
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
                    $wpdb->options,
                    self::OPTION_SETTINGS
                ),
                ARRAY_A
            );

            if ( null === $row )
            {
                if ( '' !== $wpdb->last_error )
                {
                    return false;
                }

                self::synchronize_settings_option_caches( null );
                return self::record_action_results_retirement_complete();
            }

            $serialized_settings = (string) ( $row['option_value'] ?? '' );
            $settings            = maybe_unserialize( $serialized_settings );
            if ( ! is_array( $settings ) || ! array_key_exists( 'action_results', $settings ) )
            {
                self::synchronize_settings_option_caches( $serialized_settings );
                return self::record_action_results_retirement_complete();
            }

            unset( $settings['action_results'] );
            $retired_settings = maybe_serialize( $settings );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retirement uses an atomic byte-exact compare-and-swap on the plugin-owned settings row, then reconciles WordPress option caches immediately after success.
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
                    $wpdb->options,
                    $retired_settings,
                    self::OPTION_SETTINGS,
                    $serialized_settings
                )
            );

            if ( false === $updated )
            {
                return false;
            }

            if ( 1 === $updated )
            {
                self::synchronize_settings_option_caches( $retired_settings );
                return self::record_action_results_retirement_complete();
            }
        }

        return false;
    }

    private static function record_action_results_retirement_complete(): bool
    {
        $updated = update_option(
            self::OPTION_ACTION_RESULTS_RETIREMENT_VERSION,
            self::ACTION_RESULTS_RETIREMENT_VERSION,
            false
        );

        return $updated
            || self::ACTION_RESULTS_RETIREMENT_VERSION === get_option( self::OPTION_ACTION_RESULTS_RETIREMENT_VERSION, '' );
    }

    /**
     * Reconcile WordPress and plugin-local caches with a raw database snapshot.
     *
     * A clean readback must not evict the global alloptions cache on every
     * bounded upgrade pass. Only cache entries that disagree with the durable
     * snapshot are invalidated, while the plugin singleton is always reset.
     *
     * @param string|null $serialized_settings Durable serialized value, or null when absent.
     */
    private static function synchronize_settings_option_caches( ?string $serialized_settings ): void
    {
        $alloptions_found = false;
        $alloptions       = wp_cache_get( 'alloptions', 'options', false, $alloptions_found );
        if (
            $alloptions_found
            && is_array( $alloptions )
            && array_key_exists( self::OPTION_SETTINGS, $alloptions )
            && ( null === $serialized_settings || (string) $alloptions[ self::OPTION_SETTINGS ] !== $serialized_settings )
        )
        {
            wp_cache_delete( 'alloptions', 'options' );
        }

        $option_found = false;
        $cached_option = wp_cache_get( self::OPTION_SETTINGS, 'options', false, $option_found );
        if (
            $option_found
            && ( null === $serialized_settings || (string) $cached_option !== $serialized_settings )
        )
        {
            wp_cache_delete( self::OPTION_SETTINGS, 'options' );
        }

        if ( null !== $serialized_settings )
        {
            $notoptions = wp_cache_get( 'notoptions', 'options' );
            if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION_SETTINGS ] ) )
            {
                unset( $notoptions[ self::OPTION_SETTINGS ] );
                wp_cache_set( 'notoptions', $notoptions, 'options' );
            }
        }

        Sentient_Forms_Plugin::invalidate_options_cache();
    }

    /**
     * Give existing Submission Ledger rows an expiry without allowing an
     * upgrade to delete them less than 30 days after this migration runs.
     */
    private static function backfill_submission_ledger_retention(): bool
    {
        if ( self::SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION === get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION, '' ) )
        {
            return self::delete_submission_ledger_retention_backfill_progress();
        }

        $snapshot = self::get_or_create_submission_ledger_retention_backfill_snapshot();
        if ( null === $snapshot )
        {
            return false;
        }

        $cursor = self::get_or_create_submission_ledger_retention_backfill_cursor( $snapshot['max_id'] );
        if ( null === $cursor )
        {
            return false;
        }

        $batch_size = (int) apply_filters(
            'sentient_forms_submission_ledger_retention_backfill_batch_size',
            self::SUBMISSION_LEDGER_RETENTION_BACKFILL_BATCH_SIZE
        );
        $batch_size = max( 1, min( 1000, $batch_size ) );
        $max_batches = (int) apply_filters(
            'sentient_forms_submission_ledger_retention_backfill_max_batches',
            self::SUBMISSION_LEDGER_RETENTION_BACKFILL_MAX_BATCHES
        );
        $max_batches = max( 1, min( 20, $max_batches ) );

        for ( $batch = 0; $batch < $max_batches && $cursor < $snapshot['max_id']; $batch++ )
        {
            $ids = self::next_submission_ledger_retention_backfill_ids( $cursor, $snapshot['max_id'], $batch_size );
            if ( null === $ids )
            {
                return false;
            }

            $last_id = [] === $ids ? $snapshot['max_id'] : max( $ids );
            if ( [] !== $ids && ! self::apply_submission_ledger_retention_backfill_batch( $snapshot, $cursor, $last_id ) )
            {
                return false;
            }

            $persisted_cursor = self::persist_submission_ledger_retention_backfill_cursor( $last_id, $snapshot['max_id'] );
            if ( null === $persisted_cursor )
            {
                return false;
            }

            $cursor = $persisted_cursor;
        }

        if ( $cursor < $snapshot['max_id'] )
        {
            return false;
        }

        update_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION, self::SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION, false );
        if ( self::SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION !== get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_VERSION, '' ) )
        {
            return false;
        }

        return self::delete_submission_ledger_retention_backfill_progress();
    }

    /**
     * @return array{migration_time: string, migration_floor: string, retention_days: int, max_id: int}|null
     */
    private static function get_or_create_submission_ledger_retention_backfill_snapshot(): ?array
    {
        $stored = get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_SNAPSHOT, null );
        if ( null !== $stored )
        {
            return self::normalize_submission_ledger_retention_backfill_snapshot( $stored );
        }

        global $wpdb;

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The versioned upgrade snapshot must read the live maximum ID from the plugin-owned ledger table; caching migration boundaries would be unsafe.
        $max_id = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COALESCE(MAX(id), 0) FROM %i',
                $wpdb->prefix . 'sentient_submission_ledger'
            )
        );
        if ( '' !== $wpdb->last_error || ! is_numeric( $max_id ) )
        {
            return null;
        }

        $migration_time = (string) apply_filters(
            'sentient_forms_submission_ledger_retention_migration_time',
            current_time( 'mysql', true )
        );
        $migration_timestamp = strtotime( $migration_time . ' UTC' );
        if ( false === $migration_timestamp )
        {
            $migration_timestamp = time();
        }

        $snapshot = [
            'migration_time'  => gmdate( 'Y-m-d H:i:s', $migration_timestamp ),
            'migration_floor' => gmdate( 'Y-m-d H:i:s', $migration_timestamp + ( 30 * DAY_IN_SECONDS ) ),
            // Upgrade batches freeze the persisted administrator setting. The capture-time
            // filter may be request-specific and therefore cannot govern a resumable migration.
            'retention_days'  => Sentient_Forms_Local_Data_Governance::current_submission_ledger_retention_days(),
            'max_id'          => max( 0, (int) $max_id ),
        ];

        if ( add_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_SNAPSHOT, $snapshot, '', false ) )
        {
            return $snapshot;
        }

        return self::normalize_submission_ledger_retention_backfill_snapshot(
            get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_SNAPSHOT, null )
        );
    }

    /**
     * @return array{migration_time: string, migration_floor: string, retention_days: int, max_id: int}|null
     */
    private static function normalize_submission_ledger_retention_backfill_snapshot( mixed $snapshot ): ?array
    {
        if ( ! is_array( $snapshot ) )
        {
            return null;
        }

        $migration_time  = $snapshot['migration_time'] ?? null;
        $migration_floor = $snapshot['migration_floor'] ?? null;
        $retention_days  = $snapshot['retention_days'] ?? null;
        $max_id          = $snapshot['max_id'] ?? null;
        $is_mysql_time   = static fn ( mixed $value ): bool => is_string( $value )
            && 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value );

        if (
            ! $is_mysql_time( $migration_time )
            || ! $is_mysql_time( $migration_floor )
            || ! is_numeric( $retention_days )
            || ! in_array( (int) $retention_days, Sentient_Forms_Local_Data_Governance::submission_ledger_retention_choices(), true )
            || ! is_numeric( $max_id )
            || (int) $max_id < 0
        )
        {
            return null;
        }

        return [
            'migration_time'  => $migration_time,
            'migration_floor' => $migration_floor,
            'retention_days'  => (int) $retention_days,
            'max_id'          => (int) $max_id,
        ];
    }

    private static function get_or_create_submission_ledger_retention_backfill_cursor( int $max_id ): ?int
    {
        $cursor = get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR, null );
        if ( null === $cursor )
        {
            add_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR, 0, '', false );
            $cursor = get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR, null );
        }

        if ( ! is_numeric( $cursor ) || (int) $cursor < 0 || (int) $cursor > $max_id )
        {
            return null;
        }

        return (int) $cursor;
    }

    /**
     * @return array<int, int>|null
     */
    private static function next_submission_ledger_retention_backfill_ids( int $cursor, int $max_id, int $batch_size ): ?array
    {
        global $wpdb;

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The bounded upgrade worker must read the next live batch of plugin-owned ledger IDs and cannot reuse cached migration state.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d',
                $wpdb->prefix . 'sentient_submission_ledger',
                $cursor,
                $max_id,
                $batch_size
            )
        );
        if ( '' !== $wpdb->last_error || ! is_array( $ids ) )
        {
            return null;
        }

        return array_values( array_map( 'absint', $ids ) );
    }

    /**
     * @param array{migration_time: string, migration_floor: string, retention_days: int, max_id: int} $snapshot
     */
    private static function apply_submission_ledger_retention_backfill_batch( array $snapshot, int $cursor, int $last_id ): bool
    {
        global $wpdb;

        if ( 0 === $snapshot['retention_days'] )
        {
            // Zero means manual deletion only. Clear legacy caller-assigned expiries so every
            // pre-existing row follows the selected no-automatic-expiry policy.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The versioned upgrade must update the bounded plugin-owned ledger batch directly; WordPress has no CRUD or cache API for this table.
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET expires_at = NULL WHERE id > %d AND id <= %d AND id <= %d',
                    $wpdb->prefix . 'sentient_submission_ledger',
                    $cursor,
                    $last_id,
                    $snapshot['max_id']
                )
            );
        }
        else
        {
            // The versioned migration intentionally normalizes every snapshotted legacy row to
            // max(captured_at + selected retention, migration + 30 days).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The versioned upgrade must update the bounded plugin-owned ledger batch directly; WordPress has no CRUD or cache API for this table.
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i
                    SET expires_at = CASE
                        WHEN DATE_ADD(captured_at, INTERVAL %d DAY) > %s
                            THEN DATE_ADD(captured_at, INTERVAL %d DAY)
                        ELSE %s
                    END
                    WHERE id > %d AND id <= %d AND id <= %d',
                    $wpdb->prefix . 'sentient_submission_ledger',
                    $snapshot['retention_days'],
                    $snapshot['migration_floor'],
                    $snapshot['retention_days'],
                    $snapshot['migration_floor'],
                    $cursor,
                    $last_id,
                    $snapshot['max_id']
                )
            );
        }

        return false !== $updated;
    }

    private static function persist_submission_ledger_retention_backfill_cursor( int $cursor, int $max_id ): ?int
    {
        $allowed = (bool) apply_filters(
            'sentient_forms_submission_ledger_retention_backfill_allow_cursor_persist',
            true,
            $cursor,
            $max_id
        );
        if ( ! $allowed )
        {
            return null;
        }

        $stored_cursor = get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR, null );
        if ( ! is_numeric( $stored_cursor ) )
        {
            return null;
        }

        if ( (int) $stored_cursor < $cursor )
        {
            global $wpdb;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The migration cursor needs a monotonic compare-and-set across concurrent upgrade requests.
            $updated = $wpdb->query(
                $wpdb->prepare(
                    'UPDATE %i SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d',
                    $wpdb->options,
                    (string) $cursor,
                    self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR,
                    $cursor
                )
            );
            if ( false === $updated )
            {
                return null;
            }

            wp_cache_delete( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR, 'options' );
        }

        $persisted_cursor = get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR, null );
        if ( ! is_numeric( $persisted_cursor ) || (int) $persisted_cursor < $cursor || (int) $persisted_cursor > $max_id )
        {
            return null;
        }

        return (int) $persisted_cursor;
    }

    private static function delete_submission_ledger_retention_backfill_progress(): bool
    {
        delete_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_SNAPSHOT );
        delete_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR );

        return null === get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_SNAPSHOT, null )
            && null === get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_BACKFILL_CURSOR, null );
    }

    private static function create_async_requests_table(): void
    {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'sentient_async_requests';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            request_hash CHAR(64) NOT NULL,
            action_id VARCHAR(191) NOT NULL,
            adapter VARCHAR(191) DEFAULT NULL,
            record_type VARCHAR(20) NOT NULL DEFAULT 'job',
            status VARCHAR(20) NOT NULL DEFAULT 'queued',
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            last_error TEXT NULL,
            payload_digest CHAR(64) NULL,
            telemetry_payload LONGTEXT NULL,
            PRIMARY KEY  (request_hash),
            KEY status_idx (status),
            KEY record_type_idx (record_type),
            KEY last_seen_idx (last_seen_at)
        ) {$charset_collate};";

        dbDelta( $sql );
    }

    private static function activate_current_site(): void
    {
        self::maybe_upgrade( true );
        Sentient_Forms_Local_Data_Governance::schedule_retention_cleanup();
    }

    private static function deactivate_current_site(): void
    {
        Sentient_Forms_Local_Data_Governance::unschedule_retention_cleanup();
        Sentient_Forms_OpenRouter_Model_Catalog_Refresh_Cron::unschedule();
    }

    private static function uninstall_current_site(): void
    {
        $delete_data = Sentient_Forms_Local_Data_Governance::delete_data_on_uninstall_enabled();

        if ( ! $delete_data && self::local_first_tables_exist() )
        {
            Sentient_Forms_Local_Data_Governance::unschedule_retention_cleanup();
            update_option( self::OPTION_DB_VERSION, SENTIENT_FORMS_DB_VERSION );
            return;
        }

        Sentient_Forms_Local_Data_Governance::uninstall();
    }

    /**
     * Run an installer callback for each site in a multisite network.
     *
     * @param callable(): void $callback Site-scoped installer callback.
     */
    private static function run_for_each_site( callable $callback ): void
    {
        if ( ! function_exists( 'get_sites' ) )
        {
            $callback();
            return;
        }

        $site_ids = get_sites(
            [
                'fields' => 'ids',
                'number' => 0,
            ]
        );

        foreach ( $site_ids as $site_id )
        {
            self::run_for_site( (int) $site_id, $callback );
        }
    }

    /**
     * Run an installer callback after switching to a specific site.
     *
     * @param callable(): void $callback Site-scoped installer callback.
     */
    private static function run_for_site( int $site_id, callable $callback ): void
    {
        if ( $site_id <= 0 || ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) )
        {
            $callback();
            return;
        }

        switch_to_blog( $site_id );

        global $wpdb;
        if ( method_exists( $wpdb, 'set_blog_id' ) )
        {
            $wpdb->set_blog_id( $site_id );
            $GLOBALS['blog_id']      = $site_id;
            $GLOBALS['table_prefix'] = $wpdb->get_blog_prefix( $site_id );
        }

        try
        {
            $callback();
        }
        finally
        {
            restore_current_blog();
        }
    }

    private static function local_first_tables_exist(): bool
    {
        global $wpdb;

        foreach ( Sentient_Forms_Local_Data_Governance::local_table_suffixes() as $suffix )
        {
            $table_name = $wpdb->prefix . $suffix;

            if ( ! self::table_exists( $table_name ) )
            {
                return false;
            }
        }

        return true;
    }

    private static function create_local_first_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tables = [
            "CREATE TABLE {$wpdb->prefix}sentient_provider_credentials (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider VARCHAR(50) NOT NULL,
                label VARCHAR(191) NOT NULL,
                auth_mode VARCHAR(50) NOT NULL,
                encrypted_secret LONGTEXT NULL,
                constant_name VARCHAR(191) NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'unknown',
                status_json LONGTEXT NULL,
                last_validated_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY provider_idx (provider),
                KEY status_idx (status),
                KEY updated_idx (updated_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_external_service_consents (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider VARCHAR(50) NOT NULL,
                disclosure_version VARCHAR(50) NOT NULL,
                accepted_by_user_id BIGINT UNSIGNED NULL,
                accepted_at DATETIME NOT NULL,
                site_url_hash CHAR(64) NULL,
                metadata_json LONGTEXT NULL,
                PRIMARY KEY  (id),
                KEY provider_version_idx (provider, disclosure_version),
                KEY accepted_at_idx (accepted_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_action_templates (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source VARCHAR(50) NOT NULL,
                external_id VARCHAR(191) NULL,
                code VARCHAR(191) NOT NULL,
                display_name VARCHAR(191) NOT NULL,
                description TEXT NULL,
                prompt_template LONGTEXT NOT NULL,
                default_model VARCHAR(191) NULL,
                structured_output_schema LONGTEXT NULL,
                override_schema LONGTEXT NULL,
                version VARCHAR(50) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY code_unique (code),
                KEY external_id_idx (external_id),
                KEY source_idx (source),
                KEY active_idx (is_active)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_custom_actions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                external_id VARCHAR(191) NULL,
                template_id BIGINT UNSIGNED NULL,
                code VARCHAR(191) NOT NULL,
                display_name VARCHAR(191) NOT NULL,
                definition_json LONGTEXT NOT NULL,
                model_selection_json LONGTEXT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY code_unique (code),
                KEY external_id_idx (external_id),
                KEY template_idx (template_id),
                KEY status_idx (status)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_form_mappings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                external_id VARCHAR(191) NULL,
                form_source VARCHAR(100) NOT NULL,
                form_id VARCHAR(100) NOT NULL,
                hook VARCHAR(100) NOT NULL,
                action_kind VARCHAR(30) NOT NULL,
                action_id BIGINT UNSIGNED NOT NULL,
                conditions_json LONGTEXT NULL,
                input_bindings_json LONGTEXT NOT NULL,
                execution_mode VARCHAR(30) NOT NULL,
                effect_mapping_json LONGTEXT NULL,
                settings_json LONGTEXT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY external_id_idx (external_id),
                KEY form_idx (form_source, form_id),
                KEY action_idx (action_kind, action_id),
                KEY hook_idx (hook),
                KEY enabled_idx (enabled)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_submission_ledger_settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_source VARCHAR(100) NOT NULL,
                form_id VARCHAR(100) NOT NULL,
                enabled TINYINT(1) NOT NULL DEFAULT 0,
                enabled_at DATETIME NULL,
                enabled_by_user_id BIGINT UNSIGNED NULL,
                disabled_at DATETIME NULL,
                disabled_by_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY form_unique (form_source, form_id),
                KEY enabled_idx (enabled),
                KEY updated_idx (updated_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_submission_ledger (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                submission_uuid VARCHAR(36) NOT NULL,
                form_source VARCHAR(100) NOT NULL,
                form_id VARCHAR(100) NOT NULL,
                native_entry_id VARCHAR(191) NULL,
                native_correlation_hash CHAR(64) NULL,
                native_entry_url TEXT NULL,
                source_submitted_at DATETIME NULL,
                captured_at DATETIME NOT NULL,
                logical_fields_json LONGTEXT NOT NULL,
                provider_metadata_json LONGTEXT NULL,
                file_refs_json LONGTEXT NULL,
                redaction_summary_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY submission_unique (submission_uuid),
                UNIQUE KEY native_correlation_unique (native_correlation_hash),
                KEY form_captured_idx (form_source, form_id, captured_at, id),
                KEY form_entry_idx (form_source, form_id, native_entry_id),
                KEY expires_idx (expires_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_execution_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                execution_request_id VARCHAR(191) NOT NULL,
                mapping_id BIGINT UNSIGNED NULL,
                mapping_key VARCHAR(191) NULL,
                action_code VARCHAR(191) NULL,
                action_label VARCHAR(191) NULL,
                form_source VARCHAR(100) NULL,
                form_id VARCHAR(100) NULL,
                entry_id VARCHAR(191) NULL,
                submission_uuid VARCHAR(36) NULL,
                provider VARCHAR(50) NOT NULL,
                model VARCHAR(191) NULL,
                status VARCHAR(30) NOT NULL,
                token_usage_json LONGTEXT NULL,
                cost_json LONGTEXT NULL,
                result_json LONGTEXT NULL,
                error_code VARCHAR(100) NULL,
                error_message TEXT NULL,
                payload_digest CHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY execution_request_unique (execution_request_id),
                KEY mapping_idx (mapping_id),
                KEY mapping_key_idx (mapping_key),
                KEY form_entry_idx (form_source, form_id, entry_id),
                KEY status_idx (status),
                KEY expires_idx (expires_at),
                KEY created_idx (created_at),
                KEY created_id_idx (created_at, id),
                KEY form_created_id_idx (form_source, form_id, created_at, id),
                KEY form_submission_idx (form_source, form_id, submission_uuid)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_lead_profiles (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_source VARCHAR(100) NOT NULL,
                form_id VARCHAR(100) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                profile_version INT UNSIGNED NOT NULL DEFAULT 1,
                consented_at DATETIME NULL,
                site_context_snapshot_json LONGTEXT NULL,
                spam_guidance_snapshot_json LONGTEXT NULL,
                good_lead_criteria_json LONGTEXT NULL,
                bad_lead_criteria_json LONGTEXT NULL,
                grading_rubric_json LONGTEXT NULL,
                example_entries_json LONGTEXT NULL,
                generated_profile_prompt LONGTEXT NULL,
                generation_metadata_json LONGTEXT NULL,
                assistant_json LONGTEXT NULL,
                handoff_rules_json LONGTEXT NULL,
                created_by_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY form_idx (form_source, form_id),
                KEY status_idx (status),
                KEY updated_idx (updated_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_historical_analysis_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_source VARCHAR(100) NOT NULL,
                form_id VARCHAR(100) NOT NULL,
                action_code VARCHAR(191) NOT NULL,
                lead_profile_id BIGINT UNSIGNED NULL,
                selected_entry_ids_json LONGTEXT NULL,
                filters_json LONGTEXT NULL,
                estimated_entry_count INT UNSIGNED NOT NULL DEFAULT 0,
                estimated_managed_credits INT UNSIGNED NULL,
                estimated_direct_provider_cost_json LONGTEXT NULL,
                dry_run TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(30) NOT NULL DEFAULT 'draft',
                progress_json LONGTEXT NULL,
                result_summary_json LONGTEXT NULL,
                created_by_user_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY form_idx (form_source, form_id),
                KEY profile_idx (lead_profile_id),
                KEY action_idx (action_code),
                KEY status_idx (status),
                KEY updated_idx (updated_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_lead_scoring_results (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                form_source VARCHAR(100) NOT NULL,
                form_id VARCHAR(100) NOT NULL,
                form_title VARCHAR(191) NULL,
                entry_id VARCHAR(191) NOT NULL,
                action_code VARCHAR(191) NOT NULL,
                execution_request_id VARCHAR(191) NOT NULL,
                historical_run_id BIGINT UNSIGNED NULL,
                lead_profile_id BIGINT UNSIGNED NULL,
                profile_version INT UNSIGNED NULL,
                grade VARCHAR(20) NULL,
                confidence DECIMAL(5,4) NULL,
                priority VARCHAR(30) NULL,
                fit_summary TEXT NULL,
                intent_summary TEXT NULL,
                justification LONGTEXT NULL,
                next_best_action TEXT NULL,
                suggested_reply_draft LONGTEXT NULL,
                reply_rationale LONGTEXT NULL,
                do_not_send TINYINT(1) NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'succeeded',
                entry_snapshot_json LONGTEXT NULL,
                source_payload_json LONGTEXT NULL,
                source_created_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                expires_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY execution_action_unique (execution_request_id, action_code),
                KEY form_entry_idx (form_source, form_id, entry_id),
                KEY form_grade_idx (form_source, form_id, grade),
                KEY profile_idx (lead_profile_id),
                KEY historical_idx (historical_run_id),
                KEY updated_idx (updated_at),
                KEY expires_idx (expires_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_migration_runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source VARCHAR(50) NOT NULL,
                source_version VARCHAR(50) NULL,
                status VARCHAR(30) NOT NULL,
                dry_run TINYINT(1) NOT NULL DEFAULT 1,
                summary_json LONGTEXT NOT NULL,
                conflicts_json LONGTEXT NULL,
                mapping_json LONGTEXT NULL,
                actor_user_id BIGINT UNSIGNED NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                PRIMARY KEY  (id),
                KEY source_idx (source),
                KEY status_idx (status),
                KEY started_idx (started_at)
            ) {$charset_collate};",
            "CREATE TABLE {$wpdb->prefix}sentient_model_cache (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider VARCHAR(50) NOT NULL,
                model_id VARCHAR(191) NOT NULL,
                metadata_json LONGTEXT NOT NULL,
                fetched_at DATETIME NOT NULL,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY provider_model_unique (provider, model_id),
                KEY expires_idx (expires_at)
            ) {$charset_collate};",
        ];

        foreach ( $tables as $sql )
        {
            dbDelta( $sql );
        }
    }

    private static function backfill_submission_ledger_native_correlations(): bool
    {
        if ( ! class_exists( 'Sentient_Forms_Submission_Ledger_Repository' ) )
        {
            return false;
        }
        if ( self::native_correlation_backfill_is_complete() )
        {
            delete_option( self::OPTION_NATIVE_CORRELATION_CURSOR );
            return true;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $table_name = $wpdb->prefix . 'sentient_submission_ledger';
        $last_id    = max( 0, (int) get_option( self::OPTION_NATIVE_CORRELATION_CURSOR, 0 ) );
        $batch_size = 50;

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The bounded upgrade backfill must read current plugin-owned ledger rows and must not cache migration state.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, form_source, form_id, native_entry_id FROM %i WHERE id > %d AND native_entry_id IS NOT NULL AND native_entry_id <> %s AND native_correlation_hash IS NULL ORDER BY id ASC LIMIT %d',
                $table_name,
                $last_id,
                '',
                $batch_size + 1
            ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) || '' !== $wpdb->last_error )
        {
            return false;
        }

        $has_more = count( $rows ) > $batch_size;
        foreach ( array_slice( $rows, 0, $batch_size ) as $row )
        {
            $last_id         = max( $last_id, (int) ( $row['id'] ?? 0 ) );
            $form_source     = sanitize_key( (string) ( $row['form_source'] ?? '' ) );
            $form_id         = sanitize_text_field( (string) ( $row['form_id'] ?? '' ) );
            $native_entry_id = sanitize_text_field( (string) ( $row['native_entry_id'] ?? '' ) );
            $hash            = Sentient_Forms_Submission_Ledger_Repository::native_correlation_hash( $form_source, $form_id, $native_entry_id );
            if ( null === $hash )
            {
                return false;
            }

            $winner = $repository->get_by_native_correlation_hash( $hash );
            if ( is_array( $winner ) )
            {
                if ( ! self::submission_ledger_native_tuple_matches( $winner, $form_source, $form_id, $native_entry_id ) )
                {
                    return false;
                }
                continue;
            }

            $assigned = $repository->assign_native_correlation_hash( (int) $row['id'], $hash );
            if ( is_wp_error( $assigned ) )
            {
                $winner = $repository->get_by_native_correlation_hash( $hash );
                if ( ! is_array( $winner ) || ! self::submission_ledger_native_tuple_matches( $winner, $form_source, $form_id, $native_entry_id ) )
                {
                    return false;
                }
            }
        }

        if ( $has_more )
        {
            if ( self::native_correlation_backfill_is_complete() )
            {
                delete_option( self::OPTION_NATIVE_CORRELATION_CURSOR );
                return true;
            }

            $updated = update_option( self::OPTION_NATIVE_CORRELATION_CURSOR, $last_id, false );
            if ( self::native_correlation_backfill_is_complete() )
            {
                delete_option( self::OPTION_NATIVE_CORRELATION_CURSOR );
                return true;
            }
            if ( ! $updated && $last_id !== (int) get_option( self::OPTION_NATIVE_CORRELATION_CURSOR, 0 ) )
            {
                return false;
            }

            return false;
        }

        $updated = update_option(
            self::OPTION_NATIVE_CORRELATION_BACKFILL_VERSION,
            self::NATIVE_CORRELATION_BACKFILL_VERSION,
            false
        );
        if ( ! $updated && ! self::native_correlation_backfill_is_complete() )
        {
            return false;
        }

        delete_option( self::OPTION_NATIVE_CORRELATION_CURSOR );

        return false === get_option( self::OPTION_NATIVE_CORRELATION_CURSOR, false );
    }

    private static function native_correlation_backfill_is_complete(): bool
    {
        return self::NATIVE_CORRELATION_BACKFILL_VERSION === get_option( self::OPTION_NATIVE_CORRELATION_BACKFILL_VERSION, '' );
    }

    private static function submission_ledger_native_correlation_schema_ready(): bool
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'sentient_submission_ledger';

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installer readiness must inspect the live plugin-owned table schema during activation and upgrade.
        $column = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW COLUMNS FROM %i LIKE %s',
                $table_name,
                'native_correlation_hash'
            )
        );
        if ( 'native_correlation_hash' !== $column || '' !== $wpdb->last_error )
        {
            return false;
        }

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installer readiness must inspect the live plugin-owned table indexes during activation and upgrade.
        $index = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW INDEX FROM %i WHERE Key_name = %s',
                $table_name,
                'native_correlation_unique'
            )
        );

        return null !== $index && '' !== (string) $index && '' === $wpdb->last_error;
    }

    private static function submission_ledger_native_tuple_matches( array $row, string $form_source, string $form_id, string $native_entry_id ): bool
    {
        return sanitize_key( (string) ( $row['form_source'] ?? '' ) ) === $form_source
            && sanitize_text_field( (string) ( $row['form_id'] ?? '' ) ) === $form_id
            && sanitize_text_field( (string) ( $row['native_entry_id'] ?? '' ) ) === $native_entry_id;
    }

    private static function seed_bundled_action_templates(): void
    {
        if ( ! class_exists( 'Sentient_Forms_Action_Templates_Repository' ) || ! class_exists( 'Sentient_Forms_Bundled_Action_Templates' ) )
        {
            return;
        }

        global $wpdb;
        $repository = new Sentient_Forms_Action_Templates_Repository( $wpdb );

        foreach ( Sentient_Forms_Bundled_Action_Templates::definitions() as $definition )
        {
            $repository->upsert_by_code(
                [
                    'source'                   => $definition['source'] ?? 'bundled',
                    'external_id'              => null,
                    'code'                     => $definition['code'] ?? '',
                    'display_name'             => $definition['display_name'] ?? '',
                    'description'              => $definition['description'] ?? null,
                    'prompt_template'          => $definition['prompt_template'] ?? '',
                    'default_model'            => $definition['default_model'] ?? null,
                    'structured_output_schema' => $definition['structured_output_schema'] ?? null,
                    'override_schema'          => $definition['override_schema'] ?? null,
                    'version'                  => $definition['version'] ?? '1',
                    'is_active'                => array_key_exists( 'is_active', $definition ) ? $definition['is_active'] : true,
                ]
            );
        }
    }

    private static function repair_local_first_action_integrity(): void
    {
        global $wpdb;

        $mappings_table = $wpdb->prefix . 'sentient_form_mappings';
        $actions_table  = $wpdb->prefix . 'sentient_custom_actions';
        $credentials_table = $wpdb->prefix . 'sentient_provider_credentials';

        if ( ! self::table_exists( $mappings_table ) || ! self::table_exists( $actions_table ) )
        {
            return;
        }

        if ( self::table_exists( $credentials_table ) )
        {
            $model_selection_service = new Sentient_Forms_Local_Action_Model_Selection_Service(
                new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ),
                new Sentient_Forms_Provider_Credentials_Repository( $wpdb ),
                new Sentient_Forms_Form_Mappings_Repository( $wpdb )
            );
            $model_selection_service->repair_all_custom_actions();
            $model_selection_service->repair_all_bundled_form_mappings();
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installer repair inspects plugin-owned local-first custom tables during activation/upgrade only.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT m.id AS mapping_id, m.action_id, a.id AS custom_action_id, a.status AS custom_action_status
                FROM %i m
                LEFT JOIN %i a ON a.id = m.action_id
                WHERE m.enabled = %d AND m.action_kind = %s',
                $mappings_table,
                $actions_table,
                1,
                'custom_action'
            ),
            ARRAY_A
        ) ?: [];

        foreach ( $rows as $row )
        {
            $mapping_id = absint( $row['mapping_id'] ?? 0 );
            $action_id  = absint( $row['action_id'] ?? 0 );
            if ( $mapping_id <= 0 || $action_id <= 0 )
            {
                continue;
            }

            $custom_action_id = absint( $row['custom_action_id'] ?? 0 );
            if ( $custom_action_id <= 0 )
            {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Installer repair updates a plugin-owned custom table whose name is derived from the trusted WordPress prefix.
                $wpdb->update(
                    $mappings_table,
                    [
                        'enabled'    => 0,
                        'updated_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $mapping_id ],
                    [ '%d', '%s' ],
                    [ '%d' ]
                );
                continue;
            }

            $status = sanitize_key( (string) ( $row['custom_action_status'] ?? '' ) );
            if ( 'archived' === $status )
            {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Installer repair updates a plugin-owned custom table whose name is derived from the trusted WordPress prefix.
                $wpdb->update(
                    $actions_table,
                    [
                        'status'     => 'active',
                        'updated_at' => current_time( 'mysql' ),
                    ],
                    [ 'id' => $custom_action_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
            }
        }
    }

    private static function table_exists( string $table_name ): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installer schema probes must check the database directly and run only during activation/upgrade repair.
        $found = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $table_name ) );
        $wpdb->suppress_errors( $suppress );

        return ! empty( $found );
    }
}
