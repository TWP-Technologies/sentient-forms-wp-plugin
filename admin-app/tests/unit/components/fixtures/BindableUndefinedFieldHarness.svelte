<script lang="ts">
	import InputField from '$lib/components/ui/input-field.svelte';
	import ModelSelect from '$lib/components/ui/model-select.svelte';
	import PromptBuilder from '$lib/components/ui/prompt-builder.svelte';
	import SelectField from '$lib/components/ui/select-field.svelte';
	import SlugGenerator from '$lib/components/ui/slug-generator.svelte';
	import TextareaField from '$lib/components/ui/textarea-field.svelte';
	import Toggle from '$lib/components/ui/toggle.svelte';

	type FieldType = 'input' | 'select' | 'textarea' | 'toggle' | 'model' | 'slug' | 'prompt';

	let { field = 'select' }: { field?: FieldType } = $props();
	let value = $state<string | number | undefined>(undefined);
	let checked = $state<boolean | undefined>(undefined);
	let modelHint = $state<string | null | undefined>(undefined);
	let promptOverrides = $state<Record<string, unknown> | undefined>(undefined);

	const options = [
		{ label: 'Use global default', value: 'inherit' },
		{ label: 'Suppress notifications', value: 'enabled' }
	];
</script>

{#if field === 'input'}
	<InputField id="undefined-input" label="Undefined input" bind:value />
{:else if field === 'textarea'}
	<TextareaField id="undefined-textarea" label="Undefined textarea" bind:value />
{:else if field === 'toggle'}
	<Toggle id="undefined-toggle" label="Undefined toggle" bind:checked />
{:else if field === 'model'}
	<ModelSelect id="undefined-model" bind:value={modelHint} />
{:else if field === 'slug'}
	<SlugGenerator id="undefined-slug" name="Undefined Slug" bind:value />
{:else if field === 'prompt'}
	<PromptBuilder id="undefined-prompt" bind:value={promptOverrides} />
{:else}
	<SelectField id="undefined-select" label="Undefined select" {options} bind:value />
{/if}
