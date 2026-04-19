<?php
/**
 * Local migration run repository.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Local-first migration reports live in a plugin-owned custom table. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Migration_Runs_Repository extends Sentient_Forms_Local_Repository
{
    protected function table_name(): string
    {
        return $this->wpdb->prefix . 'sentient_migration_runs';
    }

    public function create( array $data ): int | WP_Error
    {
        $summary_json = $this->encode_json_field( $data['summary_json'] ?? null, 'summary_json', true );
        if ( is_wp_error( $summary_json ) )
        {
            return $summary_json;
        }

        $conflicts_json = $this->encode_json_field( $data['conflicts_json'] ?? null, 'conflicts_json' );
        if ( is_wp_error( $conflicts_json ) )
        {
            return $conflicts_json;
        }

        $mapping_json = $this->encode_json_field( $data['mapping_json'] ?? null, 'mapping_json' );
        if ( is_wp_error( $mapping_json ) )
        {
            return $mapping_json;
        }

        $inserted = $this->wpdb->insert(
            $this->table_name(),
            [
                'source'         => sanitize_key( (string) ( $data['source'] ?? 'cps_export' ) ),
                'source_version' => isset( $data['source_version'] ) ? sanitize_text_field( (string) $data['source_version'] ) : null,
                'status'         => sanitize_key( (string) ( $data['status'] ?? 'dry_run' ) ),
                'dry_run'        => empty( $data['dry_run'] ) ? 0 : 1,
                'summary_json'   => $summary_json,
                'conflicts_json' => $conflicts_json,
                'mapping_json'   => $mapping_json,
                'actor_user_id'  => isset( $data['actor_user_id'] ) ? (int) $data['actor_user_id'] : null,
                'started_at'     => $this->now(),
                'finished_at'    => null,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        if ( false === $inserted )
        {
            return new WP_Error( 'sentient_forms_db_insert_failed', __( 'Migration run could not be created.', 'sentient-forms' ) );
        }

        return (int) $this->wpdb->insert_id;
    }

    public function mark_finished( int $id, string $status, ?array $summary = null, ?array $conflicts = null, ?array $mapping = null ): bool | WP_Error
    {
        $data = [
            'status'      => sanitize_key( $status ),
            'finished_at' => $this->now(),
        ];
        $formats = [ '%s', '%s' ];

        if ( null !== $summary )
        {
            $summary_json = $this->encode_json_field( $summary, 'summary_json', true );
            if ( is_wp_error( $summary_json ) )
            {
                return $summary_json;
            }
            $data['summary_json'] = $summary_json;
            $formats[] = '%s';
        }

        if ( null !== $conflicts )
        {
            $conflicts_json = $this->encode_json_field( $conflicts, 'conflicts_json' );
            if ( is_wp_error( $conflicts_json ) )
            {
                return $conflicts_json;
            }
            $data['conflicts_json'] = $conflicts_json;
            $formats[] = '%s';
        }

        if ( null !== $mapping )
        {
            $mapping_json = $this->encode_json_field( $mapping, 'mapping_json' );
            if ( is_wp_error( $mapping_json ) )
            {
                return $mapping_json;
            }
            $data['mapping_json'] = $mapping_json;
            $formats[] = '%s';
        }

        return false !== $this->wpdb->update( $this->table_name(), $data, [ 'id' => $id ], $formats, [ '%d' ] );
    }

    public function get( int $id ): ?array
    {
        $row = $this->get_by_id( $id );
        if ( null === $row )
        {
            return null;
        }

        $row['summary_json']   = $this->decode_json_field( $row['summary_json'] ?? null ) ?: [];
        $row['conflicts_json'] = $this->decode_json_field( $row['conflicts_json'] ?? null );
        $row['mapping_json']   = $this->decode_json_field( $row['mapping_json'] ?? null );
        return $row;
    }
}
