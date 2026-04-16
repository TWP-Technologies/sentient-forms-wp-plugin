import { expect, test, type Locator } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';
import { mockWpJson } from './utils/mock-wpjson';
import { appNavLink, expectAppUrl } from './utils/app-navigation';

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

async function ensureDependencyGraphVisible(page: Parameters<typeof test>[0]['page']) {
	const graphCanvas = page.getByTestId('dependency-graph-canvas');
	const graphToggle = page.getByTestId('linked-actions-view-graph');
	const canvasVisible =
		(await graphCanvas.count()) > 0 &&
		(await graphCanvas
			.first()
			.isVisible()
			.catch(() => false));

	if (!canvasVisible && (await graphToggle.count()) > 0) {
		await graphToggle.first().click();
	}

	await expect(graphCanvas).toBeVisible();
	return graphCanvas;
}

async function saveMappingConfigModal(page: Parameters<typeof test>[0]['page']) {
	const modal = page.getByTestId('mapping-config-modal');
	await modal
		.locator('footer')
		.getByRole('button', { name: 'Save mapping' })
		.click();
}

async function ensureMappingSectionExpanded(
	page: Parameters<typeof test>[0]['page'],
	sectionId: string
) {
	const modal = page.getByTestId('mapping-config-modal');
	const toggle = modal.getByTestId(`mapping-section-toggle-${sectionId}`);
	await expect(toggle).toBeVisible();
	const isExpanded = (await toggle.getAttribute('aria-expanded')) === 'true';
	if (!isExpanded) {
		await toggle.click();
	}
}

async function connectHandlesByMouse(
	page: Parameters<typeof test>[0]['page'],
	sourceSelector: string,
	targetSelector: string,
	attempt = 0
) {
	const viewport = page.getByTestId('dependency-graph-canvas').locator('.svelte-flow__viewport');
	const source = viewport.locator(sourceSelector).first();
	const target = viewport.locator(targetSelector).first();
	await expect(source).toBeVisible();
	await expect(target).toBeVisible();
	await source.scrollIntoViewIfNeeded();
	await target.scrollIntoViewIfNeeded();
	await source.hover();

	if (process.env.SF_E2E_DEBUG_HANDLES === '1') {
		const sourceCount = await viewport.locator(sourceSelector).count();
		const targetCount = await viewport.locator(targetSelector).count();
		const sourceMeta = await source.evaluate((node) => ({
			className: node.className,
			dataset: { ...node.dataset },
			outerHTML: node.outerHTML
		}));
		const targetMeta = await target.evaluate((node) => ({
			className: node.className,
			dataset: { ...node.dataset },
			outerHTML: node.outerHTML
		}));
		console.log('[connectHandlesByMouse] selectors', {
			sourceSelector,
			targetSelector,
			sourceCount,
			targetCount,
			sourceMeta,
			targetMeta
		});
	}

	const sourceBox = await source.boundingBox();
	const targetBox = await target.boundingBox();
	if (!sourceBox || !targetBox) {
		throw new Error('Could not resolve graph handle positions for drag connection.');
	}

	const sourceX = sourceBox.x + sourceBox.width / 2;
	const sourceY = sourceBox.y + sourceBox.height / 2;
	const targetOffsets = [
		{ x: 0, y: 0 },
		{ x: -4, y: -2 },
		{ x: -5, y: 3 },
		{ x: 4, y: -3 }
	];
	const selectedOffset = targetOffsets[attempt % targetOffsets.length] ?? { x: 0, y: 0 };
	const targetCenterX = targetBox.x + targetBox.width / 2;
	const targetCenterY = targetBox.y + targetBox.height / 2;
	const targetX = targetCenterX + selectedOffset.x;
	const targetY = targetCenterY + selectedOffset.y;

	if (process.env.SF_E2E_DEBUG_HANDLES === '1') {
		console.log('[connectHandlesByMouse] geometry', {
			attempt,
			sourceBox,
			targetBox,
			sourceX,
			sourceY,
			targetX,
			targetY
		});
	}

	if (attempt % 2 === 0) {
		await source.dragTo(target, {
			force: true,
			sourcePosition: {
				x: sourceBox.width / 2,
				y: sourceBox.height / 2
			},
			targetPosition: {
				x: Math.min(Math.max(targetBox.width / 2 + selectedOffset.x, 2), targetBox.width - 2),
				y: Math.min(Math.max(targetBox.height / 2 + selectedOffset.y, 2), targetBox.height - 2)
			},
			timeout: 5_000
		});
		return;
	}

	await page.mouse.move(sourceX, sourceY);
	await page.mouse.down();
	await page.mouse.move(sourceX + 8, sourceY + 6, { steps: 6 });
	await page.mouse.move(targetX, targetY, { steps: 32 });
	await page.mouse.up();
}

async function connectHandlesByClick(
	page: Parameters<typeof test>[0]['page'],
	sourceSelector: string,
	targetSelector: string
) {
	const viewport = page.getByTestId('dependency-graph-canvas').locator('.svelte-flow__viewport');
	const source = viewport.locator(sourceSelector).first();
	const target = viewport.locator(targetSelector).first();
	await expect(source).toBeVisible();
	await expect(target).toBeVisible();
	await source.scrollIntoViewIfNeeded();
	await target.scrollIntoViewIfNeeded();
	await source.click({ force: true });
	await target.click({ force: true });
}

async function getVisibleFeedbackText(
	page: Parameters<typeof test>[0]['page'],
	feedback: Locator
): Promise<string | null> {
	if ((await feedback.count()) === 0) return null;
	const firstFeedback = feedback.first();
	const isVisible = await firstFeedback.isVisible().catch(() => false);
	if (!isVisible) return null;
	return (await firstFeedback.textContent())?.trim() ?? '';
}

