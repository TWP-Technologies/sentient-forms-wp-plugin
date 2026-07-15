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

    private function get_by_request_hash( string $request_hash ): ?array
    {
        $wpdb = $this->wpdb;
        $row  = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE request_hash = %s',
                $this->table(),
                $request_hash
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function record( string $request_hash, array $context ): bool | WP_Error
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
            $stored_digest = isset( $existing['payload_digest'] ) && is_scalar( $existing['payload_digest'] )
                ? sanitize_text_field( (string) $existing['payload_digest'] )
                : '';
            if ( '' !== $stored_digest && is_scalar( $digest ) && '' !== (string) $digest && ! hash_equals( $stored_digest, (string) $digest ) )
            {
                return new WP_Error(
                    'sentient_forms_async_request_digest_conflict',
                    __( 'This execution identity is already associated with a different payload.', 'sentient-forms' )
                );
            }

            $existing_status = sanitize_key( (string) ( $existing['status'] ?? '' ) );
            $active_statuses = [ 'queued', 'running', 'success', 'succeeded', 'telemetry_queued' ];
            if ( ! $this->is_expired( $existing ) && in_array( $existing_status, $active_statuses, true ) )
            {
                return false;
            }

            $first_seen = $this->is_expired( $existing ) ? $now : ( $existing['first_seen_at'] ?? $now );
            $cutoff     = wp_date( 'Y-m-d H:i:s', time() - $this->ttl(), wp_timezone() );
            $digest     = is_scalar( $digest ) && '' !== (string) $digest ? (string) $digest : $stored_digest;
            $update_query = $this->wpdb->prepare(
                "UPDATE %i SET action_id = %s, adapter = %s, status = %s, first_seen_at = %s, last_seen_at = %s, last_error = NULL, payload_digest = %s, telemetry_payload = %s WHERE request_hash = %s AND record_type = %s AND (status IN ('failed', 'error', 'retry_pending', 'dependency_wait') OR last_seen_at < %s)",
                $this->table(),
                $action_id,
                $adapter,
                $status,
                $first_seen,
                $now,
                $digest,
                $telemetry_payload,
                $request_hash,
                $record_type,
                $cutoff
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
            $updated = $this->wpdb->query( $update_query );
            if ( false === $updated )
            {
                return new WP_Error(
                    'sentient_forms_async_request_persistence_failed',
                    __( 'The async execution identity could not be persisted.', 'sentient-forms' )
                );
            }

            return 1 === $updated;
        }

        $insert_query = $this->wpdb->prepare(
            'INSERT IGNORE INTO %i (request_hash, action_id, adapter, record_type, status, first_seen_at, last_seen_at, payload_digest, telemetry_payload) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $this->table(),
            $request_hash,
            $action_id,
            $adapter,
            $record_type,
            $status,
            $now,
            $now,
            $digest,
            $telemetry_payload
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
        $inserted = $this->wpdb->query( $insert_query );
        if ( 1 === $inserted )
        {
            return true;
        }

        $owner = $this->get_by_request_hash( $request_hash );
        if ( is_array( $owner ) && $record_type !== sanitize_key( (string) ( $owner['record_type'] ?? '' ) ) )
        {
            return new WP_Error(
                'sentient_forms_async_request_record_type_conflict',
                __( 'This execution identity is already owned by a different lifecycle.', 'sentient-forms' )
            );
        }
        if ( is_array( $owner ) )
        {
            $owner_digest = isset( $owner['payload_digest'] ) && is_scalar( $owner['payload_digest'] )
                ? sanitize_text_field( (string) $owner['payload_digest'] )
                : '';
            if ( '' !== $owner_digest && is_scalar( $digest ) && '' !== (string) $digest && ! hash_equals( $owner_digest, (string) $digest ) )
            {
                return new WP_Error(
                    'sentient_forms_async_request_digest_conflict',
                    __( 'This execution identity is already associated with a different payload.', 'sentient-forms' )
                );
            }

            return false;
        }

        return new WP_Error(
            'sentient_forms_async_request_persistence_failed',
            __( 'The async execution identity could not be persisted.', 'sentient-forms' )
        );
    }

    /**
     * Atomically claim one durable execution identity.
     *
     * Failed work remains terminal unless the caller explicitly declares that
     * replay is safe for the operation being claimed.
     *
     * @return array{state: string, record: array<string, mixed>|null}
     */
    public function claim_execution(
        string $request_hash,
        array $context,
        bool $retry_failed_safely = false,
        string $record_type = 'accepted_sync'
    ): array
    {
        $now       = current_time( 'mysql' );
        $action_id = sanitize_text_field( (string) ( $context['action_id'] ?? '' ) );
        $adapter   = isset( $context['adapter'] ) ? sanitize_key( (string) $context['adapter'] ) : null;
        $digest    = isset( $context['payload_digest'] ) ? sanitize_text_field( (string) $context['payload_digest'] ) : null;
        if ( null === $digest || '' === $digest )
        {
            return [ 'state' => 'digest_conflict', 'record' => null ];
        }

        $insert_query = $this->wpdb->prepare(
            'INSERT IGNORE INTO %i (request_hash, action_id, adapter, record_type, status, first_seen_at, last_seen_at, payload_digest) VALUES (%s, %s, %s, %s, %s, %s, %s, %s)',
            $this->table(),
            $request_hash,
            $action_id,
            $adapter,
            $record_type,
            'running',
            $now,
            $now,
            $digest
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
        $inserted = $this->wpdb->query( $insert_query );
        if ( 1 === $inserted )
        {
            return [
                'state'  => 'claimed',
                'record' => $this->get( $request_hash, $record_type ),
            ];
        }

        $existing = $this->get( $request_hash, $record_type );
        if ( ! is_array( $existing ) )
        {
            $owner = $this->get_by_request_hash( $request_hash );
            if ( is_array( $owner ) && $record_type !== sanitize_key( (string) ( $owner['record_type'] ?? '' ) ) )
            {
                return [ 'state' => 'record_type_conflict', 'record' => $owner ];
            }

            return [ 'state' => 'conflict', 'record' => null ];
        }

        $stored_digest = isset( $existing['payload_digest'] ) && is_scalar( $existing['payload_digest'] )
            ? sanitize_text_field( (string) $existing['payload_digest'] )
            : '';
        if ( '' === $stored_digest || ! hash_equals( $stored_digest, $digest ) )
        {
            return [ 'state' => 'digest_conflict', 'record' => $existing ];
        }

        $status = sanitize_key( (string) ( $existing['status'] ?? '' ) );
        if ( $retry_failed_safely && in_array( $status, [ 'failed', 'error' ], true ) )
        {
            $claim_query = $this->wpdb->prepare(
                "UPDATE %i SET status = 'running', last_seen_at = %s, last_error = NULL WHERE request_hash = %s AND record_type = %s AND payload_digest = %s AND status IN ('failed', 'error')",
                $this->table(),
                $now,
                $request_hash,
                $record_type,
                $digest
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
            $claimed = $this->wpdb->query( $claim_query );
            if ( 1 === $claimed )
            {
                return [
                    'state'  => 'claimed',
                    'record' => $this->get( $request_hash, $record_type ),
                ];
            }

            $existing = $this->get( $request_hash, $record_type );
            $status   = is_array( $existing ) ? sanitize_key( (string) ( $existing['status'] ?? '' ) ) : '';
        }

        if ( in_array( $status, [ 'success', 'succeeded' ], true ) )
        {
            $state = 'success';
        }
        elseif ( in_array( $status, [ 'failed', 'error' ], true ) )
        {
            $state = 'failed';
        }
        else
        {
            $state = 'active';
        }

        return [ 'state' => $state, 'record' => $existing ];
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
