<?php
/**
 * REST API Execution Status Controller class for the Sentient Forms plugin.
 * Handles routes related to retrieving execution status from CPS.
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
 * Class Sentient_Forms_Execution_Status_Controller
 * Manages REST API endpoints for execution status (CB-STATUS-001).
 */
class Sentient_Forms_Execution_Status_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'execution-status';

    /**
     * Registers the routes for the execution status controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        // GET /execution-status - List recent statuses
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'list_statuses' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ],
        );

        // GET /execution-status/{mapping_id}/{entry_id} - Get specific status
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<mapping_id>[a-f0-9-]+)/(?P<entry_id>\d+)',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_status' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'mapping_id' => [
                            'description'       => __( 'The CPS mapping UUID.', 'sentient-forms' ),
                            'type'              => 'string',
                            'required'          => true,
                            'pattern'           => '[a-f0-9-]+',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'entry_id' => [
                            'description'       => __( 'The WordPress entry ID.', 'sentient-forms' ),
                            'type'              => 'integer',
                            'required'          => true,
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * Lists recent execution statuses for the authenticated license.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function list_statuses( WP_REST_Request $request ): WP_Error | WP_REST_Response
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
            '/execution-status',
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
     * Gets execution status for a specific mapping and entry.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_status( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin    = Sentient_Forms_Plugin::instance();
        $api_key   = $plugin->get_proxy_api_key();
        $mappingId = $request->get_param( 'mapping_id' );
        $entryId   = $request->get_param( 'entry_id' );

        if ( empty( $api_key ) )
        {
            return $this->prepare_error_response(
                'missing_api_key',
                __( 'Proxy API key is not configured.', 'sentient-forms' ),
                400,
            );
        }

        $client   = $plugin->get_cps_api_client();
        $endpoint = sprintf( '/execution-status/%s/%d', rawurlencode( (string) $mappingId ), (int) $entryId );
        $response = $client->get(
            $endpoint,
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
     * Retrieves the schema for the execution status response.
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
                    'description' => __( 'Execution status ID.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'mapping_id' => [
                    'description' => __( 'The CPS mapping UUID.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'entry_id' => [
                    'description' => __( 'The WordPress entry ID.', 'sentient-forms' ),
                    'type'        => 'integer',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'status' => [
                    'description' => __( 'Execution status: queued, running, blocked, succeeded, failed, cancelled.', 'sentient-forms' ),
                    'type'        => 'string',
                    'enum'        => [ 'unknown', 'queued', 'running', 'blocked', 'succeeded', 'failed', 'cancelled' ],
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'result_summary' => [
                    'description' => __( 'Brief result summary for display.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
                'error_message' => [
                    'description' => __( 'Error message if status is failed.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->schema;
    }
}
