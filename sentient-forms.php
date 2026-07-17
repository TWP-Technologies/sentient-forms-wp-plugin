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
const SENTIENT_FORMS_DEFAULT_CPS_BASE_URL = 'https://api.sentientforms.com/v1';
const SENTIENT_FORMS_RELEASE_SOURCE_URL = 'https://github.com/TWP-Technologies/sentient-forms-wp-plugin/tree/v0.11.0';
define( 'SENTIENT_FORMS_PLUGIN_DIR', plugin_dir_path( SENTIENT_FORMS_PLUGIN_FILE ) );
define( 'SENTIENT_FORMS_PLUGIN_URL', plugin_dir_url( SENTIENT_FORMS_PLUGIN_FILE ) );

if ( ! function_exists( 'sentient_forms_debug_log' ) )
{
    /**
     * Emit opt-in diagnostic information without writing directly to PHP logs.
     *
     * The plugin does not attach a default writer. Site owners or support tooling
     * can opt in by filtering `sentient_forms_debug_log_enabled` and handling the
     * `sentient_forms_debug_log` action.
     *
     * @param string $message Diagnostic message.
     * @param array  $context Redacted contextual fields.
     */
    function sentient_forms_debug_log( string $message, array $context = [] ): void
    {
        $enabled = (bool) apply_filters(
            'sentient_forms_debug_log_enabled',
            defined( 'WP_DEBUG' ) && WP_DEBUG,
            $message,
            $context
        );

        if ( ! $enabled )
        {
            return;
        }

        do_action( 'sentient_forms_debug_log', $message, $context );
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
