<?php
/**
 * REST API Forms Controller class for the Sentient Forms plugin.
 * Handles routes for listing forms and managing basic form settings.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers\Forms
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Forms_Controller
 * Provides REST endpoints for retrieving forms from a specific source
 * and updating their basic Sentient Forms configuration.
 */
class Sentient_Forms_Form_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    /**
     * The base of this controller's routes.
     * Example: /sentient-forms/v1/gravityforms/forms
     *
     * @var string
     */
    protected string $rest_base = '(?P<form_source_slug>[a-z0-9_]+)/forms';

    /**
     * Permission checker instance.
     *
     * @var Sentient_Forms_Admin_Permission
     */
    private Sentient_Forms_Admin_Permission $permission_checker;

    /**
     * Adapter registry instance for retrieving form adapters.
     *
     * @var Sentient_Forms_Form_Adapter_Registry
     */
    private Sentient_Forms_Form_Adapter_Registry $adapter_registry;

    /**
     * Constructor.
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

        if ( !class_exists( 'Sentient_Forms_Plugin' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Plugin not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $plugin                   = Sentient_Forms_Plugin::instance();
        $this->adapter_registry   = $plugin->get_form_adapter_registry();
        $this->permission_checker = class_exists( 'Sentient_Forms_Admin_Permission' ) ? new Sentient_Forms_Admin_Permission() : null;
    }

    /**
     * Registers REST API routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'endpoint_get_forms' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_collection_args(),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<form_id>\\d+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'endpoint_get_form_settings' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_item_args(),
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'endpoint_update_form_settings' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_update_args(),
                ],
            ],
        );
    }

    /**
     * Arguments for collection endpoint.
     */
    protected function get_collection_args(): array
    {
        return [
            'form_source_slug' => [
                'validate_callback' => class_exists( 'Sentient_Forms_Form_Sources' ) ? [
                    'Sentient_Forms_Form_Sources',
                    'rest_validate_form_source_slug',
                ] : '__return_true',
                'sanitize_callback' => class_exists( 'Sentient_Forms_Form_Sources' ) ? [
                    'Sentient_Forms_Form_Sources',
                    'rest_sanitize_form_source_slug',
                ] : 'sanitize_key',
                'required'          => true,
                'type'              => 'string',
                'description'       => __( 'Slug identifying the form provider.', 'sentient-forms' ),
            ],
        ];
    }

    /**
     * Retrieves arguments required for a single item, merging collection arguments with additional parameters.
     *
     * @return array An array of arguments containing validation rules, requirements, types, and descriptions for item parameters.
     */
    protected function get_item_args(): array
    {
        return array_merge(
            $this->get_collection_args(),
            [
                'form_id' => [
                    'validate_callback' => [ $this, 'validate_form_id_param' ],
                    'required'          => true,
                    'type'              => 'integer',
                    'description'       => __( 'ID of the form.', 'sentient-forms' ),
                ],
            ],
        );
    }

    /**
     * Prepares and returns the arguments required for updating a form.
     *
     * @return array An associative array of update arguments, including item arguments and additional settings.
     */
    protected function get_update_args(): array
    {
        return array_merge(
            $this->get_item_args(),
            [
                'enabled' => [
                    'description'       => __( 'Enable or disable Sentient Forms for this form.', 'sentient-forms' ),
                    'type'              => 'boolean',
                    'required'          => false,
                    'sanitize_callback' => 'wp_validate_boolean',
                ],
                // gx todo: add validation for additional settings when implemented
            ],
        );
    }

    /**
     * Checks if the current user has the necessary permissions to manage settings.
     *
     * @param WP_REST_Request $request The REST request object containing request data.
     *
     * @return bool|WP_Error Returns true if the user has permissions, false if not, or a WP_Error instance on failure.
     */
    public function permissions_check( WP_REST_Request $request ): bool | WP_Error
    {
        return $this->permission_checker->can_manage_settings( $request );
    }

    /**
     * Validates the given form ID parameter for a REST API request.
     *
     * @param mixed           $value   The value of the form ID parameter to validate.
     * @param WP_REST_Request $request The REST API request object.
     * @param string          $param   The name of the parameter being validated.
     *
     * @return bool|WP_Error Returns true if the validation is successful; otherwise, returns a WP_Error object.
     */
    public function validate_form_id_param( mixed $value, WP_REST_Request $request, string $param ): bool | WP_Error
    {
        if ( !is_numeric( $value ) || intval( $value ) <= 0 )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Form ID must be a positive integer.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        // gx todo: verify form exists for the given source

        return true;
    }

    /**
     * Retrieve a list of forms from the specified form source through an adapter.
     *
     * @param WP_REST_Request $request The REST request object containing the form source slug parameter.
     *
     * @return WP_REST_Response|WP_Error Returns a REST response containing the list of forms or a WP_Error object on failure.
     */
    public function endpoint_get_forms( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $slug  = $request->get_param( 'form_source_slug' );
        $forms = $this->adapter_get_forms( $slug );

        if ( is_wp_error( $forms ) )
        {
            return $forms;
        }

        return $this->prepare_item_for_response( $forms );
    }

    /**
     * Retrieves settings for a form via a REST API endpoint.
     *
     * @param WP_REST_Request $request The REST API request containing 'form_source_slug' and 'form_id' parameters.
     *
     * @return WP_REST_Response|WP_Error The response containing the form settings or an error object on failure.
     */
    public function endpoint_get_form_settings( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $slug     = $request->get_param( 'form_source_slug' );
        $form_id  = (int)$request->get_param( 'form_id' );
        $settings = $this->adapter_get_form_settings( $slug, $form_id );

        if ( is_wp_error( $settings ) )
        {
            return $settings;
        }

        return $this->prepare_item_for_response( $settings );
    }

    /**
     * Endpoint: update the settings of a form.
     *
     * @param WP_REST_Request $request The REST request instance containing parameters like 'form_source_slug', 'form_id',
     *                                 and optional update fields such as 'enabled'.
     *
     * @return WP_REST_Response|WP_Error WP_REST_Response on success containing the updated form settings,
     *                                   or WP_Error if an error occurs during the update process.
     */
    public function endpoint_update_form_settings( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $slug            = $request->get_param( 'form_source_slug' );
        $form_id         = (int)$request->get_param( 'form_id' );
        $settings_update = [];

        if ( $request->has_param( 'enabled' ) )
        {
            $settings_update[ 'enabled' ] = (bool)$request->get_param( 'enabled' );
        }

        // gx todo: handle additional settings fields

        $result = $this->adapter_update_form_settings( $slug, $form_id, $settings_update );

        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result );
    }

    /**
     * Retrieves an adapter instance based on the provided form source slug.
     *
     * @param string $form_source_slug The identifier for the form source to retrieve the adapter for.
     *
     * @return Sentient_Forms_Adapter_Interface|null The adapter instance if found, or null if no matching adapter exists.
     */
    private function get_adapter( string $form_source_slug ): ?Sentient_Forms_Adapter_Interface
    {
        return $this->adapter_registry->get_adapter_by_id( $form_source_slug );
    }

    /**
     * Helper: retrieve a list of forms from an adapter.
     *
     * @param string $form_source_slug The slug identifying the form source adapter.
     *
     * @return WP_Error|array An array of forms if successful, or a WP_Error object on failure.
     */
    private function adapter_get_forms( string $form_source_slug ): WP_Error | array
    {
        $adapter = $this->get_adapter( $form_source_slug );

        if ( !$adapter )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source.', 'sentient-forms' ), 404 );
        }

        if ( !method_exists( $adapter, 'get_forms' ) )
        {
            return $this->prepare_error_response( 'rest_not_implemented', __( 'Adapter does not support listing forms.', 'sentient-forms' ), 501 );
        }

        return $adapter->get_forms();
    }

    /**
     * Retrieves the form settings for a specified form source and form ID.
     *
     * @param string $form_source_slug The slug of the form source to locate the corresponding adapter.
     * @param int    $form_id          The ID of the form whose settings need to be retrieved.
     *
     * @return WP_Error|array Returns an array of form settings if the adapter and method exist.
     *                        Returns a WP_Error if the adapter is invalid or the method is not implemented.
     */
    private function adapter_get_form_settings( string $form_source_slug, int $form_id ): WP_Error | array
    {
        $adapter = $this->get_adapter( $form_source_slug );

        if ( !$adapter )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source.', 'sentient-forms' ), 404 );
        }

        if ( !method_exists( $adapter, 'get_form_settings' ) )
        {
            return $this->prepare_error_response( 'rest_not_implemented', __( 'Adapter does not provide form settings.', 'sentient-forms' ), 501 );
        }

        return $adapter->get_form_settings( $form_id );
    }

    /**
     * Updates the form settings for a specified form source and form ID.
     *
     * @param string $form_source_slug The slug of the form source to locate the corresponding adapter.
     * @param int    $form_id          The ID of the form whose settings need to be updated.
     * @param array  $settings         An associative array of settings to merge with the existing form settings.
     *
     * @return WP_Error|array Returns an array containing the success status and updated settings if the update is successful.
     *                        Returns a WP_Error if the adapter is invalid or the required methods are not implemented.
     */
    private function adapter_update_form_settings( string $form_source_slug, int $form_id, array $settings ): WP_Error | array
    {
        $adapter = $this->get_adapter( $form_source_slug );

        if ( !$adapter )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source.', 'sentient-forms' ), 404 );
        }

        if ( !method_exists( $adapter, 'update_form_settings' ) || !method_exists( $adapter, 'get_form_settings' ) )
        {
            return $this->prepare_error_response(
                'rest_not_implemented',
                __( 'Adapter does not support updating form settings.', 'sentient-forms' ),
                501,
            );
        }

        $current_settings = $adapter->get_form_settings( $form_id );
        $new_settings     = array_merge( $current_settings, $settings );
        $success          = $adapter->update_form_settings( $form_id, $new_settings );

        return [ 'success' => $success, 'settings' => $new_settings ];
    }
}
