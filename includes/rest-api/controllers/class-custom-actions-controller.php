<?php
/**
 * REST controller proxying CPS custom-action CRUD endpoints.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Custom_Actions_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    protected string $rest_base = 'custom-actions';

    private Sentient_Forms_Custom_Actions_Service $service;

    private ?Sentient_Forms_Local_Custom_Actions_Repository $local_custom_actions = null;

    private ?Sentient_Forms_Provider_Credentials_Repository $local_provider_credentials = null;

    public function __construct()
    {
        parent::__construct();
        $this->service = new Sentient_Forms_Custom_Actions_Service( Sentient_Forms_Plugin::instance() );
        global $wpdb;
        if ( class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' ) )
        {
            $this->local_custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        }
        if ( class_exists( 'Sentient_Forms_Provider_Credentials_Repository' ) )
        {
            $this->local_provider_credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        }
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'list_custom_actions' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_collection_params_args(),
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_custom_action' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_create_args(),
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[A-Za-z0-9_-]+)',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_custom_action' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => $this->get_update_args(),
                ],
                [
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => [ $this, 'archive_custom_action' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>[A-Za-z0-9_-]+)/reactivate',
            [
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'reactivate_custom_action' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );
    }

    public function list_custom_actions( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        if ( ! apply_filters( 'sentient_forms_enable_legacy_cps_custom_actions', false ) )
        {
            return $this->prepare_item_for_response( $this->list_local_custom_actions_for_legacy_route( $request ) );
        }

        $query = [];

        if ( null !== $request->get_param( 'status' ) )
        {
            $status = strtolower( sanitize_text_field( (string) $request->get_param( 'status' ) ) );
            if ( !in_array( $status, [ 'active', 'archived' ], true ) )
            {
                return $this->prepare_error_response( 'rest_invalid_param', __( 'Status must be active or archived.', 'sentient-forms' ), 400 );
            }
            $query['status'] = $status;
        }

        if ( null !== $request->get_param( 'include_archived' ) )
        {
            $query['include_archived'] = rest_sanitize_boolean( $request->get_param( 'include_archived' ) ) ? 'true' : 'false';
        }

        if ( null !== $request->get_param( 'template_id' ) )
        {
            $query['template_id'] = sanitize_text_field( (string) $request->get_param( 'template_id' ) );
        }

        $result = $this->service->list( $query );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result->to_array() );
    }

    public function create_custom_action( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        if ( ! apply_filters( 'sentient_forms_enable_legacy_cps_custom_actions', false ) )
        {
            $payload = $this->build_create_payload( $request );
            if ( is_wp_error( $payload ) )
            {
                return $payload;
            }

            return $this->create_local_custom_action_for_legacy_route( $payload );
        }

        $payload = $this->build_create_payload( $request );
        if ( is_wp_error( $payload ) )
        {
            return $payload;
        }

        $result = $this->service->create( $payload, $this->build_actor_hint() );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result->to_array(), 201 );
    }

    public function update_custom_action( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        if ( ! apply_filters( 'sentient_forms_enable_legacy_cps_custom_actions', false ) )
        {
            $payload = $this->build_update_payload( $request );
            if ( is_wp_error( $payload ) )
            {
                return $payload;
            }

            return $this->update_local_custom_action_for_legacy_route( $request, $payload );
        }

        $payload = $this->build_update_payload( $request );
        if ( is_wp_error( $payload ) )
        {
            return $payload;
        }

        $action_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
        $result    = $this->service->update( $action_id, $payload, $this->build_actor_hint() );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result->to_array() );
    }

    public function archive_custom_action( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        if ( ! apply_filters( 'sentient_forms_enable_legacy_cps_custom_actions', false ) )
        {
            return $this->archive_local_custom_action_for_legacy_route( $request );
        }

        $action_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
        $result    = $this->service->archive( $action_id, $this->build_actor_hint() );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result->to_array() );
    }

    public function reactivate_custom_action( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        if ( ! apply_filters( 'sentient_forms_enable_legacy_cps_custom_actions', false ) )
        {
            return $this->reactivate_local_custom_action_for_legacy_route( $request );
        }

        $action_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
        $result    = $this->service->reactivate( $action_id, $this->build_actor_hint() );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result->to_array() );
    }

    /**
     * Return local custom actions through the legacy route shape so older admin screens do not call CPS.
     *
     * @return array{actions: array<int, array<string, mixed>>, quota: array<string, int>}
     */
    private function list_local_custom_actions_for_legacy_route( WP_REST_Request $request ): array
    {
        $status = strtolower( sanitize_text_field( (string) ( $request->get_param( 'status' ) ?? 'active' ) ) );
        if ( ! in_array( $status, [ 'active', 'archived' ], true ) )
        {
            $status = 'active';
        }

        $rows = $this->local_custom_actions ? $this->local_custom_actions->list( $status ) : [];
        $actions = array_map( [ $this, 'format_local_custom_action_for_legacy_route' ], $rows );

        return [
            'actions' => $actions,
            'quota'   => $this->local_custom_action_quota(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function create_local_custom_action_for_legacy_route( array $payload ): WP_REST_Response | WP_Error
    {
        if ( ! $this->local_custom_actions )
        {
            return $this->local_custom_actions_unavailable_error();
        }

        $template_id = absint( $payload['template_id'] ?? 0 );
        if ( $template_id <= 0 )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'template_id must be an action template ID.', 'sentient-forms' ),
                400,
            );
        }

        $id = $this->local_custom_actions->create(
            $this->build_local_custom_action_row( $payload, null, $template_id )
        );
        if ( is_wp_error( $id ) )
        {
            return $id;
        }

        $row = $this->local_custom_actions->get( (int) $id );
        if ( ! $row )
        {
            return $this->local_custom_action_not_found_error();
        }

        return $this->prepare_item_for_response(
            [
                'action' => $this->format_local_custom_action_for_legacy_route( $row ),
                'quota'  => $this->local_custom_action_quota(),
            ],
            201
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function update_local_custom_action_for_legacy_route( WP_REST_Request $request, array $payload ): WP_REST_Response | WP_Error
    {
        $existing = $this->get_local_custom_action_from_request( $request );
        if ( is_wp_error( $existing ) )
        {
            return $existing;
        }

        $template_id = isset( $existing['template_id'] ) ? (int) $existing['template_id'] : null;
        $updated = $this->local_custom_actions->update(
            (int) $existing['id'],
            $this->build_local_custom_action_row( $payload, $existing, $template_id )
        );
        if ( is_wp_error( $updated ) )
        {
            return $updated;
        }

        return $this->prepare_item_for_response(
            [
                'action' => $this->format_local_custom_action_for_legacy_route( $updated ),
                'quota'  => $this->local_custom_action_quota(),
            ]
        );
    }

    private function archive_local_custom_action_for_legacy_route( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $existing = $this->get_local_custom_action_from_request( $request );
        if ( is_wp_error( $existing ) )
        {
            return $existing;
        }

        $updated = $this->local_custom_actions->update_status( (int) $existing['id'], 'archived' );
        if ( ! $updated )
        {
            return new WP_Error(
                'sentient_forms_db_update_failed',
                __( 'Custom action could not be archived.', 'sentient-forms' ),
                [ 'status' => 500 ]
            );
        }

        $row = $this->local_custom_actions->get( (int) $existing['id'] );
        if ( ! $row )
        {
            return $this->local_custom_action_not_found_error();
        }

        return $this->prepare_item_for_response(
            [
                'action' => $this->format_local_custom_action_for_legacy_route( $row ),
                'quota'  => $this->local_custom_action_quota(),
            ]
        );
    }

    private function reactivate_local_custom_action_for_legacy_route( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $existing = $this->get_local_custom_action_from_request( $request );
        if ( is_wp_error( $existing ) )
        {
            return $existing;
        }

        $updated = $this->local_custom_actions->update_status( (int) $existing['id'], 'active' );
        if ( ! $updated )
        {
            return new WP_Error(
                'sentient_forms_db_update_failed',
                __( 'Custom action could not be reactivated.', 'sentient-forms' ),
                [ 'status' => 500 ]
            );
        }

        $row = $this->local_custom_actions->get( (int) $existing['id'] );
        if ( ! $row )
        {
            return $this->local_custom_action_not_found_error();
        }

        return $this->prepare_item_for_response(
            [
                'action' => $this->format_local_custom_action_for_legacy_route( $row ),
                'quota'  => $this->local_custom_action_quota(),
            ]
        );
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function get_local_custom_action_from_request( WP_REST_Request $request ): array | WP_Error
    {
        if ( ! $this->local_custom_actions )
        {
            return $this->local_custom_actions_unavailable_error();
        }

        $action_id = absint( $request->get_param( 'id' ) );
        if ( $action_id <= 0 )
        {
            return $this->local_custom_action_not_found_error();
        }

        $row = $this->local_custom_actions->get( $action_id );
        return $row ?: $this->local_custom_action_not_found_error();
    }

    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function build_local_custom_action_row( array $payload, ?array $existing = null, ?int $template_id = null ): array
    {
        $definition = $this->build_local_definition_json( $payload, $existing );

        return [
            'external_id'          => is_array( $existing ) ? ( $existing['external_id'] ?? null ) : null,
            'template_id'          => $template_id,
            'code'                 => is_array( $existing ) ? ( $existing['code'] ?? '' ) : ( $payload['code'] ?? '' ),
            'display_name'         => $payload['display_name'] ?? ( $existing['display_name'] ?? '' ),
            'definition_json'      => $definition,
            'model_selection_json' => $this->build_local_model_selection_json( $payload, $definition, $existing ),
            'status'               => $payload['status'] ?? ( $existing['status'] ?? 'active' ),
        ];
    }

    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function build_local_definition_json( array $payload, ?array $existing = null ): array
    {
        $definition = is_array( $payload['definition'] ?? null ) ? $payload['definition'] : [];
        if ( [] === $definition && is_array( $existing['definition_json'] ?? null ) )
        {
            $definition = $existing['definition_json'];
        }

        if ( isset( $payload['description'] ) && null !== $payload['description'] )
        {
            $definition['description'] = sanitize_textarea_field( (string) $payload['description'] );
        }

        if ( ! empty( $payload['prompt_overrides'] ) && is_array( $payload['prompt_overrides'] ) )
        {
            $definition['prompt_overrides'] = $payload['prompt_overrides'];
        }

        $definition['action_kind']               = sanitize_key( (string) ( $payload['action_kind'] ?? ( $definition['action_kind'] ?? 'template_override' ) ) );
        $definition['version']                   = absint( $payload['definition_version'] ?? ( $definition['version'] ?? 1 ) );
        $definition['supported_execution_modes'] = is_array( $payload['supported_execution_modes'] ?? null )
            ? array_values( array_map( 'sanitize_key', $payload['supported_execution_modes'] ) )
            : ( $definition['supported_execution_modes'] ?? [ 'after_submission' ] );

        $output_contract = is_array( $payload['output_contract'] ?? null ) ? $payload['output_contract'] : null;
        if ( is_array( $output_contract['schema'] ?? null ) )
        {
            $definition['structured_output_schema'] = $output_contract['schema'];
        }

        return $definition;
    }

    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>      $definition
     * @param array<string, mixed>|null $existing
     *
     * @return array<string, mixed>
     */
    private function build_local_model_selection_json( array $payload, array $definition, ?array $existing = null ): array
    {
        $existing_selection = is_array( $existing['model_selection_json'] ?? null ) ? $existing['model_selection_json'] : [];
        $model              = isset( $payload['model_hint'] ) && null !== $payload['model_hint'] && '' !== trim( (string) $payload['model_hint'] )
            ? sanitize_text_field( (string) $payload['model_hint'] )
            : sanitize_text_field( (string) ( $existing_selection['model'] ?? $definition['model'] ?? 'openrouter/auto' ) );

        $selection = [
            'provider' => sanitize_key( (string) ( $existing_selection['provider'] ?? $definition['provider'] ?? 'openrouter' ) ),
            'model'    => '' !== $model ? $model : 'openrouter/auto',
        ];

        $credential_id = isset( $existing_selection['credential_id'] ) ? absint( $existing_selection['credential_id'] ) : $this->find_default_local_openrouter_credential_id();
        if ( $credential_id > 0 )
        {
            $selection['credential_id'] = $credential_id;
        }

        return $selection;
    }

    private function find_default_local_openrouter_credential_id(): int
    {
        if ( ! $this->local_provider_credentials )
        {
            return 0;
        }

        $credential = $this->local_provider_credentials->find_by_provider_auth_mode( 'openrouter', 'manual_key' );
        if ( ! is_array( $credential ) || ! in_array( (string) ( $credential['status'] ?? '' ), [ 'valid', 'limited' ], true ) )
        {
            return 0;
        }

        return absint( $credential['id'] ?? 0 );
    }

    /**
     * @return array<string, int>
     */
    private function local_custom_action_quota(): array
    {
        $quota_max = max( 1, (int) apply_filters( 'sentient_forms_local_custom_action_quota_max', 999 ) );
        $active    = $this->local_custom_actions ? count( $this->local_custom_actions->list( 'active' ) ) : 0;

        return [
            'quota_max'       => $quota_max,
            'quota_used'      => $active,
            'quota_remaining' => max( 0, $quota_max - $active ),
        ];
    }

    /**
     * @param array<string, mixed> $row Local custom-action table row.
     *
     * @return array<string, mixed>
     */
    private function format_local_custom_action_for_legacy_route( array $row ): array
    {
        $definition = is_array( $row['definition_json'] ?? null ) ? $row['definition_json'] : [];
        $prompt_overrides = is_array( $definition['prompt_overrides'] ?? null ) ? $definition['prompt_overrides'] : [];
        $supported_modes  = is_array( $definition['supported_execution_modes'] ?? null )
            ? array_values( array_filter( array_map( 'sanitize_key', $definition['supported_execution_modes'] ) ) )
            : [ 'validation', 'after_submission' ];

        return [
            'id'                        => (string) (int) ( $row['id'] ?? 0 ),
            'template_id'               => isset( $row['template_id'] ) ? (string) (int) $row['template_id'] : '',
            'code'                      => isset( $row['code'] ) ? sanitize_key( (string) $row['code'] ) : '',
            'display_name'              => isset( $row['display_name'] ) ? sanitize_text_field( (string) $row['display_name'] ) : '',
            'description'               => isset( $definition['description'] ) && is_scalar( $definition['description'] )
                ? sanitize_text_field( (string) $definition['description'] )
                : null,
            'prompt_overrides'          => $prompt_overrides,
            'model_hint'                => isset( $row['model_selection_json']['model'] ) && is_scalar( $row['model_selection_json']['model'] )
                ? sanitize_text_field( (string) $row['model_selection_json']['model'] )
                : null,
            'base_credit_cost'          => null,
            'status'                    => isset( $row['status'] ) ? sanitize_key( (string) $row['status'] ) : 'active',
            'archived_at'               => null,
            'created_at'                => isset( $row['created_at'] ) ? sanitize_text_field( (string) $row['created_at'] ) : '',
            'updated_at'                => isset( $row['updated_at'] ) ? sanitize_text_field( (string) $row['updated_at'] ) : '',
            'action_kind'               => isset( $definition['action_kind'] ) && is_scalar( $definition['action_kind'] ) ? sanitize_key( (string) $definition['action_kind'] ) : 'template_override',
            'definition'                => $definition,
            'definition_version'        => isset( $definition['version'] ) ? absint( $definition['version'] ) : 1,
            'output_contract'           => isset( $definition['structured_output_schema'] ) && is_array( $definition['structured_output_schema'] )
                ? [ 'schema' => $definition['structured_output_schema'] ]
                : null,
            'supported_execution_modes' => ! empty( $supported_modes ) ? $supported_modes : [ 'after_submission' ],
        ];
    }

    private function local_custom_actions_unavailable_error(): WP_Error
    {
        return new WP_Error(
            'sentient_forms_local_custom_actions_unavailable',
            __( 'Local custom actions are not available in this plugin build.', 'sentient-forms' ),
            [ 'status' => 503 ]
        );
    }

    private function local_custom_action_not_found_error(): WP_Error
    {
        return new WP_Error(
            'sentient_forms_local_custom_action_not_found',
            __( 'Local custom action could not be found.', 'sentient-forms' ),
            [ 'status' => 404 ]
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_collection_params_args(): array
    {
        return [
            'status'           => [
                'description'       => __( 'Filter by status (active|archived).', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'include_archived' => [
                'description'       => __( 'Whether archived items should be included.', 'sentient-forms' ),
                'type'              => 'boolean',
                'required'          => false,
                'sanitize_callback' => 'rest_sanitize_boolean',
            ],
            'template_id'      => [
                'description'       => __( 'Filter by template UUID.', 'sentient-forms' ),
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_create_args(): array
    {
        return [
            'template_id'      => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
                'description'       => __( 'Identifier of the base action template.', 'sentient-forms' ),
            ],
            'code'             => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => [ $this, 'sanitize_code' ],
            ],
            'display_name'     => [
                'type'              => 'string',
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'description'      => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'prompt_overrides' => [
                'description'       => __( 'JSON object of prompt overrides.', 'sentient-forms' ),
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_prompt_overrides' ],
            ],
            'model_hint'       => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'action_kind'      => [
                'type'              => 'string',
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_action_kind' ],
                'description'       => __( 'Action kind: template_override or custom_definition.', 'sentient-forms' ),
            ],
            'definition'       => [
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_json_object_or_null' ],
                'description'       => __( 'Optional custom action definition object.', 'sentient-forms' ),
            ],
            'definition_version' => [
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_definition_version' ],
                'description'       => __( 'Definition schema version (>= 1).', 'sentient-forms' ),
            ],
            'output_contract'  => [
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_json_object_or_null' ],
                'description'       => __( 'Optional structured output contract object.', 'sentient-forms' ),
            ],
            'supported_execution_modes' => [
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_supported_execution_modes' ],
                'description'       => __( 'Allowed execution modes for this action.', 'sentient-forms' ),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function get_update_args(): array
    {
        $args = $this->get_create_args();
        unset( $args['template_id'], $args['code'] );

        return $args;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function build_create_payload( WP_REST_Request $request ): array | WP_Error
    {
        $prompt_overrides = $this->sanitize_prompt_overrides( $request->get_param( 'prompt_overrides' ) );
        if ( is_wp_error( $prompt_overrides ) )
        {
            return $prompt_overrides;
        }

        $action_kind = $this->sanitize_action_kind( $request->get_param( 'action_kind' ) ?? 'template_override' );
        if ( is_wp_error( $action_kind ) )
        {
            return $action_kind;
        }

        $definition = $this->sanitize_json_object_or_null( $request->get_param( 'definition' ) );
        if ( is_wp_error( $definition ) )
        {
            return $definition;
        }

        $definition_version = $this->sanitize_definition_version( $request->get_param( 'definition_version' ) ?? 1 );
        if ( is_wp_error( $definition_version ) )
        {
            return $definition_version;
        }

        $output_contract = $this->sanitize_json_object_or_null( $request->get_param( 'output_contract' ) );
        if ( is_wp_error( $output_contract ) )
        {
            return $output_contract;
        }

        $supported_execution_modes = $this->sanitize_supported_execution_modes(
            $request->get_param( 'supported_execution_modes' ) ?? [ 'after_submission' ]
        );
        if ( is_wp_error( $supported_execution_modes ) )
        {
            return $supported_execution_modes;
        }

        $definition_validation = $this->validate_definition_for_action_kind( $action_kind, $definition );
        if ( is_wp_error( $definition_validation ) )
        {
            return $definition_validation;
        }

        $code = $this->sanitize_code( $request->get_param( 'code' ) );
        if ( empty( $code ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'Code must contain letters, numbers, or dashes.', 'sentient-forms' )
            );
        }

        return [
            'template_id'      => sanitize_text_field( (string) $request->get_param( 'template_id' ) ),
            'code'             => $code,
            'display_name'     => sanitize_text_field( (string) $request->get_param( 'display_name' ) ),
            'description'      => $request->get_param( 'description' ) ? sanitize_textarea_field( (string) $request->get_param( 'description' ) ) : null,
            'prompt_overrides' => $prompt_overrides,
            'model_hint'       => $request->get_param( 'model_hint' ) ? sanitize_text_field( (string) $request->get_param( 'model_hint' ) ) : null,
            'action_kind'      => $action_kind,
            'definition'       => $definition,
            'definition_version' => $definition_version,
            'output_contract'  => $output_contract,
            'supported_execution_modes' => $supported_execution_modes,
        ];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function build_update_payload( WP_REST_Request $request ): array | WP_Error
    {
        $prompt_overrides = $this->sanitize_prompt_overrides( $request->get_param( 'prompt_overrides' ) );
        if ( is_wp_error( $prompt_overrides ) )
        {
            return $prompt_overrides;
        }

        $action_kind = $this->sanitize_action_kind( $request->get_param( 'action_kind' ) ?? 'template_override' );
        if ( is_wp_error( $action_kind ) )
        {
            return $action_kind;
        }

        $definition = $this->sanitize_json_object_or_null( $request->get_param( 'definition' ) );
        if ( is_wp_error( $definition ) )
        {
            return $definition;
        }

        $definition_version = $this->sanitize_definition_version( $request->get_param( 'definition_version' ) ?? 1 );
        if ( is_wp_error( $definition_version ) )
        {
            return $definition_version;
        }

        $output_contract = $this->sanitize_json_object_or_null( $request->get_param( 'output_contract' ) );
        if ( is_wp_error( $output_contract ) )
        {
            return $output_contract;
        }

        $supported_execution_modes = $this->sanitize_supported_execution_modes(
            $request->get_param( 'supported_execution_modes' ) ?? [ 'after_submission' ]
        );
        if ( is_wp_error( $supported_execution_modes ) )
        {
            return $supported_execution_modes;
        }

        $definition_validation = $this->validate_definition_for_action_kind( $action_kind, $definition );
        if ( is_wp_error( $definition_validation ) )
        {
            return $definition_validation;
        }

        return [
            'display_name'     => sanitize_text_field( (string) $request->get_param( 'display_name' ) ),
            'description'      => $request->get_param( 'description' ) ? sanitize_textarea_field( (string) $request->get_param( 'description' ) ) : null,
            'prompt_overrides' => $prompt_overrides,
            'model_hint'       => $request->get_param( 'model_hint' ) ? sanitize_text_field( (string) $request->get_param( 'model_hint' ) ) : null,
            'action_kind'      => $action_kind,
            'definition'       => $definition,
            'definition_version' => $definition_version,
            'output_contract'  => $output_contract,
            'supported_execution_modes' => $supported_execution_modes,
        ];
    }

    /**
     * @param mixed $value
     */
    public function sanitize_prompt_overrides( $value ): array | WP_Error
    {
        if ( null === $value || '' === $value )
        {
            return [];
        }

        if ( is_string( $value ) )
        {
            $decoded = json_decode( $value, true );
            if ( JSON_ERROR_NONE !== json_last_error() )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    __( 'Prompt overrides must be valid JSON.', 'sentient-forms' ),
                    400,
                );
            }
            $value = $decoded;
        }

        if ( is_object( $value ) )
        {
            $value = json_decode( wp_json_encode( $value ), true );
        }

        if ( !is_array( $value ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'Prompt overrides must be an object.', 'sentient-forms' ),
                400,
            );
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    public function sanitize_action_kind( $value ): string | WP_Error
    {
        $kind = sanitize_text_field( (string) $value );
        if ( '' === $kind )
        {
            return 'template_override';
        }

        if ( in_array( $kind, [ 'template_override', 'custom_definition' ], true ) )
        {
            return $kind;
        }

        return $this->prepare_error_response(
            'rest_invalid_param',
            __( 'action_kind must be template_override or custom_definition.', 'sentient-forms' ),
            400,
        );
    }

    /**
     * @param mixed $value
     */
    public function sanitize_definition_version( $value ): int | WP_Error
    {
        $version = (int) $value;
        if ( $version < 1 )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'definition_version must be >= 1.', 'sentient-forms' ),
                400,
            );
        }

        return $version;
    }

    /**
     * @param mixed $value
     */
    public function sanitize_supported_execution_modes( $value ): array | WP_Error
    {
        if ( null === $value || '' === $value )
        {
            return [ 'after_submission' ];
        }

        if ( is_string( $value ) )
        {
            $decoded = json_decode( $value, true );
            if ( JSON_ERROR_NONE === json_last_error() )
            {
                $value = $decoded;
            }
            else
            {
                $value = [ $value ];
            }
        }

        if ( is_object( $value ) )
        {
            $value = json_decode( wp_json_encode( $value ), true );
        }

        if ( ! is_array( $value ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'supported_execution_modes must be an array of strings.', 'sentient-forms' ),
                400,
            );
        }

        $allowed   = [ 'validation', 'after_submission', 'real_time' ];
        $sanitized = [];

        foreach ( $value as $mode )
        {
            $mode = sanitize_text_field( (string) $mode );
            if ( '' === $mode )
            {
                continue;
            }

            if ( ! in_array( $mode, $allowed, true ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    __( 'supported_execution_modes contains an unsupported mode.', 'sentient-forms' ),
                    400,
                );
            }

            if ( ! in_array( $mode, $sanitized, true ) )
            {
                $sanitized[] = $mode;
            }
        }

        if ( empty( $sanitized ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'supported_execution_modes must include at least one mode.', 'sentient-forms' ),
                400,
            );
        }

        return $sanitized;
    }

    /**
     * @param mixed $value
     */
    public function sanitize_json_object_or_null( $value ): array | null | WP_Error
    {
        if ( null === $value || '' === $value )
        {
            return null;
        }

        if ( is_string( $value ) )
        {
            $decoded = json_decode( $value, true );
            if ( JSON_ERROR_NONE !== json_last_error() )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    __( 'JSON object value must be valid JSON.', 'sentient-forms' ),
                    400,
                );
            }

            $value = $decoded;
        }

        if ( is_object( $value ) )
        {
            $value = json_decode( wp_json_encode( $value ), true );
        }

        if ( ! is_array( $value ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'Value must be a JSON object.', 'sentient-forms' ),
                400,
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $definition
     */
    private function validate_definition_for_action_kind( string $action_kind, ?array $definition ): true | WP_Error
    {
        if ( 'custom_definition' !== $action_kind )
        {
            return true;
        }

        if ( null === $definition )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'definition is required when action_kind is custom_definition.', 'sentient-forms' ),
                400,
            );
        }

        if ( ! array_key_exists( 'workflow', $definition ) )
        {
            return true;
        }

        if ( ! is_array( $definition['workflow'] ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'definition.workflow must be an object when provided.', 'sentient-forms' ),
                400,
            );
        }

        return $this->validate_workflow_definition( $definition['workflow'] );
    }

    /**
     * @param array<string, mixed> $workflow
     */
    private function validate_workflow_definition( array $workflow ): true | WP_Error
    {
        if ( array_key_exists( 'version', $workflow ) )
        {
            $version = (int) $workflow['version'];
            if ( $version < 1 )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    __( 'definition.workflow.version must be >= 1.', 'sentient-forms' ),
                    400,
                );
            }
        }

        if ( ! array_key_exists( 'nodes', $workflow ) || ! is_array( $workflow['nodes'] ) || empty( $workflow['nodes'] ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'definition.workflow.nodes must be a non-empty array.', 'sentient-forms' ),
                400,
            );
        }

        $allowed_kinds = [ 'llm_step', 'transform_step', 'decision_step' ];
        $node_ids      = [];

        foreach ( $workflow['nodes'] as $index => $node )
        {
            if ( ! is_array( $node ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    sprintf(
                        /* translators: %d: workflow node index. */
                        __( 'definition.workflow.nodes[%d] must be an object.', 'sentient-forms' ),
                        $index
                    ),
                    400,
                );
            }

            $node_id = sanitize_text_field( (string) ( $node['node_id'] ?? '' ) );
            if ( '' === trim( $node_id ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    sprintf(
                        /* translators: %d: workflow node index. */
                        __( 'definition.workflow.nodes[%d].node_id must be a non-empty string.', 'sentient-forms' ),
                        $index
                    ),
                    400,
                );
            }

            if ( in_array( $node_id, $node_ids, true ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    sprintf(
                        /* translators: %s: workflow node id. */
                        __( "Duplicate workflow node_id '%s'.", 'sentient-forms' ),
                        $node_id
                    ),
                    400,
                );
            }
            $node_ids[] = $node_id;

            $kind = sanitize_text_field( (string) ( $node['kind'] ?? '' ) );
            if ( ! in_array( $kind, $allowed_kinds, true ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    sprintf(
                        /* translators: %d: workflow node index. */
                        __( 'definition.workflow.nodes[%d].kind must be llm_step, transform_step, or decision_step.', 'sentient-forms' ),
                        $index
                    ),
                    400,
                );
            }

            $output_key = sanitize_text_field( (string) ( $node['output_key'] ?? '' ) );
            if ( '' === trim( $output_key ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    sprintf(
                        /* translators: %d: workflow node index. */
                        __( 'definition.workflow.nodes[%d].output_key must be a non-empty string.', 'sentient-forms' ),
                        $index
                    ),
                    400,
                );
            }

            if ( 'llm_step' === $kind )
            {
                $prompt_template = trim( (string) ( $node['prompt_template'] ?? '' ) );
                if ( '' === $prompt_template )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        sprintf(
                            /* translators: %d: workflow node index. */
                            __( "definition.workflow.nodes[%d].prompt_template is required for kind='llm_step'.", 'sentient-forms' ),
                            $index
                        ),
                        400,
                    );
                }
            }

            if ( array_key_exists( 'timeout_ms', $node ) )
            {
                $timeout_ms = (int) $node['timeout_ms'];
                if ( $timeout_ms < 1 )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        sprintf(
                            /* translators: %d: workflow node index. */
                            __( 'definition.workflow.nodes[%d].timeout_ms must be >= 1.', 'sentient-forms' ),
                            $index
                        ),
                        400,
                    );
                }
            }
        }

        if ( array_key_exists( 'edges', $workflow ) )
        {
            if ( ! is_array( $workflow['edges'] ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    __( 'definition.workflow.edges must be an array when provided.', 'sentient-forms' ),
                    400,
                );
            }

            foreach ( $workflow['edges'] as $index => $edge )
            {
                if ( ! is_array( $edge ) )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        sprintf(
                            /* translators: %d: workflow edge index. */
                            __( 'definition.workflow.edges[%d] must be an object.', 'sentient-forms' ),
                            $index
                        ),
                        400,
                    );
                }

                $from = sanitize_text_field( (string) ( $edge['from'] ?? '' ) );
                $to   = sanitize_text_field( (string) ( $edge['to'] ?? '' ) );

                if ( '' === trim( $from ) || '' === trim( $to ) )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        sprintf(
                            /* translators: %d: workflow edge index. */
                            __( 'definition.workflow.edges[%d].from and .to must be non-empty strings.', 'sentient-forms' ),
                            $index
                        ),
                        400,
                    );
                }

                if ( $from === $to )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        sprintf(
                            /* translators: 1: workflow edge index, 2: workflow node id. */
                            __( "definition.workflow.edges[%1\$d] cannot be self-referential ('%2\$s').", 'sentient-forms' ),
                            $index,
                            $from
                        ),
                        400,
                    );
                }

                if ( ! in_array( $from, $node_ids, true ) )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        sprintf(
                            /* translators: 1: workflow edge index, 2: workflow source node id. */
                            __( "definition.workflow.edges[%1\$d].from references unknown node_id '%2\$s'.", 'sentient-forms' ),
                            $index,
                            $from
                        ),
                        400,
                    );
                }

                if ( ! in_array( $to, $node_ids, true ) )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        sprintf(
                            /* translators: 1: workflow edge index, 2: workflow target node id. */
                            __( "definition.workflow.edges[%1\$d].to references unknown node_id '%2\$s'.", 'sentient-forms' ),
                            $index,
                            $to
                        ),
                        400,
                    );
                }
            }
        }

        if ( array_key_exists( 'max_parallelism', $workflow ) )
        {
            $max_parallelism = (int) $workflow['max_parallelism'];
            if ( $max_parallelism < 1 || $max_parallelism > 16 )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    __( 'definition.workflow.max_parallelism must be between 1 and 16.', 'sentient-forms' ),
                    400,
                );
            }
        }

        if ( array_key_exists( 'retry_policy', $workflow ) )
        {
            if ( ! is_array( $workflow['retry_policy'] ) )
            {
                return $this->prepare_error_response(
                    'rest_invalid_param',
                    __( 'definition.workflow.retry_policy must be an object when provided.', 'sentient-forms' ),
                    400,
                );
            }

            $retry_policy = $workflow['retry_policy'];
            if ( array_key_exists( 'max_attempts', $retry_policy ) )
            {
                $max_attempts = (int) $retry_policy['max_attempts'];
                if ( $max_attempts < 1 )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        __( 'definition.workflow.retry_policy.max_attempts must be >= 1.', 'sentient-forms' ),
                        400,
                    );
                }
            }

            if ( array_key_exists( 'backoff_ms', $retry_policy ) )
            {
                if ( ! is_array( $retry_policy['backoff_ms'] ) )
                {
                    return $this->prepare_error_response(
                        'rest_invalid_param',
                        __( 'definition.workflow.retry_policy.backoff_ms must be an array.', 'sentient-forms' ),
                        400,
                    );
                }

                foreach ( $retry_policy['backoff_ms'] as $index => $value )
                {
                    $backoff = (int) $value;
                    if ( $backoff < 0 )
                    {
                        return $this->prepare_error_response(
                            'rest_invalid_param',
                            sprintf(
                                /* translators: %d: retry backoff index. */
                                __( 'definition.workflow.retry_policy.backoff_ms[%d] must be >= 0.', 'sentient-forms' ),
                                $index
                            ),
                            400,
                        );
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     */
    public function sanitize_code( $value ): string
    {
        $value = (string) $value;
        $value = strtolower( $value );
        $value = preg_replace( '/[^a-z0-9\-]/', '', $value ?? '' );

        return (string) $value;
    }

    private function build_actor_hint(): string
    {
        $user_id = get_current_user_id();
        if ( $user_id )
        {
            return 'wp_user:' . $user_id;
        }

        return 'wp_user:0';
    }
}
