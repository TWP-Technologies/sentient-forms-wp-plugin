import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';

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
	let checkoutRequests = 0;

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

	await page.route('**/wp-json/sentient-forms/v1/license/billing-state', (route) =>
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
						capacity_policy: 'tier_x_quantity_v1'
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

	await page.route('**/wp-json/sentient-forms/v1/license/billing/checkout-session', (route) => {
		checkoutRequests += 1;
		const body = route.request().postDataJSON() as
			| {
					plan_code?: string;
					trial_period_days?: number;
			  }
			| undefined;
		expect(body?.plan_code).toBe('starter');
		expect(body?.trial_period_days).toBeUndefined();

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					session_id: 'cs_checkout_123',
					checkout_url: `${process.env.PREVIEW_ORIGIN ?? 'http://127.0.0.1:4175'}/#/licensing?checkout=starter`,
					customer_id: 'cus_checkout_123',
					subscription_id: 'sub_checkout_123'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
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
		'Each Sentient Forms managed-service license covers one WordPress site'
	);
	await page.getByRole('button', { name: 'Choose Starter' }).click();
	await expect.poll(() => checkoutRequests).toBe(1);
	await expect(page).toHaveURL(/checkout=starter/);

	const deactivateButton = page.getByRole('button', { name: 'Deactivate license' });
	await expect(deactivateButton).toBeVisible();

	await deactivateButton.click();
	await expect(deactivateButton).not.toBeVisible();
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
					success_url?: string;
					cancel_url?: string;
					accepted_managed_service_terms?: boolean;
			  }
			| undefined;
		expect(body?.plan_code).toBe('starter');
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
					checkout_url: `${process.env.PREVIEW_ORIGIN ?? 'http://127.0.0.1:4175'}/#/licensing?managed-checkout-started=1`,
					status: 'open',
					provider: 'stripe'
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing');

	await expect(page.getByRole('heading', { name: 'Let Sentient Forms manage model access' })).toBeVisible();
	await page.getByTestId('licensing-managed-checkout-disclosure').locator('input').check();
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

	await page.route('**/wp-json/sentient-forms/v1/license/billing-state', (route) =>
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
						monthly_credit_quota: 1500
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
						current_balance: 1500,
						tier_quota: 1500,
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
						capacity_policy: 'tier_x_quantity_v1'
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
		'1500 / 1500 managed credits remaining'
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

	await page.route('**/wp-json/sentient-forms/v1/license/billing-state', (route) =>
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
						site_limit: 5,
						monthly_credit_quota: 4000
					},
					account: {
						license_status: 'active',
						tier: {
							code: 'pro',
							display_name: 'Pro',
							site_limit: 5,
							monthly_credit_quota: 4000
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
						tier_site_limit: 5,
						allowed_sites: 10,
						active_sites: 3,
						over_limit: false,
						blocked_new_activations: false,
						grace_expires_at: null,
						capacity_policy: 'tier_x_quantity_v1'
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
		'4,000 monthly managed credits included'
	);
	await expect(page.getByText('Subscription status: active')).toBeVisible();
	await expect(page.getByText('Licensed WordPress site: 3 / 10')).toBeVisible();

	const boundary = page.getByTestId('licensing-billing-boundary');
	await expect(boundary).toContainText('Direct OpenRouter');
	await expect(boundary).toContainText('External billing');
	await expect(boundary).toContainText('Sentient Forms managed service');
	await expect(boundary).toContainText('Sentient Forms billed');
	await expect(page.getByTestId('licensing-managed-usage-summary')).toContainText(
		'8 managed runs, 7 succeeded, 1 failed'
	);
	await expect(page.getByTestId('licensing-managed-usage-summary')).toContainText('$0.01 billed');
});

test('existing subscriptions use billing portal for plan management', async ({ page }) => {
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

	await page.route('**/wp-json/sentient-forms/v1/license/billing-state', (route) =>
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
						monthly_credit_quota: 1500
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
						tier_quota: 1500,
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
						capacity_policy: 'tier_x_quantity_v1'
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
		expect(body?.flow_type).toBe('home');
		expect(body?.subscription_id).toBeUndefined();
		expect(typeof body?.return_url).toBe('string');

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

	await page.getByRole('button', { name: 'Manage in billing portal' }).first().click();

	await expect.poll(() => portalAttempts).toBe(1);
	await expect(page).toHaveURL(/about:blank#stripe-plan-management/);
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

	await page.route('**/wp-json/sentient-forms/v1/license/billing-state', (route) =>
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
						current_balance: 1500,
						tier_quota: 1500,
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
						capacity_policy: 'tier_x_quantity_v1'
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
