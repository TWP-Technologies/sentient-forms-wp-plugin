<?php
/**
 * Main REST API Loader for the Sentient Forms plugin.
 * This class is responsible for discovering, instantiating, and registering
 * all REST API controller classes for the plugin. It relies on the plugin's
 * autoloader (using a class map) to make controller classes available.
 *
 * @package    SentientForms
 * @subpackage REST_API
 * @since      0.1.0
 */

// Ensure this file is loaded within WordPress.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_REST_API
 * Initializes and registers all REST API controllers.
 * Assumes SENTIENT_FORMS_PLUGIN_DIR is defined in the main plugin file
 * and points to the root directory of the Sentient Forms plugin.
 * It also assumes that an autoloader (like Sentient_Forms_Autoloader) is active.
 */
final class Sentient_Forms_REST_API
{

    /**
     * Stores instantiated controller objects.
     *
     * @var array<Abstract_Sentient_Forms_Base_Controller>
     */
    private array $controllers = [];

    /**
     * Constructor.
     * Discovers, instantiates, and registers REST API controllers.
     * Hooks into 'rest_api_init' to register routes from all controllers.
     *
     * @since 0.1.0
     */
    public function __construct()
    {
        // Dependencies like Abstract Controllers, Traits, Validators, Permissions
        // are expected to be handled by the plugin's autoloader.
        // No explicit require_once calls here for those if autoloader is correctly configured.

        $this->instantiate_controllers();
        add_action( 'rest_api_init', [ $this, 'register_all_routes' ] );
    }

    /**
     * Defines the core REST API controller classes for the plugin.
     * Uses ::class for better type safety and refactorability.
     *
     * @return array<string> An array of fully qualified class names for core controllers.
     * @since 0.1.0
     */
    private function get_core_controller_classes(): array
    {
        $core_controllers = [
            Sentient_Forms_Async_Settings_Controller::class,
            Sentient_Forms_Async_Health_Controller::class,
            Sentient_Forms_Action_Definitions_Controller::class,
            Sentient_Forms_Custom_Actions_Controller::class,
            Sentient_Forms_Credit_Controller::class,
            Sentient_Forms_Form_Actions_Controller::class,
            Sentient_Forms_Form_Controller::class,
            Sentient_Forms_License_Controller::class,
            Sentient_Forms_Llm_Controller::class,
            Sentient_Forms_Settings_Controller::class,
            Sentient_Forms_Telemetry_Controller::class,
            // Add more core controller class names as you create them.
        ];

        $existing_core_controllers = [];
        foreach ( $core_controllers as $class_name )
        {
            // Check if class exists before adding. Autoloader should attempt to load it here.
            if ( !class_exists( $class_name ) )
            {
                error_log( "Sentient Forms REST API: Core controller class $class_name not found and will be skipped." );
                continue;
            }

            $existing_core_controllers[] = $class_name;
        }

        return $existing_core_controllers;
    }

    /**
     * Instantiates all registered controller classes.
     * Relies on the autoloader to make class files available.
     *
     * @since 0.1.0
     */
    private function instantiate_controllers(): void
    {
        $controller_classes_to_load = $this->get_core_controller_classes();

        /**
         * Filters the list of REST API controller class names to be loaded.
         * Allows other parts of the plugin or third-party extensions to add their
         * REST API controller classes. Classes added here must be autoloadable.
         *
         * @param array<string> $controller_classes An array of controller class names.
         *
         * @since 0.1.0
         */
        $controller_classes_to_load = apply_filters( 'sentient_forms_rest_api_controller_classes', $controller_classes_to_load );

        foreach ( $controller_classes_to_load as $controller_class )
        {
            if ( !is_string( $controller_class ) || !class_exists( $controller_class ) )
            {
                error_log( "Sentient Forms REST API: Invalid or non-existent controller class provided: " . print_r( $controller_class, true ) );
                continue;
            }

            // Check if the class is a subclass of the abstract base controller.
            if ( !is_subclass_of( $controller_class, Abstract_Sentient_Forms_Base_Controller::class ) )
            {
                error_log(
                    "Sentient Forms REST API: Controller class $controller_class does not extend Abstract_Sentient_Forms_Base_Controller.",
                );
                continue;
            }

            $this->controllers[] = new $controller_class();
        }
    }

    /**
     * Calls the 'register_routes' method on all instantiated controllers.
     * This method is hooked into 'rest_api_init'.
     *
     * @since 0.1.0
     */
    public function register_all_routes(): void
    {
        foreach ( $this->controllers as $controller )
        {
            // The check `method_exists($controller, 'register_routes')` is technically redundant
            // if all controllers must extend Abstract_Sentient_Forms_Base_Controller which declares
            // `register_routes` as abstract. However, it's a safe check.
            if ( !method_exists( $controller, 'register_routes' ) )
            {
                error_log( sprintf( "Sentient Forms REST API: Controller %s does not have a register_routes method.", get_class( $controller ) ) );
                continue;
            }

            $controller->register_routes();
        }
    }
}
