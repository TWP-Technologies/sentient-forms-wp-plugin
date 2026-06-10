<?php
/**
 * REST API Meta Controller class for the Sentient Forms plugin.
 * Handles routes related to plugin metadata and capabilities.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.6.0
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Meta_Controller
 * Manages REST API endpoints for plugin metadata and capabilities.
 */
class Sentient_Forms_Meta_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'meta';

    private Sentient_Forms_Admin_Permission $permission_checker;

    public function __construct()
    {
        parent::__construct();

        if ( ! class_exists( 'Sentient_Forms_Admin_Permission' ) )
        {
            Sentient_Forms_Error_Utils::throw_or_die(
                'Required dependency Sentient_Forms_Admin_Permission not found.',
                Sentient_Forms_Error_Type::dependency,
            );
        }

        $this->permission_checker = new Sentient_Forms_Admin_Permission();
    }

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/capabilities',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_capabilities' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );
    }

    /**
     * Get plugin capabilities and feature flags.
     *
     * @param WP_REST_Request $request The REST request object.
     * @return WP_Error|WP_REST_Response
     */
    public function get_capabilities( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin       = Sentient_Forms_Plugin::instance();
        $license_data = $plugin->get_license_data();

        // Determine capabilities based on license status and local-first route availability.
        $capabilities = [
            'supports_custom_actions' => $this->supports_custom_actions( $license_data ),
            'supports_status'         => true,
            'supports_credits'        => false,
            'cps_version'             => $this->get_cps_version(),
        ];

        /**
         * Filter the capabilities response.
         *
         * @param array $capabilities The capabilities array.
         * @param array $license_data Current license data.
         */
        $capabilities = apply_filters( 'sentient_forms_capabilities', $capabilities, $license_data );

        return $this->prepare_item_for_response( $capabilities );
    }

    /**
     * Get the CPS version if available (cached from last health check or activation).
     *
     * @return string|null
     */
    private function get_cps_version(): ?string
    {
        return get_transient( 'sentient_forms_cps_version' ) ?: null;
    }

    private function supports_custom_actions( array $license_data ): bool
    {
        $legacy_cps_custom_actions = (bool) apply_filters( 'sentient_forms_enable_legacy_cps_custom_actions', false );
        if ( ! $legacy_cps_custom_actions )
        {
            return class_exists( 'Sentient_Forms_Local_Custom_Actions_Repository' );
        }

        return ! empty( $license_data['proxy_api_key'] );
    }

    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => 'capabilities',
            'description' => __( 'Plugin capabilities and feature flags.', 'sentient-forms' ),
            'type'        => 'object',
            'properties'  => [
                'supports_custom_actions' => [
                    'description' => __( 'Whether custom actions are available.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
                'supports_status' => [
                    'description' => __( 'Whether status endpoints are available.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
                'supports_credits' => [
                    'description' => __( 'Whether the legacy credit balance route is available.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
                'cps_version' => [
                    'description' => __( 'CPS server version if known.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->schema;
    }
}
