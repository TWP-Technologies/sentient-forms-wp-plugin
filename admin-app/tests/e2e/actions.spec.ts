import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';
import { mockWpJson } from './utils/mock-wpjson';

const formSource = 'gravity_forms';
const formId = 123;

const baseDefinitions = [
	{
		id: 'spam-check',
		label: 'Spam check',
		source: 'cps',
		hooks: ['gform_validation'],
		base_credit_cost: 2,
		model_hint: 'gemini-1.5-flash'
	},
	{
		id: 'summarize',
		label: 'Summarize',
		source: 'local',
		hooks: ['gform_after_submission'],
		base_credit_cost: 6,
		model_hint: 'gemini-1.5-pro'
	}
];

const baseCustomActions = [
	{
		id: 'custom-hello',
		code: 'hello',
		display_name: 'Hello action',
		description: 'Greets users',
		status: 'active',
		template_id: null,
		prompt_overrides: {},
		model_hint: null,
		base_credit_cost: 1,
		archived_at: null,
		created_at: '2025-11-20T00:00:00Z',
		updated_at: '2025-11-20T00:00:00Z'
	}
];

const baseForms = [
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

const baseLinkages = [
	{
		local_mapping_id: 'map-1',
		form_id: formId,
		action_code: 'spam-check',
		action_name_label: 'Spam check',
		action_source: 'cps',
		action_status: 'active',
		trigger_hooks: ['gform_validation'],
		created_at: '2025-11-20T00:00:00Z',
		updated_at: '2025-11-20T00:00:00Z',
		is_action_enabled_for_form: true,
		last_run_status: 'unknown'
	}
];

const statusUnknown = {
	status: 'unknown',
	last_run_at: null,
	last_error_code: null,
	message: ''
};

const quota = { quota_max: 3, quota_used: 1, quota_remaining: 2 };
const creditBalance = { credits_remaining: 25, credits_used: 5, credits_max: 30 };

test.describe('Actions admin flows', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = 'http://127.0.0.1:4175';
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});
	});

	test('hash navigation opens the form actions editor', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await expect(page.getByText('Contact us')).toBeVisible();

		await page.getByRole('button', { name: 'Configure' }).click();

		await expect(page).toHaveURL(/#\/actions\/gravity_forms\/123$/);
		await expect(page.getByText('Action library')).toBeVisible();
		const definitionsCard = page.getByTestId('action-definitions-card');
		await expect(definitionsCard.getByText('Spam check', { exact: true })).toBeVisible();
	});

	test('creates a CPS template mapping from the drawer', async ({ page }) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem('sentient_forms_last_hooks', '["gform_validation"]');
			} catch {}
		});
		const linkages: unknown[] = [];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		const firstHook = drawer.locator('input[type="checkbox"]').first();
		await firstHook.check();
		await expect(firstHook).toBeChecked();

		// Template tab is default; wait for the spam-check template to be selected by the app.
		const spamRadio = drawer.getByRole('radio', { name: /Spam check/i });
		await expect(spamRadio).toBeChecked();
		const createReq = page.waitForRequest(/forms\/\d+\/actions$/);
		const createRes = page.waitForResponse(/forms\/\d+\/actions$/);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		await createReq;
		await createRes;

		await expect(drawer).toBeHidden({ timeout: 15_000 });
		const table = page.getByTestId('form-actions-table');
		await expect(table).toBeVisible({ timeout: 10_000 });
		await expect(table.getByText('Spam check')).toBeVisible();
	});

	test('creates a custom action mapping from the drawer', async ({ page }) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem('sentient_forms_last_hooks', '["gform_validation"]');
			} catch {}
		});
		const linkages: unknown[] = [];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.getByRole('button', { name: 'Add action' }).click();
		await page.getByRole('button', { name: 'Custom actions' }).click();

		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		const helloRadio = drawer.getByRole('radio', { name: /Hello action/i });
		await expect(helloRadio).toBeChecked();
		const createReq = page.waitForRequest(/forms\/\d+\/actions$/);
		const createRes = page.waitForResponse(/forms\/\d+\/actions$/);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		await createReq;
		await createRes;

		await expect(drawer).toBeHidden({ timeout: 15_000 });
		const table = page.getByTestId('form-actions-table');
		await expect(table.getByText('Hello action')).toBeVisible();
	});

	test('drawer flow: open add action and show template/custom choices', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: [],
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.getByRole('button', { name: 'Add action' }).click();
		const form = page.getByTestId('link-action-form');
		await expect(form).toBeVisible({ timeout: 10_000 });
		await expect(page.getByPlaceholder('Search by name or id')).toBeVisible();
		await expect(form.getByText('Spam check', { exact: true })).toBeVisible();

		// Switch to custom actions tab and ensure the sample action is shown
		await page.getByRole('button', { name: 'Custom actions' }).click();
		await expect(form.getByText('Hello action')).toBeVisible();
	});

		test('custom actions page renders even when backend endpoint is missing (404)', async ({
			page
		}) => {
			// Keep the preview-host runtime config from beforeEach so requests remain same-origin.
			// This spec only verifies the UI handles a 404 from the custom actions endpoint gracefully.

			// Simulate missing endpoint
			await page.route('**/wp-json/sentient-forms/v1/custom-actions**', (route) =>
				route.fulfill({ status: 404, contentType: 'application/json', body: '{}' })
			);

			// Fail fast on page errors
			const pageErrors: string[] = [];
			page.on('pageerror', (err) => pageErrors.push(err.message));

			await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });

			await expect(page.getByRole('heading', { name: 'Custom Actions' })).toBeVisible();
			await expect(page.getByText(/No active custom actions yet/i)).toBeVisible();
			expect(pageErrors, 'no runtime errors should surface').toEqual([]);
		});
	});
