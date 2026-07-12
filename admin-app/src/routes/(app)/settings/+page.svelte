<script lang="ts">
	import { run } from 'svelte/legacy';

	import { onMount } from 'svelte';
	import { telemetryStore } from '$lib/stores/telemetry.svelte';
	import { asyncSettingsStore } from '$lib/stores/async-settings.svelte';
	import { asyncHealthStore } from '$lib/stores/async-health.svelte';
	import { loggingStore } from '$lib/stores/logging.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { licenseState } from '$lib/stores/license';
	import { notifications } from '$lib/stores/notifications';
	import { Alert, Badge, Button, StateTemplate } from '$lib/components/ui';
	import type {
		FormSourceSummary,
		PluginSettingsResponse,
		SiteContextStatusResponse
	} from '$lib/api/types';
	import { navigateToAppPath } from '$lib/navigation';
	import { wpRequestEndpoint } from '$lib/wp';
	import InfoIcon from '@lucide/svelte/icons/info';
	import { normalizeSiteContextResponse, siteContextStatusLabel } from '$lib/utils/site-context';
	import { readRuntimeConfigSafely } from '$lib/schemas/runtime-config';

	type PrivacyPresetId = 'balanced' | 'privacy_focused' | 'maximum_privacy' | 'maximum_visibility';
	type RetentionDays = PluginSettingsResponse['execution_event_retention_days'];

	interface PrivacyPresetDefinition {
		label: string;
		executionEventRetentionDays: RetentionDays;
		submissionLedgerRetentionDays: RetentionDays;
		deleteDataOnUninstall: boolean;
		storeFullAiOutputs: boolean;
		enableLogging: boolean;
	}

	interface RetentionSnapshot {
		executionEventRetentionDays: RetentionDays;
		submissionLedgerRetentionDays: RetentionDays;
		deleteDataOnUninstall: boolean;
		storeFullAiOutputs: boolean;
	}

	type RetentionFeedback = { type: 'success' | 'error'; message: string };

	const telemetry = telemetryStore;
	const asyncSettings = asyncSettingsStore;
	const asyncHealth = asyncHealthStore;
	const logging = loggingStore;
	const client = createClientFromConfig();
	const runtime = readRuntimeConfigSafely();
	const formSources: FormSourceSummary[] = runtime?.formSources ?? [];

	let formDirty = $state(false);
	let formState = $state({
		maxAttempts: 3,
		baseDelaySeconds: 60,
		maxDelaySeconds: 3600
	});
	let executionLoading = $state(false);
	let executionSaving = $state(false);
	let governanceLoaded = $state(false);
	let governanceLoadError = $state<string | null>(null);
	let governanceLoggingEnabled = $state<boolean | null>(null);
	let executionGlobalDisabled = $state(false);
	let executionProviderDisabled = $state<Record<string, boolean>>({});
	let retentionSaving = $state(false);
	let managedZdrSaving = $state(false);
	let settingsWriteInFlight = $derived(executionSaving || retentionSaving || managedZdrSaving);
	let executionEventRetentionDays = $state<RetentionDays>(90);
	let submissionLedgerRetentionDays = $state<RetentionDays>(90);
	let deleteDataOnUninstall = $state(true);
	let storeFullAiOutputs = $state(false);
	let retentionSavedSnapshot = $state<RetentionSnapshot>({
		executionEventRetentionDays: 90,
		submissionLedgerRetentionDays: 90,
		deleteDataOnUninstall: true,
		storeFullAiOutputs: false
	});
	let retentionFeedback = $state<RetentionFeedback | null>(null);
	let retentionDirty = $derived(
		executionEventRetentionDays !== retentionSavedSnapshot.executionEventRetentionDays ||
			submissionLedgerRetentionDays !== retentionSavedSnapshot.submissionLedgerRetentionDays ||
			deleteDataOnUninstall !== retentionSavedSnapshot.deleteDataOnUninstall ||
			storeFullAiOutputs !== retentionSavedSnapshot.storeFullAiOutputs
	);
	let managedZdrRequired = $state(false);
	let managedAccountReady = $derived(
		['active', 'trial', 'valid'].includes(licenseState.status) &&
			licenseState.proxyKeyPresent &&
			Boolean(licenseState.licenseId) &&
			Boolean(licenseState.siteId)
	);
	let managedZdrControlDisabled = $derived(
		settingsWriteInFlight || executionLoading || !managedAccountReady
	);
	let privacySetupProfile =
		$state<NonNullable<PluginSettingsResponse['privacy_setup_profile']>>('balanced');
	let privacySetupCompletedAt = $state<string | null>(null);
	let siteContextStatus = $state<SiteContextStatusResponse | null>(null);
	let siteContextLoading = $state(false);
	let telemetryDetailsOpen = $state(false);

	const privacyPresetDefinitions: Record<PrivacyPresetId, PrivacyPresetDefinition> = {
		balanced: {
			label: 'Balanced',
			executionEventRetentionDays: 90,
			submissionLedgerRetentionDays: 90,
			deleteDataOnUninstall: true,
			storeFullAiOutputs: false,
			enableLogging: false
		},
		privacy_focused: {
			label: 'Privacy focused',
			executionEventRetentionDays: 30,
			submissionLedgerRetentionDays: 30,
			deleteDataOnUninstall: true,
			storeFullAiOutputs: false,
			enableLogging: false
		},
		maximum_privacy: {
			label: 'Maximum privacy',
			executionEventRetentionDays: 7,
			submissionLedgerRetentionDays: 7,
			deleteDataOnUninstall: true,
			storeFullAiOutputs: false,
			enableLogging: false
		},
		maximum_visibility: {
			label: 'Maximum visibility',
			executionEventRetentionDays: 180,
			submissionLedgerRetentionDays: 180,
			deleteDataOnUninstall: true,
			storeFullAiOutputs: true,
			enableLogging: true
		}
	};

	const privacyProfileLabels: Record<PrivacyPresetId, string> = {
		balanced: 'Balanced',
		privacy_focused: 'Privacy focused',
		maximum_privacy: 'Maximum privacy',
		maximum_visibility: 'Maximum visibility'
	};

	const retentionOptions = [
		{ value: 7, label: '7 days' },
		{ value: 30, label: '30 days' },
		{ value: 90, label: '90 days' },
		{ value: 180, label: '180 days' },
		{ value: 0, label: 'Manual deletion only' }
	];

	function getManualRetentionHelp(
		executionRetentionDays: number,
		ledgerRetentionDays: number
	): string | null {
		const executionIsManual = executionRetentionDays === 0;
		const ledgerIsManual = ledgerRetentionDays === 0;

		if (executionIsManual && ledgerIsManual) {
			return 'Manual deletion keeps new execution logs and Submission Ledger records until an administrator removes them or changes these settings.';
		}
		if (executionIsManual) {
			return 'Manual deletion keeps new execution logs until an administrator removes them or changes this setting.';
		}
		if (ledgerIsManual) {
			return 'Manual deletion keeps new Submission Ledger records until an administrator removes them or changes this setting.';
		}

		return null;
	}

	let manualRetentionHelp = $derived(
		getManualRetentionHelp(executionEventRetentionDays, submissionLedgerRetentionDays)
	);

	function isKnownPrivacyProfile(
		profile: PluginSettingsResponse['privacy_setup_profile']
	): profile is PrivacyPresetId {
		return (
			typeof profile === 'string' &&
			Object.prototype.hasOwnProperty.call(privacyPresetDefinitions, profile)
		);
	}

	let effectiveLoggingEnabled = $derived(
		($logging.loading || $logging.saving) && null !== governanceLoggingEnabled
			? governanceLoggingEnabled
			: $logging.enabled
	);
	let activePrivacyProfileLabel = $derived(
		isKnownPrivacyProfile(privacySetupProfile)
			? privacyProfileLabels[privacySetupProfile]
			: 'Balanced'
	);
	let activePrivacyPreset = $derived(
		isKnownPrivacyProfile(privacySetupProfile)
			? privacyPresetDefinitions[privacySetupProfile]
			: null
	);
	let privacyProfileCustomized = $derived(
		null !== activePrivacyPreset &&
			(activePrivacyPreset.executionEventRetentionDays !== executionEventRetentionDays ||
				activePrivacyPreset.submissionLedgerRetentionDays !== submissionLedgerRetentionDays ||
				activePrivacyPreset.deleteDataOnUninstall !== deleteDataOnUninstall ||
				activePrivacyPreset.storeFullAiOutputs !== storeFullAiOutputs ||
				activePrivacyPreset.enableLogging !== effectiveLoggingEnabled)
	);

	onMount(() => {
		telemetry.load();
		asyncSettings.load();
		asyncHealth.refresh();
		logging.load();
		loadExecutionSettings();
		void loadSiteContextStatus();

		const handleSettingsUpdate = (event: Event) => {
			const customEvent = event as CustomEvent<PluginSettingsResponse>;
			if (!customEvent.detail || typeof customEvent.detail !== 'object') return;
			syncGovernanceSettings(customEvent.detail);
			void logging.load();
		};

		window.addEventListener(
			'sentient-forms:settings-updated',
			handleSettingsUpdate as EventListener
		);

		return () => {
			window.removeEventListener(
				'sentient-forms:settings-updated',
				handleSettingsUpdate as EventListener
			);
		};
	});

	function syncGovernanceSettings(settings: PluginSettingsResponse): void {
		syncExecutionControls(settings);
		syncRetentionSettings(settings);
		managedZdrRequired = Boolean(settings.managed_zdr_required);
		governanceLoggingEnabled =
			typeof settings.enable_logging === 'boolean' ? settings.enable_logging : null;
		governanceLoaded = true;
		governanceLoadError = null;
	}

	function syncExecutionControls(settings: PluginSettingsResponse): void {
		executionGlobalDisabled = Boolean(settings.execution_global_disabled);
		executionProviderDisabled = normalizeProviderDisabledMap(
			settings.execution_provider_disabled,
			formSources
		);
	}

	function syncRetentionSettings(settings: PluginSettingsResponse): void {
		executionEventRetentionDays = settings.execution_event_retention_days;
		submissionLedgerRetentionDays = settings.submission_ledger_retention_days;
		deleteDataOnUninstall = settings.delete_data_on_uninstall;
		storeFullAiOutputs = settings.store_full_ai_outputs;
		retentionSavedSnapshot = {
			executionEventRetentionDays,
			submissionLedgerRetentionDays,
			deleteDataOnUninstall,
			storeFullAiOutputs
		};
		privacySetupProfile = settings.privacy_setup_profile;
		privacySetupCompletedAt = settings.privacy_setup_completed_at;
	}

	function syncManagedZdrSetting(settings: PluginSettingsResponse): void {
		managedZdrRequired = Boolean(settings.managed_zdr_required);
		governanceLoaded = true;
		governanceLoadError = null;
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
		executionLoading = true;
		governanceLoadError = null;
		try {
			const settings = await client.getSettings({ showNotifications: false });
			syncGovernanceSettings(settings);
		} catch {
			governanceLoadError = 'Local privacy and execution settings could not be loaded.';
			notifications.warning('Failed to load execution control settings');
		} finally {
			executionLoading = false;
		}
	}

	async function toggleManagedZdrRequired(nextRequired: boolean) {
		if (!managedAccountReady) {
			managedZdrRequired = false;
			notifications.warning('Active Sentient Forms Managed Service is required to enforce ZDR.');
			return;
		}

		const previous = managedZdrRequired;
		managedZdrRequired = nextRequired;
		managedZdrSaving = true;
		try {
			const settings = await client.updateSettings(
				{
					managed_zdr_required: nextRequired
				},
				{ showNotifications: false }
			);
			syncManagedZdrSetting(settings);
			notifications.success(
				nextRequired ? 'Managed ZDR enforcement enabled' : 'Managed ZDR enforcement disabled'
			);
		} catch {
			managedZdrRequired = previous;
			notifications.error('Unable to update managed ZDR enforcement');
		} finally {
			managedZdrSaving = false;
		}
	}

	function retryGovernanceLoad(): void {
		void loadExecutionSettings();
	}

	async function toggleExecutionGlobal(nextDisabled: boolean) {
		const previous = executionGlobalDisabled;
		executionGlobalDisabled = nextDisabled;
		executionSaving = true;
		try {
			const settings = await client.updateSettings(
				{
					execution_global_disabled: nextDisabled,
					execution_provider_disabled: executionProviderDisabled
				},
				{ showNotifications: false }
			);
			syncExecutionControls(settings);
			notifications.success(
				executionGlobalDisabled ? 'Global execution paused' : 'Global execution resumed'
			);
		} catch {
			executionGlobalDisabled = previous;
			notifications.error('Unable to update global execution control');
		} finally {
			executionSaving = false;
		}
	}

	async function toggleExecutionProvider(providerSlug: string, nextDisabled: boolean) {
		const previousMap = { ...executionProviderDisabled };
		executionProviderDisabled = { ...executionProviderDisabled, [providerSlug]: nextDisabled };
		executionSaving = true;
		try {
			const settings = await client.updateSettings(
				{
					execution_global_disabled: executionGlobalDisabled,
					execution_provider_disabled: executionProviderDisabled
				},
				{ showNotifications: false }
			);
			syncExecutionControls(settings);
			notifications.success(
				nextDisabled
					? `Execution paused for ${providerSlug}`
					: `Execution resumed for ${providerSlug}`
			);
		} catch {
			executionProviderDisabled = previousMap;
			notifications.error('Unable to update provider execution control');
		} finally {
			executionSaving = false;
		}
	}

	async function saveRetentionSettings(event: SubmitEvent) {
		event.preventDefault();
		if (!retentionDirty || retentionSaving) return;
		retentionSaving = true;
		retentionFeedback = null;
		try {
			const settings = await client.updateSettings(
				{
					execution_event_retention_days: executionEventRetentionDays,
					submission_ledger_retention_days: submissionLedgerRetentionDays,
					delete_data_on_uninstall: deleteDataOnUninstall,
					store_full_ai_outputs: storeFullAiOutputs
				},
				{ showNotifications: false }
			);
			syncGovernanceSettings(settings);
			retentionFeedback = {
				type: 'success',
				message: 'Retention settings saved on this site.'
			};
			notifications.success('Local data retention saved');
		} catch {
			retentionFeedback = {
				type: 'error',
				message: 'Unable to update local data retention. Your unsaved choices are still here.'
			};
			notifications.error('Unable to update local data retention');
		} finally {
			retentionSaving = false;
		}
	}

	function markRetentionChanged(): void {
		retentionFeedback = null;
	}

	function toggle(event: Event) {
		const target = event.currentTarget as HTMLInputElement;
		telemetry.setOptIn(target.checked);
	}

	function toggleLogging(event: Event) {
		const target = event.currentTarget as HTMLInputElement;
		logging.setEnabled(target.checked);
	}

	run(() => {
		if (!$asyncSettings.loading && !$asyncSettings.saving && !formDirty) {
			formState = {
				maxAttempts: $asyncSettings.maxAttempts,
				baseDelaySeconds: $asyncSettings.baseDelaySeconds,
				maxDelaySeconds: $asyncSettings.maxDelaySeconds
			};
		}
	});

	function handleInput(event: Event) {
		formDirty = true;
		const target = event.currentTarget as HTMLInputElement;
		const value = Number.parseInt(target.value, 10);
		formState = { ...formState, [target.name]: Number.isNaN(value) ? 0 : value };
	}

	async function saveAsyncSettings(event: SubmitEvent) {
		event.preventDefault();
		const payload = {
			maxAttempts: formState.maxAttempts,
			baseDelaySeconds: formState.baseDelaySeconds,
			maxDelaySeconds: formState.maxDelaySeconds
		};

		const result = await asyncSettings.save(payload);
		if (result) {
			formDirty = false;
		}
	}

	let showClearConfirm = $state(false);

	async function purgeStaleJobs() {
		await asyncHealth.purge({ status: 'queued,failed', olderThan: 10080 });
	}

	async function clearAllJobs() {
		await asyncHealth.purge({ clearAll: true });
		showClearConfirm = false;
	}

	function describeAsyncSettingsError(code: string | null): string {
		if (code === 'load_failed') {
			return 'Background processing settings could not be loaded from the API.';
		}
		if (code === 'update_failed') {
			return 'Background processing settings could not be saved.';
		}
		return 'Background processing settings are temporarily unavailable.';
	}

	function openPrivacySetupAssistant(): void {
		window.dispatchEvent(new CustomEvent('sentient-forms:open-privacy-setup'));
	}

	function openSiteContextSettings(): void {
		void navigateToAppPath('/settings/context');
	}

	async function loadSiteContextStatus(): Promise<void> {
		siteContextLoading = true;
		try {
			siteContextStatus = normalizeSiteContextResponse(await wpRequestEndpoint('siteContext.read'));
		} catch (error) {
			console.error('Failed to load Site Context status', error);
			siteContextStatus = null;
		} finally {
			siteContextLoading = false;
		}
	}
