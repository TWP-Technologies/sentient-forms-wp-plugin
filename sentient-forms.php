<?php
/**
 * Plugin Name: Sentient Forms
 * Plugin URI: https://sentientforms.com
 * Description: Integrate Large Language Models (LLMs) with form builders to automate intelligent actions on form submissions.
 * Version: 0.11.0
 * Author: TWP Technologies, LLC.
 * Author URI: https://twp.tech
 * Text Domain: sentient-forms
 * Domain Path: /languages
 * Requires at least: 6.8
 * Requires PHP: 8.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

// Define plugin constants.
const SENTIENT_FORMS_VERSION     = '0.11.0';
const SENTIENT_FORMS_DB_VERSION  = '2026.07.15.submission_native_correlation';
const SENTIENT_FORMS_PLUGIN_FILE = __FILE__;
const SENTIENT_FORMS_DEFAULT_CPS_BASE_URL = 'https://api.sentientforms.com/v2';
const SENTIENT_FORMS_RELEASE_SOURCE_URL = 'https://github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v0.11.0';
define( 'SENTIENT_FORMS_PLUGIN_DIR', plugin_dir_path( SENTIENT_FORMS_PLUGIN_FILE ) );
define( 'SENTIENT_FORMS_PLUGIN_URL', plugin_dir_url( SENTIENT_FORMS_PLUGIN_FILE ) );

if ( ! function_exists( 'sentient_forms_debug_log' ) )
{
    /**
     * Emit an allowlisted local diagnostic event after explicit consent.
     *
     * Arbitrary caller messages and context never cross this boundary. Debug mode
     * cannot enable diagnostics, and nothing is sent off-site.
     *
     * @param string $message Candidate diagnostic code or internal message.
     * @param array  $context Candidate contextual fields.
     */
    function sentient_forms_debug_log( string $message, array $context = [] ): void
    {
        $plugin_options = get_option( 'sentient_forms_settings', [] );
        $plugin_options = is_array( $plugin_options ) ? $plugin_options : [];
        $api_options    = get_option( 'sentient_forms_plugin_settings', [] );
        $api_options    = is_array( $api_options ) ? $api_options : [];
        $telemetry      = isset( $plugin_options['telemetry'] ) && is_array( $plugin_options['telemetry'] )
            ? $plugin_options['telemetry']
            : [];
        $consented      = ! empty( $telemetry['telemetry_opt_in'] );
        $logging_on     = ! empty( $plugin_options['enable_logging'] ) || ! empty( $api_options['enable_logging'] );
        $logging_on     = (bool) apply_filters(
            'sentient_forms_enable_logging',
            $logging_on || ( defined( 'SENTIENT_FORMS_LOG_ENABLED' ) && SENTIENT_FORMS_LOG_ENABLED )
        );

        if ( ! $consented || ! $logging_on )
        {
            return;
        }

        $allowed_keys = [
            'diagnostic_code',
            'action_id',
            'action_code',
            'execution_request_id',
            'adapter',
            'adapter_id',
            'form_source',
            'provider_path',
            'provider',
            'job_type',
            'attempt',
            'max_attempts',
            'status',
            'error_code',
            'warning_code',
            'reason',
            'duration_ms',
            'queue_wait_ms',
            'run_at',
            'event',
        ];
        $stable_code_keys = [
            'diagnostic_code',
            'action_id',
            'action_code',
            'adapter',
            'adapter_id',
            'form_source',
            'provider_path',
            'provider',
            'job_type',
            'status',
            'error_code',
            'warning_code',
            'reason',
            'event',
        ];
        $safe_context = [];
        foreach ( $allowed_keys as $key )
        {
            if ( ! array_key_exists( $key, $context ) )
            {
                continue;
            }

            $value = $context[ $key ];
            if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) )
            {
                $safe_context[ $key ] = $value;
                continue;
            }
            if ( ! is_scalar( $value ) )
            {
                continue;
            }

            $value = substr( sanitize_text_field( (string) $value ), 0, 191 );
            if ( in_array( $key, $stable_code_keys, true )
                && ! preg_match( '/^[a-z][a-z0-9_-]{1,63}$/D', $value ) )
            {
                continue;
            }
            if ( '' !== $value )
            {
                $safe_context[ $key ] = $value;
            }
        }

        $diagnostic_code = isset( $safe_context['diagnostic_code'] )
            ? sanitize_key( (string) $safe_context['diagnostic_code'] )
            : '';
        if ( '' === $diagnostic_code && preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $message ) )
        {
            $diagnostic_code = sanitize_key( $message );
        }
        $safe_context['diagnostic_code'] = '' !== $diagnostic_code ? $diagnostic_code : 'generic_debug_event';
        unset( $safe_context['provider'] );

        do_action( 'sentient_forms_debug_log', 'Sentient Forms diagnostic event.', $safe_context );
    }
}

/**
 * The ID of the default "free tier" LLM model.
 * This should be a valid model ID from one of the registered Sentient_Forms_Llm_Model_Interface implementations.
 * Ensure a model with this ID is registered and its `is_default_for_free_tier()` returns true if that logic is used.
 */
if ( !defined( 'SENTIENT_FORMS_DEFAULT_FREE_LLM_ID' ) )
{
    define( 'SENTIENT_FORMS_DEFAULT_FREE_LLM_ID', 'gemini-3-flash-preview' );
}

// Bootstrap Composer dependencies (Action Scheduler and tooling).
$sentient_forms_composer_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $sentient_forms_composer_autoload ) )
{
    require_once $sentient_forms_composer_autoload;
}

// Ensure Action Scheduler is loaded when bundled via Composer.
$sentient_forms_action_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( !function_exists( 'as_schedule_single_action' ) && file_exists( $sentient_forms_action_scheduler ) )
{
    require_once $sentient_forms_action_scheduler;
}

// Include the autoloader.
require_once SENTIENT_FORMS_PLUGIN_DIR . 'includes/class-autoloader.php';
require_once SENTIENT_FORMS_PLUGIN_DIR . 'includes/class-sentient-forms-installer.php';
require_once SENTIENT_FORMS_PLUGIN_DIR . 'includes/class-sentient-forms-plugin.php';

register_activation_hook( __FILE__, [ 'Sentient_Forms_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Sentient_Forms_Installer', 'deactivate' ] );
register_uninstall_hook( __FILE__, [ 'Sentient_Forms_Installer', 'uninstall' ] );

// Include template functions if any.
// require_once SENTIENT_FORMS_PLUGIN_PATH . 'includes/template-functions.php';

$sentient_forms_bootstrap = static function (): void {
    if ( class_exists( 'Sentient_Forms_Plugin' ) )
    {
        Sentient_Forms_Plugin::instance();
        return;
    }

    sentient_forms_debug_log( 'Sentient Forms: Main plugin function Sentient_Forms_Plugin was not found.' );
};

if ( did_action( 'init' ) )
{
    $sentient_forms_bootstrap();
}
else
{
    add_action( 'init', $sentient_forms_bootstrap, 0 );
}

// Activation/Deactivation hooks are typically registered within the main plugin class constructor or a dedicated hooks method.
// Example: register_activation_hook( __FILE__, array( 'Sentient_Forms_Plugin', 'activate' ) );
// This is already handled inside the Sentient_Forms_Plugin class.
