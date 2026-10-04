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
		linkages?: ReturnType<typeof mapping>[];
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
			formsActions: options.linkages ?? [
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

	for (const graphStep of ['edit', 'Save', 'restore']) {
		test(`preserves a failure policy draft and graph bindings after graph ${graphStep}`, async ({
			page
		}) => {
			const upstreamId = 'mapping-upstream-spam';
			await seedEditor(page, {
				linkages: [
					mapping({
						suppress_notifications_on_spam: true,
						suppress_webhooks_on_spam: true,
						dependency_ids: [upstreamId],
						trigger_sources: {
							gform_after_submission: { type: 'mapping', mapping_id: upstreamId }
						}
					}),
					{ ...mapping(), local_mapping_id: upstreamId, action_name_label: 'Upstream spam check' }
				]
			});
			const { modal } = await openSpamAdvanced(page);
			await modal.getByLabel('Allow delivery without classification').check();
			await modal.getByTestId('mapping-config-open-graph').click();
			await page.getByTestId('dependency-graph-clear').click();
			if (graphStep === 'Save') {
				const graphSaved = page.waitForResponse(
					(response) =>
						response.url().endsWith(`/forms/${formId}/actions/${mappingId}`) &&
						response.request().method() === 'PUT'
				);
				await page.getByTestId('dependency-graph-save').click();
				const graphResponse = await graphSaved;
				expect(graphResponse.status()).toBe(200);
				expect(
					graphResponse.request().postDataJSON().settings.spam_failure_delivery_policy
				).toBeUndefined();
				await expect(page.getByTestId('dependency-graph-save')).toBeEnabled();
			}
			if (graphStep === 'restore') {
				const { modal: intermediate } = await openSpamAdvanced(page);
				await intermediate.getByTestId('mapping-config-open-graph').click();
				await page.getByRole('button', { name: 'Fit View', exact: true }).click();
				const source = page.locator(
					`[data-nodeid="${upstreamId}"][data-handleid="dependency-source"]`
				);
				const target = page.locator(
					`[data-nodeid="${mappingId}"][data-handleid="hook-root-target:after_submission"]`
				);
				await source.click();
				await target.click();
				await expect(
					page.getByRole('group', { name: `Edge from ${upstreamId} to ${mappingId}` })
				).toBeVisible();
			}

			const { modal: reopened } = await openSpamAdvanced(page);
			await expect(reopened.getByLabel('Allow delivery without classification')).toBeChecked();
			const save = reopened.getByTestId('mapping-config-save');
			await expect(save).toBeEnabled();
			const mappingSaved = page.waitForResponse(
				(response) =>
					response.url().endsWith(`/forms/${formId}/actions/${mappingId}`) &&
					response.request().method() === 'PUT'
			);
			await save.click();
			const mappingResponse = await mappingSaved;
			const settings = mappingResponse.request().postDataJSON().settings;
			expect(settings.spam_failure_delivery_policy).toBe('allow_delivery');
			if (graphStep === 'restore') {
				expect(settings.dependency_ids).toEqual([upstreamId]);
				expect(settings.trigger_sources).toEqual({
					after_submission: { type: 'mapping', mapping_id: upstreamId }
				});
			} else {
				expect(settings.dependency_ids).toBeUndefined();
				expect(settings.trigger_sources).toEqual({ after_submission: { type: 'hook_root' } });
			}
			await expect(reopened).toBeHidden();
			await page.reload({ waitUntil: 'networkidle' });
			const { modal: readback } = await openSpamAdvanced(page);
			await expect(readback.getByLabel('Allow delivery without classification')).toBeChecked();
		});
	}

	test('initializes saved settings after Close during inherited loading', async ({ page }) => {
		const inherited = deferred();
		await seedEditor(page, {
			linkage: mapping({ suppress_notifications_on_spam: true, suppress_webhooks_on_spam: true }),
			waitUntil: 'domcontentloaded',
			beforeNavigation: async () => {
				await page.route('**/action-config/spam_detection_v1', async route => {
					await inherited.promise;
					await route.fallback();
				});
			}
		});
		const { modal } = await openSpamMapping(page);
		await expect(modal.getByText('Loading saved settings…')).toBeVisible();
		await modal.getByRole('button', { name: 'Close', exact: true }).first().click();
		await expect(modal).toBeHidden();
		const loaded = page.waitForResponse(response => response.url().endsWith('/action-config/spam_detection_v1'));
		inherited.resolve();
		await loaded;
		const { modal: reopened } = await openSpamAdvanced(page);
		await expect(reopened.getByText('Loading saved settings…')).toHaveCount(0);
		await expect(reopened.getByLabel(/Confidence Threshold/)).toHaveValue('0.8');
		await expect(reopened.getByLabel('Hold configured delivery')).toBeChecked();
	});

	test('keeps loaded mapping editable when execution status refresh fails', async ({ page }) => {
		await seedEditor(page, {
			beforeNavigation: async () => {
				await page.route(`**/wp-json/sentient-forms/v1/${formSource}/forms/${formId}/actions/status`, route => route.fulfill({
					status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Controlled status failure.' })
				}));
			}
		});
		const { modal } = await openSpamAdvanced(page);
		const failed = page.waitForResponse(response => response.url().endsWith('/actions/status'));
		await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
		await failed;
		await expect(modal.getByLabel('Allow delivery without classification')).toBeEnabled();
		await expect(modal.getByTestId('mapping-config-open-graph')).toBeEnabled();
		await modal.getByLabel('Allow delivery without classification').check();
		await expect(modal.getByTestId('mapping-config-save')).toBeEnabled();
	});

	test('restores stable title focus after Retry clears a mapping GET error', async ({ page }) => {
		let loads = 0;
		await seedEditor(page, {
			bootstrapError: () => ++loads === 2 ? { status: 500, body: { success: false, message: 'Controlled mapping load failure.' } } : null
		});
		const { modal } = await openSpamAdvanced(page);
		await modal.getByLabel('Allow delivery without classification').check();
		await modal.getByRole('button', { name: 'Close', exact: true }).first().click();
		await page.getByRole('button', { name: 'Refresh', exact: true }).first().click();
		const { modal: reopened } = await openSpamAdvanced(page);
		const retry = reopened.getByRole('button', { name: 'Retry' });
		await expect(retry).toBeVisible();
		await expect(reopened.getByLabel('Allow delivery without classification')).toBeDisabled();
		await retry.click();
		await expect(retry).toHaveCount(0);
		await expect.poll(() => loads).toBe(3);
		await expect(reopened.locator('#mapping-config-title')).toBeFocused();
		await expect(reopened.getByLabel('Allow delivery without classification')).toBeChecked();
		await expect(reopened.getByLabel('Allow delivery without classification')).toBeEnabled();
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
		await page.getByLabel('About classification failures').focus();
		await expect(modal.getByRole('tooltip')).toBeVisible();
		await page.keyboard.press('Escape');
		await expect(modal.getByRole('tooltip')).toHaveCount(0);
		await expect(modal).toBeVisible();
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
			linkage: {
				...mapping({ suppress_notifications_on_spam: true, suppress_webhooks_on_spam: true }),
				trigger_hooks: ['gform_validation']
			}
		});
		modal = (await openSpamAdvanced(page)).modal;
		await expect(modal.getByTestId('spam-failure-applicability')).toHaveText(
			'Applies to: notifications and Webhooks.'
		);
		await expect(modal.getByLabel('Hold configured delivery')).toBeEnabled();

		await seedEditor(page, {
			linkage: mapping({
				async: false,
				suppress_notifications_on_spam: true,
				suppress_webhooks_on_spam: true
			})
		});
		modal = (await openSpamAdvanced(page)).modal;
		await expect(modal.getByTestId('spam-failure-applicability')).toHaveText(
			'This mapping has no configured delivery holds.'
		);
		await expect(modal.getByLabel('Hold configured delivery')).toBeDisabled();

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
