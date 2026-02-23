<?php
/**
 * Mapping condition evaluator.
 *
 * Evaluates per-mapping conditional run rules against scalar entry values.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Condition_Evaluator
 */
class Sentient_Forms_Condition_Evaluator
{
    private const MAX_DEPTH = 3;
    private const MAX_NODES = 50;

    private const OPERATORS = [
        'eq',
        'neq',
        'contains',
        'not_contains',
        'starts_with',
        'ends_with',
        'in',
        'not_in',
        'is_empty',
        'is_not_empty',
        'gt',
        'gte',
        'lt',
        'lte',
    ];

    /**
     * Determine whether the mapping should execute for the provided entry.
     *
     * If no conditions are configured (or conditions are disabled), this returns true.
     * When conditions are enabled but malformed, this fails closed and returns false.
     *
     * @param array $action_settings Mapping linkage settings.
     * @param array $entry           Form entry payload.
     *
     * @return bool
     */
    public function should_execute( array $action_settings, array $entry ): bool
    {
        $trace = $this->evaluate_with_trace( $action_settings, $entry );
        return ! empty( $trace['should_execute'] );
    }

    /**
     * Evaluate conditions with explainability metadata for request tracing.
     *
     * @param array $action_settings Mapping linkage settings.
     * @param array $entry           Form entry payload.
     *
     * @return array{
     *     should_execute: bool,
     *     enabled: bool,
     *     evaluated: bool,
     *     matched: bool,
     *     reason_code: string,
     *     summary: string,
     *     tree: array<string, mixed>|null
     * }
     */
    public function evaluate_with_trace( array $action_settings, array $entry ): array
    {
        $conditions = $this->extract_conditions( $action_settings );
        if ( null === $conditions )
        {
            return [
                'should_execute' => true,
                'enabled'        => false,
                'evaluated'      => false,
                'matched'        => true,
                'reason_code'    => 'no_conditions',
                'summary'        => 'No conditional run rules are configured.',
                'tree'           => null,
            ];
        }

        $enabled = ! empty( $conditions['enabled'] );
        if ( ! $enabled )
        {
            return [
                'should_execute' => true,
                'enabled'        => false,
                'evaluated'      => false,
                'matched'        => true,
                'reason_code'    => 'conditions_disabled',
                'summary'        => 'Conditional run is disabled for this mapping.',
                'tree'           => null,
            ];
        }

        if ( ! isset( $conditions['root'] ) || ! is_array( $conditions['root'] ) )
        {
            return [
                'should_execute' => false,
                'enabled'        => true,
                'evaluated'      => false,
                'matched'        => false,
                'reason_code'    => 'invalid_root',
                'summary'        => 'Condition tree root is missing or invalid.',
                'tree'           => null,
            ];
        }

        $node_count = 0;
        if ( ! $this->shape_is_within_limits( $conditions['root'], 1, $node_count ) )
        {
            return [
                'should_execute' => false,
                'enabled'        => true,
                'evaluated'      => false,
                'matched'        => false,
                'reason_code'    => 'shape_limits_exceeded',
                'summary'        => 'Condition tree exceeds allowed depth or node limits.',
                'tree'           => null,
            ];
        }

        $entry_values = $this->extract_scalar_entry_values( $entry );
        $tree         = $this->evaluate_node_with_trace( $conditions['root'], $entry_values, 1 );
        $matched      = ! empty( $tree['result'] );

        return [
            'should_execute' => $matched,
            'enabled'        => true,
            'evaluated'      => true,
            'matched'        => $matched,
            'reason_code'    => $matched ? 'matched' : 'condition_false',
            'summary'        => $matched
                ? 'Condition rules matched this request.'
                : 'Condition rules did not match this request.',
            'tree'           => $tree,
        ];
    }

