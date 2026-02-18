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

const baseFormFields = [
	{ id: '1', label: 'Subject', type: 'text' },
	{ id: '2', label: 'Message', type: 'textarea' },
	{ id: '3', label: 'Amount', type: 'number' }
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

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
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

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
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

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const form = page.getByTestId('link-action-form');
		await expect(form).toBeVisible({ timeout: 10_000 });
		await expect(page.getByPlaceholder('Search by name or id')).toBeVisible();
		await expect(form.getByText('Spam check', { exact: true })).toBeVisible();

		// Switch to custom actions tab and ensure the sample action is shown
		await page.getByRole('button', { name: 'Custom actions' }).click();
		await expect(form.getByText('Hello action')).toBeVisible();
	});

	test('saves conditional run settings from mapping editor', async ({ page }) => {
		const linkages = [
			{
				...baseLinkages[0],
				settings: {}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		const table = page.getByTestId('form-actions-table');
		const firstRow = table.locator('tbody tr').first();
		await firstRow.getByRole('button', { name: 'Configure' }).click();

		const enableConditionalRun = page.getByRole('checkbox', { name: 'Enable conditional run' });
		await expect(enableConditionalRun).toBeVisible();
		await enableConditionalRun.check();
		await page.getByRole('button', { name: 'Add rule' }).click();
		await page.locator('label:has-text("Field") select').first().selectOption('1');
		await page.locator('label:has-text("Value") input').first().fill('urgent');

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-1$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-1$/, { timeout: 15_000 });
		await page.getByRole('button', { name: /^Save$/ }).click();

		const request = await updateReq;
		await updateRes;
		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		const conditions = (settings.conditions ?? {}) as Record<string, unknown>;
		const root = (conditions.root ?? {}) as Record<string, unknown>;
		const rules = (root.rules ?? []) as Array<Record<string, unknown>>;

		expect(conditions.enabled).toBe(true);
		expect(root.logic).toBe('all');
		expect(rules[0]?.field_id).toBe('1');
		expect(rules[0]?.operator).toBe('eq');
		expect(rules[0]?.value).toBe('urgent');
	});

	test('saves nested conditional run settings with list and numeric operators', async ({ page }) => {
		const linkages = [
			{
				...baseLinkages[0],
				settings: {}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		const table = page.getByTestId('form-actions-table');
		const firstRow = table.locator('tbody tr').first();
		await firstRow.getByRole('button', { name: 'Configure' }).click();

		const enableConditionalRun = page.getByRole('checkbox', { name: 'Enable conditional run' });
		await expect(enableConditionalRun).toBeVisible();
		await enableConditionalRun.check();

		await page.getByRole('button', { name: 'Add group' }).first().click();
		await page.getByRole('button', { name: 'Add rule' }).first().click();

		await page.locator('label:has-text("Field") select').first().selectOption('2');
		await page.locator('label:has-text("Operator") select').first().selectOption('in');
		await page
			.locator('label:has-text("Values (comma-separated)") input')
			.first()
			.fill('sales, billing');

		await page.getByRole('button', { name: 'Add rule' }).last().click();
		await page.locator('label:has-text("Field") select').nth(1).selectOption('3');
		await page.locator('label:has-text("Operator") select').nth(1).selectOption('gt');
		await page.locator('label:has-text("Numeric value") input').first().fill('100');

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-1$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-1$/, { timeout: 15_000 });
		await page.getByRole('button', { name: /^Save$/ }).click();

		const request = await updateReq;
		await updateRes;
		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		const conditions = (settings.conditions ?? {}) as Record<string, unknown>;
		const root = (conditions.root ?? {}) as Record<string, unknown>;
		const rules = (root.rules ?? []) as Array<Record<string, unknown>>;
		const nestedGroup = (rules[0] ?? {}) as Record<string, unknown>;
		const nestedRules = (nestedGroup.rules ?? []) as Array<Record<string, unknown>>;
		const numericRule = (rules[1] ?? {}) as Record<string, unknown>;

		expect(conditions.enabled).toBe(true);
		expect(root.logic).toBe('all');
		expect(nestedGroup.type).toBe('group');
		expect(nestedRules[0]?.operator).toBe('in');
		expect(nestedRules[0]?.value).toEqual(['sales', 'billing']);
		expect(numericRule.operator).toBe('gt');
		expect(numericRule.value).toBe(100);
	});

	test('saves dependency_ids from the dependency graph editor', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-1',
				central_action_id: 'spam-check',
				action_type_indicator: 'master',
				action_name_label: 'Spam check',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-2',
				central_action_id: 'summarize',
				action_type_indicator: 'master',
				action_name_label: 'Summarize',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });

		const table = page.getByTestId('form-actions-table');
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).click();

		await page.getByTestId('linked-actions-view-graph').click();
		await page.getByTestId('dependency-node-map-1').click();
		await page.getByTestId('linked-actions-view-table').click();

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		await summarizeRow.getByRole('button', { name: /^Save$/ }).click();

		const request = await updateReq;
		await updateRes;
		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(settings.dependency_ids).toEqual(['map-1']);
	});

	test('toggles linked-actions views and exposes graph card controls', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-1',
				central_action_id: 'spam-check',
				action_type_indicator: 'master',
				action_name_label: 'Spam check',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('form-actions-table')).toBeVisible();
		await expect(page.getByTestId('dependency-graph')).toHaveCount(0);

		await page.getByTestId('linked-actions-view-graph').click();
		await expect(page.getByTestId('dependency-graph')).toBeVisible();
		await expect(page.getByTestId('form-actions-table')).toHaveCount(0);
		await expect(page.getByTestId('dependency-node-configure-map-1')).toBeVisible();
		await expect(page.getByTestId('dependency-node-toggle-enabled-map-1')).toBeVisible();
		await expect(page.getByTestId('dependency-node-remove-map-1')).toBeVisible();

		await page.getByTestId('linked-actions-view-table').click();
		await expect(page.getByTestId('form-actions-table')).toBeVisible();
		await expect(page.getByTestId('dependency-graph')).toHaveCount(0);
	});

	test('prevents saving a cycle in dependency graph', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-1',
				central_action_id: 'spam-check',
				action_type_indicator: 'master',
				action_name_label: 'Spam check',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-2',
				central_action_id: 'summarize',
				action_type_indicator: 'master',
				action_name_label: 'Summarize',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		const table = page.getByTestId('form-actions-table');

		// First save map-1 -> map-2 (valid edge)
		const spamRow = table.locator('tbody tr').filter({ hasText: 'Spam check' });
		await spamRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('linked-actions-view-graph').click();
		await page.getByTestId('dependency-node-map-2').click();
		await page.getByTestId('linked-actions-view-table').click();
		await spamRow.getByRole('button', { name: /^Save$/ }).click();
		await page.waitForResponse(/forms\/\d+\/actions\/map-1$/, { timeout: 15_000 });

		// Then attempt map-2 -> map-1 (cycle) and ensure request is blocked client-side.
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('linked-actions-view-graph').click();
		await page.getByTestId('dependency-node-map-1').click();
		await page.getByTestId('linked-actions-view-table').click();

		const cycleRequestPromise = page
			.waitForRequest(
				(request) => request.method() === 'PUT' && /forms\/\d+\/actions\/map-2$/.test(request.url()),
				{ timeout: 1_000 }
			)
			.then(() => true)
			.catch(() => false);
		await summarizeRow.getByRole('button', { name: /^Save$/ }).click();
		const cycleRequestSent = await cycleRequestPromise;

		expect(cycleRequestSent).toBe(false);
		await expect(summarizeRow.getByRole('button', { name: /^Save$/ })).toBeVisible();
	});

	test('prevents saving async dependency to sync after-submission mapping', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-1',
				central_action_id: 'spam-check',
				action_type_indicator: 'master',
				action_name_label: 'Spam check',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-2',
				central_action_id: 'summarize',
				action_type_indicator: 'custom',
				action_name_label: 'Summarize',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: { execution_mode: 'validation' }
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		const table = page.getByTestId('form-actions-table');
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });

		await summarizeRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('linked-actions-view-graph').click();
		await page.getByTestId('dependency-node-map-1').click();
		await page.getByTestId('linked-actions-view-table').click();

		const mismatchRequestPromise = page
			.waitForRequest(
				(request) => request.method() === 'PUT' && /forms\/\d+\/actions\/map-2$/.test(request.url()),
				{ timeout: 1_000 }
			)
			.then(() => true)
			.catch(() => false);
		await summarizeRow.getByRole('button', { name: /^Save$/ }).click();
		const mismatchRequestSent = await mismatchRequestPromise;

		expect(mismatchRequestSent).toBe(false);
		await expect(summarizeRow.getByRole('button', { name: /^Save$/ })).toBeVisible();
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
