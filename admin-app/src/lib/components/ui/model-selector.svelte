<script lang="ts">
	import { onMount } from 'svelte';
	import { Card, Button, Badge, Alert, SelectField, Toggle } from '$lib/components/ui';
	import { wpFetch } from '$lib/wp';

	/**
	 * ModelSelector Component (CB-MODEL-001 through CB-MODEL-009)
	 *
	 * Provides simple preset selection by default with advanced toggle for explicit model selection.
	 * Supports backup model selection and displays audit trail for resolved models.
	 */

	interface ModelInfo {
		id: string;
		display_name: string;
		provider: string;
		speed_tier: string;
		cost_tier: string;
		capabilities: {
			reasoning: boolean;
			code: boolean;
			vision: boolean;
			tools: boolean;
			long_context: boolean;
		};
		context_window: number;
		is_preview: boolean;
		tags: string[];
		recommended_for: string[];
	}

	interface ModelPreset {
		code: string;
		display_name: string;
		description: string;
		category: string;
		resolved_model_id: string;
		auto_upgrade: boolean;
	}

	interface ModelSelection {
		primary: string;
		backup: string | null;
		is_preset: boolean;
	}

	interface OverrideStep {
		level: string;
		selection: string | null;
		applied: boolean;
		reason: string;
	}

	interface ResolvedModel {
		model_id: string;
		display_name: string;
		resolution_source: string;
		override_chain: OverrideStep[];
		backup_model_id: string | null;
	}

	interface Props {
		/** Current value (preset code or model ID) */
		value?: ModelSelection | null;
		/** Label for the selector */
		label?: string;
		/** Override level for this selector (global, action, form, mapping) */
		level?: 'global' | 'action' | 'form' | 'mapping';
		/** Whether this is read-only (just showing resolved info) */
		readonly?: boolean;
		/** Callback when selection changes */
		onchange?: (selection: ModelSelection) => void;
	}

	let {
		value = null,
		label = 'Model Selection',
		level = 'mapping',
		readonly = false,
		onchange
	}: Props = $props();

	let loading = $state(true);
	let models = $state<ModelInfo[]>([]);
	let presets = $state<ModelPreset[]>([]);
	let advancedMode = $state(false);
	let selectedPreset = $state('sf_default');
	let selectedModel = $state('');
	let selectedBackup = $state('');
	let error = $state<string | null>(null);

	// Resolved model info (for display)
	let resolved = $state<ResolvedModel | null>(null);
	let resolving = $state(false);

	async function loadModels() {
		loading = true;
		error = null;
		try {
			const response = await wpFetch<{
				success: boolean;
				data: { models: ModelInfo[]; presets: ModelPreset[] };
			}>('models');
			if (response?.data) {
				models = response.data.models;
				presets = response.data.presets;

				// Initialize from value
				if (value) {
					advancedMode = !value.is_preset;
					if (value.is_preset) {
						selectedPreset = value.primary;
					} else {
						selectedModel = value.primary;
					}
					selectedBackup = value.backup ?? '';
				}
			}
		} catch (e) {
			console.error('Failed to load models', e);
			error = e instanceof Error ? e.message : 'Failed to load models';
		} finally {
			loading = false;
		}
	}

	function handleSelectionChange() {
		const selection: ModelSelection = {
			primary: advancedMode ? selectedModel : selectedPreset,
			backup: selectedBackup || null,
			is_preset: !advancedMode
		};
		onchange?.(selection);
	}

	function toggleAdvanced() {
		advancedMode = !advancedMode;
		// Reset selection when switching modes
		if (advancedMode && models.length > 0) {
			selectedModel = models[0].id;
		} else if (!advancedMode && presets.length > 0) {
			selectedPreset = presets[0].code;
		}
		handleSelectionChange();
	}

	// Preset options for SelectField
	const presetOptions = $derived(
		presets.map((p) => ({
			value: p.code,
			label: `${p.display_name} → ${p.resolved_model_id}`
		}))
	);

	// Model options for SelectField
	const modelOptions = $derived(
		models.map((m) => ({
			value: m.id,
			label: `${m.display_name} (${m.speed_tier}, ${m.cost_tier})`
		}))
	);

	// Backup model options
	const backupOptions = $derived([
		{ value: '', label: 'No backup' },
		...models.map((m) => ({
			value: m.id,
			label: m.display_name
		}))
	]);

	// Speed tier badge variant
	function speedBadgeVariant(tier: string) {
		switch (tier) {
			case 'fastest':
				return 'success';
			case 'fast':
				return 'info';
			case 'balanced':
				return 'warning';
			case 'slow':
				return 'neutral';
			default:
				return 'neutral';
		}
	}

	// Cost tier badge variant
	function costBadgeVariant(tier: string) {
		switch (tier) {
			case 'free':
				return 'success';
			case 'low':
				return 'info';
			case 'medium':
				return 'warning';
			case 'high':
				return 'danger';
			case 'premium':
				return 'danger';
			default:
				return 'neutral';
		}
	}

	onMount(() => {
		loadModels();
	});
