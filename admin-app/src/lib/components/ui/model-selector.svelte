<script lang="ts">
	import { onMount } from 'svelte';
	import { Card, Button, Badge, Alert, SelectField } from '$lib/components/ui';
	import type {
		ModelCatalogResponse,
		ModelEstimateResponse,
		ModelInfo,
		ModelPricingEstimate,
		ModelPreset,
		ModelSelection,
		ResolvedModelSelection
	} from '$lib/api/types';
	import { unwrapRestResponse, type RestEnvelope } from '$lib/api/response';
	import { wpFetch } from '$lib/wp';

	/**
	 * ModelSelector Component (CB-MODEL-001 through CB-MODEL-009)
	 *
	 * Provides simple preset selection by default with advanced toggle for explicit model selection.
	 * Supports backup model selection and displays audit trail for resolved models.
	 */

	interface Props {
		/** Current value (preset code or model ID) */
		value?: ModelSelection | null;
		/** Label for the selector */
		label?: string;
		/** Override level for this selector (global, action, form, mapping) */
		level?: 'global' | 'action' | 'form' | 'mapping';
		/** Whether this is read-only (just showing resolved info) */
		readonly?: boolean;
		/** Action identifier used for pricing estimates */
		actionId?: string | null;
		/** Template-level model hint fallback */
		templateModelHint?: string | null;
		/** Base floor fallback when the action is not yet available remotely */
		baseCreditCost?: number | null;
		/** Inheritance inputs for live resolution/estimate */
		globalSelection?: ModelSelection | null;
		actionSelection?: ModelSelection | null;
		formSelection?: ModelSelection | null;
		mappingSelection?: ModelSelection | null;
		/** Callback when selection changes */
		onchange?: (selection: ModelSelection) => void;
	}

	let {
		value = null,
		label = 'Model Selection',
		level = 'mapping',
		readonly = false,
		actionId = null,
		templateModelHint = null,
		baseCreditCost = null,
		globalSelection = null,
		actionSelection = null,
		formSelection = null,
		mappingSelection = null,
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
	let resolved = $state<ResolvedModelSelection | null>(null);
	let pricingEstimate = $state<ModelPricingEstimate | null>(null);
	let resolving = $state(false);
	let resolutionError = $state<string | null>(null);
	let resolutionRequestToken = 0;

	function isRecord(value: unknown): value is Record<string, unknown> {
		return Boolean(value && typeof value === 'object' && !Array.isArray(value));
	}

	function normalizeModelCatalog(
		catalog: ModelCatalogResponse | Record<string, unknown> | null | undefined
	): ModelCatalogResponse {
		return {
			models: Array.isArray(catalog?.models) ? (catalog.models as ModelInfo[]) : [],
			presets: Array.isArray(catalog?.presets) ? (catalog.presets as ModelPreset[]) : [],
			pricing_policy_version:
				typeof catalog?.pricing_policy_version === 'string'
					? catalog.pricing_policy_version
					: undefined
		};
	}

	function normalizeResolvedModel(
		candidate: ResolvedModelSelection | Record<string, unknown> | null | undefined
	): ResolvedModelSelection | null {
		if (!isRecord(candidate)) {
			return null;
		}

		const modelId = typeof candidate.model_id === 'string' ? candidate.model_id : '';
		const displayName =
			typeof candidate.display_name === 'string' ? candidate.display_name : modelId;
		const resolutionSource =
			typeof candidate.resolution_source === 'string'
				? candidate.resolution_source
				: 'unavailable';

		if (!modelId || !displayName) {
			return null;
		}

		return {
			model_id: modelId,
			display_name: displayName,
			resolution_source: resolutionSource,
			override_chain: Array.isArray(candidate.override_chain)
				? (candidate.override_chain as ResolvedModelSelection['override_chain'])
				: [],
			backup_model_id:
				typeof candidate.backup_model_id === 'string' ? candidate.backup_model_id : null
		};
	}

	function syncSelectionFromValue(nextValue: ModelSelection | null | undefined) {
		if (!nextValue) {
			advancedMode = false;
			selectedPreset = 'sf_default';
			selectedModel = '';
			selectedBackup = '';
			return;
		}

		advancedMode = !nextValue.is_preset;
		selectedPreset = nextValue.is_preset ? nextValue.primary : 'sf_default';
		selectedModel = nextValue.is_preset ? '' : nextValue.primary;
		selectedBackup = nextValue.backup ?? '';
	}

	async function loadModels() {
		loading = true;
		error = null;
		try {
			const response = await wpFetch<ModelCatalogResponse | RestEnvelope<ModelCatalogResponse>>('models');
			const catalog = normalizeModelCatalog(
				unwrapRestResponse<ModelCatalogResponse | Record<string, unknown>>(response)
			);
			models = catalog.models;
			presets = catalog.presets;
			syncSelectionFromValue(value);
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
		void resolveSelectionPreview(selection);
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

	function currentSelection(): ModelSelection {
		return {
			primary: advancedMode ? selectedModel : selectedPreset,
			backup: selectedBackup || null,
			is_preset: !advancedMode
		};
	}

	function buildSelectionPayload(selection: ModelSelection) {
		const payload = {
			template_model_hint: templateModelHint ?? undefined,
			global_selection: level === 'global' ? selection : (globalSelection ?? undefined),
			action_selection: level === 'action' ? selection : (actionSelection ?? undefined),
			form_selection: level === 'form' ? selection : (formSelection ?? undefined),
			mapping_selection: level === 'mapping' ? selection : (mappingSelection ?? undefined)
		};

		return payload;
	}

	async function resolveSelectionPreview(selection: ModelSelection | null) {
		if (!selection || selection.primary.trim().length === 0) {
			resolved = null;
			pricingEstimate = null;
			resolutionError = null;
			resolving = false;
			return;
		}

		const requestToken = ++resolutionRequestToken;
		resolving = true;
		resolutionError = null;
		const selectionPayload = buildSelectionPayload(selection);

		try {
			const resolvedResponse = await wpFetch<
				ResolvedModelSelection | RestEnvelope<ResolvedModelSelection>
			>(
				'models/resolve',
				{
					method: 'POST',
					body: selectionPayload,
					showNotifications: false
				}
			);

			if (requestToken !== resolutionRequestToken) {
				return;
			}

			resolved = normalizeResolvedModel(
				unwrapRestResponse<ResolvedModelSelection | Record<string, unknown>>(resolvedResponse)
			);

			if (actionId) {
				const estimateResponse = await wpFetch<
					ModelEstimateResponse | RestEnvelope<ModelEstimateResponse>
				>(
					'models/estimate',
					{
						method: 'POST',
						body: {
							action_id: actionId,
							template_model_hint: templateModelHint ?? undefined,
							base_credit_cost: baseCreditCost ?? undefined,
							...selectionPayload
						},
						showNotifications: false
					}
				);

				if (requestToken !== resolutionRequestToken) {
					return;
				}

				const estimate = unwrapRestResponse<ModelEstimateResponse>(estimateResponse);
				const estimateResolved = normalizeResolvedModel(estimate?.resolved_model);
				if (estimateResolved) {
					resolved = estimateResolved;
				}
				pricingEstimate = estimate?.pricing_estimate ?? null;
			} else {
				pricingEstimate = null;
			}
		} catch (e) {
			if (requestToken !== resolutionRequestToken) {
				return;
			}
			console.error('Failed to resolve model selection', e);
			resolutionError = e instanceof Error ? e.message : 'Failed to resolve model selection';
			pricingEstimate = null;
		} finally {
			if (requestToken === resolutionRequestToken) {
				resolving = false;
			}
		}
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

	$effect(() => {
		syncSelectionFromValue(value);
	});

	$effect(() => {
		void resolveSelectionPreview(currentSelection());
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

		{#if resolutionError}
			<Alert variant="warning">
				Could not load the local resolved model preview. {resolutionError}
			</Alert>
		{:else if resolving}
			<p class="sf:text-xs sf:text-slate-500">Refreshing resolved model and local cost preview...</p>
		{/if}

		{#if resolved}
			<Card class="sf:bg-slate-50">
				<div class="sf:flex sf:flex-col sf:gap-2 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between">
					<div class="sf:space-y-1">
						<p class="sf:font-medium sf:text-slate-800">{resolved.display_name}</p>
						<p class="sf:text-xs sf:text-slate-500">
							Resolved from: {resolved.resolution_source}
						</p>
					</div>
					{#if pricingEstimate}
						<div class="sf:text-left sf:sm:text-right">
							<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500">
								Sentient Debit
							</p>
							<p class="sf:text-lg sf:font-semibold sf:text-slate-900">
								{pricingEstimate.estimated_debit_credits} credits
							</p>
						</div>
					{/if}
				</div>

				{#if pricingEstimate}
					<p class="sf:mt-2 sf:text-xs sf:text-slate-600">
						Base floor: <strong>{pricingEstimate.base_floor_credits}</strong> · Normalized direct-provider estimate:
						<strong>{pricingEstimate.normalized_actual_credits}</strong> · Policy:
						{pricingEstimate.pricing_policy_version}
					</p>
					<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
						Local OpenRouter runs do not spend Sentient credits. Provider charges are billed by
						OpenRouter according to the selected model and your OpenRouter account.
					</p>
				{/if}

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
		{/if}
	{/if}
</div>
