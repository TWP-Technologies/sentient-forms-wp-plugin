<script lang="ts">
	import { licenseSummary, sessionStore, type LicenseStatus } from '$lib/stores/session';
	import { Button, Card, Section, Badge } from '$lib/components/ui';
	import { onMount } from 'svelte';
	import { wpFetch } from '$lib/wp';
	import { getNextCreditReset } from '$lib/utils/credits';

	let loading = $state(true);
	let error = $state<string | null>(null);
	let creditData = $state<CreditResponse | null>(null);

	interface LicenseResponse {
		status: string;
		license_key_masked: string;
		proxy_key_present: boolean;
		tier: string | null;
		expires_at: string | null;
		last_synced: string | null;
		license_id: string | null;
		site_id: string | null;
		site_url: string;
	}

	interface CreditResponse {
		current_balance: number;
		ledger_delta?: number;
		tier?: { code: string; display_name: string; monthly_credit_quota: number };
		stale?: boolean;
		dev_mode?: boolean;
	}

	async function fetchDashboardData() {
		loading = true;
		error = null;

		try {
			// FR-009: Fetch real credit balance from license/credits endpoint
			// FR-010: Fetch real license status
			const [licenseRes, creditRes] = await Promise.allSettled([
				wpFetch<LicenseResponse>('license'),
				wpFetch<CreditResponse>('credits/balance')
			]);

			const licenseData = licenseRes.status === 'fulfilled' ? licenseRes.value : null;
			creditData = creditRes.status === 'fulfilled' ? creditRes.value : null;

			sessionStore.hydrate({
				siteUrl: licenseData?.site_url ?? window.location.origin,
				licenseStatus: (licenseData?.status as LicenseStatus) ?? 'inactive',
				proxyKeyPresent: licenseData?.proxy_key_present ?? false,
				creditsRemaining: creditData?.current_balance ?? 0,
				lastSync: licenseData?.last_synced ?? null
			});
		} catch (e) {
			error = e instanceof Error ? e.message : 'Failed to fetch dashboard data';
			// Fallback to showing cached/default state
			sessionStore.hydrate({
				siteUrl: window.location.origin,
				licenseStatus: 'error',
				proxyKeyPresent: false,
				creditsRemaining: 0,
				lastSync: null
			});
		} finally {
			loading = false;
		}
	}

	onMount(() => {
		fetchDashboardData();
	});
</script>

<Section heading="Dashboard" description="High-level health of Sentient Forms automation.">
	{#snippet actions()}
		<Button variant="secondary" onclick={fetchDashboardData} disabled={loading}>
			{loading ? 'Refreshing...' : 'Refresh'}
		</Button>
	{/snippet}

	{#if error}
		<Card>
			<p class="sf:text-center sf:text-amber-600 sf:text-sm">{error}</p>
		</Card>
	{/if}

	<div class="sf:grid sf:gap-4 sf:md:grid-cols-3">
		<Card>
			<div class="sf:space-y-1">
				<h3 class="sf:text-sm sf:font-medium sf:text-slate-500">License status</h3>
				{#if loading}
					<p class="sf:text-lg sf:font-semibold sf:text-slate-300">Loading...</p>
				{:else}
					<p class="sf:text-lg sf:font-semibold">{$licenseSummary}</p>
					<Badge variant={$sessionStore.licenseStatus === 'active' ? 'success' : 'warning'}>
						{$sessionStore.licenseStatus ?? 'unknown'}
					</Badge>
				{/if}
			</div>
		</Card>
		<Card>
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-500">Credits remaining</h3>
			{#if loading}
				<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-300">Loading...</p>
			{:else}
				{@const quota = creditData?.tier?.monthly_credit_quota}
				{@const resetInfo = getNextCreditReset()}
				<p class="sf:mt-2 sf:text-lg sf:font-semibold">
					{$sessionStore.creditsRemaining ?? '—'}{quota ? ` / ${quota}` : ''}
				</p>
				<p class="sf:text-xs sf:text-slate-400 sf:mt-1">{resetInfo.summary}</p>
			{/if}
		</Card>
		<Card>
			<h3 class="sf:text-sm sf:font-medium sf:text-slate-500">Last sync</h3>
			{#if loading}
				<p class="sf:mt-2 sf:text-lg sf:font-semibold sf:text-slate-300">Loading...</p>
			{:else}
				<p class="sf:mt-2 sf:text-lg sf:font-semibold">
					{$sessionStore.lastSync ? new Date($sessionStore.lastSync).toLocaleString() : '—'}
				</p>
			{/if}
		</Card>
	</div>
</Section>
