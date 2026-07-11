<?php
/**
 * Execution-time Action policy gate.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Action_Runtime_Policy_Gate
{
    public function __construct( private ?Sentient_Forms_Action_Policy_Resolver $resolver = null )
    {
        $this->resolver = $this->resolver ?? new Sentient_Forms_Action_Policy_Resolver();
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string>   $enabled_facets
     * @param array<string, bool>  $source_capabilities
     * @return array<string, mixed>|WP_Error
     */
    public function authorize(
        array $definition,
        array $enabled_facets,
        string $lifecycle,
        array $source_capabilities
    ): array | WP_Error
    {
        $effective = $this->resolver->resolve_action_definition( $definition, $enabled_facets );
        if ( is_wp_error( $effective ) )
        {
            return $effective;
        }

        $lifecycle = Sentient_Forms_Form_Source_Lifecycles::normalize_id( $lifecycle );
        if ( null === $lifecycle || ! in_array( $lifecycle, $effective['eligible_lifecycles'], true ) )
        {
            return new WP_Error(
                'sentient_forms_action_lifecycle_not_eligible',
                __( 'This Action is not eligible for the current Form Source lifecycle.', 'sentient-forms' ),
                [ 'lifecycle' => $lifecycle ]
            );
        }

        $missing = [];
        foreach ( $effective['required_form_source_capabilities'] as $capability )
        {
            if ( true !== ( $source_capabilities[ $capability ] ?? false ) )
            {
                $missing[] = $capability;
            }
        }
        if ( [] !== $missing )
        {
            return new WP_Error(
                'sentient_forms_action_source_capability_missing',
                __( 'The Form Source cannot provide a capability required by this Action.', 'sentient-forms' ),
                [ 'missing_capabilities' => $missing ]
            );
        }

        return $effective;
    }
}
