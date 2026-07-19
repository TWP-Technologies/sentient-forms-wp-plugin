<?php
/**
 * Lightweight store for tracking async job metadata.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Async_Metadata_Store
{
    private const OPTION = 'sentient_forms_async_jobs';
    private const MAX_JOBS = 50;

    /**
     * Record a newly scheduled job.
     *
     * @param string               $job_id             UUID assigned to the job context.
     * @param string               $hook               Hook name (local mapping or evaluation).
     * @param array<string, mixed> $payload            Payload passed to Action Scheduler.
     * @param int                  $run_at             Timestamp when the job is scheduled to run.
     * @param int|null             $action_scheduler_id Optional Action Scheduler action ID.
     * @param string|null          $group              Action Scheduler group slug.
     */
    public function record_job( string $job_id, string $hook, array $payload, int $run_at, ?int $action_scheduler_id = null, ?string $group = null ): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool | WP_Error => $this->record_job_locked( $job_id, $hook, $payload, $run_at, $action_scheduler_id, $group )
        );
    }

    /** Record a job after the complete read-modify-write fence is held. */
    private function record_job_locked( string $job_id, string $hook, array $payload, int $run_at, ?int $action_scheduler_id, ?string $group ): bool | WP_Error
    {
        $jobs = $this->get_jobs();
        $filtered_payload = $this->filter_payload( $payload, $hook );
        $jobs[ $job_id ] = [
            'job_id'       => $job_id,
            'hook'         => $hook,
            'status'       => 'queued',
            'scheduled_at' => time(),
            'run_at'       => $run_at,
            'context'      => $payload['context'] ?? [],
            'action_id'    => $payload['context']['action_id'] ?? null,
            'last_error'   => null,
            'action_scheduler_id' => $action_scheduler_id,
            'group'        => $group,
            'payload'      => $filtered_payload,
        ];

        return $this->persist_locked( $this->trim( $jobs ) );
    }

    /**
     * Update a job's status.
     */
    public function update_status( ?string $job_id, string $status, array $extra = [] ): bool | WP_Error
    {
        if ( empty( $job_id ) )
        {
            return false;
        }

        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): bool | WP_Error => $this->update_status_locked( $job_id, $status, $extra )
        );
    }

    /** Update status after the complete read-modify-write fence is held. */
    private function update_status_locked( string $job_id, string $status, array $extra ): bool | WP_Error
    {
        $jobs = $this->get_jobs();
        if ( ! isset( $jobs[ $job_id ] ) )
        {
            return false;
        }

        $jobs[ $job_id ]['status'] = $status;
        $jobs[ $job_id ] = array_merge( $jobs[ $job_id ], $extra );
        $jobs[ $job_id ]['updated_at'] = time();

        return $this->persist_locked( $jobs );
    }

    /**
     * Return all tracked jobs (newest first).
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $jobs = $this->get_jobs();
        uasort(
            $jobs,
            static function ( array $a, array $b ): int {
                return ( $b['scheduled_at'] ?? 0 ) <=> ( $a['scheduled_at'] ?? 0 );
            }
        );

        $jobs = apply_filters( 'sentient_forms_async_metadata_jobs', $jobs );

        return $jobs;
    }

    public function get( string $job_id ): ?array
    {
        $jobs = $this->get_jobs();
        return $jobs[ $job_id ] ?? null;
    }

    public function purge( callable $should_delete ): int | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            fn(): int | WP_Error => $this->purge_locked( $should_delete )
        );
    }

    /** Purge after the complete read-modify-write fence is held. */
    private function purge_locked( callable $should_delete ): int | WP_Error
    {
        $jobs    = $this->get_jobs();
        $removed = 0;

        foreach ( $jobs as $job_id => $job )
        {
            if ( $should_delete( $job ) )
            {
                unset( $jobs[ $job_id ] );
                $removed++;
            }
        }

        if ( $removed > 0 )
        {
            $persisted = $this->persist_locked( $jobs );
            if ( is_wp_error( $persisted ) )
            {
                return $persisted;
            }
        }

        return $removed;
    }

    /**
     * Reconcile metadata rows against Action Scheduler terminal statuses.
     *
     * @param array<string, mixed> $args Reconcile args.
     * @return array<string, mixed>
     */
    public function reconcile_with_action_scheduler( array $args = [] ): array
    {
        $limit      = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 100;
        $older_than = isset( $args['older_than'] ) ? max( 0, (int) $args['older_than'] ) : 300;
        $apply      = ! empty( $args['apply'] );
        $statuses   = isset( $args['statuses'] ) && is_array( $args['statuses'] )
            ? array_values( array_filter( array_map( 'strval', $args['statuses'] ) ) )
            : [ 'queued', 'retry_scheduled', 'running' ];
        $cutoff     = $older_than > 0 ? ( time() - $older_than ) : 0;
        $candidates = [];
        $scanned    = 0;
        $updated    = 0;
        $skipped    = 0;

        foreach ( array_values( $this->all() ) as $job )
        {
            if ( $scanned >= $limit )
            {
                break;
            }

            $status = (string) ( $job['status'] ?? '' );
            if ( ! in_array( $status, $statuses, true ) )
            {
                continue;
            }

            $reference = (int) ( $job['updated_at'] ?? $job['scheduled_at'] ?? $job['run_at'] ?? 0 );
            if ( $cutoff > 0 && $reference > 0 && $reference > $cutoff )
            {
                $skipped++;
                continue;
            }

            $scanned++;

            $action_scheduler_id = isset( $job['action_scheduler_id'] ) ? (int) $job['action_scheduler_id'] : 0;
            if ( $action_scheduler_id <= 0 )
            {
                $skipped++;
                continue;
            }

            $action_scheduler_status = $this->resolve_action_scheduler_status( $action_scheduler_id );
            if ( ! $this->is_terminal_action_scheduler_status( $action_scheduler_status ) )
            {
                $skipped++;
                continue;
            }

            $target_status = $this->map_action_scheduler_status( $action_scheduler_status );
            if ( null === $target_status || $target_status === $status )
            {
                $skipped++;
                continue;
            }

            $candidate = [
                'job_id'                  => (string) ( $job['job_id'] ?? '' ),
                'hook'                    => (string) ( $job['hook'] ?? '' ),
                'metadata_status'         => $status,
                'action_scheduler_id'     => $action_scheduler_id,
                'action_scheduler_status' => $action_scheduler_status,
                'target_status'           => $target_status,
                'reason'                  => 'action_scheduler_terminal_status',
            ];
            $candidates[] = $candidate;

            if ( ! $apply )
            {
                continue;
            }

            $status_update = $this->update_status(
                $candidate['job_id'],
                $target_status,
                [
                    'completed_at'            => time(),
                    'reconciled_at'           => time(),
                    'reconciled_from_status'  => $status,
                    'reconciled_as_status'    => $action_scheduler_status,
                    'last_error'              => 'failed' === $target_status
                        ? ( $job['last_error'] ?? 'Reconciled from Action Scheduler terminal failure status' )
                        : ( $job['last_error'] ?? null ),
                ]
            );
            if ( is_wp_error( $status_update ) )
            {
                $candidate['update_error'] = $status_update->get_error_code();
                $candidates[ array_key_last( $candidates ) ] = $candidate;
                $skipped++;
                continue;
            }
            $updated++;
        }

        return [
            'scanned'    => $scanned,
            'candidates' => count( $candidates ),
            'updated'    => $updated,
            'skipped'    => $skipped,
            'apply'      => $apply,
            'rows'       => $candidates,
        ];
    }

    /**
     * Remove all tracked jobs (mainly for tests).
     */
    public function clear(): bool | WP_Error
    {
        return Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
            static function (): bool {
                $deleted = delete_option( self::OPTION );
                return $deleted || false === get_option( self::OPTION, false );
            }
        );
    }

    private function get_jobs(): array
    {
        $stored = get_option( self::OPTION, [] );
        return is_array( $stored ) ? $stored : [];
    }

    private function persist_locked( array $jobs ): bool | WP_Error
    {
        if ( update_option( self::OPTION, $jobs, false ) || $jobs === get_option( self::OPTION, null ) )
        {
            return true;
        }

        return new WP_Error(
            'sentient_forms_async_metadata_persistence_failed',
            __( 'Could not persist Sentient Forms async job metadata.', 'sentient-forms' )
        );
    }

    private function trim( array $jobs ): array
    {
        if ( count( $jobs ) <= self::MAX_JOBS )
        {
            return $jobs;
        }

        uasort(
            $jobs,
            static function ( array $a, array $b ): int {
                return ( $a['scheduled_at'] ?? 0 ) <=> ( $b['scheduled_at'] ?? 0 );
            }
        );

        while ( count( $jobs ) > self::MAX_JOBS )
        {
            array_shift( $jobs );
        }

        return $jobs;
    }

    private function resolve_action_scheduler_status( int $action_scheduler_id ): ?string
    {
        $filtered_status = apply_filters( 'sentient_forms_async_metadata_action_scheduler_status', null, $action_scheduler_id );
        if ( is_string( $filtered_status ) && '' !== $filtered_status )
        {
            return $filtered_status;
        }

        if ( ! class_exists( 'ActionScheduler' ) )
        {
            return null;
        }

        try
        {
            $store = ActionScheduler::store();
            if ( ! $store || ! method_exists( $store, 'get_status' ) )
            {
                return null;
            }

            $status = $store->get_status( $action_scheduler_id );
            if ( ! is_string( $status ) || '' === $status )
            {
                return null;
            }

            return $status;
        }
        catch ( Throwable $throwable )
        {
            return null;
        }
    }

    private function is_terminal_action_scheduler_status( ?string $status ): bool
    {
        return in_array( $status, [ 'complete', 'failed', 'canceled' ], true );
    }

    private function map_action_scheduler_status( string $status ): ?string
    {
        if ( 'complete' === $status )
        {
            return 'success';
        }

        if ( in_array( $status, [ 'failed', 'canceled' ], true ) )
        {
            return 'failed';
        }

        return null;
    }

    private function filter_payload( array $payload, string $hook ): array
    {
        /**
         * Filter the payload stored alongside async job metadata.
         *
         * @param array  $payload Raw payload that Action Scheduler received.
         * @param string $hook    Hook name that will run the job.
         */
        return apply_filters( 'sentient_forms_async_metadata_payload', $payload, $hook );
    }
}
