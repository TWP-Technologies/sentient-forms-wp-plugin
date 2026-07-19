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
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool | WP_Error => $this->record_locked( $request_hash, $context )
        );
    }

    /** Persist an async request after the shared local-state fence is held. */
    private function record_locked( string $request_hash, array $context ): bool | WP_Error
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
            if ( 'indeterminate' === $existing_status )
            {
                return false;
            }

            $active_statuses = [ 'queued', 'running', 'success', 'succeeded', 'telemetry_queued' ];
            if ( in_array( $existing_status, $active_statuses, true ) )
            {
                return false;
            }

            $first_seen = $existing['first_seen_at'] ?? $now;
            $digest     = is_scalar( $digest ) && '' !== (string) $digest ? (string) $digest : $stored_digest;
            $update_query = $this->wpdb->prepare(
                "UPDATE %i SET action_id = %s, adapter = %s, status = %s, first_seen_at = %s, last_seen_at = %s, payload_digest = %s, telemetry_payload = %s WHERE request_hash = %s AND record_type = %s AND status IN ('failed', 'error', 'retry_pending', 'dependency_wait')",
                $this->table(),
                $action_id,
                $adapter,
                $status,
                $first_seen,
                $now,
                $digest,
                $telemetry_payload,
                $request_hash,
                $record_type
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
     * @return array{state: string, record: array<string, mixed>|null}|WP_Error
     */
    public function claim_execution(
        string $request_hash,
        array $context,
        bool $retry_failed_safely = false,
        string $record_type = 'accepted_sync'
    ): array | WP_Error
    {
        $result = Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): array | WP_Error => $this->claim_execution_locked( $request_hash, $context, $retry_failed_safely, $record_type )
        );

        return $result;
    }

    /** Claim after the shared local-state fence is held. */
    private function claim_execution_locked(
        string $request_hash,
        array $context,
        bool $retry_failed_safely = false,
        string $record_type = 'accepted_sync'
    ): array | WP_Error
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
        if ( false === $inserted )
        {
            return new WP_Error(
                'sentient_forms_async_request_persistence_failed',
                __( 'The async execution identity could not be persisted.', 'sentient-forms' )
            );
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
            if ( false === $claimed )
            {
                return new WP_Error(
                    'sentient_forms_async_request_persistence_failed',
                    __( 'The async execution identity could not be persisted.', 'sentient-forms' )
                );
            }

            $existing = $this->get( $request_hash, $record_type );
            $status   = is_array( $existing ) ? sanitize_key( (string) ( $existing['status'] ?? '' ) ) : '';
        }

        if ( 'indeterminate' === $status )
        {
            $state = 'indeterminate';
        }
        elseif ( in_array( $status, [ 'success', 'succeeded' ], true ) )
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

    public function mark_status( string $request_hash, string $status, ?string $error = null, string $record_type = 'job' ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool | WP_Error => $this->mark_status_locked( $request_hash, $status, $error, $record_type )
        );
    }

    /** Update status after the shared local-state fence is held. */
    private function mark_status_locked( string $request_hash, string $status, ?string $error = null, string $record_type = 'job' ): bool | WP_Error
    {
        $updated = $this->wpdb->update(
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

        if ( false === $updated )
        {
            return new WP_Error(
                'sentient_forms_async_request_persistence_failed',
                __( 'The async execution status could not be persisted.', 'sentient-forms' )
            );
        }
        if ( 1 === $updated )
        {
            return true;
        }

        $persisted = $this->get( $request_hash, $record_type );
        if ( ! is_array( $persisted ) )
        {
            return new WP_Error(
                'sentient_forms_async_request_missing',
                __( 'The authoritative async execution request no longer exists.', 'sentient-forms' )
            );
        }
        if (
            sanitize_key( (string) ( $persisted['status'] ?? '' ) ) === sanitize_key( $status )
            && (string) ( $persisted['last_error'] ?? '' ) === (string) ( $error ?? '' )
        )
        {
            return true;
        }

        return new WP_Error(
            'sentient_forms_async_request_transition_conflict',
            __( 'The authoritative async execution request is in a conflicting state.', 'sentient-forms' )
        );
    }

    /** Commit a terminal outcome only for the worker that owns the running lease. */
    public function finish_execution( string $request_hash, string $status, ?string $error = null, string $record_type = 'job' ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool | WP_Error => $this->finish_execution_locked( $request_hash, $status, $error, $record_type )
        );
    }

    private function finish_execution_locked( string $request_hash, string $status, ?string $error, string $record_type ): bool | WP_Error
    {
        $status = sanitize_key( $status );
        if ( ! in_array( $status, [ 'success', 'failed', 'skipped', 'indeterminate' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_async_request_invalid_terminal_status',
                __( 'The requested async terminal status is not supported.', 'sentient-forms' )
            );
        }

        $query = $this->wpdb->prepare(
            'UPDATE %i SET status = %s, last_seen_at = %s, last_error = %s WHERE request_hash = %s AND record_type = %s AND status = %s',
            $this->table(),
            $status,
            current_time( 'mysql' ),
            $error,
            $request_hash,
            $record_type,
            'running'
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
        $updated = $this->wpdb->query( $query );
        if ( false === $updated )
        {
            return new WP_Error(
                'sentient_forms_async_request_persistence_failed',
                __( 'The async execution outcome could not be persisted.', 'sentient-forms' )
            );
        }
        if ( 1 === $updated )
        {
            return true;
        }

        $persisted = $this->get( $request_hash, $record_type );
        if ( ! is_array( $persisted ) )
        {
            return new WP_Error(
                'sentient_forms_async_request_missing',
                __( 'The authoritative async execution request no longer exists.', 'sentient-forms' )
            );
        }
        if (
            $status === sanitize_key( (string) ( $persisted['status'] ?? '' ) )
            && (string) ( $persisted['last_error'] ?? '' ) === (string) ( $error ?? '' )
        )
        {
            return true;
        }

        return new WP_Error(
            'sentient_forms_async_request_transition_conflict',
            __( 'The authoritative async execution request does not own a running lease.', 'sentient-forms' )
        );
    }

    /** Release a running lease into the only state that scheduling may reclaim. */
    public function prepare_retry( string $request_hash, string $record_type, string $error ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool | WP_Error => $this->prepare_reschedule_locked( $request_hash, $record_type, 'retry_pending', $error )
        );
    }

    /** Release a running lease while its dependency remains incomplete. */
    public function prepare_dependency_wait( string $request_hash, string $record_type, string $error ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool | WP_Error => $this->prepare_reschedule_locked( $request_hash, $record_type, 'dependency_wait', $error )
        );
    }

    private function prepare_reschedule_locked( string $request_hash, string $record_type, string $status, string $error ): bool | WP_Error
    {
        if ( ! in_array( $status, [ 'retry_pending', 'dependency_wait' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_async_request_invalid_reschedule_status',
                __( 'The requested async reschedule status is not supported.', 'sentient-forms' )
            );
        }

        $query = $this->wpdb->prepare(
            "UPDATE %i SET status = %s, last_seen_at = %s, last_error = %s WHERE request_hash = %s AND record_type = %s AND status = 'running'",
            $this->table(),
            $status,
            current_time( 'mysql' ),
            $error,
            $request_hash,
            $record_type
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
        $updated = $this->wpdb->query( $query );
        if ( false === $updated )
        {
            return new WP_Error(
                'sentient_forms_async_request_persistence_failed',
                __( 'The async execution retry state could not be persisted.', 'sentient-forms' )
            );
        }
        if ( 1 === $updated )
        {
            return true;
        }

        $persisted = $this->get( $request_hash, $record_type );
        if ( ! is_array( $persisted ) )
        {
            return new WP_Error(
                'sentient_forms_async_request_missing',
                __( 'The authoritative async execution request no longer exists.', 'sentient-forms' )
            );
        }

        return new WP_Error(
            'sentient_forms_async_request_transition_conflict',
            __( 'The authoritative async execution request does not own a running lease.', 'sentient-forms' )
        );
    }

    /**
     * Claim a previously queued callback exactly once before it may cross the effect boundary.
     *
     * @return array{state:string,record:array<string,mixed>|null}|WP_Error
     */
    public function claim_queued_execution( string $request_hash, string $record_type, string $payload_digest ): array | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): array | WP_Error => $this->claim_queued_execution_locked( $request_hash, $record_type, $payload_digest )
        );
    }

    /** @return array{state:string,record:array<string,mixed>|null}|WP_Error */
    private function claim_queued_execution_locked( string $request_hash, string $record_type, string $payload_digest ): array | WP_Error
    {
        if ( '' === $request_hash || '' === $payload_digest )
        {
            return [ 'state' => 'digest_conflict', 'record' => null ];
        }

        $claim_query = $this->wpdb->prepare(
            "UPDATE %i SET status = 'running', last_seen_at = %s, last_error = NULL WHERE request_hash = %s AND record_type = %s AND payload_digest = %s AND status = 'queued'",
            $this->table(),
            current_time( 'mysql' ),
            $request_hash,
            $record_type,
            $payload_digest
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
        $claimed = $this->wpdb->query( $claim_query );
        if ( false === $claimed )
        {
            return new WP_Error(
                'sentient_forms_async_request_persistence_failed',
                __( 'The async execution lease could not be persisted.', 'sentient-forms' )
            );
        }
        if ( 1 === $claimed )
        {
            return [ 'state' => 'claimed', 'record' => $this->get( $request_hash, $record_type ) ];
        }

        $record = $this->get( $request_hash, $record_type );
        if ( ! is_array( $record ) )
        {
            return [ 'state' => 'missing', 'record' => null ];
        }
        $stored_digest = is_scalar( $record['payload_digest'] ?? null ) ? (string) $record['payload_digest'] : '';
        if ( '' === $stored_digest || ! hash_equals( $stored_digest, $payload_digest ) )
        {
            return [ 'state' => 'digest_conflict', 'record' => $record ];
        }

        $status = sanitize_key( (string) ( $record['status'] ?? '' ) );
        if ( 'indeterminate' === $status )
        {
            return [ 'state' => 'indeterminate', 'record' => $record ];
        }
        if ( in_array( $status, [ 'success', 'succeeded', 'failed', 'error', 'skipped' ], true ) )
        {
            return [ 'state' => 'terminal', 'record' => $record ];
        }

        return [ 'state' => 'active', 'record' => $record ];
    }

    /**
     * Reject only the queued callback identified by the supplied digest.
     *
     * A disabled stale callback must never rewrite a completed or uncertain
     * execution, because doing so would make its effect replayable later.
     *
     * @return array{state:string,record:array<string,mixed>|null}|WP_Error
     */
    public function reject_queued_execution(
        string $request_hash,
        string $record_type,
        string $payload_digest,
        string $error
    ): array | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): array | WP_Error => $this->reject_queued_execution_locked(
                $request_hash,
                $record_type,
                $payload_digest,
                $error
            )
        );
    }

    /** @return array{state:string,record:array<string,mixed>|null}|WP_Error */
    private function reject_queued_execution_locked(
        string $request_hash,
        string $record_type,
        string $payload_digest,
        string $error
    ): array | WP_Error
    {
        if ( '' === $request_hash || '' === $payload_digest )
        {
            return [ 'state' => 'digest_conflict', 'record' => null ];
        }

        $query = $this->wpdb->prepare(
            "UPDATE %i SET status = 'failed', last_seen_at = %s, last_error = %s WHERE request_hash = %s AND record_type = %s AND payload_digest = %s AND status = 'queued'",
            $this->table(),
            current_time( 'mysql' ),
            $error,
            $request_hash,
            $record_type,
            $payload_digest
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above with an identifier placeholder and scalar value placeholders.
        $updated = $this->wpdb->query( $query );
        if ( false === $updated )
        {
            return new WP_Error(
                'sentient_forms_async_request_persistence_failed',
                __( 'The disabled async execution could not be rejected safely.', 'sentient-forms' )
            );
        }
        if ( 1 === $updated )
        {
            return [ 'state' => 'rejected', 'record' => $this->get( $request_hash, $record_type ) ];
        }

        $record = $this->get( $request_hash, $record_type );
        if ( ! is_array( $record ) )
        {
            return [ 'state' => 'missing', 'record' => null ];
        }
        $stored_digest = is_scalar( $record['payload_digest'] ?? null ) ? (string) $record['payload_digest'] : '';
        if ( '' === $stored_digest || ! hash_equals( $stored_digest, $payload_digest ) )
        {
            return [ 'state' => 'digest_conflict', 'record' => $record ];
        }

        $status = sanitize_key( (string) ( $record['status'] ?? '' ) );
        if ( 'indeterminate' === $status )
        {
            return [ 'state' => 'indeterminate', 'record' => $record ];
        }
        if ( in_array( $status, [ 'success', 'succeeded', 'failed', 'error', 'skipped' ], true ) )
        {
            return [ 'state' => 'terminal', 'record' => $record ];
        }

        return [ 'state' => 'active', 'record' => $record ];
    }

    public function has_active_executions(): bool | WP_Error
    {
        return $this->has_active_executions_locked();
    }

    private function has_active_executions_locked(): bool | WP_Error
    {
        $this->wpdb->last_error = '';
        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE record_type <> 'telemetry' AND status IN ('running', 'retry_pending', 'dependency_wait', 'indeterminate')",
                $this->table()
            )
        );
        if ( null === $count && '' !== (string) $this->wpdb->last_error )
        {
            return new WP_Error(
                'sentient_forms_async_request_read_failed',
                __( 'The authoritative async execution state could not be read.', 'sentient-forms' )
            );
        }

        return 0 < (int) $count;
    }

    public function should_block( string $request_hash, string $record_type = 'job' ): bool
    {
        $record = $this->get( $request_hash, $record_type );
        if ( ! $record )
        {
            return false;
        }

        $status = sanitize_key( (string) ( $record['status'] ?? 'queued' ) );
        if ( in_array( $status, [ 'queued', 'running', 'success', 'succeeded', 'indeterminate' ], true ) )
        {
            return true;
        }

        if ( $this->is_expired( $record ) )
        {
            return false;
        }

        return 'telemetry_queued' === $status;
    }

    private function is_expired( array $record ): bool
    {
        $last_seen = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            (string) ( $record['last_seen_at'] ?? '' ),
            wp_timezone()
        );
        $parse_errors = DateTimeImmutable::getLastErrors();
        if (
            false === $last_seen
            || ( is_array( $parse_errors ) && ( 0 < $parse_errors['warning_count'] || 0 < $parse_errors['error_count'] ) )
        )
        {
            return false;
        }

        return $last_seen->getTimestamp() < time() - $this->ttl();
    }

    public function purge_older_than( int $timestamp ): int
    {
        $result = Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): int => $this->purge_older_than_locked( $timestamp )
        );

        return is_wp_error( $result ) ? 0 : $result;
    }

    /** Purge after the shared local-state fence is held. */
    private function purge_older_than_locked( int $timestamp ): int
    {
        $mysql = wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
        $wpdb  = $this->wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE last_seen_at < %s AND status IN ('success', 'succeeded', 'failed', 'error', 'skipped', 'telemetry_sent')",
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
        $recorded = $this->record(
            $hash,
            [
                'action_id'        => $event_type,
                'adapter'          => 'telemetry',
                'status'           => 'telemetry_queued',
                'record_type'      => 'telemetry',
                'telemetry_payload'=> $payload,
            ]
        );

        return is_wp_error( $recorded ) ? '' : $hash;
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

    public function update_telemetry_status( string $hash, string $status, ?string $error = null ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool => $this->update_telemetry_status_locked( $hash, $status, $error )
        );
    }

    /** Update telemetry after the shared local-state fence is held. */
    private function update_telemetry_status_locked( string $hash, string $status, ?string $error = null ): bool
    {
        $updated = $this->wpdb->update(
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

        return false !== $updated;
    }
}
