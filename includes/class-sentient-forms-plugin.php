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

    private ?Sentient_Forms_Async_Metadata_Store $async_metadata_store = null;

    private ?Sentient_Forms_Async_Settings_Service $async_settings_service = null;

    private ?Sentient_Forms_Async_Health_Service $async_health_service = null;

    private ?Sentient_Forms_Async_Request_Store $async_request_store = null;

    private ?Sentient_Forms_Telemetry_Service $telemetry_service = null;
    private ?Sentient_Forms_Condition_Evaluator $condition_evaluator = null;
    private ?Sentient_Forms_Mapping_Dependency_Planner $mapping_dependency_planner = null;
    private ?Sentient_Forms_Logger $logger = null;

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
        $this->boot_form_adapters();
        $this->load_dependencies();
        Sentient_Forms_Installer::maybe_upgrade();
        $this->init_hooks();
        $this->register_debug_hooks();

        if ( defined( 'WP_CLI' ) && WP_CLI )
        {
            require_once __DIR__ . '/cli/class-sentient-forms-async-cli-command.php';
            require_once __DIR__ . '/cli/class-sentient-forms-mappings-migrate-cli-command.php';
        }

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
        $this->get_async_handler();
        $this->get_async_health_service();
        $this->get_telemetry_service();
        $this->get_logger();
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

    private function boot_form_adapters(): void
    {
        if ( null === $this->adapter_registry )
        {
            return;
        }

        foreach ( $this->adapter_registry->get_adapters( true ) as $adapter )
        {
            if ( method_exists( $adapter, 'init' ) )
            {
                $adapter->init();
            }
        }
    }

    /**
     * Initialize WordPress hooks.
     * Adds core action and filter hooks used by the plugin.
     */
    private function init_hooks(): void
    {
        Sentient_Forms_Local_Data_Governance::register_hooks();
        Sentient_Forms_Site_Context_Controller::register_hooks();
        Sentient_Forms_OpenRouter_Model_Catalog_Refresh_Cron::register_hooks();

        if ( Sentient_Forms_Installer::is_network_active() )
        {
            add_action( 'wp_initialize_site', [ Sentient_Forms_Installer::class, 'initialize_new_site' ] );
        }

        // Add other core plugin hooks here. For example, hooks for processing form submissions
        // might be set up here or dynamically by the adapters/actions themselves.
    }

    private function register_debug_hooks(): void
    {
        $should_log = (bool) apply_filters( 'sentient_forms_enable_debug_evaluation_logging', defined( 'WP_DEBUG' ) && WP_DEBUG );
        if ( ! $should_log )
        {
            return;
        }

        add_action(
            'sentient_forms_debug_evaluation_payload',
            static function ( array $payload, array $job, array $result ): void {
                if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG )
                {
                    return;
                }

                $entry_id  = $job['context']['entry_id'] ?? 'unknown';
                $action_id = $job['context']['action_id'] ?? 'unknown';
                sentient_forms_debug_log(
                    'Sentient Forms evaluation payload prepared.',
                    [
                        'entry_id'  => $entry_id,
                        'action_id' => $action_id,
                        'payload'   => $payload,
                        'result'    => $result,
                    ]
                );
            },
            10,
            3
        );
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

    public function get_logger(): Sentient_Forms_Logger
    {
        if ( null === $this->logger )
        {
            if ( !class_exists( 'Sentient_Forms_Logger' ) )
            {
                require_once __DIR__ . '/logging/class-sentient-forms-logger.php';
            }

            $option = get_option( 'sentient_forms_settings', [] );
            $api_option = get_option( 'sentient_forms_plugin_settings', [] );
            $enabled_via_option = ! empty( $option['enable_logging'] ) || ! empty( $api_option['enable_logging'] );
            $enabled = (bool) apply_filters(
                'sentient_forms_enable_logging',
                $enabled_via_option || ( defined( 'SENTIENT_FORMS_LOG_ENABLED' ) && SENTIENT_FORMS_LOG_ENABLED )
            );

            $this->logger = new Sentient_Forms_Logger( $enabled );
        }

        return $this->logger;
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

    public function process_action_async( string $action_id, array $data, array $settings, array $context = [] ): bool | WP_Error
    {
        $central_action_id = $settings['central_action_id'] ?? '';
        if ( empty( $central_action_id ) )
        {
            return false;
        }

        $settings     = $this->normalize_batch_settings_for_runtime( $settings );
        $action_label = $settings['action_name_label'] ?? ( $central_action_id ?: $action_id );
        $entry_id     = $context['entry_id'] ?? ( $data['entry']['id'] ?? null );

        $context = array_merge(
            [
                'form_source'           => $context['form_source'] ?? null,
                'source'                => $context['source'] ?? ( $context['form_source'] ?? 'gravity_forms' ),
                'hook'                  => $context['hook'] ?? 'gform_after_submission',
                'action_id'             => $context['action_id'] ?? $action_id,
                'form_id'               => isset( $data['form']['id'] ) ? (string) $data['form']['id'] : null,
                'entry_id'              => isset( $entry_id ) && '' !== $entry_id ? (string) $entry_id : null,
                'central_action_id'     => (string) $central_action_id,
                'action_name_label'     => (string) $action_label,
                'action_type_indicator' => $settings['action_type_indicator'] ?? null,
                'local_mapping_id'      => $settings['local_mapping_id'] ?? null,
            ],
            $context,
        );

        if ( isset( $context['form_id'] ) && '' !== $context['form_id'] )
        {
            $context['form_id'] = (string) $context['form_id'];
        }

        if ( isset( $context['entry_id'] ) && '' !== $context['entry_id'] )
        {
            $context['entry_id'] = (string) $context['entry_id'];
        }

        if ( isset( $context['action_name_label'] ) )
        {
            $context['action_name_label'] = (string) $context['action_name_label'];
        }

        if ( isset( $context['action_type_indicator'] ) && is_scalar( $context['action_type_indicator'] ) && '' !== $context['action_type_indicator'] )
        {
            $context['action_type_indicator'] = (string) $context['action_type_indicator'];
        }

        if ( isset( $context['local_mapping_id'] ) && is_scalar( $context['local_mapping_id'] ) && '' !== $context['local_mapping_id'] )
        {
            $context['local_mapping_id'] = (string) $context['local_mapping_id'];
        }

        $context['central_action_id'] = (string) ( $context['central_action_id'] ?? $central_action_id );

        $execution_request_id = Sentient_Forms_Execution_Identity::generate(
            $central_action_id,
            $data['form'] ?? [],
            $data['entry'] ?? [],
            $context,
        );

        $request_store = $this->get_async_request_store();
        $recorded = $request_store->record(
            $execution_request_id,
            [
                'action_id'      => $central_action_id ?: $action_id,
                'adapter'        => $context['form_source'] ?? null,
                'status'         => 'queued',
                'payload_digest' => $this->async_job_payload_digest(
                    (string) $central_action_id,
                    $data,
                    $settings,
                    $context
                ),
            ]
        );
        if ( is_wp_error( $recorded ) )
        {
            return $recorded;
        }
        if ( true !== $recorded )
        {
            return false;
        }

        $batch_settings = isset( $settings['batch_settings'] ) && is_array( $settings['batch_settings'] )
            ? $settings['batch_settings']
            : [];
        $batch_enabled = ! empty( $batch_settings['enabled'] )
            && $this->is_after_submission_batch_hook( $context['hook'] ?? '' );
        $action_type_indicator = (string) ( $settings['action_type_indicator'] ?? '' );
        $is_cps_managed_action = in_array( $action_type_indicator, [ 'master', 'custom' ], true );

        // CB-EXEC-003/004: Use CPS-managed queue for batched CPS-backed actions.
        // If enqueue fails, schedule a local fallback at max_wait_seconds.
        if ( $is_cps_managed_action && $batch_enabled )
        {
            $async_options = [
                'delay_seconds'    => (int) ( $batch_settings['delay_seconds'] ?? 60 ),
                'max_wait_seconds' => (int) ( $batch_settings['max_wait_seconds'] ?? DAY_IN_SECONDS ),
            ];

            $enqueue = $this->get_action_executor()->enqueue_async(
                $central_action_id,
                $data['form'] ?? [],
                $data['entry'] ?? [],
                array_merge(
                    $context,
                    [
                        'execution_request_id' => $execution_request_id,
                        'central_action_id'    => $central_action_id,
                        'settings'             => $settings,
                    ]
                ),
                $async_options,
            );

            if ( ! is_wp_error( $enqueue ) )
            {
                return true;
            }

            $this->get_logger()->error(
                'cps async enqueue failed; scheduling local fallback',
                [
                    'action_id'            => $action_id,
                    'central_action_id'    => $central_action_id,
                    'execution_request_id' => $execution_request_id,
                    'error_code'           => $enqueue->get_error_code(),
                    'error_message'        => $enqueue->get_error_message(),
                ]
            );

            $fallback_run_at = time() + max( 10, (int) ( $async_options['max_wait_seconds'] ?? DAY_IN_SECONDS ) );
            $scheduled       = $this->get_async_handler()->schedule_action(
                $action_id,
                $data,
                $settings,
                array_merge(
                    $context,
                    [
                        'execution_request_id' => $execution_request_id,
                        'central_action_id'    => $central_action_id,
                        'queue_fallback'       => 'cps_enqueue_failed',
                    ]
                ),
                $fallback_run_at,
            );

            if ( ! $scheduled )
            {
                $request_store->mark_status( $execution_request_id, 'failed', __( 'CPS enqueue + local fallback scheduling failed', 'sentient-forms' ) );
            }

            return $scheduled;
        }

        $scheduled = $this->get_async_handler()->schedule_action(
            $action_id,
            $data,
            $settings,
            array_merge( $context, [ 'execution_request_id' => $execution_request_id, 'central_action_id' => $central_action_id ] ),
        );

        if ( ! $scheduled )
        {
            $request_store->mark_status( $execution_request_id, 'failed', __( 'Scheduling failed', 'sentient-forms' ) );
        }

        return $scheduled;
    }

    /**
     * Hash immutable execution inputs while excluding transient dependency state.
     */
    private function async_job_payload_digest(
        string $central_action_id,
        array $data,
        array $settings,
        array $context
    ): string
    {
        unset(
            $context['dependency_initial_outcomes'],
            $context['dependency_wait_started_at']
        );

        return hash(
            'sha256',
            (string) wp_json_encode(
                [
                    'central_action_id' => $central_action_id,
                    'data'              => $data,
                    'settings'          => $settings,
                    'context'           => $context,
                ]
            )
        );
    }

    private function normalize_batch_settings_for_runtime( array $settings ): array
    {
        if ( ! isset( $settings['batch_settings'] ) || ! is_array( $settings['batch_settings'] ) )
        {
            return $settings;
        }

        $settings['batch_settings'] = [
            'enabled'          => ! empty( $settings['batch_settings']['enabled'] ),
            'delay_seconds'    => max( 10, min( 3600, (int) ( $settings['batch_settings']['delay_seconds'] ?? 60 ) ) ),
            'max_wait_seconds' => max( 43200, min( 604800, (int) ( $settings['batch_settings']['max_wait_seconds'] ?? DAY_IN_SECONDS ) ) ),
        ];

        return $settings;
    }

    private function is_after_submission_batch_hook( mixed $hook ): bool
    {
        return Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION === Sentient_Forms_Form_Source_Lifecycles::normalize_id( $hook );
    }

    public function dispatch_action_evaluation( array $job ): bool
    {
        return $this->get_async_handler()->dispatch_evaluation( $job );
    }

    public function get_async_metadata_store(): Sentient_Forms_Async_Metadata_Store
    {
        if ( null === $this->async_metadata_store )
        {
            $this->async_metadata_store = new Sentient_Forms_Async_Metadata_Store();
        }

        return $this->async_metadata_store;
    }

    public function get_async_settings_service(): Sentient_Forms_Async_Settings_Service
    {
        if ( null === $this->async_settings_service )
        {
            $this->async_settings_service = new Sentient_Forms_Async_Settings_Service();
        }

        return $this->async_settings_service;
    }

    public function get_async_health_service(): Sentient_Forms_Async_Health_Service
    {
        if ( null === $this->async_health_service )
        {
            $this->async_health_service = new Sentient_Forms_Async_Health_Service( $this );
        }

        return $this->async_health_service;
    }

    public function get_async_request_store(): Sentient_Forms_Async_Request_Store
    {
        if ( null === $this->async_request_store )
        {
            global $wpdb;
            $this->async_request_store = new Sentient_Forms_Async_Request_Store( $wpdb );
        }

        return $this->async_request_store;
    }

    public function get_condition_evaluator(): Sentient_Forms_Condition_Evaluator
    {
        if ( null === $this->condition_evaluator )
        {
            $this->condition_evaluator = new Sentient_Forms_Condition_Evaluator();
        }

        return $this->condition_evaluator;
    }

    public function get_mapping_dependency_planner(): Sentient_Forms_Mapping_Dependency_Planner
    {
        if ( null === $this->mapping_dependency_planner )
        {
            $this->mapping_dependency_planner = new Sentient_Forms_Mapping_Dependency_Planner();
        }

        return $this->mapping_dependency_planner;
    }

    public function get_telemetry_service(): Sentient_Forms_Telemetry_Service
    {
        if ( null === $this->telemetry_service )
        {
            $this->telemetry_service = new Sentient_Forms_Telemetry_Service( $this );
        }

        return $this->telemetry_service;
    }

    public function get_cps_base_url_value(): string
    {
        return $this->get_cps_base_url();
    }

    private function get_cps_base_url(): string
    {
        $options  = $this->get_options();
        $base_url = $options['cps_base_url'] ?? null;
        $base_url = apply_filters( 'sentient_forms_cps_base_url', $base_url, $options );

        if ( empty( $base_url ) )
        {
            $base_url = defined( 'SENTIENT_FORMS_DEFAULT_CPS_BASE_URL' )
                ? SENTIENT_FORMS_DEFAULT_CPS_BASE_URL
                : 'https://api.sentientforms.com/v1';
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

    public function update_options( array $options ): void
    {
        $this->save_options( $options );
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

    private function get_telemetry_defaults(): array
    {
        return [
            'telemetry_opt_in' => false,
            'updated_at'       => null,
            'synced_at'        => null,
            'remote_updated_at'=> null,
            'last_error'       => null,
        ];
    }

    public function get_telemetry_settings(): array
    {
        $options  = $this->get_options();
        $stored   = isset( $options['telemetry'] ) && is_array( $options['telemetry'] )
            ? $options['telemetry']
            : [];

        $settings = array_merge( $this->get_telemetry_defaults(), $stored );
        $settings['telemetry_opt_in'] = ! empty( $settings['telemetry_opt_in'] );

        foreach ( [ 'updated_at', 'synced_at', 'remote_updated_at', 'last_error' ] as $field )
        {
            if ( isset( $settings[ $field ] ) && null !== $settings[ $field ] )
            {
                $settings[ $field ] = sanitize_text_field( (string) $settings[ $field ] );
            }
            else
            {
                $settings[ $field ] = null;
            }
        }

        return $settings;
    }

    public function set_telemetry_settings( array $settings ): void
    {
        $options  = $this->get_options();
        $merged   = array_merge( $this->get_telemetry_defaults(), $settings );
        $merged['telemetry_opt_in'] = ! empty( $merged['telemetry_opt_in'] );

        foreach ( [ 'updated_at', 'synced_at', 'remote_updated_at', 'last_error' ] as $field )
        {
            if ( isset( $merged[ $field ] ) && null !== $merged[ $field ] )
            {
                $merged[ $field ] = sanitize_text_field( (string) $merged[ $field ] );
            }
            else
            {
                $merged[ $field ] = null;
            }
        }

        $options['telemetry'] = $merged;
        $this->save_options( $options );
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
        $options        = $this->get_options();
        $stored_license = isset( $options['license'] ) && is_array( $options['license'] )
            ? $options['license']
            : [];

        if ( isset( $options['local_site_identifier'] ) && is_scalar( $options['local_site_identifier'] ) )
        {
            $stored_license['local_site_identifier'] = $options['local_site_identifier'];
        }

        $stored_identifier = isset( $stored_license['local_site_identifier'] ) && is_scalar( $stored_license['local_site_identifier'] )
            ? sanitize_text_field( (string) $stored_license['local_site_identifier'] )
            : '';

        if ( '' !== $stored_identifier )
        {
            return $stored_identifier;
        }

        $identifier = $this->generate_local_site_identifier();

        $license_data                          = array_merge( $this->get_license_defaults(), $stored_license );
        $license_data['local_site_identifier'] = $identifier;
        $options['license']                    = $license_data;

        unset( $options['license_key'], $options['license_status'], $options['proxy_api_key'], $options['local_site_identifier'] );

        $this->save_options( $options );

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
