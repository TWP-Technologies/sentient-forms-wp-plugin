<script lang="ts">
	import FormField from './form-field.svelte';
	import type { HTMLSelectAttributes } from 'svelte/elements';
	import type { SelectOption } from './types';

	export let id: string;
	export let label: string;
	export let options: SelectOption[] = [];
	export let description: string | null = null;
	export let help: string | null = null;
	export let error: string | null = null;
	export let required = false;
	export let disabled = false;
	export let name: string | undefined = undefined;
	export let placeholder: string | undefined = undefined;
	export let value: HTMLSelectAttributes['value'] = '';
	export let selectClass = '';

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
		class={`sf-w-full sf-rounded sf-border sf-border-slate-300 sf-bg-white sf-px-3 sf-py-2 sf-text-sm focus:sf-border-primary-500 focus:sf-ring-2 focus:sf-ring-primary-100 disabled:sf-bg-muted-100 disabled:sf-text-muted-400 ${selectClass}`}
		{disabled}
		id={id}
		name={name}
		required={required}
		{...$$restProps}
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