</script>

<div class="sf:space-y-4">
	<div class="sf:flex sf:items-center sf:justify-between">
		<p class="sf:text-sm sf:font-medium sf:text-slate-700">{label}</p>
		{#if !readonly}
			<Button size="sm" variant="ghost" onclick={toggleAdvanced}>
				{advancedMode ? '← Simple mode' : 'Advanced →'}
			</Button>
		{/if}
	</div>

	{#if loading}
		<p class="sf:text-sm sf:text-slate-600">Loading models...</p>
	{:else if error}
		<Alert variant="danger">{error}</Alert>
	{:else if readonly && resolved}
		<!-- Read-only display of resolved model -->
		<Card class="sf:bg-slate-50">
			<div class="sf:flex sf:items-center sf:justify-between">
				<div>
					<p class="sf:font-medium sf:text-slate-800">{resolved.display_name}</p>
					<p class="sf:text-xs sf:text-slate-500">
						Resolved from: {resolved.resolution_source}
					</p>
				</div>
				{#if resolved.backup_model_id}
					<Badge variant="info">Backup: {resolved.backup_model_id}</Badge>
				{/if}
			</div>

			{#if resolved.override_chain.length > 0}
				<details class="sf:mt-3">
					<summary class="sf:text-xs sf:text-slate-600 sf:cursor-pointer"> Override chain </summary>
					<ul class="sf:mt-2 sf:space-y-1 sf:text-xs sf:text-slate-500">
						{#each resolved.override_chain as step}
							<li class="sf:flex sf:items-center sf:gap-2">
								<span class={step.applied ? 'sf:text-green-600' : 'sf:text-slate-400'}>
									{step.applied ? '✓' : '○'}
								</span>
								<span class="sf:font-medium">{step.level}:</span>
								<span>{step.reason}</span>
							</li>
						{/each}
					</ul>
				</details>
			{/if}
		</Card>
	{:else}
		<!-- Selection UI -->
		{#if !advancedMode}
			<!-- Simple mode: Preset selection -->
			<SelectField
				id="model-preset"
				label="Preset"
				options={presetOptions}
				bind:value={selectedPreset}
				onchange={handleSelectionChange}
			/>

			{#if selectedPreset}
				{@const preset = presets.find((p) => p.code === selectedPreset)}
				{#if preset}
					<div class="sf:text-xs sf:text-slate-600 sf:space-y-1">
						<p>{preset.description}</p>
						<p>
							Resolves to: <strong>{preset.resolved_model_id}</strong>
							{#if preset.auto_upgrade}
								<span class="sf:ml-2"><Badge variant="info">Auto-upgrade</Badge></span>
							{/if}
						</p>
					</div>
				{/if}
			{/if}
		{:else}
			<!-- Advanced mode: Explicit model selection -->
			<SelectField
				id="model-primary"
				label="Primary Model"
				options={modelOptions}
				bind:value={selectedModel}
				onchange={handleSelectionChange}
			/>

			{#if selectedModel}
				{@const model = models.find((m) => m.id === selectedModel)}
				{#if model}
					<div class="sf:flex sf:flex-wrap sf:gap-2">
						<Badge variant={speedBadgeVariant(model.speed_tier)}>
							{model.speed_tier}
						</Badge>
						<Badge variant={costBadgeVariant(model.cost_tier)}>
							{model.cost_tier}
						</Badge>
						{#if model.is_preview}
							<Badge variant="warning">Preview</Badge>
						{/if}
						{#if model.capabilities.reasoning}
							<Badge variant="neutral">Reasoning</Badge>
						{/if}
						{#if model.capabilities.vision}
							<Badge variant="neutral">Vision</Badge>
						{/if}
					</div>
					<p class="sf:text-xs sf:text-slate-500 sf:mt-1">
						Context: {(model.context_window / 1000).toLocaleString()}K tokens
					</p>
				{/if}
			{/if}

			<SelectField
				id="model-backup"
				label="Backup Model (fallback)"
				options={backupOptions}
				bind:value={selectedBackup}
				onchange={handleSelectionChange}
			/>
		{/if}
	{/if}
</div>
