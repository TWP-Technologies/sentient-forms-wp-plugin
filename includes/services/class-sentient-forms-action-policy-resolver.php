<?php
/**
 * Effective Action policy composition.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

final class Sentient_Forms_Action_Policy_Resolver
{
    private const FEATURE_ACCESS_RANKS = [
        'unrestricted'        => 0,
        'active_subscription' => 1,
    ];

    private const EXECUTION_REQUIREMENT_RANKS = [
        'provider_flexible' => 0,
        'managed_only'      => 1,
    ];

    private const METERING_CLASS_RANKS = [
        'standard'            => 0,
        'secondary_preflight' => 1,
    ];

    public function __construct( private ?Sentient_Forms_Action_Facet_Catalog $catalog = null )
    {
        $this->catalog = $this->catalog ?? new Sentient_Forms_Action_Facet_Catalog();
    }

    public function facet_catalog(): Sentient_Forms_Action_Facet_Catalog
    {
        return $this->catalog;
    }

    /**
     * Resolve one executable Action definition and its explicitly enabled facets.
     *
     * @param array<string, mixed> $definition
     * @param array<int, string>   $enabled_facet_codes
     * @return array<string, mixed>|WP_Error
     */
    public function resolve_action_definition( array $definition, array $enabled_facet_codes = [] ): array | WP_Error
    {
        if ( ! is_array( $definition['action_policy'] ?? null ) )
        {
            return $this->invalid_policy_error( 'action_definition', 'action_policy' );
        }

        $allowed_facets = $this->normalize_identifier_list(
            $definition['allowed_facets'] ?? null,
            'allowed_facets',
            'action_definition'
        );
        if ( is_wp_error( $allowed_facets ) )
        {
            return $allowed_facets;
        }

        $default_enabled_facets = $this->normalize_identifier_list(
            $definition['enabled_facets'] ?? null,
            'enabled_facets',
            'action_definition'
        );
        if ( is_wp_error( $default_enabled_facets ) )
        {
            return $default_enabled_facets;
        }

        $requested_facets = $this->normalize_identifier_list(
            $enabled_facet_codes,
            'enabled_facets',
            'action_definition'
        );
        if ( is_wp_error( $requested_facets ) )
        {
            return $requested_facets;
        }

        foreach ( $allowed_facets as $facet_code )
        {
            if ( ! $this->catalog->has( $facet_code ) )
            {
                return $this->unknown_facet_error( $facet_code );
            }
        }

        $enabled_facets = $this->unique_merge( $default_enabled_facets, $requested_facets );
        foreach ( $enabled_facets as $facet_code )
        {
            if ( ! in_array( $facet_code, $allowed_facets, true ) )
            {
                return new WP_Error(
                    'sentient_forms_action_facet_not_allowed',
                    __( 'The enabled Action facet is not allowed by this Action.', 'sentient-forms' ),
                    [
                        'status'     => 500,
                        'facet_code' => $facet_code,
                    ]
                );
            }
        }

        return $this->resolve( $definition['action_policy'], $enabled_facets );
    }

    /**
     * @param array<string, mixed> $base_policy
     * @param array<int, string>   $enabled_facet_codes
     * @return array<string, mixed>|WP_Error
     */
    public function resolve( array $base_policy, array $enabled_facet_codes = [] ): array | WP_Error
    {
        $resolved = $this->normalize_base_policy( $base_policy );
        if ( is_wp_error( $resolved ) )
        {
            return $resolved;
        }

        foreach ( $enabled_facet_codes as $facet_code )
        {
            if ( ! is_string( $facet_code ) || '' === sanitize_key( $facet_code ) )
            {
                return $this->invalid_policy_error( 'facets', 'enabled_facets' );
            }

            $facet_code = sanitize_key( $facet_code );
            $facet = $this->catalog->get( $facet_code );
            if ( null === $facet )
            {
                return $this->unknown_facet_error( $facet_code );
            }

            $facet = $this->normalize_facet_policy( $facet, $facet_code );
            if ( is_wp_error( $facet ) )
            {
                return $facet;
            }

            $resolved['feature_access'] = $this->strictest_value(
                $resolved['feature_access'],
                $facet['feature_access'],
                self::FEATURE_ACCESS_RANKS
            );
            $resolved['execution_requirement'] = $this->strictest_value(
                $resolved['execution_requirement'],
                $facet['execution_requirement'],
                self::EXECUTION_REQUIREMENT_RANKS
            );
            $resolved['required_form_source_capabilities'] = $this->unique_merge(
                $resolved['required_form_source_capabilities'],
                $facet['required_form_source_capabilities']
            );
            $resolved['required_managed_capabilities'] = $this->unique_merge(
                $resolved['required_managed_capabilities'],
                $facet['required_managed_capabilities']
            );

            if ( [] !== $facet['lifecycle_restrictions'] )
            {
                $resolved['eligible_lifecycles'] = array_values(
                    array_intersect( $resolved['eligible_lifecycles'], $facet['lifecycle_restrictions'] )
                );
                if ( [] === $resolved['eligible_lifecycles'] )
                {
                    return new WP_Error(
                        'sentient_forms_action_policy_lifecycle_conflict',
                        __( 'The enabled Action facet is incompatible with the Action lifecycle.', 'sentient-forms' ),
                        [
                            'status'     => 500,
                            'facet_code' => $facet_code,
                        ]
                    );
                }
            }

            $resolved['metering_class'] = $this->strictest_value(
                $resolved['metering_class'],
                $facet['metering_class'],
                self::METERING_CLASS_RANKS
            );
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_facet_policy( array $policy, string $facet_code ): array | WP_Error
    {
        if ( ! isset( $policy['code'] ) || ! is_string( $policy['code'] ) || $facet_code !== sanitize_key( $policy['code'] ) )
        {
            return $this->invalid_policy_error( 'facet', 'code', $facet_code );
        }

        $feature_access = $this->normalize_enum_value(
            $policy,
            'feature_access',
            array_keys( self::FEATURE_ACCESS_RANKS ),
            'facet',
            $facet_code
        );
        if ( is_wp_error( $feature_access ) )
        {
            return $feature_access;
        }

        $execution_requirement = $this->normalize_enum_value(
            $policy,
            'execution_requirement',
            array_keys( self::EXECUTION_REQUIREMENT_RANKS ),
            'facet',
            $facet_code
        );
        if ( is_wp_error( $execution_requirement ) )
        {
            return $execution_requirement;
        }

        $form_source_capabilities = $this->normalize_identifier_list(
            $policy['required_form_source_capabilities'] ?? null,
            'required_form_source_capabilities',
            'facet',
            $facet_code
        );
        if ( is_wp_error( $form_source_capabilities ) )
        {
            return $form_source_capabilities;
        }

        $managed_capabilities = Sentient_Forms_Managed_Capability_Policy::normalize_required_capabilities(
            $policy['required_managed_capabilities'] ?? null
        );
        if ( is_wp_error( $managed_capabilities ) )
        {
            return $this->invalid_policy_error( 'facet', 'required_managed_capabilities', $facet_code );
        }

        $lifecycle_restrictions = $this->normalize_identifier_list(
            $policy['lifecycle_restrictions'] ?? null,
            'lifecycle_restrictions',
            'facet',
            $facet_code
        );
        if ( is_wp_error( $lifecycle_restrictions ) )
        {
            return $lifecycle_restrictions;
        }
        foreach ( $lifecycle_restrictions as $lifecycle )
        {
            if ( ! in_array( $lifecycle, Sentient_Forms_Form_Source_Lifecycles::canonical_ids(), true ) )
            {
                return $this->invalid_policy_error( 'facet', 'lifecycle_restrictions', $facet_code );
            }
        }

        $metering_class = $this->normalize_enum_value(
            $policy,
            'metering_class',
            array_keys( self::METERING_CLASS_RANKS ),
            'facet',
            $facet_code
        );
        if ( is_wp_error( $metering_class ) )
        {
            return $metering_class;
        }

        return [
            'code'                              => $facet_code,
            'feature_access'                    => $feature_access,
            'execution_requirement'             => $execution_requirement,
            'required_form_source_capabilities' => $form_source_capabilities,
            'required_managed_capabilities'     => $managed_capabilities,
            'lifecycle_restrictions'            => $lifecycle_restrictions,
            'metering_class'                    => $metering_class,
        ];
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_base_policy( array $policy ): array | WP_Error
    {
        $feature_access = $this->normalize_enum_value(
            $policy,
            'feature_access',
            array_keys( self::FEATURE_ACCESS_RANKS ),
            'base'
        );
        if ( is_wp_error( $feature_access ) )
        {
            return $feature_access;
        }

        $execution_requirement = $this->normalize_enum_value(
            $policy,
            'execution_requirement',
            array_keys( self::EXECUTION_REQUIREMENT_RANKS ),
            'base'
        );
        if ( is_wp_error( $execution_requirement ) )
        {
            return $execution_requirement;
        }

        $form_source_capabilities = $this->normalize_identifier_list(
            $policy['required_form_source_capabilities'] ?? null,
            'required_form_source_capabilities',
            'base'
        );
        if ( is_wp_error( $form_source_capabilities ) )
        {
            return $form_source_capabilities;
        }

        $managed_capabilities = Sentient_Forms_Managed_Capability_Policy::normalize_required_capabilities(
            $policy['required_managed_capabilities'] ?? null
        );
        if ( is_wp_error( $managed_capabilities ) )
        {
            return $this->invalid_policy_error( 'base', 'required_managed_capabilities' );
        }

        $lifecycles = $this->normalize_identifier_list(
            $policy['eligible_lifecycles'] ?? null,
            'eligible_lifecycles',
            'base'
        );
        if ( is_wp_error( $lifecycles ) || [] === $lifecycles )
        {
            return is_wp_error( $lifecycles )
                ? $lifecycles
                : $this->invalid_policy_error( 'base', 'eligible_lifecycles' );
        }
        foreach ( $lifecycles as $lifecycle )
        {
            if ( ! in_array( $lifecycle, Sentient_Forms_Form_Source_Lifecycles::canonical_ids(), true ) )
            {
                return $this->invalid_policy_error( 'base', 'eligible_lifecycles' );
            }
        }

        $metering_class = $this->normalize_enum_value(
            $policy,
            'metering_class',
            array_keys( self::METERING_CLASS_RANKS ),
            'base'
        );
        if ( is_wp_error( $metering_class ) )
        {
            return $metering_class;
        }

        return [
            'feature_access'                    => $feature_access,
            'execution_requirement'             => $execution_requirement,
            'required_form_source_capabilities' => $form_source_capabilities,
            'required_managed_capabilities'     => $managed_capabilities,
            'eligible_lifecycles'                => $lifecycles,
            'metering_class'                    => $metering_class,
        ];
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<int, string>   $allowed
     * @return string|WP_Error
     */
    private function normalize_enum_value(
        array $policy,
        string $field,
        array $allowed,
        string $source,
        string $facet_code = ''
    ): string | WP_Error
    {
        if ( ! array_key_exists( $field, $policy ) || ! is_string( $policy[ $field ] ) )
        {
            return $this->invalid_policy_error( $source, $field, $facet_code );
        }

        $value = sanitize_key( $policy[ $field ] );
        return in_array( $value, $allowed, true )
            ? $value
            : $this->invalid_policy_error( $source, $field, $facet_code );
    }

    /**
     * @return array<int, string>|WP_Error
     */
    private function normalize_identifier_list(
        mixed $value,
        string $field,
        string $source,
        string $facet_code = ''
    ): array | WP_Error
    {
        if ( ! is_array( $value ) )
        {
            return $this->invalid_policy_error( $source, $field, $facet_code );
        }

        $normalized = [];
        foreach ( $value as $item )
        {
            if ( ! is_string( $item ) || '' === sanitize_key( $item ) )
            {
                return $this->invalid_policy_error( $source, $field, $facet_code );
            }

            $normalized[] = sanitize_key( $item );
        }

        return array_values( array_unique( $normalized ) );
    }

    private function invalid_policy_error( string $source, string $field, string $facet_code = '' ): WP_Error
    {
        $data = [
            'status'        => 500,
            'policy_source' => $source,
            'field'         => $field,
        ];
        if ( '' !== $facet_code )
        {
            $data['facet_code'] = $facet_code;
        }

        return new WP_Error(
            'sentient_forms_action_policy_invalid',
            __( 'The Action policy is invalid.', 'sentient-forms' ),
            $data
        );
    }

    private function unknown_facet_error( string $facet_code ): WP_Error
    {
        return new WP_Error(
            'sentient_forms_action_facet_unknown',
            __( 'The enabled Action facet is not registered.', 'sentient-forms' ),
            [
                'status'     => 500,
                'facet_code' => $facet_code,
            ]
        );
    }

    /**
     * @param array<string, int> $ranks
     */
    private function strictest_value( string $left, string $right, array $ranks ): string
    {
        return $ranks[ $right ] > $ranks[ $left ] ? $right : $left;
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     * @return array<int, string>
     */
    private function unique_merge( array $left, array $right ): array
    {
        return array_values( array_unique( array_merge( $left, $right ) ) );
    }
}
