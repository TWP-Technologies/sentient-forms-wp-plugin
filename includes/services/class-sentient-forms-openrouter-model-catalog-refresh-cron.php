<?php
/**
 * Consent-gated OpenRouter model catalog refresh cron.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_OpenRouter_Model_Catalog_Refresh_Cron
{
    public const HOOK = 'sentient_forms_openrouter_model_catalog_refresh';

    public static function register_hooks(): void
    {
        add_action( self::HOOK, [ self::class, 'refresh' ] );
        add_action( 'sentient_forms_openrouter_model_refresh_consent_recorded', [ self::class, 'sync_schedule' ], 10, 0 );

        self::sync_schedule();
    }

    public static function sync_schedule(): void
    {
        if ( ! self::has_refresh_consent() )
        {
            self::unschedule();
            return;
        }

        if ( wp_next_scheduled( self::HOOK ) )
        {
            return;
        }

        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
    }

    public static function unschedule(): void
    {
        if ( function_exists( 'wp_clear_scheduled_hook' ) )
        {
            wp_clear_scheduled_hook( self::HOOK );
        }
    }

    public static function refresh(): void
    {
        if ( ! self::has_refresh_consent() )
        {
            self::unschedule();
            return;
        }

        $controller = new Sentient_Forms_Local_Providers_Controller();
        $result     = $controller->refresh_openrouter_model_catalog(
            [
                'output_modalities' => 'text',
            ]
        );

        if ( is_wp_error( $result ) )
        {
            sentient_forms_debug_log(
                'OpenRouter model catalog cron refresh failed.',
                [
                    'error_code'    => $result->get_error_code(),
                    'error_message' => $result->get_error_message(),
                ]
            );
        }
    }

    private static function has_refresh_consent(): bool
    {
        global $wpdb;

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );

        return null !== $consents->latest_for_provider_action( 'openrouter', 'refresh_models' );
    }
}
