<?php
/**
 * Sentient Forms managed-service client for account and billing endpoints.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Managed_Service_Client
{
    private const DEFAULT_BASE_URL = 'https://api.sentientforms.com/v2';

    private string $base_url;

    private Sentient_Forms_Api_Client $client;

    public function __construct(
        ?string $base_url = null,
        int $timeout = 30,
        ?Sentient_Forms_Api_Client $client = null
    )
    {
        $this->base_url = $this->resolve_base_url( $base_url );
        $this->client   = $client ?? new Sentient_Forms_Api_Client( $this->base_url, $timeout );
    }

    /**
     * Reserve the v2 site activation contract without using legacy /v1 licensing paths.
     *
     * @param array<string, mixed> $payload Activation payload.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function activate_site( array $payload ): array | WP_Error
    {
        $payload = $this->normalize_activation_payload( $payload );
        if ( is_wp_error( $payload ) )
        {
            return $payload;
        }

        return $this->client->post( '/account/sites/activate', $payload );
    }

    /**
     * Reserve the v2 site deactivation contract for an authenticated managed site.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function deactivate_site( string $proxy_api_key, string $site_id ): array | WP_Error
    {
        $proxy_api_key = $this->normalize_proxy_api_key( $proxy_api_key, 'sentient_managed_account_missing_proxy_key' );
        if ( is_wp_error( $proxy_api_key ) )
        {
            return $proxy_api_key;
        }

        $site_id = $this->normalize_site_id( $site_id );
        if ( is_wp_error( $site_id ) )
        {
            return $site_id;
        }

        return $this->client->post(
            '/account/sites/deactivate',
            [
                'site_id' => $site_id,
            ],
            [
                'bearer_token' => $proxy_api_key,
            ]
        );
    }

    /**
     * Fetch the v2 site account state for an authenticated managed site.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_site( string $proxy_api_key, string $site_id ): array | WP_Error
    {
        $proxy_api_key = $this->normalize_proxy_api_key( $proxy_api_key, 'sentient_managed_account_missing_proxy_key' );
        if ( is_wp_error( $proxy_api_key ) )
        {
            return $proxy_api_key;
        }

        $site_id = $this->normalize_site_id( $site_id );
        if ( is_wp_error( $site_id ) )
        {
            return $site_id;
        }

        return $this->client->get(
            '/account/sites/' . rawurlencode( $site_id ),
            [
                'bearer_token' => $proxy_api_key,
            ]
        );
    }

    /**
     * Create a managed-service Stripe Checkout session.
     *
     * @param array<string, mixed> $payload Checkout payload.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function create_checkout_session( string $proxy_api_key, array $payload ): array | WP_Error
    {
        $proxy_api_key = $this->normalize_proxy_api_key( $proxy_api_key, 'sentient_managed_billing_missing_proxy_key' );
        if ( is_wp_error( $proxy_api_key ) )
        {
            return $proxy_api_key;
        }

        $payload = $this->normalize_checkout_payload( $payload );
        if ( is_wp_error( $payload ) )
        {
            return $payload;
        }

        return $this->client->post(
            '/billing/checkout/session',
            $payload,
            [
                'bearer_token' => $proxy_api_key,
            ]
        );
    }

    /**
     * Start a first-time managed-service Stripe Checkout session.
     *
     * This route intentionally does not require a proxy key because it is the
     * path that creates the managed account for this WordPress site.
     *
     * @param array<string, mixed> $payload Checkout payload.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function start_managed_checkout( array $payload ): array | WP_Error
    {
        $payload = $this->normalize_managed_checkout_start_payload( $payload );
        if ( is_wp_error( $payload ) )
        {
            return $payload;
        }

        return $this->client->post( '/account/checkout/start', $payload );
    }

    /**
     * Complete a first-time managed-service checkout after Stripe returns.
     *
     * @param array<string, mixed> $payload Checkout completion payload.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function complete_managed_checkout( array $payload ): array | WP_Error
    {
        $payload = $this->normalize_managed_checkout_complete_payload( $payload );
        if ( is_wp_error( $payload ) )
        {
            return $payload;
        }

        return $this->client->post( '/account/checkout/complete', $payload );
    }

    /**
     * Create a managed-service Stripe Billing Portal session.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function create_portal_session(
        string $proxy_api_key,
        string $return_url,
        ?string $flow_type = null,
        ?string $subscription_id = null
    ): array | WP_Error
    {
        $proxy_api_key = $this->normalize_proxy_api_key( $proxy_api_key, 'sentient_managed_billing_missing_proxy_key' );
        if ( is_wp_error( $proxy_api_key ) )
        {
            return $proxy_api_key;
        }

        $return_url = $this->normalize_required_url( $return_url, 'return_url' );
        if ( is_wp_error( $return_url ) )
        {
            return $return_url;
        }

        $payload = [
            'return_url' => $return_url,
        ];

        if ( is_string( $flow_type ) && '' !== trim( $flow_type ) )
        {
            $payload['flow_type'] = sanitize_key( $flow_type );
        }

        if ( is_string( $subscription_id ) && '' !== trim( $subscription_id ) )
        {
            $payload['subscription_id'] = sanitize_text_field( $subscription_id );
        }

        return $this->client->post(
            '/billing/portal/session',
            $payload,
            [
                'bearer_token' => $proxy_api_key,
            ]
        );
    }

    /**
     * Fetch the managed billing state for the authenticated site.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function get_billing_state( string $proxy_api_key ): array | WP_Error
    {
        $proxy_api_key = $this->normalize_proxy_api_key( $proxy_api_key, 'sentient_managed_billing_missing_proxy_key' );
        if ( is_wp_error( $proxy_api_key ) )
        {
            return $proxy_api_key;
        }

        return $this->client->get(
            '/billing/state',
            [
                'bearer_token' => $proxy_api_key,
            ]
        );
    }

    public function get_base_url(): string
    {
        return $this->base_url;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_activation_payload( array $payload ): array | WP_Error
    {
        $normalized = [];

        foreach ( [ 'license_key', 'site_url', 'local_site_identifier' ] as $required_key )
        {
            if ( ! isset( $payload[ $required_key ] ) || ! is_scalar( $payload[ $required_key ] ) || '' === trim( (string) $payload[ $required_key ] ) )
            {
                return new WP_Error(
                    'sentient_managed_account_invalid_payload',
                    sprintf(
                        /* translators: %s: request field name. */
                        __( 'Managed account activation payload is missing %s.', 'sentient-forms' ),
                        $required_key
                    )
                );
            }
        }

        $normalized['license_key']           = sanitize_text_field( (string) $payload['license_key'] );
        $normalized['site_url']              = esc_url_raw( (string) $payload['site_url'] );
        $normalized['local_site_identifier'] = sanitize_text_field( (string) $payload['local_site_identifier'] );

        if ( '' === $normalized['site_url'] )
        {
            return new WP_Error(
                'sentient_managed_account_invalid_site_url',
                __( 'Managed account activation requires a valid site URL.', 'sentient-forms' )
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_checkout_payload( array $payload ): array | WP_Error
    {
        $normalized = [];

        foreach ( [ 'success_url', 'cancel_url' ] as $required_url )
        {
            if ( ! isset( $payload[ $required_url ] ) || ! is_scalar( $payload[ $required_url ] ) )
            {
                return new WP_Error(
                    'sentient_managed_billing_invalid_payload',
                    sprintf(
                        /* translators: %s: request field name. */
                        __( 'Managed billing checkout payload is missing %s.', 'sentient-forms' ),
                        $required_url
                    )
                );
            }

            $url = $this->normalize_required_url( (string) $payload[ $required_url ], $required_url );
            if ( is_wp_error( $url ) )
            {
                return $url;
            }

            $normalized[ $required_url ] = $url;
        }

        foreach ( [ 'price_id', 'plan_code', 'customer_email', 'customer_name' ] as $optional_text )
        {
            if ( isset( $payload[ $optional_text ] ) && is_scalar( $payload[ $optional_text ] ) && '' !== trim( (string) $payload[ $optional_text ] ) )
            {
                $normalized[ $optional_text ] = sanitize_text_field( (string) $payload[ $optional_text ] );
            }
        }

        if ( isset( $payload['quantity'] ) )
        {
            $normalized['quantity'] = max( 1, (int) $payload['quantity'] );
        }

        if ( isset( $payload['allow_promotion_codes'] ) )
        {
            $normalized['allow_promotion_codes'] = (bool) $payload['allow_promotion_codes'];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_managed_checkout_start_payload( array $payload ): array | WP_Error
    {
        $normalized = [];

        foreach ( [ 'plan_code', 'site_url', 'local_site_identifier', 'disclosure_version' ] as $required_key )
        {
            if ( ! isset( $payload[ $required_key ] ) || ! is_scalar( $payload[ $required_key ] ) || '' === trim( (string) $payload[ $required_key ] ) )
            {
                return new WP_Error(
                    'sentient_managed_checkout_invalid_payload',
                    sprintf(
                        /* translators: %s: request field name. */
                        __( 'Managed checkout payload is missing %s.', 'sentient-forms' ),
                        $required_key
                    )
                );
            }
        }

        foreach ( [ 'success_url', 'cancel_url' ] as $required_url )
        {
            if ( ! isset( $payload[ $required_url ] ) || ! is_scalar( $payload[ $required_url ] ) )
            {
                return new WP_Error(
                    'sentient_managed_checkout_invalid_payload',
                    sprintf(
                        /* translators: %s: request field name. */
                        __( 'Managed checkout payload is missing %s.', 'sentient-forms' ),
                        $required_url
                    )
                );
            }

            $url = $this->normalize_required_url( (string) $payload[ $required_url ], $required_url );
            if ( is_wp_error( $url ) )
            {
                return $url;
            }

            $normalized[ $required_url ] = $url;
        }

        $site_url = $this->normalize_required_url( (string) $payload['site_url'], 'site_url' );
        if ( is_wp_error( $site_url ) )
        {
            return $site_url;
        }

        $normalized['plan_code']                       = sanitize_key( (string) $payload['plan_code'] );
        $normalized['site_url']                        = $site_url;
        $normalized['local_site_identifier']           = sanitize_text_field( (string) $payload['local_site_identifier'] );
        $normalized['disclosure_version']              = sanitize_text_field( (string) $payload['disclosure_version'] );
        $normalized['accepted_managed_service_terms']  = ! empty( $payload['accepted_managed_service_terms'] );
        $normalized['require_zdr']                     = ! array_key_exists( 'require_zdr', $payload ) || (bool) $payload['require_zdr'];

        if ( ! $normalized['accepted_managed_service_terms'] )
        {
            return new WP_Error(
                'sentient_managed_checkout_consent_required',
                __( 'You must accept the Sentient Forms managed-service disclosure before checkout.', 'sentient-forms' )
            );
        }

        if ( ! in_array( $normalized['plan_code'], [ 'starter', 'pro', 'business' ], true ) )
        {
            return new WP_Error(
                'sentient_managed_checkout_invalid_plan',
                __( 'Choose a valid managed-service plan.', 'sentient-forms' )
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_managed_checkout_complete_payload( array $payload ): array | WP_Error
    {
        $normalized = [];

        foreach ( [ 'site_url', 'local_site_identifier' ] as $required_key )
        {
            if ( ! isset( $payload[ $required_key ] ) || ! is_scalar( $payload[ $required_key ] ) || '' === trim( (string) $payload[ $required_key ] ) )
            {
                return new WP_Error(
                    'sentient_managed_checkout_invalid_payload',
                    sprintf(
                        /* translators: %s: request field name. */
                        __( 'Managed checkout completion payload is missing %s.', 'sentient-forms' ),
                        $required_key
                    )
                );
            }
        }

        $site_url = $this->normalize_required_url( (string) $payload['site_url'], 'site_url' );
        if ( is_wp_error( $site_url ) )
        {
            return $site_url;
        }

        $normalized['site_url']              = $site_url;
        $normalized['local_site_identifier'] = sanitize_text_field( (string) $payload['local_site_identifier'] );

        foreach ( [ 'checkout_intent_id', 'checkout_session_id', 'activation_token' ] as $optional_text )
        {
            if ( isset( $payload[ $optional_text ] ) && is_scalar( $payload[ $optional_text ] ) && '' !== trim( (string) $payload[ $optional_text ] ) )
            {
                $normalized[ $optional_text ] = sanitize_text_field( (string) $payload[ $optional_text ] );
            }
        }

        if ( empty( $normalized['checkout_intent_id'] ) && empty( $normalized['checkout_session_id'] ) )
        {
            return new WP_Error(
                'sentient_managed_checkout_missing_reference',
                __( 'Managed checkout completion requires a checkout intent or Stripe session reference.', 'sentient-forms' )
            );
        }

        return $normalized;
    }

    private function normalize_required_url( string $url, string $field ): string | WP_Error
    {
        $url = esc_url_raw( trim( $url ) );
        if ( '' === $url )
        {
            return new WP_Error(
                'sentient_managed_invalid_url',
                sprintf(
                    /* translators: %s: request field name. */
                    __( 'Managed service request requires a valid %s.', 'sentient-forms' ),
                    $field
                )
            );
        }

        return $url;
    }

    private function normalize_site_id( string $site_id ): string | WP_Error
    {
        $site_id = sanitize_text_field( trim( $site_id ) );
        if ( '' === $site_id )
        {
            return new WP_Error(
                'sentient_managed_account_missing_site_id',
                __( 'Managed account requests require a site ID.', 'sentient-forms' )
            );
        }

        return $site_id;
    }

    private function normalize_proxy_api_key( string $proxy_api_key, string $error_code ): string | WP_Error
    {
        $proxy_api_key = trim( $proxy_api_key );
        if ( '' === $proxy_api_key )
        {
            return new WP_Error(
                $error_code,
                __( 'Sentient Forms managed-service requests require a site credential key.', 'sentient-forms' )
            );
        }

        return $proxy_api_key;
    }

    private function resolve_base_url( ?string $base_url ): string
    {
        $url = $base_url;
        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = $this->resolve_managed_service_override();
        }

        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = $this->resolve_proxy_api_override();
        }

        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = $this->resolve_cps_base_url_default();
        }

        if ( ! is_string( $url ) || '' === trim( $url ) )
        {
            $url = self::DEFAULT_BASE_URL;
        }

        $url = untrailingslashit( trim( $url ) );
        if ( str_ends_with( $url, '/v1' ) )
        {
            return substr( $url, 0, -3 ) . '/v2';
        }

        if ( ! str_ends_with( $url, '/v2' ) )
        {
            $url .= '/v2';
        }

        return $url;
    }

    private function resolve_managed_service_override(): ?string
    {
        $url = null;

        if ( defined( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' ) && is_string( constant( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' ) ) )
        {
            $url = constant( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        }
        elseif ( getenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' ) )
        {
            $url = (string) getenv( 'SENTIENT_FORMS_MANAGED_SERVICE_URL' );
        }

        $filtered = apply_filters( 'sentient_forms_managed_service_url', $url );
        if ( is_string( $filtered ) && '' !== trim( $filtered ) )
        {
            return $filtered;
        }

        return is_string( $url ) && '' !== trim( $url ) ? $url : null;
    }

    private function resolve_proxy_api_override(): ?string
    {
        $url = null;

        if ( defined( 'SENTIENT_FORMS_PROXY_API_URL' ) && is_string( constant( 'SENTIENT_FORMS_PROXY_API_URL' ) ) )
        {
            $url = constant( 'SENTIENT_FORMS_PROXY_API_URL' );
        }
        elseif ( getenv( 'SENTIENT_FORMS_PROXY_API_URL' ) )
        {
            $url = (string) getenv( 'SENTIENT_FORMS_PROXY_API_URL' );
        }

        $filtered = apply_filters( 'sentient_forms_proxy_api_url', $url );
        if ( is_string( $filtered ) && '' !== trim( $filtered ) )
        {
            return $filtered;
        }

        return is_string( $url ) && '' !== trim( $url ) ? $url : null;
    }

    private function resolve_cps_base_url_default(): string
    {
        $options  = get_option( 'sentient_forms_settings', [] );
        $options  = is_array( $options ) ? $options : [];
        $base_url = $options['cps_base_url'] ?? null;
        $base_url = apply_filters( 'sentient_forms_cps_base_url', $base_url, $options );

        if ( is_string( $base_url ) && '' !== trim( $base_url ) )
        {
            return $base_url;
        }

        return defined( 'SENTIENT_FORMS_DEFAULT_CPS_BASE_URL' )
            ? SENTIENT_FORMS_DEFAULT_CPS_BASE_URL
            : 'https://api.sentientforms.com/v1';
    }
}
