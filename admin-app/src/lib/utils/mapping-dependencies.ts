import type { FormActionLinkage } from '$lib/api/types';

export type MappingTriggerSourceKind = 'hook_root' | 'mapping' | 'unbound';

export interface MappingTriggerSource {
	type: MappingTriggerSourceKind;
	mappingId?: string;
}

export type MappingTriggerSourceRecord = Record<string, MappingTriggerSource>;

export type DependencyValidationIssue =
	| { code: 'self'; mappingId: string; dependencyId: string }
	| { code: 'missing'; mappingId: string; dependencyId: string }
	| { code: 'hook_mismatch'; mappingId: string; dependencyId: string; missingHooks: string[] }
	| { code: 'execution_mode_mismatch'; mappingId: string; dependencyId: string }
	| { code: 'unbound_trigger'; mappingId: string; hook: string }
	| { code: 'cycle'; mappingId: string };

export interface DependencyGraphMappingNode {
	kind: 'mapping';
	id: string;
	label: string;
	depth: number;
	row: number;
	x: number;
	y: number;
	linkage: FormActionLinkage;
}

export interface DependencyGraphHookRootNode {
	kind: 'hook_root';
	id: string;
	label: string;
	depth: number;
	row: number;
	x: number;
	y: number;
	hook: string;
}

export type DependencyGraphNode = DependencyGraphMappingNode | DependencyGraphHookRootNode;

export interface DependencyGraphEdge {
	from: string;
	to: string;
	missing: boolean;
	kind: 'dependency' | 'hook_root';
	hook?: string;
}

export interface DependencyGraphData {
	nodes: DependencyGraphNode[];
	edges: DependencyGraphEdge[];
	cycleIds: string[];
}

export type ExecutionPreviewBlockReason =
	| 'disabled'
	| 'missing_dependency'
	| 'cycle'
	| 'upstream_blocked'
	| 'invalid_trigger'
	| 'policy_violation';

export interface ExecutionPreviewBlockedNode {
	mappingId: string;
	reason: ExecutionPreviewBlockReason;
	details?: string;
}

export interface HookExecutionPreview {
	hook: string;
	order: string[];
	waves: string[][];
	runnable: string[];
	blocked: ExecutionPreviewBlockedNode[];
	cycleIds: string[];
}

export interface DependencyExecutionPreview {
	hookScope: 'all' | string;
	availableHooks: string[];
	hooks: HookExecutionPreview[];
}

const COLUMN_GAP = 420;
const KNOWN_HOOK_ORDER: Record<string, number> = {
	gform_validation: 10,
	gform_after_submission: 20
};

function compareHookIds(left: string, right: string): number {
	const leftRank = KNOWN_HOOK_ORDER[left] ?? 1000;
	const rightRank = KNOWN_HOOK_ORDER[right] ?? 1000;
	if (leftRank !== rightRank) return leftRank - rightRank;
	return left.localeCompare(right);
}

function sortHookIds(ids: Iterable<string>): string[] {
	return Array.from(new Set(ids)).sort(compareHookIds);
}

export function normalizeHooks(hooks: string[] | undefined): string[] {
	if (!Array.isArray(hooks)) return [];
	return sortHookIds(
		hooks.map((hook) => hook?.toString().trim()).filter((hook): hook is string => Boolean(hook))
	);
}

function normalizeTriggerSourceKind(value: unknown): MappingTriggerSourceKind | null {
	if (typeof value !== 'string') return null;
	const normalized = value.trim().toLowerCase();
	if (normalized === 'mapping') return 'mapping';
	if (normalized === 'hook_root' || normalized === 'root' || normalized === 'hook') {
		return 'hook_root';
	}
	if (normalized === 'unbound' || normalized === 'detached') {
		return 'unbound';
	}
	return null;
}

function normalizeTriggerSourceHook(hook: unknown): string | null {
	if (typeof hook !== 'string') return null;
	const normalized = hook.trim();
	if (!normalized) return null;
	return normalized;
}

function normalizeTriggerSourceMappingId(value: unknown): string | null {
	if (typeof value !== 'string') return null;
	const normalized = value.trim();
	if (!normalized) return null;
	return normalized;
}

