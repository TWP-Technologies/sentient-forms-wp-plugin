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
class Sentient_Forms_License_Controller extends Sentient_Forms_Abstract_Base_Controller
{
    use Sentient_Forms_Permission_Utils_Trait;

    protected string $rest_base = 'license';

    private const LICENSE_KEY_REGEX_PATTERN = '/^[0-7][0-9a-hA-Hj-kJ-Km-nM-Np-tP-Tv-zV-Z]{25}$/';

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
                    'args'                => [
                        'force_refresh' => [
                            'description'       => __( 'Bypass the short-lived cached billing-state snapshot.', 'sentient-forms' ),
                            'type'              => 'boolean',
                            'required'          => false,
                            'default'           => false,
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/managed-checkout/start',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'start_managed_checkout' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'plan_code' => [
                            'required'          => true,
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
                        'disclosure_version' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'accepted_managed_service_terms' => [
                            'required'          => true,
                            'type'              => 'boolean',
                            'sanitize_callback' => 'rest_sanitize_boolean',
                        ],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/managed-checkout/complete',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'complete_managed_checkout' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'checkout_intent_id' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'checkout_session_id' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'activation_token' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
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
            '/' . $this->rest_base . '/billing/subscription-change',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'change_subscription' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'plan_code' => [
                            'required'          => true,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'change_timing' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                            'default'           => 'start_next_cycle',
                        ],
                        'quantity' => [
                            'required' => false,
                            'type'     => 'integer',
                            'default'  => 1,
                        ],
                        'recovery_return_url' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'esc_url_raw',
                        ],
                    ],
                ],
            ],
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/billing/top-up-session',
            [
                [
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => [ $this, 'create_top_up_checkout_session' ],
                    'permission_callback' => [ $this, 'permission_callback_with_nonce' ],
                    'args'                => [
                        'pack_code' => [
                            'required'          => true,
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
                        'flow_type' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_key',
                        ],
                        'subscription_id' => [
                            'required'          => false,
                            'type'              => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
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

        $client   = $this->get_managed_service_client();
        $response = $client->activate_site(
            [
                'license_key'           => $license_key,
                'site_url'              => $site_url,
                'local_site_identifier' => $plugin->get_local_site_identifier(),
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        $payload = $this->normalize_activation_payload( $response );
        $this->store_managed_activation_payload( $license_key, $payload );

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

        $site_id = $this->require_site_id();
        if ( is_wp_error( $site_id ) )
        {
            return $site_id;
        }

        $client   = $this->get_managed_service_client();
        $response = $client->deactivate_site(
            $license_data['proxy_api_key'],
            $site_id,
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

        if (
            ! empty( $license_data['proxy_api_key'] ) &&
            in_array( $license_data['license_status'] ?? 'inactive', [ 'active', 'trial' ], true )
        )
        {
            return $this->prepare_item_for_response(
                $this->format_license_response( $license_data ),
                200
            );
        }

        $license_key = isset( $license_data['license_key'] ) ? trim( (string) $license_data['license_key'] ) : '';
        if ( '' === $license_key )
        {
            return $this->prepare_item_for_response(
                $this->format_license_response( $license_data ),
                200
            );
        }
        if ( ! $this->has_managed_license_key_format( $license_key ) )
        {
            return $this->prepare_item_for_response(
                $this->format_license_response( $license_data ),
                200
            );
        }

        $site_url = $request->get_param( 'site_url' );
        $site_url = ! empty( $site_url ) ? esc_url_raw( $site_url ) : home_url();

        $client   = $this->get_managed_service_client();
        $response = $client->activate_site(
            [
                'license_key'           => $license_key,
                'site_url'              => $site_url,
                'local_site_identifier' => $plugin->get_local_site_identifier(),
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        $payload = $this->normalize_activation_payload( $response );
        $this->store_managed_activation_payload( $license_key, $payload );

        return $this->prepare_item_for_response(
            $this->format_license_response( $plugin->get_license_data() ),
            200
        );
    }

    public function start_managed_checkout( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $accepted = rest_sanitize_boolean( $request->get_param( 'accepted_managed_service_terms' ) );
        if ( ! $accepted )
        {
            return $this->prepare_error_response(
                'sentient_managed_checkout_consent_required',
                __( 'Accept the Sentient Forms managed-service disclosure before starting checkout.', 'sentient-forms' ),
                400,
            );
        }

        $plan_code = sanitize_key( (string) $request->get_param( 'plan_code' ) );
        if ( ! in_array( $plan_code, [ 'starter', 'pro', 'business' ], true ) )
        {
            return $this->prepare_error_response(
                'sentient_managed_checkout_invalid_plan',
                __( 'Choose a valid managed-service plan.', 'sentient-forms' ),
                400,
            );
        }

        $disclosure_version = sanitize_text_field( (string) $request->get_param( 'disclosure_version' ) );
        $consent_id = $this->record_managed_service_consent(
            $disclosure_version,
            [
                'action'                => 'managed_checkout_start',
                'request_ip'            => $this->request_ip_hash(),
                'plan_code'             => $plan_code,
                'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                'require_zdr'           => true,
            ]
        );
        if ( is_wp_error( $consent_id ) )
        {
            return $consent_id;
        }

        $client = $this->get_managed_service_client();
        $response = $client->start_managed_checkout(
            [
                'plan_code'                      => $plan_code,
                'billing_interval'               => sanitize_key( (string) ( $request->get_param( 'billing_interval' ) ?: 'monthly' ) ),
                'site_url'                       => home_url(),
                'local_site_identifier'          => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                'success_url'                    => (string) $request->get_param( 'success_url' ),
                'cancel_url'                     => (string) $request->get_param( 'cancel_url' ),
                'disclosure_version'             => $disclosure_version,
                'accepted_managed_service_terms' => true,
                'require_zdr'                    => true,
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        $payload                      = $this->normalize_activation_payload( $response );
        $payload['consent_recorded']  = true;
        $payload['consent_id']        = $consent_id;
        $payload['disclosure_version'] = $disclosure_version;

        return $this->prepare_item_for_response( $payload, 200 );
    }

    public function complete_managed_checkout( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $client = $this->get_managed_service_client();
        $response = $client->complete_managed_checkout(
            [
                'site_url'              => home_url(),
                'local_site_identifier' => Sentient_Forms_Plugin::instance()->get_local_site_identifier(),
                'checkout_intent_id'    => (string) $request->get_param( 'checkout_intent_id' ),
                'checkout_session_id'   => (string) $request->get_param( 'checkout_session_id' ),
                'activation_token'      => (string) $request->get_param( 'activation_token' ),
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        $payload = $this->normalize_activation_payload( $response );
        if ( ! empty( $payload['activation_ready'] ) && ! empty( $payload['proxy_api_key'] ) )
        {
            $license_key = isset( $payload['license_key'] ) && is_scalar( $payload['license_key'] )
                ? sanitize_text_field( (string) $payload['license_key'] )
                : '';

            $this->store_managed_activation_payload( $license_key, $payload );

            $credential_id = $this->ensure_sentient_managed_provider_credential( Sentient_Forms_Plugin::instance()->get_license_data() );
            if ( is_wp_error( $credential_id ) )
            {
                return $credential_id;
            }

            $payload['credential_id']          = $credential_id;
            $payload['managed_provider_ready'] = true;
        }

        return $this->prepare_item_for_response( $payload, 200 );
    }

    public function get_billing_state( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $proxy_key = $this->require_proxy_key();
        if ( is_wp_error( $proxy_key ) )
        {
            return $proxy_key;
        }

        $cache_key     = $this->billing_state_cache_key( $proxy_key );
        $force_refresh = rest_sanitize_boolean( $request->get_param( 'force_refresh' ) );
        if ( ! $force_refresh )
        {
            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) )
            {
                return $this->prepare_item_for_response( $cached, 200 );
            }
        }

        $client   = $this->get_managed_service_client( 5 );
        $response = $client->get_billing_state( $proxy_key );

        if ( is_wp_error( $response ) )
        {
            if ( $this->is_billing_state_connectivity_error( $response ) )
            {
                $stale = get_transient( $this->billing_state_stale_cache_key( $proxy_key ) );
                if ( is_array( $stale ) )
                {
                    $stale['stale']           = true;
                    $stale['last_error_code'] = $response->get_error_code();
                    return $this->prepare_item_for_response( $stale, 200 );
                }
            }

            return $this->prepare_cps_error( $response );
        }

        $payload = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_billing_state(
            $this->normalize_activation_payload( $response )
        );
        $payload['cached_at'] = gmdate( 'c' );
        $payload['stale']     = false;

        set_transient( $cache_key, $payload, MINUTE_IN_SECONDS );
        set_transient( $this->billing_state_stale_cache_key( $proxy_key ), $payload, DAY_IN_SECONDS );

        $this->sync_cached_license_from_billing_state( $payload );

        return $this->prepare_item_for_response(
            $payload,
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

        $client   = $this->get_managed_service_client();
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

    public function create_top_up_checkout_session( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $proxy_key = $this->require_proxy_key();
        if ( is_wp_error( $proxy_key ) )
        {
            return $proxy_key;
        }

        $payload = [
            'pack_code'   => sanitize_key( (string) $request->get_param( 'pack_code' ) ),
            'success_url' => (string) $request->get_param( 'success_url' ),
            'cancel_url'  => (string) $request->get_param( 'cancel_url' ),
            'quantity'    => max( 1, (int) $request->get_param( 'quantity' ) ),
        ];

        $client   = $this->get_managed_service_client();
        $billing_state = $client->get_billing_state( $proxy_key );
        if ( is_wp_error( $billing_state ) )
        {
            return $this->prepare_cps_error( $billing_state );
        }

        $billing_state = Sentient_Forms_Managed_Usage_Sanitizer::sanitize_billing_state( $billing_state );
        $this->sync_cached_license_from_billing_state( $billing_state );

        if ( ! $this->billing_state_allows_top_up_checkout( $billing_state ) )
        {
            return $this->prepare_error_response(
                'managed_top_up_business_required',
                __( 'Top-up capacity packs are available only for active Business managed-service subscriptions.', 'sentient-forms' ),
                403,
            );
        }

        $response = $client->create_top_up_checkout_session( $proxy_key, $payload );
        if ( is_wp_error( $response ) )
        {
            return $this->prepare_cps_error( $response );
        }

        return $this->prepare_item_for_response(
            $this->normalize_activation_payload( $response ),
            200
        );
    }

    public function change_subscription( WP_REST_Request $request ): WP_Error | WP_REST_Response
    {
        $proxy_key = $this->require_proxy_key();
        if ( is_wp_error( $proxy_key ) )
        {
            return $proxy_key;
        }

        return $this->prepare_error_response(
            'managed_subscription_change_uses_portal',
            __( 'Plan changes are handled through the managed billing portal in the local-first service.', 'sentient-forms' ),
            410,
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
        $flow_type  = trim( (string) $request->get_param( 'flow_type' ) );
        $subscription_id = trim( (string) $request->get_param( 'subscription_id' ) );
        $client     = $this->get_managed_service_client();
        $response   = $client->create_portal_session(
            $proxy_key,
            $return_url,
            '' !== $flow_type ? $flow_type : null,
            '' !== $subscription_id ? $subscription_id : null,
        );
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
            return new WP_Error(
                'rest_invalid_param',
                sprintf(
                    /* translators: %s: REST parameter name. */
                    __( '%s must be a string.', 'sentient-forms' ),
                    $param
                ),
                [ 'status' => 400 ]
            );
        }

        if ( empty( $value ) || ! $this->has_managed_license_key_format( $value ) )
        {
            return new WP_Error(
                'rest_invalid_format',
                sprintf(
                    /* translators: %s: REST parameter name. */
                    __( '%s has an invalid format.', 'sentient-forms' ),
                    $param
                ),
                [ 'status' => 400 ]
            );
        }

        return true;
    }

    private function has_managed_license_key_format( string $license_key ): bool
    {
        return 1 === preg_match( self::LICENSE_KEY_REGEX_PATTERN, $license_key );
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

    private function get_managed_service_client( int $timeout = 30 ): Sentient_Forms_Managed_Service_Client
    {
        return new Sentient_Forms_Managed_Service_Client( null, $timeout );
    }

    private function billing_state_cache_key( string $proxy_key ): string
    {
        return 'sentient_forms_billing_state_' . md5( $proxy_key );
    }

    private function billing_state_stale_cache_key( string $proxy_key ): string
    {
        return 'sentient_forms_billing_state_stale_' . md5( $proxy_key );
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

    private function store_managed_activation_payload( string $license_key, array $payload ): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_key'    => $license_key,
                'license_status' => $payload['status'] ?? 'active',
                'license_id'     => $payload['license_id'] ?? '',
                'site_id'        => $payload['site_id'] ?? '',
                'proxy_api_key'  => $payload['proxy_api_key'] ?? '',
                'tier'           => $payload['tier'] ?? '',
                'expiry_date'    => $payload['expiry_date'] ?? $payload['expires_at'] ?? null,
                'last_synced'    => current_time( 'mysql' ),
            ]
        );
    }

    private function ensure_sentient_managed_provider_credential( array $license_data ): int | WP_Error
    {
        global $wpdb;

        $status_json = [
            'license_id'        => $license_data['license_id'] ?? '',
            'site_id'           => $license_data['site_id'] ?? '',
            'license_status'    => $license_data['license_status'] ?? 'active',
            'proxy_key_present' => '' !== trim( (string) ( $license_data['proxy_api_key'] ?? '' ) ),
            'billing_boundary'  => [
                'direct_openrouter_billed_by_sentient' => false,
                'managed_proxy_billed_by_sentient'     => true,
            ],
        ];

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $existing    = $credentials->find_by_provider_auth_mode( 'sentient_managed', 'sentient_proxy' );
        if ( is_array( $existing ) )
        {
            $updated = $credentials->update_status( (int) $existing['id'], 'valid', $status_json );
            if ( is_wp_error( $updated ) )
            {
                return $updated;
            }

            $this->repair_custom_action_model_selection_after_provider_change();
            return (int) $existing['id'];
        }

        $credential_id = $credentials->create(
            [
                'provider'          => 'sentient_managed',
                'label'             => __( 'Sentient Forms managed service', 'sentient-forms' ),
                'auth_mode'         => 'sentient_proxy',
                'status'            => 'valid',
                'status_json'       => $status_json,
                'last_validated_at' => gmdate( 'Y-m-d H:i:s' ),
            ]
        );

        if ( is_wp_error( $credential_id ) )
        {
            return $credential_id;
        }

        $this->repair_custom_action_model_selection_after_provider_change();
        return (int) $credential_id;
    }

    private function repair_custom_action_model_selection_after_provider_change(): void
    {
        if ( ! class_exists( 'Sentient_Forms_Local_Action_Model_Selection_Service' ) )
        {
            return;
        }

        global $wpdb;

        $service = new Sentient_Forms_Local_Action_Model_Selection_Service(
            new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ),
            new Sentient_Forms_Provider_Credentials_Repository( $wpdb ),
            new Sentient_Forms_Form_Mappings_Repository( $wpdb )
        );
        $service->repair_all_custom_actions();
    }

    private function record_managed_service_consent( string $disclosure_version, array $metadata ): int | WP_Error
    {
        global $wpdb;

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );

        return $consents->record(
            'sentient_managed',
            $disclosure_version,
            get_current_user_id() ?: null,
            $metadata
        );
    }

    private function request_ip_hash(): ?string
    {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        if ( '' === $ip )
        {
            return null;
        }

        return hash( 'sha256', $ip );
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

    private function require_site_id(): WP_Error | string
    {
        $license_data = Sentient_Forms_Plugin::instance()->get_license_data();
        $site_id      = isset( $license_data['site_id'] ) ? trim( (string) $license_data['site_id'] ) : '';

        if ( '' === $site_id )
        {
            return $this->prepare_error_response(
                'no_active_site',
                __( 'No active managed site to deactivate.', 'sentient-forms' ),
                400,
            );
        }

        return $site_id;
    }

    private function sync_cached_license_from_billing_state( array $payload ): void
    {
        $updates = [];

        $license_status = $payload['license_status'] ?? $payload['status'] ?? null;
        if (
            ! is_string( $license_status ) &&
            isset( $payload['account'] ) &&
            is_array( $payload['account'] ) &&
            isset( $payload['account']['license_status'] ) &&
            is_string( $payload['account']['license_status'] )
        )
        {
            $license_status = $payload['account']['license_status'];
        }

        if ( is_string( $license_status ) )
        {
            $updates['license_status'] = $license_status;
        }

        $tier = $payload['tier'] ?? $payload['plan'] ?? null;
        if (
            null === $tier &&
            isset( $payload['account'] ) &&
            is_array( $payload['account'] ) &&
            isset( $payload['account']['tier'] )
        )
        {
            $tier = $payload['account']['tier'];
        }

        if ( is_array( $tier ) || is_string( $tier ) )
        {
            $updates['tier'] = $tier;
        }

        if ( empty( $updates ) )
        {
            return;
        }

        $updates['last_synced'] = current_time( 'mysql' );
        Sentient_Forms_Plugin::instance()->set_license_data( $updates );
    }

    private function billing_state_allows_top_up_checkout( array $payload ): bool
    {
        return 'business' === $this->extract_billing_tier_code( $payload )
            && 'active' === $this->extract_billing_subscription_status( $payload );
    }

    private function extract_billing_tier_code( array $payload ): string
    {
        $tier = $payload['tier'] ?? $payload['plan'] ?? null;
        if (
            null === $tier &&
            isset( $payload['account'] ) &&
            is_array( $payload['account'] ) &&
            isset( $payload['account']['tier'] )
        )
        {
            $tier = $payload['account']['tier'];
        }

        if ( is_array( $tier ) && isset( $tier['code'] ) && is_string( $tier['code'] ) )
        {
            return sanitize_key( $tier['code'] );
        }

        return is_string( $tier ) ? sanitize_key( $tier ) : '';
    }

    private function extract_billing_subscription_status( array $payload ): string
    {
        $subscription = $payload['subscription'] ?? null;
        if (
            null === $subscription &&
            isset( $payload['billing'] ) &&
            is_array( $payload['billing'] ) &&
            isset( $payload['billing']['subscription'] )
        )
        {
            $subscription = $payload['billing']['subscription'];
        }

        if ( is_array( $subscription ) && isset( $subscription['status'] ) && is_string( $subscription['status'] ) )
        {
            return sanitize_key( $subscription['status'] );
        }

        return '';
    }

    private function is_billing_state_connectivity_error( WP_Error $error ): bool
    {
        return in_array(
            $error->get_error_code(),
            [
                'http_request_failed',
                'http_request_timeout',
                'request_timeout',
                'timeout',
            ],
            true
        );
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
