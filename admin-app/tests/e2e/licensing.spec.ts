import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';

const billingStateRoutePattern = /\/wp-json\/sentient-forms\/v1\/license\/billing-state(?:\?.*)?$/;

test('licensing screen handles activation flow', async ({ page }) => {
	let status = {
		status: 'inactive',
		license_key_masked: '',
		proxy_key_present: false,
		tier: null,
		expires_at: null,
		last_synced: null,
		license_id: null,
		site_id: null,
		site_url: 'https://example.test'
	};
	let activateRequests = 0;
	let deactivateRequests = 0;

	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: status }),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/license/activate', (route) => {
		activateRequests += 1;
		status = {
			status: 'active',
			license_key_masked: 'LIC-****-****-****',
			proxy_key_present: true,
			tier: 'starter',
			expires_at: '2030-01-01T00:00:00Z',
			last_synced: '2030-01-01T00:00:00Z',
			license_id: 'lic-1',
			site_id: 'site-1',
			site_url: 'https://example.test'
		};

		return route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: status }),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.route('**/wp-json/sentient-forms/v1/license/bootstrap', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: status }),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/license/deactivate', (route) => {
		deactivateRequests += 1;
		status = {
			status: 'inactive',
			license_key_masked: '',
			proxy_key_present: false,
			tier: null,
			expires_at: null,
			last_synced: '2030-01-02T00:00:00Z',
			license_id: null,
			site_id: null,
			site_url: 'https://example.test'
		};

		return route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: status }),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});

	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					credits: {
						current_balance: 100,
						tier_quota: 100,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					},
					policy: {
						paid_trial_days: 14,
						free_plan_monthly_credits: 50,
						free_plan_indefinite: true,
						private_beta_trial_enabled: true
					},
					managed_usage: {
						execution_count: 12,
						succeeded_count: 11,
						failed_count: 1,
						token_usage: {
							input_tokens: 3200,
							output_tokens: 900,
							total_tokens: 4100
						},
						billing: {
							billed_amount_microusd: 18000,
							currency: 'USD'
						}
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/license/billing/checkout-session', (route) => {
		throw new Error(`Legacy checkout route was called: ${route.request().url()}`);
	});

	await page.route('**/wp-json/sentient-forms/v1/license/managed-checkout/start', (route) => {
		throw new Error(`Activation-only flow unexpectedly started checkout: ${route.request().url()}`);
	});

	await page.goto('/#/licensing');

	await expect(page.getByRole('heading', { name: 'Activate managed service' })).toBeVisible();
	await expect(page.getByText('Managed service key stored')).toBeVisible();

	await page.getByLabel('License key').fill('LIC-123456789012345678901234');
	await page.getByRole('button', { name: 'Activate license', exact: true }).click();

	await expect(page.getByText('Tier: Starter')).toBeVisible();
	await expect(
		page.getByRole('button', { name: 'Activate license', exact: true })
	).not.toBeVisible();
	await expect(page.getByTestId('licensing-business-cap-note')).toContainText(
		'Starter, Pro, and Business each cover this WordPress site'
	);
	await expect(page.getByText(/5 sites|25 active|agency|billed yearly|annual/i)).toHaveCount(0);
	await expect(
		page.getByText(
			'Detailed provider token counts stay in internal diagnostics; this screen shows managed action credit usage.'
		)
	).toBeVisible();
	await expect(page.getByText('$0.0180')).toHaveCount(0);
	await expect(page.getByText('USD')).toHaveCount(0);
	await expect(page.getByRole('button', { name: 'Choose Starter' })).toBeDisabled();
	await page.getByTestId('licensing-managed-checkout-disclosure').locator('input').check();
	await expect(page.getByRole('button', { name: 'Choose Starter' })).toBeEnabled();

	await expect(page.getByTestId('licensing-deactivate-boundary')).toContainText(
		'does not cancel Stripe billing'
	);
	const deactivateButton = page.getByRole('button', { name: 'Deactivate site license' });
	await expect(deactivateButton).toBeVisible();

	await deactivateButton.click();
	await expect.poll(() => deactivateRequests).toBe(1);
	await expect(deactivateButton).not.toBeVisible();
	await expect(page.getByRole('button', { name: 'Choose Starter' })).toBeEnabled();
	await expect(page.getByRole('button', { name: 'Activate license', exact: true })).toBeVisible();

	await page.getByLabel('License key').fill('LIC-123456789012345678901234');
	await page.getByRole('button', { name: 'Activate license', exact: true }).click();
	await expect.poll(() => activateRequests).toBe(2);
	await expect(page.getByText('Tier: Starter')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Deactivate site license' })).toBeVisible();
});