export function normalizeTriggerSources(
	value: unknown,
	allowedHooks: string[] = []
): MappingTriggerSourceRecord {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return {};
	}

	const allowedHookSet = allowedHooks.length > 0 ? new Set(normalizeHooks(allowedHooks)) : null;
	const entries = Object.entries(value as Record<string, unknown>);
	const normalized: MappingTriggerSourceRecord = {};

	for (const [rawHook, rawSource] of entries) {
		const hook = normalizeTriggerSourceHook(rawHook);
		if (!hook) continue;
		if (allowedHookSet && !allowedHookSet.has(hook)) continue;

		if (!rawSource || typeof rawSource !== 'object' || Array.isArray(rawSource)) continue;
		const source = rawSource as Record<string, unknown>;
		const kind =
			normalizeTriggerSourceKind(source.type) ??
			normalizeTriggerSourceKind(source.source_type) ??
			normalizeTriggerSourceKind(source.kind);

		if (!kind) continue;
		if (kind === 'hook_root') {
			normalized[hook] = { type: 'hook_root' };
			continue;
		}
		if (kind === 'unbound') {
			normalized[hook] = { type: 'unbound' };
			continue;
		}

		const mappingId =
			normalizeTriggerSourceMappingId(source.mapping_id) ??
			normalizeTriggerSourceMappingId(source.source_mapping_id) ??
			normalizeTriggerSourceMappingId(source.mappingId);
		if (!mappingId) continue;

		normalized[hook] = {
			type: 'mapping',
			mappingId
		};
	}

	return normalized;
}

function hasExplicitTriggerSources(linkage: FormActionLinkage): boolean {
	const settings = linkage.settings as Record<string, unknown> | undefined;
	const raw = settings?.trigger_sources;
	if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return false;
	return Object.keys(raw as Record<string, unknown>).length > 0;
}

export function getMappingTriggerHooks(linkage: FormActionLinkage): string[] {
	const triggerHooks = normalizeHooks(linkage.trigger_hooks);
	const explicitSources = normalizeTriggerSources(
		(linkage.settings as Record<string, unknown> | undefined)?.trigger_sources,
		triggerHooks
	);
	return sortHookIds([...triggerHooks, ...Object.keys(explicitSources)]);
}

export function getMappingTriggerSources(linkage: FormActionLinkage): MappingTriggerSourceRecord {
	const hooks = getMappingTriggerHooks(linkage);
	const explicit = normalizeTriggerSources(
		(linkage.settings as Record<string, unknown> | undefined)?.trigger_sources,
		hooks
	);

	if (Object.keys(explicit).length > 0) {
		const completed: MappingTriggerSourceRecord = {};
		for (const hook of hooks) {
			completed[hook] = explicit[hook] ?? { type: 'hook_root' };
		}
		return completed;
	}

	const legacyDependencyIds = normalizeDependencyIds(linkage.settings?.dependency_ids);
	if (legacyDependencyIds.length !== 1) {
		return Object.fromEntries(hooks.map((hook) => [hook, { type: 'hook_root' as const }]));
	}

	return Object.fromEntries(
		hooks.map((hook) => [
			hook,
			{
				type: 'mapping' as const,
				mappingId: legacyDependencyIds[0]
			}
		])
	);
}

export function getMappingDependencyIdsForHook(linkage: FormActionLinkage, hook: string): string[] {
	const normalizedHook = hook?.toString().trim();
	if (!normalizedHook) return [];

	if (hasExplicitTriggerSources(linkage)) {
		const sources = getMappingTriggerSources(linkage);
		const source = sources[normalizedHook];
		if (!source || source.type !== 'mapping' || !source.mappingId) {
			return [];
		}
		return [source.mappingId];
	}

	const hooks = normalizeHooks(linkage.trigger_hooks);
	if (!hooks.includes(normalizedHook)) return [];
	return normalizeDependencyIds(linkage.settings?.dependency_ids);
}

export function deriveDependencyIdsFromTriggerSources(
	sources: MappingTriggerSourceRecord
): string[] {
	return Array.from(
		new Set(
			Object.values(sources)
				.filter((source) => source.type === 'mapping' && Boolean(source.mappingId))
				.map((source) => source.mappingId as string)
		)
	);
}

