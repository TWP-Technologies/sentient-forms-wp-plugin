import dagre from '@dagrejs/dagre';
import { MarkerType, Position, type Edge, type Node } from '@xyflow/svelte';
import type { FormActionLinkage } from '$lib/api/types';
import {
	buildDependencyGraph,
	type DependencyGraphEdge,
	type DependencyGraphNode
} from '$lib/utils/mapping-dependencies';

const MAPPING_NODE_WIDTH = 320;
const MAPPING_NODE_HEIGHT = 184;
const HOOK_ROOT_NODE_WIDTH = 220;
const HOOK_ROOT_NODE_HEIGHT = 92;
const ROOT_FAMILY_VERTICAL_GAP = 96;
const GRAPH_LAYOUT_OPTIONS = {
	rankdir: 'LR',
	align: 'UL',
	nodesep: 80,
	ranksep: 150,
	marginx: 36,
	marginy: 24
};

const HOOK_ROOT_PREFIX = '__hook_root__:';
const ROOT_EDGE_LANE_STRIDE = 12;
const ROOT_FAMILY_LANE_STRIDE = 42;
const KNOWN_HOOK_ORDER: Record<string, number> = {
	real_time: 5,
	gform_validation: 10,
	gform_after_submission: 20
};

export const DEPENDENCY_SOURCE_HANDLE_ID = 'dependency-source';
export const DEPENDENCY_TARGET_HANDLE_ID = 'dependency-target';
export const HOOK_ROOT_SOURCE_HANDLE_ID = 'hook-root-source';
export const HOOK_ROOT_SOURCE_HANDLE_PREFIX = 'hook-root-source:';
export const HOOK_ROOT_TARGET_HANDLE_PREFIX = 'hook-root-target:';

export function hookRootTargetHandleId(hook: string): string {
	return `${HOOK_ROOT_TARGET_HANDLE_PREFIX}${hook}`;
}

export function hookRootSourceHandleId(slot: number): string {
	const normalizedSlot = Math.max(0, slot);
	if (normalizedSlot === 0) {
		return HOOK_ROOT_SOURCE_HANDLE_ID;
	}
	return `${HOOK_ROOT_SOURCE_HANDLE_PREFIX}${normalizedSlot}`;
}

export type DependencyEdgeKind = 'dependency' | 'hook_root';

export type XyflowMappingNodeData = {
	kind: 'mapping';
	linkage: FormActionLinkage;
	label: string;
	mappingId: string;
};

export type XyflowHookRootNodeData = {
	kind: 'hook_root';
	hook: string;
	label: string;
	sourceHandleIds: string[];
};

export type XyflowDependencyNodeData = XyflowMappingNodeData | XyflowHookRootNodeData;

export type XyflowDependencyEdgeData = {
	kind: DependencyEdgeKind;
	missing: boolean;
	hook?: string;
	lane?: number;
};

export type XyflowDependencyGraph = {
	nodes: Node<XyflowDependencyNodeData>[];
	edges: Edge<XyflowDependencyEdgeData>[];
	cycleIds: string[];
	explicitDependencyEdgeCount: number;
	layoutSignature: string;
};

type NodeCenter = {
	x: number;
	y: number;
};

export function extractHookFromRootId(rootNodeId: string): string | null {
	if (!rootNodeId.startsWith(HOOK_ROOT_PREFIX)) return null;
	return rootNodeId.slice(HOOK_ROOT_PREFIX.length);
}