test('first-time managed checkout starts from the recommended license path with hash-safe return urls', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	const inactiveStatus = {
		status: 'inactive',
		license_key_masked: '',
		proxy_key_present: false,
		tier: null,
		expires_at: null,
		last_synced: null,
		license_id: null,
		site_id: null,
		site_url: 'https://example.test'
	};
	let checkoutRequests = 0;

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: inactiveStatus }),
			headers: { 'content-type': 'application/json' }
		})
	);
	await page.route('**/wp-json/sentient-forms/v1/license/bootstrap', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: inactiveStatus }),
			headers: { 'content-type': 'application/json' }
		})
	);
	await page.route('**/wp-json/sentient-forms/v1/license/managed-checkout/start', (route) => {
		checkoutRequests += 1;
		const body = route.request().postDataJSON() as
			| {
					plan_code?: string;
					billing_interval?: string;
					success_url?: string;
					cancel_url?: string;
					accepted_managed_service_terms?: boolean;
			  }
			| undefined;
		expect(body?.plan_code).toBe('starter');
		expect(body?.billing_interval).toBe('monthly');
		expect(body?.accepted_managed_service_terms).toBe(true);

		for (const returnUrl of [body?.success_url, body?.cancel_url]) {
			expect(returnUrl).toBeTruthy();
			const parsed = new URL(returnUrl ?? '');
			expect(parsed.searchParams.get('sentient_managed_checkout')).toBeNull();
			expect(parsed.hash === '#/licensing' || parsed.pathname.endsWith('/licensing')).toBe(true);
		}

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					checkout_intent_id: 'mci_test_123',
					checkout_session_id: 'cs_test_123',
					checkout_url: `${process.env.PREVIEW_ORIGIN ?? 'http://127.0.0.1:4175'}/licensing?managed-checkout-started=1`,
					status: 'open',
					provider: 'stripe'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing');

	await expect(
		page.getByRole('heading', { name: 'Let Sentient Forms manage model access' })
	).toBeVisible();
	await expect(page.getByTestId('licensing-managed-checkout-disclosure')).toContainText(
		'Recommended: check this before choosing a managed-service plan.'
	);
	await expect(page.getByTestId('licensing-managed-checkout-disclosure')).toContainText(
		'Sentient Forms does not store prompt or response payloads for these runs.'
	);
	await expect(page.getByText(/5 sites|25 active|agency|billed yearly|annual/i)).toHaveCount(0);
	await expect(page.getByRole('button', { name: 'Choose Starter' })).toBeDisabled();
	await page.getByTestId('licensing-managed-checkout-disclosure').locator('input').check();
	await expect(page.getByRole('button', { name: 'Choose Starter' })).toBeEnabled();
	await page.getByRole('button', { name: 'Choose Starter' }).click();

	await expect.poll(() => checkoutRequests).toBe(1);
	await expect(page).toHaveURL(/managed-checkout-started=1/);
});