export function serializeTriggerSources(
	sources: MappingTriggerSourceRecord
): Record<string, { type: MappingTriggerSourceKind; mapping_id?: string }> {
	const serialized: Record<string, { type: MappingTriggerSourceKind; mapping_id?: string }> = {};
	for (const [hook, source] of Object.entries(sources)) {
		if (source.type === 'hook_root') {
			serialized[hook] = { type: 'hook_root' };
			continue;
		}
		if (source.type === 'unbound') {
			serialized[hook] = { type: 'unbound' };
			continue;
		}
		if (!source.mappingId) continue;
		serialized[hook] = { type: 'mapping', mapping_id: source.mappingId };
	}
	return serialized;
}

export function withHookTriggerSource(
	linkage: FormActionLinkage,
	hook: string,
	source: MappingTriggerSource
): FormActionLinkage {
	const hooks = new Set(getMappingTriggerHooks(linkage));
	hooks.add(hook);
	const nextHooks = Array.from(hooks).sort();
	const nextSources = {
		...getMappingTriggerSources(linkage),
		[hook]: source
	};
	const nextDependencyIds = deriveDependencyIdsFromTriggerSources(nextSources);

	const nextSettings: Record<string, unknown> = {
		...(linkage.settings ?? {}),
		trigger_sources: serializeTriggerSources(nextSources)
	};
	if (nextDependencyIds.length > 0) {
		nextSettings.dependency_ids = nextDependencyIds;
	} else {
		delete nextSettings.dependency_ids;
	}

	return {
		...linkage,
		trigger_hooks: nextHooks,
		settings: nextSettings
	};
}

export function normalizeDependencyIds(value: unknown): string[] {
	if (!Array.isArray(value)) return [];
	const normalized = value
		.map((item) => (typeof item === 'string' ? item.trim() : ''))
		.filter(Boolean);
	return Array.from(new Set(normalized));
}

export function getMappingDependencyIds(linkage: FormActionLinkage): string[] {
	if (!hasExplicitTriggerSources(linkage)) {
		return normalizeDependencyIds(linkage.settings?.dependency_ids);
	}

	return deriveDependencyIdsFromTriggerSources(getMappingTriggerSources(linkage));
}

export function setMappingDependencyIds(
	settings: Record<string, unknown> | undefined,
	dependencyIds: string[]
): Record<string, unknown> {
	const nextSettings = { ...(settings ?? {}) };
	const normalized = normalizeDependencyIds(dependencyIds);
	if (normalized.length === 0) {
		delete nextSettings.dependency_ids;
		return nextSettings;
	}
	nextSettings.dependency_ids = normalized;
	return nextSettings;
}

function dependenciesForValidationHook(linkage: FormActionLinkage, hook: string): string[] {
	return getMappingDependencyIdsForHook(linkage, hook);
}

export function validateMappingDependencies(
	items: FormActionLinkage[]
): DependencyValidationIssue[] {
	const issues: DependencyValidationIssue[] = [];
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));

	for (const linkage of items) {
		const mappingId = linkage.local_mapping_id;
		const triggerHooks = getMappingTriggerHooks(linkage);
		const mappingIsAsync = isMappingAsync(linkage);
		const triggerSources = getMappingTriggerSources(linkage);

		for (const hook of triggerHooks) {
			const triggerSource = triggerSources[hook];
			if (triggerSource?.type === 'unbound') {
				issues.push({ code: 'unbound_trigger', mappingId, hook });
				continue;
			}

			const dependencyIds = dependenciesForValidationHook(linkage, hook);
			for (const dependencyId of dependencyIds) {
				if (dependencyId === mappingId) {
					issues.push({ code: 'self', mappingId, dependencyId });
					continue;
				}

				const dependency = byId.get(dependencyId);
				if (!dependency) {
					issues.push({ code: 'missing', mappingId, dependencyId });
					continue;
				}

				const dependencyHooks = getMappingTriggerHooks(dependency);
				if (!canDependencySatisfyHook(dependencyHooks, hook)) {
					issues.push({
						code: 'hook_mismatch',
						mappingId,
						dependencyId,
						missingHooks: [hook]
					});
				}

				const dependencyRunsAfterSubmission = dependencyHooks.includes('gform_after_submission');
				if (hook === 'gform_after_submission' && dependencyRunsAfterSubmission) {
					const dependencyIsAsync = isMappingAsync(dependency);
					if (dependencyIsAsync && !mappingIsAsync) {
						issues.push({ code: 'execution_mode_mismatch', mappingId, dependencyId });
					}
				}
			}
		}
	}

	for (const cycleId of detectCycleIds(items)) {
		issues.push({ code: 'cycle', mappingId: cycleId });
	}

	return issues;
}

