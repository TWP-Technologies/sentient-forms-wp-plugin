<script lang="ts">
	import { onDestroy, onMount } from 'svelte';
	import SiteContextNotices from '$lib/components/site-context-notices.svelte';
	import SiteContextSetupPanel from '$lib/components/site-context-setup-panel.svelte';
	import { Alert, Button, Section, StateTemplate } from '$lib/components/ui';
	import type {
		ModelSelection,
		SiteContextStatusResponse,
		SiteContextUpdateRequest
	} from '$lib/api/types';
	import { appHref } from '$lib/navigation';
	import { notifications } from '$lib/stores/notifications';
	import { toast } from 'sonner-svelte';
	import {
		DEFAULT_SITE_CONTEXT_MODEL_SELECTION,
		DEFAULT_SITE_CONTEXT_REFRESH_DAYS,
		SITE_CONTEXT_REFRESH_DAY_OPTIONS,
		compactSiteContextModelSelection,
		normalizeSiteContextResponse,
		siteContextGenerateDisabledMessage,
		siteContextModelSelectionChanged,
		siteContextStatusLabel
	} from '$lib/utils/site-context';
	import { wpFetch } from '$lib/wp';

	const CONTEXT_HARD_LIMIT = 5000;
	const GENERATION_POLL_INTERVAL_MS = 3000;
	type SiteContextBadgeVariant = 'neutral' | 'success' | 'warning';
	type ToastId = string;

	let loading = $state(true);
	let saving = $state(false);
	let generating = $state(false);
	let withdrawing = $state(false);
	let error = $state<string | null>(null);
	let status = $state<SiteContextStatusResponse | null>(null);
	let editedText = $state('');
	let autoInclude = $state(true);
	let generationConsent = $state(false);
	let autoRefreshEnabled = $state(false);
	let autoRefreshDays = $state(DEFAULT_SITE_CONTEXT_REFRESH_DAYS);
	let generationModelSelection = $state<ModelSelection>(DEFAULT_SITE_CONTEXT_MODEL_SELECTION);
	let showWithdrawConfirm = $state(false);
	let generationPollTimer: ReturnType<typeof setTimeout> | null = null;
	let generationToastId: ToastId | null = null;
	let generationToastActive = false;

	const characterCount = $derived(editedText.length);
	const isOverLimit = $derived(characterCount > CONTEXT_HARD_LIMIT);
	const hasContext = $derived(Boolean(status?.has_context));
	const statusLabel = $derived(status ? siteContextStatusLabel(status) : 'Loading');
	const statusVariant = $derived(siteContextBadgeVariant(status));
	const canWithdraw = $derived(status?.settings.consent_status === 'granted' || hasContext);
	const hasChanges = $derived.by(() => {
		if (!status) return false;
		const context = status.context;
		return (
			editedText !== (context?.summary_text ?? '') ||
			autoInclude !== (context?.auto_include ?? true) ||
			generationConsent !== (status.settings.consent_status === 'granted') ||
			autoRefreshEnabled !== status.settings.auto_refresh_enabled ||
			autoRefreshDays !== status.settings.auto_refresh_days ||
			siteContextModelSelectionChanged(
				generationModelSelection,
				status.settings.generation_model_selection
			)
		);
	});
	const generateDisabledMessage = $derived(
		siteContextGenerateDisabledMessage(status, generationConsent, hasChanges)
	);
	const generationJobActive = $derived(siteContextGenerationJobIsActive(status));
	const canGenerate = $derived(
		generationConsent &&
			!generating &&
			!generationJobActive &&
			!generateDisabledMessage &&
			status?.generation_access.can_generate === true
	);
	const generateSetupHref = $derived(
		generateDisabledMessage && !hasChanges
			? siteContextGenerationSetupHref(status?.generation_access.setup_target)
			: null
	);
	const generateSetupLabel = $derived(
		status?.generation_access.setup_target === 'licensing'
			? 'Open billing'
			: 'Set up provider'
	);

	function syncFromStatus(next: SiteContextStatusResponse): void {
		status = next;
		editedText = next.context?.summary_text ?? '';
		autoInclude = next.context?.auto_include ?? true;
		generationConsent = next.settings.consent_status === 'granted';
		autoRefreshEnabled = next.settings.auto_refresh_enabled;
		autoRefreshDays = next.settings.auto_refresh_days || DEFAULT_SITE_CONTEXT_REFRESH_DAYS;
		generationModelSelection =
			next.settings.generation_model_selection ?? DEFAULT_SITE_CONTEXT_MODEL_SELECTION;
		updateGenerationJobState(next);
	}

	function siteContextGenerationJobIsActive(nextStatus: SiteContextStatusResponse | null): boolean {
		return ['queued', 'running'].includes(nextStatus?.generation_job?.status ?? '');
	}

	function clearGenerationPoll(): void {
		if (!generationPollTimer) return;
		clearTimeout(generationPollTimer);
		generationPollTimer = null;
	}

	function scheduleGenerationPoll(): void {
		if (generationPollTimer) return;
		generationPollTimer = setTimeout(() => {
			generationPollTimer = null;
			void pollGenerationStatus();
		}, GENERATION_POLL_INTERVAL_MS);
	}

	function ensureGenerationToast(nextStatus: SiteContextStatusResponse): void {
		const job = nextStatus.generation_job;
		const model = job?.model ? `Model: ${job.model}` : undefined;
		generationToastActive = true;
		generationToastId = toast.loading('Site Context generation is running in the background.', {
			id: generationToastId ?? undefined,
			description: model,
			duration: Number.POSITIVE_INFINITY
		});
	}

	function completeGenerationToast(message: string): void {
		if (!generationToastActive) return;
		toast.success(message, {
			id: generationToastId ?? undefined
		});
		generationToastActive = false;
		generationToastId = null;
	}

	function failGenerationToast(message: string): void {
		if (generationToastActive) {
			toast.error(message, {
				id: generationToastId ?? undefined
			});
			generationToastActive = false;
			generationToastId = null;
			return;
		}

		toast.error(message);
	}

	function updateGenerationJobState(nextStatus: SiteContextStatusResponse): void {
		const job = nextStatus.generation_job;
		if (job?.status === 'queued' || job?.status === 'running') {
			generating = true;
			error = null;
			ensureGenerationToast(nextStatus);
			scheduleGenerationPoll();
			return;
		}

		clearGenerationPoll();
		generating = false;

		if (job?.status === 'succeeded') {
			completeGenerationToast('Site Context generated.');
			return;
		}

		if (job?.status === 'failed') {
			const message = job.error ?? 'Failed to generate Site Context';
			error = message;
			failGenerationToast(message);
		}
	}

	async function pollGenerationStatus(): Promise<void> {
		try {
			syncFromStatus(
				normalizeSiteContextResponse(await wpFetch<SiteContextStatusResponse>('site-context'))
			);
		} catch (e) {
			console.error('Failed to refresh Site Context generation status', e);
			const message = readableError(e, 'Failed to refresh Site Context generation status');
			error = message;
			generating = false;
			failGenerationToast(message);
		}
	}

	function siteContextBadgeVariant(
		nextStatus: SiteContextStatusResponse | null
	): SiteContextBadgeVariant {
		if (nextStatus?.status === 'ready') return 'success';
		if (nextStatus?.status === 'declined') return 'neutral';
		return 'warning';
	}

	async function loadContext(): Promise<void> {
		loading = true;
		error = null;
		try {
			syncFromStatus(
				normalizeSiteContextResponse(await wpFetch<SiteContextStatusResponse>('site-context'))
			);
		} catch (e) {
			console.error('Failed to load Site Context', e);
			error = readableError(e, 'Failed to load Site Context');
		} finally {
			loading = false;
		}
	}

	function buildSettingsPayload(): SiteContextUpdateRequest {
		return {
			summary_text: editedText,
			auto_include: autoInclude,
			pii_ack: true,
			consent_status: generationConsent ? 'granted' : 'unset',
			auto_refresh_enabled: generationConsent && autoRefreshEnabled,
			auto_refresh_days: autoRefreshDays,
			generation_model_selection: compactSiteContextModelSelection(generationModelSelection)
		};
	}

	async function saveContext(): Promise<void> {
		saving = true;
		error = null;
		try {
			const response = await wpFetch<SiteContextStatusResponse>('site-context', {
				method: 'PUT',
				body: JSON.stringify(buildSettingsPayload()),
				showNotifications: false
			});
			syncFromStatus(normalizeSiteContextResponse(response));
			notifications.success('Site Context saved');
		} catch (e) {
			console.error('Failed to save Site Context', e);
			error = readableError(e, 'Failed to save Site Context');
			notifications.error(error);
		} finally {
			saving = false;
		}
	}

	async function generateContext(): Promise<void> {
		if (!canGenerate) {
			notifications.warning(
				generateDisabledMessage ??
					'Site Context generation is not ready. Save setup and configure a paid provider before generating.'
			);
			return;
		}
		generating = true;
		error = null;
		try {
			const response = await wpFetch<SiteContextStatusResponse>('site-context/generate', {
				method: 'POST',
				body: JSON.stringify(buildSettingsPayload()),
				showNotifications: false
			});
			syncFromStatus(normalizeSiteContextResponse(response));
		} catch (e) {
			console.error('Failed to generate Site Context', e);
			error = readableError(e, 'Failed to generate Site Context');
			notifications.error(error);
		} finally {
			if (!siteContextGenerationJobIsActive(status)) {
				generating = false;
			}
		}
	}

	async function withdrawConsent(): Promise<void> {
		withdrawing = true;
		error = null;
		try {
			const response = await wpFetch<SiteContextStatusResponse>('site-context', {
				method: 'DELETE'
			});
			syncFromStatus(normalizeSiteContextResponse(response));
			showWithdrawConfirm = false;
			notifications.success('Site Context consent withdrawn');
		} catch (e) {
			console.error('Failed to withdraw Site Context consent', e);
			error = readableError(e, 'Failed to withdraw Site Context consent');
		} finally {
			withdrawing = false;
		}
	}

	function formatDate(value: string | null | undefined): string {
		if (!value) return 'Never';
		const date = new Date(value.replace(' ', 'T'));
		if (Number.isNaN(date.getTime())) return 'Unknown';
		return date.toLocaleDateString();
	}

	function siteContextGenerationSetupHref(target: string | null | undefined): string | null {
		if (target === 'providers') return appHref('/providers');
		if (target === 'licensing') return appHref('/licensing');
		return null;
	}

	function readableError(errorValue: unknown, fallback: string): string {
		const payload =
			errorValue && typeof errorValue === 'object' && 'payload' in errorValue
				? (errorValue as { payload?: unknown }).payload
				: null;

		if (payload && typeof payload === 'object') {
			const message = (payload as { message?: unknown }).message;
			if (typeof message === 'string' && message.trim().length > 0) return message;

			const nested = (payload as { error?: { message?: unknown } }).error?.message;
			if (typeof nested === 'string' && nested.trim().length > 0) return nested;
		}

		if (errorValue instanceof Error && errorValue.message !== 'Request failed') {
			return errorValue.message;
		}

		return fallback;
	}

	onMount(() => {
		void loadContext();
	});

	onDestroy(() => {
		clearGenerationPoll();
	});