export function buildXyflowDependencyGraph(items: FormActionLinkage[]): XyflowDependencyGraph {
	const graph = buildDependencyGraph(items);
	const validNodeIds = new Set(graph.nodes.map((node) => node.id));

	const dagreGraph = new dagre.graphlib.Graph();
	dagreGraph.setDefaultEdgeLabel(() => ({}));
	dagreGraph.setGraph(GRAPH_LAYOUT_OPTIONS);

	for (const node of graph.nodes) {
		const width = node.kind === 'hook_root' ? HOOK_ROOT_NODE_WIDTH : MAPPING_NODE_WIDTH;
		const height = node.kind === 'hook_root' ? HOOK_ROOT_NODE_HEIGHT : MAPPING_NODE_HEIGHT;
		dagreGraph.setNode(node.id, { width, height });
	}

	for (const edge of graph.edges) {
		if (!validNodeIds.has(edge.from) || !validNodeIds.has(edge.to)) {
			continue;
		}
		dagreGraph.setEdge(edge.from, edge.to);
	}

	dagre.layout(dagreGraph);

	const nodeCenters = new Map<string, NodeCenter>();
	for (const node of graph.nodes) {
		const dagreNode = dagreGraph.node(node.id);
		nodeCenters.set(node.id, {
			x: dagreNode?.x ?? 0,
			y: dagreNode?.y ?? 0
		});
	}

	const packedNodeCenters = packRootFamilies(graph.nodes, graph.edges, nodeCenters);

	const rootLanes = assignRootEdgeLanes(graph.edges, packedNodeCenters);
	const rootSourceSlots = assignRootEdgeSourceSlots(graph.edges, packedNodeCenters);
	const rootSourceCounts = buildRootSourceHandleCounts(rootSourceSlots);

	const nodes: Node<XyflowDependencyNodeData>[] = graph.nodes.map((node) => {
		const center = packedNodeCenters.get(node.id);
		const width = node.kind === 'hook_root' ? HOOK_ROOT_NODE_WIDTH : MAPPING_NODE_WIDTH;
		const height = node.kind === 'hook_root' ? HOOK_ROOT_NODE_HEIGHT : MAPPING_NODE_HEIGHT;
		const x = (center?.x ?? 0) - width / 2;
		const y = (center?.y ?? 0) - height / 2;

		if (node.kind === 'hook_root') {
			const sourceHandleCount = Math.max(rootSourceCounts.get(node.id) ?? 0, 1);
			const sourceHandleIds = Array.from({ length: sourceHandleCount }, (_unused, index) =>
				hookRootSourceHandleId(index)
			);
			return {
				id: node.id,
				type: 'hookRoot',
				position: { x, y },
				sourcePosition: Position.Right,
				targetPosition: Position.Left,
				data: {
					kind: 'hook_root',
					hook: node.hook,
					label: node.label,
					sourceHandleIds
				}
			};
		}

		return {
			id: node.id,
			type: 'mappingAction',
			position: { x, y },
			sourcePosition: Position.Right,
			targetPosition: Position.Left,
			data: {
				kind: 'mapping',
				label: node.label,
				linkage: node.linkage,
				mappingId: node.id
			}
		};
	});

	const edges: Edge<XyflowDependencyEdgeData>[] = graph.edges
		.filter((edge) => validNodeIds.has(edge.from) && validNodeIds.has(edge.to))
		.map((edge) => {
			const isDependency = edge.kind === 'dependency';
			const edgeHook = edge.hook ?? extractHookFromRootId(edge.from) ?? undefined;
			const lane = !isDependency ? rootLanes.get(`${edge.from}->${edge.to}`) : undefined;
			const rootSourceSlot = !isDependency
				? (rootSourceSlots.get(`${edge.from}->${edge.to}`) ?? 0)
				: 0;
			const strokeColor = edge.missing ? '#dc2626' : isDependency ? '#94a3b8' : '#3b82f6';
			const strokeWidth = isDependency ? 2 : 1.8;
			const dashArray = edge.missing ? '6 4' : '';
			const style = [
				`--xy-edge-stroke:${strokeColor}`,
				`--xy-edge-stroke-width:${strokeWidth}px`,
				dashArray ? `stroke-dasharray:${dashArray}` : ''
			]
				.filter(Boolean)
				.join(';');

			return {
				id: `${edge.kind}:${edge.from}->${edge.to}:${edge.hook ?? 'any'}`,
				source: edge.from,
				target: edge.to,
				class: isDependency
					? 'sf-removable-edge sf-dependency-edge'
					: 'sf-removable-edge sf-hook-root-edge',
				sourceHandle: isDependency
					? DEPENDENCY_SOURCE_HANDLE_ID
					: hookRootSourceHandleId(rootSourceSlot),
				targetHandle: edgeHook ? hookRootTargetHandleId(edgeHook) : DEPENDENCY_TARGET_HANDLE_ID,
				type: isDependency ? 'smoothstep' : 'hookRoot',
				style,
				markerEnd: {
					type: MarkerType.ArrowClosed,
					color: strokeColor
				},
				selectable: true,
				focusable: true,
				deletable: true,
				data: {
					kind: edge.kind,
					missing: edge.missing,
					hook: edgeHook,
					lane
				}
			};
		});

	const layoutSignature = createLayoutSignature(
		graph.nodes.map((node) => node.id),
		graph.edges
	);
	const explicitDependencyEdgeCount = graph.edges.reduce(
		(count, edge) => count + (edge.kind === 'dependency' ? 1 : 0),
		0
	);

	return {
		nodes,
		edges,
		cycleIds: graph.cycleIds,
		explicitDependencyEdgeCount,
		layoutSignature
	};
}

type NodeBounds = {
	top: number;
	bottom: number;
};

type RootFamily = {
	rootIds: string[];
	nodeIds: Set<string>;
	order: number;
};

function nodeDimensions(node: DependencyGraphNode): { width: number; height: number } {
	return node.kind === 'hook_root'
		? { width: HOOK_ROOT_NODE_WIDTH, height: HOOK_ROOT_NODE_HEIGHT }
		: { width: MAPPING_NODE_WIDTH, height: MAPPING_NODE_HEIGHT };
}

