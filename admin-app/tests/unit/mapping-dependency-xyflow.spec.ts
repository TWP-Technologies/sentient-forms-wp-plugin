import { describe, expect, it } from 'vitest';
import {
	buildXyflowDependencyGraph,
	DEPENDENCY_TARGET_HANDLE_ID,
	HOOK_ROOT_SOURCE_HANDLE_ID,
	HOOK_ROOT_SOURCE_HANDLE_PREFIX,
	hookRootTargetHandleId,
	HOOK_ROOT_TARGET_HANDLE_PREFIX
} from '$lib/utils/mapping-dependency-xyflow';
import type { FormActionLinkage } from '$lib/api/types';

const baseLinkages: FormActionLinkage[] = [
	{
		local_mapping_id: 'map_validation',
		central_action_id: 'spam_detection_v1',
		action_type_indicator: 'master',
		action_name_label: 'Validation Spam Block',
		trigger_hooks: ['gform_validation'],
		is_action_enabled_for_form: true,
		settings: {}
	},
	{
		local_mapping_id: 'map_dual',
		central_action_id: 'content_quality',
		action_type_indicator: 'custom',
		action_name_label: 'Content Quality Validation',
		trigger_hooks: ['gform_validation', 'gform_after_submission'],
		is_action_enabled_for_form: true,
		settings: { dependency_ids: ['map_validation'] }
	},
	{
		local_mapping_id: 'map_async_only',
		central_action_id: 'entry_summary',
		action_type_indicator: 'custom',
		action_name_label: 'Entry Summary',
		trigger_hooks: ['gform_after_submission'],
		is_action_enabled_for_form: true,
		settings: {}
	}
];

function nodeRect(node: ReturnType<typeof buildXyflowDependencyGraph>['nodes'][number]) {
	const width = node.data.kind === 'hook_root' ? 220 : 320;
	const height = node.data.kind === 'hook_root' ? 92 : 184;
	return {
		left: node.position.x,
		top: node.position.y,
		right: node.position.x + width,
		bottom: node.position.y + height
	};
}

function rectanglesOverlap(
	left: ReturnType<typeof nodeRect>,
	right: ReturnType<typeof nodeRect>
): boolean {
	return (
		left.left < right.right &&
		left.right > right.left &&
		left.top < right.bottom &&
		left.bottom > right.top
	);
}

