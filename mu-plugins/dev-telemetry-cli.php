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
            $svc->queue_event( 'manual_test', [ 'note' => 'dev-cli' ] );
            $svc->flush_queue();
            WP_CLI::success( 'Queued and flushed manual_test telemetry event.' );
        }
    );
}
