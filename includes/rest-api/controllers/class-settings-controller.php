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
class Sentient_Forms_Settings_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

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
        $settings = get_option( self::SETTINGS_OPTION_KEY, $this->get_default_settings() );
        return $this->prepare_item_for_response( $settings );
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
        $params           = $request->get_params();
        $current_settings = get_option( self::SETTINGS_OPTION_KEY, $this->get_default_settings() );
        $updated_settings = [];

        $endpoint_args = $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE );

        foreach ( $endpoint_args as $key => $details )
        {
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
        // Ensure all keys from default settings are present if they weren't updated or in request
        $updated_settings = array_merge( $this->get_default_settings(), $current_settings, $updated_settings );

        update_option( self::SETTINGS_OPTION_KEY, $updated_settings );

        $response_data = [
            'success'  => true,
            'message'  => __( 'Settings updated successfully.', 'sentient-forms' ),
            'settings' => $updated_settings,
        ];

        return $this->prepare_item_for_response( $response_data );
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
                'default'           => '',
            ];
            $args[ 'selected_llm' ]     = [
                'description'       => __( 'The preferred Large Language Model to use.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => [ $this->validator, 'validate_selected_llm_param' ],
                'default'           => 'gemini-1.5-flash-latest', // Example default
            ];
            $args[ 'enable_feature_x' ] = [
                'description'       => __( 'Enable or disable Feature X.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'wp_validate_boolean',
                'validate_callback' => [ $this->validator, 'validate_boolean_param' ],
                'default'           => true,
            ];
        }
        return $args;
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
}
