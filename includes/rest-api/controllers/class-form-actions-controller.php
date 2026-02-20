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

    /** @var Sentient_Forms_Mappings_Sync|null Phase 7 CSM: CPS sync service */
    private ?Sentient_Forms_Mappings_Sync $mappings_sync = null;

    const FORM_ACTIONS_OPTION_BASE = 'sentient_forms_actions_';

    /** Allowed values for action_type_indicator. */
    private const ACTION_TYPE_INDICATORS = [ 'master', 'custom' ];

    /** Allowed Gravity Forms hooks that can trigger Sentient Forms actions. */
    private const ALLOWED_TRIGGER_HOOKS = [
        'gform_validation',
        'gform_after_submission',
    ];

    /** Policy version exposed to admin workflow planner clients. */
    private const WORKFLOW_POLICY_VERSION = '2026-02-mixed-sync-async-v1';

    /** Maximum allowed nested depth for mapping condition groups. */
    private const MAX_CONDITION_DEPTH = 3;

    /** Maximum allowed node count for a mapping condition tree. */
    private const MAX_CONDITION_NODES = 50;

    /** Supported operators for conditional run rules (CB-FORMS-006). */
    private const CONDITION_OPERATORS = [
        'eq',
        'neq',
        'contains',
        'not_contains',
        'starts_with',
        'ends_with',
        'in',
        'not_in',
        'is_empty',
        'is_not_empty',
        'gt',
        'gte',
        'lt',
        'lte',
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

        // Phase 7 CSM: Initialize sync service if available
        if ( class_exists( 'Sentient_Forms_Mappings_Sync' ) ) {
            $this->mappings_sync = new Sentient_Forms_Mappings_Sync();
        }
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

        // CB-FORMS-001: Per-form master disable toggle
        // IMPORTANT: Must be registered BEFORE /{local_mapping_id} to avoid route conflict
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/disable',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_disabled' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'toggle_form_disabled' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => array_merge(
                        $this->get_collection_args(),
                        [
                            'sf_disabled' => [
                                'description'       => __( 'Whether Sentient Forms is disabled for this form.', 'sentient-forms' ),
                                'type'              => 'boolean',
                                'required'          => true,
                                'sanitize_callback' => 'rest_sanitize_boolean',
                            ],
                        ],
                    ),
                ],
            ],
        );

        // CA-MAP-001: Form field discovery endpoint for FieldSelector component
        // IMPORTANT: Must be registered BEFORE /{local_mapping_id} to avoid route conflict
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/fields',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_form_fields' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => $this->get_collection_args(),
                ],
            ],
        );

        // Workflow planning endpoint (graph execution preview + policy diagnostics).
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/workflow-plan',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_workflow_plan' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => array_merge(
                        $this->get_collection_args(),
                        [
                            'hook_scope' => [
                                'description'       => __( 'Hook scope for plan output (all or specific hook).', 'sentient-forms' ),
                                'type'              => 'string',
                                'required'          => false,
                                'default'           => 'all',
                                'sanitize_callback' => 'sanitize_text_field',
                            ],
                        ],
                    ),
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<local_mapping_id>[a-zA-Z0-9_]+)/duplicate',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'duplicate_form_action_item' ],
                    'permission_callback' => [ $this, 'permissions_check_for_form_source_and_id' ],
                    'args'                => array_merge(
                        $this->get_item_args(),
                        [
                            'parent' => [
                                'description' => __( 'Parent insertion selection for duplicated mapping.', 'sentient-forms' ),
                                'type'        => 'object',
                                'required'    => true,
                            ],
                        ],
                    ),
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
     *
     * Phase 7 CSM: Merges local WP linkages with CPS mappings.
     * CPS mappings are transformed to match local linkage format.
     */
    public function get_form_actions( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id = (int) $request->get_param( 'form_id' );

        // Get local WP linkages
        $option_key = $this->get_actions_option_key( $form_source_slug, $form_id );
        $local_actions = get_option( $option_key, [] );
        if ( ! is_array( $local_actions ) ) {
            $local_actions = [];
        }

        // CB-FORMS-001: Filter out the sf_disabled flag — it's metadata, not an action linkage.
        unset( $local_actions['sf_disabled'] );

        // Phase 7 CSM: Optionally merge CPS mappings
        $cps_actions = $this->fetch_cps_mappings_for_form( $form_source_slug, $form_id );
        $merged = $this->merge_local_and_cps_actions( array_values( $local_actions ), $cps_actions );

        return $this->prepare_item_for_response( $merged );
    }

    /**
     * Return workflow planning data for dependency graph execution previews.
     *
     * Attempts CPS authority first; falls back to a local deterministic planner when CPS is
     * unavailable so the UI can remain readable.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function get_workflow_plan( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = (int) $request->get_param( 'form_id' );
        $hook_scope       = sanitize_text_field( (string) ( $request->get_param( 'hook_scope' ) ?? 'all' ) );

        if ( 'all' !== $hook_scope && ! in_array( $hook_scope, self::ALLOWED_TRIGGER_HOOKS, true ) )
        {
            $hook_scope = 'all';
        }

        $cps_error = null;
        $authority_reason = 'cps_unavailable';
        if ( $this->mappings_sync )
        {
            $cps_plan = $this->mappings_sync->plan_workflow( $form_source_slug, $form_id, $hook_scope );
            if ( ! is_wp_error( $cps_plan ) && is_array( $cps_plan ) )
            {
                return $this->prepare_item_for_response( $this->normalize_workflow_plan_payload( $cps_plan, $hook_scope ) );
            }

            if ( is_wp_error( $cps_plan ) )
            {
                $cps_error = $cps_plan->get_error_code();
                if ( is_string( $cps_error ) && '' !== $cps_error )
                {
                    $lower_error = strtolower( $cps_error );
                    if ( false !== strpos( $lower_error, 'mismatch' ) )
                    {
                        $authority_reason = 'cps_mismatch';
                    }
                }
            }
        }

        $option_key    = $this->get_actions_option_key( $form_source_slug, $form_id );
        $local_actions = get_option( $option_key, [] );
        if ( ! is_array( $local_actions ) )
        {
            $local_actions = [];
        }
        unset( $local_actions['sf_disabled'] );

        $cps_actions = $this->fetch_cps_mappings_for_form( $form_source_slug, $form_id );
        $merged      = $this->merge_local_and_cps_actions( array_values( $local_actions ), $cps_actions );
        $fallback = $this->build_local_workflow_plan_payload( $merged, $hook_scope, $authority_reason );
        $fallback['cps_unreachable'] = 'cps_mismatch' !== $authority_reason;
        if ( is_string( $cps_error ) && '' !== $cps_error )
        {
            $fallback['cps_error_code'] = $cps_error;
        }

        return $this->prepare_item_for_response( $fallback );
    }

    /**
     * CB-FORMS-001: Get the per-form disabled state.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function get_form_disabled( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = (int) $request->get_param( 'form_id' );

        $option_key = $this->get_actions_option_key( $form_source_slug, $form_id );
        $options    = get_option( $option_key, [] );

        $sf_disabled = is_array( $options ) && ! empty( $options['sf_disabled'] );
        $execution_disable = $this->get_execution_disable_flags( $form_source_slug );
        $effective_disabled = $sf_disabled || $execution_disable['global_disabled'] || $execution_disable['provider_disabled'];

        return $this->prepare_item_for_response( [
            'sf_disabled'       => $sf_disabled,
            'global_disabled'   => $execution_disable['global_disabled'],
            'provider_disabled' => $execution_disable['provider_disabled'],
            'effective_disabled'=> $effective_disabled,
        ] );
    }

    /**
     * CB-FORMS-001: Toggle per-form master disable flag.
     *
     * Sets the sf_disabled flag in the form's option array.
     * When enabled, the GF adapter skips all action processing for this form.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response
     */
    public function toggle_form_disabled( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = (int) $request->get_param( 'form_id' );
        $sf_disabled      = (bool) $request->get_param( 'sf_disabled' );

        $option_key = $this->get_actions_option_key( $form_source_slug, $form_id );
        $options    = get_option( $option_key, [] );

        if ( ! is_array( $options ) )
        {
            $options = [];
        }

        $options['sf_disabled'] = $sf_disabled;
        update_option( $option_key, $options, false );

        $execution_disable = $this->get_execution_disable_flags( $form_source_slug );
        $effective_disabled = $sf_disabled || $execution_disable['global_disabled'] || $execution_disable['provider_disabled'];

        return $this->prepare_item_for_response( [
            'sf_disabled'       => $sf_disabled,
            'global_disabled'   => $execution_disable['global_disabled'],
            'provider_disabled' => $execution_disable['provider_disabled'],
            'effective_disabled'=> $effective_disabled,
            'message'           => $sf_disabled
                ? __( 'Sentient Forms disabled for this form.', 'sentient-forms' )
                : __( 'Sentient Forms enabled for this form.', 'sentient-forms' ),
        ] );
    }

    /**
     * Read execution disable settings from plugin-level settings.
     *
     * @param string $form_source_slug Form provider slug.
     * @return array{global_disabled: bool, provider_disabled: bool}
     */
    private function get_execution_disable_flags( string $form_source_slug ): array
    {
        $settings = get_option( 'sentient_forms_plugin_settings', [] );
        if ( ! is_array( $settings ) )
        {
            $settings = [];
        }

        $provider_map = $settings['execution_provider_disabled'] ?? [];
        if ( ! is_array( $provider_map ) )
        {
            $provider_map = [];
        }

        $provider_key = sanitize_key( $form_source_slug );

        return [
            'global_disabled'   => ! empty( $settings['execution_global_disabled'] ),
            'provider_disabled' => ! empty( $provider_map[ $provider_key ] ),
        ];
    }

    /**
     * Phase 7 CSM: Fetch CPS mappings for a specific form.
     *
     * @param string $form_source_slug Form source (e.g., 'gravity_forms').
     * @param int    $form_id          Form ID.
     * @return array Transformed CPS mappings as local linkage format.
     */
    private function fetch_cps_mappings_for_form( string $form_source_slug, int $form_id ): array
    {
        if ( ! $this->mappings_sync ) {
            return [];
        }

        $site_id = get_option( 'sentient_forms_site_id', '' );
        if ( empty( $site_id ) ) {
            return [];
        }

        try {
            $all_mappings = $this->mappings_sync->fetch_mappings();
            $form_mappings = array_filter( $all_mappings, function( $m ) use ( $site_id, $form_source_slug, $form_id ) {
                return
                    ( $m['site_id'] ?? '' ) === $site_id &&
                    ( $m['form_source'] ?? '' ) === $form_source_slug &&
                    ( (int) ( $m['form_id'] ?? 0 ) ) === $form_id &&
                    empty( $m['is_template'] ); // Exclude templates
            } );

            return array_map( [ $this, 'transform_cps_mapping_to_linkage' ], array_values( $form_mappings ) );
        } catch ( \Throwable $e ) {
            // Silently fail - CPS unreachable, use local only
            return [];
        }
    }

    /**
     * Phase 7 CSM: Transform a CPS mapping to local linkage format.
     *
     * @param array $mapping CPS mapping.
     * @return array Local linkage format.
     */
    private function transform_cps_mapping_to_linkage( array $mapping ): array
    {
        $settings = $mapping['settings'] ?? [];

        return [
            'local_mapping_id'           => 'cps_' . ( $mapping['id'] ?? uniqid() ),
            'cps_mapping_id'             => $mapping['id'] ?? null, // Track CPS origin
            'central_action_id'          => $mapping['action_template_id'] ?? $mapping['custom_action_id'] ?? '',
            'action_type_indicator'      => ! empty( $mapping['custom_action_id'] ) ? 'custom' : 'master',
            'trigger_hooks'              => $settings['trigger_hooks'] ?? [],
            'is_action_enabled_for_form' => true,
            'execution_priority'         => 10,
            'action_name_label'          => $mapping['display_name'] ?? 'CPS Mapping',
            'settings'                   => $settings,
            'source'                     => 'cps', // Mark as CPS-sourced
        ];
    }

    /**
     * Phase 7 CSM: Merge local and CPS actions, avoiding duplicates.
     *
     * @param array $local_actions Local WP linkages.
     * @param array $cps_actions   Transformed CPS mappings.
     * @return array Merged list.
     */
    private function merge_local_and_cps_actions( array $local_actions, array $cps_actions ): array
    {
        // Normalize local actions to arrays only (legacy settings may include scalar keys like "enabled").
        $normalized_local_actions = [];
        foreach ( $local_actions as $action ) {
            if ( ! is_array( $action ) ) {
                continue;
            }
            $action['source']       = $action['source'] ?? 'local';
            $normalized_local_actions[] = $action;
        }

        $local_actions = $normalized_local_actions;

        // Add CPS actions that don't have a local equivalent
        $local_central_ids = array_values(
            array_filter(
                array_map(
                    static function ( $action ) {
                        return is_array( $action ) ? (string) ( $action['central_action_id'] ?? '' ) : '';
                    },
                    $local_actions
                ),
                static function ( $id ) {
                    return '' !== $id;
                }
            )
        );

        foreach ( $cps_actions as $cps_action ) {
            if ( ! is_array( $cps_action ) ) {
                continue;
            }

            $cps_central_id = (string) ( $cps_action['central_action_id'] ?? '' );

            // Skip if local already has this central action
            if ( '' !== $cps_central_id && in_array( $cps_central_id, $local_central_ids, true ) ) {
                continue;
            }

            $local_actions[] = $cps_action;
            if ( '' !== $cps_central_id ) {
                $local_central_ids[] = $cps_central_id;
            }
        }

        return $local_actions;
    }

    /**
     * CA-MAP-001: Retrieve form fields for FieldSelector component.
     *
     * Returns field metadata transformed to FormFieldInfo format.
     * Filters out non-input fields (HTML, page breaks, sections).
     *
     * @param WP_REST_Request $request The request.
     *
     * @return WP_REST_Response Field list or error.
     */
    public function get_form_fields( WP_REST_Request $request ): WP_REST_Response
    {
        $form_source_slug = $request->get_param( 'form_source_slug' );
        $form_id          = (int) $request->get_param( 'form_id' );

        // Get the adapter for this form source
        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry ? $registry->get_adapter_by_id( $form_source_slug ) : null;
        if ( ! $adapter ) {
            return new WP_REST_Response(
                [ 'success' => false, 'message' => 'Form source adapter not found.' ],
                404
            );
        }

        // Get raw form fields from adapter
        $raw_fields = $adapter->get_form_fields( $form_id );
        if ( empty( $raw_fields ) ) {
            return new WP_REST_Response(
                [ 'success' => true, 'data' => [] ],
                200
            );
        }

        // Transform to FieldSelector format and filter non-input fields
        $excluded_types = [ 'html', 'page', 'section', 'captcha' ];
        $fields         = [];

        foreach ( $raw_fields as $field ) {
            $field_type = strtolower( $field->type ?? '' );
            if ( in_array( $field_type, $excluded_types, true ) ) {
                continue;
            }

            $fields[] = [
                'id'         => (string) ( $field->id ?? '' ),
                'label'      => $field->label ?? '',
                'type'       => $field_type,
                'adminLabel' => $field->adminLabel ?? null,
            ];
        }

        return new WP_REST_Response(
            [ 'success' => true, 'data' => $fields ],
            200
        );
    }

    /**
     * Add a new action linkage to a form.
     */
    public function add_form_action( WP_REST_Request $request ): WP_Error | WP_REST_Response
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

        $actions_to_validate            = $actions;
        $actions_to_validate[ $new_id ] = $action;

        $dependency_validation = $this->validate_mapping_dependencies( $actions_to_validate );
        if ( is_wp_error( $dependency_validation ) )
        {
            return $this->prepare_error_response(
                $dependency_validation->get_error_code(),
                $dependency_validation->get_error_message(),
                400
            );
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

        $actions_to_validate       = $actions;
        $actions_to_validate[ $id ] = $linkage;

        $dependency_validation = $this->validate_mapping_dependencies( $actions_to_validate );
        if ( is_wp_error( $dependency_validation ) )
        {
            return $this->prepare_error_response(
                $dependency_validation->get_error_code(),
                $dependency_validation->get_error_message(),
                400
            );
        }

        $actions[ $id ] = $linkage;
        update_option( $option_key, $actions, false );

        return $this->prepare_item_for_response( $linkage );
    }

    /**
     * Duplicate an existing action mapping and insert it under a selected parent.
     *
     * The duplicate copies the original mapping configuration (except local_mapping_id), then
     * rewires parent pre-existing children for the selected hook to run through the duplicate.
     * Incompatible rewires are skipped and reported in the response.
     */
    public function duplicate_form_action_item( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $option_key = $this->get_actions_option_key( $request->get_param( 'form_source_slug' ), (int) $request->get_param( 'form_id' ) );
        $actions    = get_option( $option_key, [] );
        if ( ! is_array( $actions ) )
        {
            $actions = [];
        }

        $source_id = sanitize_text_field( (string) $request->get_param( 'local_mapping_id' ) );
        if ( '' === $source_id || ! isset( $actions[ $source_id ] ) || ! is_array( $actions[ $source_id ] ) )
        {
            return $this->prepare_error_response(
                'rest_action_not_found',
                __( 'Action linkage not found to duplicate.', 'sentient-forms' ),
                404
            );
        }

        $parent = $this->sanitize_duplicate_parent_request( $request->get_param( 'parent' ) );
        if ( is_wp_error( $parent ) )
        {
            return $this->prepare_error_response(
                $parent->get_error_code(),
                $parent->get_error_message(),
                400
            );
        }

        $source_mapping      = $actions[ $source_id ];
        $source_trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $source_mapping['trigger_hooks'] ?? [] ) );
        $target_hook         = sanitize_key( (string) $parent['hook'] );
        if ( ! in_array( $target_hook, $source_trigger_hooks, true ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_duplicate_parent_hook',
                sprintf(
                    /* translators: %s: hook id */
                    __( 'Duplicate insertion hook %s is not configured on the source mapping.', 'sentient-forms' ),
                    sanitize_text_field( $target_hook )
                ),
                400
            );
        }

        if ( 'mapping' === $parent['type'] )
        {
            $parent_mapping_id = sanitize_text_field( (string) ( $parent['mapping_id'] ?? '' ) );
            if ( '' === $parent_mapping_id || ! isset( $actions[ $parent_mapping_id ] ) || ! is_array( $actions[ $parent_mapping_id ] ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_duplicate_parent',
                    __( 'Selected parent mapping does not exist.', 'sentient-forms' ),
                    400
                );
            }

            $parent_mapping_hooks = $this->sanitize_trigger_hooks( (array) ( $actions[ $parent_mapping_id ]['trigger_hooks'] ?? [] ) );
            if ( ! $this->dependency_satisfies_hook( $target_hook, $parent_mapping_hooks ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_duplicate_parent_hooks',
                    __( 'Selected parent mapping does not satisfy the selected hook.', 'sentient-forms' ),
                    400
                );
            }

            if ( 'gform_after_submission' === $target_hook )
            {
                $parent_is_async = $this->is_mapping_async( $actions[ $parent_mapping_id ] );
                $source_is_async = $this->is_mapping_async( $source_mapping );
                if ( $parent_is_async && ! $source_is_async )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_duplicate_parent_execution_mode',
                        __( 'Selected parent mapping runs async in after-submission, but the source mapping does not.', 'sentient-forms' ),
                        400
                    );
                }
            }
        }

        $new_id = uniqid( 'map_', false );
        while ( isset( $actions[ $new_id ] ) )
        {
            $new_id = uniqid( 'map_', false );
        }

        $duplicate = $source_mapping;
        $duplicate['local_mapping_id'] = $new_id;
        $duplicate['trigger_hooks'] = $source_trigger_hooks;
        if ( ! array_key_exists( 'is_action_enabled_for_form', $duplicate ) )
        {
            $duplicate['is_action_enabled_for_form'] = true;
        }
        if ( ! isset( $duplicate['settings'] ) || ! is_array( $duplicate['settings'] ) )
        {
            $duplicate['settings'] = [];
        }

        $parent_source = 'mapping' === $parent['type']
            ? [
                'type'       => 'mapping',
                'mapping_id' => sanitize_text_field( (string) ( $parent['mapping_id'] ?? '' ) ),
            ]
            : [
                'type' => 'hook_root',
            ];
        $duplicate = $this->set_mapping_trigger_source_for_hook( $duplicate, $target_hook, $parent_source );
        if ( is_wp_error( $duplicate ) )
        {
            return $this->prepare_error_response(
                $duplicate->get_error_code(),
                $duplicate->get_error_message(),
                400
            );
        }

        $candidate = $actions;
        $candidate[ $new_id ] = $duplicate;
        $duplicate_validation = $this->validate_mapping_dependencies( $candidate );
        if ( is_wp_error( $duplicate_validation ) )
        {
            return $this->prepare_error_response(
                $duplicate_validation->get_error_code(),
                $duplicate_validation->get_error_message(),
                400
            );
        }

        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();
        $children_to_move = $this->find_parent_children_for_hook( $actions, $parent, $target_hook, $planner );
        $moved_children = [];
        $skipped_children = [];
        $working = $candidate;

        foreach ( $children_to_move as $child_id )
        {
            if ( ! isset( $working[ $child_id ] ) || ! is_array( $working[ $child_id ] ) )
            {
                continue;
            }

            $next_child = $this->set_mapping_trigger_source_for_hook(
                $working[ $child_id ],
                $target_hook,
                [
                    'type'       => 'mapping',
                    'mapping_id' => $new_id,
                ]
            );
            if ( is_wp_error( $next_child ) )
            {
                $skipped_children[] = [
                    'child_id' => $child_id,
                    'hook'     => $target_hook,
                    'code'     => 'policy_violation',
                    'message'  => $next_child->get_error_message(),
                ];
                continue;
            }

            $child_candidate = $working;
            $child_candidate[ $child_id ] = $next_child;
            $child_validation = $this->validate_mapping_dependencies( $child_candidate );
            if ( is_wp_error( $child_validation ) )
            {
                $skipped_children[] = [
                    'child_id' => $child_id,
                    'hook'     => $target_hook,
                    'code'     => $this->map_dependency_validation_error_to_skip_code( $child_validation->get_error_code() ),
                    'message'  => $child_validation->get_error_message(),
                ];
                continue;
            }

            $working[ $child_id ] = $next_child;
            $moved_children[] = $child_id;
        }

        $final_validation = $this->validate_mapping_dependencies( $working );
        if ( is_wp_error( $final_validation ) )
        {
            return $this->prepare_error_response(
                $final_validation->get_error_code(),
                $final_validation->get_error_message(),
                400
            );
        }

        update_option( $option_key, $working, false );

        $warnings = [];
        if ( ! empty( $skipped_children ) )
        {
            $warnings[] = __( 'Some parent children could not be rewired due to dependency policy constraints.', 'sentient-forms' );
        }

        return $this->prepare_item_for_response(
            [
                'duplicate' => $working[ $new_id ],
                'insertion' => [
                    'parent'           => $parent,
                    'moved_children'   => array_values( $moved_children ),
                    'skipped_children' => array_values( $skipped_children ),
                    'warnings'         => $warnings,
                ],
            ],
            201
        );
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

        foreach ( $actions as &$mapping )
        {
            if ( ! is_array( $mapping ) )
            {
                continue;
            }

            if ( ! isset( $mapping['settings'] ) || ! is_array( $mapping['settings'] ) )
            {
                continue;
            }

            if ( ! isset( $mapping['settings']['dependency_ids'] ) || ! is_array( $mapping['settings']['dependency_ids'] ) )
            {
                continue;
            }

            $dependency_ids = $this->sanitize_dependency_ids( $mapping['settings']['dependency_ids'] );
            $mapping['settings']['dependency_ids'] = array_values(
                array_filter(
                    $dependency_ids,
                    static fn( string $dependency_id ): bool => $dependency_id !== $id
                )
            );
        }
        unset( $mapping );

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
     * Normalize CPS workflow planner payload shape for UI compatibility.
     *
     * @param array  $payload    Raw CPS planner payload.
     * @param string $hook_scope Requested hook scope.
     *
     * @return array
     */
    private function normalize_workflow_plan_payload( array $payload, string $hook_scope ): array
    {
        return [
            'authority'         => 'cps',
            'authority_reason'  => isset( $payload['authority_reason'] ) && is_scalar( $payload['authority_reason'] )
                ? sanitize_key( (string) $payload['authority_reason'] )
                : null,
            'cps_unreachable'   => false,
            'policy_version'    => is_string( $payload['policy_version'] ?? null )
                ? $payload['policy_version']
                : self::WORKFLOW_POLICY_VERSION,
            'hook_scope'        => $hook_scope,
            'available_hooks'   => isset( $payload['available_hooks'] ) && is_array( $payload['available_hooks'] )
                ? array_values( $payload['available_hooks'] )
                : [],
            'nodes'             => isset( $payload['nodes'] ) && is_array( $payload['nodes'] )
                ? array_values( $payload['nodes'] )
                : [],
            'edges'             => isset( $payload['edges'] ) && is_array( $payload['edges'] )
                ? array_values( $payload['edges'] )
                : [],
            'hooks'             => isset( $payload['hooks'] ) && is_array( $payload['hooks'] )
                ? array_values( $payload['hooks'] )
                : [],
            'policy_violations' => isset( $payload['policy_violations'] ) && is_array( $payload['policy_violations'] )
                ? array_values( $payload['policy_violations'] )
                : [],
        ];
    }

    /**
     * Build a local fallback workflow plan when CPS planner is unavailable.
     *
     * @param array  $actions    Merged linkage payload.
     * @param string $hook_scope        Requested hook scope.
     * @param string $authority_reason  Reason why local fallback is authoritative.
     *
     * @return array
     */
    private function build_local_workflow_plan_payload( array $actions, string $hook_scope, string $authority_reason = 'cps_unavailable' ): array
    {
        $normalized = $this->normalize_local_action_mappings( $actions );
        $planner    = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();

        $nodes = [];
        $edges = [];
        $edge_lookup = [];
        $available_hooks = [];

        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks   = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            $trigger_sources = $planner->extract_trigger_sources( $mapping, $trigger_hooks );
            $dependency_ids  = $planner->extract_dependency_ids( $mapping );
            $is_enabled     = ! empty( $mapping['is_action_enabled_for_form'] );
            $is_async       = $this->is_mapping_async( $mapping );

            $available_hooks = array_values( array_unique( array_merge( $available_hooks, $trigger_hooks ) ) );

            $nodes[] = [
                'mapping_id'        => $mapping_id,
                'label'             => sanitize_text_field( (string) ( $mapping['action_name_label'] ?? $mapping['central_action_id'] ?? $mapping_id ) ),
                'central_action_id' => sanitize_text_field( (string) ( $mapping['central_action_id'] ?? '' ) ),
                'trigger_hooks'     => $trigger_hooks,
                'trigger_sources'   => $trigger_sources,
                'dependency_ids'    => $dependency_ids,
                'is_enabled'        => $is_enabled,
                'is_async'          => $is_async,
            ];

            foreach ( $trigger_hooks as $hook )
            {
                $hook_dependency_ids = $planner->extract_dependency_ids_for_hook( $mapping, $hook );
                if ( empty( $hook_dependency_ids ) )
                {
                    $edge_id = sprintf( 'hook_root:%s->%s:%s', $hook, $mapping_id, $hook );
                    if ( isset( $edge_lookup[ $edge_id ] ) )
                    {
                        continue;
                    }

                    $edges[] = [
                        'from' => sprintf( '__hook_root__:%s', $hook ),
                        'to'   => $mapping_id,
                        'kind' => 'hook_root',
                        'hook' => $hook,
                    ];
                    $edge_lookup[ $edge_id ] = true;
                    continue;
                }

                foreach ( $hook_dependency_ids as $dependency_id )
                {
                    $edge_id = sprintf( 'dependency:%s->%s:%s', $dependency_id, $mapping_id, $hook );
                    if ( isset( $edge_lookup[ $edge_id ] ) )
                    {
                        continue;
                    }

                    $edges[] = [
                        'from' => $dependency_id,
                        'to'   => $mapping_id,
                        'kind' => 'dependency',
                        'hook' => $hook,
                    ];
                    $edge_lookup[ $edge_id ] = true;
                }
            }
        }

        sort( $available_hooks );
        $hooks_to_plan = 'all' === $hook_scope
            ? $available_hooks
            : ( in_array( $hook_scope, $available_hooks, true ) ? [ $hook_scope ] : [] );

        $hook_payloads = [];
        foreach ( $hooks_to_plan as $hook )
        {
            $plan = $planner->build_execution_plan( $normalized, $hook );
            $order = [];
            $blocked = [];
            $blocked_lookup = [];

            foreach ( (array) ( $plan['order'] ?? [] ) as $mapping_id )
            {
                if ( ! isset( $plan['nodes'][ $mapping_id ] ) || ! is_array( $plan['nodes'][ $mapping_id ] ) )
                {
                    continue;
                }

                $node = $plan['nodes'][ $mapping_id ];
                if ( empty( $node['hook_enabled'] ) )
                {
                    continue;
                }

                $order[] = $mapping_id;
                $dependency_ids = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];

                if ( in_array( $mapping_id, (array) ( $plan['cycle_ids'] ?? [] ), true ) )
                {
                    $blocked_lookup[ $mapping_id ] = 'cycle';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'cycle',
                    ];
                    continue;
                }

                if ( empty( $node['enabled'] ) )
                {
                    $blocked_lookup[ $mapping_id ] = 'disabled';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'disabled',
                    ];
                    continue;
                }

                $missing_dependencies = [];
                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( ! isset( $plan['nodes'][ $dependency_id ] ) || ! is_array( $plan['nodes'][ $dependency_id ] ) )
                    {
                        $missing_dependencies[] = $dependency_id;
                        continue;
                    }

                    if ( empty( $plan['nodes'][ $dependency_id ]['hook_enabled'] ) )
                    {
                        $dependency_hooks = isset( $plan['nodes'][ $dependency_id ]['trigger_hooks'] ) && is_array( $plan['nodes'][ $dependency_id ]['trigger_hooks'] )
                            ? $plan['nodes'][ $dependency_id ]['trigger_hooks']
                            : [];
                        if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                        {
                            $missing_dependencies[] = $dependency_id;
                        }
                    }
                }

                if ( ! empty( $missing_dependencies ) )
                {
                    $blocked_lookup[ $mapping_id ] = 'missing_dependency';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'missing_dependency',
                        'details'    => implode( ', ', array_map( 'sanitize_text_field', $missing_dependencies ) ),
                    ];
                    continue;
                }

                if ( 'gform_after_submission' === $hook )
                {
                    $invalid_dependency = null;
                    foreach ( $dependency_ids as $dependency_id )
                    {
                        if ( ! isset( $plan['nodes'][ $dependency_id ] ) || ! is_array( $plan['nodes'][ $dependency_id ] ) )
                        {
                            continue;
                        }

                        $dependency_hooks = isset( $plan['nodes'][ $dependency_id ]['trigger_hooks'] ) && is_array( $plan['nodes'][ $dependency_id ]['trigger_hooks'] )
                            ? $plan['nodes'][ $dependency_id ]['trigger_hooks']
                            : [];
                        if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                        {
                            continue;
                        }

                        $dependency_is_async = $this->is_mapping_async( (array) ( $plan['nodes'][ $dependency_id ]['mapping'] ?? [] ) );
                        $mapping_is_async    = $this->is_mapping_async( (array) ( $node['mapping'] ?? [] ) );
                        if ( $dependency_is_async && ! $mapping_is_async )
                        {
                            $invalid_dependency = $dependency_id;
                            break;
                        }
                    }

                    if ( null !== $invalid_dependency )
                    {
                        $blocked_lookup[ $mapping_id ] = 'policy_violation';
                        $blocked[] = [
                            'mapping_id' => $mapping_id,
                            'reason'     => 'policy_violation',
                            'details'    => sanitize_text_field( (string) $invalid_dependency ) . ':execution_mode_mismatch',
                        ];
                        continue;
                    }
                }
            }

            $runnable = [];
            foreach ( $order as $mapping_id )
            {
                if ( isset( $blocked_lookup[ $mapping_id ] ) )
                {
                    continue;
                }

                $node = $plan['nodes'][ $mapping_id ] ?? null;
                $dependency_ids = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
                $upstream_blocking_dependency = null;
                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( isset( $blocked_lookup[ $dependency_id ] ) )
                    {
                        $upstream_blocking_dependency = $dependency_id;
                        break;
                    }
                }

                if ( null !== $upstream_blocking_dependency )
                {
                    $blocked_lookup[ $mapping_id ] = 'upstream_blocked';
                    $blocked[] = [
                        'mapping_id' => $mapping_id,
                        'reason'     => 'upstream_blocked',
                        'details'    => sanitize_text_field( $upstream_blocking_dependency ),
                    ];
                    continue;
                }

                $runnable[] = $mapping_id;
            }

            $waves = $this->build_local_workflow_waves( $runnable, $plan['nodes'] ?? [] );
            $hook_payloads[] = [
                'hook'      => $hook,
                'order'     => $order,
                'waves'     => $waves,
                'runnable'  => $runnable,
                'blocked'   => $blocked,
                'cycle_ids' => array_values( array_filter(
                    (array) ( $plan['cycle_ids'] ?? [] ),
                    static fn( $mapping_id ) => in_array( $mapping_id, $order, true )
                ) ),
            ];
        }

        return [
            'authority'         => 'local_fallback',
            'authority_reason'  => sanitize_key( $authority_reason ),
            'cps_unreachable'   => 'cps_mismatch' !== $authority_reason,
            'policy_version'    => self::WORKFLOW_POLICY_VERSION,
            'hook_scope'        => $hook_scope,
            'available_hooks'   => $available_hooks,
            'nodes'             => $nodes,
            'edges'             => $edges,
            'hooks'             => $hook_payloads,
            'policy_violations' => $this->collect_dependency_policy_violations( $normalized ),
        ];
    }

    /**
     * Build wave groups for topological order previews.
     *
     * @param array<int, string>                  $runnable_ids Runnable mapping ids in topological order.
     * @param array<string, array<string, mixed>> $nodes        Planner nodes keyed by mapping id.
     *
     * @return array<int, array{level: int, mapping_ids: array<int, string>}>
     */
    private function build_local_workflow_waves( array $runnable_ids, array $nodes ): array
    {
        $runnable_lookup = array_fill_keys( $runnable_ids, true );
        $levels          = [];
        foreach ( $runnable_ids as $mapping_id )
        {
            $dependency_ids = isset( $nodes[ $mapping_id ]['dependency_ids'] ) && is_array( $nodes[ $mapping_id ]['dependency_ids'] )
                ? $nodes[ $mapping_id ]['dependency_ids']
                : [];
            $dependency_levels = [];
            foreach ( $dependency_ids as $dependency_id )
            {
                if ( ! isset( $runnable_lookup[ $dependency_id ] ) )
                {
                    continue;
                }

                $dependency_levels[] = (int) ( $levels[ $dependency_id ] ?? 0 );
            }

            $levels[ $mapping_id ] = empty( $dependency_levels ) ? 0 : ( max( $dependency_levels ) + 1 );
        }

        $waves = [];
        foreach ( $runnable_ids as $mapping_id )
        {
            $level = (int) ( $levels[ $mapping_id ] ?? 0 );
            if ( ! isset( $waves[ $level ] ) )
            {
                $waves[ $level ] = [];
            }
            $waves[ $level ][] = $mapping_id;
        }

        ksort( $waves );
        $result = [];
        foreach ( $waves as $level => $mapping_ids )
        {
            $result[] = [
                'level'       => (int) $level,
                'mapping_ids' => array_values( $mapping_ids ),
            ];
        }

        return $result;
    }

    /**
     * Return non-fatal dependency policy diagnostics used by the planner UI.
     *
     * @param array<string, array<string, mixed>> $normalized Action mappings keyed by local mapping id.
     *
     * @return array<int, array{mapping_id: string, dependency_id: string, code: string, message: string}>
     */
    private function collect_dependency_policy_violations( array $normalized ): array
    {
        $violations       = [];
        $violation_lookup = [];
        $planner          = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();

        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            if ( empty( $trigger_hooks ) )
            {
                continue;
            }

            $mapping_is_async = $this->is_mapping_async( $mapping );

            foreach ( $trigger_hooks as $hook )
            {
                $dependency_ids = $planner->extract_dependency_ids_for_hook( $mapping, $hook );
                if ( empty( $dependency_ids ) )
                {
                    continue;
                }

                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( ! isset( $normalized[ $dependency_id ] ) )
                    {
                        $key = sprintf( 'missing_dependency:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $violation_lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'missing_dependency',
                            'message'       => sprintf(
                                __( 'Mapping %1$s depends on unknown mapping %2$s in hook %3$s.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            ),
                        ];
                        $violation_lookup[ $key ] = true;
                        continue;
                    }

                    $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
                    if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                    {
                        $key = sprintf( 'hook_mismatch:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $violation_lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'hook_mismatch',
                            'message'       => sprintf(
                                __( 'Mapping %1$s depends on %2$s in hook %3$s, but %2$s does not run on that hook.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            ),
                        ];
                        $violation_lookup[ $key ] = true;
                        continue;
                    }

                    if ( 'gform_after_submission' !== $hook )
                    {
                        continue;
                    }

                    $dependency_is_async = $this->is_mapping_async( $normalized[ $dependency_id ] );
                    if ( $dependency_is_async && ! $mapping_is_async )
                    {
                        $key = sprintf( 'execution_mode_mismatch:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $violation_lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'execution_mode_mismatch',
                            'message'       => sprintf(
                                __( 'Mapping %1$s depends on async mapping %2$s during after-submission, so %1$s must also run async.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id )
                            ),
                        ];
                        $violation_lookup[ $key ] = true;
                    }
                }
            }
        }

        return array_values( $violations );
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

            // CB-EXEC-003/004: Dedicated sanitisation for batch_settings.
            if ( 'batch_settings' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_batch_settings( $value );
                continue;
            }

            if ( 'conditions' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_conditions( $value );
                continue;
            }

            if ( 'dependency_ids' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_dependency_ids( $value );
                continue;
            }

            if ( 'trigger_sources' === $key && is_array( $value ) )
            {
                $sanitized[ $key ] = $this->sanitize_trigger_sources( $value );
                continue;
            }

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

    /**
     * CB-EXEC-003/004: Sanitise and clamp batch execution settings.
     *
     * @param array $raw Raw batch_settings from the client.
     * @return array {enabled: bool, delay_seconds: int, max_wait_seconds: int}
     */
    private function sanitize_batch_settings( array $raw ): array
    {
        return [
            'enabled'       => ! empty( $raw['enabled'] ),
            'delay_seconds' => max( 10, min( 3600, (int) ( $raw['delay_seconds'] ?? 60 ) ) ),
            'max_wait_seconds' => max( 43200, min( 604800, (int) ( $raw['max_wait_seconds'] ?? DAY_IN_SECONDS ) ) ),
        ];
    }

    /**
     * Sanitize dependency IDs for mapping DAG relationships.
     *
     * @param array $dependency_ids Raw dependency identifiers.
     *
     * @return array<int, string>
     */
    private function sanitize_dependency_ids( array $dependency_ids ): array
    {
        $result = [];
        foreach ( $dependency_ids as $dependency_id )
        {
            if ( ! is_scalar( $dependency_id ) )
            {
                continue;
            }

            $normalized = sanitize_text_field( (string) $dependency_id );
            if ( '' === $normalized )
            {
                continue;
            }

            $result[] = $normalized;
        }

        return array_values( array_unique( $result ) );
    }

    /**
     * Sanitize per-hook trigger source definitions.
     *
     * @param array $trigger_sources Raw trigger sources keyed by hook id.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    private function sanitize_trigger_sources( array $trigger_sources ): array
    {
        $sanitized = [];
        foreach ( $trigger_sources as $hook => $source )
        {
            if ( ! is_scalar( $hook ) || ! is_array( $source ) )
            {
                continue;
            }

            $hook_key = sanitize_key( (string) $hook );
            if ( '' === $hook_key || ! in_array( $hook_key, self::ALLOWED_TRIGGER_HOOKS, true ) )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';
            if ( 'mapping' !== $type && 'hook_root' !== $type )
            {
                continue;
            }

            if ( 'hook_root' === $type )
            {
                $sanitized[ $hook_key ] = [
                    'type' => 'hook_root',
                ];
                continue;
            }

            $mapping_id = '';
            if ( isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['mapping_id'] );
            }
            elseif ( isset( $source['source_mapping_id'] ) && is_scalar( $source['source_mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['source_mapping_id'] );
            }

            if ( '' === $mapping_id )
            {
                continue;
            }

            $sanitized[ $hook_key ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        return $sanitized;
    }

    /**
     * Sanitize duplicate-parent request payload.
     *
     * @param mixed $parent Raw parent payload.
     *
     * @return array{type: string, hook: string, mapping_id?: string}|WP_Error
     */
    private function sanitize_duplicate_parent_request( $parent ): array | WP_Error
    {
        if ( ! is_array( $parent ) )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent',
                __( 'Duplicate parent payload must be an object.', 'sentient-forms' )
            );
        }

        $type = isset( $parent['type'] ) && is_scalar( $parent['type'] )
            ? sanitize_key( (string) $parent['type'] )
            : '';
        if ( 'mapping' !== $type && 'hook_root' !== $type )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent',
                __( 'Parent type must be "mapping" or "hook_root".', 'sentient-forms' )
            );
        }

        $hook = isset( $parent['hook'] ) && is_scalar( $parent['hook'] )
            ? sanitize_key( (string) $parent['hook'] )
            : '';
        if ( '' === $hook || ! in_array( $hook, self::ALLOWED_TRIGGER_HOOKS, true ) )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent_hook',
                __( 'Parent hook is missing or not supported.', 'sentient-forms' )
            );
        }

        $normalized = [
            'type' => $type,
            'hook' => $hook,
        ];

        if ( 'mapping' === $type )
        {
            $mapping_id = isset( $parent['mapping_id'] ) && is_scalar( $parent['mapping_id'] )
                ? sanitize_text_field( (string) $parent['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                return new WP_Error(
                    'rest_invalid_duplicate_parent_mapping',
                    __( 'Parent mapping_id is required when parent type is mapping.', 'sentient-forms' )
                );
            }
            $normalized['mapping_id'] = $mapping_id;
        }

        return $normalized;
    }

    /**
     * Normalize trigger source graph for all hooks on a mapping.
     *
     * Uses explicit trigger_sources when present, otherwise derives a deterministic
     * fallback from legacy dependency_ids.
     *
     * @param array<string, mixed>                       $mapping       Mapping payload.
     * @param array<int, string>                         $trigger_hooks Mapping trigger hooks.
     * @param Sentient_Forms_Mapping_Dependency_Planner  $planner       Planner service.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    private function build_mapping_trigger_sources_for_hooks(
        array $mapping,
        array $trigger_hooks,
        Sentient_Forms_Mapping_Dependency_Planner $planner
    ): array
    {
        $trigger_hooks = $this->sanitize_trigger_hooks( $trigger_hooks );
        if ( empty( $trigger_hooks ) )
        {
            return [];
        }

        $explicit = $planner->extract_trigger_sources( $mapping, $trigger_hooks );
        $has_explicit = ! empty( $explicit );
        $normalized = [];

        if ( $has_explicit )
        {
            foreach ( $trigger_hooks as $hook )
            {
                $source = $explicit[ $hook ] ?? [ 'type' => 'hook_root' ];
                $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                    ? sanitize_key( (string) $source['type'] )
                    : 'hook_root';
                if ( 'mapping' !== $type )
                {
                    $normalized[ $hook ] = [ 'type' => 'hook_root' ];
                    continue;
                }

                $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                    ? sanitize_text_field( (string) $source['mapping_id'] )
                    : '';
                if ( '' === $mapping_id )
                {
                    $normalized[ $hook ] = [ 'type' => 'hook_root' ];
                    continue;
                }

                $normalized[ $hook ] = [
                    'type'       => 'mapping',
                    'mapping_id' => $mapping_id,
                ];
            }

            return $normalized;
        }

        $legacy_dependency_ids = $this->sanitize_dependency_ids( $planner->extract_dependency_ids( $mapping ) );
        if ( 1 === count( $legacy_dependency_ids ) )
        {
            foreach ( $trigger_hooks as $hook )
            {
                $normalized[ $hook ] = [
                    'type'       => 'mapping',
                    'mapping_id' => $legacy_dependency_ids[0],
                ];
            }
            return $normalized;
        }

        foreach ( $trigger_hooks as $hook )
        {
            $normalized[ $hook ] = [ 'type' => 'hook_root' ];
        }

        return $normalized;
    }

    /**
     * Apply hook-scoped trigger source to a mapping and recompute dependency_ids.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     * @param string               $hook    Target hook.
     * @param array<string, mixed> $source  Source payload with type/mapping_id.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function set_mapping_trigger_source_for_hook( array $mapping, string $hook, array $source ): array | WP_Error
    {
        $hook = sanitize_key( $hook );
        if ( '' === $hook || ! in_array( $hook, self::ALLOWED_TRIGGER_HOOKS, true ) )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent_hook',
                __( 'Cannot apply trigger source to an unsupported hook.', 'sentient-forms' )
            );
        }

        $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
        if ( ! in_array( $hook, $trigger_hooks, true ) )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent_hook',
                __( 'Cannot apply trigger source to a hook that the mapping does not use.', 'sentient-forms' )
            );
        }

        $source_type = isset( $source['type'] ) && is_scalar( $source['type'] )
            ? sanitize_key( (string) $source['type'] )
            : '';
        if ( 'mapping' !== $source_type && 'hook_root' !== $source_type )
        {
            return new WP_Error(
                'rest_invalid_duplicate_parent',
                __( 'Invalid trigger source type.', 'sentient-forms' )
            );
        }

        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();
        $trigger_sources = $this->build_mapping_trigger_sources_for_hooks( $mapping, $trigger_hooks, $planner );

        if ( 'hook_root' === $source_type )
        {
            $trigger_sources[ $hook ] = [ 'type' => 'hook_root' ];
        }
        else
        {
            $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                ? sanitize_text_field( (string) $source['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                return new WP_Error(
                    'rest_invalid_duplicate_parent_mapping',
                    __( 'Mapping trigger source requires a mapping_id.', 'sentient-forms' )
                );
            }

            $trigger_sources[ $hook ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
            ? $mapping['settings']
            : [];
        $settings['trigger_sources'] = $this->serialize_trigger_sources_for_storage( $trigger_sources );

        $dependency_ids = $this->derive_dependency_ids_from_trigger_sources( $trigger_sources );
        if ( empty( $dependency_ids ) )
        {
            unset( $settings['dependency_ids'] );
        }
        else
        {
            $settings['dependency_ids'] = $dependency_ids;
        }

        $mapping['trigger_hooks'] = $trigger_hooks;
        $mapping['settings'] = $settings;

        return $mapping;
    }

    /**
     * Derive unique dependency_ids from trigger source graph.
     *
     * @param array<string, array{type: string, mapping_id?: string}> $trigger_sources Trigger source graph.
     *
     * @return array<int, string>
     */
    private function derive_dependency_ids_from_trigger_sources( array $trigger_sources ): array
    {
        $dependencies = [];
        foreach ( $trigger_sources as $source )
        {
            if ( ! is_array( $source ) )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';
            if ( 'mapping' !== $type )
            {
                continue;
            }

            $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                ? sanitize_text_field( (string) $source['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                continue;
            }

            $dependencies[] = $mapping_id;
        }

        return array_values( array_unique( $dependencies ) );
    }

    /**
     * Serialize trigger source graph for storage in settings.trigger_sources.
     *
     * @param array<string, array{type: string, mapping_id?: string}> $trigger_sources Trigger source graph.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    private function serialize_trigger_sources_for_storage( array $trigger_sources ): array
    {
        $serialized = [];
        foreach ( $trigger_sources as $hook => $source )
        {
            $hook_key = sanitize_key( (string) $hook );
            if ( '' === $hook_key || ! in_array( $hook_key, self::ALLOWED_TRIGGER_HOOKS, true ) )
            {
                continue;
            }

            if ( ! is_array( $source ) )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';
            if ( 'mapping' !== $type )
            {
                $serialized[ $hook_key ] = [ 'type' => 'hook_root' ];
                continue;
            }

            $mapping_id = isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] )
                ? sanitize_text_field( (string) $source['mapping_id'] )
                : '';
            if ( '' === $mapping_id )
            {
                $serialized[ $hook_key ] = [ 'type' => 'hook_root' ];
                continue;
            }

            $serialized[ $hook_key ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        return $serialized;
    }

    /**
     * Find parent children (before duplicate insertion) that are eligible to rewire for hook.
     *
     * @param array<string, mixed>                      $actions Raw action map.
     * @param array{type: string, hook: string, mapping_id?: string} $parent Parent selection payload.
     * @param string                                    $hook Hook scope.
     * @param Sentient_Forms_Mapping_Dependency_Planner $planner Planner service.
     *
     * @return array<int, string>
     */
    private function find_parent_children_for_hook(
        array $actions,
        array $parent,
        string $hook,
        Sentient_Forms_Mapping_Dependency_Planner $planner
    ): array
    {
        $hook = sanitize_key( $hook );
        if ( '' === $hook )
        {
            return [];
        }

        $children = [];
        $normalized = $this->normalize_local_action_mappings( $actions );
        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            if ( ! in_array( $hook, $trigger_hooks, true ) )
            {
                continue;
            }

            $sources = $this->build_mapping_trigger_sources_for_hooks( $mapping, $trigger_hooks, $planner );
            $hook_source = $sources[ $hook ] ?? [ 'type' => 'hook_root' ];
            $hook_source_type = isset( $hook_source['type'] ) && is_scalar( $hook_source['type'] )
                ? sanitize_key( (string) $hook_source['type'] )
                : 'hook_root';
            $hook_source_mapping = isset( $hook_source['mapping_id'] ) && is_scalar( $hook_source['mapping_id'] )
                ? sanitize_text_field( (string) $hook_source['mapping_id'] )
                : '';

            if ( 'mapping' === $parent['type'] )
            {
                $parent_mapping_id = sanitize_text_field( (string) ( $parent['mapping_id'] ?? '' ) );
                if ( 'mapping' === $hook_source_type && $hook_source_mapping === $parent_mapping_id )
                {
                    $children[] = $mapping_id;
                }
                continue;
            }

            if ( 'hook_root' === $hook_source_type )
            {
                $children[] = $mapping_id;
            }
        }

        sort( $children );
        return $children;
    }

    /**
     * Map dependency-validation WP_Error codes into duplicate rewire skip codes.
     */
    private function map_dependency_validation_error_to_skip_code( string $wp_error_code ): string
    {
        $normalized = sanitize_key( $wp_error_code );
        return match ( $normalized )
        {
            'rest_invalid_dependency_execution_mode' => 'execution_mode_mismatch',
            'rest_invalid_dependency_hooks' => 'hook_mismatch',
            'rest_invalid_dependency_cycle' => 'cycle',
            'rest_invalid_dependency_missing' => 'missing_dependency',
            default => 'policy_violation',
        };
    }

    /**
     * Validate dependency graph integrity for current form mappings.
     *
     * @param array $actions Raw stored action map keyed by local mapping id.
     *
     * @return true|WP_Error
     */
    private function validate_mapping_dependencies( array $actions ): true | WP_Error
    {
        $normalized = $this->normalize_local_action_mappings( $actions );
        if ( empty( $normalized ) )
        {
            return true;
        }

        $planner = Sentient_Forms_Plugin::instance()->get_mapping_dependency_planner();

        $cycle_ids = [];
        foreach ( self::ALLOWED_TRIGGER_HOOKS as $hook )
        {
            $plan = $planner->build_execution_plan( $normalized, $hook );
            foreach ( (array) ( $plan['cycle_ids'] ?? [] ) as $cycle_id )
            {
                $cycle_ids[] = $cycle_id;
            }
        }
        $cycle_ids = array_values( array_unique( array_map( 'sanitize_text_field', $cycle_ids ) ) );
        if ( ! empty( $cycle_ids ) )
        {
            return new WP_Error(
                'rest_invalid_dependency_cycle',
                sprintf(
                    /* translators: %s: comma-separated mapping ids */
                    __( 'Dependency graph contains a cycle: %s', 'sentient-forms' ),
                    implode( ', ', $cycle_ids )
                )
            );
        }

        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            if ( empty( $trigger_hooks ) )
            {
                continue;
            }

            $mapping_is_async = $this->is_mapping_async( $mapping );

            foreach ( $trigger_hooks as $hook )
            {
                $dependency_ids = $planner->extract_dependency_ids_for_hook( $mapping, $hook );
                if ( empty( $dependency_ids ) )
                {
                    continue;
                }

                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( $dependency_id === $mapping_id )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_self',
                            sprintf(
                                /* translators: %s: mapping id */
                                __( 'Mapping %s cannot depend on itself.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id )
                            )
                        );
                    }

                    if ( ! isset( $normalized[ $dependency_id ] ) )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_missing',
                            sprintf(
                                /* translators: 1: mapping id, 2: dependency id */
                                __( 'Mapping %1$s depends on unknown mapping %2$s.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id )
                            )
                        );
                    }

                    $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
                    if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_hooks',
                            sprintf(
                                /* translators: 1: mapping id, 2: dependency id, 3: hook name */
                                __( 'Mapping %1$s depends on %2$s in hook %3$s, but %2$s does not run on that hook.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            )
                        );
                    }

                    if ( 'gform_after_submission' !== $hook )
                    {
                        continue;
                    }

                    $dependency_is_async = $this->is_mapping_async( $normalized[ $dependency_id ] );
                    if ( $dependency_is_async && ! $mapping_is_async )
                    {
                        return new WP_Error(
                            'rest_invalid_dependency_execution_mode',
                            sprintf(
                                /* translators: 1: mapping id, 2: dependency id */
                                __( 'Mapping %1$s depends on async mapping %2$s during after-submission, so %1$s must also run async.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id )
                            )
                        );
                    }
                }
            }
        }

        return true;
    }

    /**
     * Determine whether a mapping executes asynchronously for after-submission flow.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     *
     * @return bool
     */
    private function is_mapping_async( array $mapping ): bool
    {
        $trigger_hooks        = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
        $has_validation_hook  = in_array( 'gform_validation', $trigger_hooks, true );
        $has_after_hook       = in_array( 'gform_after_submission', $trigger_hooks, true );

        if ( $has_validation_hook && ! $has_after_hook )
        {
            return false;
        }

        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) && array_key_exists( 'async', $mapping['settings'] ) )
        {
            return rest_sanitize_boolean( $mapping['settings']['async'] );
        }

        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) && isset( $mapping['settings']['execution_mode'] ) && is_scalar( $mapping['settings']['execution_mode'] ) )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['settings']['execution_mode'] );
        }

        if ( isset( $mapping['execution_mode'] ) && is_scalar( $mapping['execution_mode'] ) )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['execution_mode'] );
        }

        if ( $has_after_hook )
        {
            return true;
        }

        $indicator = isset( $mapping['action_type_indicator'] ) && is_scalar( $mapping['action_type_indicator'] )
            ? sanitize_key( (string) $mapping['action_type_indicator'] )
            : '';

        if ( 'master' === $indicator )
        {
            return true;
        }

        return false;
    }

    /**
     * Determine whether dependency hooks satisfy a required runtime hook.
     *
     * Validation dependencies can satisfy after-submission dependants because sync
     * validation execution completes before async after-submission actions begin.
     *
     * @param string            $required_hook    Runtime hook being evaluated.
     * @param array<int, mixed> $dependency_hooks Dependency trigger hooks.
     *
     * @return bool
     */
    private function dependency_satisfies_hook( string $required_hook, array $dependency_hooks ): bool
    {
        $required_hook = sanitize_key( $required_hook );
        $normalized_dependency_hooks = $this->sanitize_trigger_hooks( $dependency_hooks );

        if ( in_array( $required_hook, $normalized_dependency_hooks, true ) )
        {
            return true;
        }

        return 'gform_after_submission' === $required_hook
            && in_array( 'gform_validation', $normalized_dependency_hooks, true );
    }

    /**
     * List required hooks a dependency does not satisfy for a dependant mapping.
     *
     * @param array<int, string> $trigger_hooks    Dependant mapping hooks.
     * @param array<int, string> $dependency_hooks Dependency mapping hooks.
     *
     * @return array<int, string>
     */
    private function collect_missing_required_hooks( array $trigger_hooks, array $dependency_hooks ): array
    {
        $missing = [];
        foreach ( $trigger_hooks as $required_hook )
        {
            if ( ! $this->dependency_satisfies_hook( $required_hook, $dependency_hooks ) )
            {
                $missing[] = $required_hook;
            }
        }

        return array_values( array_unique( $missing ) );
    }

    /**
     * Normalize local mapping payloads while filtering non-action metadata keys.
     *
     * @param array $actions Raw actions payload.
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalize_local_action_mappings( array $actions ): array
    {
        $normalized = [];

        foreach ( $actions as $mapping_key => $mapping )
        {
            if ( ! is_array( $mapping ) || ! isset( $mapping['central_action_id'] ) )
            {
                continue;
            }

            $mapping_id = isset( $mapping['local_mapping_id'] ) && is_scalar( $mapping['local_mapping_id'] )
                ? sanitize_text_field( (string) $mapping['local_mapping_id'] )
                : sanitize_text_field( (string) $mapping_key );

            if ( '' === $mapping_id )
            {
                continue;
            }

            $mapping['local_mapping_id'] = $mapping_id;
            $normalized[ $mapping_id ]   = $mapping;
        }

        return $normalized;
    }

    /**
     * CB-FORMS-006: Sanitize conditional run settings.
     *
     * @param array $raw Raw condition config.
     *
     * @return array
     */
    private function sanitize_conditions( array $raw ): array
    {
        $node_count = 0;
        $root       = null;

        if ( isset( $raw['root'] ) && is_array( $raw['root'] ) )
        {
            $root = $this->sanitize_condition_node( $raw['root'], 1, $node_count );
        }

        if ( ! is_array( $root ) )
        {
            $root = [
                'type'  => 'group',
                'logic' => 'all',
                'rules' => [],
            ];
        }

        return [
            'enabled' => ! empty( $raw['enabled'] ),
            'root'    => $root,
        ];
    }

    /**
     * Sanitize a condition node recursively.
     *
     * @param array $node       Raw condition node.
     * @param int   $depth      Current recursion depth.
     * @param int   $node_count Running node counter.
     *
     * @return array|null
     */
    private function sanitize_condition_node( array $node, int $depth, int &$node_count ): ?array
    {
        if ( $depth > self::MAX_CONDITION_DEPTH )
        {
            return null;
        }

        $node_count++;
        if ( $node_count > self::MAX_CONDITION_NODES )
        {
            return null;
        }

        $type = sanitize_key( (string) ( $node['type'] ?? '' ) );
        if ( 'group' === $type )
        {
            $logic = sanitize_key( (string) ( $node['logic'] ?? 'all' ) );
            $logic = in_array( $logic, [ 'all', 'any' ], true ) ? $logic : 'all';
            $rules = [];

            if ( isset( $node['rules'] ) && is_array( $node['rules'] ) )
            {
                foreach ( $node['rules'] as $child )
                {
                    if ( ! is_array( $child ) )
                    {
                        continue;
                    }

                    $child_type      = sanitize_key( (string) ( $child['type'] ?? '' ) );
                    $child_depth     = 'group' === $child_type ? $depth + 1 : $depth;
                    $sanitized_child = $this->sanitize_condition_node( $child, $child_depth, $node_count );
                    if ( null !== $sanitized_child )
                    {
                        $rules[] = $sanitized_child;
                    }
                }
            }

            return [
                'type'  => 'group',
                'logic' => $logic,
                'rules' => array_values( $rules ),
            ];
        }

        if ( 'rule' !== $type )
        {
            return null;
        }

        $field_id = isset( $node['field_id'] ) ? sanitize_text_field( (string) $node['field_id'] ) : '';
        if ( '' === $field_id )
        {
            return null;
        }

        $operator = sanitize_key( (string) ( $node['operator'] ?? '' ) );
        if ( ! in_array( $operator, self::CONDITION_OPERATORS, true ) )
        {
            return null;
        }

        $sanitized = [
            'type'     => 'rule',
            'field_id' => $field_id,
            'operator' => $operator,
        ];

        if ( $this->condition_operator_requires_value( $operator ) )
        {
            if ( ! array_key_exists( 'value', $node ) )
            {
                return null;
            }

            $value = $this->sanitize_condition_value( $operator, $node['value'] );
            if ( null === $value )
            {
                return null;
            }

            $sanitized['value'] = $value;
        }

        return $sanitized;
    }

    /**
     * Determine whether a condition operator requires a value.
     *
     * @param string $operator Operator key.
     *
     * @return bool
     */
    private function condition_operator_requires_value( string $operator ): bool
    {
        return ! in_array( $operator, [ 'is_empty', 'is_not_empty' ], true );
    }

    /**
     * Sanitize condition rule value by operator.
     *
     * @param string $operator Operator key.
     * @param mixed  $raw      Raw value.
     *
     * @return mixed|null
     */
    private function sanitize_condition_value( string $operator, $raw )
    {
        if ( in_array( $operator, [ 'in', 'not_in' ], true ) )
        {
            if ( ! is_array( $raw ) )
            {
                return null;
            }

            $items = [];
            foreach ( $raw as $value )
            {
                if ( ! is_scalar( $value ) )
                {
                    continue;
                }

                $text = sanitize_text_field( (string) $value );
                if ( '' !== $text )
                {
                    $items[] = $text;
                }
            }

            if ( empty( $items ) )
            {
                return null;
            }

            return array_values( array_unique( $items ) );
        }

        if ( in_array( $operator, [ 'gt', 'gte', 'lt', 'lte' ], true ) )
        {
            if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) )
            {
                return null;
            }

            return (float) $raw;
        }

        if ( ! is_scalar( $raw ) )
        {
            return null;
        }

        return sanitize_text_field( (string) $raw );
    }
}
