<script lang="ts">
	import { onMount } from 'svelte';
	import { Alert, Badge, Button, Card, SelectField } from '$lib/components/ui';
	import type {
		LocalProvider,
		LocalProviderCredential,
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

	interface Props {
		value?: ModelSelection | null;
		label?: string;
		level?: 'global' | 'action' | 'form' | 'mapping';
		readonly?: boolean;
		actionId?: string | null;
		templateModelHint?: string | null;
		baseCreditCost?: number | null;
		globalSelection?: ModelSelection | null;
		actionSelection?: ModelSelection | null;
		formSelection?: ModelSelection | null;
		mappingSelection?: ModelSelection | null;
		providerCredentials?: LocalProviderCredential[] | null;
		allowedProviders?: LocalProvider[] | null;
		onchange?: (selection: ModelSelection) => void;
	}

	type SelectionMode = 'presets' | 'models' | 'custom';
	type CostFilter = 'all' | 'free' | 'paid' | 'locked';
	type CapabilityItem = readonly [key: string, label: string, short: string, active: boolean];
	type CapabilityFilter =
		| 'all'
		| 'reasoning'
		| 'structured'
		| 'tools'
		| 'vision'
		| 'files'
		| 'web_search'
		| 'long_context'
		| 'code';

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
		providerCredentials = null,
		allowedProviders = null,
		onchange
	}: Props = $props();

	const READY_PROVIDER_STATUSES = new Set(['valid', 'limited']);
	const OPENROUTER_PROVIDER = 'openrouter';
	const MANAGED_PROVIDER = 'sentient_managed';
	const CUSTOM_BACKUP_VALUE = '__custom_backup__';
	const MAX_VISIBLE_MODELS = 80;

	let loading = $state(true);
	let providerLoading = $state(false);
	let models = $state<ModelInfo[]>([]);
	let presets = $state<ModelPreset[]>([]);
	let loadedProviderCredentials = $state<LocalProviderCredential[]>([]);
	let selectionMode = $state<SelectionMode>('presets');
	let selectedPreset = $state('sf_default');
	let selectedModel = $state('');
	let selectedBackup = $state('');
	let selectedCustomModel = $state('');
	let selectedCustomBackup = $state('');
	let selectedReasoning = $state('default');
	let selectedProvider = $state(OPENROUTER_PROVIDER);
	let selectedCredentialId = $state<number | null>(null);
	let searchTerm = $state('');
	let costFilter = $state<CostFilter>('all');
	let capabilityFilter = $state<CapabilityFilter>('all');
	let error = $state<string | null>(null);
	let resolved = $state<ResolvedModelSelection | null>(null);
	let pricingEstimate = $state<ModelPricingEstimate | null>(null);
	let resolving = $state(false);
	let resolutionError = $state<string | null>(null);
	let resolutionRequestToken = 0;
	let lastEmittedSelectionSignature = '';

	const fallbackModel: ModelInfo = {
		id: 'openrouter/auto',
		display_name: 'OpenRouter Auto',
		provider: OPENROUTER_PROVIDER,
		speed_tier: 'balanced',
		cost_tier: 'unknown',
		capabilities: {
			reasoning: false,
			code: false,
			vision: false,
			tools: false,
			structured: false,
			web_search: false,
			long_context: false,
			files: false
		},
		context_window: 0,
		is_preview: false,
		tags: ['fallback'],
		recommended_for: ['Fallback until the OpenRouter catalog is refreshed']
	};

	const reasoningOptions = [
		{ value: 'default', label: 'Model default' },
		{ value: 'none', label: 'None' },
		{ value: 'minimal', label: 'Minimal' },
		{ value: 'low', label: 'Low' },
		{ value: 'medium', label: 'Medium' },
		{ value: 'high', label: 'High' },
		{ value: 'xhigh', label: 'Extra high' }
	];

	const costFilterOptions = [
		{ value: 'all', label: 'All models' },
		{ value: 'free', label: 'Free' },
		{ value: 'paid', label: 'Paid' },
		{ value: 'locked', label: 'Locked' }
	];

	const capabilityFilterOptions = [
		{ value: 'all', label: 'All capabilities' },
		{ value: 'reasoning', label: 'Reasoning' },
		{ value: 'structured', label: 'Structured output' },
		{ value: 'tools', label: 'Tool calling' },
		{ value: 'vision', label: 'Vision' },
		{ value: 'files', label: 'Files/PDF' },
		{ value: 'web_search', label: 'Web search' },
		{ value: 'long_context', label: 'Long context' },
		{ value: 'code', label: 'Coding' }
	];

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
		if (!isRecord(candidate)) return null;

		const modelId = typeof candidate.model_id === 'string' ? candidate.model_id : '';
		const displayName =
			typeof candidate.display_name === 'string' ? candidate.display_name : modelId;
		const resolutionSource =
			typeof candidate.resolution_source === 'string'
				? candidate.resolution_source
				: 'unavailable';

		if (!modelId || !displayName) return null;

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

	function allowedProviderSet(): Set<string> | null {
		return Array.isArray(allowedProviders) && allowedProviders.length > 0
			? new Set(allowedProviders.map(String))
			: null;
	}

	function readyCredentials(): LocalProviderCredential[] {
		const credentials = Array.isArray(providerCredentials)
			? providerCredentials
			: loadedProviderCredentials;
		const allowed = allowedProviderSet();

		return credentials
			.filter((credential) => READY_PROVIDER_STATUSES.has(String(credential.status ?? '').trim()))
			.filter((credential) => !allowed || allowed.has(String(credential.provider)));
	}

	function credentialsForProvider(provider: string): LocalProviderCredential[] {
		return readyCredentials().filter((credential) => credential.provider === provider);
	}

	function providerAllowed(provider: string): boolean {
		const allowed = allowedProviderSet();
		return !allowed || allowed.has(provider);
	}

	function defaultProvider(): string {
		if (providerAllowed(MANAGED_PROVIDER) && credentialsForProvider(MANAGED_PROVIDER).length > 0) {
			return MANAGED_PROVIDER;
		}

		return OPENROUTER_PROVIDER;
	}

	function defaultCredentialIdForProvider(provider: string): number | null {
		return credentialsForProvider(provider)[0]?.id ?? null;
	}

	function providerRouteLabel(provider: string): string {
		if (provider === MANAGED_PROVIDER) return 'Sentient Forms managed service';
		if (provider === OPENROUTER_PROVIDER) return 'Bring your own OpenRouter key';
		return provider;
	}

	function hasSelectedProviderPaidRoute(): boolean {
		return credentialsForProvider(selectedProvider).length > 0;
	}

	function modelIsFree(model: ModelInfo): boolean {
		return model.cost_tier === 'free' || model.id.endsWith(':free') || model.id === 'openrouter/free';
	}

	function modelIsPaid(model: ModelInfo): boolean {
		return !modelIsFree(model) && model.id !== 'openrouter/auto';
	}

	function isModelLocked(model: ModelInfo): boolean {
		return modelIsPaid(model) && !hasSelectedProviderPaidRoute();
	}

	function lockTitle(model: ModelInfo): string {
		return isModelLocked(model)
			? 'Requires a paid OpenRouter key or Sentient Forms managed service.'
			: `${model.display_name} is selectable for the current execution route.`;
	}

	function normalizeSelectedModel(candidate: string): string {
		const candidateModel = candidate ? models.find((model) => model.id === candidate) : null;
		if (candidateModel && !isModelLocked(candidateModel)) return candidateModel.id;

		return (
			models.find((model) => !isModelLocked(model) && model.id !== 'openrouter/auto')?.id ??
			models[0]?.id ??
			'openrouter/auto'
		);
	}

	function resolvedModelForPreset(code: string): string {
		return (
			presets.find((preset) => preset.code === code)?.resolved_model_id ??
			models.find((model) => model.id !== 'openrouter/auto')?.id ??
			models[0]?.id ??
			'openrouter/auto'
		);
	}

	function defaultPresetCode(): string {
		if (!hasSelectedProviderPaidRoute() && presets.some((preset) => preset.code === 'sf_free')) {
			return 'sf_free';
		}

		return presets.some((preset) => preset.code === 'sf_default') ? 'sf_default' : (presets[0]?.code ?? 'sf_default');
	}

	function presetIsLocked(code: string): boolean {
		const modelId = resolvedModelForPreset(code);
		const model = models.find((candidate) => candidate.id === modelId) ?? null;
		return model ? isModelLocked(model) : false;
	}

	function modelHasCapability(model: ModelInfo, capability: CapabilityFilter): boolean {
		if (capability === 'all') return true;
		return Boolean(model.capabilities[capability]);
	}

	function syncSelectionFromValue(nextValue: ModelSelection | null | undefined) {
		const providerFromValue =
			typeof nextValue?.provider === 'string' && nextValue.provider.trim().length > 0
				? nextValue.provider.trim()
				: defaultProvider();
		selectedProvider =
			[MANAGED_PROVIDER, OPENROUTER_PROVIDER].includes(providerFromValue) &&
			providerAllowed(providerFromValue)
				? providerFromValue
				: defaultProvider();
		selectedCredentialId =
			typeof nextValue?.credential_id === 'number' && nextValue.credential_id > 0
				? nextValue.credential_id
				: defaultCredentialIdForProvider(selectedProvider);

		if (!nextValue) {
			selectionMode = 'presets';
			selectedPreset = defaultPresetCode();
			selectedModel = normalizeSelectedModel(resolvedModelForPreset(selectedPreset));
			selectedBackup = '';
			selectedCustomModel = '';
			selectedCustomBackup = '';
			selectedReasoning = 'default';
			return;
		}

		if (nextValue.is_preset) {
			selectionMode = 'presets';
			const requestedPreset = nextValue.primary || defaultPresetCode();
			const providerWasExplicit = typeof nextValue.provider === 'string' && nextValue.provider.trim().length > 0;
			selectedPreset =
				!providerWasExplicit && presetIsLocked(requestedPreset) ? defaultPresetCode() : requestedPreset;
			selectedModel = normalizeSelectedModel(resolvedModelForPreset(selectedPreset));
		} else if (models.some((model) => model.id === nextValue.primary)) {
			selectionMode = 'models';
			selectedModel = normalizeSelectedModel(nextValue.primary);
			selectedCustomModel = '';
		} else {
			selectionMode = 'custom';
			selectedCustomModel = nextValue.primary;
			selectedModel = normalizeSelectedModel('');
		}

		if (typeof nextValue.backup === 'string' && nextValue.backup.trim().length > 0) {
			if (models.some((model) => model.id === nextValue.backup)) {
				selectedBackup = nextValue.backup;
				selectedCustomBackup = '';
			} else {
				selectedBackup = CUSTOM_BACKUP_VALUE;
				selectedCustomBackup = nextValue.backup;
			}
		} else {
			selectedBackup = '';
			selectedCustomBackup = '';
		}

		selectedReasoning = typeof nextValue.reasoning === 'string' ? nextValue.reasoning : 'default';
	}

	async function loadModels() {
		loading = true;
		error = null;
		try {
			const response = await wpFetch<ModelCatalogResponse | RestEnvelope<ModelCatalogResponse>>('models');
			const catalog = normalizeModelCatalog(
				unwrapRestResponse<ModelCatalogResponse | Record<string, unknown>>(response)
			);
			models = catalog.models.length > 0 ? catalog.models : [fallbackModel];
			presets =
				catalog.presets.length > 0
					? catalog.presets
					: [
							{
								code: 'sf_default',
								display_name: 'Recommended',
								description: 'Uses OpenRouter Auto until the local model catalog is refreshed.',
								category: 'local',
								resolved_model_id: 'openrouter/auto',
								auto_upgrade: true
							}
						];
			syncSelectionFromValue(value);
		} catch (e) {
			console.error('Failed to load models', e);
			error = e instanceof Error ? e.message : 'Failed to load models';
		} finally {
			loading = false;
		}
	}

	async function loadProviderCredentials() {
		if (Array.isArray(providerCredentials)) {
			loadedProviderCredentials = providerCredentials;
			syncSelectionFromValue(value);
			return;
		}

		providerLoading = true;
		try {
			const response = await wpFetch<
				LocalProviderCredential[] | RestEnvelope<LocalProviderCredential[]>
			>('local/providers/credentials', { showNotifications: false });
			const credentials = unwrapRestResponse<LocalProviderCredential[]>(response);
			loadedProviderCredentials = Array.isArray(credentials) ? credentials : [];
		} catch (e) {
			console.warn('Failed to load provider credentials for model selector', e);
			loadedProviderCredentials = [];
		} finally {
			providerLoading = false;
			syncSelectionFromValue(value);
		}
	}

	function selectedBackupValue(): string | null {
		if (selectedBackup === CUSTOM_BACKUP_VALUE) {
			return selectedCustomBackup.trim() || null;
		}

		return selectedBackup || null;
	}

	function selectedPrimaryValue(): string {
		if (selectionMode === 'presets') return selectedPreset;
		if (selectionMode === 'custom') return selectedCustomModel.trim();
		return selectedModel;
	}

	function selectedPrimaryModelInfo(): ModelInfo | null {
		const primary = selectionMode === 'presets' ? resolvedModelForPreset(selectedPreset) : selectedModel;
		return models.find((model) => model.id === primary) ?? null;
	}

	function selectedModelAllowsReasoning(): boolean {
		return Boolean(selectedPrimaryModelInfo()?.capabilities.reasoning);
	}

	function currentSelection(): ModelSelection {
		const includeReasoning =
			selectionMode !== 'presets' && selectedReasoning !== 'default' && selectedModelAllowsReasoning();
		return {
			primary: selectedPrimaryValue(),
			backup: selectedBackupValue(),
			is_preset: selectionMode === 'presets',
			provider: selectedProvider,
			credential_id: selectedCredentialId,
			...(includeReasoning ? { reasoning: selectedReasoning } : {})
		};
	}

	function selectionSignature(selection: ModelSelection | null | undefined): string {
		return JSON.stringify(selection ?? null);
	}

	function selectionDiffersFromValue(selection: ModelSelection): boolean {
		return selectionSignature(selection) !== selectionSignature(value ?? null);
	}

	function emitSelectionChange(selection: ModelSelection) {
		lastEmittedSelectionSignature = selectionSignature(selection);
		onchange?.(selection);
	}

	function handleSelectionChange() {
		const selection = currentSelection();
		if (!selection.primary.trim()) return;
		void resolveSelectionPreview(selection);
		emitSelectionChange(selection);
	}

	function handleProviderChange() {
		selectedCredentialId = defaultCredentialIdForProvider(selectedProvider);
		selectedReasoning = 'default';
		handleSelectionChange();
	}

	function choosePreset(preset: ModelPreset) {
		selectionMode = 'presets';
		selectedPreset = preset.code;
		selectedModel = normalizeSelectedModel(preset.resolved_model_id);
		selectedReasoning = 'default';
		handleSelectionChange();
	}

	function chooseModel(model: ModelInfo) {
		if (isModelLocked(model)) return;
		selectionMode = 'models';
		selectedModel = model.id;
		selectedCustomModel = '';
		if (!model.capabilities.reasoning) selectedReasoning = 'default';
		handleSelectionChange();
	}

	function applyCustomModel() {
		if (!customModelLooksValid(selectedCustomModel)) return;
		selectionMode = 'custom';
		selectedReasoning = 'default';
		handleSelectionChange();
	}

	function buildSelectionPayload(selection: ModelSelection) {
		return {
			template_model_hint: templateModelHint ?? undefined,
			global_selection: level === 'global' ? selection : (globalSelection ?? undefined),
			action_selection: level === 'action' ? selection : (actionSelection ?? undefined),
			form_selection: level === 'form' ? selection : (formSelection ?? undefined),
			mapping_selection: level === 'mapping' ? selection : (mappingSelection ?? undefined)
		};
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
			>('models/resolve', {
				method: 'POST',
				body: selectionPayload,
				showNotifications: false
			});

			if (requestToken !== resolutionRequestToken) return;

			resolved = normalizeResolvedModel(
				unwrapRestResponse<ResolvedModelSelection | Record<string, unknown>>(resolvedResponse)
			);

			if (actionId) {
				const estimateResponse = await wpFetch<
					ModelEstimateResponse | RestEnvelope<ModelEstimateResponse>
				>('models/estimate', {
					method: 'POST',
					body: {
						action_id: actionId,
						template_model_hint: templateModelHint ?? undefined,
						base_credit_cost: baseCreditCost ?? undefined,
						...selectionPayload
					},
					showNotifications: false
				});

				if (requestToken !== resolutionRequestToken) return;

				const estimate = unwrapRestResponse<ModelEstimateResponse>(estimateResponse);
				const estimateResolved = normalizeResolvedModel(estimate?.resolved_model);
				if (estimateResolved) resolved = estimateResolved;
				pricingEstimate = estimate?.pricing_estimate ?? null;
			} else {
				pricingEstimate = null;
			}
		} catch (e) {
			if (requestToken !== resolutionRequestToken) return;
			console.error('Failed to resolve model selection', e);
			resolutionError = e instanceof Error ? e.message : 'Failed to resolve model selection';
			pricingEstimate = null;
		} finally {
			if (requestToken === resolutionRequestToken) resolving = false;
		}
	}

	const providerRouteOptions = $derived.by(() => {
		const options: Array<{ value: string; label: string }> = [];
		if (
			providerAllowed(MANAGED_PROVIDER) &&
			(credentialsForProvider(MANAGED_PROVIDER).length > 0 || selectedProvider === MANAGED_PROVIDER)
		) {
			options.push({ value: MANAGED_PROVIDER, label: providerRouteLabel(MANAGED_PROVIDER) });
		}
		if (
			providerAllowed(OPENROUTER_PROVIDER) &&
			(credentialsForProvider(OPENROUTER_PROVIDER).length > 0 || selectedProvider === OPENROUTER_PROVIDER)
		) {
			options.push({ value: OPENROUTER_PROVIDER, label: providerRouteLabel(OPENROUTER_PROVIDER) });
		}
		return options.length > 0
			? options
			: [{ value: OPENROUTER_PROVIDER, label: providerRouteLabel(OPENROUTER_PROVIDER) }];
	});

	const selectedRouteCredential = $derived(
		readyCredentials().find((credential) => credential.id === selectedCredentialId) ?? null
	);

	const lockedModelCount = $derived(models.filter((model) => isModelLocked(model)).length);

	const filteredModels = $derived.by(() => {
		const search = searchTerm.trim().toLowerCase();
		return models.filter((model) => {
			const locked = isModelLocked(model);
			if (costFilter === 'free' && !modelIsFree(model)) return false;
			if (costFilter === 'paid' && !modelIsPaid(model)) return false;
			if (costFilter === 'locked' && !locked) return false;
			if (!modelHasCapability(model, capabilityFilter)) {
				return false;
			}
			if (!search) return true;
			const haystack = [
				model.id,
				model.display_name,
				model.provider_family,
				model.developer,
				...(model.recommended_for ?? []),
				...(model.recommendation_categories ?? []),
				...(model.tags ?? [])
			]
				.filter(Boolean)
				.join(' ')
				.toLowerCase();
			return haystack.includes(search);
		});
	});

	const visibleModels = $derived(filteredModels.slice(0, MAX_VISIBLE_MODELS));
	const backupOptions = $derived([
		{ value: '', label: 'No backup' },
		...models.map((model) => ({
			value: model.id,
			label: `${model.display_name}${isModelLocked(model) ? ' (locked)' : ''}`,
			disabled: isModelLocked(model)
		})),
		{ value: CUSTOM_BACKUP_VALUE, label: 'Custom backup model...' }
	]);

	function capabilityItems(model: ModelInfo) {
		const items: CapabilityItem[] = [
			['reasoning', 'Reasoning', 'R', model.capabilities.reasoning],
			['vision', 'Vision input', 'V', model.capabilities.vision],
			['files', 'File/PDF input', 'F', Boolean(model.capabilities.files)],
			['tools', 'Tool calling', 'T', model.capabilities.tools],
			['structured', 'Structured output', '{}', Boolean(model.capabilities.structured)],
			['web_search', 'Web search cost available', 'W', Boolean(model.capabilities.web_search)],
			['long_context', 'Long context', 'L', model.capabilities.long_context],
			['code', 'Coding strength', '</>', model.capabilities.code]
		];
		return items.filter((item) => item[3]);
	}

	function contextLabel(model: ModelInfo): string {
		if (!model.context_window) return 'Unknown context';
		if (model.context_window >= 1000000) return `${(model.context_window / 1000000).toFixed(1)}M context`;
		return `${Math.round(model.context_window / 1000).toLocaleString()}K context`;
	}

	function priceLabel(model: ModelInfo): string {
		if (modelIsFree(model)) return 'Free';
		const prompt = model.pricing?.prompt;
		const completion = model.pricing?.completion;
		if (!prompt || !completion || Number(prompt) < 0 || Number(completion) < 0) return 'Variable';
		const input = Number(prompt) * 1000000;
		const output = Number(completion) * 1000000;
		return `$${input.toLocaleString(undefined, { maximumFractionDigits: 3 })}/$${output.toLocaleString(undefined, { maximumFractionDigits: 3 })} per 1M`;
	}

	function costBadgeVariant(tier: string) {
		switch (tier) {
			case 'free':
				return 'success';
			case 'low':
				return 'info';
			case 'medium':
				return 'warning';
			case 'high':
			case 'premium':
				return 'danger';
			default:
				return 'neutral';
		}
	}

	function customModelLooksValid(modelId: string): boolean {
		const trimmed = modelId.trim();
		return (
			/^[a-z0-9_.-]+\/[a-z0-9_.:-]+$/i.test(trimmed) ||
			/^~[a-z0-9_.-]+\/[a-z0-9_.:-]+$/i.test(trimmed)
		);
	}

	onMount(() => {
		void loadModels();
		void loadProviderCredentials();
	});

	$effect(() => {
		syncSelectionFromValue(value);
	});

	$effect(() => {
		if (Array.isArray(providerCredentials)) {
			loadedProviderCredentials = providerCredentials;
			syncSelectionFromValue(value);
		}
	});

	$effect(() => {
		const selection = currentSelection();
		void resolveSelectionPreview(selection);
		if (
			!readonly &&
			selection.primary.trim() &&
			selectionDiffersFromValue(selection) &&
			selectionSignature(selection) !== lastEmittedSelectionSignature
		) {
			emitSelectionChange(selection);
		}
	});

	$effect(() => {
		if (!selectedModelAllowsReasoning() && selectedReasoning !== 'default') {
			selectedReasoning = 'default';
		}
	});
