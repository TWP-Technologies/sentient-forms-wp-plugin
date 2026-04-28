<script lang="ts">
	import { run } from 'svelte/legacy';

	import { onMount } from 'svelte';
	import { telemetryStore } from '$lib/stores/telemetry.svelte';
	import { asyncSettingsStore } from '$lib/stores/async-settings.svelte';
	import { asyncHealthStore } from '$lib/stores/async-health.svelte';
	import { loggingStore } from '$lib/stores/logging.svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { notifications } from '$lib/stores/notifications';
	import { Alert, Badge, Button, StateTemplate } from '$lib/components/ui';
	import type { FormSourceSummary, PluginSettingsResponse } from '$lib/api/types';

	type PrivacyPresetId =
		| 'balanced'
		| 'privacy_focused'
		| 'maximum_privacy'
		| 'maximum_visibility';

	interface PrivacyPresetDefinition {
		label: string;
		executionEventRetentionDays: number;
		deleteDataOnUninstall: boolean;
		storeFullAiOutputs: boolean;
		enableLogging: boolean;
	}

	const telemetry = telemetryStore;
	const asyncSettings = asyncSettingsStore;
	const asyncHealth = asyncHealthStore;
	const logging = loggingStore;
	const client = createClientFromConfig();
	const runtime = typeof window === 'undefined' ? undefined : window.sentientFormsConfig;
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
	let executionEventRetentionDays = $state(90);
	let deleteDataOnUninstall = $state(true);
	let storeFullAiOutputs = $state(false);
	let privacySetupProfile = $state<NonNullable<PluginSettingsResponse['privacy_setup_profile']>>(
		'balanced'
	);
	let privacySetupCompletedAt = $state<string | null>(null);

	const privacyPresetDefinitions: Record<PrivacyPresetId, PrivacyPresetDefinition> = {
		balanced: {
			label: 'Balanced',
			executionEventRetentionDays: 90,
			deleteDataOnUninstall: true,
			storeFullAiOutputs: false,
			enableLogging: false
		},
		privacy_focused: {
			label: 'Privacy focused',
			executionEventRetentionDays: 30,
			deleteDataOnUninstall: true,
			storeFullAiOutputs: false,
			enableLogging: false
		},
		maximum_privacy: {
			label: 'Maximum privacy',
			executionEventRetentionDays: 7,
			deleteDataOnUninstall: true,
			storeFullAiOutputs: false,
			enableLogging: false
		},
		maximum_visibility: {
			label: 'Maximum visibility',
			executionEventRetentionDays: 180,
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
		{ value: 0, label: 'Manual cleanup only' }
	];

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
		isKnownPrivacyProfile(privacySetupProfile) ? privacyPresetDefinitions[privacySetupProfile] : null
	);
	let privacyProfileCustomized = $derived(
		null !== activePrivacyPreset &&
			(activePrivacyPreset.executionEventRetentionDays !== executionEventRetentionDays ||
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

		const handleSettingsUpdate = (event: Event) => {
			const customEvent = event as CustomEvent<PluginSettingsResponse>;
			if (!customEvent.detail || typeof customEvent.detail !== 'object') return;
			syncGovernanceSettings(customEvent.detail);
			void logging.load();
		};

		window.addEventListener('sentient-forms:settings-updated', handleSettingsUpdate as EventListener);

		return () => {
			window.removeEventListener(
				'sentient-forms:settings-updated',
				handleSettingsUpdate as EventListener
			);
		};
	});

	function syncGovernanceSettings(settings: PluginSettingsResponse): void {
		executionGlobalDisabled = Boolean(settings.execution_global_disabled);
		executionProviderDisabled = normalizeProviderDisabledMap(
			settings.execution_provider_disabled,
			formSources
		);
		executionEventRetentionDays =
			typeof settings.execution_event_retention_days === 'number'
				? settings.execution_event_retention_days
				: 90;
		deleteDataOnUninstall = Boolean(settings.delete_data_on_uninstall);
		storeFullAiOutputs = Boolean(settings.store_full_ai_outputs);
		governanceLoggingEnabled =
			typeof settings.enable_logging === 'boolean' ? settings.enable_logging : null;
		privacySetupProfile = (settings.privacy_setup_profile ??
			'balanced') as NonNullable<PluginSettingsResponse['privacy_setup_profile']>;
		privacySetupCompletedAt =
			typeof settings.privacy_setup_completed_at === 'string'
				? settings.privacy_setup_completed_at
				: null;
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
			syncGovernanceSettings(settings);
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
			syncGovernanceSettings(settings);
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
		retentionSaving = true;
		try {
			const settings = await client.updateSettings(
				{
					execution_event_retention_days: executionEventRetentionDays,
					delete_data_on_uninstall: deleteDataOnUninstall,
					store_full_ai_outputs: storeFullAiOutputs
				},
				{ showNotifications: false }
			);
			syncGovernanceSettings(settings);
			notifications.success('Local data retention saved');
		} catch {
			notifications.error('Unable to update local data retention');
		} finally {
			retentionSaving = false;
		}
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
		const result = await asyncHealth.purge({ status: 'queued,failed', olderThan: 10080 });
		if (result) {
			console.log('Purged stale jobs:', result);
		}
	}

	async function clearAllJobs() {
		const result = await asyncHealth.purge({ clearAll: true });
		if (result) {
			console.log('Cleared all jobs:', result);
		}
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
</script>

<section class="sf:min-w-0 sf:space-y-6 sf:max-w-3xl">
	<header class="sf:space-y-2">
		<h1 class="sf:text-2xl sf:font-semibold sf:text-slate-900">Privacy &amp; Local Processing</h1>
		<p class="sf:text-slate-600 sf:text-sm">
			Choose how much Sentient Forms keeps locally, how much visibility you want while tuning
			actions, and how the plugin should behave when it is removed.
		</p>
	</header>

	<div class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4">
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
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:lg:flex-row sf:lg:items-center">
				<div class="sf:space-y-2">
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<p class="sf:text-base sf:font-semibold sf:text-slate-900">Privacy &amp; visibility profile</p>
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
							Start with a preset when you want the plugin to feel obvious instead of technical.
							The assistant changes retention, uninstall cleanup, local logging, and whether full AI
							outputs are kept.
						{/if}
					</p>
				</div>
				<Button type="button" variant="secondary" onclick={openPrivacySetupAssistant}>
					{privacySetupCompletedAt ? 'Reopen setup assistant' : 'Finish guided setup'}
				</Button>
			</div>

			<div class="sf:grid sf:gap-3 sf:sm:grid-cols-2 sf:xl:grid-cols-4">
				<div
					class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4"
					data-testid="settings-profile-execution-history"
				>
					<p class="sf:text-xs sf:font-medium sf:text-slate-500">Execution history</p>
					<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
						{executionEventRetentionDays === 0
							? 'Manual cleanup only'
							: `${executionEventRetentionDays} days`}
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

	{#if $asyncHealth.warnings.length}
		<div class="sf:rounded-xl sf:border sf:border-amber-200 sf:bg-amber-50 sf:p-4 sf:space-y-2">
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
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

	<div class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm">
		<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
			<div>
				<p class="sf:font-medium sf:text-slate-900">Enable telemetry sharing</p>
				<p class="sf:text-sm sf:text-slate-600">
					Share aggregated action metrics and local reliability diagnostics to help Sentient Forms
					improve reliability.
				</p>
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{$telemetry.optIn ? 'On' : 'Off'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
					checked={$telemetry.optIn}
					disabled={$telemetry.saving}
					onchange={toggle}
				/>
			</label>
		</div>

			<div class="sf:mt-4 sf:text-xs sf:text-slate-500 sf:space-y-1">
				{#if $telemetry.syncedAt}
					<p>Synced {$telemetry.syncedAt}</p>
				{/if}
				{#if $telemetry.remoteUpdatedAt}
					<p>Remote consent record updated {$telemetry.remoteUpdatedAt}</p>
				{/if}
			</div>
			{#if $telemetry.loading}
				<div class="sf:mt-3">
					<StateTemplate
						variant="loading"
						title="Loading telemetry settings"
						message="Syncing the latest telemetry consent state."
						inline
						dense
						testId="settings-telemetry-loading-state"
					/>
				</div>
			{:else if $telemetry.lastError}
				<div class="sf:mt-3">
					<StateTemplate
						variant="error"
						title="Telemetry sync issue"
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

	<div
		class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-3"
	>
		<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center">
			<div>
				<p class="sf:font-medium sf:text-slate-900">Enable on-site logging</p>
				<p class="sf:text-sm sf:text-slate-600">
					Write masked diagnostic logs to <code>wp-content/uploads/sentient-forms/logs</code> for support.
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
			<p class="sf:font-medium sf:text-slate-900">Local data retention</p>
			<p class="sf:text-sm sf:text-slate-600">
				Choose how long local execution history stays available and whether Sentient Forms should
				keep full AI responses for inspection.
			</p>
		</div>

		{#if !governanceLoaded && executionLoading}
			<StateTemplate
				variant="loading"
				title="Loading retention controls"
				message="Retrieving the saved execution history and uninstall defaults before editing."
				inline
				testId="settings-retention-loading-state"
			/>
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
		{:else}
			<div class="sf:grid sf:grid-cols-1 sf:gap-4 sf:md:grid-cols-2">
				<label class="sf:flex sf:flex-col sf:gap-1">
					<span class="sf:text-sm sf:font-medium sf:text-slate-900">Execution logs</span>
					<select
						class="sf:rounded-lg sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white sf:focus-visible:border-primary-600"
						bind:value={executionEventRetentionDays}
						disabled={retentionSaving || executionLoading}
					>
						{#each retentionOptions as option}
							<option value={option.value}>{option.label}</option>
						{/each}
					</select>
				</label>

				<label class="sf:flex sf:items-start sf:gap-3 sf:rounded-lg sf:border sf:border-slate-200 sf:p-3">
					<input
						type="checkbox"
						class="sf:mt-1 sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
						bind:checked={storeFullAiOutputs}
						disabled={retentionSaving || executionLoading}
						data-testid="settings-store-full-ai-outputs"
					/>
					<span class="sf:space-y-1">
						<span class="sf:block sf:text-sm sf:font-medium sf:text-slate-900">
							Store full AI outputs locally
						</span>
						<span class="sf:block sf:text-xs sf:text-slate-500">
							Off is the safer default. Turn this on when you need to inspect full model replies while
							building or debugging actions.
						</span>
					</span>
				</label>

				<label class="sf:flex sf:items-start sf:gap-3 sf:rounded-lg sf:border sf:border-slate-200 sf:p-3 sf:md:col-span-2">
					<input
						type="checkbox"
						class="sf:mt-1 sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
						bind:checked={deleteDataOnUninstall}
						disabled={retentionSaving || executionLoading}
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
					{storeFullAiOutputs ? 'Full replies will be stored locally' : 'Only derived action results are stored'}
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
					The default vault encrypts saved provider keys locally. If your site has stricter operational
					controls, switch to a WordPress constant or environment variable instead of storing the key in
					the database.
				</p>
			</Alert>

			<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3">
				<Button type="submit" disabled={retentionSaving || executionLoading}>
					{retentionSaving ? 'Saving…' : 'Save retention'}
				</Button>
				<p class="sf:text-xs sf:text-slate-500">
					Manual cleanup only keeps new execution logs until an administrator removes them or changes this
					setting.
				</p>
			</div>
		{/if}
	</form>

	<div class="sf:rounded-xl sf:border sf:border-slate-200 sf:bg-white sf:p-6 sf:shadow-sm sf:space-y-4">
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

		<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:p-3 sf:bg-slate-50 sf:rounded-lg">
			<div>
				<p class="sf:text-sm sf:font-medium sf:text-slate-700">Global execution</p>
				<p class="sf:text-xs sf:text-slate-500">
					Keep all providers running. Turn this off to pause everything.
				</p>
			</div>
			<label class="sf:flex sf:items-center sf:gap-3">
				<span class="sf:text-sm sf:font-semibold">{executionGlobalDisabled ? 'Paused' : 'Running'}</span>
				<input
					type="checkbox"
					class="sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
					checked={!executionGlobalDisabled}
					disabled={executionSaving || executionLoading}
					onchange={(event) =>
						toggleExecutionGlobal(!(event.currentTarget as HTMLInputElement).checked)}
				/>
			</label>
		</div>

		{#if formSources.length > 0}
			<div class="sf:space-y-2">
				{#each formSources as source}
					<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:p-3 sf:border sf:border-slate-200 sf:rounded-lg">
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
								disabled={executionSaving || executionLoading || !source.isActive}
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
				Configure how many times Sentient Forms retries background jobs and how long it waits between
				attempts.
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
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-3 sf:sm:flex-row sf:sm:items-center sf:p-3 sf:bg-slate-50 sf:rounded-lg">
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
					This will remove all tracked background jobs, including successful ones. This action cannot be
					undone.
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