test('managed checkout return with completed status resumes activation on the licensing route', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	const inactiveStatus = {
		status: 'inactive',
		license_key_masked: '',
		proxy_key_present: false,
		tier: null,
		expires_at: null,
		last_synced: null,
		license_id: null,
		site_id: null,
		site_url: 'https://example.test'
	};
	let completeRequests = 0;

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: inactiveStatus }),
			headers: { 'content-type': 'application/json' }
		})
	);
	await page.route('**/wp-json/sentient-forms/v1/license/bootstrap', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({ success: true, data: inactiveStatus }),
			headers: { 'content-type': 'application/json' }
		})
	);
	await page.route('**/wp-json/sentient-forms/v1/license/managed-checkout/complete', (route) => {
		completeRequests += 1;
		const body = route.request().postDataJSON() as
			| {
					checkout_intent_id?: string;
					checkout_session_id?: string;
					activation_token?: string;
			  }
			| undefined;
		expect(body?.checkout_intent_id).toBe('mci_test_123');
		expect(body?.checkout_session_id).toBe('cs_test_123');
		expect(body?.activation_token).toBe('activation-token');

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					activation_ready: false,
					status: 'pending_webhook',
					checkout_intent_id: 'mci_test_123',
					checkout_session_id: 'cs_test_123'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto(
		'/licensing?sentient_managed_checkout=completed&checkout_intent_id=mci_test_123&stripe_session_id=cs_test_123&activation_token=activation-token'
	);

	await expect.poll(() => completeRequests).toBe(1);
	await expect(
		page.getByText('Stripe checkout succeeded. Sentient Forms is waiting for the billing webhook')
	).toBeVisible();
});

test('managed checkout activation reloads the license before the forced billing refresh', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	const inactiveStatus = {
		status: 'inactive',
		license_key_masked: '',
		proxy_key_present: false,
		tier: null,
		expires_at: null,
		last_synced: null,
		license_id: null,
		site_id: null,
		site_url: 'https://example.test'
	};
	const activeStatus = {
		status: 'active',
		license_key_masked: 'LIC-****-****-****',
		proxy_key_present: true,
		tier: 'starter',
		expires_at: '2030-01-01T00:00:00Z',
		last_synced: '2030-01-01T00:00:00Z',
		license_id: 'lic-managed-checkout-ready',
		site_id: 'site-managed-checkout-ready',
		site_url: 'https://example.test'
	};
	const requestSequence: string[] = [];
	let checkoutCompleted = false;
	let completeRequests = 0;
	let forcedBillingRequests = 0;

	await page.route('**/wp-json/sentient-forms/v1/license', (route) => {
		requestSequence.push(checkoutCompleted ? 'license:active' : 'license:inactive');
		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: checkoutCompleted ? activeStatus : inactiveStatus
			}),
			headers: { 'content-type': 'application/json' }
		});
	});
	await page.route('**/wp-json/sentient-forms/v1/license/bootstrap', (route) => {
		requestSequence.push(checkoutCompleted ? 'bootstrap:active' : 'bootstrap:inactive');
		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: checkoutCompleted ? activeStatus : inactiveStatus
			}),
			headers: { 'content-type': 'application/json' }
		});
	});
	await page.route('**/wp-json/sentient-forms/v1/license/managed-checkout/complete', (route) => {
		completeRequests += 1;
		checkoutCompleted = true;
		requestSequence.push('checkout:complete');
		const body = route.request().postDataJSON() as
			| {
					checkout_intent_id?: string;
					checkout_session_id?: string;
					activation_token?: string;
			  }
			| undefined;
		expect(body?.checkout_intent_id).toBe('mci_test_ready');
		expect(body?.checkout_session_id).toBe('cs_test_ready');
		expect(body?.activation_token).toBe('activation-token-ready');

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					activation_ready: true,
					status: 'active',
					checkout_intent_id: 'mci_test_ready',
					checkout_session_id: 'cs_test_ready'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});
	await page.route(billingStateRoutePattern, (route) => {
		const isForcedRefresh =
			new URL(route.request().url()).searchParams.get('force_refresh') === '1';
		if (isForcedRefresh) {
			forcedBillingRequests += 1;
		}
		requestSequence.push(isForcedRefresh ? 'billing:forced' : 'billing:cached');

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					license_status: 'active',
					tier: {
						code: 'starter',
						display_name: 'Starter',
						site_limit: 1,
						monthly_credit_quota: 1000
					},
					subscription: {
						provider_subscription_id: 'sub_checkout_ready',
						status: 'active',
						quantity: 1,
						cancel_at_period_end: false,
						current_period_start: '2030-01-01T00:00:00Z',
						current_period_end: '2030-02-01T00:00:00Z',
						trial_end: null,
						provider_price_id: 'price_starter'
					},
					credits: {
						current_balance: 1000,
						tier_quota: 1000,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					},
					policy: {
						paid_trial_days: 14,
						free_plan_monthly_credits: 50,
						free_plan_indefinite: true,
						private_beta_trial_enabled: true
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto(
		'/licensing?sentient_managed_checkout=success&checkout_intent_id=mci_test_ready&stripe_session_id=cs_test_ready&activation_token=activation-token-ready'
	);

	await expect.poll(() => completeRequests).toBe(1);
	await expect.poll(() => forcedBillingRequests).toBe(1);
	await expect(page.getByText('Tier: Starter')).toBeVisible();

	const forcedBillingIndex = requestSequence.indexOf('billing:forced');
	expect(forcedBillingIndex).toBeGreaterThan(-1);
	expect(requestSequence.slice(0, forcedBillingIndex)).toContain('license:active');
});

test('licensing screen uses billing-state credits without legacy credit refresh', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					status: 'active',
					license_key_masked: 'LIC-****-****-****',
					proxy_key_present: true,
					tier: 'free',
					expires_at: null,
					last_synced: '2030-01-01T00:00:00Z',
					license_id: 'lic-race',
					site_id: 'site-race',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});

	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					license_status: 'trial',
					tier: {
						code: 'starter',
						display_name: 'Starter',
						site_limit: 1,
						monthly_credit_quota: 1000
					},
					subscription: {
						provider_subscription_id: 'sub_trial_123',
						status: 'trialing',
						quantity: 1,
						cancel_at_period_end: false,
						trial_end: '2030-01-15T00:00:00Z',
						provider_price_id: 'price_starter'
					},
					credits: {
						current_balance: 1000,
						tier_quota: 1000,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					},
					policy: {
						paid_trial_days: 14,
						free_plan_monthly_credits: 50,
						free_plan_indefinite: true,
						private_beta_trial_enabled: true
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });

	await expect(page.getByRole('heading', { name: 'Managed service' })).toBeVisible();
	await expect(
		page.getByRole('button', { name: 'Activate license', exact: true })
	).not.toBeVisible();
	await expect(page.getByText('Tier: Starter')).toBeVisible();
	await expect(page.getByTestId('licensing-credits-headline')).toContainText(
		'1000 / 1000 managed action credits remaining'
	);
	await expect(page.getByTestId('licensing-trial-status-note')).toContainText(
		'Legacy introductory period ends'
	);
});

