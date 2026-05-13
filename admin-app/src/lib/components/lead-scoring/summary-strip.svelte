<script lang="ts">
	import BanIcon from '@lucide/svelte/icons/ban';
	import CheckCheckIcon from '@lucide/svelte/icons/check-check';
	import PenLineIcon from '@lucide/svelte/icons/pen-line';
	import StarIcon from '@lucide/svelte/icons/star';
	import type { LeadGrade, LeadValueDashboard } from '$lib/api/types';

	type Props = {
		dashboard: LeadValueDashboard | null;
		gradeTotal: number;
		class?: string;
		testId?: string;
	};

	let {
		dashboard,
		gradeTotal,
		class: className = '',
		testId = 'lead-scoring-summary-strip'
	}: Props = $props();

	const metricItems = $derived([
		{
			label: 'Scored leads',
			value: dashboard?.metrics?.scored_leads ?? gradeTotal,
			help: 'Across all forms',
			icon: CheckCheckIcon,
			iconClass: 'sf:text-primary-500'
		},
		{
			label: 'Priority leads',
			value: dashboard?.metrics?.priority_leads ?? dashboard?.grades?.A ?? 0,
			help: 'A grade or high priority',
			icon: StarIcon,
			iconClass: 'sf:text-amber-500'
		},
		{
			label: 'Follow-up drafts',
			value: dashboard?.metrics?.reply_drafts ?? dashboard?.suggested_replies ?? 0,
			help: 'Replies and next steps',
			icon: PenLineIcon,
			iconClass: 'sf:text-indigo-500'
		},
		{
			label: 'Rejected or low-fit',
			value: dashboard?.metrics?.rejected_leads ?? dashboard?.grades?.Reject ?? 0,
			help: 'Rejected lead submissions',
			icon: BanIcon,
			iconClass: 'sf:text-rose-500'
		}
	]);
</script>

<div
	class={[
		'sf:overflow-hidden sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:shadow-[0_2px_10px_rgba(15,23,42,0.06)] sf:grid sf:grid-cols-[repeat(auto-fit,minmax(min(100%,14rem),1fr))]',
		className
	]
		.filter(Boolean)
		.join(' ')}
	data-testid={testId}
>
	{#each metricItems as item, index (item.label)}
		{@const Icon = item.icon}
		<div
			class={[
				'sf:min-h-28 sf:p-6',
				index === 0 ? 'sf:border-l-4 sf:border-l-primary-500' : '',
				index < metricItems.length - 1 ? 'sf:border-r sf:border-slate-100' : ''
			]
				.filter(Boolean)
				.join(' ')}
		>
			<div class="sf:flex sf:items-center sf:gap-3">
				<Icon class={`sf:h-4 sf:w-4 ${item.iconClass}`} aria-hidden="true" />
				<p class="sf:text-xs sf:font-bold sf:uppercase sf:tracking-[0.08em] sf:text-slate-500">
					{item.label}
				</p>
			</div>
			<p class="sf:mt-4 sf:text-4xl sf:font-bold sf:leading-none sf:text-slate-950">
				{item.value}
			</p>
			<p class="sf:mt-3 sf:text-base sf:text-slate-500">{item.help}</p>
		</div>
	{/each}
</div>
