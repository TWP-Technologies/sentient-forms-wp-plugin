<?php
/**
 * Runtime preflight for resolved Action policies.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Action_Policy_Preflight
{
    public function __construct(
        private ?Sentient_Forms_Action_Source_Compatibility_Manifest_Interface $compatibility_manifest = null
    )
    {
        $this->compatibility_manifest = $this->compatibility_manifest
            ?? new Sentient_Forms_Action_Source_Compatibility_Manifest();
    }

    /**
     * Attest lifecycle, Form Source capability, and metering requirements.
     *
     * @param array<string, mixed> $effective_policy Resolved base-plus-facet policy.
     * @param string               $action_code      Executable Action code.
     * @param array<string, mixed> $context          Runtime execution context.
     */
    public function attest( array $effective_policy, string $action_code, array $context ): true | WP_Error
    {
        $lifecycle = Sentient_Forms_Form_Source_Lifecycles::normalize_id(
            $context['lifecycle'] ?? $context['hook'] ?? null
        );
        if ( null === $lifecycle )
        {
            return $this->error(
                'sentient_forms_action_policy_lifecycle_missing',
                __( 'The Action execution lifecycle could not be determined.', 'sentient-forms' ),
                [ 'field' => 'lifecycle' ],
                500
            );
        }

        $eligible_lifecycles = $effective_policy['eligible_lifecycles'] ?? null;
        if ( ! is_array( $eligible_lifecycles ) || ! in_array( $lifecycle, $eligible_lifecycles, true ) )
        {
            return $this->error(
                'sentient_forms_action_policy_lifecycle_ineligible',
                __( 'This Action is not eligible for the current Form Source lifecycle.', 'sentient-forms' ),
                [ 'lifecycle' => $lifecycle ]
            );
        }

        $required_capabilities = $effective_policy['required_form_source_capabilities'] ?? null;
        if ( ! is_array( $required_capabilities ) )
        {
            return $this->error(
                'sentient_forms_action_policy_preflight_invalid',
                __( 'The resolved Action policy is invalid for runtime preflight.', 'sentient-forms' ),
                [ 'field' => 'required_form_source_capabilities' ],
                500
            );
        }

        $available_capabilities = $this->available_capabilities( $action_code, $context );
        if ( is_wp_error( $available_capabilities ) )
        {
            return $available_capabilities;
        }

        foreach ( $required_capabilities as $capability )
        {
            if ( ! is_string( $capability ) || ! in_array( $capability, $available_capabilities, true ) )
            {
                return $this->error(
                    'sentient_forms_action_policy_form_source_capability_unavailable',
                    __( 'The current Form Source does not provide a capability required by this Action.', 'sentient-forms' ),
                    [ 'capability' => is_scalar( $capability ) ? sanitize_key( (string) $capability ) : '' ]
                );
            }
        }

        $metering_class = sanitize_key( (string) ( $effective_policy['metering_class'] ?? '' ) );
        if ( 'secondary_preflight' === $metering_class && true !== ( $context['secondary_preflight_complete'] ?? null ) )
        {
            return $this->error(
                'sentient_forms_action_policy_secondary_preflight_required',
                __( 'This Action requires a completed secondary cost preflight before execution.', 'sentient-forms' )
            );
        }

        if ( ! in_array( $metering_class, [ 'standard', 'secondary_preflight' ], true ) )
        {
            return $this->error(
                'sentient_forms_action_policy_preflight_invalid',
                __( 'The resolved Action policy is invalid for runtime preflight.', 'sentient-forms' ),
                [ 'field' => 'metering_class' ],
                500
            );
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, string>|WP_Error
     */
    private function available_capabilities( string $action_code, array $context ): array | WP_Error
    {
        if ( array_key_exists( 'form_source_capabilities', $context ) )
        {
            if ( ! is_array( $context['form_source_capabilities'] ) )
            {
                return $this->error(
                    'sentient_forms_action_policy_preflight_invalid',
                    __( 'The Form Source capability attestation is invalid.', 'sentient-forms' ),
                    [ 'field' => 'form_source_capabilities' ],
                    500
                );
            }

            $capabilities = [];
            foreach ( $context['form_source_capabilities'] as $capability )
            {
                if ( ! is_string( $capability ) || '' === sanitize_key( $capability ) )
                {
                    return $this->error(
                        'sentient_forms_action_policy_preflight_invalid',
                        __( 'The Form Source capability attestation is invalid.', 'sentient-forms' ),
                        [ 'field' => 'form_source_capabilities' ],
                        500
                    );
                }

                $capabilities[] = sanitize_key( $capability );
            }

            return array_values( array_unique( $capabilities ) );
        }

        $form_source = sanitize_key( (string) ( $context['form_source'] ?? '' ) );
        if ( '' === $form_source || '' === sanitize_key( $action_code ) )
        {
            return [];
        }

        try
        {
            $row = $this->compatibility_manifest->get( $action_code, $form_source );
        }
        catch ( LogicException )
        {
            return $this->error(
                'sentient_forms_action_policy_preflight_unavailable',
                __( 'The Form Source compatibility contract is unavailable.', 'sentient-forms' ),
                [],
                500
            );
        }

        $requirements = is_array( $row['source_capabilities']['requirements'] ?? null )
            ? $row['source_capabilities']['requirements']
            : [];

        return array_values(
            array_keys(
                array_filter( $requirements, static fn( mixed $available ): bool => true === $available )
            )
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
