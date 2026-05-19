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
    private const TELEMETRY_PAYLOAD_SCHEMA = 'sentient_forms_telemetry_metadata.v1';
    private const LOG_PREFIX = '[sentient-forms][telemetry] ';
    private const ALLOWED_EVENTS = [
        'async_job_success',
        'async_job_failure',
        'async_health_warning',
    ];
    private const ALLOWED_METADATA_KEYS = [
        'schema_version',
        'plugin_version',
        'wp_version',
        'php_version',
        'provider_path',
        'action_id',
        'action_code',
        'execution_request_id',
        'adapter',
        'job_type',
        'attempt',
        'max_attempts',
        'status',
        'error_code',
        'warning_code',
        'duration_ms',
        'queue_wait_ms',
    ];

    public function __construct( private Sentient_Forms_Plugin $plugin )
    {
        add_filter( 'cron_schedules', [ $this, 'register_cron_schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'flush_queue' ] );
        $this->maybe_schedule_flush();

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
            'action_id'     => $context['action_id'] ?? '',
            'execution_request_id' => $context['execution_request_id'] ?? '',
            'provider_path' => $context['provider_path'] ?? $context['provider'] ?? '',
            'adapter'       => $context['form_source'] ?? '',
            'attempt'       => (int) ( $context['attempt'] ?? 1 ),
            'max_attempts'  => (int) ( $context['max_attempts'] ?? 3 ),
            'job_type'      => $context['job_type'] ?? 'execution',
            'status'        => 'success',
        ] );
    }

    public function handle_job_failure( array $context, WP_Error $error ): void
    {
        $this->queue_event( 'async_job_failure', [
            'action_id'     => $context['action_id'] ?? '',
            'execution_request_id' => $context['execution_request_id'] ?? '',
            'provider_path' => $context['provider_path'] ?? $context['provider'] ?? '',
            'adapter'       => $context['form_source'] ?? '',
            'attempt'       => (int) ( $context['attempt'] ?? 1 ),
            'max_attempts'  => (int) ( $context['max_attempts'] ?? 3 ),
            'job_type'      => $context['job_type'] ?? 'execution',
            'status'        => 'failure',
            'error_code'    => $error->get_error_code(),
        ] );
    }

    public function handle_health_warning( array $warning ): void
    {
        $this->queue_event( 'async_health_warning', [
            'action_id'    => $warning['action_id'] ?? '',
            'provider_path' => $warning['provider_path'] ?? $warning['provider'] ?? '',
            'adapter'      => $warning['adapter'] ?? $warning['form_source'] ?? '',
            'job_type'     => $warning['job_type'] ?? 'async_health',
            'status'       => 'warning',
            'warning_code' => $warning['warning_code'] ?? $warning['code'] ?? 'async_health_warning',
        ] );
    }

    public function queue_event( string $event_type, array $payload ): void
    {
        if ( ! in_array( $event_type, self::ALLOWED_EVENTS, true ) )
        {
            $this->log_debug( 'queue_event skipped: unsupported event type', [ 'event' => $event_type ] );
            return;
        }

        if ( ! $this->remote_telemetry_ready() )
        {
            $this->unschedule_flush();
            return;
        }

        $envelope = $this->format_payload( $event_type, $this->metadata_payload( $payload ) );
        $this->store()->enqueue_telemetry( $event_type, $envelope );
    }

    public function get_settings(): array
    {
        return $this->plugin->get_telemetry_settings();
    }

    public function update_and_sync( bool $opt_in, string $actor_hint ): array | WP_Error
    {
        $license = $this->plugin->get_license_data();
        $site_id = $this->remote_site_id( $license );
        if ( empty( $license['proxy_api_key'] ) || '' === $site_id )
        {
            $message = __( 'Telemetry consent was saved locally. Remote telemetry will not queue or send until a Sentient Forms site identity is connected.', 'sentient-forms' );
            $this->plugin->set_telemetry_settings(
                [
                    'telemetry_opt_in' => $opt_in,
                    'last_error'       => $opt_in ? $message : null,
                    'updated_at'       => current_time( 'mysql' ),
                ]
            );
            $this->maybe_schedule_flush();
            $this->log_debug( 'telemetry opt-in saved locally without remote identity', [ 'telemetry_opt_in' => $opt_in ] );

            return $this->plugin->get_telemetry_settings();
        }

        $body = [
            'telemetry_opt_in' => (bool) $opt_in,
            'actor'           => $actor_hint,
            'site_id'         => $site_id,
        ];

        $endpoint = Sentient_Forms_Url_Policy::validate_outbound_url(
            trailingslashit( $this->plugin->get_cps_base_url_value() ) . self::TELEMETRY_ENDPOINT,
            'service'
        );
        if ( is_wp_error( $endpoint ) )
        {
            $this->plugin->set_telemetry_settings(
                [
                    'telemetry_opt_in' => $opt_in,
                    'last_error'       => $endpoint->get_error_message(),
                    'updated_at'       => current_time( 'mysql' ),
                ]
            );
            $this->maybe_schedule_flush();

            return $this->plugin->get_telemetry_settings();
        }

        $response = Sentient_Forms_Url_Policy::remote_post(
            $endpoint,
            [
                'timeout'            => 10,
                'headers'            => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $license['proxy_api_key'],
                ],
                'body'               => wp_json_encode( $body ),
            ],
            'service'
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
            $this->maybe_schedule_flush();

            return $this->plugin->get_telemetry_settings();
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
        $this->maybe_schedule_flush();
        $this->log_debug( 'telemetry opt-in sync success', [ 'telemetry_opt_in' => $settings['telemetry_opt_in'] ] );

        return $this->plugin->get_telemetry_settings();
    }

    public function maybe_schedule_flush(): void
    {
        if ( ! $this->remote_telemetry_ready() )
        {
            $this->unschedule_flush();
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) )
        {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    public function unschedule_flush(): void
    {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    private function remote_telemetry_ready(): bool
    {
        if ( ! $this->consent_enabled() )
        {
            return false;
        }

        $license = $this->plugin->get_license_data();
        $proxy_key = isset( $license['proxy_api_key'] ) ? trim( (string) $license['proxy_api_key'] ) : '';
        $site_id   = $this->remote_site_id( $license );

        return '' !== $proxy_key && '' !== $site_id;
    }

    private function remote_site_id( ?array $license = null ): string
    {
        $license = $license ?? $this->plugin->get_license_data();
        $site_id = isset( $license['site_id'] ) ? trim( (string) $license['site_id'] ) : '';
        if ( '' !== $site_id )
        {
            return sanitize_text_field( $site_id );
        }

        return sanitize_text_field( (string) get_option( 'sentient_forms_site_id', '' ) );
    }

    private function metadata_payload( array $payload ): array
    {
        if ( empty( $payload['provider_path'] ) && ! empty( $payload['provider'] ) )
        {
            $payload['provider_path'] = $payload['provider'];
        }

        $metadata = [
            'schema_version' => self::TELEMETRY_PAYLOAD_SCHEMA,
            'plugin_version' => defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : 'unknown',
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => PHP_VERSION,
        ];

        foreach ( self::ALLOWED_METADATA_KEYS as $key )
        {
            if ( array_key_exists( $key, $metadata ) || ! array_key_exists( $key, $payload ) )
            {
                continue;
            }

            $value = $this->sanitize_metadata_value( $payload[ $key ] );
            if ( null !== $value && '' !== $value )
            {
                $metadata[ $key ] = $value;
            }
        }

        return $metadata;
    }

    private function sanitize_metadata_value( mixed $value ): string | int | float | bool | null
    {
        if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) )
        {
            return $value;
        }

        if ( ! is_scalar( $value ) )
        {
            return null;
        }

        return substr( sanitize_text_field( (string) $value ), 0, 191 );
    }

    private function format_payload( string $event_type, array $payload ): array
    {
        $license   = $this->plugin->get_license_data();
        $license_id = isset( $license['license_id'] ) ? sanitize_text_field( (string) $license['license_id'] ) : '';
        $site_id    = $this->remote_site_id( $license );

        return [
            'event'      => $event_type,
            'site_url'   => get_site_url(),
            'timestamp'  => gmdate( 'c' ),
            'payload'    => $payload,
            'license_id' => $license_id ?: null,
            'site_id'    => $site_id ?: null,
        ];
    }

    public function flush_queue(): void
    {
        if ( ! $this->remote_telemetry_ready() )
        {
            $this->unschedule_flush();
            $this->log_debug( 'flush_queue skipped: remote telemetry not ready' );
            return;
        }

        $license = $this->plugin->get_license_data();
        $proxy_key = trim( (string) $license['proxy_api_key'] );

        $endpoint = Sentient_Forms_Url_Policy::validate_outbound_url(
            trailingslashit( $this->plugin->get_cps_base_url_value() ) . 'telemetry/async',
            'service'
        );
        if ( is_wp_error( $endpoint ) )
        {
            $this->log_debug( 'flush_queue skipped: invalid endpoint', [ 'error' => $endpoint->get_error_message() ] );
            return;
        }

        $batch = $this->store()->claim_telemetry_batch( self::DEFAULT_BATCH_SIZE );
        if ( empty( $batch ) )
        {
            $this->log_debug( 'flush_queue skipped: no queued telemetry' );
            return;
        }

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

            $response = Sentient_Forms_Url_Policy::remote_post(
                $endpoint,
                [
                    'timeout'            => 5,
                    'headers'            => $headers,
                    'body'               => wp_json_encode( $payload ),
                ],
                'service'
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
