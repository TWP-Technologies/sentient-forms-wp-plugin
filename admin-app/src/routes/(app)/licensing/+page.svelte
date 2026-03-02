<script lang="ts">
	import { createClientFromConfig } from '$lib/api/client';
	import type { BillingStateResponse, CreditBalanceResponse } from '$lib/api/types';
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
	import { notifications } from '$lib/stores/notifications';
	import { getNextCreditReset } from '$lib/utils/credits';
	import { formatTimestamp } from '$lib/utils/date-time';
	import {
		buildCreditPresentation,
		type CreditSeverity,
		type QuotaCtaAction,
		creditSeverityToBadgeVariant,
		formatCreditSeverityLabel,
		licenseStatusToBadgeVariant,
		resolveTierDisplayName
	} from '$lib/utils/license-health-presentation';
	import { wpFetch } from '$lib/wp';
	import { onMount } from 'svelte';

	interface CheckoutPlanOption {
		code: string;
		label: string;
		description: string;
		ctaLabel: string;
		trialPeriodDays?: number;
		quantity?: number;
	}

	const checkoutPlans: CheckoutPlanOption[] = [
		{
			code: 'starter',
			label: 'Starter',
			description: 'For a single site with baseline automation volume.',
			ctaLabel: 'Choose Starter'
		},
		{
			code: 'pro',
			label: 'Pro',
			description: 'For growing teams with larger monthly credit needs.',
			ctaLabel: 'Choose Pro'
		},
		{
			code: 'business',
			label: 'Business',
			description: 'For agencies and higher-volume multi-site operations.',
			ctaLabel: 'Choose Business'
		}
	];

	const client = createClientFromConfig();

	let licenseKey = $state('');
	let issues: ValidationIssue[] = $state([]);
	let credits = $state<CreditBalanceResponse | null>(null);
	let creditsLoading = $state(false);
	let creditsError = $state<string | null>(null);
	let billing = $state<BillingStateResponse | null>(null);
	let billingLoading = $state(false);
	let billingError = $state<string | null>(null);
	let checkoutPlanPending = $state<string | null>(null);
	let portalLoading = $state(false);

	let resetInfo = $derived(getNextCreditReset());
	let creditPresentation = $derived(
		buildCreditPresentation(credits, resetInfo.summary, 'licensing')
	);
	let creditSeverityLabel = $derived(formatCreditSeverityLabel(creditPresentation.severity));
	let creditSeverityVariant = $derived(creditSeverityToBadgeVariant(creditPresentation.severity));
	let licenseStatusVariant = $derived(licenseStatusToBadgeVariant($licenseStore.status));
	let tierLabel = $derived(
		resolveTierDisplayName(credits?.tier ?? $licenseStore.tier ?? null) ?? '—'
	);
	let billingBusy = $derived(Boolean(checkoutPlanPending || portalLoading));
	let billingSubscriptionStatus = $derived(
		billing?.subscription?.status ?? ($licenseStore.status === 'active' ? 'free' : 'inactive')
	);
	let billingAllocation = $derived(billing?.allocation ?? null);
	let billingAllocationUsage = $derived(
		billingAllocation
			? `${billingAllocation.active_sites} / ${billingAllocation.allowed_sites}`
			: '—'
	);

	onMount(() => {
		void (async () => {
			await licenseStore.load();
			await Promise.all([fetchCredits(), fetchBillingState()]);
		})();
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

	async function fetchBillingState() {
		if ($licenseStore.status !== 'active' || !$licenseStore.proxyKeyPresent) {
			billing = null;
			billingError = null;
			billingLoading = false;
			return;
		}

		billingLoading = true;
		billingError = null;

		try {
			billing = await client.getBillingState({ showNotifications: false });
		} catch (error) {
			console.error('Failed to fetch billing state', error);
			billing = null;
			billingError = 'Unable to load billing state.';
		} finally {
			billingLoading = false;
		}
	}

	function currentRouteUrl(): string {
		if (typeof window === 'undefined') {
			return '/wp-admin/';
		}
		return window.location.href;
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
		await Promise.all([fetchCredits(), fetchBillingState()]);
	}

	async function handleOpenBillingPortal() {
		portalLoading = true;
		billingError = null;

		try {
			const session = await client.createPortalSession(currentRouteUrl(), {
				showNotifications: false
			});
			if (typeof window !== 'undefined') {
				window.location.assign(session.portal_url);
			}
		} catch (error) {
			console.error('Failed to create billing portal session', error);
			notifications.error('Unable to open billing portal right now.');
			billingError = 'Unable to open billing portal right now.';
		} finally {
			portalLoading = false;
		}
	}

	async function handleCheckout(plan: CheckoutPlanOption) {
		checkoutPlanPending = plan.code;
		billingError = null;

		try {
			const session = await client.createCheckoutSession(
				{
					plan_code: plan.code,
					success_url: currentRouteUrl(),
					cancel_url: currentRouteUrl(),
					quantity: plan.quantity ?? 1,
					trial_period_days: plan.trialPeriodDays
				},
				{ showNotifications: false }
			);
			if (typeof window !== 'undefined') {
				window.location.assign(session.checkout_url);
			}
		} catch (error) {
			console.error('Failed to create checkout session', error);
			notifications.error('Unable to start checkout right now.');
			billingError = 'Unable to start checkout right now.';
		} finally {
			checkoutPlanPending = null;
		}
	}

	async function handleDeactivateLicense() {
		await licenseStore.deactivate();
		await Promise.all([fetchCredits(), fetchBillingState()]);
	}

	function handleQuotaCtaAction(action: QuotaCtaAction) {
		if (action === 'open_billing') {
			void handleOpenBillingPortal();
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
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
						License
					</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="licensing-status-headline"
					>
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
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
						Credits
					</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="licensing-credits-headline"
					>
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
						<div
							class="sf:w-full sf:bg-slate-200 sf:rounded-full sf:h-2.5"
							data-testid="licensing-credit-progress"
						>
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
					onAction={handleQuotaCtaAction}
					testId="licensing-quota-cta-callout"
					ctaTestId="licensing-quota-cta-button"
					reasonTestId="licensing-quota-cta-reason"
				/>
			{/if}

			<div
				class="sf:mt-6 sf:pt-5 sf:border-t sf:border-slate-200 sf:space-y-4"
				data-testid="licensing-billing-controls"
			>
				<div class="sf:flex sf:flex-wrap sf:items-start sf:justify-between sf:gap-3">
					<div>
						<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
							Billing
						</p>
						<p class="sf:text-sm sf:text-slate-700">
							Subscription status:
							<span class="sf:font-semibold sf:text-slate-900">{billingSubscriptionStatus}</span>
							{#if billingLoading}
								<span class="sf:ml-2 sf:text-xs sf:text-slate-500">(refreshing…)</span>
							{/if}
						</p>
						{#if billing?.subscription?.current_period_end}
							<p class="sf:text-xs sf:text-slate-500">
								Current period ends {formatTimestamp(billing.subscription.current_period_end)}
							</p>
						{/if}
						<p class="sf:text-xs sf:text-slate-500">Site capacity: {billingAllocationUsage}</p>
						{#if billingAllocation}
							<p class="sf:text-xs sf:text-slate-500">
								Tier site limit {billingAllocation.tier_site_limit} x seats {billingAllocation.seat_quantity}
							</p>
							{#if billingAllocation.over_limit}
								<p class="sf:text-xs sf:font-semibold sf:text-warning-700">
									Allocation limit exceeded. New activations are blocked.
									{#if billingAllocation.grace_expires_at}
										Grace ends {formatTimestamp(billingAllocation.grace_expires_at)}.
									{/if}
								</p>
							{/if}
						{/if}
					</div>
					<Button
						variant="secondary"
						disabled={billingBusy}
						onclick={() => {
							void handleOpenBillingPortal();
						}}
					>
						{portalLoading ? 'Opening…' : 'Manage billing'}
					</Button>
				</div>

				<div class="sf:grid sf:gap-3 sf:md:grid-cols-3">
					{#each checkoutPlans as plan}
						<div
							class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-2"
						>
							<p class="sf:text-sm sf:font-semibold sf:text-slate-900">{plan.label}</p>
							<p class="sf:text-xs sf:text-slate-600">{plan.description}</p>
							<Button
								variant="secondary"
								class="sf:w-full"
								disabled={billingBusy}
								onclick={() => {
									void handleCheckout(plan);
								}}
							>
								{checkoutPlanPending === plan.code ? 'Redirecting…' : plan.ctaLabel}
							</Button>
						</div>
					{/each}
				</div>

				{#if billingError}
					<StateTemplate
						variant="error"
						title="Billing action unavailable"
						message={billingError}
						actionLabel="Retry billing state"
						onAction={() => {
							void fetchBillingState();
						}}
						inline
						testId="licensing-billing-error-state"
					/>
				{/if}
			</div>
		</Card>
	{/if}

	<Card title="License details">
		<div class="sf:text-sm sf:space-y-2">
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">License status</span>
				<span data-testid="licensing-details-status">
					<Badge variant={licenseStatusVariant}>{$licenseStore.status}</Badge>
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Proxy key stored</span>
				<span class="sf:text-slate-900 sf:font-semibold">
					{$licenseStore.proxyKeyPresent ? 'Yes' : 'No'}
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Tier</span>
				<span class="sf:text-slate-900">{tierLabel}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Subscription status</span>
				<span class="sf:text-slate-900">{billingSubscriptionStatus}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Site capacity</span>
				<span class="sf:text-slate-900">{billingAllocationUsage}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Billing period end</span>
				<span class="sf:text-slate-900">
					{formatTimestamp(billing?.subscription?.current_period_end ?? null)}
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Capacity policy</span>
				<span class="sf:text-slate-900">{billingAllocation?.capacity_policy ?? '—'}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Over-limit grace</span>
				<span class="sf:text-slate-900">
					{formatTimestamp(billingAllocation?.grace_expires_at ?? null)}
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Credits reset</span>
				<span class="sf:text-slate-900">{resetInfo.nextResetLabel}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Expires</span>
				<span class="sf:text-slate-900">{formatTimestamp($licenseStore.expiresAt)}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
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
				onclick={() => {
					void handleDeactivateLicense();
				}}
			>
				{$licenseStore.loading ? 'Processing…' : 'Deactivate license'}
			</Button>
		{/if}
	</Card>
</Section>
