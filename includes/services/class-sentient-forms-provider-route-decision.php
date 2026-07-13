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
     * @return array{provider:string,decision_reason:string}|WP_Error
     */
    public function decide( array $effective_policy, array $runtime_state ): array | WP_Error
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
            if (
                $runtime_state['subscription_active']
                && $runtime_state['managed_ready']
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
