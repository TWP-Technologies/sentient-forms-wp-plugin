<script lang="ts">
	import FormField from './form-field.svelte';
	import type { HTMLInputAttributes } from 'svelte/elements';

	interface Props {
		id: string;
		label: string;
		description?: string | null;
		help?: string | null;
		error?: string | null;
		required?: boolean;
		disabled?: boolean;
		type?: HTMLInputAttributes['type'];
		placeholder: string | undefined;
		autocomplete?: HTMLInputAttributes['autocomplete'] | undefined;
		inputClass?: string;
		name?: string | undefined;
		value?: HTMLInputAttributes['value'];
		[key: string]: any
	}

	let {
		id,
		label,
		description = null,
		help = null,
		error = null,
		required = false,
		disabled = false,
		type = 'text',
		placeholder,
		autocomplete = undefined,
		inputClass = '',
		name = undefined,
		value = $bindable(''),
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
	<input
		bind:value
		aria-describedby={describedBy}
		aria-invalid={error ? true : undefined}
		autocomplete={autocomplete}
		class={`sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus:border-primary-500 sf:focus:ring-2 sf:focus:ring-primary-100 sf:disabled:bg-muted-100 sf:disabled:text-muted-400 ${inputClass}`}
		{disabled}
		id={id}
		name={name}
		placeholder={placeholder}
		required={required}
		type={type}
		{...rest}
	/>
</FormField>
