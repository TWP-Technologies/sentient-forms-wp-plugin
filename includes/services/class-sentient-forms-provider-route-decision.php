<?php
/**
 * Pure provider-route selection for resolved Action policies.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Provider_Route_Decision
{
    /**
     * Decide an execution provider without performing provider work.
     *
     * Runtime readiness values must already include route-specific consent,
     * privacy, credential, and other eligibility checks. The caller must also
     * attest that lifecycle, capability, and metering policy was preflighted
     * by the module that owns that execution context.
     *
     * @param array<string, mixed> $effective_policy
     * @param array<string, mixed> $runtime_state
     * @param string|null          $provider_preference Explicit saved execution-route preference.
     * @return array{provider:string,decision_reason:string}|WP_Error
     */
    public function decide(
        array $effective_policy,
        array $runtime_state,
        ?string $provider_preference = null
    ): array | WP_Error
    {
        foreach (
            [
                'feature_access'        => [ 'unrestricted', 'active_subscription' ],
                'execution_requirement' => [ 'provider_flexible', 'managed_only' ],
            ] as $field => $allowed
        )
        {
            if ( ! isset( $effective_policy[ $field ] ) || ! is_string( $effective_policy[ $field ] ) )
            {
                return $this->invalid_input_error( 'policy', $field );
            }

            $effective_policy[ $field ] = sanitize_key( $effective_policy[ $field ] );
            if ( ! in_array( $effective_policy[ $field ], $allowed, true ) )
            {
                return $this->invalid_input_error( 'policy', $field );
            }
        }

        $managed_capacity_known = $runtime_state['managed_capacity_known'] ?? true;
        if ( ! is_bool( $managed_capacity_known ) )
        {
            return $this->invalid_input_error( 'state', 'managed_capacity_known' );
        }

        foreach (
            [
                'subscription_active',
                'managed_ready',
                'managed_capacity_available',
                'direct_ready',
                'policy_preflight_complete',
            ] as $field
        )
        {
            if ( ! array_key_exists( $field, $runtime_state ) || ! is_bool( $runtime_state[ $field ] ) )
            {
                return $this->invalid_input_error( 'state', $field );
            }
        }

        if ( ! $runtime_state['policy_preflight_complete'] )
        {
            return $this->error(
                'sentient_forms_provider_route_policy_not_preflighted',
                __( 'The Action policy must pass execution preflight before provider routing.', 'sentient-forms' ),
                [],
                500
            );
        }

        $provider_preference = null === $provider_preference
            ? null
            : sanitize_key( $provider_preference );
        if ( '' === $provider_preference )
        {
            $provider_preference = null;
        }
        if ( null !== $provider_preference && ! in_array( $provider_preference, [ 'openrouter', 'sentient_managed' ], true ) )
        {
            return $this->error(
                'sentient_forms_provider_route_preference_invalid',
                __( 'The saved Action execution route is invalid.', 'sentient-forms' ),
                [ 'provider' => $provider_preference ],
                500
            );
        }

        if (
            'active_subscription' === $effective_policy['feature_access']
            && ! $runtime_state['subscription_active']
        )
        {
            return $this->error(
                'sentient_forms_provider_route_subscription_required',
                __( 'This Action requires an active Sentient Forms subscription.', 'sentient-forms' )
            );
        }

        if ( 'managed_only' === $effective_policy['execution_requirement'] )
        {
            if ( 'openrouter' === $provider_preference )
            {
                return $this->error(
                    'sentient_forms_provider_route_preference_incompatible',
                    __( 'This Action requires Sentient Forms Managed Service and cannot use the saved Direct OpenRouter route.', 'sentient-forms' ),
                    [ 'provider' => 'openrouter' ]
                );
            }

            if ( ! $runtime_state['subscription_active'] )
            {
                return $this->error(
                    'sentient_forms_provider_route_subscription_required',
                    __( 'Managed-only Actions require an active Sentient Forms subscription.', 'sentient-forms' )
                );
            }

            if ( ! $runtime_state['managed_ready'] )
            {
                return $this->error(
                    'sentient_forms_provider_route_managed_unavailable',
                    __( 'Sentient Forms Managed Service is not ready for this Action.', 'sentient-forms' )
                );
            }

            if ( ! $managed_capacity_known )
            {
                return $this->managed_capacity_unknown_error();
            }

            if ( ! $runtime_state['managed_capacity_available'] )
            {
                return $this->error(
                    'sentient_forms_provider_route_managed_capacity_unavailable',
                    __( 'Sentient Forms Managed Service does not have available capacity for this Action.', 'sentient-forms' )
                );
            }

            return $this->selection( 'sentient_managed', 'managed_required_and_ready' );
        }

        if ( 'provider_flexible' === $effective_policy['execution_requirement'] )
        {
            if ( 'openrouter' === $provider_preference )
            {
                return $runtime_state['direct_ready']
                    ? $this->selection( 'openrouter', 'direct_preferred_and_ready' )
                    : $this->error(
                        'sentient_forms_provider_route_preferred_unavailable',
                        __( 'The saved Direct OpenRouter execution route is not ready.', 'sentient-forms' ),
                        [ 'provider' => 'openrouter' ]
                    );
            }

            if ( 'sentient_managed' === $provider_preference )
            {
                if ( ! $runtime_state['subscription_active'] || ! $runtime_state['managed_ready'] )
                {
                    return $this->error(
                        'sentient_forms_provider_route_preferred_unavailable',
                        __( 'The saved Sentient Forms Managed Service execution route is not ready.', 'sentient-forms' ),
                        [ 'provider' => 'sentient_managed' ]
                    );
                }

                if ( ! $managed_capacity_known )
                {
                    return $this->managed_capacity_unknown_error();
                }

                if ( $runtime_state['managed_capacity_available'] )
                {
                    return $this->selection( 'sentient_managed', 'managed_preferred_and_ready' );
                }

                if (
                    ! $runtime_state['managed_capacity_available']
                    && $runtime_state['direct_ready']
                )
                {
                    return $this->selection( 'openrouter', 'managed_preferred_without_capacity_direct_ready' );
                }

                return $this->error(
                    'sentient_forms_provider_route_preferred_unavailable',
                    __( 'The saved Sentient Forms Managed Service execution route is not ready.', 'sentient-forms' ),
                    [ 'provider' => 'sentient_managed' ]
                );
            }

            if (
                $runtime_state['subscription_active']
                && $runtime_state['managed_ready']
                && $managed_capacity_known
                && $runtime_state['managed_capacity_available']
            )
            {
                return $this->selection( 'sentient_managed', 'managed_ready_with_capacity' );
            }

            if ( $runtime_state['direct_ready'] )
            {
                return $this->selection( 'openrouter', 'direct_ready' );
            }
        }

        return $this->error(
            'sentient_forms_provider_route_unavailable',
            __( 'No eligible provider route is currently available.', 'sentient-forms' )
        );
    }

    private function managed_capacity_unknown_error(): WP_Error
    {
        return $this->error(
            'sentient_forms_provider_route_managed_capacity_unknown',
            __( 'Sentient Forms could not verify current Managed Service capacity.', 'sentient-forms' ),
            [],
            503
        );
    }

    /**
     * @return array{provider:string,decision_reason:string}
     */
    private function selection( string $provider, string $reason ): array
    {
        return [
            'provider'        => $provider,
            'decision_reason' => $reason,
        ];
    }

    private function invalid_input_error( string $source, string $field ): WP_Error
    {
        return $this->error(
            'policy' === $source
                ? 'sentient_forms_provider_route_policy_invalid'
                : 'sentient_forms_provider_route_state_invalid',
            'policy' === $source
                ? __( 'The effective Action policy is invalid.', 'sentient-forms' )
                : __( 'The provider route runtime state is invalid.', 'sentient-forms' ),
            [ 'field' => $field ],
            500
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function error( string $code, string $message, array $data = [], int $status = 409 ): WP_Error
    {
        return new WP_Error( $code, $message, array_merge( [ 'status' => $status ], $data ) );
    }
}
