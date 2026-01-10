<!--
  PromptBuilder.svelte - Hybrid UI/JSON editor for prompt overrides
  Toggles between form mode (key-value pairs) and JSON mode (raw textarea)
-->
<script lang="ts">
	import { parsePromptOverridesInput } from '$lib/utils/custom-actions';

	interface Props {
		/** Current value as a record */
		value: Record<string, unknown>;
		/** Callback when value changes */
		onchange?: (value: Record<string, unknown>) => void;
		/** Input ID prefix for label association */
		id?: string;
	}

	let { value = $bindable({}), onchange, id = 'prompt-builder' }: Props = $props();

	type EditorMode = 'form' | 'json';
	let mode = $state<EditorMode>('form');
	let jsonText = $state('');
	let jsonError = $state<string | null>(null);
	let formPairs = $state<Array<{ key: string; value: string }>>([]);

	// Initialize from value prop
	$effect(() => {
		if (Object.keys(value).length > 0 && formPairs.length === 0) {
			formPairs = Object.entries(value).map(([key, val]) => ({
				key,
				value: typeof val === 'string' ? val : JSON.stringify(val)
			}));
			jsonText = JSON.stringify(value, null, 2);
		}
	});

	function switchToJson() {
		// Sync form pairs to JSON
		const obj = formPairsToObject();
		jsonText = JSON.stringify(obj, null, 2);
		jsonError = null;
		mode = 'json';
	}

	function switchToForm() {
		// Try to parse JSON back to form
		const { result, error } = parsePromptOverridesInput(jsonText);
		if (error) {
			jsonError = error;
			return; // Don't switch if invalid
		}
		formPairs = Object.entries(result || {}).map(([key, val]) => ({
			key,
			value: typeof val === 'string' ? val : JSON.stringify(val)
		}));
		jsonError = null;
		mode = 'form';
	}

	function formPairsToObject(): Record<string, unknown> {
		const obj: Record<string, unknown> = {};
		for (const pair of formPairs) {
			if (pair.key.trim()) {
				// Try to parse value as JSON, fallback to string
				try {
					obj[pair.key.trim()] = JSON.parse(pair.value);
				} catch {
					obj[pair.key.trim()] = pair.value;
				}
			}
		}
		return obj;
	}

	function updateFromForm() {
		const obj = formPairsToObject();
		value = obj;
		onchange?.(obj);
	}

	function updateFromJson() {
		const { result, error } = parsePromptOverridesInput(jsonText);
		if (error) {
			jsonError = error;
			return;
		}
		jsonError = null;
		value = result || {};
		onchange?.(result || {});
	}

	function addPair() {
		formPairs = [...formPairs, { key: '', value: '' }];
	}

	function removePair(index: number) {
		formPairs = formPairs.filter((_, i) => i !== index);
		updateFromForm();
	}

	function updatePair(index: number, field: 'key' | 'value', newValue: string) {
		formPairs = formPairs.map((pair, i) => (i === index ? { ...pair, [field]: newValue } : pair));
		updateFromForm();
	}
</script>

<div class="sf:flex sf:flex-col sf:gap-2">
	<div class="sf:flex sf:items-center sf:justify-between">
		<label for={id} class="sf:text-sm sf:font-medium sf:text-slate-700"> Prompt Overrides </label>
		<div class="sf:flex sf:gap-1">
			<button
				type="button"
				onclick={() => (mode === 'form' ? switchToJson() : switchToForm())}
				class="sf:text-xs sf:text-indigo-600 sf:hover:text-indigo-800"
			>
				Switch to {mode === 'form' ? 'JSON' : 'Form'}
			</button>
		</div>
	</div>

	{#if mode === 'form'}
		<div
			class="sf:flex sf:flex-col sf:gap-2 sf:p-3 sf:bg-slate-50 sf:rounded-md sf:border sf:border-slate-200"
		>
			{#if formPairs.length === 0}
				<p class="sf:text-sm sf:text-slate-500 sf:italic">No overrides configured</p>
			{:else}
				{#each formPairs as pair, index (index)}
					<div class="sf:flex sf:gap-2 sf:items-start">
						<input
							type="text"
							value={pair.key}
							oninput={(e) => updatePair(index, 'key', (e.target as HTMLInputElement).value)}
							placeholder="Key"
							class="sf:w-1/3 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
								   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
						/>
						<input
							type="text"
							value={pair.value}
							oninput={(e) => updatePair(index, 'value', (e.target as HTMLInputElement).value)}
							placeholder="Value"
							class="sf:flex-1 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
								   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
						/>
						<button
							type="button"
							onclick={() => removePair(index)}
							class="sf:text-red-500 sf:hover:text-red-700 sf:px-2 sf:py-1"
							aria-label="Remove pair"
						>
							×
						</button>
					</div>
				{/each}
			{/if}
			<button
				type="button"
				onclick={addPair}
				class="sf:self-start sf:text-sm sf:text-indigo-600 sf:hover:text-indigo-800"
			>
				+ Add override
			</button>
		</div>
	{:else}
		<textarea
			{id}
			bind:value={jsonText}
			oninput={updateFromJson}
			rows={6}
			placeholder={'{"summaryTone": "friendly"}'}
			class={[
				'sf:w-full sf:rounded-md sf:border sf:font-mono sf:text-sm sf:px-3 sf:py-2',
				'focus:sf:outline-none focus:sf:ring-2 focus:sf:ring-indigo-500',
				jsonError ? 'sf:border-red-500' : 'sf:border-slate-300'
			].join(' ')}
		></textarea>
		{#if jsonError}
			<p class="sf:text-xs sf:text-red-600">{jsonError}</p>
		{/if}
	{/if}
	<p class="sf:text-xs sf:text-slate-500">
		Key-value pairs to customize the action prompt template.
	</p>
</div>
