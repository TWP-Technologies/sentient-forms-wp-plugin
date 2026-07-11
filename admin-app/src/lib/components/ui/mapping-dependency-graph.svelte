<script lang="ts">
	import {
		Background,
		ConnectionLineType,
		ConnectionMode,
		Controls,
		MiniMap,
		SvelteFlow,
		type Connection,
		type Edge,
		type Node,
		type OnConnectStartParams,
		type Viewport
	} from '@xyflow/svelte';
	import '@xyflow/svelte/dist/style.css';
	import Badge from './badge.svelte';
	import Button from './button.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { parseRegisteredEndpointRequest } from '$lib/api/endpoint-schemas';
	import MappingDependencyGraphNode from './mapping-dependency-graph-node.svelte';
	import MappingDependencyHookRootNode from './mapping-dependency-hook-root-node.svelte';
	import MappingDependencyHookRootEdge from './mapping-dependency-hook-root-edge.svelte';
	import type {
		DuplicatePopoverAnchorRect,
		DuplicatePopoverOpenChange,
		MappingDependencyGraphActionNodeData,
		MappingDependencyDuplicateParentOption,
		MappingDependencyGraphNodeData,
		MappingDependencyGraphProps
	} from './mapping-dependency-graph.types';
	import type {
		ConditionTraceNode,
		FormActionLinkage,
		FormFieldInfo,
		RequestTraceResponse,
		RequestTraceStep,
		TraceBlockReason,
		WorkflowPlanHook
	} from '$lib/api/types';
	import {
		buildXyflowDependencyGraph,
		extractHookFromRootId,
		HOOK_ROOT_TARGET_HANDLE_PREFIX,
		type XyflowDependencyEdgeData,
		type XyflowDependencyNodeData,
		type XyflowHookRootNodeData
	} from '$lib/utils/mapping-dependency-xyflow';
	import {
		buildExecutionPreview,
		canDependencySatisfyHook,
		findIntroducedDependencyIssues,
		getMappingDependencyIds,
		getMappingDependencyIdsForHook,
		getMappingTriggerHooks,
		getMappingTriggerSources,
		normalizeDependencyIds,
		validateMappingDependencies,
		withHookTriggerSource,
		type DependencyValidationIssue,
		type HookExecutionPreview
	} from '$lib/utils/mapping-dependencies';

	let {
		linkages = [],
		editingMappingId = null,
		draftDependencyIds = [],
		hookLabels = {},
		hasUnsavedChanges = false,
		workflowPlan = null,
		workflowPlanLoading = false,
		workflowPlanError = null,
		formSourceSlug = '',
		formId = 0,
		formFields = [],
		onSetEditingMapping = () => {},
		onToggleDependency = () => {},
		onConnectDependency = () => {},
		onDisconnectDependency = () => {},
		onAttachRootDependency = () => {},
		onUndoLastRootAttach = () => {},
		canUndoRootAttach = false,
		onClearDependencies = () => {},
		pendingRemovalId = null,
		onConfigureMapping = () => {},
		onToggleMappingEnabled = () => {},
		onRequestRemoveMapping = () => {},
		onConfirmRemoveMapping = () => {},
		onCancelRemoveMapping = () => {},
		onDuplicateMapping = () => {},
		duplicatingMappingId = null,
		onSaveDependencies = () => {},
		onOpenAddAction = () => {},
		onCancelDependencyEdit = () => {},
		savingDependencies = false
	}: MappingDependencyGraphProps = $props();

	const nodeTypes = {
		mappingAction: MappingDependencyGraphNode,
		hookRoot: MappingDependencyHookRootNode
	};
	const edgeTypes = {
		hookRoot: MappingDependencyHookRootEdge
	};

	const FALLBACK_HOOK_LABELS: Record<string, string> = {
		gform_validation: 'During Validation',
		gform_after_submission: 'After Submission'
	};
	const HOOK_SCOPE_ORDER: Record<string, number> = {
		gform_validation: 10,
		gform_after_submission: 20
	};
	const DUPLICATE_POPOVER_WIDTH = 280;
	const DUPLICATE_POPOVER_ESTIMATED_HEIGHT = 182;
	const DUPLICATE_POPOVER_GAP = 6;
	const DUPLICATE_POPOVER_PADDING = 8;

	function compareHookScopes(left: string, right: string): number {
		const leftRank = HOOK_SCOPE_ORDER[left] ?? 1000;
		const rightRank = HOOK_SCOPE_ORDER[right] ?? 1000;
		if (leftRank !== rightRank) return leftRank - rightRank;
		return left.localeCompare(right);
	}

	type DisplayBlocked = {
		mappingId: string;
		reason: string;
		details?: string;
	};

	type DisplayPolicyViolation = {
		mapping_id: string;
		dependency_id: string;
		code: string;
		message: string;
	};

	type DisplayWave = {
		level: number;
		mappingIds: string[];
	};

	type DisplayHookPreview = {
		hook: string;
		order: string[];
		waves: DisplayWave[];
		runnable: string[];
		blocked: DisplayBlocked[];
		cycleIds: string[];
	};

	type TraceFieldDisplay = {
		primaryName: string;
		helperText: string;
		optionLabel: string;
	};

	type ConnectionDecisionCode =
		| 'missing_target'
		| 'self_dependency'
		| 'target_out_of_scope'
		| 'source_out_of_scope'
		| 'duplicate_dependency'
		| 'hook_selection_required'
		| 'hook_mismatch'
		| 'execution_mode_mismatch'
		| 'cycle'
		| 'policy_violation'
		| 'invalid_root_attach'
		| 'unknown';

	type ConnectionDecision = {
		valid: boolean;
		code?: ConnectionDecisionCode;
		message?: string;
	};

	type ConnectionFeedback = {
		code: ConnectionDecisionCode;
		message: string;
	};

	type ConnectionEndState = {
		isValid: boolean | null;
		fromHandle?: {
			nodeId: string;
			id?: string | null;
		} | null;
		toHandle?: {
			nodeId: string;
			id?: string | null;
		} | null;
	};

	let flowNodes = $state<Node<MappingDependencyGraphNodeData>[]>([]);
	let flowEdges = $state<Edge<XyflowDependencyEdgeData>[]>([]);
	let selectedHookScope = $state<'all' | string>('all');
	let pendingConnectionSource = $state<OnConnectStartParams | null>(null);
	let connectionFeedback = $state<ConnectionFeedback | null>(null);
	let connectionFeedbackTimeout = $state<number | null>(null);
	let activeDuplicatePopoverNodeId = $state<string | null>(null);
	let activeDuplicatePopoverPosition = $state<{ left: number; top: number } | null>(null);
	let activeDuplicateParentId = $state<string>('');
	let hoveredRemovableEdgeId = $state<string | null>(null);
	let shouldAutoFitView = $state(false);
	let flowViewport = $state<Viewport>({ x: 0, y: 0, zoom: 1 });
	let graphCanvasElement = $state<HTMLDivElement | null>(null);
	let duplicatePopoverElement = $state<HTMLDivElement | null>(null);
	let traceLoading = $state(false);
	let traceError = $state<string | null>(null);
	let traceResult = $state<RequestTraceResponse | null>(null);
	let traceEntryId = $state('');
	let traceManualValues = $state<Record<string, string>>({});
	let traceIncludeDrafts = $state(true);
	let traceCustomFieldId = $state('');
	let traceCustomFieldValue = $state('');

	const resolvedHookLabels = $derived({
		...FALLBACK_HOOK_LABELS,
		...hookLabels
	});

	const availableHookScopes = $derived.by(() => {
		const seen = new Set<string>();
		for (const linkage of linkages) {
			for (const hook of getMappingTriggerHooks(linkage)) {
				if (!hook || seen.has(hook)) continue;
				seen.add(hook);
			}
		}
		return Array.from(seen).sort(compareHookScopes);
	});

	const rootHooksForNodes = $derived.by(() => {
		if (availableHookScopes.length > 0) return availableHookScopes;
		return Object.keys(FALLBACK_HOOK_LABELS);
	});
	const traceFieldOptions = $derived.by<FormFieldInfo[]>(() => {
		if (formFields.length > 0) return formFields;
		if (!traceResult?.input?.values) return [];
		return Object.keys(traceResult.input.values).map((fieldId) => ({
			id: fieldId,
			label: `Field ${fieldId}`,
			type: 'text'
		}));
	});
	const traceManualEntries = $derived.by(() =>
		Object.entries(traceManualValues).sort(([left], [right]) => left.localeCompare(right))
	);

	const scopedLinkages = $derived.by(() => {
		if (selectedHookScope === 'all') return linkages;
		return linkages.filter((linkage) =>
			getMappingTriggerHooks(linkage).includes(selectedHookScope)
		);
	});

	$effect(() => {
		selectedHookScope;
		availableHookScopes;
		if (selectedHookScope === 'all') return;
		if (!availableHookScopes.includes(selectedHookScope)) {
			selectedHookScope = 'all';
		}
	});

	$effect(() => {
		traceFieldOptions;
		if (traceCustomFieldId.trim().length > 0) return;
		const firstFieldId = traceFieldOptions[0]?.id;
		if (firstFieldId) {
			traceCustomFieldId = String(firstFieldId);
		}
	});

	const graph = $derived(buildXyflowDependencyGraph(scopedLinkages));
	const localExecutionPreview = $derived(buildExecutionPreview(scopedLinkages, selectedHookScope));
	const baselineDependencyIssues = $derived(validateMappingDependencies(linkages));
	const dependencySet = $derived(new Set(normalizeDependencyIds(draftDependencyIds)));
	const explicitEdgeCount = $derived(graph.explicitDependencyEdgeCount);
	const rootEdgeCount = $derived.by(() => {
		return graph.edges.reduce(
			(count, edge) => count + (edge.data?.kind === 'hook_root' ? 1 : 0),
			0
		);
	});

	const linkageById = $derived.by(() => {
		return new Map(scopedLinkages.map((linkage) => [linkage.local_mapping_id, linkage]));
	});
	const linkageByIdAll = $derived.by(() => {
		return new Map(linkages.map((linkage) => [linkage.local_mapping_id, linkage]));
	});
	const mappingIdsInScope = $derived.by(() => {
		return new Set(scopedLinkages.map((linkage) => linkage.local_mapping_id));
	});
	const activeDuplicatePopoverLinkage = $derived.by(() => {
		if (!activeDuplicatePopoverNodeId) return null;
		return linkageById.get(activeDuplicatePopoverNodeId) ?? null;
	});
	const activeDuplicateParentOptions = $derived.by<MappingDependencyDuplicateParentOption[]>(() => {
		if (!activeDuplicatePopoverLinkage) return [];
		return buildDuplicateParentOptions(activeDuplicatePopoverLinkage);
	});
	const activeDuplicateParentOption = $derived.by(() => {
		if (activeDuplicateParentOptions.length === 0) return null;
		const selected = activeDuplicateParentOptions.find(
			(option) => option.id === activeDuplicateParentId
		);
		return selected ?? activeDuplicateParentOptions[0] ?? null;
	});
	const activeDuplicateIsLoading = $derived.by(() => {
		if (!activeDuplicatePopoverNodeId) return false;
		return duplicatingMappingId === activeDuplicatePopoverNodeId;
	});
	const invalidHooksByMappingId = $derived.by(() => {
		const invalidById = new Map<string, string[]>();
		for (const linkage of linkages) {
			const triggerHooks = getMappingTriggerHooks(linkage);
			const triggerSources = getMappingTriggerSources(linkage);
			const invalidHooks = triggerHooks.filter((hook) => triggerSources[hook]?.type === 'unbound');
			if (invalidHooks.length > 0) {
				invalidById.set(linkage.local_mapping_id, invalidHooks);
			}
		}
		return invalidById;
	});
	const invalidMappingsAll = $derived.by(() => {
		return linkages
			.filter((linkage) => (invalidHooksByMappingId.get(linkage.local_mapping_id)?.length ?? 0) > 0)
			.map((linkage) => linkage.local_mapping_id);
	});
	const invalidMappingsInScope = $derived.by(() => {
		if (selectedHookScope === 'all') {
			return scopedLinkages
				.filter(
					(linkage) => (invalidHooksByMappingId.get(linkage.local_mapping_id)?.length ?? 0) > 0
				)
				.map((linkage) => linkage.local_mapping_id);
		}
		return scopedLinkages
			.filter((linkage) =>
				(invalidHooksByMappingId.get(linkage.local_mapping_id) ?? []).includes(selectedHookScope)
			)
			.map((linkage) => linkage.local_mapping_id);
	});
	const hasInvalidMappings = $derived.by(() => invalidMappingsAll.length > 0);
	const saveDisabledReason = $derived.by(() => {
		if (!hasInvalidMappings) return null;
		if (invalidMappingsInScope.length === 1) {
			return `${displayMappingLabel(invalidMappingsInScope[0] ?? '')} is missing an upstream trigger source.`;
		}
		if (invalidMappingsInScope.length > 1) {
			return `${invalidMappingsInScope.length} mappings are missing upstream trigger sources in this graph scope.`;
		}
		return `${invalidMappingsAll.length} mappings are invalid in other hook scopes. Switch tabs and reconnect upstream sources before saving.`;
	});
	const rootHookByNodeId = $derived.by(() => {
		const entries = graph.nodes
			.filter(isHookRootNode)
			.map((node) => [node.id, node.data.hook] as const);
		return new Map(entries);
	});
	const disabledGraphState = $derived.by(() => {
		const disabledIds = new Set<string>();
		for (const linkage of scopedLinkages) {
			if (linkage.is_action_enabled_for_form === false) {
				disabledIds.add(linkage.local_mapping_id);
			}
		}

		const adjacency = new Map<string, string[]>();
		for (const edge of graph.edges) {
			if (edge.data?.kind !== 'dependency') continue;
			if (!mappingIdsInScope.has(edge.source) || !mappingIdsInScope.has(edge.target)) continue;
			const neighbors = adjacency.get(edge.source) ?? [];
			neighbors.push(edge.target);
			adjacency.set(edge.source, neighbors);
		}

		const downstreamIds = new Set<string>();
		const disabledUpstreamByNode = new Map<string, Set<string>>();
		for (const disabledId of disabledIds) {
			const stack = [...(adjacency.get(disabledId) ?? [])];
			const visited = new Set<string>();
			while (stack.length > 0) {
				const current = stack.pop();
				if (!current || visited.has(current)) continue;
				visited.add(current);
				downstreamIds.add(current);

				const currentUpstreams = disabledUpstreamByNode.get(current) ?? new Set<string>();
				currentUpstreams.add(disabledId);
				disabledUpstreamByNode.set(current, currentUpstreams);

				for (const next of adjacency.get(current) ?? []) {
					if (!visited.has(next)) stack.push(next);
				}
			}
		}

		return {
			disabledIds,
			downstreamIds,
			disabledUpstreamByNode
		};
	});

	const editingLabel = $derived.by(() => {
		if (!editingMappingId) return null;
		const target = linkages.find((item) => item.local_mapping_id === editingMappingId);
		if (!target) return editingMappingId;
		return target.action_name_label || target.central_action_id;
	});

	const editingCurrentDeps = $derived.by(() => {
		if (!editingMappingId) return [];
		const target = linkages.find((item) => item.local_mapping_id === editingMappingId);
		if (!target) return [];
		return getMappingDependencyIds(target);
	});

	const plannerAuthority = $derived.by(() => workflowPlan?.authority ?? 'local');
	const plannerAuthorityReason = $derived.by(() => workflowPlan?.authority_reason ?? null);
	const policyVersion = $derived.by(
		() => workflowPlan?.policy_version ?? '2026-02-mixed-sync-async-v1'
	);
	const useRemotePreview = $derived.by(() => {
		if (!workflowPlan || workflowPlan.authority !== 'cps') return false;
		if (hasUnsavedChanges) return false;
		return Array.isArray(workflowPlan.hooks) && workflowPlan.hooks.length > 0;
	});

	function toDisplayPolicyViolation(issue: DependencyValidationIssue): DisplayPolicyViolation {
		switch (issue.code) {
			case 'self':
				return {
					mapping_id: issue.mappingId,
					dependency_id: issue.dependencyId,
					code: issue.code,
					message: `${issue.mappingId} cannot depend on itself.`
				};
			case 'missing':
				return {
					mapping_id: issue.mappingId,
					dependency_id: issue.dependencyId,
					code: issue.code,
					message: `${issue.mappingId} depends on unknown mapping ${issue.dependencyId}.`
				};
			case 'hook_mismatch':
				return {
					mapping_id: issue.mappingId,
					dependency_id: issue.dependencyId,
					code: issue.code,
					message: `${issue.mappingId} depends on ${issue.dependencyId}, but ${issue.dependencyId} is missing hooks: ${issue.missingHooks.join(', ')}.`
				};
			case 'execution_mode_mismatch':
				return {
					mapping_id: issue.mappingId,
					dependency_id: issue.dependencyId,
					code: issue.code,
					message: `${issue.mappingId} depends on Background mapping ${issue.dependencyId} during after-submission, so ${issue.mappingId} must also run in Background.`
				};
			case 'unbound_trigger':
				return {
					mapping_id: issue.mappingId,
					dependency_id: `__hook_root__:${issue.hook}`,
					code: issue.code,
					message: `${displayMappingLabel(issue.mappingId)} has no trigger source bound for ${displayHookLabel(issue.hook)}.`
				};
			case 'cycle':
				return {
					mapping_id: issue.mappingId,
					dependency_id: issue.mappingId,
					code: issue.code,
					message: `Dependency cycle includes ${issue.mappingId}.`
				};
		}
	}

	const localPolicyViolations = $derived.by<DisplayPolicyViolation[]>(() => {
		return baselineDependencyIssues.map(toDisplayPolicyViolation);
	});

	const policyViolations = $derived.by(() => {
		if (useRemotePreview) {
			return workflowPlan?.policy_violations ?? [];
		}

		return localPolicyViolations;
	});

	const previewSourceLabel = $derived.by(() => {
		if (useRemotePreview) return 'Preview source: managed planner';
		if (hasUnsavedChanges) return 'Preview source: local draft (unsaved edits)';
		if (plannerAuthority === 'cps')
			return 'Preview source: local planner (managed planner mismatch)';
		return 'Preview source: local planner';
	});

	const normalizedHookPreviews = $derived.by(() => {
		let hooks: DisplayHookPreview[] = [];
		if (useRemotePreview && workflowPlan) {
			hooks = workflowPlan.hooks.map(normalizeRemoteHookPreview);
		} else {
			hooks = localExecutionPreview.hooks.map(normalizeLocalHookPreview);
		}

		if (selectedHookScope === 'all') return hooks;
		const match = hooks.find((item) => item.hook === selectedHookScope);
		if (match) return [match];
		return [];
	});

	const aggregatePreview = $derived.by(() => {
		const runnable = new Set<string>();
		const blocked = new Set<string>();
		const cycles = new Set<string>();
		for (const hook of normalizedHookPreviews) {
			for (const mappingId of hook.runnable) runnable.add(mappingId);
			for (const mapping of hook.blocked) blocked.add(mapping.mappingId);
			for (const mappingId of hook.cycleIds) cycles.add(mappingId);
		}
		return {
			runnableCount: runnable.size,
			blockedCount: blocked.size,
			cycleCount: cycles.size
		};
	});

	const activePreview = $derived.by(() => normalizedHookPreviews[0] ?? null);
	const activeRunnableCount = $derived.by(() => {
		if (selectedHookScope === 'all') return aggregatePreview.runnableCount;
		return activePreview?.runnable.length ?? 0;
	});
	const activeBlockedCount = $derived.by(() => {
		if (selectedHookScope === 'all') return aggregatePreview.blockedCount;
		return activePreview?.blocked.length ?? 0;
	});
	const cycleNodeCount = $derived.by(() => {
		if (selectedHookScope === 'all') return aggregatePreview.cycleCount;
		return activePreview?.cycleIds.length ?? graph.cycleIds.length;
	});

	const previewNotice = $derived.by(() => {
		if (useRemotePreview) return null;
		if (hasUnsavedChanges) {
			return 'Unsaved edits detected. Execution preview is rendered from local draft state.';
		}
		if (plannerAuthorityReason === 'cps_mismatch') {
			return 'Managed planner nodes do not match the current graph view. Showing local fallback preview.';
		}
		return null;
	});

	const viewOnlyPositions = new Map<string, { x: number; y: number }>();
	let cachedLayoutSignature = '';

	function clamp(value: number, min: number, max: number): number {
		return Math.min(Math.max(value, min), max);
	}

	function computeDuplicatePopoverPosition(anchor: DuplicatePopoverAnchorRect): {
		left: number;
		top: number;
	} {
		if (typeof window === 'undefined') {
			return {
				left: anchor.left,
				top: anchor.bottom + DUPLICATE_POPOVER_GAP
			};
		}

		const viewportWidth = window.innerWidth;
		const viewportHeight = window.innerHeight;
		const maxLeft = Math.max(
			DUPLICATE_POPOVER_PADDING,
			viewportWidth - DUPLICATE_POPOVER_WIDTH - DUPLICATE_POPOVER_PADDING
		);
		const left = clamp(anchor.right - DUPLICATE_POPOVER_WIDTH, DUPLICATE_POPOVER_PADDING, maxLeft);
		const spaceBelow = viewportHeight - anchor.bottom - DUPLICATE_POPOVER_PADDING;
		const spaceAbove = anchor.top - DUPLICATE_POPOVER_PADDING;
		const renderAbove =
			spaceBelow < DUPLICATE_POPOVER_ESTIMATED_HEIGHT + DUPLICATE_POPOVER_GAP &&
			spaceAbove > spaceBelow;
		const naturalTop = renderAbove
			? anchor.top - DUPLICATE_POPOVER_ESTIMATED_HEIGHT - DUPLICATE_POPOVER_GAP
			: anchor.bottom + DUPLICATE_POPOVER_GAP;
		const maxTop = Math.max(
			DUPLICATE_POPOVER_PADDING,
			viewportHeight - DUPLICATE_POPOVER_ESTIMATED_HEIGHT - DUPLICATE_POPOVER_PADDING
		);
		const top = clamp(naturalTop, DUPLICATE_POPOVER_PADDING, maxTop);

		return { left, top };
	}

	function setActiveDuplicatePopoverAnchor(anchor: DuplicatePopoverAnchorRect | null): void {
		activeDuplicatePopoverPosition = anchor ? computeDuplicatePopoverPosition(anchor) : null;
	}

	function closeActiveDuplicatePopover(): void {
		activeDuplicatePopoverNodeId = null;
		activeDuplicateParentId = '';
		setActiveDuplicatePopoverAnchor(null);
	}

	function duplicateTriggerSelector(mappingId: string): string {
		if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
			return `[data-testid="dependency-node-duplicate-open-${CSS.escape(mappingId)}"]`;
		}
		return `[data-testid="dependency-node-duplicate-open-${mappingId}"]`;
	}

	function measureDuplicateTriggerAnchor(mappingId: string): DuplicatePopoverAnchorRect | null {
		if (typeof document === 'undefined') return null;
		const trigger = document.querySelector(duplicateTriggerSelector(mappingId));
		if (!(trigger instanceof HTMLElement)) return null;
		const rect = trigger.getBoundingClientRect();
		return {
			left: rect.left,
			top: rect.top,
			right: rect.right,
			bottom: rect.bottom,
			width: rect.width,
			height: rect.height
		};
	}

	function refreshActiveDuplicatePopoverAnchor(): void {
		if (!activeDuplicatePopoverNodeId) return;
		const measured = measureDuplicateTriggerAnchor(activeDuplicatePopoverNodeId);
		if (!measured) {
			closeActiveDuplicatePopover();
			return;
		}
		setActiveDuplicatePopoverAnchor(measured);
	}

	function hookRootNodeId(hook: string): string {
		return `__hook_root__:${hook}`;
	}

	function pruneStaleCachedPositions(): void {
		const validMappingIds = new Set(linkages.map((linkage) => linkage.local_mapping_id));
		const validRootIds = new Set(rootHooksForNodes.map((hook) => hookRootNodeId(hook)));

		for (const nodeId of Array.from(viewOnlyPositions.keys())) {
			const rootHook = extractHookFromRootId(nodeId);
			if (rootHook) {
				if (!validRootIds.has(nodeId)) {
					viewOnlyPositions.delete(nodeId);
				}
				continue;
			}
			if (!validMappingIds.has(nodeId)) {
				viewOnlyPositions.delete(nodeId);
			}
		}
	}

	function ensureCachedPositionsForGraphNodes(): void {
		if (cachedLayoutSignature !== graph.layoutSignature) {
			viewOnlyPositions.clear();
			cachedLayoutSignature = graph.layoutSignature;
		}
		pruneStaleCachedPositions();
		for (const node of graph.nodes) {
			if (!viewOnlyPositions.has(node.id)) {
				viewOnlyPositions.set(node.id, { ...node.position });
			}
		}
	}

	$effect(() => {
		graph.layoutSignature;
		ensureCachedPositionsForGraphNodes();
		rebuildFlowNodes();
		rebuildFlowEdges();
	});

	$effect(() => {
		editingMappingId;
		pendingRemovalId;
		draftDependencyIds;
		linkages;
		disabledGraphState;
		activeDuplicatePopoverNodeId;
		ensureCachedPositionsForGraphNodes();
		rebuildFlowNodes();
		rebuildFlowEdges();
	});

	$effect(() => {
		hoveredRemovableEdgeId;
		graph.edges;
		disabledGraphState;
		rebuildFlowEdges();
	});

	$effect(() => {
		flowNodes;
		for (const node of flowNodes) {
			viewOnlyPositions.set(node.id, { ...node.position });
		}
	});

	$effect(() => {
		mappingIdsInScope;
		activeDuplicatePopoverNodeId;
		if (!activeDuplicatePopoverNodeId) return;
		if (!mappingIdsInScope.has(activeDuplicatePopoverNodeId)) {
			closeActiveDuplicatePopover();
		}
	});

	$effect(() => {
		activeDuplicatePopoverNodeId;
		activeDuplicateParentOptions;
		if (!activeDuplicatePopoverNodeId) return;
		if (activeDuplicateParentOptions.length === 0) {
			closeActiveDuplicatePopover();
			return;
		}
		if (!activeDuplicateParentOptions.some((option) => option.id === activeDuplicateParentId)) {
			activeDuplicateParentId = activeDuplicateParentOptions[0]!.id;
		}
	});

	$effect(() => {
		pendingRemovalId;
		duplicatingMappingId;
		activeDuplicatePopoverNodeId;
		if (!activeDuplicatePopoverNodeId) return;
		if (
			pendingRemovalId === activeDuplicatePopoverNodeId ||
			duplicatingMappingId === activeDuplicatePopoverNodeId
		) {
			closeActiveDuplicatePopover();
		}
	});

	$effect(() => {
		activeDuplicatePopoverNodeId;
		flowViewport.x;
		flowViewport.y;
		flowViewport.zoom;
		if (!activeDuplicatePopoverNodeId) return;
		const frame = requestAnimationFrame(() => {
			refreshActiveDuplicatePopoverAnchor();
		});
		return () => {
			cancelAnimationFrame(frame);
		};
	});

	$effect(() => {
		graph.layoutSignature;
		activeDuplicatePopoverNodeId;
		if (!activeDuplicatePopoverNodeId) return;
		const frame = requestAnimationFrame(() => {
			refreshActiveDuplicatePopoverAnchor();
		});
		return () => {
			cancelAnimationFrame(frame);
		};
	});

	$effect(() => {
		flowEdges;
		hoveredRemovableEdgeId;
		if (!hoveredRemovableEdgeId) return;
		if (!flowEdges.some((edge) => edge.id === hoveredRemovableEdgeId)) {
			hoveredRemovableEdgeId = null;
		}
	});

	$effect(() => {
		return () => {
			if (connectionFeedbackTimeout !== null) {
				window.clearTimeout(connectionFeedbackTimeout);
			}
		};
	});

	function rebuildFlowEdges(): void {
		flowEdges = graph.edges.map((edge) => {
			const isMuted = shouldMuteEdge(edge);
			const isHovered = hoveredRemovableEdgeId === edge.id;
			const baseStrokeColor = edge.data?.missing
				? '#dc2626'
				: edge.data?.kind === 'dependency'
					? '#94a3b8'
					: '#3b82f6';
			const strokeColor = isHovered ? '#dc2626' : baseStrokeColor;
			const strokeWidth = isHovered ? 2.6 : edge.data?.kind === 'dependency' ? 2 : 1.8;
			const baseStyle = typeof edge.style === 'string' ? edge.style : '';
			const styleParts = [baseStyle, 'cursor:pointer'];
			styleParts.push(`--xy-edge-stroke:${strokeColor}`, `--xy-edge-stroke-width:${strokeWidth}px`);
			if (isMuted) {
				styleParts.push('opacity:0.45', 'stroke-dasharray:4 3');
			}
			const markerEnd =
				edge.markerEnd && typeof edge.markerEnd === 'object'
					? { ...edge.markerEnd, color: strokeColor }
					: edge.markerEnd;
			if (styleParts.length === 0) return edge;
			return {
				...edge,
				style: styleParts.filter(Boolean).join(';'),
				markerEnd
			};
		});
	}

	function rebuildFlowNodes(): void {
		flowNodes = graph.nodes.map((node) => {
			const cachedPosition = viewOnlyPositions.get(node.id);
			const nextPosition = cachedPosition ? { ...cachedPosition } : { ...node.position };
			viewOnlyPositions.set(node.id, { ...nextPosition });
			if (node.data.kind === 'hook_root') {
				return {
					...node,
					position: { ...nextPosition },
					zIndex: 5,
					data: buildHookRootNodeData(
						node.id,
						node.data.hook,
						node.data.label,
						node.data.sourceHandleIds ?? []
					)
				};
			}

			const linkage = linkageById.get(node.id) ?? node.data.linkage;
			const isEditingTarget = editingMappingId === node.id;
			const isDuplicatePopoverOpen = activeDuplicatePopoverNodeId === node.id;
			return {
				...node,
				position: { ...nextPosition },
				zIndex: isDuplicatePopoverOpen ? 120 : isEditingTarget ? 80 : 10,
				data: buildMappingNodeData(node.id, linkage, node.data.label)
			};
		});
	}

	function buildMappingNodeData(
		nodeId: string,
		linkage: FormActionLinkage,
		label: string
	): MappingDependencyGraphActionNodeData {
		const isEditingTarget = editingMappingId === nodeId;
		const isDisabled = disabledGraphState.disabledIds.has(nodeId);
		const isBlockedByDisabledUpstream = !isDisabled && disabledGraphState.downstreamIds.has(nodeId);
		const disabledUpstreamIds = Array.from(
			disabledGraphState.disabledUpstreamByNode.get(nodeId) ?? []
		);
		const invalidHooks = invalidHooksByMappingId.get(nodeId) ?? [];
		const isInvalid = invalidHooks.length > 0;
		const triggerHooks = getMappingTriggerHooks(linkage);
		const triggerSources = getMappingTriggerSources(linkage);
		const autonomousHooks = triggerHooks.filter((hook) => {
			const source = triggerSources[hook];
			return !source || source.type === 'hook_root' || !source.mappingId;
		});
		const hasConditionalRun = isConditionalRunEnabled(linkage);

		return {
			kind: 'mapping',
			nodeId,
			mappingId: nodeId,
			label,
			linkage,
			editingMappingId,
			isEditingTarget,
			isSelectedDependency: dependencySet.has(nodeId),
			isDisabled,
			isInvalid,
			invalidHooks,
			isBlockedByDisabledUpstream,
			disabledUpstreamIds,
			pendingRemovalId,
			canStartConnection: true,
			canAcceptConnection: true,
			availableRootHooks: rootHooksForNodes,
			triggerHooks,
			triggerSources,
			autonomousHooks,
			hasConditionalRun,
			hookLabels: resolvedHookLabels,
			onToggleDependency,
			onSetEditingMapping: (mappingId) => onSetEditingMapping?.(mappingId),
			onConfigureMapping,
			onToggleMappingEnabled,
			onRequestRemoveMapping,
			onConfirmRemoveMapping,
			onCancelRemoveMapping,
			onDuplicateMapping,
			onDuplicatePopoverOpenChange,
			duplicateParentOptions: buildDuplicateParentOptions(linkage),
			isDuplicating: duplicatingMappingId === nodeId,
			isDuplicatePopoverOpen: activeDuplicatePopoverNodeId === nodeId
		};
	}

	function onDuplicatePopoverOpenChange(request: DuplicatePopoverOpenChange): void {
		if (!request.open) {
			if (activeDuplicatePopoverNodeId === request.mappingId) {
				closeActiveDuplicatePopover();
			}
			return;
		}

		const anchor = request.anchorRect ?? measureDuplicateTriggerAnchor(request.mappingId);
		if (!anchor) {
			closeActiveDuplicatePopover();
			return;
		}
		activeDuplicatePopoverNodeId = request.mappingId;
		setActiveDuplicatePopoverAnchor(anchor);
	}

	function closeDuplicatePopoverFromUI(event?: MouseEvent | PointerEvent): void {
		event?.stopPropagation();
		closeActiveDuplicatePopover();
	}

	async function confirmDuplicateFromUI(event: MouseEvent): Promise<void> {
		event.stopPropagation();
		const linkage = activeDuplicatePopoverLinkage;
		const selected = activeDuplicateParentOption;
		if (!linkage || !selected || !onDuplicateMapping || activeDuplicateIsLoading) {
			return;
		}
		await onDuplicateMapping(linkage, selected.parent);
		closeActiveDuplicatePopover();
	}

	function handleDuplicatePopoverEscape(event: KeyboardEvent): void {
		if (event.key !== 'Escape') return;
		if (!activeDuplicatePopoverNodeId) return;
		closeActiveDuplicatePopover();
	}

	function handleDuplicatePopoverPointerDown(event: PointerEvent): void {
		if (!activeDuplicatePopoverNodeId) return;
		const target = event.target;
		if (!(target instanceof Node)) return;
		if (duplicatePopoverElement?.contains(target)) return;

		const trigger = document.querySelector(duplicateTriggerSelector(activeDuplicatePopoverNodeId));
		if (trigger instanceof Node && trigger.contains(target)) return;
		closeActiveDuplicatePopover();
	}

	function handleDuplicatePopoverViewportChange(): void {
		if (!activeDuplicatePopoverNodeId) return;
		refreshActiveDuplicatePopoverAnchor();
	}

	function buildDuplicateParentOptions(
		linkage: FormActionLinkage
	): MappingDependencyDuplicateParentOption[] {
		const options: MappingDependencyDuplicateParentOption[] = [];
		const sourceMappingId = linkage.local_mapping_id;
		const sourceHooks = getMappingTriggerHooks(linkage);

		for (const hook of sourceHooks) {
			const hookLabel = displayHookLabel(hook);
			options.push({
				id: `hook_root:${hook}`,
				label: `Root: ${hookLabel}`,
				description: 'Insert as autonomous action for this trigger hook.',
				parent: {
					type: 'hook_root',
					hook
				}
			});

			for (const candidate of linkages) {
				const candidateId = candidate.local_mapping_id;
				const candidateHooks = getMappingTriggerHooks(candidate);
				if (!canDependencySatisfyHook(candidateHooks, hook)) continue;

				const candidateLabel =
					candidate.action_name_label || candidate.central_action_id || candidateId;
				options.push({
					id: `mapping:${hook}:${candidateId}`,
					label: `After ${candidateLabel}`,
					description:
						candidateId === sourceMappingId
							? `Insert after this action in ${hookLabel}.`
							: `Insert after ${candidateLabel} in ${hookLabel}.`,
					parent: {
						type: 'mapping',
						hook,
						mapping_id: candidateId
					}
				});
			}
		}

		return options;
	}

	function isConditionalRunEnabled(linkage: FormActionLinkage): boolean {
		const settings = linkage.settings as Record<string, unknown> | undefined;
		const conditions = settings?.conditions;
		if (!conditions || typeof conditions !== 'object' || Array.isArray(conditions)) {
			return false;
		}

		return Boolean((conditions as Record<string, unknown>).enabled);
	}

	function buildHookRootNodeData(
		nodeId: string,
		hook: string,
		label: string,
		sourceHandleIds: string[]
	): MappingDependencyGraphNodeData {
		return {
			kind: 'hook_root',
			nodeId,
			hook,
			label,
			hookLabel: resolvedHookLabels[hook] ?? hook,
			canStartConnection: true,
			sourceHandleIds
		};
	}

	function shouldMuteEdge(edge: Edge<XyflowDependencyEdgeData>): boolean {
		if (edge.data?.kind === 'dependency') {
			return (
				disabledGraphState.disabledIds.has(edge.source) ||
				disabledGraphState.disabledIds.has(edge.target) ||
				disabledGraphState.downstreamIds.has(edge.target)
			);
		}
		return (
			disabledGraphState.disabledIds.has(edge.target) ||
			disabledGraphState.downstreamIds.has(edge.target)
		);
	}

	function hookFromHandleId(handleId: string | null | undefined): string | null {
		if (!handleId || typeof handleId !== 'string') return null;
		if (!handleId.startsWith(HOOK_ROOT_TARGET_HANDLE_PREFIX)) return null;
		const hook = handleId.slice(HOOK_ROOT_TARGET_HANDLE_PREFIX.length).trim();
		return hook || null;
	}

	function resolveConnectionHook(
		targetLinkage: FormActionLinkage | undefined,
		targetHandleId: string | null | undefined,
		sourceLinkage?: FormActionLinkage
	): string | null {
		const handleHook = hookFromHandleId(targetHandleId);
		if (handleHook) return handleHook;
		if (selectedHookScope !== 'all') return selectedHookScope;

		const hooks = targetLinkage ? getMappingTriggerHooks(targetLinkage) : [];
		if (hooks.length === 1) return hooks[0] ?? null;

		if (sourceLinkage && hooks.length > 1) {
			const sourceHooks = getMappingTriggerHooks(sourceLinkage);
			const overlappingHooks = hooks.filter((hook) => sourceHooks.includes(hook));
			if (overlappingHooks.length === 1) return overlappingHooks[0] ?? null;
		}
		return null;
	}

	function isValidConnection(connection: Connection): boolean {
		return evaluateConnectionDecision(connection).valid;
	}

	function evaluateConnectionDecision(connection: Connection): ConnectionDecision {
		const source = connection.source?.toString().trim() ?? '';
		const target = connection.target?.toString().trim() ?? '';
		const targetHandle = connection.targetHandle?.toString() ?? null;
		if (!source || !target) {
			return {
				valid: false,
				code: 'missing_target',
				message: 'Drop the line on a destination handle to create a connection.'
			};
		}
		if (source === target) {
			return {
				valid: false,
				code: 'self_dependency',
				message: 'An action cannot depend on itself.'
			};
		}
		if (!mappingIdsInScope.has(target)) {
			return {
				valid: false,
				code: 'target_out_of_scope',
				message: 'Target mapping is outside the current hook scope.'
			};
		}

		if (rootHookByNodeId.has(source)) {
			const hook = rootHookByNodeId.get(source);
			if (!hook) {
				return {
					valid: false,
					code: 'invalid_root_attach',
					message: 'Root connection is invalid for the selected target.'
				};
			}
			return evaluateRootAttachCandidate(target, hook);
		}

		if (!mappingIdsInScope.has(source)) {
			return {
				valid: false,
				code: 'source_out_of_scope',
				message: 'Source mapping is outside the current hook scope.'
			};
		}
		return evaluateDependencyCandidate(source, target, targetHandle);
	}

	function evaluateDependencyCandidate(
		source: string,
		target: string,
		targetHandleId: string | null
	): ConnectionDecision {
		const targetLinkage = linkageById.get(target);
		if (!targetLinkage) {
			return {
				valid: false,
				code: 'target_out_of_scope',
				message: 'Target mapping is outside the current hook scope.'
			};
		}

		const sourceLinkage = linkageById.get(source);
		const hook = resolveConnectionHook(targetLinkage, targetHandleId, sourceLinkage);
		if (!hook) {
			return {
				valid: false,
				code: 'hook_selection_required',
				message:
					'Connection is ambiguous. Select a hook tab or drop on a hook-specific left blue handle.'
			};
		}

		const currentDependenciesForHook = getMappingDependencyIdsForHook(targetLinkage, hook);
		if (currentDependenciesForHook.includes(source)) {
			return {
				valid: false,
				code: 'duplicate_dependency',
				message: `${displayMappingLabel(target)} already depends on ${displayMappingLabel(source)} in ${displayHookLabel(hook)}.`
			};
		}

		const nextTarget = withHookTriggerSource(targetLinkage, hook, {
			type: 'mapping',
			mappingId: source
		});

		const candidates = linkages.map((item) =>
			item.local_mapping_id === target ? nextTarget : item
		);
		const candidateIssues = validateMappingDependencies(candidates);
		const newIssues = findIntroducedDependencyIssues(baselineDependencyIssues, candidateIssues);
		if (newIssues.length === 0) {
			return { valid: true };
		}

		const issue = pickMostRelevantIssue(newIssues, target, source);
		return mapIssueToConnectionDecision(issue);
	}

	function evaluateRootAttachCandidate(target: string, hook: string): ConnectionDecision {
		const targetLinkage = linkageById.get(target);
		if (!targetLinkage) {
			return {
				valid: false,
				code: 'target_out_of_scope',
				message: 'Target mapping is outside the current hook scope.'
			};
		}

		const targetHooks = getMappingTriggerHooks(targetLinkage);
		const hasTargetHook = targetHooks.includes(hook);
		const existingDependencies = getMappingDependencyIdsForHook(targetLinkage, hook);
		const existingSources = getMappingTriggerSources(targetLinkage);
		const existingHookSource = existingSources[hook];
		if (
			hasTargetHook &&
			existingDependencies.length === 0 &&
			(existingHookSource?.type ?? 'hook_root') === 'hook_root'
		) {
			return {
				valid: false,
				code: 'duplicate_dependency',
				message: `${displayMappingLabel(target)} is already autonomous in ${displayHookLabel(hook)}.`
			};
		}

		const nextTarget = withHookTriggerSource(targetLinkage, hook, {
			type: 'hook_root'
		});

		const candidates = linkages.map((item) =>
			item.local_mapping_id === target ? nextTarget : item
		);
		const candidateIssues = validateMappingDependencies(candidates);
		const newIssues = findIntroducedDependencyIssues(baselineDependencyIssues, candidateIssues);
		if (newIssues.length === 0) {
			return { valid: true };
		}

		const issue = pickMostRelevantIssue(newIssues, target);
		return mapIssueToConnectionDecision(issue);
	}

	function pickMostRelevantIssue(
		issues: DependencyValidationIssue[],
		mappingId: string,
		dependencyId?: string
	): DependencyValidationIssue {
		const mappingIssue = issues.find((issue) => issue.mappingId === mappingId);
		if (mappingIssue) return mappingIssue;

		if (dependencyId) {
			const dependencyIssue = issues.find((issue) => {
				if (!('dependencyId' in issue)) return false;
				return issue.dependencyId === dependencyId;
			});
			if (dependencyIssue) return dependencyIssue;
		}

		return issues[0]!;
	}

	function mapIssueToConnectionDecision(issue: DependencyValidationIssue): ConnectionDecision {
		switch (issue.code) {
			case 'self':
				return {
					valid: false,
					code: 'self_dependency',
					message: `${displayMappingLabel(issue.mappingId)} cannot depend on itself.`
				};
			case 'missing':
				return {
					valid: false,
					code: 'policy_violation',
					message: `${displayMappingLabel(issue.mappingId)} depends on missing mapping ${issue.dependencyId}.`
				};
			case 'hook_mismatch':
				return {
					valid: false,
					code: 'hook_mismatch',
					message: `${displayMappingLabel(issue.mappingId)} cannot depend on ${displayMappingLabel(issue.dependencyId)} because required hooks do not overlap (${issue.missingHooks.join(', ')}).`
				};
			case 'execution_mode_mismatch':
				return {
					valid: false,
					code: 'execution_mode_mismatch',
					message: `${displayMappingLabel(issue.mappingId)} cannot depend on Background mapping ${displayMappingLabel(issue.dependencyId)} unless it also runs in Background.`
				};
			case 'cycle':
				return {
					valid: false,
					code: 'cycle',
					message: `This link creates a dependency cycle involving ${displayMappingLabel(issue.mappingId)}.`
				};
			default:
				return {
					valid: false,
					code: 'unknown',
					message: 'Connection rejected by dependency policy.'
				};
		}
	}

	function clearConnectionFeedback(): void {
		connectionFeedback = null;
		if (connectionFeedbackTimeout !== null) {
			window.clearTimeout(connectionFeedbackTimeout);
			connectionFeedbackTimeout = null;
		}
	}

	function setConnectionFeedback(code: ConnectionDecisionCode, message: string): void {
		connectionFeedback = { code, message };
		if (connectionFeedbackTimeout !== null) {
			window.clearTimeout(connectionFeedbackTimeout);
		}
		connectionFeedbackTimeout = window.setTimeout(() => {
			connectionFeedback = null;
			connectionFeedbackTimeout = null;
		}, 5500);
	}

	function handleConnect(connection: Connection): void {
		const decision = evaluateConnectionDecision(connection);
		if (!decision.valid) {
			setConnectionFeedback(decision.code ?? 'unknown', decision.message ?? 'Connection rejected.');
			return;
		}
		const source = connection.source?.toString() ?? '';
		const target = connection.target?.toString() ?? '';
		if (!source || !target) return;
		clearConnectionFeedback();

		if (rootHookByNodeId.has(source)) {
			const hook = rootHookByNodeId.get(source);
			if (hook) {
				onAttachRootDependency?.(target, hook);
				onSetEditingMapping?.(target);
			}
			return;
		}

		const targetLinkage = linkageById.get(target);
		const sourceLinkage = linkageById.get(source);
		const hook = resolveConnectionHook(
			targetLinkage,
			connection.targetHandle?.toString() ?? null,
			sourceLinkage
		);
		onConnectDependency?.(source, target, hook);
		onSetEditingMapping?.(target);
	}

	function handleConnectStart(_event: MouseEvent | TouchEvent, params: OnConnectStartParams): void {
		pendingConnectionSource = params;
		clearConnectionFeedback();
	}

	function handleConnectEnd(
		_event: MouseEvent | TouchEvent,
		connectionState: ConnectionEndState
	): void {
		const sourceNodeId =
			connectionState.fromHandle?.nodeId ?? pendingConnectionSource?.nodeId ?? '';
		const targetNodeId = connectionState.toHandle?.nodeId ?? '';
		pendingConnectionSource = null;

		if (!sourceNodeId) return;
		if (connectionState.isValid) return;

		if (!targetNodeId) {
			setConnectionFeedback(
				'missing_target',
				'Connection canceled. Drop onto a left-side hook handle to create a link.'
			);
			return;
		}

		const decision = evaluateConnectionDecision({
			source: sourceNodeId,
			target: targetNodeId,
			sourceHandle: connectionState.fromHandle?.id ?? null,
			targetHandle: connectionState.toHandle?.id ?? null
		});
		if (!decision.valid) {
			setConnectionFeedback(decision.code ?? 'unknown', decision.message ?? 'Connection rejected.');
		}
	}

	function isDependencyEdgeData(data: unknown): data is XyflowDependencyEdgeData {
		if (!data || typeof data !== 'object') return false;
		const { kind, missing } = data as { kind?: unknown; missing?: unknown };
		return (kind === 'dependency' || kind === 'hook_root') && typeof missing === 'boolean';
	}

	function isHookRootNode(
		node: Node<XyflowDependencyNodeData>
	): node is Node<XyflowHookRootNodeData> {
		return node.data.kind === 'hook_root';
	}

	function handleEdgeClick({ edge, event }: { edge: Edge; event: MouseEvent }): void {
		if (!isDependencyEdgeData(edge.data)) return;
		if (edge.data.kind === 'dependency') {
			if (!mappingIdsInScope.has(edge.source) || !mappingIdsInScope.has(edge.target)) return;
		} else if (edge.data.kind === 'hook_root') {
			if (!rootHookByNodeId.has(edge.source) || !mappingIdsInScope.has(edge.target)) return;
		} else {
			return;
		}
		event.preventDefault();
		event.stopPropagation();
		snapshotVisibleNodePositions();
		const canvasTopBeforeDisconnect = getCanvasTop();
		const viewportSnapshot = { ...flowViewport };
		if (hoveredRemovableEdgeId === edge.id) {
			hoveredRemovableEdgeId = null;
		}
		onDisconnectDependency?.(edge.source, edge.target, edge.data.hook ?? null);
		onSetEditingMapping?.(edge.target);
		requestAnimationFrame(() => {
			flowViewport = viewportSnapshot;
			requestAnimationFrame(() => {
				flowViewport = viewportSnapshot;
				restoreCanvasTop(canvasTopBeforeDisconnect);
			});
		});
		setConnectionFeedback(
			'policy_violation',
			'Removed dependency link. This mapping is invalid until a trigger source is connected again.'
		);
	}

	function handleEdgePointerEnter({ edge }: { edge: Edge; event: PointerEvent }): void {
		if (!isDependencyEdgeData(edge.data)) return;
		hoveredRemovableEdgeId = edge.id;
	}

	function handleEdgePointerLeave({ edge }: { edge: Edge; event: PointerEvent }): void {
		if (!isDependencyEdgeData(edge.data)) return;
		if (hoveredRemovableEdgeId === edge.id) {
			hoveredRemovableEdgeId = null;
		}
	}

	function handleSaveDependenciesClick(): void {
		if (hasInvalidMappings) {
			setConnectionFeedback(
				'policy_violation',
				saveDisabledReason ?? 'Resolve invalid mappings before saving.'
			);
			return;
		}
		void onSaveDependencies();
	}

	function handleNodeDragStop({
		targetNode
	}: {
		targetNode: Node<XyflowDependencyNodeData> | null;
	}): void {
		if (!targetNode) return;
		shouldAutoFitView = false;
		viewOnlyPositions.set(targetNode.id, { ...targetNode.position });
		refreshActiveDuplicatePopoverAnchor();
	}

	function handleNodeDrag({
		targetNode,
		nodes
	}: {
		targetNode: Node<XyflowDependencyNodeData> | null;
		nodes: Node<XyflowDependencyNodeData>[];
		event: MouseEvent | TouchEvent;
	}): void {
		shouldAutoFitView = false;
		if (targetNode) {
			viewOnlyPositions.set(targetNode.id, { ...targetNode.position });
		}
		for (const node of nodes) {
			viewOnlyPositions.set(node.id, { ...node.position });
		}
		refreshActiveDuplicatePopoverAnchor();
	}

	function snapshotVisibleNodePositions(): void {
		for (const node of flowNodes) {
			viewOnlyPositions.set(node.id, { ...node.position });
		}
	}

	function getCanvasTop(): number | null {
		if (!graphCanvasElement) return null;
		return graphCanvasElement.getBoundingClientRect().top;
	}

	function restoreCanvasTop(anchorTop: number | null): void {
		if (anchorTop === null) return;
		const currentTop = getCanvasTop();
		if (currentTop === null) return;
		const delta = currentTop - anchorTop;
		if (Math.abs(delta) <= 1) return;
		window.scrollBy({ top: delta, left: 0 });
	}

	function isRemoteHook(value: HookExecutionPreview | WorkflowPlanHook): value is WorkflowPlanHook {
		const candidate = value as WorkflowPlanHook;
		if (!Array.isArray(candidate.waves)) return false;
		if (candidate.waves.length > 0) {
			const firstWave = candidate.waves[0] as unknown;
			return Boolean(
				firstWave &&
				typeof firstWave === 'object' &&
				'mapping_ids' in (firstWave as Record<string, unknown>)
			);
		}

		const firstBlocked = candidate.blocked?.[0] as unknown;
		return Boolean(
			firstBlocked &&
			typeof firstBlocked === 'object' &&
			'mapping_id' in (firstBlocked as Record<string, unknown>)
		);
	}

	function normalizeLocalHookPreview(preview: HookExecutionPreview): DisplayHookPreview {
		return {
			hook: preview.hook,
			order: preview.order ?? [],
			waves: (preview.waves ?? []).map((mappingIds, level) => ({ level, mappingIds })),
			runnable: preview.runnable ?? [],
			blocked: (preview.blocked ?? []).map((item) => ({
				mappingId: item.mappingId,
				reason: item.reason,
				details: item.details
			})),
			cycleIds: preview.cycleIds ?? []
		};
	}

	function normalizeRemoteHookPreview(preview: WorkflowPlanHook): DisplayHookPreview {
		return {
			hook: preview.hook,
			order: preview.order ?? [],
			waves: (preview.waves ?? []).map((wave) => ({
				level: wave.level,
				mappingIds: wave.mapping_ids
			})),
			runnable: preview.runnable ?? [],
			blocked: (preview.blocked ?? []).map((item) => ({
				mappingId: item.mapping_id,
				reason: item.reason,
				details: item.details
			})),
			cycleIds: preview.cycle_ids ?? []
		};
	}

	function displayMappingLabel(mappingId: string): string {
		const hook = extractHookFromRootId(mappingId);
		if (hook) {
			return `${displayHookLabel(hook)} root`;
		}
		const linkage = linkageByIdAll.get(mappingId);
		if (!linkage) return mappingId;
		return linkage.action_name_label || linkage.central_action_id || mappingId;
	}

	function displayHookLabel(hook: string): string {
		return resolvedHookLabels[hook] ?? hook;
	}

	function parseTraceEntryId(rawEntryId: string): number | null | undefined {
		const trimmed = rawEntryId.trim();
		if (!trimmed) return undefined;
		if (!/^\d+$/.test(trimmed)) return null;
		const parsed = Number.parseInt(trimmed, 10);
		if (!Number.isFinite(parsed) || parsed <= 0) return null;
		return parsed;
	}

	function getTraceFieldDisplay(fieldId: string): TraceFieldDisplay {
		const match = traceFieldOptions.find((field) => String(field.id) === fieldId);
		if (!match) {
			const fallbackName = `Field ${fieldId}`;
			return {
				primaryName: fallbackName,
				helperText: `ID: ${fieldId}`,
				optionLabel: `${fallbackName} - ID: ${fieldId}`
			};
		}

		const fieldLabel = String(match.label ?? '').trim();
		const adminLabel = String(match.adminLabel ?? '').trim();
		const primaryName = fieldLabel || adminLabel || `Field ${fieldId}`;
		const helperParts: string[] = [];
		if (adminLabel && adminLabel !== primaryName) {
			helperParts.push(`Admin label: ${adminLabel}`);
		}
		helperParts.push(`ID: ${fieldId}`);

		return {
			primaryName,
			helperText: helperParts.join(' · '),
			optionLabel: `${primaryName} - ID: ${fieldId}`
		};
	}

	function addOrUpdateTraceManualValue(): void {
		const fieldId = traceCustomFieldId.trim();
		if (!fieldId) {
			traceError = 'Provide a field ID before adding a manual trace value.';
			return;
		}

		traceError = null;
		traceManualValues = {
			...traceManualValues,
			[fieldId]: traceCustomFieldValue
		};
		traceCustomFieldValue = '';
	}

	function updateTraceManualValue(fieldId: string, value: string): void {
		traceManualValues = {
			...traceManualValues,
			[fieldId]: value
		};
	}

	function removeTraceManualValue(fieldId: string): void {
		if (!(fieldId in traceManualValues)) return;
		const nextValues = { ...traceManualValues };
		delete nextValues[fieldId];
		traceManualValues = nextValues;
	}

	function clearTraceInputs(): void {
		traceManualValues = {};
		traceEntryId = '';
		traceCustomFieldValue = '';
		traceError = null;
	}

	function clearTraceResult(): void {
		traceResult = null;
		traceError = null;
	}

	async function runRequestTrace(): Promise<void> {
		if (!formSourceSlug || !formId || Number.isNaN(formId)) {
			traceError = 'Form context is unavailable, so trace simulation cannot run.';
			return;
		}

		const parsedEntryId = parseTraceEntryId(traceEntryId);
		if (parsedEntryId === null) {
			traceError = 'Entry ID must be a positive integer.';
			return;
		}

		traceLoading = true;
		traceError = null;

		const payloadInput: Record<string, unknown> = {
			hook_scope: selectedHookScope,
			field_scope: 'mapped_and_rule',
			include_drafts: traceIncludeDrafts
		};
		if (Object.keys(traceManualValues).length > 0) {
			payloadInput.entry_values = traceManualValues;
		}
		if (parsedEntryId !== undefined) {
			payloadInput.entry_id = parsedEntryId;
		}
		if (traceIncludeDrafts) {
			payloadInput.draft_mappings = linkages;
		}
		const payload = parseRegisteredEndpointRequest('forms.requestTrace.run', payloadInput);

		try {
			const client = createClientFromConfig({ notifyErrors: false });
			traceResult = await client.runRequestTrace(formSourceSlug, formId, payload, {
				showNotifications: false
			});
		} catch (error) {
			traceResult = null;
			traceError = error instanceof Error ? error.message : 'Request trace failed.';
		} finally {
			traceLoading = false;
		}
	}

	function traceOutcomeLabel(outcome: RequestTraceStep['outcome']): string {
		switch (outcome) {
			case 'would_run':
				return 'Would run';
			case 'would_queue':
				return 'Would continue in Background';
			default:
				return 'Blocked';
		}
	}

	function traceOutcomeVariant(
		outcome: RequestTraceStep['outcome']
	): 'success' | 'info' | 'danger' {
		switch (outcome) {
			case 'would_run':
				return 'success';
			case 'would_queue':
				return 'info';
			default:
				return 'danger';
		}
	}

	function describeTraceBlockReason(
		reason: TraceBlockReason | null | undefined,
		details?: string | null
	): string {
		if (!reason) return details?.trim() ?? 'Unknown block reason';
		switch (reason) {
			case 'disabled':
				return 'Mapping is disabled.';
			case 'missing_dependency':
				return details ? `Missing dependency: ${details}` : 'Missing dependency.';
			case 'cycle':
				return 'Dependency cycle detected.';
			case 'upstream_blocked':
				return details ? `Blocked by upstream mapping: ${details}` : 'Blocked by upstream mapping.';
			case 'policy_violation':
				return details ? `Policy violation: ${details}` : 'Policy violation.';
			case 'invalid_trigger':
				return details
					? `Missing trigger source: ${displayHookLabel(details)}`
					: 'Missing trigger source.';
			case 'condition_false':
				return details ? `Condition did not match: ${details}` : 'Condition did not match.';
			default:
				return reason;
		}
	}

	function formatTraceTriggerSource(step: RequestTraceStep): string {
		const source = step.trigger_source;
		if (!source || source.type === 'hook_root') return 'Hook root';
		if (source.type === 'unbound') return 'Unbound trigger source';
		if (source.mapping_id) return `Mapping: ${displayMappingLabel(source.mapping_id)}`;
		return 'Mapping source';
	}

	function formatTraceValue(value: unknown): string {
		if (value === null) return 'null';
		if (value === undefined) return 'undefined';
		if (typeof value === 'string') return `"${value}"`;
		if (typeof value === 'number' || typeof value === 'boolean') return String(value);

		try {
			const serialized = JSON.stringify(value);
			if (!serialized) return String(value);
			return serialized.length > 120 ? `${serialized.slice(0, 117)}...` : serialized;
		} catch {
			return String(value);
		}
	}

	function formatConditionTrace(node: ConditionTraceNode | null | undefined, depth = 0): string[] {
		if (!node) return [];

		const indent = '  '.repeat(depth);
		if (node.type === 'group') {
			const lines = [
				`${indent}${node.logic.toUpperCase()} group -> ${node.result ? 'match' : 'no match'}${node.reason_code ? ` (${node.reason_code})` : ''}`
			];
			for (const child of node.children ?? []) {
				lines.push(...formatConditionTrace(child, depth + 1));
			}
			return lines;
		}

		if (node.type === 'rule') {
			return [
				`${indent}rule field ${node.field_id ?? '?'} ${node.operator ?? '?'} expected ${formatTraceValue(node.expected)} actual ${formatTraceValue(node.actual)} -> ${node.result ? 'match' : 'no match'}${node.reason_code ? ` (${node.reason_code})` : ''}`
			];
		}

		return [
			`${indent}invalid node -> ${node.result ? 'match' : 'no match'}${node.reason_code ? ` (${node.reason_code})` : ''}`
		];
	}

	function describeBlockReason(item: DisplayBlocked): string {
		switch (item.reason) {
			case 'disabled':
				return 'Disabled';
			case 'missing_dependency':
				return `Missing dependency${item.details ? `: ${item.details}` : ''}`;
			case 'cycle':
				return 'Cycle detected';
			case 'upstream_blocked':
				return `Blocked by upstream${item.details ? `: ${item.details}` : ''}`;
			case 'invalid_trigger':
				return `Missing trigger source${item.details ? `: ${displayHookLabel(item.details)}` : ''}`;
			case 'policy_violation':
				return `Policy violation${item.details ? `: ${item.details}` : ''}`;
			default:
				return item.reason;
		}
	}
