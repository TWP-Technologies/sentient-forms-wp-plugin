<?php
/**
 * LLM rationale generation for historical spam guidance examples.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Spam_Guidance_Rationale_Service
{
    private const ACTION_TEMPLATE_CODE = 'spam_detection_v1';
    private const ACTION_FACET_CODE = 'spam_guidance_rationale_generation';
    private const READY_CREDENTIAL_STATUSES = [ 'valid', 'limited' ];
    private const MANAGED_SERVICE_PLAN_CODES = [ 'starter', 'pro', 'business' ];
    private const ACTIVE_SUBSCRIPTION_STATUSES = [ 'active', 'trial', 'trialing', 'valid' ];

    private Sentient_Forms_Action_Policy_Resolver $policy_resolver;

    private Sentient_Forms_Provider_Route_Decision $provider_route_decision;

    private Sentient_Forms_Action_Facet_Catalog $facet_catalog;

    public function __construct(
        private ?Sentient_Forms_Managed_Service_Client $managed_service = null,
        private ?Sentient_Forms_Managed_Proxy_Client $managed_proxy = null,
        private ?Sentient_Forms_OpenRouter_Direct_Client $openrouter = null,
        private ?Sentient_Forms_Local_Action_Model_Selection_Service $model_selection = null,
        ?Sentient_Forms_Action_Policy_Resolver $policy_resolver = null,
        ?Sentient_Forms_Provider_Route_Decision $provider_route_decision = null
    )
    {
        $this->managed_service = $this->managed_service ?? new Sentient_Forms_Managed_Service_Client( null, 30 );
        $this->managed_proxy   = $this->managed_proxy ?? new Sentient_Forms_Managed_Proxy_Client( null, 60 );
        $this->openrouter      = $this->openrouter ?? new Sentient_Forms_OpenRouter_Direct_Client( 60 );
        $this->model_selection = $this->model_selection ?? new Sentient_Forms_Local_Action_Model_Selection_Service();
        $this->facet_catalog           = null !== $policy_resolver
            ? $policy_resolver->facet_catalog()
            : new Sentient_Forms_Action_Facet_Catalog();
        $this->policy_resolver         = $policy_resolver ?? new Sentient_Forms_Action_Policy_Resolver( $this->facet_catalog );
        $this->provider_route_decision = $provider_route_decision ?? new Sentient_Forms_Provider_Route_Decision();
    }

    /**
     * @param array<string,mixed> $context
     * @return array{rationale:string,route:string,model?:string}|WP_Error
     */
    public function generate( array $context ): array | WP_Error
    {
        $context = $this->normalize_context( $context );
        if ( is_wp_error( $context ) )
        {
            return $context;
        }

        $execution_contract = $this->facet_catalog->execution_contract( self::ACTION_FACET_CODE );
        if ( is_wp_error( $execution_contract ) )
        {
            return $execution_contract;
        }
        $rationale_max_length = $this->rationale_max_length( $execution_contract );

        $pre = apply_filters( 'sentient_forms_spam_guidance_generate_rationale_pre', null, $context );
        if ( is_wp_error( $pre ) )
        {
            return $pre;
        }
        if ( null !== $pre )
        {
            return $this->normalize_generation_result( $pre, 'filter', $rationale_max_length );
        }

        $effective_policy = $this->resolve_effective_action_policy();
        if ( is_wp_error( $effective_policy ) )
        {
            return $effective_policy;
        }
        $policy_preflight = $this->preflight_execution_policy( $effective_policy, $execution_contract );
        if ( is_wp_error( $policy_preflight ) )
        {
            return $policy_preflight;
        }

        $managed_context = $this->resolve_managed_context();
        if ( is_wp_error( $managed_context ) )
        {
            return $managed_context;
        }

        $prompt = $this->facet_catalog->render_prompt( self::ACTION_FACET_CODE, $context );
        if ( is_wp_error( $prompt ) )
        {
            return $prompt;
        }
        $billing      = $this->resolve_billing_state( $managed_context );
        $billing_code = is_wp_error( $billing ) ? $billing->get_error_code() : null;
        $subscription_active = is_array( $billing ) && $this->billing_state_has_active_subscription( $billing );
        $has_credits         = is_array( $billing ) && $this->billing_state_has_credits( $billing );
        $managed_capabilities_supported = [] === $effective_policy['required_managed_capabilities'];
        $managed_credential  = $subscription_active
            ? $this->resolve_ready_managed_credential()
            : $this->subscription_required_error();
        $openrouter_credential = $subscription_active
            ? $this->resolve_ready_openrouter_credential()
            : $this->subscription_required_error();
        $route = $this->provider_route_decision->decide(
            $effective_policy,
            [
                'subscription_active'        => $subscription_active,
                'managed_ready'              => is_array( $managed_credential ) && $managed_capabilities_supported,
                'managed_capacity_available' => $has_credits,
                'direct_ready'               => is_array( $openrouter_credential ),
                'policy_preflight_complete' => true,
            ]
        );
        if ( is_wp_error( $route ) )
        {
            if ( 'sentient_forms_provider_route_subscription_required' === $route->get_error_code() )
            {
                return $this->subscription_required_error();
            }

            if ( $has_credits && is_wp_error( $managed_credential ) && ! is_array( $openrouter_credential ) )
            {
                return $managed_credential;
            }

            return $this->provider_setup_required_error(
                $billing_code,
                is_wp_error( $managed_credential ) ? $managed_credential : null,
                is_wp_error( $openrouter_credential ) ? $openrouter_credential : null,
                $route
            );
        }

        if ( 'sentient_managed' === $route['provider'] )
        {
            return $this->run_managed_generation( $managed_context, $prompt, $context, $execution_contract );
        }

        return $this->run_openrouter_generation( $prompt, $context, $openrouter_credential, $execution_contract );
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function resolve_effective_action_policy(): array | WP_Error
    {
        $definition = Sentient_Forms_Bundled_Action_Templates::get( self::ACTION_TEMPLATE_CODE );
        return $this->policy_resolver->resolve_action_definition(
            is_array( $definition ) ? $definition : [],
            [ self::ACTION_FACET_CODE ]
        );
    }

    /**
     * Preflight this facet's non-form administrative execution before routing.
     * Form lifecycle and Form Source capability checks remain owned by the
     * workflow runtime for actual form-triggered Action executions.
     *
     * @param array<string,mixed> $effective_policy
     * @param array<string,mixed> $execution_contract
     */
    private function preflight_execution_policy( array $effective_policy, array $execution_contract ): true | WP_Error
    {
        foreach ( [ 'required_form_source_capabilities', 'required_managed_capabilities', 'eligible_lifecycles' ] as $field )
        {
            if ( ! is_array( $effective_policy[ $field ] ?? null ) )
            {
                return $this->policy_preflight_error( $field );
            }
        }

        if ( 'administrative' !== sanitize_key( (string) ( $execution_contract['execution_scope'] ?? '' ) ) )
        {
            return $this->policy_preflight_error( 'execution_scope' );
        }

        if ( 'standard' !== sanitize_key( (string) ( $effective_policy['metering_class'] ?? '' ) ) )
        {
            return $this->policy_preflight_error( 'metering_class' );
        }

        if ( '' === sanitize_key( (string) ( $execution_contract['accounting_action_code'] ?? '' ) ) )
        {
            return $this->policy_preflight_error( 'accounting_action_code' );
        }

        return true;
    }

    private function policy_preflight_error( string $field ): WP_Error
    {
        return new WP_Error(
            'sentient_forms_spam_rationale_policy_preflight_failed',
            __( 'Spam Guidance rationale policy could not be preflighted for provider routing.', 'sentient-forms' ),
            [
                'status' => 500,
                'field'  => $field,
            ]
        );
    }

    private function provider_setup_required_error(
        ?string $billing_error_code,
        ?WP_Error $managed_error,
        ?WP_Error $openrouter_error,
        WP_Error $route_error
    ): WP_Error
    {
        return new WP_Error(
            'sentient_forms_spam_rationale_provider_setup_required',
            __( 'Rationale generation requires Sentient Forms managed credits or a ready paid OpenRouter key on an active managed-service subscription.', 'sentient-forms' ),
            [
                'status'                => 402,
                'billing_error_code'    => $billing_error_code,
                'managed_error_code'    => $managed_error?->get_error_code(),
                'openrouter_error_code' => $openrouter_error?->get_error_code(),
                'route_error_code'      => $route_error->get_error_code(),
            ]
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>|WP_Error
     */
    private function normalize_context( array $context ): array | WP_Error
    {
        $label = sanitize_key( (string) ( $context['label'] ?? '' ) );
        if ( ! in_array( $label, [ 'ham', 'spam' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_invalid_context',
                __( 'Rationale generation requires a legitimate or spam label.', 'sentient-forms' ),
                [ 'status' => 400, 'field' => 'label' ]
            );
        }

        $text = $this->sanitize_text( $context['text'] ?? null );
        if ( '' === $text )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_invalid_context',
                __( 'Rationale generation requires selected entry text.', 'sentient-forms' ),
                [ 'status' => 400, 'field' => 'text' ]
            );
        }

        return [
            'label'             => $label,
            'form_source'       => sanitize_key( (string) ( $context['form_source'] ?? '' ) ),
            'form_id'           => sanitize_text_field( (string) ( $context['form_id'] ?? '' ) ),
            'target_scope'      => sanitize_key( (string) ( $context['target_scope'] ?? 'form' ) ),
            'text'              => $text,
            'entry'             => is_array( $context['entry'] ?? null ) ? $context['entry'] : [],
            'existing_guidance' => is_array( $context['existing_guidance'] ?? null ) ? $context['existing_guidance'] : [],
        ];
    }

    /**
     * @return array{proxy_api_key:string,site_id:string}|WP_Error
     */
    private function resolve_managed_context(): array | WP_Error
    {
        if ( ! class_exists( 'Sentient_Forms_Plugin' ) )
        {
            return $this->subscription_required_error();
        }

        $plugin         = Sentient_Forms_Plugin::instance();
        $license        = $plugin->get_license_data();
        $license_status = sanitize_key( (string) ( $license['license_status'] ?? '' ) );
        if ( ! in_array( $license_status, [ 'active', 'trial', 'valid' ], true ) )
        {
            return $this->subscription_required_error();
        }

        $proxy_api_key = trim( (string) ( $license['proxy_api_key'] ?? $plugin->get_proxy_api_key() ) );
        $site_id       = trim( (string) ( $license['site_id'] ?? get_option( 'sentient_forms_site_id', '' ) ) );
        if ( '' === $proxy_api_key || '' === $site_id )
        {
            return $this->subscription_required_error();
        }

        return [
            'proxy_api_key' => $proxy_api_key,
            'site_id'       => $site_id,
        ];
    }

    private function subscription_required_error(): WP_Error
    {
        return new WP_Error(
            'sentient_forms_spam_rationale_subscription_required',
            __( 'Historical spam rationale generation requires an active Sentient Forms managed-service subscription.', 'sentient-forms' ),
            [ 'status' => 403 ]
        );
    }

    /**
     * @param array{proxy_api_key:string,site_id:string} $managed_context
     * @return array<string,mixed>|WP_Error
     */
    private function resolve_billing_state( array $managed_context ): array | WP_Error
    {
        $filtered = apply_filters( 'sentient_forms_spam_guidance_billing_state', null, $managed_context );
        if ( is_wp_error( $filtered ) )
        {
            return $filtered;
        }
        if ( is_array( $filtered ) )
        {
            return $filtered;
        }

        return $this->managed_service->get_billing_state( $managed_context['proxy_api_key'] );
    }

    /**
     * @param array<string,mixed> $billing
     */
    private function billing_state_has_active_subscription( array $billing ): bool
    {
        $plan    = is_array( $billing['plan'] ?? null ) ? $billing['plan'] : [];
        $account = is_array( $billing['account'] ?? null ) ? $billing['account'] : [];
        $tier    = is_array( $account['tier'] ?? null ) ? $account['tier'] : ( is_array( $billing['tier'] ?? null ) ? $billing['tier'] : [] );
        $account_tier_code = ! is_array( $account['tier'] ?? null ) ? ( $account['tier'] ?? null ) : null;
        $billing_tier_code = ! is_array( $billing['tier'] ?? null ) ? ( $billing['tier'] ?? null ) : null;

        $plan_code = sanitize_key(
            (string) (
                $plan['code']
                ?? $billing['plan_code']
                ?? $tier['code']
                ?? $account_tier_code
                ?? $billing_tier_code
                ?? ''
            )
        );
        if ( ! in_array( $plan_code, self::MANAGED_SERVICE_PLAN_CODES, true ) )
        {
            return false;
        }

        $billing_status = sanitize_key(
            (string) (
                $billing['status']
                ?? $billing['license_status']
                ?? $account['license_status']
                ?? ''
            )
        );
        if ( '' !== $billing_status && ! in_array( $billing_status, self::ACTIVE_SUBSCRIPTION_STATUSES, true ) )
        {
            return false;
        }

        $billing_details = is_array( $billing['billing'] ?? null ) ? $billing['billing'] : [];
        if ( ! array_key_exists( 'managed_enabled', $billing_details ) || ! rest_sanitize_boolean( $billing_details['managed_enabled'] ) )
        {
            return false;
        }

        $subscription = $billing_details['subscription'] ?? $billing['subscription'] ?? null;
        if ( is_array( $subscription ) )
        {
            $subscription_status = sanitize_key( (string) ( $subscription['status'] ?? '' ) );
            return '' === $subscription_status || in_array( $subscription_status, self::ACTIVE_SUBSCRIPTION_STATUSES, true );
        }

        return ! array_key_exists( 'subscription', $billing_details ) && ! array_key_exists( 'subscription', $billing );
    }

    /**
     * @param array<string,mixed> $billing
     */
    private function billing_state_has_credits( array $billing ): bool
    {
        $credits = is_array( $billing['credits'] ?? null ) ? $billing['credits'] : [];
        foreach ( [ 'current_balance', 'available_balance', 'balance' ] as $field )
        {
            if ( isset( $credits[ $field ] ) && is_numeric( $credits[ $field ] ) && (float) $credits[ $field ] > 0 )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function resolve_ready_managed_credential(): array | WP_Error
    {
        global $wpdb;

        $repository = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $credential = $repository->find_by_provider_auth_mode( 'sentient_managed', 'sentient_proxy' );
        if ( ! is_array( $credential ) )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_managed_setup_required',
                __( 'Sentient Forms Managed Service setup is not complete for this WordPress site.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! in_array( sanitize_key( (string) ( $credential['status'] ?? '' ) ), self::READY_CREDENTIAL_STATUSES, true ) )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_managed_setup_required',
                __( 'Sentient Forms Managed Service is not ready for rationale generation.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $status_json = is_array( $credential['status_json'] ?? null ) ? $credential['status_json'] : [];
        $consent     = is_array( $status_json['managed_consent'] ?? null ) ? $status_json['managed_consent'] : [];
        if ( 'revoked' === sanitize_key( (string) ( $consent['state'] ?? '' ) ) )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_managed_setup_required',
                __( 'Sentient Forms Managed Service consent has been revoked on this site.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        return $credential;
    }

    /**
     * @param array{proxy_api_key:string,site_id:string} $managed_context
     * @param array<string,mixed>                        $context
     * @return array{rationale:string,route:string,model?:string}|WP_Error
     */
    private function run_managed_generation(
        array $managed_context,
        string $prompt,
        array $context,
        array $execution_contract
    ): array | WP_Error
    {
        $payload = [
            'site_id'              => $managed_context['site_id'],
            'execution_request_id' => 'spam_guidance_rationale_' . wp_generate_uuid4(),
            'provider'             => 'sentient_managed',
            'model'                => $execution_contract['model'],
            'action_code'          => $execution_contract['accounting_action_code'],
            'prompt'               => $prompt,
            'temperature'          => $execution_contract['temperature'],
            'max_output_tokens'    => $execution_contract['max_output_tokens'],
            'output_contract'      => [
                'schema' => $execution_contract['output_schema'],
                'source' => $execution_contract['accounting_action_code'],
            ],
            'metadata'             => $execution_contract['metadata'],
        ];

        $response = apply_filters( 'sentient_forms_spam_guidance_managed_generation_response', null, $payload, $context );
        if ( null === $response )
        {
            $response = $this->managed_proxy->execute( $managed_context['proxy_api_key'], $payload );
        }
        if ( is_wp_error( $response ) )
        {
            return $response;
        }
        if ( ! is_array( $response ) )
        {
            return $this->invalid_provider_response_error();
        }

        $content = '';
        if ( isset( $response['rationale'] ) && is_scalar( $response['rationale'] ) )
        {
            $content = wp_json_encode( [ 'rationale' => (string) $response['rationale'] ] ) ?: '';
        }
        elseif ( is_array( $response['structured'] ?? null ) && isset( $response['structured']['rationale'] ) )
        {
            $content = wp_json_encode( [ 'rationale' => (string) $response['structured']['rationale'] ] ) ?: '';
        }
        elseif ( is_array( $response['output'] ?? null ) && is_scalar( $response['output']['text'] ?? null ) )
        {
            $content = (string) $response['output']['text'];
        }

        $result = $this->decode_rationale_json( $content, $this->rationale_max_length( $execution_contract ) );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return [
            'rationale' => $result['rationale'],
            'route'     => 'sentient_managed',
            'model'     => isset( $response['model'] ) && is_scalar( $response['model'] )
                ? sanitize_text_field( (string) $response['model'] )
                : $execution_contract['model'],
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array{rationale:string,route:string,model?:string}|WP_Error
     */
    private function run_openrouter_generation(
        string $prompt,
        array $context,
        array $credential,
        array $execution_contract
    ): array | WP_Error
    {
        $api_key = $this->resolve_openrouter_api_key( $credential );
        if ( is_wp_error( $api_key ) )
        {
            return $api_key;
        }

        $payload = [
            'model'           => $execution_contract['model'],
            'messages'        => [
                [
                    'role'    => 'system',
                    'content' => $execution_contract['system_prompt'],
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
            'max_tokens'      => $execution_contract['max_output_tokens'],
            'temperature'     => $execution_contract['temperature'],
            'response_format' => [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name'   => $execution_contract['output_schema_name'],
                    'strict' => true,
                    'schema' => $execution_contract['output_schema'],
                ],
            ],
            'provider'        => $execution_contract['provider'],
        ];

        $response = apply_filters( 'sentient_forms_spam_guidance_openrouter_generation_response', null, $api_key, $payload, $context );
        if ( null === $response )
        {
            $response = $this->openrouter->chat_completion( $api_key, $payload, [ 'timeout' => 60 ] );
        }
        if ( is_wp_error( $response ) )
        {
            return $response;
        }
        if ( ! is_array( $response ) )
        {
            return $this->invalid_provider_response_error();
        }

        $choice  = is_array( $response['choices'][0] ?? null ) ? $response['choices'][0] : [];
        $message = is_array( $choice['message'] ?? null ) ? $choice['message'] : [];
        $content = is_scalar( $message['content'] ?? null ) ? (string) $message['content'] : '';
        $result  = $this->decode_rationale_json( $content, $this->rationale_max_length( $execution_contract ) );
        if ( is_wp_error( $result ) )
        {
            return $result;
        }

        return [
            'rationale' => $result['rationale'],
            'route'     => 'openrouter',
            'model'     => $execution_contract['model'],
        ];
    }

    /**
     * @return array<string,mixed>|WP_Error
     */
    private function resolve_ready_openrouter_credential(): array | WP_Error
    {
        $credential = $this->model_selection->resolve_execution_credential( 'openrouter', 0 );
        if ( is_wp_error( $credential ) )
        {
            return $credential;
        }

        if ( ! in_array( sanitize_key( (string) ( $credential['status'] ?? '' ) ), self::READY_CREDENTIAL_STATUSES, true ) )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_openrouter_setup_required',
                __( 'OpenRouter credential must be valid or limited before rationale generation can run.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        if ( ! $this->openrouter_credential_is_paid( $credential ) )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_openrouter_paid_key_required',
                __( 'Rationale generation requires paid OpenRouter access when managed credits are unavailable.', 'sentient-forms' ),
                [ 'status' => 402 ]
            );
        }

        return $credential;
    }

    /**
     * @param array<string,mixed> $credential
     */
    private function openrouter_credential_is_paid( array $credential ): bool
    {
        $status_json = is_array( $credential['status_json'] ?? null ) ? $credential['status_json'] : [];
        if ( ! array_key_exists( 'is_free_tier', $status_json ) )
        {
            return false;
        }

        return ! rest_sanitize_boolean( $status_json['is_free_tier'] );
    }

    /**
     * @param array<string,mixed> $credential
     */
    private function resolve_openrouter_api_key( array $credential ): string | WP_Error
    {
        $auth_mode = sanitize_key( (string) ( $credential['auth_mode'] ?? '' ) );
        if ( 'constant' === $auth_mode )
        {
            return $this->normalize_resolved_provider_secret(
                Sentient_Forms_Provider_Secret_Resolver::resolve_constant_secret( (string) ( $credential['constant_name'] ?? '' ) )
            );
        }

        if ( ! in_array( $auth_mode, [ 'manual_key', 'oauth_broker' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_openrouter_setup_required',
                __( 'OpenRouter credential authentication mode is not supported for rationale generation.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        $encrypted = (string) ( $credential['encrypted_secret'] ?? '' );
        if ( '' === $encrypted )
        {
            return new WP_Error(
                'sentient_forms_provider_secret_missing',
                __( 'Provider credential does not contain a stored secret.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        return $this->normalize_resolved_provider_secret(
            ( new Sentient_Forms_Provider_Credential_Vault() )->decrypt( $encrypted )
        );
    }

    private function normalize_resolved_provider_secret( string | WP_Error $secret ): string | WP_Error
    {
        if ( is_wp_error( $secret ) )
        {
            return $secret;
        }

        $secret = trim( $secret );
        if ( '' === $secret )
        {
            return new WP_Error(
                'sentient_forms_provider_secret_missing',
                __( 'Provider credential does not contain a usable stored secret.', 'sentient-forms' ),
                [ 'status' => 400 ]
            );
        }

        return $secret;
    }

    /**
     * @return array{rationale:string}|WP_Error
     */
    private function decode_rationale_json( string $content, int $max_length ): array | WP_Error
    {
        $content = trim( $content );
        if ( str_starts_with( $content, '```' ) )
        {
            $content = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $content ) ?? $content;
            $content = trim( $content );
        }

        $decoded = json_decode( $content, true );
        if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() )
        {
            return $this->invalid_provider_response_error();
        }

        $rationale = $this->sanitize_text( $decoded['rationale'] ?? null, $max_length );
        if ( '' === $rationale )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_empty',
                __( 'Rationale generation returned an empty rationale.', 'sentient-forms' ),
                [ 'status' => 502 ]
            );
        }

        return [ 'rationale' => $rationale ];
    }

    private function invalid_provider_response_error(): WP_Error
    {
        return new WP_Error(
            'sentient_forms_spam_rationale_invalid_json',
            __( 'Rationale generation returned an invalid response.', 'sentient-forms' ),
            [ 'status' => 502 ]
        );
    }

    /**
     * @return array{rationale:string,route:string}|WP_Error
     */
    private function normalize_generation_result( mixed $result, string $route, int $max_length ): array | WP_Error
    {
        if ( is_string( $result ) )
        {
            $result = [ 'rationale' => $result ];
        }
        if ( ! is_array( $result ) )
        {
            return $this->invalid_provider_response_error();
        }

        $rationale = $this->sanitize_text( $result['rationale'] ?? null, $max_length );
        if ( '' === $rationale )
        {
            return new WP_Error(
                'sentient_forms_spam_rationale_empty',
                __( 'Rationale generation returned an empty rationale.', 'sentient-forms' ),
                [ 'status' => 502 ]
            );
        }

        return [
            'rationale' => $rationale,
            'route'     => isset( $result['route'] ) && is_scalar( $result['route'] )
                ? sanitize_key( (string) $result['route'] )
                : $route,
        ];
    }

    /**
     * @param array<string,mixed> $execution_contract
     */
    private function rationale_max_length( array $execution_contract ): int
    {
        $max_length = $execution_contract['output_schema']['properties']['rationale']['maxLength'] ?? 0;
        return is_int( $max_length ) && $max_length > 0 ? $max_length : 800;
    }

    private function sanitize_text( mixed $value, int $max_length = 800 ): string
    {
        if ( ! is_scalar( $value ) )
        {
            return '';
        }

        return mb_substr( trim( sanitize_textarea_field( (string) $value ) ), 0, $max_length );
    }
}
