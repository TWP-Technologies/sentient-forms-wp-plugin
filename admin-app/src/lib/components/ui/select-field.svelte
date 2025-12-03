<script lang="ts">
	import FormField from './form-field.svelte';
	import type { HTMLSelectAttributes } from 'svelte/elements';
	import type { SelectOption } from './types';

	interface Props {
		id: string;
		label: string;
		options?: SelectOption[];
		description?: string | null;
		help?: string | null;
		error?: string | null;
		required?: boolean;
		disabled?: boolean;
		name?: string | undefined;
		placeholder?: string | undefined;
		value?: HTMLSelectAttributes['value'];
		selectClass?: string;
		[key: string]: any
	}

	let {
		id,
		label,
		options = [],
		description = null,
		help = null,
		error = null,
		required = false,
		disabled = false,
		name = undefined,
		placeholder = undefined,
		value = $bindable(''),
		selectClass = '',
		...rest
	}: Props = $props();

	const describedBy = [
		description ? `${id}-description` : null,
		help ? `${id}-help` : null,
		error ? `${id}-error` : null
	]
		.filter(Boolean)
		.join(' ') || undefined;
</script>

<FormField {id} {label} {description} {help} {error} {required}>
	<select
		bind:value
		aria-describedby={describedBy}
	aria-invalid={error ? true : undefined}
		class={`sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus:border-primary-500 sf:focus:ring-2 sf:focus:ring-primary-100 sf:disabled:bg-muted-100 sf:disabled:text-muted-400 ${selectClass}`}
		{disabled}
		id={id}
		name={name}
		required={required}
		{...rest}
	>
		{#if placeholder}
			<option value="" disabled hidden>{placeholder}</option>
		{/if}
		{#each options as option}
			<option value={option.value} disabled={option.disabled}>
				{option.label}
			</option>
		{/each}
	</select>
</FormField>
