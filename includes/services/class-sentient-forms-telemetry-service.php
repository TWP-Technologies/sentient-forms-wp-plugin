<?php
/**
 * Handles async telemetry queueing and delivery.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Telemetry_Service
{
    private const CRON_HOOK = 'sentient_forms_flush_telemetry';
    private const CRON_INTERVAL = 'sentient_forms_five_minutes';
    private const DEFAULT_BATCH_SIZE = 20;
    private const TELEMETRY_ENDPOINT = 'sites/telemetry';
    private const LOG_PREFIX = '[sentient-forms][telemetry] ';

    public function __construct( private Sentient_Forms_Plugin $plugin )
    {
        add_filter( 'cron_schedules', [ $this, 'register_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'flush_queue' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) )
        {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
        }

        add_action( 'sentient_forms_async_success', [ $this, 'handle_job_success' ], 20, 2 );
        add_action( 'sentient_forms_async_failure', [ $this, 'handle_job_failure' ], 20, 2 );
        add_action( 'sentient_forms_async_health_warning', [ $this, 'handle_health_warning' ] );
    }

    public function register_cron_schedule( array $schedules ): array
    {
        if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) )
        {
            $schedules[ self::CRON_INTERVAL ] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every five minutes (Sentient Forms)', 'sentient-forms' ),
            ];
        }

        return $schedules;
    }

    private function consent_enabled(): bool
    {
        $telemetry = $this->plugin->get_telemetry_settings();
        return ! empty( $telemetry['telemetry_opt_in'] );
    }

    private function store(): Sentient_Forms_Async_Request_Store
    {
        return $this->plugin->get_async_request_store();
    }

    private function log_debug( string $message, array $context = [] ): void
    {
        sentient_forms_debug_log( self::LOG_PREFIX . $message, $context );
    }

    public function handle_job_success( array $context, array $result ): void
    {
        $this->queue_event( 'async_job_success', [
            'action_id'   => $context['action_id'] ?? '',
            'adapter'     => $context['form_source'] ?? '',
            'attempt'     => (int) ( $context['attempt'] ?? 1 ),
            'max_attempts'=> (int) ( $context['max_attempts'] ?? 3 ),
            'job_type'    => $context['job_type'] ?? 'execution',
            'result'      => wp_json_encode( $result ),
        ] );
    }

    public function handle_job_failure( array $context, WP_Error $error ): void
    {
        $this->queue_event( 'async_job_failure', [
            'action_id'   => $context['action_id'] ?? '',
            'adapter'     => $context['form_source'] ?? '',
            'attempt'     => (int) ( $context['attempt'] ?? 1 ),
            'max_attempts'=> (int) ( $context['max_attempts'] ?? 3 ),
            'job_type'    => $context['job_type'] ?? 'execution',
            'error_code'  => $error->get_error_code(),
            'error_msg'   => $error->get_error_message(),
        ] );
    }

    public function handle_health_warning( array $warning ): void
    {
        $this->queue_event( 'async_health_warning', $warning );
    }

    public function queue_event( string $event_type, array $payload ): void
    {
        if ( ! $this->consent_enabled() )
        {
            return;
        }

        $envelope = $this->format_payload( $event_type, $payload );
        $this->store()->enqueue_telemetry( $event_type, $envelope );
    }

    public function get_settings(): array
    {
        return $this->plugin->get_telemetry_settings();
    }

    public function update_and_sync( bool $opt_in, string $actor_hint ): array | WP_Error
    {
        $license = $this->plugin->get_license_data();
        if ( empty( $license['proxy_api_key'] ) )
        {
            return new WP_Error( 'cps_missing_proxy_key', __( 'Proxy key missing; activate your license first.', 'sentient-forms' ) );
        }

        $body = [
            'telemetry_opt_in' => (bool) $opt_in,
            'actor'           => $actor_hint,
            'site_id'         => $license['site_id'] ?? '',
        ];

        $response = wp_safe_remote_post(
            trailingslashit( $this->plugin->get_cps_base_url_value() ) . self::TELEMETRY_ENDPOINT,
            [
                'timeout' => 10,
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $license['proxy_api_key'],
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) )
        {
            $this->log_debug( 'telemetry opt-in sync failed', [ 'error' => $response->get_error_message(), 'endpoint' => self::TELEMETRY_ENDPOINT ] );
            $this->plugin->set_telemetry_settings(
                [
                    'telemetry_opt_in' => $opt_in,
                    'last_error'       => $response->get_error_message(),
                    'updated_at'       => current_time( 'mysql' ),
                ]
            );
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        $payload = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];

        $settings = [
            'telemetry_opt_in'  => isset( $payload['telemetry_opt_in'] ) ? (bool) $payload['telemetry_opt_in'] : $opt_in,
            'remote_updated_at' => $payload['updated_at'] ?? null,
            'synced_at'         => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
            'last_error'        => null,
        ];
        $this->plugin->set_telemetry_settings( $settings );
        $this->log_debug( 'telemetry opt-in sync success', [ 'telemetry_opt_in' => $settings['telemetry_opt_in'] ] );

        return $this->plugin->get_telemetry_settings();
    }

    private function format_payload( string $event_type, array $payload ): array
    {
        $license   = $this->plugin->get_license_data();
        $license_id = isset( $license['license_id'] ) ? sanitize_text_field( (string) $license['license_id'] ) : '';
        $site_id    = isset( $license['site_id'] ) ? sanitize_text_field( (string) $license['site_id'] ) : '';

        // Enrich with entry/form if present.
        $entry_id = $payload['entry_id'] ?? $payload['context']['entry_id'] ?? null;
        $form_id  = $payload['form_id'] ?? $payload['context']['form_id'] ?? null;
        $action_id = $payload['action_id'] ?? $payload['context']['action_id'] ?? null;
        $request_id = $payload['request_id'] ?? $payload['context']['request_id'] ?? null;

        return [
            'event'      => $event_type,
            'site_url'   => get_site_url(),
            'timestamp'  => gmdate( 'c' ),
            'payload'    => array_merge(
                $payload,
                array_filter(
                    [
                        'entry_id'   => $entry_id,
                        'form_id'    => $form_id,
                        'action_id'  => $action_id,
                        'request_id' => $request_id,
                    ],
                    static fn( $v ) => null !== $v && '' !== $v
                )
            ),
            'license_id' => $license_id ?: null,
            'site_id'    => $site_id ?: null,
        ];
    }

    public function flush_queue(): void
    {
        if ( ! $this->consent_enabled() )
        {
            $this->log_debug( 'flush_queue skipped: consent disabled' );
            return;
        }

        $license = $this->plugin->get_license_data();
        $proxy_key = isset( $license['proxy_api_key'] ) ? trim( (string) $license['proxy_api_key'] ) : '';
        if ( '' === $proxy_key )
        {
            $this->log_debug( 'flush_queue skipped: missing proxy key' );
            return;
        }

        $batch = $this->store()->claim_telemetry_batch( self::DEFAULT_BATCH_SIZE );
        if ( empty( $batch ) )
        {
            $this->log_debug( 'flush_queue skipped: no queued telemetry' );
            return;
        }

        $endpoint = trailingslashit( $this->plugin->get_cps_base_url_value() ) . 'telemetry/async';
        $headers  = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $proxy_key,
        ];

        $this->log_debug( 'flush_queue sending batch', [ 'count' => count( $batch ), 'endpoint' => $endpoint ] );

        foreach ( $batch as $row )
        {
            $payload = $row['telemetry_payload'] ? json_decode( $row['telemetry_payload'], true ) : null;
            if ( ! $payload )
            {
                $this->store()->update_telemetry_status( $row['request_hash'], 'telemetry_failed', __( 'Missing payload', 'sentient-forms' ) );
                $this->log_debug( 'telemetry payload missing', [ 'request_hash' => $row['request_hash'] ] );
                continue;
            }

            $response = wp_safe_remote_post(
                $endpoint,
                [
                    'timeout' => 5,
                    'headers' => $headers,
                    'body'    => wp_json_encode( $payload ),
                ]
            );

            if ( is_wp_error( $response ) )
            {
                $this->store()->update_telemetry_status( $row['request_hash'], 'telemetry_failed', $response->get_error_message() );
                $this->log_debug( 'telemetry post error', [ 'request_hash' => $row['request_hash'], 'error' => $response->get_error_message() ] );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 200 && $code < 300 )
            {
                $this->store()->update_telemetry_status( $row['request_hash'], 'telemetry_sent' );
                $this->log_debug( 'telemetry sent', [ 'request_hash' => $row['request_hash'], 'status' => $code, 'event' => $payload['event'] ?? null ] );
            }
            else
            {
                $body = wp_remote_retrieve_body( $response );
                $this->store()->update_telemetry_status( $row['request_hash'], 'telemetry_failed', sprintf( 'HTTP %d %s', $code, $body ) );
                $this->log_debug( 'telemetry failed', [ 'request_hash' => $row['request_hash'], 'status' => $code, 'body' => $body ] );
            }
        }
    }
}
