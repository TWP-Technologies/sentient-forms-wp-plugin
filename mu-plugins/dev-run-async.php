<?php
/**
 * Dev helper: run Action Scheduler queue to flush Sentient Forms async jobs.
 *
 * Loaded only in WP-CLI / dev; safe no-op if Action Scheduler not present.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI )
{
    /**
     * Run pending Action Scheduler jobs once.
     */
    WP_CLI::add_command(
        'sentient-forms dev-run-async',
        function () {
            if ( ! class_exists( 'ActionScheduler_QueueRunner' ) )
            {
                WP_CLI::warning( 'Action Scheduler not available; nothing to run.' );
                return;
            }

            $runner = ActionScheduler_QueueRunner::instance();

            // Limit runs to keep dev-friendly.
            add_filter(
                'action_scheduler_queue_runner_batch_size',
                static function () {
                    return 25;
                }
            );

            $runner->run();
            WP_CLI::success( 'Action Scheduler queue run completed.' );
        }
    );
}
