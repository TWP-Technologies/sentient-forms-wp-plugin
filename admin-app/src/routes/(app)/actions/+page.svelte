<script lang="ts">
	import { onMount } from 'svelte';
	import {
		Section,
		Button,
		ButtonLink,
		Badge,
		Alert,
		SearchInput,
		SelectField,
		Toggle,
		ModelSelector,
		StateTemplate
	} from '$lib/components/ui';
	import ActivityIcon from '@lucide/svelte/icons/activity';
	import BotIcon from '@lucide/svelte/icons/bot';
	import CircleDotIcon from '@lucide/svelte/icons/circle-dot';
	import ExternalLinkIcon from '@lucide/svelte/icons/external-link';
	import LibraryIcon from '@lucide/svelte/icons/library';
	import PlugZapIcon from '@lucide/svelte/icons/plug-zap';
	import PowerIcon from '@lucide/svelte/icons/power';
	import RefreshCwIcon from '@lucide/svelte/icons/refresh-cw';
	import RouteIcon from '@lucide/svelte/icons/route';
	import Settings2Icon from '@lucide/svelte/icons/settings-2';
	import SlidersIcon from '@lucide/svelte/icons/sliders-horizontal';
	import WandSparklesIcon from '@lucide/svelte/icons/wand-sparkles';
	import AlignedSelectGrid from '$lib/components/aligned-select-grid.svelte';
	import ActionCustomizationEditor from '$lib/components/action-customization-editor.svelte';
	import RealtimeSettingsEditor from '$lib/components/realtime-settings-editor.svelte';
	import SiteContextWarning from '$lib/components/site-context-warning.svelte';
	import SpamCriteriaEditor from '$lib/components/spam-criteria-editor.svelte';
	import { notifications } from '$lib/stores/notifications';
	import { appHref, navigateToAppPath } from '$lib/navigation';
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		ActionDefinition,
		ActionCategory,
		FormSourceSummary,
		FormSummary,
		FormActionLinkage,
		FormActionConfig,
		FormExecutionStatus,
		LocalProviderCredential,
		ModelSelection,
		CustomAction
	} from '$lib/api/types';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import {
		groupDefinitionsByCategory,
		getCategoryMeta,
		getDefinitionCategory
	} from '$lib/utils/action-categories';
	import { getHealthBadge as resolveHealthBadge } from '$lib/utils/form-health';
	import {
		applyInheritableBooleanToConfig,
		cloneDefaultModelSelection,
		getInheritableBooleanMode,
		isSpamActionCode,
		type InheritableBooleanMode,
		normalizeFormActionConfig
	} from '$lib/utils/action-config';
	import { isRealtimeEligibleActionId, normalizeRealtimeSettings } from '$lib/utils/realtime-settings';
	import {
		openRouterActionHealth,
		providerStatusLabel,
		providerStatusVariant
	} from '$lib/utils/provider-health';
	import { formatModelSelectionPrimary, formatTemplateModelHint } from '$lib/utils/model-selection';

	type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';

	const client = createClientFromConfig();
	const runtime = typeof window === 'undefined' ? undefined : window.sentientFormsConfig;
	const formSources: FormSourceSummary[] = runtime?.formSources ?? [];

	let definitions = $state<ActionDefinition[]>([]);
	let definitionsLoading = $state(false);
	let formsBySource = $state<Record<string, FormSummary[]>>({});
	let formsLoading = $state(false);
	let selectedSource = $state<FormSourceSummary | null>(
		formSources.find((source) => source.isActive) ?? formSources[0] ?? null
	);
	let error: string | null = $state(null);
	let searchTerm = $state('');
	let actionSearchTerm = $state('');
	let actionLibraryTab = $state<'built-in' | 'custom'>('built-in');
	let selectedActionCategory = $state<ActionCategory | 'all'>('all');
	let currentPage = $state(1);
	const pageSize = 12;

	// CB-FORMS-003: Per-form health status
	let healthByForm = $state<Map<string, FormExecutionStatus>>(new Map());
	let healthLoading = $state<Set<string>>(new Set());
	let formActionsByForm = $state<Map<string, FormActionLinkage[]>>(new Map());
	let formActionsLoading = $state<Set<string>>(new Set());

	function createBlankActionDefaults(): FormActionConfig {
		return normalizeFormActionConfig({});
	}

	// Action-level defaults state (global configuration)
	let configuringActionId = $state<string | null>(null);
	let actionDefaults = $state<FormActionConfig>(createBlankActionDefaults());
	let actionDefaultsLoading = $state(false);
	let actionDefaultsSaving = $state(false);
	let actionDefaultsById = $state<Map<string, FormActionConfig>>(new Map());
	const actionDefaultsSummaryRequests = new Set<string>();

	// CB-FORMS-002: Execution disable controls (global + provider)
	let executionSettingsLoading = $state(false);
	let executionSettingsSaving = $state(false);
	let executionGlobalDisabled = $state(false);
	let executionProviderDisabled = $state<Record<string, boolean>>({});
	let providerCredentials = $state<LocalProviderCredential[]>([]);
	let providerCredentialsLoading = $state(false);
	let providerCredentialsError = $state<string | null>(null);

	const activeSources = $derived(formSources.filter((source) => source.isActive));
	const customActions = $derived(
		customActionsState.actions.filter((action) => action.status === 'active')
	);
	const openRouterHealth = $derived(openRouterActionHealth(providerCredentials));

	function formSourceAvailabilityLabel(source: FormSourceSummary): string {
		if (source.availability === 'requires_pro' || (source.requiresPro && !source.isActive)) {
			return 'Requires Pro';
		}

		if (source.availability === 'not_installed') {
			return 'Not installed';
		}

		return source.isActive ? 'Plugin active' : 'Inactive';
	}

	function formSourceAvailabilityVariant(source: FormSourceSummary): BadgeVariant {
		return source.isActive && source.availability !== 'requires_pro' ? 'success' : 'warning';
	}

	function formSourceAvailabilityHelp(source: FormSourceSummary): string {
		const explicit = source.availabilityMessage?.trim();
		if (explicit) return explicit;

		if (source.availability === 'requires_pro' || (source.requiresPro && !source.isActive)) {
			return `${source.label} support requires the provider's Pro Forms APIs.`;
		}

		return '';
	}

	// CB-ACTIONS-002: count how many forms have each action enabled
	const formsPerAction = $derived.by(() => {
		const counts = new Map<string, number>();
		const loadedFormKeys = new Set(formActionsByForm.keys());

		for (const [formKey, linkages] of formActionsByForm) {
			const enabledActionIds = new Set(
				linkages
					.filter(isLinkageEnabled)
					.map((linkage) => getLinkageActionId(linkage))
					.filter((actionId): actionId is string => Boolean(actionId))
			);

			for (const actionId of enabledActionIds) {
				counts.set(actionId, (counts.get(actionId) ?? 0) + 1);
			}
		}

		for (const [sourceSlug, forms] of Object.entries(formsBySource)) {
			for (const form of forms) {
				const key = formStateKey(sourceSlug, form.id);
				if (loadedFormKeys.has(key)) {
					continue;
				}

				const actions =
					form.settings && typeof form.settings === 'object'
						? (form.settings as Record<string, unknown>)['actions']
						: null;
				if (actions && typeof actions === 'object') {
					for (const [actionId, cfg] of Object.entries(
						actions as Record<string, { is_action_enabled_for_form?: boolean }>
					)) {
						if (cfg?.is_action_enabled_for_form) {
							counts.set(actionId, (counts.get(actionId) ?? 0) + 1);
						}
					}
				}
			}
		}
		return counts;
	});

	// Filter forms by search term
	const filteredForms = $derived(
		(selectedSource
			? (formsBySource[selectedSource.slug] ?? [])
			: Object.values(formsBySource).flat()
		).filter(
			(form) =>
				searchTerm === '' ||
				form.title.toLowerCase().includes(searchTerm.toLowerCase()) ||
				form.id.toString().includes(searchTerm)
		)
	);

	// Pagination
	const totalPages = $derived(Math.ceil(filteredForms.length / pageSize));
	const displayedForms = $derived(
		filteredForms.slice((currentPage - 1) * pageSize, currentPage * pageSize)
	);

	// Group definitions by category
	const builtInDefinitions = $derived(
		definitions.filter((definition) => (definition.source ?? 'bundled') !== 'imported')
	);
	const groupedDefinitions = $derived(groupDefinitionsByCategory(builtInDefinitions));
	const categoryOrder: ActionCategory[] = [
		'content_quality',
		'data_processing',
		'automation',
		'custom'
	];
	const actionCategoryFilters = $derived(
		categoryOrder
			.map((category) => ({
				category,
				meta: getCategoryMeta(category),
				count: groupedDefinitions.get(category)?.length ?? 0
			}))
			.filter((item) => item.count > 0)
	);
	const filteredBuiltInDefinitions = $derived.by(() => {
		const query = actionSearchTerm.trim().toLowerCase();
		return builtInDefinitions.filter((definition) => {
			const category = getDefinitionCategory(definition);
			if (selectedActionCategory !== 'all' && category !== selectedActionCategory) {
				return false;
			}
			if (!query) return true;
			return actionDefinitionSearchText(definition).includes(query);
		});
	});
	const filteredCustomActions = $derived.by(() => {
		const query = actionSearchTerm.trim().toLowerCase();
		if (!query) return customActions;
		return customActions.filter((action) => customActionSearchText(action).includes(query));
	});
	const visibleActionCount = $derived(
		actionLibraryTab === 'built-in'
			? filteredBuiltInDefinitions.length
			: filteredCustomActions.length
	);
	const totalFormCount = $derived(
		Object.values(formsBySource).reduce((count, forms) => count + forms.length, 0)
	);

	function friendlyMessageFromError(err: unknown, fallback: string): string {
		if (err instanceof ApiClientError) {
			const payload = err.payload as { message?: string } | null;
			return payload?.message ?? err.message ?? fallback;
		}
		if (err instanceof Error) return err.message ?? fallback;
		return fallback;
	}

	function categoryBadgeVariant(
		category: ActionCategory
	): 'neutral' | 'success' | 'warning' | 'danger' | 'info' {
		switch (category) {
			case 'content_quality':
				return 'danger';
			case 'data_processing':
				return 'info';
			case 'automation':
				return 'warning';
			case 'custom':
			default:
				return 'neutral';
		}
	}

	function actionDefinitionSearchText(definition: ActionDefinition): string {
		return [
			definition.id,
			definition.label,
			definition.description,
			definition.modelHint,
			getDefinitionCategory(definition)
		]
			.filter(Boolean)
			.join(' ')
			.toLowerCase();
	}

	function customActionSearchText(action: CustomAction): string {
		return [action.code, action.display_name, action.description, action.model_hint]
			.filter(Boolean)
			.join(' ')
			.toLowerCase();
	}

	function providerLabelForForm(form: FormSummary): string {
		return selectedSource?.label ?? form.adapter_name ?? form.adapter;
	}

	function formDetailHref(form: FormSummary): string {
		const sourceSlug = selectedSource?.slug ?? form.adapter;
		return appHref(`/actions/${sourceSlug}/${form.id}`);
	}

	function selectSource(source: FormSourceSummary) {
		selectedSource = source;
		currentPage = 1;
	}

	function getActionDisplayName(actionId: string | null): string {
		if (!actionId) {
			return 'this action';
		}

		const definition = definitions.find((item) => item.id === actionId);
		if (definition?.label) {
			return definition.label;
		}

		const customAction = customActions.find((item) => item.code === actionId);
		if (customAction?.display_name) {
			return customAction.display_name;
		}

		return actionId;
	}

	function getActionDefinitionContext(actionId: string | null): {
		actionId: string | null;
		modelHint: string | null;
		baseCreditCost: number | null;
	} {
		if (!actionId) {
			return {
				actionId: null,
				modelHint: null,
				baseCreditCost: null
			};
		}

		const definition = definitions.find((item) => item.id === actionId);
		if (definition) {
			return {
				actionId,
				modelHint: definition.modelHint ?? null,
				baseCreditCost: definition.baseCreditCost ?? null
			};
		}

		const customAction = customActions.find((item) => item.code === actionId);
		if (customAction) {
			return {
				actionId,
				modelHint: customAction.model_hint ?? null,
				baseCreditCost: customAction.base_credit_cost ?? null
			};
		}

		return {
			actionId,
			modelHint: null,
			baseCreditCost: null
		};
	}

	function upsertActionDefaultsSummary(actionId: string, config: FormActionConfig) {
		actionDefaultsById = new Map(actionDefaultsById).set(
			actionId,
			normalizeFormActionConfig(config)
		);
	}

	function actionDefaultModelSummary(
		actionId: string,
		fallbackHint: string | null | undefined
	): string {
		const savedDefaults = actionDefaultsById.get(actionId);
		if (savedDefaults?.model_selection) {
			return formatModelSelectionPrimary(savedDefaults.model_selection);
		}
		return formatTemplateModelHint(fallbackHint ?? null);
	}

	async function loadActionDefaultsSummaries(actionIds: string[]) {
		const ids = [...new Set(actionIds.map((id) => id.trim()).filter(Boolean))].filter(
			(id) => !actionDefaultsSummaryRequests.has(id)
		);
		if (ids.length === 0) return;

		for (const id of ids) {
			actionDefaultsSummaryRequests.add(id);
		}

		try {
			const defaults = await client.getActionDefaultsBatch(ids);
			const nextDefaults = new Map(actionDefaultsById);
			for (const id of ids) {
				nextDefaults.set(id, normalizeFormActionConfig(defaults[id] ?? {}));
			}
			actionDefaultsById = nextDefaults;
		} catch {
			const settledDefaults = await Promise.allSettled(
				ids.map(async (id) => [id, await client.getActionDefaults(id)] as const)
			);
			const nextDefaults = new Map(actionDefaultsById);
			for (const result of settledDefaults) {
				if (result.status !== 'fulfilled') continue;

				const [id, defaults] = result.value;
				nextDefaults.set(id, normalizeFormActionConfig(defaults));
			}
			actionDefaultsById = nextDefaults;
		} finally {
			for (const id of ids) {
				actionDefaultsSummaryRequests.delete(id);
			}
		}
	}

	function clearActionModelSelection() {
		const nextDefaults = { ...actionDefaults };
		delete nextDefaults.model_selection;
		actionDefaults = nextDefaults;
	}

	function handleActionModelSelectionChange(selection: ModelSelection) {
		actionDefaults = { ...actionDefaults, model_selection: selection };
	}

	function handleActionSpamPolicyChange(
		field:
			| 'suppress_notifications_on_spam'
			| 'suppress_webhooks_on_spam'
			| 'skip_downstream_on_spam',
		mode: string
	) {
		actionDefaults = applyInheritableBooleanToConfig(
			actionDefaults,
			field,
			mode as InheritableBooleanMode
		);
	}

	function normalizeProviderDisabledMap(
		value: unknown,
		knownProviders: FormSourceSummary[]
	): Record<string, boolean> {
		const normalized: Record<string, boolean> = {};
		for (const source of knownProviders) {
			normalized[source.slug] = false;
		}

		if (!value || typeof value !== 'object' || Array.isArray(value)) {
			return normalized;
		}

		for (const [key, disabled] of Object.entries(value as Record<string, unknown>)) {
			const slug = key?.toString().trim();
			if (!slug) continue;
			normalized[slug] = Boolean(disabled);
		}

		return normalized;
	}

	async function loadExecutionSettings() {
		executionSettingsLoading = true;
		try {
			const settings = await client.getSettings({ showNotifications: false });
			executionGlobalDisabled = Boolean(settings.execution_global_disabled);
			executionProviderDisabled = normalizeProviderDisabledMap(
				settings.execution_provider_disabled,
				formSources
			);
		} catch (err) {
			const message = friendlyMessageFromError(err, 'Failed to load execution controls');
			notifications.warning(message);
		} finally {
			executionSettingsLoading = false;
		}
	}

	async function toggleGlobalExecutionDisabled(nextDisabled: boolean) {
		const previousGlobal = executionGlobalDisabled;
		executionGlobalDisabled = nextDisabled;
		executionSettingsSaving = true;

		try {
			const settings = await client.updateSettings(
				{
					execution_global_disabled: nextDisabled,
					execution_provider_disabled: executionProviderDisabled
				},
				{ showNotifications: false }
			);
			executionGlobalDisabled = Boolean(settings.execution_global_disabled);
			executionProviderDisabled = normalizeProviderDisabledMap(
				settings.execution_provider_disabled,
				formSources
			);
			notifications.success(
				executionGlobalDisabled ? 'Global execution paused' : 'Global execution resumed'
			);
		} catch (err) {
			executionGlobalDisabled = previousGlobal;
			const message = friendlyMessageFromError(err, 'Failed to update global execution control');
			notifications.error(message);
		} finally {
			executionSettingsSaving = false;
		}
	}

	async function toggleProviderExecutionDisabled(providerSlug: string, nextDisabled: boolean) {
		const previousMap = { ...executionProviderDisabled };
		executionProviderDisabled = { ...executionProviderDisabled, [providerSlug]: nextDisabled };
		executionSettingsSaving = true;

		try {
			const settings = await client.updateSettings(
				{
					execution_global_disabled: executionGlobalDisabled,
					execution_provider_disabled: executionProviderDisabled
				},
				{ showNotifications: false }
			);
			executionGlobalDisabled = Boolean(settings.execution_global_disabled);
			executionProviderDisabled = normalizeProviderDisabledMap(
				settings.execution_provider_disabled,
				formSources
			);
			notifications.success(
				nextDisabled
					? `Execution paused for ${providerSlug}`
					: `Execution resumed for ${providerSlug}`
			);
		} catch (err) {
			executionProviderDisabled = previousMap;
			const message = friendlyMessageFromError(err, 'Failed to update provider execution control');
			notifications.error(message);
		} finally {
			executionSettingsSaving = false;
		}
	}

	function providerExecutionIsPaused(providerSlug: string): boolean {
		return executionGlobalDisabled || Boolean(executionProviderDisabled[providerSlug]);
	}

	async function loadProviderCredentials() {
		providerCredentialsLoading = true;
		providerCredentialsError = null;

		try {
			providerCredentials = await client.getLocalProviderCredentials({ showNotifications: false });
		} catch (err) {
			providerCredentials = [];
			providerCredentialsError = friendlyMessageFromError(
				err,
				'Failed to load local OpenRouter status'
			);
		} finally {
			providerCredentialsLoading = false;
		}
	}

	async function loadDefinitions() {
		definitionsLoading = true;
		error = null;
		try {
			definitions = await client.getActionDefinitions({ showNotifications: false });
			void loadActionDefaultsSummaries(definitions.map((definition) => definition.id));
		} catch (err) {
			error = friendlyMessageFromError(err, 'Failed to load action templates');
			definitions = [];
		} finally {
			definitionsLoading = false;
		}
	}

	async function loadForms(options: { forceRefresh?: boolean } = {}) {
		if (activeSources.length === 0) {
			formsBySource = {};
			healthByForm = new Map();
			formActionsByForm = new Map();
			healthLoading = new Set();
			formActionsLoading = new Set();
			return;
		}

		formsLoading = true;
		error = null;

		try {
			const results = await Promise.all(
				activeSources.map(async (source) => {
					const overview = await client.getFormsOverview(source.slug, {
						showNotifications: false,
						forceRefresh: options.forceRefresh === true
					});
					return [source.slug, overview.forms] as const;
				})
			);

			formsBySource = Object.fromEntries(results);
			const nextHealthByForm = new Map<string, FormExecutionStatus>();
			const nextFormActionsByForm = new Map<string, FormActionLinkage[]>();
			for (const [sourceSlug, forms] of results) {
				for (const form of forms) {
					const key = formStateKey(sourceSlug, form.id);
					nextHealthByForm.set(key, form.execution_status);
					nextFormActionsByForm.set(key, form.actions);
				}
			}
			healthByForm = nextHealthByForm;
			formActionsByForm = nextFormActionsByForm;
			healthLoading = new Set();
			formActionsLoading = new Set();

			if (!selectedSource || !selectedSource.isActive) {
				selectedSource = activeSources[0] ?? null;
			}
		} catch (err) {
			error = friendlyMessageFromError(err, 'Failed to load forms');
			formsBySource = {};
			healthByForm = new Map();
			formActionsByForm = new Map();
			healthLoading = new Set();
			formActionsLoading = new Set();
		} finally {
			formsLoading = false;
		}
	}

	// CB-FORMS-003: Fetch execution health for all loaded forms
	function loadFormHealthStatuses() {
		const nextLoading = new Set<string>();
		for (const [sourceSlug, forms] of Object.entries(formsBySource)) {
			for (const form of forms) {
				const key = `${sourceSlug}:${form.id}`;
				nextLoading.add(key);

				client
					.getFormExecutionStatus(sourceSlug, form.id, { showNotifications: false })
					.then((status) => {
						healthByForm = new Map(healthByForm).set(key, status);
						healthLoading = new Set([...healthLoading].filter((k) => k !== key));
					})
					.catch(() => {
						// Silently remove from loading — badge stays as "No runs"
						healthLoading = new Set([...healthLoading].filter((k) => k !== key));
					});
			}
		}
		healthLoading = nextLoading;
	}

	function loadFormActionCounts() {
		const nextLoading = new Set<string>();
		const validKeys = new Set<string>();

		for (const [sourceSlug, forms] of Object.entries(formsBySource)) {
			for (const form of forms) {
				const key = formStateKey(sourceSlug, form.id);
				validKeys.add(key);
				nextLoading.add(key);

				client
					.getFormActions(sourceSlug, form.id, { showNotifications: false })
					.then((actions) => {
						formActionsByForm = new Map(formActionsByForm).set(key, actions);
						formActionsLoading = new Set([...formActionsLoading].filter((k) => k !== key));
					})
					.catch(() => {
						formActionsLoading = new Set([...formActionsLoading].filter((k) => k !== key));
					});
			}
		}

		formActionsByForm = new Map([...formActionsByForm].filter(([key]) => validKeys.has(key)));
		formActionsLoading = nextLoading;
	}

	// CB-FORMS-003: Derive badge properties from execution status
	function getHealthBadge(form: FormSummary) {
		const sourceSlug = selectedSource?.slug ?? form.adapter;
		const key = `${sourceSlug}:${form.id}`;
		return resolveHealthBadge(healthByForm.get(key) ?? null, healthLoading.has(key));
	}

	function formStateKey(sourceSlug: string, formId: string | number): string {
		return `${sourceSlug}:${formId}`;
	}

	function getLinkageActionId(linkage: FormActionLinkage): string | null {
		return (
			linkage.central_action_id ??
			(linkage as FormActionLinkage & { action_code?: string }).action_code ??
			null
		);
	}

	function isLinkageEnabled(linkage: FormActionLinkage): boolean {
		return linkage.is_action_enabled_for_form !== false;
	}

	function legacyConfiguredActionCount(form: FormSummary): number {
		const actions =
			form.settings && typeof form.settings === 'object'
				? (form.settings as Record<string, unknown>)['actions']
				: null;
		if (actions && typeof actions === 'object') {
			// Count only enabled actions (is_action_enabled_for_form: true)
			return Object.values(
				actions as Record<string, { is_action_enabled_for_form?: boolean }>
			).filter((action) => action?.is_action_enabled_for_form === true).length;
		}
		return 0;
	}

	function configuredActionCount(form: FormSummary): number | null {
		const key = formStateKey(selectedSource?.slug ?? form.adapter, form.id);
		const linkages = formActionsByForm.get(key);
		if (linkages) {
			return linkages.filter(isLinkageEnabled).length;
		}
		if (formActionsLoading.has(key)) {
			return null;
		}
		return legacyConfiguredActionCount(form);
	}

	function isFormEnabled(form: FormSummary): boolean {
		if (!form.settings || typeof form.settings !== 'object') {
			return false;
		}

		const settings = form.settings as Record<string, unknown>;
		return settings.sf_disabled !== true;
	}

	function isProviderFormActive(form: FormSummary): boolean {
		return form.provider_is_active !== false;
	}

	function prevPage() {
		if (currentPage > 1) currentPage--;
	}

	function nextPage() {
		if (currentPage < totalPages) currentPage++;
	}

	function openFormDetail(form: FormSummary) {
		if (!selectedSource) return;
		navigateToAppPath(`/actions/${selectedSource.slug}/${form.id}`);
	}

	function refreshAll() {
		loadDefinitions();
		loadForms({ forceRefresh: true }); // CB-FORMS-003: also triggers loadFormHealthStatuses()
		void loadCustomActions();
		loadExecutionSettings();
		loadProviderCredentials();
	}

	async function loadCustomActions() {
		await customActionsStore.load({ status: 'active' });
		void loadActionDefaultsSummaries(customActionsState.actions.map((action) => action.code));
	}

	onMount(() => {
		loadDefinitions();
		loadForms();
		void loadCustomActions();
		loadExecutionSettings();
		loadProviderCredentials();
	});

	// ============================================================
	// Action-Level Defaults Handlers (global configuration)
	// ============================================================

	async function loadActionDefaults(actionId: string) {
		actionDefaultsLoading = true;
		// Reset to defaults FIRST, then set configuringActionId to open modal immediately
		actionDefaults = createBlankActionDefaults();
		configuringActionId = actionId;

		try {
			const result = await client.getActionDefaults(actionId);
			const config = normalizeFormActionConfig(result);
			actionDefaults = isRealtimeEligibleActionId(actionId)
				? {
						...config,
						realtime_settings: normalizeRealtimeSettings(config.realtime_settings)
					}
				: config;
			upsertActionDefaultsSummary(actionId, actionDefaults);
		} catch (error) {
			console.warn('[ActionDefaults] Failed to load action defaults:', error);
			notifications.warning('Could not load saved defaults. Starting fresh.');
		} finally {
			actionDefaultsLoading = false;
		}
	}

	async function saveActionDefaults() {
		if (!configuringActionId) return;
		const actionId = configuringActionId;
		actionDefaultsSaving = true;
		try {
			const savedDefaults = await client.updateActionDefaults(actionId, actionDefaults);
			upsertActionDefaultsSummary(actionId, savedDefaults);
			notifications.success('Global action defaults saved successfully.');
			configuringActionId = null;
		} catch (error) {
			console.error('[ActionDefaults] Failed to save action defaults:', error);
			notifications.error('Failed to save global action defaults.');
		} finally {
			actionDefaultsSaving = false;
		}
	}

	function cancelActionDefaults() {
		configuringActionId = null;
		actionDefaults = createBlankActionDefaults();
	}

	function handleActionDefaultsBackdropClick(event: MouseEvent) {
		if (event.target !== event.currentTarget) return;
		cancelActionDefaults();
	}
