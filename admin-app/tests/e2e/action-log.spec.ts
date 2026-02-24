import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';
import { mockWpJson } from './utils/mock-wpjson';

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

    /**
     * T-E2E-001: Action log page displays entries in a table.
     */
	    test('action log page displays entries in table', async ({ page }) => {
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
	        await expect(page.getByText('Spam Detection')).toHaveCount(2);
	        await expect(page.getByText('Entry Summary')).toBeVisible();

        // Check status badges
        const tableBody = page.locator('tbody');
        await expect(tableBody.getByText(/^success$/).first()).toBeVisible();
        await expect(tableBody.getByText(/^spam$/).first()).toBeVisible();
        await expect(tableBody.getByText(/^ham$/).first()).toBeVisible();
        await expect(tableBody.getByText(/^error$/).first()).toBeVisible();
    });

    /**
     * T-E2E-002: Action log filters work correctly.
     */
    test('action log filtering by status works', async ({ page }) => {
        let requestedUrl = '';

        await page.route('**/wp-json/sentient-forms/v1/actions/log**', (route) => {
            requestedUrl = route.request().url();
            const url = new URL(requestedUrl);
            const status = url.searchParams.get('status');

            const filtered = status
                ? mockLogEntries.filter(e => e.status === status)
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

        // Select 'error' status
        await page.locator('select').selectOption('error');
        await page.getByRole('button', { name: 'Apply' }).click();

        // Wait for new request
        await page.waitForTimeout(500);

        // Should only show error entries
        await expect(page.getByText('Entry Summary')).toBeVisible();
        // The success entries should not be visible
        expect(requestedUrl).toContain('status=error');
    });

    /**
     * T-E2E-003: Action log shows empty state when no entries.
     */
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
	            if (reachedFilter) break;
	        }

	        expect(reachedFilter).toBe(true);
	        await expect(statusFilter).toBeFocused();
	        await expect(statusFilter).toHaveClass(/sf:focus-visible:ring-primary-500/);
	    });

	});