export function formatDependencyIssues(issues: DependencyValidationIssue[]): string[] {
	return issues.map((issue) => {
		switch (issue.code) {
			case 'self':
				return `${issue.mappingId} cannot depend on itself.`;
			case 'missing':
				return `${issue.mappingId} depends on missing mapping ${issue.dependencyId}.`;
			case 'hook_mismatch':
				return `${issue.mappingId} depends on ${issue.dependencyId}, but ${issue.dependencyId} is missing hooks: ${issue.missingHooks.join(', ')}.`;
			case 'execution_mode_mismatch':
				return `${issue.mappingId} depends on Background mapping ${issue.dependencyId} during after-submission, so ${issue.mappingId} must also run in Background.`;
			case 'unbound_trigger':
				return `${issue.mappingId} has no trigger source bound for hook ${issue.hook}.`;
			case 'cycle':
				return `Dependency cycle includes ${issue.mappingId}.`;
		}
	});
}

export function dependencyIssueIdentity(issue: DependencyValidationIssue): string {
	switch (issue.code) {
		case 'cycle':
			return `${issue.code}:${issue.mappingId}`;
		case 'unbound_trigger':
			return `${issue.code}:${issue.mappingId}:${issue.hook}`;
		case 'hook_mismatch':
			return `${issue.code}:${issue.mappingId}:${issue.dependencyId}:${issue.missingHooks.join('|')}`;
		default:
			return `${issue.code}:${issue.mappingId}:${'dependencyId' in issue ? issue.dependencyId : ''}`;
	}
}

export function findIntroducedDependencyIssues(
	baselineIssues: DependencyValidationIssue[],
	candidateIssues: DependencyValidationIssue[]
): DependencyValidationIssue[] {
	const baselineKeys = new Set(baselineIssues.map(dependencyIssueIdentity));
	return candidateIssues.filter((issue) => !baselineKeys.has(dependencyIssueIdentity(issue)));
}

export function buildDependencyGraph(items: FormActionLinkage[]): DependencyGraphData {
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));
	const cycleIds = detectCycleIds(items);
	const order = topologicalOrder(items);
	const layoutOrder = order.filter((mappingId) => byId.has(mappingId));

	const mappingNodes: DependencyGraphMappingNode[] = [];
	for (let index = 0; index < layoutOrder.length; index += 1) {
		const id = layoutOrder[index];
		const linkage = byId.get(id);
		if (!linkage) continue;

		mappingNodes.push({
			kind: 'mapping',
			id,
			label: linkage.action_name_label || linkage.central_action_id,
			depth: index,
			row: 0,
			x: index * COLUMN_GAP,
			y: 0,
			linkage
		});
	}

	const edgeIds = new Set<string>();
	const dependencyEdges: DependencyGraphEdge[] = [];
	const rootHooks = new Set<string>();
	const hookRootEdges: DependencyGraphEdge[] = [];
	for (const linkage of items) {
		const hooks = getMappingTriggerHooks(linkage);
		const sources = getMappingTriggerSources(linkage);
		for (const hook of hooks) {
			rootHooks.add(hook);
			const source = sources[hook] ?? { type: 'hook_root' as const };
			if (source.type === 'mapping' && source.mappingId) {
				const edgeId = `dependency:${source.mappingId}->${linkage.local_mapping_id}:${hook}`;
				if (edgeIds.has(edgeId)) continue;
				edgeIds.add(edgeId);
				dependencyEdges.push({
					from: source.mappingId,
					to: linkage.local_mapping_id,
					missing: !byId.has(source.mappingId),
					kind: 'dependency',
					hook
				});
				continue;
			}
			if (source.type === 'unbound') {
				continue;
			}

			const rootEdgeId = `hook_root:${hookRootNodeId(hook)}->${linkage.local_mapping_id}:${hook}`;
			if (edgeIds.has(rootEdgeId)) continue;
			edgeIds.add(rootEdgeId);
			hookRootEdges.push({
				from: hookRootNodeId(hook),
				to: linkage.local_mapping_id,
				missing: false,
				kind: 'hook_root',
				hook
			});
		}
	}

	const rootNodes: DependencyGraphHookRootNode[] = Array.from(rootHooks)
		.sort(compareHookIds)
		.map((hook, index) => ({
			kind: 'hook_root',
			id: hookRootNodeId(hook),
			label: hook,
			depth: -1,
			row: index,
			x: -COLUMN_GAP,
			y: index * 180,
			hook
		}));

	return {
		nodes: [...rootNodes, ...mappingNodes],
		edges: [...hookRootEdges, ...dependencyEdges],
		cycleIds
	};
}

