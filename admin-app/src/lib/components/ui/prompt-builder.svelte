<!--
  PromptBuilder.svelte - Schema-aware hybrid UI/JSON editor for prompt overrides
  Toggles between form mode (schema-guided or key-value pairs) and JSON mode (raw textarea)
  
  When a schema is provided, shows dropdown for defined keys with type-appropriate value inputs.
  Still allows adding custom keys for advanced users.
-->
<script lang="ts">
	import { parsePromptOverridesInput } from '$lib/utils/custom-actions';
	import type {
		TemplateOverrideSchema,
		OverrideKeySchema,
		OverrideKeyCategory
	} from '$lib/api/types';

	interface Props {
		/** Current value as a record */
		value: Record<string, unknown>;
		/** Optional override schema from the template */
		schema?: TemplateOverrideSchema;
		/** Callback when value changes */
		onchange?: (value: Record<string, unknown>) => void;
		/** Input ID prefix for label association */
		id?: string;
	}

	let { value = $bindable({}), schema, onchange, id = 'prompt-builder' }: Props = $props();

	type EditorMode = 'form' | 'json';
	let mode = $state<EditorMode>('form');
	let jsonText = $state('');
	let jsonError = $state<string | null>(null);
	let formPairs = $state<Array<{ key: string; value: string; fromSchema: boolean }>>([]);

	// Get schema keys for dropdown
	const schemaKeys = $derived(schema ? Object.keys(schema) : []);
	const hasSchema = $derived(schemaKeys.length > 0);

	// Initialize from value prop
	$effect(() => {
		if (Object.keys(value).length > 0 && formPairs.length === 0) {
			formPairs = Object.entries(value).map(([key, val]) => ({
				key,
				value: typeof val === 'string' ? val : JSON.stringify(val),
				fromSchema: hasSchema && schemaKeys.includes(key)
			}));
			jsonText = JSON.stringify(value, null, 2);
		}
	});

	function switchToJson() {
		const obj = formPairsToObject();
		jsonText = JSON.stringify(obj, null, 2);
		jsonError = null;
		mode = 'json';
	}

	function switchToForm() {
		const { result, error } = parsePromptOverridesInput(jsonText);
		if (error) {
			jsonError = error;
			return;
		}
		formPairs = Object.entries(result || {}).map(([key, val]) => ({
			key,
			value: typeof val === 'string' ? val : JSON.stringify(val),
			fromSchema: hasSchema && schemaKeys.includes(key)
		}));
		jsonError = null;
		mode = 'form';
	}

	function formPairsToObject(): Record<string, unknown> {
		const obj: Record<string, unknown> = {};
		for (const pair of formPairs) {
			if (pair.key.trim()) {
				const keySchema = getKeySchema(pair.key.trim());
				// For enum and string types, store as raw string
				// For boolean and number, parse appropriately
				// For unknown types, try JSON.parse with string fallback
				if (keySchema?.type === 'enum' || keySchema?.type === 'string') {
					obj[pair.key.trim()] = pair.value;
				} else if (keySchema?.type === 'boolean') {
					obj[pair.key.trim()] = pair.value === 'true';
				} else if (keySchema?.type === 'number') {
					obj[pair.key.trim()] = pair.value === '' ? null : Number(pair.value);
				} else {
					// Unknown type: try JSON parse, fall back to string
					try {
						obj[pair.key.trim()] = JSON.parse(pair.value);
					} catch {
						obj[pair.key.trim()] = pair.value;
					}
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
		formPairs = [...formPairs, { key: '', value: '', fromSchema: false }];
	}

	function addSchemaKey() {
		// Find first schema key not already in use
		const usedKeys = new Set(formPairs.map((p) => p.key));
		const availableKey = schemaKeys.find((k) => !usedKeys.has(k));
		if (availableKey && schema) {
			const keySchema = schema[availableKey];
			// For enum/string/boolean/number, store raw value; otherwise JSON stringify
			let defaultVal = '';
			if (keySchema.default !== undefined) {
				if (keySchema.type === 'enum' || keySchema.type === 'string') {
					defaultVal = String(keySchema.default);
				} else if (keySchema.type === 'boolean' || keySchema.type === 'number') {
					defaultVal = String(keySchema.default);
				} else {
					defaultVal = JSON.stringify(keySchema.default);
				}
			}
			formPairs = [...formPairs, { key: availableKey, value: defaultVal, fromSchema: true }];
			updateFromForm();
		}
	}

	function removePair(index: number) {
		formPairs = formPairs.filter((_, i) => i !== index);
		updateFromForm();
	}

	function updatePair(index: number, field: 'key' | 'value', newValue: string) {
		formPairs = formPairs.map((pair, i) =>
			i === index
				? {
						...pair,
						[field]: newValue,
						fromSchema:
							field === 'key' ? hasSchema && schemaKeys.includes(newValue) : pair.fromSchema
					}
				: pair
		);
		updateFromForm();
	}

	function getKeySchema(key: string): OverrideKeySchema | null {
		return schema?.[key] ?? null;
	}

	function getAvailableSchemaKeys(): string[] {
		if (!hasSchema) return [];
		const usedKeys = new Set(formPairs.map((p) => p.key));
		return schemaKeys.filter((k) => !usedKeys.has(k));
	}

	// CA-UI-001: Category labels for taxonomy grouping
	const categoryLabels: Record<OverrideKeyCategory, string> = {
		behavior: 'Behavior',
		output: 'Output',
		model: 'Model',
		context: 'Context',
		advanced: 'Advanced'
	};

	function getCategoryLabel(category?: OverrideKeyCategory): string {
		return category ? categoryLabels[category] : 'General';
	}

	// Group available schema keys by category
	function getGroupedSchemaKeys(): Array<{ category: string; keys: string[] }> {
		const availableKeys = getAvailableSchemaKeys();
		if (availableKeys.length === 0) return [];

		const groups: Record<string, string[]> = {};
		for (const key of availableKeys) {
			const keySchema = schema?.[key];
			const categoryLabel = getCategoryLabel(keySchema?.category);
			if (!groups[categoryLabel]) {
				groups[categoryLabel] = [];
			}
			groups[categoryLabel].push(key);
		}

		// Sort categories: General first, then alphabetically
		return Object.entries(groups)
			.sort(([a], [b]) => {
				if (a === 'General') return -1;
				if (b === 'General') return 1;
				return a.localeCompare(b);
			})
			.map(([category, keys]) => ({ category, keys }));
	}

	function renderValueInput(pair: { key: string; value: string }, index: number) {
		const keySchema = getKeySchema(pair.key);
		return { keySchema };
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
				{#if hasSchema}
					<p class="sf:text-sm sf:text-slate-500 sf:italic">
						No overrides configured. Use the dropdown below to add template options.
					</p>
				{:else}
					<p class="sf:text-sm sf:text-slate-500 sf:italic">No overrides configured</p>
				{/if}
			{:else}
				{#each formPairs as pair, index (index)}
					{@const keySchema = getKeySchema(pair.key)}
					<div class="sf:flex sf:gap-2 sf:items-start">
						<!-- Key input: dropdown if schema available, text otherwise -->
						{#if hasSchema && (pair.fromSchema || getAvailableSchemaKeys().length > 0)}
							<select
								value={pair.key}
								onchange={(e) => updatePair(index, 'key', (e.target as HTMLSelectElement).value)}
								class="sf:w-1/3 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
									   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
							>
								{#if pair.key && schemaKeys.includes(pair.key)}
									<option value={pair.key}>{pair.key}</option>
								{/if}
								{#if !pair.key}
									<option value="">Select key...</option>
								{/if}
								<!-- CA-UI-001: Group keys by category -->
								{#each getGroupedSchemaKeys() as group (group.category)}
									<optgroup label={group.category}>
										{#each group.keys as key (key)}
											<option value={key}>{key}</option>
										{/each}
									</optgroup>
								{/each}
								<option value="_custom">+ Custom key...</option>
							</select>
						{:else}
							<input
								type="text"
								value={pair.key}
								oninput={(e) => updatePair(index, 'key', (e.target as HTMLInputElement).value)}
								placeholder="Key"
								class="sf:w-1/3 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
									   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
							/>
						{/if}

						<!-- Value input: type-appropriate based on schema -->
						{#if keySchema?.type === 'enum' && keySchema.options}
							<select
								value={pair.value}
								onchange={(e) => updatePair(index, 'value', (e.target as HTMLSelectElement).value)}
								class="sf:flex-1 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
									   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
							>
								<option value="">Select...</option>
								{#each keySchema.options as opt}
									<option value={opt}>{opt}</option>
								{/each}
							</select>
						{:else if keySchema?.type === 'boolean'}
							<select
								value={pair.value}
								onchange={(e) => updatePair(index, 'value', (e.target as HTMLSelectElement).value)}
								class="sf:flex-1 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
									   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
							>
								<option value="true">true</option>
								<option value="false">false</option>
							</select>
						{:else if keySchema?.type === 'number'}
							<input
								type="number"
								value={pair.value}
								min={keySchema.min}
								max={keySchema.max}
								oninput={(e) => updatePair(index, 'value', (e.target as HTMLInputElement).value)}
								placeholder={keySchema.description ?? 'Number'}
								class="sf:flex-1 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
									   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
							/>
						{:else}
							<input
								type="text"
								value={pair.value}
								oninput={(e) => updatePair(index, 'value', (e.target as HTMLInputElement).value)}
								placeholder={keySchema?.description ?? 'Value'}
								class="sf:flex-1 sf:rounded-md sf:border sf:border-slate-300 sf:px-2 sf:py-1 sf:text-sm
									   focus:sf:outline-none focus:sf:ring-1 focus:sf:ring-indigo-500"
							/>
						{/if}

						<button
							type="button"
							onclick={() => removePair(index)}
							class="sf:text-red-500 sf:hover:text-red-700 sf:px-2 sf:py-1"
							aria-label="Remove pair"
						>
							×
						</button>
					</div>

					<!-- Show description hint for schema keys -->
					{#if keySchema?.description}
						<p class="sf:text-xs sf:text-slate-400 sf:ml-1 sf:-mt-1">{keySchema.description}</p>
					{/if}
				{/each}
			{/if}

			<div class="sf:flex sf:gap-2">
				{#if hasSchema && getAvailableSchemaKeys().length > 0}
					<button
						type="button"
						onclick={addSchemaKey}
						class="sf:self-start sf:text-sm sf:text-indigo-600 sf:hover:text-indigo-800 sf:font-medium"
					>
						+ Add template option
					</button>
				{/if}
				<button
					type="button"
					onclick={addPair}
					class="sf:self-start sf:text-sm sf:text-slate-500 sf:hover:text-slate-700"
				>
					+ Custom override
				</button>
			</div>
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
		{#if hasSchema}
			Customize the action using template-defined options, or add custom overrides.
		{:else}
			Key-value pairs to customize the action prompt template.
		{/if}
	</p>
</div>
