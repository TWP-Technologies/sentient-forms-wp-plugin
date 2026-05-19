<?php
/**
 * Form mapping dependency planner.
 *
 * Builds deterministic execution plans for mapping DAGs.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Mapping_Dependency_Planner
{
    /**
     * Normalize raw form settings into mapping records keyed by local mapping id.
     *
     * @param array $form_settings Raw form settings option payload.
     *
     * @return array<string, array<string, mixed>>
     */
    public function normalize_action_mappings( array $form_settings ): array
    {
        $mappings = [];

        foreach ( $form_settings as $mapping_id => $mapping )
        {
            if ( ! is_array( $mapping ) || empty( $mapping['central_action_id'] ) )
            {
                continue;
            }

            $resolved_id = isset( $mapping['local_mapping_id'] ) && is_scalar( $mapping['local_mapping_id'] )
                ? sanitize_text_field( (string) $mapping['local_mapping_id'] )
                : sanitize_text_field( (string) $mapping_id );

            if ( '' === $resolved_id )
            {
                continue;
            }

            $normalized = $mapping;
            $normalized['local_mapping_id'] = $resolved_id;

            $mappings[ $resolved_id ] = $normalized;
        }

        return $mappings;
    }

    /**
     * Build a hook-specific execution plan.
     *
     * @param array  $form_settings Raw form settings payload.
     * @param string $hook          Runtime hook identifier.
     *
     * @return array{
     *     order: array<int, string>,
     *     nodes: array<string, array<string, mixed>>,
     *     cycle_ids: array<int, string>
     * }
     */
    public function build_execution_plan( array $form_settings, string $hook ): array
    {
        $hook     = sanitize_key( $hook );
        $mappings = $this->normalize_action_mappings( $form_settings );
        $nodes    = [];

        foreach ( $mappings as $mapping_id => $mapping )
        {
            $trigger_hooks = $this->normalize_trigger_hooks( $mapping['trigger_hooks'] ?? [] );
            $trigger_sources = $this->extract_trigger_sources( $mapping, $trigger_hooks );
            $dependencies  = $this->extract_dependency_ids_for_hook( $mapping, $hook );

            $nodes[ $mapping_id ] = [
                'mapping'       => $mapping,
                'mapping_id'    => $mapping_id,
                'trigger_hooks' => $trigger_hooks,
                'trigger_sources' => $trigger_sources,
                'dependency_ids'=> $dependencies,
                'enabled'       => ! empty( $mapping['is_action_enabled_for_form'] ),
                'hook_enabled'  => in_array( $hook, $trigger_hooks, true ),
            ];
        }

        [ $order, $cycle_ids ] = $this->topological_order( $nodes );

        return [
            'order'     => $order,
            'nodes'     => $nodes,
            'cycle_ids' => $cycle_ids,
        ];
    }

    /**
     * Extract dependency ids from mapping settings.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     *
     * @return array<int, string>
     */
    public function extract_dependency_ids( array $mapping ): array
    {
        $trigger_hooks = $this->normalize_trigger_hooks( $mapping['trigger_hooks'] ?? [] );
        $sources = $this->extract_trigger_sources( $mapping, $trigger_hooks );
        if ( ! empty( $sources ) )
        {
            $dependencies = [];
            foreach ( $sources as $source )
            {
                if ( ! is_array( $source ) )
                {
                    continue;
                }

                if ( ( $source['type'] ?? 'hook_root' ) !== 'mapping' )
                {
                    continue;
                }

                if ( ! isset( $source['mapping_id'] ) || ! is_scalar( $source['mapping_id'] ) )
                {
                    continue;
                }

                $mapping_id = sanitize_text_field( (string) $source['mapping_id'] );
                if ( '' === $mapping_id )
                {
                    continue;
                }

                $dependencies[] = $mapping_id;
            }

            return array_values( array_unique( $dependencies ) );
        }

        if ( ! isset( $mapping['settings'] ) || ! is_array( $mapping['settings'] ) )
        {
            return [];
        }

        $raw = $mapping['settings']['dependency_ids'] ?? [];
        if ( ! is_array( $raw ) )
        {
            return [];
        }

        $result = [];
        foreach ( $raw as $value )
        {
            if ( ! is_scalar( $value ) )
            {
                continue;
            }

            $id = sanitize_text_field( (string) $value );
            if ( '' === $id )
            {
                continue;
            }

            $result[] = $id;
        }

        return array_values( array_unique( $result ) );
    }

    /**
     * Extract dependency ids that apply to a specific runtime hook.
     *
     * @param array<string, mixed> $mapping Mapping payload.
     * @param string               $hook    Runtime hook identifier.
     *
     * @return array<int, string>
     */
    public function extract_dependency_ids_for_hook( array $mapping, string $hook ): array
    {
        $hook = sanitize_key( $hook );
        if ( '' === $hook )
        {
            return [];
        }

        $trigger_hooks = $this->normalize_trigger_hooks( $mapping['trigger_hooks'] ?? [] );
        if ( ! in_array( $hook, $trigger_hooks, true ) )
        {
            return [];
        }

        $sources = $this->extract_trigger_sources( $mapping, $trigger_hooks );
        if ( isset( $sources[ $hook ] ) && is_array( $sources[ $hook ] ) )
        {
            $source = $sources[ $hook ];
            if ( ( $source['type'] ?? 'hook_root' ) !== 'mapping' )
            {
                return [];
            }

            if ( ! isset( $source['mapping_id'] ) || ! is_scalar( $source['mapping_id'] ) )
            {
                return [];
            }

            $mapping_id = sanitize_text_field( (string) $source['mapping_id'] );
            return '' === $mapping_id ? [] : [ $mapping_id ];
        }

        return $this->extract_dependency_ids( $mapping );
    }

    /**
     * Normalize configured trigger hooks.
     *
     * @param mixed $hooks Raw hooks.
     *
     * @return array<int, string>
     */
    private function normalize_trigger_hooks( $hooks ): array
    {
        if ( ! is_array( $hooks ) )
        {
            return [];
        }

        $normalized = [];
        foreach ( $hooks as $hook )
        {
            if ( ! is_scalar( $hook ) )
            {
                continue;
            }

            $value = sanitize_key( (string) $hook );
            if ( '' === $value )
            {
                continue;
            }

            $normalized[] = $value;
        }

        return array_values( array_unique( $normalized ) );
    }

    /**
     * Extract and normalize per-hook trigger sources.
     *
     * @param array<string, mixed> $mapping       Mapping payload.
     * @param array<int, string>   $trigger_hooks Hooks configured for this mapping.
     *
     * @return array<string, array{type: string, mapping_id?: string}>
     */
    public function extract_trigger_sources( array $mapping, array $trigger_hooks ): array
    {
        if ( ! isset( $mapping['settings'] ) || ! is_array( $mapping['settings'] ) )
        {
            return [];
        }

        $raw = $mapping['settings']['trigger_sources'] ?? null;
        if ( ! is_array( $raw ) )
        {
            return [];
        }

        $hook_lookup = array_fill_keys( $trigger_hooks, true );
        $normalized = [];
        foreach ( $raw as $hook => $source )
        {
            if ( ! is_scalar( $hook ) )
            {
                continue;
            }
            $hook_key = sanitize_key( (string) $hook );
            if ( '' === $hook_key || ! isset( $hook_lookup[ $hook_key ] ) )
            {
                continue;
            }

            if ( ! is_array( $source ) )
            {
                continue;
            }

            $type = isset( $source['type'] ) && is_scalar( $source['type'] )
                ? sanitize_key( (string) $source['type'] )
                : '';

            if ( 'mapping' !== $type && 'hook_root' !== $type && 'unbound' !== $type )
            {
                continue;
            }

            if ( 'hook_root' === $type || 'unbound' === $type )
            {
                $normalized[ $hook_key ] = [ 'type' => $type ];
                continue;
            }

            $mapping_id = '';
            if ( isset( $source['mapping_id'] ) && is_scalar( $source['mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['mapping_id'] );
            }
            elseif ( isset( $source['source_mapping_id'] ) && is_scalar( $source['source_mapping_id'] ) )
            {
                $mapping_id = sanitize_text_field( (string) $source['source_mapping_id'] );
            }

            if ( '' === $mapping_id )
            {
                continue;
            }

            $normalized[ $hook_key ] = [
                'type'       => 'mapping',
                'mapping_id' => $mapping_id,
            ];
        }

        return $normalized;
    }

    /**
     * Produce deterministic topological ordering for the dependency graph.
     *
     * @param array<string, array<string, mixed>> $nodes Graph nodes keyed by mapping id.
     *
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function topological_order( array $nodes ): array
    {
        if ( empty( $nodes ) )
        {
            return [ [], [] ];
        }

        $mapping_ids = array_keys( $nodes );
        $index_map   = [];
        $in_degree   = [];
        $adjacency   = [];

        foreach ( $mapping_ids as $position => $mapping_id )
        {
            $index_map[ $mapping_id ] = $position;
            $in_degree[ $mapping_id ] = 0;
            $adjacency[ $mapping_id ] = [];
        }

        foreach ( $nodes as $mapping_id => $node )
        {
            $dependencies = is_array( $node['dependency_ids'] ?? null ) ? $node['dependency_ids'] : [];
            foreach ( $dependencies as $dependency_id )
            {
                if ( ! isset( $nodes[ $dependency_id ] ) )
                {
                    continue;
                }

                $adjacency[ $dependency_id ][] = $mapping_id;
                $in_degree[ $mapping_id ]++;
            }
        }

        $queue = [];
        foreach ( $in_degree as $mapping_id => $degree )
        {
            if ( 0 === $degree )
            {
                $queue[] = $mapping_id;
            }
        }

        usort(
            $queue,
            static function ( string $left, string $right ) use ( $index_map ): int {
                return ( $index_map[ $left ] ?? 0 ) <=> ( $index_map[ $right ] ?? 0 );
            }
        );

        $order = [];
        while ( ! empty( $queue ) )
        {
            $current   = array_shift( $queue );
            $order[]   = $current;
            $neighbors = $adjacency[ $current ] ?? [];

            usort(
                $neighbors,
                static function ( string $left, string $right ) use ( $index_map ): int {
                    return ( $index_map[ $left ] ?? 0 ) <=> ( $index_map[ $right ] ?? 0 );
                }
            );

            foreach ( $neighbors as $neighbor )
            {
                $in_degree[ $neighbor ]--;
                if ( 0 === $in_degree[ $neighbor ] )
                {
                    $queue[] = $neighbor;
                }
            }

            usort(
                $queue,
                static function ( string $left, string $right ) use ( $index_map ): int {
                    return ( $index_map[ $left ] ?? 0 ) <=> ( $index_map[ $right ] ?? 0 );
                }
            );
        }

        if ( count( $order ) === count( $nodes ) )
        {
            return [ $order, [] ];
        }

        $cycle_ids = [];
        foreach ( $in_degree as $mapping_id => $degree )
        {
            if ( $degree > 0 )
            {
                $cycle_ids[] = $mapping_id;
            }
        }

        return [ $order, $cycle_ids ];
    }
}