</script>

<section class="sf:min-w-0 sf:space-y-6 sf:max-w-3xl">
	<header class="sf:space-y-2">
		<h1 class="sf:text-2xl sf:font-semibold sf:text-slate-900">Privacy &amp; Local Processing</h1>
		<p class="sf:text-slate-600 sf:text-sm">
			Choose how much Sentient Forms keeps locally, how much visibility you want while tuning
			actions, and how the plugin should behave when it is removed.
		</p>
	</header>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4"
	>
		{#if !governanceLoaded && executionLoading}
			<StateTemplate
				variant="loading"
				title="Loading local privacy settings"
				message="Retrieving your saved profile, retention choices, and uninstall behavior."
				inline
				testId="settings-governance-loading-state"
			/>
		{:else if governanceLoadError}
			<StateTemplate
				variant="error"
				title="Privacy settings unavailable"
				message={governanceLoadError}
				actionLabel="Retry"
				onAction={retryGovernanceLoad}
				inline
				testId="settings-governance-error-state"
			/>
		{:else}
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:lg:flex-row sf:lg:items-center"
			>
				<div class="sf:space-y-2">
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<p class="sf:text-base sf:font-semibold sf:text-slate-900">
							Privacy &amp; visibility profile
						</p>
						{#if privacyProfileCustomized}
							<span data-testid="settings-profile-customized-badge">
								<Badge variant="neutral">Custom settings</Badge>
							</span>
							<span data-testid="settings-profile-base-badge">
								<Badge variant="info">{activePrivacyProfileLabel}</Badge>
							</span>
						{:else}
							<span data-testid="settings-profile-base-badge">
								<Badge variant={privacySetupProfile === 'maximum_visibility' ? 'warning' : 'info'}>
									{activePrivacyProfileLabel}
								</Badge>
							</span>
						{/if}
						{#if privacySetupCompletedAt}
							<Badge variant="neutral">Setup saved</Badge>
						{:else}
							<Badge variant="warning">Setup still needs review</Badge>
						{/if}
					</div>
					<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
						{#if privacyProfileCustomized}
							Started from {activePrivacyProfileLabel}. One or more controls below now differ from
							that preset, so this site is using a custom privacy configuration.
						{:else}
							Start with a preset when you want the plugin to feel obvious instead of technical. The
							assistant changes retention, uninstall cleanup, local logging, and whether full AI
							outputs are kept.
						{/if}
					</p>
				</div>
				<Button
					type="button"
					variant="secondary"
					class="sf:group sf:min-w-[11rem] sf:gap-2 sf:border-primary-200 sf:bg-primary-50 sf:text-primary-800 sf:shadow-sm sf:hover:border-primary-300 sf:hover:bg-primary-100"
					onclick={openPrivacySetupAssistant}
				>
					<span
						class="sf:inline-flex sf:h-5 sf:w-5 sf:items-center sf:justify-center sf:rounded-full sf:bg-white sf:text-primary-700 sf:shadow-sm sf:transition-transform sf:group-hover:-rotate-12"
						aria-hidden="true"
					>
						<svg viewBox="0 0 20 20" class="sf:h-3.5 sf:w-3.5 sf:fill-current">
							<path d="M9.5 2.6 11 6.8l4.2 1.5L11 9.8 9.5 14 8 9.8 3.8 8.3 8 6.8z" />
							<path d="M15.2 1.7 15.8 3.4 17.5 4l-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6z" />
						</svg>
					</span>
					{privacySetupCompletedAt ? 'Review setup' : 'Finish guided setup'}
				</Button>
			</div>

			<div class="sf:grid sf:gap-3 sf:sm:grid-cols-2 sf:xl:grid-cols-5">
				<div
					class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4"
					data-testid="settings-profile-execution-history"
				>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Execution history</p>
					<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
						{executionEventRetentionDays === 0
							? 'Manual deletion only'
							: `${executionEventRetentionDays} days`}
					</p>
				</div>
				<div
					class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4"
					data-testid="settings-profile-submission-ledger"
				>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Submission Ledger</p>
					<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
						{submissionLedgerRetentionDays === 0
							? 'Manual deletion only'
							: `${submissionLedgerRetentionDays} days`}
					</p>
				</div>
				<div
					class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4"
					data-testid="settings-profile-full-outputs"
				>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Full AI outputs</p>
					<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
						{storeFullAiOutputs ? 'Stored locally' : 'Not stored by default'}
					</p>
				</div>
				<div
					class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4"
					data-testid="settings-profile-uninstall"
				>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Uninstall behavior</p>
					<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
						{deleteDataOnUninstall ? 'Deletes plugin data' : 'Keeps plugin data'}
					</p>
				</div>
				<div
					class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4"
					data-testid="settings-profile-diagnostics"
				>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Local diagnostics</p>
					<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
						{effectiveLoggingEnabled ? 'On-site logging enabled' : 'On-site logging disabled'}
					</p>
				</div>
			</div>
		{/if}
	</div>

	<div class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm">
		<div
			class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-4 sf:lg:flex-row sf:lg:items-center"
		>
			<div class="sf:min-w-0 sf:space-y-2">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<p class="sf:text-base sf:font-semibold sf:text-slate-900">Site Context</p>
					{#if siteContextLoading}
						<Badge variant="neutral">Checking</Badge>
					{:else if siteContextStatus}
						<Badge
							variant={siteContextStatus.status === 'ready'
								? 'success'
								: siteContextStatus.status === 'declined'
									? 'neutral'
									: 'warning'}
						>
							{siteContextStatusLabel(siteContextStatus)}
						</Badge>
					{:else}
						<Badge variant="warning">Unavailable</Badge>
					{/if}
				</div>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					Describe what this website does so spam checks, summaries, and other actions can make
					site-specific decisions instead of generic guesses.
				</p>
				{#if siteContextStatus?.is_empty && siteContextStatus.settings.consent_status === 'granted'}
					<p class="sf:text-xs sf:font-medium sf:text-amber-700">
						Generation is allowed, but no Site Context is saved yet.
					</p>
				{:else if siteContextStatus?.is_stale}
					<p class="sf:text-xs sf:font-medium sf:text-amber-700">
						Last context looks older than {siteContextStatus.stale_after_days} days.
					</p>
				{:else if siteContextStatus?.settings.consent_status === 'declined'}
					<p class="sf:text-xs sf:text-slate-500">
						AI generation is off. Manual Site Context can still be configured.
					</p>
				{/if}
			</div>
			<Button
				type="button"
				variant="secondary"
				class="sf:min-w-[13.5rem] sf:whitespace-nowrap sf:border-slate-300 sf:bg-white sf:text-slate-900"
				onclick={openSiteContextSettings}
				data-testid="settings-manage-site-context"
			>
				<svg viewBox="0 0 20 20" class="sf:h-4 sf:w-4" aria-hidden="true">
					<path fill="currentColor" d="M5 4.5h6.2v1.7H7.9l6.4 6.4-1.2 1.2-6.4-6.4v3.3H5z" />
				</svg>
				Manage Site Context
			</Button>
		</div>
	</div>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-3"
		data-testid="settings-managed-zdr"
	>
		<div
			class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-start"
		>
			<div class="sf:min-w-0 sf:space-y-1">
				<p class="sf:font-medium sf:text-slate-900">Enforce ZDR for managed service</p>
				<p class="sf:max-w-2xl sf:text-sm sf:leading-6 sf:text-slate-600">
					Requires Sentient Forms Managed Service to use routes that OpenRouter marks for Zero Data
					Retention and to deny provider data collection. If the selected model is no longer
					eligible, Sentient Forms uses a comparable ZDR-safe model when available or fails safely.
				</p>
				{#if !managedAccountReady}
					<p class="sf:text-sm sf:text-slate-500">
						Requires an active Sentient Forms Managed Service subscription.
					</p>
				{/if}
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{managedZdrRequired ? 'On' : 'Off'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
					checked={managedZdrRequired}
					disabled={managedZdrControlDisabled}
					onchange={(event) =>
						toggleManagedZdrRequired((event.currentTarget as HTMLInputElement).checked)}
					aria-label="Enforce ZDR for managed service"
				/>
			</label>
		</div>
	</div>

	{#if $asyncHealth.warnings.length}
		<div class="sf:rounded-xl sf:border sf:border-amber-200 sf:bg-amber-50 sf:p-4 sf:space-y-2">
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center"
			>
				<p class="sf:font-semibold sf:text-amber-900">Background processing warnings</p>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					class="sf:h-auto sf:px-1 sf:py-0 sf:text-amber-900 sf:underline"
					onclick={() => asyncHealth.refresh()}
				>
					Refresh
				</Button>
			</div>
			<ul class="sf:space-y-1">
				{#each $asyncHealth.warnings as warning}
					<li class="sf:text-sm sf:text-amber-900">
						<strong>{warning.code}</strong>: {warning.message}
					</li>
				{/each}
			</ul>
		</div>
	{/if}

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm"
		data-testid="settings-local-diagnostics"
	>
		<div
			class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center"
		>
			<div>
				<p class="sf:font-medium sf:text-slate-900">Allow local diagnostic events</p>
				<p class="sf:text-sm sf:text-slate-600">
					Consent allows metadata-only reliability events to be generated. Enable on-site logging
					below to write them to the masked log. Nothing is sent off-site in this release.
				</p>
			</div>
			<div class="sf:flex sf:items-center sf:gap-3">
				<Button
					type="button"
					variant="secondary"
					size="sm"
					class="sf:shrink-0 sf:gap-2 sf:whitespace-nowrap"
					style="min-width: 8.75rem; white-space: nowrap;"
					onclick={() => (telemetryDetailsOpen = true)}
				>
					<InfoIcon class="sf:h-4 sf:w-4" aria-hidden="true" />
					<span>What&nbsp;is&nbsp;recorded?</span>
				</Button>
				<label class="sf:flex sf:items-center sf:gap-3">
					<span class="sf:text-sm sf:font-semibold">{$telemetry.optIn ? 'On' : 'Off'}</span>
					<input
						type="checkbox"
						class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
						checked={$telemetry.optIn}
						disabled={$telemetry.loading || $telemetry.saving}
						onchange={toggle}
					/>
				</label>
			</div>
		</div>

		<div class="sf:mt-4 sf:text-xs sf:text-slate-500 sf:space-y-1">
			{#if $telemetry.updatedAt}
				<p>Preference saved {$telemetry.updatedAt}</p>
			{/if}
		</div>
		{#if $telemetry.loading}
			<div class="sf:mt-3">
				<StateTemplate
					variant="loading"
					title="Loading diagnostic preference"
					message="Reading the local consent setting."
					inline
					dense
					testId="settings-telemetry-loading-state"
				/>
			</div>
		{:else if $telemetry.lastError}
			<div class="sf:mt-3">
				<StateTemplate
					variant="error"
					title="Local diagnostic preference unavailable"
					message={$telemetry.lastError}
					actionLabel="Retry"
					onAction={() => {
						void telemetry.load();
					}}
					inline
					dense
					testId="settings-telemetry-error-state"
				/>
			</div>
		{/if}
	</div>

	{#if telemetryDetailsOpen}
		<div
			class="sf:fixed sf:inset-0 sf:z-[1000000] sf:flex sf:items-center sf:justify-center sf:bg-slate-950/50 sf:p-4"
			role="presentation"
			onclick={(event) => {
				if (event.currentTarget === event.target) telemetryDetailsOpen = false;
			}}
		>
			<div
				class="sf:w-full sf:max-w-3xl sf:overflow-hidden sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:shadow-2xl"
				role="dialog"
				aria-modal="true"
				aria-labelledby="telemetry-details-title"
			>
				<div
					class="sf:flex sf:flex-col sf:gap-3 sf:border-b sf:border-slate-200 sf:bg-slate-50 sf:p-5 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between"
				>
					<div>
						<p id="telemetry-details-title" class="sf:text-lg sf:font-semibold sf:text-slate-950">
							Local diagnostics and data privacy
						</p>
						<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
							Consent controls generation of local metadata-only diagnostic events. On-site logging
							must also be enabled to write them to the masked log.
						</p>
					</div>
					<Button
						type="button"
						variant="secondary"
						size="sm"
						onclick={() => (telemetryDetailsOpen = false)}
					>
						Close
					</Button>
				</div>

				<div class="sf:grid sf:gap-4 sf:p-5 sf:md:grid-cols-2">
					<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-4">
						<p class="sf:text-sm sf:font-semibold sf:text-slate-950">What is recorded locally</p>
						<p class="sf:mt-2 sf:text-sm sf:leading-6 sf:text-slate-600">
							When consent and on-site logging are both enabled, the masked log may include async
							job success or failure events, background processing warnings, plugin/runtime
							versions, provider path, action code, execution request ID, adapter, status, attempt
							counts, timing details, and sanitized error or warning codes. These events remain on
							this WordPress site.
						</p>
					</div>
					<div class="sf:rounded-lg sf:border sf:border-danger-100 sf:bg-danger-50 sf:p-4">
						<p class="sf:text-sm sf:font-semibold sf:text-danger-900">What is never recorded</p>
						<p class="sf:mt-2 sf:text-sm sf:leading-6 sf:text-danger-800">
							Form field contents, prompts, model outputs, raw error messages, visitor identifiers,
							API keys, saved provider secrets, and billing secrets are excluded from these events.
						</p>
					</div>
					<div class="sf:rounded-lg sf:border sf:border-primary-100 sf:bg-primary-50 sf:p-4">
						<p class="sf:text-sm sf:font-semibold sf:text-primary-900">Why it helps locally</p>
						<p class="sf:mt-2 sf:text-sm sf:leading-6 sf:text-primary-800">
							Local diagnostic events help administrators investigate reliability regressions, slow
							background processing, and action execution issues before sharing a support bundle.
						</p>
					</div>
					<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4">
						<p class="sf:text-sm sf:font-semibold sf:text-slate-950">Your control</p>
						<p class="sf:mt-2 sf:text-sm sf:leading-6 sf:text-slate-600">
							Turn consent off to stop generating new events, or turn on-site logging off to stop
							writing them to the masked log. Preferences are saved immediately. Nothing is sent
							off-site in this release.
						</p>
					</div>
				</div>
			</div>
		</div>
	{/if}

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-3"
	>
		<div
			class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center"
		>
			<div>
				<p class="sf:font-medium sf:text-slate-900">Enable on-site logging</p>
				<p class="sf:text-sm sf:text-slate-600">
					When local diagnostic consent above is also on, write masked logs to
					<code>wp-content/uploads/sentient-forms/logs</code> for support.
				</p>
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{$logging.enabled ? 'On' : 'Off'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
					checked={$logging.enabled}
					disabled={$logging.saving}
					onchange={toggleLogging}
				/>
			</label>
		</div>
		{#if $logging.loading}
			<StateTemplate
				variant="loading"
				title="Loading logging settings"
				message="Retrieving on-site logging preferences."
				inline
				dense
				testId="settings-logging-loading-state"
			/>
		{:else if $logging.lastError}
			<StateTemplate
				variant="error"
				title="Logging settings issue"
				message={$logging.lastError}
				actionLabel="Retry"
				onAction={() => {
					void logging.load();
				}}
				inline
				dense
				testId="settings-logging-error-state"
			/>
		{/if}
	</div>

	<form
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4"
		onsubmit={saveRetentionSettings}
	>
		<div class="sf:space-y-1">
			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
				<p class="sf:font-medium sf:text-slate-900">Local data retention</p>
				{#if retentionDirty}
					<span data-testid="settings-retention-unsaved">
						<Badge variant="warning">Unsaved changes</Badge>
					</span>
				{/if}
			</div>
			<p class="sf:text-sm sf:text-slate-600">
				Choose how long execution events and Submission Ledger records remain on this site. Changes
				affect future records only.
			</p>
		</div>

		{#if !governanceLoaded && executionLoading}
			<StateTemplate
				variant="loading"
				title="Loading retention controls"
				message="Retrieving Execution logs and Submission Ledger records settings before editing."
				inline
				testId="settings-retention-loading-state"
			/>
			<div class="sf:grid sf:grid-cols-1 sf:gap-4 sf:md:grid-cols-2">
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Execution logs</span>
					<select
						class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-3 sf:py-2 sf:text-slate-500"
						disabled
					>
						<option>Loading…</option>
					</select>
				</label>
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Submission Ledger records</span>
					<select
						class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-3 sf:py-2 sf:text-slate-500"
						disabled
					>
						<option>Loading…</option>
					</select>
				</label>
			</div>
		{:else if governanceLoadError}
			<StateTemplate
				variant="error"
				title="Retention controls unavailable"
				message={governanceLoadError}
				actionLabel="Retry"
				onAction={retryGovernanceLoad}
				inline
				testId="settings-retention-error-state"
			/>
			<div class="sf:grid sf:grid-cols-1 sf:gap-4 sf:md:grid-cols-2">
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Execution logs</span>
					<select
						class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-3 sf:py-2 sf:text-slate-500"
						disabled
					>
						<option>Unavailable</option>
					</select>
				</label>
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Submission Ledger records</span>
					<select
						class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:px-3 sf:py-2 sf:text-slate-500"
						disabled
					>
						<option>Unavailable</option>
					</select>
				</label>
			</div>
		{:else}
			<div class="sf:grid sf:grid-cols-1 sf:gap-4 sf:md:grid-cols-2">
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Execution logs</span>
					<select
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white sf:focus-visible:border-primary-600"
						bind:value={executionEventRetentionDays}
						onchange={markRetentionChanged}
						disabled={settingsWriteInFlight || executionLoading}
					>
						{#each retentionOptions as option}
							<option value={option.value}>{option.label}</option>
						{/each}
					</select>
				</label>

				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Submission Ledger records</span>
					<select
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white sf:focus-visible:border-primary-600"
						bind:value={submissionLedgerRetentionDays}
						onchange={markRetentionChanged}
						disabled={settingsWriteInFlight || executionLoading}
						data-testid="settings-submission-ledger-retention"
					>
						{#each retentionOptions as option}
							<option value={option.value}>{option.label}</option>
						{/each}
					</select>
					<span class="sf:text-xs sf:text-slate-500">
						Applies to future captured submissions. Existing expiry dates do not move when this
						changes.
					</span>
				</label>

				<label
					class="sf:flex sf:items-start sf:gap-3 sf:rounded-lg sf:border sf:border-slate-200 sf:p-3"
				>
					<input
						type="checkbox"
						class="sf:mt-1 sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
						bind:checked={storeFullAiOutputs}
						onchange={markRetentionChanged}
						disabled={settingsWriteInFlight || executionLoading}
						data-testid="settings-store-full-ai-outputs"
					/>
					<span class="sf:space-y-1">
						<span class="sf:block sf:text-sm sf:font-medium sf:text-slate-900">
							Store full AI outputs locally
						</span>
						<span class="sf:block sf:text-xs sf:text-slate-500">
							Off is the safer default. Turn this on when you need to inspect full model replies
							while building or debugging actions.
						</span>
					</span>
				</label>

				<label
					class="sf:flex sf:items-start sf:gap-3 sf:rounded-lg sf:border sf:border-slate-200 sf:p-3 sf:md:col-span-2"
				>
					<input
						type="checkbox"
						class="sf:mt-1 sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
						bind:checked={deleteDataOnUninstall}
						onchange={markRetentionChanged}
						disabled={settingsWriteInFlight || executionLoading}
					/>
					<span class="sf:space-y-1">
						<span class="sf:block sf:text-sm sf:font-medium sf:text-slate-900">
							Delete local data on uninstall
						</span>
						<span class="sf:block sf:text-xs sf:text-slate-500">
							Remove Sentient Forms tables and options when the plugin is uninstalled.
						</span>
					</span>
				</label>
			</div>

			<Alert variant={storeFullAiOutputs ? 'warning' : 'info'}>
				<p class="sf:font-semibold">
					{storeFullAiOutputs
						? 'Full replies will be stored locally'
						: 'Only derived action results are stored'}
				</p>
				<p class="sf:mt-1">
					{storeFullAiOutputs
						? 'Useful for tuning prompts and proving how an action behaved, but it keeps more model output on the site.'
						: 'Recommended for routine production use. Structured classifications, summaries, and action effects still remain available for review.'}
				</p>
			</Alert>

			<Alert variant="info">
				<p class="sf:font-semibold">High-sensitivity secret option</p>
				<p class="sf:mt-1">
					The default vault encrypts saved provider keys locally. If your site has stricter
					operational controls, switch to a WordPress constant or environment variable instead of
					storing the key in the database.
				</p>
			</Alert>

			{#if retentionFeedback}
				<Alert
					variant={retentionFeedback.type === 'success' ? 'success' : 'danger'}
					role={retentionFeedback.type === 'success' ? 'status' : 'alert'}
					aria-live={retentionFeedback.type === 'success' ? 'polite' : 'assertive'}
					data-testid={retentionFeedback.type === 'success'
						? 'settings-retention-success'
						: 'settings-retention-error'}
				>
					{retentionFeedback.message}
				</Alert>
			{/if}

			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3">
				<Button
					type="submit"
					loading={retentionSaving}
					disabled={!retentionDirty || settingsWriteInFlight || executionLoading}
				>
					{retentionSaving ? 'Saving…' : 'Save retention'}
				</Button>
				{#if manualRetentionHelp}
					<p class="sf:text-xs sf:text-slate-500" data-testid="settings-manual-retention-help">
						{manualRetentionHelp}
					</p>
				{/if}
			</div>
		{/if}
	</form>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4"
	>
		<div class="sf:space-y-1">
			<p class="sf:font-medium sf:text-slate-900">Execution controls</p>
			<p class="sf:text-sm sf:text-slate-600">
				Pause Sentient Forms execution globally or by form provider while keeping mappings editable.
			</p>
		</div>
		{#if executionLoading}
			<StateTemplate
				variant="loading"
				title="Loading execution controls"
				message="Fetching global and provider-level execution settings."
				inline
				dense
				testId="settings-execution-loading-state"
			/>
		{/if}

		<div
			class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:p-3 sf:bg-slate-50 sf:rounded-lg"
		>
			<div>
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">Global execution</p>
				<p class="sf:text-xs sf:text-slate-500">
					Keep all providers running. Turn this off to pause everything.
				</p>
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold"
					>{executionGlobalDisabled ? 'Paused' : 'Running'}</span
				>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
					aria-label="Global execution"
					checked={!executionGlobalDisabled}
					disabled={settingsWriteInFlight || executionLoading}
					onchange={(event) =>
						toggleExecutionGlobal(!(event.currentTarget as HTMLInputElement).checked)}
				/>
			</label>
		</div>

		{#if formSources.length > 0}
			<div class="sf:space-y-2">
				{#each formSources as source}
					<div
						class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:p-3 sf:border sf:border-slate-200 sf:rounded-lg"
					>
						<div class="sf:flex sf:items-center sf:gap-2">
							<p class="sf:text-sm sf:text-slate-800">{source.label}</p>
							<span class="sf:text-xs sf:text-slate-500">
								{source.isActive ? 'Plugin active' : 'Plugin inactive'}
							</span>
						</div>
						<label class="sf:flex sf:items-center sf:gap-3">
							<span class="sf:text-sm sf:font-semibold">
								{executionProviderDisabled[source.slug] ? 'Paused' : 'Running'}
							</span>
							<input
								type="checkbox"
								class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								checked={!executionProviderDisabled[source.slug]}
								disabled={settingsWriteInFlight || executionLoading || !source.isActive}
								onchange={(event) =>
									toggleExecutionProvider(
										source.slug,
										!(event.currentTarget as HTMLInputElement).checked
									)}
							/>
						</label>
					</div>
				{/each}
			</div>
		{/if}
	</div>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4"
	>
		<div class="sf:space-y-1">
			<p class="sf:font-medium sf:text-slate-900">Background retry policy</p>
			<p class="sf:text-sm sf:text-slate-600">
				Configure how many times Sentient Forms retries background jobs and how long it waits
				between attempts.
			</p>
		</div>

		<form class="sf:space-y-4" onsubmit={saveAsyncSettings}>
			<div class="sf:grid sf:grid-cols-1 sf:md:grid-cols-3 sf:gap-4">
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Max attempts</span>
					<input
						name="maxAttempts"
						type="number"
						min="1"
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white sf:focus-visible:border-primary-600"
						value={formState.maxAttempts}
						oninput={handleInput}
					/>
				</label>

				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Base delay (seconds)</span>
					<input
						name="baseDelaySeconds"
						type="number"
						min="5"
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white sf:focus-visible:border-primary-600"
						value={formState.baseDelaySeconds}
						oninput={handleInput}
					/>
				</label>

				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Max delay (seconds)</span>
					<input
						name="maxDelaySeconds"
						type="number"
						min={formState.baseDelaySeconds}
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:px-3 sf:py-2 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white sf:focus-visible:border-primary-600"
						value={formState.maxDelaySeconds}
						oninput={handleInput}
					/>
				</label>
			</div>

			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3">
				<Button type="submit" disabled={$asyncSettings.saving}>
					{$asyncSettings.saving ? 'Saving…' : 'Save settings'}
				</Button>
				{#if formDirty}
					<span class="sf:text-xs sf:text-slate-500">You have unsaved changes.</span>
				{/if}
			</div>
		</form>

		<div class="sf:text-xs sf:text-slate-500 sf:space-y-1">
			{#if $asyncSettings.updatedAt}
				<p>Last updated {$asyncSettings.updatedAt}</p>
			{/if}
			{#if $asyncSettings.updatedBy}
				<p>Updated by {$asyncSettings.updatedBy}</p>
			{/if}
		</div>
		{#if $asyncSettings.loading}
			<StateTemplate
				variant="loading"
				title="Loading background processing settings"
				message="Retrieving the current on-site retry policy."
				inline
				dense
				testId="settings-async-settings-loading-state"
			/>
		{:else if $asyncSettings.lastError}
			<StateTemplate
				variant="error"
				title="Background processing settings issue"
				message={describeAsyncSettingsError($asyncSettings.lastError)}
				actionLabel="Retry"
				onAction={() => {
					void asyncSettings.load();
				}}
				inline
				dense
				testId="settings-async-settings-error-state"
			/>
		{/if}
	</div>

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4"
	>
		<div class="sf:space-y-1">
			<p class="sf:font-medium sf:text-slate-900">Queue maintenance</p>
			<p class="sf:text-sm sf:text-slate-600">
				Clear stale or stuck jobs from the background processing queue.
			</p>
		</div>

		<div class="sf:flex sf:flex-col sf:gap-3">
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:p-3 sf:bg-slate-50 sf:rounded-lg"
			>
				<div>
					<p class="sf:text-sm sf:font-medium sf:text-slate-700">Queue depth</p>
					<p class="sf:text-xl sf:font-semibold sf:text-slate-900">{$asyncHealth.queue_depth}</p>
				</div>
				{#if $asyncHealth.oldest_run_at}
					<div class="sf:text-right">
						<p class="sf:text-sm sf:font-medium sf:text-slate-700">Oldest job</p>
						<p class="sf:text-sm sf:text-slate-600">
							{new Date($asyncHealth.oldest_run_at * 1000).toLocaleDateString()}
						</p>
					</div>
				{/if}
			</div>

			{#if $asyncHealth.lastPurgeResult}
				<div class="sf:text-sm sf:text-green-700 sf:bg-green-50 sf:p-2 sf:rounded-lg">
					✓ {$asyncHealth.lastPurgeResult.message}
				</div>
			{/if}

			<div class="sf:flex sf:flex-wrap sf:gap-3">
				<Button
					type="button"
					variant="secondary"
					class="sf:border-amber-500 sf:bg-amber-50 sf:text-amber-900 hover:sf:bg-amber-100"
					disabled={$asyncHealth.purging || $asyncHealth.queue_depth === 0}
					onclick={purgeStaleJobs}
				>
					{$asyncHealth.purging ? 'Purging…' : 'Purge stale jobs'}
				</Button>
				<Button
					type="button"
					variant="danger"
					disabled={$asyncHealth.purging || $asyncHealth.queue_depth === 0}
					onclick={() => (showClearConfirm = true)}
				>
					Clear all
				</Button>
			</div>

			<p class="sf:text-xs sf:text-slate-500">
				"Purge stale jobs" removes queued/failed jobs older than 1 week. "Clear all" removes all job
				metadata.
			</p>
		</div>
	</div>

	{#if showClearConfirm}
		<div
			class="sf:fixed sf:inset-0 sf:bg-black/50 sf:flex sf:items-center sf:justify-center sf:z-50"
		>
			<div class="sf:bg-white sf:rounded-xl sf:p-6 sf:max-w-sm sf:space-y-4 sf:shadow-xl">
				<p class="sf:font-semibold sf:text-slate-900">Clear all job metadata?</p>
				<p class="sf:text-sm sf:text-slate-600">
					This will remove all tracked background jobs, including successful ones. This action
					cannot be undone.
				</p>
				<div class="sf:flex sf:flex-wrap sf:gap-3 sf:justify-end">
					<Button type="button" variant="secondary" onclick={() => (showClearConfirm = false)}>
						Cancel
					</Button>
					<Button
						type="button"
						variant="danger"
						disabled={$asyncHealth.purging}
						onclick={clearAllJobs}
					>
						{$asyncHealth.purging ? 'Clearing…' : 'Clear all'}
					</Button>
				</div>
			</div>
		</div>
	{/if}
</section>
