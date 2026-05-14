<script lang="ts">
	import Card from '$lib/components/ui/card.svelte';
	import type { LeadGrade } from '$lib/api/types';
	import { gradeOrder, gradeTone, gradeWidth } from '$lib/components/lead-scoring/utils';

	type Props = {
		grades?: Record<LeadGrade | 'ungraded', number>;
	};

	let { grades = undefined }: Props = $props();
</script>

<Card data-testid="lead-scoring-grade-distribution">
	<h2 class="sf:text-2xl sf:font-semibold sf:text-slate-950">Grade Distribution</h2>
	<div class="sf:mt-7 sf:space-y-5">
		{#each gradeOrder as grade}
			<div>
				<div class="sf:flex sf:items-center sf:justify-between sf:text-lg">
					<span class="sf:font-medium sf:text-slate-800">{grade}</span>
					<span class="sf:text-slate-500">{grades?.[grade] ?? 0}</span>
				</div>
				<div class="sf:mt-2 sf:h-3 sf:overflow-hidden sf:rounded-full sf:bg-slate-100">
					<div class={`sf:h-full sf:rounded-full ${gradeTone(grade)}`} style={`width: ${gradeWidth(grades, grade)}`}></div>
				</div>
			</div>
		{/each}
	</div>
</Card>
