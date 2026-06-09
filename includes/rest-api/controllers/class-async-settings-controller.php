<?php
/**
 * REST API controller for async retry/backoff settings.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Async_Settings_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'async-settings';

    private Sentient_Forms_Async_Settings_Service $service;

    public function __construct()
    {
        parent::__construct();
        $this->service = Sentient_Forms_Plugin::instance()->get_async_settings_service();
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
                    'args'                => $this->get_update_args(),
                ],
            ]
        );
    }

    public function get_settings( WP_REST_Request $request ): WP_REST_Response
    {
        return $this->prepare_item_for_response( $this->format_response( $this->service->get_settings() ) );
    }

    public function update_settings( WP_REST_Request $request ): WP_REST_Response
    {
        $payload = [];
        foreach ( [ 'max_attempts', 'base_delay_seconds', 'max_delay_seconds' ] as $field )
        {
            if ( null !== $request->get_param( $field ) )
            {
                $payload[ $field ] = $request->get_param( $field );
            }
        }

        $actor = $this->build_actor_hint();
        $settings = $this->service->update_settings( $payload, $actor );

        return $this->prepare_item_for_response( $this->format_response( $settings ) );
    }

    private function get_update_args(): array
    {
        return [
            'max_attempts' => [
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
                'validate_callback' => [ $this, 'validate_positive_int' ],
                'minimum'           => 1,
            ],
            'base_delay_seconds' => [
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
                'validate_callback' => [ $this, 'validate_positive_int' ],
                'minimum'           => 5,
            ],
            'max_delay_seconds' => [
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
                'validate_callback' => [ $this, 'validate_positive_int' ],
                'minimum'           => 5,
            ],
        ];
    }

    public function validate_positive_int( $value ): bool
    {
        if ( ! is_numeric( $value ) )
        {
            return false;
        }

        return (int) $value >= 1;
    }

    private function format_response( array $settings ): array
    {
        return [
            'max_attempts'       => (int) $settings['max_attempts'],
            'base_delay_seconds' => (int) $settings['base_delay_seconds'],
            'max_delay_seconds'  => (int) $settings['max_delay_seconds'],
            'updated_at'         => $settings['updated_at'] ? gmdate( 'c', (int) $settings['updated_at'] ) : null,
            'updated_by'         => $settings['updated_by'] ?? null,
        ];
    }

    private function build_actor_hint(): string
    {
        $user_id = get_current_user_id();
        if ( $user_id )
        {
            return 'wp_user:' . $user_id;
        }

        return 'wp_cli';
    }
}
