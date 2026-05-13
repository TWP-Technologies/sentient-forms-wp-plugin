<script lang="ts">
	import InfoIcon from '@lucide/svelte/icons/info';
	import Button from './button.svelte';
	import type { Snippet } from 'svelte';

	type Props = {
		label: string;
		side?: 'top' | 'bottom' | 'left' | 'right';
		triggerLabel?: string;
		class?: string;
		children?: Snippet;
	};

	let {
		label,
		side = 'top',
		triggerLabel = 'More information',
		class: className = '',
		children
	}: Props = $props();

	const positionClass = $derived(
		side === 'bottom'
			? 'sf:left-1/2 sf:top-full sf:mt-2 sf:-translate-x-1/2'
			: side === 'left'
				? 'sf:right-full sf:top-1/2 sf:mr-2 sf:-translate-y-1/2'
				: side === 'right'
					? 'sf:left-full sf:top-1/2 sf:ml-2 sf:-translate-y-1/2'
					: 'sf:bottom-full sf:left-1/2 sf:mb-2 sf:-translate-x-1/2'
	);
</script>

<span class="sf:relative sf:inline-flex sf:items-center sf:align-middle sf:group">
	<Button
		variant="secondary"
		size="xs"
		iconOnly
		class={[
			'sf:rounded-full sf:border-slate-300 sf:p-0 sf:text-slate-500 sf:hover:border-slate-400 sf:hover:text-primary-700',
			className
		].filter(Boolean).join(' ')}
		aria-label={triggerLabel}
	>
		<InfoIcon class="sf:h-3 sf:w-3" aria-hidden="true" />
	</Button>
	<span
		role="tooltip"
		class={`sf:pointer-events-none sf:absolute ${positionClass} sf:z-[1000000] sf:w-max sf:max-w-xs sf:rounded-md sf:border sf:border-slate-800 sf:bg-slate-950 sf:px-3 sf:py-2 sf:text-xs sf:font-normal sf:leading-relaxed sf:text-white sf:opacity-0 sf:shadow-xl sf:transition sf:duration-150 sf:group-focus-within:opacity-100 sf:group-hover:opacity-100`}
	>
		{#if children}
			{@render children()}
		{:else}
			{label}
		{/if}
	</span>
</span>
