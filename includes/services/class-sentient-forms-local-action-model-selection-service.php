<?php
/**
 * Local-first action model selection repair and credential resolution.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Action_Model_Selection_Service
{
    private const READY_CREDENTIAL_STATUSES = [ 'valid', 'limited' ];
    private const EXECUTION_MODES           = [ 'sync', 'async', 'real_time' ];
    private const MANAGED_DEFAULT_MODEL     = 'gemini-3-flash-preview';
    private const SCHEDULED_ACTION_SCAN_BATCH_SIZE = 100;
    private const SCHEDULED_ACTION_SCAN_MAX_PAGES  = 100;

    public function __construct(
        private ?Sentient_Forms_Local_Custom_Actions_Repository $custom_actions = null,
        private ?Sentient_Forms_Provider_Credentials_Repository $credentials = null,
        private ?Sentient_Forms_Form_Mappings_Repository $form_mappings = null,
        private ?Sentient_Forms_Model_Cache_Repository $model_cache = null
    )
    {
        global $wpdb;

        $this->custom_actions = $this->custom_actions ?? new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $this->credentials    = $this->credentials ?? new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->form_mappings  = $this->form_mappings ?? new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->model_cache    = $this->model_cache ?? new Sentient_Forms_Model_Cache_Repository( $wpdb );
    }

    /**
     * Delete a provider credential only when no persisted action or mapping still names it.
     *
     * @return bool|WP_Error
     */
    public function delete_credential_if_unreferenced( int $credential_id ): bool | WP_Error
    {
        $credential_id = absint( $credential_id );
        global $wpdb;
        $async_requests = new Sentient_Forms_Async_Request_Store( $wpdb );
        $actions_table_configured = is_string( $wpdb->actionscheduler_actions ?? null )
            && '' !== (string) $wpdb->actionscheduler_actions;
        $actions_table = $actions_table_configured
                ? (string) $wpdb->actionscheduler_actions
                : $wpdb->prefix . 'actionscheduler_actions';

        if (
            ! $this->credentials->uses_transactional_storage()
            || ! $this->custom_actions->uses_transactional_storage()
            || ! $this->form_mappings->uses_transactional_storage()
            || ! $async_requests->uses_transactional_storage()
            || ! $this->table_uses_transactional_storage( $wpdb->options )
            || (
                $actions_table_configured
                && ! $this->table_uses_transactional_storage( $actions_table )
            )
        )
        {
            return new WP_Error(
                'sentient_forms_nontransactional_credential_reference_store',
                __( 'Provider credential references require transactional storage before deletion.', 'sentient-forms' ),
                [ 'status' => 503 ]
            );
        }

        return $this->form_mappings->transaction(
            function () use ( $credential_id ): bool | WP_Error {
                $references = [];

                $actions = $this->custom_actions->list_all_for_update();
                if ( is_wp_error( $actions ) )
                {
                    $actions->add_data( [ 'status' => 503, 'reference_source' => 'custom_actions' ] );
                    return $actions;
                }
                foreach ( $actions as $action )
                {
                    $definition = is_array( $action['definition_json'] ?? null )
                        ? $action['definition_json']
                        : [];
                    if (
                        $this->contains_credential_reference(
                            $action['model_selection_json'] ?? null,
                            $credential_id,
                            [ 'credential_id', 'backup_credential_id' ]
                        )
                        || $this->credential_reference_value_matches(
                            $definition['credential_id'] ?? null,
                            $credential_id
                        )
                    )
                    {
                        $references[] = [
                            'type' => 'custom_action',
                            'id'   => absint( $action['id'] ?? 0 ),
                            'name' => sanitize_text_field(
                                (string) ( $action['display_name'] ?? $action['code'] ?? '' )
                            ),
                        ];
                    }
                }

                $mappings = $this->form_mappings->list_all_for_update();
                if ( is_wp_error( $mappings ) )
                {
                    $mappings->add_data( [ 'status' => 503, 'reference_source' => 'form_mappings' ] );
                    return $mappings;
                }
                foreach ( $mappings as $mapping )
                {
                    if ( $this->contains_credential_reference( $mapping['settings_json'] ?? null, $credential_id ) )
                    {
                        $references[] = [
                            'type'        => 'form_mapping',
                            'id'          => absint( $mapping['id'] ?? 0 ),
                            'form_source' => sanitize_key( (string) ( $mapping['form_source'] ?? '' ) ),
                            'form_id'     => sanitize_text_field( (string) ( $mapping['form_id'] ?? '' ) ),
                        ];
                    }
                }

                $option_references = $this->option_credential_references_for_update( $credential_id );
                if ( is_wp_error( $option_references ) )
                {
                    $option_references->add_data( [ 'status' => 503, 'reference_source' => 'options' ] );
                    return $option_references;
                }
                $references = array_merge( $references, $option_references );

                $durable_references = $this->durable_execution_credential_references( $credential_id );
                if ( is_wp_error( $durable_references ) )
                {
                    $durable_references->add_data( [ 'status' => 503, 'reference_source' => 'durable_jobs' ] );
                    return $durable_references;
                }
                $references = array_merge( $references, $durable_references );

                $cron_references = $this->wp_cron_execution_credential_references( $credential_id );
                if ( is_wp_error( $cron_references ) )
                {
                    $cron_references->add_data( [ 'status' => 503, 'reference_source' => 'wp_cron' ] );
                    return $cron_references;
                }
                $references = array_merge( $references, $cron_references );

                $scheduled_references = $this->scheduled_execution_credential_references( $credential_id );
                if ( is_wp_error( $scheduled_references ) )
                {
                    $scheduled_references->add_data( [ 'status' => 503, 'reference_source' => 'action_scheduler' ] );
                    return $scheduled_references;
                }
                $references = array_merge( $references, $scheduled_references );

                if ( [] !== $references )
                {
                    return new WP_Error(
                        'sentient_forms_credential_in_use',
                        __(
                            'This provider credential is still used by local configuration or queued work. Reassign dependent Actions, update Site Context, and let active jobs finish before deleting it.',
                            'sentient-forms'
                        ),
                        [
                            'status'     => 409,
                            'references' => $references,
                        ]
                    );
                }

                return $this->credentials->delete( $credential_id );
            }
        );
    }

    /**
     * @param array<int, string> $reference_keys
     */
    private function contains_credential_reference(
        mixed $value,
        int $credential_id,
        array $reference_keys = [ 'credential_id', 'backup_credential_id' ]
    ): bool
    {
        if ( ! is_array( $value ) || $credential_id <= 0 )
        {
            return false;
        }

        foreach ( $value as $key => $nested )
        {
            if (
                in_array( $key, $reference_keys, true )
                && $this->credential_reference_value_matches( $nested, $credential_id )
            )
            {
                return true;
            }

            if (
                is_array( $nested )
                && $this->contains_credential_reference( $nested, $credential_id, $reference_keys )
            )
            {
                return true;
            }
        }

        return false;
    }

    private function credential_reference_value_matches( mixed $value, int $credential_id ): bool
    {
        if ( is_int( $value ) )
        {
            return $value === $credential_id;
        }
        if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+$/', $value ) )
        {
            return false;
        }

        $normalized = ltrim( $value, '0' );
        return (string) $credential_id === ( '' === $normalized ? '0' : $normalized );
    }

    /**
     * Lock and inspect option-backed execution configuration.
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function option_credential_references_for_update( int $credential_id ): array | WP_Error
    {
        global $wpdb;

        $references   = [];
        $option_names = [
            'sentient_forms_site_context_settings',
            'sentient_forms_site_context_generation_job',
        ];

        foreach ( $option_names as $option_name )
        {
            $wpdb->last_error = '';
            $query = $wpdb->prepare(
                'SELECT option_name, option_value FROM %i WHERE option_name = %s FOR UPDATE',
                $wpdb->options,
                $option_name
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Credential deletion requires a fresh row lock; cached option reads cannot authorize deletion.
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with identifier and scalar placeholders.
                $query,
                ARRAY_A
            );
            if ( '' !== $wpdb->last_error )
            {
                return new WP_Error(
                    'sentient_forms_credential_reference_check_failed',
                    __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
                    [ 'status' => 503 ]
                );
            }
            if ( ! is_array( $row ) )
            {
                continue;
            }

            $value = maybe_unserialize( $row['option_value'] ?? null );
            if ( ! is_array( $value ) )
            {
                return new WP_Error(
                    'sentient_forms_credential_reference_check_failed',
                    __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
                    [ 'status' => 503 ]
                );
            }
            if (
                'sentient_forms_site_context_generation_job' === $option_name
                && ! in_array(
                    sanitize_key( (string) ( $value['status'] ?? '' ) ),
                    [ 'queued', 'running', 'in-progress' ],
                    true
                )
            )
            {
                continue;
            }

            if ( $this->contains_credential_reference( $value, $credential_id ) )
            {
                $references[] = [
                    'type' => 'sentient_forms_site_context_settings' === $option_name
                        ? 'site_context_settings'
                        : 'site_context_generation_job',
                    'id'   => $option_name,
                ];
            }
        }

        foreach (
            [
                'sentient_forms_form_config_',
                'sentient_forms_action_defaults_',
                'sentient_forms_actions_',
                'sentient_forms_gravity_forms_',
            ] as $option_prefix
        )
        {
            $wpdb->last_error = '';
            $query = $wpdb->prepare(
                'SELECT option_name, option_value FROM %i '
                    . 'WHERE option_name LIKE %s ORDER BY option_name ASC FOR UPDATE',
                $wpdb->options,
                $wpdb->esc_like( $option_prefix ) . '%'
            );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Credential deletion requires a fresh locked prefix scan; cached option reads could miss execution authority.
            $rows = $wpdb->get_results(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with identifier and scalar placeholders.
                $query,
                ARRAY_A
            );
            if ( ! is_array( $rows ) || '' !== $wpdb->last_error )
            {
                return new WP_Error(
                    'sentient_forms_credential_reference_check_failed',
                    __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
                    [ 'status' => 503 ]
                );
            }

            foreach ( $rows as $row )
            {
                $value = maybe_unserialize( $row['option_value'] ?? null );
                if ( ! is_array( $value ) )
                {
                    return new WP_Error(
                        'sentient_forms_credential_reference_check_failed',
                        __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
                        [ 'status' => 503 ]
                    );
                }
                if ( $this->contains_credential_reference( $value, $credential_id ) )
                {
                    $references[] = [
                        'type' => 'configuration_option',
                        'id'   => sanitize_key( (string) ( $row['option_name'] ?? '' ) ),
                    ];
                }
            }
        }

        return $references;
    }

    /**
     * Inspect authoritative pending and running Action Scheduler payloads.
     *
     * @return array<int, array<string, mixed>>|WP_Error
     */
    private function scheduled_execution_credential_references(
        int $credential_id,
        ?bool $scheduler_classes_available = null,
        ?bool $scheduler_functions_available = null
    ): array | WP_Error
    {
        global $wpdb;

        $scheduler_classes_available ??= class_exists( 'ActionScheduler' )
            && class_exists( 'ActionScheduler_Store' );
        $scheduler_functions_available ??= function_exists( 'as_schedule_single_action' )
            || function_exists( 'as_enqueue_async_action' );
        $scheduler_authority_state = $this->action_scheduler_authority_state(
            $scheduler_classes_available,
            $scheduler_functions_available
        );
        $scheduler_store = null;
        if ( $scheduler_classes_available )
        {
            try
            {
                $scheduler_store = ActionScheduler::store();
            }
            catch ( Throwable )
            {
                return $this->credential_reference_check_failed_error();
            }
            if (
                ! $scheduler_store instanceof ActionScheduler_DBStore
                && ! $scheduler_store instanceof ActionScheduler_HybridStore
            )
            {
                return new WP_Error(
                    'sentient_forms_credential_reference_check_unavailable',
                    __( 'Provider credential references could not be verified for the active scheduler store.', 'sentient-forms' ),
                    [ 'status' => 503 ]
                );
            }
        }

        $actions_table = is_string( $wpdb->actionscheduler_actions ?? null )
            && '' !== (string) $wpdb->actionscheduler_actions
                ? (string) $wpdb->actionscheduler_actions
                : $wpdb->prefix . 'actionscheduler_actions';
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This fail-closed authority probe must observe the exact current scheduler table, not cached state.
        $table_probe = $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $actions_table ) );
        if ( false === $table_probe )
        {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The server error code distinguishes a positively absent table from any unreadable or malformed authority query.
            $probe_errors = $wpdb->get_results( 'SHOW ERRORS LIMIT 1', ARRAY_A );
            $probe_code   = is_array( $probe_errors )
                ? absint( $probe_errors[0]['Code'] ?? 0 )
                : 0;
            if ( 1146 !== $probe_code )
            {
                return $this->credential_reference_check_failed_error();
            }
            if ( 'wp_cron_only' !== $scheduler_authority_state )
            {
                return new WP_Error(
                    'sentient_forms_credential_reference_check_unavailable',
                    __(
                        'Provider credential references could not be verified. Try again after Action Scheduler is available.',
                        'sentient-forms'
                    ),
                    [ 'status' => 503 ]
                );
            }
            return [];
        }
        if ( ! $this->table_uses_transactional_storage( $actions_table ) )
        {
            return new WP_Error(
                'sentient_forms_nontransactional_credential_reference_store',
                __( 'Provider credential references require transactional storage before deletion.', 'sentient-forms' ),
                [ 'status' => 503 ]
            );
        }

        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema compatibility must be established before selecting scheduler payloads.
        $extended_args_column = $wpdb->get_var(
            $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $actions_table, 'extended_args' )
        );
        if ( '' !== $wpdb->last_error )
        {
            return $this->credential_reference_check_failed_error();
        }
        $has_extended_args = 'extended_args' === (string) $extended_args_column;

        $hybrid_store_action_ids = [];
        if ( $scheduler_store instanceof ActionScheduler_HybridStore )
        {
            $runtime_actions_table = $wpdb->actionscheduler_actions ?? null;
            $wpdb->actionscheduler_actions = $actions_table;
            try
            {
                $hybrid_store_action_ids = $this->active_scheduler_store_action_ids( $scheduler_store );
            }
            finally
            {
                $wpdb->actionscheduler_actions = $runtime_actions_table;
            }
            if ( is_wp_error( $hybrid_store_action_ids ) )
            {
                return $hybrid_store_action_ids;
            }
        }

        $references = [];
        $scanned_action_ids = [];
        $last_id    = 0;
        $pending_status = $scheduler_classes_available
            ? ActionScheduler_Store::STATUS_PENDING
            : 'pending';
        $running_status = $scheduler_classes_available
            ? ActionScheduler_Store::STATUS_RUNNING
            : 'in-progress';
        $batch_size = max(
            1,
            min(
                self::SCHEDULED_ACTION_SCAN_BATCH_SIZE,
                absint(
                    apply_filters(
                        'sentient_forms_credential_reference_action_scan_batch_size',
                        self::SCHEDULED_ACTION_SCAN_BATCH_SIZE
                    )
                )
            )
        );
        for ( $page = 0; $page < self::SCHEDULED_ACTION_SCAN_MAX_PAGES; $page++ )
        {
            $wpdb->last_error = '';
            $query = $has_extended_args
                ? $wpdb->prepare(
                    'SELECT action_id, hook, status, COALESCE(NULLIF(extended_args, %s), args) AS args FROM %i '
                        . 'WHERE hook IN (%s, %s) AND status IN (%s, %s) AND action_id > %d '
                        . 'ORDER BY action_id ASC LIMIT %d FOR UPDATE',
                    '',
                    $actions_table,
                    Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
                    'sentient_forms_evaluate_action',
                    $pending_status,
                    $running_status,
                    $last_id,
                    $batch_size
                )
                : $wpdb->prepare(
                    'SELECT action_id, hook, status, args FROM %i '
                        . 'WHERE hook IN (%s, %s) AND status IN (%s, %s) AND action_id > %d '
                        . 'ORDER BY action_id ASC LIMIT %d FOR UPDATE',
                    $actions_table,
                    Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
                    'sentient_forms_evaluate_action',
                    $pending_status,
                    $running_status,
                    $last_id,
                    $batch_size
                );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared in the version-specific branch immediately above execution.
            $rows = $wpdb->get_results( $query, ARRAY_A );
            if ( ! is_array( $rows ) || '' !== $wpdb->last_error )
            {
                return new WP_Error(
                    'sentient_forms_credential_reference_check_failed',
                    __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
                    [ 'status' => 503 ]
                );
            }

            foreach ( $rows as $row )
            {
                $action_id = absint( $row['action_id'] ?? 0 );
                $args      = json_decode( (string) ( $row['args'] ?? '' ), true );
                if ( $action_id <= $last_id || ! is_array( $args ) )
                {
                    return new WP_Error(
                        'sentient_forms_credential_reference_check_failed',
                        __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
                        [ 'status' => 503 ]
                    );
                }
                $scanned_action_ids[ $action_id ] = $action_id;

                if (
                    $this->contains_credential_reference(
                        $args,
                        $credential_id,
                        [ 'credential_id', 'backup_credential_id' ]
                    )
                )
                {
                    $references[] = [
                        'type'   => 'scheduled_execution',
                        'id'     => $action_id,
                        'hook'   => sanitize_key( (string) ( $row['hook'] ?? '' ) ),
                        'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ),
                    ];
                }

                $last_id = $action_id;
            }

            if ( count( $rows ) < $batch_size )
            {
                if ( [] !== array_diff( $hybrid_store_action_ids, $scanned_action_ids ) )
                {
                    return new WP_Error(
                        'sentient_forms_credential_reference_check_unavailable',
                        __( 'Provider credential references could not be verified for unmigrated scheduled actions.', 'sentient-forms' ),
                        [ 'status' => 503 ]
                    );
                }
                return $references;
            }
        }

        return new WP_Error(
            'sentient_forms_credential_reference_check_too_large',
            __( 'Provider credential references exceed the safe verification limit. Finish queued work and try again.', 'sentient-forms' ),
            [ 'status' => 503 ]
        );
    }

    /** @return array<int, int>|WP_Error */
    private function active_scheduler_store_action_ids( ActionScheduler_Store $store ): array | WP_Error
    {
        $ids = [];
        $limit = self::SCHEDULED_ACTION_SCAN_BATCH_SIZE * self::SCHEDULED_ACTION_SCAN_MAX_PAGES + 1;
        foreach (
            [ Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK, 'sentient_forms_evaluate_action' ] as $hook
        )
        {
            foreach ( [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ] as $status )
            {
                try
                {
                    global $wpdb;
                    $wpdb->last_error = '';
                    $found = $store->query_actions(
                        [
                            'hook'     => $hook,
                            'status'   => $status,
                            'per_page' => $limit,
                        ]
                    );
                }
                catch ( Throwable )
                {
                    return $this->credential_reference_check_failed_error();
                }
                if ( '' !== $wpdb->last_error )
                {
                    return $this->credential_reference_check_failed_error();
                }
                if ( ! is_array( $found ) || count( $found ) >= $limit )
                {
                    return $this->credential_reference_check_failed_error();
                }
                foreach ( $found as $action_id )
                {
                    $action_id = absint( $action_id );
                    if ( $action_id > 0 )
                    {
                        $ids[ $action_id ] = $action_id;
                    }
                }
            }
        }

        return $ids;
    }

    private function action_scheduler_authority_state(
        bool $classes_available,
        bool $functions_available
    ): string
    {
        if ( ! $classes_available && ! $functions_available )
        {
            return 'wp_cron_only';
        }

        return $classes_available ? 'ready' : 'unavailable';
    }

    /** @return array<int, array<string, mixed>>|WP_Error */
    private function durable_execution_credential_references( int $credential_id ): array | WP_Error
    {
        global $wpdb;

        $rows = ( new Sentient_Forms_Async_Request_Store( $wpdb ) )
            ->list_active_authority_payloads_for_update();
        if ( is_wp_error( $rows ) )
        {
            return new WP_Error(
                'sentient_forms_credential_reference_check_failed',
                __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
                [ 'status' => 503 ]
            );
        }

        $references = [];
        foreach ( $rows as $row )
        {
            if ( $this->contains_credential_reference( $row['payload'] ?? null, $credential_id ) )
            {
                $references[] = [
                    'type'   => 'accepted_sync' === ( $row['record_type'] ?? '' )
                        ? 'active_execution'
                        : 'queued_execution',
                    'id'     => sanitize_text_field( (string) ( $row['request_hash'] ?? '' ) ),
                    'status' => sanitize_key( (string) ( $row['status'] ?? '' ) ),
                ];
            }
        }

        return $references;
    }

    /** Whether a directly locked authority table can participate in the deletion transaction. */
    private function table_uses_transactional_storage( string $table ): bool
    {
        global $wpdb;

        $previous_suppress_errors = $wpdb->suppress_errors();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- SHOW CREATE TABLE is read-only engine introspection for the deletion transaction fence.
        $show_create_query = $wpdb->prepare( 'SHOW CREATE TABLE %i', $table );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct catalog inspection is required before row locks are trusted.
        $definition = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder.
            $show_create_query,
            ARRAY_N
        );
        $wpdb->suppress_errors( $previous_suppress_errors );

        return is_array( $definition )
            && isset( $definition[1] )
            && 1 === preg_match( '/\bENGINE=(?:InnoDB|XtraDB)\b/i', (string) $definition[1] );
    }

    /** @return array<int, array<string, mixed>>|WP_Error */
    private function wp_cron_execution_credential_references( int $credential_id ): array | WP_Error
    {
        global $wpdb;

        $wpdb->last_error = '';
        $query = $wpdb->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s FOR UPDATE',
            $wpdb->options,
            'cron'
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Credential deletion requires a fresh row lock on the WP-Cron authority row.
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with identifier and scalar placeholders.
            $query,
            ARRAY_A
        );
        if ( '' !== $wpdb->last_error )
        {
            return $this->credential_reference_check_failed_error();
        }
        if ( null === $row )
        {
            return [];
        }

        $cron = is_array( $row ) ? maybe_unserialize( $row['option_value'] ?? null ) : null;
        if ( ! is_array( $cron ) )
        {
            return $this->credential_reference_check_failed_error();
        }

        $references = [];
        foreach ( $cron as $timestamp => $hooks )
        {
            if ( 'version' === (string) $timestamp )
            {
                continue;
            }
            if ( ! is_numeric( $timestamp ) )
            {
                return $this->credential_reference_check_failed_error();
            }
            if ( ! is_array( $hooks ) )
            {
                return $this->credential_reference_check_failed_error();
            }
            $execution_hooks = [
                Sentient_Forms_Async_Handler::LOCAL_MAPPING_HOOK,
                'sentient_forms_evaluate_action',
            ];
            foreach ( $execution_hooks as $execution_hook )
            {
                if ( ! array_key_exists( $execution_hook, $hooks ) )
                {
                    continue;
                }
                $events = $hooks[ $execution_hook ];
                if ( ! is_array( $events ) )
                {
                    return $this->credential_reference_check_failed_error();
                }
                foreach ( $events as $event )
                {
                    if ( ! is_array( $event ) || ! is_array( $event['args'] ?? null ) )
                    {
                        return $this->credential_reference_check_failed_error();
                    }
                    if (
                        $this->contains_credential_reference(
                            $event['args'] ?? null,
                            $credential_id,
                            [ 'credential_id', 'backup_credential_id' ]
                        )
                    )
                    {
                        $references[] = [
                            'type'   => 'wp_cron_execution',
                            'id'     => (string) absint( $timestamp ),
                            'hook'   => $execution_hook,
                            'status' => 'queued',
                        ];
                    }
                }
            }
        }

        return $references;
    }

    private function credential_reference_check_failed_error(): WP_Error
    {
        return new WP_Error(
            'sentient_forms_credential_reference_check_failed',
            __( 'Provider credential references could not be verified. Try again.', 'sentient-forms' ),
            [ 'status' => 503 ]
        );
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function prepare_model_selection_for_action( array $action ): array
    {
        $definition = is_array( $action['definition_json'] ?? null ) ? $action['definition_json'] : [];
        $selection  = is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [];
        $provider   = sanitize_key( (string) ( $selection['provider'] ?? $definition['provider'] ?? 'openrouter' ) );
        if ( '' === $provider )
        {
            $provider = 'openrouter';
        }

        $selection['provider'] = $provider;
        $source_provider       = $provider;
        $template_code         = $this->resolve_bundled_template_code_for_action( $action, $definition );
        if ( '' !== $template_code )
        {
            $definition = Sentient_Forms_Bundled_Action_Templates::get( $template_code ) ?: $definition;
            $model      = isset( $selection['model'] ) && is_scalar( $selection['model'] )
                ? trim( (string) $selection['model'] )
                : '';
            $default_model = sanitize_text_field( (string) ( $definition['default_model'] ?? 'openrouter/auto' ) );

            if ( $this->should_repair_realtime_auto_default( $template_code, $selection ) )
            {
                $selection['model'] = '' !== $default_model ? $default_model : 'sf_realtime';
                $managed_credential = $this->find_single_ready_credential_for_provider( 'sentient_managed' );
                if ( is_array( $managed_credential ) )
                {
                    $provider                   = 'sentient_managed';
                    $selection['provider']      = 'sentient_managed';
                    $selection['credential_id'] = absint( $managed_credential['id'] ?? 0 );
                }
            }
            elseif ( '' === $model || ! str_contains( $model, '/' ) )
            {
                $selection['model'] = '' !== $default_model ? $default_model : 'openrouter/auto';
            }

            $openrouter_backup_model      = $this->resolve_openrouter_backup_model_for_repair( $selection, $definition );
            $openrouter_backup_credential = $this->openrouter_backup_credential_for_repair( $selection );
            $managed_repair_credential    = $this->managed_credential_for_openrouter_bundled_repair( $template_code, $source_provider );
            if ( is_array( $managed_repair_credential ) )
            {
                $managed_credential_id = absint( $managed_repair_credential['id'] ?? 0 );
                if ( $managed_credential_id > 0 )
                {
                    $provider  = 'sentient_managed';
                    $selection = $this->repair_openrouter_bundled_selection_to_managed(
                        $template_code,
                        $selection,
                        $managed_credential_id
                    );

                    if ( is_array( $openrouter_backup_credential ) )
                    {
                        if ( '' !== $openrouter_backup_model )
                        {
                            $selection['backup_model'] = $openrouter_backup_model;
                        }

                        $selection = $this->attach_openrouter_backup_selection(
                            $selection,
                            absint( $openrouter_backup_credential['id'] ?? 0 )
                        );
                    }
                }
            }
        }
        elseif ( empty( $selection['model'] ) )
        {
            $selection['model'] = sanitize_text_field( (string) ( $definition['model'] ?? 'openrouter/auto' ) );
        }

        $saved_selection = is_array( $selection['selection'] ?? null ) ? $selection['selection'] : null;
        $saved_model     = isset( $selection['model'] ) && is_scalar( $selection['model'] )
            ? trim( sanitize_text_field( (string) $selection['model'] ) )
            : '';
        if ( is_array( $saved_selection ) )
        {
            $saved_selection        = $this->normalize_runtime_selection_for_resolution( $saved_selection, $provider );
            $selection['selection'] = $saved_selection;
            if ( array_key_exists( 'require_zdr', $saved_selection ) )
            {
                $selection['require_zdr'] = rest_sanitize_boolean( $saved_selection['require_zdr'] );
            }

            $resolved_model = $this->resolve_runtime_model_selection( $saved_selection );
            if ( '' !== $resolved_model )
            {
                $selection['model'] = $resolved_model;
            }
        }
        elseif ( str_starts_with( $saved_model, 'sf_' ) )
        {
            $resolved_model = 'sentient_managed' === $provider
                ? $this->resolve_managed_preset_model_id( sanitize_key( $saved_model ) )
                : $this->resolve_local_preset_model_id( sanitize_key( $saved_model ) );
            if ( '' !== $resolved_model )
            {
                $selection['model'] = $resolved_model;
                $selection['selection'] = [
                    'primary'   => $saved_model,
                    'is_preset' => true,
                ];
            }
        }

        if ( empty( $selection['credential_id'] ) )
        {
            $credential = $this->find_single_ready_credential_for_provider( $provider );
            if ( is_array( $credential ) )
            {
                $credential_id = absint( $credential['id'] ?? 0 );
                if ( $credential_id > 0 )
                {
                    $selection['credential_id'] = $credential_id;
                }
            }
        }

        $reasoning = $this->sanitize_reasoning_effort( $selection['reasoning'] ?? null );
        if ( '' !== $reasoning && $this->model_supports_reasoning( (string) ( $selection['model'] ?? '' ), $provider ) )
        {
            $selection['reasoning'] = $reasoning;
        }
        elseif ( isset( $selection['reasoning'] ) )
        {
            unset( $selection['reasoning'] );
        }

        $tools = $this->sanitize_tool_settings( $selection['tools'] ?? ( is_array( $saved_selection ) ? ( $saved_selection['tools'] ?? null ) : null ) );
        if ( [] !== $tools )
        {
            $selection['tools'] = $tools;
        }
        elseif ( isset( $selection['tools'] ) )
        {
            unset( $selection['tools'] );
        }

        if ( array_key_exists( 'require_zdr', $selection ) )
        {
            $selection['require_zdr'] = rest_sanitize_boolean( $selection['require_zdr'] );
        }

        return $selection;
    }

    /**
     * Visitor-facing realtime suggestions must not inherit OpenRouter Auto. On older installs the
     * bundled clarification action was seeded with openrouter/auto, which can select slow routes
     * and surface as gateway timeouts in form previews.
     *
     * @param array<string, mixed> $selection
     */
    private function should_repair_realtime_auto_default( string $template_code, array $selection ): bool
    {
        if ( 'clarification_assistant_v1' !== $template_code )
        {
            return false;
        }

        if ( is_array( $selection['selection'] ?? null ) )
        {
            return false;
        }

        $model = isset( $selection['model'] ) && is_scalar( $selection['model'] )
            ? trim( sanitize_text_field( (string) $selection['model'] ) )
            : '';

        return '' === $model || 'openrouter/auto' === $model;
    }

    /**
     * Bundled local-first actions should use the managed route whenever a ready managed
     * credential exists. Direct OpenRouter is retained separately as a backup route.
     *
     * @return array<string, mixed>|null
     */
    private function managed_credential_for_openrouter_bundled_repair( string $template_code, string $provider ): ?array
    {
        if ( '' === $template_code || 'openrouter' !== sanitize_key( $provider ) || ! $this->managed_account_is_active() )
        {
            return null;
        }

        $managed_credential = $this->find_single_ready_credential_for_provider( 'sentient_managed' );
        return is_array( $managed_credential ) ? $managed_credential : null;
    }

    /**
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $definition
     */
    private function resolve_openrouter_backup_model_for_repair( array $selection, array $definition ): string
    {
        $model = isset( $selection['model'] ) && is_scalar( $selection['model'] )
            ? trim( sanitize_text_field( (string) $selection['model'] ) )
            : '';

        if ( '' !== $model && ! str_starts_with( $model, 'sf_' ) )
        {
            return $model;
        }

        if ( str_starts_with( $model, 'sf_' ) )
        {
            $resolved = $this->resolve_local_preset_model_id( sanitize_key( $model ) );
            if ( '' !== $resolved )
            {
                return $resolved;
            }
        }

        if ( is_array( $definition['structured_output_schema'] ?? null ) )
        {
            return $this->resolve_local_preset_model_id( 'sf_structured' );
        }

        return 'openrouter/auto' === $model ? 'openrouter/auto' : '';
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>|null
     */
    private function openrouter_backup_credential_for_repair( array $selection ): ?array
    {
        $credential_id = absint( $selection['credential_id'] ?? 0 );
        if ( $credential_id > 0 )
        {
            $credential = $this->resolve_execution_credential( 'openrouter', $credential_id );
            if ( is_array( $credential ) )
            {
                return $credential;
            }
        }

        $credential = $this->find_single_ready_credential_for_provider( 'openrouter' );
        return is_array( $credential ) ? $credential : null;
    }

    public function managed_account_is_active(): bool
    {
        if ( ! class_exists( 'Sentient_Forms_Plugin' ) )
        {
            return false;
        }

        $plugin         = Sentient_Forms_Plugin::instance();
        $license        = $plugin->get_license_data();
        $license_status = sanitize_key( (string) ( $license['license_status'] ?? '' ) );
        if ( ! in_array( $license_status, [ 'active', 'trial', 'valid' ], true ) )
        {
            return false;
        }

        return '' !== trim( (string) ( $license['proxy_api_key'] ?? $plugin->get_proxy_api_key() ) )
            && '' !== trim( (string) ( $license['site_id'] ?? '' ) );
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function attach_openrouter_backup_selection( array $selection, int $credential_id ): array
    {
        if ( $credential_id <= 0 )
        {
            return $selection;
        }

        $backup_model = isset( $selection['backup_model'] ) && is_scalar( $selection['backup_model'] )
            ? trim( sanitize_text_field( (string) $selection['backup_model'] ) )
            : '';
        if ( '' === $backup_model )
        {
            $backup_model = isset( $selection['model'] ) && is_scalar( $selection['model'] )
                ? trim( sanitize_text_field( (string) $selection['model'] ) )
                : '';
        }
        if ( '' === $backup_model || str_starts_with( $backup_model, 'sf_' ) )
        {
            $backup_model = 'openrouter/auto';
        }

        $selection['backup_provider']       = 'openrouter';
        $selection['backup_credential_id'] = $credential_id;
        $selection['backup_model']         = $backup_model;

        return $selection;
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function repair_openrouter_bundled_selection_to_managed( string $template_code, array $selection, int $credential_id ): array
    {
        if ( $credential_id <= 0 )
        {
            return $selection;
        }

        $preset = 'clarification_assistant_v1' === $template_code ? 'sf_realtime' : 'sf_default';

        $runtime_selection = is_array( $selection['selection'] ?? null ) ? $selection['selection'] : [];
        $runtime_selection['primary']       = $preset;
        $runtime_selection['provider']      = 'sentient_managed';
        $runtime_selection['is_preset']     = true;
        $runtime_selection['credential_id'] = $credential_id;

        $selection['provider']      = 'sentient_managed';
        $selection['model']         = $preset;
        $selection['credential_id'] = $credential_id;
        $selection['selection']     = $runtime_selection;

        return $selection;
    }

    /**
     * Apply resolved runtime model overrides without mutating the saved action default.
     *
     * @param array<string, mixed> $action
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function prepare_model_selection_for_execution( array $action, array $context = [] ): array
    {
        $selection = $this->prepare_model_selection_for_action( $action );
        $settings  = is_array( $context['settings'] ?? null ) ? $context['settings'] : [];
        $runtime   = is_array( $settings['model_selection'] ?? null ) ? $settings['model_selection'] : null;

        if ( null === $runtime )
        {
            return $selection;
        }

        $runtime_declares_no_backup = array_key_exists( 'backup', $runtime )
            && ( ! is_scalar( $runtime['backup'] ) || '' === trim( sanitize_text_field( (string) $runtime['backup'] ) ) )
            && absint( $runtime['backup_credential_id'] ?? 0 ) <= 0;
        if (
            'absent_at_admission' === ( $runtime['backup_authority_status'] ?? '' )
            || $runtime_declares_no_backup
        )
        {
            unset( $selection['backup_provider'], $selection['backup_credential_id'], $selection['backup_model'] );
        }

        if ( isset( $runtime['provider'] ) && is_scalar( $runtime['provider'] ) )
        {
            $provider = sanitize_key( (string) $runtime['provider'] );
            if ( in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) )
            {
                $selection['provider'] = $provider;

                if (
                    isset( $selection['credential_id'] )
                    && ( ! isset( $runtime['credential_id'] ) || absint( $runtime['credential_id'] ) <= 0 )
                    && isset( $action['model_selection_json']['provider'] )
                    && sanitize_key( (string) $action['model_selection_json']['provider'] ) !== $provider
                )
                {
                    unset( $selection['credential_id'] );
                }
            }
        }

        if ( isset( $runtime['credential_id'] ) && is_scalar( $runtime['credential_id'] ) )
        {
            $credential_id = absint( $runtime['credential_id'] );
            if ( $credential_id > 0 )
            {
                $selection['credential_id'] = $credential_id;
            }
        }

        if (
            'absent_at_admission' !== ( $runtime['backup_authority_status'] ?? '' )
            && isset( $runtime['backup_provider'] )
            && is_scalar( $runtime['backup_provider'] )
        )
        {
            $backup_provider = sanitize_key( (string) $runtime['backup_provider'] );
            if ( 'openrouter' === $backup_provider )
            {
                $selection['backup_provider'] = $backup_provider;
            }
        }
        if (
            'absent_at_admission' !== ( $runtime['backup_authority_status'] ?? '' )
            && isset( $runtime['backup_credential_id'] )
            && is_scalar( $runtime['backup_credential_id'] )
        )
        {
            $backup_credential_id = absint( $runtime['backup_credential_id'] );
            if ( $backup_credential_id > 0 )
            {
                $selection['backup_credential_id'] = $backup_credential_id;
            }
        }

        $runtime        = $this->normalize_runtime_selection_for_resolution( $runtime, (string) ( $selection['provider'] ?? 'openrouter' ) );
        $resolved_model = $this->resolve_runtime_model_selection( $runtime );
        if ( '' !== $resolved_model )
        {
            $selection['model']             = $resolved_model;
            $selection['selection']         = $runtime;
            $selection['resolution_source'] = 'runtime_settings';
        }

        $backup_model = isset( $runtime['backup'] ) && is_scalar( $runtime['backup'] )
            ? trim( sanitize_text_field( (string) $runtime['backup'] ) )
            : '';
        if ( '' !== $backup_model )
        {
            $selection['backup_model'] = $backup_model;
        }

        $reasoning = $this->sanitize_reasoning_effort( $runtime['reasoning'] ?? null );
        if ( '' !== $reasoning && $this->model_supports_reasoning( (string) ( $selection['model'] ?? '' ), (string) ( $selection['provider'] ?? 'openrouter' ) ) )
        {
            $selection['reasoning'] = $reasoning;
        }
        elseif ( isset( $selection['reasoning'] ) )
        {
            unset( $selection['reasoning'] );
        }

        $tools = $this->sanitize_tool_settings( $runtime['tools'] ?? ( $selection['tools'] ?? null ) );
        if ( [] !== $tools )
        {
            $selection['tools'] = $tools;
        }
        elseif ( isset( $selection['tools'] ) )
        {
            unset( $selection['tools'] );
        }

        if ( array_key_exists( 'require_zdr', $runtime ) )
        {
            $selection['require_zdr'] = rest_sanitize_boolean( $runtime['require_zdr'] );
        }

        return $selection;
    }

    /**
     * @param array<string, mixed> $action
     */
    public function repair_action_model_selection( array $action ): bool | WP_Error
    {
        $action_id = absint( $action['id'] ?? 0 );
        if ( $action_id <= 0 )
        {
            return false;
        }

        $current  = is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [];
        $prepared = $this->prepare_model_selection_for_action( $action );
        if ( $prepared === $current )
        {
            return false;
        }

        $updated = $this->custom_actions->update(
            $action_id,
            [
                'model_selection_json' => $prepared,
            ]
        );

        if ( is_wp_error( $updated ) )
        {
            return $updated;
        }

        return true;
    }

    /**
     * Refresh imported built-in custom-action definitions before execution.
     *
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function prepare_bundled_action_for_execution( array $action ): array
    {
        $action_id   = absint( $action['id'] ?? 0 );
        $definition  = is_array( $action['definition_json'] ?? null ) ? $action['definition_json'] : [];
        $template_code = $this->resolve_bundled_template_code_for_action( $action, $definition );
        if ( '' === $template_code )
        {
            return $action;
        }

        $template = Sentient_Forms_Bundled_Action_Templates::get( $template_code );
        if ( ! is_array( $template ) )
        {
            return $action;
        }

        $prepared = $this->prepare_bundled_action_definition( $action, $definition, $template_code, $template );
        if ( $prepared === $definition )
        {
            return $action;
        }

        $action['definition_json'] = $prepared;

        if ( $action_id <= 0 )
        {
            return $action;
        }

        $updated = $this->custom_actions->update(
            $action_id,
            [
                'definition_json' => $prepared,
            ]
        );

        return is_wp_error( $updated ) ? $action : $updated;
    }

    /**
     * Repair missing bundled defaults on imported local form mappings.
     *
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    public function prepare_mapping_for_action( array $mapping, array $action ): array
    {
        $mapping_id = absint( $mapping['id'] ?? 0 );
        if ( $mapping_id <= 0 )
        {
            return $mapping;
        }

        $definition    = is_array( $action['definition_json'] ?? null ) ? $action['definition_json'] : [];
        $template_code = $this->resolve_bundled_template_code_for_action( $action, $definition );
        if ( '' === $template_code )
        {
            return $mapping;
        }

        $template = Sentient_Forms_Bundled_Action_Templates::get( $template_code );
        if ( ! is_array( $template ) )
        {
            return $mapping;
        }

        $updates = [];

        $current_effects = is_array( $mapping['effect_mapping_json'] ?? null ) ? $mapping['effect_mapping_json'] : null;
        $default_effects = $this->default_effect_mapping_for_action( $template_code, $template, $action, $definition );
        if ( [] !== $default_effects )
        {
            if ( null === $current_effects || [] === $current_effects )
            {
                $updates['effect_mapping_json'] = $default_effects;
            }
            elseif ( $this->is_imported_bundled_action( $action, $definition ) )
            {
                $merged_effects = array_replace_recursive( $default_effects, $current_effects );
                if ( $merged_effects !== $current_effects )
                {
                    $updates['effect_mapping_json'] = $merged_effects;
                }
            }
        }

        $execution_mode         = sanitize_key( (string) ( $mapping['execution_mode'] ?? '' ) );
        $default_execution_mode = $this->default_execution_mode_for_mapping( $mapping, $template );
        if ( ! in_array( $execution_mode, self::EXECUTION_MODES, true ) )
        {
            $updates['execution_mode'] = $default_execution_mode;
        }

        if ( [] === $updates )
        {
            return $mapping;
        }

        $updated = $this->form_mappings->update( $mapping_id, $updates );
        return is_wp_error( $updated ) ? $mapping : $updated;
    }

    /**
     * @return array{checked: int, repaired: int, errors: array<int, array{action_id: int, code: string, message: string}>}
     */
    public function repair_all_custom_actions(): array
    {
        $summary = [
            'checked'  => 0,
            'repaired' => 0,
            'errors'   => [],
        ];

        foreach ( $this->custom_actions->list_filtered( [ 'status' => 'active' ] ) as $action )
        {
            ++$summary['checked'];
            $prepared_action = $this->prepare_bundled_action_for_execution( $action );
            if ( $prepared_action !== $action )
            {
                ++$summary['repaired'];
                $action = $prepared_action;
            }

            $repaired = $this->repair_action_model_selection( $action );
            if ( is_wp_error( $repaired ) )
            {
                $summary['errors'][] = [
                    'action_id' => absint( $action['id'] ?? 0 ),
                    'code'      => $repaired->get_error_code(),
                    'message'   => $repaired->get_error_message(),
                ];
                continue;
            }

            if ( true === $repaired )
            {
                ++$summary['repaired'];
            }
        }

        return $summary;
    }

    /**
     * @return array{checked: int, repaired: int, errors: array<int, array{mapping_id: int, code: string, message: string}>}
     */
    public function repair_all_bundled_form_mappings(): array
    {
        $summary = [
            'checked'  => 0,
            'repaired' => 0,
            'errors'   => [],
        ];

        foreach ( $this->list_custom_action_mappings() as $mapping )
        {
            ++$summary['checked'];

            $action = $this->custom_actions->get( absint( $mapping['action_id'] ?? 0 ) );
            if ( ! is_array( $action ) )
            {
                continue;
            }

            $prepared = $this->prepare_mapping_for_action( $mapping, $action );
            if ( $prepared !== $mapping )
            {
                ++$summary['repaired'];
            }
        }

        return $summary;
    }

    public function resolve_execution_credential( string $provider, int $credential_id = 0 ): array | WP_Error
    {
        $provider      = sanitize_key( $provider );
        $credential_id = absint( $credential_id );

        if ( $credential_id > 0 )
        {
            $credential = $this->credentials->get( $credential_id );
            if ( null === $credential )
            {
                return new WP_Error(
                    'sentient_forms_provider_credential_not_found',
                    __( 'Provider credential could not be found.', 'sentient-forms' ),
                    [
                        'provider'              => $provider,
                        'requested_credential_id' => $credential_id,
                    ]
                );
            }

            return $this->validate_credential_for_provider( $provider, $credential );
        }

        $credential = $this->find_single_ready_credential_for_provider( $provider );
        if ( is_wp_error( $credential ) )
        {
            return $credential;
        }

        if ( null === $credential )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_not_found',
                __( 'No ready provider credential could be found. Validate a provider key before running local actions.', 'sentient-forms' ),
                [ 'provider' => $provider ]
            );
        }

        return $credential;
    }

    public function find_single_ready_credential_for_provider( string $provider ): array | WP_Error | null
    {
        $provider    = sanitize_key( $provider );
        $credentials = [];

        foreach ( $this->credentials->list( [ 'limit' => 100 ] ) as $credential )
        {
            if ( $provider !== sanitize_key( (string) ( $credential['provider'] ?? '' ) ) )
            {
                continue;
            }

            if ( ! in_array( sanitize_key( (string) ( $credential['status'] ?? '' ) ), self::READY_CREDENTIAL_STATUSES, true ) )
            {
                continue;
            }

            $credential_id = absint( $credential['id'] ?? 0 );
            if ( $credential_id > 0 )
            {
                $credentials[] = $credential;
            }
        }

        if ( 0 === count( $credentials ) )
        {
            return null;
        }

        if ( count( $credentials ) > 1 )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_ambiguous',
                __( 'Multiple ready provider credentials exist. Choose a credential for this local action before execution.', 'sentient-forms' ),
                [ 'provider' => $provider ]
            );
        }

        return $credentials[0];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function resolve_bundled_template_code_for_action( array $action, array $definition ): string
    {
        $identity = Sentient_Forms_Bundled_Action_Templates::resolve_action_identity( $action, $definition );
        return is_wp_error( $identity ) ? '' : $identity['template_code'];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function prepare_bundled_action_definition( array $action, array $definition, string $template_code, array $template ): array
    {
        $prepared                  = $definition;
        $prepared['template_code'] = $template_code;

        foreach ( [ 'action_policy', 'allowed_facets' ] as $policy_field )
        {
            if ( is_array( $template[ $policy_field ] ?? null ) )
            {
                $prepared[ $policy_field ] = $template[ $policy_field ];
            }
        }
        if ( ! array_key_exists( 'enabled_facets', $prepared ) && is_array( $template['enabled_facets'] ?? null ) )
        {
            $prepared['enabled_facets'] = $template['enabled_facets'];
        }

        if ( ! $this->is_imported_bundled_action( $action, $definition ) )
        {
            return $prepared;
        }

        $template_definition = is_array( $template['definition_json'] ?? null ) ? $template['definition_json'] : [];
        if ( [] !== $template_definition )
        {
            $prepared = array_replace_recursive( $template_definition, $prepared );
        }

        if ( isset( $template['prompt_template'] ) && is_scalar( $template['prompt_template'] ) )
        {
            $template_prompt = trim( (string) $template['prompt_template'] );
            if ( '' !== $template_prompt )
            {
                $prepared['prompt_template'] = $template_prompt;
            }
        }

        $prepared['template_code'] = $template_code;
        return $prepared;
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $action
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function default_effect_mapping_for_action( string $template_code, array $template, array $action, array $definition ): array
    {
        $effects = is_array( $template['effect_mapping_json'] ?? null ) ? $template['effect_mapping_json'] : [];

        if (
            'entry_summary_v1' === $template_code
            && $this->is_imported_bundled_action( $action, $definition )
            && ! isset( $effects['entry_note'] )
        )
        {
            $effects['entry_note'] = [
                'path'   => 'content',
                'prefix' => __( 'Sentient Forms entry summary:', 'sentient-forms' ),
            ];
        }

        return $effects;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $template
     */
    private function default_execution_mode_for_mapping( array $mapping, array $template ): string
    {
        if ( 'gform_validation' === sanitize_key( (string) ( $mapping['hook'] ?? '' ) ) )
        {
            return 'sync';
        }

        if ( 'real_time' === sanitize_key( (string) ( $mapping['hook'] ?? '' ) ) )
        {
            return 'real_time';
        }

        $default = sanitize_key( (string) ( $template['default_execution_mode'] ?? 'async' ) );
        return in_array( $default, self::EXECUTION_MODES, true ) ? $default : 'async';
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $definition
     */
    private function is_imported_bundled_action( array $action, array $definition ): bool
    {
        if ( 'cps_template_mapping_import' === sanitize_key( (string) ( $definition['source'] ?? '' ) ) )
        {
            return true;
        }

        $code = sanitize_key( (string) ( $action['code'] ?? '' ) );
        return str_starts_with( $code, 'imported_' );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function list_custom_action_mappings(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'sentient_form_mappings';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Repair service scans plugin-owned local-first mappings during upgrade/runtime repair.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id FROM %i WHERE action_kind = %s ORDER BY id ASC',
                $table,
                'custom_action'
            ),
            ARRAY_A
        ) ?: [];

        $mappings = [];
        foreach ( $rows as $row )
        {
            $mapping = $this->form_mappings->get( absint( $row['id'] ?? 0 ) );
            if ( is_array( $mapping ) )
            {
                $mappings[] = $mapping;
            }
        }

        return $mappings;
    }

    private function validate_credential_for_provider( string $provider, array $credential ): array | WP_Error
    {
        if ( $provider !== sanitize_key( (string) ( $credential['provider'] ?? '' ) ) )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_mismatch',
                __( 'Provider credential does not match the action provider.', 'sentient-forms' )
            );
        }

        if ( in_array( sanitize_key( (string) ( $credential['status'] ?? '' ) ), [ 'disabled', 'invalid' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_provider_credential_unavailable',
                __( 'Provider credential is not available for execution.', 'sentient-forms' )
            );
        }

        return $credential;
    }

    /**
     * @param array<string, mixed> $selection
     */
    private function resolve_runtime_model_selection( array $selection ): string
    {
        $primary = isset( $selection['primary'] ) && is_scalar( $selection['primary'] )
            ? trim( sanitize_text_field( (string) $selection['primary'] ) )
            : '';
        if ( '' === $primary )
        {
            return '';
        }

        $is_preset = ! empty( $selection['is_preset'] ) || str_starts_with( $primary, 'sf_' );
        if ( ! $is_preset )
        {
            return $primary;
        }

        $preset_code = sanitize_key( $primary );
        $provider    = isset( $selection['provider'] ) && is_scalar( $selection['provider'] )
            ? sanitize_key( (string) $selection['provider'] )
            : '';

        if ( 'sentient_managed' === $provider )
        {
            return $this->resolve_managed_preset_model_id( $preset_code, $this->selection_requires_managed_zdr( $selection ) );
        }

        return $this->resolve_local_preset_model_id( $preset_code, $this->selection_requires_managed_zdr( $selection ) );
    }

    private function resolve_local_preset_model_id( string $preset_code, bool $require_zdr = false ): string
    {
        $models      = $this->list_local_openrouter_models();
        if ( $require_zdr )
        {
            $models = $this->zdr_eligible_models( $models );
            if ( [] === $models )
            {
                return '';
            }
        }

        $recommended = $this->pick_default_model_id( $models );

        $evidence_model = $this->pick_evidence_model_id( $models, $preset_code );
        if ( null !== $evidence_model )
        {
            return $evidence_model;
        }

        return match ( $preset_code ) {
            'sf_default',
            'sf_general'    => $recommended,
            'sf_quality'    => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5-pro', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.5' ] ) ?: $recommended,
            'sf_free'       => isset( $models['openrouter/free'] )
                ? 'openrouter/free'
                : ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' === (string) ( $model['cost_tier'] ?? '' ) ) ?: $recommended ),
            'sf_structured' => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'nvidia/nemotron-3-super-120b-a12b:free', 'openrouter/free' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( 'structured-output', $model['tags'] ?? [], true ) ) ?: $recommended ),
            'sf_fast'       => $this->pick_preferred_model_id( $models, [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'openai/gpt-5.4', 'openai/gpt-5.4-mini' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => in_array( (string) ( $model['speed_tier'] ?? '' ), [ 'fastest', 'fast' ], true ) ) ?: $recommended ),
            'sf_low_cost'   => $this->pick_preferred_model_id( $models, [ 'deepseek/deepseek-v4-flash', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-pro', 'openai/gpt-5.4-mini' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' !== (string) ( $model['cost_tier'] ?? '' ) && in_array( (string) ( $model['cost_tier'] ?? '' ), [ 'low', 'medium' ], true ) )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => 'free' === (string) ( $model['cost_tier'] ?? '' ) ) ?: $recommended ) ),
            'sf_long_context' => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'anthropic/claude-opus-4.7', 'moonshotai/kimi-k2.6' ] )
                ?: ( $this->pick_long_context_model_id( $models ) ?: $recommended ),
            'sf_reasoning'  => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5-pro', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'z-ai/glm-5.1', 'google/gemini-3.1-pro-preview' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['reasoning'] ) ) ?: $recommended ),
            'sf_code'       => $this->pick_preferred_model_id( $models, [ 'moonshotai/kimi-k2.6', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6', 'qwen/qwen3.6-max-preview', 'openai/gpt-5.5' ] )
                ?: ( $this->pick_first_model_id( $models, static fn ( array $model ): bool => ! empty( $model['capabilities']['code'] ) ) ?: $recommended ),
            'sf_legal'      => $this->pick_preferred_model_id( $models, [ 'google/gemini-3.1-pro-preview', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7' ] ) ?: $recommended,
            'sf_financial'  => $this->pick_preferred_model_id( $models, [ 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.7', 'openai/gpt-5.5' ] ) ?: $recommended,
            'sf_privacy'    => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3.1-pro-preview' ] ) ?: $recommended,
            'sf_realtime'   => $this->pick_preferred_model_id( $models, [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-flash' ] ) ?: $recommended,
            'sf_multimodal' => $this->pick_preferred_model_id( $models, [ 'google/gemini-3.1-pro-preview', 'google/gemini-3-flash-preview', 'openai/gpt-5.5' ] ) ?: $recommended,
            'sf_research'   => $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'google/gemini-3.1-pro-preview' ] ) ?: $recommended,
            'sf_agentic'    => $this->pick_preferred_model_id( $models, [ 'google/gemini-3.1-pro-preview', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7' ] ) ?: $recommended,
            default         => '',
        };
    }

    private function resolve_managed_preset_model_id( string $preset_code, bool $require_zdr = false ): string
    {
        if ( in_array( $preset_code, [ 'sf_default', 'sf_general', 'sf_structured', 'sf_fast', 'sf_realtime' ], true ) )
        {
            return self::MANAGED_DEFAULT_MODEL;
        }

        return $this->resolve_local_preset_model_id( $preset_code, $require_zdr );
    }

    public function resolve_openrouter_preset_model_id( string $preset_code, bool $require_zdr = false ): string
    {
        return $this->resolve_local_preset_model_id( $preset_code, $require_zdr );
    }

    private function selection_requires_managed_zdr( array $selection ): bool
    {
        $provider = isset( $selection['provider'] ) && is_scalar( $selection['provider'] )
            ? sanitize_key( (string) $selection['provider'] )
            : '';

        return 'sentient_managed' === $provider
            && (
                rest_sanitize_boolean( $selection['require_zdr'] ?? false )
                || $this->global_managed_zdr_required()
            );
    }

    private function global_managed_zdr_required(): bool
    {
        $settings = get_option( 'sentient_forms_plugin_settings', [] );
        if ( ! is_array( $settings ) )
        {
            return false;
        }

        return rest_sanitize_boolean( $settings['managed_zdr_required'] ?? false );
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function normalize_runtime_selection_for_resolution( array $selection, string $provider ): array
    {
        if ( ! isset( $selection['provider'] ) || ! is_scalar( $selection['provider'] ) || '' === sanitize_key( (string) $selection['provider'] ) )
        {
            $selection['provider'] = $provider;
        }

        return $this->sanitize_runtime_selection( $selection );
    }

    private function zdr_eligible_models( array $models ): array
    {
        return array_filter(
            $models,
            static fn ( array $model ): bool => true === ( $model['zdr_eligible'] ?? null )
        );
    }

    private function pick_evidence_model_id( array $models, string $preset_code ): ?string
    {
        $evidence = $this->model_preset_evidence();
        if ( ! isset( $evidence[ $preset_code ]['preferred_model_ids'] ) || ! is_array( $evidence[ $preset_code ]['preferred_model_ids'] ) )
        {
            return null;
        }

        return $this->pick_preferred_model_id( $models, $this->sanitize_model_id_list( $evidence[ $preset_code ]['preferred_model_ids'] ) );
    }

    private function model_preset_evidence(): array
    {
        static $preset_evidence = null;

        if ( null !== $preset_evidence )
        {
            return $preset_evidence;
        }

        $evidence_file = __DIR__ . '/../data/model-selector-preset-evidence.php';
        if ( ! file_exists( $evidence_file ) )
        {
            $preset_evidence = [];
            return [];
        }

        $evidence        = require $evidence_file;
        $preset_evidence = is_array( $evidence ) ? $evidence : [];

        return $preset_evidence;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function list_local_openrouter_models(): array
    {
        $rows   = $this->model_cache->list( 'openrouter', true, 1000 );
        $models = [];

        foreach ( $rows as $row )
        {
            $model = $this->format_openrouter_model_info( $row );
            if ( '' !== $model['id'] )
            {
                $models[ $model['id'] ] = $model;
            }
        }

        foreach ( Sentient_Forms_OpenRouter_Model_Recommendations::all() as $model_id => $metadata )
        {
            if ( isset( $models[ $model_id ] ) )
            {
                continue;
            }

            $model = $this->format_openrouter_model_info(
                [
                    'model_id'      => $model_id,
                    'metadata_json' => $metadata,
                ]
            );
            if ( '' !== $model['id'] )
            {
                $model['tags'][] = 'bundled-recommendation';
                $models[ $model['id'] ] = $model;
            }
        }

        uasort(
            $models,
            static function ( array $a, array $b ): int {
                $cost_order = [ 'free' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'premium' => 4, 'unknown' => 5 ];
                $a_cost     = $cost_order[ $a['cost_tier'] ?? 'unknown' ] ?? 5;
                $b_cost     = $cost_order[ $b['cost_tier'] ?? 'unknown' ] ?? 5;

                if ( $a_cost !== $b_cost )
                {
                    return $a_cost <=> $b_cost;
                }

                return strcasecmp( (string) $a['display_name'], (string) $b['display_name'] );
            }
        );

        return $models;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function format_openrouter_model_info( array $row ): array
    {
        $metadata             = is_array( $row['metadata_json'] ?? null ) ? $row['metadata_json'] : [];
        $model_id             = sanitize_text_field( (string) ( $row['model_id'] ?? $metadata['id'] ?? '' ) );
        $name                 = isset( $metadata['name'] ) ? sanitize_text_field( (string) $metadata['name'] ) : $model_id;
        $pricing              = is_array( $metadata['pricing'] ?? null ) ? $metadata['pricing'] : [];
        $architecture         = is_array( $metadata['architecture'] ?? null ) ? $metadata['architecture'] : [];
        $supported_parameters = $this->sanitize_string_list( $metadata['supported_parameters'] ?? [] );
        $input_modalities     = $this->sanitize_string_list( $metadata['input_modalities'] ?? $architecture['input_modalities'] ?? [] );
        $output_modalities    = $this->sanitize_string_list( $metadata['output_modalities'] ?? $architecture['output_modalities'] ?? [] );
        $context_window       = isset( $metadata['context_length'] ) ? absint( $metadata['context_length'] ) : 0;
        $is_free              = ! empty( $metadata['free'] );
        $zdr                  = $this->format_zdr_eligibility( $metadata );

        $capabilities = [
            'reasoning'    => $this->model_has_reasoning( $model_id, $name, $supported_parameters ),
            'code'         => (bool) preg_match( '/code|coder|coding/i', $model_id . ' ' . $name ),
            'vision'       => in_array( 'image', $input_modalities, true ),
            'files'        => in_array( 'file', $input_modalities, true ),
            'audio'        => in_array( 'audio', $input_modalities, true ),
            'video'        => in_array( 'video', $input_modalities, true ),
            'tools'        => (bool) array_intersect( $supported_parameters, [ 'tools', 'tool_choice', 'function_call' ] ),
            'structured'   => (bool) array_intersect( $supported_parameters, [ 'response_format', 'structured_outputs' ] ),
            'web_search'   => array_key_exists( 'web_search', $pricing ) || in_array( 'web_search_options', $supported_parameters, true ),
            'long_context' => $context_window >= 128000,
        ];

        $tags = array_values(
            array_filter(
                [
                    $is_free ? 'free' : null,
                    $capabilities['structured'] ? 'structured-output' : null,
                    $capabilities['tools'] ? 'tools' : null,
                    $capabilities['reasoning'] ? 'reasoning' : null,
                    $capabilities['code'] ? 'code' : null,
                    $capabilities['web_search'] ? 'web-search' : null,
                    $capabilities['vision'] ? 'vision' : null,
                    $capabilities['long_context'] ? 'long-context' : null,
                    true === $zdr['eligible'] ? 'zdr' : null,
                ]
            )
        );

        return [
            'id'             => $model_id,
            'display_name'   => '' !== $name ? $name : $model_id,
            'speed_tier'     => $this->infer_speed_tier( $model_id, $name ),
            'cost_tier'      => $is_free ? 'free' : $this->infer_cost_tier( $pricing ),
            'cost_symbol'    => $is_free ? 'Free' : $this->cost_symbol_for_pricing( $pricing ),
            'capabilities'   => $capabilities,
            'context_window' => $context_window,
            'tags'           => $tags,
            'zdr_eligible'   => $zdr['eligible'],
            'zdr_source'     => $zdr['source'],
            'zdr_checked_at' => $zdr['checked_at'],
            'supported_parameters' => $supported_parameters,
            'input_modalities' => $input_modalities,
            'output_modalities' => $output_modalities,
            'recommended_for' => $this->sanitize_string_label_list( $metadata['recommended_for'] ?? [] ),
            'category_rankings' => $this->sanitize_category_rankings( $metadata['category_rankings'] ?? [] ),
        ];
    }

    private function format_zdr_eligibility( array $metadata ): array
    {
        $eligible = array_key_exists( 'zdr_eligible', $metadata )
            ? rest_sanitize_boolean( $metadata['zdr_eligible'] )
            : null;
        $source = isset( $metadata['zdr_source'] ) && is_scalar( $metadata['zdr_source'] )
            ? sanitize_key( (string) $metadata['zdr_source'] )
            : null;
        $checked_at = isset( $metadata['zdr_checked_at'] ) && is_scalar( $metadata['zdr_checked_at'] )
            ? sanitize_text_field( (string) $metadata['zdr_checked_at'] )
            : null;

        return [
            'eligible'   => $eligible,
            'source'     => '' !== (string) $source ? $source : null,
            'checked_at' => '' !== (string) $checked_at ? $checked_at : null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $models
     */
    private function pick_default_model_id( array $models ): string
    {
        $preferred_default = $this->pick_preferred_model_id( $models, [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3-flash-preview', 'openai/gpt-5.4' ] );
        if ( $preferred_default )
        {
            return $preferred_default;
        }

        $paid_general = $this->pick_first_model_id(
            $models,
            static fn ( array $model ): bool => 'free' !== (string) ( $model['cost_tier'] ?? '' )
                && in_array( 'General purpose', $model['recommended_for'] ?? [], true )
        );
        if ( $paid_general )
        {
            return $paid_general;
        }

        $structured_free = $this->pick_first_model_id(
            $models,
            static fn ( array $model ): bool => 'free' === (string) ( $model['cost_tier'] ?? '' )
                && in_array( 'structured-output', $model['tags'] ?? [], true )
        );

        return $structured_free ?: ( $this->pick_first_model_id( $models, static fn (): bool => true ) ?: 'openrouter/auto' );
    }

    /**
     * @param array<string, array<string, mixed>> $models
     */
    private function pick_first_model_id( array $models, callable $matches ): ?string
    {
        foreach ( $models as $model )
        {
            if ( $matches( $model ) )
            {
                return (string) $model['id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $models
     * @param array<int, string>                  $preferred_model_ids
     */
    private function pick_preferred_model_id( array $models, array $preferred_model_ids ): ?string
    {
        foreach ( $preferred_model_ids as $model_id )
        {
            $latest_alias = $this->latest_alias_for_model_id( $model_id );
            if ( null !== $latest_alias && isset( $models[ $latest_alias ] ) )
            {
                return $latest_alias;
            }

            if ( isset( $models[ $model_id ] ) )
            {
                return $model_id;
            }
        }

        return null;
    }

    private function latest_alias_for_model_id( string $model_id ): ?string
    {
        return match ( $model_id ) {
            'openai/gpt-5.5', 'openai/gpt-5.4' => '~openai/gpt-latest',
            'openai/gpt-5.4-mini'             => '~openai/gpt-mini-latest',
            'google/gemini-3.1-pro-preview'   => '~google/gemini-pro-latest',
            'google/gemini-3-flash-preview'   => '~google/gemini-flash-latest',
            'anthropic/claude-opus-4.6',
            'anthropic/claude-opus-4.7'        => '~anthropic/claude-opus-latest',
            'anthropic/claude-sonnet-4.6'      => '~anthropic/claude-sonnet-latest',
            'anthropic/claude-haiku-4.5'       => '~anthropic/claude-haiku-latest',
            default                            => null,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $models
     */
    private function pick_long_context_model_id( array $models ): ?string
    {
        $winner = null;
        foreach ( $models as $model )
        {
            if ( null === $winner || (int) $model['context_window'] > (int) $winner['context_window'] )
            {
                $winner = $model;
            }
        }

        return is_array( $winner ) ? (string) $winner['id'] : null;
    }

    private function sanitize_reasoning_effort( mixed $value ): string
    {
        $value = sanitize_key( (string) $value );
        return in_array( $value, [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true ) ? $value : '';
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<string, mixed>
     */
    private function sanitize_runtime_selection( array $selection ): array
    {
        $sanitized = [];
        foreach ( [ 'primary', 'backup', 'reasoning', 'provider' ] as $key )
        {
            if ( isset( $selection[ $key ] ) && is_scalar( $selection[ $key ] ) )
            {
                $sanitized[ $key ] = 'provider' === $key
                    ? sanitize_key( (string) $selection[ $key ] )
                    : sanitize_text_field( (string) $selection[ $key ] );
            }
        }
        if ( isset( $selection['credential_id'] ) && is_scalar( $selection['credential_id'] ) )
        {
            $credential_id = absint( $selection['credential_id'] );
            if ( $credential_id > 0 )
            {
                $sanitized['credential_id'] = $credential_id;
            }
        }
        $tools = $this->sanitize_tool_settings( $selection['tools'] ?? null );
        if ( [] !== $tools )
        {
            $sanitized['tools'] = $tools;
        }
        $sanitized['is_preset'] = ! empty( $selection['is_preset'] );
        if ( array_key_exists( 'require_zdr', $selection ) )
        {
            $sanitized['require_zdr'] = rest_sanitize_boolean( $selection['require_zdr'] );
        }

        return $sanitized;
    }

    private function model_has_reasoning( string $model_id, string $name, array $supported_parameters ): bool
    {
        return (bool) array_intersect( $supported_parameters, [ 'reasoning', 'reasoning_effort' ] );
    }

    private function model_supports_reasoning( string $model_id, string $provider ): bool
    {
        $model = $this->list_local_openrouter_models()[ $model_id ] ?? null;
        if ( ! is_array( $model ) )
        {
            return false;
        }

        return ! empty( $model['capabilities']['reasoning'] );
    }

    public function model_supports_structured_output( string $model_id, string $provider ): bool
    {
        if ( 'openrouter' !== sanitize_key( $provider ) )
        {
            return false;
        }

        $model_id = trim( sanitize_text_field( $model_id ) );
        if ( '' === $model_id || 'openrouter/auto' === $model_id || str_starts_with( $model_id, 'sf_' ) )
        {
            return false;
        }

        $model = $this->list_local_openrouter_models()[ $model_id ] ?? null;
        if ( ! is_array( $model ) )
        {
            return false;
        }

        return ! empty( $model['capabilities']['structured'] )
            || in_array( 'structured-output', $model['tags'] ?? [], true );
    }

    /**
     * Determine whether a local OpenRouter model supports a request parameter.
     *
     * @param string $model_id  OpenRouter model ID.
     * @param string $provider  Provider key for the model.
     * @param string $parameter OpenRouter request parameter to check.
     * @return bool True when the model advertises support for the parameter.
     */
    public function model_supports_parameter( string $model_id, string $provider, string $parameter ): bool
    {
        if ( 'openrouter' !== sanitize_key( $provider ) )
        {
            return false;
        }

        $model_id  = trim( sanitize_text_field( $model_id ) );
        $parameter = sanitize_key( $parameter );
        if ( '' === $model_id || '' === $parameter )
        {
            return false;
        }

        $model = $this->list_local_openrouter_models()[ $model_id ] ?? null;
        if ( ! is_array( $model ) )
        {
            return false;
        }

        return in_array( $parameter, $model['supported_parameters'] ?? [], true );
    }

    /**
     * Determine whether local metadata is available for a model's request parameters.
     *
     * @param string $model_id OpenRouter model ID.
     * @param string $provider Provider key for the model.
     * @return bool True when the local catalog/cache has metadata for the model.
     */
    public function has_model_parameter_metadata( string $model_id, string $provider ): bool
    {
        if ( 'openrouter' !== sanitize_key( $provider ) )
        {
            return false;
        }

        $model_id = trim( sanitize_text_field( $model_id ) );
        if ( '' === $model_id )
        {
            return false;
        }

        return is_array( $this->list_local_openrouter_models()[ $model_id ] ?? null );
    }

    private function infer_speed_tier( string $model_id, string $name ): string
    {
        return preg_match( '/flash|mini|lite|fast|turbo|gpt-oss/i', $model_id . ' ' . $name )
            ? 'fast'
            : 'balanced';
    }

    private function cost_symbol_for_pricing( array $pricing ): string
    {
        if ( [] === $pricing )
        {
            return 'N/A';
        }

        $prompt     = isset( $pricing['prompt'] ) ? (float) $pricing['prompt'] : 0.0;
        $completion = isset( $pricing['completion'] ) ? (float) $pricing['completion'] : 0.0;
        $max        = max( $prompt, $completion );

        if ( $prompt < 0 || $completion < 0 )
        {
            return 'Varies';
        }

        if ( 0.0 === $max )
        {
            return 'Free';
        }

        if ( $max <= 0.000001 )
        {
            return '$';
        }

        if ( $max <= 0.00001 )
        {
            return '$$';
        }

        if ( $max <= 0.00005 )
        {
            return '$$$';
        }

        return '$$$$';
    }

    /**
     * @param array<string, mixed> $pricing
     */
    private function infer_cost_tier( array $pricing ): string
    {
        if ( [] === $pricing )
        {
            return 'unknown';
        }

        $prompt     = isset( $pricing['prompt'] ) ? (float) $pricing['prompt'] : 0.0;
        $completion = isset( $pricing['completion'] ) ? (float) $pricing['completion'] : 0.0;
        $max        = max( $prompt, $completion );

        if ( $prompt < 0 || $completion < 0 )
        {
            return 'unknown';
        }

        if ( 0.0 === $max )
        {
            return 'free';
        }

        if ( $max <= 0.000001 )
        {
            return 'low';
        }

        if ( $max <= 0.00001 )
        {
            return 'medium';
        }

        if ( $max <= 0.00005 )
        {
            return 'high';
        }

        return 'premium';
    }

    private function sanitize_string_label_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $labels = [];
        foreach ( $value as $item )
        {
            if ( is_scalar( $item ) )
            {
                $label = sanitize_text_field( (string) $item );
                if ( '' !== $label )
                {
                    $labels[] = $label;
                }
            }
        }

        return array_values( array_unique( $labels ) );
    }

    private function sanitize_category_rankings( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $rankings = [];
        foreach ( $value as $category => $rank )
        {
            $category_key = sanitize_key( (string) $category );
            $rank_value   = absint( $rank );
            if ( '' !== $category_key && $rank_value > 0 )
            {
                $rankings[ $category_key ] = $rank_value;
            }
        }

        return $rankings;
    }

    private function sanitize_tool_settings( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $settings = [];
        foreach ( [ 'web_search', 'web_fetch', 'datetime' ] as $tool_key )
        {
            if ( ! is_array( $value[ $tool_key ] ?? null ) )
            {
                continue;
            }

            $mode = sanitize_key( (string) ( $value[ $tool_key ]['mode'] ?? 'inherit' ) );
            if ( ! in_array( $mode, [ 'inherit', 'off', 'auto', 'required' ], true ) )
            {
                $mode = 'inherit';
            }

            if ( 'inherit' === $mode )
            {
                continue;
            }

            $settings[ $tool_key ] = [ 'mode' => $mode ];

            if ( 'web_search' === $tool_key )
            {
                $max_results = absint( $value[ $tool_key ]['max_results'] ?? 0 );
                if ( $max_results > 0 )
                {
                    $settings[ $tool_key ]['max_results'] = min( 10, $max_results );
                }
            }
        }

        $tool_choice = sanitize_key( (string) ( $value['tool_choice'] ?? 'inherit' ) );
        if ( in_array( $tool_choice, [ 'off', 'auto', 'required' ], true ) )
        {
            $settings['tool_choice'] = $tool_choice;
        }

        return $settings;
    }

    private function sanitize_string_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        return array_values( array_filter( array_map( 'sanitize_key', $value ) ) );
    }

    private function sanitize_model_id_list( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $model_ids = [];
        foreach ( $value as $item )
        {
            if ( ! is_scalar( $item ) )
            {
                continue;
            }

            $model_id = sanitize_text_field( (string) $item );
            if ( '' !== $model_id )
            {
                $model_ids[] = $model_id;
            }
        }

        return array_values( array_unique( $model_ids ) );
    }
}
