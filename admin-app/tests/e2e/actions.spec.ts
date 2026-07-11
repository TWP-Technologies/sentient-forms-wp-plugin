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
		source: 'bundled',
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
		updated_at: '2025-11-20T00:00:00Z',
		action_kind: 'template_override',
		definition: null,
		definition_version: 1,
		output_contract: null,
		supported_execution_modes: ['after_submission']
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

const cf7FormSource = 'contact_form_7';
const cf7FormId = 77;
const cf7Forms = [
	{
		id: cf7FormId,
		title: 'CF7 contact form',
		adapter: cf7FormSource,
		adapter_name: 'Contact Form 7',
		provider_edit_url: 'admin.php?page=wpcf7&post=77&action=edit',
		settings: null
	}
];
const cf7FormsWithoutProviderEditUrl = [
	{
		id: cf7FormId,
		title: 'CF7 contact form',
		adapter: cf7FormSource,
		adapter_name: 'Contact Form 7',
		settings: null
	}
];

const cf7FormSourceDescriptor = {
	slug: cf7FormSource,
	label: 'Contact Form 7',
	is_active: true,
	lifecycles: {
		validation: {
			supported: false,
			label: 'Validation',
			native_hook: null,
			execution_mode: 'blocking',
			requires_ledger: false,
			unsupported_reason: 'Contact Form 7 validation blocking is not supported.'
		},
		after_submission: {
			supported: true,
			label: 'After submission',
			native_hook: 'wpcf7_mail_sent',
			execution_mode: 'async',
			requires_ledger: true,
			unsupported_reason: null
		},
		real_time: {
			supported: false,
			label: 'Realtime',
			native_hook: null,
			execution_mode: 'real_time',
			requires_ledger: false,
			unsupported_reason: 'Realtime Contact Form 7 support is not available.'
		}
	},
	native_entry: {
		id: false,
		link: false,
		read: false,
		write: false
	},
	native_enrichment: {
		notes: false,
		status: false,
		spam: false,
		notification_controls: false,
		webhook_controls: false
	},
	ledger: {
		required_for_parity: true,
		enabled: false,
		settings_source: 'sentient_submission_ledger_settings',
		unavailable_reason:
			'Enable the Sentient Forms Submission Ledger before reviewing Contact Form 7 submissions in Sentient Forms.'
	}
};

const wpformsFormSource = 'wpforms';
const wpformsFormId = 88;
const wpformsForms = [
	{
		id: wpformsFormId,
		title: 'WPForms inquiry form',
		adapter: wpformsFormSource,
		adapter_name: 'WPForms',
		provider_edit_url: 'admin.php?page=wpforms-builder&view=fields&form_id=88',
		settings: null
	}
];
const wpformsLiteFormSourceDescriptor = {
	slug: wpformsFormSource,
	label: 'WPForms',
	is_active: true,
	lifecycles: {
		validation: {
			supported: false,
			label: 'Validation',
			native_hook: null,
			execution_mode: 'blocking',
			requires_ledger: false,
			unsupported_reason: 'WPForms validation blocking is not supported.'
		},
		after_submission: {
			supported: true,
			label: 'After submission',
			native_hook: 'wpforms_process_complete',
			execution_mode: 'async',
			requires_ledger: true,
			unsupported_reason: null
		},
		real_time: {
			supported: false,
			label: 'Realtime',
			native_hook: null,
			execution_mode: 'real_time',
			requires_ledger: false,
			unsupported_reason: 'Realtime WPForms support is not available.'
		}
	},
	native_entry: {
		id: false,
		link: false,
		read: false,
		write: false
	},
	native_enrichment: {
		notes: false,
		status: false,
		spam: false,
		notification_controls: false,
		webhook_controls: false
	},
	ledger: {
		required_for_parity: true,
		enabled: false,
		settings_source: 'sentient_submission_ledger_settings',
		unavailable_reason:
			'Enable the Sentient Forms Submission Ledger before reviewing WPForms Lite submissions in Sentient Forms.'
	}
};
const wpformsPaidLikeFormSourceDescriptor = {
	...wpformsLiteFormSourceDescriptor,
	native_entry: {
		id: true,
		link: true,
		read: false,
		write: false
	},
	ledger: {
		...wpformsLiteFormSourceDescriptor.ledger,
		unavailable_reason:
			'Enable the Sentient Forms Submission Ledger before reviewing paid WPForms submissions in Sentient Forms.'
	}
};

const elementorFormsFreeDescriptor = {
	slug: 'elementor_pro_forms',
	label: 'Elementor Pro Forms',
	is_active: false,
	availability: 'requires_pro',
	availability_message: 'Elementor Pro Forms support requires Elementor Pro Forms APIs.',
	requires_pro: true,
	lifecycles: {
		validation: {
			supported: false,
			label: 'Validation',
			native_hook: null,
			execution_mode: 'blocking',
			requires_ledger: false,
			unsupported_reason: 'Elementor Pro Forms validation blocking is not supported.'
		},
		after_submission: {
			supported: false,
			label: 'After submission',
			native_hook: null,
			execution_mode: 'async',
			requires_ledger: true,
			unsupported_reason: 'Elementor Pro Forms support requires Elementor Pro Forms APIs.'
		},
		real_time: {
			supported: false,
			label: 'Realtime',
			native_hook: null,
			execution_mode: 'real_time',
			requires_ledger: false,
			unsupported_reason: 'Realtime Elementor Pro Forms support is not available.'
		}
	},
	native_entry: {
		id: false,
		link: false,
		read: false,
		write: false
	},
	native_enrichment: {
		notes: false,
		status: false,
		spam: false,
		notification_controls: false,
		webhook_controls: false
	},
	ledger: {
		required_for_parity: true,
		enabled: false,
		settings_source: 'sentient_submission_ledger_settings',
		unavailable_reason:
			'Enable Elementor Pro Forms before configuring Sentient Forms ledger storage.'
	}
};

const elementorFormsProLimitedDescriptor = {
	...elementorFormsFreeDescriptor,
	is_active: true,
	availability: 'available',
	availability_message:
		'Elementor Pro Forms APIs are available. Sentient Forms can run after-submission actions after ledger opt-in.',
	requires_pro: true,
	lifecycles: {
		...elementorFormsFreeDescriptor.lifecycles,
		after_submission: {
			supported: true,
			label: 'After submission',
			native_hook: 'elementor_pro/forms/new_record',
			execution_mode: 'async',
			requires_ledger: true,
			unsupported_reason: null
		}
	},
	ledger: {
		...elementorFormsFreeDescriptor.ledger,
		unavailable_reason:
			'Enable the Sentient Forms Submission Ledger before reviewing Elementor Pro Forms submissions in Sentient Forms.'
	},
	requirements: {
		requires_pro: true,
		is_elementor_active: true,
		is_pro_forms_api_available: true,
		is_form_submissions_api_available: false,
		native_submission_parity: 'unavailable',
		native_submission_parity_reason:
			'Elementor Pro Forms APIs are available, but Elementor Form Submissions APIs are unavailable. Treat this as paid but insufficient for native submission-link parity.',
		minimum_native_submission_plan: 'elementor_pro_advanced_solo_or_higher',
		native_submission_fixture_required: true
	}
};

