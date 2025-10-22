<script lang="ts">
	import FormField from './form-field.svelte';
	import type { HTMLInputAttributes } from 'svelte/elements';

	export let id: string;
	export let label: string;
	export let description: string | null = null;
	export let help: string | null = null;
	export let error: string | null = null;
	export let required = false;
	export let disabled = false;
	export let type: HTMLInputAttributes['type'] = 'text';
	export let placeholder: string | undefined;
export let autocomplete: HTMLInputAttributes['autocomplete'] | undefined = undefined;
export let inputClass = '';
export let name: string | undefined = undefined;
	export let value: HTMLInputAttributes['value'] = '';

	const describedBy = [
		description ? `${id}-description` : null,
		help ? `${id}-help` : null,
		error ? `${id}-error` : null
	]
		.filter(Boolean)
		.join(' ') || undefined;
</script>

<FormField {id} {label} {description} {help} {error} {required}>
	<input
		bind:value
		aria-describedby={describedBy}
		aria-invalid={error ? true : undefined}
		autocomplete={autocomplete}
		class={`sf-w-full sf-rounded sf-border sf-border-slate-300 sf-bg-white sf-px-3 sf-py-2 sf-text-sm focus:sf-border-primary-500 focus:sf-ring-2 focus:sf-ring-primary-100 disabled:sf-bg-muted-100 disabled:sf-text-muted-400 ${inputClass}`}
		{disabled}
		id={id}
		name={name}
		placeholder={placeholder}
		required={required}
		type={type}
		{...$$restProps}
	/>
</FormField>
