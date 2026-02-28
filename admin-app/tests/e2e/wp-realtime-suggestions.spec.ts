import { expect, test } from '@playwright/test';
import {
	configureGravityActionMapping,
	ensureGravityForm,
	requireWpRestHealthy,
	waitForPreviewInputs
} from './utils/wp-e2e-helpers';
import { installSentientCorsProxy } from './utils/cors-proxy';
import { loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const formTitle = `Playwright Realtime Suggestions ${Date.now()}`;
const fields = [
	{ type: 'text', id: 1, label: 'Issue summary', isRequired: true },
	{ type: 'text', id: 2, label: 'Current context', isRequired: false },
	{ type: 'page', id: 3, label: 'Page break' },
	{ type: 'textarea', id: 4, label: 'Mitigation details', isRequired: false }
];

let formId = 0;

async function openRealtimePreview(page: Parameters<typeof test>[0]['page'], manualRefreshEnabled = true) {
	if (!formId) {
		formId = ensureGravityForm(formTitle, fields);
	}

	configureGravityActionMapping({
		formId,
		actionId: 'realtime_suggest',
		localMappingId: 'map_realtime_suggest',
		centralActionId: 'spam_detection_v1',
		actionNameLabel: 'Realtime Suggestions',
		hooks: ['gform_validation'],
		async: false,
		rejectSubmission: false,
		markAsSpam: false,
		executionPriority: 1,
		actionTypeIndicator: 'master',
		executionMode: 'real_time',
		realtimeSettings: {
			checkpointFieldIds: ['1'],
			debounceMs: 300,
			cooldownMs: 1000,
			manualRefreshEnabled
		}
	});

	await loginToWpAdmin(page);
	await page.goto(`${wpBaseUrl}/?gf_page=preview&id=${formId}`, { waitUntil: 'domcontentloaded' });
	await waitForPreviewInputs(page, formId);
	await expect(page.locator('.sentient-forms-realtime-widget')).toBeVisible();
}

test.describe('Gravity Forms realtime suggestions @realtime-suggestions', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise realtime suggestion flows.');

	test.beforeEach(async ({ page }) => {
		if (runWpE2E) {
			await installSentientCorsProxy(page);
			await requireWpRestHealthy(page);
		}
	});

	test('checkpoint gating + visible rendering + page-change suggestions + focus hotlink', async ({ page }) => {
		const requests: Array<Record<string, unknown>> = [];

		await page.route('**/actions/suggest*', async (route, request) => {
			const payload = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;
			requests.push(payload);
			const currentPage = Number(payload.current_page_index ?? 1);
			const responseBody =
				currentPage >= 2
					? {
						status: 'success',
						suggestions: [
							{
								suggestion_id: 'a4017ddc-c7f9-4850-9ef0-6d58f9ecf821',
								field_id: '4',
								severity: 'warning',
								message: 'Provide mitigation details on page 2.',
								jump_target_field_id: '4',
								is_suppressed: false
							}
						],
						meta: { execution_request_id: 'rt-page-2' }
					}
					: {
						status: 'success',
						suggestions: [
							{
								suggestion_id: '0a0a57f6-5437-4cf9-8d57-c9cf21af9695',
								field_id: '1',
								severity: 'warning',
								message: 'Please add specifics to your issue summary.',
								jump_target_field_id: '1',
								is_suppressed: false
							},
							{
								suggestion_id: '6a08bc82-8672-4797-b6fd-8940cd95ff6b',
								field_id: '4',
								severity: 'info',
								message: 'This hidden field should not render on page 1.',
								jump_target_field_id: '4',
								is_suppressed: false
							},
							{
								suggestion_id: '7f550875-c9d7-4ef6-b1ee-9600952d1110',
								field_id: '1',
								severity: 'info',
								message: 'Suppressed future-mitigated guidance.',
								jump_target_field_id: '1',
								is_suppressed: true,
								suppression_reason: 'mitigated_by_future_field'
							}
						],
						meta: {
							execution_request_id: 'rt-page-1',
							correlation_id: 'rt-page-1',
							credits_debited: 3
						}
					};

			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(responseBody)
			});
		});

		await openRealtimePreview(page, true);

		await page.fill('input[name="input_2"]', 'non-checkpoint text');
		await page.locator('input[name="input_1"]').click();
		await page.waitForTimeout(700);
		expect(requests.length).toBe(0);

		await page.fill('input[name="input_1"]', 'Need detailed help');
		await page.locator('input[name="input_2"]').click();

		await expect.poll(() => requests.length, { timeout: 4000 }).toBe(1);
		expect(requests[0]?.mapping_id).toBe('map_realtime_suggest');

		const widget = page.locator('.sentient-forms-realtime-widget');
		await expect(widget).toContainText('Please add specifics to your issue summary.');
		await expect(widget).not.toContainText('This hidden field should not render on page 1.');
		await expect(widget).not.toContainText('Suppressed future-mitigated guidance.');
		await expect(widget).toContainText('Credits: 3');
		await expect(widget).toContainText('Run: rt-page-1');

		const nextButton = page.locator('.gform_next_button').first();
		await expect(nextButton).toBeVisible();
		await nextButton.click();

		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
		await expect.poll(() => requests.length, { timeout: 4000 }).toBe(2);
		expect(requests[1]?.current_page_index).toBe(2);
		await expect(widget).toContainText('Provide mitigation details on page 2.');

		const focusButton = widget.locator('.sentient-forms-realtime-widget__focus[data-field-id="4"]');
		await focusButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeFocused();
	});

	test('manual refresh button respects mapping policy when disabled', async ({ page }) => {
		const requests: Array<Record<string, unknown>> = [];
		await page.route('**/actions/suggest*', async (route, request) => {
			requests.push(JSON.parse(request.postData() ?? '{}') as Record<string, unknown>);
			await route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					status: 'success',
					suggestions: [],
					meta: { execution_request_id: 'rt-manual-disabled' }
				})
			});
		});

		await openRealtimePreview(page, false);

		await page.fill('input[name="input_1"]', 'checkpoint trigger');
		await page.locator('input[name="input_2"]').click();
		await expect.poll(() => requests.length, { timeout: 4000 }).toBe(1);

		await page.locator('.sentient-forms-realtime-widget__refresh').click();
		await page.waitForTimeout(700);
		expect(requests.length).toBe(1);
	});

	test('429 suggest response is non-blocking and surfaces retry feedback', async ({ page }) => {
		await page.route('**/actions/suggest*', async (route) => {
			await route.fulfill({
				status: 429,
				contentType: 'application/json',
				body: JSON.stringify({ message: 'Suggestion rate limit exceeded. Please wait and retry.' })
			});
		});

		await openRealtimePreview(page, true);

		await page.fill('input[name="input_1"]', 'checkpoint trigger');
		await page.locator('input[name="input_2"]').click();
		await expect(page.locator('.sentient-forms-realtime-widget__error')).toContainText(
			'Suggestion rate limit exceeded. Please wait and retry.'
		);

		const nextButton = page.locator('.gform_next_button').first();
		await expect(nextButton).toBeEnabled();
		await nextButton.click();
		await expect(page.locator('textarea[name="input_4"]')).toBeVisible();
	});
});
