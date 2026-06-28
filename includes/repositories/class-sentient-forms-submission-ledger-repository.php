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

    public function get_by_native_entry_id( string $form_source, string $form_id, string $native_entry_id ): ?array
    {
        $form_source     = sanitize_key( $form_source );
        $form_id         = sanitize_text_field( $form_id );
        $native_entry_id = sanitize_text_field( $native_entry_id );

        if ( '' === $form_source || '' === $form_id || '' === $native_entry_id )
        {
            return null;
        }

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s AND native_entry_id = %s ORDER BY captured_at DESC, id DESC LIMIT 1',
                $this->table_name(),
                $form_source,
                $form_id,
                $native_entry_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $this->decode_row( $row ) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list_for_form( string $form_source, string $form_id, int $limit = 50, int $offset = 0, array $filters = [] ): array
    {
        $args = array_merge(
            [ $this->table_name() ],
            $this->build_form_filter_values( $form_source, $form_id, $filters ),
            [
                max( 1, min( 100, $limit ) ),
                max( 0, $offset ),
            ]
        );

        $rows = match ( sanitize_key( (string) ( $filters['sort'] ?? '' ) ) )
        {
            'captured_asc' => $this->list_for_form_ordered_by_captured_asc( $args ),
            'submitted_desc' => $this->list_for_form_ordered_by_submitted_desc( $args ),
            'submitted_asc' => $this->list_for_form_ordered_by_submitted_asc( $args ),
            'native_entry_asc' => $this->list_for_form_ordered_by_native_entry_asc( $args ),
            'native_entry_desc' => $this->list_for_form_ordered_by_native_entry_desc( $args ),
            default => $this->list_for_form_ordered_by_captured_desc( $args ),
        };

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

    public function count_for_form( string $form_source, string $form_id, array $filters = [] ): int
    {
        $args = array_merge(
            [ $this->table_name() ],
            $this->build_form_filter_values( $form_source, $form_id, $filters )
        );

        return (int) $this->wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional filters are represented by repeated scalar placeholders.
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                WHERE form_source = %s
                AND form_id = %s
                AND (%s = '' OR submission_uuid LIKE %s OR native_entry_id LIKE %s OR logical_fields_json LIKE %s OR provider_metadata_json LIKE %s)
                AND (%s = '' OR native_entry_id = %s)
                AND (%s = '' OR captured_at >= %s)
                AND (%s = '' OR captured_at <= %s)
                AND (
                    %s = ''
                    OR (%s = '1' AND file_refs_json IS NOT NULL AND file_refs_json <> '' AND file_refs_json <> '[]')
                    OR (%s = '0' AND (file_refs_json IS NULL OR file_refs_json = '' OR file_refs_json = '[]'))
                )",
                ...$args
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

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<int, string>
     */
    private function build_form_filter_values( string $form_source, string $form_id, array $filters ): array
    {
        $values = [
            sanitize_key( $form_source ),
            sanitize_text_field( $form_id ),
        ];

        $query = isset( $filters['q'] ) ? sanitize_text_field( (string) $filters['q'] ) : '';
        $like  = '%' . $this->wpdb->esc_like( $query ) . '%';
        array_push( $values, $query, $like, $like, $like, $like );

        $native_entry = isset( $filters['native_entry'] ) ? sanitize_text_field( (string) $filters['native_entry'] ) : '';
        array_push( $values, $native_entry, $native_entry );

        $captured_from = $this->normalize_captured_filter_datetime( $filters['captured_from'] ?? null );
        array_push( $values, $captured_from, $captured_from );

        $captured_to = $this->normalize_captured_filter_datetime( $filters['captured_to'] ?? null );
        array_push( $values, $captured_to, $captured_to );

        $has_files = '';
        if ( array_key_exists( 'has_files', $filters ) && null !== $filters['has_files'] )
        {
            $has_files = filter_var( $filters['has_files'], FILTER_VALIDATE_BOOLEAN ) ? '1' : '0';
        }

        array_push( $values, $has_files, $has_files, $has_files );

        return $values;
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    private function list_for_form_ordered_by_captured_desc( array $args ): array
    {
        return $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional filters are represented by repeated scalar placeholders.
            $this->wpdb->prepare(
                "SELECT * FROM %i
                WHERE form_source = %s
                AND form_id = %s
                AND (%s = '' OR submission_uuid LIKE %s OR native_entry_id LIKE %s OR logical_fields_json LIKE %s OR provider_metadata_json LIKE %s)
                AND (%s = '' OR native_entry_id = %s)
                AND (%s = '' OR captured_at >= %s)
                AND (%s = '' OR captured_at <= %s)
                AND (
                    %s = ''
                    OR (%s = '1' AND file_refs_json IS NOT NULL AND file_refs_json <> '' AND file_refs_json <> '[]')
                    OR (%s = '0' AND (file_refs_json IS NULL OR file_refs_json = '' OR file_refs_json = '[]'))
                )
                ORDER BY captured_at DESC, id DESC
                LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    private function list_for_form_ordered_by_captured_asc( array $args ): array
    {
        return $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional filters are represented by repeated scalar placeholders.
            $this->wpdb->prepare(
                "SELECT * FROM %i
                WHERE form_source = %s
                AND form_id = %s
                AND (%s = '' OR submission_uuid LIKE %s OR native_entry_id LIKE %s OR logical_fields_json LIKE %s OR provider_metadata_json LIKE %s)
                AND (%s = '' OR native_entry_id = %s)
                AND (%s = '' OR captured_at >= %s)
                AND (%s = '' OR captured_at <= %s)
                AND (
                    %s = ''
                    OR (%s = '1' AND file_refs_json IS NOT NULL AND file_refs_json <> '' AND file_refs_json <> '[]')
                    OR (%s = '0' AND (file_refs_json IS NULL OR file_refs_json = '' OR file_refs_json = '[]'))
                )
                ORDER BY captured_at ASC, id ASC
                LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    private function list_for_form_ordered_by_submitted_desc( array $args ): array
    {
        return $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional filters are represented by repeated scalar placeholders.
            $this->wpdb->prepare(
                "SELECT * FROM %i
                WHERE form_source = %s
                AND form_id = %s
                AND (%s = '' OR submission_uuid LIKE %s OR native_entry_id LIKE %s OR logical_fields_json LIKE %s OR provider_metadata_json LIKE %s)
                AND (%s = '' OR native_entry_id = %s)
                AND (%s = '' OR captured_at >= %s)
                AND (%s = '' OR captured_at <= %s)
                AND (
                    %s = ''
                    OR (%s = '1' AND file_refs_json IS NOT NULL AND file_refs_json <> '' AND file_refs_json <> '[]')
                    OR (%s = '0' AND (file_refs_json IS NULL OR file_refs_json = '' OR file_refs_json = '[]'))
                )
                ORDER BY source_submitted_at DESC, captured_at DESC, id DESC
                LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    private function list_for_form_ordered_by_submitted_asc( array $args ): array
    {
        return $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional filters are represented by repeated scalar placeholders.
            $this->wpdb->prepare(
                "SELECT * FROM %i
                WHERE form_source = %s
                AND form_id = %s
                AND (%s = '' OR submission_uuid LIKE %s OR native_entry_id LIKE %s OR logical_fields_json LIKE %s OR provider_metadata_json LIKE %s)
                AND (%s = '' OR native_entry_id = %s)
                AND (%s = '' OR captured_at >= %s)
                AND (%s = '' OR captured_at <= %s)
                AND (
                    %s = ''
                    OR (%s = '1' AND file_refs_json IS NOT NULL AND file_refs_json <> '' AND file_refs_json <> '[]')
                    OR (%s = '0' AND (file_refs_json IS NULL OR file_refs_json = '' OR file_refs_json = '[]'))
                )
                ORDER BY source_submitted_at ASC, captured_at ASC, id ASC
                LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    private function list_for_form_ordered_by_native_entry_asc( array $args ): array
    {
        return $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional filters are represented by repeated scalar placeholders.
            $this->wpdb->prepare(
                "SELECT * FROM %i
                WHERE form_source = %s
                AND form_id = %s
                AND (%s = '' OR submission_uuid LIKE %s OR native_entry_id LIKE %s OR logical_fields_json LIKE %s OR provider_metadata_json LIKE %s)
                AND (%s = '' OR native_entry_id = %s)
                AND (%s = '' OR captured_at >= %s)
                AND (%s = '' OR captured_at <= %s)
                AND (
                    %s = ''
                    OR (%s = '1' AND file_refs_json IS NOT NULL AND file_refs_json <> '' AND file_refs_json <> '[]')
                    OR (%s = '0' AND (file_refs_json IS NULL OR file_refs_json = '' OR file_refs_json = '[]'))
                )
                ORDER BY native_entry_id ASC, captured_at DESC, id DESC
                LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return array<int, array<string, mixed>>
     */
    private function list_for_form_ordered_by_native_entry_desc( array $args ): array
    {
        return $this->wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Optional filters are represented by repeated scalar placeholders.
            $this->wpdb->prepare(
                "SELECT * FROM %i
                WHERE form_source = %s
                AND form_id = %s
                AND (%s = '' OR submission_uuid LIKE %s OR native_entry_id LIKE %s OR logical_fields_json LIKE %s OR provider_metadata_json LIKE %s)
                AND (%s = '' OR native_entry_id = %s)
                AND (%s = '' OR captured_at >= %s)
                AND (%s = '' OR captured_at <= %s)
                AND (
                    %s = ''
                    OR (%s = '1' AND file_refs_json IS NOT NULL AND file_refs_json <> '' AND file_refs_json <> '[]')
                    OR (%s = '0' AND (file_refs_json IS NULL OR file_refs_json = '' OR file_refs_json = '[]'))
                )
                ORDER BY native_entry_id DESC, captured_at DESC, id DESC
                LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        ) ?: [];
    }

    private function normalize_captured_filter_datetime( mixed $value ): string
    {
        if ( null === $value || ! is_scalar( $value ) )
        {
            return '';
        }

        $raw = sanitize_text_field( (string) $value );
        if ( '' === $raw )
        {
            return '';
        }

        $normalized = str_replace( 'T', ' ', $raw );
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized ) )
        {
            return $normalized . ':00';
        }

        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalized ) )
        {
            return $normalized;
        }

        return '';
    }
}
