<?php
/**
 * REST API controller for the local diagnostic-event preference.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Telemetry_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'telemetry';

    private Sentient_Forms_Telemetry_Service $service;

    public function __construct()
    {
        parent::__construct();

        $plugin = Sentient_Forms_Plugin::instance();
        $this->service = $plugin->get_telemetry_service();
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_settings' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ]
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'update_settings' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'local_diagnostics_enabled' => [
                            'type'              => 'boolean',
                            'required'          => true,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                            'validate_callback' => 'rest_validate_request_arg',
                        ],
                    ],
                ],
            ]
        );
    }

    public function get_settings( WP_REST_Request $request ): WP_REST_Response
    {
        return $this->prepare_item_for_response( $this->format_response( $this->service->get_settings() ) );
    }

    public function update_settings( WP_REST_Request $request ): WP_REST_Response | WP_Error
    {
        $enabled = rest_sanitize_boolean( $request->get_param( 'local_diagnostics_enabled' ) );
        $result  = $this->service->update_preference( $enabled );

        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return $this->prepare_item_for_response( $this->format_response( $result ) );
    }

    private function format_response( array $settings ): array
    {
        return [
            'local_diagnostics_enabled' => ! empty( $settings['local_diagnostics_enabled'] ),
            'updated_at'                => $settings['updated_at'] ?? null,
        ];
    }
}
