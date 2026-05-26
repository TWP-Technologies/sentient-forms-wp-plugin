<script lang="ts">
	import { ApiClientError, createClientFromConfig } from '$lib/api/client';
	import type {
		ApiErrorPayload,
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
		quantity?: number;
	}

	interface ManagedCheckoutReference {
		checkoutIntentId?: string | null;
		checkoutSessionId?: string | null;
		activationToken?: string | null;
	}

	type BillingActionContext = 'billing_state' | 'checkout' | 'portal';

	interface BillingUiError {
		title: string;
		message: string;
		actionLabel: string;
		retry: () => Promise<void>;
	}

	const checkoutPlanCatalog = [
		{
			code: 'starter',
			label: 'Starter',
			priceDescription: '$15/month for this WordPress site, 1,500 monthly managed credits.',
			ctaLabel: 'Choose Starter'
		},
		{
			code: 'pro',
			label: 'Pro',
			priceDescription: '$39/month for this WordPress site, 3,900 monthly managed credits.',
			ctaLabel: 'Choose Pro'
		},
		{
			code: 'business',
			label: 'Business',
			priceDescription: '$99/month for this WordPress site, 9,900 monthly managed credits.',
			ctaLabel: 'Choose Business'
		}
	] as const;
	const checkoutPlanRank: Record<string, number> = {
		starter: 1,
		pro: 2,
		business: 3
	};

	const MANAGED_DISCLOSURE_VERSION = 'managed-service-v1';

	function buildEffectiveCreditSnapshot(
		billingState: BillingStateResponse | null
	): CreditBalanceResponse | null {
		if (!billingState?.credits) {
			return null;
		}

		return {
			current_balance: billingState.credits.current_balance,
			ledger_delta: billingState.credits.ledger_delta,
			tier: resolveBillingTier(billingState) ?? {
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
			return 'Sentient Forms billed';
		}
		if (value === false) {
			return 'External billing';
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
			return `Managed-service usage is metered by Sentient Forms. This plan includes ${monthlyQuota.toLocaleString()} monthly managed credits; direct OpenRouter runs stay outside Sentient Forms billing.`;
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
	let acceptedManagedCheckoutDisclosure = $state(false);
	let checkoutCompletionLoading = $state(false);
	let managedCheckoutReference = $state<ManagedCheckoutReference | null>(null);
	let managedCheckoutCompletionMessage = $state<string | null>(null);

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
			description: plan.priceDescription,
			ctaLabel: plan.ctaLabel
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

	function isCurrentCheckoutPlan(plan: CheckoutPlanOption): boolean {
		return plan.code === billingTier?.code;
	}

	function checkoutPlanActionLabel(plan: CheckoutPlanOption): string {
		if (checkoutPlanPending === plan.code) {
			return 'Redirecting…';
		}
		if (!hasExistingSubscription) {
			return plan.ctaLabel;
		}
		if (portalLoading) {
			return 'Opening…';
		}
		if (isCurrentCheckoutPlan(plan)) {
			return 'Current plan';
		}
		const currentRank = checkoutPlanRank[billingTier?.code ?? ''] ?? 0;
		const nextRank = checkoutPlanRank[plan.code] ?? currentRank;
		return nextRank > currentRank ? `Upgrade to ${plan.label}` : `Downgrade to ${plan.label}`;
	}
	let managedProxyBoundaryLabel = $derived(
		formatBillingBoundaryValue(billingBoundary?.managed_proxy_billed_by_sentient)
	);
	let managedUsageSummary = $derived(
		managedUsage
			? `${managedUsageMetrics.totalEvents.toLocaleString()} managed run${
					managedUsageMetrics.totalEvents === 1 ? '' : 's'
				}, ${managedUsageMetrics.succeededEvents.toLocaleString()} succeeded, ${managedUsageMetrics.failedEvents.toLocaleString()} failed.`
			: 'No managed-service usage recorded yet.'
	);
	let managedUsageTokens = $derived(
		managedUsage
			? `${managedUsageMetrics.inputTokens.toLocaleString()} input tokens, ${managedUsageMetrics.outputTokens.toLocaleString()} output tokens. Managed usage is shown in credits.`
			: 'Sentient Forms metering starts only after managed-service execution is enabled.'
	);

	onMount(() => {
		void (async () => {
			await completeManagedCheckoutFromReturn();
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
					return 'This site is not eligible for that checkout path. Continue with an active managed-service plan or open the billing portal to update payment details.';
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

	function buildPortalSessionRequest(
		flowType: BillingPortalSessionRequest['flow_type'] = 'home',
		subscriptionId?: string | null
	): BillingPortalSessionRequest {
		const request: BillingPortalSessionRequest = {
			return_url: currentRouteUrl(),
			flow_type: flowType
		};
		if (subscriptionId?.trim()) {
			request.subscription_id = subscriptionId.trim();
		}
		return request;
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

	function managedCheckoutReturnUrl(): string {
		if (typeof window === 'undefined') {
			return '/wp-admin/';
		}

		const url = new URL(window.location.href);
		url.searchParams.delete('sentient_managed_checkout');
		url.searchParams.delete('checkout_intent_id');
		url.searchParams.delete('checkout_session_id');
		url.searchParams.delete('stripe_session_id');
		url.searchParams.delete('activation_token');
		return url.toString();
	}

	function readManagedCheckoutReference(): ManagedCheckoutReference | null {
		if (typeof window === 'undefined') {
			return null;
		}

		const params = new URLSearchParams(window.location.search);
		const checkoutResult = params.get('sentient_managed_checkout');
		if (checkoutResult !== 'success' && checkoutResult !== 'completed') {
			return null;
		}

		const reference = {
			checkoutIntentId: params.get('checkout_intent_id'),
			checkoutSessionId: params.get('checkout_session_id') ?? params.get('stripe_session_id'),
			activationToken: params.get('activation_token')
		};

		if (!reference.checkoutIntentId && !reference.checkoutSessionId) {
			return null;
		}

		return reference;
	}

	function clearManagedCheckoutReturnParams(): void {
		if (typeof window === 'undefined') {
			return;
		}

		const url = new URL(window.location.href);
		url.searchParams.delete('sentient_managed_checkout');
		url.searchParams.delete('checkout_intent_id');
		url.searchParams.delete('checkout_session_id');
		url.searchParams.delete('stripe_session_id');
		url.searchParams.delete('activation_token');
		window.history.replaceState({}, '', url.toString());
	}

	async function completeManagedCheckoutFromReturn(): Promise<void> {
		const reference = readManagedCheckoutReference();
		if (!reference) {
			return;
		}

		managedCheckoutReference = reference;
		await completeManagedCheckout(reference);
	}

	async function completeManagedCheckout(reference: ManagedCheckoutReference): Promise<void> {
		checkoutCompletionLoading = true;
		billingError = null;
		managedCheckoutCompletionMessage = null;

		try {
			const result = await client.completeManagedCheckout(
				{
					checkout_intent_id: reference.checkoutIntentId,
					checkout_session_id: reference.checkoutSessionId,
					activation_token: reference.activationToken
				},
				{ showNotifications: false }
			);

			if (result.activation_ready) {
				managedCheckoutCompletionMessage =
					'Managed service is active. Sentient Forms stored the site credential for managed execution.';
				notifications.success(managedCheckoutCompletionMessage);
				managedCheckoutReference = null;
				clearManagedCheckoutReturnParams();
				await licenseStore.load();
				await refreshLicenseAndBilling();
				return;
			}

			managedCheckoutCompletionMessage =
				result.message ??
				'Stripe checkout succeeded. Sentient Forms is waiting for the billing webhook before activating this site.';
		} catch (error) {
			console.error('Failed to complete managed checkout', error);
			setBillingError(error, 'checkout', async () => {
				await completeManagedCheckout(reference);
			});
		} finally {
			checkoutCompletionLoading = false;
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
		await refreshLicenseAndBilling();
	}

	async function handleOpenBillingPortal(
		flowType: BillingPortalSessionRequest['flow_type'] = 'home',
		subscriptionId?: string | null
	) {
		portalLoading = true;
		billingError = null;

		try {
			const session = await client.createPortalSession(buildPortalSessionRequest(flowType, subscriptionId), {
				showNotifications: false
			});
			if (typeof window !== 'undefined') {
				window.location.assign(session.portal_url);
			}
		} catch (error) {
			console.error('Failed to create billing portal session', error);
			setBillingError(error, 'portal', async () => {
				await handleOpenBillingPortal(flowType, subscriptionId);
			});
		} finally {
			portalLoading = false;
		}
	}

	async function handleCheckout(plan: CheckoutPlanOption) {
		if (hasExistingSubscription) {
			await handleOpenBillingPortal(
				'subscription_update',
				billingSubscription?.provider_subscription_id ?? null
			);
			return;
		}

		if (!hasExistingSubscription && !acceptedManagedCheckoutDisclosure) {
			issues = [
				{
					id: 'managed-checkout-disclosure',
					message: 'Accept the Sentient Forms managed-service disclosure before checkout.'
				}
			];
			return;
		}

		checkoutPlanPending = plan.code;
		billingError = null;
		issues = [];

		try {
			const session = await client.startManagedCheckout(
				{
					plan_code: plan.code,
					success_url: managedCheckoutReturnUrl(),
					cancel_url: managedCheckoutReturnUrl(),
					disclosure_version: MANAGED_DISCLOSURE_VERSION,
					accepted_managed_service_terms: acceptedManagedCheckoutDisclosure
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
</script>

<Section
	heading={hasConnectedLicense ? 'Managed service' : 'Activate managed service'}
	description={hasConnectedLicense
		? 'Review the optional Sentient Forms managed service for this WordPress site. Use OpenRouter directly when you want to manage the account yourself; use this service when you want Sentient Forms to handle model access, spending controls, metering, and billing.'
		: 'Provide a Sentient Forms license key when you want managed execution for this WordPress site. You can still use free OpenRouter routes or your own OpenRouter key without a managed-service license.'}
>
	<ValidationSummary {issues} />

	{#if !hasConnectedLicense}
		<Card class="sf:border-blue-200 sf:bg-blue-50" data-testid="licensing-managed-checkout-card">
			<div class="sf:grid sf:gap-5 sf:lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
				<div class="sf:space-y-3">
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-blue-900">
						Recommended setup
					</p>
					<h2 class="sf:text-xl sf:font-semibold sf:text-slate-950">
						Let Sentient Forms manage model access
					</h2>
					<p class="sf:text-sm sf:text-slate-700">
						Choose this when you want a site license, spending controls, model routing, and support
						handled from one subscription. Each license covers this WordPress site only.
					</p>
					<label
						class="sf:flex sf:items-start sf:gap-3 sf:rounded-lg sf:border sf:border-blue-300 sf:bg-white sf:p-4 sf:text-sm sf:text-slate-700 sf:shadow-[0_0_22px_rgba(37,99,235,0.10)] sf:focus-within:ring-2 sf:focus-within:ring-blue-500"
						data-testid="licensing-managed-checkout-disclosure"
					>
						<input
							type="checkbox"
							class="sf:mt-1 sf:h-5 sf:w-5"
							bind:checked={acceptedManagedCheckoutDisclosure}
							aria-describedby="managed-checkout-disclosure-copy"
						/>
						<span id="managed-checkout-disclosure-copy" class="sf:space-y-1">
							<span class="sf:block sf:font-semibold sf:text-slate-900">
								Recommended: check this before choosing a managed-service plan.
							</span>
							<span class="sf:block">
								I understand managed-service runs send required prompts and form fields to Sentient
								Forms for model execution and metering.
								<strong class="sf:font-semibold sf:underline">
									Sentient Forms does not store prompt or response payloads for these runs.
								</strong>
							</span>
						</span>
					</label>
					{#if managedCheckoutCompletionMessage}
						<StateTemplate
							variant="empty"
							title={managedCheckoutReference ? 'Activation pending' : 'Managed service active'}
							message={managedCheckoutCompletionMessage}
							actionLabel={managedCheckoutReference ? 'Check again' : null}
							onAction={managedCheckoutReference
								? () => {
										void completeManagedCheckout(managedCheckoutReference);
									}
								: null}
							inline
							testId="licensing-managed-checkout-completion"
						/>
					{/if}
					{#if checkoutCompletionLoading}
						<StateTemplate
							variant="loading"
							title="Completing checkout"
							message="Waiting for the managed-service activation response."
							inline
							testId="licensing-managed-checkout-loading"
						/>
					{/if}
				</div>

				<div class="sf:grid sf:gap-3 sf:md:grid-cols-3">
					{#each checkoutPlans as plan}
						<div
							class="sf:flex sf:flex-col sf:rounded-md sf:border sf:border-blue-200 sf:bg-white sf:p-3 sf:space-y-3"
						>
							<div class="sf:space-y-1">
								<p class="sf:text-sm sf:font-semibold sf:text-slate-950">{plan.label}</p>
								<p class="sf:text-xs sf:text-slate-600">{plan.description}</p>
							</div>
							<Button
								class="sf:mt-auto sf:w-full"
								disabled={Boolean(checkoutPlanPending) ||
									isCurrentCheckoutPlan(plan) ||
									checkoutCompletionLoading ||
									!acceptedManagedCheckoutDisclosure}
								onclick={() => {
									void handleCheckout(plan);
								}}
							>
								{checkoutPlanActionLabel(plan)}
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
						void billingError?.retry();
					}}
					inline
					testId="licensing-managed-checkout-error-state"
				/>
			{/if}
		</Card>

		<Card title="Already have a license key">
			<form class="sf:space-y-4" onsubmit={handleActivate}>
				<div class="sf:grid sf:gap-4 sf:md:grid-cols-[minmax(0,1fr)_minmax(220px,0.45fr)]">
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
				</div>
				<Button type="submit" variant="secondary" disabled={$licenseStore.loading}>
					{$licenseStore.loading ? 'Processing…' : 'Activate license'}
				</Button>
			</form>
		</Card>
	{/if}

	{#if hasConnectedLicense}
		<Card class="sf:border-slate-300 sf:bg-slate-50" data-testid="licensing-overview-card">
			<div class="sf:grid sf:gap-6 sf:lg:grid-cols-2 sf:items-start">
				<div class="sf:space-y-3">
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
						Managed service
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
						<span class="sf:text-xs sf:text-slate-600">Tier: {tierLabel}</span>
					</div>
					<p class="sf:text-sm sf:text-slate-600" data-testid="licensing-last-synced-summary">
						Last synced: {formatTimestamp($licenseStore.lastSynced)}
					</p>
				</div>

				<div class="sf:space-y-3">
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
						Managed service credits
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
						<span class="sf:text-xs sf:text-slate-600" data-testid="licensing-reset-summary">
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
						<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
							Subscription
						</p>
						<p class="sf:text-sm sf:text-slate-700">
							Subscription status:
							<span class="sf:font-semibold sf:text-slate-900">{billingSubscriptionStatus}</span>
							{#if billingLoading}
								<span class="sf:ml-2 sf:text-xs sf:text-slate-600">(refreshing…)</span>
							{/if}
						</p>
						<p class="sf:text-xs sf:text-slate-600">
							Billing provider: {billingProviderLabel}
						</p>
						{#if billingSubscription?.current_period_end}
							<p class="sf:text-xs sf:text-slate-600">
								Current period ends {formatTimestamp(billingSubscription.current_period_end)}
							</p>
						{/if}
						{#if billingSubscription?.status === 'trialing'}
							<p
								class="sf:text-xs sf:font-semibold sf:text-success-700"
								data-testid="licensing-trial-status-note"
							>
								{#if billingSubscription.trial_end}
									Legacy introductory period ends {formatTimestamp(billingSubscription.trial_end)}.
								{:else}
									Legacy introductory period active.
								{/if}
							</p>
						{/if}
						<p class="sf:text-xs sf:text-slate-600">
							Licensed WordPress site: {billingAllocationUsage}
						</p>
						{#if billingAllocation}
							<p class="sf:text-xs sf:text-slate-600">
								Managed-service allocation for this WordPress install.
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
						<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
							Billing boundary
						</p>
						<p class="sf:mt-1 sf:text-sm sf:text-slate-600">
							BYOK and OpenRouter free-model runs stay outside Sentient Forms metering. Sentient
							Forms charges only for managed-service runs.
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
							<span class="sf:text-sm sf:font-medium sf:text-slate-700"
								>Sentient Forms managed service</span
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

				{#if !hasExistingSubscription}
					<label
						class="sf:flex sf:items-start sf:gap-3 sf:rounded-md sf:border sf:border-blue-200 sf:bg-blue-50 sf:p-3 sf:text-sm sf:text-blue-950"
						data-testid="licensing-managed-checkout-disclosure"
					>
						<input
							type="checkbox"
							class="sf:mt-1"
							bind:checked={acceptedManagedCheckoutDisclosure}
							aria-describedby="managed-checkout-disclosure-copy-connected"
						/>
						<span id="managed-checkout-disclosure-copy-connected" class="sf:space-y-1">
							<span class="sf:block sf:font-semibold">Use Sentient Forms managed execution</span>
							<span class="sf:block">
								I understand Sentient Forms will provision managed OpenRouter access, apply the plan
								spending cap for this WordPress site, and bill usage as Sentient Forms credits.
							</span>
						</span>
					</label>
				{/if}

				<div class="sf:grid sf:gap-3 sf:md:grid-cols-3">
					{#if hasExistingSubscription}
						<div
							class="sf:md:col-span-3 sf:rounded-md sf:border sf:border-slate-200 sf:bg-slate-50 sf:p-3 sf:space-y-2"
							data-testid="licensing-managed-portal-note"
						>
							<p
								class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600"
							>
								Plan changes
							</p>
							<p class="sf:text-xs sf:text-slate-600">
								Use the Stripe billing portal to change, cancel, or authenticate this
								managed-service subscription. Direct OpenRouter remains separate.
							</p>
						</div>
					{/if}
					<p
						class="sf:md:col-span-3 sf:text-xs sf:text-slate-600"
						data-testid="licensing-business-cap-note"
					>
						Each Sentient Forms managed-service license covers one WordPress site. Use a separate
						license for each additional site.
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
								disabled={billingBusy ||
									isCurrentCheckoutPlan(plan) ||
									(!hasExistingSubscription && !acceptedManagedCheckoutDisclosure)}
								onclick={() => {
									void handleCheckout(plan);
								}}
							>
								{checkoutPlanActionLabel(plan)}
							</Button>
						</div>
					{/each}
				</div>

				<div
					class="sf:rounded-md sf:border sf:border-slate-200 sf:bg-white sf:p-3 sf:space-y-2"
					data-testid="licensing-managed-top-up-note"
				>
					<p class="sf:text-xs sf:font-semibold sf:uppercase sf:tracking-wide sf:text-slate-600">
						Managed usage
					</p>
					<p class="sf:text-xs sf:text-slate-600">
						Managed-service usage is governed by the active Sentient Forms plan for this WordPress
						site. Direct OpenRouter credentials remain available for runs that are not billed by
						Sentient Forms.
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
				<span class="sf:font-medium sf:text-slate-700">License status</span>
				<span data-testid="licensing-details-status">
					<Badge variant={licenseStatusVariant}>{$licenseStore.status}</Badge>
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Managed service key stored</span>
				<span class="sf:text-slate-900 sf:font-semibold">
					{$licenseStore.proxyKeyPresent ? 'Yes' : 'No'}
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Tier</span>
				<span class="sf:text-slate-900">{tierLabel}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Subscription status</span>
				<span class="sf:text-slate-900">{billingSubscriptionStatus}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Site capacity</span>
				<span class="sf:text-slate-900">{billingAllocationUsage}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Billing period end</span>
				<span class="sf:text-slate-900">
					{formatTimestamp(billingSubscription?.current_period_end ?? null)}
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Direct OpenRouter billing</span>
				<span class="sf:text-slate-900">{directOpenRouterBoundaryLabel}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Managed service billing</span>
				<span class="sf:text-slate-900">{managedProxyBoundaryLabel}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Capacity policy</span>
				<span class="sf:text-slate-900">{billingAllocation?.capacity_policy ?? '—'}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Over-limit grace</span>
				<span class="sf:text-slate-900">
					{formatTimestamp(billingAllocation?.grace_expires_at ?? null)}
				</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Credits reset</span>
				<span class="sf:text-slate-900">{resetInfo.nextResetLabel}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Expires</span>
				<span class="sf:text-slate-900">{formatTimestamp($licenseStore.expiresAt)}</span>
			</div>
			<div
				class="sf:flex sf:flex-col sf:items-start sf:justify-between sf:gap-1 sf:sm:flex-row sf:sm:items-center"
			>
				<span class="sf:font-medium sf:text-slate-700">Last synced</span>
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
