import { describe, expect, it } from 'vitest';
import type { FormActionLinkage } from '$lib/api/types';
import {
	buildDependencyGraph,
	formatDependencyIssues,
	getMappingDependencyIds,
	normalizeDependencyIds,
	setMappingDependencyIds,
	validateMappingDependencies
} from '$lib/utils/mapping-dependencies';

function linkage(
	id: string,
	hooks: string[],
	dependencyIds: string[] = [],
	action = id
): FormActionLinkage {
	return {
		local_mapping_id: id,
		central_action_id: action,
		action_type_indicator: 'master',
		trigger_hooks: hooks,
		action_name_label: id,
		settings: dependencyIds.length > 0 ? { dependency_ids: dependencyIds } : {}
	};
}

describe('mapping-dependencies utils (CB-FORMS-004)', () => {
	it('normalizes dependency ids', () => {
		expect(normalizeDependencyIds([' a ', 'a', '', 7 as any])).toEqual(['a']);
	});

	it('reads and writes dependency ids from settings', () => {
		const item = linkage('map_a', ['gform_validation'], ['map_root']);
		expect(getMappingDependencyIds(item)).toEqual(['map_root']);

		const updated = setMappingDependencyIds(item.settings as Record<string, unknown>, [
			'map_root',
			'map_root',
			'  '
		]);
		expect(updated.dependency_ids).toEqual(['map_root']);
	});

	it('validates missing/self/hook mismatch dependencies', () => {
		const items: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation'], ['map_missing']),
			linkage('map_b', ['gform_validation'], ['map_b']),
			linkage('map_c', ['gform_validation', 'gform_after_submission'], ['map_d']),
			linkage('map_d', ['gform_validation'])
		];

		const issues = validateMappingDependencies(items);
		const messages = formatDependencyIssues(issues);

		expect(messages.some((message) => message.includes('map_missing'))).toBe(true);
		expect(messages.some((message) => message.includes('cannot depend on itself'))).toBe(true);
		expect(messages.some((message) => message.includes('missing hooks'))).toBe(true);
	});

	it('detects dependency cycles', () => {
		const items: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation'], ['map_c']),
			linkage('map_b', ['gform_validation'], ['map_a']),
			linkage('map_c', ['gform_validation'], ['map_b'])
		];

		const issues = validateMappingDependencies(items);
		expect(issues.some((issue) => issue.code === 'cycle')).toBe(true);
	});

	it('rejects async dependency when dependent mapping is sync after-submission', () => {
		const items: FormActionLinkage[] = [
			linkage('map_async', ['gform_after_submission']),
			{
				local_mapping_id: 'map_sync',
				central_action_id: 'custom-hello',
				action_type_indicator: 'custom',
				trigger_hooks: ['gform_after_submission'],
				action_name_label: 'map_sync',
				settings: { dependency_ids: ['map_async'], execution_mode: 'validation' }
			}
		];

		const issues = validateMappingDependencies(items);
		expect(
			issues.some(
				(issue) =>
					issue.code === 'execution_mode_mismatch' &&
					issue.mappingId === 'map_sync' &&
					issue.dependencyId === 'map_async'
			)
		).toBe(true);

		const messages = formatDependencyIssues(issues);
		expect(messages.some((message) => message.includes('must also run async'))).toBe(true);
	});

	it('builds graph node depth and edges', () => {
		const items: FormActionLinkage[] = [
			linkage('map_root', ['gform_validation']),
			linkage('map_mid', ['gform_validation'], ['map_root']),
			linkage('map_leaf', ['gform_validation'], ['map_mid'])
		];

		const graph = buildDependencyGraph(items);
		const byId = new Map(graph.nodes.map((node) => [node.id, node]));

		expect(graph.edges).toHaveLength(2);
		expect(graph.edges.every((edge) => edge.kind === 'dependency')).toBe(true);
		expect(byId.get('map_root')?.depth).toBe(0);
		expect(byId.get('map_mid')?.depth).toBe(1);
		expect(byId.get('map_leaf')?.depth).toBe(2);
		expect(graph.cycleIds).toEqual([]);
	});

	it('builds left-to-right execution connectors when dependencies are empty', () => {
		const items: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation']),
			linkage('map_b', ['gform_validation']),
			linkage('map_c', ['gform_validation'])
		];

		const graph = buildDependencyGraph(items);
		const byId = new Map(graph.nodes.map((node) => [node.id, node]));

		expect(graph.edges).toEqual([
			{ from: 'map_a', to: 'map_b', missing: false, kind: 'execution' },
			{ from: 'map_b', to: 'map_c', missing: false, kind: 'execution' }
		]);
		expect(byId.get('map_a')?.depth).toBe(0);
		expect(byId.get('map_b')?.depth).toBe(1);
		expect(byId.get('map_c')?.depth).toBe(2);
	});
});