    /**
     * Evaluate a node recursively and emit explainability data.
     *
     * @param array $node         Condition node.
     * @param array $entry_values Entry scalar values.
     * @param int   $depth        Current recursion depth.
     *
     * @return array<string, mixed>
     */
    private function evaluate_node_with_trace( array $node, array $entry_values, int $depth ): array
    {
        if ( $depth > self::MAX_DEPTH )
        {
            return [
                'type'        => 'invalid',
                'result'      => false,
                'reason_code' => 'depth_limit',
            ];
        }

        $type = sanitize_key( (string) ( $node['type'] ?? '' ) );
        if ( 'group' === $type )
        {
            return $this->evaluate_group_with_trace( $node, $entry_values, $depth );
        }

        if ( 'rule' === $type )
        {
            return $this->evaluate_rule_with_trace( $node, $entry_values );
        }

        // Support legacy nodes that omitted `type`.
        if ( isset( $node['rules'] ) && is_array( $node['rules'] ) )
        {
            return $this->evaluate_group_with_trace( $node, $entry_values, $depth );
        }

        if ( isset( $node['field_id'] ) && isset( $node['operator'] ) )
        {
            return $this->evaluate_rule_with_trace( $node, $entry_values );
        }

        return [
            'type'        => 'invalid',
            'result'      => false,
            'reason_code' => 'invalid_node',
        ];
    }

    /**
     * Evaluate a group node with explainability output.
     *
     * @param array $group        Group node.
     * @param array $entry_values Entry scalar values.
     * @param int   $depth        Current recursion depth.
     *
     * @return array<string, mixed>
     */
    private function evaluate_group_with_trace( array $group, array $entry_values, int $depth ): array
    {
        $logic = sanitize_key( (string) ( $group['logic'] ?? 'all' ) );
        $logic = in_array( $logic, [ 'all', 'any' ], true ) ? $logic : 'all';
        $rules = isset( $group['rules'] ) && is_array( $group['rules'] ) ? $group['rules'] : [];

        $children = [];
        foreach ( $rules as $child )
        {
            if ( ! is_array( $child ) )
            {
                $children[] = [
                    'type'        => 'invalid',
                    'result'      => false,
                    'reason_code' => 'invalid_child',
                ];
                continue;
            }

            $child_depth = $this->is_group_node( $child ) ? $depth + 1 : $depth;
            $children[]  = $this->evaluate_node_with_trace( $child, $entry_values, $child_depth );
        }

        if ( empty( $children ) )
        {
            return [
                'type'        => 'group',
                'logic'       => $logic,
                'result'      => false,
                'reason_code' => 'empty_group',
                'children'    => [],
            ];
        }

        if ( 'all' === $logic )
        {
            $result = ! in_array( false, array_map(
                static fn( $child_result ): bool => ! empty( $child_result['result'] ),
                $children
            ), true );
        }
        else
        {
            $result = in_array( true, array_map(
                static fn( $child_result ): bool => ! empty( $child_result['result'] ),
                $children
            ), true );
        }

        return [
            'type'        => 'group',
            'logic'       => $logic,
            'result'      => $result,
            'reason_code' => $result ? 'matched' : 'group_not_matched',
            'children'    => $children,
        ];
    }

