import { test, expect } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';

test.describe('Custom actions admin view', () => {
	test.beforeEach(async ({ page }) => {
		const wpHost = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
		await seedRuntimeConfig(page, { apiBaseUrl: `${wpHost}/wp-json/sentient-forms/v1/` });
	});

	test('lists, creates, archives, and reactivates custom actions', async ({ page }) => {
		let actions = [
			{
				id: 'action-alpha',
				template_id: 'tmpl-alpha',
				code: 'alpha',
				display_name: 'Alpha action',
				description: null,
				prompt_overrides: {},
				model_hint: null,
				base_credit_cost: 10,
				status: 'active',
				archived_at: null,
				created_at: '2025-11-10T00:00:00Z',
				updated_at: '2025-11-14T12:00:00Z'
			}
		];

		const quotaMax = 5;

		function quotaSummary() {
			const activeCount = actions.filter((action) => action.status === 'active').length;
			return {
				quota_max: quotaMax,
				quota_used: activeCount,
				quota_remaining: Math.max(0, quotaMax - activeCount)
			};
		}

		await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
			const url = route.request().url();
			const method = route.request().method();

			if (method === 'GET' && url.includes('/meta/capabilities')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							supports_custom_actions: true,
							cps_version: '1.2.0'
						}
					})
				});
			}

			if (method === 'GET' && url.includes('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify([
						{
							id: 'spam_detection_v1',
							code: 'spam_detection_v1',
							name: 'Spam Detection',
							description: 'Detects spam submissions',
							form_sources: ['gravity_forms']
						}
					])
				});
			}

			if (url.includes('/custom-actions')) {
				const reactivateMatch = url.match(/custom-actions\/([^/?]+)\/reactivate$/);
				const actionMatch = url.match(/custom-actions\/([^/?]+)$/);

				if (method === 'GET') {
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							actions,
							quota: quotaSummary()
						})
					});
				}

				if (method === 'POST' && !reactivateMatch) {
					const payload = (route.request().postDataJSON() as Record<string, unknown>) ?? {};
					const now = new Date().toISOString();
					const newAction = {
						id: `action-${Date.now()}`,
						template_id: String(payload.template_id ?? ''),
						code: String(payload.code ?? ''),
						display_name: String(payload.display_name ?? ''),
						description: (payload.description as string | null | undefined) ?? null,
						prompt_overrides: (payload.prompt_overrides as Record<string, unknown> | undefined) ?? {},
						model_hint: (payload.model_hint as string | null | undefined) ?? null,
						base_credit_cost: null,
						status: 'active',
						archived_at: null,
						created_at: now,
						updated_at: now
					};
					actions = [newAction, ...actions];

					return route.fulfill({
						status: 201,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							action: newAction,
							quota: quotaSummary()
						})
					});
				}

				if (method === 'DELETE' && actionMatch) {
					const id = actionMatch[1];
					actions = actions.map((action) =>
						action.id === id
							? {
								...action,
								status: 'archived',
								archived_at: new Date().toISOString(),
								updated_at: new Date().toISOString()
							  }
							: action
					);

					const archived = actions.find((action) => action.id === id)!;
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							action: archived,
							quota: quotaSummary()
						})
					});
				}

				if (method === 'POST' && reactivateMatch) {
					const id = reactivateMatch[1];
					actions = actions.map((action) =>
						action.id === id
							? {
								...action,
								status: 'active',
								archived_at: null,
								updated_at: new Date().toISOString()
							  }
							: action
					);

					const reactivated = actions.find((action) => action.id === id)!;
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({
							action: reactivated,
							quota: quotaSummary()
						})
					});
				}
			}

			return route.continue();
		});

		await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });
		await page.waitForFunction(() => document.body.textContent?.includes('Custom Actions'));
		await expect(page.getByRole('heading', { name: 'Custom Actions' })).toBeVisible();

		let tableRows = page.getByTestId('custom-actions-table').locator('tbody tr');
		await expect(tableRows).toHaveCount(1);

		await page.getByRole('button', { name: /Create Action/i }).click();
		await expect(page).toHaveURL(/#\/actions\/custom\/new$/);

		const createForm = page.getByTestId('custom-action-form');
		await createForm
			.getByLabel('Template ID')
			.fill('11111111-1111-4111-8111-111111111111');
		await createForm.getByLabel('Display Name').fill('Beta action');
		await createForm.getByLabel('Code').fill('beta');
		await createForm.getByRole('button', { name: 'Create Action' }).click();

		await expect(page).toHaveURL(/#\/actions\/custom$/);
		tableRows = page.getByTestId('custom-actions-table').locator('tbody tr');

		await expect(tableRows).toHaveCount(2);

		const firstRow = tableRows.first();
		await firstRow.getByRole('button', { name: 'Archive' }).click();
		await expect(firstRow.getByText('archived')).toBeVisible();

		await firstRow.getByRole('button', { name: 'Reactivate' }).click();
		await expect(firstRow.getByText('active')).toBeVisible();
	});

	test('renders without crashing when custom-action timestamps are invalid', async ({ page }) => {
		const actions = [
			{
				id: 'valid-new',
				template_id: 'tmpl-valid',
				code: 'valid-new',
				display_name: 'Valid New',
				description: null,
				prompt_overrides: {},
				model_hint: null,
				base_credit_cost: 10,
				status: 'active',
				archived_at: null,
				created_at: '2026-02-23T12:00:00Z',
				updated_at: '2026-02-23T12:00:00Z',
				action_kind: 'template_override',
				definition: null,
				definition_version: 1,
				output_contract: null,
				supported_execution_modes: ['after_submission']
			},
			{
				id: 'invalid-time',
				template_id: 'tmpl-invalid',
				code: 'invalid-time',
				display_name: 'Invalid Time',
				description: null,
				prompt_overrides: {},
				model_hint: null,
				base_credit_cost: 10,
				status: 'active',
				archived_at: null,
				created_at: '2026-02-23T11:00:00Z',
				updated_at: 'Array',
				action_kind: 'template_override',
				definition: null,
				definition_version: 1,
				output_contract: null,
				supported_execution_modes: ['after_submission']
			}
		];

		const pageErrors: string[] = [];
		page.on('pageerror', (error) => pageErrors.push(error.message));

		await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
			const url = route.request().url();
			const method = route.request().method();

			if (method === 'GET' && url.includes('/meta/capabilities')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							supports_custom_actions: true,
							cps_version: '1.2.0'
						}
					})
				});
			}

			if (method === 'GET' && url.includes('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify([])
				});
			}

			if (method === 'GET' && url.includes('/custom-actions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						actions,
						quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
					})
				});
			}

			return route.continue();
		});

		await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });
		await page.waitForFunction(() => document.body.textContent?.includes('Custom Actions'));

		await expect(page.getByRole('heading', { name: 'Custom Actions' })).toBeVisible();
		await expect(page.getByText('This view hit an error')).toHaveCount(0);
		expect(pageErrors).toEqual([]);

		const rows = page.getByTestId('custom-actions-table').locator('tbody tr');
		await expect(rows).toHaveCount(2);
		await expect(rows.nth(0)).toContainText('valid-new');

		const invalidRow = rows.filter({ hasText: 'invalid-time' });
		await expect(invalidRow.locator('td').nth(5)).toContainText('—');
	});

	test('shows shared empty state template when no custom actions exist', async ({ page }) => {
		await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
			const url = route.request().url();
			const method = route.request().method();

			if (method === 'GET' && url.includes('/meta/capabilities')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							supports_custom_actions: true,
							cps_version: '1.2.0'
						}
					})
				});
			}

			if (method === 'GET' && url.includes('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify([])
				});
			}

			if (method === 'GET' && url.includes('/custom-actions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						actions: [],
						quota: { quota_max: 5, quota_used: 0, quota_remaining: 5 }
					})
				});
			}

			return route.continue();
		});

		await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Custom Actions' })).toBeVisible();
		await expect(page.getByTestId('custom-actions-empty-state')).toBeVisible();
		await expect(page.getByRole('button', { name: /create your first action/i })).toBeVisible();
	});
});
