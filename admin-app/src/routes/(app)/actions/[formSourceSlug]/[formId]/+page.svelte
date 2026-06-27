<script lang="ts">
	import { onMount } from 'svelte';
	import {
		Section,
		Card,
		Button,
		ButtonLink,
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
	import AlignedSelectGrid from '$lib/components/aligned-select-grid.svelte';
	import ActionCustomizationEditor from '$lib/components/action-customization-editor.svelte';
	import RealtimeSettingsEditor from '$lib/components/realtime-settings-editor.svelte';
	import SiteContextWarning from '$lib/components/site-context-warning.svelte';
	import SpamCriteriaEditor from '$lib/components/spam-criteria-editor.svelte';
	import { DEFAULT_BATCH_SETTINGS, sanitizeBatchSettings } from '$lib/utils/batch';
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
	import { appHref, navigateToAppPath } from '$lib/navigation';
	import { formActionsStore, formActionsState } from '$lib/stores/form-actions.svelte';
	import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
	import { notifications } from '$lib/stores/notifications';
	import { licenseState } from '$lib/stores/license.svelte';
	import { formMappingsStore } from '$lib/stores/form-mappings.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import type { ModelSelectorCapabilityKey } from '$lib/utils/model-selector-presentation';
	import type {
		ActionDefinition,
		AttachmentMapping,
		CustomAction,
		CustomActionPostExecutionActionPayload,
		DuplicateParentSelection,
		ExecutionMode,
		ExecutionStatus,
		FormActionConfig,
		FormActionLinkage,
		FormActionMutationPayload,
		FormExecutionStatus,
		FormFieldInfo,
		FormSourceDescriptor,
		FormSummary,
		LinkedActionStatus,
		InputMapping,
		LocalCustomActionRecord,
		LocalFormMappingRecord,
		LocalProviderCredential,
		ModelSelection,
		RealtimeSettings,
		RepairState,
		ResolvedModelSelection,
		SpamIndicatorsDisplayMode,
		SpamResultDisplayMode,
		WorkflowPlanResponse
	} from '$lib/api/types';
	import { unwrapRestResponse, type RestEnvelope } from '$lib/api/response';
	import {
		applyInheritableBooleanToConfig,
		cloneDefaultModelSelection,
		deriveDraftExecutionKind,
		getInheritableBooleanMode,
		isSpamActionCode,
		modeToOptionalBoolean,
		normalizeFormActionConfig,
		normalizeOptionalBoolean,
		normalizeSpamIndicatorsDisplay,
		normalizeSpamResultDisplayMode,
		resolveInheritableBoolean,
		resolveInheritableBooleanSource,
		resolveModelSelectionChain,
		type InheritableBooleanMode
	} from '$lib/utils/action-config';
	import {
		REALTIME_ACTION_ID,
		isRealtimeEligibleActionId,
		normalizeRealtimeSettings,
		summarizeRealtimeSettings
	} from '$lib/utils/realtime-settings';
	import {
		isReadyOpenRouterCredential,
		openRouterActionHealth,
		providerStatusLabel,
		providerStatusVariant
	} from '$lib/utils/provider-health';
	import { formatModelSelectionPrimary, formatTemplateModelHint } from '$lib/utils/model-selection';
	import { wpFetch } from '$lib/wp';

	type Props = { data: { formSourceSlug: string; formId: string } };
	type CreateKind = 'template' | 'custom' | 'local_openrouter';
	type LocalBuilderExecutionMode = 'sync' | 'async';
	type LocalBuilderTemplateKey = 'spam_filter' | 'summary' | 'lead_qualification' | 'sentiment';
	type LocalBuilderTemplate = {
		key: LocalBuilderTemplateKey;
		label: string;
		description: string;
		actionName: string;
		systemPrompt: string;
		promptTemplate: string;
		resultMetaKey: string;
		resultField: string;
		structuredOutputSchema: Record<string, unknown>;
		maxTokens: number;
		temperature: number;
		defaultExecutionMode?: LocalBuilderExecutionMode;
		effectMapping?: Record<string, unknown>;
	};
	type LocalBuilderResult = {
		action: LocalCustomActionRecord;
		mappings: LocalFormMappingRecord[];
	};
	let { data }: Props = $props();

	const LOCAL_BUILDER_TEMPLATES: Record<LocalBuilderTemplateKey, LocalBuilderTemplate> = {
		spam_filter: {
			key: 'spam_filter',
			label: 'Spam filter (recommended)',
			description:
				'Classify submissions, mark likely spam in Gravity Forms, and hold spam notifications.',
			actionName: 'Local OpenRouter spam filter',
			systemPrompt:
				'You are a careful spam filter for WordPress Gravity Forms submissions. Return only compact JSON with classification, confidence, and justification. Classify as ham unless the submission is clearly abusive, bot-like, promotional, phishing, or irrelevant.',
			promptTemplate:
				'Form: {{form.title}}\nEntry: {{entry}}\n\nReturn JSON shaped as {"classification":"ham|likely_spam|spam","confidence":0.0,"justification":"short reason"}. Use likely_spam or spam only when the evidence is strong.',
			resultMetaKey: 'sentient_forms_spam_classification',
			resultField: 'classification',
			structuredOutputSchema: {
				type: 'object',
				required: ['classification', 'confidence', 'justification'],
				additionalProperties: true,
				properties: {
					classification: {
						type: 'string',
						enum: ['ham', 'likely_spam', 'spam']
					},
					confidence: {
						type: 'number',
						minimum: 0,
						maximum: 1
					},
					justification: {
						type: 'string',
						minLength: 1
					}
				}
			},
			maxTokens: 180,
			temperature: 0,
			defaultExecutionMode: 'sync',
			effectMapping: {
				store_result: true,
				meta: {
					sentient_forms_spam_classification: 'structured.classification',
					sentient_forms_spam_confidence: 'structured.confidence'
				},
				spam: {
					enabled: true,
					classification_path: 'structured.classification',
					confidence_path: 'structured.confidence',
					min_confidence: 0.8,
					suppress_notifications_on_spam: true,
					note: {
						result_display_mode: 'spam_only',
						indicators_display: 'simple'
					}
				}
			}
		},
		summary: {
			key: 'summary',
			label: 'Entry summary',
			description: 'Save a concise submission summary to entry meta.',
			actionName: 'Local OpenRouter summary',
			systemPrompt:
				'You summarize Gravity Forms submissions for a WordPress site owner. Return only compact JSON with a summary field.',
			promptTemplate:
				'Form: {{form.title}}\nEntry: {{entry}}\n\nReturn JSON shaped as {"summary":"one concise sentence about this submission"}.',
			resultMetaKey: 'sentient_forms_summary',
			resultField: 'summary',
			structuredOutputSchema: {
				type: 'object',
				required: ['summary'],
				additionalProperties: true,
				properties: {
					summary: {
						type: 'string',
						minLength: 1
					}
				}
			},
			maxTokens: 250,
			temperature: 0.2
		},
		lead_qualification: {
			key: 'lead_qualification',
			label: 'Lead qualification',
			description: 'Classify the submission as hot, warm, cold, or not_a_lead.',
			actionName: 'Local OpenRouter lead qualification',
			systemPrompt:
				'You qualify Gravity Forms submissions for a WordPress site owner. Return only compact JSON with a qualification field.',
			promptTemplate:
				'Form: {{form.title}}\nEntry: {{entry}}\n\nReturn JSON shaped as {"qualification":"hot|warm|cold|not_a_lead"} based on buying intent and fit.',
			resultMetaKey: 'sentient_forms_qualification',
			resultField: 'qualification',
			structuredOutputSchema: {
				type: 'object',
				required: ['qualification'],
				additionalProperties: true,
				properties: {
					qualification: {
						type: 'string',
						enum: ['hot', 'warm', 'cold', 'not_a_lead']
					}
				}
			},
			maxTokens: 120,
			temperature: 0
		},
		sentiment: {
			key: 'sentiment',
			label: 'Sentiment tag',
			description: 'Classify the sender sentiment for triage or reporting.',
			actionName: 'Local OpenRouter sentiment tag',
			systemPrompt:
				'You classify the sentiment of Gravity Forms submissions. Return only compact JSON with a sentiment field.',
			promptTemplate:
				'Form: {{form.title}}\nEntry: {{entry}}\n\nReturn JSON shaped as {"sentiment":"positive|neutral|negative|urgent"} based on the sender tone.',
			resultMetaKey: 'sentient_forms_sentiment',
			resultField: 'sentiment',
			structuredOutputSchema: {
				type: 'object',
				required: ['sentiment'],
				additionalProperties: true,
				properties: {
					sentiment: {
						type: 'string',
						enum: ['positive', 'neutral', 'negative', 'urgent']
					}
				}
			},
			maxTokens: 120,
			temperature: 0
		}
	};
	const LOCAL_BUILDER_TEMPLATE_OPTIONS = Object.values(LOCAL_BUILDER_TEMPLATES);

	function fallbackFormSourceLabel(slug: string): string {
		if (slug === 'gravity_forms') return 'Gravity Forms';
		if (slug === 'contact_form_7') return 'Contact Form 7';
		if (slug === 'elementor_forms') return 'Elementor Forms';

		return slug
			.split('_')
			.filter(Boolean)
			.map((part) => part.charAt(0).toUpperCase() + part.slice(1))
			.join(' ');
	}

	function descriptorRequirementString(
		requirements: FormSourceDescriptor['requirements'] | undefined,
		key: string
	): string {
		const value = requirements?.[key];
		return typeof value === 'string' ? value.trim() : '';
	}

	function sourceAwareLocalBuilderTemplate(
		baseTemplate: LocalBuilderTemplate,
		formSourceLabel: string,
		supportsNativeSpamEffects: boolean
	): LocalBuilderTemplate {
		if (supportsNativeSpamEffects) {
			return baseTemplate;
		}

		const sourceLabel = formSourceLabel.trim() || 'this form source';
		const effectMapping = baseTemplate.effectMapping
			? (structuredClone(baseTemplate.effectMapping) as Record<string, unknown>)
			: undefined;
		if (effectMapping) {
			delete effectMapping.spam;
		}

		switch (baseTemplate.key) {
			case 'spam_filter':
				return {
					...baseTemplate,
					description: `Classify ${sourceLabel} submissions after submission. This does not block validation or write native notes.`,
					systemPrompt: `You classify ${sourceLabel} submissions for a WordPress site owner. Return only compact JSON with classification, confidence, and justification. Classify as ham unless the submission is clearly abusive, bot-like, promotional, phishing, or irrelevant.`,
					promptTemplate:
						'Form: {{form.title}}\nSubmission: {{entry}}\n\nReturn JSON shaped as {"classification":"ham|likely_spam|spam","confidence":0.0,"justification":"short reason"}. Use likely_spam or spam only when the evidence is strong.',
					defaultExecutionMode: 'async',
					effectMapping
				};
			case 'summary':
				return {
					...baseTemplate,
					description: 'Save a concise submission summary in Sentient Forms action results.',
					systemPrompt: `You summarize ${sourceLabel} submissions for a WordPress site owner. Return only compact JSON with a summary field.`,
					promptTemplate:
						'Form: {{form.title}}\nSubmission: {{entry}}\n\nReturn JSON shaped as {"summary":"one concise sentence about this submission"}.'
				};
			case 'lead_qualification':
				return {
					...baseTemplate,
					systemPrompt: `You qualify ${sourceLabel} submissions for a WordPress site owner. Return only compact JSON with a qualification field.`,
					promptTemplate:
						'Form: {{form.title}}\nSubmission: {{entry}}\n\nReturn JSON shaped as {"qualification":"hot|warm|cold|not_a_lead"} based on buying intent and fit.'
				};
			case 'sentiment':
				return {
					...baseTemplate,
					systemPrompt: `You classify the sentiment of ${sourceLabel} submissions. Return only compact JSON with a sentiment field.`,
					promptTemplate:
						'Form: {{form.title}}\nSubmission: {{entry}}\n\nReturn JSON shaped as {"sentiment":"positive|neutral|negative|urgent"} based on the sender tone.'
				};
			default:
				return baseTemplate;
		}
	}

	const DOCUMENTED_BUILT_IN_DEFINITIONS: ActionDefinition[] = [
		{
			id: 'spam_detection_v1',
			label: 'Spam Detection',
			description: 'Classify submissions as spam or legitimate.',
			source: 'bundled',
			hooks: ['gform_validation', 'gform_after_submission'],
			modelHint: 'openrouter/auto'
		},
		{
			id: 'content_validation_v1',
			label: 'Content Quality Validation',
			description: 'Reject low-quality or placeholder submissions during validation.',
			source: 'bundled',
			hooks: ['gform_validation'],
			modelHint: 'openrouter/auto'
		},
		{
			id: 'entry_summary_v1',
			label: 'Entry Summary',
			description: 'Create a concise summary after submission.',
			source: 'bundled',
			hooks: ['gform_after_submission'],
			modelHint: 'openrouter/auto'
		},
		{
			id: 'clarification_assistant_v1',
			label: 'Realtime Clarification Assistant',
			description: 'Analyze visible answers while a visitor is filling out the form.',
			source: 'bundled',
			hooks: ['real_time'],
			modelHint: 'openrouter/auto'
		}
	];
	const SPAM_RESULT_DISPLAY_OPTIONS = [
		{ value: 'spam_only', label: 'Only when spam is detected' },
		{ value: 'all_results', label: 'For every classification' },
		{ value: 'none', label: 'Do not add spam notes' }
	];
	const SPAM_INDICATORS_DISPLAY_OPTIONS = [
		{ value: 'simple', label: 'Simple (Summary only)' },
		{ value: 'detailed', label: 'Detailed (List signals)' }
	];

	const FALLBACK_HOOK_LABELS: Record<string, string> = {
		gform_validation: '🔄 During Validation (Blocking)',
		gform_after_submission: '📝 After Submission (Background)',
		real_time: 'Realtime (Form Page)'
	};
	const actionsState = formActionsState;
	const customState = customActionsState;
	const providerClient = createClientFromConfig();
	let currentFormSummary = $state<FormSummary | null>(null);
	let currentFormSummaryLoading = $state(false);
	let currentFormSummaryError = $state<string | null>(null);
	const formSourceDescriptor = $derived(actionsState.bootstrap?.form_source_descriptor ?? null);
	const currentFormAdapterLabel = $derived(
		currentFormSummary?.adapter_name?.trim() ||
			formSourceDescriptor?.label?.trim() ||
			fallbackFormSourceLabel(data.formSourceSlug)
	);
	const formSourceAvailability = $derived(
		formSourceDescriptor?.availability ??
			(formSourceDescriptor?.is_active === false ? 'inactive' : 'available')
	);
	const formSourceUnavailable = $derived(
		formSourceDescriptor !== null && formSourceAvailability !== 'available'
	);
	const canConfigureFormSource = $derived(!formSourceUnavailable);
	const formSourceAvailabilityMessage = $derived.by(() => {
		const explicit = formSourceDescriptor?.availability_message?.trim();
		if (explicit) return explicit;

		if (formSourceAvailability === 'requires_pro' || formSourceDescriptor?.requires_pro === true) {
			return `${currentFormAdapterLabel} support requires the provider's Pro Forms APIs before Sentient Forms actions can be configured.`;
		}

		if (formSourceAvailability === 'not_installed') {
			return `${currentFormAdapterLabel} is not installed in this WordPress environment.`;
		}

		return `${currentFormAdapterLabel} is unavailable in this WordPress environment.`;
	});
	const formSourceLimitationMessages = $derived.by(() => {
		if (formSourceUnavailable || !formSourceDescriptor) return [];

		const messages: string[] = [];
		const validationUnsupported = formSourceDescriptor.lifecycles.validation?.supported === false;
		const realtimeUnsupported = formSourceDescriptor.lifecycles.real_time?.supported === false;
		if (validationUnsupported && realtimeUnsupported) {
			messages.push(
				`Validation blocking and realtime assistance are not supported for ${currentFormAdapterLabel} in this release.`
			);
		}

		const nativeSubmissionReason = descriptorRequirementString(
			formSourceDescriptor.requirements,
			'native_submission_parity_reason'
		);
		if (nativeSubmissionReason) {
			messages.push(nativeSubmissionReason);
		}

		const nativeEnrichment = formSourceDescriptor.native_enrichment;
		if (
			nativeEnrichment &&
			nativeEnrichment.notes === false &&
			nativeEnrichment.status === false &&
			nativeEnrichment.spam === false
		) {
			messages.push(
				`Native result writing and spam status updates stay disabled for ${currentFormAdapterLabel}.`
			);
		}

		return messages;
	});
	const providerEditLinkLabel = $derived(`Open in ${currentFormAdapterLabel}`);
	const providerEditUrl = $derived(
		currentFormSummary?.provider_edit_url ??
			(data.formSourceSlug === 'gravity_forms'
				? `admin.php?page=gf_edit_forms&id=${encodeURIComponent(String(data.formId))}`
				: data.formSourceSlug === 'contact_form_7'
					? `admin.php?page=wpcf7&post=${encodeURIComponent(String(data.formId))}&action=edit`
					: null)
	);
	const supportsNativeSpamEffects = $derived(
		formSourceDescriptor?.native_enrichment?.spam === true ||
			(formSourceDescriptor === null && data.formSourceSlug === 'gravity_forms')
	);
	const supportsNativeNotes = $derived(
		formSourceDescriptor?.native_enrichment?.notes === true ||
			(formSourceDescriptor === null && data.formSourceSlug === 'gravity_forms')
	);
	const supportsSpamNoteControls = $derived(supportsNativeSpamEffects && supportsNativeNotes);
	const localBuilderSupportsSync = $derived.by(() => {
		if (formSourceDescriptor) {
			return formSourceDescriptor.lifecycles.validation?.supported === true;
		}

		return data.formSourceSlug === 'gravity_forms';
	});
	const initialLocalBuilderTemplate = sourceAwareLocalBuilderTemplate(
		LOCAL_BUILDER_TEMPLATES.spam_filter,
		fallbackFormSourceLabel(data.formSourceSlug),
		data.formSourceSlug === 'gravity_forms'
	);

	let createKind = $state<CreateKind>('template');
	let selectedTemplateId = $state('');
	let selectedCustomId = $state('');
	let selectedHooks = $state<Set<string>>(new Set());
	let createError = $state<string | null>(null);
	let creating = $state(false);
	let showAddPanel = $state(false);
	let showTemplateLibrary = $state(false);
	let searchTerm = $state('');
	let selectedCreateDependencyIds = $state<Set<string>>(new Set());
	let localBuilderCredentialId = $state('');
	let localBuilderTemplateKey = $state<LocalBuilderTemplateKey>('spam_filter');
	let localBuilderActionName = $state(initialLocalBuilderTemplate.actionName);
	let localBuilderSystemPrompt = $state(initialLocalBuilderTemplate.systemPrompt);
	let localBuilderPromptTemplate = $state(initialLocalBuilderTemplate.promptTemplate);
	let localBuilderResultMetaKey = $state(initialLocalBuilderTemplate.resultMetaKey);
	let localBuilderExecutionMode = $state<LocalBuilderExecutionMode>(
		initialLocalBuilderTemplate.defaultExecutionMode ?? 'async'
	);
	let localBuilderModelSelection = $state<ModelSelection>(cloneDefaultModelSelection());
	let localBuilderSpamResultDisplayMode = $state<SpamResultDisplayMode>('spam_only');
	let localBuilderSpamIndicatorsDisplay = $state<SpamIndicatorsDisplayMode>('simple');
	let localBuilderResult = $state<LocalBuilderResult | null>(null);

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
	let refreshIntervalDelay: number | null = null;
	let visibilityHandler: (() => void) | null = null;
	const STATUS_REFRESH_INTERVAL_MS = 30_000;
	const STATUS_IDLE_REFRESH_INTERVAL_MS = 120_000;
	const STATUS_RECENT_WINDOW_MS = 120_000;
	const ACTIVE_STATUS_TERMS = ['queued', 'running', 'pending', 'processing', 'in progress'];
	const ACTIVE_STATUS_PATTERNS = ACTIVE_STATUS_TERMS.map(
		(term) => new RegExp(`\\b${term.replace(/\s+/g, '\\s+')}\\b`)
	);
	let providerCredentials = $state<LocalProviderCredential[]>([]);
	let providerCredentialsLoading = $state(false);
	let providerCredentialsError = $state<string | null>(null);
	const openRouterHealth = $derived(openRouterActionHealth(providerCredentials));
	const localBuilderTemplate = $derived.by(() =>
		sourceAwareLocalBuilderTemplate(
			LOCAL_BUILDER_TEMPLATES[localBuilderTemplateKey],
			currentFormAdapterLabel,
			supportsSpamNoteControls
		)
	);
	const readyOpenRouterCredentials = $derived(
		providerCredentials
			.filter((credential) => credential.provider === 'openrouter')
			.filter((credential) => isReadyOpenRouterCredential(credential))
	);
	const selectedLocalBuilderCredential = $derived(
		readyOpenRouterCredentials.find(
			(credential) => String(credential.id) === localBuilderCredentialId
		) ??
			readyOpenRouterCredentials[0] ??
			null
	);

	// CA-MAP-001: Field selection state (loaded from API)
	let formFields = $state<FormFieldInfo[]>([]);
	let fieldsLoading = $state(false);

	function lifecycleIdForHook(hook: string): string | null {
		const normalized = hook.trim();
		if (!normalized) return null;

		for (const [lifecycleId, lifecycle] of Object.entries(formSourceDescriptor?.lifecycles ?? {})) {
			if (lifecycle.native_hook === normalized || lifecycleId === normalized) {
				return lifecycleId;
			}
		}

		if (normalized === 'gform_validation') return 'validation';
		if (normalized === 'gform_after_submission' || normalized === 'wpcf7_mail_sent') {
			return 'after_submission';
		}
		if (normalized === 'real_time') return 'real_time';

		return null;
	}

	function adaptHookForCurrentSource(hook: string): string | null {
		if (!formSourceDescriptor) return hook;

		const lifecycleId = lifecycleIdForHook(hook);
		if (!lifecycleId) return null;

		const lifecycle = formSourceDescriptor.lifecycles?.[lifecycleId];
		return lifecycle?.supported ? lifecycleId : null;
	}

	function adaptHooksForCurrentSource(hooks: Iterable<string>): string[] {
		const adapted = Array.from(hooks)
			.map((hook) => adaptHookForCurrentSource(hook))
			.filter((hook): hook is string => Boolean(hook));

		return normalizeHookIds(adapted);
	}

	function hookEntriesForAction(actionId: string | null | undefined): [string, string][] {
		const availableHookKeys = new Set(hookEntries.map(([hookKey]) => hookKey));
		const fallbackEntries = hookEntries.filter(
			([hookKey]) => hookKey !== 'real_time' || isRealtimeEligibleActionId(actionId)
		);

		if (!actionId) return fallbackEntries;

		const definition =
			(actionsState.definitions ?? []).find((item) => item.id === actionId) ??
			DOCUMENTED_BUILT_IN_DEFINITIONS.find((item) => item.id === actionId);
		const definitionHooks = normalizeDefinitionHooks(definition?.hooks);
		const allowedHooks =
			definitionHooks.length > 0
				? definitionHooks
				: isRealtimeEligibleActionId(actionId)
					? ['real_time']
					: fallbackEntries.map(([hookKey]) => hookKey);
		const entryByHook = new Map(hookEntries);

		return adaptHooksForCurrentSource(allowedHooks)
			.filter((hookKey) => availableHookKeys.has(hookKey))
			.filter((hookKey) => hookKey !== 'real_time' || isRealtimeEligibleActionId(actionId))
			.map((hookKey) => [
				hookKey,
				entryByHook.get(hookKey) ?? FALLBACK_HOOK_LABELS[hookKey] ?? hookKey
			]);
	}

	function sanitizeHooksForAction(
		hooks: Iterable<string>,
		actionId: string | null | undefined
	): string[] {
		const availableHookKeys = new Set(hookEntries.map(([hookKey]) => hookKey));
		const normalized = adaptHooksForCurrentSource(hooks);
		const available = normalized.filter((hook) => availableHookKeys.has(hook));
		if (isRealtimeEligibleActionId(actionId)) return available;
		return available.filter((hook) => hook !== 'real_time');
	}

	function defaultAvailableHookKeys(): string[] {
		return hookEntries.map(([hookKey]) => hookKey);
	}

	function defaultLocalBuilderHooks(): string[] {
		const availableHookKeys = new Set(defaultAvailableHookKeys());
		if (localBuilderExecutionMode === 'sync' && availableHookKeys.has('validation')) {
			return ['validation'];
		}
		if (localBuilderExecutionMode === 'sync' && availableHookKeys.has('gform_validation')) {
			return ['gform_validation'];
		}
		if (availableHookKeys.has('after_submission')) {
			return ['after_submission'];
		}
		if (availableHookKeys.has('gform_after_submission')) {
			return ['gform_after_submission'];
		}

		return defaultAvailableHookKeys().slice(0, 1);
	}

	function normalizeLocalBuilderExecutionMode(
		mode: LocalBuilderExecutionMode
	): LocalBuilderExecutionMode {
		return mode === 'sync' && !localBuilderSupportsSync ? 'async' : mode;
	}

	function deriveExecutionModeForHooks(hooks: Iterable<string>, current?: unknown): ExecutionMode {
		const normalizedHooks = normalizeHookIds(hooks);
		if (normalizedHooks.includes('real_time')) {
			return 'real_time';
		}

		if (current === 'real_time' || current === 'validation' || current === 'after_submission') {
			return current;
		}

		return normalizedHooks.includes('validation') || normalizedHooks.includes('gform_validation')
			? 'validation'
			: 'after_submission';
	}

	function resolveRealtimeSettingsChain(
		actionConfig: FormActionConfig,
		formConfig: FormActionConfig,
		mappingRealtimeSettings?: unknown
	): RealtimeSettings {
		const actionSettings =
			actionConfig.realtime_settings && typeof actionConfig.realtime_settings === 'object'
				? actionConfig.realtime_settings
				: {};
		const formSettings =
			formConfig.realtime_settings && typeof formConfig.realtime_settings === 'object'
				? formConfig.realtime_settings
				: {};
		const mappingSettings =
			mappingRealtimeSettings && typeof mappingRealtimeSettings === 'object'
				? mappingRealtimeSettings
				: {};

		return normalizeRealtimeSettings({
			...actionSettings,
			...formSettings,
			...mappingSettings
		});
	}

	function updateRealtimeSettings(partial: Partial<RealtimeSettings>) {
		const current = normalizeRealtimeSettings(draftSettings.realtime_settings);
		draftSettings = {
			...draftSettings,
			execution_mode: 'real_time',
			realtime_settings: {
				...current,
				...partial
			}
		};
	}

	function createBlankFormActionConfig(): FormActionConfig {
		return normalizeFormActionConfig({});
	}

	function mergeDocumentedBuiltInDefinitions(items: ActionDefinition[]): ActionDefinition[] {
		const byId = new Map(items.map((definition) => [definition.id, definition]));
		const ordered: ActionDefinition[] = [];
		const seen = new Set<string>();
		const hasOfficialSpamDefinition = byId.has('spam_detection_v1');

		for (const fallback of DOCUMENTED_BUILT_IN_DEFINITIONS) {
			const definition = byId.get(fallback.id) ?? fallback;
			ordered.push(definition);
			seen.add(definition.id);
		}

		for (const definition of items) {
			if (seen.has(definition.id)) {
				continue;
			}

			if (definition.id === 'spam_analysis' && hasOfficialSpamDefinition) {
				continue;
			}

			ordered.push(definition);
			seen.add(definition.id);
		}

		return ordered;
	}

	function formatSpamResultDisplayMode(value: SpamResultDisplayMode): string {
		switch (normalizeSpamResultDisplayMode(value)) {
			case 'spam_only':
				return 'notes on spam only';
			case 'none':
				return 'no spam notes';
			default:
				return 'notes on all classifications';
		}
	}

	function getLinkedActionStatus(linkage: FormActionLinkage): LinkedActionStatus {
		const raw = linkage.linked_action_status ?? linkage.settings?.linked_action_status;
		return typeof raw === 'string' && raw.trim().length > 0 ? raw : 'unknown';
	}

	function getRepairState(linkage: FormActionLinkage): RepairState {
		const raw = linkage.repair_state ?? linkage.settings?.repair_state;
		return typeof raw === 'string' && raw.trim().length > 0 ? raw : 'ok';
	}

	function linkageNeedsRepair(linkage: FormActionLinkage): boolean {
		return getRepairState(linkage) !== 'ok';
	}

	function repairStateMessage(linkage: FormActionLinkage): string {
		const linkedStatus = getLinkedActionStatus(linkage);
		switch (linkedStatus) {
			case 'archived':
				return 'The linked local action is archived. Re-link or rebuild this mapping.';
			case 'missing':
				return 'The linked local action is missing. Re-link or rebuild this mapping.';
			default:
				return 'This local-first mapping needs repair before it can run reliably.';
		}
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
		requiredCapabilities: ModelSelectorCapabilityKey[];
	} {
		if (!actionId) {
			return {
				actionId: null,
				modelHint: null,
				baseCreditCost: null,
				requiredCapabilities: []
			};
		}

		const definition = definitions.find((item) => item.id === actionId);
		if (definition) {
			return {
				actionId,
				modelHint: definition.modelHint ?? null,
				baseCreditCost: definition.baseCreditCost ?? null,
				requiredCapabilities: hasStructuredOutputSchema(definition.structuredOutputSchema)
					? ['structured']
					: []
			};
		}

		const customAction = customActions.find((item) => item.code === actionId);
		if (customAction) {
			return {
				actionId,
				modelHint: customAction.model_hint ?? null,
				baseCreditCost: customAction.base_credit_cost ?? null,
				requiredCapabilities: customActionRequiresStructuredOutput(customAction)
					? ['structured']
					: []
			};
		}

		return {
			actionId,
			modelHint: null,
			baseCreditCost: null,
			requiredCapabilities: []
		};
	}

	function isPlainObject(value: unknown): value is Record<string, unknown> {
		return Boolean(value && typeof value === 'object' && !Array.isArray(value));
	}

	function hasStructuredOutputSchema(value: unknown): boolean {
		return isPlainObject(value) && Object.keys(value).length > 0;
	}

	function customActionRequiresStructuredOutput(action: CustomAction): boolean {
		return (
			hasStructuredOutputSchema(action.output_contract?.schema) ||
			hasStructuredOutputSchema(action.output_contract?.json_schema) ||
			hasStructuredOutputSchema(action.definition?.structured_output_schema)
		);
	}

	function cloneDraftValue<T>(value: T): T {
		if (typeof structuredClone === 'function') {
			try {
				return structuredClone(value);
			} catch {
				// Svelte state proxies cannot always be structured-cloned; JSON fallback is enough for
				// the plain mapping settings objects this editor stores.
			}
		}

		const serialized = JSON.stringify(value);
		return serialized === undefined ? value : (JSON.parse(serialized) as T);
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
	let appliedBootstrapKey = $state<string | null>(null);
	const actionDefaultPreloadIds = new Set<string>();

	function formDetailBootstrapKey(bootstrap: {
		form_source: string;
		form_id: string | number;
		generated_at: string;
		actions: unknown[];
	}): string {
		return `${bootstrap.form_source}:${bootstrap.form_id}:${bootstrap.generated_at}:${bootstrap.actions.length}`;
	}

	async function loadFormActionConfigIndex() {
		try {
			const client = createClientFromConfig();
			const configs = await client.getFormActionConfigs(data.formSourceSlug, data.formId, {
				showNotifications: false
			});
			const normalized = Object.fromEntries(
				Object.entries(configs).map(([actionId, config]) => [
					actionId,
					normalizeFormActionConfig(config)
				])
			);
			formLevelConfigByActionId = {
				...formLevelConfigByActionId,
				...normalized
			};
		} catch (error) {
			console.warn('[FormLevelConfig] Failed to preload form-level config index:', error);
		}
	}

	function normalizeActionDefaultsForAction(actionId: string, config: FormActionConfig): FormActionConfig {
		const normalizedConfig = normalizeFormActionConfig(config);
		return isRealtimeEligibleActionId(actionId)
			? {
					...normalizedConfig,
					realtime_settings: normalizeRealtimeSettings(normalizedConfig.realtime_settings)
				}
			: normalizedConfig;
	}

	async function loadActionDefaultsForAction(
		actionId: string,
		options: { force?: boolean } = {}
	): Promise<FormActionConfig> {
		if (!options.force && actionDefaultsByActionId[actionId]) {
			return actionDefaultsByActionId[actionId];
		}

		const client = createClientFromConfig();
		const config = normalizeActionDefaultsForAction(actionId, await client.getActionDefaults(actionId));
		actionDefaultsByActionId = {
			...actionDefaultsByActionId,
			[actionId]: config
		};
		return config;
	}

	async function loadActionDefaultsBatch(actionIds: string[]): Promise<void> {
		const ids = [...new Set(actionIds.map((actionId) => actionId.trim()).filter(Boolean))];
		if (ids.length === 0) return;

		const client = createClientFromConfig();
		const defaults = await client.getActionDefaultsBatch(ids);
		const returnedIds = new Set(Object.keys(defaults));
		actionDefaultsByActionId = {
			...actionDefaultsByActionId,
			...Object.fromEntries(
				Object.entries(defaults).map(([actionId, config]) => [
					actionId,
					normalizeActionDefaultsForAction(actionId, config ?? {})
				])
			)
		};

		for (const actionId of ids) {
			if (!returnedIds.has(actionId)) {
				actionDefaultPreloadIds.delete(actionId);
			}
		}
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

		const actionDefaultsPromise = loadActionDefaultsForAction(actionId, { force: false }).catch(
			(error) => {
				console.warn('[ActionDefaults] Failed to load action defaults for form config:', error);
				return createBlankFormActionConfig();
			}
		);

		try {
			if (!shouldForce && formLevelConfigByActionId[actionId]) {
				const actionDefaults = await actionDefaultsPromise;
				const cachedConfig = isRealtimeEligibleActionId(actionId)
					? {
							...formLevelConfigByActionId[actionId],
							realtime_settings: resolveRealtimeSettingsChain(
								actionDefaults,
								formLevelConfigByActionId[actionId],
								formLevelConfigByActionId[actionId].realtime_settings
							)
						}
					: formLevelConfigByActionId[actionId];
				if (shouldOpenModal) {
					formLevelConfig = cachedConfig;
				}
				return cachedConfig;
			}

			const client = createClientFromConfig();
			const [actionDefaults, rawConfig] = await Promise.all([
				actionDefaultsPromise,
				client.getFormActionConfig(data.formSourceSlug, data.formId, actionId)
			]);
			const normalizedConfig = normalizeFormActionConfig(rawConfig);
			const config = isRealtimeEligibleActionId(actionId)
				? {
						...normalizedConfig,
						realtime_settings: resolveRealtimeSettingsChain(
							actionDefaults,
							normalizedConfig,
							normalizedConfig.realtime_settings
						)
					}
				: normalizedConfig;
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
			}
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

	function preloadActionDefaultsForVisibleActions() {
		const ids = [
			...builtInDefinitions.map((definition) => definition.id),
			...customActions.map((action) => action.code)
		]
			.map((actionId) => actionId?.trim())
			.filter((actionId): actionId is string => Boolean(actionId));
		const missing = ids.filter((actionId) => !actionDefaultPreloadIds.has(actionId));
		if (missing.length === 0) return;

		for (const actionId of missing) {
			actionDefaultPreloadIds.add(actionId);
		}
		void loadActionDefaultsBatch(missing).catch((error) => {
			console.warn('[ActionDefaults] Failed to preload visible action defaults:', error);
			for (const actionId of missing) {
				actionDefaultPreloadIds.delete(actionId);
			}
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
		field:
			| 'suppress_notifications_on_spam'
			| 'suppress_webhooks_on_spam'
			| 'skip_downstream_on_spam',
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
		field:
			| 'suppress_notifications_on_spam'
			| 'suppress_webhooks_on_spam'
			| 'skip_downstream_on_spam',
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

	async function loadCurrentFormSummary() {
		currentFormSummaryLoading = true;
		currentFormSummaryError = null;

		try {
			const forms = await providerClient.getForms(data.formSourceSlug, { showNotifications: false });
			currentFormSummary =
				forms.find((form) => String(form.id) === String(data.formId)) ?? null;
		} catch (error) {
			currentFormSummary = null;
			currentFormSummaryError =
				error instanceof Error ? error.message : 'Unable to load form title.';
		} finally {
			currentFormSummaryLoading = false;
		}
	}

	async function loadProviderCredentials() {
		providerCredentialsLoading = true;
		providerCredentialsError = null;

		try {
			providerCredentials = await providerClient.getLocalProviderCredentials({
				showNotifications: false
			});
		} catch (error) {
			providerCredentials = [];
			providerCredentialsError =
				error instanceof Error ? error.message : 'Failed to load local OpenRouter status.';
		} finally {
			providerCredentialsLoading = false;
		}
	}

	function hydrateFormDetailBootstrap() {
		const bootstrap = actionsState.bootstrap;
		if (!bootstrap) return;

		const bootstrapKey = formDetailBootstrapKey(bootstrap);
		if (appliedBootstrapKey === bootstrapKey) return;
		appliedBootstrapKey = bootstrapKey;

		if (bootstrap.form) {
			currentFormSummary = bootstrap.form;
			currentFormSummaryError = null;
			currentFormSummaryLoading = false;
		} else {
			void loadCurrentFormSummary();
		}

		if (bootstrap.form_fields) {
			formFields = bootstrap.form_fields;
			fieldsLoading = false;
		} else {
			void loadFormFields();
		}

		if (bootstrap.provider_credentials) {
			providerCredentials = bootstrap.provider_credentials;
			providerCredentialsError = null;
			providerCredentialsLoading = false;
		} else {
			void loadProviderCredentials();
		}

		if (bootstrap.form_action_configs) {
			formLevelConfigByActionId = {
				...formLevelConfigByActionId,
				...Object.fromEntries(
					Object.entries(bootstrap.form_action_configs).map(([actionId, config]) => [
						actionId,
						normalizeFormActionConfig(config)
					])
				)
			};
		} else {
			void loadFormActionConfigIndex();
		}

		if (bootstrap.action_defaults) {
			actionDefaultsByActionId = {
				...actionDefaultsByActionId,
				...Object.fromEntries(
					Object.entries(bootstrap.action_defaults).map(([actionId, config]) => [
						actionId,
						normalizeActionDefaultsForAction(actionId, config)
					])
				)
			};
			for (const actionId of Object.keys(bootstrap.action_defaults)) {
				actionDefaultPreloadIds.add(actionId);
			}
		}

		if (bootstrap.custom_actions) {
			customActionsStore.hydrate(bootstrap.custom_actions, { status: 'active' });
		} else {
			void customActionsStore.load({ status: 'active' });
		}

		if (bootstrap.workflow_plan) {
			workflowPlan = bootstrap.workflow_plan;
			workflowPlanError = null;
			lastWorkflowPlanSignature = createWorkflowPlanSignature(actionsState.items ?? [], workflowPlanScope);
		} else {
			lastWorkflowPlanSignature = '';
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

	const definitions = $derived.by(() =>
		mergeDocumentedBuiltInDefinitions(actionsState.definitions ?? [])
	);
	const customActions = $derived(
		customState.actions.filter((action) => action.status === 'active')
	);
	const definitionLookup = $derived.by(() =>
		definitions.reduce<Record<string, ActionDefinition>>((acc, definition) => {
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
		const hasSourceDescriptor = formSourceDescriptor !== null;
		const next: Record<string, string> = hasSourceDescriptor ? {} : { ...FALLBACK_HOOK_LABELS };
		const descriptorHookKeys = new Set<string>();
		for (const [lifecycleId, lifecycle] of Object.entries(formSourceDescriptor?.lifecycles ?? {})) {
			if (!lifecycle.supported) continue;
			const hookKey = lifecycleId;
			descriptorHookKeys.add(hookKey);
			next[hookKey] = lifecycle.label?.toString() || next[hookKey] || hookKey;
		}
		for (const definition of actionsState.definitions ?? []) {
			if (!definition?.hooks) continue;

			if (Array.isArray(definition.hooks)) {
				for (const hook of definition.hooks) {
					const key = hasSourceDescriptor ? adaptHookForCurrentSource(hook?.toString() ?? '') : hook?.toString();
					if (hasSourceDescriptor && (!key || !descriptorHookKeys.has(key))) continue;
					if (key) next[key] = next[key] ?? key;
				}
			} else if (typeof definition.hooks === 'object') {
				for (const [hook, label] of Object.entries(definition.hooks)) {
					const key = hasSourceDescriptor ? adaptHookForCurrentSource(hook) : hook;
					if (hasSourceDescriptor && (!key || !descriptorHookKeys.has(key))) continue;
					if (key) next[key] = label?.toString() ?? next[key] ?? key;
				}
			}
		}
		hookOptions = next;
	});

	const hookEntries = $derived<[string, string][]>(Object.entries(hookOptions));
	const selectedCreateActionId = $derived(createKind === 'template' ? selectedTemplateId : null);
	const createHookEntries = $derived(hookEntriesForAction(selectedCreateActionId));
	const editingLinkage = $derived.by(() => {
		if (!editingLinkageId) return null;
		return actionsState.items.find((item) => item.local_mapping_id === editingLinkageId) ?? null;
	});
	const editingActionId = $derived(editingLinkage?.central_action_id ?? null);
	const mappingHookEntries = $derived(hookEntriesForAction(editingActionId));
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
		return resolveModelSelectionChain({
			platform: cloneDefaultModelSelection(),
			action: currentActionDefaults.model_selection ?? null,
			form: currentFormActionConfig.model_selection ?? null,
			mapping: mappingSelection
		}).selection;
	});
	const effectiveMappingModelSource = $derived.by(() => {
		const mappingSelection =
			draftSettings.model_selection &&
			typeof draftSettings.model_selection === 'object' &&
			!Array.isArray(draftSettings.model_selection)
				? (draftSettings.model_selection as ModelSelection)
				: null;
		const resolved = resolveModelSelectionChain({
			platform: cloneDefaultModelSelection(),
			action: currentActionDefaults.model_selection ?? null,
			form: currentFormActionConfig.model_selection ?? null,
			mapping: mappingSelection
		});
		return resolved.source === 'platform' ? 'system' : resolved.source;
	});
	const effectiveDraftExecutionKind = $derived(
		deriveDraftExecutionKind(draftHooks, draftSettings.execution_mode)
	);
	const isRealtimeDraft = $derived(
		draftHooks.has('real_time') || draftSettings.execution_mode === 'real_time'
	);
	const hasDisallowedRealtimeDraft = $derived(
		isRealtimeDraft && !isRealtimeEligibleActionId(editingActionId)
	);
	const realtimeSettings = $derived.by(() =>
		normalizeRealtimeSettings(draftSettings.realtime_settings)
	);
	const realtimeStorageFields = $derived.by(() =>
		formFields.filter((field) => ['hidden', 'textarea'].includes(field.type.toLowerCase()))
	);
	const realtimeStorageFieldOptions = $derived.by(() => [
		{ value: '', label: 'Automatic hidden field (recommended)' },
		...realtimeStorageFields.map((field) => ({
			value: field.id,
			label: `${field.adminLabel || field.label || `Field ${field.id}`} (${field.id})`
		}))
	]);
	const realtimeTotalPages = $derived.by(() =>
		Math.max(1, ...formFields.map((field) => Number(field.page_index ?? 1) || 1))
	);
	const isDraftAfterSubmissionOnly = $derived(effectiveDraftExecutionKind === 'background');
	const isBlockingSpamMapping = $derived(
		isSpamMapping && effectiveDraftExecutionKind !== 'background'
	);
	const effectiveSuppressNotificationsOnSpam = $derived.by(() => {
		if (!isSpamMapping) return false;
		return resolveInheritableBoolean(
			[
				normalizeOptionalBoolean(draftSettings.suppress_notifications_on_spam),
				currentFormActionConfig.suppress_notifications_on_spam,
				currentActionDefaults.suppress_notifications_on_spam
			],
			!isDraftAfterSubmissionOnly
		);
	});
	const effectiveSuppressNotificationsOnSpamSource = $derived.by(() =>
		resolveInheritableBooleanSource(
			[
				{
					level: 'mapping',
					value: normalizeOptionalBoolean(draftSettings.suppress_notifications_on_spam)
				},
				{ level: 'form', value: currentFormActionConfig.suppress_notifications_on_spam },
				{ level: 'action', value: currentActionDefaults.suppress_notifications_on_spam }
			],
			isDraftAfterSubmissionOnly ? 'background inactive' : 'blocking default'
		)
	);
	const effectiveSuppressWebhooksOnSpam = $derived.by(() => {
		if (!isSpamMapping) return false;
		return resolveInheritableBoolean(
			[
				normalizeOptionalBoolean(draftSettings.suppress_webhooks_on_spam),
				currentFormActionConfig.suppress_webhooks_on_spam,
				currentActionDefaults.suppress_webhooks_on_spam
			],
			true
		);
	});
	const effectiveSuppressWebhooksOnSpamSource = $derived.by(() =>
		resolveInheritableBooleanSource(
			[
				{
					level: 'mapping',
					value: normalizeOptionalBoolean(draftSettings.suppress_webhooks_on_spam)
				},
				{ level: 'form', value: currentFormActionConfig.suppress_webhooks_on_spam },
				{ level: 'action', value: currentActionDefaults.suppress_webhooks_on_spam }
			],
			'spam default'
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
				{
					level: 'mapping',
					value: normalizeOptionalBoolean(draftSettings.skip_downstream_on_spam)
				},
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
			if (!linkage || !isSpamActionCode(linkage.central_action_id)) continue;
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
				eligibleUpstreamSpamTriggers.map(({ hook }) => hookOptions[hook] ?? hook ?? 'Unknown hook')
			)
		);
		const uniqueMappingLabels = Array.from(
			new Set(eligibleUpstreamSpamTriggers.map(({ mapping }) => friendlyActionLabel(mapping)))
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
		const noteDisplay = formatSpamResultDisplayMode(draftSettings.spam_result_display_mode);
		const displayMode = normalizeSpamIndicatorsDisplay(draftSettings.spam_indicators_display);
		const notificationPolicy = isBlockingSpamMapping
			? effectiveSuppressNotificationsOnSpam
				? 'suppress notifications'
				: 'allow notifications'
			: 'background notifications';
		const webhookPolicy = effectiveSuppressWebhooksOnSpam ? 'suppress Webhooks' : 'allow Webhooks';
		const downstreamPolicy = effectiveSkipDownstreamOnSpam ? 'skip downstream' : 'allow downstream';
		return `Threshold ${threshold} · ${noteDisplay} · ${displayMode} indicators · ${notificationPolicy} · ${webhookPolicy} · ${downstreamPolicy}`;
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
	const realtimeSummary = $derived.by(() => {
		if (!isRealtimeDraft) return 'Disabled';
		return summarizeRealtimeSettings(realtimeSettings);
	});
	const conditionsSummary = $derived.by(() => {
		const conditions = (draftSettings.conditions ?? createDefaultConditionConfig()) as Record<
			string,
			unknown
		>;
		const enabled = conditions.enabled === true;
		if (!enabled) return 'Disabled';
		const root = conditions.root as Record<string, unknown> | undefined;
		const ruleCount = countConditionRules(root);
		return `${ruleCount} rule${ruleCount === 1 ? '' : 's'} active`;
	});
	const modelExecutionSummary = $derived.by(() => {
		const selection = effectiveMappingModelSelection;
		const executionMode = isRealtimeDraft
			? 'Realtime'
			: effectiveDraftExecutionKind === 'background'
				? 'Background'
				: effectiveDraftExecutionKind === 'mixed'
					? 'Mixed hooks'
					: 'Blocking';
		const model = formatModelSelectionPrimary(selection);
		const source =
			effectiveMappingModelSource === 'system' ? 'platform' : effectiveMappingModelSource;
		const reasoning = selection.reasoning ? ` · reasoning ${selection.reasoning}` : '';
		return `${executionMode} · ${model} · ${source}${reasoning}`;
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

	const builtInDefinitions = $derived(
		definitions.filter((definition) => (definition.source ?? 'bundled') === 'bundled')
	);
	const hasDefinitions = $derived(builtInDefinitions.length > 0);
	const hasBuiltInDefinitions = $derived(builtInDefinitions.length > 0);

	const selectedDefinition = $derived(
		selectedTemplateId ? definitionLookup[selectedTemplateId] : undefined
	);
	const selectedCustomAction = $derived(
		selectedCustomId ? (customLookupById[selectedCustomId] ?? null) : null
	);
	const routeFormSourceSlug = $derived(encodeURIComponent(data.formSourceSlug));
	const routeFormId = $derived(encodeURIComponent(data.formId));
	const currentFormTitle = $derived(currentFormSummary?.title?.trim() || `Form #${data.formId}`);
	const showLeadScoringLink = $derived(data.formSourceSlug !== 'elementor_forms');
	const submissionLedgerSettings = $derived(actionsState.bootstrap?.ledger_settings ?? null);
	const submissionLedgerEnabled = $derived(submissionLedgerSettings?.enabled === true);
	const submissionLedgerSaving = $derived(actionsState.submissionLedgerSaving === true);
	const submissionLedgerRequired = $derived(
		formSourceDescriptor?.ledger?.required_for_parity === true ||
			Object.values(formSourceDescriptor?.lifecycles ?? {}).some(
				(lifecycle) => lifecycle.requires_ledger === true
			)
	);
	const submissionLedgerRecordCount = $derived(submissionLedgerSettings?.record_count ?? 0);
	const submissionLedgerDetailHref = $derived(
		appHref(`/actions/${routeFormSourceSlug}/${routeFormId}/submissions`)
	);
	const submissionLedgerDescription = $derived.by(() => {
		if (submissionLedgerEnabled) {
			return 'Logical field snapshots are stored for this form.';
		}

		if (submissionLedgerRequired && data.formSourceSlug === 'contact_form_7') {
			return 'Contact Form 7 submissions need Sentient Forms Submission Ledger storage before after-submission actions can run with stored submission context.';
		}

		if (submissionLedgerRequired) {
			return 'Ledger-required review features stay unavailable until storage is enabled.';
		}

		return 'Logical field snapshots are not stored until enabled.';
	});
	const wpformsNativeEntryLinksAvailable = $derived(
		data.formSourceSlug === 'wpforms' && formSourceDescriptor?.native_entry?.link === true
	);
	const submissionLedgerProviderNote = $derived.by(() => {
		if (data.formSourceSlug !== 'wpforms') {
			return null;
		}

		if (wpformsNativeEntryLinksAvailable) {
			return 'WPForms paid entry storage is detected; native entry links will be attached when WPForms provides a non-zero entry ID. Sentient Forms still keeps ledger snapshots for action context.';
		}

		return 'WPForms Lite/no-native-entry submissions use Sentient Forms Submission Ledger records instead of native WPForms entry links.';
	});
	const sectionDescription = $derived(`Link actions and execution settings for ${currentFormTitle}.`);
	const entryLookupHelpText = $derived(
		data.formSourceSlug === 'gravity_forms'
			? 'Use an entry ID from the Sentient Forms Action Log for this Gravity Forms form, not the Gravity Forms submission ID.'
			: `Use a Sentient Forms Action Log entry ID for this ${currentFormAdapterLabel} form. Native provider submission IDs are not used for this check.`
	);
	const uploadSourceModeLabel = $derived(
		data.formSourceSlug === 'gravity_forms'
			? 'Gravity Forms uploads'
			: `${currentFormAdapterLabel} uploads`
	);
	const mixedUploadSourceModeLabel = $derived(
		data.formSourceSlug === 'gravity_forms'
			? 'Mixed (uploads + media)'
			: `Mixed (${currentFormAdapterLabel} uploads + media)`
	);
	const selectedCreateActionLabel = $derived.by(() => {
		if (createKind === 'template') {
			return selectedDefinition?.label ?? selectedTemplateId ?? 'Built-in action';
		}

		if (createKind === 'custom') {
			return (
				selectedCustomAction?.display_name ??
				selectedCustomAction?.code ??
				selectedCustomId ??
				'Custom action'
			);
		}

		return localBuilderActionName.trim() || localBuilderTemplate.label;
	});
	const selectedCreateActionSummary = $derived.by(() => {
		const hooks = [...selectedHooks].map((hook) => hookOptions[hook] ?? hook);
		return hooks.length > 0 ? hooks.join(', ') : 'No trigger selected';
	});
	const linkActionDisabled = $derived(
		creating ||
			!canConfigureFormSource ||
			selectedHooks.size === 0 ||
			(!hasDefinitions && createKind === 'template') ||
			(createKind === 'custom' && customActions.length === 0) ||
			(createKind === 'local_openrouter' && !selectedLocalBuilderCredential)
	);
	const selectedActionKey = $derived(
		`${createKind}:${
			createKind === 'template'
				? selectedTemplateId
				: createKind === 'custom'
					? selectedCustomId
					: `${localBuilderTemplateKey}:${localBuilderExecutionMode}`
		}`
	);

	let lastPresetKey = $state<string | null>(null);
	const LAST_HOOKS_KEY = 'sentient_forms_last_hooks';
	$effect(() => {
		if (!localBuilderSupportsSync && localBuilderExecutionMode === 'sync') {
			localBuilderExecutionMode = 'async';
		}
	});

	$effect(() => {
		if (!selectedActionKey || selectedActionKey === lastPresetKey) return;
		const presetHooks =
			createKind === 'template'
				? defaultDefinitionHooks(selectedDefinition)
				: createKind === 'local_openrouter'
					? defaultLocalBuilderHooks()
					: defaultAvailableHookKeys().slice(0, 1);
		const actionId = createKind === 'template' ? selectedTemplateId : null;
		const normalized = sanitizeHooksForAction(
			presetHooks.length > 0 ? presetHooks : defaultAvailableHookKeys().slice(0, 1),
			actionId
		);
		selectedHooks = new Set(normalized);
		lastPresetKey = selectedActionKey;
	});

	$effect(() => {
		const selectedStillAvailable = readyOpenRouterCredentials.some(
			(credential) => String(credential.id) === localBuilderCredentialId
		);

		if (readyOpenRouterCredentials.length === 0) {
			localBuilderCredentialId = '';
			return;
		}

		if (!localBuilderCredentialId || !selectedStillAvailable) {
			localBuilderCredentialId = String(readyOpenRouterCredentials[0].id);
		}
	});

	$effect(() => {
		const selectedStillAvailable = builtInDefinitions.some(
			(definition) => definition.id === selectedTemplateId
		);

		if ((!selectedTemplateId || !selectedStillAvailable) && hasDefinitions) {
			selectedTemplateId = builtInDefinitions[0]?.id ?? '';
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
		// Keep the drawer selection aligned with the selected action's supported hooks.
		if (!showAddPanel) return;

		const allowedHooks = new Set(createHookEntries.map(([hookKey]) => hookKey));
		const nextHooks = new Set([...selectedHooks].filter((hookKey) => allowedHooks.has(hookKey)));
		if (nextHooks.size === 0) {
			const firstHook = createHookEntries[0]?.[0] ?? 'gform_validation';
			nextHooks.add(firstHook);
		}

		if (
			nextHooks.size !== selectedHooks.size ||
			[...nextHooks].some((hookKey) => !selectedHooks.has(hookKey))
		) {
			selectedHooks = nextHooks;
			persistLastHooks([...nextHooks]);
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
		if (createKind === 'template') return;

		try {
			const raw = localStorage.getItem(LAST_HOOKS_KEY);
			if (!raw) return;
			const parsed = JSON.parse(raw);
			if (Array.isArray(parsed) && parsed.every((h) => typeof h === 'string')) {
				selectedHooks = new Set(sanitizeHooksForAction(parsed, selectedCreateActionId));
			}
		} catch (err) {
			console.warn('Could not restore hooks', err);
		}
	}

	function isExecutionStatusActive(status: FormExecutionStatus | null | undefined): boolean {
		const state = String(status?.status ?? '').toLowerCase().trim();
		if (ACTIVE_STATUS_TERMS.includes(state)) return true;

		const message = String(status?.message ?? '').toLowerCase();
		return ACTIVE_STATUS_PATTERNS.some((pattern) => pattern.test(message));
	}

	function isExecutionStatusRecent(status: FormExecutionStatus | null | undefined): boolean {
		if (!status?.updated_at) return false;

		const updatedAt = Date.parse(status.updated_at);
		if (Number.isNaN(updatedAt)) return false;

		return Date.now() - updatedAt <= STATUS_RECENT_WINDOW_MS;
	}

	function shouldPollExecutionStatus(): boolean {
		if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return false;
		return actionsState.supportsStatus;
	}

	function getExecutionStatusRefreshIntervalMs(): number {
		const status = actionsState.status;
		return isExecutionStatusActive(status) || isExecutionStatusRecent(status)
			? STATUS_REFRESH_INTERVAL_MS
			: STATUS_IDLE_REFRESH_INTERVAL_MS;
	}

	function startRefreshInterval() {
		if (!shouldPollExecutionStatus()) return;
		const nextDelay = getExecutionStatusRefreshIntervalMs();
		if (refreshInterval !== null && refreshIntervalDelay === nextDelay) return;
		stopRefreshInterval();
		refreshIntervalDelay = nextDelay;
		refreshInterval = window.setInterval(
			() => formActionsStore.refresh(data.formSourceSlug, data.formId),
			nextDelay
		);
	}

	function stopRefreshInterval() {
		if (refreshInterval !== null) {
			window.clearInterval(refreshInterval);
			refreshInterval = null;
		}
		refreshIntervalDelay = null;
	}

	function syncRefreshInterval() {
		if (shouldPollExecutionStatus()) {
			startRefreshInterval();
		} else {
			stopRefreshInterval();
		}
	}

	onMount(() => {
		formActionsStore.load(data.formSourceSlug, data.formId);
		restoreLastHooks();

		visibilityHandler = () => {
			if (document.visibilityState === 'hidden') {
				stopRefreshInterval();
			} else if (shouldPollExecutionStatus()) {
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
		actionsState.bootstrap;
		hydrateFormDetailBootstrap();
	});

	$effect(() => {
		actionsState.status;
		actionsState.supportsStatus;
		syncRefreshInterval();
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

	$effect(() => {
		definitions;
		customActions;
		if (actionsState.loading) return;
		const bootstrap = actionsState.bootstrap;
		if (!bootstrap || appliedBootstrapKey !== formDetailBootstrapKey(bootstrap)) return;
		preloadActionDefaultsForVisibleActions();
	});

	function normalizeDefinitionHooks(hooks?: Record<string, string> | string[]): string[] {
		if (!hooks) return [];
		return Array.isArray(hooks) ? hooks : Object.keys(hooks);
	}

	function defaultDefinitionHooks(definition?: ActionDefinition): string[] {
		if (!definition) return [];
		if (definition.id === 'spam_detection_v1' || definition.id === 'content_validation_v1') {
			return ['gform_validation'];
		}
		if (definition.id === 'entry_summary_v1') {
			return ['gform_after_submission'];
		}
		if (definition.id === 'clarification_assistant_v1') {
			return ['real_time'];
		}
		return normalizeDefinitionHooks(definition.hooks);
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
							'Sentient Forms could not execute the last managed submission because this license is out of credits. Open Managed Service to review billing before retrying.',
						actions: [
							{ id: 'licensing', label: 'Open Managed Service', variant: 'primary' },
							{ id: 'refresh', label: 'Refresh status' }
						]
					};
				case 'cps_missing_proxy_key':
					return {
						variant: 'warning',
						title: 'Provider credential required',
						description:
							'The selected managed execution route needs a Sentient Forms site credential. Switch this action to Direct OpenRouter or connect managed execution, then retry.',
						actions: [
							{ id: 'licensing', label: 'Open Managed Service', variant: 'primary' },
							{ id: 'refresh', label: 'Refresh status' }
						]
					};
				case 'timeout':
					return {
						variant: 'danger',
						title: 'Execution timed out',
						description:
							'Sentient Forms timed out while contacting the execution service. Retry the request shortly. If timeouts persist, inspect your network connectivity.',
						actions: [{ id: 'refresh', label: 'Retry now', variant: 'primary' }]
					};
				case 'rate_limited':
					return {
						variant: 'warning',
						title: 'Rate limited',
						description:
							'The execution service temporarily rate limited this action. Wait about a minute before retrying.',
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
							'The linked action no longer exists or is misconfigured. Edit the action mapping to point at a valid action before retrying.',
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
					'The most recent submission completed successfully. You can refresh the status panel or run another test.',
				actions: [{ id: 'refresh', label: 'Refresh status' }]
			};
		}

		return null;
	};

	const statusAdvice = $derived(deriveStatusAdvice(actionsState.status));

	const formatBaseCreditCost = (definition: ActionDefinition) =>
		typeof definition.baseCreditCost === 'number' ? `${definition.baseCreditCost}` : '—';
	function formatActionModelSummary(
		actionId: string,
		modelHint: string | null | undefined
	): string {
		const formSelection = formLevelConfigByActionId[actionId]?.model_selection;
		if (formSelection) {
			return `Form default: ${formatModelSelectionPrimary(formSelection)}`;
		}

		const actionSelection = actionDefaultsByActionId[actionId]?.model_selection;
		if (actionSelection) {
			return `Global default: ${formatModelSelectionPrimary(actionSelection)}`;
		}

		return `Default: ${formatTemplateModelHint(modelHint)}`;
	}

	const formatModelHint = (definition: ActionDefinition) =>
		formatActionModelSummary(definition.id, definition.modelHint ?? null);
	function invalidHooksForLinkage(linkage: FormActionLinkage): string[] {
		const triggerHooks = normalizeHookIds(getMappingTriggerHooks(linkage));
		const triggerSources = getMappingTriggerSources(linkage);
		return triggerHooks.filter((hook) => triggerSources[hook]?.type === 'unbound');
	}

	function isLinkageInvalid(linkage: FormActionLinkage): boolean {
		return invalidHooksForLinkage(linkage).length > 0;
	}

	function statusVariant(linkage: FormActionLinkage) {
		if (isLinkageInvalid(linkage) || linkageNeedsRepair(linkage)) return 'danger';
		return linkage.is_action_enabled_for_form === false ? 'warning' : 'success';
	}

	function statusLabel(linkage: FormActionLinkage) {
		if (isLinkageInvalid(linkage)) return 'Invalid';
		if (linkageNeedsRepair(linkage)) return 'Needs repair';
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

	function actionTypeLabel(linkage: FormActionLinkage): string {
		if (linkage.action_type_indicator === 'local_first') {
			return definitionLookup[linkage.central_action_id] ? 'Built-in action' : 'Direct OpenRouter';
		}
		if (linkage.action_type_indicator === 'custom') return 'Custom';
		return 'Action template';
	}

	function actionTypeVariant(linkage: FormActionLinkage): 'neutral' | 'info' | 'success' {
		if (linkage.action_type_indicator === 'local_first') return 'success';
		if (linkage.action_type_indicator === 'custom') return 'info';
		return 'neutral';
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
		return isSpamActionCode(linkage?.central_action_id);
	}

	function resetMappingSectionExpansion(linkage: FormActionLinkage | null | undefined) {
		const next = createInitialMappingModalSectionExpansion(isSpamMappingLinkage(linkage));
		const hooks = linkage ? normalizeHookIds(getMappingTriggerHooks(linkage)) : [];
		const settings = linkage?.settings ?? {};
		next.realtime = hooks.includes('real_time') || settings.execution_mode === 'real_time';
		mappingSectionExpansion = next;
	}

	function toggleMappingSection(sectionId: MappingModalSectionId) {
		mappingSectionExpansion = toggleMappingModalSectionExpansion(
			mappingSectionExpansion,
			sectionId
		);
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
					.map((hook) => (formSourceDescriptor && hook ? (lifecycleIdForHook(hook) ?? hook) : hook))
					.filter((hook): hook is string => Boolean(hook))
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
		if (hook === 'real_time' && !isRealtimeEligibleActionId(selectedCreateActionId)) {
			notifications.warning(
				'Realtime triggers are only available for Realtime Clarification Assistant.'
			);
			return;
		}
		const next = new Set(selectedHooks);
		next.has(hook) ? next.delete(hook) : next.add(hook);
		const sanitized = sanitizeHooksForAction(next, selectedCreateActionId);
		selectedHooks = new Set(sanitized);
		persistLastHooks(sanitized);
	}

	function toggleCreateDependencySelection(mappingId: string) {
		if (selectedCreateDependencyIds.has(mappingId)) {
			selectedCreateDependencyIds = new Set();
			return;
		}
		selectedCreateDependencyIds = new Set([mappingId]);
	}

	function openAddActionPanel() {
		if (!canConfigureFormSource) return;

		selectedCreateDependencyIds = new Set();
		createError = null;
		localBuilderResult = null;
		showAddPanel = true;
	}

	function normalizeLocalBuilderActionCode(value: string): string {
		const normalized = value
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '')
			.slice(0, 48);

		return normalized.length > 0 ? normalized : 'local_openrouter_action';
	}

	function isSafeLocalMetaKey(value: string): boolean {
		return /^[A-Za-z0-9_:-]+$/.test(value);
	}

	function handleLocalBuilderModelSelectionChange(selection: ModelSelection) {
		localBuilderModelSelection = selection;
	}

	function applyLocalBuilderTemplate(key: LocalBuilderTemplateKey) {
		const template = sourceAwareLocalBuilderTemplate(
			LOCAL_BUILDER_TEMPLATES[key],
			currentFormAdapterLabel,
			supportsSpamNoteControls
		);
		const templateSpamNote =
			template.effectMapping &&
			typeof template.effectMapping.spam === 'object' &&
			template.effectMapping.spam &&
			typeof (template.effectMapping.spam as Record<string, unknown>).note === 'object'
				? ((template.effectMapping.spam as Record<string, unknown>).note as Record<string, unknown>)
				: null;
		localBuilderTemplateKey = key;
		localBuilderActionName = template.actionName;
		localBuilderSystemPrompt = template.systemPrompt;
		localBuilderPromptTemplate = template.promptTemplate;
		localBuilderResultMetaKey = template.resultMetaKey;
		localBuilderExecutionMode = normalizeLocalBuilderExecutionMode(
			template.defaultExecutionMode ?? 'async'
		);
		localBuilderSpamResultDisplayMode = normalizeSpamResultDisplayMode(
			templateSpamNote?.result_display_mode,
			'spam_only'
		);
		localBuilderSpamIndicatorsDisplay = normalizeSpamIndicatorsDisplay(
			templateSpamNote?.indicators_display,
			'simple'
		);
		localBuilderResult = null;
	}

	function localBuilderStructuredOutputSchema(): Record<string, unknown> {
		return localBuilderTemplate.structuredOutputSchema;
	}

	async function resolveLocalBuilderModelSelection(): Promise<ResolvedModelSelection> {
		const response = await wpFetch<ResolvedModelSelection | RestEnvelope<ResolvedModelSelection>>(
			'models/resolve',
			{
				method: 'POST',
				body: {
					action_selection: localBuilderModelSelection,
					template_model_hint: 'openrouter/auto'
				},
				showNotifications: false
			}
		);
		const resolved = unwrapRestResponse<ResolvedModelSelection>(response);

		if (!resolved?.model_id) {
			throw new Error('Local model policy did not return a usable OpenRouter model.');
		}

		return resolved;
	}

	async function createDirectOpenRouterAction(hooks: string[]): Promise<void> {
		const credential = selectedLocalBuilderCredential;
		const actionName = localBuilderActionName.trim() || 'Local OpenRouter summary';
		const promptTemplate = localBuilderPromptTemplate.trim();
		const systemPrompt = localBuilderSystemPrompt.trim();
		const resultMetaKey = localBuilderResultMetaKey.trim();

		if (!credential) {
			createError =
				'Save and validate an OpenRouter key before creating a Direct OpenRouter action.';
			return;
		}

		if (promptTemplate.length === 0) {
			createError = 'Enter a prompt template for the Direct OpenRouter action.';
			return;
		}

		if (!isSafeLocalMetaKey(resultMetaKey)) {
			createError = 'Use letters, numbers, underscores, colons, or dashes for the result meta key.';
			return;
		}

		const resolvedModel = await resolveLocalBuilderModelSelection();
		const timestamp = Date.now();
		const action = await providerClient.createLocalCustomAction(
			{
				code: `${normalizeLocalBuilderActionCode(actionName)}_${timestamp}`,
				display_name: actionName,
				definition_json: {
					...(systemPrompt ? { system_prompt: systemPrompt } : {}),
					prompt_template: promptTemplate,
					structured_output_schema: localBuilderStructuredOutputSchema(),
					builder_template: localBuilderTemplate.key,
					max_tokens: localBuilderTemplate.maxTokens,
					temperature: localBuilderTemplate.temperature
				},
				model_selection_json: {
					provider: 'openrouter',
					model: resolvedModel.model_id,
					credential_id: credential.id,
					selection: localBuilderModelSelection,
					resolution_source: resolvedModel.resolution_source,
					policy_hint: 'local_models_resolve'
				},
				status: 'active'
			},
			{ showNotifications: false }
		);

		const templateEffectMapping = localBuilderTemplate.effectMapping
			? structuredClone(localBuilderTemplate.effectMapping)
			: null;
		const meta =
			templateEffectMapping && typeof templateEffectMapping.meta === 'object'
				? (templateEffectMapping.meta as Record<string, string>)
				: {};
		meta[resultMetaKey] = `structured.${localBuilderTemplate.resultField}`;
		const effectMapping: Record<string, unknown> = {
			...(templateEffectMapping ?? {}),
			store_result: true,
			meta
		};
		if (localBuilderTemplate.key === 'spam_filter') {
			if (supportsSpamNoteControls) {
				const spamConfig =
					effectMapping.spam && typeof effectMapping.spam === 'object'
						? { ...(effectMapping.spam as Record<string, unknown>) }
						: {};
				effectMapping.spam = {
					...spamConfig,
					note: {
						result_display_mode: normalizeSpamResultDisplayMode(
							localBuilderSpamResultDisplayMode,
							'spam_only'
						),
						indicators_display: normalizeSpamIndicatorsDisplay(
							localBuilderSpamIndicatorsDisplay,
							'simple'
						)
					}
				};
			} else {
				delete effectMapping.spam;
			}
		}

		const mappings: LocalFormMappingRecord[] = [];
		for (const hook of hooks) {
			const mapping = await providerClient.createLocalFormMapping(
				{
					form_source: data.formSourceSlug,
					form_id: data.formId,
					hook,
					action_kind: 'custom_action',
					action_id: action.id,
					input_bindings_json: {},
					execution_mode: localBuilderExecutionMode,
					effect_mapping_json: effectMapping,
					enabled: true
				},
				{ showNotifications: false }
			);
			mappings.push(mapping);
		}

		localBuilderResult = { action, mappings };
		notifications.success('Local OpenRouter action and mapping created.');
		await formActionsStore.load(data.formSourceSlug, data.formId);
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

	async function startEditingAction(linkage: FormActionLinkage, openModal = true) {
		const draftSnapshot = readEffectiveDraftForMapping(linkage.local_mapping_id);
		const initialHooks =
			draftSnapshot.triggerHooks.length > 0
				? draftSnapshot.triggerHooks
				: [hookEntries[0]?.[0] ?? 'gform_validation'];
		draftHooks = new Set(initialHooks);
		const baseSettings = cloneDraftValue(linkage.settings ?? {});
		await Promise.allSettled([
			loadActionDefaultsForAction(linkage.central_action_id, { force: false }),
			loadFormLevelConfig(linkage.central_action_id, { openModal: false, force: false })
		]);
		const inheritedFormConfig =
			formLevelConfigByActionId[linkage.central_action_id] ?? createBlankFormActionConfig();
		const inheritedActionConfig =
			actionDefaultsByActionId[linkage.central_action_id] ?? createBlankFormActionConfig();
		const baseBatchSettings = isPlainObject(baseSettings.batch_settings)
			? baseSettings.batch_settings
			: {};
		const executionMode = deriveExecutionModeForHooks(
			initialHooks,
			baseSettings.execution_mode ?? linkage.execution_mode
		);
		const nextDraftSettings = {
			...baseSettings,
			spam_confidence_threshold: baseSettings.spam_confidence_threshold ?? 0.8,
			spam_result_display_mode: normalizeSpamResultDisplayMode(
				baseSettings.spam_result_display_mode ??
					inheritedFormConfig.spam_result_display_mode ??
					inheritedActionConfig.spam_result_display_mode,
				'all_results'
			),
			spam_indicators_display: normalizeSpamIndicatorsDisplay(
				baseSettings.spam_indicators_display ??
					inheritedFormConfig.spam_indicators_display ??
					inheritedActionConfig.spam_indicators_display,
				'simple'
			),
			include_site_context: baseSettings.include_site_context ?? 'global',
			spam_positive_examples: cloneDraftValue(baseSettings.spam_positive_examples ?? []),
			spam_negative_examples: cloneDraftValue(baseSettings.spam_negative_examples ?? []),
			// CB-EXEC-002: Execution mode - default to after_submission (async) for safety.
			execution_mode: executionMode,
			realtime_settings:
				executionMode === 'real_time'
					? resolveRealtimeSettingsChain(
							inheritedActionConfig,
							inheritedFormConfig,
							baseSettings.realtime_settings
						)
					: baseSettings.realtime_settings,
			// CB-EXEC-003/004: Batch settings with sensible defaults.
			batch_settings: sanitizeBatchSettings(baseBatchSettings),
			dependency_ids: cloneDraftValue(draftSnapshot.dependencyIds),
			trigger_sources: cloneDraftValue(draftSnapshot.triggerSources),
			conditions: cloneDraftValue(baseSettings.conditions ?? createDefaultConditionConfig())
		};
		draftSettings = nextDraftSettings;
		editingLinkageId = linkage.local_mapping_id;
		resetMappingSectionExpansion(linkage);
		showMappingConfigModal = openModal;
		editBaselineSignature = createDraftSignature(initialHooks, nextDraftSettings);
		clearRootAttachUndoState();
	}

	function cancelEditingAction() {
		const cancelledMappingId = editingLinkageId;
		editingLinkageId = null;
		showMappingConfigModal = false;
		draftHooks = new Set();
		draftSettings = {};
		if (cancelledMappingId && graphDraftByMappingId[cancelledMappingId]) {
			const nextDraftMap = { ...graphDraftByMappingId };
			delete nextDraftMap[cancelledMappingId];
			graphDraftByMappingId = nextDraftMap;
		}
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
		if (hook === 'real_time' && !isRealtimeEligibleActionId(editingActionId)) {
			notifications.warning(
				'Realtime triggers are only available for Realtime Clarification Assistant.'
			);
			return;
		}
		const next = new Set(draftHooks);
		next.has(hook) ? next.delete(hook) : next.add(hook);
		draftHooks = new Set(sanitizeHooksForAction(next, editingActionId));
		const nextHooks = normalizeHookIds(draftHooks);
		const nextSources = deriveTriggerSourcesForDraft(
			nextHooks,
			normalizeDependencyIds(draftSettings.dependency_ids),
			normalizeDraftTriggerSources(draftSettings.trigger_sources, nextHooks)
		);
		const executionMode = deriveExecutionModeForHooks(nextHooks, draftSettings.execution_mode);
		draftSettings = {
			...draftSettings,
			execution_mode: executionMode,
			realtime_settings:
				executionMode === 'real_time'
					? normalizeRealtimeSettings(draftSettings.realtime_settings)
					: draftSettings.realtime_settings,
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
		if (
			(normalizedHooks.includes('real_time') || draftSettings.execution_mode === 'real_time') &&
			!isRealtimeEligibleActionId(linkage.central_action_id)
		) {
			notifications.error(
				'Realtime triggers are only available for Realtime Clarification Assistant.'
			);
			return;
		}

		// Ensure types are correct for spam settings
		if (isSpamActionCode(linkage.central_action_id)) {
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
		const executionMode = deriveExecutionModeForHooks(
			normalizedHooks,
			draftSettings.execution_mode
		);
		const nextSettings: Record<string, unknown> = {
			...draftSettings,
			execution_mode: executionMode,
			dependency_ids: normalizedDependencyIds,
			trigger_sources: persistableTriggerSources
		};
		if (executionMode === 'real_time') {
			nextSettings.realtime_settings = normalizeRealtimeSettings(draftSettings.realtime_settings);
		} else {
			delete nextSettings.realtime_settings;
		}
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

		const hooks = sanitizeHooksForAction(selectedHooks, selectedCreateActionId);
		if (hooks.length === 0) {
			createError = 'Select at least one trigger hook.';
			return;
		}
		if (hooks.includes('real_time') && !isRealtimeEligibleActionId(selectedCreateActionId)) {
			createError = 'Realtime triggers are only available for Realtime Clarification Assistant.';
			return;
		}

		if (createKind === 'local_openrouter') {
			try {
				creating = true;
				await createDirectOpenRouterAction(hooks);
				if (!createError) {
					selectedCreateDependencyIds = new Set();
				}
			} catch (error) {
				createError =
					error instanceof Error ? error.message : 'Failed to create direct OpenRouter mapping';
			} finally {
				creating = false;
			}
			return;
		}

		const dependencyIds = normalizeDependencyIds(Array.from(selectedCreateDependencyIds));
		const primaryDependencyId = dependencyIds[0] ?? null;
		if (dependencyIds.length > 0) {
			const requiredHooks = normalizeHookIds(hooks);
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
				? (selectedDefinition ??
					builtInDefinitions.find((definition) => definition.id === selectedTemplateId) ??
					null)
				: null;
		const chosenCustom =
			createKind === 'custom'
				? (selectedCustomAction ??
					customActions.find((action) => action.id === selectedCustomId) ??
					null)
				: null;

		if (createKind === 'template' && !chosenDefinition) {
			createError = 'Select a built-in action to link.';
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
			const executionMode = deriveExecutionModeForHooks(hooks);
			const realtimeSettingsForCreate =
				executionMode === 'real_time'
					? resolveRealtimeSettingsChain(
							await loadActionDefaultsForAction(centralActionId, { force: false }),
							await loadFormLevelConfig(centralActionId, { openModal: false, force: false })
						)
					: null;
			await formActionsStore.create(data.formSourceSlug, data.formId, {
				central_action_id: centralActionId,
				action_type_indicator: createKind === 'template' ? 'master' : 'custom',
				trigger_hooks: hooks,
				action_name_label: label,
				settings: {
					execution_mode: executionMode,
					...(realtimeSettingsForCreate ? { realtime_settings: realtimeSettingsForCreate } : {}),
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
		formActionsStore.refresh(data.formSourceSlug, data.formId, { forceRefresh: true });
		loadCurrentFormSummary();
		loadProviderCredentials();
		loadFormActionConfigIndex();
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

			// Create a portable template mapping through the current API contract.
			// The API expects UUIDs for action_template_id, but string codes for action_template_code.
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

	async function toggleSubmissionLedger() {
		if (submissionLedgerSaving || !canConfigureFormSource) return;

		await formActionsStore.updateSubmissionLedgerSettings(
			data.formSourceSlug,
			data.formId,
			!submissionLedgerEnabled
		);
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

<Section
	heading="Actions"
	description={sectionDescription}
>
	<!-- Form-Level Action Config Modal - Inside Section slot for Svelte 5 reactivity -->
	{#if configuringActionId}
		<div
			class="sf-wp-modal-backdrop sf-wp-modal-backdrop--elevated sf:bg-black/40 sf:flex sf:items-center sf:justify-center sf:p-2 sf:sm:p-4"
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
							<div
								class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-2 sf:sm:flex-row sf:sm:items-center"
							>
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
								requiredCapabilities={getActionDefinitionContext(configuringActionId)
									.requiredCapabilities}
								lockRequiredCapabilities={getActionDefinitionContext(configuringActionId)
									.requiredCapabilities.length > 0}
								actionSelection={actionDefaultsByActionId[configuringActionId ?? '']
									?.model_selection ?? null}
								formSelection={formLevelConfig.model_selection ?? null}
								{providerCredentials}
								onchange={handleFormLevelModelSelectionChange}
							/>
						</div>

						<ActionCustomizationEditor
							id="form-level-customization"
							actionId={configuringActionId}
							level="form"
							bind:value={formLevelConfig.action_customization}
							inheritedValue={actionDefaultsByActionId[configuringActionId ?? '']
								?.action_customization ?? null}
							inheritanceSource={actionDefaultsByActionId[
								configuringActionId ?? ''
							]?.action_customization?.trim()
								? 'action'
								: null}
						/>

						{#if configuringActionId && isRealtimeEligibleActionId(configuringActionId)}
							<RealtimeSettingsEditor
								idPrefix="form-defaults"
								scope="form"
								value={formLevelConfig.realtime_settings}
								{formFields}
								storageFieldOptions={realtimeStorageFieldOptions}
								totalPages={realtimeTotalPages}
								onchange={(settings) => {
									formLevelConfig = {
										...formLevelConfig,
										realtime_settings: settings
									};
								}}
							/>
						{/if}

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
									id="form-level-spam-result-display"
									label="Spam note visibility"
									bind:value={formLevelConfig.spam_result_display_mode}
									options={SPAM_RESULT_DISPLAY_OPTIONS}
								/>
								<SelectField
									id="form-level-spam-indicators"
									label="Spam note detail"
									bind:value={formLevelConfig.spam_indicators_display}
									options={SPAM_INDICATORS_DISPLAY_OPTIONS}
									disabled={normalizeSpamResultDisplayMode(
										formLevelConfig.spam_result_display_mode
									) === 'none'}
								/>
							</div>

							<AlignedSelectGrid
								columns={3}
								items={[
									{
										id: 'form-level-spam-notifications',
										label: 'Spam notification policy',
										description:
											'Applies to Blocking spam mappings on this form. Background mappings still send notifications immediately.',
										value: getInheritableBooleanMode(
											formLevelConfig.suppress_notifications_on_spam
										),
										options: [
											{ value: 'inherit', label: 'Use global default' },
											{ value: 'enabled', label: 'Suppress notifications' },
											{ value: 'disabled', label: 'Allow notifications' }
										],
										onchange: (event) =>
											handleFormLevelSpamPolicyChange(
												'suppress_notifications_on_spam',
												event.currentTarget.value
											)
									},
									{
										id: 'form-level-spam-webhooks',
										label: 'Spam Webhooks policy',
										description:
											'Applies only when the Gravity Forms Webhooks add-on and feed replay APIs are available.',
										value: getInheritableBooleanMode(formLevelConfig.suppress_webhooks_on_spam),
										options: [
											{ value: 'inherit', label: 'Use global default' },
											{ value: 'enabled', label: 'Suppress Webhooks' },
											{ value: 'disabled', label: 'Allow Webhooks' }
										],
										onchange: (event) =>
											handleFormLevelSpamPolicyChange(
												'suppress_webhooks_on_spam',
												event.currentTarget.value
											)
									},
									{
										id: 'form-level-spam-downstream',
										label: 'Downstream spam gate',
										description:
											'Controls whether downstream work should stop when this form’s spam mapping confirms spam.',
										value: getInheritableBooleanMode(formLevelConfig.skip_downstream_on_spam),
										options: [
											{ value: 'inherit', label: 'Use global default' },
											{ value: 'enabled', label: 'Skip downstream actions' },
											{ value: 'disabled', label: 'Allow downstream actions' }
										],
										onchange: (event) =>
											handleFormLevelSpamPolicyChange(
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
								id="form-level-context"
								label="Include Site Context"
								bind:value={formLevelConfig.include_site_context}
								options={[
									{ value: 'global', label: 'Use global setting' },
									{ value: 'always', label: 'Always include' },
									{ value: 'never', label: 'Never include' }
								]}
							/>
							<SiteContextWarning includeMode={formLevelConfig.include_site_context} />
						</div>

						<p class="sf:text-xs sf:text-slate-500 sf:pt-1">
							Submission data is processed by AI. Site Context controls whether saved site details
							join the action prompt.
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
					: 'Sentient Forms execution is running for this form'}
			>
				<span class="sf:text-xs sf:font-medium sf:text-slate-600"
					>{actionsState.effectiveDisabled ? 'Paused' : 'Running'}</span
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
			<ButtonLink variant="secondary" href={appHref('/actions')}>All forms</ButtonLink>
			{#if showLeadScoringLink}
				<ButtonLink
					variant="secondary"
					href={appHref(`/actions/${routeFormSourceSlug}/${routeFormId}/lead-value`)}
				>
					Lead Scoring
				</ButtonLink>
			{/if}
			{#if providerEditUrl}
				<a
					href={providerEditUrl}
					class="sf:inline-flex sf:h-10 sf:items-center sf:justify-center sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-4 sf:text-sm sf:font-medium sf:text-slate-700 hover:sf:bg-slate-50 hover:sf:text-slate-900"
					data-sveltekit-reload
					rel="external"
					data-testid="actions-provider-edit-link"
				>
					{providerEditLinkLabel}
				</a>
			{/if}
			<Button variant="secondary" onclick={refresh}>Refresh</Button>
			<Button onclick={openAddActionPanel} disabled={!canConfigureFormSource}>Add action</Button>
			<Button
				variant="secondary"
				onclick={() => (showTemplateLibrary = true)}
				disabled={!canConfigureFormSource}
				>Import from Library</Button
			>
			<Button variant="secondary" onclick={checkEntryStatus}>Check Sentient Forms log entry</Button>
		</div>
	{/snippet}

	<div
		class="sf:flex sf:flex-col sf:gap-3 sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:px-4 sf:py-3 sf:shadow-sm sf:md:flex-row sf:md:items-center sf:md:justify-between"
		data-testid="form-context-band"
	>
		<div class="sf:min-w-0">
			<p class="sf:text-xs sf:font-medium sf:uppercase sf:tracking-wide sf:text-slate-500">
				Current form
			</p>
			<h3
				class="sf:mt-1 sf:break-words sf:text-lg sf:font-semibold sf:leading-6 sf:text-slate-900"
				data-testid="form-context-title"
			>
				{currentFormTitle}
			</h3>
			<div class="sf:mt-2 sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				<Badge variant="neutral">{currentFormAdapterLabel}</Badge>
				<Badge variant="neutral">Form #{data.formId}</Badge>
				<Badge variant={actionsState.effectiveDisabled ? 'warning' : 'success'}>
					{actionsState.effectiveDisabled ? 'Paused' : 'Running'}
				</Badge>
				{#if currentFormSummaryLoading}
					<Badge variant="neutral">Loading title</Badge>
				{/if}
				{#if currentFormSummaryError}
					<Badge variant="warning">Title unavailable</Badge>
				{/if}
			</div>
		</div>
		<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
			{#if providerEditUrl}
				<a
					href={providerEditUrl}
					class="sf:inline-flex sf:h-9 sf:items-center sf:justify-center sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:text-sm sf:font-medium sf:text-slate-700 hover:sf:bg-slate-50 hover:sf:text-slate-900"
					data-sveltekit-reload
					rel="external"
					data-testid="form-context-provider-edit-link"
				>
					{providerEditLinkLabel}
				</a>
			{/if}
			<Button size="sm" onclick={openAddActionPanel} disabled={!canConfigureFormSource}
				>Add action</Button
			>
		</div>
	</div>

	{#if formSourceUnavailable}
		<Alert
			variant="warning"
			class="sf:mt-2"
			data-testid="form-source-availability-alert"
		>
			<div class="sf:flex sf:flex-col sf:gap-2 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between">
				<div>
					<p class="sf:font-medium">{currentFormAdapterLabel} is unavailable</p>
					<p class="sf:mt-1 sf:text-sm">{formSourceAvailabilityMessage}</p>
				</div>
				{#if formSourceAvailability === 'requires_pro' || formSourceDescriptor?.requires_pro === true}
					<Badge variant="warning">Requires Pro</Badge>
				{/if}
			</div>
		</Alert>
	{/if}

	{#if formSourceLimitationMessages.length > 0}
		<Alert
			variant="info"
			class="sf:mt-2"
			data-testid="form-source-limitations-alert"
		>
			<div class="sf:flex sf:flex-col sf:gap-2">
				<p class="sf:font-medium">{currentFormAdapterLabel} capability limits</p>
				<ul class="sf:list-disc sf:space-y-1 sf:pl-4 sf:text-sm">
					{#each formSourceLimitationMessages as message}
						<li>{message}</li>
					{/each}
				</ul>
			</div>
		</Alert>
	{/if}

	<div
		class="sf:mt-2 sf:flex sf:flex-col sf:gap-3 sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-4 sf:py-3 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between"
		data-testid="submission-ledger-affordance"
	>
		<div class="sf:min-w-0">
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				<p class="sf:text-sm sf:font-medium sf:text-slate-800">Submission Ledger</p>
				<Badge variant={submissionLedgerEnabled ? 'success' : 'neutral'}>
					{submissionLedgerEnabled ? 'On' : 'Off'}
				</Badge>
				{#if submissionLedgerRequired && !submissionLedgerEnabled}
					<Badge variant="warning">Required for parity</Badge>
				{/if}
				{#if submissionLedgerEnabled}
					<span class="sf:text-xs sf:text-slate-500">
						{submissionLedgerRecordCount.toLocaleString()} stored
					</span>
				{/if}
			</div>
			<p class="sf:mt-1 sf:text-xs sf:text-slate-600">
				{submissionLedgerDescription}
			</p>
			{#if submissionLedgerProviderNote}
				<p class="sf:mt-1 sf:text-xs sf:text-slate-600" data-testid="submission-ledger-provider-note">
					{submissionLedgerProviderNote}
				</p>
			{/if}
		</div>
		<div class="sf:flex sf:shrink-0 sf:flex-wrap sf:items-center sf:gap-2">
			<Toggle
				checked={submissionLedgerEnabled}
				disabled={submissionLedgerSaving || !canConfigureFormSource}
				onchange={toggleSubmissionLedger}
				label="Store snapshots"
				data-testid="submission-ledger-toggle"
			/>
			<ButtonLink
				size="sm"
				variant="secondary"
				href={submissionLedgerDetailHref}
				disabled={!submissionLedgerEnabled}
				data-testid="submission-ledger-view-submissions"
			>
				View submissions
			</ButtonLink>
		</div>
	</div>

	<!-- CB-FORMS-001: Warning banner when form is disabled -->
	{#if actionsState.effectiveDisabled}
		<Alert variant="warning">
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center"
			>
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

	{#if providerCredentialsLoading || providerCredentialsError || openRouterHealth.status !== 'ready'}
		<Alert
			variant={providerCredentialsLoading ? 'info' : 'warning'}
			class="sf:mt-3"
			data-testid="form-openrouter-health"
		>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center"
			>
				<div>
					<p class="sf:font-medium">
						{providerCredentialsLoading ? 'Checking OpenRouter status' : openRouterHealth.title}
					</p>
					<p class="sf:text-sm sf:mt-1">
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
					<ButtonLink size="sm" variant="secondary" href={appHref('/providers')}>
						Review OpenRouter
					</ButtonLink>
				</div>
			</div>
		</Alert>
	{/if}

	<div class="sf:grid sf:gap-4 sf:xl:grid-cols-3">
		<Card class="sf:hidden" data-testid="action-definitions-card-legacy-hidden" aria-hidden="true">
			<div
				class="sf:flex sf:flex-col sf:gap-3 sf:md:flex-row sf:md:items-center sf:md:justify-between"
			>
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Action library</p>
					<p class="sf:text-xs sf:text-slate-500 sf:mt-1">
						Use Add action to map an action to this form. Browse the catalog only when you need to
						inspect available defaults.
					</p>
				</div>
			</div>

			<details class="sf:mt-3 sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3">
				<summary class="sf:cursor-pointer sf:text-sm sf:font-medium sf:text-slate-700">
					Browse {builtInDefinitions.length} built-in and {customActions.length} custom actions
				</summary>

				{#if !hasDefinitions}
					<Alert variant="warning" class="sf:mt-3">
						Built-in actions are unavailable right now. You can still link custom actions below.
					</Alert>
				{/if}

				<div class="sf:mt-4 sf:grid sf:gap-3 sf:lg:grid-cols-2">
					<div class="sf:rounded sf:border sf:border-dashed sf:border-slate-200 sf:p-3">
						<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500 sf:mb-2">
							Built-in actions
						</p>
						{#if !hasBuiltInDefinitions}
							<p class="sf:text-sm sf:text-slate-600">No built-in actions available.</p>
						{:else}
							<ul class="sf:space-y-2">
								{#each builtInDefinitions.slice(0, 5) as definition (definition.id)}
									<li class="sf:flex sf:min-w-0 sf:items-start sf:justify-between sf:gap-3">
										<div class="sf:min-w-0 sf:flex-1">
											<p class="sf:break-words sf:text-sm sf:font-semibold sf:text-slate-800">
												{definition.label ?? definition.id}
											</p>
											<p class="sf:text-xs sf:text-slate-500">
												Hooks: {summarizeDefinitionHooks(definition.hooks)}
											</p>
											<p class="sf:break-words sf:text-xs sf:text-slate-500">
												Base credits: {formatBaseCreditCost(definition)} · Model: {formatModelHint(
													definition
												)}
											</p>
										</div>
										<Button
											size="sm"
											variant="ghost"
											onclick={() => loadFormLevelConfig(definition.id)}
											disabled={formLevelConfigLoading}
										>
											Defaults
										</Button>
									</li>
								{/each}
							</ul>
						{/if}
					</div>

					<div class="sf:rounded sf:border sf:border-dashed sf:border-slate-200 sf:p-3">
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
									<li class="sf:flex sf:min-w-0 sf:items-start sf:justify-between sf:gap-3">
										<div class="sf:min-w-0 sf:flex-1">
											<p class="sf:break-words sf:text-sm sf:font-semibold sf:text-slate-800">
												{action.display_name}
											</p>
											<p class="sf:break-all sf:text-xs sf:text-slate-500">Code: {action.code}</p>
										</div>
										<div class="sf:flex sf:shrink-0 sf:items-center sf:gap-2">
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
					</div>
				</div>
			</details>

			<div
				class="sf:mt-6 sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center"
			>
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Link actions to this form</p>
					<p class="sf:text-xs sf:text-slate-500">
						Choose an action template or custom action, then select hooks.
					</p>
				</div>
				<Button size="sm" onclick={openAddActionPanel} disabled={!canConfigureFormSource}
					>Add action</Button
				>
			</div>
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
		<div
			class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:mb-3"
		>
			<div>
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">
					{linkedActionsView === 'graph' ? 'Action Execution Order' : 'Linked actions'}
				</p>
				<p class="sf:text-xs sf:text-slate-500">
					{linkedActionsView === 'graph'
						? 'Review and edit the order actions run for this form.'
						: 'Enable, disable, or retarget hooks for actions connected to this form.'}
				</p>
			</div>
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
						Execution order
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
				<Button size="sm" onclick={openAddActionPanel} disabled={!canConfigureFormSource}
					>Add action</Button
				>
				<Button variant="secondary" size="sm" onclick={refresh}>Refresh</Button>
			</div>
		</div>

		<details
			class="sf:mb-3 sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3"
			data-testid="action-definitions-card"
		>
			<summary class="sf:cursor-pointer sf:text-sm sf:font-medium sf:text-slate-800">
				Action defaults and library
			</summary>
			<div class="sf:mt-3 sf:grid sf:gap-3 sf:lg:grid-cols-2">
				<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3">
					<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
						<div>
							<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500">
								Built-in actions
							</p>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								Configure form-level defaults without moving the execution-order graph.
							</p>
						</div>
						<Badge variant="neutral">{builtInDefinitions.length}</Badge>
					</div>
					{#if !hasBuiltInDefinitions}
						<p class="sf:mt-3 sf:text-sm sf:text-slate-600">No built-in actions available.</p>
					{:else}
						<ul class="sf:mt-3 sf:grid sf:gap-2 sf:md:grid-cols-2">
							{#each builtInDefinitions as definition (definition.id)}
								<li
									class="sf:flex sf:min-w-0 sf:items-center sf:justify-between sf:gap-2 sf:rounded sf:border sf:border-slate-100 sf:p-2"
								>
									<div class="sf:min-w-0">
										<p class="sf:truncate sf:text-sm sf:font-medium sf:text-slate-800">
											{definition.label ?? definition.id}
										</p>
										<p class="sf:truncate sf:text-xs sf:text-slate-500">
											{formatModelHint(definition)}
										</p>
									</div>
									<Button
										size="sm"
										variant="ghost"
										onclick={() => loadFormLevelConfig(definition.id)}
										disabled={formLevelConfigLoading}
									>
										Defaults
									</Button>
								</li>
							{/each}
						</ul>
					{/if}
				</div>
				<div class="sf:rounded sf:border sf:border-slate-200 sf:bg-white sf:p-3">
					<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
						<div>
							<p class="sf:text-xs sf:uppercase sf:tracking-wide sf:text-slate-500">
								Custom actions
							</p>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								Edit defaults for active custom actions, or open the custom action library.
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
					{#if customActions.length === 0}
						<p class="sf:mt-3 sf:text-sm sf:text-slate-600">No active custom actions.</p>
					{:else}
						<ul class="sf:mt-3 sf:grid sf:gap-2 sf:md:grid-cols-2">
							{#each customActions as action (action.id)}
								<li
									class="sf:flex sf:min-w-0 sf:items-center sf:justify-between sf:gap-2 sf:rounded sf:border sf:border-slate-100 sf:p-2"
								>
									<div class="sf:min-w-0">
										<p class="sf:truncate sf:text-sm sf:font-medium sf:text-slate-800">
											{action.display_name}
										</p>
										<p class="sf:truncate sf:text-xs sf:text-slate-500">{action.code}</p>
									</div>
									<Button
										size="sm"
										variant="ghost"
										onclick={() => loadFormLevelConfig(action.code)}
										disabled={formLevelConfigLoading}
									>
										Defaults
									</Button>
								</li>
							{/each}
						</ul>
					{/if}
				</div>
			</div>
		</details>

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
				message={canConfigureFormSource
					? 'Add an action mapping to run Sentient Forms logic for this form.'
					: formSourceAvailabilityMessage}
				actionLabel={canConfigureFormSource ? 'Add action' : null}
				onAction={canConfigureFormSource ? openAddActionPanel : null}
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
									<Badge variant={actionTypeVariant(linkage)}>{actionTypeLabel(linkage)}</Badge>
								</td>
								<td class="sf:px-4 sf:py-3">
									<Badge variant={statusVariant(linkage)}>{statusLabel(linkage)}</Badge>
									{#if isLinkageInvalid(linkage)}
										<p class="sf:mt-2 sf:text-xs sf:text-rose-700">
											Missing upstream source for {invalidHooksForLinkage(linkage).join(', ')}.
										</p>
									{/if}
									{#if linkageNeedsRepair(linkage)}
										<p class="sf:mt-2 sf:text-xs sf:text-rose-700">
											{repairStateMessage(linkage)}
										</p>
									{/if}
								</td>
								<td class="sf:px-4 sf:py-3 sf:text-right sf:space-x-2">
									{#if isLinkageInvalid(linkage) || linkageNeedsRepair(linkage)}
										<Button
											size="sm"
											variant="secondary"
											onclick={() => startEditingAction(linkage)}
										>
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

		<div
			class="sf:mt-4 sf:border-t sf:border-slate-200 sf:pt-4"
			data-testid="form-execution-status"
		>
			<details
				class="sf:rounded sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3"
				open={actionsState.status?.status === 'error' ||
					Boolean(actionsState.status?.last_error_code) ||
					Boolean(checkedEntryStatus)}
			>
				<summary class="sf:cursor-pointer sf:text-sm sf:font-medium sf:text-slate-800">
					Execution status and Sentient Forms log lookup
				</summary>
				<div class="sf:mt-3 sf:grid sf:gap-4 sf:lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.8fr)]">
					<div class="sf:space-y-2">
						<div class="sf:flex sf:items-center sf:justify-between sf:gap-3">
							<p class="sf:text-sm sf:font-medium sf:text-slate-700">Latest execution</p>
							{#if actionsState.status}
								<Badge variant={statusBadgeVariant(actionsState.status)}
									>{actionsState.status.status}</Badge
								>
							{/if}
						</div>
						{#if actionsState.supportsStatus === false}
							<Alert variant="warning">
								Execution status is unavailable in this plugin build
								{#if actionsState.cpsVersion}(current {actionsState.cpsVersion}){/if}
								{#if actionsState.requiredStatusVersion}
									(Requires status API ≥ {actionsState.requiredStatusVersion})
								{/if}.
							</Alert>
						{:else if actionsState.status}
							<p class="sf:text-sm sf:text-slate-700">{statusHeadline(actionsState.status)}</p>
							<p class="sf:text-sm sf:text-slate-600">{statusDescription(actionsState.status)}</p>
							{#if actionsState.status.last_error_code || (actionsState.status.status === 'error' && actionsState.status.message)}
								<p
									class="sf:text-xs sf:text-amber-700 sf:mt-1"
									data-testid="form-execution-status-error"
								>
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
							<div class="sf:flex sf:flex-wrap sf:gap-2">
								<Button size="sm" variant="secondary" onclick={refresh}>Refresh status</Button>
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
					</div>

					<div class="sf:space-y-3">
						<form class="sf:space-y-2" onsubmit={checkEntryStatus}>
							<InputField
								id="entry-id-input"
								label="Check Sentient Forms Action Log entry"
								placeholder="Action Log entry ID from this form"
								bind:value={entryLookupId}
							/>
							<p class="sf:text-xs sf:text-slate-500">
								{entryLookupHelpText}
							</p>
							<div class="sf:flex sf:justify-end">
								<Button type="submit" variant="secondary" size="sm">Check log entry</Button>
							</div>
						</form>

						{#if checkedEntryStatus}
							<div
								class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-1"
							>
								<p class="sf:text-xs sf:font-semibold sf:text-slate-700">
									Sentient Forms log entry {checkedEntryStatus.entry_id} status:
									{checkedEntryStatus.status}
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
											<summary
												class="sf:cursor-pointer sf:text-xs sf:font-medium sf:text-slate-700"
											>
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
														Failed nodes: {checkedEntryStatus.metering_summary.workflow.failed_nodes.join(
															', '
														)}
													</p>
												{/if}
											</div>
										</details>
									{/if}
								{:else}
									<p class="sf:text-xs sf:text-slate-500">
										No metering details were recorded for this log entry.
									</p>
								{/if}
							</div>
						{/if}
					</div>
				</div>
			</details>
		</div>
	</Card>

	{#if showMappingConfigModal && editingLinkage}
		<div
			class="sf-wp-modal-backdrop sf:bg-black/45 sf:flex sf:items-center sf:justify-center sf:p-2 sf:sm:p-4"
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
							Use <strong>Save mapping</strong> to persist or <strong>Discard draft</strong> to reset.
						</p>
					</div>
				{/if}

				<div class="sf:p-4 sf:sm:p-6 sf:space-y-4">
					{#if linkageNeedsRepair(editingLinkage)}
						<Alert variant="warning">
							<p class="sf:text-sm">{repairStateMessage(editingLinkage)}</p>
						</Alert>
					{/if}
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
									{#if hasDisallowedRealtimeDraft}
										<Alert variant="warning">
											<p class="sf:text-sm">
												This mapping has a legacy realtime trigger. Realtime is now reserved for
												Realtime Clarification Assistant, so choose Blocking or Background before
												saving.
											</p>
										</Alert>
									{/if}
									<div class="sf:grid sf:gap-2 sf:sm:grid-cols-2">
										{#each mappingHookEntries as [hookKey, hookLabel] (hookKey)}
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
											Triggers can come from hook roots (autonomous) or mapped actions (dependency).
											Use the graph editor for per-hook trigger source wiring.
										</p>
										<p>
											<strong>Blocking:</strong> AI runs while the submission is still in the request
											path. Use this when the result must be known before the form flow continues.
										</p>
										<p>
											<strong>Background:</strong> AI runs after the form has been accepted, so the submission
											flow is not held open.
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
													Spam-aware downstream gating now belongs on the upstream spam action. Use
													that spam mapping’s advanced settings to decide whether downstream work
													should stop after a spam classification.
												</p>
											</Alert>
										</div>
									{/if}
								</div>

								<div class="sf:border-t sf:border-slate-200 sf:pt-4">
									<ActionCustomizationEditor
										id="mapping-action-customization"
										actionId={editingLinkage.central_action_id}
										level="mapping"
										bind:value={draftSettings.action_customization}
										inheritedValue={currentFormActionConfig.action_customization ||
											currentActionDefaults.action_customization ||
											null}
										inheritanceSource={currentFormActionConfig.action_customization?.trim()
											? 'form'
											: currentActionDefaults.action_customization?.trim()
												? 'action'
												: null}
									/>
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
										inheritedPositive={currentFormActionConfig.spam_positive_examples?.length
											? (currentFormActionConfig.spam_positive_examples ?? [])
											: (currentActionDefaults.spam_positive_examples ?? [])}
										inheritedNegative={currentFormActionConfig.spam_negative_examples?.length
											? (currentFormActionConfig.spam_negative_examples ?? [])
											: (currentActionDefaults.spam_negative_examples ?? [])}
										inheritanceSource={currentFormActionConfig.spam_positive_examples?.length > 0 ||
										currentFormActionConfig.spam_negative_examples?.length > 0
											? 'form'
											: currentActionDefaults.spam_positive_examples?.length > 0 ||
												  currentActionDefaults.spam_negative_examples?.length > 0
												? 'action'
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
											Set default customization and model choices for this action on this form.
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
											id="spam-result-display"
											label="Spam note visibility"
											bind:value={draftSettings.spam_result_display_mode}
											options={SPAM_RESULT_DISPLAY_OPTIONS}
										/>
										<SelectField
											id="spam-display"
											label="Spam note detail"
											bind:value={draftSettings.spam_indicators_display}
											options={SPAM_INDICATORS_DISPLAY_OPTIONS}
											disabled={normalizeSpamResultDisplayMode(
												draftSettings.spam_result_display_mode
											) === 'none'}
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
										<SiteContextWarning includeMode={draftSettings.include_site_context} />
										<AlignedSelectGrid
											columns={3}
											items={[
												{
													id: 'spam-notification-policy',
													label: 'Notification policy on spam',
													description: isBlockingSpamMapping
														? `Current effective value: ${effectiveSuppressNotificationsOnSpam ? 'Suppress notifications' : 'Allow notifications'} (${effectiveSuppressNotificationsOnSpamSource}).`
														: `Background spam mappings do not hold notifications. Current inherited value remains ${effectiveSuppressNotificationsOnSpam ? 'suppress' : 'allow'} but is inactive while this mapping runs in Background mode.`,
													value: getInheritableBooleanMode(
														draftSettings.suppress_notifications_on_spam
													),
													options: [
														{ value: 'inherit', label: 'Use inherited policy' },
														{ value: 'enabled', label: 'Suppress notifications' },
														{ value: 'disabled', label: 'Allow notifications' }
													],
													disabled: !isBlockingSpamMapping,
													onchange: (event) =>
														handleMappingSpamPolicyChange(
															'suppress_notifications_on_spam',
															event.currentTarget.value
														)
												},
												{
													id: 'spam-webhook-policy',
													label: 'Gravity Forms Webhooks policy on spam',
													description: `Current effective value: ${effectiveSuppressWebhooksOnSpam ? 'Suppress Webhooks' : 'Allow Webhooks'} (${effectiveSuppressWebhooksOnSpamSource}). Requires the Gravity Forms Webhooks add-on and feed replay APIs.`,
													value: getInheritableBooleanMode(draftSettings.suppress_webhooks_on_spam),
													options: [
														{ value: 'inherit', label: 'Use inherited policy' },
														{ value: 'enabled', label: 'Suppress Webhooks' },
														{ value: 'disabled', label: 'Allow Webhooks' }
													],
													onchange: (event) =>
														handleMappingSpamPolicyChange(
															'suppress_webhooks_on_spam',
															event.currentTarget.value
														)
												},
												{
													id: 'spam-downstream-policy',
													label: 'Downstream spam gate',
													description: `Current effective value: ${effectiveSkipDownstreamOnSpam ? 'Skip downstream actions' : 'Allow downstream actions'} (${effectiveSkipDownstreamOnSpamSource}).`,
													value: getInheritableBooleanMode(draftSettings.skip_downstream_on_spam),
													options: [
														{ value: 'inherit', label: 'Use inherited policy' },
														{ value: 'enabled', label: 'Skip downstream actions' },
														{ value: 'disabled', label: 'Allow downstream actions' }
													],
													onchange: (event) =>
														handleMappingSpamPolicyChange(
															'skip_downstream_on_spam',
															event.currentTarget.value
														)
												}
											]}
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

					{#if isRealtimeDraft}
						<section class="sf:border sf:border-slate-200 sf:rounded-md">
							<button
								type="button"
								class="sf:flex sf:w-full sf:items-center sf:justify-between sf:gap-4 sf:px-4 sf:py-3 sf:text-left sf:hover:bg-slate-50 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-inset"
								aria-expanded={mappingSectionExpansion.realtime}
								aria-controls="mapping-section-content-realtime"
								data-testid="mapping-section-toggle-realtime"
								onclick={() => toggleMappingSection('realtime')}
							>
								<span class="sf:flex sf:flex-col">
									<span class="sf:text-sm sf:font-semibold sf:text-slate-800">
										Realtime clarification
									</span>
									<span class="sf:text-xs sf:text-slate-500">{realtimeSummary}</span>
								</span>
								<span class="sf:text-xs sf:text-slate-500">
									{mappingSectionExpansion.realtime ? 'Hide' : 'Show'}
								</span>
							</button>
							<div
								id="mapping-section-content-realtime"
								class="sf:border-t sf:border-slate-200 sf:px-4 sf:py-4 sf:space-y-4"
								hidden={!mappingSectionExpansion.realtime}
							>
								{#if mappingSectionExpansion.realtime}
										<Alert variant="warning">
											<p class="sf:text-sm">
												Real-time analysis holds the visitor on the form while the selected model
												responds. Use faster models unless the form is important enough to justify the
												wait.
											</p>
										</Alert>
										<RealtimeSettingsEditor
											idPrefix="mapping-realtime"
											scope="mapping"
											value={realtimeSettings}
											{formFields}
											storageFieldOptions={realtimeStorageFieldOptions}
											totalPages={realtimeTotalPages}
											onchange={updateRealtimeSettings}
										/>
									{/if}
								</div>
							</section>
					{/if}

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
								<span class="sf:text-sm sf:font-semibold sf:text-slate-800">Attachment mapping</span
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
										<span
											class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
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
											<option value="gf_upload">{uploadSourceModeLabel}</option>
											<option value="media_library">Media library</option>
											<option value="mixed">{mixedUploadSourceModeLabel}</option>
										</select>
									</label>

									{#if ['gf_upload', 'mixed'].includes(normalizeAttachmentMapping(draftSettings.attachment_mapping).mode)}
										<div class="sf:grid sf:gap-2">
											<span
												class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
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
																checked={normalizeAttachmentMapping(
																	draftSettings.attachment_mapping
																).gf_upload_field_ids?.includes(field.id)}
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
											<span
												class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
												>Media IDs</span
											>
											<input
												type="text"
												class="sf:px-3 sf:py-2 sf:text-sm sf:border sf:border-slate-300 sf:rounded sf:bg-white sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												placeholder="12, 45, 98"
												value={(
													normalizeAttachmentMapping(draftSettings.attachment_mapping).media_ids ??
													[]
												).join(', ')}
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
										<span
											class="sf:text-xs sf:font-medium sf:text-slate-500 sf:uppercase sf:tracking-wide"
											>Max files per run</span
										>
										<input
											type="number"
											min="1"
											max="20"
											class="sf:px-3 sf:py-2 sf:text-sm sf:border sf:border-slate-300 sf:rounded sf:bg-white sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
											value={normalizeAttachmentMapping(draftSettings.attachment_mapping)
												.max_files ?? 5}
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
									<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
										{#if draftSettings.model_selection}
											<Button size="sm" variant="ghost" onclick={clearMappingModelSelection}>
												Use inherited defaults
											</Button>
										{/if}
										{#if editingLinkage}
											<Button
												size="sm"
												variant="ghost"
												onclick={() => loadFormLevelConfig(editingLinkage.central_action_id)}
											>
												Edit Form Defaults
											</Button>
										{/if}
									</div>
								</div>
								<ModelSelector
									level="mapping"
									value={effectiveMappingModelSelection ?? cloneDefaultModelSelection()}
									actionId={getActionDefinitionContext(editingLinkage?.central_action_id ?? null)
										.actionId}
									templateModelHint={getActionDefinitionContext(
										editingLinkage?.central_action_id ?? null
									).modelHint}
									baseCreditCost={getActionDefinitionContext(
										editingLinkage?.central_action_id ?? null
									).baseCreditCost}
									requiredCapabilities={getActionDefinitionContext(
										editingLinkage?.central_action_id ?? null
									).requiredCapabilities}
									lockRequiredCapabilities={getActionDefinitionContext(
										editingLinkage?.central_action_id ?? null
									).requiredCapabilities.length > 0}
									actionSelection={currentActionDefaults.model_selection ?? null}
									formSelection={currentFormActionConfig.model_selection ?? null}
									mappingSelection={(draftSettings.model_selection as ModelSelection | undefined) ??
										null}
									{providerCredentials}
									onchange={handleMappingModelSelectionChange}
								/>

								{#if isDraftAfterSubmissionOnly}
									<div class="sf:border-t sf:border-slate-200 sf:pt-4">
										<div
											class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:mb-2"
										>
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
											Delay execution to reduce peak load. Managed action credit pricing is calculated at
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
													If managed batching cannot be queued immediately, Sentient Forms will fall
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
		<div
			class="sf-wp-drawer-backdrop sf:bg-black/40 sf:flex sf:justify-end"
			data-testid="add-action-drawer-backdrop"
		>
			<div
				class="sf-wp-drawer-panel sf:w-full sf:max-w-xl sf:bg-white sf:shadow-2xl sf:flex sf:flex-col"
				data-testid="add-action-drawer"
			>
				<div
					class="sf:flex sf:shrink-0 sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:border-b sf:border-slate-200 sf:px-4 sf:py-3"
				>
					<div>
						<p class="sf:text-sm sf:font-semibold sf:text-slate-800">Add action</p>
						<p class="sf:text-xs sf:text-slate-500">
							Create a direct OpenRouter mapping or link an existing action.
						</p>
					</div>
					<Button
						variant="ghost"
						size="sm"
						onclick={() => {
							selectedCreateDependencyIds = new Set();
							createError = null;
							localBuilderResult = null;
							showAddPanel = false;
						}}
					>
						Close
					</Button>
				</div>

				<div class="sf:flex sf:shrink-0 sf:flex-wrap sf:items-end sf:gap-2 sf:border-b sf:border-slate-200 sf:bg-white sf:px-4 sf:py-3">
					<Button
						size="sm"
						variant={createKind === 'template' ? 'primary' : 'secondary'}
						onclick={() => {
							createKind = 'template';
							localBuilderResult = null;
						}}
						disabled={!hasDefinitions}
						data-testid="create-kind-template"
					>
						Built-in actions
					</Button>
					<Button
						size="sm"
						variant={createKind === 'custom' ? 'primary' : 'secondary'}
						onclick={() => {
							createKind = 'custom';
							localBuilderResult = null;
						}}
						disabled={customActions.length === 0}
						data-testid="create-kind-custom"
					>
						Custom actions
					</Button>
					<Button
						size="sm"
						variant={createKind === 'local_openrouter' ? 'primary' : 'secondary'}
						onclick={() => {
							createKind = 'local_openrouter';
							selectedCreateDependencyIds = new Set();
							createError = null;
						}}
						data-testid="create-kind-local-openrouter"
					>
						Direct OpenRouter
					</Button>
					{#if createKind !== 'local_openrouter'}
						<div class="sf:flex-1 sf:min-w-[200px]">
							<InputField
								id="action-search"
								label="Search"
								placeholder="Search by name or id"
								bind:value={searchTerm}
							/>
						</div>
					{/if}
				</div>

				<form
					class="sf:flex sf:min-h-0 sf:flex-1 sf:flex-col"
					data-testid="link-action-form"
				>
					<div
						class="sf:min-h-0 sf:flex-1 sf:space-y-4 sf:overflow-y-auto sf:px-4 sf:py-4"
						data-testid="link-action-scroll-region"
					>
					{#if createKind === 'template'}
						{#if !hasDefinitions}
							<Alert variant="warning">No built-in actions available right now.</Alert>
						{:else}
							<div class="sf:space-y-2">
								{#each builtInDefinitions.filter((definition) => {
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
												Base credits: {formatBaseCreditCost(definition)} · Model: {formatModelHint(
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
					{:else if createKind === 'custom'}
						{#if customActions.length === 0}
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
													Base credits: {action.base_credit_cost} credits
												</p>
											{/if}
										</div>
									</label>
								{/each}
							</div>
						{/if}
					{:else}
						<div class="sf:space-y-4" data-testid="local-openrouter-builder">
							<Alert variant={openRouterHealth.status === 'ready' ? 'info' : 'warning'}>
								<div class="sf:flex sf:flex-col sf:gap-2">
									<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
										<Badge variant={providerStatusVariant(openRouterHealth.badgeStatus)}>
											{providerStatusLabel(openRouterHealth.badgeStatus)}
										</Badge>
										<p class="sf:text-sm sf:font-medium">{openRouterHealth.title}</p>
									</div>
									<p class="sf:text-sm">{openRouterHealth.message}</p>
								</div>
							</Alert>

							{#if readyOpenRouterCredentials.length === 0}
								<Alert variant="warning">
									Validate a ready OpenRouter key before creating direct local mappings.
								</Alert>
							{:else}
								<div class="sf:space-y-1">
									<label
										class="sf:text-sm sf:font-medium sf:text-slate-700"
										for="local-builder-template"
									>
										Starter
									</label>
									<select
										id="local-builder-template"
										class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
										value={localBuilderTemplateKey}
										disabled={creating}
										data-testid="local-builder-template"
										onchange={(event) =>
											applyLocalBuilderTemplate(
												event.currentTarget.value as LocalBuilderTemplateKey
											)}
									>
										{#each LOCAL_BUILDER_TEMPLATE_OPTIONS as template (template.key)}
											<option value={template.key}>{template.label}</option>
										{/each}
									</select>
									<p class="sf:text-xs sf:text-slate-500">{localBuilderTemplate.description}</p>
								</div>

								<div class="sf:grid sf:gap-3 sf:lg:grid-cols-2">
									<div class="sf:space-y-1">
										<label
											class="sf:text-sm sf:font-medium sf:text-slate-700"
											for="local-builder-credential"
										>
											OpenRouter key
										</label>
										<select
											id="local-builder-credential"
											class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
											bind:value={localBuilderCredentialId}
											disabled={creating}
											data-testid="local-builder-credential"
										>
											{#each readyOpenRouterCredentials as credential}
												<option value={String(credential.id)}
													>{credential.label} · #{credential.id}</option
												>
											{/each}
										</select>
									</div>
									<InputField
										id="local-builder-action-name"
										label="Action name"
										placeholder="Local OpenRouter summary"
										bind:value={localBuilderActionName}
										disabled={creating}
										data-testid="local-builder-action-name"
									/>
								</div>

								<div class="sf:grid sf:gap-3 sf:lg:grid-cols-2">
									<InputField
										id="local-builder-result-meta-key"
										label="Result meta key"
										placeholder="sentient_forms_summary"
										bind:value={localBuilderResultMetaKey}
										disabled={creating}
										required
										data-testid="local-builder-result-meta-key"
									/>
									<div class="sf:space-y-1">
										<label
											class="sf:text-sm sf:font-medium sf:text-slate-700"
											for="local-builder-execution-mode"
										>
											Run mode
										</label>
										<select
											id="local-builder-execution-mode"
											class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
											bind:value={localBuilderExecutionMode}
											disabled={creating}
											data-testid="local-builder-execution-mode"
										>
											<option value="async">Background local run</option>
											{#if localBuilderSupportsSync}
												<option value="sync">Immediate local run</option>
											{/if}
										</select>
									</div>
								</div>

								{#if localBuilderTemplateKey === 'spam_filter' && supportsSpamNoteControls}
									<div class="sf:grid sf:gap-3 sf:lg:grid-cols-2">
										<div class="sf:space-y-1">
											<label
												class="sf:text-sm sf:font-medium sf:text-slate-700"
												for="local-builder-spam-result-display"
											>
												Spam note visibility
											</label>
											<select
												id="local-builder-spam-result-display"
												class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												bind:value={localBuilderSpamResultDisplayMode}
												disabled={creating}
												data-testid="local-builder-spam-result-display"
											>
												{#each SPAM_RESULT_DISPLAY_OPTIONS as option (option.value)}
													<option value={option.value}>{option.label}</option>
												{/each}
											</select>
										</div>
										<div class="sf:space-y-1">
											<label
												class="sf:text-sm sf:font-medium sf:text-slate-700"
												for="local-builder-spam-indicators-display"
											>
												Spam note detail
											</label>
											<select
												id="local-builder-spam-indicators-display"
												class="sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
												bind:value={localBuilderSpamIndicatorsDisplay}
												disabled={creating ||
													normalizeSpamResultDisplayMode(localBuilderSpamResultDisplayMode) ===
														'none'}
												data-testid="local-builder-spam-indicators-display"
											>
												{#each SPAM_INDICATORS_DISPLAY_OPTIONS as option (option.value)}
													<option value={option.value}>{option.label}</option>
												{/each}
											</select>
										</div>
									</div>
								{/if}

								<div class="sf:space-y-1">
									<label
										class="sf:text-sm sf:font-medium sf:text-slate-700"
										for="local-builder-system-prompt"
									>
										System prompt
									</label>
									<textarea
										id="local-builder-system-prompt"
										class="sf:min-h-20 sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
										bind:value={localBuilderSystemPrompt}
										disabled={creating}
										data-testid="local-builder-system-prompt"
									></textarea>
								</div>

								<div class="sf:space-y-1">
									<label
										class="sf:text-sm sf:font-medium sf:text-slate-700"
										for="local-builder-prompt-template"
									>
										Prompt template
									</label>
									<textarea
										id="local-builder-prompt-template"
										class="sf:min-h-32 sf:w-full sf:rounded sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:font-mono sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
										bind:value={localBuilderPromptTemplate}
										disabled={creating}
										required
										data-testid="local-builder-prompt-template"
									></textarea>
									<p class="sf:text-xs sf:text-slate-500">
										Available placeholders include <code>{'{{form.title}}'}</code> and
										<code>{'{{entry}}'}</code>. The result must include a JSON
										<code>{localBuilderTemplate.resultField}</code> field.
									</p>
								</div>

								<div data-testid="local-builder-model-selector">
									<ModelSelector
										value={localBuilderModelSelection}
										label="Local model policy"
										level="action"
										templateModelHint="openrouter/auto"
										{providerCredentials}
										allowedProviders={['openrouter']}
										requiredCapabilities={['structured']}
										lockRequiredCapabilities={true}
										onchange={handleLocalBuilderModelSelectionChange}
									/>
								</div>
							{/if}
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
							{#each createHookEntries as [hookKey, hookLabel] (hookKey)}
								<label
									class="sf:flex sf:items-center sf:gap-2 sf:text-sm sf:text-slate-700 sf:border sf:border-slate-200 sf:rounded-md sf:px-3 sf:py-2"
								>
									<input
										type="checkbox"
										class="sf:form-checkbox sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
										checked={selectedHooks.has(hookKey)}
										onchange={() => toggleHookSelection(hookKey)}
										data-testid={`create-trigger-hook-${hookKey}`}
									/>
									<span>{hookLabel}</span>
								</label>
							{/each}
						</div>
					</div>

					{#if createKind !== 'local_openrouter'}
						<div class="sf:border-t sf:border-slate-200 sf:pt-3 sf:space-y-2">
							<div
								class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-2 sf:sm:flex-row sf:sm:items-center"
							>
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
					{/if}

					{#if localBuilderResult && createKind === 'local_openrouter'}
						<Alert variant="success" data-testid="local-builder-result">
							Action #{localBuilderResult.action.id} mapped to {localBuilderResult.mappings.length}
							hook{localBuilderResult.mappings.length === 1 ? '' : 's'} from local WordPress tables.
						</Alert>
					{/if}
					</div>

					<footer
						class="sf:shrink-0 sf:space-y-3 sf:border-t sf:border-slate-200 sf:bg-white sf:px-4 sf:py-3"
						data-testid="link-action-footer"
					>
						<div
							class="sf:flex sf:flex-col sf:gap-2 sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-3 sf:py-2 sf:sm:flex-row sf:sm:items-center sf:sm:justify-between"
							data-testid="link-action-selected-summary"
						>
							<div class="sf:min-w-0">
								<p class="sf:text-xs sf:font-medium sf:uppercase sf:tracking-wide sf:text-slate-500">
									Selected action
								</p>
								<p class="sf:truncate sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedCreateActionLabel}
								</p>
							</div>
							<p class="sf:text-xs sf:text-slate-500">{selectedCreateActionSummary}</p>
						</div>

						{#if createError}
							<Alert variant="danger">{createError}</Alert>
						{/if}

						<div class="sf:flex sf:justify-end sf:gap-2">
							<Button
								type="button"
								variant="secondary"
								onclick={() => {
									selectedCreateDependencyIds = new Set();
									createError = null;
									localBuilderResult = null;
									showAddPanel = false;
								}}
							>
								Cancel
							</Button>
							<Button
								type="button"
								onclick={() => handleCreate(new Event('submit', { cancelable: true }))}
								disabled={linkActionDisabled}
								data-testid="link-action-submit"
							>
								{creating
									? createKind === 'local_openrouter'
										? 'Creating...'
										: 'Linking...'
									: createKind === 'local_openrouter'
										? 'Create Direct OpenRouter action'
										: 'Link action'}
							</Button>
						</div>
					</footer>
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