function compareHookIds(left: string, right: string): number {
	const leftRank = KNOWN_HOOK_ORDER[left] ?? 1000;
	const rightRank = KNOWN_HOOK_ORDER[right] ?? 1000;
	if (leftRank !== rightRank) return leftRank - rightRank;
	return left.localeCompare(right);
}

function rootOrder(rootId: string): number {
	const hook = extractHookFromRootId(rootId) ?? rootId;
	return KNOWN_HOOK_ORDER[hook] ?? 1000;
}

function compareRootIds(left: string, right: string): number {
	const leftHook = extractHookFromRootId(left) ?? left;
	const rightHook = extractHookFromRootId(right) ?? right;
	return compareHookIds(leftHook, rightHook);
}

function buildRootFamilies(nodes: DependencyGraphNode[], edges: DependencyGraphEdge[]): RootFamily[] {
	const rootIds = nodes
		.filter((node) => node.kind === 'hook_root')
		.map((node) => node.id)
		.sort(compareRootIds);
	if (rootIds.length <= 1) return [];

	const dependencyOutgoing = new Map<string, string[]>();
	for (const edge of edges) {
		if (edge.kind !== 'dependency') continue;
		const targets = dependencyOutgoing.get(edge.from) ?? [];
		targets.push(edge.to);
		dependencyOutgoing.set(edge.from, targets);
	}

	const families: RootFamily[] = rootIds.map((rootId) => {
		const nodeIds = new Set<string>([rootId]);
		const queue = edges
			.filter((edge) => edge.kind === 'hook_root' && edge.from === rootId)
			.map((edge) => edge.to);

		while (queue.length > 0) {
			const nodeId = queue.shift();
			if (!nodeId || nodeIds.has(nodeId)) continue;
			nodeIds.add(nodeId);
			for (const targetId of dependencyOutgoing.get(nodeId) ?? []) {
				queue.push(targetId);
			}
		}

		return {
			rootIds: [rootId],
			nodeIds,
			order: rootOrder(rootId)
		};
	});

	return mergeIntersectingRootFamilies(families);
}

function mergeIntersectingRootFamilies(families: RootFamily[]): RootFamily[] {
	const merged: RootFamily[] = [];
	for (const family of families) {
		const overlaps = merged.filter((candidate) =>
			Array.from(family.nodeIds).some((nodeId) => candidate.nodeIds.has(nodeId))
		);
		if (overlaps.length === 0) {
			merged.push({
				rootIds: [...family.rootIds],
				nodeIds: new Set(family.nodeIds),
				order: family.order
			});
			continue;
		}

		const nextFamily: RootFamily = {
			rootIds: [...family.rootIds],
			nodeIds: new Set(family.nodeIds),
			order: family.order
		};
		for (const overlap of overlaps) {
			nextFamily.rootIds.push(...overlap.rootIds);
			for (const nodeId of overlap.nodeIds) {
				nextFamily.nodeIds.add(nodeId);
			}
			nextFamily.order = Math.min(nextFamily.order, overlap.order);
			merged.splice(merged.indexOf(overlap), 1);
		}
		merged.push(nextFamily);
	}

	return merged.sort((left, right) => left.order - right.order || left.rootIds[0]!.localeCompare(right.rootIds[0]!));
}

function familyBounds(
	family: RootFamily,
	nodesById: Map<string, DependencyGraphNode>,
	nodeCenters: Map<string, NodeCenter>
): NodeBounds | null {
	let top = Number.POSITIVE_INFINITY;
	let bottom = Number.NEGATIVE_INFINITY;
	for (const nodeId of family.nodeIds) {
		const node = nodesById.get(nodeId);
		const center = nodeCenters.get(nodeId);
		if (!node || !center) continue;
		const { height } = nodeDimensions(node);
		top = Math.min(top, center.y - height / 2);
		bottom = Math.max(bottom, center.y + height / 2);
	}

	if (!Number.isFinite(top) || !Number.isFinite(bottom)) {
		return null;
	}

	return { top, bottom };
}

function offsetFamily(
	family: RootFamily,
	nodeCenters: Map<string, NodeCenter>,
	offsetY: number
): void {
	if (offsetY <= 0) return;
	for (const nodeId of family.nodeIds) {
		const center = nodeCenters.get(nodeId);
		if (!center) continue;
		nodeCenters.set(nodeId, {
			x: center.x,
			y: center.y + offsetY
		});
	}
}