</script>

<div class="sf:space-y-4" data-testid="model-selector">
	<div class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between">
		<div>
			<p class="sf:text-sm sf:font-semibold sf:text-slate-800">{label}</p>
			<p class="sf:text-xs sf:text-slate-500">
				Choose a recommended preset, a specific OpenRouter model, or a custom model id.
			</p>
		</div>
		{#if !readonly}
			<div class="sf:flex sf:flex-wrap sf:gap-2" role="tablist" aria-label="Model selection mode">
				<Button
					size="sm"
					variant={selectionMode === 'presets' ? 'primary' : 'secondary'}
					onclick={() => {
						selectionMode = 'presets';
						handleSelectionChange();
					}}
					data-testid="model-selector-tab-presets"
				>
					Presets
				</Button>
				<Button
					size="sm"
					variant={selectionMode === 'models' ? 'primary' : 'secondary'}
					onclick={() => {
						selectionMode = 'models';
						selectedModel = normalizeSelectedModel(selectedModel || resolvedModelForPreset(selectedPreset));
						handleSelectionChange();
					}}
					data-testid="model-selector-tab-models"
				>
					All models
				</Button>
				<Button
					size="sm"
					variant={selectionMode === 'custom' ? 'primary' : 'secondary'}
					onclick={() => {
						selectionMode = 'custom';
					}}
					data-testid="model-selector-tab-custom"
				>
					Custom
				</Button>
			</div>
		{/if}
	</div>

	{#if loading}
		<p class="sf:text-sm sf:text-slate-600">Loading models...</p>
	{:else if error}
		<Alert variant="danger">{error}</Alert>
	{:else if readonly && resolved}
		<Card class="sf:bg-slate-50">
			<div class="sf:flex sf:items-center sf:justify-between sf:gap-3">
				<div>
					<p class="sf:font-medium sf:text-slate-800">{resolved.display_name}</p>
					<p class="sf:text-xs sf:text-slate-500">Resolved from: {resolved.resolution_source}</p>
				</div>
				{#if resolved.backup_model_id}
					<Badge variant="info">Backup: {resolved.backup_model_id}</Badge>
				{/if}
			</div>
		</Card>
	{:else}
		<Alert variant={lockedModelCount > 0 ? 'info' : 'warning'}>
			{#if selectedProvider === MANAGED_PROVIDER}
				Sentient Forms managed service can use paid models with managed metering and spend controls.
			{:else if hasSelectedProviderPaidRoute()}
				This route can use free and paid OpenRouter models through the selected local credential.
			{:else}
				Free models remain selectable for workflow proof. Paid models are visible but locked until a paid OpenRouter key or Sentient Forms managed service is ready.
			{/if}
		</Alert>

		{#if !readonly && (providerLoading || providerRouteOptions.length > 0)}
			<div class="sf:grid sf:gap-2 sf:md:grid-cols-[minmax(0,1fr)_auto] sf:md:items-end">
				<SelectField
					id={`model-provider-${level}`}
					label="Execution route"
					options={providerRouteOptions}
					bind:value={selectedProvider}
					onchange={handleProviderChange}
				/>
				<div class="sf:text-xs sf:text-slate-500 sf:md:text-right">
					{#if selectedRouteCredential}
						Ready: <strong>{selectedRouteCredential.label}</strong>
					{:else if selectedProvider === MANAGED_PROVIDER}
						Managed service credential required.
					{:else}
						Free/router path only until a key is ready.
					{/if}
				</div>
			</div>
		{/if}

		{#if selectionMode === 'presets'}
			<div class="sf:grid sf:gap-2 sf:lg:grid-cols-2" data-testid="model-selector-presets">
				{#each presets as preset}
					{@const model = models.find((candidate) => candidate.id === preset.resolved_model_id) ?? null}
					{@const locked = model ? isModelLocked(model) : false}
					<Button
						variant={selectedPreset === preset.code ? 'primary' : 'secondary'}
						class="sf:h-auto sf:w-full sf:justify-start sf:px-3 sf:py-3 sf:text-left"
						disabled={locked}
						title={model ? lockTitle(model) : preset.description}
						onclick={() => choosePreset(preset)}
						data-testid={`model-preset-${preset.code}`}
					>
						<span class="sf:flex sf:w-full sf:flex-col sf:gap-2">
							<span class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
								<span class="sf:font-semibold">{preset.display_name}</span>
								{#if locked}
									<Badge variant="warning">Locked</Badge>
								{:else if model && modelIsFree(model)}
									<Badge variant="success">Free</Badge>
								{:else}
									<Badge variant="info">Paid-ready</Badge>
								{/if}
							</span>
							<span class="sf:text-xs sf:font-normal sf:opacity-90">
								{preset.description}
							</span>
							<span class="sf:flex sf:flex-wrap sf:items-center sf:gap-2 sf:text-xs sf:font-normal">
								<span class="sf:font-mono">{preset.resolved_model_id}</span>
								{#if model}
									<span>{contextLabel(model)}</span>
									<span>{priceLabel(model)}</span>
								{/if}
							</span>
						</span>
					</Button>
				{/each}
			</div>
		{:else if selectionMode === 'models'}
			<div class="sf:space-y-3" data-testid="model-selector-catalog">
				<div class="sf:grid sf:gap-2 sf:lg:grid-cols-[minmax(0,1fr)_12rem_12rem]">
					<label class="sf:flex sf:flex-col sf:gap-1">
						<span class="sf:text-xs sf:font-semibold sf:text-slate-700">Search models</span>
						<input
							class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							placeholder="Search by model, provider, category..."
							value={searchTerm}
							oninput={(event) => {
								searchTerm = (event.currentTarget as HTMLInputElement).value;
							}}
							data-testid="model-search-input"
						/>
					</label>
					<SelectField
						id={`model-cost-filter-${level}`}
						label="Cost"
						options={costFilterOptions}
						bind:value={costFilter}
					/>
					<SelectField
						id={`model-capability-filter-${level}`}
						label="Capability"
						options={capabilityFilterOptions}
						bind:value={capabilityFilter}
					/>
				</div>

				<p class="sf:text-xs sf:text-slate-500">
					Showing {visibleModels.length} of {filteredModels.length} matching models. Use search to jump to newer OpenRouter ids.
				</p>

				<div class="sf:max-h-96 sf:space-y-2 sf:overflow-y-auto sf:pr-1">
					{#each visibleModels as model}
						{@const locked = isModelLocked(model)}
						<div
							class={[
								'sf:rounded-md sf:border sf:p-3 sf:transition',
								selectedModel === model.id ? 'sf:border-primary-500 sf:bg-primary-50' : 'sf:border-slate-200 sf:bg-white',
								locked ? 'sf:opacity-70' : ''
							].join(' ')}
							data-testid={`model-row-${model.id}`}
						>
							<div class="sf:flex sf:flex-col sf:gap-3 sf:lg:flex-row sf:lg:items-start sf:lg:justify-between">
								<div class="sf:min-w-0 sf:space-y-2">
									<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
										<p class="sf:min-w-0 sf:break-words sf:text-sm sf:font-semibold sf:text-slate-900">
											{model.display_name}
										</p>
										<Badge variant={costBadgeVariant(model.cost_tier)}>
											{modelIsFree(model) ? 'Free' : model.cost_tier}
										</Badge>
										{#if locked}
											<Badge variant="warning">Paid model</Badge>
										{/if}
										{#if model.is_preview}
											<Badge variant="warning">Preview</Badge>
										{/if}
									</div>
									<p class="sf:break-all sf:font-mono sf:text-xs sf:text-slate-600">{model.id}</p>
									<div class="sf:flex sf:flex-wrap sf:gap-1">
										{#each capabilityItems(model) as item}
											<span
												class="sf:inline-flex sf:min-h-7 sf:min-w-7 sf:items-center sf:justify-center sf:rounded-full sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-2 sf:text-[11px] sf:font-semibold sf:text-slate-700"
													title={item[1]}
													aria-label={item[1]}
												>
													{item[2]}
												</span>
										{/each}
									</div>
									<p class="sf:text-xs sf:text-slate-500">
										{contextLabel(model)} · {priceLabel(model)}
									</p>
								</div>
									<Button
										size="sm"
										variant={selectedModel === model.id ? 'primary' : 'secondary'}
										disabled={locked}
										title={lockTitle(model)}
										onclick={() => chooseModel(model)}
									>
										{locked ? 'Locked' : selectedModel === model.id ? 'Selected' : 'Use model'}
									</Button>
								</div>
							</div>
						{/each}
				</div>
			</div>
		{:else}
			<div class="sf:space-y-3" data-testid="model-selector-custom">
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-xs sf:font-semibold sf:text-slate-700">Custom OpenRouter model id</span>
					<input
						class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:font-mono sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
						placeholder="provider/model or ~provider/latest"
						value={selectedCustomModel}
						oninput={(event) => {
							selectedCustomModel = (event.currentTarget as HTMLInputElement).value;
						}}
						data-testid="model-custom-input"
					/>
				</label>
				<p class="sf:text-xs sf:text-slate-500">
					Use this when OpenRouter ships a model before Sentient Forms can bundle an updated catalog.
				</p>
				{#if selectedCustomModel.trim() && !customModelLooksValid(selectedCustomModel)}
					<Alert variant="warning">Use the OpenRouter id format, for example provider/model.</Alert>
				{/if}
				<Button
					size="sm"
					variant="primary"
					disabled={!customModelLooksValid(selectedCustomModel)}
					onclick={applyCustomModel}
				>
					Use custom model
				</Button>
			</div>
		{/if}

		{#if selectionMode !== 'presets'}
			<div class="sf:grid sf:gap-3 sf:lg:grid-cols-2">
				{#if selectedModelAllowsReasoning()}
					<SelectField
						id={`model-reasoning-${level}`}
						label="Reasoning effort"
						options={reasoningOptions}
						bind:value={selectedReasoning}
						onchange={handleSelectionChange}
					/>
				{/if}
				<div class="sf:space-y-2">
					<SelectField
						id={`model-backup-${level}`}
						label="Backup model"
						options={backupOptions}
						bind:value={selectedBackup}
						onchange={handleSelectionChange}
					/>
					{#if selectedBackup === CUSTOM_BACKUP_VALUE}
						<input
							class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:font-mono sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							placeholder="provider/model"
							value={selectedCustomBackup}
							oninput={(event) => {
								selectedCustomBackup = (event.currentTarget as HTMLInputElement).value;
								handleSelectionChange();
							}}
						/>
					{/if}
				</div>
			</div>
		{/if}

		{#if resolutionError}
			<Alert variant="warning">Could not load the local resolved model preview. {resolutionError}</Alert>
		{:else if resolving}
			<p class="sf:text-xs sf:text-slate-500">Refreshing resolved model and local cost preview...</p>
		{/if}

		{#if resolved}
			<Card class="sf:bg-slate-50" data-testid="model-resolution-card">
				<div class="sf:flex sf:flex-col sf:gap-2 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between">
					<div class="sf:space-y-1">
						<p class="sf:font-medium sf:text-slate-800">{resolved.display_name}</p>
						<p class="sf:text-xs sf:text-slate-500">Resolved from: {resolved.resolution_source}</p>
					</div>
					{#if pricingEstimate}
						<div class="sf:text-left sf:sm:text-right">
							<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500">Usage cost</p>
							<p class="sf:text-lg sf:font-semibold sf:text-slate-900">
								{selectedProvider === MANAGED_PROVIDER ? 'SF managed' : 'OR direct'}
							</p>
						</div>
					{/if}
				</div>

				{#if pricingEstimate}
					<p class="sf:mt-2 sf:text-xs sf:text-slate-600">
						Managed credits estimate:
						<strong>{pricingEstimate.estimated_debit_credits} credits</strong> · Direct-provider estimate:
						<strong>{pricingEstimate.normalized_actual_credits}</strong> · Policy:
						{pricingEstimate.pricing_policy_version}
					</p>
				{/if}

				{#if resolved.override_chain.length > 0}
					<details class="sf:mt-3">
						<summary class="sf:cursor-pointer sf:text-xs sf:text-slate-600">Override chain</summary>
						<ul class="sf:mt-2 sf:space-y-1 sf:text-xs sf:text-slate-500">
							{#each resolved.override_chain as step}
								<li class="sf:flex sf:items-center sf:gap-2">
									<span class={step.applied ? 'sf:text-green-700' : 'sf:text-slate-400'}>
										{step.applied ? 'yes' : 'no'}
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
