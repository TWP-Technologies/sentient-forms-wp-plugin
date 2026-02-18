<script lang="ts">
	import Badge from './badge.svelte';
	import Button from './button.svelte';
	import type { FormActionLinkage } from '$lib/api/types';
	import { buildDependencyGraph, getMappingDependencyIds } from '$lib/utils/mapping-dependencies';

	type Props = {
		linkages?: FormActionLinkage[];
		editingMappingId?: string | null;
		draftDependencyIds?: string[];
		onToggleDependency?: (mappingId: string) => void;
		pendingRemovalId?: string | null;
		onConfigureMapping?: (linkage: FormActionLinkage) => void;
		onToggleMappingEnabled?: (linkage: FormActionLinkage) => void | Promise<void>;
		onRequestRemoveMapping?: (linkage: FormActionLinkage) => void;
		onConfirmRemoveMapping?: (linkage: FormActionLinkage) => void | Promise<void>;
		onCancelRemoveMapping?: () => void;
	};

	const NODE_WIDTH = 320;
	const NODE_HEIGHT = 168;

	let {
		linkages = [],
		editingMappingId = null,
		draftDependencyIds = [],
		onToggleDependency = () => {},
		pendingRemovalId = null,
		onConfigureMapping = () => {},
		onToggleMappingEnabled = () => {},
		onRequestRemoveMapping = () => {},
		onConfirmRemoveMapping = () => {},
		onCancelRemoveMapping = () => {}
	}: Props = $props();

	const graph = $derived(buildDependencyGraph(linkages));
	const nodeById = $derived.by(() => new Map(graph.nodes.map((node) => [node.id, node])));
	const explicitEdgeCount = $derived(
		graph.edges.reduce((count, edge) => count + (edge.kind === 'dependency' ? 1 : 0), 0)
	);
	const canvasWidth = $derived(
		Math.max(
			520,
			...graph.nodes.map((node) => node.x + NODE_WIDTH + 48),
			graph.edges.length > 0 ? 520 : 0
		)
	);
	const canvasHeight = $derived(
		Math.max(
			260,
			...graph.nodes.map((node) => node.y + NODE_HEIGHT + 28),
			graph.edges.length > 0 ? 260 : 0
		)
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
		const startX = from.x + NODE_WIDTH + 8;
		const startY = from.y + NODE_HEIGHT / 2;
		const endX = to.x - 8;
		const endY = to.y + NODE_HEIGHT / 2;
		const curve = Math.max(80, (endX - startX) / 2);
		return `M ${startX} ${startY} C ${startX + curve} ${startY}, ${endX - curve} ${endY}, ${endX} ${endY}`;
	}

	function isNodeEnabled(linkage: FormActionLinkage): boolean {
		return linkage.is_action_enabled_for_form !== false;
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
		<div
			class="sf:relative sf:overflow-x-auto sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50"
		>
			<div
				class="sf:relative sf:min-h-[260px]"
				style={`width:${canvasWidth}px; height:${canvasHeight}px;`}
			>
				<svg
					class="sf:absolute sf:inset-0 sf:pointer-events-none"
					width={canvasWidth}
					height={canvasHeight}
				>
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
								stroke={edge.missing
									? '#dc2626'
									: edge.kind === 'dependency'
										? '#94a3b8'
										: '#cbd5e1'}
								stroke-width={edge.kind === 'dependency' ? '2' : '1.5'}
								stroke-dasharray={edge.missing ? '6 4' : edge.kind === 'execution' ? '5 4' : '0'}
								marker-end="url(#dependency-arrow)"
							/>
						{/if}
					{/each}
				</svg>

				{#each graph.nodes as node (node.id)}
					<div
						class={`sf:absolute sf:z-10 sf:w-[320px] sf:rounded-md sf:border sf:bg-white sf:p-3 sf:shadow-sm sf:text-left sf:space-y-2 ${nodeClass(node.linkage)}`}
						style={`left:${node.x}px; top:${node.y}px;`}
						data-testid={`dependency-node-card-${node.id}`}
					>
						<div class="sf:flex sf:items-start sf:justify-between sf:gap-2">
							<div>
								<p class="sf:text-sm sf:font-semibold sf:text-slate-800">{node.label}</p>
								<p class="sf:text-[11px] sf:text-slate-500">{node.id}</p>
							</div>
							<Badge variant={isNodeEnabled(node.linkage) ? 'success' : 'warning'}>
								{isNodeEnabled(node.linkage) ? 'Enabled' : 'Disabled'}
							</Badge>
						</div>
						<p class="sf:mt-2 sf:text-[11px] sf:text-slate-500">
							{node.linkage.trigger_hooks?.join(', ') || 'No hooks'}
						</p>
						{#if editingMappingId && editingMappingId !== node.id}
							<Button
								size="sm"
								variant={isSelectedDependency(node.id) ? 'secondary' : 'ghost'}
								onclick={() => toggleDependency(node.id)}
								data-testid={`dependency-node-${node.id}`}
							>
								{isSelectedDependency(node.id) ? 'Remove dependency' : 'Add dependency'}
							</Button>
						{/if}
						{#if editingMappingId && editingMappingId !== node.id}
							<p class="sf:mt-1 sf:text-[11px] sf:text-slate-500">
								{isSelectedDependency(node.id)
									? 'Selected dependency'
									: 'Click to set as dependency'}
							</p>
						{/if}
						<div class="sf:flex sf:flex-wrap sf:gap-2 sf:pt-1 sf:border-t sf:border-slate-100">
							<Button
								size="sm"
								variant="ghost"
								onclick={() => onConfigureMapping(node.linkage)}
								data-testid={`dependency-node-configure-${node.id}`}
							>
								Configure
							</Button>
							<Button
								size="sm"
								variant="ghost"
								onclick={() => onToggleMappingEnabled(node.linkage)}
								data-testid={`dependency-node-toggle-enabled-${node.id}`}
							>
								{isNodeEnabled(node.linkage) ? 'Disable' : 'Enable'}
							</Button>
							{#if pendingRemovalId === node.id}
								<Button
									size="sm"
									variant="danger"
									onclick={() => onConfirmRemoveMapping(node.linkage)}
									data-testid={`dependency-node-remove-confirm-${node.id}`}
								>
									Confirm remove
								</Button>
								<Button
									size="sm"
									variant="secondary"
									onclick={() => onCancelRemoveMapping()}
									data-testid={`dependency-node-remove-cancel-${node.id}`}
								>
									Cancel
								</Button>
							{:else}
								<Button
									size="sm"
									variant="ghost"
									onclick={() => onRequestRemoveMapping(node.linkage)}
									data-testid={`dependency-node-remove-${node.id}`}
								>
									Remove
								</Button>
							{/if}
						</div>
					</div>
				{/each}
			</div>
		</div>
	{/if}
	{#if graph.nodes.length > 1 && explicitEdgeCount === 0}
		<p class="sf:text-xs sf:text-slate-500">
			No explicit dependency links configured yet. Showing execution-order connectors left-to-right.
		</p>
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
