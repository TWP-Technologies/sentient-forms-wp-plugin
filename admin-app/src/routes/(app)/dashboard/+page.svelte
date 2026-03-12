<script lang="ts">
	import type { CreditBalanceResponse, LicenseInfoResponse } from '$lib/api/types';
	import { Badge, Button, Card, QuotaCtaCallout, Section, StateTemplate } from '$lib/components/ui';
	import { onMount } from 'svelte';
	import {
		buildCreditPresentation,
		type CreditSeverity,
		creditSeverityToBadgeVariant,
		formatCreditSeverityLabel,
		isConnectedLicenseStatus,
		licenseStatusToBadgeVariant,
		type QuotaCtaAction,
		resolveTierDisplayName
	} from '$lib/utils/license-health-presentation';
	import { getNextCreditReset } from '$lib/utils/credits';
	import { formatTimestamp } from '$lib/utils/date-time';
	import { navigateToAppPath } from '$lib/navigation';
	import { loadLicenseInfoSnapshot } from '$lib/stores/license';
	import { sessionStore, type LicenseStatus } from '$lib/stores/session';
	import { wpFetch } from '$lib/wp';

	let loading = $state(true);
	let error = $state<string | null>(null);
	let licenseData = $state<LicenseInfoResponse | null>(null);
	let creditData = $state<CreditBalanceResponse | null>(null);

	let resetInfo = $derived(getNextCreditReset());
	let creditPresentation = $derived(
		buildCreditPresentation(creditData, resetInfo.summary, 'dashboard')
	);
	let creditSeverityLabel = $derived(formatCreditSeverityLabel(creditPresentation.severity));
	let creditSeverityVariant = $derived(creditSeverityToBadgeVariant(creditPresentation.severity));
	let licenseStatusVariant = $derived(licenseStatusToBadgeVariant($sessionStore.licenseStatus));
	let tierLabel = $derived(
		resolveTierDisplayName(creditData?.tier ?? licenseData?.tier ?? null) ?? '—'
	);
	let licenseSummaryText = $derived(
		isConnectedLicenseStatus($sessionStore.licenseStatus)
			? $sessionStore.proxyKeyPresent
				? $sessionStore.licenseStatus === 'trial'
					? 'Trial active'
					: 'License active'
				: $sessionStore.licenseStatus === 'trial'
					? 'Trial active — proxy key missing'
					: 'License active — proxy key missing'
			: $sessionStore.licenseStatus === 'activating'
				? 'Activating license…'
				: $sessionStore.licenseStatus === 'error'
					? 'Activation error'
					: 'No active license'
	);

	async function fetchDashboardData() {
		loading = true;
		error = null;

		try {
			licenseData = await loadLicenseInfoSnapshot();

			if (isConnectedLicenseStatus(licenseData.status) && licenseData.proxy_key_present) {
				try {
					creditData = await wpFetch<CreditBalanceResponse>('credits/balance?force_refresh=1');
				} catch (creditError) {
					console.error('Failed to fetch dashboard credit balance', creditError);
					creditData = null;
					error = 'Credit details are temporarily unavailable.';
				}
			} else {
				creditData = null;
			}

			sessionStore.hydrate({
				siteUrl: licenseData?.site_url ?? window.location.origin,
				licenseStatus: (licenseData?.status as LicenseStatus) ?? 'inactive',
				proxyKeyPresent: licenseData?.proxy_key_present ?? false,
				creditsRemaining: creditData?.current_balance ?? null,
				lastSync: licenseData?.last_synced ?? null
			});
		} catch (requestError) {
			error =
				requestError instanceof Error ? requestError.message : 'Failed to fetch dashboard data';

			sessionStore.hydrate({
				siteUrl: window.location.origin,
				licenseStatus: 'error',
				proxyKeyPresent: false,
				creditsRemaining: null,
				lastSync: null
			});
		} finally {
			loading = false;
		}
	}

	function mapQuotaCalloutSeverity(severity: CreditSeverity): Exclude<CreditSeverity, 'normal'> {
		return severity === 'normal' ? 'unknown' : severity;
	}

	function resolveQuotaCalloutTitle(severity: CreditSeverity): string {
		if (severity === 'critical') {
			return 'No credits remaining';
		}
		if (severity === 'warning') {
			return 'Low credits remaining';
		}
		return 'Credit balance unavailable';
	}

	function handleQuotaCtaAction(action: QuotaCtaAction) {
		if (action === 'navigate_licensing') {
			void navigateToAppPath('/licensing');
		}
	}

	onMount(() => {
		void fetchDashboardData();
	});
