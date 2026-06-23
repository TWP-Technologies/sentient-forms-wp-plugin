<script lang="ts">
	import type { Snippet } from 'svelte';
	import type { HTMLButtonAttributes } from 'svelte/elements';
	import { getPopoverContext } from './context';

	type Props = HTMLButtonAttributes & {
		children?: Snippet;
	};

	let { children, onclick, type = 'button', ...rest }: Props = $props();
	const context = getPopoverContext();

	function handleClick(event: MouseEvent & { currentTarget: EventTarget & HTMLButtonElement }): void {
		onclick?.(event);
		if (event.defaultPrevented) return;
		context.toggle();
	}
</script>

<button
	{type}
	aria-expanded={context.open}
	data-popover-trigger
	onclick={handleClick}
	{...rest}
>
	{@render children?.()}
</button>
