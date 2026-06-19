<script lang="ts">
	import { onDestroy, tick } from 'svelte';
	import { toast } from 'sonner-svelte';
	import type {
		ModelSelection,
		PluginSettingsResponse,
		SiteContextStatusResponse
	} from '$lib/api/types';
	import SiteContextNotices from '$lib/components/site-context-notices.svelte';
	import SiteContextSetupPanel from '$lib/components/site-context-setup-panel.svelte';
	import { Alert, Badge, Button } from '$lib/components/ui';
	import { appHref } from '$lib/navigation';
	import { parseSiteContextStatusResponse } from '$lib/schemas/site-context';
	import { notifications } from '$lib/stores/notifications';
	import {
		DEFAULT_SITE_CONTEXT_MODEL_SELECTION,
		DEFAULT_SITE_CONTEXT_REFRESH_DAYS,
		SITE_CONTEXT_REFRESH_DAY_OPTIONS,
		compactSiteContextModelSelection,
		normalizeSiteContextResponse,
		siteContextGenerateDisabledMessage,
		siteContextGenerationFailureIsFresh,
		siteContextGenerationJobIsActive,
		siteContextModelSelectionChanged,
		siteContextStatusLabel
	} from '$lib/utils/site-context';
	import { wpFetch } from '$lib/wp';

	type PrivacyPresetId = 'balanced' | 'privacy_focused' | 'maximum_privacy' | 'maximum_visibility';
	type SiteContextBadgeVariant = 'neutral' | 'success' | 'warning';
	type ToastId = string;

	const SITE_CONTEXT_GENERATION_POLL_INTERVAL_MS = 3000;
	const SITE_CONTEXT_GENERATION_POLL_MAX_BACKOFF_MS = 15000;
	const SITE_CONTEXT_GENERATION_POLL_RETRY_LIMIT = 3;

	interface PrivacyPresetDefinition {
		id: PrivacyPresetId;
		label: string;
		kicker: string;
		description: string;
		retentionLabel: string;
		fullOutputLabel: string;
		loggingLabel: string;
	}

	interface Props {
		open?: boolean;
		saving?: boolean;
		applyError?: string | null;
		settings?: PluginSettingsResponse | null;
		dismissible?: boolean;
		onapply?: (preset: PrivacyPresetId) => void;
		onclose?: () => void;
	}

	const presetDefinitions: PrivacyPresetDefinition[] = [
		{
			id: 'balanced',
			label: 'Balanced',
			kicker: 'Recommended',
			description:
				'Good default for most sites. Keeps useful troubleshooting without saving full AI replies.',
			retentionLabel: '90-day execution logs',
			fullOutputLabel: 'Full AI outputs off',
			loggingLabel: 'On-site logging off'
		},
		{
			id: 'privacy_focused',
			label: 'Privacy focused',
			kicker: 'Lower retention',
			description:
				'Cuts back local history while keeping enough detail to verify that actions are working.',
			retentionLabel: '30-day execution logs',
			fullOutputLabel: 'Full AI outputs off',
			loggingLabel: 'On-site logging off'
		},
		{
			id: 'maximum_privacy',
			label: 'Maximum privacy',
			kicker: 'Minimum storage',
			description:
				'Stores the least local AI detail after actions finish. Best for sensitive intake flows.',
			retentionLabel: '7-day execution logs',
			fullOutputLabel: 'Full AI outputs off',
			loggingLabel: 'On-site logging off'
		},
		{
			id: 'maximum_visibility',
			label: 'Maximum visibility',
			kicker: 'For tuning and support',
			description:
				'Keeps more local detail so you can inspect outputs, compare prompts, and debug setups faster.',
			retentionLabel: '180-day execution logs',
			fullOutputLabel: 'Full AI outputs on',
			loggingLabel: 'On-site logging on'
		}
	];

	let {
		open = false,
		saving = false,
		applyError = null,
		settings = null,
		dismissible = false,
		onapply,
		onclose
	}: Props = $props();

	function initialPreset(
		settingsValue: PluginSettingsResponse | null | undefined
	): PrivacyPresetId {
		const candidate = settingsValue?.privacy_setup_profile;
		return presetDefinitions.some((preset) => preset.id === candidate)
			? (candidate as PrivacyPresetId)
			: 'balanced';
	}

	let selectedPreset = $state<PrivacyPresetId>(initialPreset(settings));
	let siteContextLoading = $state(false);
	let siteContextSaving = $state(false);
	let siteContextGenerating = $state(false);
	let siteContextError = $state<string | null>(null);
	let siteContextApplyError = $state<string | null>(null);
	let siteContextStatus = $state<SiteContextStatusResponse | null>(null);
	let siteContextTouched = $state(false);
	let siteContextText = $state('');
	let siteContextConsent = $state(false);
	let siteContextAutoRefresh = $state(false);
	let siteContextRefreshDays = $state(DEFAULT_SITE_CONTEXT_REFRESH_DAYS);
	let siteContextModelSelection = $state<ModelSelection>(DEFAULT_SITE_CONTEXT_MODEL_SELECTION);
	let applyErrorRegion = $state<HTMLDivElement | null>(null);
	let siteContextGenerationPollTimer: ReturnType<typeof setTimeout> | null = null;
	let siteContextGenerationPollFailures = 0;
	let siteContextGenerationToastId: ToastId | null = null;
	let siteContextGenerationToastActive = false;
	let selectedDefinition = $derived(
		presetDefinitions.find((preset) => preset.id === selectedPreset) ?? presetDefinitions[0]
	);
	let completedAtLabel = $derived(settings?.privacy_setup_completed_at ?? null);
	let siteContextHasChanges = $derived.by(() => {
		if (!siteContextStatus) return false;
		return (
			siteContextText !== (siteContextStatus.context?.summary_text ?? '') ||
			siteContextConsent !== (siteContextStatus.settings.consent_status === 'granted') ||
			siteContextAutoRefresh !== siteContextStatus.settings.auto_refresh_enabled ||
			siteContextRefreshDays !== siteContextStatus.settings.auto_refresh_days ||
			siteContextModelSelectionChanged(
				siteContextModelSelection,
				siteContextStatus.settings.generation_model_selection
			)
		);
	});
	let siteContextSetupLabel = $derived(
		siteContextStatus ? siteContextStatusLabel(siteContextStatus) : 'Loading'
	);
	let siteContextSetupVariant = $derived(siteContextBadgeVariant(siteContextStatus));
	let generateDisabledMessage = $derived(
		siteContextGenerateDisabledMessage(siteContextStatus, siteContextConsent, siteContextHasChanges)
	);
	let siteContextShouldSaveBeforeApply = $derived(siteContextTouched && siteContextHasChanges);
	let footerApplyError = $derived(applyError ?? siteContextApplyError);
	let siteContextGenerationJobActive = $derived(
		siteContextGenerationJobIsActive(siteContextStatus)
	);
	let canGenerateSiteContext = $derived(
		siteContextConsent &&
			!siteContextGenerating &&
			!siteContextGenerationJobActive &&
			!generateDisabledMessage &&
			siteContextStatus?.generation_access.can_generate === true
	);
	let generateSetupHref = $derived(
		generateDisabledMessage && !siteContextShouldSaveBeforeApply
			? siteContextGenerationSetupHref(siteContextStatus?.generation_access.setup_target)
			: null
	);
	let generateSetupLabel = $derived(
		siteContextStatus?.generation_access.setup_target === 'licensing'
			? 'Open billing'
			: 'Set up provider'
	);

	$effect(() => {
		if (!open) return;
		selectedPreset = initialPreset(settings);
		siteContextTouched = false;
		siteContextApplyError = null;
		void loadSiteContext();
	});

	$effect(() => {
		if (open) return;
		clearSiteContextGenerationPoll();
		dismissSiteContextGenerationToast();
	});

	$effect(() => {
		if (!open || !footerApplyError) return;
		void focusApplyError();
	});

	function handleBackdropClick(event: MouseEvent): void {
		if (!dismissible || saving) return;
		if (event.target !== event.currentTarget) return;
		onclose?.();
	}

	function handleKeydown(event: KeyboardEvent): void {
		if (!open || !dismissible || saving) return;
		if (event.key === 'Escape') {
			event.preventDefault();
			onclose?.();
		}
	}

	async function applySelectedPreset(): Promise<void> {
		if (siteContextShouldSaveBeforeApply && !(await saveSiteContext('apply'))) {
			return;
		}
		onapply?.(selectedPreset);
	}

	async function useBalancedDefaults(): Promise<void> {
		if (siteContextShouldSaveBeforeApply && !(await saveSiteContext('apply'))) {
			return;
		}
		onapply?.('balanced');
	}

	function parseSiteContextResponse(
		response: SiteContextStatusResponse
	): SiteContextStatusResponse {
		return parseSiteContextStatusResponse(normalizeSiteContextResponse(response));
	}

	function syncSiteContext(
		next: SiteContextStatusResponse,
		options: { preserveLocalEdits?: boolean } = {}
	): void {
		const shouldPreserveLocalEdits = options.preserveLocalEdits === true && siteContextHasChanges;
		const previousStatus = siteContextStatus;
		siteContextError = null;
		siteContextStatus = next;
		if (!shouldPreserveLocalEdits) {
			siteContextText = next.context?.summary_text ?? '';
			siteContextConsent = next.settings.consent_status === 'granted';
			siteContextAutoRefresh = next.settings.auto_refresh_enabled;
			siteContextRefreshDays = next.settings.auto_refresh_days || DEFAULT_SITE_CONTEXT_REFRESH_DAYS;
			siteContextModelSelection =
				next.settings.generation_model_selection ?? DEFAULT_SITE_CONTEXT_MODEL_SELECTION;
			siteContextTouched = false;
			siteContextApplyError = null;
		}
		updateSiteContextGenerationJobState(next, previousStatus);
	}

	function clearSiteContextGenerationPoll(): void {
		if (!siteContextGenerationPollTimer) return;
		clearTimeout(siteContextGenerationPollTimer);
		siteContextGenerationPollTimer = null;
	}

	function scheduleSiteContextGenerationPoll(
		delay = SITE_CONTEXT_GENERATION_POLL_INTERVAL_MS
	): void {
		if (!open || siteContextGenerationPollTimer) return;
		siteContextGenerationPollTimer = setTimeout(() => {
			siteContextGenerationPollTimer = null;
			void pollSiteContextGenerationStatus();
		}, delay);
	}

	function ensureSiteContextGenerationToast(nextStatus: SiteContextStatusResponse): void {
		const job = nextStatus.generation_job;
		const model = job?.model ? `Model: ${job.model}` : undefined;
		siteContextGenerationToastActive = true;
		siteContextGenerationToastId = toast.loading(
			'Site Context generation is running in the background.',
			{
				id: siteContextGenerationToastId ?? undefined,
				description: model,
				duration: Number.POSITIVE_INFINITY
			}
		);
	}

	function completeSiteContextGenerationToast(message: string): void {
		if (!siteContextGenerationToastActive) return;
		toast.success(message, {
			id: siteContextGenerationToastId ?? undefined
		});
		siteContextGenerationToastActive = false;
		siteContextGenerationToastId = null;
	}

	function dismissSiteContextGenerationToast(): void {
		if (siteContextGenerationToastId !== null) {
			toast.dismiss(siteContextGenerationToastId);
		}
		siteContextGenerationToastActive = false;
		siteContextGenerationToastId = null;
	}

	function failSiteContextGenerationToast(message: string): void {
		if (siteContextGenerationToastActive) {
			toast.error(message, {
				id: siteContextGenerationToastId ?? undefined
			});
			siteContextGenerationToastActive = false;
			siteContextGenerationToastId = null;
			return;
		}

		toast.error(message);
	}

	function updateSiteContextGenerationJobState(
		nextStatus: SiteContextStatusResponse,
		previousStatus: SiteContextStatusResponse | null
	): void {
		const job = nextStatus.generation_job;
		if (job?.status === 'queued' || job?.status === 'running') {
			siteContextGenerating = true;
			siteContextError = null;
			ensureSiteContextGenerationToast(nextStatus);
			scheduleSiteContextGenerationPoll();
			return;
		}

		clearSiteContextGenerationPoll();
		siteContextGenerationPollFailures = 0;
		siteContextGenerating = false;

		if (!job) {
			dismissSiteContextGenerationToast();
			return;
		}

		if (job?.status === 'succeeded') {
			completeSiteContextGenerationToast('Site Context generated.');
			return;
		}

		if (job?.status === 'failed' && siteContextGenerationFailureIsFresh(previousStatus, nextStatus)) {
			const message = job.error ?? 'Unable to generate Site Context.';
			siteContextError = message;
			failSiteContextGenerationToast(message);
		}
	}

	async function pollSiteContextGenerationStatus(): Promise<void> {
		if (!open) return;
		try {
			const next = parseSiteContextResponse(
				await wpFetch<SiteContextStatusResponse>('site-context')
			);
			if (!open) return;
			siteContextGenerationPollFailures = 0;
			syncSiteContext(next, { preserveLocalEdits: true });
		} catch (error) {
			if (!open) return;
			console.error('Failed to refresh Site Context generation status', error);
			if (siteContextGenerationJobIsActive(siteContextStatus)) {
				siteContextGenerationPollFailures += 1;
				if (siteContextGenerationPollFailures <= SITE_CONTEXT_GENERATION_POLL_RETRY_LIMIT) {
					scheduleSiteContextGenerationPoll(
						Math.min(
							SITE_CONTEXT_GENERATION_POLL_INTERVAL_MS * siteContextGenerationPollFailures,
							SITE_CONTEXT_GENERATION_POLL_MAX_BACKOFF_MS
						)
					);
					return;
				}
			}
			const message = readableError(error, 'Unable to refresh Site Context generation status.');
			siteContextError = message;
			siteContextGenerating = false;
			failSiteContextGenerationToast(message);
		}
	}

	function siteContextBadgeVariant(
		nextStatus: SiteContextStatusResponse | null
	): SiteContextBadgeVariant {
		if (nextStatus?.status === 'ready') return 'success';
		if (nextStatus?.status === 'declined') return 'neutral';
		return 'warning';
	}

	async function loadSiteContext(): Promise<void> {
		siteContextLoading = true;
		siteContextError = null;
		try {
			syncSiteContext(
				parseSiteContextResponse(await wpFetch<SiteContextStatusResponse>('site-context'))
			);
		} catch (error) {
			console.error('Failed to load Site Context setup state', error);
			siteContextError = readableError(error, 'Unable to load Site Context setup state.');
		} finally {
			siteContextLoading = false;
		}
	}

	function siteContextPayload() {
		return {
			summary_text: siteContextText,
			auto_include: true,
			pii_ack: true,
			consent_status: siteContextConsent ? 'granted' : 'unset',
			auto_refresh_enabled: siteContextConsent && siteContextAutoRefresh,
			auto_refresh_days: siteContextRefreshDays,
			generation_model_selection: compactSiteContextModelSelection(siteContextModelSelection)
		};
	}

	function markSiteContextTouched(): void {
		siteContextTouched = true;
		siteContextApplyError = null;
	}

	async function focusApplyError(): Promise<void> {
		await tick();
		applyErrorRegion?.scrollIntoView({ block: 'nearest' });
		applyErrorRegion?.focus();
	}

	async function saveSiteContext(source: 'manual' | 'apply' = 'manual'): Promise<boolean> {
		siteContextSaving = true;
		siteContextError = null;
		siteContextApplyError = null;
		try {
			const response = await wpFetch<SiteContextStatusResponse>('site-context', {
				method: 'PUT',
				body: JSON.stringify(siteContextPayload()),
				showNotifications: false
			});
			syncSiteContext(parseSiteContextResponse(response));
			notifications.success('Site Context setup saved');
			return true;
		} catch (error) {
			console.error('Failed to save Site Context setup', error);
			const message = readableError(
				error,
				'Unable to save Site Context setup. Apply is paused until this is saved.'
			);
			siteContextError = message;
			if (source === 'apply') {
				siteContextApplyError = message;
				await focusApplyError();
			}
			notifications.error(message);
			return false;
		} finally {
			siteContextSaving = false;
		}
	}

	async function generateSiteContext(): Promise<void> {
		if (!canGenerateSiteContext) {
			notifications.warning(
				generateDisabledMessage ??
					'Site Context generation is not ready. Save setup and configure a paid provider before generating.'
			);
			return;
		}
		siteContextGenerating = true;
		siteContextError = null;
		try {
			const response = await wpFetch<SiteContextStatusResponse>('site-context/generate', {
				method: 'POST',
				body: JSON.stringify(siteContextPayload()),
				showNotifications: false
			});
			syncSiteContext(parseSiteContextResponse(response));
		} catch (error) {
			console.error('Failed to generate Site Context', error);
			siteContextError = readableError(error, 'Unable to generate Site Context.');
			notifications.error(siteContextError);
		} finally {
			if (!siteContextGenerationJobIsActive(siteContextStatus)) {
				siteContextGenerating = false;
			}
		}
	}

	function siteContextGenerationSetupHref(target: string | null | undefined): string | null {
		if (target === 'providers') return appHref('/providers');
		if (target === 'licensing') return appHref('/licensing');
		return null;
	}

	function readableError(error: unknown, fallback: string): string {
		const payload =
			error && typeof error === 'object' && 'payload' in error
				? (error as { payload?: unknown }).payload
				: null;

		if (payload && typeof payload === 'object') {
			const message = (payload as { message?: unknown }).message;
			if (typeof message === 'string' && message.trim().length > 0) return message;

			const nested = (payload as { error?: { message?: unknown } }).error?.message;
			if (typeof nested === 'string' && nested.trim().length > 0) return nested;
		}

		if (error instanceof Error && error.message !== 'Request failed') return error.message;
		return fallback;
	}

	onDestroy(() => {
		clearSiteContextGenerationPoll();
		dismissSiteContextGenerationToast();
	});
