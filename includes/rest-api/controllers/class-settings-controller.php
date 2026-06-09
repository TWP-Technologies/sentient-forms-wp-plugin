<?php
/**
 * REST API Settings Controller class for the Sentient Forms plugin.
 * Handles routes related to managing plugin settings.
 * This class demonstrates how to use separate validator and permission classes.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

// Ensure this file is loaded within WordPress.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Settings_Controller
 * Manages REST API endpoints for plugin settings.
 */
class Sentient_Forms_Settings_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'settings';

    /**
     * Instance of the settings validator.
     *
     * @var Sentient_Forms_Settings_Validator
     * @since 0.1.0
     */
    private Sentient_Forms_Settings_Validator $validator;

    /**
     * Instance of the admin permission checker.
     *
     * @var Sentient_Forms_Admin_Permission
     * @since 0.1.0
     */
    private Sentient_Forms_Admin_Permission $permission_checker;

    /**
     * Option key where plugin settings are stored in WordPress options table.
     *
     * @var string
     */
    const SETTINGS_OPTION_KEY = 'sentient_forms_plugin_settings';

    /**
     * Constructor.
     * Initializes validator and permission checker instances.
     *
     * @since 0.1.0
     */
    public function __construct()
    {
        parent::__construct();

        if ( !class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Admin_Permission not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        if ( !class_exists( 'Sentient_Forms_Settings_Validator' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Settings_Validator not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->permission_checker = new Sentient_Forms_Admin_Permission();
        $this->validator          = new Sentient_Forms_Settings_Validator();
    }

    /**
     * Registers the routes for the settings controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // Route to get all settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_settings' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_collection_params(),
                ],
                // Define the schema for the settings resource.
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        // Route to update settings
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_settings' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                ],
                // Schema is often the same for GET and POST/PUT for the item itself.
            ],
        );
    }

    /**
     * Retrieves all plugin settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     * @since 0.1.0
     */
    public function get_settings( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        return $this->prepare_item_for_response( $this->get_response_settings() );
    }

    /**
     * Updates plugin settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error on failure.
     * @since 0.1.0
     */
    public function update_settings( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $current_settings = get_option( self::SETTINGS_OPTION_KEY, [] );
        $current_settings = is_array( $current_settings ) ? $current_settings : [];
        $updated_settings = [];
        $applied_preset   = null;

        $endpoint_args = $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE );
        $params        = $this->get_update_request_params( $request );

        if ( array_key_exists( 'privacy_setup_profile', $params ) )
        {
            $applied_preset = Sentient_Forms_Local_Data_Governance::apply_privacy_preset( $params['privacy_setup_profile'] );
            if ( ! array_key_exists( 'enable_logging', $params ) )
            {
                $params['enable_logging'] = rest_sanitize_boolean( $applied_preset['enable_logging'] ?? false );
            }
            $params['execution_event_retention_days'] = $applied_preset['execution_event_retention_days'] ?? Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days();
            $params['delete_data_on_uninstall']       = $applied_preset['delete_data_on_uninstall'] ?? Sentient_Forms_Local_Data_Governance::delete_data_on_uninstall_enabled();
            $params['store_full_ai_outputs']          = $applied_preset['store_full_ai_outputs'] ?? Sentient_Forms_Local_Data_Governance::store_full_ai_outputs_enabled();
        }

        foreach ( $endpoint_args as $key => $details )
        {
            if ( in_array( $key, $this->get_data_governance_setting_keys(), true ) )
            {
                continue;
            }

            if ( array_key_exists( $key, $params ) )
            {
                // Value is present in the request.
                // Sanitization should have occurred via 'sanitize_callback' in args definition.
                // Validation has also occurred via 'validate_callback'.
                $updated_settings[ $key ] = $params[ $key ];
            }
            elseif ( array_key_exists( $key, $current_settings ) )
            {
                // Value not in request, retain current value if it exists.
                $updated_settings[ $key ] = $current_settings[ $key ];
            }
            elseif ( isset( $details[ 'default' ] ) )
            {
                // Value not in request and not in current settings, use schema default.
                $updated_settings[ $key ] = $details[ 'default' ];
            }
        }

        // Ensure all plugin-option keys from default settings are present if they weren't updated or in request.
        $updated_settings = array_merge( $this->get_default_plugin_settings(), $current_settings, $updated_settings );
        foreach ( $this->get_data_governance_setting_keys() as $data_governance_key )
        {
            unset( $updated_settings[ $data_governance_key ] );
        }

        update_option( self::SETTINGS_OPTION_KEY, $updated_settings );

        if ( array_key_exists( 'execution_event_retention_days', $params ) )
        {
            Sentient_Forms_Local_Data_Governance::update_execution_event_retention_days( $params['execution_event_retention_days'] );
        }

        if ( array_key_exists( 'delete_data_on_uninstall', $params ) )
        {
            Sentient_Forms_Local_Data_Governance::update_delete_data_on_uninstall( $params['delete_data_on_uninstall'] );
        }

        if ( array_key_exists( 'store_full_ai_outputs', $params ) )
        {
            Sentient_Forms_Local_Data_Governance::update_store_full_ai_outputs( $params['store_full_ai_outputs'] );
        }

        if ( array_key_exists( 'privacy_setup_profile', $params ) && null === $applied_preset )
        {
            Sentient_Forms_Local_Data_Governance::update_privacy_setup_profile( $params['privacy_setup_profile'] );
            Sentient_Forms_Local_Data_Governance::update_privacy_setup_completed_at();
        }

        $response_data = [
            'success'  => true,
            'message'  => __( 'Settings updated successfully.', 'sentient-forms' ),
            'settings' => $this->get_response_settings(),
        ];

        return $this->prepare_item_for_response( $response_data );
    }

    /**
     * WordPress REST requests can arrive as JSON or form-encoded payloads.
     *
     * The Svelte admin app sends JSON, while older tests and tools may still use body params.
     *
     * @return array<string, mixed>
     */
    private function get_update_request_params( WP_REST_Request $request ): array
    {
        $params = [];

        $json_params = $request->get_json_params();
        if ( is_array( $json_params ) )
        {
            $params = $json_params;
        }

        $body_params = $request->get_body_params();
        if ( is_array( $body_params ) )
        {
            $params = array_merge( $params, $body_params );
        }

        return $params;
    }

    /**
     * Retrieves the endpoint arguments for the settings schema.
     * This defines the expected parameters for creating/updating settings.
     *
     * @param string|null $method HTTP method (WP_REST_Server::CREATABLE, WP_REST_Server::EDITABLE).
     *
     * @return array Endpoint arguments.
     * @since 0.1.0
     */
    public function get_endpoint_args_for_item_schema( ?string $method = null ): array
    {
        $args = [];

        if ( WP_REST_Server::EDITABLE === $method || WP_REST_Server::CREATABLE === $method )
        {
            $args[ 'api_key' ]          = [
                'description'       => __( 'Your API Key for the Sentient Forms service.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this->validator, 'validate_api_key_param' ],
                'default'           => null,
            ];
            $args[ 'selected_llm' ]     = [
                'description'       => __( 'The preferred Large Language Model to use.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this->validator, 'validate_selected_llm_param' ],
                'default'           => null,
            ];
            $args[ 'enable_logging' ] = [
                'description'       => __( 'Enable masked diagnostic logging on the WordPress site.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'wp_validate_boolean',
                'validate_callback' => [ $this->validator, 'validate_boolean_param' ],
                'default'           => false,
            ];
            $args[ 'execution_global_disabled' ] = [
                'description'       => __( 'Pause Sentient Forms execution for all form providers.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'wp_validate_boolean',
                'validate_callback' => [ $this->validator, 'validate_boolean_param' ],
                'default'           => false,
            ];
            $args[ 'execution_provider_disabled' ] = [
                'description'       => __( 'Per-provider execution disable map keyed by provider slug.', 'sentient-forms' ),
                'type'              => 'object',
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_provider_disabled_map' ],
                'validate_callback' => [ $this->validator, 'validate_provider_disabled_map_param' ],
                'default'           => [],
            ];
            $args[ 'execution_event_retention_days' ] = [
                'description'       => __( 'Local execution log retention window in days. Use 0 for manual cleanup only.', 'sentient-forms' ),
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => [ Sentient_Forms_Local_Data_Governance::class, 'sanitize_execution_event_retention_days' ],
                'validate_callback' => [ $this->validator, 'validate_execution_event_retention_days_param' ],
                'enum'              => Sentient_Forms_Local_Data_Governance::execution_event_retention_choices(),
                'default'           => Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days(),
            ];
            $args[ 'delete_data_on_uninstall' ] = [
                'description'       => __( 'Delete all plugin-owned local data when Sentient Forms is uninstalled.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'wp_validate_boolean',
                'validate_callback' => [ $this->validator, 'validate_boolean_param' ],
                'default'           => Sentient_Forms_Local_Data_Governance::delete_data_on_uninstall_enabled(),
            ];
            $args[ 'store_full_ai_outputs' ] = [
                'description'       => __( 'Store full AI outputs locally for troubleshooting and action review.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'wp_validate_boolean',
                'validate_callback' => [ $this->validator, 'validate_boolean_param' ],
                'default'           => Sentient_Forms_Local_Data_Governance::store_full_ai_outputs_enabled(),
            ];
            $args[ 'privacy_setup_profile' ] = [
                'description'       => __( 'Recorded privacy and visibility preset chosen by the site administrator.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => [ Sentient_Forms_Local_Data_Governance::class, 'sanitize_privacy_setup_profile' ],
                'validate_callback' => [ $this->validator, 'validate_privacy_setup_profile_param' ],
                'enum'              => Sentient_Forms_Local_Data_Governance::privacy_setup_profile_choices(),
                'default'           => Sentient_Forms_Local_Data_Governance::current_privacy_setup_profile(),
            ];
            $args[ 'privacy_setup_completed_at' ] = [
                'description'       => __( 'ISO8601 timestamp recording when the first-run privacy setup was completed.', 'sentient-forms' ),
                'type'              => [ 'string', 'null' ],
                'required'          => false,
                'default'           => Sentient_Forms_Local_Data_Governance::privacy_setup_completed_at(),
            ];
        }
        return $args;
    }

    /**
     * Sanitize provider execution disable map values.
     *
     * @param mixed $value Raw request value.
     * @return array<string, bool>
     */
    public function sanitize_provider_disabled_map( mixed $value ): array
    {
        if ( ! is_array( $value ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $value as $provider_slug => $is_disabled )
        {
            $provider_key = sanitize_key( (string) $provider_slug );
            if ( '' === $provider_key )
            {
                continue;
            }

            $sanitized[ $provider_key ] = rest_sanitize_boolean( $is_disabled );
        }

        return $sanitized;
    }

    /**
     * Retrieves the schema for the settings object.
     *
     * @return array|null Item schema data.
     * @since 0.1.0
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }
        $properties = [];
        $args       = $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE );
        foreach ( $args as $key => $details )
        {
            $properties[ $key ] = [
                'description' => $details[ 'description' ],
                'type'        => $details[ 'type' ],
                'default'     => $details[ 'default' ] ?? null,
                // 'context'     => $details['context'] ?? array('view', 'edit'), // If context varies
            ];
            if ( isset( $details[ 'enum' ] ) )
            {
                $properties[ $key ][ 'enum' ] = $details[ 'enum' ];
            }
        }

        $this->schema = [
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'sentient_forms_settings', // Consistent with option key or resource
            'description' => __( 'Sentient Forms plugin settings.', 'sentient-forms' ),
            'type'        => 'object',
            'properties'  => $properties,
        ];
        return $this->schema;
    }

    /**
     * Get default settings values.
     *
     * @return array Default settings.
     * @since 0.1.0
     */
    protected function get_default_settings(): array
    {
        $defaults    = [];
        $schema_args = $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE );
        foreach ( $schema_args as $key => $details )
        {
            if ( isset( $details[ 'default' ] ) )
            {
                $defaults[ $key ] = $details[ 'default' ];
            }
        }
        return $defaults;
    }

    /**
     * Build the full response settings payload across the legacy plugin option and local data-governance options.
     *
     * @return array<string, mixed>
     */
    private function get_response_settings(): array
    {
        $settings = get_option( self::SETTINGS_OPTION_KEY, [] );
        $settings = is_array( $settings ) ? $settings : [];
        $settings = array_merge( $this->get_default_plugin_settings(), $settings );

        foreach ( $this->get_data_governance_setting_keys() as $data_governance_key )
        {
            unset( $settings[ $data_governance_key ] );
        }

        return array_merge(
            $settings,
            [
                'execution_event_retention_days' => Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days(),
                'delete_data_on_uninstall'       => Sentient_Forms_Local_Data_Governance::delete_data_on_uninstall_enabled(),
                'store_full_ai_outputs'          => Sentient_Forms_Local_Data_Governance::store_full_ai_outputs_enabled(),
                'privacy_setup_profile'          => Sentient_Forms_Local_Data_Governance::current_privacy_setup_profile(),
                'privacy_setup_completed_at'     => Sentient_Forms_Local_Data_Governance::privacy_setup_completed_at(),
            ]
        );
    }

    /**
     * Defaults stored inside the plugin settings option.
     *
     * @return array<string, mixed>
     */
    private function get_default_plugin_settings(): array
    {
        $defaults = $this->get_default_settings();
        foreach ( $this->get_data_governance_setting_keys() as $data_governance_key )
        {
            unset( $defaults[ $data_governance_key ] );
        }

        return $defaults;
    }

    /**
     * Settings owned by local data-governance options instead of the main plugin option.
     *
     * @return array<int, string>
     */
    private function get_data_governance_setting_keys(): array
    {
        return [
            'execution_event_retention_days',
            'delete_data_on_uninstall',
            'store_full_ai_outputs',
            'privacy_setup_profile',
            'privacy_setup_completed_at',
        ];
    }
}
