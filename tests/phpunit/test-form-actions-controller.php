<?php

class Tests_Form_Actions_Controller extends WP_UnitTestCase {
    private Sentient_Forms_Form_Actions_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        $this->controller = new Sentient_Forms_Form_Actions_Controller();
    }

    public function test_validate_trigger_hooks_accepts_allowed_values(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'gform_validation', 'gform_after_submission' ],
            $request,
            'trigger_hooks'
        );

        $this->assertTrue( $result );
    }

    public function test_validate_trigger_hooks_rejects_unknown_hook(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'gform_bogus_hook' ],
            $request,
            'trigger_hooks'
        );

        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_hook', $result->get_error_code() );
    }

    public function test_add_form_action_sanitizes_trigger_hooks(): void {
        delete_option( 'sentient_forms_actions_gravity_forms_1' );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation', 'evil_hook', 'gform_validation' ] );

        $response = $this->controller->add_form_action( $request );
        $data     = $response->get_data();

        $this->assertSame( [ 'gform_validation' ], $data['trigger_hooks'] );
    }

    public function test_sanitize_settings_drops_batch_discount_and_clamps_delay(): void
    {
        $settings = [
            'batch_settings' => [
                'enabled'          => true,
                'delay_seconds'    => 1,
                'discount_percent' => 95,
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];

        $this->assertSame( true, $batch['enabled'] ?? null );
        $this->assertSame( 10, $batch['delay_seconds'] ?? null );
        $this->assertSame( DAY_IN_SECONDS, $batch['max_wait_seconds'] ?? null );
        $this->assertArrayNotHasKey( 'discount_percent', $batch );
    }

    public function test_sanitize_settings_clamps_batch_delay_upper_bound(): void
    {
        $settings = [
            'batch_settings' => [
                'enabled'       => true,
                'delay_seconds' => 99999,
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];

        $this->assertSame( 3600, $batch['delay_seconds'] ?? null );
        $this->assertSame( DAY_IN_SECONDS, $batch['max_wait_seconds'] ?? null );
    }

    public function test_sanitize_settings_clamps_batch_max_wait_bounds(): void
    {
        $settings = [
            'batch_settings' => [
                'enabled'          => true,
                'delay_seconds'    => 60,
                'max_wait_seconds' => 10,
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];
        $this->assertSame( 43200, $batch['max_wait_seconds'] ?? null );

        $settings['batch_settings']['max_wait_seconds'] = 9999999;
        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];
        $this->assertSame( 604800, $batch['max_wait_seconds'] ?? null );
    }

    public function test_sanitize_settings_conditions_keeps_nested_rules_and_numeric_values(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'any',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '1',
                            'operator' => 'contains',
                            'value'    => 'urgent',
                        ],
                        [
                            'type'  => 'group',
                            'logic' => 'all',
                            'rules' => [
                                [
                                    'type'     => 'rule',
                                    'field_id' => '2',
                                    'operator' => 'gte',
                                    'value'    => '10.5',
                                ],
                                [
                                    'type'     => 'rule',
                                    'field_id' => '3',
                                    'operator' => 'in',
                                    'value'    => [ 'sales', 'billing', '' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $sanitized  = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $conditions = $sanitized['conditions'] ?? null;

        $this->assertIsArray( $conditions );
        $this->assertTrue( $conditions['enabled'] ?? false );
        $this->assertSame( 'group', $conditions['root']['type'] ?? null );
        $this->assertSame( 'any', $conditions['root']['logic'] ?? null );

        $nested_rules = $conditions['root']['rules'][1]['rules'] ?? [];
        $this->assertSame( 10.5, $nested_rules[0]['value'] ?? null );
        $this->assertSame( [ 'sales', 'billing' ], $nested_rules[1]['value'] ?? [] );
    }

    public function test_sanitize_settings_conditions_drops_invalid_rules(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '',
                            'operator' => 'eq',
                            'value'    => 'x',
                        ],
                        [
                            'type'     => 'rule',
                            'field_id' => '4',
                            'operator' => 'invalid_operator',
                            'value'    => 'x',
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $rules     = $sanitized['conditions']['root']['rules'] ?? [];

        $this->assertSame( [], $rules );
    }

    public function test_sanitize_settings_conditions_preserves_rule_inside_depth_three_group(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'  => 'group',
                            'logic' => 'all',
                            'rules' => [
                                [
                                    'type'  => 'group',
                                    'logic' => 'all',
                                    'rules' => [
                                        [
                                            'type'     => 'rule',
                                            'field_id' => '9',
                                            'operator' => 'eq',
                                            'value'    => 'run',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $rule      = $sanitized['conditions']['root']['rules'][0]['rules'][0]['rules'][0] ?? null;

        $this->assertIsArray( $rule );
        $this->assertSame( '9', $rule['field_id'] ?? null );
        $this->assertSame( 'eq', $rule['operator'] ?? null );
        $this->assertSame( 'run', $rule['value'] ?? null );
    }

    public function test_sanitize_settings_conditions_prunes_nodes_deeper_than_depth_limit(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'  => 'group',
                            'logic' => 'all',
                            'rules' => [
                                [
                                    'type'  => 'group',
                                    'logic' => 'all',
                                    'rules' => [
                                        [
                                            'type'  => 'group',
                                            'logic' => 'all',
                                            'rules' => [
                                                [
                                                    'type'     => 'rule',
                                                    'field_id' => '10',
                                                    'operator' => 'eq',
                                                    'value'    => 'run',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $rules     = $sanitized['conditions']['root']['rules'][0]['rules'][0]['rules'] ?? [];

        $this->assertSame( [], $rules );
    }

    public function test_sanitize_settings_conditions_enforces_node_limit(): void
    {
        $rules = [];
        for ( $index = 0; $index < 60; $index++ )
        {
            $rules[] = [
                'type'     => 'rule',
                'field_id' => (string) ( $index + 1 ),
                'operator' => 'is_empty',
            ];
        }

        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => $rules,
                ],
            ],
        ];

        $sanitized_rules = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'] ?? [];

        $this->assertLessThanOrEqual( 49, count( $sanitized_rules ) );
        $this->assertNotEmpty( $sanitized_rules );
    }

    public function test_sanitize_settings_conditions_keeps_is_empty_without_value(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '5',
                            'operator' => 'is_empty',
                        ],
                    ],
                ],
            ],
        ];

        $rule = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'][0] ?? null;
        $this->assertIsArray( $rule );
        $this->assertSame( 'is_empty', $rule['operator'] ?? null );
        $this->assertArrayNotHasKey( 'value', $rule );
    }

    public function test_sanitize_settings_conditions_drops_eq_rule_when_value_missing(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '5',
                            'operator' => 'eq',
                        ],
                    ],
                ],
            ],
        ];

        $rules = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'] ?? [];
        $this->assertSame( [], $rules );
    }

    public function test_sanitize_settings_conditions_drops_numeric_rule_when_value_not_numeric(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '2',
                            'operator' => 'gt',
                            'value'    => 'not-a-number',
                        ],
                    ],
                ],
            ],
        ];

        $rules = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'] ?? [];
        $this->assertSame( [], $rules );
    }

    // =========================================================================
    // CB-FORMS-001: Per-Form Master Disable Tests
    // =========================================================================

    /**
     * CB-FORMS-001: sf_disabled flag must NOT leak into the actions array.
     *
     * The sf_disabled boolean lives in the same WP option as action linkages.
     * get_form_actions MUST filter it out, otherwise the frontend receives a
     * phantom "action" whose value is `true` instead of an action object.
     */
    public function test_get_form_actions_excludes_sf_disabled_from_response(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_1';

        // Simulate a form with sf_disabled = true and one real action.
        update_option( $option_key, [
            'sf_disabled' => true,
            'map_spam_v1' => [
                'local_mapping_id'           => 'map_spam_v1',
                'central_action_id'          => 'spam_detection_v1',
                'action_type_indicator'      => 'master',
                'is_action_enabled_for_form' => true,
                'trigger_hooks'              => [ 'gform_validation' ],
            ],
        ] );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions( $request );
        $data     = $response->get_data();

        // Response should be an array of action linkages only — no sf_disabled key.
        $this->assertIsArray( $data );

        // Verify none of the entries is the boolean `true` (the sf_disabled value).
        foreach ( $data as $item ) {
            $this->assertIsArray( $item, 'Every item in get_form_actions must be an action array, not a scalar' );
        }

        // At least one real action should survive.
        $action_ids = array_column( $data, 'central_action_id' );
        $this->assertContains( 'spam_detection_v1', $action_ids );

        delete_option( $option_key );
    }

    /**
     * CB-FORMS-001: toggle_form_disabled round-trip — set, read, clear.
     */
    public function test_toggle_form_disabled_round_trip(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_2';
        delete_option( $option_key );

        // — Enable disable flag
        $put_request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/2/actions/disable' );
        $put_request->set_param( 'form_source_slug', 'gravity_forms' );
        $put_request->set_param( 'form_id', 2 );
        $put_request->set_param( 'sf_disabled', true );

        $response = $this->controller->toggle_form_disabled( $put_request );
        $data     = $response->get_data();
        $this->assertTrue( $data['sf_disabled'], 'After toggling ON, sf_disabled should be true' );

        // — READ it back via get_form_disabled
        $get_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/2/actions/disable' );
        $get_request->set_param( 'form_source_slug', 'gravity_forms' );
        $get_request->set_param( 'form_id', 2 );

        $response = $this->controller->get_form_disabled( $get_request );
        $data     = $response->get_data();
        $this->assertTrue( $data['sf_disabled'], 'GET should reflect the stored disabled state' );

        // — Disable it again
        $put_request->set_param( 'sf_disabled', false );
        $response = $this->controller->toggle_form_disabled( $put_request );
        $data     = $response->get_data();
        $this->assertFalse( $data['sf_disabled'], 'After toggling OFF, sf_disabled should be false' );

        // — Verify GET reflects the cleared state
        $response = $this->controller->get_form_disabled( $get_request );
        $data     = $response->get_data();
        $this->assertFalse( $data['sf_disabled'], 'GET should reflect the cleared disabled state' );

        delete_option( $option_key );
    }

    public function test_get_form_disabled_includes_global_and_provider_disable_flags(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_3';
        delete_option( $option_key );
        update_option( $option_key, [ 'sf_disabled' => false ] );

        update_option(
            'sentient_forms_plugin_settings',
            [
                'execution_global_disabled'   => true,
                'execution_provider_disabled' => [ 'gravity_forms' => true ],
            ]
        );

        $get_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/3/actions/disable' );
        $get_request->set_param( 'form_source_slug', 'gravity_forms' );
        $get_request->set_param( 'form_id', 3 );

        $response = $this->controller->get_form_disabled( $get_request );
        $data     = $response->get_data();

        $this->assertFalse( $data['sf_disabled'] );
        $this->assertTrue( $data['global_disabled'] );
        $this->assertTrue( $data['provider_disabled'] );
        $this->assertTrue( $data['effective_disabled'] );

        delete_option( $option_key );
        delete_option( 'sentient_forms_plugin_settings' );
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invoke_private( string $method, array $args = [] ): mixed
    {
        $reflection = new ReflectionMethod( $this->controller, $method );
        $reflection->setAccessible( true );

        return $reflection->invokeArgs( $this->controller, $args );
    }
}
