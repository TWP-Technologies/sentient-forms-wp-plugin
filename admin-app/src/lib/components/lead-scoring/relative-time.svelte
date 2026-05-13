<script lang="ts">
	import { SvelteDate } from 'svelte/reactivity';
	import Button from '$lib/components/ui/button.svelte';
	import { absoluteDateTime, relativeDateTime } from '$lib/components/lead-scoring/utils';

	type Props = {
		value?: string | null;
		class?: string;
	};

	let { value = null, class: className = '' }: Props = $props();

	const now = new SvelteDate();
	const label = $derived(relativeDateTime(value, now.getTime()));
	const absolute = $derived(absoluteDateTime(value));

	$effect(() => {
		const interval = window.setInterval(() => {
			now.setTime(Date.now());
		}, 1000);
		return () => window.clearInterval(interval);
	});
</script>

{#if label}
	<span class="sf:relative sf:inline-flex sf:min-w-max sf:items-center sf:group">
		<Button
			variant="inline"
			size="sm"
			class={[
				'sf:inline-flex sf:min-w-max sf:cursor-help sf:items-center sf:whitespace-nowrap sf:border-0 sf:border-b sf:border-dotted sf:border-slate-300 sf:bg-transparent sf:p-0 sf:text-current sf:decoration-slate-300 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-2',
				className
			]
				.filter(Boolean)
				.join(' ')}
		>
			{label}
		</Button>
		{#if absolute}
			<span
				role="tooltip"
				class="sf:pointer-events-none sf:absolute sf:bottom-full sf:left-1/2 sf:z-[1000000] sf:mb-2 sf:w-max sf:max-w-xs sf:-translate-x-1/2 sf:rounded-md sf:border sf:border-slate-800 sf:bg-slate-950 sf:px-3 sf:py-2 sf:text-xs sf:font-normal sf:text-white sf:opacity-0 sf:shadow-xl sf:transition sf:duration-150 sf:group-focus-within:opacity-100 sf:group-hover:opacity-100"
			>
				{absolute}
			</span>
		{/if}
	</span>
{/if}
