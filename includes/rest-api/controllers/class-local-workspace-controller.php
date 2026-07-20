<?php
/**
 * REST API controller for local-first action, mapping, and execution records.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Workspace_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'local';

    private Sentient_Forms_Action_Templates_Repository $templates;
    private Sentient_Forms_Local_Custom_Actions_Repository $custom_actions;
    private Sentient_Forms_Form_Mappings_Repository $mappings;
    private Sentient_Forms_Execution_Events_Repository $events;
    private Sentient_Forms_Local_Action_Execution_Service $local_execution;
    private Sentient_Forms_Local_Support_Bundle_Service $support_bundle;
    private Sentient_Forms_Local_Cutover_Service $cutover;
    private Sentient_Forms_Local_Import_Service $import;

    public function __construct(
        ?Sentient_Forms_Action_Templates_Repository $templates = null,
        ?Sentient_Forms_Local_Custom_Actions_Repository $custom_actions = null,
        ?Sentient_Forms_Form_Mappings_Repository $mappings = null,
        ?Sentient_Forms_Execution_Events_Repository $events = null,
        ?Sentient_Forms_Local_Action_Execution_Service $local_execution = null,
        ?Sentient_Forms_Local_Support_Bundle_Service $support_bundle = null,
        ?Sentient_Forms_Local_Cutover_Service $cutover = null,
        ?Sentient_Forms_Local_Import_Service $import = null
    )
    {
        parent::__construct();

        global $wpdb;

        $this->templates      = $templates ?? new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $this->custom_actions = $custom_actions ?? new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $this->mappings       = $mappings ?? new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->events         = $events ?? new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $this->local_execution = $local_execution ?? new Sentient_Forms_Local_Action_Execution_Service(
            $this->mappings,
            $this->custom_actions,
            null,
            null,
            $this->events
        );
        $this->support_bundle = $support_bundle ?? new Sentient_Forms_Local_Support_Bundle_Service( $wpdb );
        $this->cutover        = $cutover ?? new Sentient_Forms_Local_Cutover_Service( $wpdb );
        $this->import         = $import ?? new Sentient_Forms_Local_Import_Service( $wpdb );
    }

    public function register_routes(): void
    {
        $this->register_collection_route( 'action-templates', [ $this, 'list_action_templates' ], [ $this, 'upsert_action_template' ] );
        $this->register_collection_route( 'custom-actions', [ $this, 'list_custom_actions' ], [ $this, 'create_custom_action' ] );
        $this->register_collection_route( 'form-mappings', [ $this, 'list_form_mappings' ], [ $this, 'create_form_mapping' ] );
        $this->register_collection_route( 'execution-events', [ $this, 'list_execution_events' ], [ $this, 'record_execution_event' ] );
        $this->register_migration_routes();

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/support-bundle',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_support_bundle' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/form-mappings/(?P<id>[\d]+)/execute-test',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'execute_form_mapping' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'id' => [
                            'type'              => 'integer',
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );
    }

    public function list_action_templates( WP_REST_Request $request ): WP_REST_Response
    {
        return $this->prepare_item_for_response(
            array_map( [ $this, 'format_action_template' ], $this->templates->list_active() )
        );
    }

    public function upsert_action_template( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $payload = $request->get_params();
        $id      = $this->templates->upsert_by_code(
            [
                'source'                   => $payload['source'] ?? 'bundled',
                'external_id'              => $payload['external_id'] ?? null,
                'code'                     => $payload['code'] ?? '',
                'display_name'             => $payload['display_name'] ?? '',
                'description'              => $payload['description'] ?? null,
                'prompt_template'          => $payload['prompt_template'] ?? '',
                'default_model'            => $payload['default_model'] ?? null,
                'structured_output_schema' => $this->array_param( $payload, 'structured_output_schema' ),
                'override_schema'          => $this->array_param( $payload, 'override_schema' ),
                'version'                  => $payload['version'] ?? '1',
                'is_active'                => $this->bool_param( $payload, 'is_active', true ),
            ]
        );

        if ( is_wp_error( $id ) )
        {
            return $id;
        }

        $row = $this->templates->get_by_code( (string) $payload['code'] );

        return $this->prepare_item_for_response( $this->format_action_template( $row ?? [ 'id' => $id ] ), 201 );
    }

    public function list_custom_actions( WP_REST_Request $request ): WP_REST_Response
    {
        $status = sanitize_key( (string) ( $request->get_param( 'status' ) ?? 'active' ) );
        $args   = [
            'status'           => $status,
            'include_archived' => rest_sanitize_boolean( $request->get_param( 'include_archived' ) ),
        ];

        return $this->prepare_item_for_response(
            array_map( [ $this, 'format_custom_action' ], $this->custom_actions->list_filtered( $args ) )
        );
    }

    public function create_custom_action( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $payload = $request->get_params();
        $id      = $this->custom_actions->create(
            [
                'external_id'          => $payload['external_id'] ?? null,
                'template_id'          => $payload['template_id'] ?? null,
                'code'                 => $payload['code'] ?? '',
                'display_name'         => $payload['display_name'] ?? '',
                'definition_json'      => $this->array_param( $payload, 'definition_json' ),
                'model_selection_json' => $this->array_param( $payload, 'model_selection_json' ),
                'status'               => $payload['status'] ?? 'active',
            ]
        );

        if ( is_wp_error( $id ) )
        {
            return $id;
        }

        $row = $this->custom_actions->get_by_code( (string) $payload['code'] );

        return $this->prepare_item_for_response( $this->format_custom_action( $row ?? [ 'id' => $id ] ), 201 );
    }

    public function list_form_mappings( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $form_source = sanitize_key( (string) $request->get_param( 'form_source' ) );
        $form_id     = sanitize_text_field( (string) $request->get_param( 'form_id' ) );

        if ( '' === $form_source || '' === $form_id )
        {
            return new WP_Error(
                'sentient_forms_missing_form_mapping_filter',
                __( 'form_source and form_id are required to list local form mappings.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        return $this->prepare_item_for_response(
            array_map( [ $this, 'format_form_mapping' ], $this->mappings->list_for_form( $form_source, $form_id ) )
        );
    }

    public function create_form_mapping( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $payload = $request->get_params();
        $action_kind = sanitize_key( (string) ( $payload['action_kind'] ?? '' ) );
        $action_id   = absint( $payload['action_id'] ?? 0 );

        if ( 'custom_action' === $action_kind )
        {
            $action = $this->custom_actions->get( $action_id );
            if ( ! is_array( $action ) )
            {
                return new WP_Error(
                    'sentient_forms_local_action_not_found',
                    __( 'Local custom action could not be found.', 'sentient-forms' ),
                    [ 'status' => 404 ]
                );
            }

            if ( 'active' !== (string) ( $action['status'] ?? '' ) )
            {
                return new WP_Error(
                    'sentient_forms_local_action_inactive',
                    __( 'Local custom action must be active before it can be mapped.', 'sentient-forms' ),
                    [ 'status' => 409 ]
                );
            }
        }

        $id      = $this->mappings->create(
            [
                'external_id'         => $payload['external_id'] ?? null,
                'form_source'         => $payload['form_source'] ?? 'gravity_forms',
                'form_id'             => $payload['form_id'] ?? '',
                'hook'                => $payload['hook'] ?? '',
                'action_kind'         => $action_kind,
                'action_id'           => $action_id,
                'conditions_json'     => $this->array_param( $payload, 'conditions_json' ),
                'input_bindings_json' => $this->array_param( $payload, 'input_bindings_json' ),
                'execution_mode'      => $payload['execution_mode'] ?? 'async',
                'effect_mapping_json' => $this->array_param( $payload, 'effect_mapping_json' ),
                'enabled'             => $this->bool_param( $payload, 'enabled', true ),
            ]
        );

        if ( is_wp_error( $id ) )
        {
            return $id;
        }

        $rows = $this->mappings->list_for_form( (string) $payload['form_source'], (string) $payload['form_id'] );
        $row  = null;
        foreach ( $rows as $candidate )
        {
            if ( (int) $candidate['id'] === (int) $id )
            {
                $row = $candidate;
                break;
            }
        }

        return $this->prepare_item_for_response( $this->format_form_mapping( $row ?? [ 'id' => $id ] ), 201 );
    }

    public function list_execution_events( WP_REST_Request $request ): WP_REST_Response
    {
        $limit = null !== $request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 50;

        return $this->prepare_item_for_response(
            array_map( [ $this, 'format_execution_event' ], $this->events->list_recent( $limit ) )
        );
    }

    public function record_execution_event( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $payload = $request->get_params();
        $provider = sanitize_key( (string) ( $payload['provider'] ?? 'openrouter' ) );
        $cost_json = $this->array_param( $payload, 'cost_json' );
        $result_json = $this->array_param( $payload, 'result_json' );
        if ( class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' ) && Sentient_Forms_Managed_Usage_Sanitizer::is_managed_provider( $provider ) )
        {
            $cost_json   = is_array( $cost_json ) ? Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $cost_json ) : $cost_json;
            $result_json = is_array( $result_json ) ? Sentient_Forms_Managed_Usage_Sanitizer::sanitize_for_managed_context( $result_json ) : $result_json;
        }

        $id      = $this->events->record(
            [
                'execution_request_id' => $payload['execution_request_id'] ?? '',
                'mapping_id'           => $payload['mapping_id'] ?? null,
                'form_source'          => $payload['form_source'] ?? null,
                'form_id'              => $payload['form_id'] ?? null,
                'entry_id'             => $payload['entry_id'] ?? null,
                'provider'             => $provider,
                'model'                => $payload['model'] ?? null,
                'status'               => $payload['status'] ?? 'queued',
                'token_usage_json'     => $this->array_param( $payload, 'token_usage_json' ),
                'cost_json'            => $cost_json,
                'result_json'          => $result_json,
                'error_code'           => $payload['error_code'] ?? null,
                'error_message'        => $payload['error_message'] ?? null,
                'payload_digest'       => $payload['payload_digest'] ?? null,
                'expires_at'           => $payload['expires_at'] ?? null,
            ]
        );

        if ( is_wp_error( $id ) )
        {
            return $id;
        }

        $row = $this->events->get_by_request_id( (string) $payload['execution_request_id'] );

        return $this->prepare_item_for_response( $this->format_execution_event( $row ?? [ 'id' => $id ] ), 201 );
    }

    public function execute_form_mapping( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $form    = $request->get_param( 'form' );
        $entry   = $request->get_param( 'entry' );
        $context = $request->get_param( 'context' );

        if ( ! is_array( $form ) || [] === $form )
        {
            return new WP_Error(
                'sentient_forms_missing_test_form',
                __( 'A form object is required for local mapping test execution.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! is_array( $entry ) )
        {
            return new WP_Error(
                'sentient_forms_missing_test_entry',
                __( 'An entry object is required for local mapping test execution.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        if ( null !== $context && ! is_array( $context ) )
        {
            return new WP_Error(
                'sentient_forms_invalid_test_context',
                __( 'Execution context must be an object.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $context = is_array( $context ) ? $context : [];
        unset(
            $context['form_source_capabilities'],
            $context['secondary_preflight_complete']
        );

        $result = $this->local_execution->execute_mapping(
            (int) $request['id'],
            $form,
            $entry,
            $context
        );

        if ( is_wp_error( $result ) )
        {
            if ( ! is_array( $result->get_error_data() ) || ! isset( $result->get_error_data()['status'] ) )
            {
                $result->add_data( [ 'status' => 400 ] );
            }

            return $result;
        }

        return $this->prepare_item_for_response( $result );
    }

    public function get_support_bundle( WP_REST_Request $request ): WP_REST_Response
    {
        return $this->prepare_item_for_response( $this->support_bundle->build() );
    }

    public function get_migration_readiness( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $report = $this->cutover->build_readiness_report();
        return is_wp_error( $report ) ? $report : $this->prepare_item_for_response( $report );
    }

    public function create_migration_dry_run( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $result = $this->cutover->record_dry_run( get_current_user_id() ?: null );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result, 201 );
    }

    public function run_migration_approved_reset( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $confirmation = $request->get_param( 'confirmation_phrase' );
        $result       = $this->cutover->approved_reset(
            is_scalar( $confirmation ) ? (string) $confirmation : '',
            get_current_user_id() ?: null
        );

        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result );
    }

    public function create_migration_import_dry_run( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $bundle = $request->get_param( 'bundle' );
        if ( ! is_array( $bundle ) )
        {
            return new WP_Error(
                'sentient_forms_missing_import_bundle',
                __( 'A CPS export bundle object is required for import dry-run.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $result = $this->import->dry_run( $bundle, get_current_user_id() ?: null );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result, 201 );
    }

    public function run_migration_import_apply( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $bundle = $request->get_param( 'bundle' );
        if ( ! is_array( $bundle ) )
        {
            return new WP_Error(
                'sentient_forms_missing_import_bundle',
                __( 'A CPS export bundle object is required for import apply.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $result = $this->import->apply( $bundle, get_current_user_id() ?: null );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result, 201 );
    }

    private function register_migration_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/migration/readiness',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_migration_readiness' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/migration/dry-run',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_migration_dry_run' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/migration/import/dry-run',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_migration_import_dry_run' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'bundle' => [
                            'type'              => 'object',
                            'required'          => true,
                            'validate_callback' => 'rest_validate_request_arg',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/migration/import/apply',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'run_migration_import_apply' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'bundle' => [
                            'type'              => 'object',
                            'required'          => true,
                            'validate_callback' => 'rest_validate_request_arg',
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/migration/approved-reset',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'run_migration_approved_reset' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'confirmation_phrase' => [
                            'type'              => 'string',
                            'required'          => true,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    private function register_collection_route( string $path, callable $read_callback, callable $write_callback ): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/' . $path,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => $read_callback,
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => $write_callback,
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );
    }

    private function array_param( array $payload, string $key ): ?array
    {
        if ( ! array_key_exists( $key, $payload ) || null === $payload[ $key ] || '' === $payload[ $key ] )
        {
            return null;
        }

        return is_array( $payload[ $key ] ) ? $payload[ $key ] : null;
    }

    private function bool_param( array $payload, string $key, bool $default ): bool
    {
        if ( ! array_key_exists( $key, $payload ) )
        {
            return $default;
        }

        return rest_sanitize_boolean( $payload[ $key ] );
    }

    private function format_action_template( array $row ): array
    {
        return [
            'id'                       => (int) ( $row['id'] ?? 0 ),
            'source'                   => $row['source'] ?? null,
            'external_id'              => $row['external_id'] ?? null,
            'code'                     => $row['code'] ?? null,
            'display_name'             => $row['display_name'] ?? null,
            'description'              => $row['description'] ?? null,
            'prompt_template'          => $row['prompt_template'] ?? null,
            'default_model'            => $row['default_model'] ?? null,
            'structured_output_schema' => $row['structured_output_schema'] ?? null,
            'override_schema'          => $row['override_schema'] ?? null,
            'version'                  => $row['version'] ?? null,
            'is_active'                => ! empty( $row['is_active'] ),
            'created_at'               => $row['created_at'] ?? null,
            'updated_at'               => $row['updated_at'] ?? null,
        ];
    }

    private function format_custom_action( array $row ): array
    {
        return [
            'id'                   => (int) ( $row['id'] ?? 0 ),
            'external_id'          => $row['external_id'] ?? null,
            'template_id'          => isset( $row['template_id'] ) ? (int) $row['template_id'] : null,
            'code'                 => $row['code'] ?? null,
            'display_name'         => $row['display_name'] ?? null,
            'definition_json'      => $row['definition_json'] ?? [],
            'model_selection_json' => $row['model_selection_json'] ?? null,
            'status'               => $row['status'] ?? null,
            'created_at'           => $row['created_at'] ?? null,
            'updated_at'           => $row['updated_at'] ?? null,
        ];
    }

    private function format_form_mapping( array $row ): array
    {
        return [
            'id'                  => (int) ( $row['id'] ?? 0 ),
            'external_id'         => $row['external_id'] ?? null,
            'form_source'         => $row['form_source'] ?? null,
            'form_id'             => $row['form_id'] ?? null,
            'hook'                => $row['hook'] ?? null,
            'action_kind'         => $row['action_kind'] ?? null,
            'action_id'           => isset( $row['action_id'] ) ? (int) $row['action_id'] : 0,
            'conditions_json'     => $row['conditions_json'] ?? null,
            'input_bindings_json' => $row['input_bindings_json'] ?? [],
            'execution_mode'      => $row['execution_mode'] ?? null,
            'effect_mapping_json' => $row['effect_mapping_json'] ?? null,
            'enabled'             => ! empty( $row['enabled'] ),
            'created_at'          => $row['created_at'] ?? null,
            'updated_at'          => $row['updated_at'] ?? null,
        ];
    }

    private function format_execution_event( array $row ): array
    {
        $row = class_exists( 'Sentient_Forms_Managed_Usage_Sanitizer' )
            ? Sentient_Forms_Managed_Usage_Sanitizer::sanitize_event_fields( $row )
            : $row;
        $form_source = isset( $row['form_source'] ) && is_scalar( $row['form_source'] )
            ? sanitize_key( (string) $row['form_source'] )
            : null;

        return [
            'id'                   => (int) ( $row['id'] ?? 0 ),
            'execution_request_id' => $row['execution_request_id'] ?? null,
            'mapping_id'           => isset( $row['mapping_id'] ) ? (int) $row['mapping_id'] : null,
            'form_source'          => $form_source,
            'form_id'              => $row['form_id'] ?? null,
            'entry_id'             => $this->format_execution_event_entry_id( $row['entry_id'] ?? null, $form_source ),
            'provider'             => $row['provider'] ?? null,
            'model'                => $row['model'] ?? null,
            'status'               => $row['status'] ?? null,
            'token_usage_json'     => $row['token_usage_json'] ?? null,
            'cost_json'            => $row['cost_json'] ?? null,
            'result_json'          => $row['result_json'] ?? null,
            'error_code'           => $row['error_code'] ?? null,
            'error_message'        => $row['error_message'] ?? null,
            'payload_digest'       => $row['payload_digest'] ?? null,
            'created_at'           => $row['created_at'] ?? null,
            'updated_at'           => $row['updated_at'] ?? null,
            'expires_at'           => $row['expires_at'] ?? null,
        ];
    }

    private function format_execution_event_entry_id( mixed $entry_id, ?string $form_source ): ?string
    {
        if ( null === $entry_id || ! is_scalar( $entry_id ) )
        {
            return null;
        }

        $entry_id = sanitize_text_field( (string) $entry_id );
        if ( '' === $entry_id )
        {
            return null;
        }

        if ( null !== $form_source )
        {
            $native_entry = Sentient_Forms_Form_Sources::native_entry_capability_for_form_source( $form_source );
            if ( is_array( $native_entry ) && array_key_exists( 'id', $native_entry ) && ! $native_entry['id'] )
            {
                return null;
            }
        }

        return $entry_id;
    }

}
