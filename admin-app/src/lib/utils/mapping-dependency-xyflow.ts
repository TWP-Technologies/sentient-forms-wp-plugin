import dagre from '@dagrejs/dagre';
import { MarkerType, Position, type Edge, type Node } from '@xyflow/svelte';
import type { FormActionLinkage } from '$lib/api/types';
import { buildDependencyGraph } from '$lib/utils/mapping-dependencies';

const MAPPING_NODE_WIDTH = 320;
const MAPPING_NODE_HEIGHT = 184;
const HOOK_ROOT_NODE_WIDTH = 220;
const HOOK_ROOT_NODE_HEIGHT = 92;
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

	const rootLanes = assignRootEdgeLanes(graph.edges, nodeCenters);
	const rootSourceSlots = assignRootEdgeSourceSlots(graph.edges, nodeCenters);
	const rootSourceCounts = buildRootSourceHandleCounts(rootSourceSlots);

	const nodes: Node<XyflowDependencyNodeData>[] = graph.nodes.map((node) => {
		const center = nodeCenters.get(node.id);
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
				`stroke:${strokeColor}`,
				`stroke-width:${strokeWidth}`,
				dashArray ? `stroke-dasharray:${dashArray}` : ''
			]
				.filter(Boolean)
				.join(';');

			return {
				id: `${edge.kind}:${edge.from}->${edge.to}:${edge.hook ?? 'any'}`,
				source: edge.from,
				target: edge.to,
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
				selectable: isDependency,
				focusable: isDependency,
				deletable: isDependency,
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
		.map((edge) => `${edge.kind}:${edge.from}->${edge.to}:${edge.hook ?? 'any'}`)
		.sort()
		.join('|');
	return `${nodeSignature}::${edgeSignature}`;
}