test('licensing screen explains the v2 managed billing boundary', async ({ page }) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					status: 'active',
					license_key_masked: 'LIC-****-****-****',
					proxy_key_present: true,
					tier: 'free',
					expires_at: null,
					last_synced: '2030-01-01T00:00:00Z',
					license_id: 'lic-v2-boundary',
					site_id: 'site-v2-boundary',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});

	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					service: 'sentient-managed',
					status: 'active',
					site_id: 'site-v2-boundary',
					license_id: 'lic-v2-boundary',
					plan: {
						code: 'pro',
						display_name: 'Pro',
						site_limit: 1,
						monthly_credit_quota: 3000
					},
					account: {
						license_status: 'active',
						tier: {
							code: 'pro',
							display_name: 'Pro',
							site_limit: 1,
							monthly_credit_quota: 3000
						}
					},
					billing: {
						provider: 'stripe',
						customer_id: 'cus_v2_boundary',
						managed_enabled: true,
						subscription: {
							provider_subscription_id: 'sub_v2_boundary',
							status: 'active',
							quantity: 2,
							cancel_at_period_end: false,
							current_period_start: '2030-01-01T00:00:00Z',
							current_period_end: '2030-02-01T00:00:00Z',
							trial_end: null,
							provider_price_id: 'price_v2_pro'
						}
					},
					allocation: {
						seat_quantity: 2,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					},
					managed_usage: {
						execution_count: 8,
						succeeded_count: 7,
						failed_count: 1,
						token_usage: {
							input_tokens: 1234,
							output_tokens: 567,
							total_tokens: 1801
						},
						billing: {
							billed_amount_microusd: 12500,
							currency: 'USD'
						}
					},
					billing_boundary: {
						direct_openrouter_billed_by_sentient: false,
						managed_proxy_billed_by_sentient: true
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });

	await expect(page.getByText('Tier: Pro')).toBeVisible();
	await expect(page.getByTestId('licensing-credits-headline')).toContainText(
		'3,000 monthly managed action credits included'
	);
	await expect(page.getByText('Subscription status: active')).toBeVisible();
	await expect(page.getByText('Licensed WordPress site: 1 / 1')).toBeVisible();

	const boundary = page.getByTestId('licensing-billing-boundary');
	await expect(boundary).toContainText('Direct OpenRouter');
	await expect(boundary).toContainText('External billing');
	await expect(boundary).toContainText('Sentient Forms managed service');
	await expect(boundary).toContainText('Sentient Forms billed');
	await expect(page.getByTestId('licensing-managed-usage-summary')).toContainText(
		'8 managed runs, 7 succeeded, 1 failed'
	);
	await expect(page.getByTestId('licensing-managed-usage-summary')).toContainText(
		'Detailed provider token counts stay in internal diagnostics; this screen shows managed action credit usage.'
	);
	await expect(page.getByTestId('licensing-managed-usage-summary')).not.toContainText('$0.01');
	await expect(page.getByTestId('licensing-managed-usage-summary')).not.toContainText('USD');
});

