<?php
/**
 * Request trace service for form mapping simulations.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Request_Tracer
{
    private const POLICY_VERSION = '2026-02-request-tracer-v1';

    private const ALLOWED_TRIGGER_HOOKS = [
        'gform_validation',
        'gform_after_submission',
    ];

    private Sentient_Forms_Mapping_Dependency_Planner $planner;

    private Sentient_Forms_Condition_Evaluator $condition_evaluator;

    public function __construct(
        Sentient_Forms_Mapping_Dependency_Planner $planner,
        Sentient_Forms_Condition_Evaluator $condition_evaluator
    )
    {
        $this->planner = $planner;
        $this->condition_evaluator = $condition_evaluator;
    }

    /**
     * Simulate mapping execution for the given request payload.
     *
     * @param array<string, mixed>        $actions     Mapping payloads keyed by local mapping id.
     * @param array<string, string>       $entry_values Scalar entry values used for condition evaluation.
     * @param string                      $hook_scope   Hook scope ('all' or specific hook).
     *
     * @return array<string, mixed>
     */
    public function trace( array $actions, array $entry_values, string $hook_scope = 'all' ): array
    {
        $normalized      = $this->planner->normalize_action_mappings( $actions );
        $available_hooks = $this->collect_available_hooks( $normalized );
        $hook_scope      = sanitize_key( $hook_scope );
        if ( 'all' !== $hook_scope && ! in_array( $hook_scope, $available_hooks, true ) )
        {
            $hook_scope = 'all';
        }

        $hooks_to_trace = 'all' === $hook_scope ? $available_hooks : [ $hook_scope ];
        $hooks          = [];
        foreach ( $hooks_to_trace as $hook )
        {
            $plan    = $this->planner->build_execution_plan( $normalized, $hook );
            $hooks[] = $this->build_hook_trace( $hook, $plan, $normalized, $entry_values );
        }

        return [
            'authority'         => 'wp_rest',
            'policy_version'    => self::POLICY_VERSION,
            'hook_scope'        => $hook_scope,
            'available_hooks'   => $available_hooks,
            'hooks'             => $hooks,
            'policy_violations' => $this->collect_policy_violations( $normalized ),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $normalized
     *
     * @return array<int, string>
     */
    private function collect_available_hooks( array $normalized ): array
    {
        $hooks = [];
        foreach ( $normalized as $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            $hooks         = array_merge( $hooks, $trigger_hooks );
        }

        $hooks = array_values( array_unique( $hooks ) );
        usort( $hooks, [ $this, 'compare_hook_ids' ] );

        return $hooks;
    }

    /**
     * @param array<string, mixed>                $plan
     * @param array<string, array<string, mixed>> $normalized
     * @param array<string, string>               $entry_values
     *
     * @return array<string, mixed>
     */
    private function build_hook_trace( string $hook, array $plan, array $normalized, array $entry_values ): array
    {
        $order          = [];
        $steps          = [];
        $runnable       = [];
        $queued         = [];
        $blocked        = [];
        $mapping_states = [];
        $cycle_ids      = array_values( array_unique( array_map( 'sanitize_text_field', (array) ( $plan['cycle_ids'] ?? [] ) ) ) );
        $cycle_lookup   = array_fill_keys( $cycle_ids, true );

        foreach ( (array) ( $plan['order'] ?? [] ) as $mapping_id )
        {
            if ( ! isset( $plan['nodes'][ $mapping_id ] ) || ! is_array( $plan['nodes'][ $mapping_id ] ) )
            {
                continue;
            }

            $node    = $plan['nodes'][ $mapping_id ];
            $mapping = isset( $node['mapping'] ) && is_array( $node['mapping'] ) ? $node['mapping'] : [];
            if ( empty( $node['hook_enabled'] ) )
            {
                continue;
            }

            $label          = isset( $mapping['action_name_label'] ) && is_scalar( $mapping['action_name_label'] )
                ? sanitize_text_field( (string) $mapping['action_name_label'] )
                : sanitize_text_field( (string) ( $mapping['central_action_id'] ?? $mapping_id ) );
            $dependency_ids = is_array( $node['dependency_ids'] ?? null )
                ? array_values( array_map( 'sanitize_text_field', $node['dependency_ids'] ) )
                : [];
            $is_async       = $this->is_mapping_async( $mapping );
            $trigger_source = $this->read_trigger_source_for_hook( $mapping, $hook );
            $order[]        = $mapping_id;

            $step = [
                'mapping_id'     => $mapping_id,
                'label'          => $label,
                'dependency_ids' => $dependency_ids,
                'trigger_source' => $trigger_source,
                'execution_mode' => $is_async ? 'after_submission' : 'validation',
                'is_async'       => $is_async,
                'outcome'        => 'blocked',
                'block_reason'   => null,
                'block_details'  => null,
                'condition'      => $this->build_condition_not_evaluated_payload( $mapping, 'not_evaluated', 'Condition was not evaluated for this step.' ),
            ];

            if ( isset( $cycle_lookup[ $mapping_id ] ) )
            {
                $this->append_blocked( $blocked, $mapping_states, $step, $mapping_id, 'cycle', null );
                $steps[] = $step;
                continue;
            }

            if ( empty( $node['enabled'] ) )
            {
                $this->append_blocked( $blocked, $mapping_states, $step, $mapping_id, 'disabled', null );
                $steps[] = $step;
                continue;
            }

            if ( 'unbound' === ( $trigger_source['type'] ?? '' ) )
            {
                $this->append_blocked( $blocked, $mapping_states, $step, $mapping_id, 'invalid_trigger', $hook );
                $steps[] = $step;
                continue;
            }

            $missing_dependencies = [];
            foreach ( $dependency_ids as $dependency_id )
            {
                if ( ! isset( $normalized[ $dependency_id ] ) )
                {
                    $missing_dependencies[] = $dependency_id;
                    continue;
                }

                $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
                if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                {
                    $missing_dependencies[] = $dependency_id;
                }
            }

            if ( ! empty( $missing_dependencies ) )
            {
                $this->append_blocked(
                    $blocked,
                    $mapping_states,
                    $step,
                    $mapping_id,
                    'missing_dependency',
                    implode( ', ', $missing_dependencies )
                );
                $steps[] = $step;
                continue;
            }

            if ( 'gform_after_submission' === $hook )
            {
                $policy_violation_dependency = null;
                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( ! isset( $normalized[ $dependency_id ] ) )
                    {
                        continue;
                    }

                    $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
                    if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                    {
                        continue;
                    }

                    $dependency_is_async = $this->is_mapping_async( $normalized[ $dependency_id ] );
                    if ( $dependency_is_async && ! $is_async )
                    {
                        $policy_violation_dependency = $dependency_id;
                        break;
                    }
                }

                if ( null !== $policy_violation_dependency )
                {
                    $this->append_blocked(
                        $blocked,
                        $mapping_states,
                        $step,
                        $mapping_id,
                        'policy_violation',
                        $policy_violation_dependency . ':execution_mode_mismatch'
                    );
                    $steps[] = $step;
                    continue;
                }
            }

            $upstream_block = $this->resolve_dependency_blocking_mapping(
                $dependency_ids,
                $mapping_states,
                $is_async
            );
            if ( null !== $upstream_block )
            {
                $upstream_state = $mapping_states[ $upstream_block ] ?? 'unknown';
                $this->append_blocked(
                    $blocked,
                    $mapping_states,
                    $step,
                    $mapping_id,
                    'upstream_blocked',
                    $upstream_block . ':' . $upstream_state
                );
                $steps[] = $step;
                continue;
            }

            $condition_trace    = $this->condition_evaluator->evaluate_with_trace( $mapping, $entry_values );
            $step['condition']  = $condition_trace;
            if ( empty( $condition_trace['should_execute'] ) )
            {
                $this->append_blocked(
                    $blocked,
                    $mapping_states,
                    $step,
                    $mapping_id,
                    'condition_false',
                    isset( $condition_trace['summary'] ) && is_scalar( $condition_trace['summary'] )
                        ? sanitize_text_field( (string) $condition_trace['summary'] )
                        : null
                );
                $steps[] = $step;
                continue;
            }

            if ( $is_async )
            {
                $step['outcome']      = 'would_queue';
                $step['block_reason'] = null;
                $step['block_details']= null;
                $queued[]             = $mapping_id;
                $mapping_states[ $mapping_id ] = 'queued';
            }
            else
            {
                $step['outcome']      = 'would_run';
                $step['block_reason'] = null;
                $step['block_details']= null;
                $runnable[]           = $mapping_id;
                $mapping_states[ $mapping_id ] = 'succeeded';
            }

            $steps[] = $step;
        }

        $executable = array_values( array_unique( array_merge( $runnable, $queued ) ) );
        $waves      = $this->build_waves( $order, $executable, (array) ( $plan['nodes'] ?? [] ), $hook );

        return [
            'hook'      => $hook,
            'order'     => $order,
            'waves'     => $waves,
            'runnable'  => $runnable,
            'queued'    => $queued,
            'blocked'   => $blocked,
            'cycle_ids' => $cycle_ids,
            'steps'     => $steps,
        ];
    }

    /**
     * @param array<int, array{mapping_id: string, reason: string, details?: string}> $blocked
     * @param array<string, string>                                                     $mapping_states
     * @param array<string, mixed>                                                      $step
     */
    private function append_blocked(
        array &$blocked,
        array &$mapping_states,
        array &$step,
        string $mapping_id,
        string $reason,
        ?string $details
    ): void
    {
        $step['outcome']      = 'blocked';
        $step['block_reason'] = $reason;
        $step['block_details']= $details;

        $payload = [
            'mapping_id' => $mapping_id,
            'reason'     => $reason,
        ];
        if ( null !== $details && '' !== $details )
        {
            $payload['details'] = $details;
        }

        $blocked[] = $payload;
        $mapping_states[ $mapping_id ] = 'skipped';
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return array{type: string, mapping_id?: string}
     */
    private function read_trigger_source_for_hook( array $mapping, string $hook ): array
    {
        $hook     = sanitize_key( $hook );
        $settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
            ? $mapping['settings']
            : [];
        $sources  = isset( $settings['trigger_sources'] ) && is_array( $settings['trigger_sources'] )
            ? $settings['trigger_sources']
            : [];
        if ( ! isset( $sources[ $hook ] ) || ! is_array( $sources[ $hook ] ) )
        {
            return [ 'type' => 'hook_root' ];
        }

        $type = isset( $sources[ $hook ]['type'] ) && is_scalar( $sources[ $hook ]['type'] )
            ? sanitize_key( (string) $sources[ $hook ]['type'] )
            : 'hook_root';
        if ( 'hook_root' === $type )
        {
            return [ 'type' => 'hook_root' ];
        }
        if ( 'unbound' === $type )
        {
            return [ 'type' => 'unbound' ];
        }
        if ( 'mapping' !== $type )
        {
            return [ 'type' => 'hook_root' ];
        }

        $mapping_id = isset( $sources[ $hook ]['mapping_id'] ) && is_scalar( $sources[ $hook ]['mapping_id'] )
            ? sanitize_text_field( (string) $sources[ $hook ]['mapping_id'] )
            : '';
        if ( '' === $mapping_id )
        {
            return [ 'type' => 'hook_root' ];
        }

        return [
            'type'       => 'mapping',
            'mapping_id' => $mapping_id,
        ];
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return array<string, mixed>
     */
    private function build_condition_not_evaluated_payload( array $mapping, string $reason_code, string $summary ): array
    {
        $enabled = false;
        if (
            isset( $mapping['settings'] )
            && is_array( $mapping['settings'] )
            && isset( $mapping['settings']['conditions'] )
            && is_array( $mapping['settings']['conditions'] )
        )
        {
            $enabled = ! empty( $mapping['settings']['conditions']['enabled'] );
        }

        return [
            'should_execute' => $enabled ? null : true,
            'enabled'        => $enabled,
            'evaluated'      => false,
            'matched'        => $enabled ? null : true,
            'reason_code'    => $reason_code,
            'summary'        => $summary,
            'tree'           => null,
        ];
    }

    /**
     * @param array<int, string>              $order
     * @param array<int, string>              $executable_ids
     * @param array<string, array<string,mixed>> $nodes
     *
     * @return array<int, array{level: int, mapping_ids: array<int, string>}>
     */
    private function build_waves( array $order, array $executable_ids, array $nodes, string $hook ): array
    {
        if ( empty( $order ) || empty( $executable_ids ) )
        {
            return [];
        }

        $executable_lookup = array_fill_keys( $executable_ids, true );
        $levels            = [];
        foreach ( $order as $mapping_id )
        {
            if ( ! isset( $executable_lookup[ $mapping_id ] ) )
            {
                continue;
            }

            if ( ! isset( $nodes[ $mapping_id ] ) || ! is_array( $nodes[ $mapping_id ] ) )
            {
                continue;
            }

            $dependencies = is_array( $nodes[ $mapping_id ]['dependency_ids'] ?? null )
                ? $nodes[ $mapping_id ]['dependency_ids']
                : [];
            $dependency_levels = [];
            foreach ( $dependencies as $dependency_id )
            {
                if ( ! isset( $executable_lookup[ $dependency_id ] ) )
                {
                    continue;
                }

                $dependency_levels[] = (int) ( $levels[ $dependency_id ] ?? 0 );
            }

            $levels[ $mapping_id ] = empty( $dependency_levels ) ? 0 : ( max( $dependency_levels ) + 1 );
        }

        $waves = [];
        foreach ( $order as $mapping_id )
        {
            if ( ! isset( $executable_lookup[ $mapping_id ] ) )
            {
                continue;
            }

            if ( ! isset( $nodes[ $mapping_id ] ) || ! is_array( $nodes[ $mapping_id ] ) )
            {
                continue;
            }

            $level = (int) ( $levels[ $mapping_id ] ?? 0 );
            if ( ! isset( $waves[ $level ] ) )
            {
                $waves[ $level ] = [];
            }
            $waves[ $level ][] = $mapping_id;
        }

        ksort( $waves );
        $result = [];
        foreach ( $waves as $level => $mapping_ids )
        {
            $result[] = [
                'level'       => (int) $level,
                'mapping_ids' => array_values( $mapping_ids ),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $normalized
     *
     * @return array<int, array{mapping_id: string, dependency_id: string, code: string, message: string}>
     */
    private function collect_policy_violations( array $normalized ): array
    {
        $violations = [];
        $lookup     = [];

        foreach ( $normalized as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
            if ( empty( $trigger_hooks ) )
            {
                continue;
            }

            $mapping_is_async = $this->is_mapping_async( $mapping );
            foreach ( $trigger_hooks as $hook )
            {
                $dependency_ids = $this->planner->extract_dependency_ids_for_hook( $mapping, $hook );
                foreach ( $dependency_ids as $dependency_id )
                {
                    if ( ! isset( $normalized[ $dependency_id ] ) )
                    {
                        $key = sprintf( 'missing:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'missing_dependency',
                            'message'       => sprintf(
                                __( 'Mapping %1$s depends on unknown mapping %2$s in hook %3$s.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            ),
                        ];
                        $lookup[ $key ] = true;
                        continue;
                    }

                    $dependency_hooks = $this->sanitize_trigger_hooks( (array) ( $normalized[ $dependency_id ]['trigger_hooks'] ?? [] ) );
                    if ( ! $this->dependency_satisfies_hook( $hook, $dependency_hooks ) )
                    {
                        $key = sprintf( 'hook_mismatch:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                        if ( isset( $lookup[ $key ] ) )
                        {
                            continue;
                        }

                        $violations[] = [
                            'mapping_id'    => $mapping_id,
                            'dependency_id' => $dependency_id,
                            'code'          => 'hook_mismatch',
                            'message'       => sprintf(
                                __( 'Mapping %1$s depends on %2$s in hook %3$s, but %2$s does not run on that hook.', 'sentient-forms' ),
                                sanitize_text_field( $mapping_id ),
                                sanitize_text_field( $dependency_id ),
                                sanitize_text_field( $hook )
                            ),
                        ];
                        $lookup[ $key ] = true;
                        continue;
                    }

                    if ( 'gform_after_submission' !== $hook )
                    {
                        continue;
                    }

                    $dependency_is_async = $this->is_mapping_async( $normalized[ $dependency_id ] );
                    if ( ! $dependency_is_async || $mapping_is_async )
                    {
                        continue;
                    }

                    $key = sprintf( 'execution_mode:%s:%s:%s', $mapping_id, $dependency_id, $hook );
                    if ( isset( $lookup[ $key ] ) )
                    {
                        continue;
                    }

                    $violations[] = [
                        'mapping_id'    => $mapping_id,
                        'dependency_id' => $dependency_id,
                        'code'          => 'execution_mode_mismatch',
                        'message'       => sprintf(
                            __( 'Mapping %1$s depends on async mapping %2$s during after-submission, so %1$s must also run async.', 'sentient-forms' ),
                            sanitize_text_field( $mapping_id ),
                            sanitize_text_field( $dependency_id )
                        ),
                    ];
                    $lookup[ $key ] = true;
                }
            }
        }

        return array_values( $violations );
    }

    /**
     * @param array<int, string>        $dependency_ids
     * @param array<string, string>     $mapping_states
     */
    private function resolve_dependency_blocking_mapping(
        array $dependency_ids,
        array $mapping_states,
        bool $allow_queued_dependencies
    ): ?string
    {
        foreach ( $dependency_ids as $dependency_id )
        {
            if ( ! is_string( $dependency_id ) || '' === $dependency_id )
            {
                continue;
            }

            $outcome = $mapping_states[ $dependency_id ] ?? null;
            if ( null === $outcome )
            {
                return $dependency_id;
            }

            if ( 'failed' === $outcome || 'skipped' === $outcome )
            {
                return $dependency_id;
            }

            if ( ! $allow_queued_dependencies && 'queued' === $outcome )
            {
                return $dependency_id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $mapping
     */
    private function is_mapping_async( array $mapping ): bool
    {
        $trigger_hooks       = $this->sanitize_trigger_hooks( (array) ( $mapping['trigger_hooks'] ?? [] ) );
        $has_validation_hook = in_array( 'gform_validation', $trigger_hooks, true );
        $has_after_hook      = in_array( 'gform_after_submission', $trigger_hooks, true );

        if ( $has_validation_hook && ! $has_after_hook )
        {
            return false;
        }

        if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) && array_key_exists( 'async', $mapping['settings'] ) )
        {
            return rest_sanitize_boolean( $mapping['settings']['async'] );
        }

        if (
            isset( $mapping['settings'] )
            && is_array( $mapping['settings'] )
            && isset( $mapping['settings']['execution_mode'] )
            && is_scalar( $mapping['settings']['execution_mode'] )
        )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['settings']['execution_mode'] );
        }

        if ( isset( $mapping['execution_mode'] ) && is_scalar( $mapping['execution_mode'] ) )
        {
            return 'after_submission' === sanitize_key( (string) $mapping['execution_mode'] );
        }

        if ( $has_after_hook )
        {
            return true;
        }

        $indicator = isset( $mapping['action_type_indicator'] ) && is_scalar( $mapping['action_type_indicator'] )
            ? sanitize_key( (string) $mapping['action_type_indicator'] )
            : '';

        return 'master' === $indicator;
    }

    /**
     * @param array<int, mixed> $dependency_hooks
     */
    private function dependency_satisfies_hook( string $required_hook, array $dependency_hooks ): bool
    {
        $required_hook            = sanitize_key( $required_hook );
        $normalized_dependencies  = $this->sanitize_trigger_hooks( $dependency_hooks );

        if ( in_array( $required_hook, $normalized_dependencies, true ) )
        {
            return true;
        }

        return 'gform_after_submission' === $required_hook
            && in_array( 'gform_validation', $normalized_dependencies, true );
    }

    /**
     * @param array<int, mixed> $hooks
     *
     * @return array<int, string>
     */
    private function sanitize_trigger_hooks( array $hooks ): array
    {
        $normalized = [];
        foreach ( $hooks as $hook )
        {
            if ( ! is_scalar( $hook ) )
            {
                continue;
            }

            $hook_key = sanitize_key( (string) $hook );
            if ( '' === $hook_key || ! in_array( $hook_key, self::ALLOWED_TRIGGER_HOOKS, true ) )
            {
                continue;
            }

            $normalized[] = $hook_key;
        }

        $normalized = array_values( array_unique( $normalized ) );
        usort( $normalized, [ $this, 'compare_hook_ids' ] );

        return $normalized;
    }

    private function compare_hook_ids( string $left, string $right ): int
    {
        $order = [
            'gform_validation'       => 10,
            'gform_after_submission' => 20,
        ];

        $left_rank  = $order[ $left ] ?? 1000;
        $right_rank = $order[ $right ] ?? 1000;
        if ( $left_rank !== $right_rank )
        {
            return $left_rank <=> $right_rank;
        }

        return strcmp( $left, $right );
    }
}
