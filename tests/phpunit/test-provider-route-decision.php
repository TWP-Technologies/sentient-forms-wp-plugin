<?php

class Tests_Provider_Route_Decision extends WP_UnitTestCase
{
    public function test_subscription_gated_policy_rejects_ready_direct_route_when_subscription_is_inactive(): void
    {
        $decision = ( new Sentient_Forms_Provider_Route_Decision() )->decide(
            [
                'feature_access'        => 'active_subscription',
                'execution_requirement' => 'provider_flexible',
            ],
            [
                'subscription_active'       => false,
                'managed_ready'             => false,
                'managed_capacity_available' => false,
                'direct_ready'              => true,
                'policy_preflight_complete' => true,
            ]
        );

        $this->assertWPError( $decision );
        $this->assertSame( 'sentient_forms_provider_route_subscription_required', $decision->get_error_code() );
    }

    public function test_provider_flexible_policy_prefers_managed_then_selects_eligible_direct(): void
    {
        $router = new Sentient_Forms_Provider_Route_Decision();
        $subscription_policy = [
            'feature_access'        => 'active_subscription',
            'execution_requirement' => 'provider_flexible',
        ];

        $this->assertSame(
            [
                'provider'        => 'sentient_managed',
                'decision_reason' => 'managed_ready_with_capacity',
            ],
            $router->decide(
                $subscription_policy,
                [
                    'subscription_active'        => true,
                    'managed_ready'              => true,
                    'managed_capacity_available' => true,
                    'direct_ready'               => true,
                    'policy_preflight_complete' => true,
                ]
            )
        );

        $this->assertSame(
            [
                'provider'        => 'openrouter',
                'decision_reason' => 'direct_ready',
            ],
            $router->decide(
                $subscription_policy,
                [
                    'subscription_active'        => true,
                    'managed_ready'              => true,
                    'managed_capacity_available' => false,
                    'direct_ready'               => true,
                    'policy_preflight_complete' => true,
                ]
            )
        );

        $this->assertSame(
            [
                'provider'        => 'openrouter',
                'decision_reason' => 'direct_ready',
            ],
            $router->decide(
                [
                    'feature_access'        => 'unrestricted',
                    'execution_requirement' => 'provider_flexible',
                ],
                [
                    'subscription_active'        => false,
                    'managed_ready'              => true,
                    'managed_capacity_available' => true,
                    'direct_ready'               => true,
                    'policy_preflight_complete' => true,
                ]
            )
        );

        $unavailable = $router->decide(
            $subscription_policy,
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_available' => false,
                'direct_ready'               => false,
                'policy_preflight_complete' => true,
            ]
        );
        $this->assertWPError( $unavailable );
        $this->assertSame( 'sentient_forms_provider_route_unavailable', $unavailable->get_error_code() );
    }

    public function test_provider_flexible_policy_honors_an_explicit_ready_direct_preference(): void
    {
        $decision = ( new Sentient_Forms_Provider_Route_Decision() )->decide(
            [
                'feature_access'        => 'active_subscription',
                'execution_requirement' => 'provider_flexible',
            ],
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_available' => true,
                'direct_ready'               => true,
            ],
            'openrouter'
        );

        $this->assertSame(
            [
                'provider'        => 'openrouter',
                'decision_reason' => 'direct_preferred_and_ready',
            ],
            $decision
        );
    }

    public function test_provider_flexible_policy_honors_an_explicit_ready_managed_preference(): void
    {
        $decision = ( new Sentient_Forms_Provider_Route_Decision() )->decide(
            [
                'feature_access'        => 'unrestricted',
                'execution_requirement' => 'provider_flexible',
            ],
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_available' => true,
                'direct_ready'               => true,
            ],
            'sentient_managed'
        );

        $this->assertSame(
            [
                'provider'        => 'sentient_managed',
                'decision_reason' => 'managed_preferred_and_ready',
            ],
            $decision
        );
    }

    public function test_provider_flexible_policy_does_not_silently_replace_an_unavailable_direct_preference(): void
    {
        $decision = ( new Sentient_Forms_Provider_Route_Decision() )->decide(
            [
                'feature_access'        => 'unrestricted',
                'execution_requirement' => 'provider_flexible',
            ],
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_available' => true,
                'direct_ready'               => false,
            ],
            'openrouter'
        );

        $this->assertWPError( $decision );
        $this->assertSame( 'sentient_forms_provider_route_preferred_unavailable', $decision->get_error_code() );
        $this->assertSame( 'openrouter', $decision->get_error_data()['provider'] ?? null );
    }

    public function test_managed_preference_uses_ready_direct_route_when_managed_capacity_is_unavailable(): void
    {
        $decision = ( new Sentient_Forms_Provider_Route_Decision() )->decide(
            [
                'feature_access'        => 'unrestricted',
                'execution_requirement' => 'provider_flexible',
            ],
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_available' => false,
                'direct_ready'               => true,
            ],
            'sentient_managed'
        );

        $this->assertSame(
            [
                'provider'        => 'openrouter',
                'decision_reason' => 'managed_preferred_without_capacity_direct_ready',
            ],
            $decision
        );
    }

    public function test_managed_preference_does_not_treat_unknown_capacity_as_zero_capacity(): void
    {
        $decision = ( new Sentient_Forms_Provider_Route_Decision() )->decide(
            [
                'feature_access'        => 'unrestricted',
                'execution_requirement' => 'provider_flexible',
            ],
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_known'     => false,
                'managed_capacity_available' => false,
                'direct_ready'               => true,
            ],
            'sentient_managed'
        );

        $this->assertWPError( $decision );
        $this->assertSame( 'sentient_forms_provider_route_managed_capacity_unknown', $decision->get_error_code() );
    }

    public function test_managed_only_policy_requires_subscription_readiness_and_capacity_without_direct_fallback(): void
    {
        $router = new Sentient_Forms_Provider_Route_Decision();
        $policy = [
            'feature_access'        => 'unrestricted',
            'execution_requirement' => 'managed_only',
        ];

        $inactive = $router->decide(
            $policy,
            [
                'subscription_active'        => false,
                'managed_ready'              => true,
                'managed_capacity_available' => true,
                'direct_ready'               => true,
                'policy_preflight_complete' => true,
            ]
        );
        $this->assertWPError( $inactive );
        $this->assertSame( 'sentient_forms_provider_route_subscription_required', $inactive->get_error_code() );

        $not_ready = $router->decide(
            $policy,
            [
                'subscription_active'        => true,
                'managed_ready'              => false,
                'managed_capacity_available' => true,
                'direct_ready'               => true,
                'policy_preflight_complete' => true,
            ]
        );
        $this->assertWPError( $not_ready );
        $this->assertSame( 'sentient_forms_provider_route_managed_unavailable', $not_ready->get_error_code() );

        $no_capacity = $router->decide(
            $policy,
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_available' => false,
                'direct_ready'               => true,
                'policy_preflight_complete' => true,
            ]
        );
        $this->assertWPError( $no_capacity );
        $this->assertSame( 'sentient_forms_provider_route_managed_capacity_unavailable', $no_capacity->get_error_code() );

        $this->assertSame(
            [
                'provider'        => 'sentient_managed',
                'decision_reason' => 'managed_required_and_ready',
            ],
            $router->decide(
                $policy,
                [
                    'subscription_active'        => true,
                    'managed_ready'              => true,
                    'managed_capacity_available' => true,
                    'direct_ready'               => false,
                    'policy_preflight_complete' => true,
                ]
            )
        );
    }

    public function test_managed_only_policy_rejects_a_saved_direct_preference(): void
    {
        $decision = ( new Sentient_Forms_Provider_Route_Decision() )->decide(
            [
                'feature_access'        => 'active_subscription',
                'execution_requirement' => 'managed_only',
            ],
            [
                'subscription_active'        => true,
                'managed_ready'              => true,
                'managed_capacity_available' => true,
                'direct_ready'               => true,
            ],
            'openrouter'
        );

        $this->assertWPError( $decision );
        $this->assertSame( 'sentient_forms_provider_route_preference_incompatible', $decision->get_error_code() );
        $this->assertSame( 'openrouter', $decision->get_error_data()['provider'] ?? null );
    }

    public function test_malformed_policy_or_runtime_state_fails_closed(): void
    {
        $router = new Sentient_Forms_Provider_Route_Decision();
        $state = [
            'subscription_active'        => true,
            'managed_ready'              => false,
            'managed_capacity_available' => false,
            'direct_ready'               => true,
            'policy_preflight_complete' => true,
        ];

        $invalid_policy = $router->decide(
            [
                'feature_access'        => 'licensed',
                'execution_requirement' => 'provider_flexible',
            ],
            $state
        );
        $this->assertWPError( $invalid_policy );
        $this->assertSame( 'sentient_forms_provider_route_policy_invalid', $invalid_policy->get_error_code() );
        $this->assertSame( 'feature_access', $invalid_policy->get_error_data()['field'] ?? null );

        $missing_state = $state;
        unset( $missing_state['direct_ready'] );
        $missing_state_decision = $router->decide(
            [
                'feature_access'        => 'unrestricted',
                'execution_requirement' => 'provider_flexible',
            ],
            $missing_state
        );
        $this->assertWPError( $missing_state_decision );
        $this->assertSame( 'sentient_forms_provider_route_state_invalid', $missing_state_decision->get_error_code() );
        $this->assertSame( 'direct_ready', $missing_state_decision->get_error_data()['field'] ?? null );

        $non_boolean_state = $state;
        $non_boolean_state['managed_ready'] = 1;
        $non_boolean_state_decision = $router->decide(
            [
                'feature_access'        => 'unrestricted',
                'execution_requirement' => 'provider_flexible',
            ],
            $non_boolean_state
        );
        $this->assertWPError( $non_boolean_state_decision );
        $this->assertSame( 'sentient_forms_provider_route_state_invalid', $non_boolean_state_decision->get_error_code() );
        $this->assertSame( 'managed_ready', $non_boolean_state_decision->get_error_data()['field'] ?? null );

        $invalid_preference = $router->decide(
            [
                'feature_access'        => 'unrestricted',
                'execution_requirement' => 'provider_flexible',
            ],
            $state,
            'legacy_proxy'
        );
        $this->assertWPError( $invalid_preference );
        $this->assertSame( 'sentient_forms_provider_route_preference_invalid', $invalid_preference->get_error_code() );
    }

    public function test_router_rejects_provider_selection_without_completed_policy_preflight(): void
    {
        $router = new Sentient_Forms_Provider_Route_Decision();
        $policy = [
            'feature_access'        => 'unrestricted',
            'execution_requirement' => 'provider_flexible',
        ];
        $state = [
            'subscription_active'        => false,
            'managed_ready'              => false,
            'managed_capacity_available' => false,
            'direct_ready'               => true,
        ];

        $missing = $router->decide( $policy, $state );
        $this->assertWPError( $missing );
        $this->assertSame( 'sentient_forms_provider_route_state_invalid', $missing->get_error_code() );
        $this->assertSame( 'policy_preflight_complete', $missing->get_error_data()['field'] ?? null );

        $state['policy_preflight_complete'] = false;
        $incomplete = $router->decide( $policy, $state );
        $this->assertWPError( $incomplete );
        $this->assertSame( 'sentient_forms_provider_route_policy_not_preflighted', $incomplete->get_error_code() );
    }
}
