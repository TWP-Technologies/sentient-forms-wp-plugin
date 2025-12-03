<script lang="ts">
	import FormField from './form-field.svelte';
	import type { HTMLTextareaAttributes } from 'svelte/elements';

	interface Props {
		id: string;
		label: string;
		description?: string | null;
		help?: string | null;
		error?: string | null;
		required?: boolean;
		disabled?: boolean;
		name?: string | undefined;
		placeholder?: string | undefined;
		rows?: HTMLTextareaAttributes['rows'];
		textareaClass?: string;
		value?: HTMLTextareaAttributes['value'];
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
		name = undefined,
		placeholder = undefined,
		rows = 4,
		textareaClass = '',
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
	<textarea
		bind:value
		aria-describedby={describedBy}
		aria-invalid={error ? true : undefined}
		class={`sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus:border-primary-500 sf:focus:ring-2 sf:focus:ring-primary-100 sf:disabled:bg-muted-100 sf:disabled:text-muted-400 ${textareaClass}`}
		{disabled}
		id={id}
		name={name}
		placeholder={placeholder}
		required={required}
		rows={rows}
		{...rest}
	></textarea>
</FormField>