describe('mapping-dependency-xyflow', () => {
	it('uses hook-specific target handles for hook-root edges', () => {
		const graph = buildXyflowDependencyGraph(baseLinkages);
		const rootEdges = graph.edges.filter((edge) => edge.data?.kind === 'hook_root');
		expect(rootEdges.length).toBeGreaterThan(0);

		for (const edge of rootEdges) {
			const hook = edge.data?.hook;
			expect(hook).toBeTruthy();
			expect(edge.targetHandle).toBe(hookRootTargetHandleId(String(hook)));
		}
	});

	it('uses hook-specific target handles for explicit dependency edges when hook context is known', () => {
		const graph = buildXyflowDependencyGraph(baseLinkages);
		const dependencyEdges = graph.edges.filter((edge) => edge.data?.kind === 'dependency');
		expect(dependencyEdges.length).toBeGreaterThan(0);

		for (const edge of dependencyEdges) {
			if (edge.data?.hook) {
				expect(edge.targetHandle).toBe(hookRootTargetHandleId(String(edge.data.hook)));
			} else {
				expect(edge.targetHandle).toBe(DEPENDENCY_TARGET_HANDLE_ID);
			}
		}
	});

	it('preserves legacy dependency target handle when hook context is absent', () => {
		const graph = buildXyflowDependencyGraph([
			{
				local_mapping_id: 'map_source',
				central_action_id: 'source',
				action_type_indicator: 'master',
				action_name_label: 'Source',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map_target',
				central_action_id: 'target',
				action_type_indicator: 'master',
				action_name_label: 'Target',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map_source'],
					trigger_sources: {
						gform_validation: {
							type: 'mapping',
							mapping_id: 'map_source'
						}
					}
				}
			}
		]);
		const dependencyEdges = graph.edges.filter((edge) => edge.data?.kind === 'dependency');
		expect(dependencyEdges.length).toBeGreaterThan(0);
		expect(
			dependencyEdges.some(
				(edge) =>
					edge.targetHandle?.startsWith(HOOK_ROOT_TARGET_HANDLE_PREFIX) ||
					edge.targetHandle === DEPENDENCY_TARGET_HANDLE_ID
			)
		).toBe(true);
	});

	it('orders root-edge lanes by rendered node Y position', () => {
		const graph = buildXyflowDependencyGraph([
			{
				local_mapping_id: 'map_z',
				central_action_id: 'action_z',
				action_type_indicator: 'master',
				action_name_label: 'Action Z',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map_a',
				central_action_id: 'action_a',
				action_type_indicator: 'master',
				action_name_label: 'Action A',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map_m',
				central_action_id: 'action_m',
				action_type_indicator: 'master',
				action_name_label: 'Action M',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			}
		]);
		const byId = new Map(graph.nodes.map((node) => [node.id, node]));
		const rootEdges = graph.edges.filter(
			(edge) => edge.data?.kind === 'hook_root' && edge.source === '__hook_root__:gform_validation'
		);
		expect(rootEdges).toHaveLength(3);

		const byY = [...rootEdges]
			.sort((left, right) => {
				const leftY = byId.get(left.target)?.position.y ?? 0;
				const rightY = byId.get(right.target)?.position.y ?? 0;
				return leftY - rightY;
			})
			.map((edge) => edge.target);

		const byLane = [...rootEdges]
			.sort((left, right) => (left.data?.lane ?? 0) - (right.data?.lane ?? 0))
			.map((edge) => edge.target);

		expect(byLane).toEqual(byY);
	});

	it('assigns distinct root source handles for fan-out hook-root edges', () => {
		const graph = buildXyflowDependencyGraph([
			{
				local_mapping_id: 'map_a',
				central_action_id: 'action_a',
				action_type_indicator: 'master',
				action_name_label: 'Action A',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map_b',
				central_action_id: 'action_b',
				action_type_indicator: 'master',
				action_name_label: 'Action B',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map_c',
				central_action_id: 'action_c',
				action_type_indicator: 'master',
				action_name_label: 'Action C',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			}
		]);

		const rootEdges = graph.edges.filter(
			(edge) => edge.data?.kind === 'hook_root' && edge.source === '__hook_root__:gform_validation'
		);
		expect(rootEdges).toHaveLength(3);
		const sourceHandles = new Set(rootEdges.map((edge) => edge.sourceHandle));
		expect(sourceHandles.size).toBe(3);
		expect(sourceHandles.has(HOOK_ROOT_SOURCE_HANDLE_ID)).toBe(true);
		expect(
			[...sourceHandles].some((handle) => handle?.startsWith(HOOK_ROOT_SOURCE_HANDLE_PREFIX))
		).toBe(true);
	});

	it('keeps both root and dependency edges selectable for click-to-remove', () => {
		const graph = buildXyflowDependencyGraph(baseLinkages);
		const rootEdges = graph.edges.filter((edge) => edge.data?.kind === 'hook_root');
		const dependencyEdges = graph.edges.filter((edge) => edge.data?.kind === 'dependency');

		expect(rootEdges.length).toBeGreaterThan(0);
		expect(dependencyEdges.length).toBeGreaterThan(0);

		for (const edge of rootEdges) {
			expect(edge.selectable).toBe(true);
			expect(edge.deletable).toBe(true);
		}
		for (const edge of dependencyEdges) {
			expect(edge.selectable).toBe(true);
			expect(edge.deletable).toBe(true);
		}
	});

	it('uses stable default edge marker colors that match edge semantics', () => {
		const graph = buildXyflowDependencyGraph(baseLinkages);
		expect(graph.edges.length).toBeGreaterThan(0);

		for (const edge of graph.edges) {
			const edgeStyle = String(edge.style ?? '');
			expect(edgeStyle).toContain('--xy-edge-stroke:');
			expect(edgeStyle).toContain('--xy-edge-stroke-width:');
			expect(edgeStyle).not.toMatch(/(?:^|;)stroke:\s*#/);

			const markerEnd = edge.markerEnd as { color?: string } | undefined;
			const expectedColor = edge.data?.missing
				? '#dc2626'
				: edge.data?.kind === 'dependency'
					? '#94a3b8'
					: '#3b82f6';
			expect(markerEnd?.color).toBe(expectedColor);
		}
	});

	it('packs independent root-trigger families so realtime actions do not overlap existing roots', () => {
		const graph = buildXyflowDependencyGraph([
			{
				local_mapping_id: 'map_validation',
				central_action_id: 'spam_detection_v1',
				action_type_indicator: 'master',
				action_name_label: 'Validation Spam Block',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map_realtime',
				central_action_id: 'clarification_assistant_v1',
				action_type_indicator: 'master',
				action_name_label: 'Realtime Clarification Assistant',
				trigger_hooks: ['real_time'],
				is_action_enabled_for_form: true,
				settings: {
					execution_mode: 'real_time',
					trigger_sources: {
						real_time: { type: 'hook_root' }
					}
				}
			},
			{
				local_mapping_id: 'map_after_submission',
				central_action_id: 'entry_summary',
				action_type_indicator: 'custom',
				action_name_label: 'Entry Summary',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			}
		]);

		const rootNodes = graph.nodes.filter((node) => node.data.kind === 'hook_root');
		expect(rootNodes.map((node) => node.id)).toEqual([
			'__hook_root__:real_time',
			'__hook_root__:gform_validation',
			'__hook_root__:gform_after_submission'
		]);

		for (let leftIndex = 0; leftIndex < graph.nodes.length; leftIndex += 1) {
			for (let rightIndex = leftIndex + 1; rightIndex < graph.nodes.length; rightIndex += 1) {
				const left = graph.nodes[leftIndex]!;
				const right = graph.nodes[rightIndex]!;
				expect(
					rectanglesOverlap(nodeRect(left), nodeRect(right)),
					`${left.id} should not overlap ${right.id}`
				).toBe(false);
			}
		}

		const byId = new Map(graph.nodes.map((node) => [node.id, node]));
		expect(byId.get('__hook_root__:real_time')!.position.y).toBeLessThan(
			byId.get('__hook_root__:gform_validation')!.position.y
		);
		expect(byId.get('__hook_root__:gform_validation')!.position.y).toBeLessThan(
			byId.get('__hook_root__:gform_after_submission')!.position.y
		);
	});
});