export function buildExecutionPreview(
	items: FormActionLinkage[],
	hookScope: 'all' | string = 'all'
): DependencyExecutionPreview {
	const availableHooks = sortHookIds(items.flatMap((item) => getMappingTriggerHooks(item)));

	const hooksToPlan =
		hookScope === 'all' ? availableHooks : availableHooks.includes(hookScope) ? [hookScope] : [];

	return {
		hookScope,
		availableHooks,
		hooks: hooksToPlan.map((hook) => buildHookExecutionPreview(items, hook))
	};
}

function hookRootNodeId(hook: string): string {
	return `__hook_root__:${hook}`;
}

function isMappingAsync(linkage: FormActionLinkage): boolean {
	const hooks = getMappingTriggerHooks(linkage);
	const hasValidationHook = hooks.includes('gform_validation');
	const hasAfterSubmissionHook = hooks.includes('gform_after_submission');

	if (hasValidationHook && !hasAfterSubmissionHook) {
		return false;
	}

	const asyncSetting = linkage.settings?.async;
	if (typeof asyncSetting !== 'undefined') {
		return normalizeBoolean(asyncSetting);
	}

	const executionMode = linkage.settings?.execution_mode;
	if (executionMode === 'after_submission') {
		return true;
	}
	if (executionMode === 'validation') {
		return false;
	}

	const topLevelExecutionMode = linkage.execution_mode;
	if (topLevelExecutionMode === 'after_submission') {
		return true;
	}
	if (topLevelExecutionMode === 'validation') {
		return false;
	}

	if (hasAfterSubmissionHook) {
		return true;
	}

	if (linkage.action_type_indicator === 'master') {
		return true;
	}

	return false;
}

function normalizeBoolean(value: unknown): boolean {
	if (typeof value === 'boolean') return value;
	if (typeof value === 'number') return value !== 0;
	if (typeof value !== 'string') return Boolean(value);

	const normalized = value.trim().toLowerCase();
	if (
		normalized === '' ||
		normalized === '0' ||
		normalized === 'false' ||
		normalized === 'off' ||
		normalized === 'no'
	) {
		return false;
	}

	return true;
}

