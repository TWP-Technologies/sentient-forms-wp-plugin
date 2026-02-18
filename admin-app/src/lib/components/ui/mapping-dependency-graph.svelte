<script lang="ts">
	import Badge from './badge.svelte';
	import type { FormActionLinkage } from '$lib/api/types';
	import { buildDependencyGraph, getMappingDependencyIds } from '$lib/utils/mapping-dependencies';

	type Props = {
		linkages?: FormActionLinkage[];
		editingMappingId?: string | null;
		draftDependencyIds?: string[];
		onToggleDependency?: (mappingId: string) => void;
	};

	let {
		linkages = [],
		editingMappingId = null,
		draftDependencyIds = [],
		onToggleDependency = () => {}
	}: Props = $props();

	const graph = $derived(buildDependencyGraph(linkages));
	const nodeById = $derived.by(() => new Map(graph.nodes.map((node) => [node.id, node])));
	const canvasWidth = $derived(
		Math.max(420, ...graph.nodes.map((node) => node.x + 260), graph.edges.length > 0 ? 420 : 0)
	);
	const canvasHeight = $derived(
		Math.max(220, ...graph.nodes.map((node) => node.y + 110), graph.edges.length > 0 ? 220 : 0)
	);

	function isEditingTarget(nodeId: string): boolean {
		return editingMappingId === nodeId;
	}

	function isSelectedDependency(nodeId: string): boolean {
		return draftDependencyIds.includes(nodeId);
	}

	function nodeClass(node: FormActionLinkage): string {
		if (isEditingTarget(node.local_mapping_id)) {
			return 'sf:border-blue-500 sf:ring-2 sf:ring-blue-200';
		}
		if (editingMappingId && isSelectedDependency(node.local_mapping_id)) {
			return 'sf:border-emerald-500 sf:bg-emerald-50';
		}
		return 'sf:border-slate-200';
	}

	function toggleDependency(nodeId: string): void {
		if (!editingMappingId || editingMappingId === nodeId) return;
		onToggleDependency(nodeId);
	}

	function edgePath(fromId: string, toId: string): string {
		const from = nodeById.get(fromId);
		const to = nodeById.get(toId);
		if (!from || !to) return '';
		const startX = from.x + 238;
		const startY = from.y + 42;
		const endX = to.x - 6;
		const endY = to.y + 42;
		const curve = Math.max(60, (endX - startX) / 2);
		return `M ${startX} ${startY} C ${startX + curve} ${startY}, ${endX - curve} ${endY}, ${endX} ${endY}`;
	}

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
</script>

<div class="sf:space-y-3" data-testid="dependency-graph">
	<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
		<div>
			<p class="sf:text-sm sf:font-medium sf:text-slate-700">Dependency graph</p>
			<p class="sf:text-xs sf:text-slate-500">
				Run order is left-to-right. Dependents wait for prerequisites.
			</p>
		</div>
		{#if graph.cycleIds.length > 0}
			<Badge variant="danger">Cycle detected</Badge>
		{/if}
	</div>

	{#if editingLabel}
		<p class="sf:text-xs sf:text-slate-600">
			Editing <strong>{editingLabel}</strong>. Click other nodes to toggle dependencies.
		</p>
	{/if}

	{#if graph.nodes.length === 0}
		<p class="sf:text-sm sf:text-slate-500">No mappings yet.</p>
	{:else}
		<div class="sf:relative sf:overflow-x-auto sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50">
			<div class="sf:relative sf:min-h-[220px]" style={`width:${canvasWidth}px; height:${canvasHeight}px;`}>
				<svg class="sf:absolute sf:inset-0" width={canvasWidth} height={canvasHeight}>
					<defs>
						<marker
							id="dependency-arrow"
							markerWidth="10"
							markerHeight="7"
							refX="9"
							refY="3.5"
							orient="auto"
						>
							<polygon points="0 0, 10 3.5, 0 7" class="sf:fill-slate-400" />
						</marker>
					</defs>
					{#each graph.edges as edge (`${edge.from}->${edge.to}`)}
						{@const path = edgePath(edge.from, edge.to)}
						{#if path}
							<path
								d={path}
								fill="none"
								stroke={edge.missing ? '#dc2626' : '#94a3b8'}
								stroke-width="2"
								stroke-dasharray={edge.missing ? '6 4' : '0'}
								marker-end="url(#dependency-arrow)"
							/>
						{/if}
					{/each}
				</svg>

				{#each graph.nodes as node (node.id)}
					<button
						type="button"
						class={`sf:absolute sf:w-[240px] sf:rounded-md sf:border sf:bg-white sf:p-3 sf:shadow-sm sf:text-left ${nodeClass(node.linkage)} ${editingMappingId && editingMappingId !== node.id ? 'sf:cursor-pointer' : ''}`}
						style={`left:${node.x}px; top:${node.y}px;`}
						onclick={() => toggleDependency(node.id)}
						disabled={!editingMappingId || editingMappingId === node.id}
						data-testid={`dependency-node-${node.id}`}
					>
						<div class="sf:flex sf:items-start sf:justify-between sf:gap-2">
							<div>
								<p class="sf:text-sm sf:font-semibold sf:text-slate-800">{node.label}</p>
								<p class="sf:text-[11px] sf:text-slate-500">{node.id}</p>
							</div>
							<Badge variant={node.linkage.is_action_enabled_for_form === false ? 'warning' : 'success'}>
								{node.linkage.is_action_enabled_for_form === false ? 'Disabled' : 'Enabled'}
							</Badge>
						</div>
						<p class="sf:mt-2 sf:text-[11px] sf:text-slate-500">
							{node.linkage.trigger_hooks?.join(', ') || 'No hooks'}
						</p>
						{#if editingMappingId && editingMappingId !== node.id}
							<p class="sf:mt-1 sf:text-[11px] sf:text-slate-500">
								{isSelectedDependency(node.id) ? 'Selected dependency' : 'Click to set as dependency'}
							</p>
						{/if}
					</button>
				{/each}
			</div>
		</div>
	{/if}

	{#if graph.cycleIds.length > 0}
		<p class="sf:text-xs sf:text-red-600">
			Cycle participants: {graph.cycleIds.join(', ')}
		</p>
	{/if}

	{#if editingMappingId}
		<p class="sf:text-xs sf:text-slate-500">
			Current dependencies: {editingCurrentDeps.length > 0 ? editingCurrentDeps.join(', ') : 'none'}
		</p>
	{/if}
</div>
