import { expect, test, type Page } from '@playwright/test';
import { mockWpJson } from './utils/mock-wpjson';
import { seedRuntimeConfig } from './utils/runtime-config';

const formSource = 'gravity_forms';
const formId = 123;
const mappingId = 'mapping-spam-failure';

const descriptor = {
	slug: formSource,
	label: 'Gravity Forms',
	is_active: true,
	lifecycles: {
		after_submission: {
			supported: true,
			label: 'After submission',
			native_hook: 'gform_after_submission',
			execution_mode: 'async',
			requires_ledger: false,
			unsupported_reason: null
		}
	},
	native_entry: { id: true, link: true, read: true, write: true },
	native_enrichment: {
		notes: true,
		status: true,
		spam: true,
		notification_controls: true,
		webhook_controls: true
	},
	ledger: {
		required_for_parity: false,
		enabled: false,
		settings_source: 'sentient_submission_ledger_settings',
		unavailable_reason: null
	}
};

const blockingDescriptor = {
	...descriptor,
	lifecycles: {
		validation: {
			supported: true,
			label: 'During validation',
			native_hook: 'gform_validation',
			execution_mode: 'blocking',
			requires_ledger: false,
			unsupported_reason: null
		}
	}
};

function mapping(settings: Record<string, unknown> = {}) {
	return {
		local_mapping_id: mappingId,
		form_id: formId,
		central_action_id: 'spam_detection_v1',
		action_name_label: 'Spam Detection',
		action_type_indicator: 'local_first',
		action_status: 'active',
		trigger_hooks: ['gform_after_submission'],
		is_action_enabled_for_form: true,
		settings
	};
}

const definition = {
	id: 'spam_detection_v1',
	label: 'Spam Detection',
	source: 'bundled',
	hooks: ['gform_after_submission'],
	base_credit_cost: 0,
	model_hint: 'openrouter/auto'
};

const form = {
	id: formId,
	title: 'Controlled spam failure fixture',
	adapter: formSource,
	adapter_name: 'Gravity Forms',
	settings: { enabled: true, actions: {} }
};

function deferred() {
	let resolve!: () => void;
	return {
		promise: new Promise<void>((next) => {
			resolve = next;
		}),
		resolve: () => resolve()
	};
}

async function seedEditor(
	page: Page,
	options: {
		canManage?: boolean;
		linkage?: ReturnType<typeof mapping>;
		descriptor?: typeof descriptor | typeof blockingDescriptor | null;
		definitions?: (typeof definition)[];
		status?: Record<string, unknown>;
		bootstrapError?:
			| { status?: number; body: unknown }
			| ((source: string, id: string | number) => { status?: number; body: unknown } | null);
		beforeNavigation?: () => Promise<void>;
		waitUntil?: 'domcontentloaded' | 'networkidle';
	} = {}
) {
	await seedRuntimeConfig(page, {
		currentUser: { id: 1, canManage: options.canManage ?? true }
	});
	await mockWpJson(page, {
		actions: {
			forms: { [formSource]: [form] },
			definitions: options.definitions ?? [definition],
			formsActions: [
				options.linkage ??
					mapping({ suppress_notifications_on_spam: true, suppress_webhooks_on_spam: true })
			],
			formSourceDescriptors: {
				[formSource]: options.descriptor === undefined ? descriptor : options.descriptor
			},
			formFields: [{ id: '1', label: 'Message', type: 'textarea' }],
			status: options.status,
			bootstrapError: options.bootstrapError
		}
	});
	await options.beforeNavigation?.();
	await page.goto('/#/actions/gravity_forms/123', {
		waitUntil: options.waitUntil ?? 'networkidle'
	});
}

async function openSpamAdvanced(page: Page) {
	const tableToggle = page.getByTestId('linked-actions-view-table');
	await expect(tableToggle).toBeVisible();
	await tableToggle.click();
	const table = page.getByTestId('form-actions-table');
	await expect(table).toBeVisible();
	const configure = table.locator('tbody tr').first().getByRole('button', { name: 'Configure' });
	await configure.click();
	const modal = page.getByTestId('mapping-config-modal');
	await expect(modal).toBeVisible();
	await modal.getByTestId('mapping-section-toggle-spam_advanced').click();
	await expect(modal.getByTestId('spam-failure-delivery-setting')).toBeVisible();
	return { configure, modal };
}

async function openSpamMapping(page: Page) {
	const tableToggle = page.getByTestId('linked-actions-view-table');
	await expect(tableToggle).toBeVisible();
	await tableToggle.click();
	const table = page.getByTestId('form-actions-table');
	await expect(table).toBeVisible();
	const configure = table.locator('tbody tr').first().getByRole('button', { name: 'Configure' });
	await configure.click();
	return { configure, modal: page.getByTestId('mapping-config-modal') };
}

