<?php
/**
 * Local form mapping repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first form mappings live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Form_Mappings_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_form_mappings';
    }

    public function create( array $data ): int | WP_Error
    {
        $conditions_json = $this->encode_json_field( $data['conditions_json'] ?? null, 'conditions_json' );
        if ( is_wp_error( $conditions_json ) )
        {
            return $conditions_json;
        }

        $input_bindings_json = $this->encode_json_field( $data['input_bindings_json'] ?? null, 'input_bindings_json', true );
        if ( is_wp_error( $input_bindings_json ) )
        {
            return $input_bindings_json;
        }

        $effect_mapping_json = $this->encode_json_field( $data['effect_mapping_json'] ?? null, 'effect_mapping_json' );
        if ( is_wp_error( $effect_mapping_json ) )
        {
            return $effect_mapping_json;
        }

        $now = $this->now();
        $inserted = $this->wpdb->insert(
            $this->table_name(),
            [
                'external_id'         => isset( $data['external_id'] ) ? sanitize_text_field( (string) $data['external_id'] ) : null,
                'form_source'         => sanitize_key( (string) ( $data['form_source'] ?? 'gravity_forms' ) ),
                'form_id'             => sanitize_text_field( (string) ( $data['form_id'] ?? '' ) ),
                'hook'                => sanitize_key( (string) ( $data['hook'] ?? '' ) ),
                'action_kind'         => sanitize_key( (string) ( $data['action_kind'] ?? '' ) ),
                'action_id'           => (int) ( $data['action_id'] ?? 0 ),
                'conditions_json'     => $conditions_json,
                'input_bindings_json' => $input_bindings_json,
                'execution_mode'      => sanitize_key( (string) ( $data['execution_mode'] ?? 'async' ) ),
                'effect_mapping_json' => $effect_mapping_json,
                'enabled'             => empty( $data['enabled'] ) ? 0 : 1,
                'created_at'          => $now,
                'updated_at'          => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Form mapping could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function list_for_form( string $form_source, string $form_id ): array
    {
        $wpdb = $this->wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE form_source = %s AND form_id = %s ORDER BY id ASC',
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id )
            ),
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function get( int $id ): ?array
    {
        $row = $this->get_by_id( $id );
        return $row ? $this->decode_row( $row ) : null;
    }

    private function decode_row( array $row ): array
    {
        $row['conditions_json']     = $this->decode_json_field( $row['conditions_json'] ?? null );
        $row['input_bindings_json'] = $this->decode_json_field( $row['input_bindings_json'] ?? null ) ?: [];
        $row['effect_mapping_json'] = $this->decode_json_field( $row['effect_mapping_json'] ?? null );
        $row['enabled']             = ! empty( $row['enabled'] );
        return $row;
    }
}
