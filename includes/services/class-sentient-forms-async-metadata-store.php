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
     * @param string               $job_id  UUID assigned to the job context.
     * @param string               $hook    Hook name (`sentient_forms_process_action` or evaluation).
     * @param array<string, mixed> $payload Payload passed to Action Scheduler.
     * @param int                  $run_at  Timestamp when the job is scheduled to run.
     */
    public function record_job( string $job_id, string $hook, array $payload, int $run_at ): void
    {
        $jobs = $this->get_jobs();
        $jobs[ $job_id ] = [
            'job_id'       => $job_id,
            'hook'         => $hook,
            'status'       => 'queued',
            'scheduled_at' => time(),
            'run_at'       => $run_at,
            'context'      => $payload['context'] ?? [],
            'action_id'    => $payload['context']['action_id'] ?? null,
            'last_error'   => null,
        ];

        $this->persist( $this->trim( $jobs ) );
    }

    /**
     * Update a job's status.
     */
    public function update_status( ?string $job_id, string $status, array $extra = [] ): void
    {
        if ( empty( $job_id ) )
        {
            return;
        }

        $jobs = $this->get_jobs();
        if ( ! isset( $jobs[ $job_id ] ) )
        {
            return;
        }

        $jobs[ $job_id ]['status'] = $status;
        $jobs[ $job_id ] = array_merge( $jobs[ $job_id ], $extra );
        if ( ! isset( $jobs[ $job_id ]['updated_at'] ) )
        {
            $jobs[ $job_id ]['updated_at'] = time();
        }

        $this->persist( $jobs );
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

        return $jobs;
    }

    /**
     * Remove all tracked jobs (mainly for tests).
     */
    public function clear(): void
    {
        delete_option( self::OPTION );
    }

    private function get_jobs(): array
    {
        $stored = get_option( self::OPTION, [] );
        return is_array( $stored ) ? $stored : [];
    }

    private function persist( array $jobs ): void
    {
        update_option( self::OPTION, $jobs, false );
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
}
