import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';

const mockLogEntries = [
	{
		id: 'uuid-1',
		form_source: 'gravity_forms',
		form_id: 1,
		entry_id: 100,
		action_code: 'spam_detection_v1',
		action_label: 'Spam Detection',
		status: 'success',
		result_summary: 'Entry analyzed successfully',
		classification: 'ham',
		credits_used: 10,
		error_code: null,
		error_message: null,
		structured_output_valid: true,
		created_at: '2025-12-26T12:00:00Z',
		completed_at: '2025-12-26T12:00:01Z'
	},
	{
		id: 'uuid-2',
		form_source: 'gravity_forms',
		form_id: 1,
		entry_id: 101,
		action_code: 'spam_detection_v1',
		action_label: 'Spam Detection',
		status: 'success',
		result_summary: 'Detected spam patterns',
		classification: 'spam',
		credits_used: 10,
		error_code: null,
		error_message: null,
		structured_output_valid: false,
		created_at: '2025-12-26T12:05:00Z',
		completed_at: '2025-12-26T12:05:02Z'
	},
	{
		id: 'uuid-3',
		form_source: 'gravity_forms',
		form_id: 2,
		entry_id: 200,
		action_code: 'entry_summary_v1',
		action_label: 'Entry Summary',
		status: 'error',
		result_summary: null,
		classification: null,
		credits_used: 0,
		error_code: 'timeout',
		error_message: 'CPS request timed out after 30 seconds',
		structured_output_valid: false,
		created_at: '2025-12-26T12:10:00Z',
		completed_at: null
	}
];