test('starter and pro subscriptions do not expose purchasable top-ups', async ({ page }) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					status: 'active',
					license_key_masked: 'LIC-****-****-****',
					proxy_key_present: true,
					tier: 'pro',
					expires_at: null,
					last_synced: '2030-01-01T00:00:00Z',
					license_id: 'lic-pro-no-top-up',
					site_id: 'site-pro-no-top-up',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});
	await page.route('**/wp-json/sentient-forms/v1/license/billing/top-up-session', (route) => {
		throw new Error(`Starter/Pro top-up checkout was called: ${route.request().url()}`);
	});
	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					license_status: 'active',
					tier: {
						code: 'pro',
						display_name: 'Pro',
						site_limit: 1,
						monthly_credit_quota: 3000
					},
					subscription: {
						provider_subscription_id: 'sub_pro_no_top_up',
						status: 'active',
						quantity: 1,
						cancel_at_period_end: false,
						current_period_start: '2030-01-01T00:00:00Z',
						current_period_end: '2030-02-01T00:00:00Z',
						trial_end: null,
						provider_price_id: 'price_test_pro'
					},
					credits: {
						current_balance: 2800,
						tier_quota: 3000,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });

	const topUpNote = page.getByTestId('licensing-managed-top-up-note');
	await expect(topUpNote).toContainText(
		'Starter and Pro include monthly managed action credits without purchasable top-ups.'
	);
	await expect(topUpNote).toContainText('Business includes access to capacity packs.');
	await expect(page.getByRole('button', { name: 'Add capacity' })).toHaveCount(0);
});

test('business subscriptions expose canonical top-up packs and send pack code', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	let topUpRequests = 0;

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					status: 'active',
					license_key_masked: 'LIC-****-****-****',
					proxy_key_present: true,
					tier: 'business',
					expires_at: null,
					last_synced: '2030-01-01T00:00:00Z',
					license_id: 'lic-business-top-up',
					site_id: 'site-business-top-up',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});
	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					license_status: 'active',
					tier: {
						code: 'business',
						display_name: 'Business',
						site_limit: 1,
						monthly_credit_quota: 10000
					},
					subscription: {
						provider_subscription_id: 'sub_business_top_up',
						status: 'active',
						quantity: 1,
						cancel_at_period_end: false,
						current_period_start: '2030-01-01T00:00:00Z',
						current_period_end: '2030-02-01T00:00:00Z',
						trial_end: null,
						provider_price_id: 'price_test_business'
					},
					credits: {
						current_balance: 9200,
						tier_quota: 10000,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);
	await page.route('**/wp-json/sentient-forms/v1/license/billing/top-up-session', (route) => {
		topUpRequests += 1;
		const body = route.request().postDataJSON() as
			| { pack_code?: string; success_url?: string; cancel_url?: string; quantity?: number }
			| undefined;
		expect(body?.pack_code).toBe('top_up_medium');
		expect(body?.quantity).toBe(1);
		expect(typeof body?.success_url).toBe('string');
		expect(typeof body?.cancel_url).toBe('string');

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					session_id: 'cs_business_top_up',
					checkout_url: 'about:blank#business-top-up',
					customer_id: 'cus_business_top_up',
					top_up_credits: 5000,
					pack_code: 'top_up_medium'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });

	const topUpNote = page.getByTestId('licensing-managed-top-up-note');
	await expect(topUpNote).toContainText('Top-up credits are available only on Business');
	await expect(topUpNote).toContainText('$20 / 1,000 credits');
	await expect(topUpNote).toContainText('$85 / 5,000 credits');
	await expect(topUpNote).toContainText('$150 / 10,000 credits');

	const addCapacityButtons = page.getByRole('button', { name: 'Add capacity' });
	await expect(addCapacityButtons).toHaveCount(3);
	await addCapacityButtons.nth(1).click();
	await expect.poll(() => topUpRequests).toBe(1);
	await expect(page).toHaveURL(/about:blank#business-top-up/);
});

