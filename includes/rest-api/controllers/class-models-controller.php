<?php
/**
 * REST API Models Controller class for the Sentient Forms plugin.
 * Handles routes related to model catalog and selection.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Models_Controller
 * Manages REST API endpoints for model catalog and selection (CB-MODEL-*).
 */
class Sentient_Forms_Models_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'models';

    /**
     * Registers the routes for the models controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // GET /models - List available models and presets
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'list_models' ],
                'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
            ],
        );

        // POST /models/resolve - Resolve model based on override chain
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/resolve',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'resolve_model' ],
                'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                'args'                => [
                    'global_selection' => [
                        'description'       => __( 'Global model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'action_selection' => [
                        'description'       => __( 'Action-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'form_selection' => [
                        'description'       => __( 'Form-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'mapping_selection' => [
                        'description'       => __( 'Mapping-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                ],
            ],
        );

        // POST /models/estimate - Resolve model and return a pricing estimate
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/estimate',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'estimate_model' ],
                'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                'args'                => [
                    'action_id' => [
                        'description'       => __( 'Action identifier used for pricing lookup.', 'sentient-forms' ),
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'template_model_hint' => [
                        'description'       => __( 'Template-level model hint fallback.', 'sentient-forms' ),
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'base_credit_cost' => [
                        'description'       => __( 'Fallback base credit cost when the action template is not found in CPS.', 'sentient-forms' ),
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'global_selection' => [
                        'description'       => __( 'Global model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'action_selection' => [
                        'description'       => __( 'Action-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'form_selection' => [
                        'description'       => __( 'Form-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                    'mapping_selection' => [
                        'description'       => __( 'Mapping-level model selection.', 'sentient-forms' ),
                        'type'              => 'object',
                        'sanitize_callback' => 'rest_sanitize_request_arg',
                    ],
                ],
            ],
        );
    }

    /**
     * Lists available models and presets from CPS.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function list_models( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin  = Sentient_Forms_Plugin::instance();
        $api_key = $plugin->get_proxy_api_key();

        if ( empty( $api_key ) )
        {
            return $this->prepare_error_response(
                'missing_api_key',
                __( 'Proxy API key is not configured.', 'sentient-forms' ),
                400,
            );
        }

        $client   = $plugin->get_cps_api_client();
        $response = $client->get(
            '/models',
            [
                'bearer_token' => $api_key,
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_error_response(
                'cps_error',
                $response->get_error_message(),
                is_array( $response->get_error_data() ) && isset( $response->get_error_data()['status'] )
                    ? (int) $response->get_error_data()['status']
                    : 500,
            );
        }

        return $this->prepare_item_for_response( $response );
    }

    /**
     * Resolves model based on override chain.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function resolve_model( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin  = Sentient_Forms_Plugin::instance();
        $api_key = $plugin->get_proxy_api_key();

        if ( empty( $api_key ) )
        {
            return $this->prepare_error_response(
                'missing_api_key',
                __( 'Proxy API key is not configured.', 'sentient-forms' ),
                400,
            );
        }

        $body = [
            'global_selection'  => $request->get_param( 'global_selection' ),
            'action_selection'  => $request->get_param( 'action_selection' ),
            'form_selection'    => $request->get_param( 'form_selection' ),
            'mapping_selection' => $request->get_param( 'mapping_selection' ),
        ];

        $client   = $plugin->get_cps_api_client();
        $response = $client->post(
            '/models/resolve',
            $body,
            [
                'bearer_token' => $api_key,
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_error_response(
                'cps_error',
                $response->get_error_message(),
                500,
            );
        }

        return $this->prepare_item_for_response( $response );
    }

    /**
     * Resolve pricing estimate based on the override chain.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function estimate_model( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin  = Sentient_Forms_Plugin::instance();
        $api_key = $plugin->get_proxy_api_key();

        if ( empty( $api_key ) )
        {
            return $this->prepare_error_response(
                'missing_api_key',
                __( 'Proxy API key is not configured.', 'sentient-forms' ),
                400,
            );
        }

        $body = [
            'action_id'           => $request->get_param( 'action_id' ),
            'template_model_hint' => $request->get_param( 'template_model_hint' ),
            'base_credit_cost'    => $request->get_param( 'base_credit_cost' ),
            'global_selection'    => $request->get_param( 'global_selection' ),
            'action_selection'    => $request->get_param( 'action_selection' ),
            'form_selection'      => $request->get_param( 'form_selection' ),
            'mapping_selection'   => $request->get_param( 'mapping_selection' ),
        ];

        $client   = $plugin->get_cps_api_client();
        $response = $client->post(
            '/models/estimate',
            $body,
            [
                'bearer_token' => $api_key,
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_error_response(
                'cps_error',
                $response->get_error_message(),
                is_array( $response->get_error_data() ) && isset( $response->get_error_data()['status'] )
                    ? (int) $response->get_error_data()['status']
                    : 500,
            );
        }

        return $this->prepare_item_for_response( $response );
    }

    /**
     * Retrieves the schema for the models response.
     *
     * @return array|null Item schema data.
     */
    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'    => 'https://json-schema.org/draft/2020-12/schema',
            'title'      => $this->rest_base,
            'type'       => 'object',
            'properties' => [
                'models' => [
                    'description' => __( 'Available models.', 'sentient-forms' ),
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'id'           => [ 'type' => 'string' ],
                            'display_name' => [ 'type' => 'string' ],
                            'provider'     => [ 'type' => 'string' ],
                            'speed_tier'   => [ 'type' => 'string' ],
                            'cost_tier'    => [ 'type' => 'string' ],
                        ],
                    ],
                ],
                'presets' => [
                    'description' => __( 'Available presets.', 'sentient-forms' ),
                    'type'        => 'array',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'code'              => [ 'type' => 'string' ],
                            'display_name'      => [ 'type' => 'string' ],
                            'description'       => [ 'type' => 'string' ],
                            'resolved_model_id' => [ 'type' => 'string' ],
                        ],
                    ],
                ],
            ],
        ];

        return $this->schema;
    }
}
