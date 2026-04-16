<?php
/**
 * Plugin Name: Sentient Forms
 * Plugin URI: https://sentientforms.com
 * Description: Integrate Large Language Models (LLMs) with form builders to automate intelligent actions on form submissions.
 * Version: 0.1.0
 * Author: TWP Technologies, LLC.
 * Author URI: https://sentientforms.com
 * Text Domain: sentient-forms
 * Domain Path: /languages
 * Requires at least: 6.8.0
 * Requires PHP: 8.2
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

// Define plugin constants.
const SENTIENT_FORMS_VERSION     = '0.1.0';
const SENTIENT_FORMS_DB_VERSION  = '2025.11.17';
const SENTIENT_FORMS_PLUGIN_FILE = __FILE__;
define( 'SENTIENT_FORMS_PLUGIN_DIR', plugin_dir_path( SENTIENT_FORMS_PLUGIN_FILE ) );
define( 'SENTIENT_FORMS_PLUGIN_URL', plugin_dir_url( SENTIENT_FORMS_PLUGIN_FILE ) );

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

// Include template functions if any.
// require_once SENTIENT_FORMS_PLUGIN_PATH . 'includes/template-functions.php';

$sentient_forms_bootstrap = static function (): void {
    if ( class_exists( 'Sentient_Forms_Plugin' ) )
    {
        Sentient_Forms_Plugin::instance();
        return;
    }

    error_log( 'Sentient Forms: Main plugin function sentient_forms() not found.' );
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