test('existing subscriptions use subscription update portal for plan changes', async ({ page }) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	let portalAttempts = 0;

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					status: 'trial',
					license_key_masked: 'LIC-****-****-****',
					proxy_key_present: true,
					tier: 'starter',
					expires_at: '2030-01-15T00:00:00Z',
					last_synced: '2030-01-01T00:00:00Z',
					license_id: 'lic-trial-next-cycle',
					site_id: 'site-trial-next-cycle',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});

	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					license_status: 'trial',
					tier: {
						code: 'starter',
						display_name: 'Starter',
						site_limit: 1,
						monthly_credit_quota: 1000
					},
					subscription: {
						provider_subscription_id: 'sub_trial_next_cycle_123',
						status: 'trialing',
						quantity: 1,
						cancel_at_period_end: false,
						current_period_start: '2030-01-01T00:00:00Z',
						current_period_end: null,
						trial_end: '2030-01-15T00:00:00Z',
						provider_price_id: 'price_test_starter'
					},
					credits: {
						current_balance: 1200,
						tier_quota: 1000,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					},
					policy: {
						paid_trial_days: 14,
						free_plan_monthly_credits: 50,
						free_plan_indefinite: true,
						private_beta_trial_enabled: true
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/license/billing/subscription-change', (route) => {
		throw new Error(`Obsolete subscription-change route was called: ${route.request().url()}`);
	});

	await page.route('**/wp-json/sentient-forms/v1/license/billing/portal-session', (route) => {
		portalAttempts += 1;
		const body = route.request().postDataJSON() as
			| { flow_type?: string; subscription_id?: string; return_url?: string }
			| undefined;
		expect(body?.flow_type).toBe('subscription_update');
		expect(body?.subscription_id).toBe('sub_trial_next_cycle_123');
		expect(typeof body?.return_url).toBe('string');
		const returnUrl = new URL(body?.return_url ?? '');
		expect(returnUrl.origin).toBe(process.env.PREVIEW_ORIGIN ?? 'http://127.0.0.1:4175');
		expect(returnUrl.hash === '#/licensing' || returnUrl.pathname.endsWith('/licensing')).toBe(
			true
		);

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					session_id: 'bps_plan_management_123',
					portal_url: 'about:blank#stripe-plan-management',
					customer_id: 'cus_test_123'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });
	await expect(page.getByTestId('licensing-trial-status-note')).toContainText(
		'Legacy introductory period ends'
	);
	await expect(page.getByTestId('licensing-managed-portal-note')).toContainText(
		'Use the Stripe billing portal'
	);

	await page.getByRole('button', { name: 'Upgrade to Pro' }).click();

	await expect.poll(() => portalAttempts).toBe(1);
	await expect(page).toHaveURL(/about:blank#stripe-plan-management/);
});

