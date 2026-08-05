<script lang="ts">
	import { onMount } from 'svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import { parseSettingsUpdatedEvent, SETTINGS_UPDATED_EVENT } from '$lib/api/settings-events';
	import type {
		LocalActionTemplate,
		LocalCustomActionRecord,
		LocalExecutionEvent,
		LocalProviderCredential,
		DashboardSummaryResponse
	} from '$lib/api/types';
	import { Badge, Button, Card, Section, StateTemplate } from '$lib/components/ui';
	import { navigateToAppPath } from '$lib/navigation';
	import { formatTimestamp } from '$lib/utils/date-time';

	type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';
	type StructuredResult = Record<string, unknown>;

	interface ImpactSummary {
		totalRuns: number;
		succeededRuns: number;
		spamFlagged: number;
		entryNotesCreated: number;
		marketerInsights: number;
		followUpsNeeded: number;
		urgentSignals: number;
		gradedLeads: number;
		replyDrafts: number;
	}

	const client = createClientFromConfig();

	let loading = $state(true);
	let errors = $state<string[]>([]);
	let providers = $state<LocalProviderCredential[]>([]);
	let templates = $state<LocalActionTemplate[]>([]);
	let customActions = $state<LocalCustomActionRecord[]>([]);
	let recentEvents = $state<LocalExecutionEvent[]>([]);

	let openRouterCredential = $derived(
		providers.find((credential) => credential.provider === 'openrouter' && credential.secret_configured)
	);
	let managedCredential = $derived(
		providers.find(
			(credential) => credential.provider === 'sentient_managed' && credential.secret_configured
		)
	);
	let openRouterStatus = $derived(openRouterCredential?.status ?? 'missing');
	let managedStatus = $derived(managedCredential?.status ?? 'missing');
	let recentLocalEvents = $derived(recentEvents.filter((event) => !isImportedHistoryEvent(event)));
	let importedHistoryCount = $derived(recentEvents.length - recentLocalEvents.length);
	let recentDisplayEvents = $derived(recentLocalEvents.slice(0, 5));
	let impactSummary = $derived(computeImpactSummary(recentLocalEvents));
	let successfulRuns = $derived(
		recentLocalEvents.filter((event) => event.status === 'succeeded').length
	);
	let failedRuns = $derived(recentLocalEvents.filter((event) => event.status === 'failed').length);
	let activeTemplates = $derived(templates.filter((template) => template.is_active).length);
	let activeCustomActions = $derived(
		customActions.filter((action) => action.status === 'active').length
	);
	let latestEvent = $derived(recentLocalEvents[0] ?? null);

	async function loadDashboardData(options: { forceRefresh?: boolean } = {}): Promise<void> {
		loading = true;
		errors = [];

		try {
			const summary = await client.getDashboardSummary({
				showNotifications: false,
				forceRefresh: options.forceRefresh === true
			});
			providers = summary.providers;
			templates = summary.templates;
			customActions = summary.custom_actions;
			recentEvents = summary.recent_events;
			errors = dashboardSectionErrors(summary);
		} catch (error) {
			errors = [
				error instanceof Error ? error.message : 'Dashboard summary is unavailable.'
			];
		}

		loading = false;
	}

	function dashboardSectionErrors(summary: DashboardSummaryResponse): string[] {
		const sectionErrors = Array.isArray(summary.section_errors) ? summary.section_errors : [];
		return sectionErrors
			.map((sectionError) => sectionError.message)
			.filter((message): message is string => typeof message === 'string' && message.trim() !== '');
	}

	function openRouterStatusLabel(status: string): string {
		switch (status) {
			case 'valid':
				return 'OpenRouter ready';
			case 'limited':
				return 'OpenRouter limited';
			case 'invalid':
				return 'OpenRouter needs attention';
			case 'disabled':
				return 'OpenRouter disabled';
			default:
				return 'OpenRouter not connected';
		}
	}

	function managedStatusLabel(status: string): string {
		switch (status) {
			case 'valid':
				return 'Managed service ready';
			case 'limited':
				return 'Managed service limited';
			case 'invalid':
				return 'Managed service needs attention';
			case 'disabled':
				return 'Managed service disabled';
			default:
				return 'Managed service not connected';
		}
	}

	function openRouterStatusVariant(status: string): BadgeVariant {
		switch (status) {
			case 'valid':
				return 'success';
			case 'limited':
				return 'warning';
			case 'invalid':
			case 'disabled':
				return 'danger';
			default:
				return 'neutral';
		}
	}

	function openRouterStatusBadgeLabel(status: string): string {
		switch (status) {
			case 'valid':
				return 'Ready';
			case 'limited':
				return 'Limited';
			case 'invalid':
				return 'Needs attention';
			case 'disabled':
				return 'Disabled';
			default:
				return 'Not connected';
		}
	}

	function isImportedHistoryEvent(event: LocalExecutionEvent): boolean {
		const provider = typeof event.provider === 'string' ? event.provider.trim().toLowerCase() : '';
		return provider === 'legacy_cps' || provider === 'cps';
	}

	function computeImpactSummary(events: LocalExecutionEvent[]): ImpactSummary {
		return events.reduce<ImpactSummary>(
			(summary, event) => {
				const structured = structuredResult(event);
				summary.totalRuns += 1;
				if (event.status === 'succeeded') {
					summary.succeededRuns += 1;
				}

				const classification = stringValue(structured.classification);
				if (classification === 'spam' || classification === 'likely_spam') {
					summary.spamFlagged += 1;
				}

				if (effectApplied(event, 'entry_note') || effectApplied(event, 'spam_note')) {
					summary.entryNotesCreated += 1;
				}

				if (
					stringValue(structured.sentiment) ||
					stringValue(structured.status) ||
					stringValue(structured.intent) ||
					stringValue(structured.route_to) ||
					stringValue(structured.severity) ||
					stringValue(structured.grade) ||
					stringValue(structured.next_best_action)
				) {
					summary.marketerInsights += 1;
				}

				if (stringValue(structured.grade)) {
					summary.gradedLeads += 1;
				}

				if (stringValue(structured.suggested_reply_draft)) {
					summary.replyDrafts += 1;
				}

				const informationStatus = stringValue(structured.status);
				if (informationStatus === 'needs_follow_up' || informationStatus === 'insufficient') {
					summary.followUpsNeeded += 1;
				}

				const urgency = stringValue(structured.urgency);
				const priority = stringValue(structured.priority);
				const severity = stringValue(structured.severity);
				if (
					urgency === 'high' ||
					urgency === 'critical' ||
					priority === 'high' ||
					priority === 'urgent' ||
					severity === 'high' ||
					severity === 'critical'
				) {
					summary.urgentSignals += 1;
				}

				return summary;
			},
			{
				totalRuns: 0,
				succeededRuns: 0,
				spamFlagged: 0,
				entryNotesCreated: 0,
				marketerInsights: 0,
				followUpsNeeded: 0,
				urgentSignals: 0,
				gradedLeads: 0,
				replyDrafts: 0
			}
		);
	}

	function structuredResult(event: LocalExecutionEvent): StructuredResult {
		const result = event.result_json;
		if (isPlainObject(result?.result) && isPlainObject(result.result.structured)) {
			return result.result.structured;
		}
		const structured = result?.structured;
		return isPlainObject(structured) ? structured : {};
	}

	function effectApplied(event: LocalExecutionEvent, effect: string): boolean {
		const result = event.result_json;
		const effects = isPlainObject(result?.effects) ? result.effects : null;
		const applied = effects?.applied;
		return Array.isArray(applied) && applied.includes(effect);
	}

	function isPlainObject(value: unknown): value is Record<string, unknown> {
		return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
	}

	function stringValue(value: unknown): string {
		return typeof value === 'string' ? value.trim().toLowerCase() : '';
	}

	onMount(() => {
		void loadDashboardData();

		function handleSettingsUpdated(event: Event): void {
			if (!parseSettingsUpdatedEvent(event)) return;
			void loadDashboardData({ forceRefresh: true });
		}

		window.addEventListener(SETTINGS_UPDATED_EVENT, handleSettingsUpdated);

		return () => {
			window.removeEventListener(SETTINGS_UPDATED_EVENT, handleSettingsUpdated);
		};
	});
