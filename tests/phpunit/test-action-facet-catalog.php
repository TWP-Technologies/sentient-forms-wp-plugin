<?php

class Tests_Action_Facet_Catalog extends WP_UnitTestCase
{
    public function test_default_catalog_registers_only_spam_guidance_rationale_generation(): void
    {
        $catalog = new Sentient_Forms_Action_Facet_Catalog();

        $this->assertSame( [ 'spam_guidance_rationale_generation' ], $catalog->codes() );
        $this->assertSame(
            [
                'code'                              => 'spam_guidance_rationale_generation',
                'feature_access'                    => 'active_subscription',
                'execution_requirement'             => 'provider_flexible',
                'required_form_source_capabilities' => [],
                'required_managed_capabilities'     => [],
                'lifecycle_restrictions'            => [],
                'metering_class'                    => 'standard',
            ],
            $catalog->get( 'spam_guidance_rationale_generation' )
        );
    }

    public function test_effective_policy_composes_base_and_enabled_facet_with_strictest_requirements(): void
    {
        $catalog = new Sentient_Forms_Action_Facet_Catalog(
            [
                'test_managed_validation' => [
                    'code'                              => 'test_managed_validation',
                    'feature_access'                    => 'active_subscription',
                    'execution_requirement'             => 'managed_only',
                    'required_form_source_capabilities' => [ 'field_errors', 'accepted_submission' ],
                    'required_managed_capabilities'     => [ 'tool_budget', 'base_limit' ],
                    'lifecycle_restrictions'            => [ 'validation', 'real_time' ],
                    'metering_class'                    => 'secondary_preflight',
                ],
            ]
        );
        $resolver = new Sentient_Forms_Action_Policy_Resolver( $catalog );

        $resolved = $resolver->resolve(
            [
                'feature_access'                    => 'unrestricted',
                'execution_requirement'             => 'provider_flexible',
                'required_form_source_capabilities' => [ 'accepted_submission' ],
                'required_managed_capabilities'     => [ 'base_limit' ],
                'eligible_lifecycles'                => [ 'validation', 'after_submission' ],
                'metering_class'                    => 'standard',
            ],
            [ 'test_managed_validation' ]
        );

        $this->assertSame(
            [
                'feature_access'                    => 'active_subscription',
                'execution_requirement'             => 'managed_only',
                'required_form_source_capabilities' => [ 'accepted_submission', 'field_errors' ],
                'required_managed_capabilities'     => [ 'base_limit', 'tool_budget' ],
                'eligible_lifecycles'                => [ 'validation' ],
                'metering_class'                    => 'secondary_preflight',
            ],
            $resolved
        );
    }

    public function test_effective_policy_fails_closed_for_unknown_facet(): void
    {
        $resolver = new Sentient_Forms_Action_Policy_Resolver();

        $resolved = $resolver->resolve(
            [
                'feature_access'                    => 'unrestricted',
                'execution_requirement'             => 'provider_flexible',
                'required_form_source_capabilities' => [],
                'required_managed_capabilities'     => [],
                'eligible_lifecycles'                => [ 'after_submission' ],
                'metering_class'                    => 'standard',
            ],
            [ 'not_registered' ]
        );

        $this->assertWPError( $resolved );
        $this->assertSame( 'sentient_forms_action_facet_unknown', $resolved->get_error_code() );
        $this->assertSame( 'not_registered', $resolved->get_error_data()['facet_code'] ?? null );
    }

    public function test_action_definition_fails_closed_for_registered_but_not_allowed_facet(): void
    {
        $resolver = new Sentient_Forms_Action_Policy_Resolver();

        $resolved = $resolver->resolve_action_definition(
            [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            [ 'spam_guidance_rationale_generation' ]
        );

        $this->assertWPError( $resolved );
        $this->assertSame( 'sentient_forms_action_facet_not_allowed', $resolved->get_error_code() );
        $this->assertSame( 'spam_guidance_rationale_generation', $resolved->get_error_data()['facet_code'] ?? null );
    }

    public function test_effective_policy_fails_closed_when_facet_lifecycles_do_not_overlap_base(): void
    {
        $catalog = new Sentient_Forms_Action_Facet_Catalog(
            [
                'validation_only' => [
                    'code'                              => 'validation_only',
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [],
                    'required_managed_capabilities'     => [],
                    'lifecycle_restrictions'            => [ 'validation' ],
                    'metering_class'                    => 'standard',
                ],
            ]
        );
        $resolver = new Sentient_Forms_Action_Policy_Resolver( $catalog );

        $resolved = $resolver->resolve(
            [
                'feature_access'                    => 'unrestricted',
                'execution_requirement'             => 'provider_flexible',
                'required_form_source_capabilities' => [],
                'required_managed_capabilities'     => [],
                'eligible_lifecycles'                => [ 'after_submission' ],
                'metering_class'                    => 'standard',
            ],
            [ 'validation_only' ]
        );

        $this->assertWPError( $resolved );
        $this->assertSame( 'sentient_forms_action_policy_lifecycle_conflict', $resolved->get_error_code() );
        $this->assertSame( 'validation_only', $resolved->get_error_data()['facet_code'] ?? null );
    }

    public function test_effective_policy_fails_closed_for_invalid_base_policy(): void
    {
        $resolver = new Sentient_Forms_Action_Policy_Resolver();

        $resolved = $resolver->resolve(
            [
                'feature_access'                    => 'members_only',
                'execution_requirement'             => 'provider_flexible',
                'required_form_source_capabilities' => [],
                'required_managed_capabilities'     => [],
                'eligible_lifecycles'                => [ 'after_submission' ],
                'metering_class'                    => 'standard',
            ]
        );

        $this->assertWPError( $resolved );
        $this->assertSame( 'sentient_forms_action_policy_invalid', $resolved->get_error_code() );
        $this->assertSame( 'base', $resolved->get_error_data()['policy_source'] ?? null );
        $this->assertSame( 'feature_access', $resolved->get_error_data()['field'] ?? null );
    }

    public function test_effective_policy_fails_closed_for_invalid_facet_policy(): void
    {
        $catalog = new Sentient_Forms_Action_Facet_Catalog(
            [
                'invalid_route' => [
                    'code'                              => 'invalid_route',
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'cps_preferred',
                    'required_form_source_capabilities' => [],
                    'required_managed_capabilities'     => [],
                    'lifecycle_restrictions'            => [],
                    'metering_class'                    => 'standard',
                ],
            ]
        );
        $resolver = new Sentient_Forms_Action_Policy_Resolver( $catalog );

        $resolved = $resolver->resolve(
            [
                'feature_access'                    => 'unrestricted',
                'execution_requirement'             => 'provider_flexible',
                'required_form_source_capabilities' => [],
                'required_managed_capabilities'     => [],
                'eligible_lifecycles'                => [ 'after_submission' ],
                'metering_class'                    => 'standard',
            ],
            [ 'invalid_route' ]
        );

        $this->assertWPError( $resolved );
        $this->assertSame( 'sentient_forms_action_policy_invalid', $resolved->get_error_code() );
        $this->assertSame( 'facet', $resolved->get_error_data()['policy_source'] ?? null );
        $this->assertSame( 'execution_requirement', $resolved->get_error_data()['field'] ?? null );
        $this->assertSame( 'invalid_route', $resolved->get_error_data()['facet_code'] ?? null );
    }
}
