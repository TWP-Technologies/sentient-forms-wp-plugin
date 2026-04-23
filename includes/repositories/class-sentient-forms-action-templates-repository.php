<?php
/**
 * Local action template repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first action templates live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Action_Templates_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_action_templates';
    }

    public function upsert_by_code( array $data ): int | WP_Error
    {
        $code = sanitize_key( (string) ( $data['code'] ?? '' ) );
        if ( '' === $code )
        {
            return new WP_Error( 'sentient_forms_missing_code', __( 'Template code is required.', 'sentient-forms' ) );
        }

        $structured_output_schema = $this->encode_json_field( $data['structured_output_schema'] ?? null, 'structured_output_schema' );
        if ( is_wp_error( $structured_output_schema ) )
        {
            return $structured_output_schema;
        }

        $override_schema = $this->encode_json_field( $data['override_schema'] ?? null, 'override_schema' );
        if ( is_wp_error( $override_schema ) )
        {
            return $override_schema;
        }

        $now = $this->now();
        $row = [
            'source'                   => sanitize_key( (string) ( $data['source'] ?? 'bundled' ) ),
            'external_id'              => isset( $data['external_id'] ) ? sanitize_text_field( (string) $data['external_id'] ) : null,
            'code'                     => $code,
            'display_name'             => sanitize_text_field( (string) ( $data['display_name'] ?? $code ) ),
            'description'              => isset( $data['description'] ) ? sanitize_textarea_field( (string) $data['description'] ) : null,
            'prompt_template'          => (string) ( $data['prompt_template'] ?? '' ),
            'default_model'            => isset( $data['default_model'] ) ? sanitize_text_field( (string) $data['default_model'] ) : null,
            'structured_output_schema' => $structured_output_schema,
            'override_schema'          => $override_schema,
            'version'                  => sanitize_text_field( (string) ( $data['version'] ?? '1' ) ),
            'is_active'                => empty( $data['is_active'] ) ? 0 : 1,
            'updated_at'               => $now,
        ];

        $existing = $this->get_by_code( $code );
        if ( $existing )
        {
            $updated = $this->wpdb->update(
                $this->table_name(),
                $row,
                [ 'id' => (int) $existing['id'] ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ],
                [ '%d' ]
            );

            if ( false === $updated )
            {
                return new WP_Error( 'sentient_forms_db_update_failed', __( 'Template could not be updated.', 'sentient-forms' ) );
            }

            return (int) $existing['id'];
        }

        $row['created_at'] = $now;
        $previous_suppress = $this->wpdb->suppress_errors( true );
        $inserted = $this->wpdb->insert(
            $this->table_name(),
            $row,
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
        $this->wpdb->suppress_errors( $previous_suppress );

        if ( false === $inserted )
        {
            $existing_after_insert = $this->get_by_code( $code );
            if ( $existing_after_insert )
            {
                $updated = $this->wpdb->update(
                    $this->table_name(),
                    $row,
                    [ 'id' => (int) $existing_after_insert['id'] ],
                    [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ],
                    [ '%d' ]
                );

                if ( false !== $updated )
                {
                    return (int) $existing_after_insert['id'];
                }
            }

            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Template could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function get_by_code( string $code ): ?array
    {
        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE code = %s',
                sanitize_key( $code )
            ),
            ARRAY_A
        );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function get( int $id ): ?array
    {
        $row = $this->get_by_id( $id );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function list_active(): array
    {
        $wpdb = $this->wpdb;
        $rows = $wpdb->get_results(
            'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE is_active = 1 ORDER BY display_name ASC',
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function list_by_source( string $source, bool $active_only = true ): array
    {
        $source = sanitize_key( $source );
        $wpdb   = $this->wpdb;

        if ( $active_only )
        {
            $query = $wpdb->prepare(
                'SELECT * FROM %i WHERE source = %s AND is_active = 1 ORDER BY display_name ASC',
                $this->table_name(),
                $source
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and source placeholder.
            $rows = $wpdb->get_results( $query, ARRAY_A ) ?: [];

            return array_map( [ $this, 'decode_row' ], $rows );
        }

        $query = $wpdb->prepare(
            'SELECT * FROM %i WHERE source = %s ORDER BY display_name ASC',
            $this->table_name(),
            $source
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and source placeholder.
        $rows  = $wpdb->get_results( $query, ARRAY_A ) ?: [];

        return array_map( [ $this, 'decode_row' ], $rows );
    }

    private function decode_row( array $row ): array
    {
        $row['structured_output_schema'] = $this->decode_json_field( $row['structured_output_schema'] ?? null );
        $row['override_schema']          = $this->decode_json_field( $row['override_schema'] ?? null );
        $row['is_active']                = ! empty( $row['is_active'] );
        return $row;
    }
}
