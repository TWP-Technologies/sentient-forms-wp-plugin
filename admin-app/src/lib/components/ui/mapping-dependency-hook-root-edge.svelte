<script lang="ts">
	import { BaseEdge, Position, getBezierPath, type EdgeProps } from '@xyflow/svelte';

	let {
		id,
		data,
		markerEnd,
		markerStart,
		interactionWidth,
		style,
		sourceX,
		sourceY,
		targetX,
		targetY
	}: EdgeProps = $props();

	const laneOffset = $derived.by(() => {
		const lane = Number.isFinite(data?.lane) ? Number(data?.lane ?? 0) : 0;
		return Math.max(-96, Math.min(96, lane));
	});

	const edgePath = $derived.by(() => {
		const sourceYOffset = laneOffset * 0.18;
		const targetYOffset = laneOffset * 0.1;
		const [path] = getBezierPath({
			sourceX,
			sourceY: sourceY + sourceYOffset,
			sourcePosition: Position.Right,
			targetX,
			targetY: targetY + targetYOffset,
			targetPosition: Position.Left,
			curvature: 0.28
		});
		return path;
	});
</script>

<BaseEdge
	{id}
	path={edgePath}
	{style}
	{markerStart}
	{markerEnd}
	interactionWidth={interactionWidth ?? 10}
/>
