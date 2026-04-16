<script lang="ts">
	import { onMount } from 'svelte';
	import {
		Section,
		Card,
		Button,
		Badge,
		Alert,
		InputField,
		SelectField,
		FieldSelector,
		ConditionBuilder,
		TemplateLibrary,
			ModelSelector,
			Toggle,
			MappingDependencyGraph,
			StateTemplate
		} from '$lib/components/ui';
	import SpamCriteriaEditor from '$lib/components/spam-criteria-editor.svelte';
	import { DEFAULT_BATCH_SETTINGS } from '$lib/utils/batch';
	import { createDefaultConditionConfig, validateConditionConfig } from '$lib/utils/conditions';
	import {
		createInitialMappingModalSectionExpansion,
		toggleMappingModalSectionExpansion,
		type MappingModalSectionExpansion,
		type MappingModalSectionId
	} from '$lib/utils/mapping-modal-sections';
	import {
		canDependencySatisfyHook,
		deriveDependencyIdsFromTriggerSources,
		findIntroducedDependencyIssues,
		formatDependencyIssues,
		getMappingTriggerHooks,
		getMappingTriggerSources,
		normalizeDependencyIds,
		serializeTriggerSources,
		validateMappingDependencies
	} from '$lib/utils/mapping-dependencies';
	import { navigateToAppPath } from '$lib/navigation';
	import { formActionsStore, formActionsState } from '$lib/stores/form-actions.svelte';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { notifications } from '$lib/stores/notifications';
	import { licenseState } from '$lib/stores/license.svelte';
	import { formMappingsStore } from '$lib/stores/form-mappings.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import type {
		ActionDefinition,
		AttachmentMapping,
		CustomAction,
		CustomActionPostExecutionActionPayload,
		DuplicateParentSelection,
		ExecutionStatus,
		FormActionConfig,
		FormActionLinkage,
		FormActionMutationPayload,
		FormExecutionStatus,
		FormFieldInfo,
		InputMapping,
		ModelSelection,
		WorkflowPlanResponse
	} from '$lib/api/types';
	import {
		applyInheritableBooleanToConfig,
		cloneDefaultModelSelection,
		getInheritableBooleanMode,
		isSpamActionCode,
		modeToOptionalBoolean,
		normalizeFormActionConfig,
		normalizeOptionalBoolean,
		resolveInheritableBoolean,
		resolveInheritableBooleanSource,
		type InheritableBooleanMode
	} from '$lib/utils/action-config';

	type Props = { data: { formSourceSlug: string; formId: number } };
	let { data }: Props = $props();

	const FALLBACK_HOOK_LABELS: Record<string, string> = {
		gform_validation: '🔄 During Validation (Blocking)',
		gform_after_submission: '📝 After Submission (Background)'
	};

	const providerEditUrl = $derived(
		data.formSourceSlug === 'gravity_forms'
			? `admin.php?page=gf_edit_forms&id=${encodeURIComponent(String(data.formId))}`
			: null
	);

	const actionsState = formActionsState;
	const customState = customActionsState;

	let createKind = $state<'template' | 'custom'>('template');
	let selectedTemplateId = $state('');
	let selectedCustomId = $state('');
	let selectedHooks = $state<Set<string>>(new Set());
	let createError = $state<string | null>(null);
	let creating = $state(false);
	let showAddPanel = $state(false);
	let showTemplateLibrary = $state(false);
	let searchTerm = $state('');
	let selectedCreateDependencyIds = $state<Set<string>>(new Set());

	let editingLinkageId = $state<string | null>(null);
	let showMappingConfigModal = $state(false);
	let draftHooks = $state<Set<string>>(new Set());
	let draftSettings = $state<Record<string, any>>({});
	let mappingSectionExpansion = $state<MappingModalSectionExpansion>(
		createInitialMappingModalSectionExpansion(false)
	);
	let editBaselineSignature = $state<string | null>(null);
	type DraftTriggerSource = { type: 'hook_root' | 'mapping' | 'unbound'; mapping_id?: string };
	type DraftTriggerSourceRecord = Record<string, DraftTriggerSource>;
	type EligibleUpstreamSpamTrigger = { hook: string; mapping: FormActionLinkage };
	let graphDraftByMappingId = $state<
		Record<
			string,
			{ dependencyIds: string[]; triggerHooks: string[]; triggerSources: DraftTriggerSourceRecord }
		>
	>({});
	let workflowPlan = $state<WorkflowPlanResponse | null>(null);
	let workflowPlanLoading = $state(false);
	let workflowPlanError = $state<string | null>(null);
	let workflowPlanScope = $state<'all' | string>('all');
	let lastWorkflowPlanSignature = $state<string>('');
	let duplicatingMappingId = $state<string | null>(null);
	let rootAttachUndo = $state<{
		mappingId: string;
		hooks: string[];
		dependencyIds: string[];
		triggerSources: DraftTriggerSourceRecord;
		hook: string;
	} | null>(null);
	let rootAttachUndoTimer: number | null = null;
	const draftDependencyIds = $derived.by(() => {
		const hooks = normalizeHookIds(draftHooks);
		const triggerSources = normalizeDraftTriggerSources(draftSettings.trigger_sources, hooks);
		return deriveDependencyIdsForDraft(triggerSources);
	});
	let savingDependencies = $state(false);
	let linkedActionsView = $state<'graph' | 'table'>('graph');
	let pendingRemovalId = $state<string | null>(null);
	let entryLookupId = $state('');
	let checkedEntryStatus = $state<ExecutionStatus | null>(null);
	let refreshInterval: number | null = null;
	let visibilityHandler: (() => void) | null = null;

	// CA-MAP-001: Field selection state (loaded from API)
	let formFields = $state<FormFieldInfo[]>([]);
	let fieldsLoading = $state(false);

	function createBlankFormActionConfig(): FormActionConfig {
		return normalizeFormActionConfig({});
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

	function isPlainObject(value: unknown): value is Record<string, unknown> {
		return Boolean(value && typeof value === 'object' && !Array.isArray(value));
	}

	function getCustomActionPostExecutionActions(
		action: CustomAction | null
	): CustomActionPostExecutionActionPayload[] {
		const defaults = action?.definition?.execution_defaults;
		if (!isPlainObject(defaults)) {
			return [];
		}

		const actions = defaults.post_execution_actions;
		if (!Array.isArray(actions)) {
			return [];
		}

		return actions.filter(
			(effect): effect is CustomActionPostExecutionActionPayload =>
				isPlainObject(effect) && typeof effect.type === 'string'
		);
	}

	// Form-level action config state (hierarchical spam examples)
	let configuringActionId = $state<string | null>(null);
	let formLevelConfig = $state<FormActionConfig>(createBlankFormActionConfig());
	let formLevelConfigLoading = $state(false);
	let formLevelConfigSaving = $state(false);
	let formLevelConfigByActionId = $state<Record<string, FormActionConfig>>({});
	let actionDefaultsByActionId = $state<Record<string, FormActionConfig>>({});

	async function loadActionDefaultsForAction(
		actionId: string,
		options: { force?: boolean } = {}
	): Promise<FormActionConfig> {
		if (!options.force && actionDefaultsByActionId[actionId]) {
			return actionDefaultsByActionId[actionId];
		}

		const client = createClientFromConfig();
		const config = normalizeFormActionConfig(await client.getActionDefaults(actionId));
		actionDefaultsByActionId = {
			...actionDefaultsByActionId,
			[actionId]: config
		};
		return config;
	}

	async function loadFormLevelConfig(
		actionId: string,
		options: { openModal?: boolean; force?: boolean } = {}
	): Promise<FormActionConfig> {
		const shouldOpenModal = options.openModal ?? true;
		const shouldForce = options.force ?? shouldOpenModal;
		formLevelConfigLoading = true;
		formLevelConfig = createBlankFormActionConfig();
		if (shouldOpenModal) {
			configuringActionId = actionId;
		}

		try {
			if (!shouldForce && formLevelConfigByActionId[actionId]) {
				const cachedConfig = formLevelConfigByActionId[actionId];
				if (shouldOpenModal) {
					formLevelConfig = cachedConfig;
				}
				return cachedConfig;
			}

			const client = createClientFromConfig();
			const config = normalizeFormActionConfig(
				await client.getFormActionConfig(data.formSourceSlug, data.formId, actionId)
			);
			formLevelConfigByActionId = {
				...formLevelConfigByActionId,
				[actionId]: config
			};
			if (shouldOpenModal) {
				formLevelConfig = config;
			}
			return config;
		} catch (error) {
			console.warn('[FormLevelConfig] Failed to load form-level config:', error);
			if (shouldOpenModal) {
				notifications.warning('Could not load saved config. Starting with defaults.');
			};
			return createBlankFormActionConfig();
		} finally {
			formLevelConfigLoading = false;
		}
	}

	function preloadActionHierarchy(actionId: string) {
		void loadActionDefaultsForAction(actionId, { force: false }).catch((error) => {
			console.warn('[ActionDefaults] Failed to preload action defaults:', error);
		});
		void loadFormLevelConfig(actionId, { openModal: false, force: false }).catch((error) => {
			console.warn('[FormLevelConfig] Failed to preload form-level config:', error);
		});
	}

	async function saveFormLevelConfig() {
		if (!configuringActionId) return;
		formLevelConfigSaving = true;
		try {
			const client = createClientFromConfig();
			const savedConfig = normalizeFormActionConfig(
				await client.updateFormActionConfig(
					data.formSourceSlug,
					data.formId,
					configuringActionId,
					formLevelConfig
				)
			);
			formLevelConfigByActionId = {
				...formLevelConfigByActionId,
				[configuringActionId]: savedConfig
			};
			formLevelConfig = savedConfig;
			notifications.success('Form-level configuration saved successfully.');
			configuringActionId = null;
		} catch (error) {
			console.error('[FormLevelConfig] Failed to save form-level config:', error);
			notifications.error('Failed to save form-level configuration.');
		} finally {
			formLevelConfigSaving = false;
		}
	}

	function clearFormLevelModelSelection() {
		const nextConfig = { ...formLevelConfig };
		delete nextConfig.model_selection;
		formLevelConfig = nextConfig;
	}

	function handleFormLevelModelSelectionChange(selection: ModelSelection) {
		formLevelConfig = { ...formLevelConfig, model_selection: selection };
	}

	function handleFormLevelSpamPolicyChange(
		field: 'suppress_notifications_on_spam' | 'skip_downstream_on_spam',
		mode: string
	) {
		formLevelConfig = applyInheritableBooleanToConfig(
			formLevelConfig,
			field,
			mode as InheritableBooleanMode
		);
	}

	function clearMappingModelSelection() {
		const nextDraftSettings = { ...draftSettings };
		delete nextDraftSettings.model_selection;
		draftSettings = nextDraftSettings;
	}

	function handleMappingModelSelectionChange(selection: ModelSelection) {
		draftSettings = { ...draftSettings, model_selection: selection };
	}

	function handleMappingSpamPolicyChange(
		field: 'suppress_notifications_on_spam' | 'skip_downstream_on_spam',
		mode: string
	) {
		const nextDraftSettings = { ...draftSettings };
		const nextValue = modeToOptionalBoolean(mode as InheritableBooleanMode);

		if (typeof nextValue === 'undefined') {
			delete nextDraftSettings[field];
		} else {
			nextDraftSettings[field] = nextValue;
		}

		draftSettings = nextDraftSettings;
	}

	function cancelFormLevelConfig() {
		configuringActionId = null;
		formLevelConfig = createBlankFormActionConfig();
	}

	function handleFormLevelDefaultsBackdropClick(event: MouseEvent) {
		if (event.target !== event.currentTarget) return;
		cancelFormLevelConfig();
	}

	async function loadFormFields() {
		if (fieldsLoading) return;
		fieldsLoading = true;
		try {
			const client = createClientFromConfig();
			formFields = await client.getFormFields(data.formSourceSlug, data.formId);
		} catch (error) {
			console.warn('[FormMapping] Failed to load form fields:', error);
			formFields = []; // Graceful fallback
		} finally {
			fieldsLoading = false;
		}
	}

	const DEFAULT_ATTACHMENT_MAPPING: AttachmentMapping = {
		mode: 'none',
		gf_upload_field_ids: [],
		media_ids: [],
		max_files: 5
	};

	function normalizeAttachmentMapping(raw: unknown): AttachmentMapping {
		if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
			return { ...DEFAULT_ATTACHMENT_MAPPING };
		}

		const candidate = raw as Record<string, unknown>;
		const mode =
			typeof candidate.mode === 'string' &&
			['none', 'gf_upload', 'media_library', 'mixed'].includes(candidate.mode)
				? (candidate.mode as AttachmentMapping['mode'])
				: 'none';
		const gfUploadFieldIds = Array.isArray(candidate.gf_upload_field_ids)
			? Array.from(
					new Set(
						candidate.gf_upload_field_ids
							.map((value) => value?.toString().trim())
							.filter((value): value is string => Boolean(value))
					)
				)
			: [];
		const mediaIds = Array.isArray(candidate.media_ids)
			? Array.from(
					new Set(
						candidate.media_ids
							.map((value) => Number.parseInt(String(value), 10))
							.filter((value) => Number.isFinite(value) && value > 0)
					)
				)
			: [];
		const parsedMaxFiles = Number.parseInt(String(candidate.max_files ?? 5), 10);
		const maxFiles = Number.isFinite(parsedMaxFiles)
			? Math.max(1, Math.min(20, parsedMaxFiles))
			: 5;

		return {
			mode,
			gf_upload_field_ids: gfUploadFieldIds,
			media_ids: mediaIds,
			max_files: maxFiles
		};
	}

	function parseMediaIdsInput(value: string): number[] {
		return Array.from(
			new Set(
				value
					.split(/[,\s]+/)
					.map((part) => Number.parseInt(part.trim(), 10))
					.filter((part) => Number.isFinite(part) && part > 0)
			)
		);
	}

	function updateAttachmentMapping(next: Partial<AttachmentMapping>) {
		const current = normalizeAttachmentMapping(draftSettings.attachment_mapping);
		draftSettings = {
			...draftSettings,
			attachment_mapping: {
				...current,
				...next
			}
		};
	}

	function toggleAttachmentUploadField(fieldId: string) {
		const current = normalizeAttachmentMapping(draftSettings.attachment_mapping);
		const next = new Set(current.gf_upload_field_ids ?? []);
		if (next.has(fieldId)) {
			next.delete(fieldId);
		} else {
			next.add(fieldId);
		}
		updateAttachmentMapping({ gf_upload_field_ids: Array.from(next) });
	}

	const definitions = $derived(actionsState.definitions ?? []);
	const customActions = $derived(
		customState.actions.filter((action) => action.status === 'active')
	);
	const definitionLookup = $derived.by(() =>
		actionsState.definitions.reduce<Record<string, ActionDefinition>>((acc, definition) => {
			acc[definition.id] = definition;
			return acc;
		}, {})
	);
	const customLookupById = $derived.by(() =>
		customActions.reduce<Record<string, CustomAction>>((acc, action) => {
			acc[action.id] = action;
			return acc;
		}, {})
	);
	const customLookupByCode = $derived.by(() =>
		customActions.reduce<Record<string, CustomAction>>((acc, action) => {
			acc[action.code] = action;
			return acc;
		}, {})
	);

	let hookOptions = $state<Record<string, string>>({ ...FALLBACK_HOOK_LABELS });

	$effect(() => {
		const next: Record<string, string> = { ...FALLBACK_HOOK_LABELS };
		for (const definition of actionsState.definitions ?? []) {
			if (!definition?.hooks) continue;

			if (Array.isArray(definition.hooks)) {
				for (const hook of definition.hooks) {
					const key = hook?.toString();
					if (key) next[key] = next[key] ?? key;
				}
			} else if (typeof definition.hooks === 'object') {
				for (const [hook, label] of Object.entries(definition.hooks)) {
					if (hook) next[hook] = label?.toString() ?? hook;
				}
			}
		}
		hookOptions = next;
	});

	const hookEntries = $derived<[string, string][]>(Object.entries(hookOptions));
	const editingLinkage = $derived.by(() => {
		if (!editingLinkageId) return null;
		return actionsState.items.find((item) => item.local_mapping_id === editingLinkageId) ?? null;
	});
	const currentActionDefaults = $derived.by<FormActionConfig>(() => {
		if (!editingLinkage?.central_action_id) {
			return createBlankFormActionConfig();
		}

		return (
			actionDefaultsByActionId[editingLinkage.central_action_id] ?? createBlankFormActionConfig()
		);
	});
	const currentFormActionConfig = $derived.by<FormActionConfig>(() => {
		if (!editingLinkage?.central_action_id) {
			return createBlankFormActionConfig();
		}

		return (
			formLevelConfigByActionId[editingLinkage.central_action_id] ?? createBlankFormActionConfig()
		);
	});
	const currentDraftSignature = $derived.by(() => {
		if (!editingLinkageId) return null;
		return createDraftSignature(draftHooks, draftSettings);
	});
	const hasUnsavedMappingChanges = $derived.by(() => {
		if (!editingLinkageId || !editBaselineSignature || !currentDraftSignature) return false;
		return editBaselineSignature !== currentDraftSignature;
	});
	const isSpamMapping = $derived.by(() => isSpamActionCode(editingLinkage?.central_action_id));
	const effectiveMappingModelSelection = $derived.by<ModelSelection>(() => {
		const mappingSelection =
			draftSettings.model_selection &&
			typeof draftSettings.model_selection === 'object' &&
			!Array.isArray(draftSettings.model_selection)
				? (draftSettings.model_selection as ModelSelection)
				: null;
		if (mappingSelection?.primary) {
			return mappingSelection;
		}

		if (currentFormActionConfig.model_selection?.primary) {
			return currentFormActionConfig.model_selection;
		}

		if (currentActionDefaults.model_selection?.primary) {
			return currentActionDefaults.model_selection;
		}

		return cloneDefaultModelSelection();
	});
	const effectiveMappingModelSource = $derived.by(() => {
		if (draftSettings.model_selection?.primary) {
			return 'mapping';
		}
		if (currentFormActionConfig.model_selection?.primary) {
			return 'form';
		}
		if (currentActionDefaults.model_selection?.primary) {
			return 'action';
		}
		return 'system';
	});
	const isBlockingSpamMapping = $derived.by(
		() => isSpamMapping && draftSettings.execution_mode !== 'after_submission'
	);
	const effectiveSuppressNotificationsOnSpam = $derived.by(() => {
		if (!isSpamMapping) return false;
		return resolveInheritableBoolean(
			[
				normalizeOptionalBoolean(draftSettings.suppress_notifications_on_spam),
				currentFormActionConfig.suppress_notifications_on_spam,
				currentActionDefaults.suppress_notifications_on_spam
			],
			draftSettings.execution_mode !== 'after_submission'
		);
	});
	const effectiveSuppressNotificationsOnSpamSource = $derived.by(() =>
		resolveInheritableBooleanSource(
			[
				{ level: 'mapping', value: normalizeOptionalBoolean(draftSettings.suppress_notifications_on_spam) },
				{ level: 'form', value: currentFormActionConfig.suppress_notifications_on_spam },
				{ level: 'action', value: currentActionDefaults.suppress_notifications_on_spam }
			],
			draftSettings.execution_mode !== 'after_submission'
				? 'blocking default'
				: 'background inactive'
		)
	);
	const effectiveSkipDownstreamOnSpam = $derived.by(() => {
		if (!isSpamMapping) return false;
		return resolveInheritableBoolean(
			[
				normalizeOptionalBoolean(draftSettings.skip_downstream_on_spam),
				currentFormActionConfig.skip_downstream_on_spam,
				currentActionDefaults.skip_downstream_on_spam
			],
			true
		);
	});
	const effectiveSkipDownstreamOnSpamSource = $derived.by(() =>
		resolveInheritableBooleanSource(
			[
				{ level: 'mapping', value: normalizeOptionalBoolean(draftSettings.skip_downstream_on_spam) },
				{ level: 'form', value: currentFormActionConfig.skip_downstream_on_spam },
				{ level: 'action', value: currentActionDefaults.skip_downstream_on_spam }
			],
			'spam default'
		)
	);
	const eligibleUpstreamSpamTriggers = $derived.by<EligibleUpstreamSpamTrigger[]>(() => {
		if (!editingLinkageId) return [];
		const hooks = normalizeHookIds(draftHooks);
		if (hooks.length === 0) return [];
		const dependencyIds = normalizeDependencyIds(draftSettings.dependency_ids);
		const triggerSources = deriveTriggerSourcesForDraft(
			hooks,
			dependencyIds,
			normalizeDraftTriggerSources(draftSettings.trigger_sources, hooks)
		);
		const eligible: EligibleUpstreamSpamTrigger[] = [];
		for (const hook of hooks) {
			const triggerSource = triggerSources[hook];
			if (triggerSource?.type !== 'mapping' || !triggerSource.mapping_id) continue;
			const linkage = getLinkageById(triggerSource.mapping_id);
			if (!linkage || linkage.central_action_id !== 'spam_detection_v1') continue;
			const dependencyHooks = normalizeHookIds(getMappingTriggerHooks(linkage));
			if (!canDependencySatisfyHook(dependencyHooks, hook)) continue;
			eligible.push({ hook, mapping: linkage });
		}
		return eligible;
	});
	const canSkipOnUpstreamSpam = $derived.by(() => eligibleUpstreamSpamTriggers.length > 0);
	const skipOnUpstreamSpamSummary = $derived.by(() => {
		if (!canSkipOnUpstreamSpam) return '';
		const uniqueHookLabels = Array.from(
			new Set(
				eligibleUpstreamSpamTriggers.map(
					({ hook }) => hookOptions[hook] ?? hook ?? 'Unknown hook'
				)
			)
		);
		const uniqueMappingLabels = Array.from(
			new Set(
				eligibleUpstreamSpamTriggers.map(({ mapping }) => friendlyActionLabel(mapping))
			)
		);
		return `Applies on ${uniqueHookLabels.join(', ')} when triggered by ${uniqueMappingLabels.join(', ')}`;
	});
	const guidanceSummary = $derived.by(() => {
		if (!isSpamMapping) return '';
		const localPositive = Array.isArray(draftSettings.spam_positive_examples)
			? draftSettings.spam_positive_examples.length
			: 0;
		const localNegative = Array.isArray(draftSettings.spam_negative_examples)
			? draftSettings.spam_negative_examples.length
			: 0;
		const inheritedPositive = Array.isArray(currentFormActionConfig.spam_positive_examples)
			? currentFormActionConfig.spam_positive_examples.length
			: 0;
		const inheritedNegative = Array.isArray(currentFormActionConfig.spam_negative_examples)
			? currentFormActionConfig.spam_negative_examples.length
			: 0;
		const localTotal = localPositive + localNegative;
		const inheritedTotal = inheritedPositive + inheritedNegative;
		const usingInherited = localTotal === 0 && inheritedTotal > 0;
		const total = usingInherited ? inheritedTotal : localTotal;
		if (total === 0) return 'No examples configured';
		return usingInherited
			? `${total} inherited example${total === 1 ? '' : 's'}`
			: `${total} custom example${total === 1 ? '' : 's'}`;
	});
	const coreSectionSummary = $derived.by(() => {
		const hookCount = draftHooks.size;
		const dependencyCount = draftDependencyIds.length;
		const hookLabel = `${hookCount} hook${hookCount === 1 ? '' : 's'}`;
		if (dependencyCount === 0) {
			return `${hookLabel} · autonomous`;
		}
		return `${hookLabel} · ${dependencyCount} dependenc${dependencyCount === 1 ? 'y' : 'ies'}`;
	});
	const spamAdvancedSummary = $derived.by(() => {
		if (!isSpamMapping) return '';
		const thresholdRaw =
			typeof draftSettings.spam_confidence_threshold !== 'undefined'
				? draftSettings.spam_confidence_threshold
				: 0.8;
		const parsedThreshold = Number.parseFloat(String(thresholdRaw));
		const threshold = Number.isFinite(parsedThreshold) ? parsedThreshold.toFixed(2) : '0.80';
		const displayMode =
			typeof draftSettings.spam_indicators_display === 'string'
				? draftSettings.spam_indicators_display
				: 'simple';
		const notificationPolicy = isBlockingSpamMapping
			? effectiveSuppressNotificationsOnSpam
				? 'suppress notifications'
				: 'allow notifications'
			: 'background notifications';
		const downstreamPolicy = effectiveSkipDownstreamOnSpam
			? 'skip downstream'
			: 'allow downstream';
		return `Threshold ${threshold} · ${displayMode} · ${notificationPolicy} · ${downstreamPolicy}`;
	});
	const inputMappingSummary = $derived.by(() => {
		const mapping = (draftSettings.input_mapping ?? {
			mode: 'selected',
			include_metadata: false
		}) as InputMapping;
		if (mapping.mode === 'all') {
			return mapping.include_metadata ? 'All fields + metadata' : 'All fields';
		}
		if (mapping.mode === 'exclude') {
			const excludedCount = Array.isArray(mapping.field_ids) ? mapping.field_ids.length : 0;
			return excludedCount > 0
				? `All except ${excludedCount} field${excludedCount === 1 ? '' : 's'}`
				: 'Exclude mode';
		}
		const selectedCount = Array.isArray(mapping.field_ids) ? mapping.field_ids.length : 0;
		return selectedCount > 0
			? `${selectedCount} selected field${selectedCount === 1 ? '' : 's'}`
			: 'Selected fields mode';
	});
	const attachmentUploadFields = $derived(
		formFields.filter((field) => ['fileupload', 'post_image'].includes(field.type.toLowerCase()))
	);
	const attachmentMappingSummary = $derived.by(() => {
		const mapping = normalizeAttachmentMapping(draftSettings.attachment_mapping);
		if (mapping.mode === 'none') return 'Disabled';
		const uploadCount = Array.isArray(mapping.gf_upload_field_ids)
			? mapping.gf_upload_field_ids.length
			: 0;
		const mediaCount = Array.isArray(mapping.media_ids) ? mapping.media_ids.length : 0;
		if (mapping.mode === 'gf_upload') {
			return `${uploadCount} upload field${uploadCount === 1 ? '' : 's'}`;
		}
		if (mapping.mode === 'media_library') {
			return `${mediaCount} media item${mediaCount === 1 ? '' : 's'}`;
		}
		return `${uploadCount} upload field${uploadCount === 1 ? '' : 's'} + ${mediaCount} media item${mediaCount === 1 ? '' : 's'}`;
	});
	const conditionsSummary = $derived.by(() => {
		const conditions = (draftSettings.conditions ??
			createDefaultConditionConfig()) as Record<string, unknown>;
		const enabled = conditions.enabled === true;
		if (!enabled) return 'Disabled';
		const root = conditions.root as Record<string, unknown> | undefined;
		const ruleCount = countConditionRules(root);
		return `${ruleCount} rule${ruleCount === 1 ? '' : 's'} active`;
	});
	const modelExecutionSummary = $derived.by(() => {
		const selection = effectiveMappingModelSelection;
		const executionMode =
			draftSettings.execution_mode === 'after_submission' ? 'Background' : 'Blocking';
		const model = selection.primary?.toString().trim() || 'sf_default';
		return `${executionMode} · ${model}`;
	});

	$effect(() => {
		if (!editingLinkageId) return;
		if (draftSettings.skip_on_upstream_spam !== true) return;
		if (canSkipOnUpstreamSpam) return;
		const nextDraftSettings = { ...draftSettings };
		delete nextDraftSettings.skip_on_upstream_spam;
		draftSettings = nextDraftSettings;
	});
	const graphHasDraftChanges = $derived.by(() => Object.keys(graphDraftByMappingId).length > 0);
	const hasGraphUnsavedChanges = $derived(hasUnsavedMappingChanges || graphHasDraftChanges);
	const graphEditingDependencyIds = $derived.by(() => {
		if (!editingLinkageId) return [] as string[];
		return readEffectiveDraftForMapping(editingLinkageId).dependencyIds;
	});
	const graphRenderLinkages = $derived.by<FormActionLinkage[]>(() =>
		actionsState.items.map((item) => {
			const draft = graphDraftByMappingId[item.local_mapping_id];
			const nextHooks = draft?.triggerHooks ?? item.trigger_hooks ?? [];
			const nextDependencyIds =
				draft?.dependencyIds ?? normalizeDependencyIds(item.settings?.dependency_ids);
			const nextTriggerSources =
				draft?.triggerSources ?? serializeTriggerSources(getMappingTriggerSources(item));
			const nextSettings: Record<string, unknown> = {
				...(item.settings ?? {})
			};
			if (nextDependencyIds.length > 0) {
				nextSettings.dependency_ids = nextDependencyIds;
			} else {
				delete nextSettings.dependency_ids;
			}
			nextSettings.trigger_sources = nextTriggerSources;

			return {
				...item,
				trigger_hooks: nextHooks,
				settings: nextSettings as FormActionLinkage['settings']
			};
		})
	);
	const editableDependenciesForCreate = $derived.by(() => {
		const requiredHooks = normalizeHookIds(selectedHooks);
		if (requiredHooks.length === 0) return [] as FormActionLinkage[];

		return actionsState.items.filter((linkage) => {
			if (linkage.is_action_enabled_for_form === false) return false;
			const dependencyHooks = normalizeHookIds(getMappingTriggerHooks(linkage));
			return dependencySupportsSelectedHooks(dependencyHooks, requiredHooks);
		});
	});

	const hasDefinitions = $derived(definitions.length > 0);
	const hasCpsDefinitions = $derived(
		hasDefinitions && definitions.some((definition) => definition.source === 'cps')
	);
	const hasLocalDefinitions = $derived(
		hasDefinitions && definitions.some((definition) => (definition.source ?? 'local') !== 'cps')
	);
	const definitionsBadgeVariant = $derived(hasCpsDefinitions ? 'success' : 'warning');
	const definitionsBadgeLabel = $derived(hasCpsDefinitions ? 'CPS templates' : 'Local fallback');

	const selectedDefinition = $derived(
		selectedTemplateId ? definitionLookup[selectedTemplateId] : undefined
	);
	const selectedCustomAction = $derived(
		selectedCustomId ? (customLookupById[selectedCustomId] ?? null) : null
	);
	const selectedActionKey = $derived(
		`${createKind}:${createKind === 'template' ? selectedTemplateId : selectedCustomId}`
	);

	let lastPresetKey = $state<string | null>(null);
	const LAST_HOOKS_KEY = 'sentient_forms_last_hooks';
	$effect(() => {
		if (!selectedActionKey || selectedActionKey === lastPresetKey) return;
		const presetHooks =
			createKind === 'template'
				? normalizeDefinitionHooks(selectedDefinition?.hooks)
				: ['gform_validation'];
		const normalized = presetHooks.length > 0 ? presetHooks : ['gform_validation'];
		selectedHooks = new Set(normalized);
		lastPresetKey = selectedActionKey;
	});

	$effect(() => {
		if (!selectedTemplateId && hasDefinitions) {
			selectedTemplateId = definitions[0]?.id ?? '';
		}
	});

	$effect(() => {
		if (!selectedCustomId && customActions.length > 0) {
			selectedCustomId = customActions[0]?.id ?? '';
		}
	});

	$effect(() => {
		if (createKind === 'template' && !hasDefinitions && customActions.length > 0) {
			createKind = 'custom';
		}
	});

	$effect(() => {
		// Ensure at least one hook is preselected when opening the drawer
		if (showAddPanel && selectedHooks.size === 0) {
			const firstHook = Object.keys(hookOptions)[0] ?? 'gform_validation';
			selectedHooks = new Set([firstHook]);
		}
	});

	$effect(() => {
		const eligibleIds = new Set(editableDependenciesForCreate.map((item) => item.local_mapping_id));
		let changed = false;
		const next = new Set<string>();
		for (const mappingId of selectedCreateDependencyIds) {
			if (eligibleIds.has(mappingId)) {
				next.add(mappingId);
			} else {
				changed = true;
			}
		}
		if (changed) {
			selectedCreateDependencyIds = next;
		}
	});

	$effect(() => {
		const validIds = new Set(actionsState.items.map((item) => item.local_mapping_id));
		let changed = false;
		const next: Record<
			string,
			{ dependencyIds: string[]; triggerHooks: string[]; triggerSources: DraftTriggerSourceRecord }
		> = {};
		for (const [mappingId, draft] of Object.entries(graphDraftByMappingId)) {
			if (!validIds.has(mappingId)) {
				changed = true;
				continue;
			}
			next[mappingId] = draft;
		}
		if (changed) {
			graphDraftByMappingId = next;
		}
	});

	function persistLastHooks(hooks: string[]) {
		try {
			localStorage.setItem(LAST_HOOKS_KEY, JSON.stringify(hooks));
		} catch (err) {
			console.warn('Could not persist hooks', err);
		}
	}

	function restoreLastHooks() {
		try {
			const raw = localStorage.getItem(LAST_HOOKS_KEY);
			if (!raw) return;
			const parsed = JSON.parse(raw);
			if (Array.isArray(parsed) && parsed.every((h) => typeof h === 'string')) {
				selectedHooks = new Set(parsed);
			}
		} catch (err) {
			console.warn('Could not restore hooks', err);
		}
	}

	function startRefreshInterval() {
		if (refreshInterval !== null) return;
		refreshInterval = window.setInterval(
			() => formActionsStore.refresh(data.formSourceSlug, data.formId),
			30_000
		);
	}

	function stopRefreshInterval() {
		if (refreshInterval !== null) {
			window.clearInterval(refreshInterval);
			refreshInterval = null;
		}
	}

	onMount(() => {
		formActionsStore.load(data.formSourceSlug, data.formId);
		customActionsStore.load({ status: 'active' });
		loadFormFields(); // CA-MAP-001: Load form fields for FieldSelector
		restoreLastHooks();
		startRefreshInterval();

		visibilityHandler = () => {
			if (document.visibilityState === 'hidden') {
				stopRefreshInterval();
			} else {
				void formActionsStore.refresh(data.formSourceSlug, data.formId);
				startRefreshInterval();
			}
		};
		document.addEventListener('visibilitychange', visibilityHandler);

		return () => {
			stopRefreshInterval();
			if (visibilityHandler) {
				document.removeEventListener('visibilitychange', visibilityHandler);
			}
			clearRootAttachUndoState();
			formActionsStore.reset();
		};
	});

	$effect(() => {
		actionsState.items;
		actionsState.loading;
		if (actionsState.loading) return;
		if ((actionsState.items ?? []).length === 0) {
			workflowPlan = null;
			workflowPlanError = null;
			lastWorkflowPlanSignature = '';
			return;
		}
		void loadWorkflowPlan(workflowPlanScope);
	});

	function normalizeDefinitionHooks(hooks?: Record<string, string> | string[]): string[] {
		if (!hooks) return [];
		return Array.isArray(hooks) ? hooks : Object.keys(hooks);
	}

	function summarizeDefinitionHooks(hooks?: Record<string, string> | string[]): string {
		if (!hooks) return 'Default (gform_validation)';
		if (Array.isArray(hooks)) {
			return hooks.length > 0 ? hooks.join(', ') : 'Default (gform_validation)';
		}
		const labels = Object.values(hooks);
		return labels.length > 0 ? labels.join(', ') : 'Default (gform_validation)';
	}

	const statusBadgeVariant = (status: FormExecutionStatus) => {
		if (status.status === 'error') return 'danger';
		if (status.status === 'success') return 'success';
		return 'info';
	};

	const statusHeadline = (status: FormExecutionStatus) => {
		switch (status.status) {
			case 'success':
				return 'Last run succeeded';
			case 'error':
				return 'Last run failed';
			default:
				return 'Awaiting first run';
		}
	};

	const statusDescription = (status: FormExecutionStatus) => {
		if (status.message && status.message.length > 0) return status.message;
		if (status.status === 'unknown') return 'Sentient Forms has not processed any entries yet.';
		return 'Sentient Forms recently attempted to run. Review the guidance below for next steps.';
	};

	type AdviceActionId = 'refresh' | 'licensing';
	type AdviceAction = { id: AdviceActionId; label: string; variant?: 'primary' | 'secondary' };
	type StatusAdvice = {
		variant: 'info' | 'success' | 'warning' | 'danger';
		title: string;
		description: string;
		actions?: AdviceAction[];
	};

	const deriveStatusAdvice = (status: FormExecutionStatus | null): StatusAdvice | null => {
		if (!status) return null;
		if (status.status === 'error') {
			const code = status.last_error_code ?? '';
			switch (code) {
				case 'insufficient_credits':
					return {
						variant: 'warning',
						title: 'Out of credits',
						description:
							'Sentient Forms could not execute the last submission because this license is out of credits. Visit the Licensing tab to add credits before retrying.',
						actions: [
							{ id: 'licensing', label: 'Open Licensing', variant: 'primary' },
							{ id: 'refresh', label: 'Refresh status' }
						]
					};
				case 'cps_missing_proxy_key':
					return {
						variant: 'warning',
						title: 'License activation required',
						description:
							'Sentient Forms proxy credentials are missing. Activate your license on the Licensing tab, then retry the submission.',
						actions: [
							{ id: 'licensing', label: 'Open Licensing', variant: 'primary' },
							{ id: 'refresh', label: 'Refresh status' }
						]
					};
				case 'timeout':
					return {
						variant: 'danger',
						title: 'CPS timed out',
						description:
							'Sentient Forms timed out while contacting the CPS service. Retry the request shortly. If timeouts persist, inspect your network connectivity.',
						actions: [{ id: 'refresh', label: 'Retry now', variant: 'primary' }]
					};
				case 'rate_limited':
					return {
						variant: 'warning',
						title: 'Rate limited by CPS',
						description:
							'The CPS service temporarily rate limited this action. Wait about a minute before retrying.',
						actions: [{ id: 'refresh', label: 'Refresh status' }]
					};
				case 'duplicate_execution':
					return {
						variant: 'info',
						title: 'Already processed',
						description:
							'Sentient Forms already processed this submission. Refresh the status to review the existing result.',
						actions: [{ id: 'refresh', label: 'Refresh status', variant: 'primary' }]
					};
				case 'llm_error':
					return {
						variant: 'warning',
						title: 'Upstream LLM error',
						description:
							'The upstream LLM provider reported an error. Retry shortly and contact support if it keeps happening.',
						actions: [{ id: 'refresh', label: 'Refresh status' }]
					};
				case 'invalid_action_id':
					return {
						variant: 'danger',
						title: 'Action mapping is invalid',
						description:
							'The linked CPS action no longer exists or is misconfigured. Edit the action mapping to point at a valid CPS action before retrying.',
						actions: [{ id: 'refresh', label: 'Refresh status' }]
					};
				default:
					return {
						variant: 'danger',
						title: 'Sentient Forms execution failed',
						description:
							status.message ??
							'Sentient Forms could not complete the last submission. Review the error details, then retry the request.',
						actions: [{ id: 'refresh', label: 'Refresh status', variant: 'primary' }]
					};
			}
		}

		if (status.status === 'unknown') {
			return {
				variant: 'info',
				title: 'Awaiting first run',
				description:
					'Sentient Forms has not processed any entries for this form yet. Submit a test entry, then refresh this status panel.',
				actions: [{ id: 'refresh', label: 'Refresh status' }]
			};
		}

		if (status.status === 'success') {
			return {
				variant: 'success',
				title: 'Sentient Forms ran successfully',
				description:
					status.message ??
					'The most recent submission completed successfully. You can refresh to see the latest credit balance or run another test.',
				actions: [{ id: 'refresh', label: 'Refresh status' }]
			};
		}

		return null;
	};

	const statusAdvice = $derived(deriveStatusAdvice(actionsState.status));

	const formatBaseCreditCost = (definition: ActionDefinition) =>
		typeof definition.baseCreditCost === 'number' ? `${definition.baseCreditCost}` : '—';
	const formatModelHint = (definition: ActionDefinition) => definition.modelHint ?? '—';
	const definitionSourceBadgeVariant = (definition: ActionDefinition) =>
		definition.source === 'cps' ? 'success' : 'warning';

	function invalidHooksForLinkage(linkage: FormActionLinkage): string[] {
		const triggerHooks = normalizeHookIds(getMappingTriggerHooks(linkage));
		const triggerSources = getMappingTriggerSources(linkage);
		return triggerHooks.filter((hook) => triggerSources[hook]?.type === 'unbound');
	}

	function isLinkageInvalid(linkage: FormActionLinkage): boolean {
		return invalidHooksForLinkage(linkage).length > 0;
	}

	function statusVariant(linkage: FormActionLinkage) {
		if (isLinkageInvalid(linkage)) return 'danger';
		return linkage.is_action_enabled_for_form === false ? 'warning' : 'success';
	}

	function statusLabel(linkage: FormActionLinkage) {
		if (isLinkageInvalid(linkage)) return 'Invalid';
		return linkage.is_action_enabled_for_form === false ? 'Disabled' : 'Enabled';
	}

	function friendlyActionLabel(linkage: FormActionLinkage): string {
		if (linkage.action_name_label) return linkage.action_name_label;
		const template = definitionLookup[linkage.central_action_id];
		if (template?.label) return template.label;
		const custom =
			customLookupByCode[linkage.central_action_id] ?? customLookupById[linkage.central_action_id];
		if (custom?.display_name) return custom.display_name;
		return linkage.central_action_id ?? 'Unnamed action';
	}

	function dependencyBadgeLabel(mappingId: string): string {
		const linked = actionsState.items.find((item) => item.local_mapping_id === mappingId);
		if (!linked) return mappingId;
		return friendlyActionLabel(linked);
	}

	function countConditionRules(group: Record<string, unknown> | undefined): number {
		if (!group) return 0;
		const rules = Array.isArray(group.rules) ? group.rules : [];
		let count = 0;
		for (const rule of rules) {
			if (!rule || typeof rule !== 'object') continue;
			const candidate = rule as Record<string, unknown>;
			if (candidate.type === 'group') {
				count += countConditionRules(candidate);
				continue;
			}
			count += 1;
		}
		return count;
	}

	function isSpamMappingLinkage(linkage: FormActionLinkage | null | undefined): boolean {
		return linkage?.central_action_id === 'spam_detection_v1';
	}

	function resetMappingSectionExpansion(linkage: FormActionLinkage | null | undefined) {
		mappingSectionExpansion = createInitialMappingModalSectionExpansion(
			isSpamMappingLinkage(linkage)
		);
	}

	function toggleMappingSection(sectionId: MappingModalSectionId) {
		mappingSectionExpansion = toggleMappingModalSectionExpansion(mappingSectionExpansion, sectionId);
	}

	function closeMappingConfigModal() {
		showMappingConfigModal = false;
	}

	function handleMappingConfigBackdropClick(event: MouseEvent) {
		if (event.target !== event.currentTarget) return;
		closeMappingConfigModal();
	}

	function handleWindowKeydown(event: KeyboardEvent) {
		if (!showMappingConfigModal) return;
		if (event.key !== 'Escape') return;
		event.preventDefault();
		closeMappingConfigModal();
	}

	function normalizeHookIds(hooks: Iterable<string>): string[] {
		return Array.from(
			new Set(
				Array.from(hooks)
					.map((hook) => hook?.toString().trim())
					.filter(Boolean)
			)
		).sort();
	}

	function dependencySupportsSelectedHooks(
		dependencyHooks: string[],
		requiredHooks: string[]
	): boolean {
		for (const requiredHook of requiredHooks) {
			if (!canDependencySatisfyHook(dependencyHooks, requiredHook)) {
				return false;
			}
		}
		return true;
	}

	function getLinkageById(mappingId: string): FormActionLinkage | null {
		return actionsState.items.find((item) => item.local_mapping_id === mappingId) ?? null;
	}

	function normalizeDraftTriggerSources(value: unknown, hooks: string[]): DraftTriggerSourceRecord {
		const hookSet = new Set(hooks);
		if (!value || typeof value !== 'object' || Array.isArray(value)) {
			return {};
		}

		const normalized: DraftTriggerSourceRecord = {};
		for (const [hook, rawSource] of Object.entries(value as Record<string, unknown>)) {
			if (!hookSet.has(hook)) continue;
			if (!rawSource || typeof rawSource !== 'object' || Array.isArray(rawSource)) continue;
			const source = rawSource as Record<string, unknown>;
			const type = typeof source.type === 'string' ? source.type.trim().toLowerCase() : '';
			if (type === 'hook_root' || type === 'root') {
				normalized[hook] = { type: 'hook_root' };
				continue;
			}
			if (type === 'unbound' || type === 'detached') {
				normalized[hook] = { type: 'unbound' };
				continue;
			}
			if (type !== 'mapping') continue;
			const mappingId =
				typeof source.mapping_id === 'string'
					? source.mapping_id.trim()
					: typeof source.mappingId === 'string'
						? source.mappingId.trim()
						: '';
			if (!mappingId) continue;
			normalized[hook] = { type: 'mapping', mapping_id: mappingId };
		}
		return normalized;
	}

	function deriveTriggerSourcesForDraft(
		triggerHooks: string[],
		dependencyIds: string[],
		existing: DraftTriggerSourceRecord
	): DraftTriggerSourceRecord {
		const next: DraftTriggerSourceRecord = {};
		const primaryDependency = dependencyIds[0] ?? null;
		for (const hook of triggerHooks) {
			const source = existing[hook];
			if (source?.type === 'mapping' && source.mapping_id) {
				next[hook] = { type: 'mapping', mapping_id: source.mapping_id };
				continue;
			}
			if (source?.type === 'hook_root') {
				next[hook] = { type: 'hook_root' };
				continue;
			}
			if (source?.type === 'unbound') {
				next[hook] = { type: 'unbound' };
				continue;
			}
			if (primaryDependency) {
				next[hook] = { type: 'mapping', mapping_id: primaryDependency };
				continue;
			}
			next[hook] = { type: 'hook_root' };
		}
		return next;
	}

	function deriveDependencyIdsForDraft(triggerSources: DraftTriggerSourceRecord): string[] {
		return Array.from(
			new Set(
				Object.values(triggerSources)
					.filter((source) => source.type === 'mapping' && Boolean(source.mapping_id))
					.map((source) => source.mapping_id as string)
			)
		);
	}

	function readEffectiveDraftForMapping(mappingId: string): {
		dependencyIds: string[];
		triggerHooks: string[];
		triggerSources: DraftTriggerSourceRecord;
	} {
		const linkage = getLinkageById(mappingId);
		const fallback = {
			dependencyIds: [] as string[],
			triggerHooks: [] as string[],
			triggerSources: {} as DraftTriggerSourceRecord
		};
		if (!linkage) return fallback;

		const explicitDraft = graphDraftByMappingId[mappingId];
		if (explicitDraft) {
			const hooks = normalizeHookIds(explicitDraft.triggerHooks);
			const sources = deriveTriggerSourcesForDraft(
				hooks,
				normalizeDependencyIds(explicitDraft.dependencyIds),
				normalizeDraftTriggerSources(explicitDraft.triggerSources, hooks)
			);
			return {
				dependencyIds: deriveDependencyIdsForDraft(sources),
				triggerHooks: hooks,
				triggerSources: sources
			};
		}

		if (editingLinkageId === mappingId && hasUnsavedMappingChanges) {
			const hooks = normalizeHookIds(draftHooks);
			const sources = deriveTriggerSourcesForDraft(
				hooks,
				normalizeDependencyIds(draftSettings.dependency_ids),
				normalizeDraftTriggerSources(draftSettings.trigger_sources, hooks)
			);
			return {
				dependencyIds: deriveDependencyIdsForDraft(sources),
				triggerHooks: hooks,
				triggerSources: sources
			};
		}

		const hooks = normalizeHookIds(getMappingTriggerHooks(linkage));
		const baseSources = getMappingTriggerSources(linkage);
		const normalizedSources: DraftTriggerSourceRecord = {};
		for (const hook of hooks) {
			const source = baseSources[hook];
			if (!source || source.type === 'hook_root') {
				normalizedSources[hook] = { type: 'hook_root' };
				continue;
			}
			if (!source.mappingId) {
				normalizedSources[hook] = { type: 'hook_root' };
				continue;
			}
			normalizedSources[hook] = {
				type: 'mapping',
				mapping_id: source.mappingId
			};
		}

		return {
			dependencyIds: deriveDependencyIdsForDraft(normalizedSources),
			triggerHooks: hooks,
			triggerSources: normalizedSources
		};
	}

	function hasDraftDifference(
		mappingId: string,
		dependencyIds: string[],
		triggerHooks: string[],
		triggerSources: DraftTriggerSourceRecord
	): boolean {
		const linkage = getLinkageById(mappingId);
		if (!linkage) return false;
		const baseHooks = normalizeHookIds(getMappingTriggerHooks(linkage));
		const baseSourceMap = getMappingTriggerSources(linkage);
		const baseTriggerSources: DraftTriggerSourceRecord = {};
		for (const hook of baseHooks) {
			const source = baseSourceMap[hook];
			if (!source || source.type === 'hook_root') {
				baseTriggerSources[hook] = { type: 'hook_root' };
				continue;
			}
			baseTriggerSources[hook] = {
				type: 'mapping',
				mapping_id: source.mappingId
			};
		}
		const baseDependencyIds = deriveDependencyIdsForDraft(baseTriggerSources);
		if (
			dependencyIds.length !== baseDependencyIds.length ||
			triggerHooks.length !== baseHooks.length
		) {
			return true;
		}
		if (
			dependencyIds.some((id, index) => id !== baseDependencyIds[index]) ||
			triggerHooks.some((hook, index) => hook !== baseHooks[index])
		) {
			return true;
		}

		const baseSerialized = JSON.stringify(sortKeysDeep(baseTriggerSources));
		const nextSerialized = JSON.stringify(sortKeysDeep(triggerSources));
		return baseSerialized !== nextSerialized;
	}

	function toggleHookSelection(hook: string) {
		const next = new Set(selectedHooks);
		next.has(hook) ? next.delete(hook) : next.add(hook);
		selectedHooks = next;
		persistLastHooks(Array.from(next));
	}

	function toggleCreateDependencySelection(mappingId: string) {
		if (selectedCreateDependencyIds.has(mappingId)) {
			selectedCreateDependencyIds = new Set();
			return;
		}
		selectedCreateDependencyIds = new Set([mappingId]);
	}

	function openAddActionPanel() {
		selectedCreateDependencyIds = new Set();
		createError = null;
		showAddPanel = true;
	}

	function normalizeDraftHooks(hooks: Iterable<string>): string[] {
		return Array.from(
			new Set(
				Array.from(hooks)
					.map((hook) => hook?.toString().trim())
					.filter(Boolean)
			)
		).sort();
	}

	function sortKeysDeep(value: unknown): unknown {
		if (Array.isArray(value)) {
			return value.map((item) => sortKeysDeep(item));
		}

		if (!value || typeof value !== 'object') {
			return value;
		}

		const entries = Object.entries(value as Record<string, unknown>)
			.filter(([, entry]) => typeof entry !== 'undefined')
			.sort(([a], [b]) => a.localeCompare(b));
		const normalized: Record<string, unknown> = {};
		for (const [key, entry] of entries) {
			normalized[key] = sortKeysDeep(entry);
		}
		return normalized;
	}

	function normalizeDraftSettings(
		settings: Record<string, any>,
		hooks: string[]
	): Record<string, unknown> {
		const normalized = sortKeysDeep(settings) as Record<string, unknown>;
		const dependencyIds = normalizeDependencyIds(normalized.dependency_ids);
		if (dependencyIds.length > 0) {
			normalized.dependency_ids = dependencyIds;
		} else {
			delete normalized.dependency_ids;
		}
		const triggerSources = normalizeDraftTriggerSources(normalized.trigger_sources, hooks);
		normalized.trigger_sources = triggerSources;
		normalized.dependency_ids = deriveDependencyIdsForDraft(triggerSources);
		if (normalized.skip_on_upstream_spam !== true) {
			delete normalized.skip_on_upstream_spam;
		}
		return normalized;
	}

	function createDraftSignature(hooks: Iterable<string>, settings: Record<string, any>): string {
		const normalizedHooks = normalizeDraftHooks(hooks);
		return JSON.stringify(
			sortKeysDeep({
				hooks: normalizedHooks,
				settings: normalizeDraftSettings(settings, normalizedHooks)
			})
		);
	}

	function createWorkflowPlanSignature(
		items: FormActionLinkage[],
		hookScope: 'all' | string
	): string {
		return JSON.stringify(
			items.map((item) => ({
				id: item.local_mapping_id,
				hooks: normalizeHookIds(getMappingTriggerHooks(item)),
				enabled: item.is_action_enabled_for_form !== false,
				dependency_ids: normalizeDependencyIds(item.settings?.dependency_ids),
				trigger_sources: serializeTriggerSources(getMappingTriggerSources(item)),
				scope: hookScope
			}))
		);
	}

	function buildLinkagesFromDraftMap(
		draftMap: Record<
			string,
			{ dependencyIds: string[]; triggerHooks: string[]; triggerSources: DraftTriggerSourceRecord }
		>
	): FormActionLinkage[] {
		return actionsState.items.map((item) => {
			const draft = draftMap[item.local_mapping_id];
			if (!draft) return item;
			const nextSettings: Record<string, unknown> = {
				...(item.settings ?? {})
			};
			if (draft.dependencyIds.length > 0) {
				nextSettings.dependency_ids = draft.dependencyIds;
			} else {
				delete nextSettings.dependency_ids;
			}
			nextSettings.trigger_sources = draft.triggerSources;

			return {
				...item,
				trigger_hooks: draft.triggerHooks,
				settings: nextSettings as FormActionLinkage['settings']
			};
		});
	}

	function applyGraphDraftMutation(
		mappingId: string,
		dependencyIds: string[],
		triggerHooks: string[],
		triggerSources: DraftTriggerSourceRecord,
		options: { syncModal?: boolean; showErrors?: boolean; allowUnboundIssues?: boolean } = {}
	): boolean {
		const linkage = getLinkageById(mappingId);
		if (!linkage) return false;

		const nextHooks = normalizeHookIds(triggerHooks);
		const normalizedSources = deriveTriggerSourcesForDraft(
			nextHooks,
			normalizeDependencyIds(dependencyIds),
			normalizeDraftTriggerSources(triggerSources, nextHooks)
		);
		const nextDependencies = deriveDependencyIdsForDraft(normalizedSources);
		const nextDraftMap = { ...graphDraftByMappingId };
		if (hasDraftDifference(mappingId, nextDependencies, nextHooks, normalizedSources)) {
			nextDraftMap[mappingId] = {
				dependencyIds: nextDependencies,
				triggerHooks: nextHooks,
				triggerSources: normalizedSources
			};
		} else {
			delete nextDraftMap[mappingId];
		}

		const candidateItems = buildLinkagesFromDraftMap(nextDraftMap);
		const baselineItems = buildLinkagesFromDraftMap(graphDraftByMappingId);
		const introducedIssues = findIntroducedDependencyIssues(
			validateMappingDependencies(baselineItems),
			validateMappingDependencies(candidateItems)
		);
		const blockingIssues =
			options.allowUnboundIssues === true
				? introducedIssues.filter((issue) => issue.code !== 'unbound_trigger')
				: introducedIssues;
		if (blockingIssues.length > 0) {
			if (options.showErrors ?? true) {
				notifications.error(formatDependencyIssues(blockingIssues)[0]);
			}
			return false;
		}

		graphDraftByMappingId = nextDraftMap;
		if (options.syncModal && showMappingConfigModal && editingLinkageId === mappingId) {
			draftHooks = new Set(nextHooks);
			draftSettings = {
				...draftSettings,
				dependency_ids: nextDependencies,
				trigger_sources: normalizedSources
			};
		}
		return true;
	}

	function clearRootAttachUndoState() {
		rootAttachUndo = null;
		if (rootAttachUndoTimer !== null) {
			window.clearTimeout(rootAttachUndoTimer);
			rootAttachUndoTimer = null;
		}
	}

	function scheduleRootAttachUndoExpiry() {
		if (rootAttachUndoTimer !== null) {
			window.clearTimeout(rootAttachUndoTimer);
		}
		rootAttachUndoTimer = window.setTimeout(() => {
			rootAttachUndo = null;
			rootAttachUndoTimer = null;
		}, 9000);
	}

	async function loadWorkflowPlan(hookScope: 'all' | string = workflowPlanScope) {
		workflowPlanScope = hookScope;
		const signature = createWorkflowPlanSignature(actionsState.items ?? [], hookScope);
		if (signature === lastWorkflowPlanSignature && workflowPlan && !workflowPlanError) {
			return;
		}

		workflowPlanLoading = true;
		workflowPlanError = null;
		try {
			const client = createClientFromConfig();
			workflowPlan = await client.getWorkflowPlan(data.formSourceSlug, data.formId, hookScope, {
				showNotifications: false
			});
			lastWorkflowPlanSignature = signature;
		} catch (error) {
			workflowPlanError =
				error instanceof Error
					? error.message
					: 'Workflow plan unavailable; using local fallback preview.';
			workflowPlan = null;
		} finally {
			workflowPlanLoading = false;
		}
	}

	function startEditingAction(linkage: FormActionLinkage, openModal = true) {
		const draftSnapshot = readEffectiveDraftForMapping(linkage.local_mapping_id);
		const initialHooks =
			draftSnapshot.triggerHooks.length > 0
				? draftSnapshot.triggerHooks
				: [hookEntries[0]?.[0] ?? 'gform_validation'];
		draftHooks = new Set(initialHooks);
		// Clone settings with sensible defaults to avoid Svelte 5 $bindable() issues with undefined
		const baseSettings = linkage.settings ?? {};
		const nextDraftSettings = {
			spam_confidence_threshold: baseSettings.spam_confidence_threshold ?? 0.8,
			spam_result_display_mode: baseSettings.spam_result_display_mode ?? 'entry_note',
			spam_indicators_display: baseSettings.spam_indicators_display ?? 'simple',
			include_site_context: baseSettings.include_site_context ?? 'global',
			spam_positive_examples: baseSettings.spam_positive_examples ?? [],
			spam_negative_examples: baseSettings.spam_negative_examples ?? [],
			// CB-EXEC-002: Execution mode - default to after_submission (async) for safety
			execution_mode: baseSettings.execution_mode ?? 'after_submission',
			// CB-EXEC-003/004: Batch settings with sensible defaults
			batch_settings: baseSettings.batch_settings ?? { ...DEFAULT_BATCH_SETTINGS },
			...baseSettings,
			dependency_ids: draftSnapshot.dependencyIds,
			trigger_sources: draftSnapshot.triggerSources,
			conditions: baseSettings.conditions ?? createDefaultConditionConfig()
		};
		draftSettings = nextDraftSettings;
		editingLinkageId = linkage.local_mapping_id;
		resetMappingSectionExpansion(linkage);
		showMappingConfigModal = openModal;
		editBaselineSignature = createDraftSignature(initialHooks, nextDraftSettings);
		clearRootAttachUndoState();
		preloadActionHierarchy(linkage.central_action_id);
	}

	function cancelEditingAction() {
		editingLinkageId = null;
		showMappingConfigModal = false;
		draftHooks = new Set();
		draftSettings = {};
		resetMappingSectionExpansion(null);
		editBaselineSignature = null;
		clearRootAttachUndoState();
	}

	function clearDraftDependencies() {
		if (!editingLinkageId) return;
		const current = readEffectiveDraftForMapping(editingLinkageId);
		const nextSources: DraftTriggerSourceRecord = Object.fromEntries(
			current.triggerHooks.map((hook) => [hook, { type: 'hook_root' as const }])
		);
		applyGraphDraftMutation(editingLinkageId, [], current.triggerHooks, nextSources, {
			syncModal: true
		});
		clearRootAttachUndoState();
	}

	function attachMappingToHookRoot(mappingId: string, hook: string) {
		const normalizedMappingId = mappingId?.toString().trim();
		if (!normalizedMappingId) return;
		const normalizedHook = hook?.toString().trim();
		if (!normalizedHook) return;
		const current = readEffectiveDraftForMapping(normalizedMappingId);

		const previous = {
			mappingId: normalizedMappingId,
			hooks: current.triggerHooks,
			dependencyIds: current.dependencyIds,
			triggerSources: current.triggerSources,
			hook: normalizedHook
		};

		const nextHooks = normalizeHookIds([...current.triggerHooks, normalizedHook]);
		const nextSources: DraftTriggerSourceRecord = {
			...current.triggerSources,
			[normalizedHook]: { type: 'hook_root' }
		};
		const applied = applyGraphDraftMutation(
			normalizedMappingId,
			deriveDependencyIdsForDraft(nextSources),
			nextHooks,
			nextSources,
			{
				syncModal: true
			}
		);
		if (!applied) return;

		editingLinkageId = normalizedMappingId;
		rootAttachUndo = previous;
		scheduleRootAttachUndoExpiry();

		const hookLabel = hookOptions[normalizedHook] ?? normalizedHook;
		notifications.info(`Attached to root "${hookLabel}". Hook trigger updated in draft.`);
	}

	function undoLastRootAttach() {
		if (!rootAttachUndo) return;
		const restored = applyGraphDraftMutation(
			rootAttachUndo.mappingId,
			rootAttachUndo.dependencyIds,
			rootAttachUndo.hooks,
			rootAttachUndo.triggerSources,
			{ syncModal: true }
		);
		if (!restored) return;
		editingLinkageId = rootAttachUndo.mappingId;
		notifications.info('Restored previous dependencies and hooks.');
		clearRootAttachUndoState();
	}

	function openGraphDependencyEditor() {
		if (editingLinkageId && showMappingConfigModal) {
			const current = readEffectiveDraftForMapping(editingLinkageId);
			applyGraphDraftMutation(
				editingLinkageId,
				current.dependencyIds,
				current.triggerHooks,
				current.triggerSources,
				{
					syncModal: true
				}
			);
		}
		linkedActionsView = 'graph';
		showMappingConfigModal = false;
	}

	function toggleDraftHook(hook: string) {
		const next = new Set(draftHooks);
		next.has(hook) ? next.delete(hook) : next.add(hook);
		draftHooks = next;
		const nextHooks = normalizeHookIds(next);
		const nextSources = deriveTriggerSourcesForDraft(
			nextHooks,
			normalizeDependencyIds(draftSettings.dependency_ids),
			normalizeDraftTriggerSources(draftSettings.trigger_sources, nextHooks)
		);
		draftSettings = {
			...draftSettings,
			trigger_sources: nextSources,
			dependency_ids: deriveDependencyIdsForDraft(nextSources)
		};
	}

	function toggleDraftDependency(mappingId: string) {
		if (!editingLinkageId || editingLinkageId === mappingId) {
			return;
		}

		const currentDraft = readEffectiveDraftForMapping(editingLinkageId);
		const current = currentDraft.dependencyIds;
		const next = current.includes(mappingId)
			? current.filter((id) => id !== mappingId)
			: [...current, mappingId];
		applyGraphDraftMutation(
			editingLinkageId,
			next,
			currentDraft.triggerHooks,
			currentDraft.triggerSources,
			{
				syncModal: true
			}
		);
	}

	function setGraphEditingMapping(mappingId: string | null) {
		editingLinkageId = mappingId;
		if (!mappingId) return;
		if (showMappingConfigModal && editingLinkageId === mappingId) {
			const current = readEffectiveDraftForMapping(mappingId);
			draftHooks = new Set(current.triggerHooks);
			draftSettings = {
				...draftSettings,
				dependency_ids: current.dependencyIds,
				trigger_sources: current.triggerSources
			};
		}
	}

	function connectGraphDependency(
		sourceMappingId: string,
		targetMappingId: string,
		hook: string | null
	) {
		if (!sourceMappingId || !targetMappingId || sourceMappingId === targetMappingId) return;
		if (!hook) {
			notifications.warning(
				'Select a hook tab or connect into a hook-specific left handle to set dependency order.'
			);
			return;
		}
		const current = readEffectiveDraftForMapping(targetMappingId);
		const nextHooks = normalizeHookIds([...current.triggerHooks, hook]);
		const nextSources: DraftTriggerSourceRecord = {
			...current.triggerSources,
			[hook]: {
				type: 'mapping',
				mapping_id: sourceMappingId
			}
		};

		const applied = applyGraphDraftMutation(
			targetMappingId,
			deriveDependencyIdsForDraft(nextSources),
			nextHooks,
			nextSources,
			{
				syncModal: true
			}
		);
		if (applied) {
			editingLinkageId = targetMappingId;
			clearRootAttachUndoState();
		}
	}

	function disconnectGraphDependency(
		sourceMappingId: string,
		targetMappingId: string,
		hook: string | null
	) {
		if (!sourceMappingId || !targetMappingId || sourceMappingId === targetMappingId) return;
		const current = readEffectiveDraftForMapping(targetMappingId);
		const sourceHookRoot = sourceMappingId.startsWith('__hook_root__:')
			? sourceMappingId.slice('__hook_root__:'.length)
			: null;
		const targetHooks = hook
			? [hook]
			: sourceHookRoot
				? current.triggerHooks.filter((hookKey) => hookKey === sourceHookRoot)
				: current.triggerHooks.filter(
						(hookKey) => current.triggerSources[hookKey]?.mapping_id === sourceMappingId
					);
		if (targetHooks.length === 0) {
			editingLinkageId = targetMappingId;
			return;
		}

		const nextSources: DraftTriggerSourceRecord = {
			...current.triggerSources
		};
		for (const hookKey of targetHooks) {
			nextSources[hookKey] = { type: 'unbound' };
		}
		const applied = applyGraphDraftMutation(
			targetMappingId,
			deriveDependencyIdsForDraft(nextSources),
			current.triggerHooks,
			nextSources,
			{
				syncModal: true,
				allowUnboundIssues: true
			}
		);
		if (applied) {
			editingLinkageId = targetMappingId;
		}
	}

	async function duplicateGraphMapping(
		linkage: FormActionLinkage,
		parent: DuplicateParentSelection
	) {
		if (!linkage?.local_mapping_id) return;

		if (hasGraphUnsavedChanges) {
			notifications.warning(
				'Save or cancel dependency drafts before duplicating a mapping so insertion runs from a consistent graph state.'
			);
			return;
		}

		try {
			duplicatingMappingId = linkage.local_mapping_id;
			const client = createClientFromConfig();
			const result = await client.duplicateFormAction(
				data.formSourceSlug,
				data.formId,
				linkage.local_mapping_id,
				{ parent },
				{ showNotifications: false }
			);

			await formActionsStore.load(data.formSourceSlug, data.formId);
			graphDraftByMappingId = {};
			selectedCreateDependencyIds = new Set();
			clearRootAttachUndoState();
			pendingRemovalId = null;
			linkedActionsView = 'graph';
			editingLinkageId = result.duplicate.local_mapping_id;

			const movedCount = result.insertion?.moved_children?.length ?? 0;
			const skippedCount = result.insertion?.skipped_children?.length ?? 0;

			if (movedCount > 0) {
				notifications.success(
					`Duplicate inserted. Rewired ${movedCount} downstream mapping${movedCount === 1 ? '' : 's'}.`
				);
			} else {
				notifications.success('Duplicate inserted.');
			}

			if (skippedCount > 0) {
				const skippedPreview = result.insertion.skipped_children
					.slice(0, 2)
					.map((child) => `${child.child_id} (${child.code})`)
					.join(', ');
				notifications.warning(
					`Skipped ${skippedCount} downstream mapping${skippedCount === 1 ? '' : 's'} due to policy constraints${skippedPreview ? `: ${skippedPreview}` : ''}.`
				);
			}
			for (const warning of result.insertion?.warnings ?? []) {
				if (warning) notifications.warning(warning);
			}

			await loadWorkflowPlan(workflowPlanScope);
		} catch (error) {
			const message =
				error instanceof Error
					? error.message
					: 'Failed to duplicate mapping from dependency graph.';
			notifications.error(message);
		} finally {
			duplicatingMappingId = null;
		}
	}

	async function saveDependenciesFromGraph() {
		const pendingDraftMap = { ...graphDraftByMappingId };
		if (editingLinkageId && hasUnsavedMappingChanges) {
			const modalDraft = readEffectiveDraftForMapping(editingLinkageId);
			if (
				hasDraftDifference(
					editingLinkageId,
					modalDraft.dependencyIds,
					modalDraft.triggerHooks,
					modalDraft.triggerSources
				)
			) {
				pendingDraftMap[editingLinkageId] = {
					dependencyIds: modalDraft.dependencyIds,
					triggerHooks: modalDraft.triggerHooks,
					triggerSources: modalDraft.triggerSources
				};
			}
		}

		const draftEntries = Object.entries(pendingDraftMap);
		if (draftEntries.length === 0) {
			notifications.info('No dependency graph changes to save.');
			return;
		}
		const candidateItems = buildLinkagesFromDraftMap(pendingDraftMap);
		const candidateIssues = validateMappingDependencies(candidateItems);
		const unboundIssues = candidateIssues.filter((issue) => issue.code === 'unbound_trigger');
		if (unboundIssues.length > 0) {
			notifications.error(
				unboundIssues.length === 1
					? formatDependencyIssues(unboundIssues)[0]
					: `${unboundIssues.length} mappings are missing a trigger source. Connect each invalid node before saving.`
			);
			return;
		}
		const introducedIssues = findIntroducedDependencyIssues(
			validateMappingDependencies(actionsState.items),
			candidateIssues
		);
		if (introducedIssues.length > 0) {
			notifications.error(formatDependencyIssues(introducedIssues)[0]);
			return;
		}

		try {
			savingDependencies = true;
			const client = createClientFromConfig();
			let savedCount = 0;
			for (const [mappingId, draft] of draftEntries) {
				const linkage = getLinkageById(mappingId);
				if (!linkage) continue;

				const nextSettings: Record<string, unknown> = {
					...(linkage.settings ?? {})
				};
				if (draft.dependencyIds.length > 0) {
					nextSettings.dependency_ids = draft.dependencyIds;
				} else {
					delete nextSettings.dependency_ids;
				}
				nextSettings.trigger_sources = draft.triggerSources;

				const payload: Partial<FormActionMutationPayload> = {
					settings: nextSettings as FormActionMutationPayload['settings']
				};
				const baseHooks = normalizeHookIds(getMappingTriggerHooks(linkage));
				if (
					draft.triggerHooks.length !== baseHooks.length ||
					draft.triggerHooks.some((hook, index) => hook !== baseHooks[index])
				) {
					payload.trigger_hooks = draft.triggerHooks;
				}

				await client.updateFormAction(data.formSourceSlug, data.formId, mappingId, payload, {
					showNotifications: false
				});
				savedCount += 1;
			}

			await formActionsStore.load(data.formSourceSlug, data.formId);
			graphDraftByMappingId = {};
			selectedCreateDependencyIds = new Set();
			if (editingLinkageId) {
				const refreshed = getLinkageById(editingLinkageId);
				if (refreshed) {
					const refreshedHooks = normalizeHookIds(refreshed.trigger_hooks ?? []);
					const refreshedDraft = readEffectiveDraftForMapping(editingLinkageId);
					draftHooks = new Set(refreshedHooks);
					draftSettings = {
						...draftSettings,
						dependency_ids: refreshedDraft.dependencyIds,
						trigger_sources: refreshedDraft.triggerSources
					};
					editBaselineSignature = createDraftSignature(refreshedHooks, {
						...draftSettings,
						dependency_ids: refreshedDraft.dependencyIds,
						trigger_sources: refreshedDraft.triggerSources
					});
				}
			}
			if (savedCount > 0) {
				notifications.success(
					savedCount === 1
						? 'Dependency mapping saved.'
						: `Saved dependency updates for ${savedCount} mappings.`
				);
			}
			clearRootAttachUndoState();
			await loadWorkflowPlan(workflowPlanScope);
		} finally {
			savingDependencies = false;
		}
	}

	async function saveActionChanges(linkage: FormActionLinkage) {
		const normalizedHooks = normalizeHookIds(draftHooks);
		if (normalizedHooks.length === 0) {
			notifications.error('Select at least one trigger hook.');
			return;
		}

		// Ensure types are correct for spam settings
		if (linkage.central_action_id === 'spam_detection_v1') {
			if (draftSettings.spam_confidence_threshold) {
				draftSettings.spam_confidence_threshold = parseFloat(
					String(draftSettings.spam_confidence_threshold)
				);
			}
		}

		const conditionErrors = validateConditionConfig(
			draftSettings.conditions ?? createDefaultConditionConfig()
		);
		if (conditionErrors.length > 0) {
			notifications.error(conditionErrors[0]);
			return;
		}

		const normalizedTriggerSources = deriveTriggerSourcesForDraft(
			normalizedHooks,
			normalizeDependencyIds(draftSettings.dependency_ids),
			normalizeDraftTriggerSources(draftSettings.trigger_sources, normalizedHooks)
		);
		const unboundHooks = normalizedHooks.filter(
			(hook) => normalizedTriggerSources[hook]?.type === 'unbound'
		);
		if (unboundHooks.length > 0) {
			notifications.error(
				`Missing trigger source for ${unboundHooks.join(', ')}. Connect an upstream mapping or hook root before saving.`
			);
			return;
		}
		const normalizedDependencyIds = deriveDependencyIdsForDraft(normalizedTriggerSources);
		const persistableTriggerSources: Record<
			string,
			{ type: 'hook_root' | 'mapping'; mapping_id?: string }
		> = Object.fromEntries(
			Object.entries(normalizedTriggerSources).map(([hook, source]) => [
				hook,
				source.type === 'mapping'
					? { type: 'mapping' as const, mapping_id: source.mapping_id }
					: { type: 'hook_root' as const }
			])
		);
		const nextSettings: Record<string, unknown> = {
			...draftSettings,
			dependency_ids: normalizedDependencyIds,
			trigger_sources: persistableTriggerSources
		};
		if (normalizedDependencyIds.length === 0) {
			delete nextSettings.dependency_ids;
		}
		if (!canSkipOnUpstreamSpam || draftSettings.skip_on_upstream_spam !== true) {
			delete nextSettings.skip_on_upstream_spam;
		}

		const updatedLinkage: FormActionLinkage = {
			...linkage,
			trigger_hooks: normalizedHooks,
			settings: nextSettings as FormActionLinkage['settings']
		};
		const candidateItems = actionsState.items.map((item) =>
			item.local_mapping_id === linkage.local_mapping_id ? updatedLinkage : item
		);
		const introducedIssues = findIntroducedDependencyIssues(
			validateMappingDependencies(actionsState.items),
			validateMappingDependencies(candidateItems)
		);
		if (introducedIssues.length > 0) {
			notifications.error(formatDependencyIssues(introducedIssues)[0]);
			return;
		}

		await formActionsStore.updateAction(data.formSourceSlug, data.formId, linkage, {
			trigger_hooks: normalizedHooks,
			settings: nextSettings as FormActionMutationPayload['settings']
		});
		if (graphDraftByMappingId[linkage.local_mapping_id]) {
			const nextDraftMap = { ...graphDraftByMappingId };
			delete nextDraftMap[linkage.local_mapping_id];
			graphDraftByMappingId = nextDraftMap;
		}
		await loadWorkflowPlan(workflowPlanScope);
		editingLinkageId = null;
		showMappingConfigModal = false;
		draftHooks = new Set();
		draftSettings = {};
		resetMappingSectionExpansion(null);
		editBaselineSignature = null;
	}

	async function handleCreate(event?: Event) {
		if (event?.preventDefault) {
			try {
				event.preventDefault();
			} catch (err) {
				console.warn('handleCreate preventDefault failed', err);
			}
		}
		createError = null;

		const hooks = Array.from(selectedHooks).filter(Boolean);
		if (hooks.length === 0) {
			createError = 'Select at least one trigger hook.';
			return;
		}
		const dependencyIds = normalizeDependencyIds(Array.from(selectedCreateDependencyIds));
		const primaryDependencyId = dependencyIds[0] ?? null;
		if (dependencyIds.length > 0) {
			const requiredHooks = normalizeHookIds(selectedHooks);
			const invalid = dependencyIds.filter((dependencyId) => {
				const linkage = getLinkageById(dependencyId);
				if (!linkage || linkage.is_action_enabled_for_form === false) {
					return true;
				}
				const dependencyHooks = normalizeHookIds(getMappingTriggerHooks(linkage));
				return !dependencySupportsSelectedHooks(dependencyHooks, requiredHooks);
			});
			if (invalid.length > 0) {
				createError =
					'Some selected dependencies are not compatible with the chosen trigger hooks. Adjust hooks or dependency selection.';
				return;
			}
		}

		const chosenDefinition =
			createKind === 'template'
				? (selectedDefinition ?? definitions.find((def) => def.id === selectedTemplateId) ?? null)
				: null;
		const chosenCustom =
			createKind === 'custom'
				? (selectedCustomAction ??
					customActions.find((action) => action.id === selectedCustomId) ??
					null)
				: null;

		if (createKind === 'template' && !chosenDefinition) {
			createError = 'Select a CPS template to link.';
			return;
		}

		if (createKind === 'custom' && !chosenCustom) {
			createError = 'Select a custom action to link.';
			return;
		}

		try {
			creating = true;
			const centralActionId =
				createKind === 'template'
					? (chosenDefinition?.id ?? selectedTemplateId)
					: chosenCustom?.code;
			if (!centralActionId) {
				createError = 'Select an action to link.';
				return;
			}
			const label =
				createKind === 'template'
					? (chosenDefinition?.label ?? centralActionId)
					: (chosenCustom?.display_name ?? chosenCustom?.code ?? centralActionId);
			const triggerSources: Record<string, { type: 'hook_root' | 'mapping'; mapping_id?: string }> =
				Object.fromEntries(
					hooks.map((hook) => [
						hook,
						primaryDependencyId
							? { type: 'mapping' as const, mapping_id: primaryDependencyId }
							: { type: 'hook_root' as const }
					])
				);
			const customPostExecutionActions =
				createKind === 'custom' ? getCustomActionPostExecutionActions(chosenCustom) : [];
			await formActionsStore.create(data.formSourceSlug, data.formId, {
				central_action_id: centralActionId,
				action_type_indicator: createKind === 'template' ? 'master' : 'custom',
				trigger_hooks: hooks,
				action_name_label: label,
				settings: {
					...(dependencyIds.length > 0 ? { dependency_ids: dependencyIds } : {}),
					trigger_sources: triggerSources,
					...(customPostExecutionActions.length > 0
						? { post_execution_actions: customPostExecutionActions }
						: {})
				}
			});
			pendingRemovalId = null;
			selectedCreateDependencyIds = new Set();
			showAddPanel = false;
		} catch (error) {
			createError = error instanceof Error ? error.message : 'Failed to create action mapping';
		} finally {
			creating = false;
		}
	}

	function requestRemove(linkage: FormActionLinkage) {
		pendingRemovalId = linkage.local_mapping_id;
	}

	function cancelRemove() {
		pendingRemovalId = null;
	}

	async function confirmRemove(linkage: FormActionLinkage) {
		await formActionsStore.remove(data.formSourceSlug, data.formId, linkage);
		pendingRemovalId = null;
	}

	async function toggleEnabled(linkage: FormActionLinkage) {
		if (isLinkageInvalid(linkage)) {
			notifications.warning(
				'Repair the missing upstream trigger source before changing this mapping state.'
			);
			return;
		}

		const enabled = linkage.is_action_enabled_for_form !== false;
		await formActionsStore.toggleEnabled(data.formSourceSlug, data.formId, linkage, !enabled);
	}

	function refresh() {
		formActionsStore.refresh(data.formSourceSlug, data.formId);
	}

	// Phase 7 CSM: Save current action config as a template
	let savingTemplate = $state(false);
	async function saveAsTemplate(linkage: FormActionLinkage) {
		if (!licenseState.siteId) {
			notifications.error('Site not activated. Please activate your license first.');
			return;
		}

		savingTemplate = true;
		try {
			const displayName = linkage.action_name_label ?? `Template from form ${data.formId}`;

			// Create a new template mapping in CPS
			// Note: CPS expects UUIDs for action_template_id field, but string codes for action_template_code.
			// For master templates (codes like 'spam_detection_v1'), we use action_template_code.
			const isMasterTemplate = linkage.action_type_indicator === 'master';

			// CSM-006: Extract portable field references from current form fields
			// This enables smart field re-mapping when importing template to different sites
			const inputMapping = linkage.settings?.input_mapping;
			let portableFields: Array<{ label: string; type: string }> = [];

			if (inputMapping?.mode === 'selected' && inputMapping.field_ids) {
				// Only include fields that were explicitly selected
				portableFields = formFields
					.filter((f) => inputMapping.field_ids!.includes(f.id))
					.map((f) => ({ label: f.label, type: f.type }));
			} else if (inputMapping?.mode !== 'exclude') {
				// Include all fields for 'all' mode or no mapping specified
				portableFields = formFields.map((f) => ({ label: f.label, type: f.type }));
			}

			const result = await formMappingsStore.createMapping({
				form_source: data.formSourceSlug,
				display_name: displayName,
				// UUID field - leave undefined for code-based templates
				action_template_id: undefined,
				// String code field for master templates like 'spam_detection_v1'
				action_template_code: isMasterTemplate ? linkage.central_action_id : undefined,
				custom_action_id:
					linkage.action_type_indicator === 'custom' ? linkage.central_action_id : undefined,
				is_template: true,
				settings: {
					trigger_hooks: getMappingTriggerHooks(linkage),
					portable_fields: portableFields, // CSM-006: field labels for cross-site portability
					...(linkage.settings ?? {})
				}
			});

			if (result) {
				notifications.success(`Saved "${displayName}" as template`);
			} else {
				notifications.error('Failed to save as template');
			}
		} catch (error) {
			console.error('Failed to save as template', error);
			notifications.error('Failed to save as template');
		} finally {
			savingTemplate = false;
		}
	}

	async function checkEntryStatus(event?: SubmitEvent | Event) {
		event?.preventDefault?.();
		const parsed = Number.parseInt(entryLookupId.trim(), 10);
		if (!entryLookupId.trim() || Number.isNaN(parsed) || parsed <= 0) {
			createError = 'Entry ID must be a positive number.';
			checkedEntryStatus = null;
			return;
		}
		createError = null;

		try {
			const status = await formActionsStore.fetchExecutionStatus(
				data.formSourceSlug,
				data.formId,
				parsed
			);
			checkedEntryStatus = status;

			if (status.status === 'error' && status.last_error) {
				notifications.error(status.last_error);
			} else if (status.last_response) {
				notifications.success('Sentient Forms processed the entry successfully.');
			} else {
				notifications.info('No Sentient Forms execution data found for this entry.');
			}
		} catch (error) {
			checkedEntryStatus = null;
			console.error(error);
		}
	}

	function performStatusAction(actionId: AdviceActionId) {
		if (actionId === 'refresh') {
			refresh();
			return;
		}
		if (actionId === 'licensing') {
			navigateToAppPath('/licensing');
		}
	}