</script>

<Section
	heading="Actions"
	description="Pair action templates and custom actions with your active forms."
>
	{#snippet actions()}
		<div class="sf:flex sf:flex-wrap sf:gap-2">
			<Button variant="secondary" onclick={refreshAll} class="sf:gap-2">
				<RefreshCwIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
				Refresh
			</Button>
			<ButtonLink variant="secondary" href={appHref('/actions/custom')} class="sf:gap-2">
				<Settings2Icon class="sf:h-4 sf:w-4" aria-hidden="true" />
				Manage custom actions
			</ButtonLink>
		</div>
	{/snippet}

	<div
		class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:shadow-sm"
		data-testid="actions-operations-strip"
	>
		<div
			class="sf:flex sf:flex-col sf:gap-3 sf:xl:flex-row sf:xl:items-center sf:xl:justify-between"
		>
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				<div
					class="sf:flex sf:items-center sf:gap-2 sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:px-3 sf:py-2"
				>
					<PowerIcon class="sf:h-4 sf:w-4 sf:text-slate-500" aria-hidden="true" />
					<span class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
						Global execution
					</span>
					<Badge variant={executionGlobalDisabled ? 'warning' : 'success'}>
						{executionGlobalDisabled ? 'Paused' : 'Running'}
					</Badge>
					<Toggle
						checked={!executionGlobalDisabled}
						disabled={executionSettingsSaving || executionSettingsLoading}
						onchange={() => toggleGlobalExecutionDisabled(!executionGlobalDisabled)}
					/>
				</div>

				<div
					class="sf:flex sf:items-center sf:gap-2 sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:px-3 sf:py-2"
					data-testid="actions-openrouter-health"
				>
					<PlugZapIcon class="sf:h-4 sf:w-4 sf:text-slate-500" aria-hidden="true" />
					<span class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
						{providerCredentialsLoading ? 'Checking provider' : openRouterHealth.title}
					</span>
					<Badge
						variant={providerCredentialsError
							? 'warning'
							: providerCredentialsLoading
								? 'neutral'
								: providerStatusVariant(openRouterHealth.badgeStatus)}
					>
						{providerCredentialsError
							? 'Unavailable'
							: providerCredentialsLoading
								? 'Checking'
								: providerStatusLabel(openRouterHealth.badgeStatus)}
					</Badge>
					{#if openRouterHealth.status !== 'ready' || providerCredentialsError}
						<ButtonLink size="sm" variant="secondary" href={appHref('/providers')}>
							Review
						</ButtonLink>
					{/if}
				</div>
			</div>

			{#if formSources.length > 0}
				<div class="sf:flex sf:min-w-0 sf:flex-wrap sf:items-center sf:gap-2">
					{#each formSources as source}
						<div
							class="sf:flex sf:items-center sf:gap-3 sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:px-3 sf:py-2"
						>
							<span class="sf:flex sf:min-w-0 sf:flex-col">
								<span class="sf:max-w-40 sf:truncate sf:text-sm sf:font-medium sf:text-slate-800">
									{source.label}
								</span>
								{#if formSourceAvailabilityHelp(source)}
									<span class="sf:max-w-64 sf:text-xs sf:text-slate-500">
										{formSourceAvailabilityHelp(source)}
									</span>
								{/if}
							</span>
							<Badge variant={formSourceAvailabilityVariant(source)}>
								{formSourceAvailabilityLabel(source)}
							</Badge>
							{#if source.isActive}
								<Badge variant={providerExecutionIsPaused(source.slug) ? 'warning' : 'success'}>
									{providerExecutionIsPaused(source.slug) ? 'Paused' : 'Running'}
								</Badge>
								<Toggle
									checked={!Boolean(executionProviderDisabled[source.slug])}
									disabled={executionSettingsSaving || executionSettingsLoading}
									onchange={() =>
										toggleProviderExecutionDisabled(
											source.slug,
											!Boolean(executionProviderDisabled[source.slug])
										)}
								/>
							{/if}
						</div>
					{/each}
				</div>
			{/if}
		</div>
		{#if providerCredentialsError}
			<p class="sf:mt-2 sf:text-xs sf:text-amber-700">{providerCredentialsError}</p>
		{/if}
	</div>

	{#if error}
		<div class="sf:mt-4">
			<StateTemplate
				variant="error"
				title="Unable to load actions data"
				message={error}
				actionLabel="Retry"
				onAction={refreshAll}
				testId="actions-error-state"
			/>
		</div>
	{/if}

	{#if activeSources.length === 0}
		<div class="sf:mt-4">
			<StateTemplate
				variant="empty"
				title="No active form providers"
				message="Install and activate a supported form builder (like Gravity Forms) to start mapping Sentient Forms actions."
				testId="actions-no-provider-state"
			/>
		</div>
	{:else}
		<div class="sf:mt-4 sf:grid sf:gap-4 sf:xl:grid-cols-[minmax(320px,0.38fr)_minmax(0,1fr)]">
			<section
				class="sf:flex sf:min-h-[620px] sf:max-h-[calc(100vh-260px)] sf:flex-col sf:overflow-hidden sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:shadow-sm"
				data-testid="actions-library-panel"
			>
				<div class="sf:border-b sf:border-slate-200 sf:bg-slate-50 sf:p-4">
					<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
						<div>
							<div class="sf:flex sf:items-center sf:gap-2">
								<LibraryIcon class="sf:h-5 sf:w-5 sf:text-primary-600" aria-hidden="true" />
								<h2 class="sf:text-base sf:font-semibold sf:text-slate-950">Action Library</h2>
							</div>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								Defaults, templates, and reusable automations.
							</p>
						</div>
						<Badge variant="neutral">{visibleActionCount} shown</Badge>
					</div>

					<div
						class="sf:mt-4 sf:grid sf:grid-cols-2 sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-1"
					>
						<Button
							size="sm"
							variant={actionLibraryTab === 'built-in' ? 'primary' : 'ghost'}
							class="sf:w-full sf:rounded"
							aria-pressed={actionLibraryTab === 'built-in'}
							onclick={() => (actionLibraryTab = 'built-in')}
						>
							Built-in
							<span class="sf:ml-1 sf:text-xs sf:opacity-80">{builtInDefinitions.length}</span>
						</Button>
						<Button
							size="sm"
							variant={actionLibraryTab === 'custom' ? 'primary' : 'ghost'}
							class="sf:w-full sf:rounded"
							aria-pressed={actionLibraryTab === 'custom'}
							onclick={() => (actionLibraryTab = 'custom')}
						>
							Custom
							<span class="sf:ml-1 sf:text-xs sf:opacity-80">{customActions.length}</span>
						</Button>
					</div>

					<SearchInput
						bind:value={actionSearchTerm}
						placeholder="Search actions..."
						wrapperClass="sf:mt-3"
					/>

					{#if actionLibraryTab === 'built-in' && actionCategoryFilters.length > 1}
						<div class="sf:mt-3 sf:flex sf:flex-wrap sf:gap-2">
							<Button
								size="xs"
								variant={selectedActionCategory === 'all' ? 'secondary' : 'ghost'}
								class="sf:rounded-full"
								onclick={() => (selectedActionCategory = 'all')}
							>
								All
							</Button>
							{#each actionCategoryFilters as item (item.category)}
								<Button
									size="xs"
									variant={selectedActionCategory === item.category ? 'secondary' : 'ghost'}
									class="sf:rounded-full"
									onclick={() => (selectedActionCategory = item.category)}
								>
									{item.meta.label}
									<span class="sf:ml-1 sf:text-[11px] sf:opacity-70">{item.count}</span>
								</Button>
							{/each}
						</div>
					{/if}
				</div>

				<div class="sf:flex-1 sf:overflow-y-auto sf:p-3">
					{#if actionLibraryTab === 'built-in'}
						{#if definitionsLoading}
							<StateTemplate
								variant="loading"
								title="Loading built-in actions"
								message="Fetching available built-in actions."
								inline
								dense
								testId="actions-definitions-loading-state"
							/>
						{:else if builtInDefinitions.length === 0}
							<StateTemplate
								variant="empty"
								title="No built-in actions loaded yet"
								message="Refresh and try again."
								actionLabel="Refresh actions"
								onAction={() => {
									void loadDefinitions();
								}}
								inline
								dense
								testId="actions-definitions-empty-state"
							/>
						{:else if filteredBuiltInDefinitions.length === 0}
							<StateTemplate
								variant="empty"
								title="No built-in actions match"
								message="Clear the search or category filter."
								inline
								dense
								testId="actions-definitions-filter-empty-state"
							/>
						{:else}
							<ul class="sf:space-y-2">
								{#each filteredBuiltInDefinitions as definition (definition.id)}
									{@const formCount = formsPerAction.get(definition.id) ?? 0}
									{@const category = getDefinitionCategory(definition)}
									{@const meta = getCategoryMeta(category)}
									<li
										class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:transition hover:sf:border-primary-200 hover:sf:bg-primary-50/30"
										data-testid={`actions-built-in-action-${definition.id}`}
									>
										<div class="sf:flex sf:items-start sf:gap-3">
											<div
												class="sf:flex sf:h-9 sf:w-9 sf:shrink-0 sf:items-center sf:justify-center sf:rounded-md sf:bg-primary-50 sf:text-primary-700"
											>
												<BotIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
											</div>
											<div class="sf:min-w-0 sf:flex-1">
												<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
													<p class="sf:font-semibold sf:text-slate-950 sf:break-words">
														{definition.label ?? definition.id}
													</p>
													<Badge variant={categoryBadgeVariant(category)}>
														{meta.label}
													</Badge>
													<Badge variant={formCount > 0 ? 'info' : 'neutral'}>
														{formCount} form{formCount !== 1 ? 's' : ''}
													</Badge>
												</div>
												<p class="sf:mt-1 sf:text-xs sf:text-slate-600">
													Default model: {actionDefaultModelSummary(
														definition.id,
														definition.modelHint ?? null
													)}
												</p>
												{#if definition.description}
													<p class="sf:mt-1 sf:line-clamp-2 sf:text-xs sf:text-slate-500">
														{definition.description}
													</p>
												{/if}
											</div>
										</div>
										<div class="sf:mt-3 sf:flex sf:items-center sf:justify-end">
											<Button
												size="sm"
												variant="secondary"
												onclick={() => loadActionDefaults(definition.id)}
												disabled={actionDefaultsLoading}
												data-testid={`action-defaults-button-${definition.id}`}
											>
												Defaults
											</Button>
										</div>
									</li>
								{/each}
							</ul>
						{/if}
					{:else if filteredCustomActions.length === 0}
						<StateTemplate
							variant="empty"
							title={customActions.length === 0
								? 'No custom actions yet'
								: 'No custom actions match'}
							message={customActions.length === 0
								? 'Create a custom action to tailor responses for this site.'
								: 'Clear the search to see all custom actions.'}
							actionLabel={customActions.length === 0 ? 'Manage custom actions' : undefined}
							onAction={() => {
								void navigateToAppPath('/actions/custom');
							}}
							inline
							dense
							testId="actions-custom-actions-empty-state"
						/>
					{:else}
						<ul class="sf:space-y-2">
							{#each filteredCustomActions as action (action.id)}
								{@const customFormCount = formsPerAction.get(action.code) ?? 0}
								<li
									class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:transition hover:sf:border-primary-200 hover:sf:bg-primary-50/30"
									data-testid={`actions-custom-action-${action.id}`}
								>
									<div class="sf:flex sf:items-start sf:gap-3">
										<div
											class="sf:flex sf:h-9 sf:w-9 sf:shrink-0 sf:items-center sf:justify-center sf:rounded-md sf:bg-slate-100 sf:text-slate-700"
										>
											<WandSparklesIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
										</div>
										<div class="sf:min-w-0 sf:flex-1">
											<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
												<p class="sf:font-semibold sf:text-slate-950 sf:break-words">
													{action.display_name}
												</p>
												<Badge variant={customFormCount > 0 ? 'info' : 'neutral'}>
													{customFormCount} form{customFormCount !== 1 ? 's' : ''}
												</Badge>
												<Badge variant="success">Active</Badge>
											</div>
											<p class="sf:mt-1 sf:text-xs sf:text-slate-600 sf:break-all">
												{action.code} · Default model: {actionDefaultModelSummary(
													action.code,
													action.model_hint ?? null
												)}
											</p>
											{#if action.description}
												<p class="sf:mt-1 sf:line-clamp-2 sf:text-xs sf:text-slate-500">
													{action.description}
												</p>
											{/if}
										</div>
									</div>
									<div class="sf:mt-3 sf:flex sf:items-center sf:justify-end sf:gap-2">
										<Button
											size="sm"
											variant="secondary"
											onclick={() => loadActionDefaults(action.code)}
											disabled={actionDefaultsLoading}
											data-testid={`action-defaults-button-${action.code}`}
										>
											Defaults
										</Button>
									</div>
								</li>
							{/each}
						</ul>
					{/if}
				</div>
			</section>

			<section
				class="sf:flex sf:min-h-[620px] sf:max-h-[calc(100vh-260px)] sf:flex-col sf:overflow-hidden sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:shadow-sm"
				data-testid="actions-forms-workspace"
			>
				<div class="sf:border-b sf:border-slate-200 sf:bg-white sf:p-4">
					<div
						class="sf:flex sf:flex-col sf:gap-3 sf:lg:flex-row sf:lg:items-start sf:lg:justify-between"
					>
						<div>
							<div class="sf:flex sf:items-center sf:gap-2">
								<RouteIcon class="sf:h-5 sf:w-5 sf:text-primary-600" aria-hidden="true" />
								<h2 class="sf:text-base sf:font-semibold sf:text-slate-950">Forms Workspace</h2>
							</div>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								{filteredForms.length} visible · {totalFormCount} loaded across active providers.
							</p>
						</div>
						<Button
							size="sm"
							variant="secondary"
							onclick={() => void loadForms({ forceRefresh: true })}
							disabled={formsLoading}
							class="sf:gap-2"
						>
							<RefreshCwIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
							Refresh forms
						</Button>
					</div>

					<div class="sf:mt-4 sf:flex sf:flex-col sf:gap-3 sf:2xl:flex-row sf:2xl:items-center">
						<div class="sf:flex sf:flex-wrap sf:gap-2">
							{#each activeSources as source}
								<Button
									size="sm"
									variant={selectedSource?.slug === source.slug ? 'primary' : 'secondary'}
									onclick={() => selectSource(source)}
									class="sf:gap-2"
								>
									<CircleDotIcon class="sf:h-3.5 sf:w-3.5" aria-hidden="true" />
									{source.label}
								</Button>
							{/each}
						</div>
						<SearchInput
							bind:value={searchTerm}
							placeholder="Search forms..."
							wrapperClass="sf:min-w-64 sf:flex-1"
						/>
					</div>
				</div>

				<div class="sf:flex-1 sf:overflow-y-auto sf:bg-slate-50 sf:p-4">
					{#if formsLoading}
						<StateTemplate
							variant="loading"
							title="Loading forms"
							message="Retrieving forms for the selected provider."
							inline
							testId="actions-forms-loading-state"
						/>
					{:else if displayedForms.length === 0}
						<StateTemplate
							variant="empty"
							title="No forms detected"
							message={`No forms were detected for ${selectedSource?.label ?? 'this provider'}. Create a form first, then refresh this page.`}
							actionLabel="Refresh forms"
							onAction={() => {
								void loadForms({ forceRefresh: true });
							}}
							inline
							testId="actions-forms-empty-state"
						/>
					{:else}
						<div class="sf:grid sf:gap-3 sf:lg:grid-cols-2 sf:2xl:grid-cols-3">
							{#each displayedForms as form (form.id)}
								{@const actionCount = configuredActionCount(form)}
								{@const enabled = isFormEnabled(form)}
								{@const providerActive = isProviderFormActive(form)}
								{@const health = getHealthBadge(form)}
								<article
									class="sf:flex sf:min-h-56 sf:flex-col sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-4 sf:shadow-sm sf:transition hover:sf:border-primary-200 hover:sf:shadow-md"
									data-testid={`actions-form-card-${form.id}`}
								>
									<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
										<div class="sf:min-w-0">
											<p class="sf:text-base sf:font-semibold sf:text-slate-950 sf:break-words">
												{form.title}
											</p>
											<p class="sf:mt-1 sf:text-xs sf:text-slate-600">
												{providerLabelForForm(form)} · ID {form.id}
											</p>
										</div>
										<div
											class="sf:flex sf:h-10 sf:w-10 sf:shrink-0 sf:items-center sf:justify-center sf:rounded-md sf:bg-primary-50 sf:text-primary-700"
										>
											<ActivityIcon class="sf:h-5 sf:w-5" aria-hidden="true" />
										</div>
									</div>

									<div class="sf:mt-3 sf:flex sf:flex-wrap sf:items-center sf:gap-1.5">
										<Badge variant={providerActive ? 'success' : 'warning'}>
											{providerActive ? 'Form active' : 'Form inactive'}
										</Badge>
										<Badge variant={enabled ? 'info' : 'neutral'}>
											{enabled ? 'Automation enabled' : 'Automation paused'}
										</Badge>
										<span title={health.tooltip}>
											<Badge variant={health.variant}>{health.label}</Badge>
										</span>
									</div>

									<div
										class="sf:mt-4 sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-3 sf:py-2"
									>
										<div class="sf:flex sf:items-center sf:justify-between sf:gap-3">
											<span
												class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600"
											>
												Configured actions
											</span>
											{#if actionCount === null}
												<span class="sf:text-sm sf:text-slate-500">Checking…</span>
											{:else}
												<span class="sf:text-lg sf:font-semibold sf:text-slate-950"
													>{actionCount}</span
												>
											{/if}
										</div>
										{#if actionCount === 0}
											<p class="sf:mt-1 sf:text-xs sf:text-amber-700">No actions configured yet.</p>
										{/if}
									</div>

									{#if !providerActive}
										<p class="sf:mt-3 sf:text-xs sf:text-amber-700">
											The provider form is inactive. Mappings remain editable, but the form will not
											accept live submissions until it is reactivated.
										</p>
									{/if}

									<div class="sf:mt-auto sf:flex sf:flex-col sf:gap-2 sf:pt-4">
										<ButtonLink
											size="sm"
											href={formDetailHref(form)}
											class="sf:w-full sf:justify-center sf:gap-2"
											data-testid={`actions-configure-form-${form.id}`}
										>
											<SlidersIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
											Configure Actions
										</ButtonLink>
										{#if form.provider_edit_url}
											<ButtonLink
												size="sm"
												variant="secondary"
												href={form.provider_edit_url}
												class="sf:w-full sf:justify-center sf:gap-2"
												data-sveltekit-reload
												rel="external"
												data-testid={`actions-provider-edit-link-${form.id}`}
											>
												<ExternalLinkIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
												Open in {providerLabelForForm(form)}
											</ButtonLink>
										{/if}
									</div>
								</article>
							{/each}
						</div>

						{#if totalPages > 1}
							<div class="sf:mt-4 sf:flex sf:items-center sf:justify-center sf:gap-4">
								<Button
									size="sm"
									variant="secondary"
									onclick={prevPage}
									disabled={currentPage === 1}
								>
									Previous
								</Button>
								<span class="sf:text-sm sf:text-slate-600">
									Page {currentPage} of {totalPages}
								</span>
								<Button
									size="sm"
									variant="secondary"
									onclick={nextPage}
									disabled={currentPage === totalPages}
								>
									Next
								</Button>
							</div>
						{/if}
					{/if}
				</div>
			</section>
		</div>
	{/if}
</Section>

<!-- Action-Level Defaults Modal (global configuration) -->
{#if configuringActionId}
	<div
		class="sf-wp-modal-backdrop sf:bg-black/50 sf:flex sf:items-center sf:justify-center sf:p-2 sf:sm:p-4"
		onclick={handleActionDefaultsBackdropClick}
		onkeydown={(event) => {
			if (event.key === 'Escape') cancelActionDefaults();
		}}
		tabindex="-1"
		role="button"
		aria-label="Close action defaults modal"
	>
		<div
			data-testid="action-defaults-modal"
			class="sf:bg-white sf:rounded-lg sf:shadow-xl sf:max-w-2xl sf:w-[calc(100%-0.5rem)] sf:sm:w-full sf:max-h-[90vh] sf:overflow-y-auto"
			role="dialog"
			aria-modal="true"
			aria-labelledby="action-defaults-title"
			tabindex="-1"
		>
			<header
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:px-4 sf:sm:px-6 sf:py-4 sf:border-b sf:border-slate-200 sf:sm:flex-row sf:sm:items-center"
			>
				<div>
					<h2 id="action-defaults-title" class="sf:text-lg sf:font-semibold sf:text-slate-800">
						Global Action Defaults
					</h2>
					<p class="sf:text-sm sf:text-slate-600">
						Configure defaults for <strong>{getActionDisplayName(configuringActionId)}</strong> across
						all forms.
					</p>
				</div>
				<Button
					variant="ghost"
					size="sm"
					iconOnly
					class="sf:text-lg"
					onclick={cancelActionDefaults}
					aria-label="Close"
					data-testid="action-defaults-close"
				>
					×
				</Button>
			</header>

			<div class="sf:p-4 sf:sm:p-6 sf:space-y-6">
				{#if actionDefaultsLoading}
					<p class="sf:text-sm sf:text-slate-600">Loading configuration...</p>
				{:else}
					<Alert variant="info">
						<p class="sf:text-sm">
							Global defaults apply first. Form-level defaults and individual mappings can still
							override them later.
						</p>
					</Alert>

					<div class="sf:space-y-3">
						<div
							class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-2 sf:sm:flex-row sf:sm:items-center"
						>
							<div>
								<p class="sf:text-sm sf:font-medium sf:text-slate-800">Default model</p>
								<p class="sf:text-xs sf:text-slate-600">
									Set the default model selection for this action across all forms.
								</p>
							</div>
							{#if actionDefaults.model_selection}
								<Button size="sm" variant="ghost" onclick={clearActionModelSelection}>
									Use platform default
								</Button>
							{/if}
						</div>
						<ModelSelector
							level="action"
							value={actionDefaults.model_selection ?? cloneDefaultModelSelection()}
							actionId={getActionDefinitionContext(configuringActionId).actionId}
							templateModelHint={getActionDefinitionContext(configuringActionId).modelHint}
							baseCreditCost={getActionDefinitionContext(configuringActionId).baseCreditCost}
							actionSelection={actionDefaults.model_selection ?? null}
							{providerCredentials}
							onchange={handleActionModelSelectionChange}
						/>
					</div>

					<ActionCustomizationEditor
						id="action-level-customization"
						actionId={configuringActionId}
						level="action"
						bind:value={actionDefaults.action_customization}
					/>

					{#if configuringActionId && isRealtimeEligibleActionId(configuringActionId)}
						<RealtimeSettingsEditor
							idPrefix="action-defaults"
							scope="action"
							value={actionDefaults.realtime_settings}
							onchange={(settings) => {
								actionDefaults = {
									...actionDefaults,
									realtime_settings: settings
								};
							}}
						/>
					{/if}

					{#if configuringActionId && isSpamActionCode(configuringActionId)}
						<SpamCriteriaEditor
							initiallyExpanded={true}
							positiveExamples={actionDefaults.spam_positive_examples ?? []}
							negativeExamples={actionDefaults.spam_negative_examples ?? []}
							onchange={(data) => {
								actionDefaults = {
									...actionDefaults,
									spam_positive_examples: data.positive,
									spam_negative_examples: data.negative
								};
							}}
						/>

						<AlignedSelectGrid
							columns={3}
							items={[
								{
									id: 'action-level-spam-notifications',
									label: 'Spam notification policy',
									description:
										'Applies to Blocking spam mappings. Background mappings always allow notifications to send immediately.',
									value: getInheritableBooleanMode(actionDefaults.suppress_notifications_on_spam),
									options: [
										{ value: 'inherit', label: 'Use platform default' },
										{ value: 'enabled', label: 'Suppress notifications' },
										{ value: 'disabled', label: 'Allow notifications' }
									],
									onchange: (event) =>
										handleActionSpamPolicyChange(
											'suppress_notifications_on_spam',
											event.currentTarget.value
										)
								},
								{
									id: 'action-level-spam-webhooks',
									label: 'Spam Webhooks policy',
									description:
										'Applies when the Gravity Forms Webhooks add-on and feed replay APIs are available.',
									value: getInheritableBooleanMode(actionDefaults.suppress_webhooks_on_spam),
									options: [
										{ value: 'inherit', label: 'Use platform default' },
										{ value: 'enabled', label: 'Suppress Webhooks' },
										{ value: 'disabled', label: 'Allow Webhooks' }
									],
									onchange: (event) =>
										handleActionSpamPolicyChange(
											'suppress_webhooks_on_spam',
											event.currentTarget.value
										)
								},
								{
									id: 'action-level-spam-downstream',
									label: 'Downstream spam gate',
									description:
										'Controls whether downstream work should stop when this spam action confirms spam.',
									value: getInheritableBooleanMode(actionDefaults.skip_downstream_on_spam),
									options: [
										{ value: 'inherit', label: 'Use platform default' },
										{ value: 'enabled', label: 'Skip downstream actions' },
										{ value: 'disabled', label: 'Allow downstream actions' }
									],
									onchange: (event) =>
										handleActionSpamPolicyChange(
											'skip_downstream_on_spam',
											event.currentTarget.value
										)
								}
							]}
						/>
					{/if}

					<div
						class="sf:grid sf:gap-3 sf:lg:grid-cols-[minmax(16rem,0.9fr)_minmax(0,1.1fr)] sf:lg:items-end"
					>
						<SelectField
							id="action-level-context"
							label="Include Site Context"
							bind:value={actionDefaults.include_site_context}
							options={[
								{ value: 'global', label: 'Use global setting' },
								{ value: 'always', label: 'Always include' },
								{ value: 'never', label: 'Never include' }
							]}
						/>
						<SiteContextWarning includeMode={actionDefaults.include_site_context} />
					</div>

					<p class="sf:text-xs sf:text-slate-500 sf:pt-2 sf:flex sf:items-center sf:gap-1">
						<span class="sf:text-amber-500">⚠</span>
						These settings apply globally. Override them per form or per mapping when the workflow needs
						something different.
					</p>
				{/if}
			</div>

			<footer
				class="sf:sticky sf:bottom-0 sf:flex sf:flex-wrap sf:justify-end sf:gap-2 sf:px-4 sf:sm:px-6 sf:py-4 sf:border-t sf:border-slate-200 sf:bg-slate-50"
			>
				<Button variant="secondary" onclick={cancelActionDefaults}>Cancel</Button>
				<Button
					onclick={saveActionDefaults}
					disabled={actionDefaultsSaving || actionDefaultsLoading}
				>
					{actionDefaultsSaving ? 'Saving...' : 'Save Global Defaults'}
				</Button>
			</footer>
		</div>
	</div>
{/if}
