<script lang="ts">
	import type { HTMLButtonAttributes } from 'svelte/elements';

	type ToggleEvent = CustomEvent<{ checked: boolean }>;
	type Props = {
		id?: string;
		checked?: boolean;
		disabled?: boolean;
	label?: string | null;
	description?: string | null;
	descriptionClass?: string;
	onchange?: (event: ToggleEvent) => void;
} & Omit<HTMLButtonAttributes, 'type' | 'role'>;

	const autoId = $props.id();
	let {
		id = autoId,
		checked = $bindable(),
	disabled = false,
	label = null,
	description = null,
	descriptionClass = 'sf:text-slate-500',
	onchange,
	...rest
}: Props = $props();

	let ariaDescribedBy = $derived(description ? `${id}-description` : undefined);
	let ariaLabelledBy = $derived(label ? `${id}-label` : undefined);
	let isChecked = $derived(checked === true);
	let thumbStyle = $derived(`transform: translateX(${isChecked ? '1.25rem' : '0'});`);

	function emitChange(next: boolean) {
		checked = next;
		onchange?.(new CustomEvent('change', { detail: { checked: next } }));
	}

	function handleClick() {
		if (disabled) return;
		emitChange(!isChecked);
	}

	function handleKeydown(event: KeyboardEvent) {
		if (disabled) return;
		if (event.key === ' ' || event.key === 'Enter') {
			event.preventDefault();
			emitChange(!isChecked);
		}
	}
</script>

<div class="sf:flex sf:items-start sf:gap-3" data-testid="toggle">
	<button
		{...rest}
		type="button"
		id={id}
		role="switch"
		aria-checked={isChecked}
		aria-describedby={ariaDescribedBy}
		aria-labelledby={ariaLabelledBy}
		aria-label={ariaLabelledBy ? undefined : label ?? 'Toggle setting'}
		class="sf:relative sf:inline-flex sf:h-6 sf:w-11 sf:shrink-0 sf:items-center sf:rounded-full sf:border-2 sf:border-transparent sf:transition-colors sf:duration-200 sf:ease-in-out sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-2 {isChecked
			? 'sf:bg-primary-600'
			: 'sf:bg-slate-300'} {disabled
			? 'sf:opacity-50 sf:cursor-not-allowed'
			: 'sf:cursor-pointer'}"
		disabled={disabled}
		onclick={handleClick}
		onkeydown={handleKeydown}
	>
		<span
			aria-hidden="true"
			class="sf:pointer-events-none sf:inline-block sf:h-5 sf:w-5 sf:rounded-full sf:bg-white sf:shadow-md sf:ring-0 sf:transition-transform sf:duration-200 sf:ease-in-out"
			style={thumbStyle}
		></span>
	</button>
	{#if label}
		<div class="sf:flex sf:flex-col sf:gap-1">
			<span class="sf:text-sm sf:font-medium sf:text-slate-700" id={`${id}-label`}>{label}</span>
			{#if description}
				<span class={`sf:text-xs ${descriptionClass}`} id={`${id}-description`}>
					{description}
				</span>
			{/if}
		</div>
	{/if}
</div>