</script>

<Section
	heading="Dashboard"
	description="At-a-glance health for license status and credit availability."
>
	{#snippet actions()}
		<Button variant="secondary" onclick={fetchDashboardData} disabled={loading}>
			{loading ? 'Refreshing...' : 'Refresh'}
		</Button>
	{/snippet}

	{#if error}
		<StateTemplate
			variant="error"
			title="Dashboard data is partially unavailable"
			message={error}
			actionLabel="Retry"
			onAction={fetchDashboardData}
			testId="dashboard-error-state"
		/>
	{/if}

	<Card class="sf:border-slate-300 sf:bg-slate-50" data-testid="dashboard-overview-card">
		<div class="sf:grid sf:gap-6 sf:lg:grid-cols-2">
			<div class="sf:space-y-3">
				<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
					License health
				</p>
				<p
					class="sf:text-2xl sf:font-semibold sf:text-slate-900"
					data-testid="dashboard-license-summary"
				>
					{licenseSummaryText}
				</p>
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<span data-testid="dashboard-license-status">
						<Badge variant={licenseStatusVariant}>
							{$sessionStore.licenseStatus ?? 'unknown'}
						</Badge>
					</span>
					<span class="sf:text-xs sf:text-slate-500">
						Proxy key {$sessionStore.proxyKeyPresent ? 'present' : 'missing'}
					</span>
				</div>
			</div>

			<div class="sf:space-y-3">
				<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
					Credits
				</p>
				<p
					class="sf:text-2xl sf:font-semibold sf:text-slate-900"
					data-testid="dashboard-credits-headline"
				>
					{loading ? 'Loading credit balance…' : creditPresentation.headline}
				</p>
				<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
					<span data-testid="dashboard-credits-severity">
						<Badge variant={creditSeverityVariant}>
							{creditSeverityLabel}
						</Badge>
					</span>
					<span class="sf:text-xs sf:text-slate-500" data-testid="dashboard-reset-summary">
						{resetInfo.summary}
					</span>
				</div>
				<p class="sf:text-sm sf:text-slate-600" data-testid="dashboard-credits-detail">
					{loading ? 'Refreshing credit details…' : creditPresentation.detail}
				</p>
				{#if !loading && creditPresentation.quotaCta}
					<QuotaCtaCallout
						severity={mapQuotaCalloutSeverity(creditPresentation.severity)}
						title={resolveQuotaCalloutTitle(creditPresentation.severity)}
						message={creditPresentation.detail}
						cta={creditPresentation.quotaCta}
						onAction={handleQuotaCtaAction}
						testId="dashboard-quota-cta-callout"
						ctaTestId="dashboard-quota-cta-button"
						reasonTestId="dashboard-quota-cta-reason"
					/>
				{/if}
			</div>
		</div>
	</Card>

	<div class="sf:grid sf:gap-4 sf:md:grid-cols-3">
		<Card data-testid="dashboard-tier-card">
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-500">Tier</h3>
			<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-900">
				{loading ? 'Loading…' : tierLabel}
			</p>
		</Card>

		<Card data-testid="dashboard-last-sync-card">
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-500">Last sync</h3>
			<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-900">
				{loading ? 'Loading…' : formatTimestamp($sessionStore.lastSync)}
			</p>
		</Card>

		<Card data-testid="dashboard-site-card">
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-500">Site</h3>
			<p class="sf:mt-2 sf:text-sm sf:font-medium sf:text-slate-700 sf:break-all">
				{$sessionStore.siteUrl || '—'}
			</p>
		</Card>
	</div>
</Section>