test.describe('Action Log UI (T-E2E-001, T-E2E-002, T-E2E-003)', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = 'http://127.0.0.1:4175';
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});
	});

	test('action log page displays entries with scanable status/output/result cues', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/actions/log**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					entries: mockLogEntries,
					total: 3,
					total_pages: 1,
					page: 1,
					per_page: 20
				})
			})
		);

		await page.goto('/#/actions/log', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Action Log' })).toBeVisible();
		await expect(page.getByTestId('action-log-loading-state')).toHaveCount(0);
		await expect(page.getByTestId('action-log-table')).toBeVisible();

		const successRow = page.getByTestId('action-log-row-uuid-1');
		await expect(successRow.getByText('Success')).toBeVisible();
		await expect(successRow.getByText('Structured')).toBeVisible();
		await expect(successRow.getByText('Ham')).toBeVisible();

		const spamRow = page.getByTestId('action-log-row-uuid-2');
		await expect(spamRow.getByText('Raw')).toBeVisible();
		await expect(spamRow.getByText(/^Spam$/)).toBeVisible();

		const errorRow = page.getByTestId('action-log-row-uuid-3');
		await expect(errorRow.getByText('Error')).toBeVisible();
		await expect(errorRow.getByText('Not available')).toBeVisible();
		await expect(errorRow.getByText(/timeout/i)).toBeVisible();
	});

	test('action log filtering by status shows active chips and allows chip clear', async ({ page }) => {
		const requestedUrls: string[] = [];

		await page.route('**/wp-json/sentient-forms/v1/actions/log**', (route) => {
			const requestedUrl = route.request().url();
			requestedUrls.push(requestedUrl);
			const url = new URL(requestedUrl);
			const status = url.searchParams.get('status');

			const filtered = status
				? mockLogEntries.filter((entry) => entry.status === status)
				: mockLogEntries;

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					entries: filtered,
					total: filtered.length,
					total_pages: 1,
					page: 1,
					per_page: 20
				})
			});
		});

		await page.goto('/#/actions/log', { waitUntil: 'networkidle' });

		await page.locator('#filter-status').selectOption('error');
		await page.getByTestId('action-log-apply-filters').click();

		await expect(page.getByTestId('action-log-active-filters')).toBeVisible();
		await expect(page.getByTestId('action-log-filter-chip-status')).toContainText('Status:');
		await expect(page.getByTestId('action-log-filter-chip-status')).toContainText('Error');
		await expect.poll(() => requestedUrls[requestedUrls.length - 1] ?? '').toContain('status=error');

		await page.getByTestId('action-log-filter-chip-status').click();

		await expect.poll(() => requestedUrls[requestedUrls.length - 1] ?? '').not.toContain('status=error');
		await expect(page.getByTestId('action-log-active-filters')).toHaveCount(0);
	});

	test('action log pagination shows range and page context', async ({ page }) => {
		const pagedEntries = Array.from({ length: 25 }, (_, index) => ({
			id: `uuid-${index + 1}`,
			form_source: 'gravity_forms',
			form_id: 7,
			entry_id: 700 + index,
			action_code: 'spam_detection_v1',
			action_label: 'Spam Detection',
			status: 'success',
			result_summary: 'Queued for follow-up',
			classification: null,
			credits_used: 10,
			error_code: null,
			error_message: null,
			structured_output_valid: true,
			created_at: '2025-12-26T12:00:00Z',
			completed_at: '2025-12-26T12:00:01Z'
		}));

		await page.route('**/wp-json/sentient-forms/v1/actions/log**', (route) => {
			const url = new URL(route.request().url());
			const requestedPage = Number.parseInt(url.searchParams.get('page') ?? '1', 10);
			const perPage = Number.parseInt(url.searchParams.get('per_page') ?? '20', 10);
			const start = (requestedPage - 1) * perPage;
			const end = start + perPage;

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					entries: pagedEntries.slice(start, end),
					total: pagedEntries.length,
					total_pages: Math.ceil(pagedEntries.length / perPage),
					page: requestedPage,
					per_page: perPage
				})
			});
		});

		await page.goto('/#/actions/log', { waitUntil: 'networkidle' });

		await expect(page.getByTestId('action-log-pagination-range')).toHaveText('Showing 1-20 of 25');
		await expect(page.getByTestId('action-log-pagination-page')).toHaveText('Page 1 of 2');
		await expect(page.getByRole('button', { name: 'Previous' })).toBeDisabled();

		await page.getByRole('button', { name: 'Next' }).click();

		await expect(page.getByTestId('action-log-pagination-range')).toHaveText('Showing 21-25 of 25');
		await expect(page.getByTestId('action-log-pagination-page')).toHaveText('Page 2 of 2');
		await expect(page.getByRole('button', { name: 'Next' })).toBeDisabled();
	});

	test('action log shows empty state when no entries', async ({ page }) => {
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

		await expect(page.getByRole('heading', { name: 'Action Log' })).toBeVisible();
		await expect(page.getByTestId('action-log-empty-state')).toBeVisible();
		await expect(page.getByText(/No action logs yet/i)).toBeVisible();
	});

	test('action log shows error template and recovers on retry', async ({ page }) => {
		let requestCount = 0;

		await page.route('**/wp-json/sentient-forms/v1/actions/log**', (route) => {
			requestCount += 1;
			if (requestCount === 1) {
				return route.fulfill({
					status: 500,
					contentType: 'application/json',
					body: JSON.stringify({
						success: false,
						message: 'Server exploded'
					})
				});
			}

			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					entries: [mockLogEntries[0]],
					total: 1,
					total_pages: 1,
					page: 1,
					per_page: 20
				})
			});
		});

		await page.goto('/#/actions/log', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('action-log-error-state')).toBeVisible();
		await page.getByTestId('action-log-error-state').getByRole('button', { name: 'Retry' }).click();

		await expect(page.getByTestId('action-log-error-state')).toHaveCount(0);
		await expect(page.getByText('Spam Detection')).toBeVisible();
		expect(requestCount).toBeGreaterThanOrEqual(2);
	});

	test('status filter is keyboard-focusable with visible focus classes', async ({ page }) => {
		await page.route('**/wp-json/sentient-forms/v1/actions/log**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify({
					entries: mockLogEntries,
					total: mockLogEntries.length,
					total_pages: 1,
					page: 1,
					per_page: 20
				})
			})
		);

		await page.goto('/#/actions/log', { waitUntil: 'networkidle' });

		const statusFilter = page.locator('#filter-status');
		let reachedFilter = false;
		for (let attempt = 0; attempt < 20; attempt += 1) {
			await page.keyboard.press('Tab');
			reachedFilter = await statusFilter.evaluate((node) => node === document.activeElement);
			if (reachedFilter) {
				break;
			}
		}

		expect(reachedFilter).toBe(true);
		await expect(statusFilter).toBeFocused();
		await expect(statusFilter).toHaveClass(/sf:focus-visible:ring-primary-500/);
	});
});
