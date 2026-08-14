import { expect, test } from '@playwright/test';
import { mockResponsiveApi } from './utils/mock-responsive-api';
import { seedRuntimeConfig } from './utils/runtime-config';

const credentials = [
	{
		id: 61,
		provider: 'openrouter',
		label: 'Primary OpenRouter key',
		auth_mode: 'manual_key',
		constant_name: null,
		status: 'valid',
		status_json: { is_free_tier: false },
		last_validated_at: '2030-01-05T10:00:00Z',
		created_at: '2030-01-05T09:00:00Z',
		updated_at: '2030-01-05T10:00:00Z',
		secret_configured: true
	},
	{
		id: 64,
		provider: 'openrouter',
		label: 'Backup OpenRouter key',
		auth_mode: 'manual_key',
		constant_name: null,
		status: 'valid',
		status_json: { is_free_tier: false },
		last_validated_at: '2030-01-05T10:00:00Z',
		created_at: '2030-01-05T09:00:00Z',
		updated_at: '2030-01-05T10:00:00Z',
		secret_configured: true
	}
];

test.describe('Provider credential deletion UI', () => {
	test.beforeEach(async ({ page }) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
		await mockResponsiveApi(page);
		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(credentials)
			})
		);
	});

	test('serializes deletion requests across credential rows', async ({ page }) => {
		let releaseDelete!: () => void;
		let markDeleteStarted!: () => void;
		const deleteGate = new Promise<void>((resolve) => {
			releaseDelete = resolve;
		});
		const deleteStarted = new Promise<void>((resolve) => {
			markDeleteStarted = resolve;
		});

		await page.route(
			'**/wp-json/sentient-forms/v1/local/providers/credentials/61',
			async (route) => {
				markDeleteStarted();
				await deleteGate;
				await route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ deleted: true, credential: credentials[0] })
				});
			}
		);

		await page.goto('/#/providers', { waitUntil: 'domcontentloaded' });
		const primaryRow = page
			.getByTestId('providers-openrouter-credential')
			.filter({ hasText: 'Primary OpenRouter key' });
		const backupRow = page
			.getByTestId('providers-openrouter-credential')
			.filter({ hasText: 'Backup OpenRouter key' });

		await primaryRow.getByRole('button', { name: 'Delete' }).click();
		await primaryRow.getByRole('button', { name: 'Delete key' }).click();
		await deleteStarted;
		await expect(backupRow.getByRole('button', { name: 'Delete' })).toBeDisabled();

		releaseDelete();
		await expect(primaryRow).toBeHidden();
		await expect(backupRow.getByRole('button', { name: 'Delete' })).toBeEnabled();
	});

	test('caps large blocker lists while preserving the authoritative total', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/local/providers/credentials/61', (route) =>
			route.fulfill({
				status: 409,
				contentType: 'application/json',
				body: JSON.stringify({
					code: 'sentient_forms_credential_in_use',
					message: 'This provider credential is still used by local configuration or queued work.',
					data: {
						status: 409,
						references: Array.from({ length: 30 }, (_, index) => ({
							type: 'form_mapping',
							id: index + 1,
							form_source: 'gravity_forms',
							form_id: `large-reference-${index + 1}`
						}))
					}
				})
			})
		);

		await page.goto('/#/providers', { waitUntil: 'domcontentloaded' });
		const primaryRow = page
			.getByTestId('providers-openrouter-credential')
			.filter({ hasText: 'Primary OpenRouter key' });
		await primaryRow.getByRole('button', { name: 'Delete' }).click();
		await primaryRow.getByRole('button', { name: 'Delete key' }).click();

		const references = primaryRow.getByTestId('providers-openrouter-delete-references');
		await expect(references).toContainText('Resolve these 30 blockers first');
		await expect(references.locator('li')).toHaveCount(25);
		await expect(
			references.getByTestId('providers-openrouter-delete-references-omitted')
		).toHaveText('Showing the first 25; 5 more not shown.');
	});
});