</script>

<svelte:document onpointerdown={handleDuplicatePopoverPointerDown} />
<svelte:window
	onkeydown={handleDuplicatePopoverEscape}
	onresize={handleDuplicatePopoverViewportChange}
	onscroll={handleDuplicatePopoverViewportChange}
/>
<div class="sf:space-y-3" data-testid="dependency-graph">
	<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
		<div>
			<p class="sf:text-sm sf:font-medium sf:text-slate-700">Action Execution Order</p>
			<div
				class="sf:mt-1 sf:flex sf:flex-wrap sf:items-center sf:gap-2 sf:text-[11px] sf:text-slate-600"
			>
				<span class="sf:rounded-full sf:border sf:border-slate-200 sf:bg-white sf:px-2 sf:py-0.5">
					Drag to reorder
				</span>
				<span class="sf:rounded-full sf:border sf:border-blue-200 sf:bg-blue-50 sf:px-2 sf:py-0.5">
					Blue edges run first
				</span>
				<span
					class="sf:rounded-full sf:border sf:border-indigo-200 sf:bg-indigo-50 sf:px-2 sf:py-0.5"
				>
					Hook roots start a run
				</span>
				<span class="sf:rounded-full sf:border sf:border-rose-200 sf:bg-rose-50 sf:px-2 sf:py-0.5">
					Red edges can be removed
				</span>
			</div>
		</div>
		<div class="sf:flex sf:items-center sf:gap-2 sf:flex-wrap">
			<Badge variant={plannerAuthority === 'cps' ? 'success' : 'neutral'}>
				{plannerAuthority === 'cps' ? 'Planner authority: managed' : 'Planner authority: local'}
			</Badge>
			{#if graph.cycleIds.length > 0}
				<Badge variant="danger">Cycle detected</Badge>
			{/if}
		</div>
	</div>

	<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
		<Button
			size="sm"
			variant={selectedHookScope === 'all' ? 'primary' : 'secondary'}
			onclick={() => {
				selectedHookScope = 'all';
			}}
		>
			All hook spaces
		</Button>
		{#each availableHookScopes as hookScope (hookScope)}
			<Button
				size="sm"
				variant={selectedHookScope === hookScope ? 'primary' : 'secondary'}
				onclick={() => {
					selectedHookScope = hookScope;
				}}
			>
				{displayHookLabel(hookScope)}
			</Button>
		{/each}
		<Button
			size="sm"
			variant="secondary"
			onclick={() => onOpenAddAction?.()}
			data-testid="dependency-graph-open-add-action"
		>
			Add action
		</Button>
	</div>

	{#if hasUnsavedChanges}
		<div
			class="sf:sticky sf:top-0 sf:z-10 sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2 sf:rounded-md sf:border sf:border-amber-300 sf:bg-amber-50 sf:px-3 sf:py-2"
			data-testid="dependency-graph-dirty-bar"
		>
			<div class="sf:flex sf:items-center sf:gap-2">
				<Badge variant="warning">Unsaved changes</Badge>
				<p class="sf:text-xs sf:text-amber-800">
					Dependency edits are local until you click <strong>Save dependencies</strong>.
				</p>
			</div>
			<Button
				size="sm"
				variant="secondary"
				onclick={handleSaveDependenciesClick}
				disabled={savingDependencies || hasInvalidMappings}
			>
				{savingDependencies ? 'Saving…' : 'Save dependencies'}
			</Button>
		</div>
	{/if}

	{#if hasInvalidMappings}
		<p
			class="sf:text-xs sf:rounded-md sf:border sf:border-rose-300 sf:bg-rose-50 sf:px-3 sf:py-2 sf:text-rose-800"
			data-testid="dependency-graph-invalid-bar"
		>
			<strong>Cannot save yet:</strong>
			{saveDisabledReason}
		</p>
	{/if}

	{#if editingLabel}
		<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
			<p class="sf:text-xs sf:text-slate-600">
				Editing <strong>{editingLabel}</strong>. Connect dependencies into this node, or connect
				from a root to make it autonomous.
			</p>
			<div class="sf:flex sf:items-center sf:gap-2 sf:flex-wrap">
				<Button
					size="sm"
					variant="secondary"
					onclick={() => onClearDependencies()}
					disabled={editingCurrentDeps.length === 0}
					data-testid="dependency-graph-clear"
				>
					Make autonomous
				</Button>
				{#if canUndoRootAttach}
					<Button
						size="sm"
						variant="ghost"
						onclick={() => onUndoLastRootAttach()}
						data-testid="dependency-graph-undo-root-attach"
					>
						Undo root attach
					</Button>
				{/if}
				<Button
					size="sm"
					variant={hasUnsavedChanges ? 'secondary' : 'primary'}
					onclick={handleSaveDependenciesClick}
					disabled={savingDependencies || hasInvalidMappings}
					data-testid="dependency-graph-save"
				>
					{savingDependencies ? 'Saving…' : 'Save dependencies'}
				</Button>
				<Button
					size="sm"
					variant="secondary"
					onclick={() => onCancelDependencyEdit()}
					data-testid="dependency-graph-cancel"
				>
					Cancel editing
				</Button>
			</div>
		</div>
	{/if}

	{#if plannerAuthority !== 'cps' && plannerAuthority !== 'local'}
		<p
			class="sf:text-xs sf:rounded-md sf:border sf:border-amber-300 sf:bg-amber-50 sf:px-3 sf:py-2 sf:text-amber-800"
		>
			Managed workflow planner is unavailable. Showing local fallback preview in read-only authority
			mode.
		</p>
	{/if}

	{#if workflowPlanLoading}
		<p class="sf:text-xs sf:text-slate-500">Refreshing workflow planner…</p>
	{/if}

	{#if workflowPlanError}
		<p class="sf:text-xs sf:text-amber-700">{workflowPlanError}</p>
	{/if}

	{#if previewNotice}
		<p
			class="sf:text-xs sf:rounded-md sf:border sf:border-sky-200 sf:bg-sky-50 sf:px-3 sf:py-2 sf:text-sky-800"
		>
			{previewNotice}
		</p>
	{/if}

	{#if pendingConnectionSource}
		<p
			class="sf:text-xs sf:rounded-md sf:border sf:border-indigo-200 sf:bg-indigo-50 sf:px-3 sf:py-2 sf:text-indigo-800"
		>
			Connecting from <strong>{displayMappingLabel(pendingConnectionSource.nodeId ?? '')}</strong>.
			Drop on an action's left hook handle to set dependency order, or on a root target handle to
			retarget hook root.
		</p>
	{/if}

	{#if connectionFeedback}
		<p
			class="sf:text-xs sf:rounded-md sf:border sf:border-amber-300 sf:bg-amber-50 sf:px-3 sf:py-2 sf:text-amber-800"
			data-testid="dependency-graph-connection-feedback"
		>
			<strong>Connection blocked:</strong>
			{connectionFeedback.message}
		</p>
	{/if}

	{#if graph.nodes.length === 0}
		<p class="sf:text-sm sf:text-slate-500">No mappings yet.</p>
	{:else}
		<div class="sf:grid sf:gap-3 sf:xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
			<div
				class="sf:relative sf:h-[520px] sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50"
				data-testid="dependency-graph-canvas"
				data-viewport={`${flowViewport.x.toFixed(2)},${flowViewport.y.toFixed(2)},${flowViewport.zoom.toFixed(3)}`}
				bind:this={graphCanvasElement}
			>
				<SvelteFlow
					bind:nodes={flowNodes}
					bind:edges={flowEdges}
					bind:viewport={flowViewport}
					fitView={shouldAutoFitView}
					nodesDraggable
					nodesConnectable
					elementsSelectable
					panOnDrag
					zoomOnScroll
					onconnect={handleConnect}
					onconnectstart={handleConnectStart}
					onconnectend={handleConnectEnd}
					onclickconnectstart={handleConnectStart}
					onclickconnectend={handleConnectEnd}
					onedgeclick={handleEdgeClick}
					onedgepointerenter={handleEdgePointerEnter}
					onedgepointerleave={handleEdgePointerLeave}
					onnodedrag={handleNodeDrag}
					onnodedragstop={handleNodeDragStop}
					{isValidConnection}
					connectionMode={ConnectionMode.Strict}
					connectionLineType={ConnectionLineType.SmoothStep}
					{nodeTypes}
					{edgeTypes}
					clickConnect
					class="sf:rounded-md"
					noDragClass="nodrag"
					noPanClass="nopan"
					noWheelClass="sf-nowheel"
					connectionRadius={56}
					minZoom={0.3}
					maxZoom={1.8}
				>
					<Background />
					<MiniMap class="sf:pointer-events-none" />
					<Controls />
				</SvelteFlow>
				{#if activeDuplicatePopoverNodeId && activeDuplicatePopoverPosition}
					<div
						class="sf:fixed sf:z-[260] sf:w-[280px] sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-2 sf:shadow-xl sf:space-y-2 nodrag nopan sf-nowheel"
						style={`left:${activeDuplicatePopoverPosition.left}px;top:${activeDuplicatePopoverPosition.top}px;`}
						onpointerdown={(event) => event.stopPropagation()}
						bind:this={duplicatePopoverElement}
						data-testid={`dependency-node-duplicate-popover-${activeDuplicatePopoverNodeId}`}
					>
						<p class="sf:text-[11px] sf:font-semibold sf:text-slate-700">
							Duplicate and insert under
						</p>
						<select
							class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-2 sf:py-1 sf:text-xs sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							bind:value={activeDuplicateParentId}
							data-testid={`dependency-node-duplicate-select-${activeDuplicatePopoverNodeId}`}
						>
							{#each activeDuplicateParentOptions as option (option.id)}
								<option value={option.id}>{option.label}</option>
							{/each}
						</select>
						{#if activeDuplicateParentOption?.description}
							<p class="sf:text-[11px] sf:text-slate-500">
								{activeDuplicateParentOption.description}
							</p>
						{/if}
						<div class="sf:flex sf:justify-end sf:gap-1">
							<Button
								size="sm"
								variant="secondary"
								class="nodrag"
								onclick={closeDuplicatePopoverFromUI}
								data-testid={`dependency-node-duplicate-cancel-${activeDuplicatePopoverNodeId}`}
								disabled={activeDuplicateIsLoading}
							>
								Cancel
							</Button>
							<Button
								size="sm"
								variant="primary"
								class="nodrag"
								onclick={confirmDuplicateFromUI}
								data-testid={`dependency-node-duplicate-confirm-${activeDuplicatePopoverNodeId}`}
								disabled={!activeDuplicateParentOption || activeDuplicateIsLoading}
							>
								{activeDuplicateIsLoading ? 'Duplicating…' : 'Duplicate'}
							</Button>
						</div>
					</div>
				{/if}
			</div>

			<div
				class="sf:h-[520px] sf:overflow-y-auto sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-3"
			>
				<div>
					<p class="sf:text-sm sf:font-semibold sf:text-slate-800">Execution-order preview</p>
					<p class="sf:text-xs sf:text-slate-500">Policy version: {policyVersion}</p>
					<p class="sf:text-xs sf:text-slate-500">{previewSourceLabel}</p>
				</div>

				<div class="sf:grid sf:grid-cols-3 sf:gap-2">
					<div
						class="sf:rounded-md sf:border sf:border-emerald-200 sf:bg-emerald-50 sf:px-2 sf:py-1"
					>
						<p class="sf:text-[11px] sf:text-emerald-700">Runnable</p>
						<p class="sf:text-sm sf:font-semibold sf:text-emerald-800">{activeRunnableCount}</p>
					</div>
					<div class="sf:rounded-md sf:border sf:border-amber-200 sf:bg-amber-50 sf:px-2 sf:py-1">
						<p class="sf:text-[11px] sf:text-amber-700">Blocked</p>
						<p class="sf:text-sm sf:font-semibold sf:text-amber-800">{activeBlockedCount}</p>
					</div>
					<div class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-2 sf:py-1">
						<p class="sf:text-[11px] sf:text-slate-600">Cycle nodes</p>
						<p class="sf:text-sm sf:font-semibold sf:text-slate-800">{cycleNodeCount}</p>
					</div>
				</div>

				{#if selectedHookScope === 'all'}
					<div class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-2">
						<p class="sf:text-xs sf:font-semibold sf:text-slate-700">All hook spaces</p>
						<p class="sf:text-[11px] sf:text-slate-500 sf:mt-1">
							Aggregate preview across all hook roots.
						</p>
					</div>
				{/if}

				{#if normalizedHookPreviews.length === 0}
					<p class="sf:text-xs sf:text-slate-500">No hook preview is available for this scope.</p>
				{:else}
					{#each normalizedHookPreviews as hookPreview (hookPreview.hook)}
						<div
							class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-2 sf:space-y-2"
						>
							<p class="sf:text-xs sf:font-semibold sf:text-slate-700">
								Hook: {displayHookLabel(hookPreview.hook)}
							</p>
							<p class="sf:text-[11px] sf:text-slate-500">
								Order:
								{hookPreview.order.length > 0
									? hookPreview.order.map((id) => displayMappingLabel(id)).join(' → ')
									: 'none'}
							</p>

							<div>
								<p class="sf:text-[11px] sf:font-semibold sf:text-slate-700 sf:mb-1">
									Parallel waves
								</p>
								{#if hookPreview.waves.length === 0}
									<p class="sf:text-[11px] sf:text-slate-500">No runnable waves for this hook.</p>
								{:else}
									<div class="sf:space-y-1">
										{#each hookPreview.waves as wave (wave.level)}
											<p class="sf:text-[11px] sf:text-slate-600">
												<strong>Wave {wave.level + 1}:</strong>
												{wave.mappingIds.map((id) => displayMappingLabel(id)).join(' · ')}
											</p>
										{/each}
									</div>
								{/if}
							</div>

							<div>
								<p class="sf:text-[11px] sf:font-semibold sf:text-slate-700 sf:mb-1">
									Blocked mappings
								</p>
								{#if hookPreview.blocked.length === 0}
									<p class="sf:text-[11px] sf:text-emerald-700">None in this hook.</p>
								{:else}
									<ul class="sf:space-y-1">
										{#each hookPreview.blocked as blocked (blocked.mappingId)}
											<li class="sf:text-[11px] sf:text-slate-700">
												<strong>{displayMappingLabel(blocked.mappingId)}</strong>:
												{describeBlockReason(blocked)}
											</li>
										{/each}
									</ul>
								{/if}
							</div>
						</div>
					{/each}
				{/if}

				<div>
					<p class="sf:text-xs sf:font-semibold sf:text-slate-700 sf:mb-1">Policy diagnostics</p>
					{#if policyViolations.length === 0}
						<p class="sf:text-xs sf:text-emerald-700">No policy violations detected.</p>
					{:else}
						<ul class="sf:space-y-1">
							{#each policyViolations.slice(0, 6) as issue (`${issue.mapping_id}:${issue.dependency_id}:${issue.code}`)}
								<li class="sf:text-xs sf:text-amber-800">
									<strong>{displayMappingLabel(issue.mapping_id)}</strong> →
									{displayMappingLabel(issue.dependency_id)}: {issue.message}
								</li>
							{/each}
						</ul>
					{/if}
				</div>

				<div
					class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-2 sf:space-y-3"
					data-testid="request-trace-panel"
				>
					<div>
						<p class="sf:text-xs sf:font-semibold sf:text-slate-700">Request tracer</p>
						<p class="sf:text-[11px] sf:text-slate-500">
							Simulate request outcomes for the current graph scope using optional entry values.
						</p>
					</div>

					<div class="sf:grid sf:grid-cols-1 sf:gap-2">
						<label class="sf:text-[11px] sf:text-slate-600">
							Entry ID (optional)
							<input
								type="text"
								inputmode="numeric"
								bind:value={traceEntryId}
								placeholder="e.g. 1234"
								class="sf:mt-1 sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-2 sf:py-1.5 sf:text-xs sf:text-slate-700 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								data-testid="request-trace-entry-id"
							/>
						</label>

						<label class="sf:flex sf:items-center sf:gap-2 sf:text-[11px] sf:text-slate-600">
							<input
								type="checkbox"
								checked={traceIncludeDrafts}
								class="sf:h-4 sf:w-4 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								onchange={(event) => {
									traceIncludeDrafts = (event.currentTarget as HTMLInputElement).checked;
								}}
								data-testid="request-trace-include-drafts"
							/>
							Include unsaved draft mappings
						</label>
					</div>

					<div class="sf:space-y-2">
						<p class="sf:text-[11px] sf:font-semibold sf:text-slate-700">Manual field values</p>
						<div class="sf:grid sf:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sf:gap-2">
							{#if traceFieldOptions.length > 0}
								<select
									bind:value={traceCustomFieldId}
									class="sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-2 sf:py-1.5 sf:text-xs sf:text-slate-700 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
									data-testid="request-trace-field-id"
								>
									{#each traceFieldOptions as field (`${field.id}`)}
										<option value={String(field.id)}>
											{getTraceFieldDisplay(String(field.id)).optionLabel}
										</option>
									{/each}
								</select>
							{:else}
								<input
									type="text"
									bind:value={traceCustomFieldId}
									placeholder="Field ID"
									class="sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-2 sf:py-1.5 sf:text-xs sf:text-slate-700 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
									data-testid="request-trace-field-id"
								/>
							{/if}
							<input
								type="text"
								bind:value={traceCustomFieldValue}
								placeholder="Field value"
								class="sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-2 sf:py-1.5 sf:text-xs sf:text-slate-700 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								data-testid="request-trace-field-value"
							/>
							<Button
								size="sm"
								variant="secondary"
								onclick={addOrUpdateTraceManualValue}
								data-testid="request-trace-add-manual-value"
							>
								Add
							</Button>
						</div>

						{#if traceManualEntries.length === 0}
							<p class="sf:text-[11px] sf:text-slate-500">No manual values added.</p>
						{:else}
							<div class="sf:space-y-1">
								{#each traceManualEntries as [fieldId, value] (fieldId)}
									<div
										class="sf:grid sf:grid-cols-[minmax(0,1fr)_auto] sf:items-center sf:gap-2"
										data-testid={`request-trace-manual-${fieldId}`}
									>
										<label class="sf:text-[11px] sf:text-slate-600">
											<span class="sf:block sf:text-xs sf:font-medium sf:text-slate-700">
												{getTraceFieldDisplay(fieldId).primaryName}
											</span>
											<span class="sf:block sf:text-[11px] sf:text-slate-500">
												{getTraceFieldDisplay(fieldId).helperText}
											</span>
											<input
												type="text"
												{value}
												oninput={(event) => {
													updateTraceManualValue(
														fieldId,
														(event.currentTarget as HTMLInputElement).value
													);
												}}
												class="sf:mt-1 sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-2 sf:py-1 sf:text-xs sf:text-slate-700 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
											/>
										</label>
										<Button
											size="sm"
											variant="ghost"
											onclick={() => removeTraceManualValue(fieldId)}
										>
											Remove
										</Button>
									</div>
								{/each}
							</div>
						{/if}
					</div>

					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<Button
							size="sm"
							variant="primary"
							disabled={traceLoading}
							onclick={() => {
								void runRequestTrace();
							}}
							data-testid="request-trace-run"
						>
							{traceLoading ? 'Tracing…' : 'Run trace'}
						</Button>
						<Button size="sm" variant="secondary" onclick={clearTraceResult}>Clear result</Button>
						<Button size="sm" variant="ghost" onclick={clearTraceInputs}>Clear inputs</Button>
					</div>

					{#if traceError}
						<p
							class="sf:text-xs sf:rounded-md sf:border sf:border-rose-300 sf:bg-rose-50 sf:px-2 sf:py-1 sf:text-rose-800"
							data-testid="request-trace-error"
						>
							{traceError}
						</p>
					{/if}

					{#if traceResult}
						<div class="sf:space-y-2" data-testid="request-trace-results">
							<div class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-2">
								<p class="sf:text-[11px] sf:text-slate-700">
									<strong>Input source:</strong>
									{traceResult.input.source}
								</p>
								<p class="sf:text-[11px] sf:text-slate-600">
									<strong>Effective values:</strong>
									{Object.keys(traceResult.input.values ?? {}).length}
									·
									<strong>Manual:</strong>
									{traceResult.input.manual_field_ids.length}
									·
									<strong>Imported:</strong>
									{traceResult.input.imported_field_ids.length}
								</p>
								{#if traceResult.input.warnings.length > 0}
									<ul class="sf:mt-1 sf:space-y-1">
										{#each traceResult.input.warnings as warning, index (`warning-${index}`)}
											<li class="sf:text-[11px] sf:text-amber-700">{warning}</li>
										{/each}
									</ul>
								{/if}
							</div>

							{#if traceResult.hooks.length === 0}
								<p class="sf:text-[11px] sf:text-slate-500">
									No trace hooks are available for this graph.
								</p>
							{:else}
								{#each traceResult.hooks as hookTrace (hookTrace.hook)}
									<div
										class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-2 sf:space-y-2"
										data-testid={`request-trace-hook-${hookTrace.hook}`}
									>
										<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
											<p class="sf:text-[11px] sf:font-semibold sf:text-slate-700">
												{displayHookLabel(hookTrace.hook)}
											</p>
											<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-1">
												<Badge variant="success">Run: {hookTrace.runnable.length}</Badge>
												<Badge variant="info">Background: {hookTrace.queued.length}</Badge>
												<Badge variant="danger">Blocked: {hookTrace.blocked.length}</Badge>
											</div>
										</div>

										{#if hookTrace.steps.length === 0}
											<p class="sf:text-[11px] sf:text-slate-500">
												No step details returned for this hook.
											</p>
										{:else}
											<div class="sf:space-y-2">
												{#each hookTrace.steps as step (`${hookTrace.hook}:${step.mapping_id}`)}
													<div
														class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-2 sf:space-y-1"
														data-testid={`request-trace-step-${step.mapping_id}`}
													>
														<div
															class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2"
														>
															<p class="sf:text-[11px] sf:font-semibold sf:text-slate-700">
																{step.label}
															</p>
															<Badge variant={traceOutcomeVariant(step.outcome)}>
																{traceOutcomeLabel(step.outcome)}
															</Badge>
														</div>

														<p class="sf:text-[11px] sf:text-slate-600">
															<strong>Trigger:</strong>
															{formatTraceTriggerSource(step)}
															·
															<strong>Deps:</strong>
															{step.dependency_ids.length > 0
																? step.dependency_ids
																		.map((id) => displayMappingLabel(id))
																		.join(', ')
																: 'none'}
														</p>

														{#if step.outcome === 'blocked'}
															<p class="sf:text-[11px] sf:text-rose-700">
																{describeTraceBlockReason(step.block_reason, step.block_details)}
															</p>
														{/if}

														<div class="sf:text-[11px] sf:text-slate-600">
															<p>
																<strong>Condition:</strong>
																{step.condition.summary}
															</p>
															<p>
																<strong>Reason code:</strong>
																{step.condition.reason_code}
															</p>
														</div>

														{#if step.condition.tree}
															<details class="sf:rounded-sm sf:bg-white sf:p-1">
																<summary class="sf:cursor-pointer sf:text-[11px] sf:text-slate-600">
																	Condition decision tree
																</summary>
																<ul class="sf:mt-1 sf:space-y-0.5">
																	{#each formatConditionTrace(step.condition.tree) as line, index (`${step.mapping_id}:${index}`)}
																		<li class="sf:text-[11px] sf:font-mono sf:text-slate-600">
																			{line}
																		</li>
																	{/each}
																</ul>
															</details>
														{/if}
													</div>
												{/each}
											</div>
										{/if}
									</div>
								{/each}
							{/if}
						</div>
					{/if}
				</div>
			</div>
		</div>
	{/if}

	<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3 sf:text-[11px] sf:text-slate-500">
		<span>Slate edge: explicit dependency (upstream prerequisite -> dependent)</span>
		<span>Blue edge: hook root trigger for autonomous action</span>
	</div>

	{#if graph.nodes.length > 1 && explicitEdgeCount === 0}
		<p class="sf:text-xs sf:text-slate-500">
			No explicit dependency links configured yet. Autonomous actions connect from hook roots.
		</p>
	{/if}

	{#if graph.nodes.length > 1 && rootEdgeCount === 0}
		<p class="sf:text-xs sf:text-slate-500">
			No autonomous actions in this hook scope. All visible actions have explicit dependencies.
		</p>
	{/if}

	{#if graph.cycleIds.length > 0}
		<p class="sf:text-xs sf:text-red-600">Cycle participants: {graph.cycleIds.join(', ')}</p>
	{/if}

	{#if editingMappingId}
		<p class="sf:text-xs sf:text-slate-500">
			Upstream Dependencies: {editingCurrentDeps.length > 0
				? editingCurrentDeps.join(', ')
				: 'none'}
		</p>
	{/if}
</div>

<style>
	:global(.sf-removable-edge path),
	:global(path.sf-removable-edge) {
		cursor: pointer;
		transition:
			stroke 120ms ease,
			stroke-width 120ms ease,
			opacity 120ms ease;
	}
</style>
