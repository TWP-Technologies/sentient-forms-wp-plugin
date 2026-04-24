<!--
  ModelSelect.svelte - Model selection dropdown with static options
  TODO: Future enhancement - fetch from the local provider model registry
-->
<script lang="ts">
	interface Props {
		/** Currently selected model hint */
		value?: string | null;
		/** Callback when selection changes */
		onchange?: (value: string | null) => void;
		/** Input ID for label association */
		id?: string;
	}

	let { value = $bindable(), onchange, id = 'model-select' }: Props = $props();

	// Static model options - TODO: fetch from the local provider model registry when available
	const modelOptions = [
		{ value: '', label: 'Default (template decides)' },
		{ value: 'gemini-3-pro-preview', label: 'Gemini 3 Pro (Preview)' },
		{ value: 'gemini-3-flash-preview', label: 'Gemini 3 Flash (Preview)' },
		{ value: 'gemini-2.5-pro', label: 'Gemini 2.5 Pro' },
		{ value: 'gemini-2.5-flash', label: 'Gemini 2.5 Flash' },
		{ value: 'custom', label: 'Custom model...' }
	] as const;

	let showCustomInput = $state(false);
	let customValue = $state('');

	// Check if current value is a custom model
	$effect(() => {
		const currentValue = value ?? null;
		const isKnownModel = modelOptions.some((opt) => opt.value === currentValue || opt.value === '');
		if (currentValue && !isKnownModel) {
			showCustomInput = true;
			customValue = currentValue;
		}
	});

	function handleSelectChange(event: Event) {
		const select = event.target as HTMLSelectElement;
		const selected = select.value;

		if (selected === 'custom') {
			showCustomInput = true;
			// Keep current custom value if exists
		} else {
			showCustomInput = false;
			customValue = '';
			const newValue = selected === '' ? null : selected;
			value = newValue;
			onchange?.(newValue);
		}
	}

	function handleCustomInput(event: Event) {
		const input = event.target as HTMLInputElement;
		customValue = input.value;
		const newValue = input.value.trim() || null;
		value = newValue;
		onchange?.(newValue);
	}

	// Determine which option to show as selected
	const selectValue = $derived.by(() => {
		if (showCustomInput) return 'custom';
		const currentValue = value ?? null;
		if (!currentValue) return '';
		const known = modelOptions.find((opt) => opt.value === currentValue);
		return known ? known.value : 'custom';
	});
</script>

<div class="sf:flex sf:flex-col sf:gap-1">
	<label for={id} class="sf:text-sm sf:font-medium sf:text-slate-700">Model Hint</label>
	<select
		{id}
		value={selectValue}
		onchange={handleSelectChange}
		class="sf:rounded-md sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:text-sm
			   sf:bg-white sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
	>
		{#each modelOptions as option}
			<option value={option.value}>{option.label}</option>
		{/each}
	</select>

	{#if showCustomInput}
		<input
			type="text"
			value={customValue}
			oninput={handleCustomInput}
			placeholder="e.g., models/gemini-pro"
			class="sf:rounded-md sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:text-sm sf:font-mono
				   sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
		/>
	{/if}
	<p class="sf:text-xs sf:text-slate-500">Optional: override the default model for this action.</p>
</div>
