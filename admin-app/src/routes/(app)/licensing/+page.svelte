<script lang="ts">
	import type { CreditBalanceResponse } from '$lib/api/types';
	import {
		Badge,
		Button,
		Card,
		InputField,
		QuotaCtaCallout,
		Section,
		StateTemplate,
		ValidationSummary
	} from '$lib/components/ui';
	import type { ValidationIssue } from '$lib/components/ui/types';
	import { licenseStore } from '$lib/stores/license';
	import { getNextCreditReset } from '$lib/utils/credits';
	import { formatTimestamp } from '$lib/utils/date-time';
	import {
		buildCreditPresentation,
		type CreditSeverity,
		creditSeverityToBadgeVariant,
		formatCreditSeverityLabel,
		licenseStatusToBadgeVariant,
		resolveTierDisplayName
	} from '$lib/utils/license-health-presentation';
	import { wpFetch } from '$lib/wp';
	import { onMount } from 'svelte';

	let licenseKey = $state('');
	let issues: ValidationIssue[] = $state([]);
	let credits = $state<CreditBalanceResponse | null>(null);
	let creditsLoading = $state(false);
	let creditsError = $state<string | null>(null);

	let resetInfo = $derived(getNextCreditReset());
	let creditPresentation = $derived(buildCreditPresentation(credits, resetInfo.summary, 'licensing'));
	let creditSeverityLabel = $derived(formatCreditSeverityLabel(creditPresentation.severity));
	let creditSeverityVariant = $derived(creditSeverityToBadgeVariant(creditPresentation.severity));
	let licenseStatusVariant = $derived(licenseStatusToBadgeVariant($licenseStore.status));
	let tierLabel = $derived(resolveTierDisplayName(credits?.tier ?? $licenseStore.tier ?? null) ?? '—');

	onMount(() => {
		void licenseStore.load();
		void fetchCredits();
	});

	async function fetchCredits() {
		creditsLoading = true;
		creditsError = null;

		try {
			credits = await wpFetch<CreditBalanceResponse>('credits/balance');
		} catch (error) {
			console.error('Failed to fetch credits', error);
			credits = null;
			creditsError = 'Unable to load credit balance.';
		} finally {
			creditsLoading = false;
		}
	}

	async function handleActivate(event: SubmitEvent) {
		event.preventDefault();
		issues = [];

		if (!licenseKey.trim()) {
			issues = [{ id: 'license-key', message: 'Enter your license key' }];
			return;
		}

		await licenseStore.activate(licenseKey.trim());
		licenseKey = '';
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
</script>

<Section
	heading={$licenseStore.status === 'active' ? 'License management' : 'License activation'}
	description={$licenseStore.status === 'active'
		? 'Review license status, tier, credits, and reset timing before making changes.'
		: 'Provide your Sentient Forms license key to enable CPS-backed automations.'}
>
	<ValidationSummary {issues} />

	{#if $licenseStore.status !== 'active'}
		<Card>
			<form class="sf:space-y-4" onsubmit={handleActivate}>
				<InputField
					id="license-key"
					bind:value={licenseKey}
					label="License key"
					placeholder="LIC-XXXX-XXXX-XXXX"
					required
					error={issues.find((issue) => issue.id === 'license-key')?.message ?? null}
				/>
				<InputField
					id="site-url"
					value={$licenseStore.siteUrl}
					label="Site URL"
					type="url"
					placeholder={$licenseStore.siteUrl}
					disabled
				/>
				<Button type="submit" disabled={$licenseStore.loading}>
					{$licenseStore.loading ? 'Processing…' : 'Activate'}
				</Button>
			</form>
		</Card>
	{/if}

	{#if $licenseStore.status === 'active'}
		<Card class="sf:border-slate-300 sf:bg-slate-50" data-testid="licensing-overview-card">
			<div class="sf:grid sf:gap-6 sf:lg:grid-cols-2 sf:items-start">
				<div class="sf:space-y-3">
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">License</p>
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="licensing-status-headline">
						Active and connected
					</p>
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<span data-testid="licensing-status-badge">
							<Badge variant={licenseStatusVariant}>{$licenseStore.status}</Badge>
						</span>
						<span class="sf:text-xs sf:text-slate-500">Tier: {tierLabel}</span>
					</div>
					<p class="sf:text-sm sf:text-slate-600" data-testid="licensing-last-synced-summary">
						Last synced: {formatTimestamp($licenseStore.lastSynced)}
					</p>
				</div>

				<div class="sf:space-y-3">
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">Credits</p>
					<p class="sf:text-2xl sf:font-semibold sf:text-slate-900" data-testid="licensing-credits-headline">
						{creditsLoading ? 'Loading credit balance…' : creditPresentation.headline}
					</p>
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<span data-testid="licensing-credit-severity">
							<Badge variant={creditSeverityVariant}>{creditSeverityLabel}</Badge>
						</span>
						<span class="sf:text-xs sf:text-slate-500" data-testid="licensing-reset-summary">
							{resetInfo.summary}
						</span>
					</div>
					<p class="sf:text-sm sf:text-slate-600" data-testid="licensing-credits-detail">
						{creditsLoading ? 'Refreshing credit details…' : creditPresentation.detail}
					</p>

					{#if creditPresentation.percentage !== null}
						<div class="sf:w-full sf:bg-slate-200 sf:rounded-full sf:h-2.5" data-testid="licensing-credit-progress">
							<div
								class="sf:h-2.5 sf:rounded-full {creditPresentation.severity === 'critical'
									? 'sf:bg-danger-500'
									: creditPresentation.severity === 'warning'
										? 'sf:bg-warning-500'
										: 'sf:bg-success-500'}"
								style="width: {creditPresentation.percentage}%"
							></div>
						</div>
					{/if}
				</div>
			</div>

			{#if creditsError}
				<StateTemplate
					variant="error"
					title="Unable to load credit balance"
					message={creditsError}
					actionLabel="Retry credits"
					onAction={() => {
						void fetchCredits();
					}}
					inline
					testId="licensing-credit-error-state"
				/>
			{:else if !creditsLoading && creditPresentation.quotaCta}
				<QuotaCtaCallout
					severity={mapQuotaCalloutSeverity(creditPresentation.severity)}
					title={resolveQuotaCalloutTitle(creditPresentation.severity)}
					message={creditPresentation.detail}
					cta={creditPresentation.quotaCta}
					testId="licensing-quota-cta-callout"
					ctaTestId="licensing-quota-cta-button"
					reasonTestId="licensing-quota-cta-reason"
				/>
			{/if}
		</Card>
	{/if}

	<Card title="License details">
		<div class="sf:text-sm sf:space-y-2">
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center">
				<span class="sf:font-medium sf:text-slate-600">License status</span>
				<span data-testid="licensing-details-status">
					<Badge variant={licenseStatusVariant}>{$licenseStore.status}</Badge>
				</span>
			</div>
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center">
				<span class="sf:font-medium sf:text-slate-600">Proxy key stored</span>
				<span class="sf:text-slate-900 sf:font-semibold">
					{$licenseStore.proxyKeyPresent ? 'Yes' : 'No'}
				</span>
			</div>
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center">
				<span class="sf:font-medium sf:text-slate-600">Tier</span>
				<span class="sf:text-slate-900">{tierLabel}</span>
			</div>
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center">
				<span class="sf:font-medium sf:text-slate-600">Credits reset</span>
				<span class="sf:text-slate-900">{resetInfo.nextResetLabel}</span>
			</div>
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center">
				<span class="sf:font-medium sf:text-slate-600">Expires</span>
				<span class="sf:text-slate-900">{formatTimestamp($licenseStore.expiresAt)}</span>
			</div>
			<div class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center">
				<span class="sf:font-medium sf:text-slate-600">Last synced</span>
				<span class="sf:text-slate-900">{formatTimestamp($licenseStore.lastSynced)}</span>
			</div>
		</div>

		{#if $licenseStore.loading}
			<StateTemplate
				variant="loading"
				title="Updating license details"
				message="This may take a few seconds."
				inline
				testId="licensing-loading-state"
			/>
		{/if}

		{#if $licenseStore.status === 'active'}
			<Button
				variant="secondary"
				class="sf:mt-4"
				disabled={$licenseStore.loading}
				onclick={() => licenseStore.deactivate()}
			>
				{$licenseStore.loading ? 'Processing…' : 'Deactivate license'}
			</Button>
		{/if}
	</Card>
</Section>
