<?php
/**
 * Redacted local-first support bundle service.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Support diagnostics read live counts from plugin-owned custom tables and intentionally avoid caching stale diagnostic state. SQL is prepared or uses escaped table names at each call site.
class Sentient_Forms_Local_Support_Bundle_Service
{
    public function __construct( private ?wpdb $wpdb = null )
    {
        global $wpdb;

        $this->wpdb = $this->wpdb ?? $wpdb;
    }

    /**
     * Build a diagnostic bundle that intentionally excludes secrets and result payloads.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $bundle = [
            'generated_at'       => gmdate( 'c' ),
            'plugin'             => [
                'version'    => defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : null,
                'db_version' => get_option( 'sentient_forms_db_version', null ),
            ],
            'wordpress'          => [
                'version'     => get_bloginfo( 'version' ),
                'multisite'   => is_multisite(),
                'php_version' => PHP_VERSION,
            ],
            'local_tables'       => $this->table_counts(),
            'providers'          => $this->provider_summaries(),
            'external_consents'  => $this->consent_summaries(),
            'execution_summary'  => $this->execution_summary(),
            'retention'          => [
                'event_retention_days'    => Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days(),
                'delete_data_on_uninstall' => Sentient_Forms_Local_Data_Governance::delete_data_on_uninstall_enabled(),
                'store_full_ai_outputs'   => Sentient_Forms_Local_Data_Governance::store_full_ai_outputs_enabled(),
                'privacy_setup_profile'   => Sentient_Forms_Local_Data_Governance::current_privacy_setup_profile(),
                'privacy_setup_completed_at' => Sentient_Forms_Local_Data_Governance::privacy_setup_completed_at(),
            ],
        ];

        return self::redact( $bundle );
    }

    /**
     * Recursively redact secret-like fields from diagnostic output.
     */
    public static function redact( mixed $value ): mixed
    {
        if ( is_string( $value ) )
        {
            return self::redact_secret_patterns( $value );
        }

        if ( ! is_array( $value ) )
        {
            return $value;
        }

        $redacted = [];
        foreach ( $value as $key => $item )
        {
            $key_string = is_string( $key ) ? strtolower( $key ) : '';
            if ( preg_match( '/(secret|api[_-]?key|token|password|authorization|encrypted)/', $key_string ) )
            {
                $redacted[ $key ] = '[redacted]';
                continue;
            }

            $redacted[ $key ] = self::redact( $item );
        }

        return $redacted;
    }

    private static function redact_secret_patterns( string $value ): string
    {
        $redacted = preg_replace( '/sk-or-[A-Za-z0-9._:-]{4,}/', 'sk-or-[redacted]', $value );

        return is_string( $redacted ) ? $redacted : $value;
    }

    /**
     * @return array<string, int|null>
     */
    private function table_counts(): array
    {
        $counts = [];
        foreach ( Sentient_Forms_Local_Data_Governance::local_table_suffixes() as $suffix )
        {
            $wpdb = $this->wpdb;
            $table_name = $wpdb->prefix . $suffix;
            if ( $table_name !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) )
            {
                $counts[ $suffix ] = null;
                continue;
            }

            $counts[ $suffix ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $table_name ) );
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function provider_summaries(): array
    {
        $repository = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $rows       = $repository->list( [ 'limit' => 50 ] );

        return array_map(
            static fn ( array $row ): array => [
                'id'                => (int) ( $row['id'] ?? 0 ),
                'provider'          => $row['provider'] ?? null,
                'label'             => $row['label'] ?? null,
                'auth_mode'         => $row['auth_mode'] ?? null,
                'constant_name'     => $row['constant_name'] ?? null,
                'status'            => $row['status'] ?? null,
                'last_validated_at' => $row['last_validated_at'] ?? null,
                'updated_at'        => $row['updated_at'] ?? null,
            ],
            $rows
        );
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    private function consent_summaries(): array
    {
        $repository = new Sentient_Forms_External_Service_Consent_Repository( $this->wpdb );
        $providers  = [ 'openrouter', 'sentient_managed' ];
        $summary    = [];

        foreach ( $providers as $provider )
        {
            $row = $repository->latest_for_provider( $provider );
            $summary[ $provider ] = is_array( $row )
                ? [
                    'disclosure_version' => $row['disclosure_version'] ?? null,
                    'accepted_at'        => $row['accepted_at'] ?? null,
                    'accepted_by_user_id'=> isset( $row['accepted_by_user_id'] ) ? (int) $row['accepted_by_user_id'] : null,
                ]
                : null;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function execution_summary(): array
    {
        $events = new Sentient_Forms_Execution_Events_Repository( $this->wpdb );
        $recent = array_map(
            static fn ( array $row ): array => [
                'id'                   => (int) ( $row['id'] ?? 0 ),
                'execution_request_id' => $row['execution_request_id'] ?? null,
                'mapping_id'           => isset( $row['mapping_id'] ) ? (int) $row['mapping_id'] : null,
                'form_source'          => $row['form_source'] ?? null,
                'form_id'              => $row['form_id'] ?? null,
                'entry_id'             => $row['entry_id'] ?? null,
                'provider'             => $row['provider'] ?? null,
                'model'                => $row['model'] ?? null,
                'status'               => $row['status'] ?? null,
                'error_code'           => $row['error_code'] ?? null,
                'has_error_message'    => ! empty( $row['error_message'] ),
                'has_result'           => ! empty( $row['result_json'] ),
                'created_at'           => $row['created_at'] ?? null,
                'updated_at'           => $row['updated_at'] ?? null,
                'expires_at'           => $row['expires_at'] ?? null,
            ],
            $events->list_recent( 20 )
        );

        return [
            'recent' => $recent,
        ];
    }
}
