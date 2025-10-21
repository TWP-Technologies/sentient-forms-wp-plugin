<script lang="ts">
	import { buttonStyles } from '$lib/components/ui/buttonStyles';
	import { createEventDispatcher } from 'svelte';
	import type { HTMLButtonAttributes } from 'svelte/elements';

type Props = {
	variant?: 'primary' | 'secondary' | 'ghost' | 'danger';
	size?: 'sm' | 'md' | 'lg';
	loading?: boolean;
	iconOnly?: boolean;
} & HTMLButtonAttributes;

export let variant: Props['variant'] = 'primary';
export let size: Props['size'] = 'md';
export let loading = false;
export let iconOnly = false;
export let type: HTMLButtonAttributes['type'] = 'button';
export let disabled: boolean | undefined = undefined;

	const dispatch = createEventDispatcher();

	function handleClick(event: MouseEvent) {
		if (loading || disabled) {
			event.preventDefault();
			return;
		}
		dispatch('click', event);
	}
</script>

<button
	type={type}
	class={buttonStyles({ variant, size }) + (iconOnly ? ' sf-px-0 sf-justify-center sf-w-10' : '')}
	on:click={handleClick}
	disabled={disabled || loading}
	aria-busy={loading}
	{...$$restProps}
>
	{#if loading}
		<span class="sf-h-4 sf-w-4 sf-rounded-full sf-border-2 sf-border-white/40 sf-border-t-white sf-animate-spin" aria-hidden="true"></span>
	{/if}
	<slot />
</button>
