<?php
/**
 * Local execution event repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first execution events live in a plugin-owned custom table and are mutable audit/runtime rows. WordPress core has no native CRUD/cache API for these records; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Execution_Events_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_execution_events';
    }

    public function record( array $data ): int | WP_Error
    {
        $execution_request_id = sanitize_text_field( (string) ( $data['execution_request_id'] ?? '' ) );
        if ( '' === $execution_request_id )
        {
            return new WP_Error( 'sentient_forms_missing_execution_request_id', __( 'Execution request ID is required.', 'sentient-forms' ) );
        }

        $provider = sanitize_key( (string) ( $data['provider'] ?? 'openrouter' ) );
        if ( class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) && Sentient_Forms_Managed_Usage_Sanitizer::is_managed_provider( $provider ) )
        {
            if ( isset( $data['cost_json'] ) && is_array( $data['cost_json'] ) )
            {
                $data['cost_json'] = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $data['cost_json'] );
            }

            if ( isset( $data['result_json'] ) && is_array( $data['result_json'] ) )
            {
                $data['result_json'] = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $data['result_json'] );
            }
        }

        $token_usage_json = $this->encode_json_field( $data['token_usage_json'] ?? null, 'token_usage_json' );
        if ( is_wp_error( $token_usage_json ) )
        {
            return $token_usage_json;
        }

        $cost_json = $this->encode_json_field( $data['cost_json'] ?? null, 'cost_json' );
        if ( is_wp_error( $cost_json ) )
        {
            return $cost_json;
        }

        $result_json = $this->encode_json_field( $data['result_json'] ?? null, 'result_json' );
        if ( is_wp_error( $result_json ) )
        {
            return $result_json;
        }

        $now = $this->now();
        $row = [
            'execution_request_id' => $execution_request_id,
            'mapping_id'           => isset( $data['mapping_id'] ) ? (int) $data['mapping_id'] : null,
            'form_source'          => isset( $data['form_source'] ) ? sanitize_key( (string) $data['form_source'] ) : null,
            'form_id'              => isset( $data['form_id'] ) ? sanitize_text_field( (string) $data['form_id'] ) : null,
            'entry_id'             => isset( $data['entry_id'] ) ? sanitize_text_field( (string) $data['entry_id'] ) : null,
            'submission_uuid'      => isset( $data['submission_uuid'] ) ? sanitize_text_field( (string) $data['submission_uuid'] ) : null,
            'provider'             => $provider,
            'model'                => isset( $data['model'] ) ? sanitize_text_field( (string) $data['model'] ) : null,
            'status'               => sanitize_key( (string) ( $data['status'] ?? 'queued' ) ),
            'token_usage_json'     => $token_usage_json,
            'cost_json'            => $cost_json,
            'result_json'          => $result_json,
            'error_code'           => isset( $data['error_code'] ) ? sanitize_key( (string) $data['error_code'] ) : null,
            'error_message'        => isset( $data['error_message'] ) ? sanitize_textarea_field( (string) $data['error_message'] ) : null,
            'payload_digest'       => isset( $data['payload_digest'] ) ? sanitize_text_field( (string) $data['payload_digest'] ) : null,
            'created_at'           => $now,
            'updated_at'           => $now,
            'expires_at'           => $this->resolve_expires_at( $data ),
        ];

        $existing = $this->get_by_request_id( $execution_request_id );
        if ( $existing )
        {
            unset( $row['created_at'] );
            $updated = $this->wpdb->update(
                $this->table_name(),
                $row,
                [ 'execution_request_id' => $execution_request_id ],
                [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%s' ]
            );

            if ( false === $updated )
            {
                return new WP_Error( 'sentient_forms_db_update_failed', __( 'Execution event could not be updated.', 'sentient-forms' ) );
            }

            return (int) $existing['id'];
        }

        $inserted = $this->wpdb->insert(
            $this->table_name(),
            $row,
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Execution event could not be recorded.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function get_by_request_id( string $execution_request_id ): ?array
    {
        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE execution_request_id = %s',
                $this->table_name(),
                $execution_request_id
            ),
            ARRAY_A
        );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function get_by_id( int $id ): ?array
    {
        $id = absint( $id );
        if ( $id <= 0 )
        {
            return null;
        }

        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE id = %d',
                $this->table_name(),
                $id
            ),
            ARRAY_A
        );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function list_recent( int $limit = 50 ): array
    {
        $wpdb = $this->wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d',
                $this->table_name(),
                max( 1, min( 100, $limit ) )
            ),
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function list_recent_for_action_log( int $limit = 500 ): array
    {
        $wpdb = $this->wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d',
                $this->table_name(),
                max( 1, min( 500, $limit ) )
            ),
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function list_for_action_log( array $filters = [], int $limit = 20, int $offset = 0 ): array
    {
        $wpdb = $this->wpdb;
        $filter_values = $this->action_log_filter_values( $filters );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM %i WHERE (%d = 0 OR form_id = %s)
                    AND (
                        %s = ''
                        OR (%s = 'success' AND status IN ('succeeded', 'success'))
                        OR (%s = 'error' AND status IN ('failed', 'error'))
                        OR (%s = 'blocked' AND status IN ('blocked', 'skipped'))
                        OR (%s = 'pending' AND status IN ('queued', 'running', 'pending'))
                    )
                    AND (%s = '' OR created_at >= %s)
                    AND (%s = '' OR created_at <= %s)
                    ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                $this->table_name(),
                $filter_values['form_id'],
                $filter_values['form_id_text'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['date_from'],
                $filter_values['date_from'],
                $filter_values['date_to'],
                $filter_values['date_to'],
                max( 1, min( 500, $limit ) ),
                max( 0, $offset )
            ),
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function count_for_action_log( array $filters = [] ): int
    {
        $wpdb = $this->wpdb;
        $filter_values = $this->action_log_filter_values( $filters );

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE (%d = 0 OR form_id = %s)
                    AND (
                        %s = ''
                        OR (%s = 'success' AND status IN ('succeeded', 'success'))
                        OR (%s = 'error' AND status IN ('failed', 'error'))
                        OR (%s = 'blocked' AND status IN ('blocked', 'skipped'))
                        OR (%s = 'pending' AND status IN ('queued', 'running', 'pending'))
                    )
                    AND (%s = '' OR created_at >= %s)
                    AND (%s = '' OR created_at <= %s)",
                $this->table_name(),
                $filter_values['form_id'],
                $filter_values['form_id_text'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['status'],
                $filter_values['date_from'],
                $filter_values['date_from'],
                $filter_values['date_to'],
                $filter_values['date_to']
            )
        );
    }

    public function get_latest_for_form( string $form_source, int $form_id ): ?array
    {
        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s ORDER BY created_at DESC, id DESC LIMIT 1',
                $this->table_name(),
                sanitize_key( $form_source ),
                (string) $form_id
            ),
            ARRAY_A
        );

        return $row ? $this->decode_row( $row ) : null;
    }

    public function get_latest_for_entry( string $form_source, mixed $form_id, mixed $entry_id, ?string $submission_uuid = null ): ?array
    {
        $form_source     = sanitize_key( $form_source );
        $form_id         = sanitize_text_field( (string) $form_id );
        $entry_id        = is_scalar( $entry_id ) ? sanitize_text_field( (string) $entry_id ) : '';
        $submission_uuid = null !== $submission_uuid ? sanitize_text_field( $submission_uuid ) : '';
        if ( '' === $form_source || '' === $form_id )
        {
            return null;
        }

        $wpdb = $this->wpdb;

        if ( '' !== $submission_uuid )
        {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_source = %s AND form_id = %s AND submission_uuid = %s ORDER BY created_at DESC, id DESC LIMIT 1',
                    $this->table_name(),
                    $form_source,
                    $form_id,
                    $submission_uuid
                ),
                ARRAY_A
            );

            if ( is_array( $row ) )
            {
                return $this->decode_row( $row );
            }
        }

        if ( '' === $entry_id )
        {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s AND entry_id = %s ORDER BY created_at DESC, id DESC LIMIT 1',
                $this->table_name(),
                $form_source,
                $form_id,
                $entry_id
            ),
            ARRAY_A
        );

        return $row ? $this->decode_row( $row ) : null;
    }

    /**
     * @param array<int, int|string> $form_ids Form IDs to load.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_latest_for_forms( string $form_source, array $form_ids ): array
    {
        $normalized_ids = [];
        foreach ( $form_ids as $form_id )
        {
            $normalized_id = sanitize_text_field( (string) $form_id );
            if ( '' !== $normalized_id )
            {
                $normalized_ids[] = $normalized_id;
            }
        }

        $normalized_ids = array_values( array_unique( $normalized_ids ) );
        if ( empty( $normalized_ids ) )
        {
            return [];
        }

        $wpdb = $this->wpdb;
        $rows = [];
        // Keep per-form prepared queries here: WordPress.org Plugin Check flags dynamic IN placeholder assembly.
        foreach ( $normalized_ids as $normalized_id )
        {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_source = %s AND form_id = %s ORDER BY created_at DESC, id DESC LIMIT 1',
                    $this->table_name(),
                    sanitize_key( $form_source ),
                    $normalized_id
                ),
                ARRAY_A
            );

            if ( is_array( $row ) )
            {
                $rows[] = $row;
            }
        }

        $latest = [];
        foreach ( $rows as $row )
        {
            $form_id = sanitize_text_field( (string) ( $row['form_id'] ?? '' ) );
            if ( '' === $form_id || isset( $latest[ $form_id ] ) )
            {
                continue;
            }

            $latest[ $form_id ] = $this->decode_row( $row );
        }

        return $latest;
    }

    public function cleanup_expired( ?string $before = null ): int
    {
        $before = $before ?: $this->now();
        $wpdb  = $this->wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE expires_at IS NOT NULL AND expires_at < %s',
                $this->table_name(),
                $before
            )
        );
        return (int) $this->wpdb->rows_affected;
    }

    private function decode_row( array $row ): array
    {
        $row['token_usage_json'] = $this->decode_json_field( $row['token_usage_json'] ?? null );
        $row['cost_json']        = $this->decode_json_field( $row['cost_json'] ?? null );
        $row['result_json']      = $this->decode_json_field( $row['result_json'] ?? null );
        return $row;
    }

    private function action_log_filter_values( array $filters ): array
    {
        $form_id   = absint( $filters['form_id'] ?? 0 );
        $status    = sanitize_key( (string) ( $filters['status'] ?? '' ) );
        $date_from = isset( $filters['date_from'] ) ? sanitize_text_field( (string) $filters['date_from'] ) : '';
        $date_to   = isset( $filters['date_to'] ) ? sanitize_text_field( (string) $filters['date_to'] ) : '';

        return [
            'form_id'      => $form_id,
            'form_id_text' => (string) $form_id,
            'status'       => $status,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
        ];
    }

    private function resolve_expires_at( array $data ): ?string
    {
        if ( array_key_exists( 'expires_at', $data ) )
        {
            return null !== $data['expires_at'] ? sanitize_text_field( (string) $data['expires_at'] ) : null;
        }

        if ( class_exists( 'Sentient_Forms_Local_Data_Governance' ) )
        {
            return Sentient_Forms_Local_Data_Governance::default_execution_event_expires_at();
        }

        return null;
    }
}
