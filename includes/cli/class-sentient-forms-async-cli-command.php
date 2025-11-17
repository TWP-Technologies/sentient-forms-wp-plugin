<?php
/**
 * WP-CLI helpers for inspecting async jobs.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

if ( defined( '\\WP_CLI' ) && WP_CLI && ! class_exists( 'Sentient_Forms_Async_CLI_Command' ) )
{
    /**
     * Sentient Forms async job commands.
     */
    class Sentient_Forms_Async_CLI_Command
    {
        public function list_jobs(): void
        {
            $store = Sentient_Forms_Plugin::instance()->get_async_metadata_store();
            $jobs  = array_values( $store->all() );

            if ( empty( $jobs ) )
            {
                WP_CLI::success( 'No async jobs recorded.' );
                return;
            }

            $rows = array_map(
                static function ( array $job ): array {
                    return [
                        'job_id'    => $job['job_id'],
                        'status'    => $job['status'],
                        'hook'      => $job['hook'],
                        'action'    => $job['action_id'] ?? '',
                        'run_at'    => isset( $job['run_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $job['run_at'] ) : '',
                        'attempt'   => $job['context']['attempt'] ?? 1,
                        'last_error'=> $job['last_error'] ?? '',
                    ];
                },
                $jobs
            );

            WP_CLI\Utils\format_items( 'table', $rows, [ 'job_id', 'status', 'hook', 'action', 'attempt', 'run_at', 'last_error' ] );
        }

        public function clear(): void
        {
            Sentient_Forms_Plugin::instance()->get_async_metadata_store()->clear();
            WP_CLI::success( 'Cleared async job metadata.' );
        }
    }

    WP_CLI::add_command( 'sentient-forms async list', [ new Sentient_Forms_Async_CLI_Command(), 'list_jobs' ] );
    WP_CLI::add_command( 'sentient-forms async clear', [ new Sentient_Forms_Async_CLI_Command(), 'clear' ] );
}
