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
    private const OPTION_SUBMISSION_LEDGER_RETENTION_DAYS = 'sentient_forms_submission_ledger_retention_days';
    private const OPTION_DELETE_ON_UNINSTALL = 'sentient_forms_delete_data_on_uninstall';
    private const OPTION_STORE_FULL_AI_OUTPUTS = 'sentient_forms_store_full_ai_outputs';
    private const OPTION_PRIVACY_SETUP_PROFILE = 'sentient_forms_privacy_setup_profile';
    private const OPTION_PRIVACY_SETUP_COMPLETED_AT = 'sentient_forms_privacy_setup_completed_at';
    private const DEFAULT_RETENTION_DAYS = 90;
    private const DEFAULT_DELETE_ON_UNINSTALL = true;
    private const DEFAULT_STORE_FULL_AI_OUTPUTS = false;
    private const DEFAULT_PRIVACY_SETUP_PROFILE = 'balanced';
    private const MANUAL_RETENTION_DAYS = 0;
    private const ALLOWED_RETENTION_DAYS = [ 7, 30, 90, 180, self::MANUAL_RETENTION_DAYS ];
    private const ALLOWED_PRIVACY_SETUP_PROFILES = [ 'balanced', 'privacy_focused', 'maximum_privacy', 'maximum_visibility' ];

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
     * Return the supported Submission Ledger retention choices for the admin UI.
     *
     * @return array<int, int>
     */
    public static function submission_ledger_retention_choices(): array
    {
        return self::ALLOWED_RETENTION_DAYS;
    }

    /**
     * Normalize administrator-configurable Submission Ledger retention days.
     */
    public static function sanitize_submission_ledger_retention_days( mixed $value ): int
    {
        if ( ! is_numeric( $value ) )
        {
            return self::DEFAULT_RETENTION_DAYS;
        }

        $days = (int) $value;

        return in_array( $days, self::ALLOWED_RETENTION_DAYS, true )
            ? $days
            : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Read the current Submission Ledger retention setting.
     */
    public static function current_submission_ledger_retention_days(): int
    {
        return self::sanitize_submission_ledger_retention_days(
            get_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS )
        );
    }

    /**
     * Persist the Submission Ledger retention setting.
     */
    public static function update_submission_ledger_retention_days( mixed $value ): int
    {
        $days = self::sanitize_submission_ledger_retention_days( $value );
        update_option( self::OPTION_SUBMISSION_LEDGER_RETENTION_DAYS, $days );

        return $days;
    }

    /**
     * Calculate the expiry for a newly captured Submission Ledger record.
     *
     * @param string|null $captured_at UTC MySQL datetime assigned by the capture service.
     */
    public static function default_submission_ledger_expires_at( ?string $captured_at = null ): ?string
    {
        $days = self::current_submission_ledger_retention_days();
        $days = (int) apply_filters( 'sentient_forms_submission_ledger_retention_days', $days );

        if ( $days <= 0 )
        {
            return null;
        }

        $captured_at = $captured_at ?: current_time( 'mysql', true );
        $timestamp   = strtotime( $captured_at . ' UTC' );
        if ( false === $timestamp )
        {
            $timestamp = time();
        }

        return gmdate( 'Y-m-d H:i:s', $timestamp + ( $days * DAY_IN_SECONDS ) );
    }

    /**
     * Read whether uninstall should delete local plugin-owned data.
     */
    public static function delete_data_on_uninstall_enabled(): bool
    {
        return rest_sanitize_boolean( get_option( self::OPTION_DELETE_ON_UNINSTALL, self::DEFAULT_DELETE_ON_UNINSTALL ) );
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
     * Read whether full AI outputs may be persisted locally.
     */
    public static function store_full_ai_outputs_enabled(): bool
    {
        return rest_sanitize_boolean( get_option( self::OPTION_STORE_FULL_AI_OUTPUTS, self::DEFAULT_STORE_FULL_AI_OUTPUTS ) );
    }

    /**
     * Persist whether full AI outputs may be stored locally.
     */
    public static function update_store_full_ai_outputs( mixed $value ): bool
    {
        $enabled = rest_sanitize_boolean( $value );
        update_option( self::OPTION_STORE_FULL_AI_OUTPUTS, $enabled );

        return $enabled;
    }

    /**
     * @return array<int, string>
     */
    public static function privacy_setup_profile_choices(): array
    {
        return self::ALLOWED_PRIVACY_SETUP_PROFILES;
    }

    /**
     * Normalize the recorded privacy setup profile.
     */
    public static function sanitize_privacy_setup_profile( mixed $value ): string
    {
        $profile = sanitize_key( (string) $value );
        if ( in_array( $profile, self::ALLOWED_PRIVACY_SETUP_PROFILES, true ) )
        {
            return $profile;
        }

        return self::DEFAULT_PRIVACY_SETUP_PROFILE;
    }

    /**
     * Read the currently recorded privacy/visibility profile.
     */
    public static function current_privacy_setup_profile(): string
    {
        return self::sanitize_privacy_setup_profile(
            get_option( self::OPTION_PRIVACY_SETUP_PROFILE, self::DEFAULT_PRIVACY_SETUP_PROFILE )
        );
    }

    /**
     * Persist the selected privacy/visibility profile.
     */
    public static function update_privacy_setup_profile( mixed $value ): string
    {
        $profile = self::sanitize_privacy_setup_profile( $value );
        update_option( self::OPTION_PRIVACY_SETUP_PROFILE, $profile );

        return $profile;
    }

    /**
     * Read the completion timestamp for the first-run privacy setup assistant.
     */
    public static function privacy_setup_completed_at(): ?string
    {
        $completed_at = get_option( self::OPTION_PRIVACY_SETUP_COMPLETED_AT, null );
        if ( ! is_string( $completed_at ) )
        {
            return null;
        }

        $completed_at = trim( $completed_at );
        return '' !== $completed_at ? $completed_at : null;
    }

    /**
     * Persist the completion timestamp for the first-run privacy setup assistant.
     */
    public static function update_privacy_setup_completed_at( mixed $value = null ): ?string
    {
        $completed_at = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( '' === $completed_at )
        {
            $completed_at = gmdate( 'c' );
        }

        update_option( self::OPTION_PRIVACY_SETUP_COMPLETED_AT, $completed_at );
        return $completed_at;
    }

    /**
     * Clear the completion timestamp so the assistant can be shown again.
     */
    public static function clear_privacy_setup_completed_at(): void
    {
        delete_option( self::OPTION_PRIVACY_SETUP_COMPLETED_AT );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function privacy_preset_definitions(): array
    {
        return [
            'balanced' => [
                'label'                     => __( 'Balanced', 'sentient-forms' ),
                'description'               => __( 'Keeps practical troubleshooting without storing full AI responses by default.', 'sentient-forms' ),
                'execution_event_retention_days' => 90,
                'submission_ledger_retention_days' => 90,
                'delete_data_on_uninstall'  => true,
                'store_full_ai_outputs'     => false,
                'enable_logging'            => false,
            ],
            'privacy_focused' => [
                'label'                     => __( 'Privacy focused', 'sentient-forms' ),
                'description'               => __( 'Shorter retention and reduced local visibility for routine production sites.', 'sentient-forms' ),
                'execution_event_retention_days' => 30,
                'submission_ledger_retention_days' => 30,
                'delete_data_on_uninstall'  => true,
                'store_full_ai_outputs'     => false,
                'enable_logging'            => false,
            ],
            'maximum_privacy' => [
                'label'                     => __( 'Maximum privacy', 'sentient-forms' ),
                'description'               => __( 'Minimizes what Sentient Forms keeps locally after an action runs.', 'sentient-forms' ),
                'execution_event_retention_days' => 7,
                'submission_ledger_retention_days' => 7,
                'delete_data_on_uninstall'  => true,
                'store_full_ai_outputs'     => false,
                'enable_logging'            => false,
            ],
            'maximum_visibility' => [
                'label'                     => __( 'Maximum visibility', 'sentient-forms' ),
                'description'               => __( 'Keeps more local diagnostics for setup, tuning, and troubleshooting.', 'sentient-forms' ),
                'execution_event_retention_days' => 180,
                'submission_ledger_retention_days' => 180,
                'delete_data_on_uninstall'  => true,
                'store_full_ai_outputs'     => true,
                'enable_logging'            => true,
            ],
        ];
    }

    /**
     * Apply one of the supported privacy/visibility presets and mark the assistant complete.
     *
     * @return array<string, mixed>
     */
    public static function apply_privacy_preset( mixed $profile ): array
    {
        $profile = self::sanitize_privacy_setup_profile( $profile );
        $preset  = self::privacy_preset_definitions()[ $profile ];

        self::update_privacy_setup_profile( $profile );
        self::update_execution_event_retention_days( $preset['execution_event_retention_days'] );
        self::update_submission_ledger_retention_days( $preset['submission_ledger_retention_days'] );
        self::update_delete_data_on_uninstall( $preset['delete_data_on_uninstall'] );
        self::update_store_full_ai_outputs( $preset['store_full_ai_outputs'] );
        self::update_privacy_setup_completed_at();

        return [
            'privacy_setup_profile'      => $profile,
            'privacy_setup_completed_at' => self::privacy_setup_completed_at(),
            'execution_event_retention_days' => self::current_execution_event_retention_days(),
            'submission_ledger_retention_days' => self::current_submission_ledger_retention_days(),
            'delete_data_on_uninstall'   => self::delete_data_on_uninstall_enabled(),
            'store_full_ai_outputs'      => self::store_full_ai_outputs_enabled(),
            'enable_logging'             => rest_sanitize_boolean( $preset['enable_logging'] ),
        ];
    }

    /**
     * Strip raw full-output content from stored execution payloads unless the webmaster opted in.
     *
     * @param array<string, mixed> $result Raw execution result payload.
     * @return array<string, mixed>
     */
    public static function sanitize_execution_result_for_storage( array $result, ?string $provider = null ): array
    {
        $is_managed = class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' )
            && Sentient_Forms_Managed_Usage_Sanitizer::is_managed_payload( $result );
        if ( null !== $provider && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) )
        {
            $is_managed = $is_managed || Sentient_Forms_Managed_Usage_Sanitizer::is_managed_provider( $provider );
        }

        if ( self::store_full_ai_outputs_enabled() )
        {
            return $is_managed && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' )
                ? Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $result )
                : $result;
        }

        $filtered = [];
        foreach ( [ 'provider_response_id', 'model', 'finish_reason', 'usage', 'metering', 'effects', 'native_effect_outcomes', 'structured_output_valid', 'structured_output_schema_source', 'privacy_route_assertion', 'privacy_route_fallback', 'privacy_route_failure', 'fallback' ] as $key )
        {
            if ( array_key_exists( $key, $result ) )
            {
                $filtered[ $key ] = $result[ $key ];
            }
        }

        if ( is_array( $result['structured'] ?? null ) )
        {
            $filtered['structured'] = $result['structured'];
        }

        foreach ( [ 'classification', 'confidence', 'summary', 'justification', 'reasoning' ] as $key )
        {
            if ( array_key_exists( $key, $result ) )
            {
                $filtered[ $key ] = $result[ $key ];
            }
        }

        $summary = self::derive_result_summary( $result );
        if ( '' !== $summary )
        {
            $filtered['result_summary'] = $summary;
        }

        return $is_managed && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' )
            ? Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $filtered )
            : $filtered;
    }

    /**
     * Strip raw full-output content from higher-level execution payloads before local persistence.
     *
     * @param array<string, mixed> $payload Raw execution payload.
     * @return array<string, mixed>
     */
    public static function sanitize_execution_payload_for_storage( array $payload ): array
    {
        $provider   = isset( $payload['provider'] ) && is_scalar( $payload['provider'] ) ? sanitize_key( (string) $payload['provider'] ) : null;
        $is_managed = class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' )
            && ( Sentient_Forms_Managed_Usage_Sanitizer::is_managed_provider( $provider ) || Sentient_Forms_Managed_Usage_Sanitizer::is_managed_payload( $payload ) );

        if ( self::store_full_ai_outputs_enabled() )
        {
            return $is_managed && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' )
                ? Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $payload )
                : $payload;
        }

        $filtered = $payload;

        if ( is_array( $filtered['result'] ?? null ) )
        {
            $filtered['result'] = self::sanitize_execution_result_for_storage( $filtered['result'], $provider );
        }

        if ( is_array( $filtered['result_data'] ?? null ) )
        {
            $filtered['result_data'] = self::sanitize_legacy_result_data_for_storage( $filtered['result_data'] );
        }

        if ( is_array( $filtered['structured_output'] ?? null ) )
        {
            $filtered['structured_output'] = $filtered['structured_output'];
        }

        if ( isset( $filtered['llm_output'] ) )
        {
            $summary = self::derive_result_summary( [ 'content' => $filtered['llm_output'] ] );
            unset( $filtered['llm_output'] );
            if ( '' !== $summary && empty( $filtered['result_summary'] ) )
            {
                $filtered['result_summary'] = $summary;
            }
        }

        if ( isset( $filtered['content'] ) )
        {
            $summary = self::derive_result_summary( [ 'content' => $filtered['content'] ] );
            unset( $filtered['content'] );
            if ( '' !== $summary && empty( $filtered['result_summary'] ) )
            {
                $filtered['result_summary'] = $summary;
            }
        }

        return $is_managed && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' )
            ? Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $filtered )
            : $filtered;
    }

    /**
     * Delete expired execution rows and return the number removed.
     */
    public static function run_retention_cleanup(): int
    {
        global $wpdb;

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $deleted = $events->cleanup_expired( gmdate( 'Y-m-d H:i:s' ) );
        if ( class_exists( 'Sentient_Forms_Lead_Scoring_Results_Repository' ) )
        {
            $lead_scoring = new Sentient_Forms_Lead_Scoring_Results_Repository( $wpdb );
            $deleted += $lead_scoring->cleanup_expired( gmdate( 'Y-m-d H:i:s' ) );
        }
        if ( class_exists( 'Sentient_Forms_Submission_Ledger_Repository' ) )
        {
            $submission_ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
            $deleted += $submission_ledger->cleanup_expired( gmdate( 'Y-m-d H:i:s' ) );
        }

        return $deleted;
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
        $event_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                WHERE result_json LIKE %s OR error_message LIKE %s
                ORDER BY id ASC
                LIMIT %d OFFSET %d',
                $wpdb->prefix . 'sentient_execution_events',
                $like,
                $like,
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];
        $lead_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                WHERE entry_snapshot_json LIKE %s OR source_payload_json LIKE %s OR justification LIKE %s OR suggested_reply_draft LIKE %s OR reply_rationale LIKE %s OR next_best_action LIKE %s
                ORDER BY id ASC
                LIMIT %d OFFSET %d',
                $wpdb->prefix . 'sentient_lead_scoring_results',
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];
        $ledger_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i
                WHERE logical_fields_json LIKE %s OR provider_metadata_json LIKE %s OR file_refs_json LIKE %s OR native_entry_id LIKE %s OR native_entry_url LIKE %s
                ORDER BY id ASC
                LIMIT %d OFFSET %d',
                $wpdb->prefix . 'sentient_submission_ledger',
                $like,
                $like,
                $like,
                $like,
                $like,
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];

        $data = array_merge(
            array_map( [ self::class, 'format_export_item' ], $event_rows ),
            array_map( [ self::class, 'format_lead_scoring_export_item' ], $lead_rows ),
            array_map( [ self::class, 'format_submission_ledger_export_item' ], $ledger_rows )
        );

        return [
            'data' => $data,
            'done' => count( $event_rows ) < $per_page && count( $lead_rows ) < $per_page && count( $ledger_rows ) < $per_page,
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
        $event_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i
                WHERE result_json LIKE %s OR error_message LIKE %s
                ORDER BY id ASC
                LIMIT %d',
                $wpdb->prefix . 'sentient_execution_events',
                $like,
                $like,
                $per_page
            ),
            ARRAY_A
        ) ?: [];
        $lead_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i
                WHERE entry_snapshot_json LIKE %s OR source_payload_json LIKE %s OR justification LIKE %s OR suggested_reply_draft LIKE %s OR reply_rationale LIKE %s OR next_best_action LIKE %s
                ORDER BY id ASC
                LIMIT %d',
                $wpdb->prefix . 'sentient_lead_scoring_results',
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $per_page
            ),
            ARRAY_A
        ) ?: [];
        $ledger_rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i
                WHERE logical_fields_json LIKE %s OR provider_metadata_json LIKE %s OR file_refs_json LIKE %s OR native_entry_id LIKE %s OR native_entry_url LIKE %s
                ORDER BY id ASC
                LIMIT %d',
                $wpdb->prefix . 'sentient_submission_ledger',
                $like,
                $like,
                $like,
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

        foreach ( $event_rows as $row )
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

        $lead_update_table = $wpdb->prefix . 'sentient_lead_scoring_results';
        foreach ( $lead_rows as $row )
        {
            $wpdb->update(
                $lead_update_table,
                [
                    'entry_snapshot_json'    => $payload,
                    'source_payload_json'    => $payload,
                    'fit_summary'            => '',
                    'intent_summary'         => '',
                    'justification'          => '',
                    'next_best_action'       => '',
                    'suggested_reply_draft'  => '',
                    'reply_rationale'        => '',
                    'updated_at'             => $erased_at,
                ],
                [ 'id' => (int) $row['id'] ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $ledger_update_table = $wpdb->prefix . 'sentient_submission_ledger';
        foreach ( $ledger_rows as $row )
        {
            $wpdb->update(
                $ledger_update_table,
                [
                    'native_entry_id'        => null,
                    'native_entry_url'       => null,
                    'logical_fields_json'    => $payload,
                    'provider_metadata_json' => $payload,
                    'file_refs_json'         => $payload,
                    'redaction_summary_json' => $payload,
                    'updated_at'             => $erased_at,
                ],
                [ 'id' => (int) $row['id'] ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        $removed = count( $event_rows ) > 0 || count( $lead_rows ) > 0 || count( $ledger_rows ) > 0;

        return [
            'items_removed'  => $removed,
            'items_retained' => $removed,
            'messages'       => $removed
                ? [ __( 'Sentient Forms removed local execution, lead scoring, and submission ledger content and retained non-content audit metadata.', 'sentient-forms' ) ]
                : [],
            'done'           => count( $event_rows ) < $per_page && count( $lead_rows ) < $per_page && count( $ledger_rows ) < $per_page,
        ];
    }

    /**
     * Remove local plugin data when the admin has explicitly opted into deletion.
     */
    public static function uninstall(): void
    {
        self::unschedule_retention_cleanup();

        $delete_data = (bool) get_option( self::OPTION_DELETE_ON_UNINSTALL, self::DEFAULT_DELETE_ON_UNINSTALL );
        $delete_data = (bool) apply_filters( 'sentient_forms_delete_data_on_uninstall', $delete_data );

        if ( ! $delete_data )
        {
            return;
        }

        global $wpdb;

        foreach ( self::local_table_suffixes() as $suffix )
        {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- This runs only during explicit uninstall cleanup for plugin-owned local-first tables after the admin has opted into data deletion.
            $wpdb->query(
                $wpdb->prepare(
                    'DROP TABLE IF EXISTS %i',
                    $wpdb->prefix . $suffix
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
        }

        self::delete_plugin_options();
        self::delete_gravity_forms_entry_meta();
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
            'sentient_submission_ledger_settings',
            'sentient_submission_ledger',
            'sentient_execution_events',
            'sentient_lead_profiles',
            'sentient_historical_analysis_runs',
            'sentient_lead_scoring_results',
            'sentient_migration_runs',
            'sentient_model_cache',
        ];
    }

    private static function delete_plugin_options(): void
    {
        global $wpdb;

        $option_names = [
            'sentient_forms_db_version',
            'sentient_forms_settings',
            'sentient_forms_plugin_settings',
            'sentient_forms_license_status',
            'sentient_forms_proxy_api_key',
            self::OPTION_RETENTION_DAYS,
            self::OPTION_SUBMISSION_LEDGER_RETENTION_DAYS,
            'sentient_forms_submission_ledger_retention_backfill_version',
            'sentient_forms_submission_ledger_retention_backfill_snapshot_v1',
            'sentient_forms_submission_ledger_retention_backfill_cursor_v1',
            self::OPTION_DELETE_ON_UNINSTALL,
            self::OPTION_STORE_FULL_AI_OUTPUTS,
            self::OPTION_PRIVACY_SETUP_PROFILE,
            self::OPTION_PRIVACY_SETUP_COMPLETED_AT,
        ];

        foreach ( $option_names as $option_name )
        {
            delete_option( $option_name );
        }

        self::delete_plugin_transients();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall cleanup for plugin-owned options and transients.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like( 'sentient_forms_' ) . '%',
                $wpdb->esc_like( '_transient_sentient_forms_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_sentient_forms_' ) . '%'
            )
        );
    }

    private static function delete_plugin_transients(): void
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall cleanup for plugin-owned transients and their object-cache entries.
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like( '_transient_sentient_forms_' ) . '%'
            )
        );

        foreach ( $option_names as $option_name )
        {
            $transient_name = substr( (string) $option_name, strlen( '_transient_' ) );
            if ( '' !== $transient_name )
            {
                delete_transient( $transient_name );
            }
        }
    }

    private static function delete_gravity_forms_entry_meta(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'gf_entry_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall cleanup for plugin-owned Gravity Forms entry metadata.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! is_string( $exists ) || '' === $exists )
        {
            return;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicit uninstall cleanup for plugin-owned Gravity Forms entry metadata.
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s',
                $table,
                $wpdb->esc_like( 'sentient_forms_' ) . '%',
                $wpdb->esc_like( '_sentient_forms_' ) . '%'
            )
        );
    }

    private static function format_export_item( array $row ): array
    {
        $result = json_decode( (string) ( $row['result_json'] ?? '' ), true );
        if ( is_array( $result ) && class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) )
        {
            $result = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_local_currency_policy( $result, $row['provider'] ?? null );
        }

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

    private static function format_lead_scoring_export_item( array $row ): array
    {
        $entry_snapshot = json_decode( (string) ( $row['entry_snapshot_json'] ?? '' ), true );
        $source_payload = json_decode( (string) ( $row['source_payload_json'] ?? '' ), true );

        return [
            'group_id'          => 'sentient-forms-lead-scoring-results',
            'group_label'       => __( 'Sentient Forms Lead Scoring Results', 'sentient-forms' ),
            'group_description' => __( 'Local Lead Scoring result records stored by Sentient Forms on this WordPress site.', 'sentient-forms' ),
            'item_id'           => 'sentient-forms-lead-scoring-result-' . (string) ( $row['id'] ?? '' ),
            'data'              => [
                [
                    'name'  => __( 'Form ID', 'sentient-forms' ),
                    'value' => (string) ( $row['form_id'] ?? '' ),
                ],
                [
                    'name'  => __( 'Entry ID', 'sentient-forms' ),
                    'value' => (string) ( $row['entry_id'] ?? '' ),
                ],
                [
                    'name'  => __( 'Grade', 'sentient-forms' ),
                    'value' => (string) ( $row['grade'] ?? '' ),
                ],
                [
                    'name'  => __( 'Justification', 'sentient-forms' ),
                    'value' => (string) ( $row['justification'] ?? '' ),
                ],
                [
                    'name'  => __( 'Suggested Reply', 'sentient-forms' ),
                    'value' => (string) ( $row['suggested_reply_draft'] ?? '' ),
                ],
                [
                    'name'  => __( 'Entry Snapshot', 'sentient-forms' ),
                    'value' => is_array( $entry_snapshot ) ? (string) wp_json_encode( $entry_snapshot ) : (string) ( $row['entry_snapshot_json'] ?? '' ),
                ],
                [
                    'name'  => __( 'Source Payload', 'sentient-forms' ),
                    'value' => is_array( $source_payload ) ? (string) wp_json_encode( $source_payload ) : (string) ( $row['source_payload_json'] ?? '' ),
                ],
                [
                    'name'  => __( 'Updated At', 'sentient-forms' ),
                    'value' => (string) ( $row['updated_at'] ?? '' ),
                ],
            ],
        ];
    }

    private static function format_submission_ledger_export_item( array $row ): array
    {
        $logical_fields    = json_decode( (string) ( $row['logical_fields_json'] ?? '' ), true );
        $provider_metadata = json_decode( (string) ( $row['provider_metadata_json'] ?? '' ), true );
        $file_refs         = json_decode( (string) ( $row['file_refs_json'] ?? '' ), true );

        return [
            'group_id'          => 'sentient-forms-submission-ledger',
            'group_label'       => __( 'Sentient Forms Submission Ledger', 'sentient-forms' ),
            'group_description' => __( 'Local submission snapshots stored by Sentient Forms after explicit ledger opt-in.', 'sentient-forms' ),
            'item_id'           => 'sentient-forms-submission-ledger-' . (string) ( $row['id'] ?? '' ),
            'data'              => [
                [
                    'name'  => __( 'Submission UUID', 'sentient-forms' ),
                    'value' => (string) ( $row['submission_uuid'] ?? '' ),
                ],
                [
                    'name'  => __( 'Form Source', 'sentient-forms' ),
                    'value' => (string) ( $row['form_source'] ?? '' ),
                ],
                [
                    'name'  => __( 'Form ID', 'sentient-forms' ),
                    'value' => (string) ( $row['form_id'] ?? '' ),
                ],
                [
                    'name'  => __( 'Native Entry ID', 'sentient-forms' ),
                    'value' => (string) ( $row['native_entry_id'] ?? '' ),
                ],
                [
                    'name'  => __( 'Logical Fields', 'sentient-forms' ),
                    'value' => is_array( $logical_fields ) ? (string) wp_json_encode( $logical_fields ) : (string) ( $row['logical_fields_json'] ?? '' ),
                ],
                [
                    'name'  => __( 'Provider Metadata', 'sentient-forms' ),
                    'value' => is_array( $provider_metadata ) ? (string) wp_json_encode( $provider_metadata ) : (string) ( $row['provider_metadata_json'] ?? '' ),
                ],
                [
                    'name'  => __( 'File References', 'sentient-forms' ),
                    'value' => is_array( $file_refs ) ? (string) wp_json_encode( $file_refs ) : (string) ( $row['file_refs_json'] ?? '' ),
                ],
                [
                    'name'  => __( 'Captured At', 'sentient-forms' ),
                    'value' => (string) ( $row['captured_at'] ?? '' ),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function derive_result_summary( array $result ): string
    {
        $structured = is_array( $result['structured'] ?? null ) ? $result['structured'] : [];
        foreach ( [ $structured['summary'] ?? null, $structured['justification'] ?? null, $result['summary'] ?? null, $result['justification'] ?? null, $result['reasoning'] ?? null, $result['content'] ?? null ] as $candidate )
        {
            if ( is_scalar( $candidate ) && '' !== trim( (string) $candidate ) )
            {
                return wp_trim_words( sanitize_textarea_field( (string) $candidate ), 50, '...' );
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $result_data
     * @return array<string, mixed>
     */
    private static function sanitize_legacy_result_data_for_storage( array $result_data ): array
    {
        $filtered = $result_data;

        if ( isset( $filtered['llm_output'] ) )
        {
            $summary = self::derive_result_summary( [ 'content' => $filtered['llm_output'] ] );
            unset( $filtered['llm_output'] );
            if ( '' !== $summary && empty( $filtered['result_summary'] ) )
            {
                $filtered['result_summary'] = $summary;
            }
        }

        if ( isset( $filtered['content'] ) )
        {
            $summary = self::derive_result_summary( [ 'content' => $filtered['content'] ] );
            unset( $filtered['content'] );
            if ( '' !== $summary && empty( $filtered['result_summary'] ) )
            {
                $filtered['result_summary'] = $summary;
            }
        }

        return $filtered;
    }
}
