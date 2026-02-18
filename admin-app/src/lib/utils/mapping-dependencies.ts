import type { FormActionLinkage } from '$lib/api/types';

export type DependencyValidationIssue =
	| { code: 'self'; mappingId: string; dependencyId: string }
	| { code: 'missing'; mappingId: string; dependencyId: string }
	| { code: 'hook_mismatch'; mappingId: string; dependencyId: string; missingHooks: string[] }
	| { code: 'execution_mode_mismatch'; mappingId: string; dependencyId: string }
	| { code: 'cycle'; mappingId: string };

export interface DependencyGraphNode {
	id: string;
	label: string;
	depth: number;
	row: number;
	x: number;
	y: number;
	linkage: FormActionLinkage;
}

export interface DependencyGraphEdge {
	from: string;
	to: string;
	missing: boolean;
}

export interface DependencyGraphData {
	nodes: DependencyGraphNode[];
	edges: DependencyGraphEdge[];
	cycleIds: string[];
}

const COLUMN_GAP = 420;
const ROW_GAP = 220;

export function normalizeDependencyIds(value: unknown): string[] {
	if (!Array.isArray(value)) return [];
	const normalized = value
		.map((item) => (typeof item === 'string' ? item.trim() : ''))
		.filter(Boolean);
	return Array.from(new Set(normalized));
}

export function getMappingDependencyIds(linkage: FormActionLinkage): string[] {
	return normalizeDependencyIds(linkage.settings?.dependency_ids);
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

export function validateMappingDependencies(items: FormActionLinkage[]): DependencyValidationIssue[] {
	const issues: DependencyValidationIssue[] = [];
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));

	for (const linkage of items) {
		const mappingId = linkage.local_mapping_id;
		const dependencyIds = getMappingDependencyIds(linkage);
		const triggerHooks = normalizeHooks(linkage.trigger_hooks);

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

			const dependencyHooks = normalizeHooks(dependency.trigger_hooks);
			const missingHooks = triggerHooks.filter((hook) => !dependencyHooks.includes(hook));
			if (missingHooks.length > 0) {
				issues.push({
					code: 'hook_mismatch',
					mappingId,
					dependencyId,
					missingHooks
				});
			}

			const runsAfterSubmission = triggerHooks.includes('gform_after_submission');
			const dependencyRunsAfterSubmission = dependencyHooks.includes('gform_after_submission');
			if (runsAfterSubmission && dependencyRunsAfterSubmission) {
				const mappingIsAsync = isMappingAsync(linkage);
				const dependencyIsAsync = isMappingAsync(dependency);
				if (dependencyIsAsync && !mappingIsAsync) {
					issues.push({ code: 'execution_mode_mismatch', mappingId, dependencyId });
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
				return `${issue.mappingId} depends on async mapping ${issue.dependencyId} during after-submission, so ${issue.mappingId} must also run async.`;
			case 'cycle':
				return `Dependency cycle includes ${issue.mappingId}.`;
		}
	});
}

export function buildDependencyGraph(items: FormActionLinkage[]): DependencyGraphData {
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));
	const cycleIds = detectCycleIds(items);
	const order = topologicalOrder(items);
	const depths = new Map<string, number>();

	for (const mappingId of order) {
		const linkage = byId.get(mappingId);
		if (!linkage) continue;

		const deps = getMappingDependencyIds(linkage).filter((dep) => byId.has(dep));
		if (deps.length === 0) {
			depths.set(mappingId, 0);
			continue;
		}

		const maxDepth = deps.reduce((acc, depId) => {
			const depDepth = depths.get(depId) ?? 0;
			return Math.max(acc, depDepth);
		}, 0);
		depths.set(mappingId, maxDepth + 1);
	}

	const rowsByDepth = new Map<number, number>();
	const nodes: DependencyGraphNode[] = [];
	for (const linkage of items) {
		const id = linkage.local_mapping_id;
		const depth = depths.get(id) ?? 0;
		const row = rowsByDepth.get(depth) ?? 0;
		rowsByDepth.set(depth, row + 1);

		nodes.push({
			id,
			label: linkage.action_name_label || linkage.central_action_id,
			depth,
			row,
			x: depth * COLUMN_GAP,
			y: row * ROW_GAP,
			linkage
		});
	}

	const edges: DependencyGraphEdge[] = [];
	for (const linkage of items) {
		for (const dependencyId of getMappingDependencyIds(linkage)) {
			edges.push({
				from: dependencyId,
				to: linkage.local_mapping_id,
				missing: !byId.has(dependencyId)
			});
		}
	}

	return { nodes, edges, cycleIds };
}

function normalizeHooks(hooks: string[] | undefined): string[] {
	if (!Array.isArray(hooks)) return [];
	const normalized = hooks
		.map((hook) => hook?.toString().trim())
		.filter((hook): hook is string => Boolean(hook));
	return Array.from(new Set(normalized));
}

function isMappingAsync(linkage: FormActionLinkage): boolean {
	if (linkage.action_type_indicator === 'master') {
		return true;
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

	return false;
}

function normalizeBoolean(value: unknown): boolean {
	if (typeof value === 'boolean') return value;
	if (typeof value === 'number') return value !== 0;
	if (typeof value !== 'string') return Boolean(value);

	const normalized = value.trim().toLowerCase();
	if (normalized === '' || normalized === '0' || normalized === 'false' || normalized === 'off' || normalized === 'no') {
		return false;
	}

	return true;
}

function detectCycleIds(items: FormActionLinkage[]): string[] {
	const inDegree = new Map<string, number>();
	const outgoing = new Map<string, string[]>();
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));

	for (const item of items) {
		inDegree.set(item.local_mapping_id, 0);
		outgoing.set(item.local_mapping_id, []);
	}

	for (const item of items) {
		for (const dependencyId of getMappingDependencyIds(item)) {
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
	const inDegree = new Map<string, number>();
	const outgoing = new Map<string, string[]>();
	const byId = new Map(items.map((item) => [item.local_mapping_id, item]));
	const originalOrder = new Map(items.map((item, index) => [item.local_mapping_id, index]));

	for (const item of items) {
		inDegree.set(item.local_mapping_id, 0);
		outgoing.set(item.local_mapping_id, []);
	}

	for (const item of items) {
		for (const dependencyId of getMappingDependencyIds(item)) {
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