function buildHookExecutionPreview(items: FormActionLinkage[], hook: string): HookExecutionPreview {
	const nodes = items.filter((item) => getMappingTriggerHooks(item).includes(hook));
	const byIdAll = new Map(items.map((item) => [item.local_mapping_id, item]));
	const byId = new Map(nodes.map((item) => [item.local_mapping_id, item]));
	const cycleIds = detectCycleIdsByHook(nodes, hook);
	const cycleSet = new Set(cycleIds);
	const order = topologicalOrderByHook(nodes, hook);
	const blocked: ExecutionPreviewBlockedNode[] = [];
	const blockedById = new Map<string, ExecutionPreviewBlockedNode>();
	const runnable = new Set<string>();

	for (const node of nodes) {
		const mappingId = node.local_mapping_id;
		if (node.is_action_enabled_for_form === false) {
			const record: ExecutionPreviewBlockedNode = {
				mappingId,
				reason: 'disabled'
			};
			blocked.push(record);
			blockedById.set(mappingId, record);
			continue;
		}

		if (cycleSet.has(mappingId)) {
			const record: ExecutionPreviewBlockedNode = {
				mappingId,
				reason: 'cycle'
			};
			blocked.push(record);
			blockedById.set(mappingId, record);
			continue;
		}

		const triggerSource = getMappingTriggerSources(node)[hook];
		if (triggerSource?.type === 'unbound') {
			const record: ExecutionPreviewBlockedNode = {
				mappingId,
				reason: 'invalid_trigger',
				details: hook
			};
			blocked.push(record);
			blockedById.set(mappingId, record);
			continue;
		}

		const dependencyIds = getMappingDependencyIdsForHook(node, hook);
		const missing = dependencyIds.filter((dependencyId) => {
			if (byId.has(dependencyId)) return false;
			const dependency = byIdAll.get(dependencyId);
			if (!dependency) return true;
			const dependencyHooks = getMappingTriggerHooks(dependency);
			return !canDependencySatisfyHook(dependencyHooks, hook);
		});
		if (missing.length > 0) {
			const record: ExecutionPreviewBlockedNode = {
				mappingId,
				reason: 'missing_dependency',
				details: missing.join(', ')
			};
			blocked.push(record);
			blockedById.set(mappingId, record);
			continue;
		}

		if (hook === 'gform_after_submission') {
			const invalidDependency = dependencyIds.find((dependencyId) => {
				const dependency = byIdAll.get(dependencyId);
				if (!dependency) return false;
				if (!canDependencySatisfyHook(getMappingTriggerHooks(dependency), hook)) return false;
				return isMappingAsync(dependency) && !isMappingAsync(node);
			});
			if (invalidDependency) {
				const record: ExecutionPreviewBlockedNode = {
					mappingId,
					reason: 'policy_violation',
					details: `${invalidDependency}:execution_mode_mismatch`
				};
				blocked.push(record);
				blockedById.set(mappingId, record);
				continue;
			}
		}
	}

	for (const mappingId of order) {
		if (blockedById.has(mappingId)) {
			continue;
		}

		const node = byId.get(mappingId);
		if (!node) continue;
		const dependencyIds = getMappingDependencyIdsForHook(node, hook).filter((dependencyId) =>
			byId.has(dependencyId)
		);
		const blockingDependency = dependencyIds.find((dependencyId) => blockedById.has(dependencyId));
		if (blockingDependency) {
			const reason = blockedById.get(blockingDependency)?.reason ?? 'upstream_blocked';
			const record: ExecutionPreviewBlockedNode = {
				mappingId,
				reason: 'upstream_blocked',
				details: `${blockingDependency}:${reason}`
			};
			blocked.push(record);
			blockedById.set(mappingId, record);
			continue;
		}

		runnable.add(mappingId);
	}

	return {
		hook,
		order,
		waves: buildExecutionWaves(order, byId, runnable, hook),
		runnable: order.filter((mappingId) => runnable.has(mappingId)),
		blocked,
		cycleIds
	};
}

function buildExecutionWaves(
	order: string[],
	byId: Map<string, FormActionLinkage>,
	runnable: Set<string>,
	hook: string
): string[][] {
	if (order.length === 0) {
		return [];
	}

	const levelById = new Map<string, number>();
	for (const mappingId of order) {
		if (!runnable.has(mappingId)) {
			continue;
		}

		const node = byId.get(mappingId);
		if (!node) continue;
		const dependencyIds = getMappingDependencyIdsForHook(node, hook).filter((dependencyId) =>
			runnable.has(dependencyId)
		);
		const level =
			dependencyIds.length === 0
				? 0
				: dependencyIds.reduce((maxLevel, dependencyId) => {
						const currentLevel = levelById.get(dependencyId) ?? 0;
						return Math.max(maxLevel, currentLevel);
					}, 0) + 1;
		levelById.set(mappingId, level);
	}

	const wavesMap = new Map<number, string[]>();
	for (const mappingId of order) {
		if (!runnable.has(mappingId)) continue;
		const level = levelById.get(mappingId) ?? 0;
		const wave = wavesMap.get(level) ?? [];
		wave.push(mappingId);
		wavesMap.set(level, wave);
	}

	return Array.from(wavesMap.entries())
		.sort(([a], [b]) => a - b)
		.map(([, ids]) => ids);
}

function detectCycleIds(items: FormActionLinkage[]): string[] {
	return detectCycleIdsWithResolver(items, (item) => getMappingDependencyIds(item));
}

function detectCycleIdsByHook(items: FormActionLinkage[], hook: string): string[] {
	return detectCycleIdsWithResolver(items, (item) => getMappingDependencyIdsForHook(item, hook));
}