test('larger subscriptions use the same subscription update portal for downgrades', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	let portalAttempts = 0;

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					status: 'active',
					license_key_masked: 'LIC-****-****-****',
					proxy_key_present: true,
					tier: 'pro',
					expires_at: '2030-02-01T00:00:00Z',
					last_synced: '2030-01-01T00:00:00Z',
					license_id: 'lic-pro-downgrade',
					site_id: 'site-pro-downgrade',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});

	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					license_status: 'active',
					tier: {
						code: 'pro',
						display_name: 'Pro',
						site_limit: 1,
						monthly_credit_quota: 3000
					},
					subscription: {
						provider_subscription_id: 'sub_pro_downgrade_123',
						status: 'active',
						quantity: 1,
						cancel_at_period_end: false,
						current_period_start: '2030-01-01T00:00:00Z',
						current_period_end: '2030-02-01T00:00:00Z',
						trial_end: null,
						provider_price_id: 'price_test_pro'
					},
					credits: {
						current_balance: 2800,
						tier_quota: 3000,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					},
					policy: {
						paid_trial_days: 14,
						free_plan_monthly_credits: 50,
						free_plan_indefinite: true,
						private_beta_trial_enabled: true
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/license/billing/subscription-change', (route) => {
		throw new Error(`Obsolete subscription-change route was called: ${route.request().url()}`);
	});

	await page.route('**/wp-json/sentient-forms/v1/license/billing/portal-session', (route) => {
		portalAttempts += 1;
		const body = route.request().postDataJSON() as
			| { flow_type?: string; subscription_id?: string; return_url?: string }
			| undefined;
		expect(body?.flow_type).toBe('subscription_update');
		expect(body?.subscription_id).toBe('sub_pro_downgrade_123');
		const returnUrl = new URL(body?.return_url ?? '');
		expect(returnUrl.origin).toBe(process.env.PREVIEW_ORIGIN ?? 'http://127.0.0.1:4175');

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					session_id: 'bps_plan_downgrade_123',
					portal_url: 'about:blank#stripe-plan-downgrade',
					customer_id: 'cus_test_123'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });
	await page.getByRole('button', { name: 'Downgrade to Starter' }).click();

	await expect.poll(() => portalAttempts).toBe(1);
	await expect(page).toHaveURL(/about:blank#stripe-plan-downgrade/);
});

test('licensing billing error state maps portal failures to actionable copy', async ({ page }) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	let portalAttempts = 0;

	await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					status: 'active',
					license_key_masked: 'LIC-****-****-****',
					proxy_key_present: true,
					tier: 'starter',
					expires_at: '2030-01-01T00:00:00Z',
					last_synced: '2030-01-01T00:00:00Z',
					license_id: 'lic-portal',
					site_id: 'site-portal',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
		throw new Error(`Legacy credit-balance route was called: ${route.request().url()}`);
	});

	await page.route(billingStateRoutePattern, (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider: 'stripe',
					customer_id: 'cus_test_123',
					subscription: {
						provider_subscription_id: 'sub_test_123',
						status: 'active',
						quantity: 1,
						cancel_at_period_end: false,
						current_period_start: '2030-01-01T00:00:00Z',
						current_period_end: '2030-02-01T00:00:00Z',
						trial_end: null,
						provider_price_id: 'price_test_starter'
					},
					credits: {
						current_balance: 1000,
						tier_quota: 1000,
						ledger_delta: 0,
						top_up_available: 0
					},
					allocation: {
						seat_quantity: 1,
						tier_site_limit: 1,
						allowed_sites: 1,
						active_sites: 1,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_allowance_v2'
					},
					policy: {
						paid_trial_days: 14,
						free_plan_monthly_credits: 50,
						free_plan_indefinite: true,
						private_beta_trial_enabled: true
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/license/billing/portal-session', (route) => {
		portalAttempts += 1;
		const body = route.request().postDataJSON() as
			| { flow_type?: string; subscription_id?: string; return_url?: string }
			| undefined;
		expect(body?.flow_type).toBe('home');
		expect(body?.subscription_id).toBeUndefined();
		return route.fulfill({
			status: 403,
			body: JSON.stringify({
				error_code: 'billing_payment_blocked',
				message: 'Billing is blocked for this customer due to prior chargeback activity.'
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });

	await page.getByRole('button', { name: 'Manage billing' }).click();
	const errorState = page.getByTestId('licensing-billing-error-state');
	await expect(errorState).toBeVisible();
	await expect(errorState).toContainText('Billing portal unavailable');
	await expect(errorState).toContainText('repeated chargeback activity');

	const retryButton = page.getByRole('button', { name: 'Retry opening billing portal' });
	await expect(retryButton).toBeVisible();
	await retryButton.click();
	await expect.poll(() => portalAttempts).toBe(2);
});
