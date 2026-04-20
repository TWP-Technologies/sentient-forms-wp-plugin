<script lang="ts">
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		ApiErrorPayload,
		BillingPolicyState,
		BillingPortalSessionRequest,
		BillingSubscriptionState,
		BillingStateResponse,
		CreditBalanceResponse,
		TierSummary
	} from '$lib/api/types';
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
		isConnectedLicenseStatus,
		licenseStatusToBadgeVariant,
		resolveTierDisplayName
	} from '$lib/utils/license-health-presentation';
	import { onMount, tick } from 'svelte';

	interface CheckoutPlanOption {
		code: string;
		label: string;
		description: string;
		ctaLabel: string;
		trialPeriodDays?: number;
		quantity?: number;
	}

	type BillingActionContext = 'billing_state' | 'checkout' | 'portal';

	interface BillingUiError {
		title: string;
		message: string;
		actionLabel: string;
		retry: () => Promise<void>;
	}

	const BUSINESS_PLAN_SITE_CAP = 200;
	const DEFAULT_BILLING_POLICY: BillingPolicyState = {
		paid_trial_days: 14,
		free_plan_monthly_credits: 50,
		free_plan_indefinite: true,
		private_beta_trial_enabled: true
	};

	const checkoutPlanCatalog = [
		{
			code: 'starter',
			label: 'Starter',
			priceDescription: '$15/month, 1 site, 1,500 monthly credits.',
			ctaLabel: 'Choose Starter'
		},
		{
			code: 'pro',
			label: 'Pro',
			priceDescription: '$39/month, up to 5 sites, 4,000 monthly credits.',
			ctaLabel: 'Choose Pro'
		},
		{
			code: 'business',
			label: 'Business',
			priceDescription: `$99/month, up to ${BUSINESS_PLAN_SITE_CAP} sites during launch, 12,000 monthly credits.`,
			ctaLabel: 'Choose Business'
		}
	] as const;

	function buildEffectiveCreditSnapshot(
		billingState: BillingStateResponse | null
	): CreditBalanceResponse | null {
		if (!billingState?.credits) {
			return null;
		}

		return {
			current_balance: billingState.credits.current_balance,
			ledger_delta: billingState.credits.ledger_delta,
			tier:
				resolveBillingTier(billingState) ??
				{
					code: 'unknown',
					display_name: 'Unknown',
					monthly_credit_quota: billingState.credits.tier_quota
				},
			stale: false
		};
	}

	function resolveBillingTier(billingState: BillingStateResponse | null): TierSummary | null {
		return billingState?.plan ?? billingState?.account?.tier ?? billingState?.tier ?? null;
	}

	function resolveBillingStatus(billingState: BillingStateResponse | null): string | null {
		return (
			billingState?.status ??
			billingState?.account?.license_status ??
			billingState?.license_status ??
			null
		);
	}

	function resolveBillingSubscription(
		billingState: BillingStateResponse | null
	): BillingSubscriptionState | null {
		return billingState?.billing?.subscription ?? billingState?.subscription ?? null;
	}

	function resolveBillingProvider(billingState: BillingStateResponse | null): string {
		return billingState?.billing?.provider ?? billingState?.provider ?? 'not configured';
	}

	function formatBillingBoundaryValue(value: boolean | null | undefined): string {
		if (value === true) {
			return 'Sentient billed';
		}
		if (value === false) {
			return 'Not Sentient billed';
		}
		return 'Not configured';
	}

	function billingBoundaryVariant(
		value: boolean | null | undefined
	): 'neutral' | 'success' | 'warning' {
		if (value === true) {
			return 'warning';
		}
		if (value === false) {
			return 'success';
		}
		return 'neutral';
	}

	function formatMicroUsd(microUsd: number): string {
		const dollars = microUsd / 1_000_000;
		return dollars >= 0.01 ? `$${dollars.toFixed(2)}` : `$${dollars.toFixed(4)}`;
	}

	function normalizeManagedUsageMetric(value: unknown): number {
		return typeof value === 'number' && Number.isFinite(value) ? value : 0;
	}

	function resolveManagedUsageMetrics(usage: BillingStateResponse['managed_usage'] | null) {
		return {
			totalEvents: normalizeManagedUsageMetric(usage?.total_events ?? usage?.execution_count),
			succeededEvents: normalizeManagedUsageMetric(
				usage?.succeeded_events ?? usage?.succeeded_count
			),
			failedEvents: normalizeManagedUsageMetric(usage?.failed_events ?? usage?.failed_count),
			inputTokens: normalizeManagedUsageMetric(
				usage?.total_input_tokens ?? usage?.token_usage?.input_tokens
			),
			outputTokens: normalizeManagedUsageMetric(
				usage?.total_output_tokens ?? usage?.token_usage?.output_tokens
			),
			billedMicroUsd: normalizeManagedUsageMetric(
				usage?.total_billed_micro_usd ?? usage?.billing?.billed_amount_microusd
			)
		};
	}

	function resolveMonthlyManagedCreditQuota(tier: TierSummary | null): number | null {
		const quota = tier?.monthly_credit_quota;
		return typeof quota === 'number' && Number.isFinite(quota) && quota > 0 ? quota : null;
	}

	function buildManagedCreditsHeadline(
		creditSnapshot: CreditBalanceResponse | null,
		monthlyQuota: number | null,
		headline: string
	): string {
		if (creditSnapshot) {
			return headline;
		}

		if (monthlyQuota !== null) {
			return `${monthlyQuota.toLocaleString()} monthly managed credits included`;
		}

		return headline;
	}

	function buildManagedCreditsDetail(
		creditSnapshot: CreditBalanceResponse | null,
		monthlyQuota: number | null,
		detail: string
	): string {
		if (creditSnapshot) {
			return detail;
		}

		if (monthlyQuota !== null) {
			return `Managed proxy usage is metered by Sentient. This plan includes ${monthlyQuota.toLocaleString()} monthly managed credits; direct OpenRouter runs stay outside Sentient billing.`;
		}

		return detail;
	}

	const client = createClientFromConfig();

	let licenseKey = $state('');
	let issues: ValidationIssue[] = $state([]);
	let billing = $state<BillingStateResponse | null>(null);
	let billingLoading = $state(false);
	let billingError = $state<BillingUiError | null>(null);
	let checkoutPlanPending = $state<string | null>(null);
	let portalLoading = $state(false);
	let billingControlsElement = $state<HTMLDivElement | null>(null);

	let resetInfo = $derived(getNextCreditReset());
	let effectiveCredits = $derived(buildEffectiveCreditSnapshot(billing));
	let creditPresentation = $derived(
		buildCreditPresentation(effectiveCredits, resetInfo.summary, 'licensing')
	);
	let billingTier = $derived(resolveBillingTier(billing));
	let managedCreditQuota = $derived(resolveMonthlyManagedCreditQuota(billingTier));
	let managedCreditsHeadline = $derived(
		buildManagedCreditsHeadline(effectiveCredits, managedCreditQuota, creditPresentation.headline)
	);
	let managedCreditsDetail = $derived(
		buildManagedCreditsDetail(effectiveCredits, managedCreditQuota, creditPresentation.detail)
	);
	let billingStatus = $derived(resolveBillingStatus(billing));
	let billingSubscription = $derived(resolveBillingSubscription(billing));
	let billingProviderLabel = $derived(resolveBillingProvider(billing));
	let billingBoundary = $derived(billing?.billing_boundary ?? null);
	let managedUsage = $derived(billing?.managed_usage ?? null);
	let managedUsageMetrics = $derived(resolveManagedUsageMetrics(managedUsage));
	let billingPolicy = $derived(resolveBillingPolicy(billing?.policy));
	let creditSeverityLabel = $derived(formatCreditSeverityLabel(creditPresentation.severity));
	let creditSeverityVariant = $derived(creditSeverityToBadgeVariant(creditPresentation.severity));
	let managedCreditBadgeLabel = $derived(
		effectiveCredits || managedCreditQuota === null ? creditSeverityLabel : 'Plan allowance'
	);
	let licenseStatusVariant = $derived(licenseStatusToBadgeVariant($licenseStore.status));
	let checkoutPlans = $derived.by(() =>
		checkoutPlanCatalog.map((plan) => ({
			code: plan.code,
			label: plan.label,
			description: `${plan.priceDescription} Includes a one-time ${billingPolicy.paid_trial_days}-day paid-plan trial when eligible.`,
			ctaLabel: plan.ctaLabel,
			trialPeriodDays: billingPolicy.paid_trial_days
		}))
	);
	let tierLabel = $derived(
		resolveTierDisplayName(billingTier ?? $licenseStore.tier ?? effectiveCredits?.tier ?? null) ??
			'—'
	);
	let hasExistingSubscription = $derived(Boolean(billingSubscription?.provider_subscription_id));
	let billingBusy = $derived(Boolean(checkoutPlanPending || portalLoading));
	let hasConnectedLicense = $derived(
		isConnectedLicenseStatus($licenseStore.status) && $licenseStore.proxyKeyPresent
	);
	let billingSubscriptionStatus = $derived(
		billingSubscription?.status ?? (hasConnectedLicense ? (billingStatus ?? 'free') : 'inactive')
	);
	let billingAllocation = $derived(billing?.allocation ?? null);
	let billingAllocationUsage = $derived(
		billingAllocation
			? `${billingAllocation.active_sites} / ${billingAllocation.allowed_sites}`
			: '—'
	);
	let directOpenRouterBoundaryLabel = $derived(
		formatBillingBoundaryValue(billingBoundary?.direct_openrouter_billed_by_sentient)
	);
	let managedProxyBoundaryLabel = $derived(
		formatBillingBoundaryValue(billingBoundary?.managed_proxy_billed_by_sentient)
	);
	let managedUsageSummary = $derived(
		managedUsage
			? `${managedUsageMetrics.totalEvents.toLocaleString()} managed run${
					managedUsageMetrics.totalEvents === 1 ? '' : 's'
				}, ${managedUsageMetrics.succeededEvents.toLocaleString()} succeeded, ${managedUsageMetrics.failedEvents.toLocaleString()} failed.`
			: 'No managed proxy usage recorded yet.'
	);
	let managedUsageTokens = $derived(
		managedUsage
			? `${managedUsageMetrics.inputTokens.toLocaleString()} input tokens, ${managedUsageMetrics.outputTokens.toLocaleString()} output tokens, ${formatMicroUsd(managedUsageMetrics.billedMicroUsd)} billed.`
			: 'Sentient metering starts only after managed proxy execution is enabled.'
	);

	onMount(() => {
		void (async () => {
			await licenseStore.load();
			await refreshLicenseAndBilling();
			await maybeFocusBillingControls();
		})();
	});

	function shouldFocusBillingControls(): boolean {
		if (typeof window === 'undefined') return false;

		const searchFocus = new URLSearchParams(window.location.search).get('focus');
		if (searchFocus === 'billing') {
			return true;
		}

		const rawHash = window.location.hash.replace(/^#/, '');
		const queryIndex = rawHash.indexOf('?');
		if (queryIndex === -1) {
			return false;
		}

		return new URLSearchParams(rawHash.slice(queryIndex + 1)).get('focus') === 'billing';
	}

	function focusBillingControls(): void {
		billingControlsElement?.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	async function maybeFocusBillingControls(): Promise<void> {
		if (!shouldFocusBillingControls()) {
			return;
		}

		await tick();
		focusBillingControls();
	}

	function defaultBillingErrorMessage(context: BillingActionContext): string {
		switch (context) {
			case 'billing_state':
				return 'Unable to load billing state.';
			case 'checkout':
				return 'Unable to start checkout right now.';
			case 'portal':
				return 'Unable to open billing portal right now.';
		}
	}

	function billingErrorTitle(context: BillingActionContext): string {
		switch (context) {
			case 'portal':
				return 'Billing portal unavailable';
			case 'checkout':
				return 'Billing action unavailable';
			case 'billing_state':
			default:
				return 'Unable to load billing state';
		}
	}

	function billingErrorActionLabel(context: BillingActionContext): string {
		switch (context) {
			case 'portal':
				return 'Retry opening billing portal';
			case 'checkout':
				return 'Retry checkout';
			case 'billing_state':
			default:
				return 'Retry billing state';
		}
	}

	function billingErrorMessage(error: unknown, context: BillingActionContext): string {
		if (error instanceof ApiClientError) {
			switch (error.code) {
				case 'billing_payment_blocked':
					return 'Billing is blocked due to repeated chargeback activity. Contact support to review account restrictions before retrying checkout.';
				case 'billing_not_configured':
					return 'Billing is not configured for this environment yet. Ask an administrator to verify Stripe keys and webhook secrets.';
				case 'trial_unavailable':
					return `This license already consumed its one-time ${billingPolicy.paid_trial_days}-day paid-plan trial. Continue with a paid plan to switch tiers.`;
				case 'billing_provider_unreachable':
				case 'billing_provider_error':
					return 'Stripe is temporarily unavailable. Retry in a moment or use Manage billing once connectivity recovers.';
				case 'subscription_payment_action_required':
					return 'Stripe requires payment authentication to start this plan now. Open Manage billing to complete authentication, then retry.';
			}

			const payload = error.payload as ApiErrorPayload | undefined;
			const providerMessage =
				payload?.message?.trim() || payload?.error?.message?.trim() || error.message.trim();
			if (providerMessage.length > 0 && providerMessage !== 'Request failed') {
				return providerMessage;
			}
		}

		if (error instanceof Error && error.message.trim().length > 0) {
			return error.message.trim();
		}

		return defaultBillingErrorMessage(context);
	}

	function buildPortalSessionRequest(): BillingPortalSessionRequest {
		const subscriptionId = billingSubscription?.provider_subscription_id ?? null;
		if (subscriptionId) {
			return {
				return_url: currentRouteUrl(),
				flow_type: 'subscription_update',
				subscription_id: subscriptionId
			};
		}

		return {
			return_url: currentRouteUrl(),
			flow_type: 'home'
		};
	}

	function setBillingError(
		error: unknown,
		context: BillingActionContext,
		retry: () => Promise<void>,
		notify = true
	): void {
		const message = billingErrorMessage(error, context);
		billingError = {
			title: billingErrorTitle(context),
			message,
			actionLabel: billingErrorActionLabel(context),
			retry
		};
		if (notify) {
			notifications.error(message);
		}
	}

	async function fetchBillingState() {
		if (!hasConnectedLicense) {
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
			setBillingError(error, 'billing_state', fetchBillingState, false);
		} finally {
			billingLoading = false;
		}
	}

	async function refreshLicenseAndBilling() {
		if (!hasConnectedLicense) {
			billing = null;
			billingError = null;
			return;
		}

		await fetchBillingState();
		if ($licenseStore.proxyKeyPresent) {
			await licenseStore.load();
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
		await refreshLicenseAndBilling();
	}

	async function handleOpenBillingPortal() {
		portalLoading = true;
		billingError = null;

		try {
			const session = await client.createPortalSession(buildPortalSessionRequest(), {
				showNotifications: false
			});
			if (typeof window !== 'undefined') {
				window.location.assign(session.portal_url);
			}
		} catch (error) {
			console.error('Failed to create billing portal session', error);
			setBillingError(error, 'portal', handleOpenBillingPortal);
		} finally {
			portalLoading = false;
		}
	}

	async function handleCheckout(plan: CheckoutPlanOption) {
		if (hasExistingSubscription) {
			await handleOpenBillingPortal();
			return;
		}

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
			setBillingError(error, 'checkout', async () => {
				await handleCheckout(plan);
			});
		} finally {
			checkoutPlanPending = null;
		}
	}

	async function handleDeactivateLicense() {
		await licenseStore.deactivate();
		await refreshLicenseAndBilling();
	}

	function handleQuotaCtaAction(action: QuotaCtaAction) {
		if (action === 'focus_licensing_billing') {
			focusBillingControls();
			return;
		}

		if (action === 'open_billing') {
			void handleOpenBillingPortal();
		}
	}

	function mapQuotaCalloutSeverity(severity: CreditSeverity): Exclude<CreditSeverity, 'normal'> {
		return severity === 'normal' ? 'unknown' : severity;
	}

	function resolveQuotaCalloutTitle(_severity: CreditSeverity): string {
		return creditPresentation.calloutTitle;
	}

	function resolveBillingPolicy(
		policy: BillingStateResponse['policy'] | null | undefined
	): BillingPolicyState {
		return {
			paid_trial_days:
				typeof policy?.paid_trial_days === 'number' && policy.paid_trial_days > 0
					? policy.paid_trial_days
					: DEFAULT_BILLING_POLICY.paid_trial_days,
			free_plan_monthly_credits:
				typeof policy?.free_plan_monthly_credits === 'number' &&
				policy.free_plan_monthly_credits >= 0
					? policy.free_plan_monthly_credits
					: DEFAULT_BILLING_POLICY.free_plan_monthly_credits,
			free_plan_indefinite:
				typeof policy?.free_plan_indefinite === 'boolean'
					? policy.free_plan_indefinite
					: DEFAULT_BILLING_POLICY.free_plan_indefinite,
			private_beta_trial_enabled:
				typeof policy?.private_beta_trial_enabled === 'boolean'
					? policy.private_beta_trial_enabled
					: DEFAULT_BILLING_POLICY.private_beta_trial_enabled
		};
	}

	function freePlanPolicyText(policy: BillingPolicyState): string {
		const cadence = policy.free_plan_indefinite
			? 'remains available indefinitely'
			: 'remains available';
		return `The Free plan ${cadence} with ${policy.free_plan_monthly_credits} monthly credits.`;
	}
</script>

<Section
	heading={hasConnectedLicense ? 'License management' : 'License activation'}
	description={hasConnectedLicense
		? 'Review Sentient managed billing, usage, and site allocation. Direct OpenRouter remains outside Sentient billing.'
		: 'Provide your Sentient Forms license key to enable optional Sentient managed billing.'}
>
	<ValidationSummary {issues} />

	{#if !hasConnectedLicense}
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

	{#if hasConnectedLicense}
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
						Managed credits
					</p>
					<p
						class="sf:text-2xl sf:font-semibold sf:text-slate-900"
						data-testid="licensing-credits-headline"
					>
						{billingLoading ? 'Loading billing state…' : managedCreditsHeadline}
					</p>
					<div class="sf:flex sf:flex-wrap sf:items-center sf:gap-2">
						<span data-testid="licensing-credit-severity">
							<Badge variant={creditSeverityVariant}>{managedCreditBadgeLabel}</Badge>
						</span>
						<span class="sf:text-xs sf:text-slate-500" data-testid="licensing-reset-summary">
							{resetInfo.summary}
						</span>
					</div>
					<p class="sf:text-sm sf:text-slate-600" data-testid="licensing-credits-detail">
						{billingLoading ? 'Refreshing billing details…' : managedCreditsDetail}
					</p>

					{#if effectiveCredits && creditPresentation.percentage !== null}
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

			{#if effectiveCredits && !billingLoading && creditPresentation.quotaCta}
				<QuotaCtaCallout
					severity={mapQuotaCalloutSeverity(creditPresentation.severity)}
					title={resolveQuotaCalloutTitle(creditPresentation.severity)}
					message={managedCreditsDetail}
					cta={creditPresentation.quotaCta}
					onAction={handleQuotaCtaAction}
					testId="licensing-quota-cta-callout"
					ctaTestId="licensing-quota-cta-button"
					reasonTestId="licensing-quota-cta-reason"
				/>
			{/if}

			<div
				bind:this={billingControlsElement}
				class="sf:mt-6 sf:pt-5 sf:border-t sf:border-slate-200 sf:space-y-4"
				id="licensing-billing-controls"
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
						<p class="sf:text-xs sf:text-slate-500">
							Billing provider: {billingProviderLabel}
						</p>
						{#if billingSubscription?.current_period_end}
							<p class="sf:text-xs sf:text-slate-500">
								Current period ends {formatTimestamp(billingSubscription.current_period_end)}
							</p>
						{/if}
						{#if billingSubscription?.status === 'trialing'}
							<p
								class="sf:text-xs sf:font-semibold sf:text-success-700"
								data-testid="licensing-trial-status-note"
							>
								{#if billingSubscription.trial_end}
									Trial active until {formatTimestamp(billingSubscription.trial_end)}.
								{:else}
									Trial active for this subscription.
								{/if}
								One-time {billingPolicy.paid_trial_days}-day paid-plan trial.
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

				<div
					class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-3"
					data-testid="licensing-billing-boundary"
				>
					<div>
						<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
							Billing boundary
						</p>
						<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
							BYOK and OpenRouter free-model runs stay outside Sentient metering. Sentient charges
							only for managed proxy runs.
						</p>
					</div>
					<div class="sf:grid sf:gap-2 sf:md:grid-cols-2">
						<div class="sf:flex sf:items-center sf:justify-between sf:gap-2">
							<span class="sf:text-sm sf:font-medium sf:text-slate-700">Direct OpenRouter</span>
							<Badge
								variant={billingBoundaryVariant(
									billingBoundary?.direct_openrouter_billed_by_sentient
								)}
							>
								{directOpenRouterBoundaryLabel}
							</Badge>
						</div>
						<div class="sf:flex sf:items-center sf:justify-between sf:gap-2">
							<span class="sf:text-sm sf:font-medium sf:text-slate-700">Sentient managed proxy</span
							>
							<Badge
								variant={billingBoundaryVariant(billingBoundary?.managed_proxy_billed_by_sentient)}
							>
								{managedProxyBoundaryLabel}
							</Badge>
						</div>
					</div>
					<div
						class="sf:rounded-md sf:bg-slate-50 sf:p-3 sf:text-xs sf:text-slate-600"
						data-testid="licensing-managed-usage-summary"
					>
						<p>{managedUsageSummary}</p>
						<p>{managedUsageTokens}</p>
					</div>
				</div>

				<div class="sf:grid sf:gap-3 sf:md:grid-cols-3">
					{#if !hasExistingSubscription}
						<p
							class="sf:md:col-span-3 sf:text-xs sf:text-slate-500"
							data-testid="licensing-trial-policy-note"
						>
							Eligible paid subscriptions start with a one-time {billingPolicy.paid_trial_days}-day
							paid-plan trial. {freePlanPolicyText(billingPolicy)}
							{#if billingPolicy.private_beta_trial_enabled}
								Invite-only Private Beta sites can remain on Private Beta until you upgrade.
							{/if}
						</p>
					{/if}
					{#if hasExistingSubscription}
						<div
							class="sf:md:col-span-3 sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:space-y-2"
							data-testid="licensing-managed-portal-note"
						>
							<p
								class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500"
							>
								Plan changes
							</p>
							<p class="sf:text-xs sf:text-slate-500">
								Use the Stripe billing portal to change, cancel, or authenticate this managed
								subscription. Direct OpenRouter remains separate.
							</p>
						</div>
					{/if}
					<p
						class="sf:md:col-span-3 sf:text-xs sf:text-slate-500"
						data-testid="licensing-business-cap-note"
					>
						Business currently supports up to {BUSINESS_PLAN_SITE_CAP} sites during launch. Contact support
						for larger agency or multi-brand allocations.
					</p>
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
								{#if hasExistingSubscription}
									{portalLoading ? 'Opening…' : 'Manage in billing portal'}
								{:else}
									{checkoutPlanPending === plan.code ? 'Redirecting…' : plan.ctaLabel}
								{/if}
							</Button>
						</div>
					{/each}
				</div>

				<div
					class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-2"
					data-testid="licensing-managed-top-up-note"
				>
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
						Managed usage
					</p>
					<p class="sf:text-xs sf:text-slate-500">
						Managed proxy usage is governed by the active Sentient plan. Top-up credit packs are
						retired for the local-first service; use direct OpenRouter credentials for non-Sentient
						billed runs or manage the plan in Stripe.
					</p>
				</div>

				{#if billingError}
					<StateTemplate
						variant="error"
						title={billingError.title}
						message={billingError.message}
						actionLabel={billingError.actionLabel}
						onAction={() => {
							void billingError.retry();
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
					{formatTimestamp(billingSubscription?.current_period_end ?? null)}
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Direct OpenRouter billing</span>
				<span class="sf:text-slate-900">{directOpenRouterBoundaryLabel}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-600">Managed proxy billing</span>
				<span class="sf:text-slate-900">{managedProxyBoundaryLabel}</span>
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

		{#if hasConnectedLicense}
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