test.describe('spam failure delivery mapping editor', () => {
	test.describe.configure({ timeout: 30_000 });

	test('waits for one PUT acknowledgment, retains a rejected draft, and reads back a successful retry', async ({
		page
	}) => {
		await seedEditor(page);
		const { modal } = await openSpamAdvanced(page);
		const allow = modal.getByLabel('Allow delivery without classification');
		await allow.check();

		let putCount = 0;
		let releaseFirstPut: (() => void) | null = null;
		await page.route(
			`**/wp-json/sentient-forms/v1/${formSource}/forms/${formId}/actions/${mappingId}`,
			async (route) => {
				if (route.request().method() !== 'PUT') return route.fallback();
				putCount += 1;
				if (putCount === 1) {
					await new Promise<void>((resolve) => {
						releaseFirstPut = resolve;
					});
					return route.fulfill({
						status: 500,
						contentType: 'application/json',
						body: JSON.stringify({ success: false, message: 'Controlled persistence rejection.' })
					});
				}
				return route.fallback();
			}
		);

		const save = modal.getByTestId('mapping-config-save');
		await save.click();
		await expect(save).toBeDisabled();
		await expect.poll(() => putCount).toBe(1);
		releaseFirstPut?.();
		await expect(modal.locator('p[role="alert"]')).toContainText('Your draft is kept');
		await expect(allow).toBeChecked();
		await expect(save).toBeFocused();

		await save.click();
		await expect(modal).toBeHidden();
		await expect.poll(() => putCount).toBe(2);
		const { modal: reopened } = await openSpamMapping(page);
		await reopened.getByTestId('mapping-section-toggle-spam_advanced').click();
		await expect(reopened.getByLabel('Allow delivery without classification')).toBeChecked();
	});

	test('restores stable title focus after Retry clears a global GET error inside the editor', async ({
		page
	}) => {
		let statusRequests = 0;
		await seedEditor(page, {
			beforeNavigation: async () => {
				await page.route(
					`**/wp-json/sentient-forms/v1/${formSource}/forms/${formId}/actions/status`,
					async (route) => {
						statusRequests += 1;
						if (statusRequests === 1) {
							return route.fulfill({
								status: 500,
								contentType: 'application/json',
								body: JSON.stringify({ success: false, message: 'Controlled status failure.' })
							});
						}
						return route.fulfill({
							status: 200,
							contentType: 'application/json',
							body: JSON.stringify({
								success: true,
								data: {
									status: 'unknown',
									message: null,
									entry_id: null,
									last_error_code: null,
									last_result: null,
									updated_at: null
								}
							})
						});
					}
				);
			}
		});
		const { modal } = await openSpamAdvanced(page);
		await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
		const retry = modal.getByRole('button', { name: 'Retry' });
		await expect(retry).toBeVisible();
		await retry.click();
		await expect(retry).toHaveCount(0);
		await expect.poll(() => statusRequests).toBe(2);
		await expect(modal.locator('#mapping-config-title')).toBeFocused();
	});

	test('keeps native radio, tooltip, dialog, and background focus behavior reachable', async ({
		page
	}) => {
		await seedEditor(page);
		const { configure, modal } = await openSpamAdvanced(page);
		const hold = modal.getByLabel('Hold configured delivery');
		const allow = modal.getByLabel('Allow delivery without classification');
		await hold.focus();
		await hold.press('ArrowDown');
		await expect(allow).toBeChecked();
		await allow.press('Shift+Tab');
		await expect(page.getByLabel('About classification failures')).toBeFocused();
		const graph = modal.getByTestId('mapping-config-open-graph');
		const save = modal.getByTestId('mapping-config-save');
		await graph.focus();
		await graph.press('Shift+Tab');
		await expect(save).toBeFocused();
		await page.keyboard.press('Escape');
		await expect(modal.getByRole('tooltip')).toHaveCount(0);
		await page.keyboard.press('Escape');
		await expect(modal).toBeHidden();
		await expect(configure).toBeFocused();
	});

	test('locks editing for permission, legacy, load, and busy states without changing applicability truth', async ({
		page
	}) => {
		await seedEditor(page, { canManage: false });
		let modal = (await openSpamAdvanced(page)).modal;
		await expect(modal.getByTestId('spam-failure-applicability')).toHaveText(
			'Applies to: notifications and Webhooks.'
		);
		await expect(modal.getByLabel('Hold configured delivery')).toBeDisabled();
		await expect(modal.getByTestId('mapping-config-open-graph')).toBeDisabled();

		await seedEditor(page, {
			descriptor: blockingDescriptor,
			linkage: { ...mapping(), trigger_hooks: ['gform_validation'] }
		});
		modal = (await openSpamAdvanced(page)).modal;
		await expect(modal.getByTestId('spam-failure-applicability')).toHaveText(
			'Applies to: notifications and Webhooks.'
		);
		await expect(modal.getByLabel('Hold configured delivery')).toBeEnabled();

		await seedEditor(page, {
			linkage: {
				...mapping({
					suppress_notifications_on_spam: false,
					suppress_webhooks_on_spam: false
				}),
				action_type_indicator: 'master'
			}
		});
		modal = (await openSpamAdvanced(page)).modal;
		await expect(modal.getByText('This mapping is read-only.')).toBeVisible();
		await expect(modal.getByTestId('spam-failure-applicability')).toHaveText(
			'This mapping has no configured delivery holds.'
		);
		await expect(modal.getByLabel('Hold configured delivery')).toBeDisabled();

		await seedEditor(page, { descriptor: null });
		modal = (await openSpamAdvanced(page)).modal;
		await expect(modal.getByTestId('spam-failure-applicability')).toHaveText(
			'Delivery hold availability is not confirmed.'
		);
		await expect(modal.getByLabel('Hold configured delivery')).toBeDisabled();
	});

	test('does not reopen after Close while inherited configuration is delayed', async ({ page }) => {
		const releaseInheritedConfig = deferred();
		let batchDefaultsCompleted = 0;
		let individualDefaultsCompleted = 0;
		let formConfigCompleted = 0;
		await seedEditor(page, {
			definitions: [],
			waitUntil: 'domcontentloaded',
			beforeNavigation: async () => {
				await page.route(/\/actions\/defaults\?ids=/, async (route) => {
					await releaseInheritedConfig.promise;
					await route.fallback();
					batchDefaultsCompleted += 1;
				});
				await page.route(
					`**/wp-json/sentient-forms/v1/actions/spam_detection_v1/defaults`,
					async (route) => {
						await releaseInheritedConfig.promise;
						await route.fallback();
						individualDefaultsCompleted += 1;
					}
				);
				await page.route(
					`**/wp-json/sentient-forms/v1/forms/${formSource}/${formId}/action-config/spam_detection_v1`,
					async (route) => {
						await releaseInheritedConfig.promise;
						await route.fallback();
						formConfigCompleted += 1;
					}
				);
			}
		});
		const { modal } = await openSpamMapping(page);
		await expect(modal.getByText('Loading saved settings…')).toBeVisible();
		await modal.getByTestId('mapping-config-close-header').click();
		await expect(modal).toBeHidden();
		releaseInheritedConfig.resolve();
		await expect.poll(() => batchDefaultsCompleted).toBeGreaterThan(0);
		await expect.poll(() => individualDefaultsCompleted).toBeGreaterThan(0);
		await expect.poll(() => formConfigCompleted).toBeGreaterThan(0);
		await expect(modal).toBeHidden();
	});

	test('keeps Spam advanced open when inherited configuration finishes loading', async ({
		page
	}) => {
		const releaseInheritedConfig = deferred();
		await seedEditor(page, {
			definitions: [],
			waitUntil: 'domcontentloaded',
			beforeNavigation: async () => {
				await page.route(/\/actions\/defaults\?ids=/, async (route) => {
					await releaseInheritedConfig.promise;
					return route.fallback();
				});
				await page.route(
					`**/wp-json/sentient-forms/v1/actions/spam_detection_v1/defaults`,
					async (route) => {
						await releaseInheritedConfig.promise;
						return route.fallback();
					}
				);
				await page.route(
					`**/wp-json/sentient-forms/v1/forms/${formSource}/${formId}/action-config/spam_detection_v1`,
					async (route) => {
						await releaseInheritedConfig.promise;
						return route.fallback();
					}
				);
			}
		});
		const { modal } = await openSpamMapping(page);
		const advanced = modal.getByTestId('mapping-section-toggle-spam_advanced');
		await expect(modal.getByText('Loading saved settings…')).toBeVisible();
		await advanced.click();
		await expect(advanced).toHaveAttribute('aria-expanded', 'true');

		releaseInheritedConfig.resolve();

		await expect(modal.getByText('Loading saved settings…')).toHaveCount(0);
		await expect(advanced).toHaveAttribute('aria-expanded', 'true');
		await expect(modal.getByTestId('spam-failure-delivery-setting')).toBeVisible();
	});
});
