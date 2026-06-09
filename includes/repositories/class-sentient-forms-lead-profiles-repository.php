<?php
/**
 * Local lead scoring setup repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lead profiles live in plugin-owned local-first tables. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Lead_Profiles_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_lead_profiles';
    }

    public function save( array $data, ?int $id = null ): int | WP_Error
    {
        $row = $this->build_write_row( $data, null !== $id && $id > 0 );
        if ( is_wp_error( $row ) )
        {
            return $row;
        }

        if ( null !== $id && $id > 0 )
        {
            $updated = $this->wpdb->update(
                $this->table_name(),
                $row['values'],
                [ 'id' => $id ],
                $row['formats'],
                [ '%d' ]
            );

            if ( false === $updated )
            {
                return new WP_Error( 'sentient_forms_db_update_failed', __( 'Lead profile could not be updated.', 'sentient-forms' ) );
            }

            return $id;
        }

        $inserted = $this->wpdb->insert(
            $this->table_name(),
            $row['values'],
            $row['formats']
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Lead profile could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function get( int $id ): ?array
    {
        $row = $this->get_by_id( $id );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function get_latest_for_form( string $form_source, string $form_id ): ?array
    {
        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
                $this->table_name(),
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id )
            ),
            ARRAY_A
        );

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

    public function list_latest_by_form( int $limit = 200 ): array
    {
        $limit = max( 1, min( 1000, $limit ) );

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Static query uses %i/%d placeholders and a plugin-owned table identifier.
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                'SELECT * FROM %i ORDER BY updated_at DESC, id DESC LIMIT %d',
                $this->table_name(),
                $limit
            ),
            ARRAY_A
        ) ?: [];
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $latest = [];
        foreach ( array_map( [ $this, 'decode_row' ], $rows ) as $row )
        {
            $key = sanitize_key( (string) ( $row['form_source'] ?? '' ) ) . ':' . sanitize_text_field( (string) ( $row['form_id'] ?? '' ) );
            if ( ':' === $key || isset( $latest[ $key ] ) )
            {
                continue;
            }

            $latest[ $key ] = $row;
        }

        return array_values( $latest );
    }

    public function increment_profile_version( int $id ): int | WP_Error
    {
        $profile = $this->get( $id );
        if ( null === $profile )
        {
            return new WP_Error( 'sentient_forms_lead_profile_not_found', __( 'Lead profile could not be found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        $version = max( 1, (int) ( $profile['profile_version'] ?? 1 ) ) + 1;
        $saved   = $this->save( [ 'profile_version' => $version ], $id );
        if ( is_wp_error( $saved ) )
        {
            return $saved;
        }

        return $version;
    }

    /**
     * @return array{values: array<string, mixed>, formats: array<int, string>}|WP_Error
     */
    private function build_write_row( array $data, bool $is_update ): array | WP_Error
    {
        $values  = [];
        $formats = [];

        $this->maybe_add_scalar( $values, $formats, $data, 'form_source', 'sanitize_key' );
        $this->maybe_add_scalar( $values, $formats, $data, 'form_id', 'sanitize_text_field' );
        $this->maybe_add_scalar( $values, $formats, $data, 'status', 'sanitize_key' );

        if ( array_key_exists( 'profile_version', $data ) )
        {
            $values['profile_version'] = max( 1, (int) $data['profile_version'] );
            $formats[]                 = '%d';
        }

        $this->maybe_add_nullable_text( $values, $formats, $data, 'consented_at' );

        foreach (
            [
                'site_context_snapshot_json',
                'spam_guidance_snapshot_json',
                'good_lead_criteria_json',
                'bad_lead_criteria_json',
                'grading_rubric_json',
                'example_entries_json',
                'generation_metadata_json',
                'assistant_json',
                'handoff_rules_json',
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

        $this->maybe_add_nullable_textarea( $values, $formats, $data, 'generated_profile_prompt' );

        if ( array_key_exists( 'created_by_user_id', $data ) )
        {
            $values['created_by_user_id'] = null === $data['created_by_user_id'] ? null : absint( $data['created_by_user_id'] );
            $formats[]                    = '%d';
        }

        $now = $this->now();
        if ( ! $is_update )
        {
            $values['form_source'] = $values['form_source'] ?? sanitize_key( (string) ( $data['form_source'] ?? 'gravity_forms' ) );
            $formats[]             = array_key_exists( 'form_source', $data ) ? '' : '%s';
            $values['form_id']     = $values['form_id'] ?? sanitize_text_field( (string) ( $data['form_id'] ?? '' ) );
            $formats[]             = array_key_exists( 'form_id', $data ) ? '' : '%s';
            $values['status']      = $values['status'] ?? 'draft';
            $formats[]             = array_key_exists( 'status', $data ) ? '' : '%s';
            $values['created_at']  = $now;
            $formats[]             = '%s';
        }

        $values['updated_at'] = $now;
        $formats[]            = '%s';
        $formats              = array_values( array_filter( $formats, static fn ( string $format ): bool => '' !== $format ) );

        if ( ! $is_update && ( '' === (string) $values['form_source'] || '' === (string) $values['form_id'] ) )
        {
            return new WP_Error( 'sentient_forms_missing_lead_profile_form', __( 'Form source and form ID are required for Lead Scoring setup.', 'sentient-forms' ) );
        }

        return [
            'values'  => $values,
            'formats' => $formats,
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string>   $formats
     */
    private function maybe_add_scalar( array &$values, array &$formats, array $data, string $key, callable $sanitizer ): void
    {
        if ( ! array_key_exists( $key, $data ) )
        {
            return;
        }

        $values[ $key ] = $sanitizer( (string) $data[ $key ] );
        $formats[]      = '%s';
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string>   $formats
     */
    private function maybe_add_nullable_text( array &$values, array &$formats, array $data, string $key ): void
    {
        if ( ! array_key_exists( $key, $data ) )
        {
            return;
        }

        $values[ $key ] = null === $data[ $key ] ? null : sanitize_text_field( (string) $data[ $key ] );
        $formats[]      = '%s';
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string>   $formats
     */
    private function maybe_add_nullable_textarea( array &$values, array &$formats, array $data, string $key ): void
    {
        if ( ! array_key_exists( $key, $data ) )
        {
            return;
        }

        $values[ $key ] = null === $data[ $key ] ? null : wp_check_invalid_utf8( (string) $data[ $key ] );
        $formats[]      = '%s';
    }

    private function decode_row( array $row ): array
    {
        foreach (
            [
                'site_context_snapshot_json',
                'spam_guidance_snapshot_json',
                'good_lead_criteria_json',
                'bad_lead_criteria_json',
                'grading_rubric_json',
                'example_entries_json',
                'generation_metadata_json',
                'assistant_json',
                'handoff_rules_json',
            ] as $field
        )
        {
            $row[ $field ] = $this->decode_json_field( $row[ $field ] ?? null );
        }

        $row['id']                   = (int) ( $row['id'] ?? 0 );
        $row['profile_version']      = (int) ( $row['profile_version'] ?? 1 );
        $row['created_by_user_id']   = isset( $row['created_by_user_id'] ) ? (int) $row['created_by_user_id'] : null;

        return $row;
    }
}
