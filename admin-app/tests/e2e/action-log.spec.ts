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
        await expect(page.getByText('Spam Detection')).toBeVisible();
        await expect(page.getByText('Entry Summary')).toBeVisible();

        // Check status badges
        await expect(page.getByText('success').first()).toBeVisible();
        await expect(page.getByText('spam')).toBeVisible();
        await expect(page.getByText('ham')).toBeVisible();
        await expect(page.getByText('error')).toBeVisible();
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
        await expect(page.getByText(/No action logs found/i)).toBeVisible();
    });

    /**
     * T-E2E-004: Dashboard shows real license status.
     */
    test('dashboard displays real license status from API', async ({ page }) => {
        await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    status: 'active',
                    license_key_masked: 'ABCD****WXYZ',
                    proxy_key_present: true,
                    tier: 'pro',
                    expires_at: '2026-12-31T23:59:59Z',
                    last_synced: '2025-12-26T12:00:00Z',
                    license_id: 'lic-123',
                    site_id: 'site-456',
                    site_url: 'https://example.com'
                })
            })
        );

        await page.route('**/wp-json/sentient-forms/v1/credits', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    credits_remaining: 875,
                    credits_used_total: 125
                })
            })
        );

        await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
        await expect(page.getByText('active')).toBeVisible();
        await expect(page.getByText('875')).toBeVisible();
    });

    /**
     * T-E2E-005: Dashboard shows loading state and handles API errors.
     */
    test('dashboard handles API errors gracefully', async ({ page }) => {
        await page.route('**/wp-json/sentient-forms/v1/license', (route) =>
            route.fulfill({ status: 500, contentType: 'application/json', body: '{"error": "Internal error"}' })
        );

        await page.route('**/wp-json/sentient-forms/v1/credits', (route) =>
            route.fulfill({ status: 500, contentType: 'application/json', body: '{"error": "Internal error"}' })
        );

        await page.goto('/#/dashboard', { waitUntil: 'networkidle' });

        await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
        // Should show some fallback/error state but not crash
        await expect(page.getByText(/Failed|error|unknown/i)).toBeVisible();
    });
});
