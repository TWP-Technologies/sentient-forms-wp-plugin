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
            'provider'             => sanitize_key( (string) ( $data['provider'] ?? 'openrouter' ) ),
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
                [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
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
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
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
                'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE execution_request_id = %s',
                $execution_request_id
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
                'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' ORDER BY created_at DESC, id DESC LIMIT %d',
                max( 1, min( 100, $limit ) )
            ),
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function cleanup_expired( ?string $before = null ): int
    {
        $before = $before ?: $this->now();
        $wpdb  = $this->wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . esc_sql( $this->table_name() ) . ' WHERE expires_at IS NOT NULL AND expires_at < %s',
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
