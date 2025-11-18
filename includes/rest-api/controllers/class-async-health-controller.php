<?php
/**
 * REST API controller for async health summaries.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Async_Health_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    protected string $rest_base = 'async-health';

    public function __construct()
    {
        parent::__construct();
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_health' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );
    }

    public function get_health( WP_REST_Request $request ): WP_REST_Response
    {
        $payload = Sentient_Forms_Plugin::instance()->get_async_health_service()->evaluate();
        return $this->prepare_item_for_response( $payload );
    }
}
