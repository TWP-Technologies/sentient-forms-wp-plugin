<?php
/**
 * Main Plugin Class for Sentient Forms.
 * Initializes the plugin, sets up hooks, and manages core functionality.
 *
 * @package SentientForms
 * @since   0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

/**
 * Final Class Sentient_Forms_Plugin
 * The main singleton class for the Sentient Forms plugin.
 */
final class Sentient_Forms_Plugin
{

    /**
     * The single instance of the class.
     *
     * @var Sentient_Forms_Plugin
     */
    private static Sentient_Forms_Plugin $_instance;

    /**
     * LLM Model Registry instance.
     * Manages available Large Language Models.
     *
     * @var Sentient_Forms_Llm_Model_Registry
     */
    private Sentient_Forms_Llm_Model_Registry $llm_model_registry;

    /**
     * Action Registry instance.
     * Manages available actions that can be performed (e.g., Spam Analysis).
     *
     * @var Sentient_Forms_Action_Registry
     */
    private Sentient_Forms_Action_Registry $action_registry;

    /**
     * Adapter Registry instance.
     * Manages integrations with different form provider plugins.
     *
     * @var Sentient_Forms_Form_Adapter_Registry
     */
    private Sentient_Forms_Form_Adapter_Registry $adapter_registry;

    /**
     * REST API instance.
     * Handles interactions with the REST API for the application.
     *
     * @var Sentient_Forms_REST_API
     */
    private Sentient_Forms_REST_API $rest_api;

    /**
     * Plugin options.
     * Stores settings retrieved from the WordPress options table.
     *
     * @var array|null
     */
    private ?array $options = null;

    /**
     * Main Sentient_Forms_Plugin Instance.
     * Ensures only one instance of Sentient_Forms_Plugin is loaded or can be loaded.
     * This is a common singleton pattern in WordPress plugins.
     *
     * @return Sentient_Forms_Plugin - Main instance.
     */
    public static function instance(): Sentient_Forms_Plugin
    {
        if ( is_null( self::$_instance ) )
        {
            self::$_instance = new self();
            self::$_instance->init();
        }
        return self::$_instance;
    }

