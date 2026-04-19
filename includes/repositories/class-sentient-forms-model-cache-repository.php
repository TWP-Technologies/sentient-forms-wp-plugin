<?php
/**
 * Local provider model metadata cache repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first provider model metadata lives in a plugin-owned custom table with its own TTL. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Model_Cache_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_model_cache';
    }

    public function upsert( string $provider, string $model_id, array $metadata, string $expires_at ): bool | WP_Error
    {
        $provider = sanitize_key( $provider );
        $model_id = sanitize_text_field( $model_id );
        $encoded  = $this->encode_json_field( $metadata, 'metadata_json', true );
        if ( is_wp_error( $encoded ) )
        {
            return $encoded;
        }

        $existing = $this->get( $provider, $model_id, false );
        $row = [
            'provider'      => $provider,
            'model_id'      => $model_id,
            'metadata_json' => $encoded,
            'fetched_at'    => $this->now(),
            'expires_at'    => sanitize_text_field( $expires_at ),
        ];

        if ( $existing )
        {
            return false !== $this->wpdb->update(
                $this->table_name(),
                $row,
                [ 'id' => (int) $existing['id'] ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
        }

        return false !== $this->wpdb->insert(
            $this->table_name(),
            $row,
            [ '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public function upsert_many( string $provider, array $models, string $expires_at ): int | WP_Error
    {
        $stored = 0;

        foreach ( $models as $model )
        {
            if ( ! is_array( $model ) || empty( $model['id'] ) || ! is_string( $model['id'] ) )
            {
                continue;
            }

            $result = $this->upsert( $provider, $model['id'], $model, $expires_at );
            if ( is_wp_error( $result ) )
            {
                return $result;
            }

            if ( $result )
            {
                ++$stored;
            }
        }

        return $stored;
    }

    public function get( string $provider, string $model_id, bool $require_unexpired = true ): ?array
    {
        $wpdb = $this->wpdb;

        if ( $require_unexpired )
        {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE provider = %s AND model_id = %s AND expires_at >= %s LIMIT 1',
                    sanitize_key( $provider ),
                    sanitize_text_field( $model_id ),
                    $this->now()
                ),
                ARRAY_A
            );
        }
        else
        {
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE provider = %s AND model_id = %s LIMIT 1',
                    sanitize_key( $provider ),
                    sanitize_text_field( $model_id )
                ),
                ARRAY_A
            );
        }
        if ( ! $row )
        {
            return null;
        }

        $row['metadata_json'] = $this->decode_json_field( $row['metadata_json'] ?? null ) ?: [];
        return $row;
    }

    public function list( string $provider, bool $include_expired = false, int $limit = 500 ): array
    {
        $wpdb    = $this->wpdb;
        $limit   = max( 1, min( 1000, $limit ) );

        if ( $include_expired )
        {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE provider = %s ORDER BY model_id ASC LIMIT %d',
                    sanitize_key( $provider ),
                    $limit
                ),
                ARRAY_A
            );
        }
        else
        {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . esc_sql( $this->table_name() ) . ' WHERE provider = %s AND expires_at >= %s ORDER BY model_id ASC LIMIT %d',
                    sanitize_key( $provider ),
                    $this->now(),
                    $limit
                ),
                ARRAY_A
            );
        }

        return array_map(
            function ( array $row ): array {
                $row['metadata_json'] = $this->decode_json_field( $row['metadata_json'] ?? null ) ?: [];
                return $row;
            },
            is_array( $rows ) ? $rows : []
        );
    }

    public function purge_expired(): int
    {
        $wpdb = $this->wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . esc_sql( $this->table_name() ) . ' WHERE expires_at < %s',
                $this->now()
            )
        );
        return (int) $this->wpdb->rows_affected;
    }
}
