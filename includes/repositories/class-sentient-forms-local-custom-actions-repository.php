<?php
/**
 * Local custom action repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first custom actions live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Local_Custom_Actions_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_custom_actions';
    }

    public function create( array $data ): int | WP_Error
    {
        $code = sanitize_key( (string) ( $data['code'] ?? '' ) );
        if ( '' === $code )
        {
            return new WP_Error( 'sentient_forms_missing_code', __( 'Custom action code is required.', 'sentient-forms' ) );
        }

        $definition_json = $this->encode_json_field( $data['definition_json'] ?? null, 'definition_json', true );
        if ( is_wp_error( $definition_json ) )
        {
            return $definition_json;
        }

        $model_selection_json = $this->encode_json_field( $data['model_selection_json'] ?? null, 'model_selection_json' );
        if ( is_wp_error( $model_selection_json ) )
        {
            return $model_selection_json;
        }

        $now = $this->now();
        $inserted = $this->wpdb->insert(
            $this->table_name(),
            [
                'external_id'          => isset( $data['external_id'] ) ? sanitize_text_field( (string) $data['external_id'] ) : null,
                'template_id'          => isset( $data['template_id'] ) ? (int) $data['template_id'] : null,
                'code'                 => $code,
                'display_name'         => sanitize_text_field( (string) ( $data['display_name'] ?? $code ) ),
                'definition_json'      => $definition_json,
                'model_selection_json' => $model_selection_json,
                'status'               => sanitize_key( (string) ( $data['status'] ?? 'active' ) ),
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Custom action could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function upsert_by_code( array $data ): int | WP_Error
    {
        $code = sanitize_key( (string) ( $data['code'] ?? '' ) );
        if ( '' === $code )
        {
            return new WP_Error( 'sentient_forms_missing_code', __( 'Custom action code is required.', 'sentient-forms' ) );
        }

        $definition_json = $this->encode_json_field( $data['definition_json'] ?? null, 'definition_json', true );
        if ( is_wp_error( $definition_json ) )
        {
            return $definition_json;
        }

        $model_selection_json = $this->encode_json_field( $data['model_selection_json'] ?? null, 'model_selection_json' );
        if ( is_wp_error( $model_selection_json ) )
        {
            return $model_selection_json;
        }

        $now = $this->now();
        $row = [
            'external_id'          => isset( $data['external_id'] ) ? sanitize_text_field( (string) $data['external_id'] ) : null,
            'template_id'          => isset( $data['template_id'] ) ? (int) $data['template_id'] : null,
            'code'                 => $code,
            'display_name'         => sanitize_text_field( (string) ( $data['display_name'] ?? $code ) ),
            'definition_json'      => $definition_json,
            'model_selection_json' => $model_selection_json,
            'status'               => sanitize_key( (string) ( $data['status'] ?? 'active' ) ),
            'updated_at'           => $now,
        ];

        $existing = $this->get_by_code( $code );
        if ( $existing )
        {
            $updated = $this->wpdb->update(
                $this->table_name(),
                $row,
                [ 'id' => (int) $existing['id'] ],
                [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );

            if ( false === $updated )
            {
                return new WP_Error( 'sentient_forms_db_update_failed', __( 'Custom action could not be updated.', 'sentient-forms' ) );
            }

            return (int) $existing['id'];
        }

        $row['created_at'] = $now;
        $inserted = $this->wpdb->insert(
            $this->table_name(),
            $row,
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Custom action could not be created.', 'sentient-forms' ) );
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

    public function list( string $status = 'active' ): array
    {
        $wpdb = $this->wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE status = %s ORDER BY updated_at DESC',
                sanitize_key( $status )
            ),
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function update_status( int $id, string $status ): bool
    {
        return false !== $this->wpdb->update(
            $this->table_name(),
            [
                'status'     => sanitize_key( $status ),
                'updated_at' => $this->now(),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    private function decode_row( array $row ): array
    {
        $row['definition_json']      = $this->decode_json_field( $row['definition_json'] ?? null ) ?: [];
        $row['model_selection_json'] = $this->decode_json_field( $row['model_selection_json'] ?? null );
        return $row;
    }
}
