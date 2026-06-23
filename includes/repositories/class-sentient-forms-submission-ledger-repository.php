<?php
/**
 * Submission ledger repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Submission ledger records live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static queries use $wpdb->prepare() with %i/%s/%d placeholders and plugin-owned table identifiers.
class Sentient_Forms_Submission_Ledger_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_submission_ledger';
    }

    public function create( array $data ): int | WP_Error
    {
        $submission_uuid = isset( $data['submission_uuid'] ) && is_scalar( $data['submission_uuid'] )
            ? sanitize_text_field( (string) $data['submission_uuid'] )
            : '';
        if ( '' === $submission_uuid )
        {
            return new WP_Error( 'sentient_forms_missing_submission_uuid', __( 'Submission UUID is required.', 'sentient-forms' ) );
        }
        $form_source = sanitize_key( (string) ( $data['form_source'] ?? '' ) );
        $form_id     = sanitize_text_field( (string) ( $data['form_id'] ?? '' ) );
        if ( '' === $form_source || '' === $form_id )
        {
            return new WP_Error( 'sentient_forms_invalid_submission_ledger_scope', __( 'Submission ledger records require a form source and form ID.', 'sentient-forms' ) );
        }

        $logical_fields_json = $this->encode_json_field( $data['logical_fields_json'] ?? null, 'logical_fields_json', true );
        if ( is_wp_error( $logical_fields_json ) )
        {
            return $logical_fields_json;
        }

        $provider_metadata_json = $this->encode_json_field( $data['provider_metadata_json'] ?? null, 'provider_metadata_json' );
        if ( is_wp_error( $provider_metadata_json ) )
        {
            return $provider_metadata_json;
        }

        $file_refs_json = $this->encode_json_field( $data['file_refs_json'] ?? null, 'file_refs_json' );
        if ( is_wp_error( $file_refs_json ) )
        {
            return $file_refs_json;
        }

        $redaction_summary_json = $this->encode_json_field( $data['redaction_summary_json'] ?? null, 'redaction_summary_json' );
        if ( is_wp_error( $redaction_summary_json ) )
        {
            return $redaction_summary_json;
        }

        $now = $this->now();
        $inserted = $this->wpdb->insert(
            $this->table_name(),
            [
                'submission_uuid'        => $submission_uuid,
                'form_source'            => $form_source,
                'form_id'                => $form_id,
                'native_entry_id'        => isset( $data['native_entry_id'] ) ? sanitize_text_field( (string) $data['native_entry_id'] ) : null,
                'native_entry_url'       => isset( $data['native_entry_url'] ) ? esc_url_raw( (string) $data['native_entry_url'] ) : null,
                'source_submitted_at'    => isset( $data['source_submitted_at'] ) ? sanitize_text_field( (string) $data['source_submitted_at'] ) : null,
                'captured_at'            => isset( $data['captured_at'] ) ? sanitize_text_field( (string) $data['captured_at'] ) : $now,
                'logical_fields_json'    => $logical_fields_json,
                'provider_metadata_json' => $provider_metadata_json,
                'file_refs_json'         => $file_refs_json,
                'redaction_summary_json' => $redaction_summary_json,
                'created_at'             => $now,
                'updated_at'             => $now,
                'expires_at'             => isset( $data['expires_at'] ) ? sanitize_text_field( (string) $data['expires_at'] ) : null,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Submission ledger record could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function get_by_submission_uuid( string $submission_uuid ): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE submission_uuid = %s LIMIT 1',
                $this->table_name(),
                sanitize_text_field( $submission_uuid )
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $this->decode_row( $row ) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_for_form( string $form_source, string $form_id, int $limit = 50, int $offset = 0 ): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s ORDER BY captured_at DESC, id DESC LIMIT %d OFFSET %d',
                $this->table_name(),
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id ),
                max( 1, min( 100, $limit ) ),
                max( 0, $offset )
            ),
            ARRAY_A
        ) ?: [];

        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function count_all(): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*) FROM %i',
                $this->table_name()
            )
        );
    }

    public function count_for_form( string $form_source, string $form_id ): int
    {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE form_source = %s AND form_id = %s',
                $this->table_name(),
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id )
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_recent( int $limit = 20 ): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i ORDER BY captured_at DESC, id DESC LIMIT %d',
                $this->table_name(),
                max( 1, min( 100, $limit ) )
            ),
            ARRAY_A
        ) ?: [];

        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function cleanup_expired( ?string $before = null ): int
    {
        $before = $before ?: $this->now();
        $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE expires_at IS NOT NULL AND expires_at < %s',
                $this->table_name(),
                $before
            )
        );

        return (int) $this->wpdb->rows_affected;
    }

    private function decode_row( array $row ): array
    {
        $row['id']                      = absint( $row['id'] ?? 0 );
        $row['logical_fields_json']     = $this->decode_json_field( $row['logical_fields_json'] ?? null ) ?: [];
        $row['provider_metadata_json']  = $this->decode_json_field( $row['provider_metadata_json'] ?? null );
        $row['file_refs_json']          = $this->decode_json_field( $row['file_refs_json'] ?? null );
        $row['redaction_summary_json']  = $this->decode_json_field( $row['redaction_summary_json'] ?? null );

        return $row;
    }
}