    /**
     * Evaluate a rule node with explainability output.
     *
     * @param array $rule         Rule node.
     * @param array $entry_values Entry scalar values.
     *
     * @return array<string, mixed>
     */
    private function evaluate_rule_with_trace( array $rule, array $entry_values ): array
    {
        $field_id = isset( $rule['field_id'] ) ? sanitize_text_field( (string) $rule['field_id'] ) : '';
        if ( '' === $field_id )
        {
            return [
                'type'        => 'rule',
                'field_id'    => '',
                'operator'    => '',
                'result'      => false,
                'reason_code' => 'missing_field_id',
            ];
        }

        $operator = sanitize_key( (string) ( $rule['operator'] ?? '' ) );
        if ( ! in_array( $operator, self::OPERATORS, true ) )
        {
            return [
                'type'        => 'rule',
                'field_id'    => $field_id,
                'operator'    => $operator,
                'result'      => false,
                'reason_code' => 'invalid_operator',
            ];
        }

        $actual = $entry_values[ $field_id ] ?? null;
        if ( 'is_empty' === $operator )
        {
            $result = $this->is_empty_value( $actual );
            return [
                'type'        => 'rule',
                'field_id'    => $field_id,
                'operator'    => $operator,
                'actual'      => $actual,
                'result'      => $result,
                'reason_code' => $result ? 'matched' : 'not_empty',
            ];
        }

        if ( 'is_not_empty' === $operator )
        {
            $result = ! $this->is_empty_value( $actual );
            return [
                'type'        => 'rule',
                'field_id'    => $field_id,
                'operator'    => $operator,
                'actual'      => $actual,
                'result'      => $result,
                'reason_code' => $result ? 'matched' : 'empty',
            ];
        }

        if ( ! array_key_exists( 'value', $rule ) )
        {
            return [
                'type'        => 'rule',
                'field_id'    => $field_id,
                'operator'    => $operator,
                'actual'      => $actual,
                'result'      => false,
                'reason_code' => 'missing_expected_value',
            ];
        }

        $expected = $rule['value'];
        $result   = match ( $operator )
        {
            'eq'           => $this->compare_equals( $actual, $expected ),
            'neq'          => ! $this->compare_equals( $actual, $expected ),
            'contains'     => $this->compare_contains( $actual, $expected ),
            'not_contains' => ! $this->compare_contains( $actual, $expected ),
            'starts_with'  => $this->compare_starts_with( $actual, $expected ),
            'ends_with'    => $this->compare_ends_with( $actual, $expected ),
            'in'           => $this->compare_in_list( $actual, $expected ),
            'not_in'       => ! $this->compare_in_list( $actual, $expected ),
            'gt'           => $this->compare_numeric( $actual, $expected, 'gt' ),
            'gte'          => $this->compare_numeric( $actual, $expected, 'gte' ),
            'lt'           => $this->compare_numeric( $actual, $expected, 'lt' ),
            'lte'          => $this->compare_numeric( $actual, $expected, 'lte' ),
            default        => false,
        };

        $reason_code = $result ? 'matched' : 'comparison_failed';
        if ( null === $actual )
        {
            $reason_code = 'missing_actual_value';
        }

        return [
            'type'        => 'rule',
            'field_id'    => $field_id,
            'operator'    => $operator,
            'actual'      => $actual,
            'expected'    => $expected,
            'result'      => $result,
            'reason_code' => $reason_code,
        ];
    }

    /**
     * Extract conditions object from mapping settings.
     *
     * @param array $action_settings Mapping linkage settings.
     *
     * @return array|null
     */
    private function extract_conditions( array $action_settings ): ?array
    {
        if ( isset( $action_settings['settings'] )
             && is_array( $action_settings['settings'] )
             && isset( $action_settings['settings']['conditions'] )
             && is_array( $action_settings['settings']['conditions'] ) )
        {
            return $action_settings['settings']['conditions'];
        }

        if ( isset( $action_settings['conditions'] ) && is_array( $action_settings['conditions'] ) )
        {
            return $action_settings['conditions'];
        }

        return null;
    }