</script>

<Section
	heading="Site Context"
	description="Describe this site so form actions can make more correct decisions about legitimate submissions, spam, summaries, and follow-up work."
>
	{#snippet actions()}
		<div class="sf:flex sf:flex-wrap sf:gap-2">
			<Button variant="secondary" onclick={loadContext} disabled={loading}>
				{loading ? 'Loading...' : 'Refresh'}
			</Button>
			<Button
				variant="secondary"
				disabled={!canWithdraw || withdrawing}
				onclick={() => (showWithdrawConfirm = true)}
			>
				Withdraw consent
			</Button>
		</div>
	{/snippet}

	{#if error}
		<StateTemplate
			variant="error"
			title="Site Context request failed"
			message={error}
			actionLabel="Retry"
			onAction={() => {
				void loadContext();
			}}
			testId="site-context-error-state"
		/>
	{/if}

	{#if loading}
		<StateTemplate
			variant="loading"
			title="Loading Site Context"
			message="Fetching saved context, consent, model, and refresh settings."
			testId="site-context-loading-state"
		/>
	{:else if status}
		<div class="sf:space-y-5">
			<p class="sf:text-sm sf:text-slate-600">
				Last updated: {formatDate(status.context?.updated_at)}
			</p>

			{#if status.is_empty && generationConsent}
				<Alert variant="warning" data-testid="site-context-empty-consented-warning">
					Consent is enabled, but Site Context is empty. Generate or write context before
					relying on site-specific action decisions.
				</Alert>
			{:else if status.is_stale}
				<Alert variant="warning" data-testid="site-context-stale-warning">
					Site Context looks older than {status.stale_after_days} days. Refresh it before using it
					for high-confidence spam decisions.
				</Alert>
			{:else if status.settings.consent_status === 'declined'}
				<Alert variant="info" data-testid="site-context-declined-warning">
					AI-generated Site Context is off. You can still write context manually; better context
					usually improves correct action resolution.
				</Alert>
			{/if}

			<SiteContextSetupPanel
				statusLabel={statusLabel}
				statusVariant={statusVariant}
				saving={saving}
				generating={generating}
				saveLabel="Save context"
				saveDisabled={!hasChanges || isOverLimit}
				generateDisabled={!canGenerate}
				generateDisabledMessage={generating ? null : generateDisabledMessage}
				generateSetupHref={generateSetupHref}
				generateSetupLabel={generateSetupLabel}
				bind:contextText={editedText}
				bind:generationConsent={generationConsent}
				bind:autoRefreshEnabled={autoRefreshEnabled}
				bind:autoRefreshDays={autoRefreshDays}
				bind:modelSelection={generationModelSelection}
				refreshDayOptions={SITE_CONTEXT_REFRESH_DAY_OPTIONS}
				onSave={saveContext}
				onGenerate={generateContext}
			/>

			<SiteContextNotices />
		</div>

		{#if showWithdrawConfirm}
			<div
				class="sf:fixed sf:inset-0 sf:z-[1300] sf:flex sf:items-center sf:justify-center sf:bg-slate-950/50 sf:p-4"
				role="presentation"
				data-testid="site-context-withdraw-backdrop"
			>
				<div
					class="sf:w-full sf:max-w-md sf:rounded-lg sf:border sf:border-slate-200 sf:bg-white sf:p-5 sf:shadow-2xl"
					role="dialog"
					aria-modal="true"
					aria-labelledby="site-context-withdraw-title"
				>
					<h2
						id="site-context-withdraw-title"
						class="sf:text-lg sf:font-semibold sf:text-slate-900"
					>
						Withdraw Site Context consent?
					</h2>
					<p class="sf:mt-2 sf:text-sm sf:text-slate-600">
						This deletes saved Site Context, disables automatic refresh, and future action prompts
						will not include Site Context until you enable it again.
					</p>
					<div class="sf:mt-5 sf:flex sf:flex-wrap sf:justify-end sf:gap-2">
						<Button
							variant="secondary"
							disabled={withdrawing}
							onclick={() => (showWithdrawConfirm = false)}
						>
							Cancel
						</Button>
						<Button variant="danger" loading={withdrawing} onclick={withdrawConsent}>
							Withdraw and delete
						</Button>
					</div>
				</div>
			</div>
		{/if}
	{/if}
</Section>
