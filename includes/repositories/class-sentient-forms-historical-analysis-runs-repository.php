<?php
/**
 * Local historical analysis run repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Historical analysis runs live in plugin-owned local-first tables. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Historical_Analysis_Runs_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_historical_analysis_runs';
    }

    public function create( array $data ): int | WP_Error
    {
        $row = $this->build_write_row( $data, false );
        if ( is_wp_error( $row ) )
        {
            return $row;
        }

        $inserted = $this->wpdb->insert(
            $this->table_name(),
            $row['values'],
            $row['formats']
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Historical analysis run could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function update( int $id, array $data ): int | WP_Error
    {
        $id = absint( $id );
        if ( $id <= 0 )
        {
            return new WP_Error( 'sentient_forms_invalid_historical_run_id', __( 'Historical analysis run ID is invalid.', 'sentient-forms' ) );
        }

        $row = $this->build_write_row( $data, true );
        if ( is_wp_error( $row ) )
        {
            return $row;
        }

        $updated = $this->wpdb->update(
            $this->table_name(),
            $row['values'],
            [ 'id' => $id ],
            $row['formats'],
            [ '%d' ]
        );

        if ( false === $updated )
        {
            return new WP_Error( 'sentient_forms_db_update_failed', __( 'Historical analysis run could not be updated.', 'sentient-forms' ) );
        }

        return $id;
    }

    public function get( int $id ): ?array
    {
        $row = $this->get_by_id( $id );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function list_for_form( string $form_source, string $form_id, int $limit = 20 ): array
    {
        $wpdb = $this->wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s ORDER BY updated_at DESC, id DESC LIMIT %d',
                $this->table_name(),
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id ),
                max( 1, min( 100, $limit ) )
            ),
            ARRAY_A
        ) ?: [];

        return array_map( [ $this, 'decode_row' ], $rows );
    }

    /**
     * @return array{values: array<string, mixed>, formats: array<int, string>}|WP_Error
     */
    private function build_write_row( array $data, bool $is_update ): array | WP_Error
    {
        $values  = [];
        $formats = [];

        foreach ( [ 'form_source', 'form_id', 'action_code', 'status' ] as $field )
        {
            if ( ! array_key_exists( $field, $data ) )
            {
                continue;
            }

            $values[ $field ] = 'form_source' === $field || 'action_code' === $field
                ? sanitize_key( (string) $data[ $field ] )
                : sanitize_text_field( (string) $data[ $field ] );
            $formats[]        = '%s';
        }

        foreach ( [ 'lead_profile_id', 'estimated_entry_count', 'estimated_managed_credits', 'created_by_user_id' ] as $field )
        {
            if ( ! array_key_exists( $field, $data ) )
            {
                continue;
            }

            $values[ $field ] = null === $data[ $field ] ? null : absint( $data[ $field ] );
            $formats[]        = '%d';
        }

        if ( array_key_exists( 'dry_run', $data ) )
        {
            $values['dry_run'] = rest_sanitize_boolean( $data['dry_run'] ) ? 1 : 0;
            $formats[]         = '%d';
        }

        foreach (
            [
                'selected_entry_ids_json',
                'filters_json',
                'estimated_direct_provider_cost_json',
                'progress_json',
                'result_summary_json',
            ] as $field
        )
        {
            if ( ! array_key_exists( $field, $data ) )
            {
                continue;
            }

            $encoded = $this->encode_json_field( $data[ $field ], $field );
            if ( is_wp_error( $encoded ) )
            {
                return $encoded;
            }

            $values[ $field ] = $encoded;
            $formats[]        = '%s';
        }

        $now = $this->now();
        if ( ! $is_update )
        {
            $values['form_source']            = $values['form_source'] ?? sanitize_key( (string) ( $data['form_source'] ?? 'gravity_forms' ) );
            $formats[]                        = array_key_exists( 'form_source', $data ) ? '' : '%s';
            $values['form_id']                = $values['form_id'] ?? sanitize_text_field( (string) ( $data['form_id'] ?? '' ) );
            $formats[]                        = array_key_exists( 'form_id', $data ) ? '' : '%s';
            $values['action_code']            = $values['action_code'] ?? 'lead_grading_v1';
            $formats[]                        = array_key_exists( 'action_code', $data ) ? '' : '%s';
            $values['estimated_entry_count']  = $values['estimated_entry_count'] ?? 0;
            $formats[]                        = array_key_exists( 'estimated_entry_count', $data ) ? '' : '%d';
            $values['dry_run']                = $values['dry_run'] ?? 1;
            $formats[]                        = array_key_exists( 'dry_run', $data ) ? '' : '%d';
            $values['status']                 = $values['status'] ?? 'draft';
            $formats[]                        = array_key_exists( 'status', $data ) ? '' : '%s';
            $values['created_at']             = $now;
            $formats[]                        = '%s';
        }

        $values['updated_at'] = $now;
        $formats[]            = '%s';
        $formats              = array_values( array_filter( $formats, static fn ( string $format ): bool => '' !== $format ) );

        if ( ! $is_update && ( '' === (string) $values['form_source'] || '' === (string) $values['form_id'] ) )
        {
            return new WP_Error( 'sentient_forms_missing_historical_run_form', __( 'Form source and form ID are required for historical analysis runs.', 'sentient-forms' ) );
        }

        return [
            'values'  => $values,
            'formats' => $formats,
        ];
    }

    private function decode_row( array $row ): array
    {
        foreach (
            [
                'selected_entry_ids_json',
                'filters_json',
                'estimated_direct_provider_cost_json',
                'progress_json',
                'result_summary_json',
            ] as $field
        )
        {
            $row[ $field ] = $this->decode_json_field( $row[ $field ] ?? null );
        }

        foreach ( [ 'id', 'lead_profile_id', 'estimated_entry_count', 'estimated_managed_credits', 'created_by_user_id' ] as $field )
        {
            if ( isset( $row[ $field ] ) )
            {
                $row[ $field ] = (int) $row[ $field ];
            }
        }

        $row['dry_run'] = ! empty( $row['dry_run'] );

        return $row;
    }
}
