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
            $store = $this->get_store();
            $jobs  = array_values( $store->all() );

            if ( empty( $jobs ) )
            {
                WP_CLI::success( 'No background jobs recorded.' );
                return;
            }

            $rows = array_map(
                static function ( array $job ): array {
                    return [
                        'job_id'    => $job['job_id'],
                        'status'    => $job['status'],
                        'hook'      => $job['hook'],
                        'queue'     => $job['group'] ?? '',
                        'action'    => $job['action_id'] ?? '',
                        'run_at'    => isset( $job['run_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $job['run_at'] ) : '',
                        'attempt'   => $job['context']['attempt'] ?? 1,
                        'last_error'=> $job['last_error'] ?? '',
                    ];
                },
                $jobs
            );

            WP_CLI\Utils\format_items( 'table', $rows, [ 'job_id', 'status', 'hook', 'queue', 'action', 'attempt', 'run_at', 'last_error' ] );
        }

        public function clear(): void
        {
            $this->get_store()->clear();
            WP_CLI::success( 'Cleared background job metadata.' );
        }

        public function settings( array $args, array $assoc_args ): void
        {
            $service = $this->get_async_settings_service();
            if ( empty( $assoc_args ) )
            {
                $settings = $service->get_settings();
                WP_CLI\Utils\format_items(
                    'table',
                    [
                        [
                            'max_attempts' => $settings['max_attempts'],
                            'base_delay'   => $settings['base_delay_seconds'],
                            'max_delay'    => $settings['max_delay_seconds'],
                            'updated_at'   => $settings['updated_at'] ? gmdate( 'c', (int) $settings['updated_at'] ) : '',
                            'updated_by'   => $settings['updated_by'] ?? '',
                        ],
                    ],
                    [ 'max_attempts', 'base_delay', 'max_delay', 'updated_at', 'updated_by' ]
                );
                return;
            }

            $payload = [];
            if ( isset( $assoc_args['max-attempts'] ) )
            {
                $payload['max_attempts'] = absint( $assoc_args['max-attempts'] );
            }
            if ( isset( $assoc_args['base-delay'] ) )
            {
                $payload['base_delay_seconds'] = absint( $assoc_args['base-delay'] );
            }
            if ( isset( $assoc_args['max-delay'] ) )
            {
                $payload['max_delay_seconds'] = absint( $assoc_args['max-delay'] );
            }

            if ( empty( $payload ) )
            {
                WP_CLI::error( 'Provide at least one setting to update (max-attempts, base-delay, max-delay).' );
            }

            $settings = $service->update_settings( $payload, 'wp_cli' );
            WP_CLI::success(
                sprintf(
                    'Background settings updated (max attempts %d, base %ds, max %ds).',
                    $settings['max_attempts'],
                    $settings['base_delay_seconds'],
                    $settings['max_delay_seconds']
                )
            );
        }

        public function requeue( array $args ): void
        {
            $job_id = $args[0] ?? '';
            if ( empty( $job_id ) )
            {
                WP_CLI::error( 'Provide a job ID to requeue.' );
            }

            $store = $this->get_store();
            $job   = $store->get( $job_id );

            if ( null === $job )
            {
                WP_CLI::error( sprintf( 'Job %s not found.', $job_id ) );
            }

            $payload = $job['payload'] ?? null;
            if ( !is_array( $payload ) )
            {
                WP_CLI::error( 'This job does not have a stored payload. Ensure filters do not strip payload data.' );
            }

            $handler         = $this->get_handler();
            $context         = $payload['context'] ?? [];
            $context['requeued_from'] = $job_id;
            unset( $context['job_id'] );
            $context['attempt']    = 1;
            $context['last_error'] = null;

            $scheduled = false;
            if ( 'sentient_forms_process_local_mapping' === $job['hook'] )
            {
                $local_mapping_id = absint( $payload['local_mapping_id'] ?? $context['local_form_mapping_id'] ?? 0 );
                if ( $local_mapping_id <= 0 )
                {
                    WP_CLI::error( 'Job payload is missing a local mapping ID.' );
                }

                $scheduled = $handler->schedule_local_mapping(
                    $local_mapping_id,
                    [ 'id' => $payload['form_id'] ?? $context['form_id'] ?? '' ],
                    [
                        'id'              => $payload['entry_id'] ?? $context['entry_id'] ?? '',
                        'submission_uuid' => $payload['submission_uuid'] ?? $context['submission_uuid'] ?? null,
                    ],
                    $context,
                );
            }
            elseif ( 'sentient_forms_evaluate_action' === $job['hook'] )
            {
                $scheduled = $handler->dispatch_evaluation(
                    [
                        'adapter_id' => $context['adapter_id'] ?? $context['form_source'] ?? null,
                        'entry_id'   => $context['entry_id'] ?? null,
                        'form_id'    => $context['form_id'] ?? null,
                        'action_id'  => $context['action_id'] ?? null,
                        'payload'    => $context['evaluation_payload'] ?? [],
                        'context'    => $context,
                    ]
                );
            }
            else
            {
                $scheduled = $this->requeue_via_action_scheduler( $job );
            }

            if ( !$scheduled )
            {
                WP_CLI::error( sprintf( 'Failed to requeue job %s.', $job_id ) );
            }

            $store->update_status( $job_id, 'requeued', [ 'requeued_at' => time() ] );
            $new_job_id = $this->find_requeued_job_id( $job_id );
            if ( $new_job_id )
            {
                WP_CLI::success( sprintf( 'Requeued job %s as %s.', $job_id, $new_job_id ) );
                return;
            }

            WP_CLI::success( sprintf( 'Requeued job %s.', $job_id ) );
        }

        public function purge( array $args, array $assoc_args ): void
        {
            $status_arg = $assoc_args['status'] ?? 'success,failed';
            $statuses   = array_filter( array_map( 'trim', explode( ',', $status_arg ) ) );

            $older_than_minutes = isset( $assoc_args['older-than'] ) ? max( 0, (int) $assoc_args['older-than'] ) : 0;
            $seconds_per_minute = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60;
            $threshold          = $older_than_minutes > 0 ? time() - ( $older_than_minutes * $seconds_per_minute ) : null;

            $store   = $this->get_store();
            $removed = $store->purge(
                function ( array $job ) use ( $statuses, $threshold ): bool {
                    if ( ! empty( $statuses ) && ! in_array( $job['status'], $statuses, true ) )
                    {
                        return false;
                    }

                    if ( null === $threshold )
                    {
                        return true;
                    }

                    $reference = $job['updated_at'] ?? $job['completed_at'] ?? $job['run_at'] ?? $job['scheduled_at'] ?? 0;
                    if ( 0 === $reference )
                    {
                        return false;
                    }

                    return $reference <= $threshold;
                }
            );

            if ( 0 === $removed )
            {
                WP_CLI::warning( 'No jobs matched the purge criteria.' );
                return;
            }

            WP_CLI::success( sprintf( 'Purged %d job(s).', $removed ) );
        }

        /**
         * Reconcile metadata rows that drifted from Action Scheduler terminal status.
         *
         * ## OPTIONS
         *
         * [--apply]
         * : Apply updates (default is dry-run).
         *
         * [--limit=<rows>]
         * : Max eligible rows to scan. Default: 50.
         *
         * [--older-than=<seconds>]
         * : Minimum age in seconds before a row is eligible. Default: 300.
         *
         * [--statuses=<statuses>]
         * : CSV of metadata statuses to inspect (queued,retry_scheduled,running).
         */
        public function reconcile( array $args, array $assoc_args ): void
        {
            $limit      = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 50;
            $older_than = isset( $assoc_args['older-than'] ) ? max( 0, (int) $assoc_args['older-than'] ) : 300;
            $apply      = array_key_exists( 'apply', $assoc_args );
            $statuses   = $this->parse_reconcile_statuses( (string) ( $assoc_args['statuses'] ?? 'queued,retry_scheduled,running' ) );

            $result = $this->get_store()->reconcile_with_action_scheduler(
                [
                    'apply'      => $apply,
                    'limit'      => $limit,
                    'older_than' => $older_than,
                    'statuses'   => $statuses,
                ]
            );

            WP_CLI::line(
                sprintf(
                    'Scanned %d row(s); %d candidate(s); %d skipped.',
                    (int) ( $result['scanned'] ?? 0 ),
                    (int) ( $result['candidates'] ?? 0 ),
                    (int) ( $result['skipped'] ?? 0 )
                )
            );

            $rows = $result['rows'] ?? [];
            if ( ! empty( $rows ) )
            {
                WP_CLI\Utils\format_items(
                    'table',
                    $rows,
                    [ 'job_id', 'hook', 'metadata_status', 'action_scheduler_id', 'action_scheduler_status', 'target_status', 'reason' ]
                );
            }

            if ( 0 === (int) ( $result['candidates'] ?? 0 ) )
            {
                WP_CLI::success( 'No stale background metadata rows detected.' );
                return;
            }

            if ( ! $apply )
            {
                WP_CLI::warning( 'Dry-run only. Re-run with --apply to persist status updates.' );
                return;
            }

            WP_CLI::success( sprintf( 'Reconciled %d row(s).', (int) ( $result['updated'] ?? 0 ) ) );
        }

        private function get_store(): Sentient_Forms_Async_Metadata_Store
        {
            return Sentient_Forms_Plugin::instance()->get_async_metadata_store();
        }

        private function get_handler(): Sentient_Forms_Async_Handler
        {
            return Sentient_Forms_Plugin::instance()->get_async_handler();
        }

        private function get_async_settings_service(): Sentient_Forms_Async_Settings_Service
        {
            return Sentient_Forms_Plugin::instance()->get_async_settings_service();
        }

        /**
         * @return list<string>
         */
        private function parse_reconcile_statuses( string $status_arg ): array
        {
            $allowed  = [ 'queued', 'retry_scheduled', 'running' ];
            $statuses = array_values( array_filter( array_map( 'trim', explode( ',', $status_arg ) ) ) );
            if ( empty( $statuses ) )
            {
                WP_CLI::error( 'Provide at least one status via --statuses.' );
                return [];
            }

            $invalid = array_values( array_diff( $statuses, $allowed ) );
            if ( ! empty( $invalid ) )
            {
                WP_CLI::error(
                    sprintf(
                        'Invalid status(es): %s. Allowed values: %s.',
                        implode( ', ', $invalid ),
                        implode( ', ', $allowed )
                    )
                );
                return [];
            }

            return array_values( array_unique( $statuses ) );
        }

        public function status(): void
        {
            $service = Sentient_Forms_Plugin::instance()->get_async_health_service();
            $summary = $service->evaluate();
            WP_CLI::line( sprintf( 'Background queue depth: %d', $summary['queue_depth'] ?? 0 ) );
            if ( ! empty( $summary['oldest_run_at'] ) )
            {
                WP_CLI::line( 'Oldest scheduled background run: ' . gmdate( 'c', (int) $summary['oldest_run_at'] ) );
            }

            $warnings = $summary['warnings'] ?? [];
            if ( empty( $warnings ) )
            {
                WP_CLI::success( 'No background-processing warnings detected.' );
                return;
            }

            $rows = array_map(
                static function ( array $warning ): array {
                    return [
                        'code'    => $warning['code'] ?? '',
                        'level'   => $warning['level'] ?? '',
                        'message' => $warning['message'] ?? '',
                    ];
                },
                $warnings
            );

            WP_CLI\Utils\format_items( 'table', $rows, [ 'code', 'level', 'message' ] );
        }

        public function list_requests( array $args, array $assoc_args ): void
        {
            $limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
            $status = $assoc_args['status'] ?? null;
            $record_type = $assoc_args['record-type'] ?? 'job';
            $store  = Sentient_Forms_Plugin::instance()->get_async_request_store();
            $rows   = $store->list( [ 'limit' => $limit, 'status' => $status, 'record_type' => $record_type ] );
            if ( empty( $rows ) )
            {
                WP_CLI::success( 'No background requests recorded.' );
                return;
            }

            $formatted = array_map(
                static function ( array $row ): array {
                    return [
                        'hash'       => $row['request_hash'],
                        'action'     => $row['action_id'],
                        'adapter'    => $row['adapter'] ?? '',
                        'status'     => $row['status'],
                        'first_seen' => $row['first_seen_at'],
                        'last_seen'  => $row['last_seen_at'],
                        'last_error' => $row['last_error'] ?? '',
                    ];
                },
                $rows
            );

            WP_CLI\Utils\format_items( 'table', $formatted, [ 'hash', 'action', 'adapter', 'status', 'first_seen', 'last_seen', 'last_error' ] );
        }

        public function purge_requests( array $args, array $assoc_args ): void
        {
            if ( empty( $assoc_args['older-than'] ) )
            {
                WP_CLI::error( 'Provide --older-than=<minutes>.' );
            }

            $minutes = max( 1, (int) $assoc_args['older-than'] );
            $timestamp = time() - ( $minutes * MINUTE_IN_SECONDS );
            $store   = Sentient_Forms_Plugin::instance()->get_async_request_store();
            $removed = $store->purge_older_than( $timestamp );
            WP_CLI::success( sprintf( 'Purged %d request(s).', $removed ) );
        }

        private function requeue_via_action_scheduler( array $job ): bool
        {
            if ( empty( $job['action_scheduler_id'] ) || ! class_exists( 'ActionScheduler' ) )
            {
                return false;
            }

            try
            {
                $store  = ActionScheduler::store();
                $action = $store->fetch_action( $job['action_scheduler_id'] );
                if ( ! $action )
                {
                    return false;
                }

                $group = $job['group'] ?? '';
                $hook  = $job['hook'];
                $args  = $action->get_args();
                $time  = time();

                if ( function_exists( 'as_schedule_single_action' ) )
                {
                    $new_action_id = as_schedule_single_action( $time, $hook, $args, $group );
                    return (bool) $new_action_id;
                }

                return (bool) wp_schedule_single_event( $time, $hook, $args );
            }
            catch ( Throwable $throwable )
            {
                WP_CLI::warning( sprintf( 'Action Scheduler requeue failed: %s', $throwable->getMessage() ) );
                return false;
            }
        }

        private function find_requeued_job_id( string $source_job_id ): ?string
        {
            foreach ( $this->get_store()->all() as $job )
            {
                if ( ( $job['context']['requeued_from'] ?? null ) === $source_job_id )
                {
                    return $job['job_id'];
                }
            }

            return null;
        }

        public function logs_tail( array $args, array $assoc_args ): void
        {
            $lines = isset( $assoc_args['lines'] ) ? max( 1, (int) $assoc_args['lines'] ) : 200;
            $logger = Sentient_Forms_Plugin::instance()->get_logger();
            $path   = $logger->get_log_path();

            if ( ! $logger->is_enabled() )
            {
                WP_CLI::warning( 'Logging is disabled (set SENTIENT_FORMS_LOG_ENABLED=1 to enable).' );
            }

            if ( ! file_exists( $path ) )
            {
                WP_CLI::warning( sprintf( 'No log file found at %s', $path ) );
                return;
            }

            $rows = @file( $path, FILE_IGNORE_NEW_LINES );
            if ( false === $rows )
            {
                WP_CLI::error( 'Unable to read log file.' );
            }

            $tail = array_slice( $rows, -$lines );
            foreach ( $tail as $row )
            {
                WP_CLI::line( $row );
            }
        }

        public function logs_bundle(): void
        {
            $logger = Sentient_Forms_Plugin::instance()->get_logger();
            $dir    = $logger->get_log_dir();
            $path   = $logger->get_log_path();

            if ( ! file_exists( $path ) )
            {
                WP_CLI::error( 'No logs to bundle; file not found.' );
            }

            $files = glob( $dir . '/sf.log*' ) ?: [];
            if ( empty( $files ) )
            {
                WP_CLI::error( 'No log files matched sf.log*' );
            }

            $bundle = $dir . '/sf-support-bundle-' . gmdate( 'Ymd-His' ) . '.zip';

            if ( class_exists( 'ZipArchive' ) )
            {
                $zip = new ZipArchive();
                if ( true !== $zip->open( $bundle, ZipArchive::CREATE ) )
                {
                    WP_CLI::error( 'Unable to create zip bundle.' );
                }

                foreach ( $files as $file )
                {
                    $zip->addFile( $file, basename( $file ) );
                }

                $zip->close();
                WP_CLI::success( sprintf( 'Bundle created: %s', $bundle ) );
                return;
            }

            // Fallback: gzip the main log only.
            $content = file_get_contents( $path );
            if ( false === $content )
            {
                WP_CLI::error( 'Unable to read log file for gzip fallback.' );
            }

            $bundle = $dir . '/sf-support-bundle-' . gmdate( 'Ymd-His' ) . '.gz';
            file_put_contents( $bundle, gzencode( $content, 6 ) );
            WP_CLI::success( sprintf( 'Bundle created (gzip fallback): %s', $bundle ) );
        }
    }

    $sentient_forms_async_cli = new Sentient_Forms_Async_CLI_Command();
    WP_CLI::add_command( 'sentient-forms async list', [ $sentient_forms_async_cli, 'list_jobs' ] );
    WP_CLI::add_command( 'sentient-forms async clear', [ $sentient_forms_async_cli, 'clear' ] );
    WP_CLI::add_command( 'sentient-forms async requeue', [ $sentient_forms_async_cli, 'requeue' ] );
    WP_CLI::add_command( 'sentient-forms async purge', [ $sentient_forms_async_cli, 'purge' ] );
    WP_CLI::add_command( 'sentient-forms async reconcile', [ $sentient_forms_async_cli, 'reconcile' ] );
    WP_CLI::add_command( 'sentient-forms async settings', [ $sentient_forms_async_cli, 'settings' ] );
    WP_CLI::add_command( 'sentient-forms async status', [ $sentient_forms_async_cli, 'status' ] );
    WP_CLI::add_command( 'sentient-forms async-requests list', [ $sentient_forms_async_cli, 'list_requests' ] );
    WP_CLI::add_command( 'sentient-forms async-requests purge', [ $sentient_forms_async_cli, 'purge_requests' ] );
    WP_CLI::add_command( 'sentient-forms logs tail', [ $sentient_forms_async_cli, 'logs_tail' ] );
    WP_CLI::add_command( 'sentient-forms logs bundle', [ $sentient_forms_async_cli, 'logs_bundle' ] );
}