function packRootFamilies(
	nodes: DependencyGraphNode[],
	edges: DependencyGraphEdge[],
	nodeCenters: Map<string, NodeCenter>
): Map<string, NodeCenter> {
	const adjusted = new Map(nodeCenters);
	const nodesById = new Map(nodes.map((node) => [node.id, node]));
	const families = buildRootFamilies(nodes, edges);
	if (families.length <= 1) return adjusted;

	let previousBottom: number | null = null;
	for (const family of families) {
		const bounds = familyBounds(family, nodesById, adjusted);
		if (!bounds) continue;
		if (previousBottom === null) {
			previousBottom = bounds.bottom;
			continue;
		}

		const requiredTop = previousBottom + ROOT_FAMILY_VERTICAL_GAP;
		const offsetY = Math.max(0, requiredTop - bounds.top);
		offsetFamily(family, adjusted, offsetY);

		const updatedBounds = familyBounds(family, nodesById, adjusted);
		previousBottom = updatedBounds?.bottom ?? bounds.bottom + offsetY;
	}

	return adjusted;
}

function assignRootEdgeLanes(
	edges: Array<{ from: string; to: string; kind: DependencyEdgeKind; hook?: string }>,
	nodeCenters: Map<string, NodeCenter>
): Map<string, number> {
	const rootTargets = new Map<string, string[]>();
	for (const edge of edges) {
		if (edge.kind !== 'hook_root') continue;
		const list = rootTargets.get(edge.from) ?? [];
		list.push(edge.to);
		rootTargets.set(edge.from, list);
	}

	const sortedRootIds = sortNodeIdsByY(Array.from(rootTargets.keys()), nodeCenters);
	const rootMidpoint = (sortedRootIds.length - 1) / 2;
	const rootBaseLane = new Map<string, number>();
	for (let rootIndex = 0; rootIndex < sortedRootIds.length; rootIndex += 1) {
		const rootId = sortedRootIds[rootIndex]!;
		rootBaseLane.set(rootId, Math.round((rootIndex - rootMidpoint) * ROOT_FAMILY_LANE_STRIDE));
	}

	const laneMap = new Map<string, number>();
	for (const [rootId, targets] of rootTargets) {
		const sortedTargets = sortNodeIdsByY(targets, nodeCenters);
		const midpoint = (sortedTargets.length - 1) / 2;
		for (let index = 0; index < sortedTargets.length; index += 1) {
			const to = sortedTargets[index]!;
			const rootLane = rootBaseLane.get(rootId) ?? 0;
			const localLane = Math.round((index - midpoint) * ROOT_EDGE_LANE_STRIDE);
			const lane = rootLane + localLane;
			laneMap.set(`${rootId}->${to}`, lane);
		}
	}

	return laneMap;
}

function assignRootEdgeSourceSlots(
	edges: Array<{ from: string; to: string; kind: DependencyEdgeKind; hook?: string }>,
	nodeCenters: Map<string, NodeCenter>
): Map<string, number> {
	const rootTargets = new Map<string, string[]>();
	for (const edge of edges) {
		if (edge.kind !== 'hook_root') continue;
		const list = rootTargets.get(edge.from) ?? [];
		list.push(edge.to);
		rootTargets.set(edge.from, list);
	}

	const slots = new Map<string, number>();
	for (const [rootId, targets] of rootTargets) {
		const sortedTargets = sortNodeIdsByY(targets, nodeCenters);
		for (let index = 0; index < sortedTargets.length; index += 1) {
			const to = sortedTargets[index]!;
			slots.set(`${rootId}->${to}`, index);
		}
	}

	return slots;
}

function buildRootSourceHandleCounts(slots: Map<string, number>): Map<string, number> {
	const counts = new Map<string, number>();
	for (const [edgeKey, slot] of slots) {
		const rootId = edgeKey.split('->')[0];
		if (!rootId) continue;
		const count = Math.max(counts.get(rootId) ?? 0, slot + 1);
		counts.set(rootId, count);
	}
	return counts;
}

function sortNodeIdsByY(ids: string[], nodeCenters: Map<string, NodeCenter>): string[] {
	return [...ids].sort((left, right) => {
		const leftY = nodeCenters.get(left)?.y ?? 0;
		const rightY = nodeCenters.get(right)?.y ?? 0;
		if (leftY === rightY) return left.localeCompare(right);
		return leftY - rightY;
	});
}

function createLayoutSignature(
	nodeIds: string[],
	edges: Array<{ from: string; to: string; kind: DependencyEdgeKind; hook?: string }>
): string {
	const nodeSignature = [...nodeIds].sort().join('|');
	const edgeSignature = [...edges]
		.filter((edge) => edge.kind === 'hook_root')
		.map((edge) => `${edge.kind}:${edge.from}->${edge.to}:${edge.hook ?? 'any'}`)
		.sort()
		.join('|');
	return `${nodeSignature}::${edgeSignature}`;
}