const baseLinkages = [
	{
		local_mapping_id: 'map-1',
		form_id: formId,
		central_action_id: 'spam-check',
		action_name_label: 'Spam check',
		action_type_indicator: 'master',
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

const limitedOpenRouterCredential = {
	id: 7,
	provider: 'openrouter',
	label: 'OpenRouter key',
	auth_mode: 'manual_key',
	constant_name: null,
	status: 'limited',
	status_json: { http_status: 402 },
	last_validated_at: '2026-04-18T22:00:00Z',
	created_at: '2026-04-18T22:00:00Z',
	updated_at: '2026-04-18T22:00:00Z',
	secret_configured: true
};

const statusUnknown = {
	status: 'unknown',
	last_run_at: null,
	last_error_code: null,
	message: ''
};

const quota = { quota_max: 3, quota_used: 1, quota_remaining: 2 };
const creditBalance = { credits_remaining: 25, credits_used: 5, credits_max: 30 };

function managedProviderPathPolicy(actionIds: string | string[]) {
	const ids = Array.isArray(actionIds) ? actionIds : [actionIds];

	return {
		default_provider: 'sentient_managed',
		providers: {
			sentient_managed: {
				ready: true,
				credential_id: 7,
				blocked_reason_code: null
			},
			openrouter: {
				ready: false,
				credential_id: null,
				blocked_reason_code: 'structured_openrouter_model_unavailable'
			}
		},
		actions: Object.fromEntries(
			ids.map((actionId) => [
				actionId,
				{
					selected_provider: 'sentient_managed',
					model_selection: {
						provider: 'sentient_managed',
						model: 'gemini-3-flash-preview',
						credential_id: 7,
						selection: {
							primary: 'sf_default',
							provider: 'sentient_managed',
							credential_id: 7,
							is_preset: true
						}
					},
					blocked_reason_code: null,
					requires_structured_output: true
				}
			])
		)
	};
}

async function openLinkedActionsTable(page: Parameters<typeof test>[0]['page']) {
	const tableToggle = page.getByTestId('linked-actions-view-table');
	if ((await tableToggle.count()) > 0) {
		await tableToggle.first().click();
	}
	const table = page.getByTestId('form-actions-table');
	await expect(table).toBeVisible();
	return table;
}

function trackSentientRestRequests(page: Page): string[] {
	const requests: string[] = [];
	page.on('request', (request) => {
		try {
			const url = new URL(request.url());
			const restPrefix = '/wp-json/sentient-forms/v1/';
			const restIndex = url.pathname.indexOf(restPrefix);

			if (restIndex >= 0) {
				requests.push(
					`${request.method()} ${url.pathname.slice(restIndex + restPrefix.length)}${url.search}`
				);
			}
		} catch {
			// Ignore non-URL request records emitted by the browser driver.
		}
	});

	return requests;
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
	await modal.locator('footer').getByRole('button', { name: 'Save mapping' }).click();
}

async function installFixedWpAdminBar(page: Page, height = 64) {
	await page.addInitScript((adminBarHeight: number) => {
		const install = () => {
			document.body.classList.add('wp-admin');
			document.body.style.paddingTop = `${adminBarHeight}px`;
			document.documentElement.style.setProperty(
				'--sentient-forms-wp-admin-offset',
				`${adminBarHeight}px`
			);
			const existing = document.getElementById('wpadminbar');
			if (existing) {
				existing.remove();
			}
			const adminBar = document.createElement('div');
			adminBar.id = 'wpadminbar';
			adminBar.setAttribute('aria-hidden', 'true');
			Object.assign(adminBar.style, {
				position: 'fixed',
				top: '0',
				left: '0',
				right: '0',
				height: `${adminBarHeight}px`,
				zIndex: '99999'
			});
			document.body.prepend(adminBar);
		};

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', install, { once: true });
			return;
		}

		install();
	}, height);
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
	await page.waitForTimeout(120);
	await page.mouse.up();
}

function isOutsideViewportClickError(error: unknown): boolean {
	return error instanceof Error && /outside of the viewport/i.test(error.message);
}

async function connectHandlesByClick(
	page: Parameters<typeof test>[0]['page'],
	sourceSelector: string,
	targetSelector: string
): Promise<boolean> {
	const viewport = page.getByTestId('dependency-graph-canvas').locator('.svelte-flow__viewport');
	const source = viewport.locator(sourceSelector).first();
	const target = viewport.locator(targetSelector).first();
	await expect(source).toBeVisible();
	await expect(target).toBeVisible();
	await source.scrollIntoViewIfNeeded();
	await target.scrollIntoViewIfNeeded();
	try {
		await source.click({ force: true });
		await target.click({ force: true });
	} catch (error) {
		if (!isOutsideViewportClickError(error)) {
			throw error;
		}
		await page.keyboard.press('Escape').catch(() => {});
		return false;
	}
	return true;
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
	timeoutMs = 1600
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
	const maxAttempts = Math.max(1, options.maxAttempts ?? 5);
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
			const clickedHandles = await connectHandlesByClick(page, sourceSelector, targetSelector);
			if (clickedHandles) {
				outcome = await waitForConnectionOutcome(
					page,
					outcome.feedbackText || baselineFeedbackText
				);
			}
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

async function expectRelativeBoxPositionStable(
	locator: Locator,
	container: Locator,
	expected: Box,
	tolerance = 4
): Promise<void> {
	await expect
		.poll(
			async () => {
				const box = await locator.boundingBox();
				const containerBox = await container.boundingBox();
				if (!box || !containerBox) return Number.POSITIVE_INFINITY;

				const relative = toRelativeBox(box, containerBox);
				return Math.max(Math.abs(relative.x - expected.x), Math.abs(relative.y - expected.y));
			},
			{ timeout: 2_000 }
		)
		.toBeLessThanOrEqual(tolerance);
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
				tier: 'pro',
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

	test('loads the action overview through one source summary request', async ({ page }) => {
		let overviewRequests = 0;
		let defaultsBatchRequests = 0;
		let legacyDefaultsRequests = 0;
		let legacyActionsRequests = 0;
		let legacyStatusRequests = 0;

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

		await page.route('**/wp-json/sentient-forms/v1/gravity_forms/forms/overview**', (route) => {
			overviewRequests += 1;
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					form_source: formSource,
					forms: baseForms.map((form) => ({
						...form,
						actions: baseLinkages,
						action_count: baseLinkages.length,
						enabled_action_count: baseLinkages.filter(
							(linkage) => linkage.is_action_enabled_for_form
						).length,
						execution_status: statusUnknown
					})),
					generated_at: '2030-01-05T10:00:00Z'
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/actions/defaults**', (route) => {
			defaultsBatchRequests += 1;
			const url = new URL(route.request().url());
			const ids = (url.searchParams.get('ids') ?? '').split(',').filter(Boolean);
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					defaults: Object.fromEntries(ids.map((id) => [id, {}])),
					generated_at: '2030-01-05T10:00:00Z'
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/actions/*/defaults', (route) => {
			legacyDefaultsRequests += 1;
			return route.fulfill({
				status: 418,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'action library should batch defaults' })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/gravity_forms/forms/*/actions', (route) => {
			legacyActionsRequests += 1;
			return route.fulfill({
				status: 418,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'overview should not fetch per-form actions' })
			});
		});

		await page.route(
			'**/wp-json/sentient-forms/v1/gravity_forms/forms/*/actions/status',
			(route) => {
				legacyStatusRequests += 1;
				return route.fulfill({
					status: 418,
					contentType: 'application/json',
					body: JSON.stringify({ message: 'overview should not fetch per-form status' })
				});
			}
		);

		const sentientRequests = trackSentientRestRequests(page);
		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await expect(page.getByTestId('actions-forms-workspace')).toContainText('Contact us');
		expect(sentientRequests.length).toBeLessThanOrEqual(10);
		expect(overviewRequests).toBe(1);
		expect(defaultsBatchRequests).toBeGreaterThan(0);
		expect(defaultsBatchRequests).toBeLessThanOrEqual(2);
		expect(legacyDefaultsRequests).toBe(0);
		expect(legacyActionsRequests).toBe(0);
		expect(legacyStatusRequests).toBe(0);
	});

	test('shows free Elementor as requiring Pro on the actions overview', async ({ page }) => {
		await seedRuntimeConfig(page, {
			formSources: [
				{
					slug: 'elementor_pro_forms',
					label: 'Elementor Pro Forms',
					isActive: false,
					availability: 'requires_pro',
					availabilityMessage: 'Elementor Pro Forms support requires Elementor Pro Forms APIs.',
					requiresPro: true,
					descriptor: elementorFormsFreeDescriptor
				}
			]
		});
		await mockWpJson(page, {
			actions: {
				forms: { elementor_pro_forms: [] },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: [],
				formSourceDescriptors: { elementor_pro_forms: elementorFormsFreeDescriptor },
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const providerLabel = page.getByText('Elementor Pro Forms', { exact: true });
		await expect(providerLabel).toBeVisible();
		const providerStatus = providerLabel.locator('../..');
		await expect(providerStatus).toContainText('Requires Pro');
		await expect(providerStatus).toContainText(
			'Elementor Pro Forms support requires Elementor Pro Forms APIs.'
		);
		await expect(providerStatus).not.toContainText('Inactive');
		await expect(providerStatus).not.toContainText('Running');
	});

	test('shows missing Elementor as not installed on the actions overview', async ({ page }) => {
		const elementorFormsMissingDescriptor = {
			...elementorFormsFreeDescriptor,
			availability: 'not_installed',
			availability_message: 'Install Elementor and Elementor Pro to enable Elementor Pro Forms.'
		};

		await seedRuntimeConfig(page, {
			formSources: [
				{
					slug: 'elementor_pro_forms',
					label: 'Elementor Pro Forms',
					isActive: false,
					availability: 'not_installed',
					availabilityMessage: 'Install Elementor and Elementor Pro to enable Elementor Pro Forms.',
					requiresPro: true,
					descriptor: elementorFormsMissingDescriptor
				}
			]
		});
		await mockWpJson(page, {
			actions: {
				forms: { elementor_pro_forms: [] },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: [],
				formSourceDescriptors: { elementor_pro_forms: elementorFormsMissingDescriptor },
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const providerLabel = page.getByText('Elementor Pro Forms', { exact: true });
		await expect(providerLabel).toBeVisible();
		const providerStatus = providerLabel.locator('../..');
		await expect(providerStatus).toContainText('Not installed');
		await expect(providerStatus).toContainText(
			'Install Elementor and Elementor Pro to enable Elementor Pro Forms.'
		);
		await expect(providerStatus).not.toContainText('Requires Pro');
		await expect(providerStatus).not.toContainText('Running');
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

		await page
			.getByTestId(`actions-form-card-${formId}`)
			.getByRole('link', { name: 'Configure Actions' })
			.click();

		await expectAppUrl(page, '/actions/gravity_forms/123');
		await expect(
			page.getByTestId('dependency-graph').getByText('Action Execution Order')
		).toBeVisible();
		await expect(page.getByTestId('form-context-band')).toBeVisible();
		await expect(page.getByTestId('form-context-title')).toHaveText('Contact us');
		await expect(page.getByTestId('form-context-band').getByText('Form #123')).toBeVisible();
		await expect(page.locator('header').getByRole('button', { name: 'Add action' })).toBeVisible();
	});

	test('loads the form editor through one form actions bootstrap request', async ({ page }) => {
		let bootstrapRequests = 0;
		let defaultsBatchRequests = 0;
		let legacyDefaultsRequests = 0;
		let legacyActionsRequests = 0;
		let legacyStatusRequests = 0;
		let legacyDisableRequests = 0;

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.route(
			'**/wp-json/sentient-forms/v1/gravity_forms/forms/123/actions/bootstrap**',
			(route) => {
				bootstrapRequests += 1;
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						form_source: formSource,
						form_id: formId,
						form: baseForms[0],
						actions: baseLinkages,
						execution_status: statusUnknown,
						disabled_state: {
							sf_disabled: false,
							global_disabled: false,
							provider_disabled: false,
							effective_disabled: false
						},
						capabilities: {
							supports_status: true,
							supports_custom_actions: true,
							supports_credits: true,
							cps_version: 'test'
						},
						definitions: baseDefinitions,
						custom_actions: { actions: baseCustomActions, quota },
						provider_credentials: [limitedOpenRouterCredential],
						form_action_configs: { 'spam-check': {} },
						form_fields: baseFormFields,
						action_defaults: { 'spam-check': {}, summarize: {}, hello: {} },
						workflow_plan: {
							authority: 'local',
							authority_reason: 'test_fixture',
							cps_unreachable: false,
							policy_version: '2026-02-mixed-sync-async-v1',
							hook_scope: 'all',
							available_hooks: ['gform_validation'],
							nodes: [],
							edges: [],
							hooks: [],
							policy_violations: []
						},
						generated_at: '2030-01-05T10:00:00Z'
					})
				});
			}
		);

		await page.route('**/wp-json/sentient-forms/v1/actions/defaults**', (route) => {
			defaultsBatchRequests += 1;
			const url = new URL(route.request().url());
			const ids = (url.searchParams.get('ids') ?? '').split(',').filter(Boolean);
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					defaults: Object.fromEntries(ids.map((id) => [id, {}])),
					generated_at: '2030-01-05T10:00:00Z'
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/actions/*/defaults', (route) => {
			legacyDefaultsRequests += 1;
			return route.fulfill({
				status: 418,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'form editor should batch defaults' })
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/gravity_forms/forms/123/actions', (route) => {
			legacyActionsRequests += 1;
			return route.fulfill({
				status: 418,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'form editor should use actions/bootstrap' })
			});
		});
		await page.route(
			'**/wp-json/sentient-forms/v1/gravity_forms/forms/123/actions/status',
			(route) => {
				legacyStatusRequests += 1;
				return route.fulfill({
					status: 418,
					contentType: 'application/json',
					body: JSON.stringify({ message: 'form editor should use actions/bootstrap' })
				});
			}
		);
		await page.route(
			'**/wp-json/sentient-forms/v1/gravity_forms/forms/123/actions/disable',
			(route) => {
				legacyDisableRequests += 1;
				return route.fulfill({
					status: 418,
					contentType: 'application/json',
					body: JSON.stringify({ message: 'form editor should use actions/bootstrap' })
				});
			}
		);

		const sentientRequests = trackSentientRestRequests(page);
		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });

		await expect(
			page.getByTestId('dependency-graph').getByText('Action Execution Order')
		).toBeVisible();
		expect(sentientRequests.length).toBeLessThanOrEqual(3);
		expect(bootstrapRequests).toBe(1);
		expect(defaultsBatchRequests).toBeLessThanOrEqual(1);
		expect(legacyDefaultsRequests).toBe(0);
		expect(legacyActionsRequests).toBe(0);
		expect(legacyStatusRequests).toBe(0);
		expect(legacyDisableRequests).toBe(0);
	});

	test('keeps a low-frequency status poll alive while the form editor is idle', async ({
		page
	}) => {
		await page.clock.install({ time: new Date('2030-01-05T10:00:00Z') });
		let statusRequests = 0;

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.route(
			'**/wp-json/sentient-forms/v1/gravity_forms/forms/123/actions/status',
			(route) => {
				statusRequests += 1;
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						status: 'unknown',
						last_run_at: '2030-01-05T10:02:00Z',
						last_error_code: null,
						message: 'Execution is running.',
						updated_at: '2030-01-05T10:02:00Z',
						entry_id: 456,
						last_result: null
					})
				});
			}
		);

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });

		await expect(
			page.getByTestId('dependency-graph').getByText('Action Execution Order')
		).toBeVisible();
		expect(statusRequests).toBe(0);

		await page.clock.runFor(119_000);
		expect(statusRequests).toBe(0);

		await page.clock.runFor(1_000);
		await expect.poll(() => statusRequests, { timeout: 2_000 }).toBe(1);
		await expect(page.getByTestId('form-execution-status')).toContainText('running');
	});

	test('keeps Add Action controls visible below the WordPress admin bar with long action lists', async ({
		page
	}) => {
		await page.setViewportSize({ width: 1280, height: 720 });
		await installFixedWpAdminBar(page, 68);
		const manyDefinitions = Array.from({ length: 30 }, (_, index) => ({
			id: `bulk_action_${index + 1}`,
			label: `Bulk action ${index + 1}`,
			source: 'bundled',
			hooks: ['gform_validation'],
			base_credit_cost: 1,
			model_hint: 'openrouter/auto'
		}));

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: manyDefinitions,
				status: statusUnknown,
				formsActions: [],
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();

		const drawer = page.getByTestId('add-action-drawer');
		const footer = page.getByTestId('link-action-footer');
		const submit = page.getByTestId('link-action-submit');
		await expect(drawer).toBeVisible();
		await expect(footer).toBeVisible();
		await expect(submit).toBeVisible();
		await expect(submit).toBeInViewport({ ratio: 1 });
		await expect(page.getByTestId('link-action-selected-summary')).toBeVisible();

		const adminBarBottom = await page
			.locator('#wpadminbar')
			.evaluate((element) => element.getBoundingClientRect().bottom);
		await expect
			.poll(async () => {
				const box = await drawer.boundingBox();
				return box?.y ?? 0;
			})
			.toBeGreaterThanOrEqual(adminBarBottom);
	});

	test('opens mapping editor from table without cloning Svelte state proxies', async ({ page }) => {
		const pageErrors: string[] = [];
		page.on('pageerror', (error) => {
			pageErrors.push(error.message);
		});

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		const table = await openLinkedActionsTable(page);
		await table.locator('tbody tr').first().getByRole('button', { name: 'Configure' }).click();

		const modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByText('Configure Action Mapping')).toBeVisible();
		await expect(modal.getByTestId('mapping-section-toggle-conditions')).toBeVisible();
		expect(pageErrors.filter((message) => message.includes('structuredClone'))).toEqual([]);
	});

	test('shows built-in definitions with category badges and hides imported source chips', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					...baseDefinitions,
					{
						id: 'imported-history-template',
						label: 'Imported CPS history template',
						source: 'imported',
						hooks: ['gform_after_submission'],
						base_credit_cost: 4,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const builtInCard = page.getByTestId('actions-library-panel');
		await expect(builtInCard.getByText('Spam check', { exact: true })).toBeVisible();
		await expect(builtInCard.getByText('Summarize', { exact: true })).toBeVisible();
		await expect(builtInCard.getByText('Content Quality', { exact: true })).toBeVisible();
		await expect(builtInCard.getByText('Data Processing', { exact: true })).toBeVisible();
		await expect(builtInCard.getByText('Imported CPS history template')).toHaveCount(0);
		await expect(builtInCard.getByText('Local templates')).toHaveCount(0);
		await expect(builtInCard.getByText('Managed templates')).toHaveCount(0);
		await expect(builtInCard.getByText(/^Local$/)).toHaveCount(0);
		await expect(builtInCard.getByText(/^Managed$/)).toHaveCount(0);
	});

	test('shows effective saved global model defaults on action overview cards', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam detection',
						source: 'bundled',
						hooks: ['gform_validation'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance,
				actionDefaultsById: {
					spam_detection_v1: {
						model_selection: {
							primary: '~google/gemini-flash-latest',
							is_preset: false,
							provider: 'sentient_managed',
							reasoning: 'low'
						}
					}
				}
			},
			customActions: {
				list: {
					actions: [
						{
							...baseCustomActions[0],
							code: 'hello',
							display_name: 'Hello action',
							model_hint: 'openrouter/auto'
						}
					],
					quota
				}
			}
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const spamCard = page.getByTestId('actions-built-in-action-spam_detection_v1');
		await expect(spamCard).toContainText('Default model: ~google/gemini-flash-latest');
		await expect(spamCard).not.toContainText('Default model: Recommended preset');

		await page.getByRole('button', { name: /Custom/ }).click();
		const customCard = page.getByTestId('actions-custom-action-custom-hello');
		await expect(customCard).toContainText('Default model: Recommended preset');
	});

	test('model selector refresh requires OpenRouter metadata disclosure acceptance', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam detection',
						source: 'bundled',
						hooks: ['gform_validation'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByTestId('action-defaults-button-spam_detection_v1').click();
		const defaultsModal = page.getByTestId('action-defaults-modal');
		await expect(defaultsModal).toBeVisible();
		await defaultsModal.getByTestId('model-selector-open').click();

		await page.getByTestId('model-selector-refresh-catalog').click();
		const disclosure = page.getByTestId('model-selector-refresh-disclosure');
		await expect(disclosure).toBeVisible();
		await expect(disclosure).toContainText('No prompts, model outputs, or form entries are sent.');

		const refreshButton = disclosure.getByRole('button', { name: 'Refresh catalog' });
		await expect(refreshButton).toBeDisabled();
		await disclosure
			.getByRole('checkbox', { name: /fetch model metadata from OpenRouter/i })
			.check();
		await expect(refreshButton).toBeEnabled();
	});

	test('surfaces degraded OpenRouter health on overview and form mapping views', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } },
			localProviders: { credentials: [limitedOpenRouterCredential] }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const overviewHealth = page.getByTestId('actions-openrouter-health');
		await expect(overviewHealth).toContainText('OpenRouter key needs attention');
		await expect(overviewHealth.getByText('Limited')).toBeVisible();
		await expect(overviewHealth.getByRole('link', { name: 'Review' })).toBeVisible();

		await page
			.getByTestId(`actions-form-card-${formId}`)
			.getByRole('link', { name: 'Configure Actions' })
			.click();

		const formHealth = page.getByTestId('form-openrouter-health');
		await expect(formHealth).toContainText('OpenRouter key needs attention');
		await expect(formHealth).toContainText('OpenRouter reported insufficient credits');
		await expect(formHealth.getByText('Limited')).toBeVisible();
		await expect(formHealth.getByRole('link', { name: 'Review OpenRouter' })).toBeVisible();
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
		await expect(card.getByText('Configured actions')).toBeVisible();
		await expect(card.getByText('1', { exact: true })).toBeVisible();
		await expect(card.getByText('No actions configured')).toHaveCount(0);
	});

	test('keeps overview form cards readable at constrained WordPress admin width', async ({
		page
	}) => {
		await page.setViewportSize({ width: 780, height: 900 });
		const readableForms = [
			baseForms[0],
			{
				...baseForms[0],
				id: 124,
				title: 'Playwright QA Form Staging Notes'
			},
			{
				...baseForms[0],
				id: 125,
				title: 'Test Force Form'
			},
			{
				...baseForms[0],
				id: 126,
				title: 'Trust Form New'
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: readableForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		const card = page.getByTestId('actions-form-card-124');
		await expect(card).toBeVisible();
		const cardBox = await card.boundingBox();
		expect(cardBox?.width ?? 0).toBeGreaterThan(300);

		const title = card.getByText('Playwright QA Form Staging Notes');
		const titleBox = await title.boundingBox();
		expect(titleBox?.height ?? 999).toBeLessThan(72);
	});

	test('keeps overview custom action names readable in WordPress admin width', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });

		const longNamedCustomActions = [
			{
				...baseCustomActions[0],
				id: 'recommended-contact-follow-up',
				code: 'recommended_contact_follow_up',
				display_name: 'Recommended Contact Follow Up and Classification Action'
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: longNamedCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByRole('button', { name: /Custom/ }).click();

		const actionCard = page.getByTestId('actions-custom-action-recommended-contact-follow-up');
		await expect(actionCard).toBeVisible();

		const label = actionCard.getByText('Recommended Contact Follow Up and Classification Action');
		const labelBox = await label.boundingBox();
		const cardBox = await actionCard.boundingBox();

		expect(cardBox?.width ?? 0).toBeGreaterThan(240);
		expect(labelBox?.height ?? 999).toBeLessThan(80);
	});

	test('keeps overview form titles readable in desktop WordPress admin width', async ({ page }) => {
		await page.setViewportSize({ width: 1280, height: 900 });

		const readableForms = [
			{
				...baseForms[0],
				id: 124,
				title: 'Playwright QA Form Staging Test'
			},
			{
				...baseForms[0],
				id: 125,
				title: 'Test for Fred'
			},
			{
				...baseForms[0],
				id: 126,
				title: 'Test for Fred New'
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: readableForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: baseLinkages,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });

		for (const form of readableForms) {
			const card = page.getByTestId(`actions-form-card-${form.id}`);
			await expect(card).toBeVisible();

			const title = card.getByText(form.title);
			const titleBox = await title.boundingBox();

			expect(titleBox?.height ?? 999).toBeLessThan(56);
		}
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

		await page.goto('/actions/custom/new', { waitUntil: 'domcontentloaded' });
		await expect(
			page.locator('header').getByRole('heading', { name: 'Create Custom Action' })
		).toBeVisible();
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
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByTestId('action-defaults-button-spam_detection_v1').click();

		const modal = page.getByTestId('action-defaults-modal');
		await expect(modal).toBeVisible();
		await expect(modal.getByRole('button', { name: /Classification Guidance/i })).toBeVisible();
		await expect(modal.locator('#positive-example-0')).toHaveValue('Known customer request');
		await expect(modal.locator('#positive-rationale-0')).toHaveValue(
			'Existing customers sometimes ask terse follow-up questions.'
		);
		await expect(modal.locator('#negative-example-0')).toHaveValue('Bulk SEO outreach');
		await expect(modal.locator('#negative-rationale-0')).toHaveValue(
			'Generic agency pitch unrelated to the form purpose.'
		);
		await expect(modal.locator('#action-level-context')).toHaveValue('always');
	});

	test('saves spam defaults as strict structured config and reloads them', async ({ page }) => {
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
						include_site_context: 'global',
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
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByTestId('action-defaults-button-spam_detection_v1').click();

		const modal = page.getByTestId('action-defaults-modal');
		await expect(modal).toBeVisible();
		await modal.locator('#positive-example-0').fill('Warranty help for order SF-42.');
		await modal
			.locator('#positive-rationale-0')
			.fill('Specific existing customer support request.');
		await modal
			.locator('#negative-example-0')
			.fill('hey I am interested can you send me a catalog and your phone number');
		await modal
			.locator('#negative-rationale-0')
			.fill('Known vague lead-harvesting pattern for this customer.');
		await modal
			.locator('#action-level-customization')
			.fill('Catalog and phone-number requests are spam for this site.');
		await expect(modal.getByText(/trusted/i)).toHaveCount(0);
		await modal.locator('#action-level-spam-notifications').selectOption('enabled');
		await modal.locator('#action-level-spam-webhooks').selectOption('enabled');
		await modal.locator('#action-level-context').selectOption('always');

		const saveRequestPromise = page.waitForRequest(
			(request) =>
				request.method() === 'POST' && /\/actions\/spam_detection_v1\/defaults$/.test(request.url())
		);
		await modal.getByRole('button', { name: 'Save Global Defaults' }).click();
		const saveRequest = await saveRequestPromise;
		const payload = saveRequest.postDataJSON() as Record<string, unknown>;

		expect(payload).toMatchObject({
			include_site_context: 'always',
			action_customization: 'Catalog and phone-number requests are spam for this site.',
			suppress_notifications_on_spam: true,
			suppress_webhooks_on_spam: true
		});
		expect(payload.spam_positive_examples).toEqual([
			{
				text: 'Warranty help for order SF-42.',
				rationale: 'Specific existing customer support request.'
			}
		]);
		expect(payload.spam_negative_examples).toEqual([
			{
				text: 'hey I am interested can you send me a catalog and your phone number',
				rationale: 'Known vague lead-harvesting pattern for this customer.'
			}
		]);

		await expect(modal).toBeHidden();
		await page.getByTestId('action-defaults-button-spam_detection_v1').click();
		await expect(
			page.getByTestId('action-defaults-modal').locator('#negative-rationale-0')
		).toHaveValue('Known vague lead-harvesting pattern for this customer.');
		await expect(
			page.getByTestId('action-defaults-modal').locator('#action-level-spam-webhooks')
		).toHaveValue('enabled');
	});

	test('exposes action customization for non-spam built-in defaults', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'entry_summary_v1',
						label: 'Entry Summary',
						source: 'bundled',
						hooks: ['gform_after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				creditBalance,
				actionDefaultsById: {
					entry_summary_v1: {
						action_customization: 'Start with lead intent and keep the summary concise.'
					}
				}
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByTestId('action-defaults-button-entry_summary_v1').click();

		const modal = page.getByTestId('action-defaults-modal');
		await expect(modal).toBeVisible();
		await expect(modal.getByLabel('Action customization')).toHaveValue(
			'Start with lead intent and keep the summary concise.'
		);
		await expect(modal.getByText(/trusted/i)).toHaveCount(0);
		await modal
			.locator('#action-level-customization')
			.fill('Mention budget and urgency when either detail is present.');

		const saveRequestPromise = page.waitForRequest(
			(request) =>
				request.method() === 'POST' && /\/actions\/entry_summary_v1\/defaults$/.test(request.url())
		);
		await modal.getByRole('button', { name: 'Save Global Defaults' }).click();
		const saveRequest = await saveRequestPromise;
		const payload = saveRequest.postDataJSON() as Record<string, unknown>;
		expect(payload.action_customization).toBe(
			'Mention budget and urgency when either detail is present.'
		);
	});

	test('shows inherited action customization in form-level defaults', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'entry_summary_v1',
						label: 'Entry Summary',
						source: 'bundled',
						hooks: ['gform_after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				formsActions: [],
				status: statusUnknown,
				creditBalance,
				actionDefaultsById: {
					entry_summary_v1: {
						action_customization: 'Global summary rule: lead intent first.'
					}
				},
				formActionConfigById: {
					entry_summary_v1: {}
				}
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto(`/#/actions/${formSource}/${formId}`, { waitUntil: 'networkidle' });
		await page.getByText('Action defaults and library').click();
		await expect(
			page.getByText('Configure form-level defaults without moving the execution-order graph.')
		).toBeVisible();
		await page
			.getByRole('listitem')
			.filter({ hasText: /Entry Summary/ })
			.getByRole('button', { name: 'Defaults' })
			.click();

		const modal = page.getByTestId('form-defaults-modal');
		await expect(modal).toBeVisible();
		await expect(modal.locator('#form-level-customization')).toHaveAttribute(
			'placeholder',
			'Global summary rule: lead intent first.'
		);
		await expect(modal.getByText('Inheriting action customization.')).toBeVisible();
		await expect(modal.getByText(/trusted/i)).toHaveCount(0);
		await modal
			.locator('#form-level-customization')
			.fill('Form summary rule: include budget before urgency.');

		const saveRequestPromise = page.waitForRequest(
			(request) =>
				request.method() === 'POST' &&
				/\/forms\/gravity_forms\/123\/action-config\/entry_summary_v1$/.test(request.url())
		);
		await modal.getByRole('button', { name: 'Save Defaults' }).click();
		const saveRequest = await saveRequestPromise;
		const payload = saveRequest.postDataJSON() as Record<string, unknown>;
		expect(payload.action_customization).toBe('Form summary rule: include budget before urgency.');
	});

	test('loads form-level defaults for provider-native Elementor form IDs', async ({ page }) => {
		const elementorFormId = '91:formabc';
		const encodedElementorFormId = encodeURIComponent(elementorFormId);
		const scopedElementorConfigKey = JSON.stringify([
			'elementor_pro_forms',
			elementorFormId,
			'entry_summary_v1'
		]);

		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: elementorFormId,
							title: 'Elementor contact page',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				definitions: [
					{
						id: 'entry_summary_v1',
						label: 'Entry Summary',
						source: 'bundled',
						hooks: ['after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				formsActions: [],
				status: statusUnknown,
				formSourceDescriptors: { elementor_pro_forms: elementorFormsProLimitedDescriptor },
				formActionConfigById: {
					entry_summary_v1: {
						action_customization: 'Wrong form summary default.'
					},
					[scopedElementorConfigKey]: {
						action_customization: 'Elementor form summary default.'
					}
				},
				actionDefaultsById: {
					entry_summary_v1: {
						action_customization: 'Global Elementor fallback.'
					}
				},
				ledgerSettings: {
					form_source: 'elementor_pro_forms',
					form_id: elementorFormId,
					enabled: false,
					enabled_at: null,
					enabled_by_user_id: null,
					disabled_at: null,
					disabled_by_user_id: null,
					settings_source: 'sentient_submission_ledger_settings',
					ledger_records_endpoint: `/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions`,
					record_count: 0
				},
				creditBalance
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto(`/actions/elementor_pro_forms/${encodedElementorFormId}`, {
			waitUntil: 'networkidle'
		});
		await page.getByText('Action defaults and library').click();
		const configRequestPromise = page.waitForRequest(
			(request) =>
				request.method() === 'GET' &&
				request
					.url()
					.includes(
						`/forms/elementor_pro_forms/${encodedElementorFormId}/action-config/entry_summary_v1`
					)
		);
		await page
			.getByRole('listitem')
			.filter({ hasText: /Entry Summary/ })
			.getByRole('button', { name: 'Defaults' })
			.click();
		await configRequestPromise;

		const modal = page.getByTestId('form-defaults-modal');
		await expect(modal).toBeVisible();
		await expect(modal.locator('#form-level-customization')).toHaveValue(
			'Elementor form summary default.'
		);
	});

	test('does not expose Lead Scoring for Elementor Pro Forms while native submission parity is unproven', async ({
		page
	}) => {
		const elementorFormId = '91:formabc';
		const encodedElementorFormId = encodeURIComponent(elementorFormId);

		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: elementorFormId,
							title: 'Elementor lead form',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				definitions: [
					{
						id: 'lead_grading_v1',
						label: 'Lead Scoring',
						source: 'bundled',
						hooks: ['after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				formsActions: [],
				status: statusUnknown,
				formSourceDescriptors: { elementor_pro_forms: elementorFormsProLimitedDescriptor },
				ledgerSettings: {
					form_source: 'elementor_pro_forms',
					form_id: elementorFormId,
					enabled: false,
					enabled_at: null,
					enabled_by_user_id: null,
					disabled_at: null,
					disabled_by_user_id: null,
					settings_source: 'sentient_submission_ledger_settings',
					ledger_records_endpoint: `/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions`,
					record_count: 0
				},
				creditBalance
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto(`/actions/elementor_pro_forms/${encodedElementorFormId}`, {
			waitUntil: 'networkidle'
		});

		await expect(
			page.getByText(
				'Elementor Pro Forms APIs are available, but Elementor Form Submissions APIs are unavailable.'
			)
		).toBeVisible();
		await expect(page.locator('header').getByRole('link', { name: 'Lead Scoring' })).toHaveCount(0);
	});

	test('blocks direct Elementor Lead Scoring route while native submission parity is unproven', async ({
		page
	}) => {
		const elementorFormId = '91:formabc';
		const encodedElementorFormId = encodeURIComponent(elementorFormId);
		let leadValueRequests = 0;

		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: elementorFormId,
							title: 'Elementor lead form',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				formSourceDescriptors: { elementor_pro_forms: elementorFormsProLimitedDescriptor },
				creditBalance
			}
		});

		await page.route('**/wp-json/sentient-forms/v1/lead-value/**', async (route) => {
			leadValueRequests += 1;
			await route.fulfill({
				status: 500,
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify({
					code: 'unexpected_elementor_lead_value_call',
					message: 'Elementor Lead Scoring should be blocked before API calls.'
				})
			});
		});

		await page.goto(`/actions/elementor_pro_forms/${encodedElementorFormId}/lead-value`, {
			waitUntil: 'networkidle'
		});

		await expect(page.getByTestId('elementor-lead-scoring-unavailable')).toContainText(
			'Lead Scoring is not available for Elementor Pro Forms yet.'
		);
		await expect(page.getByTestId('elementor-lead-scoring-unavailable')).toContainText(
			'Lead Scoring needs reliable native entry search, corrections, and notes before staff can safely grade Elementor leads.'
		);
		await expect(page.getByTestId('elementor-lead-scoring-unavailable')).not.toContainText(
			/APIs|fixture|Advanced Solo/i
		);
		await expect(page.getByRole('link', { name: 'Back to form actions' })).toHaveAttribute(
			'href',
			`/actions/elementor_pro_forms/${encodedElementorFormId}`
		);
		expect(leadValueRequests).toBe(0);
	});

	test('creates a built-in action mapping from the drawer', async ({ page }) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem(
					'sentient_forms_last_hooks',
					'["gform_validation","gform_after_submission"]'
				);
			} catch {}
		});
		const linkages: unknown[] = [];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation', 'gform_after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: linkages,
				creditBalance,
				providerPathPolicy: managedProviderPathPolicy('spam_detection_v1')
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		const validationHook = drawer.getByTestId('create-trigger-hook-gform_validation');
		await validationHook.check();
		await expect(validationHook).toBeChecked();

		// Built-in actions tab is default; wait for Spam Detection to be selected by the app.
		const spamRadio = drawer.getByRole('radio', { name: /Spam Detection/i });
		await expect(spamRadio).toBeChecked();
		const createReq = page.waitForRequest(/forms\/\d+\/actions$/);
		const createRes = page.waitForResponse(/forms\/\d+\/actions$/);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		const request = await createReq;
		await createRes;
		expect(request.postDataJSON()).toMatchObject({
			central_action_id: 'spam_detection_v1',
			trigger_hooks: ['gform_validation']
		});

		await expect(drawer).toBeHidden({ timeout: 15_000 });
		const table = await openLinkedActionsTable(page);
		await expect(table.getByText('Spam Detection')).toBeVisible();
	});

	test('blocks built-in action creation when no compatible execution route is available', async ({
		page
	}) => {
		const createRequests: unknown[] = [];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation', 'gform_after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: [],
				creditBalance,
				providerPathPolicy: {
					default_provider: null,
					providers: {
						sentient_managed: {
							ready: false,
							credential_id: null,
							blocked_reason_code: null
						},
						openrouter: {
							ready: false,
							credential_id: null,
							blocked_reason_code: 'structured_openrouter_model_unavailable'
						}
					},
					actions: {
						spam_detection_v1: {
							selected_provider: null,
							model_selection: null,
							blocked_reason_code: 'structured_openrouter_model_unavailable',
							requires_structured_output: true
						}
					}
				}
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		page.on('request', (request) => {
			if (request.method() === 'POST' && /forms\/123\/actions$/.test(request.url())) {
				createRequests.push(request.postDataJSON());
			}
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();

		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await expect(drawer.getByRole('radio', { name: /Spam Detection/i })).toBeDisabled();
		await expect(drawer.getByText('Structured output route unavailable')).toBeVisible();
		await expect(drawer.getByTestId('link-action-submit')).toBeDisabled();
		await drawer.getByTestId('link-action-submit').evaluate((button: HTMLButtonElement) => {
			button.click();
		});
		expect(createRequests).toHaveLength(0);
	});

	test('fails closed when the provider path policy is missing from bootstrap', async ({ page }) => {
		const createRequests: unknown[] = [];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation', 'gform_after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: [],
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		page.on('request', (request) => {
			if (request.method() === 'POST' && /forms\/123\/actions$/.test(request.url())) {
				createRequests.push(request.postDataJSON());
			}
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();

		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		const spamOption = drawer.getByTestId('built-in-action-option-spam_detection_v1');
		await expect(spamOption.getByRole('radio', { name: /Spam Detection/i })).toBeDisabled();
		await expect(spamOption.getByText('Provider route policy unavailable')).toBeVisible();
		await expect(drawer.getByTestId('link-action-submit')).toBeDisabled();
		await drawer.getByTestId('link-action-submit').evaluate((button: HTMLButtonElement) => {
			button.click();
		});
		expect(createRequests).toHaveLength(0);
	});

	test('refreshes built-in provider policy after provider setup changes', async ({ page }) => {
		let bootstrapRequests = 0;
		const providerPathPolicy = {
			default_provider: null,
			providers: {
				sentient_managed: {
					ready: false,
					credential_id: null,
					blocked_reason_code: 'managed_not_ready'
				},
				openrouter: {
					ready: false,
					credential_id: null,
					blocked_reason_code: 'structured_openrouter_model_unavailable'
				}
			},
			actions: {
				spam_detection_v1: {
					selected_provider: null,
					model_selection: null,
					blocked_reason_code: 'managed_not_ready',
					requires_structured_output: true
				}
			}
		};

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation', 'gform_after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: [],
				creditBalance,
				providerPathPolicy
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		page.on('request', (request) => {
			if (request.url().includes('/gravity_forms/forms/123/actions/bootstrap')) {
				bootstrapRequests += 1;
			}
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		const initialBootstrapRequests = bootstrapRequests;
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();

		const drawer = page.getByTestId('link-action-form');
		const spamOption = drawer.getByTestId('built-in-action-option-spam_detection_v1');
		await expect(spamOption.getByRole('radio', { name: /Spam Detection/i })).toBeDisabled();
		await drawer.getByRole('button', { name: 'Cancel' }).click({ timeout: 10_000 });
		await expect(drawer).toBeHidden();

		providerPathPolicy.default_provider = 'sentient_managed';
		providerPathPolicy.providers.sentient_managed = {
			ready: true,
			credential_id: 7,
			blocked_reason_code: null
		};
		providerPathPolicy.actions.spam_detection_v1 = {
			selected_provider: 'sentient_managed',
			model_selection: {
				provider: 'sentient_managed',
				model: 'gemini-3-flash-preview',
				credential_id: 7,
				selection: {
					primary: 'sf_default',
					provider: 'sentient_managed',
					credential_id: 7,
					is_preset: true
				}
			},
			blocked_reason_code: null,
			requires_structured_output: true
		};

		await page.getByTestId('actions-refresh').click({ timeout: 10_000 });
		await expect.poll(() => bootstrapRequests).toBeGreaterThan(initialBootstrapRequests);

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		await expect(drawer).toBeVisible();
		await expect(spamOption.getByRole('radio', { name: /Spam Detection/i })).toBeEnabled();
		await expect(spamOption.getByText(/^Managed$/)).toBeVisible();
		await expect(drawer.getByTestId('link-action-submit')).toBeEnabled();
	});

	test('only exposes realtime trigger for the Realtime Clarification Assistant', async ({
		page
	}) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem('sentient_forms_last_hooks', '["real_time"]');
			} catch {}
		});

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					},
					{
						id: 'clarification_assistant_v1',
						label: 'Realtime Clarification Assistant',
						source: 'bundled',
						hooks: ['real_time'],
						base_credit_cost: 4,
						model_hint: 'openrouter/auto'
					}
				],
				providerPathPolicy: managedProviderPathPolicy([
					'spam_detection_v1',
					'clarification_assistant_v1'
				]),
				status: statusUnknown,
				formsActions: [],
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await expect(drawer.getByRole('radio', { name: /Spam Detection/i })).toBeChecked();
		await expect(drawer.getByTestId('create-trigger-hook-gform_validation')).toBeVisible();
		await expect(drawer.getByTestId('create-trigger-hook-gform_after_submission')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toHaveCount(0);

		await drawer.getByRole('radio', { name: /Realtime Clarification Assistant/i }).check();
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toBeVisible();
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toBeChecked();
		await expect(drawer.getByTestId('create-trigger-hook-gform_validation')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-gform_after_submission')).toHaveCount(0);

		await page.getByRole('button', { name: 'Custom actions' }).click();
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toHaveCount(0);
	});

	test('keeps realtime root DAG family separated from existing root actions', async ({ page }) => {
		const linkages = [
			{
				local_mapping_id: 'map-validation',
				central_action_id: 'spam_detection_v1',
				action_type_indicator: 'master',
				action_name_label: 'Validation Spam Block',
				trigger_hooks: ['gform_validation'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-summary',
				central_action_id: 'entry_summary',
				action_type_indicator: 'master',
				action_name_label: 'Entry Summary',
				trigger_hooks: ['gform_after_submission'],
				is_action_enabled_for_form: true,
				settings: {}
			},
			{
				local_mapping_id: 'map-realtime',
				central_action_id: 'clarification_assistant_v1',
				action_type_indicator: 'master',
				action_name_label: 'Realtime Clarification Assistant',
				trigger_hooks: ['real_time'],
				is_action_enabled_for_form: true,
				settings: {
					execution_mode: 'real_time',
					trigger_sources: {
						real_time: { type: 'hook_root' }
					}
				}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					},
					{
						id: 'entry_summary',
						label: 'Entry Summary',
						source: 'bundled',
						hooks: ['gform_after_submission'],
						base_credit_cost: 4,
						model_hint: 'openrouter/auto'
					},
					{
						id: 'clarification_assistant_v1',
						label: 'Realtime Clarification Assistant',
						source: 'bundled',
						hooks: ['real_time'],
						base_credit_cost: 4,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: linkages,
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await ensureDependencyGraphVisible(page);

		const nodes = [
			page.getByTestId('dependency-hook-root-real_time'),
			page.getByTestId('dependency-node-card-map-realtime'),
			page.getByTestId('dependency-hook-root-gform_validation'),
			page.getByTestId('dependency-node-card-map-validation'),
			page.getByTestId('dependency-hook-root-gform_after_submission'),
			page.getByTestId('dependency-node-card-map-summary')
		];
		for (const node of nodes) {
			await expect(node).toBeVisible();
		}

		const rects = await Promise.all(
			nodes.map(async (node) => {
				const box = await node.boundingBox();
				expect(box).toBeTruthy();
				if (!box) throw new Error('Missing dependency graph node box.');
				return {
					left: box.x,
					top: box.y,
					right: box.x + box.width,
					bottom: box.y + box.height
				};
			})
		);

		for (let leftIndex = 0; leftIndex < rects.length; leftIndex += 1) {
			for (let rightIndex = leftIndex + 1; rightIndex < rects.length; rightIndex += 1) {
				const left = rects[leftIndex]!;
				const right = rects[rightIndex]!;
				const overlaps =
					left.left < right.right &&
					left.right > right.left &&
					left.top < right.bottom &&
					left.bottom > right.top;
				expect(overlaps, `node ${leftIndex} should not overlap node ${rightIndex}`).toBe(false);
			}
		}
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

	test('creates a direct OpenRouter local action mapping from the drawer', async ({ page }) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem('sentient_forms_last_hooks', '["gform_after_submission"]');
			} catch {}
		});

		const resolvePayloads: Record<string, unknown>[] = [];
		let createdActionPayload: Record<string, unknown> | null = null;
		let createdMappingPayload: Record<string, unknown> | null = null;
		let updateMappingPayload: Record<string, unknown> | null = null;
		const formActions: Record<string, unknown>[] = [];

		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: formActions,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } },
			localProviders: {
				credentials: [
					{
						id: 42,
						provider: 'openrouter',
						label: 'OpenRouter ready key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: null,
						last_validated_at: '2030-01-05T10:00:00Z',
						created_at: '2030-01-05T09:00:00Z',
						updated_at: '2030-01-05T10:00:00Z',
						secret_configured: true
					}
				]
			}
		});

		await page.route('**/wp-json/sentient-forms/v1/models', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					models: [
						{
							id: 'openai/gpt-oss-20b:free',
							display_name: 'OpenAI GPT OSS 20B Free',
							provider: 'openrouter',
							speed_tier: 'fast',
							cost_tier: 'free',
							capabilities: {
								reasoning: false,
								code: false,
								vision: false,
								tools: false,
								long_context: true
							},
							context_window: 131072,
							is_preview: false,
							tags: ['free'],
							recommended_for: ['summary']
						}
					],
					presets: [
						{
							code: 'sf_default',
							display_name: 'Default',
							description: 'Use the default local policy.',
							category: 'general',
							resolved_model_id: 'openrouter/auto',
							auto_upgrade: true
						},
						{
							code: 'sf_free',
							display_name: 'Free OpenRouter',
							description: 'Prefer a locally cached free OpenRouter model.',
							category: 'cost',
							resolved_model_id: 'openai/gpt-oss-20b:free',
							auto_upgrade: false
						}
					],
					pricing_policy_version: 'local-openrouter-v2'
				})
			})
		);

		await page.route('**/wp-json/sentient-forms/v1/models/resolve', async (route) => {
			const payload = route.request().postDataJSON() as Record<string, unknown>;
			resolvePayloads.push(payload);

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					model_id: 'openai/gpt-oss-20b:free',
					display_name: 'OpenAI GPT OSS 20B Free',
					resolution_source: 'preset',
					override_chain: [
						{
							level: 'action',
							selection:
								((payload.action_selection as Record<string, unknown> | undefined)?.primary as
									| string
									| undefined) ?? 'sf_default',
							applied: true,
							reason: 'Selected local action builder preset.'
						}
					],
					backup_model_id: null
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/local/custom-actions', async (route) => {
			const payload = route.request().postDataJSON() as Record<string, unknown>;
			createdActionPayload = payload;

			return route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({
					id: 81,
					external_id: null,
					template_id: null,
					code: 'local_openrouter_summary_1',
					display_name: payload.display_name ?? 'Local OpenRouter summary',
					definition_json: (payload.definition_json as Record<string, unknown>) ?? {},
					model_selection_json: (payload.model_selection_json as Record<string, unknown>) ?? null,
					status: 'active',
					created_at: '2030-01-05T10:00:00Z',
					updated_at: '2030-01-05T10:00:00Z'
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/local/form-mappings', async (route) => {
			const payload = route.request().postDataJSON() as Record<string, unknown>;
			createdMappingPayload = payload;
			const hook = String(payload.hook ?? 'gform_after_submission');
			formActions.push({
				local_mapping_id: 'local_first_91',
				local_form_mapping_id: 91,
				central_action_id: 'local_openrouter_summary_1',
				action_type_indicator: 'local_first',
				action_name_label:
					(typeof createdActionPayload?.display_name === 'string'
						? createdActionPayload.display_name
						: null) ?? 'Local OpenRouter summary',
				trigger_hooks: [hook],
				is_action_enabled_for_form: true,
				execution_priority: 91,
				execution_mode: payload.execution_mode === 'sync' ? 'validation' : 'after_submission',
				settings: {
					local_form_mapping_id: 91,
					execution_mode: payload.execution_mode === 'sync' ? 'validation' : 'after_submission',
					effect_mapping_json: (payload.effect_mapping_json as Record<string, unknown>) ?? {},
					trigger_sources: {
						[hook]: { type: 'hook_root' }
					}
				}
			});

			return route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({
					id: 91,
					external_id: null,
					form_source: 'gravity_forms',
					form_id: String(formId),
					hook: payload.hook ?? 'gform_after_submission',
					action_kind: 'custom_action',
					action_id: 81,
					input_bindings_json: (payload.input_bindings_json as Record<string, unknown>) ?? {},
					conditions_json: null,
					execution_mode: payload.execution_mode ?? 'async',
					effect_mapping_json: (payload.effect_mapping_json as Record<string, unknown>) ?? {},
					enabled: true,
					created_at: '2030-01-05T10:00:00Z',
					updated_at: '2030-01-05T10:00:00Z'
				})
			});
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await page.getByTestId('create-kind-local-openrouter').click();

		await expect(drawer.getByTestId('local-openrouter-builder')).toBeVisible();
		await expect(drawer.getByTestId('local-builder-template')).toHaveValue('spam_filter');
		await expect(drawer.getByTestId('local-builder-model-selector')).toBeVisible();
		await drawer.getByTestId('model-selector-open').click();
		await page.getByTestId('model-preset-sf_free').click();
		await expect(page.getByTestId('model-selector-dialog')).toHaveCount(0);
		await expect(drawer.getByTestId('local-builder-result-meta-key')).toHaveValue(
			'sentient_forms_spam_classification'
		);
		await expect(drawer.getByTestId('local-builder-execution-mode')).toHaveValue('sync');
		await expect(drawer.getByTestId('local-builder-spam-result-display')).toHaveValue('spam_only');
		await drawer.getByTestId('local-builder-spam-result-display').selectOption('all_results');
		await drawer.getByTestId('local-builder-spam-indicators-display').selectOption('detailed');
		await drawer.getByTestId('local-builder-action-name').fill('Local drawer spam filter');
		await drawer.getByRole('button', { name: 'Create Direct OpenRouter action' }).click();

		await expect(drawer.getByTestId('local-builder-result')).toContainText('Action #81');
		expect(resolvePayloads).toContainEqual(
			expect.objectContaining({
				action_selection: expect.objectContaining({
					primary: 'sf_free',
					is_preset: true,
					backup: null
				}),
				template_model_hint: 'openrouter/auto'
			})
		);
		expect(createdActionPayload).toMatchObject({
			display_name: 'Local drawer spam filter',
			definition_json: {
				builder_template: 'spam_filter'
			},
			model_selection_json: {
				provider: 'openrouter',
				model: 'openai/gpt-oss-20b:free',
				credential_id: 42,
				resolution_source: 'preset',
				policy_hint: 'local_models_resolve',
				selection: {
					primary: 'sf_free',
					is_preset: true,
					backup: null
				}
			}
		});
		expect(createdActionPayload?.definition_json).toMatchObject({
			structured_output_schema: {
				required: ['classification', 'confidence', 'justification']
			}
		});
		expect(createdActionPayload?.definition_json).not.toHaveProperty('response_format');
		expect(createdMappingPayload).toMatchObject({
			form_source: 'gravity_forms',
			form_id: String(formId),
			hook: 'gform_validation',
			action_kind: 'custom_action',
			action_id: 81,
			execution_mode: 'sync',
			effect_mapping_json: {
				store_result: true,
				meta: {
					sentient_forms_spam_classification: 'structured.classification',
					sentient_forms_spam_confidence: 'structured.confidence'
				},
				spam: {
					enabled: true,
					classification_path: 'structured.classification',
					confidence_path: 'structured.confidence',
					min_confidence: 0.8,
					suppress_notifications_on_spam: true,
					note: {
						result_display_mode: 'all_results',
						indicators_display: 'detailed'
					}
				}
			}
		});

		const closeDrawer = drawer.getByRole('button', { name: 'Cancel' });
		await closeDrawer.click();
		await expect(drawer).toBeHidden();

		const table = await openLinkedActionsTable(page);
		await expect(table.getByText('Local drawer spam filter')).toBeVisible();
		await expect(table.getByText('Direct OpenRouter')).toBeVisible();
		await expect(table.getByText('ID: local_openrouter_summary_1')).toBeVisible();

		await table.getByRole('button', { name: 'Configure' }).click();
		const modal = page.getByTestId('mapping-config-modal');
		await expect(modal).toBeVisible();
		await expect(modal.getByText('Local drawer spam filter (local_first_91)')).toBeVisible();
		await modal.getByTestId('mapping-config-close-header').click();
		await expect(modal).toBeHidden();

		const updateReq = page.waitForRequest((request) => {
			if (!/forms\/123\/actions\/local_first_91$/.test(request.url())) return false;
			updateMappingPayload = request.postDataJSON() as Record<string, unknown>;
			return request.method() === 'PUT';
		});
		await table.getByRole('button', { name: 'Disable' }).click();
		await updateReq;
		expect(updateMappingPayload).toMatchObject({
			is_action_enabled_for_form: false
		});
		await expect(table.getByText('Disabled')).toBeVisible();
	});

	test('scopes the Direct OpenRouter drawer to Contact Form 7 after-submission ledger storage', async ({
		page
	}) => {
		let modelResolveRequests = 0;
		page.on('request', (request) => {
			const url = new URL(request.url());
			if (request.method() === 'POST' && url.pathname.endsWith('/models/resolve')) {
				modelResolveRequests += 1;
			}
		});

		await mockWpJson(page, {
			actions: {
				forms: { [cf7FormSource]: cf7FormsWithoutProviderEditUrl },
				definitions: [],
				status: statusUnknown,
				formsActions: [],
				formFields: [
					{ id: 'your-name', label: 'Your name', type: 'text' },
					{ id: 'your-email', label: 'Your email', type: 'email' },
					{ id: 'your-message', label: 'Your message', type: 'textarea' }
				],
				formSourceDescriptors: { [cf7FormSource]: cf7FormSourceDescriptor },
				creditBalance
			},
			customActions: { list: { actions: [], quota } },
			localProviders: {
				credentials: [
					{
						id: 42,
						provider: 'openrouter',
						label: 'OpenRouter ready key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: null,
						last_validated_at: '2030-01-05T10:00:00Z',
						created_at: '2030-01-05T09:00:00Z',
						updated_at: '2030-01-05T10:00:00Z',
						secret_configured: true
					}
				]
			}
		});

		await page.goto('/actions/contact_form_7/77', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('form-context-band')).toContainText('Contact Form 7');
		await expect(page.getByTestId('form-context-provider-edit-link')).toHaveText(
			'Open in Contact Form 7'
		);
		await expect(page.getByTestId('submission-ledger-affordance')).toContainText(
			'Required for parity'
		);
		await expect(page.getByTestId('submission-ledger-affordance')).toContainText(
			'Contact Form 7 submissions need Sentient Forms Submission Ledger storage'
		);

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await page.getByTestId('create-kind-local-openrouter').click();

		await expect(drawer.getByTestId('local-openrouter-builder')).toBeVisible();
		await expect(drawer.getByTestId('local-builder-template')).toHaveValue('spam_filter');
		await expect(drawer.getByText('does not block validation or write native notes')).toBeVisible();
		await expect(drawer.getByTestId('local-builder-execution-mode')).toHaveValue('async');
		await expect(drawer.getByTestId('local-builder-spam-result-display')).toHaveCount(0);
		await expect(drawer.getByTestId('local-builder-spam-indicators-display')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-after_submission')).toBeChecked();
		await expect(drawer.getByTestId('create-trigger-hook-wpcf7_mail_sent')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-gform_validation')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toHaveCount(0);
		await page.waitForTimeout(600);
		expect(modelResolveRequests).toBeLessThanOrEqual(3);
	});

	test('presents WPForms Lite as ledger-only after-submission support without native entry claims', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [wpformsFormSource]: wpformsForms },
				definitions: [],
				status: statusUnknown,
				formsActions: [],
				formFields: [
					{ id: '1', label: 'Name', type: 'name' },
					{ id: '2', label: 'Email', type: 'email' },
					{ id: '3', label: 'Message', type: 'textarea' }
				],
				formSourceDescriptors: { [wpformsFormSource]: wpformsLiteFormSourceDescriptor },
				creditBalance
			},
			customActions: { list: { actions: [], quota } },
			localProviders: {
				credentials: [
					{
						id: 42,
						provider: 'openrouter',
						label: 'OpenRouter ready key',
						auth_mode: 'manual_key',
						constant_name: null,
						status: 'valid',
						status_json: null,
						last_validated_at: '2030-01-05T10:00:00Z',
						created_at: '2030-01-05T09:00:00Z',
						updated_at: '2030-01-05T10:00:00Z',
						secret_configured: true
					}
				]
			}
		});

		await page.goto('/actions/wpforms/88', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('form-context-band')).toContainText('WPForms');
		await expect(page.getByTestId('form-context-provider-edit-link')).toHaveText('Open in WPForms');
		await expect(page.getByTestId('submission-ledger-affordance')).toContainText(
			'Required for parity'
		);
		await expect(page.getByTestId('submission-ledger-affordance')).toContainText(
			'WPForms Lite/no-native-entry submissions use Sentient Forms Submission Ledger records'
		);
		await expect(page.getByTestId('submission-ledger-affordance')).not.toContainText(
			'WPForms paid entry storage is detected'
		);

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await page.getByTestId('create-kind-local-openrouter').click();

		await expect(drawer.getByTestId('local-openrouter-builder')).toBeVisible();
		await expect(drawer.getByTestId('local-builder-template')).toHaveValue('spam_filter');
		await expect(drawer.getByText('does not block validation or write native notes')).toBeVisible();
		await expect(drawer.getByTestId('local-builder-execution-mode')).toHaveValue('async');
		await expect(drawer.getByTestId('local-builder-spam-result-display')).toHaveCount(0);
		await expect(drawer.getByTestId('local-builder-spam-indicators-display')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-after_submission')).toBeChecked();
		await expect(drawer.getByTestId('create-trigger-hook-wpforms_process_complete')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-gform_validation')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toHaveCount(0);
	});

	test('presents paid-like WPForms entry storage as native-entry link enrichment', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [wpformsFormSource]: wpformsForms },
				definitions: [],
				status: statusUnknown,
				formsActions: [],
				formFields: [
					{ id: '1', label: 'Name', type: 'name' },
					{ id: '2', label: 'Email', type: 'email' },
					{ id: '3', label: 'Message', type: 'textarea' }
				],
				formSourceDescriptors: { [wpformsFormSource]: wpformsPaidLikeFormSourceDescriptor },
				creditBalance
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto('/actions/wpforms/88', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('form-context-band')).toContainText('WPForms');
		await expect(page.getByTestId('submission-ledger-provider-note')).toContainText(
			'WPForms paid entry storage is detected'
		);
		await expect(page.getByTestId('submission-ledger-provider-note')).toContainText(
			'native entry links will be attached when WPForms provides a non-zero entry ID'
		);
		await expect(page.getByTestId('submission-ledger-provider-note')).not.toContainText(
			'Lite/no-native-entry'
		);
	});

	test('keeps free Elementor-only Forms unavailable instead of exposing configuration', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: 91,
							title: 'Elementor contact page',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: [],
				formFields: [],
				formSourceDescriptors: { elementor_pro_forms: elementorFormsFreeDescriptor },
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/elementor_pro_forms/91', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('form-context-band')).toContainText('Elementor Pro Forms');
		await expect(page.getByTestId('form-source-availability-alert')).toContainText(
			'Elementor Pro Forms support requires Elementor Pro Forms APIs.'
		);
		await expect(page.getByTestId('submission-ledger-toggle')).toBeDisabled();

		const addActionButtons = page.getByRole('button', { name: 'Add action' });
		await expect(addActionButtons.first()).toBeDisabled();
		await expect(page.getByTestId('link-action-form')).toHaveCount(0);
		await expect(page.locator('header').getByRole('switch').first()).toBeDisabled();

		await page
			.getByTestId('action-definitions-card')
			.getByText('Action defaults and library')
			.click();
		const defaultButtons = page.getByTestId('action-definitions-card').getByRole('button', {
			name: 'Defaults'
		});
		const defaultButtonCount = await defaultButtons.count();
		expect(defaultButtonCount).toBeGreaterThan(0);
		for (let index = 0; index < defaultButtonCount; index += 1) {
			await expect(defaultButtons.nth(index)).toBeDisabled();
		}
	});

	test('keeps direct free Elementor route unavailable when bootstrap returns requires_pro', async ({
		page
	}) => {
		await seedRuntimeConfig(page, {
			formSources: [
				{
					slug: 'elementor_pro_forms',
					label: 'Elementor Pro Forms',
					isActive: false,
					availability: 'requires_pro',
					availabilityMessage: 'Elementor Pro Forms support requires Elementor Pro Forms APIs.',
					requiresPro: true,
					descriptor: elementorFormsFreeDescriptor
				}
			]
		});
		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: '656:sfdogfood1',
							title: 'Elementor local dogfood',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: [],
				formFields: [],
				formSourceDescriptors: { elementor_pro_forms: elementorFormsFreeDescriptor },
				bootstrapError: {
					status: 404,
					body: {
						code: 'rest_form_source_unavailable',
						message: 'Elementor Pro Forms support requires Elementor Pro Forms APIs.',
						data: { status: 404 }
					}
				},
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/elementor_pro_forms/656%3Asfdogfood1', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('form-source-availability-alert')).toContainText(
			'Elementor Pro Forms support requires Elementor Pro Forms APIs.'
		);
		await expect(page.getByTestId('submission-ledger-toggle')).toBeDisabled();
		await expect(page.getByRole('button', { name: 'Check Sentient Forms log entry' })).toHaveCount(
			0
		);
		await expect(page.getByTestId('form-actions-error-state')).toHaveCount(0);

		const addActionButtons = page.getByRole('button', { name: 'Add action' });
		await expect(addActionButtons.first()).toBeDisabled();
	});

	test('shows Elementor Pro Forms native-submission limitations without blocking after-submission setup', async ({
		page
	}) => {
		const elementorLinkages = [
			{
				local_mapping_id: 'elementor-after-submission',
				form_id: '91',
				central_action_id: 'summarize',
				action_name_label: 'Summarize lead',
				action_type_indicator: 'master',
				action_status: 'active',
				trigger_hooks: ['elementor_pro/forms/new_record'],
				created_at: '2026-06-25T02:45:00Z',
				updated_at: '2026-06-25T02:45:00Z',
				is_action_enabled_for_form: true,
				last_run_status: 'unknown',
				settings: {
					trigger_sources: {
						after_submission: { type: 'hook_root' }
					}
				}
			}
		];

		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: 91,
							title: 'Elementor contact page',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				definitions: baseDefinitions,
				status: statusUnknown,
				formsActions: elementorLinkages,
				formFields: [...baseFormFields, { id: '4', label: 'Project files', type: 'fileupload' }],
				formSourceDescriptors: { elementor_pro_forms: elementorFormsProLimitedDescriptor },
				ledgerSettings: {
					form_source: 'elementor_pro_forms',
					form_id: '91',
					enabled: false,
					enabled_at: null,
					enabled_by_user_id: null,
					disabled_at: null,
					disabled_by_user_id: null,
					settings_source: 'sentient_submission_ledger_settings',
					ledger_records_endpoint:
						'/wp-json/sentient-forms/v1/elementor_pro_forms/forms/91/submissions',
					record_count: 0
				},
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/elementor_pro_forms/91', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('form-source-availability-alert')).toHaveCount(0);
		await expect(page.getByTestId('form-source-limitations-alert')).toContainText(
			'Validation blocking and realtime assistance are not supported'
		);
		await expect(page.getByTestId('form-source-limitations-alert')).toContainText(
			'Elementor Form Submissions APIs are unavailable'
		);
		await expect(page.getByTestId('form-source-limitations-alert')).toContainText(
			'Native result writing and spam status updates stay disabled'
		);
		await page.getByText('Execution status and Sentient Forms log lookup').click();
		await expect(
			page.getByText(
				'Native entry status lookup is unavailable for Elementor Pro Forms. Use the Submission Ledger and Action Log list for submitted Elementor Pro Forms records.'
			)
		).toBeVisible();
		await expect(page.getByRole('button', { name: 'Check Sentient Forms log entry' })).toHaveCount(
			0
		);
		await expect(page.getByRole('button', { name: 'Check log entry' })).toHaveCount(0);
		await expect(page.getByText('this Gravity Forms form')).toHaveCount(0);

		const table = await openLinkedActionsTable(page);
		await table.locator('tbody tr').first().getByRole('button', { name: 'Configure' }).click();
		const modal = page.getByTestId('mapping-config-modal');
		await expect(modal.getByText('Configure Action Mapping')).toBeVisible();
		await modal.getByTestId('mapping-section-toggle-attachment_mapping').click();
		const sourceModeSelect = modal.getByLabel('Source mode');
		await expect(sourceModeSelect).toContainText('Media library');
		await expect(sourceModeSelect).not.toContainText('Elementor Pro Forms uploads');
		await expect(sourceModeSelect).not.toContainText('Mixed (Elementor Pro Forms uploads + media)');
		await expect(sourceModeSelect).not.toContainText('Gravity Forms uploads');
		await expect(modal).toContainText(
			'Elementor Pro Forms upload fields are stored as ledger file references only.'
		);
		await modal.locator('footer').getByRole('button', { name: 'Close' }).click();
		await expect(modal).toHaveCount(0);

		await expect(page.locator('header').getByRole('button', { name: 'Add action' })).toBeEnabled();
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await expect(drawer.getByTestId('create-trigger-hook-after_submission')).toBeChecked();
		await expect(drawer.getByTestId('create-trigger-hook-gform_validation')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toHaveCount(0);
	});

	test('links Elementor actions through provider-native mock routes', async ({ page }) => {
		const elementorFormId = '91:formabc';
		const encodedElementorFormId = encodeURIComponent(elementorFormId);

		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: elementorFormId,
							title: 'Elementor contact page',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				definitions: baseDefinitions,
				providerPathPolicy: managedProviderPathPolicy('summarize'),
				status: statusUnknown,
				formsActions: [],
				formFields: baseFormFields,
				formSourceDescriptors: { elementor_pro_forms: elementorFormsProLimitedDescriptor },
				ledgerSettings: {
					form_source: 'elementor_pro_forms',
					form_id: elementorFormId,
					enabled: false,
					enabled_at: null,
					enabled_by_user_id: null,
					disabled_at: null,
					disabled_by_user_id: null,
					settings_source: 'sentient_submission_ledger_settings',
					ledger_records_endpoint: `/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions`,
					record_count: 0
				},
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto(`/actions/elementor_pro_forms/${encodedElementorFormId}`, {
			waitUntil: 'networkidle'
		});

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await drawer.getByRole('radio', { name: /Summarize/i }).check();
		await expect(drawer.getByTestId('create-trigger-hook-after_submission')).toBeChecked();

		const createReq = page.waitForRequest(
			(request) =>
				request.method() === 'POST' &&
				request.url().includes(`/elementor_pro_forms/forms/${encodedElementorFormId}/actions`)
		);
		await drawer.getByRole('button', { name: 'Link action' }).click();
		const request = await createReq;
		expect(request.postDataJSON()).toMatchObject({
			central_action_id: 'summarize',
			trigger_hooks: ['after_submission']
		});
		await expect(drawer).toBeHidden({ timeout: 15_000 });

		const table = await openLinkedActionsTable(page);
		await expect(table.getByText('Summarize')).toBeVisible();
	});

	test('keeps Elementor direct submission review unavailable while ledger storage is disabled', async ({
		page
	}) => {
		await mockWpJson(page, {});
		const directResponse = (data: unknown) => data;
		let submissionRecordRequests = 0;

		await page.route(
			'**/wp-json/sentient-forms/v1/elementor_pro_forms/forms/91/ledger-settings',
			(route) =>
				route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(
						directResponse({
							form_source: 'elementor_pro_forms',
							form_id: '91',
							enabled: false,
							enabled_at: null,
							enabled_by_user_id: null,
							disabled_at: null,
							disabled_by_user_id: null,
							settings_source: 'sentient_submission_ledger_settings',
							ledger_records_endpoint:
								'/wp-json/sentient-forms/v1/elementor_pro_forms/forms/91/submissions',
							record_count: 1
						})
					)
				})
		);
		await page.route(
			'**/wp-json/sentient-forms/v1/elementor_pro_forms/forms/91/submissions**',
			(route) => {
				submissionRecordRequests += 1;

				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(
						directResponse({
							form_source: 'elementor_pro_forms',
							form_id: '91',
							submissions: [
								{
									id: 12,
									submission_uuid: '66666666-7777-4888-9999-aaaaaaaaaaaa',
									form_source: 'elementor_pro_forms',
									form_id: '91',
									native_entry_id: null,
									native_entry_url: null,
									source_submitted_at: null,
									captured_at: '2026-06-24T22:41:00Z',
									logical_fields: {
										email: 'lead@example.test'
									},
									provider_metadata: {},
									file_refs: [],
									redaction_summary: {
										redacted_keys: []
									},
									action_runs: [],
									expires_at: null,
									detail_endpoint:
										'/wp-json/sentient-forms/v1/elementor_pro_forms/forms/91/submissions/66666666-7777-4888-9999-aaaaaaaaaaaa'
								}
							],
							count: 1,
							per_page: 50,
							offset: 0
						})
					)
				});
			}
		);

		await page.goto('/actions/elementor_pro_forms/91/submissions', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('submission-ledger-status-card')).toContainText('Off');
		await expect(page.getByTestId('submission-ledger-empty')).toContainText(
			'New submitted forms will appear here after ledger storage is enabled and a form is submitted.'
		);
		await expect(page.getByTestId('submission-ledger-table')).toHaveCount(0);
		expect(submissionRecordRequests).toBe(0);
	});

	test('shows grouped Elementor action runs on the submission ledger page', async ({ page }) => {
		const elementorFormId = '91:formabc';
		const encodedElementorFormId = encodeURIComponent(elementorFormId);

		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: elementorFormId,
							title: 'Elementor contact page',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				formSourceDescriptors: { elementor_pro_forms: elementorFormsProLimitedDescriptor }
			}
		});
		const directResponse = (data: unknown) => data;

		await page.route(
			`**/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/ledger-settings`,
			(route) =>
				route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(
						directResponse({
							form_source: 'elementor_pro_forms',
							form_id: elementorFormId,
							enabled: true,
							enabled_at: '2026-06-24T22:40:00Z',
							enabled_by_user_id: 1,
							disabled_at: null,
							disabled_by_user_id: null,
							settings_source: 'sentient_submission_ledger_settings',
							ledger_records_endpoint: `/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions`,
							record_count: 1
						})
					)
				})
		);
		await page.route(
			`**/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions**`,
			(route) =>
				route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(
						directResponse({
							form_source: 'elementor_pro_forms',
							form_id: elementorFormId,
							submissions: [
								{
									id: 12,
									submission_uuid: '66666666-7777-4888-9999-aaaaaaaaaaaa',
									form_source: 'elementor_pro_forms',
									form_id: elementorFormId,
									native_entry_id: null,
									native_entry_url: null,
									source_submitted_at: null,
									captured_at: '2026-06-24T22:41:00Z',
									logical_fields: {
										email: 'lead@example.test'
									},
									provider_metadata: {
										form_name: 'Elementor Ledger Form'
									},
									file_refs: [],
									redaction_summary: {
										redacted_keys: []
									},
									action_runs: [
										{
											execution_request_id: 'req-elementor-ledger-run',
											mapping_id: 987,
											status: 'success',
											provider: 'openrouter',
											model: 'openrouter/auto',
											last_result: {
												structured: {
													summary: 'Elementor action result is grouped.'
												}
											},
											last_error_code: null,
											last_error_message: null,
											created_at: '2026-06-24T22:41:10Z',
											updated_at: '2026-06-24T22:41:20Z'
										}
									],
									expires_at: null,
									detail_endpoint: `/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions/66666666-7777-4888-9999-aaaaaaaaaaaa`
								}
							],
							count: 1,
							per_page: 50,
							offset: 0
						})
					)
				})
		);

		await page.goto(`/actions/elementor_pro_forms/${encodedElementorFormId}/submissions`, {
			waitUntil: 'networkidle'
		});

		const row = page.getByTestId('submission-ledger-row');
		await expect(row).toContainText('66666666-7777-4888-9999-aaaaaaaaaaaa');
		await expect(row.getByTestId('submission-ledger-action-runs')).toContainText('1 action run');
		await expect(row.getByTestId('submission-ledger-action-runs')).toContainText(
			'Elementor action result is grouped.'
		);
		await expect(page.getByTestId('submission-ledger-native-limit')).toContainText(
			'Elementor Form Submissions APIs are unavailable'
		);
		await expect(page.getByTestId('submission-ledger-native-limit')).toContainText(
			'paid but insufficient for native submission-link parity'
		);
	});

	test('renders Elementor ledger submissions when action runs are null', async ({ page }) => {
		const elementorFormId = '91:formabc';
		const encodedElementorFormId = encodeURIComponent(elementorFormId);

		await mockWpJson(page, {
			actions: {
				forms: {
					elementor_pro_forms: [
						{
							id: elementorFormId,
							title: 'Elementor contact page',
							adapter: 'elementor_pro_forms',
							adapter_name: 'Elementor Pro Forms',
							settings: null
						}
					]
				},
				formSourceDescriptors: { elementor_pro_forms: elementorFormsProLimitedDescriptor }
			}
		});
		const directResponse = (data: unknown) => data;

		await page.route(
			`**/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/ledger-settings`,
			(route) =>
				route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(
						directResponse({
							form_source: 'elementor_pro_forms',
							form_id: elementorFormId,
							enabled: true,
							enabled_at: '2026-06-24T22:40:00Z',
							enabled_by_user_id: 1,
							disabled_at: null,
							disabled_by_user_id: null,
							settings_source: 'sentient_submission_ledger_settings',
							ledger_records_endpoint: `/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions`,
							record_count: 1
						})
					)
				})
		);
		await page.route(
			`**/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions**`,
			(route) =>
				route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(
						directResponse({
							form_source: 'elementor_pro_forms',
							form_id: elementorFormId,
							submissions: [
								{
									id: 13,
									submission_uuid: '77777777-8888-4999-aaaa-bbbbbbbbbbbb',
									form_source: 'elementor_pro_forms',
									form_id: elementorFormId,
									native_entry_id: null,
									native_entry_url: null,
									source_submitted_at: null,
									captured_at: '2026-06-24T22:42:00Z',
									logical_fields: {
										email: 'nullable-runs@example.test'
									},
									provider_metadata: {
										form_name: 'Elementor Ledger Form'
									},
									file_refs: [],
									redaction_summary: {
										redacted_keys: []
									},
									action_runs: null,
									expires_at: null,
									detail_endpoint: `/wp-json/sentient-forms/v1/elementor_pro_forms/forms/${encodedElementorFormId}/submissions/77777777-8888-4999-aaaa-bbbbbbbbbbbb`
								}
							],
							count: 1,
							per_page: 50,
							offset: 0
						})
					)
				})
		);

		await page.goto(`/actions/elementor_pro_forms/${encodedElementorFormId}/submissions`, {
			waitUntil: 'networkidle'
		});

		const row = page.getByTestId('submission-ledger-row');
		await expect(row).toContainText('77777777-8888-4999-aaaa-bbbbbbbbbbbb');
		await expect(row.getByTestId('submission-ledger-action-runs')).toContainText('0 action runs');
		await expect(row.getByTestId('submission-ledger-action-runs')).toContainText(
			'No action output recorded yet.'
		);
	});

	test('maps Contact Form 7 built-in Entry Summary to the CF7 after-submission hook', async ({
		page
	}) => {
		await page.addInitScript(() => {
			try {
				localStorage.setItem('sentient_forms_last_hooks', '["gform_validation"]');
			} catch {}
		});

		await mockWpJson(page, {
			actions: {
				forms: { [cf7FormSource]: cf7Forms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation', 'gform_after_submission'],
						base_credit_cost: null,
						model_hint: 'openrouter/auto'
					},
					{
						id: 'entry_summary_v1',
						label: 'Entry Summary',
						source: 'bundled',
						hooks: ['gform_after_submission'],
						base_credit_cost: null,
						model_hint: 'openrouter/auto'
					}
				],
				providerPathPolicy: managedProviderPathPolicy(['spam_detection_v1', 'entry_summary_v1']),
				status: statusUnknown,
				formsActions: [],
				formFields: [
					{ id: 'your-name', label: 'Your name', type: 'text' },
					{ id: 'your-email', label: 'Your email', type: 'email' },
					{ id: 'your-message', label: 'Your message', type: 'textarea' }
				],
				formSourceDescriptors: { [cf7FormSource]: cf7FormSourceDescriptor },
				creditBalance
			},
			customActions: { list: { actions: [], quota } }
		});

		await page.goto('/actions/contact_form_7/77', { waitUntil: 'networkidle' });
		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();

		await drawer
			.locator('label', { hasText: 'Entry Summary' })
			.locator('input[type="radio"]')
			.check();

		await expect(drawer.getByTestId('create-trigger-hook-after_submission')).toBeChecked();
		await expect(drawer.getByTestId('create-trigger-hook-wpcf7_mail_sent')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-gform_validation')).toHaveCount(0);
		await expect(drawer.getByTestId('create-trigger-hook-real_time')).toHaveCount(0);

		const createRequestPromise = page.waitForRequest((request) => {
			const url = new URL(request.url());
			return (
				request.method() === 'POST' && url.pathname.endsWith('/contact_form_7/forms/77/actions')
			);
		});
		const createResponsePromise = page.waitForResponse((response) => {
			const url = new URL(response.url());
			return (
				response.request().method() === 'POST' &&
				url.pathname.endsWith('/contact_form_7/forms/77/actions')
			);
		});
		await drawer.getByRole('button', { name: 'Link action' }).click();
		const createRequest = await createRequestPromise;
		await createResponsePromise;

		const payload = createRequest.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(payload.central_action_id).toBe('entry_summary_v1');
		expect(payload.trigger_hooks).toEqual(['after_submission']);
		expect(settings.execution_mode).toBe('after_submission');
		expect(
			(settings.trigger_sources as Record<string, { type?: string }> | undefined)?.after_submission
				?.type
		).toBe('hook_root');
	});

	test('surfaces only the documented bundled built-ins in the add-action drawer', async ({
		page
	}) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_analysis',
						label: 'Spam Analysis',
						source: 'cps',
						hooks: ['gform_validation'],
						base_credit_cost: 2,
						model_hint: 'gemini-1.5-flash'
					},
					{
						id: 'entry_evaluation',
						label: 'Entry Evaluation',
						source: 'cps',
						hooks: ['gform_after_submission'],
						base_credit_cost: 6,
						model_hint: 'gemini-1.5-pro'
					}
				],
				status: statusUnknown,
				formsActions: [],
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });

		await page.locator('header').getByRole('button', { name: 'Add action' }).click();
		const drawer = page.getByTestId('link-action-form');
		await expect(drawer).toBeVisible();
		await page.getByRole('button', { name: 'Built-in actions' }).click();
		const actionList = page.getByTestId('link-action-scroll-region');
		await expect(actionList.getByText('Spam Detection')).toBeVisible();
		await expect(actionList.getByText('Content Quality Validation')).toBeVisible();
		await expect(actionList.getByText('Entry Summary')).toBeVisible();
		await expect(actionList.getByText('Spam Analysis')).toHaveCount(0);
		await expect(actionList.getByText('Entry Evaluation')).toHaveCount(0);
	});

	test('surfaces repair-needed local-first mappings in the table and modal', async ({ page }) => {
		await mockWpJson(page, {
			actions: {
				forms: { [formSource]: baseForms },
				definitions: [
					{
						id: 'spam_detection_v1',
						label: 'Spam Detection',
						source: 'bundled',
						hooks: ['gform_validation', 'gform_after_submission'],
						base_credit_cost: 2,
						model_hint: 'openrouter/auto'
					}
				],
				status: statusUnknown,
				formsActions: [
					{
						local_mapping_id: 'local_first_repair',
						central_action_id: 'spam_detection_v1',
						action_type_indicator: 'master',
						action_name_label: 'Spam Detection',
						trigger_hooks: ['gform_validation'],
						is_action_enabled_for_form: true,
						linked_action_status: 'archived',
						repair_state: 'needs_repair',
						settings: {
							linked_action_status: 'archived',
							repair_state: 'needs_repair'
						}
					}
				],
				formFields: baseFormFields,
				creditBalance
			},
			customActions: { list: { actions: baseCustomActions, quota } }
		});

		await page.goto('/actions/gravity_forms/123', { waitUntil: 'networkidle' });

		const table = await openLinkedActionsTable(page);
		const row = table.locator('tbody tr').filter({ hasText: 'Spam Detection' });
		await expect(row.getByText('Needs repair')).toBeVisible();
		await expect(
			row.getByText('The linked local action is archived. Re-link or rebuild this mapping.')
		).toBeVisible();
		await row.getByRole('button', { name: 'Repair' }).click();

		const modal = page.getByTestId('mapping-config-modal');
		await expect(modal).toBeVisible();
		await expect(
			modal.getByText('The linked local action is archived. Re-link or rebuild this mapping.')
		).toBeVisible();
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
				providerPathPolicy: managedProviderPathPolicy(['spam_detection_v1', 'summarize']),
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
		expect(
			(settings.trigger_sources as Record<string, { type?: string; mapping_id?: string }>)
				?.gform_validation?.type
		).toBe('mapping');
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
				providerPathPolicy: managedProviderPathPolicy('summarize'),
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
		const actionList = page.getByTestId('link-action-scroll-region');
		await expect(actionList.getByText('Spam Detection', { exact: true })).toBeVisible();

		// Switch to custom actions tab and ensure the sample action is shown
		const customActionsTab = page.getByRole('button', { name: 'Custom actions' });
		await expect(customActionsTab).toBeEnabled();
		await customActionsTab.click();
		await expect(actionList.getByText('Hello action')).toBeVisible();
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
				definitions: [
					{
						...baseDefinitions[0],
						hooks: ['gform_validation', 'gform_after_submission']
					},
					baseDefinitions[1]
				],
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
				definitions: [
					{
						...baseDefinitions[0],
						hooks: ['gform_validation', 'gform_after_submission']
					},
					baseDefinitions[1]
				],
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
		const targetHandle = '[data-nodeid="map-2"][data-handleid="hook-root-target:gform_validation"]';
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
		const rootTarget = '[data-nodeid="map-2"][data-handleid="hook-root-target:gform_validation"]';
		await connectHandlesAndAssert(page, rootSource, rootTarget);

		const updateReq = page.waitForRequest(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		const updateRes = page.waitForResponse(/forms\/\d+\/actions\/map-2$/, { timeout: 15_000 });
		await page.getByTestId('dependency-graph-save').click();
		const request = await updateReq;
		await updateRes;

		const payload = request.postDataJSON() as Record<string, unknown>;
		const settings = (payload.settings ?? {}) as Record<string, unknown>;
		expect(
			(settings.trigger_sources as Record<string, { type?: string }>)?.gform_validation?.type
		).toBe('hook_root');
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
		let draggedBox = await mapTwoCard.boundingBox();
		if (!draggedBox) {
			throw new Error('Could not resolve dragged map-2 card position before edge removal.');
		}
		if (
			Math.max(Math.abs(draggedBox.x - beforeDragBox.x), Math.abs(draggedBox.y - beforeDragBox.y)) <
			12
		) {
			await dragNodeCardByMouse(page, 'map-2', 180, 96);
			draggedBox = await mapTwoCard.boundingBox();
			if (!draggedBox) {
				throw new Error('Could not resolve re-dragged map-2 card position before edge removal.');
			}
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

		const canvasAfterRemove = await graphCanvas.boundingBox();
		if (!canvasAfterRemove) {
			throw new Error('Could not resolve graph canvas position after edge removal.');
		}
		const viewportAfterRemove = await graphCanvas.getAttribute('data-viewport');
		if (!viewportAfterRemove) {
			throw new Error('Could not read viewport state after edge removal.');
		}
		expect(viewportAfterRemove).toBe(viewportBeforeRemove);
		await expectRelativeBoxPositionStable(
			mapOneCard,
			graphCanvas,
			toRelativeBox(mapOneBeforeRemoveBox, canvasBeforeRemove)
		);
		await expectRelativeBoxPositionStable(
			mapTwoCard,
			graphCanvas,
			toRelativeBox(draggedBox, canvasBeforeRemove)
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

	test('shows compact execution-order helper legend and removes redundant per-card drag instruction', async ({
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
		const graph = page.getByTestId('dependency-graph');
		await expect(graph.getByText('Action Execution Order')).toBeVisible();
		await expect(graph.getByText('Drag to reorder')).toBeVisible();
		await expect(graph.getByText('Blue edges run first')).toBeVisible();
		await expect(graph.getByText('Hook roots start a run')).toBeVisible();
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
						hit.closest('[data-testid^="dependency-node-card-"]')?.getAttribute('data-testid') ??
						null;
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
					execution_mode: 'after_submission',
					spam_positive_examples: [
						{
							text: 'Known customer request',
							rationale: 'Existing customers sometimes ask terse follow-up questions.'
						}
					]
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
		await expect(modal.getByTestId('mapping-section-toggle-model_execution')).toContainText(
			'Blocking · Recommended preset'
		);
		await modal.getByTestId('mapping-section-toggle-spam_advanced').click();
		await expect(modal.getByTestId('mapping-section-toggle-spam_advanced')).toContainText(
			'suppress notifications'
		);
		await expect(modal.getByLabel('Notification policy on spam')).toBeEnabled();
		await expect(
			modal.getByText('Current effective value: Suppress notifications (blocking default).')
		).toBeVisible();
		await expect(modal.getByText('Background spam mappings do not hold notifications')).toHaveCount(
			0
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
				definitions: [
					{
						...baseDefinitions[0],
						hooks: ['gform_validation', 'gform_after_submission']
					},
					baseDefinitions[1]
				],
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
		await expect(
			modal.getByTestId('mapping-trigger-hook-gform_after_submission')
		).not.toBeChecked();
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
