<script lang="ts">
	import type { Snippet } from 'svelte';
	import type { HTMLAttributes } from 'svelte/elements';
	import { getPopoverContext } from './context';

	type Props = HTMLAttributes<HTMLDivElement> & {
		align?: 'start' | 'center' | 'end';
		children?: Snippet;
	};

	let { align = 'start', class: className = '', children, ...rest }: Props = $props();
	const context = getPopoverContext();

	const alignClass = $derived(
		align === 'end'
			? 'sf:right-0'
			: align === 'center'
				? 'sf:left-1/2 sf:-translate-x-1/2'
				: 'sf:left-0'
	);
</script>

{#if context.open}
	<div
		class={[
			'sf:absolute sf:top-full sf:z-[1300] sf:mt-2 sf:w-[min(18rem,calc(100vw-3rem))] sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:text-sm sf:leading-5 sf:text-slate-700 sf:shadow-xl',
			alignClass,
			className
		]
			.filter(Boolean)
			.join(' ')}
		{...rest}
	>
		{@render children?.()}
	</div>
{/if}
