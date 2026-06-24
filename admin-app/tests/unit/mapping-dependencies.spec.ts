import { describe, expect, it } from 'vitest';
import type { FormActionLinkage } from '$lib/api/types';
import {
	buildExecutionPreview,
	buildDependencyGraph,
	dependencyIssueIdentity,
	findIntroducedDependencyIssues,
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
			linkage('map_c', ['gform_validation'], ['map_d']),
			linkage('map_d', ['gform_after_submission'])
		];

		const issues = validateMappingDependencies(items);
		const messages = formatDependencyIssues(issues);

		expect(messages.some((message) => message.includes('map_missing'))).toBe(true);
		expect(messages.some((message) => message.includes('cannot depend on itself'))).toBe(true);
		expect(messages.some((message) => message.includes('missing hooks'))).toBe(true);
	});

	it('flags mappings with unbound trigger sources as invalid', () => {
		const items: FormActionLinkage[] = [
			{
				local_mapping_id: 'map_unbound',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_validation'],
				action_name_label: 'map_unbound',
				settings: {
					trigger_sources: {
						gform_validation: { type: 'unbound' }
					}
				}
			}
		];

		const issues = validateMappingDependencies(items);
		expect(
			issues.some(
				(issue) =>
					issue.code === 'unbound_trigger' &&
					issue.mappingId === 'map_unbound' &&
					issue.hook === 'gform_validation'
			)
		).toBe(true);
		expect(
			formatDependencyIssues(issues).some((message) => message.includes('no trigger source'))
		).toBe(true);
	});

	it('keeps stable issue identities for hook mismatch issues', () => {
		const items: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation'], ['map_b']),
			linkage('map_b', ['gform_after_submission'])
		];
		const issues = validateMappingDependencies(items);
		const hookMismatch = issues.find((issue) => issue.code === 'hook_mismatch');
		expect(hookMismatch).toBeTruthy();
		expect(dependencyIssueIdentity(hookMismatch!)).toContain('hook_mismatch:map_a:map_b');
	});

	it('keeps stable issue identities for unbound trigger issues', () => {
		const items: FormActionLinkage[] = [
			{
				local_mapping_id: 'map_unbound',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_after_submission'],
				action_name_label: 'map_unbound',
				settings: {
					trigger_sources: {
						gform_after_submission: { type: 'unbound' }
					}
				}
			}
		];
		const issues = validateMappingDependencies(items);
		const unboundIssue = issues.find((issue) => issue.code === 'unbound_trigger');
		expect(unboundIssue).toBeTruthy();
		expect(dependencyIssueIdentity(unboundIssue!)).toBe(
			'unbound_trigger:map_unbound:gform_after_submission'
		);
	});

	it('finds only newly introduced dependency issues', () => {
		const baselineItems: FormActionLinkage[] = [
			linkage('map_a', ['gform_after_submission'], ['map_b']),
			linkage('map_b', ['gform_validation'])
		];
		const candidateItems: FormActionLinkage[] = [
			...baselineItems,
			linkage('map_c', ['gform_validation'])
		];

		const introduced = findIntroducedDependencyIssues(
			validateMappingDependencies(baselineItems),
			validateMappingDependencies(candidateItems)
		);

		expect(introduced).toEqual([]);
	});

	it('detects introduced issues when a new invalid edge is added', () => {
		const baselineItems: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation']),
			linkage('map_b', ['gform_validation'])
		];
		const candidateItems: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation'], ['map_b']),
			linkage('map_b', ['gform_validation'], ['map_a'])
		];

		const introduced = findIntroducedDependencyIssues(
			validateMappingDependencies(baselineItems),
			validateMappingDependencies(candidateItems)
		);

		expect(introduced.some((issue) => issue.code === 'cycle')).toBe(true);
	});

	it('allows validation-hook dependency for after-submission dependant', () => {
		const items: FormActionLinkage[] = [
			linkage('map_sync_upstream', ['gform_validation']),
			{
				local_mapping_id: 'map_async_downstream',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_after_submission'],
				action_name_label: 'map_async_downstream',
				settings: { dependency_ids: ['map_sync_upstream'] }
			}
		];

		const issues = validateMappingDependencies(items);
		expect(issues.some((issue) => issue.code === 'hook_mismatch')).toBe(false);

		const preview = buildExecutionPreview(items, 'all');
		const afterSubmission = preview.hooks.find((hook) => hook.hook === 'gform_after_submission');
		expect(afterSubmission?.runnable).toEqual(['map_async_downstream']);
	});

	it('allows per-hook dependency source when mapping runs on sync and async hooks', () => {
		const items: FormActionLinkage[] = [
			linkage('map_async_parent', ['gform_after_submission']),
			{
				local_mapping_id: 'map_dual_child',
				central_action_id: 'dual_child',
				action_type_indicator: 'custom',
				trigger_hooks: ['gform_validation', 'gform_after_submission'],
				action_name_label: 'map_dual_child',
				settings: {
					trigger_sources: {
						gform_validation: { type: 'hook_root' },
						gform_after_submission: { type: 'mapping', mapping_id: 'map_async_parent' }
					},
					dependency_ids: ['map_async_parent']
				}
			}
		];

		const issues = validateMappingDependencies(items);
		expect(issues.some((issue) => issue.code === 'hook_mismatch')).toBe(false);

		const validationPreview = buildExecutionPreview(items, 'gform_validation').hooks[0];
		const afterPreview = buildExecutionPreview(items, 'gform_after_submission').hooks[0];
		expect(validationPreview?.runnable).toContain('map_dual_child');
		expect(afterPreview?.runnable).toEqual(['map_async_parent', 'map_dual_child']);
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
		expect(messages.some((message) => message.includes('must also run in Background'))).toBe(
			true
		);
	});

	it('rejects async dependency when dependent mapping uses canonical after-submission hook', () => {
		const items: FormActionLinkage[] = [
			linkage('map_async', ['after_submission']),
			{
				local_mapping_id: 'map_sync',
				central_action_id: 'custom-hello',
				action_type_indicator: 'custom',
				trigger_hooks: ['after_submission'],
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

		const preview = buildExecutionPreview(items, 'after_submission');
		const hookPreview = preview.hooks[0];
		expect(hookPreview?.runnable).toEqual(['map_async']);
		expect(
			hookPreview?.blocked.some(
				(item) => item.mappingId === 'map_sync' && item.reason === 'policy_violation'
			)
		).toBe(true);
	});

	it('marks execution-mode mismatch as blocked in after-submission preview', () => {
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

		const preview = buildExecutionPreview(items, 'gform_after_submission');
		const hookPreview = preview.hooks[0];

		expect(hookPreview?.runnable).toEqual(['map_async']);
		expect(
			hookPreview?.blocked.some(
				(item) => item.mappingId === 'map_sync' && item.reason === 'policy_violation'
			)
		).toBe(true);
	});

	it('includes both sync and async autonomous mappings in all-hook preview', () => {
		const items: FormActionLinkage[] = [
			linkage('map_sync_a', ['gform_validation']),
			linkage('map_sync_b', ['gform_validation']),
			linkage('map_async_c', ['gform_after_submission'])
		];

		const preview = buildExecutionPreview(items, 'all');
		expect(preview.availableHooks).toEqual(['gform_validation', 'gform_after_submission']);

		const runnableUnion = new Set(
			preview.hooks.flatMap((hookPreview) => hookPreview.runnable ?? [])
		);
		expect(runnableUnion).toEqual(new Set(['map_sync_a', 'map_sync_b', 'map_async_c']));
	});

	it('builds graph node depth and edges', () => {
		const items: FormActionLinkage[] = [
			linkage('map_root', ['gform_validation']),
			linkage('map_mid', ['gform_validation'], ['map_root']),
			linkage('map_leaf', ['gform_validation'], ['map_mid'])
		];

		const graph = buildDependencyGraph(items);
		const byId = new Map(graph.nodes.map((node) => [node.id, node]));
		const dependencyEdges = graph.edges.filter((edge) => edge.kind === 'dependency');
		const rootEdges = graph.edges.filter((edge) => edge.kind === 'hook_root');

		expect(dependencyEdges).toHaveLength(2);
		expect(rootEdges).toHaveLength(1);
		expect(rootEdges[0]).toEqual({
			from: '__hook_root__:gform_validation',
			to: 'map_root',
			missing: false,
			kind: 'hook_root',
			hook: 'gform_validation'
		});
		expect(byId.get('map_root')?.depth).toBe(0);
		expect(byId.get('map_mid')?.depth).toBe(1);
		expect(byId.get('map_leaf')?.depth).toBe(2);
		expect(graph.cycleIds).toEqual([]);
	});

	it('builds hook-root connectors when dependencies are empty', () => {
		const items: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation']),
			linkage('map_b', ['gform_validation']),
			linkage('map_c', ['gform_validation'])
		];

		const graph = buildDependencyGraph(items);
		const byId = new Map(graph.nodes.map((node) => [node.id, node]));
		const rootEdges = graph.edges.filter((edge) => edge.kind === 'hook_root');
		const dependencyEdges = graph.edges.filter((edge) => edge.kind === 'dependency');

		expect(rootEdges).toEqual([
			{
				from: '__hook_root__:gform_validation',
				to: 'map_a',
				missing: false,
				kind: 'hook_root',
				hook: 'gform_validation'
			},
			{
				from: '__hook_root__:gform_validation',
				to: 'map_b',
				missing: false,
				kind: 'hook_root',
				hook: 'gform_validation'
			},
			{
				from: '__hook_root__:gform_validation',
				to: 'map_c',
				missing: false,
				kind: 'hook_root',
				hook: 'gform_validation'
			}
		]);
		expect(dependencyEdges).toEqual([]);
		expect(byId.get('map_a')?.depth).toBe(0);
		expect(byId.get('map_b')?.depth).toBe(1);
		expect(byId.get('map_c')?.depth).toBe(2);
	});

	it('omits root edges when a hook is explicitly unbound', () => {
		const items: FormActionLinkage[] = [
			{
				local_mapping_id: 'map_unbound',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_after_submission'],
				action_name_label: 'map_unbound',
				settings: {
					trigger_sources: {
						gform_after_submission: { type: 'unbound' }
					}
				}
			}
		];

		const graph = buildDependencyGraph(items);
		const rootEdges = graph.edges.filter((edge) => edge.kind === 'hook_root');
		expect(rootEdges).toEqual([]);
	});

	it('marks unbound trigger mappings as blocked in execution preview', () => {
		const items: FormActionLinkage[] = [
			{
				local_mapping_id: 'map_unbound',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_after_submission'],
				action_name_label: 'map_unbound',
				settings: {
					trigger_sources: {
						gform_after_submission: { type: 'unbound' }
					}
				}
			}
		];

		const preview = buildExecutionPreview(items, 'gform_after_submission');
		const hookPreview = preview.hooks[0];
		expect(hookPreview?.runnable).toEqual([]);
		expect(
			hookPreview?.blocked.some(
				(item) => item.mappingId === 'map_unbound' && item.reason === 'invalid_trigger'
			)
		).toBe(true);
	});

	it('omits hook-root edges for non-autonomous mappings', () => {
		const items: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation']),
			linkage('map_b', ['gform_validation'], ['map_a']),
			linkage('map_c', ['gform_validation'], ['map_b'])
		];

		const graph = buildDependencyGraph(items);
		const rootEdges = graph.edges.filter((edge) => edge.kind === 'hook_root');

		expect(rootEdges).toEqual([
			{
				from: '__hook_root__:gform_validation',
				to: 'map_a',
				missing: false,
				kind: 'hook_root',
				hook: 'gform_validation'
			}
		]);
		expect(rootEdges.some((edge) => edge.to === 'map_b')).toBe(false);
		expect(rootEdges.some((edge) => edge.to === 'map_c')).toBe(false);
	});

	it('adds one hook-root edge per hook for autonomous mappings', () => {
		const items: FormActionLinkage[] = [
			linkage('map_multi', ['gform_validation', 'gform_after_submission'])
		];

		const graph = buildDependencyGraph(items);
		const rootEdges = graph.edges.filter((edge) => edge.kind === 'hook_root');

		expect(rootEdges).toEqual([
			{
				from: '__hook_root__:gform_validation',
				to: 'map_multi',
				missing: false,
				kind: 'hook_root',
				hook: 'gform_validation'
			},
			{
				from: '__hook_root__:gform_after_submission',
				to: 'map_multi',
				missing: false,
				kind: 'hook_root',
				hook: 'gform_after_submission'
			}
		]);
	});

	it('builds mixed trigger sources with one root edge and one dependency edge per hook', () => {
		const items: FormActionLinkage[] = [
			linkage('map_parent', ['gform_after_submission']),
			{
				local_mapping_id: 'map_child',
				central_action_id: 'content_quality',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_validation', 'gform_after_submission'],
				action_name_label: 'map_child',
				settings: {
					trigger_sources: {
						gform_validation: { type: 'hook_root' },
						gform_after_submission: { type: 'mapping', mapping_id: 'map_parent' }
					}
				}
			}
		];

		const graph = buildDependencyGraph(items);
		const rootEdges = graph.edges.filter(
			(edge) => edge.kind === 'hook_root' && edge.to === 'map_child'
		);
		const dependencyEdges = graph.edges.filter(
			(edge) => edge.kind === 'dependency' && edge.to === 'map_child'
		);

		expect(rootEdges).toEqual([
			{
				from: '__hook_root__:gform_validation',
				to: 'map_child',
				missing: false,
				kind: 'hook_root',
				hook: 'gform_validation'
			}
		]);
		expect(dependencyEdges).toEqual([
			{
				from: 'map_parent',
				to: 'map_child',
				missing: false,
				kind: 'dependency',
				hook: 'gform_after_submission'
			}
		]);
	});

	it('renders hook root nodes even when every action has dependencies', () => {
		const items: FormActionLinkage[] = [
			linkage('map_a', ['gform_validation', 'gform_after_submission'], ['map_b']),
			linkage('map_b', ['gform_validation', 'gform_after_submission'], ['map_c']),
			linkage('map_c', ['gform_validation', 'gform_after_submission'], ['map_a'])
		];
		const graph = buildDependencyGraph(items);
		const rootNodes = graph.nodes.filter((node) => node.kind === 'hook_root');
		expect(rootNodes.some((node) => node.id === '__hook_root__:gform_validation')).toBe(true);
		expect(rootNodes.some((node) => node.id === '__hook_root__:gform_after_submission')).toBe(true);
	});
});
