<?php
/**
 * Local provider credential repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first provider credentials live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Provider_Credentials_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_provider_credentials';
    }

    public function create( array $data ): int | WP_Error
    {
        $provider  = sanitize_key( (string) ( $data['provider'] ?? '' ) );
        $label     = sanitize_text_field( (string) ( $data['label'] ?? '' ) );
        $auth_mode = sanitize_key( (string) ( $data['auth_mode'] ?? '' ) );
        $status    = sanitize_key( (string) ( $data['status'] ?? 'unknown' ) );

        if ( ! in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) )
        {
            return new WP_Error( 'sentient_forms_invalid_provider', __( 'Provider is not supported.', 'sentient-forms' ) );
        }

        if ( '' === $label )
        {
            return new WP_Error( 'sentient_forms_missing_label', __( 'Credential label is required.', 'sentient-forms' ) );
        }

        if ( ! in_array( $auth_mode, [ 'manual_key', 'oauth_broker', 'sentient_proxy', 'constant' ], true ) )
        {
            return new WP_Error( 'sentient_forms_invalid_auth_mode', __( 'Authentication mode is not supported.', 'sentient-forms' ) );
        }

        if ( ! in_array( $status, [ 'unknown', 'valid', 'invalid', 'limited', 'disabled' ], true ) )
        {
            return new WP_Error( 'sentient_forms_invalid_status', __( 'Credential status is not supported.', 'sentient-forms' ) );
        }

        $status_json = $this->encode_json_field( $data['status_json'] ?? null, 'status_json' );
        if ( is_wp_error( $status_json ) )
        {
            return $status_json;
        }

        $now = $this->now();
        $inserted = $this->wpdb->insert(
            $this->table_name(),
            [
                'provider'          => $provider,
                'label'             => $label,
                'auth_mode'         => $auth_mode,
                'encrypted_secret'  => isset( $data['encrypted_secret'] ) ? (string) $data['encrypted_secret'] : null,
                'constant_name'     => isset( $data['constant_name'] ) ? sanitize_text_field( (string) $data['constant_name'] ) : null,
                'status'            => $status,
                'status_json'       => $status_json,
                'last_validated_at' => isset( $data['last_validated_at'] ) ? sanitize_text_field( (string) $data['last_validated_at'] ) : null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Credential could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function get( int $id ): ?array
    {
        $row = $this->get_by_id( $id );
        if ( null === $row )
        {
            return null;
        }

        $row['status_json'] = $this->decode_json_field( $row['status_json'] ?? null );
        return $row;
    }

    public function list( array $args = [] ): array
    {
        $limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 50;
        $wpdb  = $this->wpdb;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' ORDER BY updated_at DESC LIMIT %d',
                $limit
            ),
            ARRAY_A
        ) ?: [];

        foreach ( $rows as &$row )
        {
            $row['status_json'] = $this->decode_json_field( $row['status_json'] ?? null );
        }

        return $rows;
    }

    public function find_by_provider_auth_mode( string $provider, string $auth_mode ): ?array
    {
        $provider  = sanitize_key( $provider );
        $auth_mode = sanitize_key( $auth_mode );
        $wpdb      = $this->wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE provider = %s AND auth_mode = %s ORDER BY updated_at DESC, id DESC LIMIT 1',
                $provider,
                $auth_mode
            ),
            ARRAY_A
        );

        if ( ! $row )
        {
            return null;
        }

        $row['status_json'] = $this->decode_json_field( $row['status_json'] ?? null );
        return $row;
    }

    public function update_status( int $id, string $status, ?array $status_json = null ): bool | WP_Error
    {
        $status = sanitize_key( $status );
        if ( ! in_array( $status, [ 'unknown', 'valid', 'invalid', 'limited', 'disabled' ], true ) )
        {
            return new WP_Error( 'sentient_forms_invalid_status', __( 'Credential status is not supported.', 'sentient-forms' ) );
        }

        $encoded = $this->encode_json_field( $status_json, 'status_json' );
        if ( is_wp_error( $encoded ) )
        {
            return $encoded;
        }

        return false !== $this->wpdb->update(
            $this->table_name(),
            [
                'status'            => $status,
                'status_json'       => $encoded,
                'last_validated_at' => $this->now(),
                'updated_at'        => $this->now(),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );
    }

    public function delete( int $id ): bool | WP_Error
    {
        $id = absint( $id );
        if ( $id <= 0 )
        {
            return new WP_Error( 'sentient_forms_invalid_credential_id', __( 'Credential ID is invalid.', 'sentient-forms' ) );
        }

        if ( null === $this->get_by_id( $id ) )
        {
            return new WP_Error( 'sentient_forms_credential_not_found', __( 'Provider credential could not be found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        $deleted = $this->wpdb->delete(
            $this->table_name(),
            [ 'id' => $id ],
            [ '%d' ]
        );

        if ( false === $deleted )
        {
            return new WP_Error( 'sentient_forms_db_delete_failed', __( 'Provider credential could not be deleted.', 'sentient-forms' ) );
        }

        return 0 < (int) $deleted;
    }
}
