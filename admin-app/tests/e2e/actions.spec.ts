import { test, expect } from '@playwright/test';

test.describe('Actions configuration', () => {
	test('manages action mappings and credit balance', async ({ page }) => {
		let actions = [
			{
				local_mapping_id: 'map_existing',
				central_action_id: 'spam_detection_v1',
				action_name_label: 'Spam detection',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				execution_priority: 10
			}
		];

		const creditEnvelope = {
			success: true,
			data: {
				current_balance: 1234,
				ledger_delta: -10,
				tier: {
					code: 'starter',
					display_name: 'Starter',
					site_limit: 3,
					monthly_credit_quota: 1000
				}
			}
		};

		const definitions = [
			{
				id: 'spam_detection_v1',
				label: 'Spam detection',
				hooks: ['gform_validation'],
				source: 'cps',
				baseCreditCost: 5,
				modelHint: 'models/gemini-1.5-flash',
				templateId: 'template-spam'
			},
			{
				id: 'summary_v1',
				label: 'Entry summary',
				hooks: ['gform_after_submission'],
				source: 'cps',
				baseCreditCost: 8,
				modelHint: 'models/gemini-1.5-pro',
				templateId: 'template-summary'
			}
		];

		let entryStatusHits = 0;

		await page.context().route('**/wp-json/sentient-forms/v1/**', (route) => {
			const url = route.request().url();
			const method = route.request().method();

			if (url.endsWith('/credits/balance')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(creditEnvelope)
				});
			}

			if (url.endsWith('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(definitions)
				});
			}

			if (url.endsWith('/actions/status')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						status: 'success',
						message: 'Last run succeeded',
						entry_id: 41,
						updated_at: '2030-01-01T00:00:00Z'
					})
				});
			}

			if (/\/actions\/entries\/\d+\/status$/.test(url)) {
				entryStatusHits += 1;
				const entryId = Number.parseInt(url.substring(url.lastIndexOf('/') + 1), 10);

				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						entry_id: entryId,
						form_id: 1,
						status: 'error',
						last_error: 'Sentient Forms could not run: insufficient credits.',
						processed_at: '2030-01-01T00:00:00Z'
					})
				});
			}

			if (url.endsWith('/actions')) {
				if (method === 'GET') {
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify(actions)
					});
				}

				if (method === 'POST') {
					const payload = (route.request().postDataJSON() as Record<string, unknown> | null) ?? {};
					const newAction = {
						local_mapping_id: `map_${Date.now()}`,
						central_action_id: String(payload.central_action_id ?? ''),
						action_type_indicator: payload.action_type_indicator ?? 'master',
						trigger_hooks: (payload.trigger_hooks as string[]) ?? [],
						is_action_enabled_for_form: payload.is_action_enabled_for_form ?? true,
						execution_priority: payload.execution_priority ?? 10,
						action_name_label:
							definitions.find((definition) => definition.id === payload.central_action_id)?.label ?? null
					};

					actions = [...actions, newAction];

					return route.fulfill({
						status: 201,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify(newAction)
					});
				}
			}

			const actionItemMatch = url.match(/\/actions\/([^/]+)$/);
			if (actionItemMatch) {
				const mappingId = actionItemMatch[1];
				if (method === 'PUT') {
					const payload = (route.request().postDataJSON() as Record<string, unknown> | null) ?? {};
					actions = actions.map((action) =>
						action.local_mapping_id === mappingId
							? {
									...action,
									...payload
							  }
							: action
					);

					const updated = actions.find((action) => action.local_mapping_id === mappingId);

					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify(updated)
					});
				}

				if (method === 'DELETE') {
					actions = actions.filter((action) => action.local_mapping_id !== mappingId);
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify({ deleted: true })
					});
				}
			}

			return route.continue();
		});

		try {
			await page.goto('/actions/gravity_forms/1', { waitUntil: 'networkidle' });

			await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
			const definitionsCard = page.getByTestId('action-definitions-card');
			await expect(definitionsCard).toBeVisible();
			await expect(definitionsCard.getByText('CPS templates', { exact: true })).toBeVisible();
			await expect(definitionsCard.getByText('Spam detection', { exact: true })).toBeVisible();
			await expect(definitionsCard.getByRole('cell', { name: 'models/gemini-1.5-flash', exact: true })).toBeVisible();
			await expect(definitionsCard.getByRole('cell', { name: '5', exact: true })).toBeVisible();
			await expect(definitionsCard.getByText('CPS templates could not be fetched.', { exact: false })).toHaveCount(0);
			await expect(page.getByText('Current credit balance:')).toBeVisible();
			await expect(page.getByText('Last run succeeded').first()).toBeVisible();
			const actionTableRows = page.getByTestId('form-actions-table').locator('tbody tr');
			await expect(actionTableRows).toHaveCount(1);

			await page.once('dialog', (dialog) => dialog.accept('spam_detection_v1'));
			await page.getByRole('button', { name: 'New action' }).click();
			await expect(actionTableRows).toHaveCount(2);

			const toggleButton = page.getByRole('button', { name: 'Disable' }).first();
			await toggleButton.click();
			await expect(page.getByRole('button', { name: 'Enable' })).toBeVisible();

			const removeButton = page.getByRole('button', { name: 'Remove' }).last();
			await page.once('dialog', (dialog) => dialog.accept());
			await removeButton.click();
			await expect(actionTableRows).toHaveCount(1);

			await page.once('dialog', (dialog) => dialog.accept('123'));
			await page.getByRole('button', { name: 'Check entry status' }).click();
			await expect.poll(() => entryStatusHits).toBe(1);
		} finally {
			await page.context().unroute('**/wp-json/sentient-forms/v1/**');
		}
	});

	test('surfaces actionable guidance when CPS errors occur', async ({ page }) => {
		await page.context().route('**/wp-json/sentient-forms/v1/**', (route) => {
			const url = route.request().url();

			if (url.endsWith('/license')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							status: 'active',
							license_key_masked: 'LIC-****',
							proxy_key_present: true,
							expires_at: '2030-01-01',
							last_synced: '2030-01-01 00:00:00',
							tier: {
								code: 'starter',
								display_name: 'Starter',
								site_limit: 3,
								monthly_credit_quota: 1000
							},
							license_id: 'lic-1',
							site_id: 'site-1',
							site_url: 'https://example.test'
						}
					})
				});
			}

			if (url.endsWith('/credits/balance')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						success: true,
						data: {
							current_balance: 0,
							ledger_delta: 0,
							tier: {
								code: 'starter',
								display_name: 'Starter',
								site_limit: 3,
								monthly_credit_quota: 1000
							}
						}
					})
				});
			}

			if (url.endsWith('/actions/definitions')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify([
						{
							id: 'spam_detection_v1',
							label: 'Spam detection',
							hooks: ['gform_validation'],
							source: 'local'
						}
					])
				});
			}

			if (url.endsWith('/actions/status')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						status: 'error',
						message: 'Sentient Forms could not run: insufficient credits remain for this license.',
						entry_id: 99,
						last_error_code: 'insufficient_credits',
						updated_at: '2030-01-01T00:00:00Z'
					})
				});
			}

			if (url.endsWith('/actions')) {
				if (route.request().method() === 'GET') {
					return route.fulfill({
						status: 200,
						headers: { 'content-type': 'application/json' },
						body: JSON.stringify([])
					});
				}

				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({ deleted: true })
				});
			}

			return route.continue();
		});

			try {
				await page.goto('/actions/gravity_forms/1', { waitUntil: 'networkidle' });

				await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
				const fallbackCard = page.getByTestId('action-definitions-card');
				await expect(fallbackCard.getByText('Local fallback', { exact: true })).toBeVisible();
				await expect(
					fallbackCard.getByText('CPS templates could not be fetched.', { exact: false })
				).toBeVisible();
				await expect(page.getByText('Out of credits', { exact: true }).first()).toBeVisible();
			await expect(
				page.getByText(
					'Sentient Forms could not execute the last submission because this license is out of credits. Visit the Licensing tab to add credits before retrying.'
				)
			).toBeVisible();

			const openLicensing = page.getByRole('button', { name: 'Open Licensing' });
			await expect(openLicensing).toBeVisible();
			await openLicensing.click();

			await expect(page).toHaveURL(/\/licensing$/);
		} finally {
			await page.context().unroute('**/wp-json/sentient-forms/v1/**');
		}
	});
});
