<?php
/**
 * REST API Credit Controller class for the Sentient Forms plugin.
 * Handles compatibility for the retired Sentient credit balance route.
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
 * Class Sentient_Forms_Credit_Controller
 * Manages the retired credit balance endpoint.
 */
class Sentient_Forms_Credit_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;
    /**
     * The base of this controller's routes.
     *
     * @var string
     * @since 0.1.0
     */
    protected string $rest_base = 'credits';

    /**
     * Registers the routes for the credit controller.
     *
     * @since 0.1.0
     */
    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/balance',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_credit_balance' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'force_refresh' => [
                            'description'       => __( 'Legacy compatibility flag; ignored because credit balance is retired.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'default'           => false,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );
    }

    /**
     * Reports that the legacy credit balance route is retired.
     *
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function get_credit_balance( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        return $this->prepare_error_response(
            'sentient_forms_credit_balance_retired',
            __( 'The legacy Sentient credit balance route is retired in local-first mode. Use the Licensing billing-state route for managed plan allowance; direct OpenRouter runs are billed by OpenRouter, not Sentient.', 'sentient-forms' ),
            410,
        );
    }

    /**
     * Retrieves the schema for the credit balance response.
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
            'title'      => $this->rest_base . '/balance',
            'type'       => 'object',
            'properties' => [
                'message' => [
                    'description' => __( 'Compatibility message for retired credit balance callers.', 'sentient-forms' ),
                    'type'        => 'string',
                    'context'     => [ 'view' ],
                    'readonly'    => true,
                ],
            ],
            'required'   => [ 'message' ],
        ];

        return $this->schema;
    }
}
