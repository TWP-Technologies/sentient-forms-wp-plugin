<?php
/**
 * REST API Site Context Controller class for the Sentient Forms plugin.
 * Handles routes related to managing site context for personalized spam detection.
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
 * Class Sentient_Forms_Site_Context_Controller
 * Manages REST API endpoints for site context (CB-SA-001).
 */
class Sentient_Forms_Site_Context_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'site-context';

    /**
     * Registers the routes for the site context controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // GET /site-context - Get current site context
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_context' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [ $this, 'create_context' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'site_url' => [
                            'description'       => __( 'Site URL for context generation.', 'sentient-forms' ),
                            'type'              => 'string',
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                        'pii_ack' => [
                            'description'       => __( 'Acknowledge PII will be sent to LLM.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'required'          => true,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_context' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'summary_text' => [
                            'description'       => __( 'Updated summary text.', 'sentient-forms' ),
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_textarea_field',
                        ],
                        'auto_include' => [
                            'description'       => __( 'Auto-include in spam prompts.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                        'pii_ack' => [
                            'description'       => __( 'PII acknowledgment.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Gets the current site context from CPS.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_context( WP_REST_Request $request ): WP_Error | WP_REST_Response
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

        $client   = new Sentient_Forms_Llm_Api_Client( $api_key );
        $response = $client->get( '/v1/site-context' );

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
     * Creates or regenerates site context.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function create_context( WP_REST_Request $request ): WP_Error | WP_REST_Response
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
            'site_url' => $request->get_param( 'site_url' ) ?? get_site_url(),
            'pii_ack'  => $request->get_param( 'pii_ack' ),
        ];

        $client   = new Sentient_Forms_Llm_Api_Client( $api_key );
        $response = $client->post( '/v1/site-context', $body );

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
     * Updates site context (manual edit).
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function update_context( WP_REST_Request $request ): WP_Error | WP_REST_Response
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

        $body = [];
        if ( $request->has_param( 'summary_text' ) )
        {
            $body['summary_text'] = $request->get_param( 'summary_text' );
        }
        if ( $request->has_param( 'auto_include' ) )
        {
            $body['auto_include'] = $request->get_param( 'auto_include' );
        }
        if ( $request->has_param( 'pii_ack' ) )
        {
            $body['pii_ack'] = $request->get_param( 'pii_ack' );
        }

        $client   = new Sentient_Forms_Llm_Api_Client( $api_key );
        $response = $client->put( '/v1/site-context', $body );

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
     * Retrieves the schema for the site context response.
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
                'id' => [
                    'description' => __( 'Site context ID.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'summary_text' => [
                    'description' => __( 'The site context summary text.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view', 'edit' ],
                ],
                'source' => [
                    'description' => __( 'How context was created: llm_search, manual, import.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'auto_include' => [
                    'description' => __( 'Include context in spam detection prompts.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit' ],
                ],
                'pii_ack' => [
                    'description' => __( 'PII acknowledgment flag.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'context'     => [ 'view', 'edit' ],
                ],
            ],
        ];

        return $this->schema;
    }
}
