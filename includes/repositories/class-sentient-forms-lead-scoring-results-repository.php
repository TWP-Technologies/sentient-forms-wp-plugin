<?php
/**
 * Local lead scoring result index.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lead scoring result rows live in plugin-owned local-first tables. WordPress core has no native CRUD/cache API for these records; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Lead_Scoring_Results_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_lead_scoring_results';
    }

    public function upsert_from_execution( array $data ): int | WP_Error
    {
        $action_code = sanitize_key( (string) ( $data['action_code'] ?? '' ) );
        if ( ! in_array( $action_code, [ 'lead_grading_v1', 'suggested_reply_v1' ], true ) )
        {
            return new WP_Error( 'sentient_forms_lead_scoring_result_unsupported_action', __( 'Only lead scoring actions can be indexed.', 'sentient-forms' ) );
        }

        $execution_request_id = sanitize_text_field( (string) ( $data['execution_request_id'] ?? '' ) );
        if ( '' === $execution_request_id )
        {
            return new WP_Error( 'sentient_forms_lead_scoring_result_missing_execution_id', __( 'Execution request ID is required for lead scoring results.', 'sentient-forms' ) );
        }

        $row = $this->build_write_row( $data );
        if ( is_wp_error( $row ) )
        {
            return $row;
        }

        $existing = $this->get_by_execution( $execution_request_id, $action_code );
        if ( $existing )
        {
            $updated = $this->wpdb->update(
                $this->table_name(),
                $row['values'],
                [ 'id' => (int) $existing['id'] ],
                $row['formats'],
                [ '%d' ]
            );

            if ( false === $updated )
            {
                return new WP_Error( 'sentient_forms_db_update_failed', __( 'Lead scoring result could not be updated.', 'sentient-forms' ) );
            }

            return (int) $existing['id'];
        }

        $inserted = $this->wpdb->insert( $this->table_name(), $row['values'], $row['formats'] );
        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Lead scoring result could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function get_by_execution( string $execution_request_id, string $action_code ): ?array
    {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static queries use %i/%s/%d placeholders and plugin-owned table identifiers.
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE execution_request_id = %s AND action_code = %s LIMIT 1',
                $this->table_name(),
                sanitize_text_field( $execution_request_id ),
                sanitize_key( $action_code )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $row ? $this->decode_row( $row ) : null;
    }

    public function list_recent_rows( ?string $form_source = null, ?string $form_id = null, int $limit = 1000 ): array
    {
        $limit       = max( 1, min( 2000, $limit ) );
        $form_source = null !== $form_source ? sanitize_key( $form_source ) : null;
        $form_id     = null !== $form_id ? sanitize_text_field( $form_id ) : null;

        if ( null !== $form_source && '' !== $form_source && null !== $form_id && '' !== $form_id )
        {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static queries use %i/%s/%d placeholders and plugin-owned table identifiers.
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM %i WHERE action_code IN ('lead_grading_v1', 'suggested_reply_v1') AND form_source = %s AND form_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
                    $this->table_name(),
                    $form_source,
                    $form_id,
                    $limit
                ),
                ARRAY_A
            ) ?: [];
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            return array_map( [ $this, 'decode_row' ], $rows );
        }

        if ( null !== $form_source && '' !== $form_source )
        {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static queries use %i/%s/%d placeholders and plugin-owned table identifiers.
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM %i WHERE action_code IN ('lead_grading_v1', 'suggested_reply_v1') AND form_source = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
                    $this->table_name(),
                    $form_source,
                    $limit
                ),
                ARRAY_A
            ) ?: [];
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            return array_map( [ $this, 'decode_row' ], $rows );
        }

        if ( null !== $form_id && '' !== $form_id )
        {
            // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static queries use %i/%s/%d placeholders and plugin-owned table identifiers.
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT * FROM %i WHERE action_code IN ('lead_grading_v1', 'suggested_reply_v1') AND form_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d",
                    $this->table_name(),
                    $form_id,
                    $limit
                ),
                ARRAY_A
            ) ?: [];
            // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
            return array_map( [ $this, 'decode_row' ], $rows );
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static queries use %i/%s/%d placeholders and plugin-owned table identifiers.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM %i WHERE action_code IN ('lead_grading_v1', 'suggested_reply_v1') ORDER BY updated_at DESC, id DESC LIMIT %d",
                $this->table_name(),
                $limit
            ),
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function paginated_entries( array $args = [] ): array
    {
        $page     = max( 1, absint( $args['page'] ?? 1 ) );
        $per_page = max( 5, min( 50, absint( $args['per_page'] ?? 10 ) ) );
        $form_source = isset( $args['form_source'] ) ? sanitize_key( (string) $args['form_source'] ) : null;
        $form_id     = isset( $args['form_id'] ) ? sanitize_text_field( (string) $args['form_id'] ) : null;
        $query       = isset( $args['q'] ) ? strtolower( trim( sanitize_text_field( (string) $args['q'] ) ) ) : '';

        $entries = $this->combine_entry_rows( $this->list_recent_rows( $form_source, $form_id, 2000 ) );
        if ( '' !== $query )
        {
            $entries = array_values(
                array_filter(
                    $entries,
                    static function ( array $entry ) use ( $query ): bool
                    {
                        $haystack = strtolower( (string) wp_json_encode( $entry ) );
                        return str_contains( $haystack, $query );
                    }
                )
            );
        }

        $total  = count( $entries );
        $offset = ( $page - 1 ) * $per_page;

        return [
            'entries'  => array_slice( $entries, $offset, $per_page ),
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => $total,
            'pages'    => (int) ceil( $total / $per_page ),
        ];
    }

    public function dashboard( ?string $form_source = null, ?string $form_id = null ): array
    {
        $entries = $this->combine_entry_rows( $this->list_recent_rows( $form_source, $form_id, 2000 ) );
        $grades  = [ 'A' => 0, 'B' => 0, 'C' => 0, 'Reject' => 0, 'ungraded' => 0 ];
        $priority = 0;
        $reply_drafts = 0;
        $rejected = 0;

        foreach ( $entries as $entry )
        {
            $grade = (string) ( $entry['grade'] ?? '' );
            if ( isset( $grades[ $grade ] ) )
            {
                ++$grades[ $grade ];
            }
            else
            {
                ++$grades['ungraded'];
            }

            $priority_value = sanitize_key( (string) ( $entry['priority'] ?? '' ) );
            if ( 'A' === $grade || in_array( $priority_value, [ 'high', 'urgent' ], true ) )
            {
                ++$priority;
            }

            if ( '' !== trim( (string) ( $entry['suggested_reply_draft'] ?? '' ) ) || '' !== trim( (string) ( $entry['next_best_action'] ?? '' ) ) )
            {
                ++$reply_drafts;
            }

            if ( 'Reject' === $grade )
            {
                ++$rejected;
            }
        }

        return [
            'scored_leads'       => count( array_filter( $entries, static fn ( array $entry ): bool => '' !== (string) ( $entry['grade'] ?? '' ) ) ),
            'priority_leads'     => $priority,
            'reply_drafts'       => $reply_drafts,
            'rejected_leads'     => $rejected,
            'grades'             => $grades,
            'latest_entries'     => array_slice( $entries, 0, 5 ),
        ];
    }

    public function forms_summary(): array
    {
        $entries = $this->combine_entry_rows( $this->list_recent_rows( null, null, 2000 ) );
        $forms   = [];

        foreach ( $entries as $entry )
        {
            $key = sanitize_key( (string) $entry['form_source'] ) . ':' . sanitize_text_field( (string) $entry['form_id'] );
            if ( ! isset( $forms[ $key ] ) )
            {
                $forms[ $key ] = [
                    'form_source'    => $entry['form_source'],
                    'form_id'        => $entry['form_id'],
                    'form_title'     => $entry['form_title'] ?? '',
                    'scored_leads'   => 0,
                    'priority_leads' => 0,
                    'reply_drafts'   => 0,
                    'latest_at'      => $entry['updated_at'] ?? null,
                ];
            }

            if ( '' !== (string) ( $entry['grade'] ?? '' ) )
            {
                ++$forms[ $key ]['scored_leads'];
            }

            if ( 'A' === (string) ( $entry['grade'] ?? '' ) || in_array( sanitize_key( (string) ( $entry['priority'] ?? '' ) ), [ 'high', 'urgent' ], true ) )
            {
                ++$forms[ $key ]['priority_leads'];
            }

            if ( '' !== trim( (string) ( $entry['suggested_reply_draft'] ?? '' ) ) || '' !== trim( (string) ( $entry['next_best_action'] ?? '' ) ) )
            {
                ++$forms[ $key ]['reply_drafts'];
            }

            if ( strcmp( (string) ( $entry['updated_at'] ?? '' ), (string) ( $forms[ $key ]['latest_at'] ?? '' ) ) > 0 )
            {
                $forms[ $key ]['latest_at'] = $entry['updated_at'] ?? null;
            }
        }

        return array_values( $forms );
    }

    public function get_entry_result( string $form_source, string $form_id, string $entry_id ): ?array
    {
        $rows = $this->list_recent_rows( $form_source, $form_id, 2000 );
        foreach ( $this->combine_entry_rows( $rows ) as $entry )
        {
            if ( sanitize_text_field( (string) ( $entry['entry_id'] ?? '' ) ) === sanitize_text_field( $entry_id ) )
            {
                return $entry;
            }
        }

        return null;
    }

    public function apply_human_correction( string $form_source, string $form_id, string $entry_id, string $grade, string $justification, ?int $user_id = null ): array | WP_Error
    {
        $row = $this->latest_action_row( $form_source, $form_id, $entry_id, 'lead_grading_v1' );
        if ( null === $row )
        {
            return new WP_Error(
                'sentient_forms_lead_scoring_result_not_found',
                __( 'A stored Lead Scoring result is required before a lead grade can be corrected.', 'sentient-forms' ),
                [ 'status' => 404 ]
            );
        }

        $normalized_grade = $this->normalize_grade( $grade );
        $justification    = wp_check_invalid_utf8( mb_substr( trim( $justification ), 0, 6000 ) );
        if ( '' === $normalized_grade || '' === $justification )
        {
            return new WP_Error(
                'sentient_forms_lead_scoring_correction_invalid',
                __( 'A valid corrected grade and justification are required.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $source_payload = is_array( $row['source_payload_json'] ?? null ) ? $row['source_payload_json'] : [];
        if ( ! isset( $source_payload['model_original'] ) )
        {
            $source_payload['model_original'] = [
                'grade'         => $row['grade'] ?? '',
                'justification' => $row['justification'] ?? '',
                'corrected_at'  => current_time( 'mysql' ),
            ];
        }

        $source_payload['human_correction'] = [
            'grade'                  => $normalized_grade,
            'justification'          => $justification,
            'original_grade'         => $source_payload['model_original']['grade'] ?? ( $row['grade'] ?? '' ),
            'original_justification' => $source_payload['model_original']['justification'] ?? ( $row['justification'] ?? '' ),
            'corrected_by_user_id'   => $user_id,
            'corrected_at'           => current_time( 'mysql' ),
        ];

        $source_payload_json = $this->encode_json_field( $source_payload, 'source_payload_json' );
        if ( is_wp_error( $source_payload_json ) )
        {
            return $source_payload_json;
        }

        $updated = $this->wpdb->update(
            $this->table_name(),
            [
                'grade'               => $normalized_grade,
                'justification'       => $justification,
                'source_payload_json' => $source_payload_json,
                'updated_at'          => $this->now(),
            ],
            [ 'id' => (int) $row['id'] ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        if ( false === $updated )
        {
            return new WP_Error( 'sentient_forms_db_update_failed', __( 'Lead Scoring correction could not be saved.', 'sentient-forms' ) );
        }

        $entry = $this->get_entry_result( $form_source, $form_id, $entry_id );
        return is_array( $entry )
            ? $entry
            : new WP_Error( 'sentient_forms_lead_scoring_result_not_found', __( 'Updated Lead Scoring result could not be loaded.', 'sentient-forms' ), [ 'status' => 404 ] );
    }

    public function cleanup_expired( ?string $before = null ): int
    {
        $before = $before ?: $this->now();
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static query uses %i/%s placeholders and a plugin-owned table identifier.
        $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM %i WHERE expires_at IS NOT NULL AND expires_at < %s',
                $this->table_name(),
                $before
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
        return (int) $this->wpdb->rows_affected;
    }

    private function build_write_row( array $data ): array | WP_Error
    {
        $entry_snapshot = $this->encode_json_field( is_array( $data['entry_snapshot'] ?? null ) ? $data['entry_snapshot'] : [], 'entry_snapshot_json' );
        if ( is_wp_error( $entry_snapshot ) )
        {
            return $entry_snapshot;
        }

        $source_payload = $this->encode_json_field( is_array( $data['source_payload'] ?? null ) ? $data['source_payload'] : [], 'source_payload_json' );
        if ( is_wp_error( $source_payload ) )
        {
            return $source_payload;
        }

        $now = $this->now();
        $values = [
            'form_source'          => sanitize_key( (string) ( $data['form_source'] ?? 'gravity_forms' ) ),
            'form_id'              => sanitize_text_field( (string) ( $data['form_id'] ?? '' ) ),
            'form_title'           => sanitize_text_field( (string) ( $data['form_title'] ?? '' ) ),
            'entry_id'             => sanitize_text_field( (string) ( $data['entry_id'] ?? '' ) ),
            'action_code'          => sanitize_key( (string) ( $data['action_code'] ?? '' ) ),
            'execution_request_id' => sanitize_text_field( (string) ( $data['execution_request_id'] ?? '' ) ),
            'historical_run_id'    => isset( $data['historical_run_id'] ) ? absint( $data['historical_run_id'] ) : null,
            'lead_profile_id'      => isset( $data['lead_profile_id'] ) ? absint( $data['lead_profile_id'] ) : null,
            'profile_version'      => isset( $data['profile_version'] ) ? absint( $data['profile_version'] ) : null,
            'grade'                => $this->normalize_grade( (string) ( $data['grade'] ?? '' ) ),
            'confidence'           => is_numeric( $data['confidence'] ?? null ) ? max( 0, min( 1, (float) $data['confidence'] ) ) : null,
            'priority'             => sanitize_key( (string) ( $data['priority'] ?? '' ) ),
            'fit_summary'          => wp_check_invalid_utf8( mb_substr( (string) ( $data['fit_summary'] ?? '' ), 0, 1000 ) ),
            'intent_summary'       => wp_check_invalid_utf8( mb_substr( (string) ( $data['intent_summary'] ?? '' ), 0, 1000 ) ),
            'justification'        => wp_check_invalid_utf8( mb_substr( (string) ( $data['justification'] ?? '' ), 0, 6000 ) ),
            'next_best_action'     => wp_check_invalid_utf8( mb_substr( (string) ( $data['next_best_action'] ?? '' ), 0, 2000 ) ),
            'suggested_reply_draft'=> wp_check_invalid_utf8( mb_substr( (string) ( $data['suggested_reply_draft'] ?? '' ), 0, 6000 ) ),
            'reply_rationale'      => wp_check_invalid_utf8( mb_substr( (string) ( $data['reply_rationale'] ?? '' ), 0, 4000 ) ),
            'do_not_send'          => rest_sanitize_boolean( $data['do_not_send'] ?? false ) ? 1 : 0,
            'status'               => sanitize_key( (string) ( $data['status'] ?? 'succeeded' ) ),
            'entry_snapshot_json'   => $entry_snapshot,
            'source_payload_json'   => $source_payload,
            'source_created_at'     => sanitize_text_field( (string) ( $data['source_created_at'] ?? $now ) ),
            'expires_at'           => array_key_exists( 'expires_at', $data ) ? ( null === $data['expires_at'] ? null : sanitize_text_field( (string) $data['expires_at'] ) ) : $this->default_expires_at(),
            'updated_at'           => $now,
        ];

        if ( '' === $values['form_id'] || '' === $values['entry_id'] )
        {
            return new WP_Error( 'sentient_forms_lead_scoring_result_missing_entry', __( 'Form ID and entry ID are required for lead scoring results.', 'sentient-forms' ) );
        }

        if ( ! $this->get_by_execution( $values['execution_request_id'], $values['action_code'] ) )
        {
            $values['created_at'] = $now;
        }

        return [
            'values'  => $values,
            'formats' => array_fill( 0, count( $values ), '%s' ),
        ];
    }

    private function combine_entry_rows( array $rows ): array
    {
        $entries = [];
        foreach ( $rows as $row )
        {
            $key = sanitize_key( (string) $row['form_source'] ) . ':' . sanitize_text_field( (string) $row['form_id'] ) . ':' . sanitize_text_field( (string) $row['entry_id'] );
            if ( ! isset( $entries[ $key ] ) )
            {
                $entries[ $key ] = [
                    'form_source'             => $row['form_source'],
                    'form_id'                 => $row['form_id'],
                    'form_title'              => $row['form_title'],
                    'entry_id'                => $row['entry_id'],
                    'entry_snapshot'          => $row['entry_snapshot_json'] ?? [],
                    'updated_at'              => $row['updated_at'],
                    'lead_execution_id'       => null,
                    'reply_execution_id'      => null,
                    'historical_run_id'       => null,
                ];
            }

            if ( 'lead_grading_v1' === $row['action_code'] )
            {
                foreach ( [ 'grade', 'confidence', 'priority', 'fit_summary', 'intent_summary', 'justification', 'lead_profile_id', 'profile_version', 'historical_run_id' ] as $field )
                {
                    $entries[ $key ][ $field ] = $row[ $field ] ?? null;
                }
                $entries[ $key ]['lead_execution_id'] = $row['execution_request_id'] ?? null;
                $source_payload = is_array( $row['source_payload_json'] ?? null ) ? $row['source_payload_json'] : [];
                if ( is_array( $source_payload['human_correction'] ?? null ) )
                {
                    $entries[ $key ]['correction'] = $source_payload['human_correction'];
                }
            }

            if ( 'suggested_reply_v1' === $row['action_code'] )
            {
                foreach ( [ 'next_best_action', 'suggested_reply_draft', 'reply_rationale', 'do_not_send', 'profile_version', 'historical_run_id' ] as $field )
                {
                    $entries[ $key ][ $field ] = $row[ $field ] ?? null;
                }
                $entries[ $key ]['reply_execution_id'] = $row['execution_request_id'] ?? null;
            }

            if ( strcmp( (string) ( $row['updated_at'] ?? '' ), (string) ( $entries[ $key ]['updated_at'] ?? '' ) ) > 0 )
            {
                $entries[ $key ]['updated_at'] = $row['updated_at'];
            }
        }

        usort(
            $entries,
            static fn ( array $a, array $b ): int => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) )
        );

        return array_values( $entries );
    }

    private function latest_action_row( string $form_source, string $form_id, string $entry_id, string $action_code ): ?array
    {
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static query uses %i/%s placeholders and a plugin-owned table identifier.
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s AND entry_id = %s AND action_code = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
                $this->table_name(),
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id ),
                sanitize_text_field( $entry_id ),
                sanitize_key( $action_code )
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        return $row ? $this->decode_row( $row ) : null;
    }

    private function decode_row( array $row ): array
    {
        $row['id']                  = (int) ( $row['id'] ?? 0 );
        $row['historical_run_id']   = isset( $row['historical_run_id'] ) ? (int) $row['historical_run_id'] : null;
        $row['lead_profile_id']     = isset( $row['lead_profile_id'] ) ? (int) $row['lead_profile_id'] : null;
        $row['profile_version']     = isset( $row['profile_version'] ) ? (int) $row['profile_version'] : null;
        $row['confidence']          = isset( $row['confidence'] ) ? (float) $row['confidence'] : null;
        $row['do_not_send']         = ! empty( $row['do_not_send'] );
        $row['entry_snapshot_json'] = $this->decode_json_field( $row['entry_snapshot_json'] ?? null ) ?? [];
        $row['source_payload_json'] = $this->decode_json_field( $row['source_payload_json'] ?? null ) ?? [];

        return $row;
    }

    private function normalize_grade( string $grade ): string
    {
        $grade = strtoupper( trim( $grade ) );
        if ( in_array( $grade, [ 'A', 'B', 'C' ], true ) )
        {
            return $grade;
        }

        if ( in_array( $grade, [ 'F', 'REJECT', 'REJECTED' ], true ) )
        {
            return 'Reject';
        }

        return '';
    }

    private function default_expires_at(): ?string
    {
        if ( class_exists( 'Sentient_Forms_Local_Data_Governance' ) )
        {
            return Sentient_Forms_Local_Data_Governance::default_execution_event_expires_at();
        }

        return null;
    }
}
