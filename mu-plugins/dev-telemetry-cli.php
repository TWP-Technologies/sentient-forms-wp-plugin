<?php
/**
 * Dev helper to enqueue and flush a telemetry event via WP-CLI.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI )
{
    WP_CLI::add_command(
        'sentient-forms dev-telemetry',
        function () {
            $svc = Sentient_Forms_Plugin::instance()->get_telemetry_service();
            $svc->queue_event(
                'async_health_warning',
                [
                    'provider_path' => 'dev_cli',
                    'job_type'      => 'manual_test',
                    'status'        => 'warning',
                    'warning_code'  => 'dev_cli_manual_test',
                ]
            );
            $svc->flush_queue();
            WP_CLI::success( 'Queued and flushed async_health_warning telemetry event.' );
        }
    );
}
