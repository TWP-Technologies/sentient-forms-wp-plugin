<?php
/**
 * Local-first data retention, privacy, and uninstall helpers.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export/erase and retention cleanup must query plugin-owned custom tables directly so data is current and not cached across privacy operations. SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Local_Data_Governance
{
    public const RETENTION_HOOK = 'sentient_forms_local_retention_cleanup';

    private const OPTION_RETENTION_DAYS = 'sentient_forms_execution_event_retention_days';
    private const OPTION_DELETE_ON_UNINSTALL = 'sentient_forms_delete_data_on_uninstall';
    private const DEFAULT_RETENTION_DAYS = 90;
    private const MANUAL_RETENTION_DAYS = 0;
    private const ALLOWED_RETENTION_DAYS = [ 7, 30, 90, 180, self::MANUAL_RETENTION_DAYS ];

    /**
     * Register runtime hooks for privacy tools and scheduled cleanup.
     */
    public static function register_hooks(): void
    {
        add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'register_personal_data_exporter' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'register_personal_data_eraser' ] );
        add_action( self::RETENTION_HOOK, [ self::class, 'run_retention_cleanup' ] );

        self::schedule_retention_cleanup();
    }

    /**
     * Schedule the local execution-event cleanup job.
     */
    public static function schedule_retention_cleanup(): void
    {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) )
        {
            return;
        }

        if ( wp_next_scheduled( self::RETENTION_HOOK ) )
        {
            return;
        }

        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::RETENTION_HOOK );
    }

    /**
     * Clear scheduled cleanup when the plugin is deactivated or removed.
     */
    public static function unschedule_retention_cleanup(): void
    {
        if ( ! function_exists( 'wp_clear_scheduled_hook' ) )
        {
            return;
        }

        wp_clear_scheduled_hook( self::RETENTION_HOOK );
    }

    /**
     * Calculate the default expiry timestamp for new local execution rows.
     */
    public static function default_execution_event_expires_at(): ?string
    {
        $days = self::current_execution_event_retention_days();
        $days = (int) apply_filters( 'sentient_forms_execution_event_retention_days', $days );

        if ( $days <= 0 )
        {
            return null;
        }

        return gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
    }

    /**
     * Return the supported execution-event retention choices for the admin UI.
     *
     * @return array<int, int>
     */
    public static function execution_event_retention_choices(): array
    {
        return self::ALLOWED_RETENTION_DAYS;
    }

    /**
     * Normalize administrator-configurable execution-event retention days.
     *
     * @param mixed $value Raw value from REST/options.
     */
    public static function sanitize_execution_event_retention_days( mixed $value ): int
    {
        if ( ! is_numeric( $value ) )
        {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int) $value;

        if ( in_array( $days, self::ALLOWED_RETENTION_DAYS, true ) )
        {
            return $days;
        }

        return self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Read the current execution-event retention setting.
     */
    public static function current_execution_event_retention_days(): int
    {
        return self::sanitize_execution_event_retention_days(
            get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS )
        );
    }

    /**
     * Persist the execution-event retention setting.
     *
     * @param mixed $value Raw value from REST/options.
     */
    public static function update_execution_event_retention_days( mixed $value ): int
    {
        $days = self::sanitize_execution_event_retention_days( $value );
        update_option( self::OPTION_RETENTION_DAYS, $days );

        return $days;
    }

    /**
     * Read whether uninstall should delete local plugin-owned data.
     */
    public static function delete_data_on_uninstall_enabled(): bool
    {
        return rest_sanitize_boolean( get_option( self::OPTION_DELETE_ON_UNINSTALL, false ) );
    }

    /**
     * Persist whether uninstall should delete local plugin-owned data.
     */
    public static function update_delete_data_on_uninstall( mixed $value ): bool
    {
        $delete_data = rest_sanitize_boolean( $value );
        update_option( self::OPTION_DELETE_ON_UNINSTALL, $delete_data );

        return $delete_data;
    }

    /**
     * Delete expired execution rows and return the number removed.
     */
    public static function run_retention_cleanup(): int
    {
        global $wpdb;

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        return $events->cleanup_expired( gmdate( 'Y-m-d H:i:s' ) );
    }

    /**
     * Register the local execution-event exporter with WordPress privacy tools.
     *
     * @param array<string, array<string, mixed>> $exporters Existing exporters.
     * @return array<string, array<string, mixed>>
     */
    public static function register_personal_data_exporter( array $exporters ): array
    {
        $exporters['sentient-forms-execution-events'] = [
            'exporter_friendly_name' => __( 'Sentient Forms Local Execution Events', 'sentient-forms' ),
            'callback'               => [ self::class, 'export_personal_data' ],
        ];

        return $exporters;
    }

    /**
     * Register the local execution-event eraser with WordPress privacy tools.
     *
     * @param array<string, array<string, mixed>> $erasers Existing erasers.
     * @return array<string, array<string, mixed>>
     */
    public static function register_personal_data_eraser( array $erasers ): array
    {
        $erasers['sentient-forms-execution-events'] = [
            'eraser_friendly_name' => __( 'Sentient Forms Local Execution Events', 'sentient-forms' ),
            'callback'             => [ self::class, 'erase_personal_data' ],
        ];

        return $erasers;
    }

    /**
     * Export local execution rows that directly contain a verified email address.
     *
     * @param string $email_address Verified request email.
     * @param int    $page          One-based page number.
     * @return array<string, mixed>
     */
    public static function export_personal_data( string $email_address, int $page = 1 ): array
    {
        global $wpdb;

        $email_address = sanitize_email( $email_address );
        if ( '' === $email_address )
        {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $per_page = 100;
        $offset   = max( 0, ( $page - 1 ) * $per_page );
        $like     = '%' . $wpdb->esc_like( $email_address ) . '%';
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $wpdb->prefix . 'sentient_execution_events' ) . "
                WHERE result_json LIKE %s OR error_message LIKE %s
                ORDER BY id ASC
                LIMIT %d OFFSET %d",
                $like,
                $like,
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $data = array_map( [ self::class, 'format_export_item' ], $rows );

        return [
            'data' => $data,
            'done' => count( $rows ) < $per_page,
        ];
    }

    /**
     * Erase local result/error content that directly contains a verified email address.
     *
     * @param string $email_address Verified request email.
     * @param int    $page          One-based page number. Ignored while mutating to avoid skipped rows.
     * @return array<string, mixed>
     */
    public static function erase_personal_data( string $email_address, int $page = 1 ): array
    {
        global $wpdb;

        $email_address = sanitize_email( $email_address );
        if ( '' === $email_address )
        {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }

        $per_page = 100;
        $like     = '%' . $wpdb->esc_like( $email_address ) . '%';
        $rows     = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM ' . esc_sql( $wpdb->prefix . 'sentient_execution_events' ) . "
                WHERE result_json LIKE %s OR error_message LIKE %s
                ORDER BY id ASC
                LIMIT %d",
                $like,
                $like,
                $per_page
            ),
            ARRAY_A
        ) ?: [];

        $erased_at = gmdate( 'Y-m-d H:i:s' );
        $payload   = wp_json_encode(
            [
                'personal_data_erased' => true,
                'erased_at'            => $erased_at,
            ]
        );
        $update_table = $wpdb->prefix . 'sentient_execution_events';

        foreach ( $rows as $row )
        {
            $wpdb->update(
                $update_table,
                [
                    'result_json'   => $payload,
                    'error_message' => null,
                    'updated_at'    => $erased_at,
                ],
                [ 'id' => (int) $row['id'] ],
                [ '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $removed = count( $rows ) > 0;

        return [
            'items_removed'  => $removed,
            'items_retained' => $removed,
            'messages'       => $removed
                ? [ __( 'Sentient Forms removed local execution result/error content and retained non-content audit metadata.', 'sentient-forms' ) ]
                : [],
            'done'           => count( $rows ) < $per_page,
        ];
    }

    /**
     * Remove local plugin data when the admin has explicitly opted into deletion.
     */
    public static function uninstall(): void
    {
        self::unschedule_retention_cleanup();

        $delete_data = (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, false );
        $delete_data = (bool) apply_filters( 'sentient_forms_delete_data_on_uninstall', $delete_data );

        if ( ! $delete_data )
        {
            return;
        }

        global $wpdb;

        foreach ( self::local_table_suffixes() as $suffix )
        {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- This runs only during explicit uninstall cleanup for plugin-owned local-first tables after the admin has opted into data deletion.
            $wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . $suffix ) );
        }

        delete_option( 'sentient_forms_db_version' );
        delete_option( self::OPTION_RETENTION_DAYS );
        delete_option( self::OPTION_DELETE_ON_UNINSTALL );
    }

    /**
     * Local-first table suffixes governed by this migration.
     *
     * @return array<int, string>
     */
    public static function local_table_suffixes(): array
    {
        return [
            'sentient_provider_credentials',
            'sentient_external_service_consents',
            'sentient_action_templates',
            'sentient_custom_actions',
            'sentient_form_mappings',
            'sentient_execution_events',
            'sentient_migration_runs',
            'sentient_model_cache',
        ];
    }

    private static function format_export_item( array $row ): array
    {
        $result = json_decode( (string) ( $row['result_json'] ?? '' ), true );

        return [
            'group_id'          => 'sentient-forms-execution-events',
            'group_label'       => __( 'Sentient Forms Local Execution Events', 'sentient-forms' ),
            'group_description' => __( 'Local AI execution records stored by Sentient Forms on this WordPress site.', 'sentient-forms' ),
            'item_id'           => 'sentient-forms-execution-event-' . (string) ( $row['id'] ?? '' ),
            'data'              => [
                [
                    'name'  => __( 'Execution Request ID', 'sentient-forms' ),
                    'value' => (string) ( $row['execution_request_id'] ?? '' ),
                ],
                [
                    'name'  => __( 'Provider', 'sentient-forms' ),
                    'value' => (string) ( $row['provider'] ?? '' ),
                ],
                [
                    'name'  => __( 'Model', 'sentient-forms' ),
                    'value' => (string) ( $row['model'] ?? '' ),
                ],
                [
                    'name'  => __( 'Status', 'sentient-forms' ),
                    'value' => (string) ( $row['status'] ?? '' ),
                ],
                [
                    'name'  => __( 'Result', 'sentient-forms' ),
                    'value' => is_array( $result ) ? (string) wp_json_encode( $result ) : (string) ( $row['result_json'] ?? '' ),
                ],
                [
                    'name'  => __( 'Error Message', 'sentient-forms' ),
                    'value' => (string) ( $row['error_message'] ?? '' ),
                ],
                [
                    'name'  => __( 'Created At', 'sentient-forms' ),
                    'value' => (string) ( $row['created_at'] ?? '' ),
                ],
            ],
        ];
    }
}
