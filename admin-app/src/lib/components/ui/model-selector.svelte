<script lang="ts">
	import { onMount, untrack } from 'svelte';
	import { Alert, Badge, Button, ReasoningEffortRail, SelectField } from '$lib/components/ui';
	import * as Popover from '$lib/components/ui/popover/index.js';
	import RefreshCwIcon from '@lucide/svelte/icons/refresh-cw';
	import type {
		LocalProvider,
		LocalProviderCredential,
		ModelCatalogResponse,
		ModelEstimateResponse,
		ModelInfo,
		ModelPricingEstimate,
		ModelPreset,
		OpenRouterModelRefreshConsentState,
		ModelSelection,
		ResolvedModelSelection
	} from '$lib/api/types';
	import { createClientFromConfig } from '$lib/api/client';
	import { OPENROUTER_DISCLOSURE_VERSION } from '$lib/constants/external-services';
	import { notifications } from '$lib/stores/notifications';
	import {
		MODEL_RANK_CATEGORIES,
		categoryLabel,
		categoryRankingItems,
		filterAndSortModels,
		isModelFree as modelIsFree,
		missingRequiredCapabilities,
		modelSelectorZdrControl,
		modelCapabilityCount,
		modelCostLabel,
		modelBestRank,
		modelSupportsToolChoice,
		modelSupportsServerTool,
		priceSymbolFromTier,
		providerDisplayName,
		providerKey,
		providerMonogram,
		type ModelSelectorCapabilityKey,
		type ModelSelectorCostLimit,
		type ModelSelectorSortMode
	} from '$lib/utils/model-selector-presentation';
	import {
		reasoningOptionsForModel,
		modelSupportsReasoning,
		normalizeModelReasoningEffort,
		type ModelReasoningControlValue,
		type ModelReasoningEffort
	} from '$lib/utils/model-selection';
	import { loadSharedManagedZdrRequirement } from '$lib/utils/managed-zdr-requirement-cache';
	import { wpRequestEndpoint } from '$lib/wp';

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
		requiredCapabilities?: ModelSelectorCapabilityKey[] | null;
		lockRequiredCapabilities?: boolean;
		managedZdrRequired?: boolean | null;
		webSearchMaxResultsLimit?: number;
		onchange?: (selection: ModelSelection) => void;
	}

	type SelectionMode = 'presets' | 'models' | 'custom';
	type ToolMode = 'inherit' | 'off' | 'auto' | 'required';
	type ModelReasoningSettings = Exclude<NonNullable<ModelSelection['reasoning']>, string>;
	type CapabilityItem = readonly [key: string, label: string, short: string, active: boolean];
	type RankLimit = '0' | '3' | '5' | '10' | '25' | '50';
	type ContextLimit = '0' | '32000' | '128000' | '200000' | '1000000';

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
		requiredCapabilities: requiredCapabilitiesProp = null,
		lockRequiredCapabilities = false,
		managedZdrRequired = null,
		webSearchMaxResultsLimit = 10,
		onchange
	}: Props = $props();

	const READY_PROVIDER_STATUSES = new Set(['valid', 'limited']);
	const OPENROUTER_PROVIDER = 'openrouter';
	const MANAGED_PROVIDER = 'sentient_managed';
	const CUSTOM_BACKUP_VALUE = '__custom_backup__';
	const MAX_VISIBLE_MODELS = 80;
	const client = createClientFromConfig();

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
	let selectedReasoning = $state<ModelReasoningControlValue>('default');
	let savedReasoningSettings = $state<ModelReasoningSettings | null>(null);
	let savedReasoningSelectionKey = $state('');
	let toolChoiceMode = $state<ToolMode>('inherit');
	let webSearchMode = $state<ToolMode>('inherit');
	let webSearchMaxResults = $state(5);
	let webFetchMode = $state<ToolMode>('inherit');
	let datetimeMode = $state<ToolMode>('inherit');
	let selectedProvider = $state(OPENROUTER_PROVIDER);
	let selectedCredentialId = $state<number | null>(null);
	let searchTerm = $state('');
	let costLimit = $state<ModelSelectorCostLimit>('all');
	let providerFilter = $state('all');
	let categoryFilter = $state('all');
	let rankLimit = $state<RankLimit>('0');
	let contextLimit = $state<ContextLimit>('0');
	let sortMode = $state<ModelSelectorSortMode>('name');
	let zdrOnly = $state(false);
	let explicitRequireZdr = $state(false);
	let requiredCapabilities = $state<Set<ModelSelectorCapabilityKey>>(
		new Set(requiredCapabilitiesProp ?? [])
	);
	let advancedFiltersOpen = $state(false);
	let isPickerOpen = $state(false);
	let highlightedModelId = $state<string | null>(null);
	let highlightedPresetCode = $state<string | null>(null);
	let error = $state<string | null>(null);
	let refreshDisclosureOpen = $state(false);
	let refreshDisclosureAccepted = $state(false);
	let refreshConsentState = $state<OpenRouterModelRefreshConsentState | null>(null);
	let refreshCatalogError = $state<string | null>(null);
	let refreshingCatalog = $state(false);
	let resolved = $state<ResolvedModelSelection | null>(null);
	let pricingEstimate = $state<ModelPricingEstimate | null>(null);
	let loadedManagedZdrRequired = $state(false);
	let resolving = $state(false);
	let resolutionError = $state<string | null>(null);
	let resolutionRequestToken = 0;
	let lastEmittedSelectionSignature = '';
	let lastSyncedExternalValueSignature = '';

	const fallbackModel: ModelInfo = {
		id: 'openrouter/auto',
		display_name: 'OpenRouter Auto',
		provider: OPENROUTER_PROVIDER,
		speed_tier: 'balanced',
		cost_tier: 'unknown',
		cost_symbol: 'Varies',
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

	const fallbackReasoningOptions: Array<{
		value: 'default' | ModelReasoningEffort;
		label: string;
	}> = [
		{ value: 'default', label: 'Model default' },
		{ value: 'none', label: 'Off' },
		{ value: 'minimal', label: 'Minimal' },
		{ value: 'low', label: 'Low' },
		{ value: 'medium', label: 'Medium' },
		{ value: 'high', label: 'High' },
		{ value: 'xhigh', label: 'Extra high' }
	];

	const toolModeOptions = [
		{ value: 'inherit', label: 'Inherit' },
		{ value: 'off', label: 'Off' },
		{ value: 'auto', label: 'Let model decide' },
		{ value: 'required', label: 'Require when available' }
	];

	const toolChoiceOptions = [
		{ value: 'inherit', label: 'Model default' },
		{ value: 'off', label: 'No tools' },
		{ value: 'auto', label: 'Auto' },
		{ value: 'required', label: 'Required' }
	];

	const costLimitOptions = [
		{ value: 'all', label: 'All models' },
		{ value: 'free', label: 'Free' },
		{ value: 'low', label: '$ or less' },
		{ value: 'medium', label: '$$ or less' },
		{ value: 'high', label: '$$$ or less' },
		{ value: 'premium', label: '$$$$ or less' }
	];

	const sortOptions = [
		{ value: 'name', label: 'Name A-Z' },
		{ value: 'provider', label: 'Provider A-Z' },
		{ value: 'cost', label: 'Lowest cost' },
		{ value: 'context', label: 'Largest context' },
		{ value: 'newest', label: 'Newest' },
		{ value: 'capabilities', label: 'Most capabilities' },
		{ value: 'rank', label: 'Best category rank' }
	];

	const rankLimitOptions = [
		{ value: '0', label: 'Any rank' },
		{ value: '3', label: 'Top 3' },
		{ value: '5', label: 'Top 5' },
		{ value: '10', label: 'Top 10' },
		{ value: '25', label: 'Top 25' },
		{ value: '50', label: 'Top 50' }
	];

	const contextLimitOptions = [
		{ value: '0', label: 'Any context' },
		{ value: '32000', label: '32K+' },
		{ value: '128000', label: '128K+' },
		{ value: '200000', label: '200K+' },
		{ value: '1000000', label: '1M+' }
	];

	const capabilityFilterOptions: Array<{
		value: ModelSelectorCapabilityKey;
		label: string;
		short: string;
	}> = [
		{ value: 'reasoning', label: 'Reasoning', short: 'R' },
		{ value: 'structured', label: 'Structured output', short: '{}' },
		{ value: 'tools', label: 'Tool calling', short: 'T' },
		{ value: 'vision', label: 'Vision', short: 'V' },
		{ value: 'files', label: 'Files/PDF', short: 'F' },
		{ value: 'web_search', label: 'Web search', short: 'W' },
		{ value: 'long_context', label: 'Long context', short: 'L' },
		{ value: 'code', label: 'Coding', short: '</>' }
	];

	function capabilityLabel(capability: ModelSelectorCapabilityKey): string {
		return (
			capabilityFilterOptions.find((option) => option.value === capability)?.label ?? capability
		);
	}

	function capabilityListLabel(capabilities: ModelSelectorCapabilityKey[]): string {
		return capabilities.map(capabilityLabel).join(', ');
	}

	function isRecord(value: unknown): value is Record<string, unknown> {
		return Boolean(value && typeof value === 'object' && !Array.isArray(value));
	}

	function normalizeReasoningSettings(value: unknown): ModelReasoningSettings | null {
		if (!isRecord(value)) return null;

		const settings: ModelReasoningSettings = {};
		const effort = normalizeModelReasoningEffort(value.effort);
		if (effort) settings.effort = effort;

		const maxTokens = Number(value.max_tokens);
		if (Number.isFinite(maxTokens) && maxTokens > 0) {
			settings.max_tokens = Math.round(maxTokens);
		}

		if (typeof value.exclude === 'boolean') settings.exclude = value.exclude;
		if (typeof value.enabled === 'boolean') settings.enabled = value.enabled;

		return Object.keys(settings).length > 0 ? settings : null;
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
			typeof candidate.resolution_source === 'string' ? candidate.resolution_source : 'unavailable';

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
		if (provider === MANAGED_PROVIDER) return 'Sentient Forms Managed Service';
		if (provider === OPENROUTER_PROVIDER) return 'Bring your own OpenRouter key';
		return provider;
	}

	function hasSelectedProviderPaidRoute(): boolean {
		return credentialsForProvider(selectedProvider).length > 0;
	}

	function modelIsPaid(model: ModelInfo): boolean {
		return !modelIsFree(model) && model.id !== 'openrouter/auto';
	}

	function modelIsZdrEligible(model: ModelInfo | null | undefined): boolean {
		return model?.zdr_eligible === true;
	}

	function isModelLocked(model: ModelInfo): boolean {
		return modelIsPaid(model) && !hasSelectedProviderPaidRoute();
	}

	function lockTitle(model: ModelInfo): string {
		return isModelLocked(model)
			? 'Paid OpenRouter model. Unlock with Sentient Forms Managed Service or your own paid OpenRouter key.'
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

		return presets.some((preset) => preset.code === 'sf_default')
			? 'sf_default'
			: (presets[0]?.code ?? 'sf_default');
	}

	function presetIsLocked(code: string): boolean {
		const modelId = resolvedModelForPreset(code);
		const model = models.find((candidate) => candidate.id === modelId) ?? null;
		return model ? isModelLocked(model) : false;
	}

	function syncSelectionFromValue(
		nextValue: ModelSelection | null | undefined,
		{ force = false }: { force?: boolean } = {}
	) {
		const externalValueSignature = selectionSignature(nextValue ?? null);
		if (!force && externalValueSignature === lastSyncedExternalValueSignature) return;
		lastSyncedExternalValueSignature = externalValueSignature;

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
			savedReasoningSettings = null;
			savedReasoningSelectionKey = '';
			zdrOnly = effectiveManagedZdrRequired;
			explicitRequireZdr = false;
			syncToolSettings(null);
			return;
		}

		if (nextValue.is_preset) {
			selectionMode = 'presets';
			const requestedPreset = nextValue.primary || defaultPresetCode();
			const providerWasExplicit =
				typeof nextValue.provider === 'string' && nextValue.provider.trim().length > 0;
			selectedPreset =
				!providerWasExplicit && presetIsLocked(requestedPreset)
					? defaultPresetCode()
					: requestedPreset;
			selectedModel = resolvedModelForPreset(selectedPreset);
		} else if (models.some((model) => model.id === nextValue.primary)) {
			selectionMode = 'models';
			selectedModel = nextValue.primary;
			selectedCustomModel = '';
		} else {
			if (customModelAllowedFromSavedSelection(nextValue.primary)) {
				selectionMode = 'custom';
				selectedCustomModel = nextValue.primary;
				selectedModel = normalizeSelectedModel('');
			} else {
				selectionMode = 'presets';
				selectedPreset = defaultPresetCode();
				selectedModel = normalizeSelectedModel(resolvedModelForPreset(selectedPreset));
				selectedCustomModel = nextValue.primary;
			}
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

		selectedReasoning = normalizeModelReasoningEffort(nextValue.reasoning) ?? 'default';
		savedReasoningSettings = normalizeReasoningSettings(nextValue.reasoning);
		savedReasoningSelectionKey = savedReasoningSettings ? reasoningSelectionKey(nextValue) : '';
		explicitRequireZdr = selectedProvider === MANAGED_PROVIDER && nextValue.require_zdr === true;
		zdrOnly =
			effectiveManagedZdrRequired || (selectedProvider === MANAGED_PROVIDER && explicitRequireZdr);
		syncToolSettings(nextValue.tools);
		if (!readonly && clearUnsupportedToolSelections()) {
			const sanitizedSelection = currentSelection();
			if (
				sanitizedSelection.primary.trim() &&
				selectionSignature(sanitizedSelection) !== lastEmittedSelectionSignature
			) {
				emitSelectionChange(sanitizedSelection);
			}
		}
	}

	function normalizeToolMode(value: unknown): ToolMode {
		return value === 'off' || value === 'auto' || value === 'required' ? value : 'inherit';
	}

	const effectiveWebSearchMaxResultsLimit = $derived(
		Math.max(1, Math.min(10, Math.round(webSearchMaxResultsLimit || 10)))
	);

	function clampWebSearchMaxResults(value: unknown): number {
		const numeric = Number(value);
		if (!Number.isFinite(numeric)) return Math.min(5, effectiveWebSearchMaxResultsLimit);
		return Math.max(1, Math.min(effectiveWebSearchMaxResultsLimit, Math.round(numeric)));
	}

	function syncToolSettings(value: unknown) {
		const tools = isRecord(value) ? value : {};
		toolChoiceMode = normalizeToolMode(tools.tool_choice);
		const webSearch = isRecord(tools.web_search) ? tools.web_search : {};
		const webFetch = isRecord(tools.web_fetch) ? tools.web_fetch : {};
		const datetime = isRecord(tools.datetime) ? tools.datetime : {};
		webSearchMode = normalizeToolMode(webSearch.mode);
		webFetchMode = normalizeToolMode(webFetch.mode);
		datetimeMode = normalizeToolMode(datetime.mode);
		const maxResults = Number(webSearch.max_results);
		webSearchMaxResults = clampWebSearchMaxResults(maxResults);
	}

	function clearUnsupportedToolSelections(
		model: ModelInfo | null = selectedPrimaryModelInfo()
	): boolean {
		if (selectedProvider !== OPENROUTER_PROVIDER || selectionMode === 'custom' || !model)
			return false;
		let changed = false;
		if (!modelSupportsServerTool(model, 'web_search') && webSearchMode !== 'inherit') {
			webSearchMode = 'inherit';
			changed = true;
		}
		if (!modelSupportsServerTool(model, 'web_fetch') && webFetchMode !== 'inherit') {
			webFetchMode = 'inherit';
			changed = true;
		}
		if (!modelSupportsServerTool(model, 'datetime') && datetimeMode !== 'inherit') {
			datetimeMode = 'inherit';
			changed = true;
		}
		if (!modelSupportsToolChoice(model) && toolChoiceMode !== 'inherit') {
			toolChoiceMode = 'inherit';
			changed = true;
		}
		return changed;
	}

	async function loadModels() {
		loading = true;
		error = null;
		try {
			const catalog = normalizeModelCatalog(await wpRequestEndpoint('models.catalog'));
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
			if (!isPickerOpen) syncSelectionFromValue(value, { force: true });
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
			if (!isPickerOpen) syncSelectionFromValue(value, { force: true });
			return;
		}

		providerLoading = true;
		try {
			const credentials = await wpRequestEndpoint('providers.credentials.list', {
				showNotifications: false
			});
			loadedProviderCredentials = credentials.map((credential) => ({
				id: credential.id,
				provider: credential.provider,
				label: credential.label,
				auth_mode: credential.auth_mode,
				constant_name: credential.constant_name ?? null,
				status: credential.status,
				status_json: credential.status_json ?? null,
				last_validated_at: credential.last_validated_at ?? null,
				secret_configured: credential.secret_configured,
				created_at: credential.created_at ?? null,
				updated_at: credential.updated_at ?? null
			}));
		} catch (e) {
			console.warn('Failed to load provider credentials for model selector', e);
			loadedProviderCredentials = [];
		} finally {
			providerLoading = false;
			if (!isPickerOpen) syncSelectionFromValue(value, { force: true });
		}
	}

	async function loadOpenRouterRefreshConsent() {
		try {
			const catalog = await client.getOpenRouterModels({ limit: 1 }, { showNotifications: false });
			refreshConsentState = catalog.refresh_consent ?? null;
			refreshDisclosureAccepted = refreshConsentState?.state === 'accepted';
		} catch (e) {
			console.warn('Failed to load OpenRouter refresh consent state', e);
		}
	}

	async function loadManagedZdrRequirement() {
		if (managedZdrRequired !== null) {
			loadedManagedZdrRequired = Boolean(managedZdrRequired);
			return;
		}

		try {
			loadedManagedZdrRequired = await loadSharedManagedZdrRequirement(() =>
				client.getSettings({ showNotifications: false })
			);
			if (!isPickerOpen) syncSelectionFromValue(value, { force: true });
		} catch (e) {
			console.warn('Failed to load managed ZDR requirement for model selector', e);
			loadedManagedZdrRequired = false;
		}
	}

	function modelRefreshErrorMessage(err: unknown): string {
		return err instanceof Error ? err.message : 'Unable to refresh the model catalog.';
	}

	function openModelCatalogRefresh() {
		refreshCatalogError = null;
		if (refreshConsentState?.state === 'accepted') {
			void refreshModelCatalog();
			return;
		}

		refreshDisclosureOpen = !refreshDisclosureOpen;
	}

	async function refreshModelCatalog() {
		refreshCatalogError = null;

		if (!refreshDisclosureAccepted) {
			refreshCatalogError = 'Accept the disclosure before refreshing OpenRouter model metadata.';
			refreshDisclosureOpen = true;
			return;
		}

		refreshingCatalog = true;
		try {
			const catalog = await client.refreshOpenRouterModels(
				{
					disclosure_version: OPENROUTER_DISCLOSURE_VERSION,
					accepted_external_service_terms: true,
					output_modalities: 'text'
				},
				{ showNotifications: false }
			);
			refreshConsentState =
				catalog.refresh_consent ??
				({
					state: 'accepted',
					disclosure_version: OPENROUTER_DISCLOSURE_VERSION,
					consent_id: catalog.consent_id ?? null,
					accepted_at: null
				} satisfies OpenRouterModelRefreshConsentState);
			refreshDisclosureAccepted = true;
			refreshDisclosureOpen = false;
			await loadModels();
			notifications.success('OpenRouter model catalog refreshed');
		} catch (e) {
			refreshCatalogError = modelRefreshErrorMessage(e);
		} finally {
			refreshingCatalog = false;
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

	function reasoningSelectionKeyFromParts(
		primary: string,
		isPreset: boolean,
		provider: string
	): string {
		return JSON.stringify({
			primary,
			is_preset: isPreset,
			provider
		});
	}

	function reasoningSelectionKey(selection: ModelSelection | null | undefined): string {
		return reasoningSelectionKeyFromParts(
			selection?.primary ?? '',
			selection?.is_preset === true,
			selection?.provider ?? OPENROUTER_PROVIDER
		);
	}

	function selectedPrimaryModelInfo(): ModelInfo | null {
		const primary =
			selectionMode === 'presets'
				? (resolved?.model_id ?? resolvedModelForPreset(selectedPreset))
				: selectionMode === 'custom'
					? selectedCustomModel.trim()
					: selectedModel;
		return models.find((model) => model.id === primary) ?? null;
	}

	function modelById(modelId: string | null | undefined): ModelInfo | null {
		if (!modelId) return null;
		return models.find((model) => model.id === modelId) ?? null;
	}

	function selectedConcreteModelId(): string {
		if (selectionMode === 'presets') return resolvedModelForPreset(selectedPreset);
		if (selectionMode === 'custom') return selectedCustomModel.trim();
		return selectedModel;
	}

	function presetDisplayName(code: string): string {
		const preset = presets.find((candidate) => candidate.code === code);
		if (preset?.display_name) return preset.display_name;
		if (code === 'sf_default') return 'Recommended';
		if (code === 'sf_free') return 'Free model';
		if (code.startsWith('sf_')) return `${code.replace(/^sf_/, '').replaceAll('_', ' ')} preset`;
		return code;
	}

	function catalogPresetModelId(code: string): string | null {
		return presets.find((preset) => preset.code === code)?.resolved_model_id ?? null;
	}

	function displayModelId(modelId: string | null | undefined): string {
		if (!modelId) return 'No model selected';
		if (modelId === 'openrouter/auto') return 'OpenRouter Auto';
		return modelId;
	}

	function selectedDisplayName(): string {
		if (selectionMode === 'presets') {
			return presetDisplayName(selectedPreset);
		}

		const model = modelById(selectedConcreteModelId());
		return model?.display_name ?? selectedConcreteModelId() ?? 'No model selected';
	}

	function selectedModelIdLabel(): string {
		if (selectionMode === 'presets') {
			const catalogModelId = catalogPresetModelId(selectedPreset);
			if (resolved?.model_id) return displayModelId(resolved.model_id);
			if (catalogModelId) return displayModelId(catalogModelId);
			if (loading) return 'Resolving preset...';
			return displayModelId(resolvedModelForPreset(selectedPreset));
		}
		return displayModelId(selectedConcreteModelId());
	}

	function activeDetailModel(): ModelInfo | null {
		if (
			selectionMode === 'presets' &&
			highlightedPresetCode === selectedPreset &&
			resolved?.model_id
		) {
			const resolvedModel = modelById(resolved.model_id);
			if (resolvedModel) return resolvedModel;
		}

		return modelById(highlightedModelId) ?? modelById(selectedConcreteModelId());
	}

	function activePreset(): ModelPreset | null {
		return (
			presets.find((preset) => preset.code === highlightedPresetCode) ??
			presets.find((preset) => preset.code === selectedPreset) ??
			null
		);
	}

	function presetTopCandidates(preset: ModelPreset | null) {
		return (preset?.top_candidates ?? [])
			.filter(
				(candidate) => typeof candidate.score === 'number' && Number.isFinite(candidate.score)
			)
			.slice(0, 3);
	}

	function candidateModelLabel(modelId: string): string {
		return modelById(modelId)?.display_name ?? modelId;
	}

	function selectedModelAllowsReasoning(): boolean {
		return modelSupportsReasoning(selectedPrimaryModelInfo());
	}

	function modelIdAllowsReasoning(modelId: string | null | undefined): boolean {
		return modelSupportsReasoning(modelById(modelId));
	}

	function selectedModelNeedsReasoningMetadataHint(): boolean {
		return (
			selectionMode === 'custom' &&
			selectedCustomModel.trim().length > 0 &&
			customModelLooksValid(selectedCustomModel) &&
			!selectedModelAllowsReasoning()
		);
	}

	function selectedModelSupportsTools(): boolean {
		if (selectionMode === 'custom') return true;
		const model = selectedPrimaryModelInfo();
		return Boolean(model?.capabilities.tools || model?.capabilities.web_search);
	}

	function selectedModelSupportsToolChoice(): boolean {
		if (selectedProvider !== OPENROUTER_PROVIDER) return false;
		if (selectionMode === 'custom') return true;
		return modelSupportsToolChoice(selectedPrimaryModelInfo());
	}

	function selectedModelSupportsWebSearch(): boolean {
		if (selectedProvider !== OPENROUTER_PROVIDER) return false;
		if (selectionMode === 'custom') return true;
		return modelSupportsServerTool(selectedPrimaryModelInfo(), 'web_search');
	}

	function selectedModelSupportsWebFetch(): boolean {
		if (selectedProvider !== OPENROUTER_PROVIDER) return false;
		if (selectionMode === 'custom') return true;
		return modelSupportsServerTool(selectedPrimaryModelInfo(), 'web_fetch');
	}

	function selectedModelSupportsDatetime(): boolean {
		if (selectedProvider !== OPENROUTER_PROVIDER) return false;
		if (selectionMode === 'custom') return true;
		return modelSupportsServerTool(selectedPrimaryModelInfo(), 'datetime');
	}

	function currentToolSettings(): Record<string, unknown> | null {
		if (selectedProvider !== OPENROUTER_PROVIDER) return null;

		const tools: Record<string, unknown> = {};
		if (toolChoiceMode !== 'inherit' && selectedModelSupportsToolChoice()) {
			tools.tool_choice = toolChoiceMode;
		}
		if (webSearchMode !== 'inherit' && selectedModelSupportsWebSearch()) {
			tools.web_search = {
				mode: webSearchMode,
				max_results: clampWebSearchMaxResults(webSearchMaxResults)
			};
		}
		if (webFetchMode !== 'inherit' && selectedModelSupportsWebFetch()) {
			tools.web_fetch = { mode: webFetchMode };
		}
		if (datetimeMode !== 'inherit' && selectedModelSupportsDatetime()) {
			tools.datetime = { mode: datetimeMode };
		}

		return Object.keys(tools).length > 0 ? tools : null;
	}

	function currentReasoningSettings(): ModelSelection['reasoning'] | undefined {
		if (!selectedModelAllowsReasoning()) return undefined;

		const currentKey = reasoningSelectionKeyFromParts(
			selectedPrimaryValue(),
			selectionMode === 'presets',
			selectedProvider
		);
		const savedEffort = normalizeModelReasoningEffort(savedReasoningSettings);

		if (savedReasoningSettings && currentKey === savedReasoningSelectionKey) {
			if (selectedReasoning === 'default') {
				return savedEffort ? undefined : savedReasoningSettings;
			}

			if (selectedReasoning === savedEffort) {
				return {
					...savedReasoningSettings,
					effort: selectedReasoning
				};
			}
		}

		return selectedReasoning === 'default' ? undefined : selectedReasoning;
	}

	function currentSelection(): ModelSelection {
		const reasoning = currentReasoningSettings();
		const tools = currentToolSettings();
		const requireZdr =
			selectedProvider === MANAGED_PROVIDER &&
			(explicitRequireZdr || (zdrOnly === true && !effectiveManagedZdrRequired));
		return {
			primary: selectedPrimaryValue(),
			backup: selectedBackupValue(),
			is_preset: selectionMode === 'presets',
			provider: selectedProvider,
			credential_id: selectedCredentialId,
			...(requireZdr ? { require_zdr: true } : {}),
			...(reasoning ? { reasoning } : {}),
			...(tools ? { tools } : {})
		};
	}

	function selectionSignature(selection: ModelSelection | null | undefined): string {
		return JSON.stringify(selection ?? null);
	}

	function selectionDiffersFromValue(selection: ModelSelection): boolean {
		return selectionSignature(selection) !== selectionSignature(value ?? null);
	}

	function currentSelectionDependencySignature(): string {
		return JSON.stringify({
			selectionMode,
			selectedPreset,
			selectedModel,
			selectedBackup,
			selectedCustomModel,
			selectedCustomBackup,
			selectedReasoning,
			savedReasoningSettings,
			savedReasoningSelectionKey,
			toolChoiceMode,
			webSearchMode,
			webSearchMaxResults,
			webFetchMode,
			datetimeMode,
			selectedProvider,
			selectedCredentialId,
			zdrOnly,
			explicitRequireZdr,
			effectiveManagedZdrRequired,
			effectiveWebSearchMaxResultsLimit
		});
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
		explicitRequireZdr = false;
		handleSelectionChange();
	}

	function handleZdrControlClick(event: MouseEvent) {
		if (zdrControl.disabled) return;

		event.preventDefault();
		zdrOnly = !zdrControl.checked;
		explicitRequireZdr =
			selectedProvider === MANAGED_PROVIDER && zdrOnly && !effectiveManagedZdrRequired;
		handleSelectionChange();
	}

	function choosePreset(preset: ModelPreset) {
		selectionMode = 'presets';
		selectedPreset = preset.code;
		highlightedPresetCode = preset.code;
		selectedModel = normalizeSelectedModel(preset.resolved_model_id);
		if (!modelIdAllowsReasoning(preset.resolved_model_id)) selectedReasoning = 'default';
		clearUnsupportedToolSelections();
		highlightedModelId = preset.resolved_model_id;
		handleSelectionChange();
		isPickerOpen = false;
	}

	function chooseModel(model: ModelInfo) {
		if (isModelLocked(model)) return;
		selectionMode = 'models';
		selectedModel = model.id;
		selectedCustomModel = '';
		if (!modelSupportsReasoning(model)) selectedReasoning = 'default';
		clearUnsupportedToolSelections(model);
		highlightedModelId = model.id;
		handleSelectionChange();
		isPickerOpen = false;
	}

	function applyCustomModel() {
		if (!customModelLooksValid(selectedCustomModel) || !customModelAllowed(selectedCustomModel))
			return;
		selectionMode = 'custom';
		if (!modelIdAllowsReasoning(selectedCustomModel.trim())) selectedReasoning = 'default';
		handleSelectionChange();
		isPickerOpen = false;
	}

	function openPicker() {
		if (readonly) return;
		highlightedModelId = selectedConcreteModelId();
		highlightedPresetCode = selectionMode === 'presets' ? selectedPreset : null;
		isPickerOpen = true;
	}

	function closePicker() {
		isPickerOpen = false;
	}

	function handleDialogKeydown(event: KeyboardEvent) {
		if (event.key === 'Escape' && isPickerOpen) {
			event.preventDefault();
			closePicker();
		}
	}

	function setMode(mode: SelectionMode) {
		selectionMode = mode;
		if (mode === 'models') {
			highlightedPresetCode = null;
			selectedModel = normalizeSelectedModel(
				selectedModel || resolvedModelForPreset(selectedPreset)
			);
			highlightedModelId = selectedModel;
		} else if (mode === 'presets') {
			highlightedPresetCode = selectedPreset;
			highlightedModelId = resolvedModelForPreset(selectedPreset);
		} else {
			highlightedPresetCode = null;
			highlightedModelId = selectedConcreteModelId();
		}
	}

	function compactSelectionForResolve(selection: ModelSelection | null | undefined) {
		if (!selection) return undefined;
		const { tools: _tools, ...compact } = selection;
		return compact;
	}

	function payloadSelection(
		selection: ModelSelection | null | undefined,
		options: { compactTools?: boolean } = {}
	) {
		return options.compactTools ? compactSelectionForResolve(selection) : (selection ?? undefined);
	}

	function buildSelectionPayload(
		selection: ModelSelection,
		options: { compactTools?: boolean } = {}
	) {
		return {
			template_model_hint: templateModelHint ?? undefined,
			global_selection: payloadSelection(level === 'global' ? selection : globalSelection, options),
			action_selection: payloadSelection(level === 'action' ? selection : actionSelection, options),
			form_selection: payloadSelection(level === 'form' ? selection : formSelection, options),
			mapping_selection: payloadSelection(
				level === 'mapping' ? selection : mappingSelection,
				options
			)
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
		const resolutionPayload = buildSelectionPayload(selection, { compactTools: true });

		try {
			const resolvedResponse = await wpRequestEndpoint('models.resolve', {
				method: 'POST',
				body: resolutionPayload,
				showNotifications: false
			});

			if (requestToken !== resolutionRequestToken) return;

			resolved = normalizeResolvedModel(resolvedResponse);

			if (actionId) {
				const estimate = await wpRequestEndpoint('models.estimate', {
					method: 'POST',
					body: {
						action_id: actionId,
						base_credit_cost: baseCreditCost ?? undefined,
						...selectionPayload
					},
					showNotifications: false
				});

				if (requestToken !== resolutionRequestToken) return;

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
			(credentialsForProvider(OPENROUTER_PROVIDER).length > 0 ||
				selectedProvider === OPENROUTER_PROVIDER)
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
	const managedServiceActive = $derived(credentialsForProvider(MANAGED_PROVIDER).length > 0);
	const effectiveManagedZdrRequired = $derived(
		managedZdrRequired === null ? loadedManagedZdrRequired : Boolean(managedZdrRequired)
	);
	const zdrControl = $derived(
		modelSelectorZdrControl({
			managedServiceActive,
			managedZdrRequired: effectiveManagedZdrRequired,
			provider: selectedProvider,
			zdrOnly
		})
	);

	const lockedModelCount = $derived(models.filter((model) => isModelLocked(model)).length);

	const providerFilterOptions = $derived.by(() => {
		const options = new Map<string, string>();
		for (const model of models) {
			options.set(providerKey(model), providerDisplayName(model));
		}
		return [
			{ value: 'all', label: 'All providers' },
			...[...options.entries()]
				.sort((left, right) => left[1].localeCompare(right[1], undefined, { sensitivity: 'base' }))
				.map(([value, label]) => ({ value, label }))
		];
	});

	const categoryFilterOptions = $derived.by(() => {
		const discovered = new Set<string>();
		for (const model of models) {
			for (const category of Object.keys(model.category_rankings ?? {})) {
				discovered.add(category);
			}
		}
		const ordered = [
			...MODEL_RANK_CATEGORIES.filter((category) => discovered.has(category)),
			...[...discovered].filter(
				(category) =>
					!MODEL_RANK_CATEGORIES.includes(category as (typeof MODEL_RANK_CATEGORIES)[number])
			)
		];
		return [
			{ value: 'all', label: 'All categories' },
			...ordered.map((category) => ({ value: category, label: categoryLabel(category) }))
		];
	});

	const activeRankLimit = $derived(Number.parseInt(rankLimit, 10) || 0);
	const activeContextLimit = $derived(Number.parseInt(contextLimit, 10) || 0);
	const effectiveRequiredCapabilities = $derived([
		...new Set([...(requiredCapabilitiesProp ?? []), ...requiredCapabilities])
	]);
	const selectedModelMissingRequiredCapabilities = $derived.by(() => {
		return missingRequiredCapabilities(selectedPrimaryModelInfo(), effectiveRequiredCapabilities);
	});

	const filteredModels = $derived.by(() => {
		return filterAndSortModels(models, {
			searchTerm,
			costLimit,
			provider: providerFilter,
			category: categoryFilter,
			maxRank: activeRankLimit,
			minContext: activeContextLimit,
			requiredCapabilities: effectiveRequiredCapabilities,
			sortMode,
			zdrOnly: zdrControl.checked
		});
	});

	const visibleModels = $derived(filteredModels.slice(0, MAX_VISIBLE_MODELS));
	const selectedReasoningOptions = $derived.by(() =>
		selectedModelAllowsReasoning()
			? reasoningOptionsForModel(selectedPrimaryModelInfo())
			: fallbackReasoningOptions
	);
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

	function toggleCapabilityFilter(capability: ModelSelectorCapabilityKey) {
		if (lockRequiredCapabilities && requiredCapabilitiesProp?.includes(capability)) return;
		const next = new Set(requiredCapabilities);
		if (next.has(capability)) {
			next.delete(capability);
		} else {
			next.add(capability);
		}
		requiredCapabilities = next;
	}

	function providerMonogramClass(model: ModelInfo | null): string {
		const key = model ? providerKey(model) : 'openrouter';
		if (key.includes('anthropic')) return 'sf:bg-orange-50 sf:text-orange-700 sf:border-orange-200';
		if (key.includes('openai')) return 'sf:bg-emerald-50 sf:text-emerald-700 sf:border-emerald-200';
		if (key.includes('google')) return 'sf:bg-sky-50 sf:text-sky-700 sf:border-sky-200';
		if (key.includes('moonshot')) return 'sf:bg-indigo-50 sf:text-indigo-700 sf:border-indigo-200';
		if (key.includes('deepseek')) return 'sf:bg-cyan-50 sf:text-cyan-700 sf:border-cyan-200';
		if (key.includes('z.ai') || key.includes('zai'))
			return 'sf:bg-rose-50 sf:text-rose-700 sf:border-rose-200';
		return 'sf:bg-slate-100 sf:text-slate-700 sf:border-slate-200';
	}

	function selectedCategoryHelp(): string {
		if (categoryFilter === 'all')
			return 'Ranks are shown across every category OpenRouter publishes.';
		return `Ranks are filtered to ${categoryLabel(categoryFilter)}.`;
	}

	function contextLabel(model: ModelInfo): string {
		if (!model.context_window) return 'Unknown context';
		if (model.context_window >= 1000000)
			return `${(model.context_window / 1000000).toFixed(1)}M context`;
		return `${Math.round(model.context_window / 1000).toLocaleString()}K context`;
	}

	function priceLabel(model: ModelInfo): string {
		if (selectedProvider === MANAGED_PROVIDER) {
			if (pricingEstimate?.kind === 'sentient_credits') {
				return pricingEstimate.label ?? `${pricingEstimate.estimated_debit_credits} credits`;
			}
			return 'Route-defined credits';
		}

		if (modelIsFree(model)) return 'Free';
		const prompt = model.pricing?.prompt;
		const completion = model.pricing?.completion;
		if (!prompt || !completion || Number(prompt) < 0 || Number(completion) < 0) return 'Variable';
		const input = Number(prompt) * 1000000;
		const output = Number(completion) * 1000000;
		return `$${input.toLocaleString(undefined, { maximumFractionDigits: 3 })}/$${output.toLocaleString(undefined, { maximumFractionDigits: 3 })} per 1M`;
	}

	function routeSummary(): string {
		if (selectedProvider === MANAGED_PROVIDER) {
			return hasSelectedProviderPaidRoute()
				? 'Paid OpenRouter route active through Sentient Forms Managed Service'
				: 'Managed Service route needs setup';
		}

		return hasSelectedProviderPaidRoute()
			? 'Paid OpenRouter route active through your key'
			: 'Free OpenRouter models only';
	}

	function modelAccessLabel(model: ModelInfo): string {
		if (modelIsFree(model)) return 'Free';
		const cost = modelCostLabel(model);
		return isModelLocked(model) ? `${cost} locked` : cost;
	}

	function modelDescription(model: ModelInfo | null): string {
		if (!model) return 'Custom OpenRouter model id';
		if (model.description) return model.description;
		if (model.recommended_for?.length) return model.recommended_for.slice(0, 2).join(' · ');
		return `${model.provider_family ?? model.provider} model`;
	}

	function pricingEstimateLabel(): string {
		if (!pricingEstimate) {
			return selectedProvider === MANAGED_PROVIDER ? 'SF managed estimate' : 'OR direct estimate';
		}

		if (pricingEstimate.label) return pricingEstimate.label;
		if (selectedProvider !== MANAGED_PROVIDER && typeof pricingEstimate.amount_usd === 'number')
			return `OR est. ${formatUsd(pricingEstimate.amount_usd)}`;
		if (pricingEstimate.kind === 'sentient_credits') {
			return `SF: ${pricingEstimate.estimated_debit_credits} credits`;
		}

		return selectedProvider === MANAGED_PROVIDER ? 'SF managed estimate' : 'OR direct estimate';
	}

	function pricingEstimateDetail(): string {
		if (!pricingEstimate) {
			return 'Estimate unavailable until the model policy resolves.';
		}

		const confidence =
			typeof pricingEstimate.confidence === 'string' ? pricingEstimate.confidence : null;
		const sampleCount =
			typeof pricingEstimate.sample_count === 'number' ? pricingEstimate.sample_count : 0;
		const source =
			sampleCount > 0
				? `${sampleCount} recent run${sampleCount === 1 ? '' : 's'}`
				: 'baseline profile';
		const suffix = confidence ? ` · ${confidence} confidence` : '';

		if (pricingEstimate.kind === 'sentient_credits') {
			return `Managed Service credit estimate from ${source}${suffix}.`;
		}

		return `Provider-billed estimate from ${source}${suffix}. Local OpenRouter runs do not spend managed action credits. Provider charges are billed by OpenRouter or the configured provider account.`;
	}

	function formatUsd(amount: number): string {
		if (!Number.isFinite(amount)) return '$0.00';
		if (amount === 0) return '$0.00';
		if (amount < 0.01) {
			return `$${amount.toLocaleString(undefined, {
				minimumFractionDigits: 4,
				maximumFractionDigits: 6
			})}`;
		}

		return `$${amount.toLocaleString(undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 4
		})}`;
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

	function customModelAllowed(modelId: string): boolean {
		const trimmed = modelId.trim();
		return (
			hasSelectedProviderPaidRoute() || trimmed.endsWith(':free') || trimmed === 'openrouter/free'
		);
	}

	function customModelAllowedFromSavedSelection(modelId: string): boolean {
		return customModelLooksValid(modelId);
	}

	onMount(() => {
		void loadModels();
		void loadProviderCredentials();
		void loadOpenRouterRefreshConsent();
		void loadManagedZdrRequirement();
	});

	$effect(() => {
		if (!isPickerOpen) syncSelectionFromValue(value);
	});

	$effect(() => {
		if (Array.isArray(providerCredentials)) {
			loadedProviderCredentials = providerCredentials;
			if (!isPickerOpen) syncSelectionFromValue(value, { force: true });
		}
	});

	$effect(() => {
		if (managedZdrRequired !== null) {
			loadedManagedZdrRequired = Boolean(managedZdrRequired);
			if (!isPickerOpen) syncSelectionFromValue(value, { force: true });
		}
	});

	$effect(() => {
		currentSelectionDependencySignature();
		const selection = untrack(() => currentSelection());
		untrack(() => {
			void resolveSelectionPreview(selection);
		});
		if (
			!readonly &&
			(value === null || value === undefined) &&
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
		if (
			selectedModelAllowsReasoning() &&
			!selectedReasoningOptions.some((option) => option.value === selectedReasoning)
		) {
			selectedReasoning = 'default';
		}
	});
</script>

<svelte:window onkeydown={handleDialogKeydown} />

<div class="sf:space-y-3" data-testid="model-selector">
	<div class="sf:flex sf:flex-col sf:gap-2">
		<div
			class="sf:flex sf:flex-col sf:gap-2 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between"
		>
			<div>
				<p class="sf:text-sm sf:font-semibold sf:text-slate-800">{label}</p>
				<p class="sf:text-xs sf:text-slate-500">{routeSummary()}</p>
			</div>
			{#if !readonly}
				<Button
					size="sm"
					variant="secondary"
					onclick={openPicker}
					data-testid="model-selector-open"
				>
					Change model
				</Button>
			{/if}
		</div>

		<div
			class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:shadow-sm"
			data-testid="model-selector-summary"
		>
			<div
				class="sf:flex sf:flex-col sf:gap-3 sf:lg:flex-row sf:lg:items-start sf:lg:justify-between"
			>
				<div class="sf:min-w-0 sf:space-y-1">
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<p class="sf:min-w-0 sf:break-words sf:text-sm sf:font-semibold sf:text-slate-900">
							{selectedDisplayName()}
						</p>
						<Badge variant={selectionMode === 'presets' ? 'info' : 'neutral'}>
							{selectionMode === 'presets'
								? 'Preset'
								: selectionMode === 'custom'
									? 'Custom'
									: 'Model'}
						</Badge>
						{#if modelById(selectedModelIdLabel())}
							{@const selectedModelInfo = modelById(selectedModelIdLabel())}
							{#if selectedModelInfo}
								<Badge variant={modelIsFree(selectedModelInfo) ? 'success' : 'warning'}>
									{modelAccessLabel(selectedModelInfo)}
								</Badge>
								{#if modelIsZdrEligible(selectedModelInfo)}
									<Badge variant="success">ZDR</Badge>
								{/if}
							{/if}
						{/if}
					</div>
					<p class="sf:break-all sf:font-mono sf:text-xs sf:text-slate-600">
						{selectedModelIdLabel()}
					</p>
					{#if resolved}
						<p class="sf:text-xs sf:text-slate-500">
							Resolved from {resolved.resolution_source}
							{#if resolved.backup_model_id}
								· backup {resolved.backup_model_id}
							{/if}
						</p>
					{:else if resolving}
						<p class="sf:text-xs sf:text-slate-500">Resolving model policy...</p>
					{/if}
				</div>
				{#if pricingEstimate}
					<div class="sf:w-full sf:min-w-0 sf:lg:w-80 sf:lg:flex-none">
						<div class="sf:rounded-md sf:bg-slate-50 sf:px-3 sf:py-2 sf:text-left sf:lg:text-right">
							<p class="sf:text-[11px] sf:font-semibold sf:uppercase sf:text-slate-500">
								{selectedProvider === MANAGED_PROVIDER
									? 'Managed action credits'
									: 'Provider estimate'}
							</p>
							<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
								{pricingEstimateLabel()}
							</p>
							<p class="sf:text-xs sf:text-slate-500">{pricingEstimateDetail()}</p>
						</div>
					</div>
				{/if}
			</div>

			{#if selectedModelAllowsReasoning()}
				<div class="sf:mt-4 sf:w-full sf:min-w-0" data-testid="model-reasoning-summary">
					<ReasoningEffortRail
						id={`model-summary-reasoning-${level}`}
						options={selectedReasoningOptions}
						bind:value={selectedReasoning}
						disabled={readonly}
						onchange={() => handleSelectionChange()}
					/>
				</div>
			{/if}

			{#if !loading && selectedModelMissingRequiredCapabilities.length > 0}
				<Alert variant="warning" class="sf:mt-4" data-testid="model-required-capability-warning">
					This action requires {capabilityListLabel(selectedModelMissingRequiredCapabilities)}.
					Choose a compatible OpenRouter model before saving.
				</Alert>
			{/if}

			<div class="sf:mt-4 sf:border-t sf:border-slate-200 sf:pt-4">
				<div
					class="sf:flex sf:flex-col sf:gap-2 sf:lg:flex-row sf:lg:items-start sf:lg:justify-between"
				>
					<div>
						<p class="sf:text-sm sf:font-semibold sf:text-slate-800">Model tools</p>
						<p class="sf:text-xs sf:text-slate-500">
							Set OpenRouter server tools for this selected model only when the action needs fresh
							or external context.
						</p>
					</div>
					{#if !selectedModelSupportsTools()}
						<p class="sf:max-w-md sf:text-xs sf:text-slate-500">
							This cached model does not advertise tool support. Use Custom ID if OpenRouter has
							newer capabilities than this bundled snapshot.
						</p>
					{/if}
				</div>
				<div class="sf-model-tools-layout sf:mt-3" data-testid="model-tools-layout">
					<div class="sf-model-tools-grid">
						<div class="sf-model-tools-field">
							<label class="sf-model-tools-label" for={`model-summary-tool-choice-${level}`}>
								Tool choice
							</label>
							<select
								id={`model-summary-tool-choice-${level}`}
								class="sf-model-tools-select"
								bind:value={toolChoiceMode}
								disabled={readonly ||
									(!selectedModelSupportsToolChoice() && toolChoiceMode === 'inherit')}
								onchange={handleSelectionChange}
								data-testid="model-summary-tool-choice"
							>
								{#each toolChoiceOptions as option}
									<option value={option.value}>{option.label}</option>
								{/each}
							</select>
						</div>
						<div class="sf-model-tools-field">
							<label class="sf-model-tools-label" for={`model-summary-web-search-${level}`}>
								Web search
							</label>
							<select
								id={`model-summary-web-search-${level}`}
								class="sf-model-tools-select"
								bind:value={webSearchMode}
								disabled={readonly ||
									(!selectedModelSupportsWebSearch() && webSearchMode === 'inherit')}
								onchange={handleSelectionChange}
								data-testid="model-summary-web-search"
							>
								{#each toolModeOptions as option}
									<option value={option.value}>{option.label}</option>
								{/each}
							</select>
						</div>
						<div class="sf-model-tools-field">
							<label class="sf-model-tools-label" for={`model-summary-web-fetch-${level}`}>
								Web fetch
							</label>
							<select
								id={`model-summary-web-fetch-${level}`}
								class="sf-model-tools-select"
								bind:value={webFetchMode}
								disabled={readonly ||
									(!selectedModelSupportsWebFetch() && webFetchMode === 'inherit')}
								onchange={handleSelectionChange}
								data-testid="model-summary-web-fetch"
							>
								{#each toolModeOptions as option}
									<option value={option.value}>{option.label}</option>
								{/each}
							</select>
						</div>
						<div class="sf-model-tools-field">
							<label class="sf-model-tools-label" for={`model-summary-datetime-${level}`}>
								Current date/time
							</label>
							<select
								id={`model-summary-datetime-${level}`}
								class="sf-model-tools-select"
								bind:value={datetimeMode}
								disabled={readonly ||
									(!selectedModelSupportsDatetime() && datetimeMode === 'inherit')}
								onchange={handleSelectionChange}
								data-testid="model-summary-datetime"
							>
								{#each toolModeOptions as option}
									<option value={option.value}>{option.label}</option>
								{/each}
							</select>
						</div>
					</div>
				</div>
				{#if webSearchMode !== 'inherit' && webSearchMode !== 'off'}
					<label class="sf:mt-3 sf:flex sf:max-w-xs sf:flex-col sf:gap-1">
						<span class="sf:text-xs sf:font-semibold sf:text-slate-700">
							Search results per call
						</span>
						<input
							type="number"
							min="1"
							max={effectiveWebSearchMaxResultsLimit}
							class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
							value={webSearchMaxResults}
							disabled={readonly}
							oninput={(event) => {
								webSearchMaxResults = clampWebSearchMaxResults(
									(event.currentTarget as HTMLInputElement).value
								);
								handleSelectionChange();
							}}
						/>
					</label>
				{/if}
			</div>
		</div>
	</div>

	{#if loading}
		<p class="sf:text-sm sf:text-slate-600">Loading models...</p>
	{:else if error}
		<Alert variant="danger">{error}</Alert>
	{:else if resolutionError}
		<Alert variant="warning"
			>Could not load the local resolved model preview. {resolutionError}</Alert
		>
	{/if}

	{#if isPickerOpen && !readonly}
		{@const detailModel = activeDetailModel()}
		{@const detailMonogram = providerMonogram(detailModel)}
		<div
			class="sf-wp-modal-backdrop sf:flex sf:items-center sf:justify-center sf:overflow-hidden sf:bg-slate-950/55 sf:p-3 sf:sm:p-6"
			role="dialog"
			aria-modal="true"
			aria-labelledby={`model-selector-title-${level}`}
			data-testid="model-selector-dialog"
		>
			<div
				class="sf-model-selector-shell sf:flex sf:w-full sf:max-w-[88rem] sf:flex-col sf:overflow-hidden sf:rounded-xl sf:bg-white sf:shadow-2xl"
			>
				<header
					class="sf:flex-none sf:border-b sf:border-slate-200 sf:bg-slate-950 sf:px-4 sf:py-4 sf:text-white sf:sm:px-5"
				>
					<div
						class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between"
					>
						<div>
							<p
								id={`model-selector-title-${level}`}
								class="sf:text-base sf:font-semibold sf:tracking-tight"
							>
								Choose model
							</p>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-300">{routeSummary()}</p>
						</div>
						<div class="sf:flex sf:items-center sf:gap-2">
							<span class="sf:relative sf:inline-flex sf:group">
								<Button
									size="sm"
									variant="secondary"
									iconOnly
									onclick={openModelCatalogRefresh}
									loading={refreshingCatalog}
									aria-label="Refresh model catalog"
									data-testid="model-selector-refresh-catalog"
								>
									<RefreshCwIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
								</Button>
								<span
									role="tooltip"
									class="sf:pointer-events-none sf:absolute sf:right-0 sf:top-full sf:z-[1000000] sf:mt-2 sf:w-max sf:max-w-xs sf:rounded-md sf:border sf:border-slate-800 sf:bg-slate-950 sf:px-3 sf:py-2 sf:text-xs sf:font-normal sf:leading-relaxed sf:text-white sf:opacity-0 sf:shadow-xl sf:transition sf:duration-150 sf:group-focus-within:opacity-100 sf:group-hover:opacity-100"
								>
									Refresh cached OpenRouter model metadata
								</span>
							</span>
							<Button
								size="sm"
								variant="secondary"
								onclick={closePicker}
								data-testid="model-selector-close"
							>
								Close
							</Button>
						</div>
					</div>

					{#if refreshDisclosureOpen}
						<div
							class="sf:mt-4 sf:rounded-lg sf:border sf:border-primary-200 sf:bg-primary-50 sf:p-4 sf:text-slate-900"
							data-testid="model-selector-refresh-disclosure"
						>
							<div class="sf:grid sf:gap-4 sf:lg:grid-cols-[minmax(0,1fr)_auto] sf:lg:items-end">
								<div>
									<p class="sf:text-sm sf:font-semibold sf:text-slate-950">
										Refresh OpenRouter model metadata
									</p>
									<p class="sf:mt-1 sf:max-w-3xl sf:text-sm sf:leading-6 sf:text-slate-700">
										Refreshing fetches public model names, pricing, context limits, and capabilities
										from OpenRouter, then stores a local cache on this WordPress site. No prompts,
										model outputs, or form entries are sent.
									</p>
									<label
										class="sf:mt-3 sf:flex sf:items-start sf:gap-3 sf:text-sm sf:text-slate-800"
									>
										<input
											type="checkbox"
											class="sf:mt-0.5 sf:h-4 sf:w-4 sf:rounded sf:border-slate-300 sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-primary-50"
											bind:checked={refreshDisclosureAccepted}
										/>
										<span>I understand and agree to fetch model metadata from OpenRouter.</span>
									</label>
									{#if refreshCatalogError}
										<p class="sf:mt-2 sf:text-sm sf:text-danger-700">{refreshCatalogError}</p>
									{/if}
								</div>
								<div class="sf:flex sf:flex-wrap sf:justify-end sf:gap-2">
									<Button
										size="sm"
										variant="ghost"
										onclick={() => {
											refreshDisclosureOpen = false;
											refreshCatalogError = null;
											refreshDisclosureAccepted = refreshConsentState?.state === 'accepted';
										}}
									>
										Cancel
									</Button>
									<Button
										size="sm"
										onclick={refreshModelCatalog}
										loading={refreshingCatalog}
										disabled={!refreshDisclosureAccepted}
									>
										Refresh catalog
									</Button>
								</div>
							</div>
						</div>
					{/if}
				</header>

				<div class="sf:flex-none sf:border-b sf:border-slate-200 sf:bg-slate-50 sf:p-4 sf:sm:p-5">
					<div class="sf:grid sf:gap-3 sf:lg:grid-cols-[minmax(0,1fr)_18rem] sf:lg:items-end">
						<div
							class="sf:flex sf:flex-wrap sf:gap-2"
							role="tablist"
							aria-label="Model selection mode"
						>
							<Button
								size="sm"
								variant={selectionMode === 'presets' ? 'primary' : 'secondary'}
								onclick={() => setMode('presets')}
								data-testid="model-selector-tab-presets"
							>
								Recommended
							</Button>
							<Button
								size="sm"
								variant={selectionMode === 'models' ? 'primary' : 'secondary'}
								onclick={() => setMode('models')}
								data-testid="model-selector-tab-models"
							>
								All models
							</Button>
							<Button
								size="sm"
								variant={selectionMode === 'custom' ? 'primary' : 'secondary'}
								onclick={() => setMode('custom')}
								data-testid="model-selector-tab-custom"
							>
								Custom ID
							</Button>
						</div>

						{#if providerRouteOptions.length > 0}
							<SelectField
								id={`model-provider-${level}`}
								label="Execution route"
								options={providerRouteOptions}
								bind:value={selectedProvider}
								onchange={handleProviderChange}
							/>
						{/if}
					</div>

					<div class="sf:mt-3 sf:flex sf:flex-wrap sf:items-center sf:gap-3">
						<Popover.Root>
							<Popover.Trigger
								class={[
									'sf:inline-flex sf:min-h-10 sf:items-center sf:gap-3 sf:rounded-lg sf:border sf:px-3 sf:py-2 sf:text-left sf:text-sm sf:transition-colors sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-2',
									zdrControl.checked
										? 'sf:border-emerald-300 sf:bg-emerald-50 sf:text-emerald-950'
										: 'sf:border-slate-200 sf:bg-white sf:text-slate-700',
									zdrControl.disabled
										? 'sf:cursor-help sf:opacity-80'
										: 'sf:hover:border-primary-300 sf:hover:bg-primary-50'
								].join(' ')}
								role="switch"
								aria-checked={zdrControl.checked}
								aria-disabled={zdrControl.disabled}
								aria-label="ZDR-only model filter"
								onclick={handleZdrControlClick}
								data-testid="model-selector-zdr-control"
							>
								<span
									class={[
										'sf:flex sf:h-5 sf:w-9 sf:flex-none sf:items-center sf:rounded-full sf:p-0.5 sf:transition-colors',
										zdrControl.checked ? 'sf:bg-emerald-600' : 'sf:bg-slate-300'
									].join(' ')}
									aria-hidden="true"
								>
									<span
										class={[
											'sf:h-4 sf:w-4 sf:rounded-full sf:bg-white sf:shadow-sm sf:transition-transform',
											zdrControl.checked ? 'sf:translate-x-4' : 'sf:translate-x-0'
										].join(' ')}
									></span>
								</span>
								<span class="sf:min-w-0">
									<span
										class="sf:block sf:text-xs sf:font-semibold sf:uppercase sf:tracking-normal"
									>
										ZDR-only
									</span>
									<span class="sf:block sf:text-xs sf:text-slate-600">
										Show models OpenRouter marks for ZDR routes.
									</span>
								</span>
							</Popover.Trigger>
							{#if zdrControl.popover}
								<Popover.Content data-testid="model-selector-zdr-popover">
									{zdrControl.popover}
								</Popover.Content>
							{/if}
						</Popover.Root>
					</div>

					{#if !hasSelectedProviderPaidRoute()}
						<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
							Paid OpenRouter models are visible but locked until Sentient Forms Managed Service or
							a paid OpenRouter key is ready.
						</p>
					{:else if selectedRouteCredential}
						<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
							Ready route: <strong>{selectedRouteCredential.label}</strong>
						</p>
					{:else if providerLoading}
						<p class="sf:mt-3 sf:text-sm sf:text-slate-600">Refreshing provider access...</p>
					{/if}
				</div>

				<div class="sf:grid sf:min-h-0 sf:flex-1 sf:gap-0 sf:lg:grid-cols-[minmax(0,1fr)_24rem]">
					<section
						class="sf:flex sf:min-h-0 sf:min-w-0 sf:flex-col sf:border-b sf:border-slate-200 sf:p-4 sf:lg:border-b-0 sf:lg:border-r sf:sm:p-5"
					>
						{#if selectionMode === 'presets'}
							<div class="sf:min-h-0 sf:flex-1 sf:overflow-y-auto sf:pr-1">
								<div
									class="sf:grid sf:gap-3 sf:md:grid-cols-2"
									data-testid="model-selector-presets"
								>
									{#each presets as preset}
										{@const model = modelById(preset.resolved_model_id)}
										{@const locked = model ? isModelLocked(model) : false}
										<Button
											variant="secondary"
											size="md"
											class={[
												'sf:h-auto sf:min-h-36 sf:w-full sf:items-stretch sf:justify-start sf:rounded-lg sf:p-4 sf:text-left',
												selectedPreset === preset.code
													? 'sf:border-primary-500 sf:bg-primary-50'
													: 'sf:border-slate-200 sf:bg-white sf:hover:border-slate-300',
												locked ? 'sf:cursor-not-allowed sf:bg-slate-50 sf:opacity-75' : ''
											].join(' ')}
											title={model ? lockTitle(model) : preset.description}
											aria-disabled={locked}
											onfocus={() => {
												highlightedPresetCode = preset.code;
												highlightedModelId = preset.resolved_model_id;
											}}
											onmouseover={() => {
												highlightedPresetCode = preset.code;
												highlightedModelId = preset.resolved_model_id;
											}}
											onclick={() => {
												if (!locked) choosePreset(preset);
											}}
											data-testid={`model-preset-${preset.code}`}
										>
											<span class="sf:flex sf:h-full sf:flex-col sf:gap-3">
												<span class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
													<span class="sf:text-sm sf:font-semibold sf:text-slate-900">
														{preset.display_name}
													</span>
													{#if locked}
														<Badge variant="warning">
															{model ? `${modelCostLabel(model)} locked` : 'Paid'}
														</Badge>
													{:else if model && modelIsFree(model)}
														<Badge variant="success">Free</Badge>
													{:else}
														<Badge variant="info">
															{model ? modelCostLabel(model) : 'Paid'}
														</Badge>
													{/if}
													{#if modelIsZdrEligible(model)}
														<Badge variant="success">ZDR</Badge>
													{/if}
												</span>
												<span class="sf:text-sm sf:text-slate-600">{preset.description}</span>
												<span
													class="sf:mt-auto sf:break-all sf:font-mono sf:text-xs sf:text-slate-500"
												>
													{preset.resolved_model_id}
												</span>
											</span>
										</Button>
									{/each}
								</div>
							</div>
						{:else if selectionMode === 'models'}
							<div
								class="sf:flex sf:min-h-0 sf:flex-1 sf:flex-col sf:gap-4"
								data-testid="model-selector-catalog"
							>
								<div class="sf:grid sf:gap-2 sf:xl:grid-cols-[minmax(0,1fr)_10rem_auto]">
									<label class="sf:flex sf:flex-col sf:gap-1">
										<span class="sf:text-xs sf:font-semibold sf:text-slate-700">Search models</span>
										<input
											class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
											value={searchTerm}
											oninput={(event) => {
												searchTerm = (event.currentTarget as HTMLInputElement).value;
											}}
										/>
									</label>
									<SelectField
										id={`model-sort-${level}`}
										label="Sort"
										options={sortOptions}
										bind:value={sortMode}
									/>
									<div class="sf:flex sf:flex-col sf:justify-end">
										<Button
											size="sm"
											variant="secondary"
											class="sf:min-h-10 sf:whitespace-nowrap sf:border-slate-300 sf:bg-white"
											aria-expanded={advancedFiltersOpen}
											onclick={() => {
												advancedFiltersOpen = !advancedFiltersOpen;
											}}
											data-testid="model-selector-advanced-filters-toggle"
										>
											{advancedFiltersOpen ? 'Hide filters' : 'Advanced filters'}
										</Button>
									</div>
								</div>

								{#if advancedFiltersOpen}
									<div
										class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:shadow-inner"
										data-testid="model-selector-advanced-filters-panel"
									>
										<div class="sf:grid sf:gap-3 sf:xl:grid-cols-[10rem_10rem_12rem_10rem_10rem]">
											<SelectField
												id={`model-provider-filter-${level}`}
												label="Provider"
												options={providerFilterOptions}
												bind:value={providerFilter}
												data-testid="model-provider-filter"
											/>
											<SelectField
												id={`model-cost-filter-${level}`}
												label="Max cost"
												options={costLimitOptions}
												bind:value={costLimit}
												data-testid="model-cost-filter"
											/>
											<SelectField
												id={`model-category-filter-${level}`}
												label="OpenRouter category"
												options={categoryFilterOptions}
												bind:value={categoryFilter}
												data-testid="model-category-filter"
											/>
											<SelectField
												id={`model-rank-filter-${level}`}
												label="Rank"
												options={rankLimitOptions}
												bind:value={rankLimit}
												disabled={categoryFilter === 'all'}
												data-testid="model-rank-filter"
											/>
											<SelectField
												id={`model-context-filter-${level}`}
												label="Context"
												options={contextLimitOptions}
												bind:value={contextLimit}
												data-testid="model-context-filter"
											/>
										</div>

										<div class="sf:mt-3">
											<p class="sf:text-xs sf:font-semibold sf:text-slate-700">
												Required capabilities
											</p>
											<div class="sf:mt-1 sf:flex sf:flex-wrap sf:gap-1.5">
												{#each capabilityFilterOptions as capability}
													{@const capabilityLocked =
														lockRequiredCapabilities &&
														(requiredCapabilitiesProp ?? []).includes(capability.value)}
													{@const capabilityActive =
														requiredCapabilities.has(capability.value) ||
														(requiredCapabilitiesProp ?? []).includes(capability.value)}
													<Button
														variant="secondary"
														size="sm"
														class={[
															'sf:min-h-8 sf:gap-1 sf:rounded-full sf:px-2.5 sf:text-xs',
															capabilityActive
																? 'sf:border-primary-500 sf:bg-primary-50 sf:text-primary-700'
																: 'sf:border-slate-200 sf:bg-white sf:text-slate-600 sf:hover:border-slate-300'
														].join(' ')}
														aria-pressed={capabilityActive}
														disabled={capabilityLocked}
														title={capability.label}
														onclick={() => toggleCapabilityFilter(capability.value)}
														data-testid={`model-capability-filter-${capability.value}`}
													>
														<span aria-hidden="true">{capability.short}</span>
														<span class="sf:hidden sf:2xl:inline">{capability.label}</span>
													</Button>
												{/each}
											</div>
										</div>
									</div>
								{/if}

								<div
									class="sf:flex sf:flex-col sf:gap-1 sf:text-xs sf:text-slate-500 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between"
								>
									<p>
										Showing {visibleModels.length} of {filteredModels.length} matching models.
									</p>
									<p>{selectedCategoryHelp()}</p>
								</div>

								<div class="sf:min-h-0 sf:flex-1 sf:space-y-2 sf:overflow-y-auto sf:pr-1">
									{#if visibleModels.length === 0}
										<div
											class="sf:rounded-lg sf:border sf:border-dashed sf:border-slate-300 sf:bg-white sf:p-6 sf:text-sm sf:text-slate-600"
											data-testid="model-selector-empty"
										>
											No models match these filters. Clear a capability, rank, or cost filter to
											widen the list.
										</div>
									{/if}
									{#each visibleModels as model (model.id)}
										{@const locked = isModelLocked(model)}
										{@const monogram = providerMonogram(model)}
										{@const modelRanks = categoryRankingItems(model)}
										<Button
											variant="secondary"
											size="md"
											class={[
												'sf:h-auto sf:min-h-32 sf:w-full sf:items-stretch sf:justify-start sf:rounded-lg sf:p-3 sf:text-left',
												selectedModel === model.id
													? 'sf:border-primary-500 sf:bg-primary-50'
													: 'sf:border-slate-200 sf:bg-white sf:hover:border-slate-300',
												locked ? 'sf:bg-slate-50 sf:opacity-75' : ''
											].join(' ')}
											title={lockTitle(model)}
											aria-disabled={locked}
											onfocus={() => {
												highlightedModelId = model.id;
											}}
											onmouseover={() => {
												highlightedModelId = model.id;
											}}
											onclick={() => {
												if (!locked) chooseModel(model);
											}}
											data-testid={`model-row-${model.id}`}
										>
											<span
												class="sf:grid sf:w-full sf:gap-3 sf:xl:grid-cols-[minmax(0,1fr)_minmax(16rem,0.8fr)]"
											>
												<span class="sf:flex sf:min-w-0 sf:gap-3">
													<span
														class={`sf:flex sf:h-11 sf:w-11 sf:flex-none sf:items-center sf:justify-center sf:rounded-lg sf:border sf:text-xs sf:font-bold ${providerMonogramClass(model)}`}
														title={monogram.label}
														aria-label={`${monogram.label} model`}
													>
														{monogram.initials}
													</span>
													<span class="sf:min-w-0 sf:space-y-2">
														<span class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
															<span class="sf:text-sm sf:font-semibold sf:text-slate-900">
																{model.display_name}
															</span>
															<Badge variant={costBadgeVariant(model.cost_tier)}>
																{modelCostLabel(model)}
															</Badge>
															{#if modelIsZdrEligible(model)}
																<Badge variant="success">ZDR</Badge>
															{/if}
															{#if locked}
																<Badge variant="warning">Paid model</Badge>
															{/if}
															{#if model.is_preview}
																<Badge variant="warning">Preview</Badge>
															{/if}
														</span>
														<span
															class="sf:block sf:break-all sf:font-mono sf:text-xs sf:text-slate-600"
														>
															{model.id}
														</span>
														<span class="sf:flex sf:flex-wrap sf:gap-1">
															{#each capabilityItems(model) as item}
																<span
																	class="sf:inline-flex sf:min-h-7 sf:min-w-7 sf:items-center sf:justify-center sf:rounded-full sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-2 sf:text-[11px] sf:font-semibold sf:text-slate-700"
																	title={item[1]}
																	aria-label={item[1]}
																>
																	{item[2]}
																</span>
															{/each}
														</span>
													</span>
												</span>
												<span class="sf:flex sf:flex-col sf:gap-2">
													<span class="sf:grid sf:grid-cols-3 sf:gap-2 sf:text-xs">
														<span class="sf:rounded-md sf:bg-slate-50 sf:px-2 sf:py-1">
															<span
																class="sf:block sf:text-[10px] sf:font-semibold sf:uppercase sf:text-slate-500"
																>Context</span
															>
															<span class="sf:font-semibold sf:text-slate-800"
																>{contextLabel(model)}</span
															>
														</span>
														<span class="sf:rounded-md sf:bg-slate-50 sf:px-2 sf:py-1">
															<span
																class="sf:block sf:text-[10px] sf:font-semibold sf:uppercase sf:text-slate-500"
																>Caps</span
															>
															<span class="sf:font-semibold sf:text-slate-800"
																>{modelCapabilityCount(model)}</span
															>
														</span>
														<span class="sf:rounded-md sf:bg-slate-50 sf:px-2 sf:py-1">
															<span
																class="sf:block sf:text-[10px] sf:font-semibold sf:uppercase sf:text-slate-500"
																>Best rank</span
															>
															<span class="sf:font-semibold sf:text-slate-800">
																{modelBestRank(model) ? `#${modelBestRank(model)}` : '—'}
															</span>
														</span>
													</span>
													{#if modelRanks.length > 0}
														<span class="sf:flex sf:flex-wrap sf:gap-1">
															{#each modelRanks.slice(0, 8) as [category, rank]}
																<span
																	class={[
																		'sf:inline-flex sf:items-center sf:gap-1 sf:rounded-full sf:border sf:px-2 sf:py-0.5 sf:text-[11px] sf:font-semibold',
																		categoryFilter === category
																			? 'sf:border-primary-300 sf:bg-primary-50 sf:text-primary-700'
																			: 'sf:border-slate-200 sf:bg-white sf:text-slate-600'
																	].join(' ')}
																	title={`OpenRouter ${categoryLabel(category)} rank ${rank}`}
																>
																	#{rank}
																	{categoryLabel(category)}
																</span>
															{/each}
														</span>
													{/if}
													<span class="sf:text-xs sf:font-semibold sf:text-slate-600">
														{locked
															? 'Paid model'
															: selectedModel === model.id
																? 'Selected'
																: 'Use model'}
													</span>
												</span>
											</span>
										</Button>
									{/each}
								</div>
							</div>
						{:else}
							<div class="sf:space-y-4" data-testid="model-selector-custom">
								<label class="sf:flex sf:flex-col sf:gap-1">
									<span class="sf:text-xs sf:font-semibold sf:text-slate-700">
										Custom OpenRouter model id
									</span>
									<input
										class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:font-mono sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
										placeholder="provider/model or ~provider/latest"
										value={selectedCustomModel}
										oninput={(event) => {
											selectedCustomModel = (event.currentTarget as HTMLInputElement).value;
										}}
										data-testid="model-custom-input"
									/>
								</label>
								{#if selectedCustomModel.trim() && !customModelLooksValid(selectedCustomModel)}
									<Alert variant="warning"
										>Use the OpenRouter id format, for example provider/model.</Alert
									>
								{:else if selectedCustomModel.trim() && !customModelAllowed(selectedCustomModel)}
									<Alert variant="warning"
										>Custom paid model IDs require Sentient Forms Managed Service or your own paid
										OpenRouter key. Free custom IDs can end in :free or use openrouter/free.</Alert
									>
								{/if}
								<Button
									size="sm"
									variant="primary"
									disabled={!customModelLooksValid(selectedCustomModel) ||
										!customModelAllowed(selectedCustomModel)}
									onclick={applyCustomModel}
								>
									Use custom model
								</Button>
							</div>
						{/if}

						<div
							class="sf:mt-5 sf:grid sf:gap-3 sf:border-t sf:border-slate-200 sf:pt-4 sf:lg:grid-cols-2"
						>
							{#if selectedModelAllowsReasoning()}
								<SelectField
									id={`model-reasoning-${level}`}
									label="Reasoning effort"
									description="Requested through OpenRouter for this model."
									options={selectedReasoningOptions}
									bind:value={selectedReasoning}
									onchange={handleSelectionChange}
								/>
							{:else if selectedModelNeedsReasoningMetadataHint()}
								<Alert variant="info">
									Reasoning options appear when the custom ID matches a cached OpenRouter model that
									advertises reasoning support.
								</Alert>
							{/if}
							{#if selectionMode !== 'presets'}
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
											class="sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:font-mono sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
											placeholder="provider/model"
											value={selectedCustomBackup}
											oninput={(event) => {
												selectedCustomBackup = (event.currentTarget as HTMLInputElement).value;
												handleSelectionChange();
											}}
										/>
									{/if}
								</div>
							{/if}
						</div>
					</section>

					<aside class="sf:min-h-0 sf:overflow-y-auto sf:bg-slate-50 sf:p-4 sf:sm:p-5">
						<div class="sf:space-y-4" data-testid="model-selector-detail">
							<div class="sf:flex sf:items-start sf:gap-3">
								<span
									class={`sf:flex sf:h-12 sf:w-12 sf:flex-none sf:items-center sf:justify-center sf:rounded-xl sf:border sf:text-sm sf:font-bold ${providerMonogramClass(detailModel)}`}
									title={detailMonogram.label}
									aria-label={`${detailMonogram.label} model`}
								>
									{detailMonogram.initials}
								</span>
								<div class="sf:min-w-0">
									<p class="sf:text-xs sf:font-semibold sf:uppercase sf:text-slate-500">
										Selection
									</p>
									<p class="sf:mt-1 sf:text-lg sf:font-semibold sf:text-slate-950">
										{detailModel?.display_name ?? selectedDisplayName()}
									</p>
									<p class="sf:mt-1 sf:break-all sf:font-mono sf:text-xs sf:text-slate-600">
										{detailModel?.id ?? selectedModelIdLabel()}
									</p>
								</div>
							</div>

							<p class="sf:text-sm sf:text-slate-600">{modelDescription(detailModel)}</p>

							{#if modelIsZdrEligible(detailModel)}
								<div class="sf:flex sf:flex-wrap sf:items-start sf:gap-2">
									<Badge variant="success">ZDR</Badge>
									<div class="sf:min-w-0 sf:space-y-1 sf:text-xs sf:leading-5 sf:text-slate-600">
										{#each zdrControl.helperSentences as helperSentence}
											<p>{helperSentence}</p>
										{/each}
									</div>
								</div>
							{/if}

							{#if selectionMode === 'presets'}
								{@const selectedPresetDetails = activePreset()}
								{@const topCandidates = presetTopCandidates(selectedPresetDetails)}
								{#if topCandidates.length > 0}
									<div
										class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3"
										data-testid="model-preset-top-candidates"
									>
										<p class="sf:text-xs sf:font-semibold sf:uppercase sf:text-slate-500">
											Recommendation ranking
										</p>
										<div class="sf:mt-3 sf:space-y-1.5">
											{#each topCandidates as candidate, index}
												<div
													class="sf:flex sf:items-start sf:justify-between sf:gap-3"
													data-testid={`model-preset-candidate-${index + 1}`}
												>
													<p class="sf:min-w-0 sf:text-xs sf:font-medium sf:text-slate-800">
														<span class="sf:font-semibold">#{index + 1}</span>
														{candidateModelLabel(candidate.model_id)}
													</p>
													<p class="sf:flex-none sf:text-xs sf:font-semibold sf:text-slate-700">
														{Math.round(candidate.score)}/100
													</p>
												</div>
											{/each}
										</div>
									</div>
								{/if}
							{/if}

							<div class="sf:grid sf:grid-cols-2 sf:gap-2">
								<div class="sf:rounded-md sf:bg-white sf:p-3">
									<p class="sf:text-[11px] sf:font-semibold sf:uppercase sf:text-slate-500">
										Provider
									</p>
									<p class="sf:mt-1 sf:text-sm sf:font-medium sf:text-slate-900">
										{detailModel ? providerDisplayName(detailModel) : 'OpenRouter'}
									</p>
								</div>
								<div class="sf:rounded-md sf:bg-white sf:p-3">
									<p class="sf:text-[11px] sf:font-semibold sf:uppercase sf:text-slate-500">
										Access
									</p>
									<p class="sf:mt-1 sf:text-sm sf:font-medium sf:text-slate-900">
										{detailModel ? modelAccessLabel(detailModel) : 'Custom'}
									</p>
								</div>
								<div class="sf:rounded-md sf:bg-white sf:p-3">
									<p class="sf:text-[11px] sf:font-semibold sf:uppercase sf:text-slate-500">
										Context
									</p>
									<p class="sf:mt-1 sf:text-sm sf:font-medium sf:text-slate-900">
										{detailModel ? contextLabel(detailModel) : 'Unknown'}
									</p>
								</div>
								<div class="sf:rounded-md sf:bg-white sf:p-3">
									<p class="sf:text-[11px] sf:font-semibold sf:uppercase sf:text-slate-500">
										{selectedProvider === MANAGED_PROVIDER
											? 'Managed action credits'
											: 'Provider price'}
									</p>
									<p class="sf:mt-1 sf:text-sm sf:font-medium sf:text-slate-900">
										{detailModel ? priceLabel(detailModel) : 'Route-defined'}
									</p>
								</div>
							</div>

							{#if categoryRankingItems(detailModel).length > 0}
								<div>
									<p class="sf:text-xs sf:font-semibold sf:uppercase sf:text-slate-500">
										OpenRouter category ranks
									</p>
									<div class="sf:mt-2 sf:flex sf:flex-wrap sf:gap-2">
										{#each categoryRankingItems(detailModel) as [category, rank]}
											<span
												class="sf:inline-flex sf:items-center sf:gap-1 sf:rounded-full sf:border sf:border-slate-200 sf:bg-white sf:px-2.5 sf:py-1 sf:text-xs sf:font-medium sf:text-slate-700"
												title={`OpenRouter ${categoryLabel(category)} rank ${rank}`}
											>
												#{rank}
												{categoryLabel(category)}
											</span>
										{/each}
									</div>
								</div>
							{/if}

							{#if detailModel}
								<div>
									<p class="sf:text-xs sf:font-semibold sf:uppercase sf:text-slate-500">
										Capabilities
									</p>
									<div class="sf:mt-2 sf:flex sf:flex-wrap sf:gap-2">
										{#each capabilityItems(detailModel) as item}
											<span
												class="sf:inline-flex sf:items-center sf:gap-1 sf:rounded-full sf:border sf:border-slate-200 sf:bg-white sf:px-2.5 sf:py-1 sf:text-xs sf:font-medium sf:text-slate-700"
												title={item[1]}
											>
												<span aria-hidden="true">{item[2]}</span>
												<span>{item[1]}</span>
											</span>
										{/each}
									</div>
								</div>
							{/if}

							{#if resolved}
								<div
									class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3"
									data-testid="model-resolution-card"
								>
									<p class="sf:text-xs sf:font-semibold sf:text-slate-700">
										Resolved as {resolved.display_name}
									</p>
									<p class="sf:mt-1 sf:text-xs sf:text-slate-500">
										Source: {resolved.resolution_source}
										{#if resolved.backup_model_id}
											· backup {resolved.backup_model_id}
										{/if}
									</p>
									{#if pricingEstimate}
										<p class="sf:mt-2 sf:text-xs sf:text-slate-600">
											{pricingEstimate.label ??
												`${pricingEstimate.estimated_debit_credits} credits`}
										</p>
									{/if}
								</div>
							{/if}
						</div>
					</aside>
				</div>
			</div>
		</div>
	{/if}
</div>

<style>
	.sf-model-tools-layout {
		container-type: inline-size;
	}

	.sf-model-tools-grid {
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		gap: 0.75rem;
	}

	.sf-model-tools-field {
		display: grid;
		grid-template-columns: minmax(0, 1fr);
		gap: 0.35rem;
		min-width: 0;
	}

	.sf-model-tools-label {
		color: rgb(51 65 85);
		font-size: 0.875rem;
		font-weight: 600;
		line-height: 1.25rem;
	}

	.sf-model-tools-select {
		width: 100%;
		min-width: 0;
		border: 1px solid rgb(203 213 225);
		border-radius: 0.25rem;
		background-color: rgb(255 255 255);
		padding: 0.5rem 0.75rem;
		color: rgb(15 23 42);
		font-size: 0.875rem;
		line-height: 1.25rem;
	}

	.sf-model-tools-select:focus-visible {
		border-color: rgb(37 99 235);
		outline: none;
		box-shadow:
			0 0 0 1px rgb(37 99 235),
			0 0 0 3px rgb(191 219 254);
	}

	.sf-model-tools-select:disabled {
		background-color: rgb(241 245 249);
		color: rgb(148 163 184);
	}

	@container (min-width: 36rem) {
		.sf-model-tools-grid {
			grid-template-columns: repeat(2, minmax(0, 1fr));
			column-gap: clamp(1.5rem, 6cqi, 5rem);
			row-gap: 0.75rem;
		}

		.sf-model-tools-field {
			grid-template-columns: minmax(6.75rem, 0.44fr) minmax(10rem, 1fr);
			align-items: center;
			gap: 0.75rem;
		}

		.sf-model-tools-label {
			white-space: nowrap;
		}
	}
</style>
