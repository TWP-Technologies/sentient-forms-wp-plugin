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

async function openLinkedActionsTable(page: Parameters<typeof test>[0]['page']) {
	const tableToggle = page.getByTestId('linked-actions-view-table');
	if ((await tableToggle.count()) > 0) {
		await tableToggle.first().click();
	}
	const table = page.getByTestId('form-actions-table');
	await expect(table).toBeVisible();
	return table;
}

async function saveMappingConfigModal(page: Parameters<typeof test>[0]['page']) {
	const modal = page.getByTestId('mapping-config-modal');
	await modal
		.locator('footer')
		.getByRole('button', { name: /^Save( changes)?$/ })
		.click();
}

async function connectHandlesByMouse(
	page: Parameters<typeof test>[0]['page'],
	sourceSelector: string,
	targetSelector: string
) {
	const source = page.locator(sourceSelector).first();
	const target = page.locator(targetSelector).first();
	await expect(source).toBeVisible();
	await expect(target).toBeVisible();
	await source.scrollIntoViewIfNeeded();
	await target.scrollIntoViewIfNeeded();
	await source.hover();

	const sourceBox = await source.boundingBox();
	const targetBox = await target.boundingBox();
	if (!sourceBox || !targetBox) {
		throw new Error('Could not resolve graph handle positions for drag connection.');
	}

	const sourceX = sourceBox.x + sourceBox.width / 2;
	const sourceY = sourceBox.y + sourceBox.height / 2;
	const targetX = targetBox.x + targetBox.width / 2;
	const targetY = targetBox.y + targetBox.height / 2;

	await page.mouse.move(sourceX, sourceY);
	await page.mouse.down();
	await page.mouse.move(targetX, targetY, { steps: 20 });
	await page.mouse.up();
}

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
		const table = await openLinkedActionsTable(page);
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
		const table = await openLinkedActionsTable(page);
		await expect(table.getByText('Hello action')).toBeVisible();
	});

	test('creates a mapping with execute-after dependencies from the drawer', async ({ page }) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem('sentient_forms_last_hooks', '["gform_validation"]');
			} catch {}
		});
		const linkages: unknown[] = [
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
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();

		const dependencyOption = drawer.locator('label', { hasText: 'ID: map-1' }).first();
		await dependencyOption.locator('input[type="checkbox"]').check();
		await expect(dependencyOption.getByText('Spam check')).toBeVisible();

		const createReq = page.waitForRequest(/forms\/\d+\/actions$/);
		const createRes = page.waitForResponse(/forms\/\d+\/actions$/);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		const request = await createReq;
		await createRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(settings.dependency_ids).toEqual(['map-1']);
	});

	test('allows validation upstream dependency for after-submission mapping in drawer', async ({
		page
	}) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem('sentient_forms_last_hooks', '["gform_after_submission"]');
			} catch {}
		});

		const linkages: unknown[] = [
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
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.getByRole('heading', { name: 'Actions' }).waitFor();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();

		// Switch to after-submission template hook preset.
		await drawer.getByRole('radio', { name: /Summarize/i }).check();
		await expect(drawer.getByText('Triggered by action (optional)')).toBeVisible();

		// Validation-only map-1 should still be eligible as an upstream dependency.
		const dependencyOption = drawer.locator('label', { hasText: 'ID: map-1' }).first();
		await expect(dependencyOption).toBeVisible();
		await dependencyOption.locator('input[type="checkbox"]').check();

		const createReq = page.waitForRequest(/forms\/\d+\/actions$/);
		const createRes = page.waitForResponse(/forms\/\d+\/actions$/);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		const request = await createReq;
		await createRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(payload.trigger_hooks).toEqual(['gform_after_submission']);
		expect(settings.dependency_ids).toEqual(['map-1']);
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
		const table = await openLinkedActionsTable(page);
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
		await saveMappingConfigModal(page);

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

	test('saves nested conditional run settings with list and numeric operators', async ({
		page
	}) => {
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
		const table = await openLinkedActionsTable(page);
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
		await saveMappingConfigModal(page);

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

		const table = await openLinkedActionsTable(page);
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).first().click();
		await page.getByTestId('mapping-config-open-graph').click();
		const sourceHandle = '[data-nodeid="map-1"][data-handleid="dependency-source"]';
		const targetHandle = '[data-nodeid="map-2"][data-handleid="hook-root-target:gform_validation"]';
		await connectHandlesByMouse(page, sourceHandle, targetHandle);

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		await page.getByTestId('dependency-graph-save').click();

		const request = await updateReq;
		await updateRes;
		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(settings.dependency_ids).toEqual(['map-1']);
	});

	test('make autonomous clears dependency_ids and shows unsaved indicator', async ({ page }) => {
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
				settings: { dependency_ids: ['map-1'] }
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

		const table = await openLinkedActionsTable(page);
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		await page.getByTestId('dependency-graph-clear').click();
		await expect(page.getByTestId('dependency-graph-dirty-bar')).toBeVisible();

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		await page.getByTestId('dependency-graph-save').click();

		const request = await updateReq;
		await updateRes;
		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(settings.dependency_ids).toBeUndefined();
	});

	test('saves dependency_ids directly in graph view', async ({ page }) => {
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

		const table = await openLinkedActionsTable(page);
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();
		const sourceHandle = '[data-nodeid="map-1"][data-handleid="dependency-source"]';
		const targetHandle = '[data-nodeid="map-2"][data-handleid="hook-root-target:gform_validation"]';
		await connectHandlesByMouse(page, sourceHandle, targetHandle);

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		await page.getByTestId('dependency-graph-save').click();
		const request = await updateReq;
		await updateRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(settings.dependency_ids).toEqual(['map-1']);
	});

	test('supports drag-and-drop dependency connection on graph handles', async ({ page }) => {
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
		const table = await openLinkedActionsTable(page);
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).first().click();
		await page.getByTestId('mapping-config-open-graph').click();

		const sourceHandle = '[data-nodeid="map-1"][data-handleid="dependency-source"]';
		const targetHandle = '[data-nodeid="map-2"][data-handleid="dependency-target"]';
		await connectHandlesByMouse(page, sourceHandle, targetHandle);
		await expect(page.getByTestId('dependency-graph-dirty-bar')).toBeVisible();

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		await page.getByTestId('dependency-graph-save').click();
		const request = await updateReq;
		await updateRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(settings.dependency_ids).toEqual(['map-1']);
	});

	test('supports dependency drag when hook-root edges overlap in view', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-1',
				central_action_id: 'entry-summary',
				action_type_indicator: 'master',
				action_name_label: 'Entry Summary',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-2',
				central_action_id: 'content-quality',
				action_type_indicator: 'master',
				action_name_label: 'Content Quality Validation',
				trigger_hooks: ['gform_after_submission', 'gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-3',
				central_action_id: 'spam-check',
				action_type_indicator: 'master',
				action_name_label: 'Validation Spam Block',
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
		const table = await openLinkedActionsTable(page);
		const contentQualityRow = table
			.locator('tbody tr')
			.filter({ hasText: 'Content Quality Validation' });
		await contentQualityRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		const sourceHandle = '[data-nodeid=\"map-3\"][data-handleid=\"dependency-source\"]';
		const targetHandle = '[data-nodeid=\"map-2\"][data-handleid=\"dependency-target\"]';
		await connectHandlesByMouse(page, sourceHandle, targetHandle);
		await expect(page.getByTestId('dependency-graph-dirty-bar')).toBeVisible();
	});

	test('infers async hook when connecting async source to dual-hook target via generic handle', async ({
		page
	}) => {
		const linkages = [
			{
				local_mapping_id: 'map-async',
				central_action_id: 'entry-summary',
				action_type_indicator: 'master',
				action_name_label: 'Entry Summary',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-dual',
				central_action_id: 'content-quality',
				action_type_indicator: 'master',
				action_name_label: 'Content Quality Validation',
				trigger_hooks: ['gform_validation', 'gform_after_submission'],
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
		const table = await openLinkedActionsTable(page);
		const dualRow = table.locator('tbody tr').filter({ hasText: 'Content Quality Validation' });
		await dualRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		const sourceHandle = '[data-nodeid="map-async"][data-handleid="dependency-source"]';
		const targetHandle = '[data-nodeid="map-dual"][data-handleid="dependency-target"]';
		await connectHandlesByMouse(page, sourceHandle, targetHandle);
		await expect(page.getByTestId('dependency-graph-dirty-bar')).toBeVisible();

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-dual$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-dual$/, { timeout: 15_000 });
		await page.getByTestId('dependency-graph-save').click();
		const request = await updateReq;
		await updateRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, any>;
		expect(settings.dependency_ids).toEqual(['map-async']);
		expect(settings.trigger_sources?.gform_after_submission?.type).toBe('mapping');
		expect(settings.trigger_sources?.gform_after_submission?.mapping_id).toBe('map-async');
	});

	test('supports drag-and-drop root retarget for autonomous mapping', async ({ page }) => {
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
				settings: { dependency_ids: ['map-1'] }
			},
			{
				local_mapping_id: 'map-3',
				central_action_id: 'summarize',
				action_type_indicator: 'master',
				action_name_label: 'Entry Summary',
				trigger_hooks: ['gform_after_submission'],
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
		const table = await openLinkedActionsTable(page);
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).first().click();
		await page.getByTestId('mapping-config-open-graph').click();

		const rootSource =
			'[data-nodeid="__hook_root__:gform_validation"][data-handleid="hook-root-source"]';
		const rootTarget =
			'[data-nodeid="map-2"][data-handleid="hook-root-target:gform_validation"]';
		await connectHandlesByMouse(page, rootSource, rootTarget);
		await expect(page.getByTestId('dependency-graph-dirty-bar')).toBeVisible();

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		await page.getByTestId('dependency-graph-save').click();
		const request = await updateReq;
		await updateRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect((settings.trigger_sources as Record<string, { type?: string }>)?.gform_validation?.type).toBe(
			'hook_root'
		);
		expect(settings.dependency_ids).toBeUndefined();
	});

	test('blocks duplicate root-to-action links for the same hook', async ({ page }) => {
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
		const table = await openLinkedActionsTable(page);
		const row = table.locator('tbody tr').filter({ hasText: 'Spam check' });
		await row.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		const rootSource =
			'[data-nodeid="__hook_root__:gform_validation"][data-handleid="hook-root-source"]';
		const rootTarget = '[data-nodeid="map-1"][data-handleid="hook-root-target:gform_validation"]';
		await connectHandlesByMouse(page, rootSource, rootTarget);

		const feedback = page.getByTestId('dependency-graph-connection-feedback');
		await expect(feedback).toBeVisible();
		await expect(feedback).toContainText(/already autonomous/i);
		await expect(page.getByTestId('dependency-graph-dirty-bar')).toHaveCount(0);
	});

	test('shows dependent trigger source and hides autonomous hook badges on graph cards', async ({
		page
	}) => {
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
				settings: {
					dependency_ids: ['map-1'],
					trigger_sources: {
						gform_validation: { type: 'mapping', mapping_id: 'map-1' }
					}
				}
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
		const table = await openLinkedActionsTable(page);
		const row = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await row.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		const dependentCard = page.getByTestId('dependency-node-card-map-2');
		await expect(dependentCard.getByText('Triggered by action: map-1')).toBeVisible();
		await expect(dependentCard.getByText('During Validation')).toHaveCount(0);
	});

	test('shows compact graph helper legend and removes redundant per-card drag instruction', async ({
		page
	}) => {
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
		await expect(page.getByTestId('dependency-graph-canvas')).toBeVisible();
		await expect(page.getByText('Pan, zoom, and drag enabled')).toBeVisible();
		await expect(
			page.getByText('Blue right handle -> left gray or hook-blue handle: dependency')
		).toBeVisible();
		const mappingCard = page.getByTestId('dependency-node-card-map-1');
		await expect(mappingCard).not.toContainText(
			"Drag from the right blue handle into another action's left gray or hook-blue handles to set dependency order."
		);
	});

	test('keeps duplicate control in right action cluster next to remove', async ({ page }) => {
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
		await expect(page.getByTestId('dependency-graph-canvas')).toBeVisible();

		const leftActions = page.getByTestId('dependency-node-actions-left-map-1');
		const rightActions = page.getByTestId('dependency-node-actions-right-map-1');
		const configure = page.getByTestId('dependency-node-configure-map-1');
		const duplicate = page.getByTestId('dependency-node-duplicate-open-map-1');
		const remove = page.getByTestId('dependency-node-remove-map-1');

		await expect(leftActions).toBeVisible();
		await expect(rightActions).toBeVisible();
		await expect(configure).toBeVisible();
		await expect(duplicate).toBeVisible();
		await expect(remove).toBeVisible();
		await expect(leftActions.getByTestId('dependency-node-duplicate-open-map-1')).toHaveCount(0);
		await expect(rightActions.getByTestId('dependency-node-duplicate-open-map-1')).toHaveCount(1);

		const [leftBox, duplicateBox, removeBox] = await Promise.all([
			leftActions.boundingBox(),
			duplicate.boundingBox(),
			remove.boundingBox()
		]);
		if (!leftBox || !duplicateBox || !removeBox) {
			throw new Error('Could not resolve action cluster geometry for duplicate button placement.');
		}

		expect(duplicateBox.x).toBeGreaterThan(leftBox.x + leftBox.width - 12);
		const duplicateMidY = duplicateBox.y + duplicateBox.height / 2;
		const removeMidY = removeBox.y + removeBox.height / 2;
		expect(Math.abs(duplicateMidY - removeMidY)).toBeLessThan(14);
	});

	test('raises active node z-index so duplicate popover is rendered above neighboring cards', async ({
		page
	}) => {
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
				trigger_hooks: ['gform_validation', 'gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map-1']
				}
			},
			{
				local_mapping_id: 'map-3',
				central_action_id: 'entry-summary',
				action_type_indicator: 'master',
				action_name_label: 'Entry Summary',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-4',
				central_action_id: 'spam-check',
				action_type_indicator: 'master',
				action_name_label: 'Spam Follow-up',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map-3']
				}
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
		await expect(page.getByTestId('dependency-graph-canvas')).toBeVisible();
		await page.getByTestId('dependency-node-duplicate-open-map-2').click();

		const popover = page.getByTestId('dependency-node-duplicate-popover-map-2');
		await expect(popover).toBeVisible();

		const layering = await page.evaluate(() => {
			const activeCard = document.querySelector('[data-testid="dependency-node-card-map-2"]');
			if (!activeCard) {
				return {
					missing: 'active_card',
					activeWrapperZ: 0,
					maxOtherWrapperZ: 0,
					overlapChecked: false,
					overlapHitPopover: false,
					popoverVisible: false
				};
			}
			const popoverElement = document.querySelector(
				'[data-testid="dependency-node-duplicate-popover-map-2"]'
			);
			if (!popoverElement) {
				return {
					missing: 'popover',
					activeWrapperZ: 0,
					maxOtherWrapperZ: 0,
					overlapChecked: false,
					overlapHitPopover: false,
					popoverVisible: false
				};
			}

			const activeWrapper = activeCard.closest('.svelte-flow__node');
			const wrappers = Array.from(document.querySelectorAll('.svelte-flow__node'));
			const cardWrappers = wrappers.filter((wrapper) =>
				Boolean(wrapper.querySelector('[data-testid^="dependency-node-card-"]'))
			);
			const otherWrappers = cardWrappers.filter((wrapper) => wrapper !== activeWrapper);

			const zIndexValue = (element: Element | null): number => {
				if (!element) return 0;
				const parsed = Number.parseInt(getComputedStyle(element).zIndex, 10);
				return Number.isFinite(parsed) ? parsed : 0;
			};

			const activeWrapperZ = zIndexValue(activeWrapper);
			const maxOtherWrapperZ = otherWrappers.reduce(
				(max, wrapper) => Math.max(max, zIndexValue(wrapper)),
				0
			);

			const popRect = popoverElement.getBoundingClientRect();
			let overlapChecked = false;
			let overlapHitPopover = false;
			for (const wrapper of otherWrappers) {
				const card = wrapper.querySelector('[data-testid^="dependency-node-card-"]');
				if (!card) continue;
				const rect = card.getBoundingClientRect();
				const left = Math.max(popRect.left, rect.left);
				const right = Math.min(popRect.right, rect.right);
				const top = Math.max(popRect.top, rect.top);
				const bottom = Math.min(popRect.bottom, rect.bottom);
				if (right - left <= 8 || bottom - top <= 8) continue;
				overlapChecked = true;
				const hit = document.elementFromPoint(left + (right - left) / 2, top + (bottom - top) / 2);
				overlapHitPopover = Boolean(hit && popoverElement.contains(hit));
				break;
			}

			return {
				missing: null as string | null,
				activeWrapperZ,
				maxOtherWrapperZ,
				overlapChecked,
				overlapHitPopover,
				popoverVisible: getComputedStyle(popoverElement).display !== 'none'
			};
		});

		expect(layering.missing).toBeNull();
		expect(layering.popoverVisible).toBe(true);
		expect(layering.activeWrapperZ).toBeGreaterThan(layering.maxOtherWrapperZ);
		if (layering.overlapChecked) {
			expect(layering.overlapHitPopover).toBe(true);
		}
	});

	test('duplicates a mapping from graph card icon and posts parent insertion payload', async ({
		page
	}) => {
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
		await expect(page.getByTestId('dependency-graph-canvas')).toBeVisible();
		await expect(page.locator('[data-testid^="dependency-node-card-"]')).toHaveCount(2);

		const duplicateReq = page.waitForRequest(/forms\/\d+\/actions\/map-1\/duplicate$/, {
			timeout: 15_000
		});
		const duplicateRes = page.waitForResponse(/forms\/\d+\/actions\/map-1\/duplicate$/, {
			timeout: 15_000
		});

		await page.getByTestId('dependency-node-duplicate-open-map-1').click();
		await expect(page.getByTestId('dependency-node-duplicate-select-map-1')).toBeVisible();
		await page.getByTestId('dependency-node-duplicate-confirm-map-1').click();

		const request = await duplicateReq;
		await duplicateRes;
		const payload = request.postDataJSON() as Record<string, unknown>;
		expect((payload.parent as Record<string, unknown>)?.type).toBe('hook_root');
		expect((payload.parent as Record<string, unknown>)?.hook).toBe('gform_validation');

		await expect(page.locator('[data-testid^="dependency-node-card-"]')).toHaveCount(3);
	});

	test('shows connection feedback when a drag is rejected by policy', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-1',
				central_action_id: 'spam-check',
				action_type_indicator: 'master',
				action_name_label: 'Spam check',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: { dependency_ids: ['map-2'] }
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
		const table = await openLinkedActionsTable(page);
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		const sourceHandle = '[data-nodeid="map-2"][data-handleid="dependency-source"]';
		const targetHandle = '[data-nodeid="map-1"][data-handleid="dependency-target"]';
		await connectHandlesByMouse(page, sourceHandle, targetHandle);

		const feedback = page.getByTestId('dependency-graph-connection-feedback');
		await expect(feedback).toBeVisible();
		await expect(feedback).toContainText(/cycle|already depends/i);
		await expect(page.getByTestId('dependency-graph-dirty-bar')).toHaveCount(0);
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

		await expect(page.getByTestId('dependency-graph')).toBeVisible();
		await expect(page.getByTestId('form-actions-table')).toHaveCount(0);
		await expect(page.getByText('Linked actions')).toHaveCount(0);
		await expect(
			page.getByText('Enable, disable, or retarget hooks for actions connected to this form.')
		).toHaveCount(0);
		await expect(page.getByTestId('dependency-node-configure-map-1')).toBeVisible();
		await expect(page.getByTestId('dependency-node-toggle-enabled-map-1')).toBeVisible();
		await expect(page.getByTestId('dependency-node-remove-map-1')).toBeVisible();

		await page.getByTestId('linked-actions-view-table').click();
		await expect(page.getByTestId('form-actions-table')).toBeVisible();
		await expect(page.getByText('Linked actions')).toBeVisible();
		await expect(
			page.getByText('Enable, disable, or retarget hooks for actions connected to this form.')
		).toBeVisible();
		await page.getByTestId('linked-actions-view-graph').click();
		await expect(page.getByTestId('dependency-graph')).toBeVisible();

		await page.getByTestId('dependency-node-configure-map-1').click();
		const configModal = page.getByTestId('mapping-config-modal');
		await expect(configModal).toBeVisible();
		await expect(configModal.getByText('Configure Action Mapping')).toBeVisible();
		await configModal.getByRole('button', { name: 'Close' }).first().click();
		await expect(configModal).toBeHidden();

		const disableReq = page.waitForRequest(
			(request) => request.method() === 'PUT' && /forms\/\d+\/actions\/map-1$/.test(request.url()),
			{ timeout: 15_000 }
		);
		const disableRes = page.waitForResponse(
			(response) =>
				response.request().method() === 'PUT' &&
				/forms\/\d+\/actions\/map-1$/.test(response.url()) &&
				response.status() === 200,
			{ timeout: 15_000 }
		);
		await page.getByTestId('dependency-node-toggle-enabled-map-1').click();
		const disableRequest = await disableReq;
		await disableRes;
		expect(
			(disableRequest.postDataJSON() as { is_action_enabled_for_form?: boolean })
				.is_action_enabled_for_form
		).toBe(false);
		await expect(page.getByTestId('dependency-node-toggle-enabled-map-1')).toHaveText('Disabled');

		await page.getByTestId('dependency-node-remove-map-1').click();
		await expect(page.getByTestId('dependency-node-remove-confirm-map-1')).toBeVisible();
		await expect(page.getByTestId('dependency-node-remove-cancel-map-1')).toBeVisible();
		await page.getByTestId('dependency-node-remove-cancel-map-1').click();
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
				settings: { dependency_ids: ['map-2'] }
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
		const table = await openLinkedActionsTable(page);

		// map-1 already depends on map-2 from fixture. Attempt map-2 -> map-1 and ensure
		// the request is blocked client-side.
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });
		await summarizeRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();
		await connectHandlesByMouse(
			page,
			'[data-nodeid="map-1"][data-handleid="dependency-source"]',
			'[data-nodeid="map-2"][data-handleid="hook-root-target:gform_validation"]'
		);

		const cycleRequestPromise = page
			.waitForRequest(
				(request) =>
					request.method() === 'PUT' && /forms\/\d+\/actions\/map-2$/.test(request.url()),
				{ timeout: 1_000 }
			)
			.then(() => true)
			.catch(() => false);
		await page.getByTestId('dependency-graph-save').click();
		const cycleRequestSent = await cycleRequestPromise;

		expect(cycleRequestSent).toBe(false);
		await expect(page.getByTestId('dependency-graph-save')).toBeVisible();
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
		const table = await openLinkedActionsTable(page);
		const summarizeRow = table.locator('tbody tr').filter({ hasText: 'Summarize' });

		await summarizeRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();
		await connectHandlesByMouse(
			page,
			'[data-nodeid="map-1"][data-handleid="dependency-source"]',
			'[data-nodeid="map-2"][data-handleid="hook-root-target:gform_after_submission"]'
		);

		const mismatchRequestPromise = page
			.waitForRequest(
				(request) =>
					request.method() === 'PUT' && /forms\/\d+\/actions\/map-2$/.test(request.url()),
				{ timeout: 1_000 }
			)
			.then(() => true)
			.catch(() => false);
		await page.getByTestId('dependency-graph-save').click();
		const mismatchRequestSent = await mismatchRequestPromise;

		expect(mismatchRequestSent).toBe(false);
		await expect(page.getByTestId('dependency-graph-save')).toBeVisible();
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
