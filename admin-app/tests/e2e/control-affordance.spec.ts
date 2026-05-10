import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';
import { mockWpJson } from './utils/mock-wpjson';

const formSource = 'gravity_forms';
const formId = 123;

const definitions = [
	{
		id: 'spam_detection_v1',
		label: 'Spam detection',
		source: 'cps',
		hooks: ['gform_validation'],
		base_credit_cost: 2
	}
];

const forms = [
	{
		id: formId,
		title: 'Contact us',
		adapter: formSource,
		adapter_name: 'Gravity Forms',
		settings: {
			enabled: true,
			actions: {}
		}
	}
];

const linkages = [
	{
		local_mapping_id: 'map-1',
		central_action_id: 'spam_detection_v1',
		action_type_indicator: 'master',
		action_name_label: 'Spam detection',
		trigger_hooks: ['gform_validation'],
		is_action_enabled_for_form: true,
		settings: {}
	}
];

const formFields = [
	{ id: '1', label: 'Name', type: 'text' },
	{ id: '2', label: 'Message', type: 'textarea' }
];

const statusUnknown = {
	status: 'unknown',
	last_run_at: null,
	last_error_code: null,
	message: ''
};

const quota = { quota_max: 3, quota_used: 1, quota_remaining: 2 };
const creditBalance = { credits_remaining: 25, credits_used: 5, credits_max: 30 };

test.describe('Control affordance normalization', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});
	});

	test('actions defaults modal buttons follow shared variants', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: forms },
				definitions,
				status: statusUnknown,
				formsActions: linkages,
				creditBalance,
				actionDefaultsById: {
					spam_detection_v1: {
						include_site_context: 'always',
						spam_positive_examples: [
							{
								text: 'Known customer request',
								rationale: 'Existing customers sometimes ask terse follow-up questions.'
							}
						],
						spam_negative_examples: [
							{
								text: 'Bulk SEO outreach',
								rationale: 'Generic agency pitch unrelated to the form purpose.'
							}
						]
					}
				}
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByTestId('action-defaults-button-spam_detection_v1').click();

		const modal = page.getByTestId('action-defaults-modal');
		await expect(modal).toBeVisible();

		const closeButton = modal.getByTestId('action-defaults-close');
		await expect(closeButton).toHaveClass(/sf:inline-flex/);
		await expect(closeButton).toHaveClass(/sf:border-slate-300/);

		const cancelButton = modal.getByRole('button', { name: 'Cancel' });
		await expect(cancelButton).toHaveClass(/sf:border-slate-300/);

		const saveButton = modal.getByRole('button', { name: /Save Global Defaults/ });
		await expect(saveButton).toHaveClass(/sf:bg-primary-600/);
	});

	test('form-level defaults and mapping disclosure controls expose affordance classes', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: forms },
				definitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields,
				creditBalance
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });

		await page.getByTestId('action-definitions-card').locator('summary').click();
		await page.getByRole('button', { name: 'Defaults' }).first().click();
		const formDefaultsModal = page.getByTestId('form-defaults-modal');
		await expect(formDefaultsModal).toBeVisible();

		const closeButton = formDefaultsModal.getByTestId('form-defaults-close');
		await expect(closeButton).toHaveClass(/sf:inline-flex/);
		await expect(closeButton).toHaveClass(/sf:border-slate-300/);

		const saveDefaults = formDefaultsModal.getByRole('button', { name: /Save Defaults/ });
		await expect(saveDefaults).toHaveClass(/sf:bg-primary-600/);
		await closeButton.click();

		await page.getByTestId('linked-actions-view-table').click();
		await page
			.getByTestId('form-actions-table')
			.locator('tbody tr')
			.first()
			.getByRole('button', { name: 'Configure' })
			.click();

		const mappingModal = page.getByTestId('mapping-config-modal');
		await expect(mappingModal).toBeVisible();

		const coreSectionToggle = mappingModal.getByTestId('mapping-section-toggle-core');
		await expect(coreSectionToggle).toHaveClass(/sf:focus-visible:ring-2/);
		await expect(coreSectionToggle).toHaveClass(/sf:focus-visible:ring-primary-500/);

		const saveMapping = mappingModal.getByTestId('mapping-config-save');
		await expect(saveMapping).toHaveClass(/sf:bg-primary-600/);
	});

	test('action log controls expose high-contrast focus classes', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/actions/log**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					entries: [],
					total: 0,
					total_pages: 0,
					page: 1,
					per_page: 20
				})
			})
		);

		await page.goto('/#/actions/log', { waitUntil: 'networkidle' });

		const refreshButton = page.getByRole('button', { name: 'Refresh' });
		await expect(refreshButton).toHaveClass(/sf:focus-visible:ring-slate-600/);
		await expect(refreshButton).toHaveClass(/sf:focus-visible:ring-offset-white/);

		const statusFilter = page.locator('#filter-status');
		await expect(statusFilter).toHaveClass(/sf:focus-visible:ring-primary-500/);
		await expect(statusFilter).toHaveClass(/sf:focus-visible:ring-offset-white/);
	});
});