async function waitForConnectionOutcome(
	page: Parameters<typeof test>[0]['page'],
	baselineFeedbackText: string | null,
	timeoutMs = 1200
): Promise<{
	dirtyVisible: boolean;
	feedbackVisible: boolean;
	feedbackText: string;
}> {
	const dirtyBar = page.getByTestId('dependency-graph-dirty-bar');
	const feedback = page.getByTestId('dependency-graph-connection-feedback');
	const start = Date.now();
	let latestFeedbackText = baselineFeedbackText ?? '';

	while (Date.now() - start < timeoutMs) {
		const dirtyVisible =
			(await dirtyBar.count()) > 0 &&
			(await dirtyBar
				.first()
				.isVisible()
				.catch(() => false));
		if (dirtyVisible) {
			return {
				dirtyVisible: true,
				feedbackVisible: false,
				feedbackText: latestFeedbackText
			};
		}

		const feedbackText = await getVisibleFeedbackText(page, feedback);
		if (feedbackText) {
			latestFeedbackText = feedbackText;
			if (feedbackText !== baselineFeedbackText) {
				return {
					dirtyVisible: false,
					feedbackVisible: true,
					feedbackText
				};
			}
		}
		await page.waitForTimeout(75);
	}

	const finalFeedbackText = await getVisibleFeedbackText(page, feedback);
	if (finalFeedbackText && finalFeedbackText !== baselineFeedbackText) {
		return {
			dirtyVisible: false,
			feedbackVisible: true,
			feedbackText: finalFeedbackText
		};
	}
	return {
		dirtyVisible: false,
		feedbackVisible: false,
		feedbackText: finalFeedbackText ?? latestFeedbackText
	};
}

async function connectHandlesAndAssert(
	page: Parameters<typeof test>[0]['page'],
	sourceSelector: string,
	targetSelector: string,
	options: {
		expectRejected?: boolean;
		rejectedMessage?: RegExp;
		expectDirtyBar?: boolean;
		maxAttempts?: number;
		allowNoFeedbackOnFailure?: boolean;
	} = {}
) {
	const maxAttempts = Math.max(1, options.maxAttempts ?? 3);
	const dirtyBar = page.getByTestId('dependency-graph-dirty-bar');
	const feedback = page.getByTestId('dependency-graph-connection-feedback');
	let lastFeedbackText = '';

	for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
		const baselineFeedbackText = await getVisibleFeedbackText(page, feedback);
		await connectHandlesByMouse(page, sourceSelector, targetSelector, attempt - 1);
		let outcome = await waitForConnectionOutcome(page, baselineFeedbackText);
		if (
			!outcome.dirtyVisible &&
			(!outcome.feedbackVisible || /connection canceled/i.test(outcome.feedbackText))
		) {
			await connectHandlesByClick(page, sourceSelector, targetSelector);
			outcome = await waitForConnectionOutcome(page, outcome.feedbackText || baselineFeedbackText);
		}
		const { dirtyVisible, feedbackVisible, feedbackText } = outcome;
		if (feedbackText) {
			lastFeedbackText = feedbackText;
		}

		if (options.expectRejected) {
			if (feedbackVisible) {
				if (/connection canceled/i.test(feedbackText)) {
					continue;
				}
				if (options.rejectedMessage) {
					await expect(feedback).toContainText(options.rejectedMessage);
				}
				await expect(dirtyBar).toHaveCount(0);
				return;
			}

			if (dirtyVisible) {
				throw new Error(
					'Expected connection to be rejected, but graph marked unsaved dependency changes.'
				);
			}

			continue;
		}

		if (dirtyVisible) {
			if (options.expectDirtyBar ?? true) {
				await expect(dirtyBar).toBeVisible();
			}
			return;
		}

		if (feedbackVisible && !/connection canceled/i.test(lastFeedbackText)) {
			throw new Error(
				`Expected connection to succeed, but graph rejected it: ${lastFeedbackText || 'Unknown feedback'}`
			);
		}
	}

	if (options.expectRejected) {
		if (options.allowNoFeedbackOnFailure) {
			await expect(dirtyBar).toHaveCount(0);
			return;
		}
		throw new Error(
			`Expected connection rejection feedback after ${maxAttempts} attempt(s), but none appeared.`
		);
	}

	throw new Error(
		`Connection did not create unsaved changes after ${maxAttempts} attempt(s). Last feedback: ${lastFeedbackText || 'none'}`
	);
}

type Box = { x: number; y: number; width: number; height: number };

function assertBoxPositionStable(before: Box, after: Box, tolerance = 4): void {
	expect(Math.abs(after.x - before.x)).toBeLessThanOrEqual(tolerance);
	expect(Math.abs(after.y - before.y)).toBeLessThanOrEqual(tolerance);
}

function assertBoxMoved(before: Box, after: Box, minDelta = 12): void {
	const deltaX = Math.abs(after.x - before.x);
	const deltaY = Math.abs(after.y - before.y);
	expect(Math.max(deltaX, deltaY)).toBeGreaterThanOrEqual(minDelta);
}

function toRelativeBox(box: Box, container: Box): Box {
	return {
		x: box.x - container.x,
		y: box.y - container.y,
		width: box.width,
		height: box.height
	};
}

async function dragNodeCardByMouse(
	page: Parameters<typeof test>[0]['page'],
	nodeId: string,
	deltaX: number,
	deltaY: number
) {
	const node = page.locator(`.svelte-flow__node[data-id="${nodeId}"]`).first();
	await expect(node).toBeVisible();
	await node.scrollIntoViewIfNeeded();

	const box = await node.boundingBox();
	if (!box) {
		throw new Error(`Could not resolve graph node bounds for ${nodeId}.`);
	}

	const startX = box.x + box.width * 0.5;
	const startY = box.y + Math.min(30, Math.max(18, box.height * 0.15));
	await page.mouse.move(startX, startY);
	await page.mouse.down();
	await page.mouse.move(startX + deltaX, startY + deltaY, { steps: 24 });
	await page.mouse.up();
	await page.waitForTimeout(60);
}

