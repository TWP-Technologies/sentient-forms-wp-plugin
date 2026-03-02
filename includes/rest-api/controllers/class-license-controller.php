<?php
/**
 * REST API License Controller class for the Sentient Forms plugin.
 * Handles routes related to managing the plugin's license.
 *
 * @package    SentientForms
 * @subpackage REST_API\Controllers
 * @since      0.1.0
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_License_Controller
 * Manages REST API endpoints for plugin license.
 */
class Sentient_Forms_License_Controller extends Abstract_Sentient_Forms_Base_Controller
{
    use Trait_Sentient_Forms_Permission_Utils;

    protected string $rest_base = 'license';

    private const LICENSE_KEY_REGEX_PATTERN = '/^[0-7][0-9a-hA-Hj-kJ-Km-nM-Np-tP-Tv-zV-Z]{25}$/';

    private const CPS_BASE_URL_FILTER = 'sentient_forms_cps_api_base_url';

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
            '/' . $this->rest_base,
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_license_info' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
                'schema' => [ $this, 'get_item_schema' ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/activate',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'activate_license' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'license_key' => [
                            'required'          => true,
                            'type'              => 'string',
                            'description'       => __( 'The license key to activate.', 'sentient-forms' ),
                            'sanitize_callback' => 'sanitize_text_field',
                            'validate_callback' => [ $this, 'validate_license_key_format' ],
                        ],
                        'site_url'    => [
                            'required'          => false,
                            'type'              => 'string',
                            'description'       => __( 'Optional site URL override.', 'sentient-forms' ),
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/deactivate',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'deactivate_license' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bootstrap',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'bootstrap_license' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'site_url' => [
                            'required'          => false,
                            'type'              => 'string',
                            'description'       => __( 'Optional site URL override.', 'sentient-forms' ),
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/billing-state',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [ $this, 'get_billing_state' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/billing/checkout-session',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'create_checkout_session' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'price_id' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'plan_code' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'success_url' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                        'cancel_url' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                        'quantity' => [
                            'required' => false,
                            'type'     => 'integer',
                            'default'  => 1,
                        ],
                        'trial_period_days' => [
                            'required' => false,
                            'type'     => 'integer',
                        ],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/billing/portal-session',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'create_portal_session' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'return_url' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                    ],
                ],
            ],
        );
    }

    public function get_license_info( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $license_data = Sentient_Forms_Plugin::instance()->get_license_data();

        return $this->prepare_item_for_response( $this->format_license_response( $license_data ) );
    }

    public function activate_license( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin      = Sentient_Forms_Plugin::instance();
        $license_key = $request->get_param( 'license_key' );
        $site_url    = $request->get_param( 'site_url' );
        $site_url    = ! empty( $site_url ) ? esc_url_raw( $site_url ) : home_url();

        $client   = $this->get_licensing_client();
        $response = $client->activate_license(
            $license_key,
            $site_url,
            $plugin->get_local_site_identifier(),
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        $payload = $this->normalize_activation_payload( $response );

        $plugin->set_license_data(
            [
                'license_key'    => $license_key,
                'license_status' => $payload['status'] ?? 'active',
                'license_id'     => $payload['license_id'] ?? '',
                'site_id'        => $payload['site_id'] ?? '',
                'proxy_api_key'  => $payload['proxy_api_key'] ?? '',
                'tier'           => $payload['tier'] ?? '',
                'expiry_date'    => $payload['expiry_date'] ?? null,
                'last_synced'    => current_time( 'mysql' ),
            ]
        );

        return $this->prepare_item_for_response(
            $this->format_license_response( $plugin->get_license_data() ),
            200
        );
    }

    public function deactivate_license( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin       = Sentient_Forms_Plugin::instance();
        $license_data = $plugin->get_license_data();

        if ( empty( $license_data['proxy_api_key'] ) )
        {
            return $this->prepare_error_response(
                'no_active_license',
                __( 'No active license to deactivate.', 'sentient-forms' ),
                400,
            );
        }

        $client   = $this->get_licensing_client();
        $response = $client->deactivate_license(
            $license_data['proxy_api_key'],
            $license_data['license_id'],
            $license_data['site_id'],
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        $plugin->clear_license_data();

        return $this->prepare_item_for_response(
            [
                'success' => true,
                'message' => __( 'License deactivated successfully.', 'sentient-forms' ),
                'status'  => 'inactive',
            ]
        );
    }

    public function bootstrap_license( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $plugin       = Sentient_Forms_Plugin::instance();
        $license_data = $plugin->get_license_data();

        if ( ! empty( $license_data['proxy_api_key'] ) && 'active' === ( $license_data['license_status'] ?? 'inactive' ) )
        {
            return $this->prepare_item_for_response(
                $this->format_license_response( $license_data ),
                200
            );
        }

        $site_url = $request->get_param( 'site_url' );
        $site_url = ! empty( $site_url ) ? esc_url_raw( $site_url ) : home_url();

        $client   = $this->get_licensing_client();
        $response = $client->bootstrap_license(
            $site_url,
            $plugin->get_local_site_identifier(),
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        $payload = $this->normalize_activation_payload( $response );

        $plugin->set_license_data(
            [
                'license_key'    => $license_data['license_key'] ?? '',
                'license_status' => $payload['status'] ?? 'active',
                'license_id'     => $payload['license_id'] ?? '',
                'site_id'        => $payload['site_id'] ?? '',
                'proxy_api_key'  => $payload['proxy_api_key'] ?? '',
                'tier'           => $payload['tier'] ?? '',
                'expiry_date'    => $payload['expiry_date'] ?? null,
                'last_synced'    => current_time( 'mysql' ),
            ]
        );

        return $this->prepare_item_for_response(
            $this->format_license_response( $plugin->get_license_data() ),
            200
        );
    }

    public function get_billing_state( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $proxy_key = $this->require_proxy_key();
        if ( is_wp_error( $proxy_key ) )
        {
            return $proxy_key;
        }

        $client   = $this->get_licensing_client();
        $response = $client->get_billing_state( $proxy_key );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        return $this->prepare_item_for_response(
            $this->normalize_activation_payload( $response ),
            200
        );
    }

    public function create_checkout_session( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $proxy_key = $this->require_proxy_key();
        if ( is_wp_error( $proxy_key ) )
        {
            return $proxy_key;
        }

        $payload = [
            'success_url'       => (string) $request->get_param( 'success_url' ),
            'cancel_url'        => (string) $request->get_param( 'cancel_url' ),
            'quantity'          => max( 1, (int) $request->get_param( 'quantity' ) ),
        ];
        $price_id = trim( (string) $request->get_param( 'price_id' ) );
        if ( '' !== $price_id )
        {
            $payload['price_id'] = $price_id;
        }

        $plan_code = sanitize_key( (string) $request->get_param( 'plan_code' ) );
        if ( '' !== $plan_code )
        {
            $payload['plan_code'] = $plan_code;
        }

        if ( empty( $payload['price_id'] ) && empty( $payload['plan_code'] ) )
        {
            return $this->prepare_error_response(
                'invalid_request',
                __( 'price_id or plan_code is required.', 'sentient-forms' ),
                400,
            );
        }

        $trial_days = $request->get_param( 'trial_period_days' );
        if ( null !== $trial_days && '' !== $trial_days )
        {
            $payload['trial_period_days'] = max( 0, (int) $trial_days );
        }

        $client   = $this->get_licensing_client();
        $response = $client->create_checkout_session( $proxy_key, $payload );
        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        return $this->prepare_item_for_response(
            $this->normalize_activation_payload( $response ),
            200
        );
    }

    public function create_portal_session( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $proxy_key = $this->require_proxy_key();
        if ( is_wp_error( $proxy_key ) )
        {
            return $proxy_key;
        }

        $return_url = (string) $request->get_param( 'return_url' );
        $client     = $this->get_licensing_client();
        $response   = $client->create_portal_session( $proxy_key, $return_url );
        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        return $this->prepare_item_for_response(
            $this->normalize_activation_payload( $response ),
            200
        );
    }

    public function validate_license_key_format( mixed $value, WP_REST_Request $request, string $param ): true | WP_Error
    {
        if ( ! is_string( $value ) )
        {
            return new WP_Error( 'rest_invalid_param', sprintf( __( '%s must be a string.', 'sentient-forms' ), $param ), [ 'status' => 400 ] );
        }

        if ( empty( $value ) || ! preg_match( self::LICENSE_KEY_REGEX_PATTERN, $value ) )
        {
            return new WP_Error( 'rest_invalid_format', sprintf( __( '%s has an invalid format.', 'sentient-forms' ), $param ), [ 'status' => 400 ] );
        }

        return true;
    }

    public function get_item_schema(): ?array
    {
        if ( $this->schema )
        {
            return $this->schema;
        }

        $this->schema = [
            '$schema'     => 'https://json-schema.org/draft/2020-12/schema',
            'title'       => $this->rest_base,
            'description' => __( 'Plugin license information.', 'sentient-forms' ),
            'type'        => 'object',
            'properties'  => [
                'status'             => [
                    'description' => __( 'Current license status.', 'sentient-forms' ),
                    'type'        => 'string',
                    'readonly'    => true,
                ],
                'license_key_masked' => [
                    'description' => __( 'Masked license key.', 'sentient-forms' ),
                    'type'        => 'string',
                    'readonly'    => true,
                ],
                'proxy_key_present'  => [
                    'description' => __( 'Whether a proxy API key is stored.', 'sentient-forms' ),
                    'type'        => 'boolean',
                    'readonly'    => true,
                ],
                'tier'               => [
                    'description' => __( 'Active subscription tier.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'readonly'    => true,
                ],
                'expires_at'         => [
                    'description' => __( 'License expiry timestamp.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'format'      => 'date-time',
                    'readonly'    => true,
                ],
                'last_synced'        => [
                    'description' => __( 'Timestamp of the latest successful sync.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'format'      => 'date-time',
                    'readonly'    => true,
                ],
                'license_id'         => [
                    'description' => __( 'CPS license identifier.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'readonly'    => true,
                ],
                'site_id'            => [
                    'description' => __( 'CPS site identifier.', 'sentient-forms' ),
                    'type'        => [ 'string', 'null' ],
                    'readonly'    => true,
                ],
                'site_url'           => [
                    'description' => __( 'WordPress site URL used during activation.', 'sentient-forms' ),
                    'type'        => 'string',
                    'readonly'    => true,
                ],
            ],
        ];

        return $this->schema;
    }

    private function get_licensing_client(): Sentient_Forms_Licensing_Api_Client
    {
        $default = 'https://api.sentientforms.com/v1';
        $base    = apply_filters( self::CPS_BASE_URL_FILTER, $default );

        return new Sentient_Forms_Licensing_Api_Client( $base );
    }

    private function format_license_response( array $license_data ): array
    {
        $license_key = $license_data['license_key'] ?? '';
        $masked_key  = '';

        if ( ! empty( $license_key ) )
        {
            $masked_key = strlen( $license_key ) > 8
                ? substr( $license_key, 0, 4 ) . str_repeat( '*', strlen( $license_key ) - 8 ) . substr( $license_key, -4 )
                : str_repeat( '*', strlen( $license_key ) );
        }

        return [
            'status'             => $license_data['license_status'] ?? 'inactive',
            'license_key_masked' => $masked_key,
            'proxy_key_present'  => ! empty( $license_data['proxy_api_key'] ),
            'tier'               => $license_data['tier'] ?: null,
            'expires_at'         => $license_data['expiry_date'] ?: null,
            'last_synced'        => $license_data['last_synced'] ?: null,
            'license_id'         => $license_data['license_id'] ?: null,
            'site_id'            => $license_data['site_id'] ?: null,
            'site_url'           => home_url(),
        ];
    }

    private function normalize_activation_payload( array $payload ): array
    {
        if ( isset( $payload['data'] ) && is_array( $payload['data'] ) )
        {
            return $payload['data'];
        }

        return $payload;
    }

    private function require_proxy_key(): WP_Error | string
    {
        $license_data = Sentient_Forms_Plugin::instance()->get_license_data();
        $proxy_key    = isset( $license_data['proxy_api_key'] ) ? trim( (string) $license_data['proxy_api_key'] ) : '';

        if ( '' === $proxy_key )
        {
            return $this->prepare_error_response(
                'no_active_license',
                __( 'No active license to manage billing for.', 'sentient-forms' ),
                400,
            );
        }

        return $proxy_key;
    }

    private function prepare_cps_error( WP_Error $error ): WP_Error
    {
        $code    = $error->get_error_code() ?: 'license_activation_failed';
        $message = $error->get_error_message() ?: __( 'Licensing request failed.', 'sentient-forms' );
        $data    = $error->get_error_data();
        $status  = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;

        return $this->prepare_error_response( $code, $message, $status, is_array( $data ) ? $data : [] );
    }
}
