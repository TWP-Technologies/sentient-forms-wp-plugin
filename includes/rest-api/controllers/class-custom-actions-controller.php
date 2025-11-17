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

    public function __construct()
    {
        parent::__construct();
        $this->service = new Sentient_Forms_Custom_Actions_Service( Sentient_Forms_Plugin::instance() );
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
            '/' . $this->rest_base . '/(?P<id>[a-f0-9-]{8,})',
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
            '/' . $this->rest_base . '/(?P<id>[a-f0-9-]{8,})/reactivate',
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
        $action_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
        $result    = $this->service->reactivate( $action_id, $this->build_actor_hint() );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $result->to_array() );
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
                'description'       => __( 'UUID of the CPS action template.', 'sentient-forms' ),
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
            'base_credit_cost' => [
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => [ $this, 'sanitize_credit_cost' ],
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

        $base_credit_cost = $this->sanitize_credit_cost( $request->get_param( 'base_credit_cost' ) );
        if ( is_wp_error( $base_credit_cost ) )
        {
            return $base_credit_cost;
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
            'base_credit_cost' => $base_credit_cost,
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

        $base_credit_cost = $this->sanitize_credit_cost( $request->get_param( 'base_credit_cost' ) );
        if ( is_wp_error( $base_credit_cost ) )
        {
            return $base_credit_cost;
        }

        return [
            'display_name'     => sanitize_text_field( (string) $request->get_param( 'display_name' ) ),
            'description'      => $request->get_param( 'description' ) ? sanitize_textarea_field( (string) $request->get_param( 'description' ) ) : null,
            'prompt_overrides' => $prompt_overrides,
            'model_hint'       => $request->get_param( 'model_hint' ) ? sanitize_text_field( (string) $request->get_param( 'model_hint' ) ) : null,
            'base_credit_cost' => $base_credit_cost,
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
    public function sanitize_credit_cost( $value ): int | null | WP_Error
    {
        if ( null === $value || '' === $value )
        {
            return null;
        }

        if ( !is_numeric( $value ) )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'Base credit cost must be numeric.', 'sentient-forms' ),
                400,
            );
        }

        $int_value = (int) $value;
        if ( $int_value < 0 )
        {
            return $this->prepare_error_response(
                'rest_invalid_param',
                __( 'Base credit cost must be positive.', 'sentient-forms' ),
                400,
            );
        }

        return $int_value;
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
