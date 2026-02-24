<script lang="ts">
	import type { FormFieldInfo, MappingConditionsConfig } from '$lib/api/types';
	import { createDefaultConditionConfig } from '$lib/utils/conditions';
	import ConditionGroupEditor from './condition-group-editor.svelte';

	interface Props {
		fields: FormFieldInfo[];
		value?: MappingConditionsConfig;
		onchange: (conditions: MappingConditionsConfig) => void;
		disabled?: boolean;
	}

	let { fields, value, onchange, disabled = false }: Props = $props();

	function ensureConfig(config: MappingConditionsConfig | undefined): MappingConditionsConfig {
		if (!config || !config.root) {
			return createDefaultConditionConfig();
		}

		return config;
	}

	const config = $derived(ensureConfig(value));

	function update(next: MappingConditionsConfig) {
		onchange(next);
	}

	function toggleEnabled() {
		update({
			...config,
			enabled: !config.enabled
		});
	}
</script>

<div class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:space-y-3">
	<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
		<div>
			<p class="sf:text-sm sf:font-medium sf:text-slate-800">Conditional run</p>
			<p class="sf:text-xs sf:text-slate-500">
				Only run this mapping when the configured field rules match.
			</p>
		</div>
		<label class="sf:flex sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700">
			<input
				type="checkbox"
				class="sf:h-4 sf:w-4 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
				checked={config.enabled}
				onchange={toggleEnabled}
				{disabled}
			/>
			<span>Enable conditional run</span>
		</label>
	</div>

	{#if config.enabled}
		<ConditionGroupEditor
			group={config.root}
			{fields}
			depth={1}
			{disabled}
			onchange={(root) => update({ ...config, root })}
		/>
	{:else}
		<p class="sf:text-sm sf:text-slate-500">
			Conditions are disabled. This mapping will run whenever its selected trigger hooks fire.
		</p>
	{/if}
</div>
