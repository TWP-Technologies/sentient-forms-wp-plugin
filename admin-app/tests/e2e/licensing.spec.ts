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
	let creditBalanceRequests: string[] = [];

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
		creditBalanceRequests.push(route.request().url());
		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					current_balance: 50,
					ledger_delta: 0,
					tier: {
						code: 'free',
						display_name: 'Free',
						monthly_credit_quota: 50
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
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
		expect(body?.trial_period_days).toBe(14);

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

	await expect(page.getByRole('heading', { name: 'License activation' })).toBeVisible();
	await expect(page.getByText('Proxy key stored')).toBeVisible();

	await page.getByLabel('License key').fill('LIC-123456789012345678901234');
	await page.getByRole('button', { name: 'Activate', exact: true }).click();

	await expect(page.getByText('Tier: starter')).toBeVisible();
	await expect(page.getByRole('button', { name: 'Activate', exact: true })).not.toBeVisible();
	await expect(page.getByTestId('licensing-trial-policy-note')).toContainText(
		'one-time 14-day paid-plan trial'
	);
	await expect(page.getByTestId('licensing-trial-policy-note')).toContainText(
		'Free plan remains available indefinitely with 50 monthly credits'
	);
	await expect(page.getByTestId('licensing-trial-policy-note')).toContainText(
		'Private Beta sites can remain on Private Beta'
	);
	await expect(page.getByTestId('licensing-business-cap-note')).toContainText(
		'up to 200 sites during launch'
	);
	await page.getByRole('button', { name: 'Choose Starter' }).click();
	await expect.poll(() => checkoutRequests).toBe(1);
	expect(creditBalanceRequests.some((url) => url.includes('force_refresh=1'))).toBe(true);
	await expect(page).toHaveURL(/checkout=starter/);

	const deactivateButton = page.getByRole('button', { name: 'Deactivate license' });
	await expect(deactivateButton).toBeVisible();

	await deactivateButton.click();
	await expect(deactivateButton).not.toBeVisible();
});

test('licensing screen prefers billing-state tier and quota when credit refresh lags', async ({
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

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					current_balance: 1500,
					ledger_delta: 0,
					tier: {
						code: 'free',
						display_name: 'Free',
						monthly_credit_quota: 50
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

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

	await expect(page.getByRole('heading', { name: 'License management' })).toBeVisible();
	await expect(page.getByRole('button', { name: 'Activate', exact: true })).not.toBeVisible();
	await expect(page.getByText('Tier: Starter')).toBeVisible();
	await expect(page.getByTestId('licensing-credits-headline')).toContainText(
		'1500 / 1500 credits remaining'
	);
	await expect(page.getByTestId('licensing-trial-status-note')).toContainText(
		'One-time 14-day paid-plan trial.'
	);
});

test('start-next-cycle plan changes stay available while a subscription is trialing', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	let subscriptionChangeAttempts = 0;
	let capturedRecoveryReturnUrl: string | null = null;

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

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					current_balance: 1200,
					ledger_delta: 0,
					tier: {
						code: 'starter',
						display_name: 'Starter',
						monthly_credit_quota: 1500
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

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
		subscriptionChangeAttempts += 1;
		const body = route.request().postDataJSON() as
			| {
					plan_code?: string;
					change_timing?: string;
					recovery_return_url?: string;
			  }
			| undefined;
		expect(body?.plan_code).toBe('business');
		expect(body?.change_timing).toBe('start_next_cycle');
		expect(typeof body?.recovery_return_url).toBe('string');
		capturedRecoveryReturnUrl = body?.recovery_return_url ?? null;

		return route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					provider_subscription_id: 'sub_trial_next_cycle_123',
					provider_price_id: 'price_test_business',
					plan_code: 'business',
					change_timing: 'start_next_cycle',
					effective_at: '2030-01-15T00:00:00Z',
					renewal_grant_applied: false,
					carryover_grant_applied: false,
					carryover_credits_granted: 0
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });
	await expect(page.getByTestId('licensing-trial-status-note')).toContainText(
		'Trial active until'
	);

	await page.getByRole('button', { name: 'Start next cycle' }).click();
	await page.getByRole('button', { name: 'Switch to Business' }).click();

	await expect.poll(() => subscriptionChangeAttempts).toBe(1);
	await expect.poll(() => capturedRecoveryReturnUrl !== null).toBe(true);
	await expect(page).toHaveURL(/\/licensing(?:$|[#?])/);
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

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					current_balance: 1500,
					ledger_delta: 0,
					tier: {
						code: 'starter',
						display_name: 'Starter',
						monthly_credit_quota: 1500
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

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
		expect(body?.flow_type).toBe('subscription_update');
		expect(body?.subscription_id).toBe('sub_test_123');
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

test('start-now plan changes redirect to Stripe recovery portal when authentication is required', async ({
	page
}) => {
	const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
	await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });

	let subscriptionChangeAttempts = 0;
	let capturedRecoveryReturnUrl: string | null = null;

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
					license_id: 'lic-sca',
					site_id: 'site-sca',
					site_url: 'https://example.test'
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

	await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) =>
		route.fulfill({
			status: 200,
			body: JSON.stringify({
				success: true,
				data: {
					current_balance: 1200,
					ledger_delta: 0,
					tier: {
						code: 'starter',
						display_name: 'Starter',
						monthly_credit_quota: 1500
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		})
	);

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
		subscriptionChangeAttempts += 1;
		const body = route.request().postDataJSON() as
			| {
					plan_code?: string;
					change_timing?: string;
					recovery_return_url?: string;
			  }
			| undefined;
		expect(body?.plan_code).toBe('business');
		expect(body?.change_timing).toBe('start_now');
		expect(typeof body?.recovery_return_url).toBe('string');
		capturedRecoveryReturnUrl = body?.recovery_return_url ?? null;

		return route.fulfill({
			status: 409,
			body: JSON.stringify({
				success: false,
				error: {
					code: 'subscription_payment_action_required',
					message:
						'Immediate plan change requires payment authentication. Open billing to complete authentication and retry.',
					meta: {
						portal_recovery: {
							session_id: 'bps_recovery_123',
							portal_url: 'about:blank#stripe-recovery',
							customer_id: 'cus_test_123'
						}
					}
				}
			}),
			headers: { 'content-type': 'application/json' }
		});
	});

	await page.goto('/#/licensing', { waitUntil: 'networkidle' });
	await page.getByRole('button', { name: 'Start now' }).click();
	await page.getByRole('button', { name: 'Switch to Business' }).click();

	await expect.poll(() => subscriptionChangeAttempts).toBe(1);
	await expect.poll(() => capturedRecoveryReturnUrl !== null).toBe(true);
	await expect(page).toHaveURL(/about:blank#stripe-recovery/);
});