</script>

<Section
	heading="Sentient Forms workspace"
	description="Start with the managed Sentient Forms service when you want setup, model access, spend controls, and support handled for this WordPress site. Direct OpenRouter remains available for free testing and self-managed BYOK use."
>
	{#snippet actions()}
		<Button
			variant="secondary"
			onclick={() => void loadDashboardData({ forceRefresh: true })}
			disabled={loading}
		>
			{loading ? 'Refreshing...' : 'Refresh'}
		</Button>
			<Button onclick={() => navigateToAppPath('/licensing')}>
				{managedCredential ? 'Manage managed service' : 'Set up managed service'}
			</Button>
	{/snippet}

	{#if errors.length > 0}
		<StateTemplate
			variant="error"
			title="Local workspace data is partially unavailable"
			message={errors[0]}
			actionLabel="Retry"
			onAction={() => void loadDashboardData({ forceRefresh: true })}
			testId="dashboard-error-state"
		>
			{#if errors.length > 1}
				<p class="sf:mt-2 sf:text-xs sf:text-danger-700">
					{errors.length - 1} more local checks need attention.
				</p>
			{/if}
		</StateTemplate>
	{/if}

	<Card class="sf:border-slate-300 sf:bg-slate-50" data-testid="dashboard-local-first-summary">
		<div class="sf:grid sf:gap-6 sf:lg:grid-cols-[1.2fr_0.8fr]">
			<div class="sf:space-y-3">
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<Badge variant={openRouterStatusVariant(managedStatus)}>
						{managedStatusLabel(managedStatus)}
					</Badge>
					<Badge variant="info">Recommended setup</Badge>
					<Badge variant="neutral">Direct OpenRouter optional</Badge>
				</div>
				<h3 class="sf:text-xl sf:font-semibold sf:text-slate-900">
					{managedCredential?.label ?? 'Managed Sentient Forms service'}
				</h3>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					Use managed execution when you want Sentient Forms to handle provider setup, recommended paid models, service metering, and spend controls. Free OpenRouter routes are useful for proving a workflow; BYOK is for teams that want to own provider billing and key limits themselves.
				</p>
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					<Button size="sm" onclick={() => navigateToAppPath('/licensing')}>
						{managedCredential ? 'Review managed service' : 'Set up managed service'}
					</Button>
					<Button size="sm" variant="secondary" onclick={() => navigateToAppPath('/actions')}>
						Map a form
					</Button>
					<Button size="sm" variant="ghost" onclick={() => navigateToAppPath('/providers')}>
						Use direct OpenRouter
					</Button>
				</div>
			</div>

			<div class="sf:grid sf:grid-cols-2 sf:gap-x-6 sf:gap-y-4" data-testid="dashboard-local-counts">
				<div class="sf:border-l sf:border-slate-300 sf:pl-3">
					<p class="sf:text-xs sf:font-medium sf:text-slate-600">Provider keys</p>
					<p class="sf:mt-1 sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="dashboard-provider-count">
						{loading ? '...' : providers.length}
					</p>
				</div>
				<div class="sf:border-l sf:border-slate-300 sf:pl-3">
					<p class="sf:text-xs sf:font-medium sf:text-slate-600">Templates</p>
					<p class="sf:mt-1 sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="dashboard-template-count">
						{loading ? '...' : activeTemplates}
					</p>
				</div>
				<div class="sf:border-l sf:border-slate-300 sf:pl-3">
					<p class="sf:text-xs sf:font-medium sf:text-slate-600">Custom actions</p>
					<p class="sf:mt-1 sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="dashboard-custom-action-count">
						{loading ? '...' : activeCustomActions}
					</p>
				</div>
				<div class="sf:border-l sf:border-slate-300 sf:pl-3">
					<p class="sf:text-xs sf:font-medium sf:text-slate-600">Recent runs</p>
					<p class="sf:mt-1 sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="dashboard-execution-count">
						{loading ? '...' : recentLocalEvents.length}
					</p>
				</div>
			</div>
		</div>
	</Card>

	<div class="sf:grid sf:gap-4 sf:xl:grid-cols-3">
		<Card data-testid="dashboard-managed-status">
			<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
				<div>
					<h3 class="sf:text-sm sf:font-medium sf:text-slate-600">Recommended provider</h3>
					<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-900">
						{managedStatusLabel(managedStatus)}
					</p>
				</div>
				<Badge variant={openRouterStatusVariant(managedStatus)}>
					{managedCredential ? 'Configured' : 'Setup'}
				</Badge>
			</div>
			<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
				{managedCredential?.last_validated_at
					? `Last checked ${formatTimestamp(managedCredential.last_validated_at)}`
					: 'Activate managed service for paid models, setup help, and spend controls.'}
			</p>
		</Card>

		<Card data-testid="dashboard-openrouter-status">
			<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
				<div>
					<h3 class="sf:text-sm sf:font-medium sf:text-slate-600">Self-managed provider</h3>
					<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-900">
						{openRouterStatusLabel(openRouterStatus)}
					</p>
				</div>
				<Badge variant={openRouterStatusVariant(openRouterStatus)}>
					{openRouterStatusBadgeLabel(openRouterStatus)}
				</Badge>
			</div>
			<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
				{openRouterCredential?.last_validated_at
					? `Last checked ${formatTimestamp(openRouterCredential.last_validated_at)}`
					: 'Validate a key before the first provider call.'}
			</p>
		</Card>

		<Card data-testid="dashboard-free-path-card">
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-600">Free workflow proof</h3>
			<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-900">OpenRouter free routes</p>
			<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
				Try actions with free models first if you want to confirm the plugin path before choosing managed service or BYOK paid models.
			</p>
		</Card>
	</div>

	<Card title="Recent local runs" data-testid="dashboard-recent-runs-card">
		{#if loading}
			<StateTemplate variant="loading" title="Loading local runs" dense />
		{:else if recentLocalEvents.length === 0}
			<StateTemplate
				variant="empty"
				title="No local runs yet"
				message={
					importedHistoryCount > 0
						? 'Imported CPS history is still available in Action Log, but this site has not run any local-first actions yet.'
						: 'Connect OpenRouter, map a form, then submit a test entry.'
				}
				actionLabel={importedHistoryCount > 0 ? 'Open action log' : 'Open actions'}
				onAction={() => navigateToAppPath(importedHistoryCount > 0 ? '/actions/log' : '/actions')}
				dense
			/>
		{:else}
			<div class="sf:space-y-3">
				<div class="sf:flex sf:flex-wrap sf:gap-2" data-testid="dashboard-impact-summary">
					<Badge variant="info">{impactSummary.totalRuns} observed runs</Badge>
					<Badge variant="success">{impactSummary.succeededRuns} successful automations</Badge>
					<Badge variant={impactSummary.entryNotesCreated > 0 ? 'info' : 'neutral'}>
						{impactSummary.entryNotesCreated} staff notes
					</Badge>
					<Badge variant={impactSummary.marketerInsights > 0 ? 'info' : 'neutral'}>
						{impactSummary.marketerInsights} marketer insights
					</Badge>
					<Badge variant={impactSummary.gradedLeads > 0 ? 'success' : 'neutral'}>
						{impactSummary.gradedLeads} graded leads
					</Badge>
					<Badge variant={impactSummary.replyDrafts > 0 ? 'info' : 'neutral'}>
						{impactSummary.replyDrafts} reply drafts
					</Badge>
					<Badge variant={impactSummary.spamFlagged > 0 ? 'warning' : 'neutral'}>
						{impactSummary.spamFlagged} spam flagged
					</Badge>
					<Badge variant={impactSummary.followUpsNeeded > 0 ? 'info' : 'neutral'}>
						{impactSummary.followUpsNeeded} need follow-up
					</Badge>
					<Badge variant={impactSummary.urgentSignals > 0 ? 'danger' : 'neutral'}>
						{impactSummary.urgentSignals} urgent signals
					</Badge>
				</div>
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					<Badge variant="success">{successfulRuns} succeeded</Badge>
					<Badge variant={failedRuns > 0 ? 'danger' : 'neutral'}>{failedRuns} failed</Badge>
				</div>
					<!-- svelte-ignore a11y_no_noninteractive_tabindex because keyboard users need to reach this scroll region -->
					<div
						class="sf:overflow-x-auto"
						role="region"
						tabindex="0"
						aria-label="Recent local runs table"
					>
					<table class="sf:min-w-full sf:divide-y sf:divide-slate-200 sf:text-sm">
						<thead>
								<tr class="sf:text-left sf:text-xs sf:font-semibold sf:uppercase sf:text-slate-600">
								<th class="sf:py-2 sf:pr-4">Status</th>
								<th class="sf:py-2 sf:pr-4">Provider</th>
								<th class="sf:py-2 sf:pr-4">Model</th>
								<th class="sf:py-2">Created</th>
							</tr>
						</thead>
						<tbody class="sf:divide-y sf:divide-slate-100">
							{#each recentDisplayEvents as event}
								<tr>
									<td class="sf:py-2 sf:pr-4">
										<Badge variant={event.status === 'failed' ? 'danger' : event.status === 'succeeded' ? 'success' : 'neutral'}>
											{event.status ?? 'unknown'}
										</Badge>
									</td>
									<td class="sf:py-2 sf:pr-4 sf:text-slate-700">{event.provider ?? '—'}</td>
									<td class="sf:py-2 sf:pr-4 sf:text-slate-700">{event.model ?? '—'}</td>
									<td class="sf:py-2 sf:text-slate-600">{formatTimestamp(event.created_at)}</td>
								</tr>
							{/each}
						</tbody>
					</table>
				</div>
					<p class="sf:text-xs sf:text-slate-600">
						Latest request: {latestEvent?.execution_request_id ?? '—'}
					</p>
			</div>
		{/if}
	</Card>
</Section>
