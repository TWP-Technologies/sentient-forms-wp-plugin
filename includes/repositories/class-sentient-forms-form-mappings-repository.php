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
        $hook = $this->normalize_hook( $data['hook'] ?? '' );
        if ( is_wp_error( $hook ) )
        {
            return $hook;
        }

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

        $settings_json = $this->encode_json_field( $data['settings_json'] ?? null, 'settings_json' );
        if ( is_wp_error( $settings_json ) )
        {
            return $settings_json;
        }

        $now = $this->now();
        $inserted = $this->wpdb->insert(
            $this->table_name(),
            [
                'external_id'         => isset( $data['external_id'] ) ? sanitize_text_field( (string) $data['external_id'] ) : null,
                'form_source'         => sanitize_key( (string) ( $data['form_source'] ?? 'gravity_forms' ) ),
                'form_id'             => sanitize_text_field( (string) ( $data['form_id'] ?? '' ) ),
                'hook'                => $hook,
                'action_kind'         => sanitize_key( (string) ( $data['action_kind'] ?? '' ) ),
                'action_id'           => (int) ( $data['action_id'] ?? 0 ),
                'conditions_json'     => $conditions_json,
                'input_bindings_json' => $input_bindings_json,
                'execution_mode'      => sanitize_key( (string) ( $data['execution_mode'] ?? 'async' ) ),
                'effect_mapping_json' => $effect_mapping_json,
                'settings_json'       => $settings_json,
                'enabled'             => empty( $data['enabled'] ) ? 0 : 1,
                'created_at'          => $now,
                'updated_at'          => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
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
                'SELECT * FROM %i WHERE form_source = %s AND form_id = %s ORDER BY id ASC',
                $this->table_name(),
                sanitize_key( $form_source ),
                sanitize_text_field( $form_id )
            ),
            ARRAY_A
        ) ?: [];
        return array_map( [ $this, 'decode_row' ], $rows );
    }

    /**
     * @param array<int, int|string> $form_ids Form IDs to load.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function list_for_forms( string $form_source, array $form_ids ): array
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
            $form_rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE form_source = %s AND form_id = %s ORDER BY id ASC',
                    $this->table_name(),
                    sanitize_key( $form_source ),
                    $normalized_id
                ),
                ARRAY_A
            ) ?: [];

            $rows = array_merge( $rows, $form_rows );
        }

        $grouped = [];
        foreach ( $rows as $row )
        {
            $decoded = $this->decode_row( $row );
            $form_id = sanitize_text_field( (string) ( $decoded['form_id'] ?? '' ) );
            if ( '' === $form_id )
            {
                continue;
            }

            if ( ! isset( $grouped[ $form_id ] ) )
            {
                $grouped[ $form_id ] = [];
            }
            $grouped[ $form_id ][] = $decoded;
        }

        return $grouped;
    }

    public function get( int $id ): ?array
    {
        $row = $this->get_by_id( $id );
        return $row ? $this->decode_row( $row ) : null;
    }

    public function list_enabled_for_action( int $action_id, string $action_kind = 'custom_action' ): array
    {
        $action_id = absint( $action_id );
        if ( $action_id <= 0 )
        {
            return [];
        }

        $wpdb = $this->wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE action_kind = %s AND action_id = %d AND enabled = 1 ORDER BY id ASC',
                $this->table_name(),
                sanitize_key( $action_kind ),
                $action_id
            ),
            ARRAY_A
        ) ?: [];

        return array_map( [ $this, 'decode_row' ], $rows );
    }

    public function update( int $id, array $data ): array | WP_Error
    {
        $id = absint( $id );
        if ( $id <= 0 )
        {
            return new WP_Error( 'sentient_forms_invalid_mapping_id', __( 'Form mapping ID is invalid.', 'sentient-forms' ) );
        }

        $fields  = [];
        $formats = [];

        if ( array_key_exists( 'external_id', $data ) )
        {
            $fields['external_id'] = null === $data['external_id'] ? null : sanitize_text_field( (string) $data['external_id'] );
            $formats[]             = '%s';
        }

        if ( array_key_exists( 'form_source', $data ) )
        {
            $fields['form_source'] = sanitize_key( (string) $data['form_source'] );
            $formats[]             = '%s';
        }

        if ( array_key_exists( 'form_id', $data ) )
        {
            $fields['form_id'] = sanitize_text_field( (string) $data['form_id'] );
            $formats[]         = '%s';
        }

        if ( array_key_exists( 'hook', $data ) )
        {
            $hook = $this->normalize_hook( $data['hook'] );
            if ( is_wp_error( $hook ) )
            {
                return $hook;
            }

            $fields['hook'] = $hook;
            $formats[]      = '%s';
        }

        if ( array_key_exists( 'action_kind', $data ) )
        {
            $fields['action_kind'] = sanitize_key( (string) $data['action_kind'] );
            $formats[]             = '%s';
        }

        if ( array_key_exists( 'action_id', $data ) )
        {
            $fields['action_id'] = (int) $data['action_id'];
            $formats[]           = '%d';
        }

        if ( array_key_exists( 'conditions_json', $data ) )
        {
            $conditions_json = $this->encode_json_field( $data['conditions_json'], 'conditions_json' );
            if ( is_wp_error( $conditions_json ) )
            {
                return $conditions_json;
            }

            $fields['conditions_json'] = $conditions_json;
            $formats[]                 = '%s';
        }

        if ( array_key_exists( 'input_bindings_json', $data ) )
        {
            $input_bindings_json = $this->encode_json_field( $data['input_bindings_json'], 'input_bindings_json', true );
            if ( is_wp_error( $input_bindings_json ) )
            {
                return $input_bindings_json;
            }

            $fields['input_bindings_json'] = $input_bindings_json;
            $formats[]                     = '%s';
        }

        if ( array_key_exists( 'execution_mode', $data ) )
        {
            $fields['execution_mode'] = sanitize_key( (string) $data['execution_mode'] );
            $formats[]                = '%s';
        }

        if ( array_key_exists( 'effect_mapping_json', $data ) )
        {
            $effect_mapping_json = $this->encode_json_field( $data['effect_mapping_json'], 'effect_mapping_json' );
            if ( is_wp_error( $effect_mapping_json ) )
            {
                return $effect_mapping_json;
            }

            $fields['effect_mapping_json'] = $effect_mapping_json;
            $formats[]                     = '%s';
        }

        if ( array_key_exists( 'settings_json', $data ) )
        {
            $settings_json = $this->encode_json_field( $data['settings_json'], 'settings_json' );
            if ( is_wp_error( $settings_json ) )
            {
                return $settings_json;
            }

            $fields['settings_json'] = $settings_json;
            $formats[]               = '%s';
        }

        if ( array_key_exists( 'enabled', $data ) )
        {
            $fields['enabled'] = empty( $data['enabled'] ) ? 0 : 1;
            $formats[]         = '%d';
        }

        $fields['updated_at'] = $this->now();
        $formats[]            = '%s';

        $updated = $this->wpdb->update(
            $this->table_name(),
            $fields,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );

        if ( false === $updated )
        {
            return new WP_Error( 'sentient_forms_db_update_failed', __( 'Form mapping could not be updated.', 'sentient-forms' ) );
        }

        $row = $this->get( $id );
        if ( null === $row )
        {
            return new WP_Error( 'sentient_forms_mapping_not_found', __( 'Form mapping could not be found after update.', 'sentient-forms' ) );
        }

        return $row;
    }

    public function delete( int $id ): bool
    {
        $id = absint( $id );
        if ( $id <= 0 )
        {
            return false;
        }

        return false !== $this->wpdb->delete(
            $this->table_name(),
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    private function normalize_hook( mixed $hook ): string | WP_Error
    {
        $normalized = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
        if ( null === $normalized )
        {
            return new WP_Error(
                'sentient_forms_invalid_mapping_hook',
                __( 'Form mapping hook must be a supported lifecycle ID.', 'sentient-forms' )
            );
        }

        return $normalized;
    }

    private function decode_row( array $row ): array
    {
        $row['conditions_json']     = $this->decode_json_field( $row['conditions_json'] ?? null );
        $row['input_bindings_json'] = $this->decode_json_field( $row['input_bindings_json'] ?? null ) ?: [];
        $row['effect_mapping_json'] = $this->decode_json_field( $row['effect_mapping_json'] ?? null );
        $row['settings_json']       = $this->decode_json_field( $row['settings_json'] ?? null ) ?: [];
        $row['enabled']             = ! empty( $row['enabled'] );
        return $row;
    }
}