test.describe('Actions admin flows', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});
	});

	test('does not continuously refetch shell license health on the actions route', async ({
		page
	}) => {
		const licenseRequests: string[] = [];
		const creditRequests: string[] = [];

		await seedRuntimeConfig(page, {
			license: {
				status: 'active',
				proxyKeyPresent: true,
				tier: {
					code: 'pro',
					display_name: 'Pro',
					monthly_credit_quota: 1000
				},
				lastSynced: '2030-01-05T10:00:00Z',
				licenseId: 'lic-1',
				siteId: 'site-1'
			}
		});

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance: {
					current_balance: 875,
					tier: {
						code: 'pro',
						display_name: 'Pro',
						monthly_credit_quota: 1000
					}
				}
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.route('**/wp-json/sentient-forms/v1/license', (route) => {
			licenseRequests.push(route.request().url());
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'active',
					license_key_masked: 'LIC-****-****-1234',
					proxy_key_present: true,
					tier: {
						code: 'pro',
						display_name: 'Pro',
						monthly_credit_quota: 1000
					},
					expires_at: '2030-01-01T00:00:00Z',
					last_synced: '2030-01-05T10:00:00Z',
					license_id: 'lic-1',
					site_id: 'site-1',
					site_url: 'https://example.test'
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/credits/balance**', (route) => {
			creditRequests.push(route.request().url());
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					current_balance: 875,
					tier: {
						code: 'pro',
						display_name: 'Pro',
						monthly_credit_quota: 1000
					}
				})
			});
		});

		await page.goto('/#/actions');
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await page.waitForTimeout(2000);

		expect(licenseRequests.length).toBeLessThanOrEqual(2);
		expect(creditRequests.length).toBeLessThanOrEqual(2);
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

		await expectAppUrl(page, '/actions/gravity_forms/123');
		await expect(page.getByText('Action library')).toBeVisible();
		const definitionsCard = page.getByTestId('action-definitions-card');
		await expect(definitionsCard.getByText('Spam check', { exact: true })).toBeVisible();
	});

	test('shows overview mapping count from linked form actions', async ({ page }) => {
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

		const card = page.getByTestId(`actions-form-card-${formId}`);
		await expect(card.getByText('Contact us')).toBeVisible();
		await expect(card.getByText('1 action')).toBeVisible();
		await expect(card.getByText('No actions configured')).toHaveCount(0);
	});

	test('uses the runtime form disable flag for overview automation status', async ({ page }) => {
		const runtimeEnabledForms = [
			{
				...baseForms[0],
				settings: {
					...baseForms[0].settings,
					enabled: false,
					sf_disabled: false
				}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: runtimeEnabledForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const card = page.getByTestId(`actions-form-card-${formId}`);
		await expect(card.getByText('Automation enabled')).toBeVisible();
		await expect(card.getByText('Automation paused')).toHaveCount(0);
	});

	test('treats a missing runtime disable flag as enabled on the overview', async ({ page }) => {
		const legacyConfiguredForms = [
			{
				...baseForms[0],
				settings: {
					...baseForms[0].settings,
					enabled: false
				}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: legacyConfiguredForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const card = page.getByTestId(`actions-form-card-${formId}`);
		await expect(card.getByText('Automation enabled')).toBeVisible();
		await expect(card.getByText('Automation paused')).toHaveCount(0);
	});

	test('keeps sidebar active state aligned for nested action routes', async ({ page }) => {
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

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await expect(appNavLink(page, '/actions')).toHaveClass(/sf-bg-slate-200/);
		await expect(appNavLink(page, '/actions')).toHaveClass(/sf-text-slate-900/);
		await expect(appNavLink(page, '/actions/custom')).not.toHaveClass(/sf-bg-slate-200/);

		await page.goto('/actions/custom/new', { waitUntil: 'networkidle' });
		await expect(page.locator('main > section > header h2', { hasText: 'Create Custom Action' })).toBeVisible();
		await expect(appNavLink(page, '/actions/custom')).toHaveClass(/sf-bg-slate-200/);
		await expect(appNavLink(page, '/actions/custom')).toHaveClass(/sf-text-slate-900/);
	});

	test('persists the form disabled state across a reload in preview mode', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance,
				disableState: {
					sf_disabled: false,
					global_disabled: false,
					provider_disabled: false,
					effective_disabled: false
				}
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		const formToggle = page.getByRole('switch').first();
		await expect(formToggle).toHaveAttribute('aria-checked', 'true');
		await expect(
			page.getByText('Sentient Forms execution is paused for this form', { exact: false })
		).toHaveCount(0);

		const disableResponse = page.waitForResponse(
			(response) =>
				response.request().method() === 'PUT' &&
				/forms\/\d+\/actions\/disable$/.test(response.url()) &&
				response.status() === 200,
			{ timeout: 15_000 }
		);

		await formToggle.click();
		await disableResponse;
		await expect(formToggle).toHaveAttribute('aria-checked', 'false');
		await expect(page.getByText('Sentient Forms execution is paused for this form')).toBeVisible();

		await page.reload({ waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await expect(page.getByRole('switch').first()).toHaveAttribute('aria-checked', 'false');
		await expect(page.getByText('Sentient Forms execution is paused for this form')).toBeVisible();
	});

	test('opens spam defaults modal with guidance expanded by default from actions page', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam detection',
						source: 'cps',
						hooks: ['gform_validation'],
						base_credit_cost: 2,
						model_hint: 'gemini-1.5-flash'
					}
				],
				status: statusUnknown,
				creditBalance,
				actionDefaultsById: {
					spam_detection_v1: {
						include_site_context: 'always',
						spam_positive_examples: ['Known customer request'],
						spam_negative_examples: ['Bulk SEO outreach']
					}
				}
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByTestId('action-defaults-button-spam_detection_v1').click();

		const modal = page.getByTestId('action-defaults-modal');
		await expect(modal).toBeVisible();
		await expect(modal.getByRole('button', { name: /Classification Guidance/i })).toBeVisible();
		await expect(modal.locator('#new-positive')).toBeVisible();
		await expect(modal.getByText('Known customer request')).toBeVisible();
		await expect(modal.getByText('Bulk SEO outreach')).toBeVisible();
		await expect(modal.locator('#action-level-context')).toHaveValue('always');
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

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
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

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const customActionsTab = page.getByRole('button', { name: 'Custom actions' });
		await expect(customActionsTab).toBeEnabled();
		await customActionsTab.click();

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

	test('creates a mapping with an upstream trigger source from the drawer', async ({ page }) => {
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

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();

		const dependencyOption = drawer.locator('label', { hasText: 'ID: map-1' }).first();
		await dependencyOption.locator('input[type="radio"]').check();
		await expect(dependencyOption.getByText('Spam check')).toBeVisible();

		const createReq = page.waitForRequest(/forms\/\d+\/actions$/);
		const createRes = page.waitForResponse(/forms\/\d+\/actions$/);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		const request = await createReq;
		await createRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(settings.dependency_ids).toEqual(['map-1']);
		expect((settings.trigger_sources as Record<string, { type?: string; mapping_id?: string }>)?.gform_validation?.type).toBe(
			'mapping'
		);
		expect(
			(settings.trigger_sources as Record<string, { type?: string; mapping_id?: string }>)
				?.gform_validation?.mapping_id
		).toBe('map-1');
	});

	test('allows validation upstream trigger source for after-submission mapping in drawer', async ({
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

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.getByRole('heading', { name: 'Actions' }).waitFor();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();

		// Switch to after-submission template hook preset.
		await drawer.getByRole('radio', { name: /Summarize/i }).check();
		await expect(drawer.getByText('Triggered by action (optional)')).toBeVisible();

		// Validation-only map-1 should still be eligible as an upstream trigger source.
		const dependencyOption = drawer.locator('label', { hasText: 'ID: map-1' }).first();
		await expect(dependencyOption).toBeVisible();
		await dependencyOption.locator('input[type="radio"]').check();

		const createReq = page.waitForRequest(/forms\/\d+\/actions$/);
		const createRes = page.waitForResponse(/forms\/\d+\/actions$/);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		const request = await createReq;
		await createRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(payload.trigger_hooks).toEqual(['gform_after_submission']);
		expect(settings.dependency_ids).toEqual(['map-1']);
		expect(
			(settings.trigger_sources as Record<string, { type?: string; mapping_id?: string }>)
				?.gform_after_submission?.type
		).toBe('mapping');
		expect(
			(settings.trigger_sources as Record<string, { type?: string; mapping_id?: string }>)
				?.gform_after_submission?.mapping_id
		).toBe('map-1');
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

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const form = page.getByTestId('link-action-form');
		await expect(form).toBeVisible({ timeout: 10_000 });
		await expect(page.getByPlaceholder('Search by name or id')).toBeVisible();
		await expect(form.getByText('Spam check', { exact: true })).toBeVisible();

		// Switch to custom actions tab and ensure the sample action is shown
		const customActionsTab = page.getByRole('button', { name: 'Custom actions' });
		await expect(customActionsTab).toBeEnabled();
		await customActionsTab.click();
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
		await ensureMappingSectionExpanded(page, 'conditions');

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
		await ensureMappingSectionExpanded(page, 'conditions');

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
		await connectHandlesAndAssert(page, sourceHandle, targetHandle);
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

	test('shows upstream spam-gate advisory for dependent downstream mappings only', async ({
		page
	}) => {
		const spamGateAdvisoryText =
			'Spam-aware downstream gating now belongs on the upstream spam action.';
		const definitions = [
			{
				id: 'spam_detection_v1',
				label: 'Spam detection',
				source: 'cps',
				hooks: ['gform_validation', 'gform_after_submission'],
				base_credit_cost: 10,
				model_hint: 'gemini-1.5-flash'
			},
			{
				id: 'entry_summary_v1',
				label: 'Entry summary',
				source: 'cps',
				hooks: ['gform_validation', 'gform_after_submission'],
				base_credit_cost: 8,
				model_hint: 'gemini-1.5-pro'
			},
			{
				id: 'content_validation_v1',
				label: 'Content validation',
				source: 'cps',
				hooks: ['gform_validation', 'gform_after_submission'],
				base_credit_cost: 8,
				model_hint: 'gemini-1.5-pro'
			}
		];
		const linkages = [
			{
				local_mapping_id: 'map-spam-validation',
				central_action_id: 'spam_detection_v1',
				action_type_indicator: 'master',
				action_name_label: 'Validation spam gate',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-validation-gated',
				central_action_id: 'content_validation_v1',
				action_type_indicator: 'master',
				action_name_label: 'Validation after spam gate',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map-spam-validation'],
					trigger_sources: {
						gform_validation: { type: 'mapping', mapping_id: 'map-spam-validation' }
					}
				}
			},
			{
				local_mapping_id: 'map-spam',
				central_action_id: 'spam_detection_v1',
				action_type_indicator: 'master',
				action_name_label: 'Spam gate',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-summary-gated',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				action_name_label: 'Summary after spam gate',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map-spam'],
					trigger_sources: {
						gform_after_submission: { type: 'mapping', mapping_id: 'map-spam' }
					}
				}
			},
			{
				local_mapping_id: 'map-mixed-spam-gated',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				action_name_label: 'Mixed hook spam gate',
				trigger_hooks: ['gform_validation', 'gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map-spam-validation'],
					trigger_sources: {
						gform_validation: { type: 'mapping', mapping_id: 'map-spam-validation' },
						gform_after_submission: { type: 'hook_root' }
					}
				}
			},
			{
				local_mapping_id: 'map-summary-root',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				action_name_label: 'Autonomous summary',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {
					trigger_sources: {
						gform_after_submission: { type: 'hook_root' }
					}
				}
			},
			{
				local_mapping_id: 'map-parent-summary',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				action_name_label: 'Summary parent',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-summary-from-summary',
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				action_name_label: 'Summary from summary parent',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map-parent-summary'],
					trigger_sources: {
						gform_after_submission: { type: 'mapping', mapping_id: 'map-parent-summary' }
					}
				}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.getByRole('heading', { name: 'Actions' }).waitFor();
		const table = await openLinkedActionsTable(page);

		await table
			.locator('tbody tr')
			.filter({ hasText: 'Validation after spam gate' })
			.getByRole('button', { name: 'Configure' })
			.first()
			.click();
		let modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByText(spamGateAdvisoryText)).toBeVisible();
		await modal.getByTestId('mapping-config-close-header').click();

		await table
			.locator('tbody tr')
			.filter({ hasText: 'Summary after spam gate' })
			.getByRole('button', { name: 'Configure' })
			.first()
			.click();
		modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByText(spamGateAdvisoryText)).toBeVisible();
		await modal.getByTestId('mapping-config-make-autonomous').click();
		await expect(modal.getByText(spamGateAdvisoryText)).toHaveCount(0);
		await modal.getByTestId('mapping-config-close-header').click();

		await table
			.locator('tbody tr')
			.filter({ hasText: 'Mixed hook spam gate' })
			.getByRole('button', { name: 'Configure' })
			.first()
			.click();
		modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByText(spamGateAdvisoryText)).toBeVisible();
		await modal.getByTestId('mapping-config-close-header').click();

		await table
			.locator('tbody tr')
			.filter({ hasText: 'Summary from summary parent' })
			.getByRole('button', { name: 'Configure' })
			.first()
			.click();
		modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByText(spamGateAdvisoryText)).toHaveCount(0);
	});

	test('persists upstream skip_downstream_on_spam for spam mappings', async ({ page }) => {
		const definitions = [
			{
				id: 'spam_detection_v1',
				label: 'Spam detection',
				source: 'cps',
				hooks: ['gform_validation', 'gform_after_submission'],
				base_credit_cost: 10,
				model_hint: 'gemini-1.5-flash'
			},
			{
				id: 'content_validation_v1',
				label: 'Content validation',
				source: 'cps',
				hooks: ['gform_validation'],
				base_credit_cost: 8,
				model_hint: 'gemini-1.5-pro'
			}
		];
		const linkages = [
			{
				local_mapping_id: 'map-spam',
				central_action_id: 'spam_detection_v1',
				action_type_indicator: 'master',
				action_name_label: 'Spam gate',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-summary',
				central_action_id: 'content_validation_v1',
				action_type_indicator: 'master',
				action_name_label: 'Content validation',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {
					dependency_ids: ['map-spam'],
					trigger_sources: {
						gform_validation: { type: 'mapping', mapping_id: 'map-spam' }
					}
				}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.getByRole('heading', { name: 'Actions' }).waitFor();
		const table = await openLinkedActionsTable(page);
		await table
			.locator('tbody tr')
			.filter({ hasText: 'Spam gate' })
			.getByRole('button', { name: 'Configure' })
			.first()
			.click();

		const modal = page.getByTestId('mapping-config-modal');
		await modal.getByTestId('mapping-section-toggle-spam_advanced').click();
		await modal.getByLabel('Downstream spam gate').selectOption('enabled');

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-spam$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-spam$/, { timeout: 15_000 });
		await saveMappingConfigModal(page);

		const request = await updateReq;
		await updateRes;
		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, any>;

		expect(settings.skip_downstream_on_spam).toBe(true);
		expect(settings.skip_on_upstream_spam).toBeUndefined();
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
		await connectHandlesAndAssert(page, sourceHandle, targetHandle);
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
		await connectHandlesAndAssert(page, sourceHandle, targetHandle);

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
		const targetHandle =
			'[data-nodeid=\"map-2\"][data-handleid=\"hook-root-target:gform_validation\"]';
		await connectHandlesAndAssert(page, sourceHandle, targetHandle);
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
		await connectHandlesAndAssert(page, sourceHandle, targetHandle);

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

	test('blocks ambiguous generic-handle dependency connections with feedback', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-source',
				central_action_id: 'content-quality',
				action_type_indicator: 'master',
				action_name_label: 'Source Dual Hook',
				trigger_hooks: ['gform_validation', 'gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-target',
				central_action_id: 'entry-summary',
				action_type_indicator: 'master',
				action_name_label: 'Target Dual Hook',
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
		const targetRow = table.locator('tbody tr').filter({ hasText: 'Target Dual Hook' });
		await targetRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		const sourceHandle = '[data-nodeid="map-source"][data-handleid="dependency-source"]';
		const targetHandle = '[data-nodeid="map-target"][data-handleid="dependency-target"]';
		await connectHandlesAndAssert(page, sourceHandle, targetHandle, {
			expectRejected: true,
			rejectedMessage: /ambiguous|hook-specific/i,
			allowNoFeedbackOnFailure: true
		});
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
		await connectHandlesAndAssert(page, rootSource, rootTarget);

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
		await connectHandlesAndAssert(page, rootSource, rootTarget, {
			expectRejected: true,
			rejectedMessage: /already autonomous/i
		});
	});

	test('removing a root edge keeps graph mounted and surfaces invalid trigger state', async ({
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
		const pageErrors: string[] = [];
		page.on('pageerror', (err) => pageErrors.push(err.message));

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
		await ensureDependencyGraphVisible(page);

		const rootEdge = page.getByRole('group', {
			name: /Edge from __hook_root__:gform_validation to map-1/i
		});
		await expect(rootEdge).toBeVisible();
		await rootEdge.hover({ force: true });
		await rootEdge.click({ force: true });
		const dirtyBar = page.getByTestId('dependency-graph-dirty-bar');
		if (
			(await dirtyBar.count()) === 0 ||
			!(await dirtyBar
				.first()
				.isVisible()
				.catch(() => false))
		) {
			await rootEdge.dispatchEvent('click');
		}

		const feedback = page.getByTestId('dependency-graph-connection-feedback');
		if ((await feedback.count()) > 0) {
			const firstFeedback = feedback.first();
			const feedbackVisible = await firstFeedback.isVisible().catch(() => false);
			if (feedbackVisible) {
				await expect(firstFeedback).toContainText(/Removed dependency link/i);
			}
		}
		await expect(dirtyBar).toBeVisible();
		await expect(page.getByTestId('dependency-graph-invalid-bar')).toContainText(
			/missing an upstream trigger source/i
		);
		await expect(page.getByTestId('dependency-graph-save')).toBeDisabled();
		await expect(page.getByText('Policy diagnostics')).toBeVisible();
		await expect(page.getByText(/no trigger source bound/i)).toBeVisible();
		expect(pageErrors, 'removing an edge should not trigger runtime errors').toEqual([]);
	});

	test('hovering a removable edge tints arrowhead and line with the same color', async ({
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
		await ensureDependencyGraphVisible(page);

		const edgeGroup = page.getByRole('group', { name: /Edge from map-1 to map-2/i });
		await expect(edgeGroup).toBeVisible();
		const edgePath = edgeGroup.locator('.svelte-flow__edge-path').first();
		const edgeInteractionPath = edgeGroup.locator('.svelte-flow__edge-interaction').first();
		await expect(edgePath).toBeVisible();
		await expect(edgeInteractionPath).toBeVisible();

		async function readEdgeColors() {
			return edgePath.evaluate((node) => {
				const path = node as SVGPathElement;
				const markerValue = path.getAttribute('marker-end') ?? '';
				return {
					pathStroke: getComputedStyle(path).stroke,
					markerValue
				};
			});
		}

		const baselineColors = await readEdgeColors();
		expect(baselineColors.pathStroke).toMatch(/rgb\(/);
		expect(baselineColors.markerValue).toContain('url(');
		expect(baselineColors.pathStroke).not.toBe('rgb(0, 0, 0)');

		await edgeGroup.evaluate((element) => {
			element.dispatchEvent(new PointerEvent('pointerenter', { cancelable: true }));
		});
		const hoverColors = await readEdgeColors();
		expect(hoverColors.pathStroke).toBe('rgb(220, 38, 38)');
		expect(hoverColors.markerValue).toContain('url(');
		expect(hoverColors.markerValue).not.toBe(baselineColors.markerValue);
	});

	test('keeps dragged node position stable when hovering and leaving a removable edge', async ({
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
		await ensureDependencyGraphVisible(page);

		const mapTwoCard = page.getByTestId('dependency-node-card-map-2');
		const beforeDragBox = await mapTwoCard.boundingBox();
		if (!beforeDragBox) {
			throw new Error('Could not resolve map-2 card position before drag.');
		}
		await dragNodeCardByMouse(page, 'map-2', 160, 72);
		const draggedBox = await mapTwoCard.boundingBox();
		if (!draggedBox) {
			throw new Error('Could not resolve dragged map-2 card position.');
		}
		assertBoxMoved(beforeDragBox, draggedBox);

		const edgeGroup = page.getByRole('group', { name: /Edge from map-1 to map-2/i });
		await expect(edgeGroup).toBeVisible();
		const edgePath = edgeGroup.locator('.svelte-flow__edge-path').first();
		const edgeInteractionPath = edgeGroup.locator('.svelte-flow__edge-interaction').first();
		await expect(edgePath).toBeVisible();
		await expect(edgeInteractionPath).toBeVisible();

		const baselineStroke = await edgePath.evaluate((node) => getComputedStyle(node).stroke);
		await edgeGroup.evaluate((element) => {
			element.dispatchEvent(new PointerEvent('pointerenter', { cancelable: true }));
		});
		await expect
			.poll(async () => edgePath.evaluate((node) => getComputedStyle(node).stroke))
			.toBe('rgb(220, 38, 38)');

		const hoveredBox = await mapTwoCard.boundingBox();
		if (!hoveredBox) {
			throw new Error('Could not resolve hovered map-2 card position.');
		}
		assertBoxPositionStable(draggedBox, hoveredBox);

		await edgeGroup.evaluate((element) => {
			element.dispatchEvent(new PointerEvent('pointerleave', { cancelable: true }));
		});
		await expect
			.poll(async () => edgePath.evaluate((node) => getComputedStyle(node).stroke))
			.toBe(baselineStroke);

		const afterLeaveBox = await mapTwoCard.boundingBox();
		if (!afterLeaveBox) {
			throw new Error('Could not resolve map-2 card position after leaving edge hover.');
		}
		assertBoxPositionStable(draggedBox, afterLeaveBox);
	});

	test('removing a dependency edge keeps dragged node position stable', async ({ page }) => {
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
		await ensureDependencyGraphVisible(page);

		const mapTwoCard = page.getByTestId('dependency-node-card-map-2');
		const beforeDragBox = await mapTwoCard.boundingBox();
		if (!beforeDragBox) {
			throw new Error('Could not resolve map-2 card position before drag.');
		}
		const graphCanvas = page.getByTestId('dependency-graph-canvas');
		const mapOneCard = page.getByTestId('dependency-node-card-map-1');
		await dragNodeCardByMouse(page, 'map-2', 140, 68);
		const draggedBox = await mapTwoCard.boundingBox();
		if (!draggedBox) {
			throw new Error('Could not resolve dragged map-2 card position before edge removal.');
		}
		assertBoxMoved(beforeDragBox, draggedBox);
		const mapOneBeforeRemoveBox = await mapOneCard.boundingBox();
		if (!mapOneBeforeRemoveBox) {
			throw new Error('Could not resolve map-1 card position before edge removal.');
		}
		const canvasBeforeRemove = await graphCanvas.boundingBox();
		if (!canvasBeforeRemove) {
			throw new Error('Could not resolve graph canvas position before edge removal.');
		}
		const viewportBeforeRemove = await graphCanvas.getAttribute('data-viewport');
		if (!viewportBeforeRemove) {
			throw new Error('Could not read viewport state before edge removal.');
		}

		const edgeGroup = page.getByRole('group', { name: /Edge from map-1 to map-2/i });
		await expect(edgeGroup).toBeVisible();
		const edgePath = edgeGroup.locator('.svelte-flow__edge-path').first();
		await edgePath.evaluate((element) => {
			element.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true, cancelable: true }));
			element.dispatchEvent(new MouseEvent('pointerup', { bubbles: true, cancelable: true }));
			element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
		});
		await expect(edgeGroup).toHaveCount(0);

		const afterRemoveBox = await mapTwoCard.boundingBox();
		if (!afterRemoveBox) {
			throw new Error('Could not resolve map-2 card position after edge removal.');
		}
		const mapOneAfterBox = await mapOneCard.boundingBox();
		if (!mapOneAfterBox) {
			throw new Error('Could not resolve map-1 card position after edge removal.');
		}
		const canvasAfterRemove = await graphCanvas.boundingBox();
		if (!canvasAfterRemove) {
			throw new Error('Could not resolve graph canvas position after edge removal.');
		}
		const viewportAfterRemove = await graphCanvas.getAttribute('data-viewport');
		if (!viewportAfterRemove) {
			throw new Error('Could not read viewport state after edge removal.');
		}
		expect(viewportAfterRemove).toBe(viewportBeforeRemove);
		assertBoxPositionStable(canvasBeforeRemove, canvasAfterRemove, 6);
		assertBoxPositionStable(
			toRelativeBox(mapOneBeforeRemoveBox, canvasBeforeRemove),
			toRelativeBox(mapOneAfterBox, canvasAfterRemove)
		);
		assertBoxPositionStable(
			toRelativeBox(draggedBox, canvasBeforeRemove),
			toRelativeBox(afterRemoveBox, canvasAfterRemove)
		);
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
		await ensureDependencyGraphVisible(page);
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
		await ensureDependencyGraphVisible(page);

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
		await ensureDependencyGraphVisible(page);
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
					overlapHitTag: null as string | null,
					overlapHitTestId: null as string | null,
					overlapHitCardTestId: null as string | null,
					sampleX: null as number | null,
					sampleY: null as number | null,
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
					overlapHitTag: null as string | null,
					overlapHitTestId: null as string | null,
					overlapHitCardTestId: null as string | null,
					sampleX: null as number | null,
					sampleY: null as number | null,
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
			let overlapHitTag: string | null = null;
			let overlapHitTestId: string | null = null;
			let overlapHitCardTestId: string | null = null;
			let sampleX: number | null = null;
			let sampleY: number | null = null;
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
				sampleX = left + (right - left) / 2;
				sampleY = top + (bottom - top) / 2;
				const hit = document.elementFromPoint(sampleX, sampleY);
				overlapHitPopover = Boolean(hit && popoverElement.contains(hit));
				if (hit instanceof Element) {
					overlapHitTag = hit.tagName.toLowerCase();
					overlapHitTestId = hit.getAttribute('data-testid');
					overlapHitCardTestId =
						hit.closest('[data-testid^="dependency-node-card-"]')?.getAttribute('data-testid') ?? null;
				}
				break;
			}

			return {
				missing: null as string | null,
				activeWrapperZ,
				maxOtherWrapperZ,
				overlapChecked,
				overlapHitPopover,
				overlapHitTag,
				overlapHitTestId,
				overlapHitCardTestId,
				sampleX,
				sampleY,
				popoverVisible: getComputedStyle(popoverElement).display !== 'none'
			};
		});

		expect(layering.missing).toBeNull();
		expect(layering.popoverVisible).toBe(true);
		expect(layering.activeWrapperZ).toBeGreaterThan(layering.maxOtherWrapperZ);
		if (layering.overlapChecked) {
			expect(
				layering.overlapHitPopover,
				`Expected overlap sample to hit popover; sample=(${layering.sampleX}, ${layering.sampleY}), hitTag=${layering.overlapHitTag}, hitTestId=${layering.overlapHitTestId}, hitCard=${layering.overlapHitCardTestId}`
			).toBe(true);
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
		await ensureDependencyGraphVisible(page);
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

	test('shows connection feedback when a connection is rejected by policy', async ({ page }) => {
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
		const spamRow = table.locator('tbody tr').filter({ hasText: 'Spam check' });
		await spamRow.getByRole('button', { name: 'Configure' }).click();
		await page.getByTestId('mapping-config-open-graph').click();

		const sourceHandle =
			'[data-nodeid="__hook_root__:gform_validation"][data-handleid="hook-root-source"]';
		const targetHandle = '[data-nodeid="map-1"][data-handleid="hook-root-target:gform_validation"]';
		await connectHandlesAndAssert(page, sourceHandle, targetHandle, {
			expectRejected: true,
			rejectedMessage: /already autonomous/i
		});
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
		await configModal.getByTestId('mapping-config-close-header').click();
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

	test('uses progressive disclosure defaults and visible guidance summary for spam mappings', async ({
		page
	}) => {
		const definitions = [
			...baseDefinitions,
			{
				id: 'spam_detection_v1',
				label: 'Spam detection',
				source: 'cps',
				hooks: ['gform_validation']
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
				settings: {
					spam_positive_examples: ['Known customer request']
				}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		const table = await openLinkedActionsTable(page);
		await table.locator('tbody tr').first().getByRole('button', { name: 'Configure' }).click();

		const modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByTestId('mapping-section-toggle-core')).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		await expect(modal.getByTestId('mapping-section-toggle-guidance')).toHaveAttribute(
			'aria-expanded',
			'true'
		);
		await expect(modal.getByTestId('mapping-section-toggle-spam_advanced')).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		await expect(modal.getByTestId('mapping-section-toggle-input_mapping')).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		await expect(modal.getByTestId('mapping-section-toggle-attachment_mapping')).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		await expect(modal.getByTestId('mapping-section-toggle-conditions')).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		await expect(modal.getByTestId('mapping-section-toggle-model_execution')).toHaveAttribute(
			'aria-expanded',
			'false'
		);
		await expect(modal.getByText('1 custom example')).toBeVisible();
	});

	test('preserves local draft on close and clears it on discard', async ({ page }) => {
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

		let modal = page.getByTestId('mapping-config-modal');
		const syncHook = modal.getByTestId('mapping-trigger-hook-gform_validation');
		const asyncHook = modal.getByTestId('mapping-trigger-hook-gform_after_submission');

		await expect(syncHook).toBeChecked();
		await expect(asyncHook).not.toBeChecked();
		await asyncHook.check();
		await syncHook.uncheck();

		await modal.getByTestId('mapping-config-close-header').click();
		await expect(modal).toBeHidden();

		await firstRow.getByRole('button', { name: 'Configure' }).click();
		modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByTestId('mapping-trigger-hook-gform_after_submission')).toBeChecked();
		await expect(modal.getByTestId('mapping-trigger-hook-gform_validation')).not.toBeChecked();

		await modal.getByTestId('mapping-config-discard-draft').click();
		await expect(modal).toBeHidden();

		await firstRow.getByRole('button', { name: 'Configure' }).click();
		modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByTestId('mapping-trigger-hook-gform_validation')).toBeChecked();
		await expect(modal.getByTestId('mapping-trigger-hook-gform_after_submission')).not.toBeChecked();
	});

	test('runs request tracer with manual values and renders step diagnostics', async ({ page }) => {
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
					dependency_ids: ['map-1']
				}
			}
		];
		let tracePayload: Record<string, unknown> | null = null;

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance,
				requestTrace: (payload) => {
					tracePayload = payload;
					return {
						authority: 'wp_rest',
						policy_version: '2026-02-request-tracer-v1',
						hook_scope: payload.hook_scope ?? 'all',
						available_hooks: ['gform_validation'],
						input: {
							source: 'manual',
							entry_id: null,
							field_scope: 'mapped_and_rule',
							values: payload.entry_values ?? {},
							manual_field_ids: ['2'],
							imported_field_ids: [],
							overridden_field_ids: [],
							warnings: [],
							include_drafts: true,
							draft_applied: true
						},
						hooks: [
							{
								hook: 'gform_validation',
								order: ['map-1', 'map-2'],
								waves: [{ level: 0, mapping_ids: ['map-1'] }],
								runnable: ['map-1'],
								queued: [],
								blocked: [
									{
										mapping_id: 'map-2',
										reason: 'condition_false',
										details: 'Condition rules did not match this request.'
									}
								],
								cycle_ids: [],
								steps: [
									{
										mapping_id: 'map-1',
										label: 'Spam check',
										dependency_ids: [],
										trigger_source: { type: 'hook_root' },
										execution_mode: 'validation',
										is_async: false,
										outcome: 'would_run',
										block_reason: null,
										block_details: null,
										condition: {
											should_execute: true,
											enabled: true,
											evaluated: true,
											matched: true,
											reason_code: 'matched',
											summary: 'Condition rules matched this request.',
											tree: {
												type: 'rule',
												field_id: '2',
												operator: 'contains',
												actual: 'hello world',
												expected: 'hello',
												result: true,
												reason_code: 'matched'
											}
										}
									},
									{
										mapping_id: 'map-2',
										label: 'Summarize',
										dependency_ids: ['map-1'],
										trigger_source: { type: 'mapping', mapping_id: 'map-1' },
										execution_mode: 'validation',
										is_async: false,
										outcome: 'blocked',
										block_reason: 'condition_false',
										block_details: 'Condition rules did not match this request.',
										condition: {
											should_execute: false,
											enabled: true,
											evaluated: true,
											matched: false,
											reason_code: 'condition_false',
											summary: 'Condition rules did not match this request.',
											tree: {
												type: 'group',
												logic: 'all',
												result: false,
												reason_code: 'group_not_matched',
												children: [
													{
														type: 'rule',
														field_id: '2',
														operator: 'contains',
														actual: 'hello world',
														expected: 'urgent',
														result: false,
														reason_code: 'comparison_failed'
													}
												]
											}
										}
									}
								]
							}
						],
						policy_violations: []
					};
				}
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await ensureDependencyGraphVisible(page);

		await page.getByTestId('request-trace-field-id').selectOption('2');
		await page.getByTestId('request-trace-field-value').fill('hello world');
		await page.getByTestId('request-trace-add-manual-value').click();
		await expect(page.getByTestId('request-trace-manual-2')).toBeVisible();
		await expect(page.getByTestId('request-trace-manual-2')).toContainText('Message');
		await expect(page.getByTestId('request-trace-manual-2')).toContainText('ID: 2');

		await page.getByTestId('request-trace-run').click();
		await expect(page.getByTestId('request-trace-results')).toBeVisible();

		expect(tracePayload).not.toBeNull();
		expect((tracePayload?.entry_values as Record<string, string> | undefined)?.['2']).toBe(
			'hello world'
		);
		expect(tracePayload?.include_drafts).toBe(true);
		expect(Array.isArray(tracePayload?.draft_mappings)).toBe(true);

		await expect(page.getByTestId('request-trace-hook-gform_validation')).toContainText('Run: 1');
		await expect(page.getByTestId('request-trace-step-map-1')).toContainText('Would run');
		await expect(page.getByTestId('request-trace-step-map-2')).toContainText(
			'Condition did not match'
		);
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
		await connectHandlesAndAssert(
			page,
				'[data-nodeid="map-1"][data-handleid="dependency-source"]',
				'[data-nodeid="map-2"][data-handleid="dependency-target"]',
				{
					expectRejected: true,
					rejectedMessage: /cycle/i,
					allowNoFeedbackOnFailure: true
				}
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
		await connectHandlesAndAssert(
			page,
				'[data-nodeid="map-1"][data-handleid="dependency-source"]',
				'[data-nodeid="map-2"][data-handleid="dependency-target"]',
				{
					expectRejected: true,
					rejectedMessage: /must also run in background|cannot depend on background/i,
					allowNoFeedbackOnFailure: true
				}
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
