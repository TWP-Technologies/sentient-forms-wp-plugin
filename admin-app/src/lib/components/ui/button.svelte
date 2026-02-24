<script lang="ts">
	import { buttonStyles, type ButtonVariant } from '$lib/components/ui/buttonStyles';
	import type { HTMLButtonAttributes } from 'svelte/elements';

type Props = {
	variant?: ButtonVariant;
	size?: 'sm' | 'md' | 'lg';
	loading?: boolean;
	iconOnly?: boolean;
} & HTMLButtonAttributes & { children?: () => unknown };

	let {
		variant = 'primary',
		size = 'md',
		loading = false,
		iconOnly = false,
		class: className = '',
		type = 'button',
		disabled = undefined,
		onclick: userOnClick,
		children,
		...rest
	}: Props = $props();

	type ButtonClickEvent = MouseEvent & { currentTarget: EventTarget & HTMLButtonElement };

	let isDisabled = $derived(Boolean(disabled) || loading);

	function handleClick(event: ButtonClickEvent) {
		if (isDisabled) {
			event.preventDefault();
			event.stopPropagation();
			return;
		}

		userOnClick?.(event);
	}
</script>

<button
	{type}
	class={[
		buttonStyles({ variant, size }),
		iconOnly ? 'sf:px-0 sf:justify-center sf:w-10' : '',
		className
	]
		.filter(Boolean)
		.join(' ')}
	onclick={handleClick}
	disabled={isDisabled}
	aria-busy={loading}
	{...rest}
>
	{#if loading}
		<span class="sf:h-4 sf:w-4 sf:rounded-full sf:border-2 sf:border-white/40 sf:border-t-white sf:animate-spin" aria-hidden="true"></span>
	{/if}
	{@render children?.()}
</button>
