<script lang="ts">
	import { onMount } from 'svelte';
	import { createClientFromConfig } from '$lib/api/client';
	import type {
		LocalActionTemplate,
		LocalCustomActionRecord,
		LocalExecutionEvent,
		LocalProviderCredential,
		LocalSupportBundle
	} from '$lib/api/types';
	import { Badge, Button, Card, Section, StateTemplate } from '$lib/components/ui';
	import { navigateToAppPath } from '$lib/navigation';
	import { formatTimestamp } from '$lib/utils/date-time';

	type BadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';
	type SettledResult<T> = PromiseSettledResult<T>;

	const client = createClientFromConfig();

	let loading = $state(true);
	let errors = $state<string[]>([]);
	let providers = $state<LocalProviderCredential[]>([]);
	let templates = $state<LocalActionTemplate[]>([]);
	let customActions = $state<LocalCustomActionRecord[]>([]);
	let recentEvents = $state<LocalExecutionEvent[]>([]);
	let supportBundle = $state<LocalSupportBundle | null>(null);

	let openRouterCredential = $derived(
		providers.find((credential) => credential.provider === 'openrouter' && credential.secret_configured)
	);
	let openRouterStatus = $derived(openRouterCredential?.status ?? 'missing');
	let recentLocalEvents = $derived(recentEvents.filter((event) => !isImportedHistoryEvent(event)));
	let importedHistoryCount = $derived(recentEvents.length - recentLocalEvents.length);
	let successfulRuns = $derived(
		recentLocalEvents.filter((event) => event.status === 'succeeded').length
	);
	let failedRuns = $derived(recentLocalEvents.filter((event) => event.status === 'failed').length);
	let activeTemplates = $derived(templates.filter((template) => template.is_active).length);
	let activeCustomActions = $derived(
		customActions.filter((action) => action.status === 'active').length
	);
	let executionRetentionDays = $derived(
		typeof supportBundle?.retention?.event_retention_days === 'number'
			? supportBundle.retention.event_retention_days
			: null
	);
	let latestEvent = $derived(recentLocalEvents[0] ?? null);

	function rejectionMessage(result: SettledResult<unknown>, fallback: string): string | null {
		if (result.status === 'fulfilled') {
			return null;
		}

		return result.reason instanceof Error ? result.reason.message : fallback;
	}

	async function loadDashboardData(): Promise<void> {
		loading = true;
		errors = [];

		const [
			providerResult,
			templateResult,
			customActionResult,
			eventResult,
			supportBundleResult
		] = await Promise.allSettled([
			client.getLocalProviderCredentials({ showNotifications: false }),
			client.getLocalActionTemplates({ showNotifications: false }),
			client.getLocalCustomActions('active', { showNotifications: false }),
			client.getLocalExecutionEvents(5, { showNotifications: false }),
			client.getLocalSupportBundle({ showNotifications: false })
		]);

		if (providerResult.status === 'fulfilled') providers = providerResult.value;
		if (templateResult.status === 'fulfilled') templates = templateResult.value;
		if (customActionResult.status === 'fulfilled') customActions = customActionResult.value;
		if (eventResult.status === 'fulfilled') recentEvents = eventResult.value;
		if (supportBundleResult.status === 'fulfilled') supportBundle = supportBundleResult.value;

		errors = [
			rejectionMessage(providerResult, 'Provider health is unavailable.'),
			rejectionMessage(templateResult, 'Template catalog is unavailable.'),
			rejectionMessage(customActionResult, 'Custom actions are unavailable.'),
			rejectionMessage(eventResult, 'Recent execution history is unavailable.'),
			rejectionMessage(supportBundleResult, 'Local diagnostics are unavailable.')
		].filter((message): message is string => Boolean(message));

		loading = false;
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

	function tableCount(tableSuffix: string): string {
		const count = supportBundle?.local_tables?.[tableSuffix];
		return typeof count === 'number' ? String(count) : '—';
	}

	onMount(() => {
		void loadDashboardData();

		function handleSettingsUpdated(): void {
			void loadDashboardData();
		}

		window.addEventListener(
			'sentient-forms:settings-updated',
			handleSettingsUpdated as EventListener
		);

		return () => {
			window.removeEventListener(
				'sentient-forms:settings-updated',
				handleSettingsUpdated as EventListener
			);
		};
	});
</script>

<Section
	heading="Local workspace"
	description="Run AI actions from this WordPress site with your own provider key. Sentient billing stays optional."
>
	{#snippet actions()}
		<Button variant="secondary" onclick={loadDashboardData} disabled={loading}>
			{loading ? 'Refreshing...' : 'Refresh'}
		</Button>
		<Button onclick={() => navigateToAppPath('/providers')}>Connect OpenRouter</Button>
	{/snippet}

	{#if errors.length > 0}
		<StateTemplate
			variant="error"
			title="Local workspace data is partially unavailable"
			message={errors[0]}
			actionLabel="Retry"
			onAction={loadDashboardData}
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
					<Badge variant={openRouterStatusVariant(openRouterStatus)}>
						{openRouterStatusLabel(openRouterStatus)}
					</Badge>
					<Badge variant="info">Free path available</Badge>
				</div>
				<h3 class="sf:text-xl sf:font-semibold sf:text-slate-900">
					{openRouterCredential?.label ?? 'Bring your own OpenRouter key'}
				</h3>
				<p class="sf:max-w-2xl sf:text-sm sf:text-slate-600">
					OpenRouter direct mode keeps provider credentials and action data in WordPress. Sentient does not meter direct BYOK or free-model runs.
				</p>
				<div class="sf:flex sf:flex-wrap sf:gap-2">
					<Button size="sm" onclick={() => navigateToAppPath('/providers')}>
						{openRouterCredential ? 'Review provider' : 'Connect provider'}
					</Button>
					<Button size="sm" variant="secondary" onclick={() => navigateToAppPath('/actions')}>
						Map a form
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
		<Card data-testid="dashboard-openrouter-status">
			<div class="sf:flex sf:items-start sf:justify-between sf:gap-3">
				<div>
					<h3 class="sf:text-sm sf:font-medium sf:text-slate-600">Direct provider</h3>
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
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-600">Sentient charges</h3>
			<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-900">Direct OpenRouter: no</p>
			<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
				Managed billing belongs only to Sentient-hosted paid execution. BYOK and OpenRouter free models stay outside the Sentient meter.
			</p>
		</Card>

		<Card data-testid="dashboard-diagnostics-card">
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-600">Local diagnostics</h3>
			<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-900">
				{executionRetentionDays ? `${executionRetentionDays} day retention` : 'Retention not set'}
			</p>
			<p class="sf:mt-3 sf:text-sm sf:text-slate-600">
				Events table: {tableCount('sentient_execution_events')}. Providers table: {tableCount('sentient_provider_credentials')}.
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
							{#each recentLocalEvents as event}
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