</script>

<svelte:window onkeydown={handleKeydown} />

{#if open}
	<div
		class="sf:fixed sf:inset-0 sf:z-[1200] sf:flex sf:items-start sf:justify-center sf:overflow-y-auto sf:bg-slate-950/45 sf:p-4 sf:sm:p-6"
		role="presentation"
		onclick={handleBackdropClick}
		data-testid="privacy-setup-assistant-backdrop"
	>
		<div
			class="sf:my-6 sf:w-full sf:max-w-5xl sf:overflow-hidden sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:shadow-2xl"
			role="dialog"
			aria-modal="true"
			aria-labelledby="privacy-setup-assistant-title"
			data-testid="privacy-setup-assistant"
		>
			<div
				class="sf:border-b sf:border-slate-200 sf:bg-slate-950 sf:px-5 sf:py-5 sf:text-white sf:sm:px-6"
			>
				<div class="sf:flex sf:flex-wrap sf:items-start sf:justify-between sf:gap-3">
					<div class="sf:max-w-3xl sf:space-y-2">
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
							<Badge variant="info">Privacy setup</Badge>
							{#if completedAtLabel}
								<Badge variant="neutral">Setup saved</Badge>
							{/if}
						</div>
						<h2
							id="privacy-setup-assistant-title"
							class="sf:text-xl sf:font-semibold sf:!text-white"
						>
							Choose how much Sentient Forms keeps locally
						</h2>
						<p class="sf:max-w-2xl sf:text-sm sf:text-slate-200">
							These defaults change how long execution history stays on this WordPress site, whether
							full AI replies are saved, and what gets removed on uninstall. You can change them
							later in Settings.
						</p>
					</div>
					{#if dismissible}
						<Button
							variant="secondary"
							size="sm"
							class="sf:border-white/20 sf:bg-white/10 sf:text-white sf:hover:bg-white/15"
							disabled={saving}
							onclick={() => onclose?.()}
						>
							Close
						</Button>
					{/if}
				</div>
			</div>

			<div class="sf:space-y-6 sf:p-5 sf:sm:p-6">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<span
						class="sf:inline-flex sf:h-7 sf:w-7 sf:items-center sf:justify-center sf:rounded-full sf:bg-primary-600 sf:text-sm sf:font-semibold sf:text-white"
					>
						1
					</span>
					<div>
						<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
							Choose local retention defaults
						</p>
						<p class="sf:text-xs sf:text-slate-500">
							Pick the baseline that matches this site's risk and support needs.
						</p>
					</div>
				</div>
				<div class="sf:grid sf:gap-3 sf:lg:grid-cols-4">
					{#each presetDefinitions as preset}
						<Button
							type="button"
							variant="secondary"
							size="md"
							class={`sf:h-full sf:w-full sf:flex-col sf:items-start sf:rounded-lg sf:p-4 sf:text-left sf:transition-all ${
								selectedPreset === preset.id
									? 'sf:border-primary-600 sf:bg-primary-50 sf:shadow-md sf:ring-2 sf:ring-primary-500 sf:ring-offset-2 sf:ring-offset-white sf:hover:border-primary-600 sf:hover:bg-primary-50'
									: 'sf:border-slate-200 sf:bg-white sf:hover:border-slate-300 sf:hover:bg-slate-50'
							}`}
							aria-pressed={selectedPreset === preset.id}
							onclick={() => {
								selectedPreset = preset.id;
							}}
							data-testid={`privacy-setup-preset-${preset.id}`}
						>
							<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
								<p class="sf:text-base sf:font-semibold sf:text-slate-900">{preset.label}</p>
								<Badge variant={preset.id === 'balanced' ? 'success' : 'neutral'}>
									{preset.kicker}
								</Badge>
								{#if selectedPreset === preset.id}
									<Badge variant="info">Selected</Badge>
								{/if}
							</div>
							<p class="sf:mt-2 sf:text-sm sf:text-slate-600">{preset.description}</p>
							<ul class="sf:mt-4 sf:space-y-2 sf:text-xs sf:text-slate-500">
								<li>{preset.retentionLabel}</li>
								<li>{preset.fullOutputLabel}</li>
								<li>{preset.loggingLabel}</li>
							</ul>
						</Button>
					{/each}
				</div>

				<div class="sf:space-y-4">
					<div
						class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-4 sf:space-y-4"
					>
						<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-3">
							<span
								class="sf:inline-flex sf:h-7 sf:w-7 sf:items-center sf:justify-center sf:rounded-full sf:bg-slate-900 sf:text-sm sf:font-semibold sf:text-white"
							>
								2
							</span>
							<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
								<p class="sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedDefinition.label} changes
								</p>
								<Badge variant="info">{selectedDefinition.kicker}</Badge>
							</div>
						</div>
						<div class="sf:grid sf:gap-3 sf:sm:grid-cols-3">
							<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<p class="sf:text-xs sf:font-medium sf:text-slate-500">Execution logs</p>
								<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedDefinition.retentionLabel}
								</p>
							</div>
							<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<p class="sf:text-xs sf:font-medium sf:text-slate-500">AI reply storage</p>
								<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedDefinition.fullOutputLabel}
								</p>
							</div>
							<div class="sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-3">
								<p class="sf:text-xs sf:font-medium sf:text-slate-500">Diagnostics</p>
								<p class="sf:mt-1 sf:text-sm sf:font-semibold sf:text-slate-900">
									{selectedDefinition.loggingLabel}
								</p>
							</div>
						</div>
						<p class="sf:text-sm sf:text-slate-600">
							All presets still keep action definitions, mappings, and provider setup on this site
							until you delete them. The difference is how much execution history stays available
							for review.
						</p>
					</div>

					<div class="sf:space-y-6">
						{#if siteContextError}
							<Alert variant="danger" data-testid="privacy-site-context-error">
								{siteContextError}
							</Alert>
						{/if}
						<SiteContextSetupPanel
							stepNumber={3}
							statusLabel={siteContextSetupLabel}
							statusVariant={siteContextSetupVariant}
							loading={siteContextLoading}
							saving={siteContextSaving}
							generating={siteContextGenerating}
							generateDisabled={!canGenerateSiteContext}
							generateDisabledMessage={siteContextGenerating ? null : generateDisabledMessage}
							{generateSetupHref}
							{generateSetupLabel}
							bind:contextText={siteContextText}
							bind:generationConsent={siteContextConsent}
							bind:autoRefreshEnabled={siteContextAutoRefresh}
							bind:autoRefreshDays={siteContextRefreshDays}
							bind:modelSelection={siteContextModelSelection}
							refreshDayOptions={SITE_CONTEXT_REFRESH_DAY_OPTIONS}
							onSave={saveSiteContext}
							onGenerate={generateSiteContext}
							onChange={markSiteContextTouched}
						/>
						<SiteContextNotices />
					</div>
				</div>

				<div
					class="sf:flex sf:flex-col sf:gap-3 sf:border-t sf:border-slate-200 sf:pt-5 sf:md:flex-row sf:md:items-center sf:md:justify-between"
				>
					<div class="sf:flex sf:min-w-0 sf:flex-col sf:gap-3">
						<div class="sf:flex sf:min-w-0 sf:items-start sf:gap-3">
							<span
								class="sf:inline-flex sf:h-7 sf:w-7 sf:shrink-0 sf:items-center sf:justify-center sf:rounded-full sf:bg-slate-900 sf:text-sm sf:font-semibold sf:text-white"
							>
								4
							</span>
							<p class="sf:min-w-0 sf:text-sm sf:text-slate-500">
								Skip Setup applies the recommended Balanced defaults and keeps the plugin ready to
								use immediately.
							</p>
						</div>
						{#if footerApplyError}
							<div
								bind:this={applyErrorRegion}
								tabindex="-1"
								class="sf:focus-visible:outline-none"
								data-testid="privacy-setup-apply-error"
							>
								<Alert variant="danger">
									{footerApplyError}
								</Alert>
							</div>
						{/if}
					</div>
					<div class="sf:flex sf:shrink-0 sf:flex-nowrap sf:gap-2">
						<Button
							variant="secondary"
							disabled={saving || siteContextSaving}
							onclick={useBalancedDefaults}
						>
							Skip Setup
						</Button>
						<Button
							class="sf:min-w-[9rem]"
							loading={saving || siteContextSaving}
							disabled={saving || siteContextSaving}
							onclick={applySelectedPreset}
						>
							{saving || siteContextSaving ? 'Saving...' : `Apply ${selectedDefinition.label}`}
						</Button>
					</div>
				</div>
			</div>
		</div>
	</div>
{/if}
