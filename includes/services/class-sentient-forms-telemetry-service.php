<?php
/**
 * Handles consent for metadata-only local diagnostic events.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Telemetry_Service
{
    private const CRON_HOOK = 'sentient_forms_flush_telemetry';
    private const TELEMETRY_PAYLOAD_SCHEMA = 'sentient_forms_telemetry_metadata.v1';
    private const LOG_PREFIX = '[sentient-forms][diagnostics] ';
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
        $this->unschedule_flush();
    }

    public function queue_event( string $event_type, array $payload ): void
    {
        if ( ! in_array( $event_type, self::ALLOWED_EVENTS, true ) )
        {
            $this->log_debug( 'queue_event skipped: unsupported event type', [ 'event' => $event_type ] );
            return;
        }

        if ( ! $this->consent_enabled() )
        {
            return;
        }

        $this->log_debug(
            'local diagnostic event',
            [
                'event'    => $event_type,
                'metadata' => $this->metadata_payload( $payload ),
            ]
        );
    }

    public function get_settings(): array
    {
        return $this->plugin->get_telemetry_settings();
    }

    public function update_and_sync( bool $opt_in, string $actor_hint ): array
    {
        unset( $actor_hint );

        $this->plugin->set_telemetry_settings(
            [
                'telemetry_opt_in' => $opt_in,
                'updated_at'       => current_time( 'mysql' ),
            ]
        );
        $this->unschedule_flush();

        return $this->plugin->get_telemetry_settings();
    }

    public function maybe_schedule_flush(): void
    {
        $this->unschedule_flush();
    }

    public function unschedule_flush(): void
    {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function flush_queue(): void
    {
        $this->unschedule_flush();
        $this->log_debug( 'retired remote queue remains disabled' );
    }

    private function consent_enabled(): bool
    {
        $telemetry = $this->plugin->get_telemetry_settings();

        return ! empty( $telemetry['telemetry_opt_in'] );
    }

    private function log_debug( string $message, array $context = [] ): void
    {
        sentient_forms_debug_log( self::LOG_PREFIX . $message, $context );
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
}