function detectCycleIdsWithResolver(
	items: FormActionLinkage[],
	getDependencyIds: (item: FormActionLinkage) => string[]
): string[] {
	const inDegree = new Map<string, number>();
	const outgoing = new Map<string, string[]>();
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));

	for (const item of items) {
		inDegree.set(item.local_mapping_id, 0);
		outgoing.set(item.local_mapping_id, []);
	}

	for (const item of items) {
		for (const dependencyId of getDependencyIds(item)) {
			if (!byId.has(dependencyId)) continue;
			outgoing.get(dependencyId)?.push(item.local_mapping_id);
			inDegree.set(item.local_mapping_id, (inDegree.get(item.local_mapping_id) ?? 0) + 1);
		}
	}

	const queue: string[] = [];
	for (const [id, degree] of inDegree) {
		if (degree === 0) queue.push(id);
	}

	let visited = 0;
	while (queue.length > 0) {
		const current = queue.shift()!;
		visited += 1;
		for (const neighbor of outgoing.get(current) ?? []) {
			const nextDegree = (inDegree.get(neighbor) ?? 0) - 1;
			inDegree.set(neighbor, nextDegree);
			if (nextDegree === 0) queue.push(neighbor);
		}
	}

	if (visited === items.length) return [];

	const cycleIds: string[] = [];
	for (const [id, degree] of inDegree) {
		if (degree > 0) cycleIds.push(id);
	}
	return cycleIds;
}

function topologicalOrder(items: FormActionLinkage[]): string[] {
	return topologicalOrderWithResolver(items, (item) => getMappingDependencyIds(item));
}

function topologicalOrderByHook(items: FormActionLinkage[], hook: string): string[] {
	return topologicalOrderWithResolver(items, (item) => getMappingDependencyIdsForHook(item, hook));
}

function topologicalOrderWithResolver(
	items: FormActionLinkage[],
	getDependencyIds: (item: FormActionLinkage) => string[]
): string[] {
	const inDegree = new Map<string, number>();
	const outgoing = new Map<string, string[]>();
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));
	const originalOrder = new Map(items.map((item, index) => [item.local_mapping_id, index]));

	for (const item of items) {
		inDegree.set(item.local_mapping_id, 0);
		outgoing.set(item.local_mapping_id, []);
	}

	for (const item of items) {
		for (const dependencyId of getDependencyIds(item)) {
			if (!byId.has(dependencyId)) continue;
			outgoing.get(dependencyId)?.push(item.local_mapping_id);
			inDegree.set(item.local_mapping_id, (inDegree.get(item.local_mapping_id) ?? 0) + 1);
		}
	}

	const queue = Array.from(inDegree.entries())
		.filter(([, degree]) => degree === 0)
		.map(([id]) => id)
		.sort((a, b) => (originalOrder.get(a) ?? 0) - (originalOrder.get(b) ?? 0));

	const order: string[] = [];
	while (queue.length > 0) {
		const current = queue.shift()!;
		order.push(current);

		const neighbors = [...(outgoing.get(current) ?? [])].sort(
			(a, b) => (originalOrder.get(a) ?? 0) - (originalOrder.get(b) ?? 0)
		);

		for (const neighbor of neighbors) {
			const nextDegree = (inDegree.get(neighbor) ?? 0) - 1;
			inDegree.set(neighbor, nextDegree);
			if (nextDegree === 0) queue.push(neighbor);
		}
		queue.sort((a, b) => (originalOrder.get(a) ?? 0) - (originalOrder.get(b) ?? 0));
	}

	for (const item of items) {
		if (!order.includes(item.local_mapping_id)) {
			order.push(item.local_mapping_id);
		}
	}

	return order;
}

export function canDependencySatisfyHook(dependencyHooks: string[], requiredHook: string): boolean {
	if (dependencyHooks.includes(requiredHook)) {
		return true;
	}
	// Validation (sync) can satisfy after-submission dependants.
	if (requiredHook === 'gform_after_submission' && dependencyHooks.includes('gform_validation')) {
		return true;
	}
	return false;
}

export function collectMissingRequiredHooks(
	targetHooks: string[],
	dependencyHooks: string[]
): string[] {
	return targetHooks.filter((hook) => !canDependencySatisfyHook(dependencyHooks, hook));
}
