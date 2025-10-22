<script lang="ts">
	import { createEventDispatcher } from 'svelte';

	export let id: string;
	export let checked = false;
	export let disabled = false;
	export let label: string | null = null;
	export let description: string | null = null;

	const dispatch = createEventDispatcher<{ change: { checked: boolean } }>();

	function emitChange(next: boolean) {
		checked = next;
		dispatch('change', { checked: next });
	}

	function handleClick() {
		if (disabled) return;
		emitChange(!checked);
	}

	function handleKeydown(event: KeyboardEvent) {
		if (disabled) return;
		if (event.key === ' ' || event.key === 'Enter') {
			event.preventDefault();
			emitChange(!checked);
		}
	}

	$: ariaDescribedBy = description ? `${id}-description` : undefined;
	$: ariaLabelledBy = label ? `${id}-label` : undefined;
</script>

<div class="sf-flex sf-items-start sf-gap-3" data-testid="toggle">
	<button
		type="button"
		id={id}
		role="switch"
		aria-checked={checked}
		aria-describedby={ariaDescribedBy}
		aria-labelledby={ariaLabelledBy}
		aria-label={ariaLabelledBy ? undefined : label ?? 'Toggle setting'}
		class={`sf-relative sf-inline-flex sf-h-6 sf-w-11 sf-items-center sf-rounded-full sf-transition-colors ${
			checked ? 'sf-bg-primary-600' : 'sf-bg-muted-400'
		} ${disabled ? 'sf-opacity-60 sf-cursor-not-allowed' : 'sf-cursor-pointer'}`}
		disabled={disabled}
		on:click={handleClick}
		on:keydown={handleKeydown}
	>
		<span
			aria-hidden="true"
			class={`sf-inline-block sf-h-5 sf-w-5 sf-rounded-full sf-bg-white sf-shadow-card sf-transition-transform ${
				checked ? 'sf-translate-x-5' : 'sf-translate-x-1'
			}`}
		></span>
	</button>
	{#if label}
		<div class="sf-flex sf-flex-col sf-gap-1">
			<span class="sf-text-sm sf-font-medium sf-text-slate-700" id={`${id}-label`}>{label}</span>
			{#if description}
				<span class="sf-text-xs sf-text-slate-500" id={`${id}-description`}>
					{description}
				</span>
			{/if}
		</div>
	{/if}
</div>
