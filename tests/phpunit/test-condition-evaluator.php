<?php

class Tests_Condition_Evaluator extends WP_UnitTestCase
{
    private Sentient_Forms_Condition_Evaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new Sentient_Forms_Condition_Evaluator();
    }

    public function test_should_execute_returns_true_without_conditions(): void
    {
        $this->assertTrue( $this->evaluator->should_execute( [], [ '1' => 'hello' ] ) );
    }

    public function test_should_execute_returns_true_when_conditions_disabled(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => false,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [],
                    ],
                ],
            ],
        ];

        $this->assertTrue( $this->evaluator->should_execute( $settings, [ '1' => 'hello' ] ) );
    }

    public function test_should_execute_supports_nested_groups_and_numeric_operator(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [
                            [
                                'type'     => 'rule',
                                'field_id' => '1',
                                'operator' => 'contains',
                                'value'    => 'ticket',
                            ],
                            [
                                'type'  => 'group',
                                'logic' => 'any',
                                'rules' => [
                                    [
                                        'type'     => 'rule',
                                        'field_id' => '2',
                                        'operator' => 'gte',
                                        'value'    => 5,
                                    ],
                                    [
                                        'type'     => 'rule',
                                        'field_id' => '3',
                                        'operator' => 'eq',
                                        'value'    => 'priority',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $entry = [
            '1' => 'Support ticket request',
            '2' => '4',
            '3' => 'Priority',
        ];

        $this->assertTrue( $this->evaluator->should_execute( $settings, $entry ) );
    }

    public function test_should_execute_returns_false_when_field_missing_for_value_operator(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [
                            [
                                'type'     => 'rule',
                                'field_id' => '77',
                                'operator' => 'eq',
                                'value'    => 'run',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertFalse( $this->evaluator->should_execute( $settings, [ '1' => 'run' ] ) );
    }

    public function test_should_execute_treats_missing_field_as_empty_for_is_empty(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [
                            [
                                'type'     => 'rule',
                                'field_id' => '99',
                                'operator' => 'is_empty',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertTrue( $this->evaluator->should_execute( $settings, [ '1' => 'hello' ] ) );
    }

    public function test_should_execute_returns_false_for_invalid_numeric_compare(): void
    {
        $settings = [
            'settings' => [
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
                                'value'    => 10,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertFalse( $this->evaluator->should_execute( $settings, [ '2' => 'abc' ] ) );
    }

    public function test_should_execute_fails_closed_when_enabled_root_missing(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                ],
            ],
        ];

        $this->assertFalse( $this->evaluator->should_execute( $settings, [ '1' => 'hello' ] ) );
    }

    public function test_should_execute_allows_rules_inside_depth_three_group(): void
    {
        $settings = [
            'settings' => [
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
                                                'field_id' => '3',
                                                'operator' => 'eq',
                                                'value'    => 'approved',
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

        $this->assertTrue( $this->evaluator->should_execute( $settings, [ '3' => 'Approved' ] ) );
    }

    public function test_should_execute_fails_closed_when_group_depth_exceeds_limit(): void
    {
        $settings = [
            'settings' => [
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
                                                        'field_id' => '4',
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
            ],
        ];

        $this->assertFalse( $this->evaluator->should_execute( $settings, [ '4' => 'run' ] ) );
    }

    public function test_should_execute_fails_closed_when_node_limit_exceeded(): void
    {
        $rules = [];
        for ( $index = 0; $index < 51; $index++ )
        {
            $rules[] = [
                'type'     => 'rule',
                'field_id' => (string) ( 100 + $index ),
                'operator' => 'is_empty',
            ];
        }

        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => $rules,
                    ],
                ],
            ],
        ];

        $this->assertFalse( $this->evaluator->should_execute( $settings, [] ) );
    }

    public function test_should_execute_supports_remaining_string_list_and_numeric_operators(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [
                            [
                                'type'     => 'rule',
                                'field_id' => '1',
                                'operator' => 'neq',
                                'value'    => 'bye',
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '2',
                                'operator' => 'not_contains',
                                'value'    => 'zzz',
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '3',
                                'operator' => 'starts_with',
                                'value'    => 'prefix',
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '4',
                                'operator' => 'ends_with',
                                'value'    => 'suffix',
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '5',
                                'operator' => 'in',
                                'value'    => [ 'sales', 'billing' ],
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '6',
                                'operator' => 'not_in',
                                'value'    => [ 'sales', 'billing' ],
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '7',
                                'operator' => 'is_not_empty',
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '8',
                                'operator' => 'lt',
                                'value'    => 10,
                            ],
                            [
                                'type'     => 'rule',
                                'field_id' => '9',
                                'operator' => 'lte',
                                'value'    => 3,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $entry = [
            '1' => 'hello world',
            '2' => 'hello world',
            '3' => 'PrefixValue',
            '4' => 'valueSuffix',
            '5' => 'sales',
            '6' => 'support',
            '7' => 'present',
            '8' => '2',
            '9' => '3',
        ];

        $this->assertTrue( $this->evaluator->should_execute( $settings, $entry ) );
    }

    public function test_evaluate_with_trace_returns_condition_tree_for_match(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [
                            [
                                'type'     => 'rule',
                                'field_id' => '1',
                                'operator' => 'contains',
                                'value'    => 'urgent',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $trace = $this->evaluator->evaluate_with_trace(
            $settings,
            [
                '1' => 'Urgent follow-up needed',
            ]
        );

        $this->assertTrue( $trace['should_execute'] );
        $this->assertTrue( $trace['enabled'] );
        $this->assertTrue( $trace['evaluated'] );
        $this->assertTrue( $trace['matched'] );
        $this->assertSame( 'matched', $trace['reason_code'] );
        $this->assertIsArray( $trace['tree'] );
        $this->assertSame( 'group', $trace['tree']['type'] ?? null );
        $this->assertSame( true, $trace['tree']['result'] ?? null );
    }

    public function test_evaluate_with_trace_reports_condition_false_and_tree_details(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [
                            [
                                'type'     => 'rule',
                                'field_id' => '4',
                                'operator' => 'eq',
                                'value'    => 'approved',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $trace = $this->evaluator->evaluate_with_trace( $settings, [ '4' => 'pending' ] );

        $this->assertFalse( $trace['should_execute'] );
        $this->assertTrue( $trace['enabled'] );
        $this->assertTrue( $trace['evaluated'] );
        $this->assertFalse( $trace['matched'] );
        $this->assertSame( 'condition_false', $trace['reason_code'] );
        $this->assertIsArray( $trace['tree'] );
        $this->assertSame( false, $trace['tree']['result'] ?? null );

        $child = $trace['tree']['children'][0] ?? null;
        $this->assertIsArray( $child );
        $this->assertSame( 'rule', $child['type'] ?? null );
        $this->assertSame( 'comparison_failed', $child['reason_code'] ?? null );
    }

    public function test_evaluate_with_trace_fails_closed_when_root_is_invalid(): void
    {
        $settings = [
            'settings' => [
                'conditions' => [
                    'enabled' => true,
                    'root'    => 'invalid',
                ],
            ],
        ];

        $trace = $this->evaluator->evaluate_with_trace( $settings, [ '1' => 'value' ] );

        $this->assertFalse( $trace['should_execute'] );
        $this->assertTrue( $trace['enabled'] );
        $this->assertFalse( $trace['evaluated'] );
        $this->assertFalse( $trace['matched'] );
        $this->assertSame( 'invalid_root', $trace['reason_code'] );
        $this->assertNull( $trace['tree'] );
    }
}
