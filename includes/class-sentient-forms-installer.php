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
    }

    public static function maybe_upgrade(): void
    {
        $current = get_option( self::OPTION_DB_VERSION, '' );
        if ( $current === SENTIENT_FORMS_DB_VERSION )
        {
            return;
        }

        self::create_async_requests_table();
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
}
