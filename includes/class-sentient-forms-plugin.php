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
    private static ?Sentient_Forms_Plugin $_instance = null;

    /**
     * LLM Model Registry instance.
     * Manages available Large Language Models.
     *
     * @var Sentient_Forms_Llm_Model_Registry
     */
    private ?Sentient_Forms_Llm_Model_Registry $llm_model_registry = null;

    /**
     * Action Registry instance.
     * Manages available actions that can be performed (e.g., Spam Analysis).
     *
     * @var Sentient_Forms_Action_Registry
     */
    private ?Sentient_Forms_Action_Registry $action_registry = null;

    /**
     * Adapter Registry instance.
     * Manages integrations with different form provider plugins.
     *
     * @var Sentient_Forms_Form_Adapter_Registry
     */
    private ?Sentient_Forms_Form_Adapter_Registry $adapter_registry = null;

    /**
     * REST API instance.
     * Handles interactions with the REST API for the application.
     *
     * @var Sentient_Forms_REST_API
     */
    private ?Sentient_Forms_REST_API $rest_api = null;

    /**
     * Plugin options.
     * Stores settings retrieved from the WordPress options table.
     *
     * @var array|null
     */
    private const OPTION_KEY = 'sentient_forms_settings';

    private ?array $options = null;

    private ?Sentient_Forms_Api_Client $cps_api_client = null;

    private ?Sentient_Forms_Action_Executor $action_executor = null;

    private ?Sentient_Forms_Async_Handler $async_handler = null;

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
        // Initialize registries before loading REST/API dependencies so controllers
        // can resolve the registries during their constructors.
        $this->init_registries();
        $this->load_dependencies();
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

    public function get_action( string $action_id ): ?Sentient_Forms_Action_Interface
    {
        if ( !$this->action_registry )
        {
            return null;
        }

        return $this->action_registry->get_action( $action_id );
    }

    public function get_api_client(): Sentient_Forms_Api_Client
    {
        return $this->get_cps_api_client();
    }

    public function get_cps_api_client(): Sentient_Forms_Api_Client
    {
        if ( null === $this->cps_api_client )
        {
            $this->cps_api_client = new Sentient_Forms_Api_Client( $this->get_cps_base_url() );
        }

        return $this->cps_api_client;
    }

    public function get_action_executor(): Sentient_Forms_Action_Executor
    {
        if ( null === $this->action_executor )
        {
            $this->action_executor = new Sentient_Forms_Action_Executor( $this, $this->get_cps_api_client() );
        }

        return $this->action_executor;
    }

    public function get_async_handler(): Sentient_Forms_Async_Handler
    {
        if ( null === $this->async_handler )
        {
            $this->async_handler = new Sentient_Forms_Async_Handler( $this );
        }

        return $this->async_handler;
    }

    public function process_action_async( string $action_id, array $data, array $settings, array $context = [] ): bool
    {
        $central_action_id = $settings['central_action_id'] ?? '';
        if ( empty( $central_action_id ) )
        {
            return false;
        }

        $context = array_merge(
            [
                'form_source' => $context['form_source'] ?? null,
                'hook'        => $context['hook'] ?? 'gform_after_submission',
                'action_id'   => $context['action_id'] ?? $action_id,
                'form_id'     => $context['form_id'] ?? ( $data['form']['id'] ?? null ),
            ],
            $context,
        );

        $execution_request_id = Sentient_Forms_Action_Executor::generate_execution_request_id(
            $central_action_id,
            $data['form'] ?? [],
            $data['entry'] ?? [],
            $context,
        );

        $cache_key = 'sentient_forms_async_' . $execution_request_id;
        if ( false !== get_transient( $cache_key ) )
        {
            return false;
        }

        $scheduled = $this->get_async_handler()->schedule_action(
            $action_id,
            $data,
            $settings,
            array_merge( $context, [ 'execution_request_id' => $execution_request_id, 'central_action_id' => $central_action_id ] ),
        );

        if ( $scheduled )
        {
            set_transient( $cache_key, 1, HOUR_IN_SECONDS );
        }

        return $scheduled;
    }

    private function get_cps_base_url(): string
    {
        $options  = $this->get_options();
        $base_url = $options['cps_base_url'] ?? null;
        $base_url = apply_filters( 'sentient_forms_cps_base_url', $base_url, $options );

        if ( empty( $base_url ) )
        {
            $base_url = 'https://staging-api.totalwebpartners.com/v1';
        }

        return untrailingslashit( $base_url );
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
            $this->options = get_option( self::OPTION_KEY, [] );
        }
        return is_array( $this->options ) ? $this->options : [];
    }

    private function save_options( array $options ): void
    {
        $this->options = $options;
        update_option( self::OPTION_KEY, $options );
    }

    private function get_license_defaults(): array
    {
        return [
            'license_key'           => '',
            'license_status'        => 'inactive',
            'license_id'            => '',
            'site_id'               => '',
            'proxy_api_key'         => '',
            'tier'                  => '',
            'expiry_date'           => null,
            'last_synced'           => null,
            'local_site_identifier' => '',
        ];
    }

    public function get_license_data(): array
    {
        $options        = $this->get_options();
        $license_data   = [];
        $defaults       = $this->get_license_defaults();
        $stored_license = $options['license'] ?? [];

        if ( isset( $options['license_key'] ) )
        {
            $stored_license['license_key'] = $options['license_key'];
        }
        if ( isset( $options['license_status'] ) )
        {
            $stored_license['license_status'] = $options['license_status'];
        }
        if ( isset( $options['proxy_api_key'] ) )
        {
            $stored_license['proxy_api_key'] = $options['proxy_api_key'];
        }

        foreach ( $defaults as $key => $default_value )
        {
            if ( isset( $stored_license[ $key ] ) )
            {
                $license_data[ $key ] = is_string( $stored_license[ $key ] )
                    ? sanitize_text_field( $stored_license[ $key ] )
                    : $stored_license[ $key ];
            }
            else
            {
                $license_data[ $key ] = $default_value;
            }
        }

        if ( empty( $license_data['local_site_identifier'] ) )
        {
            $license_data['local_site_identifier'] = $this->generate_local_site_identifier();
        }

        return $license_data;
    }

    public function set_license_data( array $data ): void
    {
        $options        = $this->get_options();
        $defaults       = $this->get_license_defaults();
        $license_data   = $this->get_license_data();

        foreach ( $defaults as $key => $default_value )
        {
            if ( array_key_exists( $key, $data ) )
            {
                $value                 = $data[ $key ];
                $license_data[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
            }
        }

        $options['license'] = $license_data;

        unset( $options['license_key'], $options['license_status'], $options['proxy_api_key'] );

        $this->save_options( $options );
    }

    public function clear_license_data(): void
    {
        $license_data                 = $this->get_license_data();
        $license_data['license_status'] = 'inactive';
        $license_data['license_key']    = '';
        $license_data['proxy_api_key']  = '';
        $license_data['license_id']     = '';
        $license_data['site_id']        = '';
        $license_data['tier']           = '';
        $license_data['expiry_date']    = null;
        $license_data['last_synced']    = current_time( 'mysql' );

        $this->set_license_data( $license_data );
    }

    public function get_local_site_identifier(): string
    {
        $license = $this->get_license_data();
        if ( !empty( $license['local_site_identifier'] ) )
        {
            return $license['local_site_identifier'];
        }

        $identifier = $this->generate_local_site_identifier();

        $this->set_license_data(
            array_merge(
                $license,
                [ 'local_site_identifier' => $identifier ]
            )
        );

        return $identifier;
    }

    private function generate_local_site_identifier(): string
    {
        if ( function_exists( 'wp_generate_uuid4' ) )
        {
            return str_replace( '-', '', wp_generate_uuid4() );
        }

        return substr( hash( 'sha256', uniqid( (string) get_current_user_id(), true ) ), 0, 32 );
    }

    /**
     * Get Proxy API Key from plugin options.
     *
     * @return string The Proxy API Key, or an empty string if not set.
     */
    public function get_proxy_api_key(): string
    {
        $license = $this->get_license_data();
        return $license['proxy_api_key'] ?? '';
    }

    /**
     * Get License Key from plugin options.
     *
     * @return string The License Key, or an empty string if not set.
     */
    public function get_license_key(): string
    {
        $license = $this->get_license_data();
        return $license['license_key'] ?? '';
    }

    /**
     * Get License Status from plugin options.
     *
     * @return string The License Status (e.g., 'valid', 'invalid', 'expired'), or an empty string if not set.
     */
    public function get_license_status(): string
    {
        $license = $this->get_license_data();
        return $license['license_status'] ?? '';
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
