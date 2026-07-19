<?php
/**
 * Provides async queue health summaries for notices, CLI, and telemetry.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Async_Health_Service
{
    private const FAILURE_WINDOW = HOUR_IN_SECONDS;
    private const DEFAULT_QUEUE_THRESHOLD = 20;
    private const DEFAULT_FAILURE_THRESHOLD = 3;
    private const DEFAULT_STALE_QUEUE_THRESHOLD = 900;

    public function __construct( private Sentient_Forms_Plugin $plugin )
    {
    }

    public function evaluate(): array
    {
        $store = $this->plugin->get_async_metadata_store();
        $jobs  = array_values( $store->all() );

        $queue_threshold   = (int) apply_filters( 'sentient_forms_async_queue_threshold', self::DEFAULT_QUEUE_THRESHOLD );
        $failure_threshold = (int) apply_filters( 'sentient_forms_async_failure_threshold', self::DEFAULT_FAILURE_THRESHOLD );
        $failure_window    = (int) apply_filters( 'sentient_forms_async_failure_window', self::FAILURE_WINDOW );
        $stale_threshold   = (int) apply_filters( 'sentient_forms_async_stale_queue_threshold', self::DEFAULT_STALE_QUEUE_THRESHOLD );

        $queue_depth = 0;
        $oldest_run  = null;
        $recent_failures = [];
        $now         = time();

        foreach ( $jobs as $job )
        {
            $status = $job['status'] ?? '';
            if ( in_array( $status, [ 'queued', 'retry_scheduled', 'running' ], true ) )
            {
                $queue_depth++;
                $run_at = isset( $job['run_at'] ) ? (int) $job['run_at'] : null;
                if ( $run_at && ( null === $oldest_run || $run_at < $oldest_run ) )
                {
                    $oldest_run = $run_at;
                }
            }

            if ( 'failed' === $status )
            {
                $scheduled = isset( $job['scheduled_at'] ) ? (int) $job['scheduled_at'] : 0;
                if ( $scheduled >= ( $now - $failure_window ) )
                {
                    $action_id = $job['action_id'] ?? ( $job['context']['action_id'] ?? 'unknown' );
                    $key       = $action_id . '|' . ( $job['context']['form_source'] ?? 'unknown' );
                    $recent_failures[ $key ] = ( $recent_failures[ $key ] ?? 0 ) + 1;
                }
            }
        }

        $warnings = [];

        if ( $queue_depth >= $queue_threshold )
        {
            $warnings[] = [
                'code'    => 'queue_backlog',
                'level'   => 'warning',
                'message' => sprintf(
                    /* translators: 1: pending job count, 2: configured queue threshold. */
                    __( 'Background queue backlog: %1$d jobs pending (threshold %2$d).', 'sentient-forms' ),
                    $queue_depth,
                    $queue_threshold
                ),
                'data'    => [ 'queue_depth' => $queue_depth, 'threshold' => $queue_threshold, 'oldest_run_at' => $oldest_run ],
            ];
        }

        $oldest_overdue_seconds = null;
        if ( null !== $oldest_run )
        {
            $oldest_overdue_seconds = max( 0, $now - $oldest_run );
        }

        if ( null !== $oldest_overdue_seconds && $stale_threshold > 0 && $oldest_overdue_seconds >= $stale_threshold )
        {
            $warnings[] = [
                'code'    => 'queue_stalled',
                'level'   => 'warning',
                'message' => sprintf(
                    /* translators: 1: oldest job overdue seconds, 2: configured stale queue threshold. */
                    __( 'Background queue may be stalled: oldest due job is %1$d seconds overdue (threshold %2$d).', 'sentient-forms' ),
                    $oldest_overdue_seconds,
                    $stale_threshold
                ),
                'data'    => [
                    'oldest_run_at'  => $oldest_run,
                    'overdue_seconds' => $oldest_overdue_seconds,
                    'threshold'      => $stale_threshold,
                ],
            ];
        }

        foreach ( $recent_failures as $key => $count )
        {
            if ( $count >= $failure_threshold )
            {
                [ $action_id, $adapter ] = array_pad( explode( '|', $key ), 2, 'unknown' );
                $warnings[] = [
                    'code'    => 'consecutive_failures',
                    'level'   => 'error',
                    'message' => sprintf(
                        /* translators: 1: action id, 2: form adapter id, 3: failure count. */
                        __( 'Action %1$s (%2$s) failed %3$d times in the last hour.', 'sentient-forms' ),
                        $action_id,
                        $adapter,
                        $count
                    ),
                    'data'    => [ 'action_id' => $action_id, 'adapter' => $adapter, 'count' => $count, 'threshold' => $failure_threshold ],
                ];
            }
        }

        if ( ! function_exists( 'as_schedule_single_action' ) )
        {
            $warnings[] = [
                'code'    => 'scheduler_missing',
                'level'   => 'error',
                'message' => __( 'Action Scheduler is not available; Sentient Forms background jobs will not run.', 'sentient-forms' ),
                'data'    => [],
            ];
        }

        foreach ( $warnings as $warning )
        {
            do_action( 'sentient_forms_async_health_warning', $warning );
        }

        return [
            'queue_depth'            => $queue_depth,
            'oldest_run_at'          => $oldest_run,
            'oldest_overdue_seconds' => $oldest_overdue_seconds,
            'recent_failures'        => $recent_failures,
            'warnings'               => $warnings,
        ];
    }
}
