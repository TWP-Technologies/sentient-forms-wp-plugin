<?php
/**
 * REST API Form Actions Controller class for the Sentient Forms plugin.
 * Handles routes related to managing action linkages for a specific form source and form ID.
 *
 * @package    SentientForms
 * @subpackage REST_API\\Controllers\\Forms
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Form_Actions_Controller
 * Manages REST API endpoints for local action linkages of a form.
 */
class Sentient_Forms_Form_Actions_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;
    /**
     * Route base including form source and form ID placeholders.
     *
     * @var string
     */
    protected string $rest_base = '(?P<form_source_slug>[a-z0-9_]+)/forms/(?P<form_id>\\d+)/actions';

    /** @var Sentient_Forms_Admin_Permission */
    private Sentient_Forms_Admin_Permission $permission_checker;

    const FORM_ACTIONS_OPTION_BASE = 'sentient_forms_actions_';

    /** Allowed values for action_type_indicator. */
    private const ACTION_TYPE_INDICATORS = [ 'master', 'custom' ];

    /** Allowed Gravity Forms hooks that can trigger Sentient Forms actions. */
    private const ALLOWED_TRIGGER_HOOKS = [
        'gform_validation',
        'gform_after_submission',
    ];

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

        if ( !class_exists( 'Sentient_Forms_Form_Sources' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Form_Sources not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->permission_checker = new Sentient_Forms_Admin_Permission();
    }

    /**
     * Build option key for a specific form.
     */
    private function get_actions_option_key( string $form_source_slug, int $form_id ): string
    {
        return self::FORM_ACTIONS_OPTION_BASE . sanitize_key( $form_source_slug ) . '_' . absint( $form_id );
    }

    /**
     * Register REST routes.
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_actions' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'add_form_action' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/status',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_execution_status' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<local_mapping_id>[a-zA-Z0-9_]+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_item_args(),
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'delete_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_item_args(),
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/entries/(?P<entry_id>\\d+)/status',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_entry_execution_status' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_entry_status_args(),
                ],
            ],
        );
    }

    /** Collection args */
    protected function get_collection_args(): array
    {
        return [
            'form_source_slug' => [
                'validate_callback' => [ 'Sentient_Forms_Form_Sources', 'rest_validate_form_source_slug' ],
                'sanitize_callback' => [ 'Sentient_Forms_Form_Sources', 'rest_sanitize_form_source_slug' ],
                'required'          => true,
                'type'              => 'string',
                'description'       => __( 'The slug identifying the form plugin source.', 'sentient-forms' ),
            ],
            'form_id'          => [
                'validate_callback' => [ $this, 'validate_form_id_param' ],
                'required'          => true,
                'type'              => 'integer',
                'description'       => __( 'The ID of the form.', 'sentient-forms' ),
            ],
        ];
    }

    /** Item args */
    protected function get_item_args(): array
    {
        return array_merge(
            $this->get_collection_args(),
            [
                'local_mapping_id' => [
                    'validate_callback' => [ $this, 'validate_local_mapping_id_param' ],
                    'required'          => true,
                    'type'              => 'string',
                    'description'       => __( 'Local mapping ID for the action linkage.', 'sentient-forms' ),
                ],
            ],
        );
    }

    /**
     * Arguments for entry status endpoint.
     */
    protected function get_entry_status_args(): array
    {
        return array_merge(
            $this->get_collection_args(),
            [
                'entry_id' => [
                    'validate_callback' => [ $this, 'validate_entry_id_param' ],
                    'required'          => true,
                    'type'              => 'integer',
                    'description'       => __( 'Gravity Forms entry ID to inspect.', 'sentient-forms' ),
                ],
            ],
        );
    }

    /** Permission check for form source and ID. */
    public function permissions_check_for_form_source_and_id( WP_REST_Request $request ): WP_Error | bool
    {
        $source = $request->get_param( 'form_source_slug' );
        if ( !Sentient_Forms_Form_Sources::is_supported_source( $source ) )
        {
            return $this->prepare_error_response( 'rest_invalid_form_source', __( 'Invalid form source provided.', 'sentient-forms' ), 400 );
        }

        $form_id = (int)$request->get_param( 'form_id' );
        if ( $form_id <= 0 )
        {
            return $this->prepare_error_response( 'rest_invalid_form_id', __( 'Invalid form ID provided.', 'sentient-forms' ), 400 );
        }

        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $source ) : null;
        if ( $registry && $adapter )
        {
            if ( method_exists( $adapter, 'form_exists' ) && !$adapter->form_exists( $form_id ) )
            {
                return $this->prepare_error_response(
                    'rest_form_not_found',
                    __( 'Form not found for the given source and ID.', 'sentient-forms' ),
                    404,
                );
            }
            elseif ( method_exists( $adapter, 'get_form_object' ) && null === $adapter->get_form_object( $form_id ) )
            {
                return $this->prepare_error_response(
                    'rest_form_not_found',
                    __( 'Form not found for the given source and ID.', 'sentient-forms' ),
                    404,
                );
            }
        }

        return $this->permission_callback_with_nonce( $request );
    }

    /** Validate form_id param. */
    public function validate_form_id_param( int $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( $value <= 0 )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Form ID must be a positive integer.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        if ( class_exists( 'Sentient_Forms_Plugin' ) )
        {
            $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
            if ( $registry )
            {
                $adapter = $registry->get_adapter_by_id( $request->get_param( 'form_source_slug' ) );
                if ( $adapter )
                {
                    if ( method_exists( $adapter, 'form_exists' ) && !$adapter->form_exists( $value ) )
                    {
                        return new WP_Error(
                            'rest_form_not_found', __( 'Form not found for the given source and ID.', 'sentient-forms' ), [ 'status' => 404 ],
                        );
                    }
                    elseif ( method_exists( $adapter, 'get_form_object' ) && null === $adapter->get_form_object( $value ) )
                    {
                        return new WP_Error(
                            'rest_form_not_found', __( 'Form not found for the given source and ID.', 'sentient-forms' ), [ 'status' => 404 ],
                        );
                    }
                }
            }
        }

        return true;
    }

    /** Validate local_mapping_id path parameter. */
    public function validate_local_mapping_id_param( $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( empty( $value ) || !is_string( $value ) || !preg_match( '/^[a-zA-Z0-9_]+$/', $value ) )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Invalid mapping ID.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        $option_key = $this->get_actions_option_key( $request->get_param( 'form_source_slug' ), (int)$request->get_param( 'form_id' ) );
        $actions    = get_option( $option_key, [] );
        if ( !is_array( $actions ) )
        {
            $actions = [];
        }

        if ( in_array( $request->get_method(), [ 'GET', 'POST', 'PUT', 'PATCH,', 'DELETE' ], true ) && !isset( $actions[ $value ] ) )
        {
            return new WP_Error( 'rest_action_not_found', __( 'Action linkage not found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        return true;
    }

    /** Validate entry_id path parameter. */
    public function validate_entry_id_param( int $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( $value <= 0 )
        {
            return new WP_Error( 'rest_invalid_entry', __( 'Entry ID must be a positive integer.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        if ( ! class_exists( 'GFAPI' ) )
        {
            return new WP_Error( 'rest_gf_missing', __( 'Gravity Forms is required for this endpoint.', 'sentient-forms' ), [ 'status' => 500 ] );
        }

        $entry = GFAPI::get_entry( $value );
        if ( is_wp_error( $entry ) )
        {
            return new WP_Error( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), [ 'status' => 404 ] );
        }

        $form_id = (int) $request->get_param( 'form_id' );
        if ( $form_id > 0 && isset( $entry['form_id'] ) && (int) $entry['form_id'] !== $form_id )
        {
            return new WP_Error( 'rest_entry_form_mismatch', __( 'Entry does not belong to the requested form.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        return true;
    }

    /**
     * Retrieve all action linkages for a form.
     */
    public function get_form_actions( WP_REST_Request $request ): WP_REST_Response
    {
        $option_key = $this->get_actions_option_key( $request->get_param( 'form_source_slug' ), (int)$request->get_param( 'form_id' ) );
        $actions    = get_option( $option_key, [] );
        if ( !is_array( $actions ) )
        {
            $actions = [];
        }

        return $this->prepare_item_for_response( array_values( $actions ) );
    }

    /**
     * Add a new action linkage to a form.
     */
    public function add_form_action( WP_REST_Request $request ): WP_REST_Response
    {
        $option_key = $this->get_actions_option_key( $request->get_param( 'form_source_slug' ), (int)$request->get_param( 'form_id' ) );
        $actions    = get_option( $option_key, [] );
        if ( !is_array( $actions ) )
        {
            $actions = [];
        }

        $new_id = uniqid( 'map_', false );
        while ( isset( $actions[ $new_id ] ) )
        {
            $new_id = uniqid( 'map_', false );
        }

        $action = [
            'local_mapping_id'           => $new_id,
            'central_action_id'          => $request->get_param( 'central_action_id' ),
            'action_type_indicator'      => $request->get_param( 'action_type_indicator' ),
            'trigger_hooks'              => $this->sanitize_trigger_hooks( (array) $request->get_param( 'trigger_hooks' ) ),
            'is_action_enabled_for_form' => $request->get_param( 'is_action_enabled_for_form' ) ?? true,
            'execution_priority'         => $request->get_param( 'execution_priority' ) ?? 10,
        ];

        if ( $request->has_param( 'action_name_label' ) )
        {
            $action[ 'action_name_label' ] = $request->get_param( 'action_name_label' );
        }

        if ( $request->has_param( 'settings' ) )
        {
            $action[ 'settings' ] = $this->sanitize_settings( $request->get_param( 'settings' ) );
        }

        $actions[ $new_id ] = $action;
        update_option( $option_key, $actions, false );

        return $this->prepare_item_for_response( $action, 201 );
    }

    /**
     * Retrieve a specific action linkage.
     */
    public function get_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $option_key = $this->get_actions_option_key( $request->get_param( 'form_source_slug' ), (int)$request->get_param( 'form_id' ) );
        $actions    = get_option( $option_key, [] );
        $id         = $request->get_param( 'local_mapping_id' );
        if ( isset( $actions[ $id ] ) )
        {
            return $this->prepare_item_for_response( $actions[ $id ] );
        }

        return $this->prepare_error_response( 'rest_action_not_found', __( 'Action linkage not found.', 'sentient-forms' ), 404 );
    }

    /**
     * Update an existing action linkage.
     */
    public function update_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $option_key = $this->get_actions_option_key( $request->get_param( 'form_source_slug' ), (int)$request->get_param( 'form_id' ) );
        $actions    = get_option( $option_key, [] );
        $id         = $request->get_param( 'local_mapping_id' );
        if ( !isset( $actions[ $id ] ) )
        {
            return $this->prepare_error_response( 'rest_action_not_found', __( 'Action linkage not found to update.', 'sentient-forms' ), 404 );
        }

        $linkage = $actions[ $id ];

        if ( $request->has_param( 'central_action_id' ) )
        {
            $linkage[ 'central_action_id' ] = $request->get_param( 'central_action_id' );
        }
        if ( $request->has_param( 'action_type_indicator' ) )
        {
            $linkage[ 'action_type_indicator' ] = $request->get_param( 'action_type_indicator' );
        }
        if ( $request->has_param( 'trigger_hooks' ) )
        {
            $linkage[ 'trigger_hooks' ] = $this->sanitize_trigger_hooks( (array) $request->get_param( 'trigger_hooks' ) );
        }
        if ( $request->has_param( 'is_action_enabled_for_form' ) )
        {
            $linkage[ 'is_action_enabled_for_form' ] = (bool)$request->get_param( 'is_action_enabled_for_form' );
        }
        if ( $request->has_param( 'execution_priority' ) )
        {
            $linkage[ 'execution_priority' ] = (int)$request->get_param( 'execution_priority' );
        }
        if ( $request->has_param( 'action_name_label' ) )
        {
            $linkage[ 'action_name_label' ] = $request->get_param( 'action_name_label' );
        }
        if ( $request->has_param( 'settings' ) )
        {
            $linkage[ 'settings' ] = $this->sanitize_settings( $request->get_param( 'settings' ) );
        }

        $actions[ $id ] = $linkage;
        update_option( $option_key, $actions, false );

        return $this->prepare_item_for_response( $linkage );
    }

    /** Delete an action linkage. */
    public function delete_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $option_key = $this->get_actions_option_key( $request->get_param( 'form_source_slug' ), (int)$request->get_param( 'form_id' ) );
        $actions    = get_option( $option_key, [] );
        $id         = $request->get_param( 'local_mapping_id' );
        if ( !isset( $actions[ $id ] ) )
        {
            return $this->prepare_error_response( 'rest_action_not_found', __( 'Action linkage not found to delete.', 'sentient-forms' ), 404 );
        }

        $deleted = $actions[ $id ];
        unset( $actions[ $id ] );
        update_option( $option_key, $actions, false );

        return $this->prepare_item_for_response( [ 'deleted' => true, 'previous' => $deleted ] );
    }


    /** Retrieve aggregated execution status for a form. */
    public function get_form_execution_status( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = Sentient_Forms_Form_Sources::rest_sanitize_form_source_slug(
            $request->get_param( 'form_source_slug' ),
            $request,
            'form_source_slug'
        );

        $form_id    = (int) $request->get_param( 'form_id' );
        $option_key = $this->get_form_status_option_key( $form_source_slug, $form_id );
        $status     = get_option( $option_key, null );

        if ( ! is_array( $status ) )
        {
            $status = [
                'status'          => 'unknown',
                'message'         => null,
                'entry_id'        => null,
                'last_error_code' => null,
                'last_result'     => null,
                'updated_at'      => null,
            ];
        }

        return $this->prepare_item_for_response( $status );
    }

    /**
     * Retrieve the latest execution status for a given entry.
     */
    public function get_entry_execution_status( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        if ( ! class_exists( 'GFAPI' ) )
        {
            return $this->prepare_error_response( 'rest_gf_missing', __( 'Gravity Forms is required for this endpoint.', 'sentient-forms' ), 500 );
        }

        $entry_id = (int) $request->get_param( 'entry_id' );
        $entry    = GFAPI::get_entry( $entry_id );

        if ( is_wp_error( $entry ) )
        {
            return $this->prepare_error_response( 'rest_entry_not_found', __( 'Entry not found.', 'sentient-forms' ), 404 );
        }

        $last_response = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, 'sentient_forms_last_response' ) : null;
        $last_error    = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, 'sentient_forms_last_error' ) : null;
        $processed_at  = function_exists( 'gform_get_meta' ) ? gform_get_meta( $entry_id, 'sentient_forms_last_processed_at' ) : null;

        $payload = [
            'entry_id'       => $entry_id,
            'form_id'        => (int) ( $entry['form_id'] ?? 0 ),
            'last_response'  => $this->maybe_decode_json_meta( $last_response ),
            'last_error'     => is_string( $last_error ) && $last_error !== '' ? $last_error : null,
            'processed_at'   => is_string( $processed_at ) && $processed_at !== '' ? $processed_at : null,
            'status'         => is_string( $last_error ) && $last_error !== '' ? 'error' : ( $last_response ? 'success' : 'unknown' ),
        ];

        return $this->prepare_item_for_response( $payload );
    }

    /**
     * Endpoint args for item schema.
     */

    private function get_form_status_option_key( string $form_source_slug, int $form_id ): string
    {
        return sprintf(
            'sentient_forms_form_status_%s_%d',
            sanitize_key( $form_source_slug ),
            $form_id
        );
    }

    public function get_endpoint_args_for_item_schema( $method = null ): array
    {
        $args = $this->get_collection_args();

        if ( WP_REST_Server::CREATABLE === $method || WP_REST_Server::EDITABLE === $method )
        {
            $args[ 'central_action_id' ]          = [
                'description'       => __( 'ID of the action on the central server.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => WP_REST_Server::CREATABLE === $method,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => 'rest_validate_request_arg',
            ];
            $args[ 'action_type_indicator' ]      = [
                'description'       => __( 'Indicator of action type.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => WP_REST_Server::CREATABLE === $method,
                'sanitize_callback' => 'sanitize_key',
                'enum'              => self::ACTION_TYPE_INDICATORS,
            ];
            $args[ 'trigger_hooks' ]              = [
                'description'       => __( 'Hooks that trigger this action.', 'sentient-forms' ),
                'type'              => 'array',
                'required'          => WP_REST_Server::CREATABLE === $method,
                'items'             => [
                    'type' => 'string',
                    'enum' => self::ALLOWED_TRIGGER_HOOKS,
                ],
                'validate_callback' => [ $this, 'validate_trigger_hooks_param' ],
            ];
            $args[ 'is_action_enabled_for_form' ] = [
                'description'       => __( 'Whether the action is enabled for the form.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'default'           => true,
            ];
            $args[ 'execution_priority' ]         = [
                'description'       => __( 'Execution priority for the action.', 'sentient-forms' ),
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
                'default'           => 10,
            ];
            $args[ 'action_name_label' ]          = [
                'description'       => __( 'Optional display label for the action.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ];
            $args[ 'settings' ]                   = [
                'description'       => __( 'Action-specific configuration settings.', 'sentient-forms' ),
                'type'              => 'object',
                'required'          => false,
            ];
        }

        if ( WP_REST_Server::EDITABLE === $method )
        {
            $args[ 'local_mapping_id' ] = [
                'validate_callback' => [ $this, 'validate_local_mapping_id_param' ],
                'required'          => true,
                'type'              => 'string',
            ];
        }

        return $args;
    }

    /**
     * Schema for an action linkage item.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'sentient_form_action_linkage',
            'description' => __( 'A linkage between a form and a central action.', 'sentient-forms' ),
            'type'        => 'object',
            'properties'  => [
                'local_mapping_id'           => [
                    'description' => __( 'Unique ID for this linkage.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                    'readonly'    => true,
                ],
                'central_action_id'          => [
                    'description' => __( 'Action identifier on the central server.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'action_type_indicator'      => [
                    'description' => __( 'Indicator for the type of central action.', 'sentient-forms' ),
                    'type'        => 'string',
                    'enum'        => self::ACTION_TYPE_INDICATORS,
                    'context'     => [ 'view', 'edit' ],
                ],
                'trigger_hooks'              => [
                    'description' => __( 'Hooks that trigger the action.', 'sentient-forms' ),
                    'type'        => 'array',
                    'default'     => [],
                    'items'       => [
                        'type' => 'string',
                        'enum' => self::ALLOWED_TRIGGER_HOOKS,
                    ],
                    'context'     => [ 'view', 'edit' ],
                ],
                'is_action_enabled_for_form' => [
                    'description' => __( 'Whether this action is enabled for the form.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit' ],
                    'default'     => true,
                ],
                'execution_priority'         => [
                    'description' => __( 'Priority in which the action executes.', 'sentient-forms' ),
                    'type'        => 'integer',
                    'context'     => [ 'view', 'edit' ],
                    'default'     => 10,
                ],
                'action_name_label'          => [
                    'description' => __( 'Human readable name of the action.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'settings'                   => [
                    'description' => __( 'Action-specific configuration settings.', 'sentient-forms' ),
                    'type'        => 'object',
                    'context'     => [ 'view', 'edit' ],
                    'default'     => [],
                ],
            ],
        ];

        return $this->schema;
    }

    /** Validate trigger_hooks parameter. */
    public function validate_trigger_hooks_param( $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( !is_array( $value ) )
        {
            return new WP_Error( 'rest_invalid_param', __( 'Trigger hooks must be an array.', 'sentient-forms' ), [ 'status' => 400 ] );
        }

        foreach ( $value as $hook )
        {
            if ( !is_string( $hook ) )
            {
                return new WP_Error( 'rest_invalid_param', __( 'Each trigger hook must be a string.', 'sentient-forms' ), [ 'status' => 400 ] );
            }

            $hook_key = sanitize_key( $hook );
            if ( ! in_array( $hook_key, self::ALLOWED_TRIGGER_HOOKS, true ) )
            {
                return new WP_Error(
                    'rest_invalid_hook',
                    sprintf(
                        /* translators: %s: invalid hook name */
                        __( 'Hook %s is not supported. Allowed hooks: gform_validation, gform_after_submission.', 'sentient-forms' ),
                        esc_html( $hook )
                    ),
                    [ 'status' => 400 ],
                );
            }
        }

        return true;
    }

    /**
     * Normalize trigger hooks to allowed sanitized values.
     */
    private function sanitize_trigger_hooks( array $hooks ): array
    {
        if ( empty( $hooks ) )
        {
            return [];
        }

        $normalized = array_map( 'sanitize_key', $hooks );
        $normalized = array_filter(
            $normalized,
            static fn( $hook ) => in_array( $hook, self::ALLOWED_TRIGGER_HOOKS, true )
        );

        return array_values( array_unique( $normalized ) );
    }

    /**
     * Safely decode JSON-encoded Gravity Forms meta values.
     */
    private function maybe_decode_json_meta( mixed $value ): mixed
    {
        if ( ! is_string( $value ) || '' === trim( $value ) )
        {
            return null;
        }

        $decoded = json_decode( $value, true );
        if ( JSON_ERROR_NONE === json_last_error() )
        {
            return $decoded;
        }

        return $value;
    }

    /**
     * Sanitize settings array recursively.
     */
    private function sanitize_settings( $settings ): array
    {
        if ( ! is_array( $settings ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $settings as $key => $value )
        {
            $key = sanitize_key( $key );
            if ( is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_settings( $value );
            }
            elseif ( is_bool( $value ) || is_numeric( $value ) )
            {
                // Allow booleans and numbers (int/float) to pass through
                $sanitized[ $key ] = $value;
            }
            else
            {
                $sanitized[ $key ] = sanitize_text_field( (string)$value );
            }
        }

        return $sanitized;
    }
}
