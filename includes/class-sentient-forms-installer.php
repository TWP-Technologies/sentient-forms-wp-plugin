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

    public static function activate(): void
    {
        self::maybe_upgrade();
        Sentient_Forms_Local_Data_Governance::schedule_retention_cleanup();
    }

    public static function deactivate(): void
    {
        Sentient_Forms_Local_Data_Governance::unschedule_retention_cleanup();
    }

    public static function uninstall(): void
    {
        Sentient_Forms_Local_Data_Governance::uninstall();
    }

    public static function maybe_upgrade(): void
    {
        $current = get_option( self::OPTION_DB_VERSION, '' );
        if ( $current === SENTIENT_FORMS_DB_VERSION )
        {
            return;
        }

        self::create_async_requests_table();
        self::create_local_first_tables();
        update_option( self::OPTION_DB_VERSION, SENTIENT_FORMS_DB_VERSION );
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
}
