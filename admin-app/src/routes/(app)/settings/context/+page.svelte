<script lang="ts">
	import { onMount } from 'svelte';
	import SiteContextNotices from '$lib/components/site-context-notices.svelte';
	import SiteContextSetupPanel from '$lib/components/site-context-setup-panel.svelte';
	import { Alert, Button, Section, StateTemplate } from '$lib/components/ui';
	import type {
		ModelSelection,
		SiteContextStatusResponse,
		SiteContextUpdateRequest
	} from '$lib/api/types';
	import { notifications } from '$lib/stores/notifications';
	import {
		DEFAULT_SITE_CONTEXT_MODEL_SELECTION,
		DEFAULT_SITE_CONTEXT_REFRESH_DAYS,
		SITE_CONTEXT_REFRESH_DAY_OPTIONS,
		normalizeSiteContextResponse,
		siteContextStatusLabel
	} from '$lib/utils/site-context';
	import { wpFetch } from '$lib/wp';

	const CONTEXT_HARD_LIMIT = 5000;
	type SiteContextBadgeVariant = 'neutral' | 'success' | 'warning';

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

	const characterCount = $derived(editedText.length);
	const isOverLimit = $derived(characterCount > CONTEXT_HARD_LIMIT);
	const hasContext = $derived(Boolean(status?.has_context));
	const statusLabel = $derived(status ? siteContextStatusLabel(status) : 'Loading');
	const statusVariant = $derived(siteContextBadgeVariant(status));
	const canGenerate = $derived(generationConsent && !generating);
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
			JSON.stringify(generationModelSelection) !==
				JSON.stringify(
					status.settings.generation_model_selection ?? DEFAULT_SITE_CONTEXT_MODEL_SELECTION
				)
		);
	});

	function syncFromStatus(next: SiteContextStatusResponse): void {
		status = next;
		editedText = next.context?.summary_text ?? '';
		autoInclude = next.context?.auto_include ?? true;
		generationConsent = next.settings.consent_status === 'granted';
		autoRefreshEnabled = next.settings.auto_refresh_enabled;
		autoRefreshDays = next.settings.auto_refresh_days || DEFAULT_SITE_CONTEXT_REFRESH_DAYS;
		generationModelSelection =
			next.settings.generation_model_selection ?? DEFAULT_SITE_CONTEXT_MODEL_SELECTION;
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
			error = e instanceof Error ? e.message : 'Failed to load Site Context';
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
			generation_model_selection: generationModelSelection
		};
	}

	async function saveContext(): Promise<void> {
		saving = true;
		error = null;
		try {
			const response = await wpFetch<SiteContextStatusResponse>('site-context', {
				method: 'PUT',
				body: JSON.stringify(buildSettingsPayload())
			});
			syncFromStatus(normalizeSiteContextResponse(response));
			notifications.success('Site Context saved');
		} catch (e) {
			console.error('Failed to save Site Context', e);
			error = e instanceof Error ? e.message : 'Failed to save Site Context';
		} finally {
			saving = false;
		}
	}

	async function generateContext(): Promise<void> {
		if (!generationConsent) {
			notifications.warning('Allow AI-generated Site Context before generating.');
			return;
		}
		generating = true;
		error = null;
		try {
			const response = await wpFetch<SiteContextStatusResponse>('site-context/generate', {
				method: 'POST',
				body: JSON.stringify(buildSettingsPayload())
			});
			syncFromStatus(normalizeSiteContextResponse(response));
			notifications.success('Site Context generated');
		} catch (e) {
			console.error('Failed to generate Site Context', e);
			error = e instanceof Error ? e.message : 'Failed to generate Site Context';
		} finally {
			generating = false;
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
			error = e instanceof Error ? e.message : 'Failed to withdraw Site Context consent';
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

	onMount(() => {
		void loadContext();
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
