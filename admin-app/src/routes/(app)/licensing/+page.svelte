<script lang="ts">
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		ApiErrorPayload,
		BillingPolicyState,
		BillingPortalSessionRequest,
		BillingStateResponse,
		CreditBalanceResponse
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

	interface TopUpPackOption {
		code: string;
		label: string;
		description: string;
		ctaLabel: string;
		credits: number;
	}

	type BillingActionContext =
		| 'billing_state'
		| 'checkout'
		| 'subscription_change'
		| 'top_up'
		| 'portal';

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

	const topUpPacks: TopUpPackOption[] = [
		{
			code: 'top_up_small',
			label: 'Small top-up',
			description: '5,000 credits for burst usage.',
			ctaLabel: 'Buy $45 pack',
			credits: 5000
		},
		{
			code: 'top_up_medium',
			label: 'Medium top-up',
			description: '10,000 credits for sustained demand.',
			ctaLabel: 'Buy $80 pack',
			credits: 10000
		},
		{
			code: 'top_up_large',
			label: 'Large top-up',
			description: '25,000 credits for agency-scale spikes.',
			ctaLabel: 'Buy $175 pack',
			credits: 25000
		}
	];

	function buildEffectiveCreditSnapshot(
		billingState: BillingStateResponse | null,
		fallbackCredits: CreditBalanceResponse | null
	): CreditBalanceResponse | null {
		if (!billingState?.credits) {
			return fallbackCredits;
		}

		return {
			current_balance: billingState.credits.current_balance,
			ledger_delta: billingState.credits.ledger_delta,
			tier:
				billingState.tier ??
				(fallbackCredits?.tier
					? {
							...fallbackCredits.tier,
							monthly_credit_quota:
								billingState.credits.tier_quota ?? fallbackCredits.tier.monthly_credit_quota
						}
					: {
							code: 'unknown',
							display_name: 'Unknown',
							monthly_credit_quota: billingState.credits.tier_quota
						}),
			stale: false
		};
	}

	const client = createClientFromConfig();

	let licenseKey = $state('');
	let issues: ValidationIssue[] = $state([]);
	let credits = $state<CreditBalanceResponse | null>(null);
	let creditsLoading = $state(false);
	let creditsError = $state<string | null>(null);
	let billing = $state<BillingStateResponse | null>(null);
	let billingLoading = $state(false);
	let billingError = $state<BillingUiError | null>(null);
	let checkoutPlanPending = $state<string | null>(null);
	let topUpPackPending = $state<string | null>(null);
	let subscriptionChangePending = $state<string | null>(null);
	let subscriptionChangeTiming = $state<'start_next_cycle' | 'start_now'>('start_next_cycle');
	let portalLoading = $state(false);

	let resetInfo = $derived(getNextCreditReset());
	let effectiveCredits = $derived(buildEffectiveCreditSnapshot(billing, credits));
	let creditPresentation = $derived(
		buildCreditPresentation(effectiveCredits, resetInfo.summary, 'licensing')
	);
	let billingPolicy = $derived(resolveBillingPolicy(billing?.policy));
	let creditSeverityLabel = $derived(formatCreditSeverityLabel(creditPresentation.severity));
	let creditSeverityVariant = $derived(creditSeverityToBadgeVariant(creditPresentation.severity));
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
		resolveTierDisplayName(billing?.tier ?? $licenseStore.tier ?? effectiveCredits?.tier ?? null) ??
			'—'
	);
	let hasExistingSubscription = $derived(Boolean(billing?.subscription?.provider_subscription_id));
	let billingBusy = $derived(
		Boolean(checkoutPlanPending || topUpPackPending || subscriptionChangePending || portalLoading)
	);
	let hasConnectedLicense = $derived(
		isConnectedLicenseStatus($licenseStore.status) && $licenseStore.proxyKeyPresent
	);
	let billingSubscriptionStatus = $derived(
		billing?.subscription?.status ?? (hasConnectedLicense ? 'free' : 'inactive')
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
			await refreshLicenseAndBilling();
		})();
	});

	function defaultBillingErrorMessage(context: BillingActionContext): string {
		switch (context) {
			case 'billing_state':
				return 'Unable to load billing state.';
			case 'checkout':
				return 'Unable to start checkout right now.';
			case 'subscription_change':
				return 'Unable to change subscription right now.';
			case 'top_up':
				return 'Unable to start top-up checkout right now.';
			case 'portal':
				return 'Unable to open billing portal right now.';
		}
	}

	function billingErrorTitle(context: BillingActionContext): string {
		switch (context) {
			case 'portal':
				return 'Billing portal unavailable';
			case 'checkout':
			case 'subscription_change':
			case 'top_up':
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
			case 'subscription_change':
				return 'Retry plan change';
			case 'top_up':
				return 'Retry top-up checkout';
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

	function resolvePortalRecoveryUrl(error: ApiClientError): string | null {
		const payload = error.payload as ApiErrorPayload | undefined;
		const portalUrl = payload?.error?.meta?.portal_recovery?.portal_url;
		if (typeof portalUrl !== 'string') {
			return null;
		}
		const normalized = portalUrl.trim();
		return normalized.length > 0 ? normalized : null;
	}

	function buildPortalSessionRequest(): BillingPortalSessionRequest {
		const subscriptionId = billing?.subscription?.provider_subscription_id ?? null;
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

	async function fetchCredits(forceRefresh = false) {
		creditsLoading = true;
		creditsError = null;

		try {
			credits = await wpFetch<CreditBalanceResponse>(
				forceRefresh ? 'credits/balance?force_refresh=1' : 'credits/balance'
			);
		} catch (error) {
			console.error('Failed to fetch credits', error);
			credits = null;
			creditsError = 'Unable to load credit balance.';
		} finally {
			creditsLoading = false;
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
			credits = null;
			creditsError = null;
			billing = null;
			billingError = null;
			return;
		}

		await fetchBillingState();
		await fetchCredits(true);
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
			await handleSubscriptionChange(plan);
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

	async function handleSubscriptionChange(plan: CheckoutPlanOption) {
		subscriptionChangePending = plan.code;
		billingError = null;

		try {
			const response = await client.changeSubscription(
				{
					plan_code: plan.code,
					change_timing: subscriptionChangeTiming,
					quantity: plan.quantity ?? 1,
					recovery_return_url: currentRouteUrl()
				},
				{ showNotifications: false }
			);
			const modeLabel =
				response.change_timing === 'start_now' ? 'started now' : 'scheduled for next cycle';
			notifications.success(`Plan updated to ${plan.label} (${modeLabel}).`);
			await refreshLicenseAndBilling();
		} catch (error) {
			console.error('Failed to change subscription plan', error);
			if (
				error instanceof ApiClientError &&
				error.code === 'subscription_payment_action_required'
			) {
				const recoveryUrl = resolvePortalRecoveryUrl(error);
				if (recoveryUrl && typeof window !== 'undefined') {
					notifications.warning(
						'Additional card authentication is required. Redirecting to Stripe billing portal…'
					);
					window.location.assign(recoveryUrl);
					return;
				}
			}
			setBillingError(error, 'subscription_change', async () => {
				await handleSubscriptionChange(plan);
			});
		} finally {
			subscriptionChangePending = null;
		}
	}

	async function handleTopUpCheckout(pack: TopUpPackOption) {
		topUpPackPending = pack.code;
		billingError = null;

		try {
			const session = await client.createTopUpCheckoutSession(
				{
					pack_code: pack.code,
					success_url: currentRouteUrl(),
					cancel_url: currentRouteUrl(),
					quantity: 1
				},
				{ showNotifications: false }
			);
			if (typeof window !== 'undefined') {
				window.location.assign(session.checkout_url);
			}
		} catch (error) {
			console.error('Failed to create top-up checkout session', error);
			setBillingError(error, 'top_up', async () => {
				await handleTopUpCheckout(pack);
			});
		} finally {
			topUpPackPending = null;
		}
	}

	async function handleDeactivateLicense() {
		await licenseStore.deactivate();
		await refreshLicenseAndBilling();
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
		? 'Review license status, tier, credits, and reset timing before making changes.'
		: 'Provide your Sentient Forms license key to enable CPS-backed automations.'}
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
						void refreshLicenseAndBilling();
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
						{#if billing?.subscription?.status === 'trialing'}
							<p
								class="sf:text-xs sf:font-semibold sf:text-success-700"
								data-testid="licensing-trial-status-note"
							>
								{#if billing.subscription.trial_end}
									Trial active until {formatTimestamp(billing.subscription.trial_end)}.
								{:else}
									Trial active for this subscription.
								{/if}
								One-time {billingPolicy.paid_trial_days}-day paid-plan trial.
							</p>
						{/if}
						<p class="sf:text-xs sf:text-slate-500">Site capacity: {billingAllocationUsage}</p>
						{#if typeof billing?.credits?.top_up_available === 'number'}
							<p class="sf:text-xs sf:text-slate-500">
								Top-up credits available: {billing.credits.top_up_available.toLocaleString()}
							</p>
						{/if}
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
						>
							<p
								class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500"
							>
								Plan change timing
							</p>
							<div class="sf:flex sf:flex-wrap sf:gap-2">
								<Button
									variant={subscriptionChangeTiming === 'start_next_cycle'
										? 'primary'
										: 'secondary'}
									onclick={() => {
										subscriptionChangeTiming = 'start_next_cycle';
									}}
									disabled={billingBusy}
								>
									Start next cycle
								</Button>
								<Button
									variant={subscriptionChangeTiming === 'start_now' ? 'primary' : 'secondary'}
									onclick={() => {
										subscriptionChangeTiming = 'start_now';
									}}
									disabled={billingBusy}
								>
									Start now
								</Button>
							</div>
							<p class="sf:text-xs sf:text-slate-500">
								Start next cycle keeps your current plan until renewal. Start now attempts an
								immediate charge and resets your billing anchor.
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
									{subscriptionChangePending === plan.code ? 'Saving…' : `Switch to ${plan.label}`}
								{:else}
									{checkoutPlanPending === plan.code ? 'Redirecting…' : plan.ctaLabel}
								{/if}
							</Button>
						</div>
					{/each}
				</div>

				<div class="sf:space-y-2">
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-500">
						Top-up packs
					</p>
					<p class="sf:text-xs sf:text-slate-500">
						Top-up credits roll forward for up to 12 months.
					</p>
					<div class="sf:grid sf:gap-3 sf:md:grid-cols-3">
						{#each topUpPacks as pack}
							<div
								class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-2"
							>
								<p class="sf:text-sm sf:font-semibold sf:text-slate-900">{pack.label}</p>
								<p class="sf:text-xs sf:text-slate-600">{pack.description}</p>
								<p class="sf:text-xs sf:text-slate-500">{pack.credits.toLocaleString()} credits</p>
								<Button
									variant="secondary"
									class="sf:w-full"
									disabled={billingBusy}
									onclick={() => {
										void handleTopUpCheckout(pack);
									}}
								>
									{topUpPackPending === pack.code ? 'Redirecting…' : pack.ctaLabel}
								</Button>
							</div>
						{/each}
					</div>
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