    /**
     * Ensure the condition tree is within hard bounds.
     *
     * @param array $node       Condition tree node.
     * @param int   $depth      Current recursion depth.
     * @param int   $node_count Running node count.
     *
     * @return bool
     */
    private function shape_is_within_limits( array $node, int $depth, int &$node_count ): bool
    {
        if ( $depth > self::MAX_DEPTH )
        {
            return false;
        }

        $node_count++;
        if ( $node_count > self::MAX_NODES )
        {
            return false;
        }

        $type = sanitize_key( (string) ( $node['type'] ?? '' ) );
        if ( 'group' !== $type )
        {
            return true;
        }

        if ( empty( $node['rules'] ) || ! is_array( $node['rules'] ) )
        {
            return true;
        }

        foreach ( $node['rules'] as $child )
        {
            if ( ! is_array( $child ) )
            {
                continue;
            }

            $child_depth = $this->is_group_node( $child ) ? $depth + 1 : $depth;
            if ( ! $this->shape_is_within_limits( $child, $child_depth, $node_count ) )
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate a node recursively.
     *
     * @param array $node        Condition node.
     * @param array $entry_values Entry scalar values.
     * @param int   $depth       Current recursion depth.
     *
     * @return bool
     */
    private function evaluate_node( array $node, array $entry_values, int $depth ): bool
    {
        if ( $depth > self::MAX_DEPTH )
        {
            return false;
        }

        $type = sanitize_key( (string) ( $node['type'] ?? '' ) );
        if ( 'group' === $type )
        {
            return $this->evaluate_group( $node, $entry_values, $depth );
        }

        if ( 'rule' === $type )
        {
            return $this->evaluate_rule( $node, $entry_values );
        }

        // Support legacy nodes that omitted `type`.
        if ( isset( $node['rules'] ) && is_array( $node['rules'] ) )
        {
            return $this->evaluate_group( $node, $entry_values, $depth );
        }

        if ( isset( $node['field_id'] ) && isset( $node['operator'] ) )
        {
            return $this->evaluate_rule( $node, $entry_values );
        }

        return false;
    }

    /**
     * Evaluate a group node.
     *
     * @param array $group       Group node.
     * @param array $entry_values Entry scalar values.
     * @param int   $depth       Current recursion depth.
     *
     * @return bool
     */
    private function evaluate_group( array $group, array $entry_values, int $depth ): bool
    {
        $logic = sanitize_key( (string) ( $group['logic'] ?? 'all' ) );
        $logic = in_array( $logic, [ 'all', 'any' ], true ) ? $logic : 'all';

        $rules = isset( $group['rules'] ) && is_array( $group['rules'] ) ? $group['rules'] : [];
        if ( empty( $rules ) )
        {
            return false;
        }

        if ( 'all' === $logic )
        {
            foreach ( $rules as $child )
            {
                if ( ! is_array( $child ) )
                {
                    return false;
                }

                $child_depth = $this->is_group_node( $child ) ? $depth + 1 : $depth;
                if ( ! $this->evaluate_node( $child, $entry_values, $child_depth ) )
                {
                    return false;
                }
            }
            return true;
        }

        foreach ( $rules as $child )
        {
            if ( ! is_array( $child ) )
            {
                continue;
            }

            $child_depth = $this->is_group_node( $child ) ? $depth + 1 : $depth;
            if ( $this->evaluate_node( $child, $entry_values, $child_depth ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate a rule node.
     *
     * @param array $rule        Rule node.
     * @param array $entry_values Entry scalar values.
     *
     * @return bool
     */
    private function evaluate_rule( array $rule, array $entry_values ): bool
    {
        $field_id = isset( $rule['field_id'] ) ? sanitize_text_field( (string) $rule['field_id'] ) : '';
        if ( '' === $field_id )
        {
            return false;
        }

        $operator = sanitize_key( (string) ( $rule['operator'] ?? '' ) );
        if ( ! in_array( $operator, self::OPERATORS, true ) )
        {
            return false;
        }

        $actual = $entry_values[ $field_id ] ?? null;
        if ( 'is_empty' === $operator )
        {
            return $this->is_empty_value( $actual );
        }

        if ( 'is_not_empty' === $operator )
        {
            return ! $this->is_empty_value( $actual );
        }

        if ( ! array_key_exists( 'value', $rule ) )
        {
            return false;
        }

        $expected = $rule['value'];

        return match ( $operator )
        {
            'eq'           => $this->compare_equals( $actual, $expected ),
            'neq'          => ! $this->compare_equals( $actual, $expected ),
            'contains'     => $this->compare_contains( $actual, $expected ),
            'not_contains' => ! $this->compare_contains( $actual, $expected ),
            'starts_with'  => $this->compare_starts_with( $actual, $expected ),
            'ends_with'    => $this->compare_ends_with( $actual, $expected ),
            'in'           => $this->compare_in_list( $actual, $expected ),
            'not_in'       => ! $this->compare_in_list( $actual, $expected ),
            'gt'           => $this->compare_numeric( $actual, $expected, 'gt' ),
            'gte'          => $this->compare_numeric( $actual, $expected, 'gte' ),
            'lt'           => $this->compare_numeric( $actual, $expected, 'lt' ),
            'lte'          => $this->compare_numeric( $actual, $expected, 'lte' ),
            default        => false,
        };
    }

    /**
     * Extract scalar entry values and normalize to strings.
     *
     * @param array $entry Raw entry payload.
     *
     * @return array<string, string>
     */
    private function extract_scalar_entry_values( array $entry ): array
    {
        $values = [];

        foreach ( $entry as $key => $value )
        {
            if ( ! is_scalar( $value ) )
            {
                continue;
            }

            $values[ (string) $key ] = sanitize_text_field( (string) $value );
        }

        return $values;
    }

    /**
     * Determine whether a value is considered empty.
     *
     * @param mixed $value Value to inspect.
     *
     * @return bool
     */
    private function is_empty_value( $value ): bool
    {
        if ( null === $value )
        {
            return true;
        }

        if ( is_string( $value ) )
        {
            return '' === trim( $value );
        }

        return empty( $value );
    }

    /**
     * Normalize a scalar value for case-insensitive string comparison.
     *
     * @param mixed $value Value to normalize.
     *
     * @return string|null
     */
    private function normalize_scalar_text( $value ): ?string
    {
        if ( ! is_scalar( $value ) )
        {
            return null;
        }

        $text = sanitize_text_field( (string) $value );
        $text = trim( $text );
        if ( '' === $text )
        {
            return '';
        }

        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
    }

    /**
     * Case-insensitive equality compare.
     *
     * @param mixed $actual   Actual value.
     * @param mixed $expected Expected value.
     *
     * @return bool
     */
    private function compare_equals( $actual, $expected ): bool
    {
        if ( is_array( $expected ) || is_object( $expected ) )
        {
            return false;
        }

        $left  = $this->normalize_scalar_text( $actual );
        $right = $this->normalize_scalar_text( $expected );

        if ( null === $left || null === $right )
        {
            return false;
        }

        return $left === $right;
    }

    /**
     * Case-insensitive contains compare.
     *
     * @param mixed $actual   Actual value.
     * @param mixed $expected Expected value.
     *
     * @return bool
     */
    private function compare_contains( $actual, $expected ): bool
    {
        $left  = $this->normalize_scalar_text( $actual );
        $right = $this->normalize_scalar_text( $expected );
        if ( null === $left || null === $right || '' === $right )
        {
            return false;
        }

        return false !== strpos( $left, $right );
    }

    /**
     * Case-insensitive starts_with compare.
     *
     * @param mixed $actual   Actual value.
     * @param mixed $expected Expected value.
     *
     * @return bool
     */
    private function compare_starts_with( $actual, $expected ): bool
    {
        $left  = $this->normalize_scalar_text( $actual );
        $right = $this->normalize_scalar_text( $expected );
        if ( null === $left || null === $right || '' === $right )
        {
            return false;
        }

        return str_starts_with( $left, $right );
    }

    /**
     * Case-insensitive ends_with compare.
     *
     * @param mixed $actual   Actual value.
     * @param mixed $expected Expected value.
     *
     * @return bool
     */
    private function compare_ends_with( $actual, $expected ): bool
    {
        $left  = $this->normalize_scalar_text( $actual );
        $right = $this->normalize_scalar_text( $expected );
        if ( null === $left || null === $right || '' === $right )
        {
            return false;
        }

        return str_ends_with( $left, $right );
    }

    /**
     * Membership compare (case-insensitive).
     *
     * @param mixed $actual   Actual value.
     * @param mixed $expected Expected list.
     *
     * @return bool
     */
    private function compare_in_list( $actual, $expected ): bool
    {
        if ( ! is_array( $expected ) || empty( $expected ) )
        {
            return false;
        }

        $needle = $this->normalize_scalar_text( $actual );
        if ( null === $needle )
        {
            return false;
        }

        foreach ( $expected as $item )
        {
            $candidate = $this->normalize_scalar_text( $item );
            if ( null !== $candidate && $needle === $candidate )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Numeric comparison helper.
     *
     * @param mixed  $actual    Actual value.
     * @param mixed  $expected  Expected value.
     * @param string $operation Numeric operation.
     *
     * @return bool
     */
    private function compare_numeric( $actual, $expected, string $operation ): bool
    {
        if ( is_array( $expected ) || is_object( $expected ) )
        {
            return false;
        }

        if ( ! is_scalar( $actual ) || ! is_scalar( $expected ) )
        {
            return false;
        }

        if ( ! is_numeric( $actual ) || ! is_numeric( $expected ) )
        {
            return false;
        }

        $left  = (float) $actual;
        $right = (float) $expected;

        return match ( $operation )
        {
            'gt'  => $left > $right,
            'gte' => $left >= $right,
            'lt'  => $left < $right,
            'lte' => $left <= $right,
            default => false,
        };
    }

    /**
     * Determine whether a node should count as a depth-increasing group.
     *
     * @param array $node Candidate condition node.
     *
     * @return bool
     */
    private function is_group_node( array $node ): bool
    {
        $type = sanitize_key( (string) ( $node['type'] ?? '' ) );
        if ( 'group' === $type )
        {
            return true;
        }

        return isset( $node['rules'] ) && is_array( $node['rules'] );
    }
}
