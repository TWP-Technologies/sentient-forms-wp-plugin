<script lang="ts">
	import type { Snippet } from 'svelte';

	interface Props {
		children?: Snippet;
		class?: string;
		testId?: string;
		align?: 'between' | 'end';
	}

	let {
		children,
		class: className = '',
		testId = 'sticky-action-footer',
		align = 'end'
	}: Props = $props();

	const justifyClass = $derived(align === 'between' ? 'sf:justify-between' : 'sf:justify-end');
</script>

<footer
	class={`sf-sticky-action-footer sf:shrink-0 sf:border-t sf:border-slate-200 sf:bg-white/95 sf:px-4 sf:py-3 sf:shadow-[0_-8px_18px_rgba(15,23,42,0.08)] sf:backdrop-blur sf:sm:px-6 ${className}`.trim()}
	data-testid={testId}
>
	<div class={`sf:flex sf:flex-wrap sf:items-center sf:gap-2 ${justifyClass}`}>
		{@render children?.()}
	</div>
</footer>

<style>
	.sf-sticky-action-footer {
		position: sticky;
		bottom: 0;
		z-index: 20;
		padding-bottom: max(0.75rem, env(safe-area-inset-bottom));
	}
</style>
