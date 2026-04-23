<script lang="ts">
	import { onMount } from 'svelte';
	import {
		Section,
		Card,
		Button,
		Badge,
		Alert,
		SelectField,
		Toggle,
		ModelSelector,
		StateTemplate
	} from '$lib/components/ui';
	import SpamCriteriaEditor from '$lib/components/spam-criteria-editor.svelte';
	import { notifications } from '$lib/stores/notifications';
	import { navigateToAppPath } from '$lib/navigation';
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
		ModelSelection
	} from '$lib/api/types';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { groupDefinitionsByCategory, getCategoryMeta } from '$lib/utils/action-categories';
	import { getHealthBadge as resolveHealthBadge } from '$lib/utils/form-health';
	import {
		applyInheritableBooleanToConfig,
		cloneDefaultModelSelection,
		getInheritableBooleanMode,
		isSpamActionCode,
		type InheritableBooleanMode,
		normalizeFormActionConfig
	} from '$lib/utils/action-config';
	import {
		openRouterActionHealth,
		providerStatusLabel,
		providerStatusVariant
	} from '$lib/utils/provider-health';

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

	function friendlyMessageFromError(err: unknown, fallback: string): string {
		if (err instanceof ApiClientError) {
			const payload = err.payload as { message?: string } | null;
			return payload?.message ?? err.message ?? fallback;
		}
		if (err instanceof Error) return err.message ?? fallback;
		return fallback;
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

	function clearActionModelSelection() {
		const nextDefaults = { ...actionDefaults };
		delete nextDefaults.model_selection;
		actionDefaults = nextDefaults;
	}

	function handleActionModelSelectionChange(selection: ModelSelection) {
		actionDefaults = { ...actionDefaults, model_selection: selection };
	}

	function handleActionSpamPolicyChange(
		field: 'suppress_notifications_on_spam' | 'skip_downstream_on_spam',
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
		} catch (err) {
			error = friendlyMessageFromError(err, 'Failed to load action templates');
			definitions = [];
		} finally {
			definitionsLoading = false;
		}
	}

	async function loadForms() {
		if (activeSources.length === 0) {
			formsBySource = {};
			return;
		}

		formsLoading = true;
		error = null;

		try {
			const results = await Promise.all(
				activeSources.map(async (source) => {
					const forms = await client.getForms(source.slug, { showNotifications: false });
					return [source.slug, forms] as const;
				})
			);

			formsBySource = Object.fromEntries(results);
			if (!selectedSource || !selectedSource.isActive) {
				selectedSource = activeSources[0] ?? null;
			}

			// CB-FORMS-003: Load health statuses after forms are available
			loadFormHealthStatuses();
			loadFormActionCounts();
		} catch (err) {
			error = friendlyMessageFromError(err, 'Failed to load forms');
			formsBySource = {};
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

	function formStateKey(sourceSlug: string, formId: number): string {
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
		loadForms(); // CB-FORMS-003: also triggers loadFormHealthStatuses()
		customActionsStore.reload();
		loadExecutionSettings();
		loadProviderCredentials();
	}

	onMount(() => {
		loadDefinitions();
		loadForms();
		customActionsStore.load({ status: 'active' });
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
			actionDefaults = normalizeFormActionConfig(result);
		} catch (error) {
			console.warn('[ActionDefaults] Failed to load action defaults:', error);
			notifications.warning('Could not load saved defaults. Starting fresh.');
		} finally {
			actionDefaultsLoading = false;
		}
	}

	async function saveActionDefaults() {
		if (!configuringActionId) return;
		actionDefaultsSaving = true;
		try {
			await client.updateActionDefaults(configuringActionId, actionDefaults);
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
			<Button variant="secondary" onclick={refreshAll}>Refresh</Button>
			<Button variant="secondary" onclick={() => navigateToAppPath('/actions/custom')}>
				Manage custom actions
			</Button>
		</div>
	{/snippet}

	<div class="sf:grid sf:gap-4 sf:lg:grid-cols-3">
		<Card data-testid="actions-built-in-card">
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Built-in actions</p>
					<p class="sf:text-xs sf:text-slate-600">
						Included with Sentient Forms and ready to map.
					</p>
				</div>
			</div>
				{#if definitionsLoading}
					<div class="sf:mt-3">
						<StateTemplate
							variant="loading"
							title="Loading built-in actions"
							message="Fetching available built-in actions."
							inline
							dense
							testId="actions-definitions-loading-state"
						/>
					</div>
				{:else if builtInDefinitions.length === 0}
					<div class="sf:mt-3">
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
					</div>
				{:else}
				<div class="sf:mt-3 sf:space-y-3">
					{#each categoryOrder as category}
						{@const items = groupedDefinitions.get(category) ?? []}
						{#if items.length > 0}
							{@const meta = getCategoryMeta(category)}
							<div>
								<p
									class="sf:mb-1 sf:text-xs sf:font-medium sf:uppercase sf:tracking-wide sf:text-slate-600"
								>
									{meta.icon}
									{meta.label}
								</p>
								<ul class="sf:space-y-1">
										{#each items.slice(0, 3) as definition (definition.id)}
											{@const formCount = formsPerAction.get(definition.id) ?? 0}
											<li class="sf:flex sf:flex-col sf:items-start sf:gap-2">
												<div class="sf:min-w-0 sf:flex-1">
													<p class="sf:text-sm sf:font-semibold sf:text-slate-800 sf:break-words">
														{definition.label ?? definition.id}
													</p>
												</div>
												<div class="sf:flex sf:w-full sf:flex-wrap sf:items-center sf:gap-2">
													<Button
														size="sm"
														variant="ghost"
														onclick={() => loadActionDefaults(definition.id)}
														disabled={actionDefaultsLoading}
														data-testid={`action-defaults-button-${definition.id}`}
													>
														Defaults
													</Button>
													<Badge variant={formCount > 0 ? 'info' : 'neutral'}>
														{formCount} form{formCount !== 1 ? 's' : ''}
													</Badge>
												</div>
											</li>
										{/each}
								</ul>
							</div>
						{/if}
					{/each}
				</div>
			{/if}
		</Card>

		<Card>
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Custom actions</p>
					<p class="sf:text-xs sf:text-slate-600">Tenant-specific automations.</p>
				</div>
				<Badge variant="info">{customActions.length} active</Badge>
			</div>
				{#if customActions.length === 0}
					<div class="sf:mt-3">
						<StateTemplate
							variant="empty"
							title="No custom actions yet"
							message="Create a custom action to tailor responses for this site."
							actionLabel="Manage custom actions"
							onAction={() => {
								void navigateToAppPath('/actions/custom');
							}}
							inline
							dense
							testId="actions-custom-actions-empty-state"
						/>
					</div>
				{:else}
				<ul class="sf:mt-3 sf:space-y-2">
					{#each customActions.slice(0, 4) as action (action.id)}
						{@const customFormCount = formsPerAction.get(action.code) ?? 0}
						<li
							class="sf:flex sf:flex-col sf:items-start sf:gap-2"
							data-testid={`actions-custom-action-${action.id}`}
						>
							<div class="sf:min-w-0 sf:flex-1">
								<p class="sf:text-sm sf:font-semibold sf:text-slate-800 sf:break-words">
									{action.display_name}
								</p>
								<p class="sf:text-xs sf:text-slate-600 sf:break-all">Code: {action.code}</p>
							</div>
							<div class="sf:flex sf:w-full sf:flex-wrap sf:items-center sf:gap-2">
								<Button
									size="sm"
									variant="ghost"
									onclick={() => loadActionDefaults(action.code)}
									disabled={actionDefaultsLoading}
									data-testid={`action-defaults-button-${action.code}`}
								>
									Defaults
								</Button>
								<Badge variant={customFormCount > 0 ? 'info' : 'neutral'}>
									{customFormCount} form{customFormCount !== 1 ? 's' : ''}
								</Badge>
								<Badge variant="success">Active</Badge>
							</div>
						</li>
					{/each}
				</ul>
			{/if}
		</Card>

		<Card>
			<p class="sf:text-sm sf:font-medium sf:text-slate-700">Form providers</p>
			{#if formSources.length === 0}
				<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
					Install and activate a supported form builder (like Gravity Forms) to start mapping
					actions.
				</p>
			{:else}
				<div class="sf:mt-3 sf:space-y-3">
					<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
						<div>
							<p class="sf:text-sm sf:font-medium sf:text-slate-700">Global execution</p>
							<p class="sf:text-xs sf:text-slate-600">
								Pause all Sentient Forms runs without locking mapping edits.
							</p>
						</div>
						<div class="sf:flex sf:items-center sf:gap-2">
							<Badge variant={executionGlobalDisabled ? 'warning' : 'success'}>
								{executionGlobalDisabled ? 'Paused' : 'Running'}
							</Badge>
							<Toggle
								checked={!executionGlobalDisabled}
								disabled={executionSettingsSaving || executionSettingsLoading}
								onchange={() => toggleGlobalExecutionDisabled(!executionGlobalDisabled)}
							/>
						</div>
					</div>

					<div
						class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3"
						data-testid="actions-openrouter-health"
					>
						<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
							<div>
								<p class="sf:text-sm sf:font-medium sf:text-slate-700">
									{providerCredentialsLoading ? 'Checking OpenRouter status' : openRouterHealth.title}
								</p>
								<p class="sf:mt-1 sf:text-xs sf:text-slate-600">
									{providerCredentialsError ?? openRouterHealth.message}
								</p>
							</div>
							<div class="sf:flex sf:items-center sf:gap-2">
								<Badge
									variant={providerCredentialsError
										? 'warning'
										: providerCredentialsLoading
											? 'neutral'
											: providerStatusVariant(openRouterHealth.badgeStatus)}
								>
									{providerCredentialsError
										? 'Status unavailable'
										: providerCredentialsLoading
											? 'Checking'
											: providerStatusLabel(openRouterHealth.badgeStatus)}
								</Badge>
								{#if openRouterHealth.status !== 'ready' || providerCredentialsError}
									<Button
										size="sm"
										variant="secondary"
										onclick={() => navigateToAppPath('/providers')}
									>
										Review OpenRouter
									</Button>
								{/if}
							</div>
						</div>
					</div>

					{#each formSources as source}
						<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
							<div class="sf:flex sf:items-center sf:gap-2">
								<span class="sf:text-sm sf:text-slate-800">{source.label}</span>
								<Badge variant={source.isActive ? 'success' : 'warning'}>
									{source.isActive ? 'Plugin active' : 'Plugin inactive'}
								</Badge>
							</div>
							<div class="sf:flex sf:items-center sf:gap-2">
								<Badge variant={providerExecutionIsPaused(source.slug) ? 'warning' : 'success'}>
									{providerExecutionIsPaused(source.slug) ? 'Execution paused' : 'Execution running'}
								</Badge>
								<Toggle
									checked={!Boolean(executionProviderDisabled[source.slug])}
									disabled={executionSettingsSaving || executionSettingsLoading || !source.isActive}
									onchange={() =>
										toggleProviderExecutionDisabled(
											source.slug,
											!Boolean(executionProviderDisabled[source.slug])
										)}
								/>
							</div>
						</div>
					{/each}
				</div>
				{#if activeSources.length === 0}
					<Alert variant="warning" class="sf:mt-3">
						Activate at least one form provider to configure actions.
					</Alert>
				{/if}
				{#if executionGlobalDisabled}
					<Alert variant="warning" class="sf:mt-3">
						Global execution is paused. You can still configure mappings while runs are paused.
					</Alert>
				{/if}
			{/if}
		</Card>
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
		<Card class="sf:mt-4">
			<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					{#each activeSources as source}
						<Button
							size="sm"
							variant={selectedSource?.slug === source.slug ? 'primary' : 'secondary'}
							onclick={() => (selectedSource = source)}
						>
							{source.label}
						</Button>
					{/each}
				</div>
				<Button size="sm" variant="secondary" onclick={loadForms} disabled={formsLoading}>
					Refresh forms
				</Button>
			</div>

			<div class="sf:mt-4 sf:flex sf:flex-wrap sf:items-center sf:gap-3">
				<input
					type="text"
					bind:value={searchTerm}
					placeholder="Search forms..."
					class="sf:min-w-0 sf:flex-1 sf:rounded-md sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:text-sm sf:placeholder-slate-400 sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
				/>
				<span class="sf:text-sm sf:text-slate-600">
					{filteredForms.length} form{filteredForms.length !== 1 ? 's' : ''}
				</span>
			</div>

				{#if formsLoading}
					<div class="sf:mt-4">
						<StateTemplate
							variant="loading"
							title="Loading forms"
							message="Retrieving forms for the selected provider."
							inline
							testId="actions-forms-loading-state"
						/>
					</div>
				{:else if displayedForms.length === 0}
					<div class="sf:mt-4">
						<StateTemplate
							variant="empty"
							title="No forms detected"
							message={`No forms were detected for ${selectedSource?.label ?? 'this provider'}. Create a form first, then refresh this page.`}
							actionLabel="Refresh forms"
							onAction={() => {
								void loadForms();
							}}
							inline
							testId="actions-forms-empty-state"
						/>
					</div>
				{:else}
				<div class="sf:mt-4 sf:grid sf:gap-4 sf:lg:grid-cols-2 sf:xl:grid-cols-3">
					{#each displayedForms as form (form.id)}
						{@const actionCount = configuredActionCount(form)}
						{@const enabled = isFormEnabled(form)}
						{@const providerActive = isProviderFormActive(form)}
						{@const health = getHealthBadge(form)}
						<Card data-testid={`actions-form-card-${form.id}`}>
							<div class="sf:flex sf:flex-col sf:items-start sf:gap-3">
								<div class="sf:min-w-0 sf:flex-1">
									<p class="sf:font-semibold sf:text-slate-800 sf:break-words">{form.title}</p>
									<p class="sf:text-xs sf:text-slate-600">ID: {form.id}</p>
								</div>
								<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-1">
									<Badge variant={providerActive ? 'success' : 'warning'}>
										{providerActive ? 'Form active' : 'Form inactive'}
									</Badge>
									<Badge variant={enabled ? 'info' : 'neutral'}>
										{enabled ? 'Automation enabled' : 'Automation paused'}
									</Badge>
									<!-- CB-FORMS-003: Health badge -->
									<span title={health.tooltip}>
										<Badge variant={health.variant}>{health.label}</Badge>
									</span>
									{#if actionCount === null}
										<span class="sf:text-xs sf:text-slate-600">Checking actions…</span>
									{:else if actionCount > 0}
										<span class="sf:text-xs sf:text-indigo-600 sf:font-medium">
											{actionCount} action{actionCount !== 1 ? 's' : ''}
										</span>
									{/if}
								</div>
							</div>
							<div class="sf:mt-3 sf:flex sf:flex-wrap sf:items-center sf:gap-2">
								<span class="sf:text-xs sf:text-slate-600">
									{selectedSource?.label ?? form.adapter_name ?? form.adapter}
								</span>
								{#if actionCount === 0}
									<span class="sf:text-xs sf:text-amber-700">No actions configured</span>
								{/if}
							</div>
							{#if !providerActive}
								<p class="sf:mt-2 sf:text-xs sf:text-amber-700">
									The provider form is inactive. Sentient Forms mappings remain editable, but the
									form itself will not accept live submissions until it is reactivated.
								</p>
							{/if}
							<div class="sf:mt-3">
								<Button size="sm" onclick={() => openFormDetail(form)} class="sf:w-full">
									Configure Actions
								</Button>
								{#if form.provider_edit_url}
									<a
										href={form.provider_edit_url}
											class="sf:mt-2 sf:block sf:text-center sf:text-xs sf:font-medium sf:text-slate-700 hover:sf:text-slate-900"
										data-sveltekit-reload
										rel="external"
										data-testid={`actions-provider-edit-link-${form.id}`}
									>
										Open in {selectedSource?.label ?? form.adapter_name ?? form.adapter}
									</a>
								{/if}
							</div>
						</Card>
					{/each}
				</div>

				{#if totalPages > 1}
					<div class="sf:mt-4 sf:flex sf:items-center sf:justify-center sf:gap-4">
						<Button size="sm" variant="secondary" onclick={prevPage} disabled={currentPage === 1}>
							← Previous
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
							Next →
						</Button>
					</div>
				{/if}
			{/if}
		</Card>
	{/if}
</Section>

<!-- Action-Level Defaults Modal (global configuration) -->
{#if configuringActionId}
	<div
		class="sf:fixed sf:inset-0 sf:bg-black/50 sf:flex sf:items-center sf:justify-center sf:z-50 sf:p-2 sf:sm:p-4"
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
						<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-2 sf:sm:flex-row sf:sm:items-center">
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
							onchange={handleActionModelSelectionChange}
						/>
					</div>

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

						<div class="sf:grid sf:gap-4 sf:md:grid-cols-2">
							<SelectField
								id="action-level-spam-notifications"
								label="Spam notification policy"
								description="Applies to Blocking spam mappings. Background mappings always allow notifications to send immediately."
								value={getInheritableBooleanMode(actionDefaults.suppress_notifications_on_spam)}
								options={[
									{ value: 'inherit', label: 'Use platform default' },
									{ value: 'enabled', label: 'Suppress notifications' },
									{ value: 'disabled', label: 'Allow notifications' }
								]}
								onchange={(event) =>
									handleActionSpamPolicyChange(
										'suppress_notifications_on_spam',
										event.currentTarget.value
									)}
							/>
							<SelectField
								id="action-level-spam-downstream"
								label="Downstream spam gate"
								description="Controls whether downstream work should stop when this spam action confirms spam."
								value={getInheritableBooleanMode(actionDefaults.skip_downstream_on_spam)}
								options={[
									{ value: 'inherit', label: 'Use platform default' },
									{ value: 'enabled', label: 'Skip downstream actions' },
									{ value: 'disabled', label: 'Allow downstream actions' }
								]}
								onchange={(event) =>
									handleActionSpamPolicyChange(
										'skip_downstream_on_spam',
										event.currentTarget.value
									)}
							/>
						</div>
					{/if}

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

					<p class="sf:text-xs sf:text-slate-500 sf:pt-2 sf:flex sf:items-center sf:gap-1">
						<span class="sf:text-amber-500">⚠</span>
						These settings apply globally. Override them per form or per mapping when the workflow
						needs something different.
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
