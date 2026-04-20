<?php
/**
 * Local-first cutover readiness and approved reset service.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cutover reports and approved resets must inspect and clear plugin-owned custom tables/options directly so the operator sees current state and reset counts. SQL is prepared or uses escaped table names at each call site.
class Sentient_Forms_Local_Cutover_Service
{
    public const CONFIRMATION_PHRASE = 'RESET LOCAL-FIRST CUTOVER';

    private const SOURCE = 'local_first_cutover';

    private const RESET_TABLE_SUFFIXES = [
        'sentient_action_templates',
        'sentient_custom_actions',
        'sentient_form_mappings',
        'sentient_execution_events',
        'sentient_async_requests',
    ];

    private const PRESERVED_TABLE_SUFFIXES = [
        'sentient_provider_credentials',
        'sentient_external_service_consents',
        'sentient_migration_runs',
        'sentient_model_cache',
    ];

    private const LEGACY_EXACT_OPTIONS_TO_REPORT = [
        'sentient_forms_settings',
        'sentient_forms_license_status',
        'sentient_forms_site_id',
        'sentient_forms_action_log',
        'sentient_forms_proxy_api_key',
        '_transient_sentient_forms_cps_version',
        '_transient_timeout_sentient_forms_cps_version',
    ];

    private const LEGACY_EXACT_OPTIONS_TO_RESET = [
        'sentient_forms_action_log',
        'sentient_forms_proxy_api_key',
        '_transient_sentient_forms_cps_version',
        '_transient_timeout_sentient_forms_cps_version',
    ];

    private const LEGACY_OPTION_PREFIXES_TO_REPORT = [
        'sentient_forms_actions_',
        'sentient_forms_action_defaults_',
    ];

    private const LEGACY_OPTION_PREFIXES_TO_RESET = [
        'sentient_forms_actions_',
        'sentient_forms_action_defaults_',
    ];

    private wpdb $wpdb;
    private Sentient_Forms_Migration_Runs_Repository $migration_runs;

    public function __construct( ?wpdb $database = null, ?Sentient_Forms_Migration_Runs_Repository $migration_runs = null )
    {
        global $wpdb;

        $this->wpdb           = $database ?? $wpdb;
        $this->migration_runs = $migration_runs ?? new Sentient_Forms_Migration_Runs_Repository( $this->wpdb );
    }

    /**
     * Build a non-mutating report for the current local-first cutover state.
     *
     * @return array<string, mixed>
     */
    public function build_readiness_report(): array
    {
        $local_tables   = $this->table_counts( Sentient_Forms_Local_Data_Governance::local_table_suffixes() );
        $runtime_tables = $this->table_counts( [ 'sentient_async_requests' ] );
        $legacy_options = $this->legacy_option_report();
        $settings       = $this->settings_summary();
        $warnings       = $this->build_warnings( $local_tables, $runtime_tables, $legacy_options, $settings );

        return [
            'generated_at'              => gmdate( 'c' ),
            'source'                    => self::SOURCE,
            'source_version'            => defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : null,
            'confirmation_phrase'       => self::CONFIRMATION_PHRASE,
            'ready_for_reset'           => true,
            'ready_for_local_execution' => (int) ( $local_tables['sentient_provider_credentials'] ?? 0 ) > 0,
            'local_tables'              => $local_tables,
            'runtime_tables'            => $runtime_tables,
            'legacy_options'            => $legacy_options,
            'settings'                  => $settings,
            'reset_plan'                => [
                'tables_cleared'              => self::RESET_TABLE_SUFFIXES,
                'tables_preserved_by_default' => self::PRESERVED_TABLE_SUFFIXES,
                'exact_options_deleted'       => self::LEGACY_EXACT_OPTIONS_TO_RESET,
                'option_prefixes_deleted'     => self::LEGACY_OPTION_PREFIXES_TO_RESET,
                'settings_preserved'          => [
                    'sentient_forms_settings',
                    'sentient_forms_plugin_settings',
                    'license',
                    'telemetry',
                    'execution_controls',
                ],
            ],
            'warnings'                  => $warnings,
        ];
    }

    /**
     * Record an auditable dry-run migration report without changing runtime data.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function record_dry_run( ?int $actor_user_id = null ): array | WP_Error
    {
        $report = $this->build_readiness_report();
        $run_id = $this->migration_runs->create(
            [
                'source'         => self::SOURCE,
                'source_version' => defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : null,
                'status'         => 'dry_run',
                'dry_run'        => true,
                'summary_json'   => $this->summarize_report( $report ),
                'conflicts_json' => $report['warnings'],
                'mapping_json'   => $report['reset_plan'],
                'actor_user_id'  => $actor_user_id,
            ]
        );

        if ( is_wp_error( $run_id ) )
        {
            return $run_id;
        }

        $finished = $this->migration_runs->mark_finished(
            $run_id,
            'dry_run_complete',
            array_merge(
                $this->summarize_report( $report ),
                [
                    'run_id' => $run_id,
                ]
            )
        );

        if ( is_wp_error( $finished ) )
        {
            return $finished;
        }

        return [
            'run_id' => $run_id,
            'status' => 'dry_run_complete',
            'report' => $report,
        ];
    }

    /**
     * Run the approved cutover reset after the operator enters the confirmation phrase.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function approved_reset( string $confirmation_phrase, ?int $actor_user_id = null ): array | WP_Error
    {
        if ( self::CONFIRMATION_PHRASE !== trim( $confirmation_phrase ) )
        {
            return new WP_Error(
                'sentient_forms_cutover_confirmation_required',
                __( 'Enter the exact confirmation phrase before running the local-first cutover reset.', 'sentient-forms' ),
                [
                    'status'              => 400,
                    'confirmation_phrase' => self::CONFIRMATION_PHRASE,
                ]
            );
        }

        $before = $this->build_readiness_report();
        $run_id = $this->migration_runs->create(
            [
                'source'         => self::SOURCE,
                'source_version' => defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : null,
                'status'         => 'running',
                'dry_run'        => false,
                'summary_json'   => [
                    'before' => $this->summarize_report( $before ),
                ],
                'conflicts_json' => $before['warnings'],
                'mapping_json'   => $before['reset_plan'],
                'actor_user_id'  => $actor_user_id,
            ]
        );

        if ( is_wp_error( $run_id ) )
        {
            return $run_id;
        }

        $deleted_tables = $this->delete_table_rows( self::RESET_TABLE_SUFFIXES );
        if ( is_wp_error( $deleted_tables ) )
        {
            $this->migration_runs->mark_finished(
                $run_id,
                'failed',
                [
                    'error_code'    => $deleted_tables->get_error_code(),
                    'error_message' => $deleted_tables->get_error_message(),
                    'before'        => $this->summarize_report( $before ),
                ]
            );
            return $deleted_tables;
        }

        $deleted_options = $this->delete_legacy_options();
        $after           = $this->build_readiness_report();
        $summary         = [
            'before'          => $this->summarize_report( $before ),
            'after'           => $this->summarize_report( $after ),
            'deleted_tables'  => $deleted_tables,
            'deleted_options' => $deleted_options,
            'preserved'       => self::PRESERVED_TABLE_SUFFIXES,
        ];

        $finished = $this->migration_runs->mark_finished( $run_id, 'completed', $summary );
        if ( is_wp_error( $finished ) )
        {
            return $finished;
        }

        return [
            'run_id'          => $run_id,
            'status'          => 'completed',
            'before'          => $before,
            'after'           => $after,
            'deleted_tables'  => $deleted_tables,
            'deleted_options' => $deleted_options,
            'preserved'       => self::PRESERVED_TABLE_SUFFIXES,
        ];
    }

    /**
     * @param array<int, string> $suffixes
     * @return array<string, int|null>
     */
    private function table_counts( array $suffixes ): array
    {
        $counts = [];
        foreach ( $suffixes as $suffix )
        {
            $table_name = $this->wpdb->prefix . $suffix;
            if ( ! $this->table_exists( $table_name ) )
            {
                $counts[ $suffix ] = null;
                continue;
            }

            $counts[ $suffix ] = (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $table_name ) );
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function legacy_option_report(): array
    {
        return [
            'exact_options'   => $this->exact_option_report(),
            'option_prefixes' => $this->prefix_option_report(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function exact_option_report(): array
    {
        $report = [];
        foreach ( self::LEGACY_EXACT_OPTIONS_TO_REPORT as $option_name )
        {
            $value = get_option( $option_name, null );
            $report[ $option_name ] = [
                'exists'       => null !== $value,
                'will_delete'  => in_array( $option_name, self::LEGACY_EXACT_OPTIONS_TO_RESET, true ),
                'value_shape'  => $this->describe_value_shape( $value ),
                'value_length' => is_string( $value ) ? strlen( $value ) : null,
            ];
        }

        return $report;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function prefix_option_report(): array
    {
        $report = [];
        foreach ( self::LEGACY_OPTION_PREFIXES_TO_REPORT as $prefix )
        {
            $option_names = $this->option_names_for_prefix( $prefix );
            $report[ $prefix ] = [
                'count'       => count( $option_names ),
                'sample'      => array_slice( $option_names, 0, 10 ),
                'will_delete' => in_array( $prefix, self::LEGACY_OPTION_PREFIXES_TO_RESET, true ),
            ];
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings_summary(): array
    {
        $legacy_options = get_option( 'sentient_forms_settings', [] );
        $legacy_options = is_array( $legacy_options ) ? $legacy_options : [];

        $plugin_settings = get_option( 'sentient_forms_plugin_settings', [] );
        $plugin_settings = is_array( $plugin_settings ) ? $plugin_settings : [];

        $license = isset( $legacy_options['license'] ) && is_array( $legacy_options['license'] )
            ? $legacy_options['license']
            : [];

        foreach ( [ 'license_key', 'license_status', 'proxy_api_key', 'site_id', 'license_id' ] as $legacy_license_key )
        {
            if ( isset( $legacy_options[ $legacy_license_key ] ) )
            {
                $license[ $legacy_license_key ] = $legacy_options[ $legacy_license_key ];
            }
        }

        $execution_provider_disabled = isset( $plugin_settings['execution_provider_disabled'] ) && is_array( $plugin_settings['execution_provider_disabled'] )
            ? $plugin_settings['execution_provider_disabled']
            : [];

        return [
            'option_present'                            => [] !== $legacy_options || [] !== $plugin_settings,
            'legacy_option_present'                     => [] !== $legacy_options,
            'plugin_settings_option_present'            => [] !== $plugin_settings,
            'top_level_legacy_license_fields_present'   => array_values(
                array_filter(
                    [ 'license_key', 'license_status', 'proxy_api_key' ],
                    static fn ( string $key ): bool => array_key_exists( $key, $legacy_options )
                )
            ),
            'license_key_present'                       => ! empty( $license['license_key'] ),
            'proxy_key_present'                         => ! empty( $license['proxy_api_key'] ),
            'site_id_present'                           => ! empty( $license['site_id'] ),
            'telemetry_opt_in'                          => ! empty( $legacy_options['telemetry']['telemetry_opt_in'] ),
            'execution_global_disabled'                 => ! empty( $plugin_settings['execution_global_disabled'] ),
            'execution_provider_disabled_count'         => count( array_filter( $execution_provider_disabled ) ),
            'cps_endpoint_override_present'             => ! empty( $legacy_options['cps_base_url'] ) || ! empty( $legacy_options['api_base_url'] ),
            'enforce_nonce_verification_configured'     => array_key_exists( 'enforce_nonce_verification', $legacy_options ),
        ];
    }

    /**
     * @param array<string, int|null> $local_tables
     * @param array<string, int|null> $runtime_tables
     * @param array<string, mixed>    $legacy_options
     * @param array<string, mixed>    $settings
     * @return array<int, array<string, string>>
     */
    private function build_warnings( array $local_tables, array $runtime_tables, array $legacy_options, array $settings ): array
    {
        $warnings = [];

        $missing_tables = array_keys(
            array_filter(
                array_merge( $local_tables, $runtime_tables ),
                static fn ( ?int $count ): bool => null === $count
            )
        );

        if ( [] !== $missing_tables )
        {
            $warnings[] = [
                'code'    => 'missing_tables',
                'message' => sprintf(
                    /* translators: %s: comma-separated table suffix list. */
                    __( 'Local-first tables are missing and should be created by the installer before reset: %s.', 'sentient-forms' ),
                    implode( ', ', $missing_tables )
                ),
            ];
        }

        $reset_rows = 0;
        foreach ( self::RESET_TABLE_SUFFIXES as $suffix )
        {
            $reset_rows += (int) ( $local_tables[ $suffix ] ?? $runtime_tables[ $suffix ] ?? 0 );
        }

        if ( $reset_rows > 0 )
        {
            $warnings[] = [
                'code'    => 'local_runtime_data_will_be_removed',
                'message' => __( 'Approved reset will remove local action templates, custom actions, mappings, execution events, and queued runtime metadata.', 'sentient-forms' ),
            ];
        }

        if ( $this->legacy_option_count( $legacy_options ) > 0 )
        {
            $warnings[] = [
                'code'    => 'legacy_options_will_be_removed',
                'message' => __( 'Approved reset will delete legacy option-backed form action mappings, action defaults, action logs, and stale CPS transients.', 'sentient-forms' ),
            ];
        }

        if ( (int) ( $local_tables['sentient_provider_credentials'] ?? 0 ) <= 0 )
        {
            $warnings[] = [
                'code'    => 'provider_setup_required',
                'message' => __( 'No local provider credential is stored yet; OpenRouter or managed execution setup is still required before live submissions can execute.', 'sentient-forms' ),
            ];
        }

        if ( ! empty( $settings['proxy_key_present'] ) )
        {
            $warnings[] = [
                'code'    => 'managed_metering_still_configured',
                'message' => __( 'A managed proxy key is still configured and will be preserved for Option 2 metering or managed execution.', 'sentient-forms' ),
            ];
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed> $legacy_options
     */
    private function legacy_option_count( array $legacy_options ): int
    {
        $count = 0;

        foreach ( $legacy_options['exact_options'] ?? [] as $option )
        {
            if ( ! empty( $option['exists'] ) && ! empty( $option['will_delete'] ) )
            {
                ++$count;
            }
        }

        foreach ( $legacy_options['option_prefixes'] ?? [] as $prefix )
        {
            if ( ! empty( $prefix['will_delete'] ) )
            {
                $count += (int) ( $prefix['count'] ?? 0 );
            }
        }

        return $count;
    }

    /**
     * @param array<int, string> $suffixes
     * @return array<string, int|null>|WP_Error
     */
    private function delete_table_rows( array $suffixes ): array | WP_Error
    {
        $deleted = [];
        foreach ( $suffixes as $suffix )
        {
            $table_name = $this->wpdb->prefix . $suffix;
            if ( ! $this->table_exists( $table_name ) )
            {
                $deleted[ $suffix ] = null;
                continue;
            }

            $result = $this->wpdb->query( 'DELETE FROM ' . esc_sql( $table_name ) );
            if ( false === $result )
            {
                return new WP_Error(
                    'sentient_forms_cutover_table_delete_failed',
                    sprintf(
                        /* translators: %s: table suffix. */
                        __( 'Could not clear table %s during local-first cutover reset.', 'sentient-forms' ),
                        $suffix
                    ),
                    [ 'status' => 500 ]
                );
            }

            $deleted[ $suffix ] = (int) $result;
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>
     */
    private function delete_legacy_options(): array
    {
        $deleted = [
            'exact_options'   => [],
            'option_prefixes' => [],
        ];

        foreach ( self::LEGACY_EXACT_OPTIONS_TO_RESET as $option_name )
        {
            $existed = null !== get_option( $option_name, null );
            delete_option( $option_name );
            $deleted['exact_options'][ $option_name ] = $existed;
        }

        foreach ( self::LEGACY_OPTION_PREFIXES_TO_RESET as $prefix )
        {
            $option_names = $this->option_names_for_prefix( $prefix, 5000 );
            foreach ( $option_names as $option_name )
            {
                delete_option( $option_name );
            }

            $deleted['option_prefixes'][ $prefix ] = [
                'count'  => count( $option_names ),
                'sample' => array_slice( $option_names, 0, 10 ),
            ];
        }

        return $deleted;
    }

    /**
     * @return array<int, string>
     */
    private function option_names_for_prefix( string $prefix, int $limit = 100 ): array
    {
        $limit         = max( 1, min( 5000, $limit ) );
        $options_table = esc_sql( $this->wpdb->options );
        $query         = $this->wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The only interpolated value is the escaped core options table name; option prefix and limit are prepared placeholders.
            "SELECT option_name FROM {$options_table} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT %d",
            $this->wpdb->esc_like( $prefix ) . '%',
            $limit
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above; the interpolated table name is the escaped core options table.
        $rows          = $this->wpdb->get_col( $query );

        return array_values( array_map( 'strval', is_array( $rows ) ? $rows : [] ) );
    }

    private function table_exists( string $table_name ): bool
    {
        $query = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above for a dynamic plugin-owned table existence check.
        return $table_name === $this->wpdb->get_var( $query );
    }

    private function describe_value_shape( mixed $value ): string
    {
        if ( null === $value )
        {
            return 'missing';
        }

        if ( is_array( $value ) )
        {
            return 'array';
        }

        if ( is_bool( $value ) )
        {
            return 'boolean';
        }

        if ( is_numeric( $value ) )
        {
            return 'numeric';
        }

        return gettype( $value );
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function summarize_report( array $report ): array
    {
        return [
            'generated_at'              => $report['generated_at'] ?? null,
            'ready_for_local_execution' => $report['ready_for_local_execution'] ?? false,
            'local_tables'              => $report['local_tables'] ?? [],
            'runtime_tables'            => $report['runtime_tables'] ?? [],
            'legacy_option_count'       => $this->legacy_option_count( $report['legacy_options'] ?? [] ),
            'warning_codes'             => array_values(
                array_map(
                    static fn ( array $warning ): string => (string) ( $warning['code'] ?? '' ),
                    is_array( $report['warnings'] ?? null ) ? $report['warnings'] : []
                )
            ),
        ];
    }
}
