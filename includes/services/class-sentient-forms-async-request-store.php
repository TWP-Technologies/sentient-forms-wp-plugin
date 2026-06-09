<?php
/**
 * Persistent store for async idempotency requests.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Async idempotency records live in a plugin-owned custom table, not post/user/option metadata. WordPress core has no native CRUD/cache API for these rows; SQL is prepared and table names are escaped at each call site.
class Sentient_Forms_Async_Request_Store
{
    private const DEFAULT_TTL = DAY_IN_SECONDS;

    public function __construct( private wpdb $wpdb )
    {
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'sentient_async_requests';
    }

    private function ttl(): int
    {
        $ttl = (int) apply_filters( 'sentient_forms_async_request_ttl', self::DEFAULT_TTL );
        return max( HOUR_IN_SECONDS, $ttl );
    }

    public function get( string $request_hash, string $record_type = 'job' ): ?array
    {
        $wpdb = $this->wpdb;
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE request_hash = %s AND record_type = %s',
                $this->table(),
                $request_hash,
                $record_type
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function record( string $request_hash, array $context ): void
    {
        $now         = current_time( 'mysql' );
        $action_id   = $context['action_id'] ?? '';
        $adapter     = $context['adapter'] ?? null;
        $status      = $context['status'] ?? 'queued';
        $digest      = $context['payload_digest'] ?? null;
        $record_type = $context['record_type'] ?? 'job';
        $telemetry_payload = isset( $context['telemetry_payload'] ) ? wp_json_encode( $context['telemetry_payload'] ) : null;

        $existing = $this->get( $request_hash, $record_type );
        if ( $existing )
        {
            $first_seen = $this->is_expired( $existing ) ? $now : ( $existing['first_seen_at'] ?? $now );
            $this->wpdb->update(
                $this->table(),
                [
                    'action_id'     => $action_id,
                    'adapter'       => $adapter,
                    'status'        => $status,
                    'first_seen_at' => $first_seen,
                    'last_seen_at'  => $now,
                    'last_error'    => null,
                    'payload_digest'=> $digest,
                    'telemetry_payload' => $telemetry_payload,
                ],
                [ 'request_hash' => $request_hash ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ],
                [ '%s' ]
            );
            return;
        }

        $this->wpdb->insert(
            $this->table(),
            [
                'request_hash' => $request_hash,
                'action_id'    => $action_id,
                'adapter'      => $adapter,
                'record_type'  => $record_type,
                'status'       => $status,
                'first_seen_at'=> $now,
                'last_seen_at' => $now,
                'payload_digest' => $digest,
                'telemetry_payload' => $telemetry_payload,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    public function mark_status( string $request_hash, string $status, ?string $error = null, string $record_type = 'job' ): void
    {
        $this->wpdb->update(
            $this->table(),
            [
                'status'       => $status,
                'last_seen_at' => current_time( 'mysql' ),
                'last_error'   => $error,
            ],
            [
                'request_hash' => $request_hash,
                'record_type'  => $record_type,
            ],
            [ '%s', '%s', '%s' ],
            [ '%s', '%s' ]
        );
    }

    public function should_block( string $request_hash, string $record_type = 'job' ): bool
    {
        $record = $this->get( $request_hash, $record_type );
        if ( ! $record )
        {
            return false;
        }

        if ( $this->is_expired( $record ) )
        {
            return false;
        }

        $status = $record['status'] ?? 'queued';
        return in_array( $status, [ 'queued', 'running', 'success' ], true );
    }

    private function is_expired( array $record ): bool
    {
        $ttl = $this->ttl();
        $cutoff = time() - $ttl;
        $last_seen = strtotime( $record['last_seen_at'] ?? 'now' );
        return $last_seen < $cutoff;
    }

    public function purge_older_than( int $timestamp ): int
    {
        $mysql = gmdate( 'Y-m-d H:i:s', $timestamp );
        $wpdb  = $this->wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE last_seen_at < %s',
                $this->table(),
                $mysql
            )
        );
        return (int) $this->wpdb->rows_affected;
    }

    public function list( array $args = [] ): array
    {
        $limit  = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 20;
        $status = $args['status'] ?? null;
        $record_type = $args['record_type'] ?? 'job';

        $wpdb = $this->wpdb;

        if ( $status )
        {
            return $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE record_type = %s AND status = %s ORDER BY last_seen_at DESC LIMIT %d',
                    $this->table(),
                    $record_type,
                    $status,
                    $limit
                ),
                ARRAY_A
            ) ?: [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE record_type = %s ORDER BY last_seen_at DESC LIMIT %d',
                $this->table(),
                $record_type,
                $limit
            ),
            ARRAY_A
        ) ?: [];
    }

    public function enqueue_telemetry( string $event_type, array $payload ): string
    {
        $hash = wp_hash( $event_type . wp_json_encode( $payload ) . microtime( true ) );
        $this->record(
            $hash,
            [
                'action_id'        => $event_type,
                'adapter'          => 'telemetry',
                'status'           => 'telemetry_queued',
                'record_type'      => 'telemetry',
                'telemetry_payload'=> $payload,
            ]
        );

        return $hash;
    }

    public function claim_telemetry_batch( int $limit = 25 ): array
    {
        $rows = $this->list(
            [
                'limit'       => $limit,
                'record_type' => 'telemetry',
                'status'      => 'telemetry_queued',
            ]
        );

        return $rows;
    }

    public function update_telemetry_status( string $hash, string $status, ?string $error = null ): void
    {
        $this->wpdb->update(
            $this->table(),
            [
                'status'          => $status,
                'last_seen_at'    => current_time( 'mysql' ),
                'last_error'      => $error,
            ],
            [ 'request_hash' => $hash ],
            [ '%s', '%s', '%s' ],
            [ '%s' ]
        );
    }
}