    /**
     * Initialize the plugin.
     * This private method is called once during the first instantiation.
     * It loads dependencies, initializes registries, and sets up WordPress hooks.
     */
    private function init(): void
    {
        $this->load_dependencies();
        $this->init_registries();
        $this->init_hooks();

        // Initialize admin area if in admin context or WP-CLI.
        if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) )
        {
            if ( !class_exists( 'Sentient_Forms_Admin' ) )
            {
                Sentient_Forms_Error_Utils::throw_or_die(
                    'Sentient Forms: Sentient_Forms_Admin class not found during init.',
                    Sentient_Forms_Error_Type::dependency,
                );
            }

            $admin = new Sentient_Forms_Admin( $this ); // Pass plugin instance to Admin.
            $admin->init();
        }
    }

    /**
     * Load plugin dependencies.
     * Includes necessary files and sets up the autoloader if applicable.
     * This method ensures all core interfaces and classes are available.
     */
    private function load_dependencies(): void
    {
        if ( !class_exists( 'Sentient_Forms_REST_API' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Sentient Forms: Sentient_Forms_REST_API class not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->rest_api = new Sentient_Forms_REST_API();
    }

    /**
     * Initialize registries.
     * Creates instances of LLM, Action, and Adapter registries.
     * Populates them with available models, actions, and adapters.
     */
    private function init_registries(): void
    {
        if ( !class_exists( 'Sentient_Forms_Llm_Model_Registry' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Sentient Forms: Sentient_Forms_Llm_Model_Registry class not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        if ( !class_exists( 'Sentient_Forms_Action_Registry' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Sentient Forms: Sentient_Forms_Action_Registry class not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        if ( !class_exists( 'Sentient_Forms_Form_Adapter_Registry' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Sentient Forms: Sentient_Forms_Form_Adapter_Registry class not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->action_registry    = new Sentient_Forms_Action_Registry( $this );
        $this->adapter_registry   = new Sentient_Forms_Form_Adapter_Registry( $this );
        $this->llm_model_registry = new Sentient_Forms_Llm_Model_Registry();
    }

    /**
     * Initialize WordPress hooks.
     * Adds core action and filter hooks used by the plugin.
     */
    private function init_hooks(): void
    {
        // Hook for loading plugin text domain for internationalization.
        add_action( 'plugins_loaded', [ $this, 'load_plugin_textdomain' ] );

        // Add other core plugin hooks here. For example, hooks for processing form submissions
        // might be set up here or dynamically by the adapters/actions themselves.
    }

    /**
     * Load plugin textdomain for internationalization.
     */
    public function load_plugin_textdomain(): void
    {
        if ( defined( 'SENTIENT_FORMS_PLUGIN_FILE' ) )
        {
            load_plugin_textdomain(
                'sentient-forms', // Unique text domain string.
                false,            // Deprecated argument.
                dirname( plugin_basename( SENTIENT_FORMS_PLUGIN_FILE ) ) . '/languages/', // Path to .mo files.
            );
        }
    }

    /**
     * Get the LLM Model Registry.
     * Provides access to the registry managing LLM models.
     *
     * @return Sentient_Forms_Llm_Model_Registry The LLM model registry instance.
     */
    public function get_llm_model_registry(): Sentient_Forms_Llm_Model_Registry
    {
        return $this->llm_model_registry;
    }

    /**
     * Get the Action Registry.
     * Provides access to the registry managing available actions.
     *
     * @return Sentient_Forms_Action_Registry The action registry instance.
     */
    public function get_action_registry(): Sentient_Forms_Action_Registry
    {
        return $this->action_registry;
    }

    /**
     * Get the Adapter Registry.
     * Provides access to the registry managing form provider adapters.
     *
     * @return Sentient_Forms_Form_Adapter_Registry The adapter registry instance.
     */
    public function get_form_adapter_registry(): Sentient_Forms_Form_Adapter_Registry
    {
        return $this->adapter_registry;
    }

    /**
     * Get form adapters.
     * This method provides a direct way to get adapter instances.
     * It can return either only active adapters or all registered adapters.
     *
     * @param bool $only_active Whether to return only active adapters (where $adapter->is_active() is true).
     *                          Defaults to true.
     *
     * @return Sentient_Forms_Adapter_Interface[] An array of adapter instances. Empty if registry not available.
     */
    public function get_adapters( bool $only_active = true ): array
    {
        if ( $only_active )
        {
            return $this->adapter_registry->get_adapters( true );
        }

        return $this->adapter_registry->get_all_adapters();
    }

    /**
     * Get plugin options.
     * Retrieves all plugin settings, typically stored in a single array in wp_options.
     *
     * @return array Plugin options. Defaults to an empty array if no options are found.
     */
    public function get_options(): array
    {
        if ( null === $this->options )
        {
            $this->options = get_option( 'sentient_forms_settings', [] );
        }
        return is_array( $this->options ) ? $this->options : [];
    }

    /**
     * Get Proxy API Key from plugin options.
     *
     * @return string The Proxy API Key, or an empty string if not set.
     */
    public function get_proxy_api_key(): string
    {
        $options = $this->get_options();
        return $options[ 'proxy_api_key' ] ?? '';
    }

    /**
     * Get License Key from plugin options.
     *
     * @return string The License Key, or an empty string if not set.
     */
    public function get_license_key(): string
    {
        $options = $this->get_options();
        return $options[ 'license_key' ] ?? '';
    }

    /**
     * Get License Status from plugin options.
     *
     * @return string The License Status (e.g., 'valid', 'invalid', 'expired'), or an empty string if not set.
     */
    public function get_license_status(): string
    {
        $options = $this->get_options();
        return $options[ 'license_status' ] ?? '';
    }

    /**
     * Cloning is forbidden to prevent multiple instances of this singleton.
     */
    public function __clone()
    {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'sentient-forms' ), '0.1.0' );
    }

    /**
     * Unserializing instances of this class is forbidden.
     */
    public function __wakeup()
    {
        _doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing instances of this class is forbidden.', 'sentient-forms' ), '0.1.0' );
    }
}
