<script lang="ts">
	import { Handle, Position, type NodeProps } from '@xyflow/svelte';
	import Badge from './badge.svelte';
	import type { MappingDependencyGraphHookRootNodeData } from './mapping-dependency-graph.types';
	import { hookRootSourceHandleId } from '$lib/utils/mapping-dependency-xyflow';

	type Props = NodeProps & {
		data: MappingDependencyGraphHookRootNodeData;
	};

	let { data }: Props = $props();

	function rootSourceHandleStyle(index: number, total: number): string {
		const span = Math.max(total - 1, 1);
		const ratio = total <= 1 ? 0.5 : index / span;
		const topPercent = 30 + ratio * 40;
		return `top:${topPercent}%; width:18px; height:18px; background:#2563eb; border:2px solid #ffffff; box-shadow:0 0 0 1px #1d4ed8; z-index:5; pointer-events:all; cursor:crosshair;`;
	}

	const sourceHandleIds = $derived.by(() => {
		const provided = data.sourceHandleIds ?? [];
		if (provided.length > 0) {
			return Array.from(new Set(provided));
		}
		return [hookRootSourceHandleId(0)];
	});
</script>

{#each sourceHandleIds as handleId, index (handleId)}
	<Handle
		type="source"
		position={Position.Right}
		id={handleId}
		isConnectableStart={data.canStartConnection}
		isConnectableEnd={false}
		style={rootSourceHandleStyle(index, sourceHandleIds.length)}
	/>
{/each}

<div
	class="sf:w-[220px] sf:rounded-md sf:border sf:border-blue-300 sf:bg-blue-50 sf:px-3 sf:py-2 sf:shadow-sm sf:space-y-1"
	data-testid={`dependency-hook-root-${data.hook}`}
>
	<div class="sf:flex sf:items-center sf:justify-between sf:gap-2">
		<p class="sf:text-sm sf:font-semibold sf:text-blue-900">{data.hookLabel}</p>
		<Badge variant="info">Root</Badge>
	</div>
	<p class="sf:text-[11px] sf:text-blue-700">
		{#if data.canStartConnection}
			Drag from the right blue handle into an action to make it autonomous in this hook.
		{:else}
			Autonomous mappings in this hook start from here.
		{/if}
	</p>
</div>
