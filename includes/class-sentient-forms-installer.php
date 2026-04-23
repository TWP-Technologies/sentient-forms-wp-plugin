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

        if ( $current !== SENTIENT_FORMS_DB_VERSION || ( $repair_missing_tables && ! $tables_ready ) )
        {
            self::create_async_requests_table();
            self::create_local_first_tables();
            update_option( self::OPTION_DB_VERSION, SENTIENT_FORMS_DB_VERSION );
            $tables_ready = self::local_first_tables_exist();
        }

        if ( ! $tables_ready )
        {
            return;
        }

        self::seed_bundled_action_templates();
        self::repair_local_first_action_integrity();
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
            "CREATE TABLE {$wpdb->prefix}sentient_execution_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                execution_request_id VARCHAR(191) NOT NULL,
                mapping_id BIGINT UNSIGNED NULL,
                form_source VARCHAR(100) NULL,
                form_id VARCHAR(100) NULL,
                entry_id VARCHAR(191) NULL,
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
                KEY form_entry_idx (form_source, form_id, entry_id),
                KEY status_idx (status),
                KEY expires_idx (expires_at),
                KEY created_idx (created_at),
                KEY created_id_idx (created_at, id)
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

        if ( ! self::table_exists( $mappings_table ) || ! self::table_exists( $actions_table ) )
        {
            return;
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
