<script lang="ts">
	import { onMount } from 'svelte';
	import {
		Alert,
		Badge,
		Button,
		Card,
		ModelSelector,
		Section,
		StateTemplate,
		Toggle
	} from '$lib/components/ui';
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

	const CONTEXT_SOFT_LIMIT = 2000;
	const CONTEXT_WARN_LIMIT = 4500;
	const CONTEXT_HARD_LIMIT = 5000;

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
	const limitPercent = $derived(Math.min((characterCount / CONTEXT_HARD_LIMIT) * 100, 100));
	const hasContext = $derived(Boolean(status?.has_context));
	const statusLabel = $derived(status ? siteContextStatusLabel(status) : 'Loading');
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
				JSON.stringify(status.settings.generation_model_selection ?? DEFAULT_SITE_CONTEXT_MODEL_SELECTION)
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
		<Button variant="secondary" onclick={loadContext} disabled={loading}>
			{loading ? 'Loading…' : 'Refresh'}
		</Button>
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
		<div class="sf:grid sf:gap-5 sf:xl:grid-cols-[minmax(0,1fr)_minmax(28rem,0.72fr)]">
			<Card>
				<div class="sf:space-y-5">
					<div class="sf:flex sf:flex-col sf:gap-3 sf:sm:flex-row sf:sm:items-start sf:sm:justify-between">
						<div class="sf:space-y-2">
							<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
								<p class="sf:text-base sf:font-semibold sf:text-slate-900">Context summary</p>
								<Badge
									variant={status.status === 'ready'
										? 'success'
										: status.status === 'declined'
											? 'neutral'
											: 'warning'}
								>
									{statusLabel}
								</Badge>
							</div>
							<p class="sf:text-sm sf:text-slate-600">
								Last updated: {formatDate(status.context?.updated_at)}
							</p>
						</div>
						<div class="sf:flex sf:flex-wrap sf:gap-2">
							<Button
								variant="secondary"
								disabled={!canWithdraw || withdrawing}
								onclick={() => (showWithdrawConfirm = true)}
							>
								Withdraw consent
							</Button>
							<Button disabled={saving || !hasChanges || isOverLimit} onclick={saveContext}>
								{saving ? 'Saving…' : 'Save changes'}
							</Button>
						</div>
					</div>

					{#if status.is_empty && generationConsent}
						<Alert variant="warning" data-testid="site-context-empty-consented-warning">
							Consent is enabled, but Site Context is empty. Generate or write context before relying on
							site-specific action decisions.
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

					<div>
						<label for="context-text" class="sf:block sf:text-sm sf:font-semibold sf:text-slate-900">
							Site Context text
						</label>
						<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
							Keep this useful to a model: what the site does, who normally contacts you, what a good
							lead looks like, and what should be suspicious for this site.
						</p>
						<textarea
							id="context-text"
							bind:value={editedText}
							rows={10}
							maxlength={CONTEXT_HARD_LIMIT}
							class="sf:mt-3 sf:w-full sf:rounded-md sf:border sf:px-3 sf:py-2 sf:text-sm sf:placeholder-slate-400 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white {characterCount >= CONTEXT_WARN_LIMIT ? 'sf:border-danger-500 sf:focus-visible:border-danger-500 sf:focus-visible:ring-danger-500' : characterCount > CONTEXT_SOFT_LIMIT ? 'sf:border-warning-600 sf:focus-visible:border-warning-600 sf:focus-visible:ring-warning-600' : 'sf:border-slate-300 sf:focus-visible:border-primary-600 sf:focus-visible:ring-primary-500'}"
							placeholder="Example: This site sells commercial HVAC maintenance in Austin. Legitimate leads usually ask about service plans, emergency repairs, rooftop units, or commercial quotes..."
							data-testid="site-context-textarea"
						></textarea>
						<div class="sf:mt-1 sf:h-1 sf:w-full sf:overflow-hidden sf:rounded-full sf:bg-slate-100">
							<div
								class="sf:h-full sf:rounded-full sf:transition-all {characterCount >= CONTEXT_WARN_LIMIT ? 'sf:bg-red-500' : characterCount > CONTEXT_SOFT_LIMIT ? 'sf:bg-amber-500' : 'sf:bg-indigo-500'}"
								style={`width: ${limitPercent}%`}
							></div>
						</div>
						<p
							class="sf:mt-1 sf:text-xs {characterCount >= CONTEXT_WARN_LIMIT ? 'sf:font-medium sf:text-red-600' : characterCount > CONTEXT_SOFT_LIMIT ? 'sf:text-amber-700' : 'sf:text-slate-600'}"
						>
							{characterCount} / {CONTEXT_HARD_LIMIT} characters
						</p>
					</div>

					<Toggle
						label="Include Site Context by default"
						description="When actions use their global setting, this context can be included in model prompts."
						bind:checked={autoInclude}
					/>
				</div>
			</Card>

			<div class="sf:space-y-5">
				<Card>
					<div class="sf:space-y-4">
						<div>
							<p class="sf:text-base sf:font-semibold sf:text-slate-900">AI generation</p>
							<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
								Generate context from public website evidence. Website content is treated as
								untrusted evidence, not instructions.
							</p>
						</div>

						<label class="sf:flex sf:items-start sf:gap-3 sf:rounded-lg sf:border sf:border-slate-200 sf:p-3">
							<input
								type="checkbox"
								class="sf:mt-1 sf:h-5 sf:w-5 sf:rounded sf:text-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								bind:checked={generationConsent}
								data-testid="site-context-generation-consent"
							/>
							<span>
								<span class="sf:block sf:text-sm sf:font-semibold sf:text-slate-900">
									Allow AI to generate Site Context
								</span>
								<span class="sf:block sf:text-xs sf:text-slate-600">
									This may use web search or website fetching through the selected route.
								</span>
							</span>
						</label>

						<Toggle
							label="Refresh automatically"
							description="Use WordPress scheduled tasks to refresh context at the selected cadence."
							bind:checked={autoRefreshEnabled}
							disabled={!generationConsent}
						/>

						<label class="sf:block">
							<span class="sf:text-sm sf:font-semibold sf:text-slate-900">Refresh cadence</span>
							<select
								class="sf:mt-1 sf:w-full sf:rounded-md sf:border sf:border-slate-300 sf:bg-white sf:px-3 sf:py-2 sf:text-sm sf:focus-visible:border-primary-600 sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-primary-500 sf:focus-visible:ring-offset-1 sf:focus-visible:ring-offset-white"
								bind:value={autoRefreshDays}
								disabled={!generationConsent || !autoRefreshEnabled}
							>
								{#each SITE_CONTEXT_REFRESH_DAY_OPTIONS as days}
									<option value={days}>{days} days</option>
								{/each}
							</select>
						</label>

						<Button
							class="sf:w-full"
							disabled={!canGenerate}
							loading={generating}
							onclick={generateContext}
							data-testid="site-context-generate-now"
						>
							{generating ? 'Generating…' : 'Generate now'}
						</Button>
					</div>
				</Card>

				<Card>
					<ModelSelector
						label="Generation model"
						level="global"
						value={generationModelSelection}
						readonly={!generationConsent}
						requiredCapabilities={['web_search']}
						lockRequiredCapabilities={true}
						onchange={(selection) => {
							generationModelSelection = selection;
						}}
					/>
				</Card>
			</div>
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
					<h2 id="site-context-withdraw-title" class="sf:text-lg sf:font-semibold sf:text-slate-900">
						Withdraw Site Context consent?
					</h2>
					<p class="sf:mt-2 sf:text-sm sf:text-slate-600">
						This deletes saved Site Context, disables automatic refresh, and future action prompts will
						not include Site Context until you enable it again.
					</p>
					<div class="sf:mt-5 sf:flex sf:flex-wrap sf:justify-end sf:gap-2">
						<Button variant="secondary" disabled={withdrawing} onclick={() => (showWithdrawConfirm = false)}>
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