</script>

<svelte:window onkeydown={handleWindowKeydown} />

<Section heading="Actions" description="Link CPS templates or custom actions to this form.">
	<!-- Form-Level Action Config Modal - Inside Section slot for Svelte 5 reactivity -->
	{#if configuringActionId}
		<div
			class="sf:fixed sf:inset-0 sf:z-50 sf:bg-black/40 sf:flex sf:items-center sf:justify-center sf:p-2 sf:sm:p-4"
			onclick={handleFormLevelDefaultsBackdropClick}
			onkeydown={(event) => {
				if (event.key === 'Escape') cancelFormLevelConfig();
			}}
			tabindex="-1"
			role="button"
			aria-label="Close form defaults modal"
		>
			<div
				data-testid="form-defaults-modal"
				class="sf:bg-white sf:rounded-lg sf:shadow-xl sf:max-w-2xl sf:w-[calc(100%-0.5rem)] sf:sm:w-full sf:max-h-[90vh] sf:overflow-y-auto"
				role="dialog"
				aria-modal="true"
				aria-labelledby="form-defaults-title"
				tabindex="-1"
			>
				<header
					class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:px-4 sf:sm:px-6 sf:py-4 sf:border-b sf:border-slate-200"
				>
					<div>
						<h2 id="form-defaults-title" class="sf:text-lg sf:font-semibold sf:text-slate-800">
							Form-Level Defaults
						</h2>
						<p class="sf:text-sm sf:text-slate-500">
							Configure defaults for <strong>{getActionDisplayName(configuringActionId)}</strong>
							on this form.
						</p>
					</div>
					<Button
						variant="ghost"
						size="sm"
						iconOnly
						class="sf:text-lg"
						onclick={cancelFormLevelConfig}
						aria-label="Close"
						data-testid="form-defaults-close"
					>
						×
					</Button>
				</header>

				<div class="sf:p-4 sf:sm:p-6 sf:space-y-6">
					{#if formLevelConfigLoading}
						<p class="sf:text-sm sf:text-slate-500">Loading configuration...</p>
					{:else}
						<Alert variant="info">
							<p class="sf:text-sm">
								These defaults apply to this form before any individual mapping override is applied.
							</p>
						</Alert>

						<div class="sf:space-y-3">
							<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-2 sf:sm:flex-row sf:sm:items-center">
								<div>
									<p class="sf:text-sm sf:font-medium sf:text-slate-800">Default model</p>
									<p class="sf:text-xs sf:text-slate-500">
										Set the default model for this action on this form.
									</p>
								</div>
								{#if formLevelConfig.model_selection}
									<Button size="sm" variant="ghost" onclick={clearFormLevelModelSelection}>
										Use global defaults
									</Button>
								{/if}
							</div>
							<ModelSelector
								level="form"
								value={formLevelConfig.model_selection ?? cloneDefaultModelSelection()}
								actionId={getActionDefinitionContext(configuringActionId).actionId}
								templateModelHint={getActionDefinitionContext(configuringActionId).modelHint}
								baseCreditCost={getActionDefinitionContext(configuringActionId).baseCreditCost}
								actionSelection={
									actionDefaultsByActionId[configuringActionId ?? '']?.model_selection ?? null
								}
								formSelection={formLevelConfig.model_selection ?? null}
								onchange={handleFormLevelModelSelectionChange}
							/>
						</div>

						{#if configuringActionId && isSpamActionCode(configuringActionId)}
							<SpamCriteriaEditor
								positiveExamples={formLevelConfig.spam_positive_examples ?? []}
								negativeExamples={formLevelConfig.spam_negative_examples ?? []}
								onchange={(data) => {
									formLevelConfig = {
										...formLevelConfig,
										spam_positive_examples: data.positive,
										spam_negative_examples: data.negative
									};
								}}
							/>

							<div class="sf:grid sf:gap-4 sf:md:grid-cols-2">
								<SelectField
									id="form-level-spam-notifications"
									label="Spam notification policy"
									description="Applies to Blocking spam mappings on this form. Background mappings still send notifications immediately."
									value={getInheritableBooleanMode(
										formLevelConfig.suppress_notifications_on_spam
									)}
									options={[
										{ value: 'inherit', label: 'Use global default' },
										{ value: 'enabled', label: 'Suppress notifications' },
										{ value: 'disabled', label: 'Allow notifications' }
									]}
									onchange={(event) =>
										handleFormLevelSpamPolicyChange(
											'suppress_notifications_on_spam',
											event.currentTarget.value
										)}
								/>
								<SelectField
									id="form-level-spam-downstream"
									label="Downstream spam gate"
									description="Controls whether downstream work should stop when this form’s spam mapping confirms spam."
									value={getInheritableBooleanMode(formLevelConfig.skip_downstream_on_spam)}
									options={[
										{ value: 'inherit', label: 'Use global default' },
										{ value: 'enabled', label: 'Skip downstream actions' },
										{ value: 'disabled', label: 'Allow downstream actions' }
									]}
									onchange={(event) =>
										handleFormLevelSpamPolicyChange(
											'skip_downstream_on_spam',
											event.currentTarget.value
										)}
								/>
							</div>
						{/if}

						<SelectField
							id="form-level-context"
							label="Include Site Context"
							bind:value={formLevelConfig.include_site_context}
							options={[
								{ value: 'global', label: 'Use global setting' },
								{ value: 'always', label: 'Always include' },
								{ value: 'never', label: 'Never include' }
							]}
						/>

						<p class="sf:text-xs sf:text-slate-500 sf:pt-2 sf:flex sf:flex-wrap sf:items-start sf:gap-1">
							<span class="sf:text-amber-500">⚠</span>
							Submission data is processed by AI.
							<a href="#/settings/context" class="sf:underline hover:sf:text-slate-700">
								Review Site Context settings
							</a>
							for PII handling options.
						</p>
					{/if}
				</div>

				<footer
					class="sf:flex sf:flex-wrap sf:justify-end sf:gap-2 sf:px-4 sf:sm:px-6 sf:py-4 sf:border-t sf:border-slate-200 sf:bg-slate-50"
				>
					<Button variant="secondary" onclick={cancelFormLevelConfig}>Cancel</Button>
					<Button
						onclick={saveFormLevelConfig}
						disabled={formLevelConfigSaving || formLevelConfigLoading}
					>
						{formLevelConfigSaving ? 'Saving...' : 'Save Defaults'}
					</Button>
				</footer>
			</div>
		</div>
	{/if}
	{#snippet actions()}
		<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
			<!-- CB-FORMS-001: Per-form master disable toggle -->
			<div
				class="sf:flex sf:items-center sf:gap-2 sf:mr-3 sf:pr-3 sf:border-r sf:border-slate-300"
				title={actionsState.effectiveDisabled
					? 'Sentient Forms execution is paused for this form'
					: 'Sentient Forms is active for this form'}
			>
				<span class="sf:text-xs sf:font-medium sf:text-slate-600"
					>{actionsState.effectiveDisabled ? 'Paused' : 'Active'}</span
				>
				{#if actionsState.globalDisabled}
					<Badge variant="warning">Global pause</Badge>
				{/if}
				{#if actionsState.providerDisabled}
					<Badge variant="warning">Provider pause</Badge>
				{/if}
				{#if actionsState.sfDisabled}
					<Badge variant="warning">Form pause</Badge>
				{/if}
				<Toggle
					checked={!actionsState.sfDisabled}
					onchange={() =>
						formActionsStore.toggleFormDisabled(
							data.formSourceSlug,
							data.formId,
							!actionsState.sfDisabled
						)}
				/>
			</div>
			<Button variant="secondary" onclick={() => navigateToAppPath('/actions')}>All forms</Button>
			{#if providerEditUrl}
				<a
					href={providerEditUrl}
					class="sf:inline-flex sf:h-10 sf:items-center sf:justify-center sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-4 sf:text-sm sf:font-medium sf:text-slate-700 hover:sf:bg-slate-50 hover:sf:text-slate-900"
					data-sveltekit-reload
					rel="external"
					data-testid="actions-provider-edit-link"
				>
					Open in Gravity Forms
				</a>
			{/if}
			<Button variant="secondary" onclick={refresh}>Refresh</Button>
			<Button onclick={openAddActionPanel}>Add action</Button>
			<Button variant="secondary" onclick={() => (showTemplateLibrary = true)}
				>Import from Library</Button
			>
			<Button variant="secondary" onclick={checkEntryStatus}>Check entry status</Button>
		</div>
	{/snippet}

	<!-- CB-FORMS-001: Warning banner when form is disabled -->
	{#if actionsState.effectiveDisabled}
		<Alert variant="warning">
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
				<div>
					<p class="sf:font-medium">⚠ Sentient Forms execution is paused for this form</p>
					<p class="sf:text-sm sf:mt-1">
						All runs are paused. Mapping and action edits remain available while paused.
					</p>
					<div class="sf:mt-2 sf:flex sf:flex-wrap sf:gap-2">
						{#if actionsState.globalDisabled}
							<Badge variant="warning">Global execution pause is enabled</Badge>
						{/if}
						{#if actionsState.providerDisabled}
							<Badge variant="warning">Provider execution pause is enabled</Badge>
						{/if}
						{#if actionsState.sfDisabled}
							<Badge variant="warning">This form is paused</Badge>
						{/if}
					</div>
				</div>
			</div>
		</Alert>
	{/if}

	<div class="sf:grid sf:gap-4 sf:xl:grid-cols-3">
		<Card class="sf:xl:col-span-2" data-testid="action-definitions-card">
			<div
				class="sf:flex sf:flex-col sf:gap-3 sf:md:flex-row sf:md:items-center sf:md:justify-between"
			>
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Action library</p>
					<p class="sf:text-xs sf:text-slate-500 sf:mt-1">
						Browse CPS templates and custom actions you can map to this form.
					</p>
				</div>
				<Badge variant={definitionsBadgeVariant}>{definitionsBadgeLabel}</Badge>
			</div>

			{#if !hasDefinitions}
				<Alert variant="warning" class="sf:mt-3">
					CPS templates are unavailable right now. You can still link custom actions below.
				</Alert>
			{:else if hasLocalDefinitions}
				<Alert variant="info" class="sf:mt-3">
					Some templates come from local extensions and may not exist in CPS. Confirm availability
					before linking.
				</Alert>
			{/if}

			<div class="sf:mt-4 sf:grid sf:gap-3 sf:lg:grid-cols-2">
				<Card class="sf:border-dashed">
					<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500 sf:mb-2">
						Built-in templates
					</p>
					{#if definitions.length === 0}
						<p class="sf:text-sm sf:text-slate-600">No templates available.</p>
					{:else}
						<ul class="sf:space-y-2">
							{#each definitions.slice(0, 5) as definition (definition.id)}
								<li class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div class="sf:flex-1">
										<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
											{definition.label ?? definition.id}
										</p>
										<p class="sf:text-xs sf:text-slate-500">
											Hooks: {summarizeDefinitionHooks(definition.hooks)}
										</p>
										<p class="sf:text-xs sf:text-slate-500">
											CPS base cost: {formatBaseCreditCost(definition)} · Model: {formatModelHint(
												definition
											)}
										</p>
									</div>
									<div class="sf:flex sf:items-center sf:gap-2">
										<Button
											size="sm"
											variant="ghost"
											onclick={() => loadFormLevelConfig(definition.id)}
											disabled={formLevelConfigLoading}
										>
											Defaults
										</Button>
										<Badge variant={definitionSourceBadgeVariant(definition)}>
											{definition.source === 'cps' ? 'CPS' : 'Local'}
										</Badge>
									</div>
								</li>
							{/each}
						</ul>
					{/if}
				</Card>

				<Card class="sf:border-dashed">
					<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
						<div>
							<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500 sf:mb-1">
								Custom actions
							</p>
							<p class="sf:text-sm sf:text-slate-700">
								{customActions.length > 0
									? `${customActions.length} active`
									: 'No active custom actions'}
							</p>
						</div>
						<Button
							size="sm"
							variant="secondary"
							onclick={() => navigateToAppPath('/actions/custom')}
						>
							Manage
						</Button>
					</div>
					{#if customActions.length > 0}
						<ul class="sf:mt-3 sf:space-y-2">
							{#each customActions.slice(0, 4) as action (action.id)}
								<li class="sf:flex sf:items-start sf:justify-between sf:gap-3">
									<div>
										<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
											{action.display_name}
										</p>
										<p class="sf:text-xs sf:text-slate-500">Code: {action.code}</p>
									</div>
									<div class="sf:flex sf:items-center sf:gap-2">
										<Button
											size="sm"
											variant="ghost"
											onclick={() => loadFormLevelConfig(action.code)}
											disabled={formLevelConfigLoading}
										>
											Defaults
										</Button>
										<Badge variant="success">Active</Badge>
									</div>
								</li>
							{/each}
						</ul>
					{/if}
				</Card>
			</div>

			<div class="sf:mt-6 sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Link actions to this form</p>
					<p class="sf:text-xs sf:text-slate-500">
						Choose a CPS template or custom action, then select hooks.
					</p>
				</div>
				<Button size="sm" onclick={openAddActionPanel}>Add action</Button>
			</div>
		</Card>

		<Card class="sf:space-y-3">
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">Execution status</p>
				{#if actionsState.status}
					<Badge variant={statusBadgeVariant(actionsState.status)}
						>{actionsState.status.status}</Badge
					>
				{/if}
			</div>
			{#if actionsState.supportsCredits === false}
				<Alert variant="warning">
					Credit balance is unavailable on this CPS backend
					{#if actionsState.cpsVersion}(current {actionsState.cpsVersion}){/if}
					{#if actionsState.requiredCreditsVersion}
						(Requires CPS ≥ {actionsState.requiredCreditsVersion})
					{/if}. Upgrade or enable credits support to see balance.
				</Alert>
			{:else if actionsState.balance}
				<p class="sf:text-sm sf:text-slate-600">
					Credit balance: <strong>{actionsState.balance.current_balance}</strong>
				</p>
			{/if}
			{#if actionsState.supportsStatus === false}
				<Alert variant="warning">
					Execution status is unavailable on this CPS backend
					{#if actionsState.cpsVersion}(current {actionsState.cpsVersion}){/if}
					{#if actionsState.requiredStatusVersion}
						(Requires CPS ≥ {actionsState.requiredStatusVersion})
					{/if}. Upgrade or enable the status endpoint to see run results.
				</Alert>
			{:else if actionsState.status}
				<p class="sf:text-sm sf:text-slate-700">{statusHeadline(actionsState.status)}</p>
				<p class="sf:text-sm sf:text-slate-600">{statusDescription(actionsState.status)}</p>
				{#if actionsState.status.last_error_code || actionsState.status.message}
					<p class="sf:text-xs sf:text-amber-700 sf:mt-1">
						{actionsState.status.last_error_code
							? `Last error: ${actionsState.status.last_error_code}`
							: ''}
						{actionsState.status.message ? ` ${actionsState.status.message}` : ''}
					</p>
				{/if}
				{#if actionsState.status.updated_at}
					<p class="sf:text-xs sf:text-slate-500">
						Updated {new Date(actionsState.status.updated_at).toLocaleString()}
					</p>
				{:else}
					<p class="sf:text-xs sf:text-slate-500">Last updated: not available</p>
				{/if}
				<div class="sf:flex sf:justify-end">
					<Button size="sm" variant="secondary" onclick={refresh}>Refresh now</Button>
				</div>
			{:else}
				<p class="sf:text-sm sf:text-slate-600">Status not loaded yet.</p>
			{/if}

			{#if statusAdvice}
				<Alert variant={statusAdvice.variant}>
					<div class="sf:flex sf:flex-col sf:gap-2">
						<p class="sf:font-medium">{statusAdvice.title}</p>
						<p>{statusAdvice.description}</p>
						{#if statusAdvice.actions && statusAdvice.actions.length > 0}
							<div class="sf:flex sf:flex-wrap sf:gap-2">
								{#each statusAdvice.actions as action (action.id)}
									<Button
										size="sm"
										variant={action.variant ?? 'secondary'}
										onclick={() => performStatusAction(action.id)}
									>
										{action.label}
									</Button>
								{/each}
							</div>
						{/if}
					</div>
				</Alert>
			{/if}

			<form class="sf:pt-2 sf:space-y-2" onsubmit={checkEntryStatus}>
				<InputField
					id="entry-id-input"
					label="Check entry status"
					placeholder="Enter entry ID"
					bind:value={entryLookupId}
				/>
				<div class="sf:flex sf:justify-end">
					<Button type="submit" variant="secondary" size="sm">Check</Button>
				</div>
			</form>

			{#if checkedEntryStatus}
				<div class="sf:mt-3 sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:space-y-1">
					<p class="sf:text-xs sf:font-semibold sf:text-slate-700">
						Entry {checkedEntryStatus.entry_id} status: {checkedEntryStatus.status}
					</p>
					{#if checkedEntryStatus.metering_summary}
						<p class="sf:text-xs sf:text-slate-600">
							Credits debited:
							<strong>{checkedEntryStatus.metering_summary.credits_debited ?? 'n/a'}</strong>
							{#if checkedEntryStatus.metering_summary.pricing_policy_version}
								· Policy: {checkedEntryStatus.metering_summary.pricing_policy_version}
							{/if}
						</p>
						{#if checkedEntryStatus.metering_summary.correlation_id}
							<p class="sf:text-xs sf:text-slate-600 sf:break-all">
								Correlation: {checkedEntryStatus.metering_summary.correlation_id}
							</p>
						{/if}
						{#if checkedEntryStatus.metering_summary.workflow}
							<details class="sf:pt-1">
								<summary class="sf:cursor-pointer sf:text-xs sf:font-medium sf:text-slate-700">
									Workflow metering breakdown
								</summary>
								<div class="sf:mt-1 sf:space-y-1">
									<p class="sf:text-xs sf:text-slate-600">
										Status: {checkedEntryStatus.metering_summary.workflow.status} · Credits total:
										{checkedEntryStatus.metering_summary.workflow.credits_total}
									</p>
									{#if Object.keys(checkedEntryStatus.metering_summary.workflow.credits_by_node).length > 0}
										<ul class="sf:text-xs sf:text-slate-600 sf:list-disc sf:pl-4">
											{#each Object.entries(checkedEntryStatus.metering_summary.workflow.credits_by_node) as [nodeId, nodeCredits] (nodeId)}
												<li>{nodeId}: {nodeCredits}</li>
											{/each}
										</ul>
									{/if}
									{#if checkedEntryStatus.metering_summary.workflow.failed_nodes.length > 0}
										<p class="sf:text-xs sf:text-amber-700">
											Failed nodes: {checkedEntryStatus.metering_summary.workflow.failed_nodes.join(', ')}
										</p>
									{/if}
								</div>
							</details>
						{/if}
					{:else}
						<p class="sf:text-xs sf:text-slate-500">No metering details were recorded for this entry.</p>
					{/if}
				</div>
			{/if}
		</Card>
	</div>

	{#if actionsState.error}
		<div class="sf:mt-4">
			<StateTemplate
				variant="error"
				title="Unable to load linked actions"
				message={actionsState.error}
				actionLabel="Retry"
				onAction={refresh}
				testId="form-actions-error-state"
			/>
		</div>
	{/if}

	<Card class="sf:mt-4">
		<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:mb-3">
			{#if linkedActionsView !== 'graph'}
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Linked actions</p>
					<p class="sf:text-xs sf:text-slate-500">
						Enable, disable, or retarget hooks for actions connected to this form.
					</p>
				</div>
			{/if}
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				{#if actionsState.items.length > 0}
					<Button
						variant={linkedActionsView === 'graph' ? 'primary' : 'secondary'}
						size="sm"
						onclick={() => {
							linkedActionsView = 'graph';
						}}
						data-testid="linked-actions-view-graph"
					>
						Graph
					</Button>
					<Button
						variant={linkedActionsView === 'table' ? 'primary' : 'secondary'}
						size="sm"
						onclick={() => {
							linkedActionsView = 'table';
						}}
						data-testid="linked-actions-view-table"
					>
						Table
					</Button>
				{/if}
				<Button variant="secondary" size="sm" onclick={refresh}>Refresh</Button>
			</div>
		</div>

			{#if actionsState.loading}
				<StateTemplate
					variant="loading"
					title="Loading action mappings"
					message="Fetching mappings linked to this form."
					inline
					testId="form-actions-loading-state"
				/>
			{:else if actionsState.items.length === 0}
				<StateTemplate
					variant="empty"
					title="No linked actions yet"
					message="Add an action mapping to run Sentient Forms logic for this form."
					actionLabel="Add action"
					onAction={openAddActionPanel}
					inline
					testId="form-actions-empty-state"
				/>
			{:else if linkedActionsView === 'graph'}
			<MappingDependencyGraph
				linkages={graphRenderLinkages}
				editingMappingId={editingLinkageId}
				draftDependencyIds={graphEditingDependencyIds}
				hookLabels={hookOptions}
				hasUnsavedChanges={hasGraphUnsavedChanges}
				{workflowPlan}
				{workflowPlanLoading}
				{workflowPlanError}
				formSourceSlug={data.formSourceSlug}
				formId={data.formId}
				{formFields}
				{pendingRemovalId}
				onSetEditingMapping={setGraphEditingMapping}
				onToggleDependency={toggleDraftDependency}
				onConnectDependency={connectGraphDependency}
				onDisconnectDependency={disconnectGraphDependency}
				onAttachRootDependency={attachMappingToHookRoot}
				onUndoLastRootAttach={undoLastRootAttach}
				canUndoRootAttach={Boolean(rootAttachUndo)}
				onClearDependencies={clearDraftDependencies}
				onDuplicateMapping={duplicateGraphMapping}
				{duplicatingMappingId}
				onSaveDependencies={saveDependenciesFromGraph}
				onOpenAddAction={openAddActionPanel}
				onCancelDependencyEdit={cancelEditingAction}
				{savingDependencies}
				onConfigureMapping={(linkage) => {
					startEditingAction(linkage);
				}}
				onToggleMappingEnabled={toggleEnabled}
				onRequestRemoveMapping={requestRemove}
				onConfirmRemoveMapping={confirmRemove}
				onCancelRemoveMapping={cancelRemove}
			/>
		{:else}
			<div class="sf:overflow-x-auto" data-testid="form-actions-table-scroll">
				<table
					class="sf:min-w-full sf:divide-y sf:divide-slate-200"
					data-testid="form-actions-table"
				>
					<thead class="sf:bg-slate-50">
						<tr
							class="sf:text-left sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600"
						>
							<th class="sf:px-4 sf:py-3">Action</th>
							<th class="sf:px-4 sf:py-3">Hooks</th>
							<th class="sf:px-4 sf:py-3">Type</th>
							<th class="sf:px-4 sf:py-3">Status</th>
							<th class="sf:px-4 sf:py-3 sf:text-right">Actions</th>
						</tr>
					</thead>
					<tbody class="sf:divide-y sf:divide-slate-200">
						{#each actionsState.items as linkage (linkage.local_mapping_id)}
							<tr class="sf:text-sm sf:text-slate-700">
								<td class="sf:px-4 sf:py-3 sf:font-medium">
									{friendlyActionLabel(linkage)}
									<p class="sf:text-xs sf:text-slate-500">
										ID: {linkage.central_action_id}
									</p>
								</td>
								<td class="sf:px-4 sf:py-3">
									<div class="sf:flex sf:flex-wrap sf:gap-2">
										{#if getMappingTriggerHooks(linkage).length > 0}
											{#each getMappingTriggerHooks(linkage) as hook (hook)}
												<Badge variant="info">{hookOptions[hook] ?? hook}</Badge>
											{/each}
										{:else}
											<span class="sf:text-xs sf:text-slate-500">No hooks configured</span>
										{/if}
									</div>
									<div class="sf:mt-2 sf:space-x-2">
										<Button size="sm" variant="ghost" onclick={() => startEditingAction(linkage)}>
											Configure
										</Button>
										<Button
											size="sm"
											variant="ghost"
											onclick={() => saveAsTemplate(linkage)}
											disabled={savingTemplate || !licenseState.siteId}
										>
											{savingTemplate ? 'Saving...' : 'Save as Template'}
										</Button>
									</div>
									{#if editingLinkageId === linkage.local_mapping_id}
										<p class="sf:mt-2 sf:text-xs sf:text-primary-700">
											Configuration is open in the modal editor.
										</p>
									{/if}
								</td>
								<td class="sf:px-4 sf:py-3">
									<Badge variant={linkage.action_type_indicator === 'custom' ? 'info' : 'neutral'}>
										{linkage.action_type_indicator === 'custom' ? 'Custom' : 'CPS template'}
									</Badge>
								</td>
								<td class="sf:px-4 sf:py-3">
									<Badge variant={statusVariant(linkage)}>{statusLabel(linkage)}</Badge>
									{#if isLinkageInvalid(linkage)}
										<p class="sf:mt-2 sf:text-xs sf:text-rose-700">
											Missing upstream source for {invalidHooksForLinkage(linkage).join(', ')}.
										</p>
									{/if}
								</td>
								<td class="sf:px-4 sf:py-3 sf:text-right sf:space-x-2">
									{#if isLinkageInvalid(linkage)}
										<Button size="sm" variant="secondary" onclick={() => startEditingAction(linkage)}>
											Repair
										</Button>
									{:else}
										<Button size="sm" variant="secondary" onclick={() => toggleEnabled(linkage)}>
											{linkage.is_action_enabled_for_form === false ? 'Enable' : 'Disable'}
										</Button>
									{/if}
									{#if pendingRemovalId === linkage.local_mapping_id}
										<Button size="sm" variant="danger" onclick={() => confirmRemove(linkage)}>
											Confirm
										</Button>
										<Button size="sm" variant="ghost" onclick={cancelRemove}>Cancel</Button>
									{:else}
										<Button size="sm" variant="ghost" onclick={() => requestRemove(linkage)}>
											Remove
										</Button>
									{/if}
								</td>
							</tr>
						{/each}
					</tbody>
				</table>
			</div>
		{/if}
	</Card>

		{#if showMappingConfigModal && editingLinkage}
			<div
				class="sf:fixed sf:inset-0 sf:z-40 sf:bg-black/45 sf:flex sf:items-center sf:justify-center sf:p-2 sf:sm:p-4"
				onclick={handleMappingConfigBackdropClick}
				onkeydown={(event) => {
					if (event.key === 'Escape') closeMappingConfigModal();
				}}
				data-testid="mapping-config-modal"
				tabindex="-1"
				role="button"
				aria-label="Close mapping configuration modal"
			>
				<div
					class="sf:bg-white sf:rounded-lg sf:shadow-xl sf:max-w-4xl sf:w-[calc(100%-0.5rem)] sf:sm:w-full sf:max-h-[90vh] sf:overflow-y-auto"
					role="dialog"
					aria-modal="true"
					aria-labelledby="mapping-config-title"
					tabindex="-1"
				>
					<header
						class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-4 sf:sm:flex-row sf:sm:items-center sf:px-4 sf:sm:px-6 sf:py-4 sf:border-b sf:border-slate-200"
					>
						<div>
							<p id="mapping-config-title" class="sf:text-base sf:font-semibold sf:text-slate-800">
								Configure Action Mapping
							</p>
							<p class="sf:text-sm sf:text-slate-500">
								{friendlyActionLabel(editingLinkage)} ({editingLinkage.local_mapping_id})
							</p>
						</div>
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<Button
							size="sm"
							variant="secondary"
							onclick={openGraphDependencyEditor}
							data-testid="mapping-config-open-graph"
						>
							Open graph editor
						</Button>
							<Button
								size="sm"
								variant="ghost"
								onclick={closeMappingConfigModal}
								data-testid="mapping-config-close-header"
							>
								Close
							</Button>
						</div>
					</header>
					{#if hasUnsavedMappingChanges}
						<div
							class="sf:sticky sf:top-0 sf:z-10 sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2 sf:border-b sf:border-amber-300 sf:bg-amber-50 sf:px-4 sf:sm:px-6 sf:py-2"
							data-testid="mapping-dirty-bar-modal"
						>
							<div class="sf:flex sf:items-center sf:gap-2">
								<Badge variant="warning">Unsaved changes</Badge>
								<p class="sf:text-xs sf:text-amber-800">
									Edits stay in local browser memory until you save.
								</p>
							</div>
							<p class="sf:text-xs sf:text-amber-800">
								Use <strong>Save mapping</strong> to persist or <strong>Discard draft</strong> to
								reset.
							</p>
						</div>
					{/if}

					<div class="sf:p-4 sf:sm:p-6 sf:space-y-4">
						<section class="sf:border sf:border-slate-200 sf:rounded-md">
							<button
								type="button"
								class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
								aria-expanded={mappingSectionExpansion.core}
								aria-controls="mapping-section-content-core"
								data-testid="mapping-section-toggle-core"
								onclick={() => toggleMappingSection('core')}
							>
								<span class="sf:flex sf:flex-col">
									<span class="sf:text-sm sf:font-semibold sf:text-slate-800">Core mapping</span>
									<span class="sf:text-xs sf:text-slate-500">{coreSectionSummary}</span>
								</span>
								<span class="sf:text-xs sf:text-slate-500">
									{mappingSectionExpansion.core ? 'Hide' : 'Show'}
								</span>
							</button>
							<div
								id="mapping-section-content-core"
								class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4 sf:space-y-4"
								hidden={!mappingSectionExpansion.core}
							>
								{#if mappingSectionExpansion.core}
									<div>
										<p
											class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500 sf:mb-2"
										>
											Triggers
										</p>
										<div class="sf:grid sf:gap-2 sf:sm:grid-cols-2">
											{#each hookEntries as [hookKey, hookLabel] (hookKey)}
												<label class="sf:flex sf:items-center sf:gap-2 sf:text-sm">
													<input
														type="checkbox"
														class="sf:form-checkbox sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
														checked={draftHooks.has(hookKey)}
														onchange={() => toggleDraftHook(hookKey)}
														data-testid={`mapping-trigger-hook-${hookKey}`}
													/>
													<span>{hookLabel}</span>
												</label>
											{/each}
										</div>
										<div class="sf:mt-2 sf:text-xs sf:text-slate-500 sf:space-y-1">
											<p>
												Triggers can come from hook roots (autonomous) or mapped actions
												(dependency). Use the graph editor for per-hook trigger source wiring.
											</p>
											<p>
												<strong>Blocking:</strong> AI runs while the submission is still in the
												request path. Use this when the result must be known before the form flow
												continues.
											</p>
											<p>
												<strong>Background:</strong> AI runs after the form has been accepted, so
												the submission flow is not held open.
											</p>
										</div>
									</div>

									<div class="sf:border-t sf:border-slate-200 sf:pt-4">
										<p
											class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500 sf:mb-2"
										>
											Upstream Dependencies
										</p>
										<div
											class="sf:flex sf:flex-col sf:md:flex-row sf:md:items-center sf:md:justify-between sf:gap-2"
										>
											<p class="sf:text-xs sf:text-slate-500">
												This mapping runs after all selected dependencies succeed.
											</p>
											<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
												<Button size="sm" variant="ghost" onclick={openGraphDependencyEditor}>
													Edit on graph
												</Button>
												<Button
													size="sm"
													variant="secondary"
													onclick={clearDraftDependencies}
													disabled={draftDependencyIds.length === 0}
													data-testid="mapping-config-make-autonomous"
												>
													Make autonomous
												</Button>
											</div>
										</div>
										{#if draftDependencyIds.length > 0}
											<div class="sf:mt-2 sf:flex sf:flex-wrap sf:gap-2">
												{#each draftDependencyIds as dependencyId (dependencyId)}
													<Badge variant="info">{dependencyBadgeLabel(dependencyId)}</Badge>
												{/each}
											</div>
										{:else}
											<p class="sf:mt-2 sf:text-xs sf:text-slate-500">
												No dependencies configured. This action is autonomous.
											</p>
										{/if}

										{#if canSkipOnUpstreamSpam || draftSettings.skip_on_upstream_spam === true}
											<div class="sf:mt-4 sf:border-t sf:border-slate-200 sf:pt-4">
												<Alert variant="info">
													<p class="sf:text-sm">
														Spam-aware downstream gating now belongs on the upstream spam action.
														Use that spam mapping’s advanced settings to decide whether downstream
														work should stop after a spam classification.
													</p>
												</Alert>
											</div>
										{/if}
									</div>
								{/if}
							</div>
						</section>

						{#if isSpamMapping}
							<section class="sf:border sf:border-slate-200 sf:rounded-md">
								<button
									type="button"
									class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
									aria-expanded={mappingSectionExpansion.guidance}
									aria-controls="mapping-section-content-guidance"
									data-testid="mapping-section-toggle-guidance"
									onclick={() => toggleMappingSection('guidance')}
								>
									<span class="sf:flex sf:flex-col">
										<span class="sf:text-sm sf:font-semibold sf:text-slate-800">
											Classification guidance
										</span>
										<span class="sf:text-xs sf:text-slate-500">{guidanceSummary}</span>
									</span>
									<span class="sf:text-xs sf:text-slate-500">
										{mappingSectionExpansion.guidance ? 'Hide' : 'Show'}
									</span>
								</button>
								<div
									id="mapping-section-content-guidance"
									class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4 sf:space-y-3"
									hidden={!mappingSectionExpansion.guidance}
								>
									{#if mappingSectionExpansion.guidance}
										<Alert variant="info">
											<p class="sf:text-sm">
												Use examples to teach the classifier what counts as legitimate or spam for
												this form.
											</p>
										</Alert>
										<SpamCriteriaEditor
											positiveExamples={draftSettings.spam_positive_examples ?? []}
											negativeExamples={draftSettings.spam_negative_examples ?? []}
											inheritedPositive={currentFormActionConfig.spam_positive_examples ?? []}
											inheritedNegative={currentFormActionConfig.spam_negative_examples ?? []}
											inheritanceSource={currentFormActionConfig.spam_positive_examples?.length > 0 ||
											currentFormActionConfig.spam_negative_examples?.length > 0
												? 'form'
												: null}
											onchange={(details) => {
												draftSettings = {
													...draftSettings,
													spam_positive_examples: details.positive,
													spam_negative_examples: details.negative
												};
											}}
										/>
										<p class="sf:text-xs sf:text-slate-500 sf:flex sf:items-center sf:gap-1">
											<span class="sf:text-amber-500">⚠</span>
											Submission data is processed by AI.
											<a href="#/settings/context" class="sf:underline hover:sf:text-slate-700">
												Review Site Context settings
											</a>
											for PII handling options.
										</p>
										<div class="sf:pt-2 sf:border-t sf:border-slate-100">
											<Button
												size="sm"
												variant="secondary"
												onclick={() => loadFormLevelConfig(editingLinkage.central_action_id)}
											>
												Edit Form Defaults
											</Button>
											<p class="sf:text-xs sf:text-slate-500 sf:mt-1">
												Set default classification guidance for this action on this form.
											</p>
										</div>
									{/if}
								</div>
							</section>

							<section class="sf:border sf:border-slate-200 sf:rounded-md">
								<button
									type="button"
									class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
									aria-expanded={mappingSectionExpansion.spam_advanced}
									aria-controls="mapping-section-content-spam-advanced"
									data-testid="mapping-section-toggle-spam_advanced"
									onclick={() => toggleMappingSection('spam_advanced')}
								>
									<span class="sf:flex sf:flex-col">
										<span class="sf:text-sm sf:font-semibold sf:text-slate-800">
											Spam advanced settings
										</span>
										<span class="sf:text-xs sf:text-slate-500">{spamAdvancedSummary}</span>
									</span>
									<span class="sf:text-xs sf:text-slate-500">
										{mappingSectionExpansion.spam_advanced ? 'Hide' : 'Show'}
									</span>
								</button>
								<div
									id="mapping-section-content-spam-advanced"
									class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4"
									hidden={!mappingSectionExpansion.spam_advanced}
								>
									{#if mappingSectionExpansion.spam_advanced}
										<div class="sf:grid sf:gap-4">
											<InputField
												id="spam-threshold"
												label="Confidence Threshold (0.0 - 1.0)"
												type="number"
												step="0.05"
												min="0"
												max="1"
												bind:value={draftSettings.spam_confidence_threshold}
												placeholder="0.80"
											/>
											<SelectField
												id="spam-display"
												label="Indicators Display"
												bind:value={draftSettings.spam_indicators_display}
												options={[
													{ value: 'simple', label: 'Simple (Summary only)' },
													{ value: 'detailed', label: 'Detailed (List signals)' }
												]}
											/>
											<SelectField
												id="spam-context"
												label="Include Site Context"
												bind:value={draftSettings.include_site_context}
												options={[
													{ value: 'global', label: 'Use global setting' },
													{ value: 'always', label: 'Always include' },
													{ value: 'never', label: 'Never include' }
												]}
											/>
											<SelectField
												id="spam-notification-policy"
												label="Notification policy on spam"
												description={isBlockingSpamMapping
													? `Current effective value: ${effectiveSuppressNotificationsOnSpam ? 'Suppress notifications' : 'Allow notifications'} (${effectiveSuppressNotificationsOnSpamSource}).`
													: `Background spam mappings do not hold notifications. Current inherited value remains ${effectiveSuppressNotificationsOnSpam ? 'suppress' : 'allow'} but is inactive while this mapping runs in Background mode.`}
												value={getInheritableBooleanMode(
													draftSettings.suppress_notifications_on_spam
												)}
												options={[
													{ value: 'inherit', label: 'Use inherited policy' },
													{ value: 'enabled', label: 'Suppress notifications' },
													{ value: 'disabled', label: 'Allow notifications' }
												]}
												disabled={!isBlockingSpamMapping}
												onchange={(event) =>
													handleMappingSpamPolicyChange(
														'suppress_notifications_on_spam',
														event.currentTarget.value
													)}
											/>
											<SelectField
												id="spam-downstream-policy"
												label="Downstream spam gate"
												description={`Current effective value: ${effectiveSkipDownstreamOnSpam ? 'Skip downstream actions' : 'Allow downstream actions'} (${effectiveSkipDownstreamOnSpamSource}).`}
												value={getInheritableBooleanMode(
													draftSettings.skip_downstream_on_spam
												)}
												options={[
													{ value: 'inherit', label: 'Use inherited policy' },
													{ value: 'enabled', label: 'Skip downstream actions' },
													{ value: 'disabled', label: 'Allow downstream actions' }
												]}
												onchange={(event) =>
													handleMappingSpamPolicyChange(
														'skip_downstream_on_spam',
														event.currentTarget.value
													)}
											/>
										</div>
									{/if}
								</div>
							</section>
						{/if}

						<section class="sf:border sf:border-slate-200 sf:rounded-md">
							<button
								type="button"
								class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
								aria-expanded={mappingSectionExpansion.input_mapping}
								aria-controls="mapping-section-content-input-mapping"
								data-testid="mapping-section-toggle-input_mapping"
								onclick={() => toggleMappingSection('input_mapping')}
							>
								<span class="sf:flex sf:flex-col">
									<span class="sf:text-sm sf:font-semibold sf:text-slate-800">Input mapping</span>
									<span class="sf:text-xs sf:text-slate-500">{inputMappingSummary}</span>
								</span>
								<span class="sf:text-xs sf:text-slate-500">
									{mappingSectionExpansion.input_mapping ? 'Hide' : 'Show'}
								</span>
							</button>
							<div
								id="mapping-section-content-input-mapping"
								class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4"
								hidden={!mappingSectionExpansion.input_mapping}
							>
								{#if mappingSectionExpansion.input_mapping}
									<FieldSelector
										fields={formFields}
										value={draftSettings.input_mapping ?? {
											mode: 'selected',
											include_metadata: false
										}}
										onchange={(mapping) => {
											draftSettings = { ...draftSettings, input_mapping: mapping };
										}}
									/>
								{/if}
							</div>
						</section>

						<section class="sf:border sf:border-slate-200 sf:rounded-md">
							<button
								type="button"
								class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
								aria-expanded={mappingSectionExpansion.attachment_mapping}
								aria-controls="mapping-section-content-attachment-mapping"
								data-testid="mapping-section-toggle-attachment_mapping"
								onclick={() => toggleMappingSection('attachment_mapping')}
							>
								<span class="sf:flex sf:flex-col">
									<span class="sf:text-sm sf:font-semibold sf:text-slate-800"
										>Attachment mapping</span
									>
									<span class="sf:text-xs sf:text-slate-500">{attachmentMappingSummary}</span>
								</span>
								<span class="sf:text-xs sf:text-slate-500">
									{mappingSectionExpansion.attachment_mapping ? 'Hide' : 'Show'}
								</span>
							</button>
							<div
								id="mapping-section-content-attachment-mapping"
								class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4"
								hidden={!mappingSectionExpansion.attachment_mapping}
							>
								{#if mappingSectionExpansion.attachment_mapping}
									<div class="sf:grid sf:gap-4">
										<label class="sf:flex sf:flex-col sf:gap-1">
											<span class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
												>Source mode</span
											>
											<select
												class="sf:px-3 sf:py-2 sf:text-sm sf:border sf:border-slate-300 sf:rounded sf:bg-white sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												value={normalizeAttachmentMapping(draftSettings.attachment_mapping).mode}
												onchange={(event) =>
													updateAttachmentMapping({
														mode: (event.currentTarget as HTMLSelectElement)
															.value as AttachmentMapping['mode']
													})}
											>
												<option value="none">Disabled</option>
												<option value="gf_upload">Gravity Forms uploads</option>
												<option value="media_library">Media library</option>
												<option value="mixed">Mixed (uploads + media)</option>
											</select>
										</label>

										{#if ['gf_upload', 'mixed'].includes(normalizeAttachmentMapping(draftSettings.attachment_mapping).mode)}
											<div class="sf:grid sf:gap-2">
												<span class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
													>Upload fields</span
												>
												{#if attachmentUploadFields.length === 0}
													<p class="sf:text-sm sf:text-slate-500 sf:italic">
														No upload fields found on this form.
													</p>
												{:else}
													<div class="sf:grid sf:gap-2">
														{#each attachmentUploadFields as field (field.id)}
															<label
																class="sf:flex sf:items-center sf:gap-2 sf:p-2 sf:rounded sf:border sf:border-slate-100 hover:sf:bg-slate-50 sf:cursor-pointer"
															>
																<input
																	type="checkbox"
																	class="sf:w-4 sf:h-4 sf:text-primary-600 sf:rounded sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
																	checked={normalizeAttachmentMapping(draftSettings.attachment_mapping).gf_upload_field_ids?.includes(field.id)}
																	onchange={() => toggleAttachmentUploadField(field.id)}
																/>
																<span class="sf:text-sm sf:text-slate-700"
																	>{field.adminLabel || field.label} ({field.id})</span
																>
															</label>
														{/each}
													</div>
												{/if}
											</div>
										{/if}

										{#if ['media_library', 'mixed'].includes(normalizeAttachmentMapping(draftSettings.attachment_mapping).mode)}
											<label class="sf:flex sf:flex-col sf:gap-1">
												<span class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
													>Media IDs</span
												>
												<input
													type="text"
													class="sf:px-3 sf:py-2 sf:text-sm sf:border sf:border-slate-300 sf:rounded sf:bg-white sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
													placeholder="12, 45, 98"
													value={(normalizeAttachmentMapping(draftSettings.attachment_mapping).media_ids ?? []).join(', ')}
													oninput={(event) =>
														updateAttachmentMapping({
															media_ids: parseMediaIdsInput(
																(event.currentTarget as HTMLInputElement).value
															)
														})}
												/>
												<span class="sf:text-xs sf:text-slate-500">
													Enter comma-separated WordPress media attachment IDs.
												</span>
											</label>
										{/if}

										<label class="sf:flex sf:flex-col sf:gap-1">
											<span class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
												>Max files per run</span
											>
											<input
												type="number"
												min="1"
												max="20"
												class="sf:px-3 sf:py-2 sf:text-sm sf:border sf:border-slate-300 sf:rounded sf:bg-white sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												value={normalizeAttachmentMapping(draftSettings.attachment_mapping).max_files ?? 5}
												oninput={(event) =>
													updateAttachmentMapping({
														max_files: Math.max(
															1,
															Math.min(
																20,
																Number.parseInt(
																	(event.currentTarget as HTMLInputElement).value || '5',
																	10
																)
															)
														)
													})}
											/>
										</label>
									</div>
								{/if}
							</div>
						</section>

						<section class="sf:border sf:border-slate-200 sf:rounded-md">
							<button
								type="button"
								class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
								aria-expanded={mappingSectionExpansion.conditions}
								aria-controls="mapping-section-content-conditions"
								data-testid="mapping-section-toggle-conditions"
								onclick={() => toggleMappingSection('conditions')}
							>
								<span class="sf:flex sf:flex-col">
									<span class="sf:text-sm sf:font-semibold sf:text-slate-800">Conditional run</span>
									<span class="sf:text-xs sf:text-slate-500">{conditionsSummary}</span>
								</span>
								<span class="sf:text-xs sf:text-slate-500">
									{mappingSectionExpansion.conditions ? 'Hide' : 'Show'}
								</span>
							</button>
							<div
								id="mapping-section-content-conditions"
								class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4"
								hidden={!mappingSectionExpansion.conditions}
							>
								{#if mappingSectionExpansion.conditions}
									{#if fieldsLoading}
										<p class="sf:text-sm sf:text-slate-500">
											Loading form fields for conditional run options...
										</p>
									{/if}
									<ConditionBuilder
										fields={formFields}
										value={draftSettings.conditions ?? createDefaultConditionConfig()}
										disabled={fieldsLoading}
										onchange={(conditions) => {
											draftSettings = { ...draftSettings, conditions };
										}}
									/>
								{/if}
							</div>
						</section>

						<section class="sf:border sf:border-slate-200 sf:rounded-md">
							<button
								type="button"
								class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
								aria-expanded={mappingSectionExpansion.model_execution}
								aria-controls="mapping-section-content-model-execution"
								data-testid="mapping-section-toggle-model_execution"
								onclick={() => toggleMappingSection('model_execution')}
							>
								<span class="sf:flex sf:flex-col">
									<span class="sf:text-sm sf:font-semibold sf:text-slate-800">
										Model and execution
									</span>
									<span class="sf:text-xs sf:text-slate-500">{modelExecutionSummary}</span>
								</span>
								<span class="sf:text-xs sf:text-slate-500">
									{mappingSectionExpansion.model_execution ? 'Hide' : 'Show'}
								</span>
							</button>
							<div
								id="mapping-section-content-model-execution"
								class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4 sf:space-y-4"
								hidden={!mappingSectionExpansion.model_execution}
							>
								{#if mappingSectionExpansion.model_execution}
									<Alert variant="info">
										<p class="sf:text-sm">
											{#if effectiveMappingModelSource === 'mapping'}
												This mapping is using its own model override.
											{:else if effectiveMappingModelSource === 'form'}
												This mapping is inheriting its model from the form-level defaults.
											{:else if effectiveMappingModelSource === 'action'}
												This mapping is inheriting its model from the global action defaults.
											{:else}
												This mapping is using the platform default model selection.
											{/if}
										</p>
									</Alert>
									<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-2">
										<p class="sf:text-xs sf:text-slate-500">
											Changing the selector below creates or updates a mapping-specific override.
										</p>
										{#if draftSettings.model_selection}
											<Button size="sm" variant="ghost" onclick={clearMappingModelSelection}>
												Use inherited defaults
											</Button>
										{:else if editingLinkage}
											<Button
												size="sm"
												variant="ghost"
												onclick={() => loadFormLevelConfig(editingLinkage.central_action_id)}
											>
												Edit Form Defaults
											</Button>
										{/if}
									</div>
									<ModelSelector
										level="mapping"
										value={effectiveMappingModelSelection ?? cloneDefaultModelSelection()}
										actionId={getActionDefinitionContext(editingLinkage?.central_action_id ?? null).actionId}
										templateModelHint={
											getActionDefinitionContext(editingLinkage?.central_action_id ?? null).modelHint
										}
										baseCreditCost={
											getActionDefinitionContext(editingLinkage?.central_action_id ?? null)
												.baseCreditCost
										}
										actionSelection={currentActionDefaults.model_selection ?? null}
										formSelection={currentFormActionConfig.model_selection ?? null}
										mappingSelection={
											(draftSettings.model_selection as ModelSelection | undefined) ?? null
										}
										onchange={handleMappingModelSelectionChange}
									/>

									{#if draftSettings.execution_mode === 'after_submission'}
										<div class="sf:border-t sf:border-slate-200 sf:pt-4">
											<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:mb-2">
												<p
													class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500"
												>
													Batch Execution
												</p>
												<Toggle
													checked={draftSettings.batch_settings?.enabled ?? false}
													onchange={() => {
														const current = draftSettings.batch_settings ?? {
															...DEFAULT_BATCH_SETTINGS
														};
														draftSettings = {
															...draftSettings,
															batch_settings: { ...current, enabled: !current.enabled }
														};
													}}
												/>
											</div>
											<p class="sf:text-xs sf:text-slate-500 sf:mb-3">
												Delay execution to reduce peak load. Credit pricing is calculated by CPS at
												execution time.
											</p>

											{#if draftSettings.batch_settings?.enabled}
												<div class="sf:grid sf:gap-3">
													<InputField
														id="batch-delay"
														label="Delay (seconds)"
														type="number"
														min="10"
														max="3600"
														placeholder="60"
														bind:value={draftSettings.batch_settings.delay_seconds}
													/>
													<InputField
														id="batch-max-wait"
														label="Max wait before fallback (seconds)"
														type="number"
														min="43200"
														max="604800"
														placeholder="86400"
														bind:value={draftSettings.batch_settings.max_wait_seconds}
													/>
													<p class="sf:text-xs sf:text-slate-500">
														If CPS batching cannot be queued immediately, Sentient Forms will fall
														back to local scheduling by this deadline.
													</p>
												</div>
											{/if}
										</div>
									{/if}
								{/if}
							</div>
						</section>
					</div>

					<footer
						class="sf:flex sf:flex-wrap sf:items-center sf:justify-between sf:gap-3 sf:px-4 sf:sm:px-6 sf:py-4 sf:border-t sf:border-slate-200 sf:bg-slate-50"
					>
						<p class="sf:text-xs sf:text-slate-600">
							Close keeps draft changes in this browser only.
						</p>
						<div class="sf:flex sf:flex-wrap sf:items-center sf:justify-end sf:gap-2">
							<Button
								variant="ghost"
								onclick={closeMappingConfigModal}
								data-testid="mapping-config-close-preserve"
							>
								Close
							</Button>
							<Button
								variant="secondary"
								onclick={cancelEditingAction}
								data-testid="mapping-config-discard-draft"
							>
								Discard draft
							</Button>
							<Button
								variant="primary"
								onclick={() => saveActionChanges(editingLinkage)}
								data-testid="mapping-config-save"
							>
								Save mapping
							</Button>
						</div>
					</footer>
				</div>
			</div>
		{/if}

		{#if showAddPanel}
			<div class="sf:fixed sf:inset-0 sf:z-30 sf:bg-black/40 sf:flex sf:justify-end">
				<div class="sf:h-full sf:w-full sf:max-w-xl sf:bg-white sf:shadow-2xl sf:flex sf:flex-col">
					<div
						class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:border-b sf:border-slate-200 sf:px-4 sf:py-3"
					>
						<div>
							<p class="sf:text-sm sf:font-semibold sf:text-slate-800">Add action</p>
							<p class="sf:text-xs sf:text-slate-500">Link a CPS template or custom action.</p>
						</div>
						<Button
							variant="ghost"
							size="sm"
							onclick={() => {
								selectedCreateDependencyIds = new Set();
								createError = null;
								showAddPanel = false;
							}}
						>
							Close
						</Button>
					</div>
 
					<div class="sf:flex sf:flex-wrap sf:items-end sf:gap-2 sf:px-4 sf:py-3">
						<Button
							size="sm"
							variant={createKind === 'template' ? 'primary' : 'secondary'}
							onclick={() => (createKind = 'template')}
							disabled={!hasDefinitions}
						>
							CPS templates
						</Button>
						<Button
							size="sm"
							variant={createKind === 'custom' ? 'primary' : 'secondary'}
							onclick={() => (createKind = 'custom')}
							disabled={customActions.length === 0}
						>
							Custom actions
						</Button>
						<div class="sf:flex-1 sf:min-w-[200px]">
							<InputField
								id="action-search"
								label="Search"
								placeholder="Search by name or id"
								bind:value={searchTerm}
							/>
						</div>
					</div>

					<form
						class="sf:flex sf:flex-col sf:gap-4 sf:px-4 sf:pb-4 sf:overflow-y-auto"
						data-testid="link-action-form"
					>
						{#if createKind === 'template'}
							{#if !hasDefinitions}
								<Alert variant="warning">No CPS templates available right now.</Alert>
							{:else}
								<div class="sf:space-y-2">
									{#each definitions.filter((definition) => {
										const term = searchTerm.toLowerCase();
										if (!term) return true;
										const label = (definition.label ?? '').toLowerCase();
										return definition.id.toLowerCase().includes(term) || label.includes(term);
									}) as definition (definition.id)}
										<label
											class="sf:flex sf:items-start sf:gap-3 sf:border sf:border-slate-200 sf:rounded-md sf:p-3 sf:cursor-pointer sf:hover:border-primary-300"
										>
											<input
												type="radio"
												name="template-choice"
												class="sf:mt-1 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												checked={selectedTemplateId === definition.id}
												onchange={() => (selectedTemplateId = definition.id)}
											/>
											<div class="sf:flex sf:flex-col sf:gap-1">
												<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
													{definition.label ?? definition.id}
												</p>
												<p class="sf:text-xs sf:text-slate-500">ID: {definition.id}</p>
												<p class="sf:text-xs sf:text-slate-500">
													CPS base cost: {formatBaseCreditCost(definition)} · Model: {formatModelHint(
														definition
													)}
												</p>
												<p class="sf:text-xs sf:text-slate-500">
													Hooks: {summarizeDefinitionHooks(definition.hooks)}
												</p>
											</div>
										</label>
									{/each}
								</div>
							{/if}
						{:else if customActions.length === 0}
							<Alert variant="info">No active custom actions. Create one first.</Alert>
						{:else}
							<div class="sf:space-y-2">
								{#each customActions.filter((action) => {
									const term = searchTerm.toLowerCase();
									if (!term) return true;
									return action.display_name.toLowerCase().includes(term) || action.code
											.toLowerCase()
											.includes(term) || action.id.toLowerCase().includes(term);
								}) as action (action.id)}
									<label
										class="sf:flex sf:items-start sf:gap-3 sf:border sf:border-slate-200 sf:rounded-md sf:p-3 sf:cursor-pointer sf:hover:border-primary-300"
									>
										<input
											type="radio"
											name="custom-choice"
											class="sf:mt-1 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
											checked={selectedCustomId === action.id}
											onchange={() => (selectedCustomId = action.id)}
										/>
										<div class="sf:flex sf:flex-col sf:gap-1">
											<p class="sf:text-sm sf:font-semibold sf:text-slate-800">
												{action.display_name}
											</p>
											<p class="sf:text-xs sf:text-slate-500">Code: {action.code}</p>
											{#if action.base_credit_cost !== null}
												<p class="sf:text-xs sf:text-slate-500">
													CPS base cost: {action.base_credit_cost} credits
												</p>
											{/if}
										</div>
									</label>
								{/each}
							</div>
						{/if}

						<div>
							<div class="sf:flex sf:items-center sf:gap-2 sf:mb-2">
								<p class="sf:text-sm sf:font-medium sf:text-slate-700">Triggers</p>
								{#if selectedHooks.size === 0}
									<span class="sf:text-xs sf:text-amber-600">Select at least one</span>
								{/if}
							</div>
							<div class="sf:flex sf:flex-wrap sf:gap-3">
								{#if hookEntries.length === 0}
									{#each Object.entries(FALLBACK_HOOK_LABELS) as [hookKey, hookLabel]}
										<label
											class="sf:flex sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700 sf:border sf:border-slate-200 sf:rounded-md sf:px-3 sf:py-2"
										>
											<input
												type="checkbox"
												class="sf:form-checkbox sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												checked={selectedHooks.has(hookKey)}
												onchange={() => toggleHookSelection(hookKey)}
											/>
											<span>{hookLabel}</span>
										</label>
									{/each}
								{:else}
									{#each hookEntries as [hookKey, hookLabel] (hookKey)}
										<label
											class="sf:flex sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700 sf:border sf:border-slate-200 sf:rounded-md sf:px-3 sf:py-2"
										>
											<input
												type="checkbox"
												class="sf:form-checkbox sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												checked={selectedHooks.has(hookKey)}
												onchange={() => toggleHookSelection(hookKey)}
											/>
											<span>{hookLabel}</span>
										</label>
									{/each}
								{/if}
							</div>
						</div>

						<div class="sf:border-t sf:border-slate-200 sf:pt-3 sf:space-y-2">
							<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-2 sf:sm:flex-row sf:sm:items-center">
								<p class="sf:text-sm sf:font-medium sf:text-slate-700">
									Triggered by action (optional)
								</p>
								{#if selectedCreateDependencyIds.size > 0}
									<Badge variant="info">1 selected</Badge>
								{/if}
							</div>
							<p class="sf:text-xs sf:text-slate-500">
								Choose one mapped action as upstream trigger source, or leave empty for autonomous
								hook roots.
							</p>
							{#if selectedHooks.size === 0}
								<p class="sf:text-xs sf:text-amber-700">
									Choose trigger hooks first to see compatible upstream actions.
								</p>
							{:else if editableDependenciesForCreate.length === 0}
								<p class="sf:text-xs sf:text-slate-500">
									No compatible existing actions match the selected hooks.
								</p>
							{:else}
								<div class="sf:grid sf:gap-2">
									{#each editableDependenciesForCreate as linkage (linkage.local_mapping_id)}
										<label
											class="sf:flex sf:items-start sf:gap-2 sf:border sf:border-slate-200 sf:rounded-md sf:px-3 sf:py-2 sf:cursor-pointer sf:hover:border-primary-300"
										>
											<input
												type="radio"
												name="create-dependency-trigger"
												class="sf:mt-1 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												checked={selectedCreateDependencyIds.has(linkage.local_mapping_id)}
												onchange={() => toggleCreateDependencySelection(linkage.local_mapping_id)}
											/>
											<div class="sf:min-w-0 sf:flex-1">
												<p class="sf:text-sm sf:font-medium sf:text-slate-800">
													{friendlyActionLabel(linkage)}
												</p>
												<p class="sf:text-xs sf:text-slate-500">
													ID: {linkage.local_mapping_id}
												</p>
												<div class="sf:mt-1 sf:flex sf:flex-wrap sf:gap-1">
													{#each getMappingTriggerHooks(linkage) as hook (hook)}
														<Badge variant="info">{hookOptions[hook] ?? hook}</Badge>
													{/each}
												</div>
											</div>
										</label>
									{/each}
								</div>
							{/if}
						</div>

						{#if createError}
							<Alert variant="danger">{createError}</Alert>
						{/if}

						<div class="sf:flex sf:justify-end sf:gap-2 sf:pb-2">
							<Button
								type="button"
								variant="secondary"
								onclick={() => {
									selectedCreateDependencyIds = new Set();
									createError = null;
									showAddPanel = false;
								}}
							>
								Cancel
							</Button>
							<Button
								type="button"
								onclick={() => handleCreate(new Event('submit', { cancelable: true }))}
								disabled={creating ||
									selectedHooks.size === 0 ||
									(!hasDefinitions && createKind === 'template')}
							>
								{creating ? 'Linking…' : 'Link action'}
							</Button>
						</div>
					</form>
				</div>
			</div>
		{/if}

	<!-- Phase 7 CSM: Template Library Modal -->
	<TemplateLibrary
		bind:open={showTemplateLibrary}
		siteId={licenseState.siteId ?? ''}
		formSource={data.formSourceSlug}
		formId={data.formId}
		{formFields}
		onImport={(mapping) => {
			notifications.success(`Imported template: ${mapping.display_name}`);
			formActionsStore.refresh(data.formSourceSlug, data.formId);
		}}
	/>
</Section>
