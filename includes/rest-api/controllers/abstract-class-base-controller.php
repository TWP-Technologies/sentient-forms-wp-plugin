<?php
/**
 * Abstract Base REST Controller for the Sentient Forms plugin.
 * Provides common properties and helper methods for concrete REST API controllers.
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
 * Abstract Class Sentient_Forms_Abstract_Base_Controller
 * Base class for all Sentient Forms REST API controllers.
 */
abstract class Sentient_Forms_Abstract_Base_Controller
{

    /**
     * The namespace for all REST API routes in this plugin.
     * Follows the pattern 'vendor/v1' or 'plugin-slug/v1'.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $namespace = 'sentient-forms/v1';

    /**
     * The base of this controller's routes.
     * Example: 'settings', 'forms', 'license'. This will be appended to the namespace.
     * So, a $rest_base of 'settings' would result in routes like '/sentient-forms/v1/settings'.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = '';

    /**
     * Stores the schema for the controller's items.
     * Used for basic caching of the schema.
     *
     * @var array|null
     * @since 0.1.0
     */
    protected ?array $schema = null;

    /**
     * Constructor.
     * Can be used for common setup tasks for all controllers, though often
     * specific setup is handled in concrete controller constructors if needed.
     *
     * @since 0.1.0
     */
    public function __construct()
    {
    }

    /**
     * Abstract method to register the routes for the controller.
     * Each concrete controller class *must* implement this method to define
     * its specific REST API endpoints.
     *
     * @return void
     * @since 0.1.0
     */
    abstract public function register_routes(): void;

    /**
     * Prepares a successful REST API response for a single item or collection.
     *
     * @param mixed $data   The data to include in the response.
     * @param int   $status Optional. The HTTP status code for the response. Default 200.
     *
     * @return WP_REST_Response The formatted REST response.
     * @since 0.1.0
     */
    protected function prepare_item_for_response( mixed $data, int $status = 200 ): WP_REST_Response
    {
        return new WP_REST_Response( $data, $status );
    }

    /**
     * Prepares an error REST API response.
     *
     * @param string $error_code       A WordPress-style error code (e.g., 'rest_invalid_param').
     * @param string $error_message    The human-readable error message.
     * @param int    $http_status_code Optional. The HTTP status code for the error. Default 400.
     * @param array  $additional_data  Optional. Additional data to include with the error.
     *
     * @return WP_Error The formatted WP_Error object.
     * @since 0.1.0
     */
    protected function prepare_error_response(
        string $error_code,
        string $error_message,
        int    $http_status_code = 400,
        array  $additional_data = [],
    ): WP_Error {
        $error_data = array_merge( [ 'status' => $http_status_code ], $additional_data );
        return new WP_Error( $error_code, $error_message, $error_data );
    }

    /**
     * A common permission check to see if the current user has the 'manage_options' capability.
     * This is a basic example; more granular permissions should be defined in
     * dedicated permission classes or methods.
     *
     * @param WP_REST_Request $request The current REST API request object.
     *
     * @return bool|WP_Error True if the user has 'manage_options' capability, WP_Error otherwise.
     * @since 0.1.0
     */
    protected function check_manage_options_permission( WP_REST_Request $request ): WP_Error | bool
    {
        if ( !current_user_can( 'manage_options' ) )
        {
            return $this->prepare_error_response(
                'rest_forbidden_context',
                __( 'Sorry, you are not allowed to perform this action. Requires "manage_options" capability.', 'sentient-forms' ),
                403, // HTTP 403 Forbidden
            );
        }

        return true;
    }

    /**
     * Retrieves the schema for a single item.
     * Controllers should override this method to define their item schema,
     * which is used for documentation and can be used for validation.
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

        $this->schema = [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            // Title should be unique for each controller.
            'title'      => $this->namespace . '/' . $this->rest_base,
            'type'       => 'object',
            'properties' => [
                // Define common properties here if any, or leave to concrete classes.
                // Example:
                // 'id' => array(
                //     'description' => __( 'Unique identifier for the object.', 'sentient-forms' ),
                //     'type'        => 'integer',
                //     'context'     => array( 'view', 'edit', 'embed' ),
                //     'readonly'    => true,
                // ),
            ],
        ];
        return $this->schema;
    }

    /**
     * Retrieves the query params for collections.
     * Useful for defining common pagination or filtering parameters.
     *
     * @return array Collection parameters.
     * @since 0.1.0
     */
    public function get_collection_params(): array
    {
        return [
            'context'  => $this->get_context_param(),
            'page'     => [
                'description'       => __( 'Current page of the collection.', 'sentient-forms' ),
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
                'minimum'           => 1,
            ],
            'per_page' => [
                'description'       => __( 'Maximum number of items to be returned in result set.', 'sentient-forms' ),
                'type'              => 'integer',
                'default'           => 10,
                'sanitize_callback' => 'absint',
                'validate_callback' => 'rest_validate_request_arg',
                'minimum'           => 1,
                'maximum'           => 100,
            ],
            'search'   => [
                'description'       => __( 'Limit results to those matching a string.', 'sentient-forms' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ],
            // Add other common collection parameters like 'orderby', 'order'
        ];
    }

    /**
     * Get the context param.
     * Helper for defining a standard 'context' argument for endpoints.
     *
     * @param array $args Optional. Additional arguments for the context parameter.
     *
     * @return array Context parameter definition.
     * @since 0.1.0
     */
    protected function get_context_param( array $args = [] ): array
    {
        $param_details = [
            'description'       => __( 'Scope under which the request is made; determines fields present in response.', 'sentient-forms' ),
            'type'              => 'string',
            'default'           => 'view',
            'enum'              => [
                'view',
                'embed',
                'edit',
            ],
            'validate_callback' => 'rest_validate_request_arg', // Standard WordPress validation for enum.
        ];

        if ( isset( $args[ 'default' ] ) )
        {
            $param_details[ 'default' ] = $args[ 'default' ];
        }

        return $param_details;
    }
}
