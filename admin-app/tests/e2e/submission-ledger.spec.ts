import { expect, test } from '@playwright/test';
import { seedRuntimeConfig } from './utils/runtime-config';

const ledgerSettings = {
	form_source: 'gravity_forms',
	form_id: '42',
	enabled: true,
	enabled_at: '2030-01-01T00:00:00Z',
	enabled_by_user_id: 1,
	disabled_at: null,
	disabled_by_user_id: null,
	settings_source: 'sentient_submission_ledger_settings',
	ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/42/submissions',
	record_count: 61
};

function record(index: number, overrides: Record<string, unknown> = {}) {
	return {
		id: index,
		submission_uuid: `123e4567-e89b-42d3-a456-${String(index).padStart(12, '0')}`,
		form_source: 'gravity_forms',
		form_id: '42',
		native_entry_id: String(1000 + index),
		native_entry_url: `https://example.test/wp-admin/admin.php?page=gf_entries&id=42&lid=${1000 + index}`,
		source_submitted_at: '2030-01-05T10:00:00Z',
		captured_at: `2030-01-05T10:${String(index).padStart(2, '0')}:00Z`,
		logical_fields: { name: `Lead ${index}`, message: `General inquiry ${index}` },
		provider_metadata: {},
		file_refs: [],
		redaction_summary: {},
		expires_at: null,
		detail_endpoint: `/sentient-forms/v1/gravity_forms/forms/42/submissions/${index}`,
		...overrides
	};
}

test.describe('Submission Ledger admin view', () => {
	test.beforeEach(async ({ page }) => {
		await seedRuntimeConfig(page, { apiBaseUrl: '/wp-json/sentient-forms/v1/' });
	});

	test('searches the server ledger and reports filtered totals', async ({ page }) => {
		const seenSubmissionQueries: string[] = [];

		await page.context().route('**/wp-json/sentient-forms/v1/**', (route) => {
			const requestUrl = new URL(route.request().url());
			const path = requestUrl.pathname;

			if (path.endsWith('/gravity_forms/forms/42/ledger-settings')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(ledgerSettings)
				});
			}

			if (path.endsWith('/gravity_forms/forms/42/submissions')) {
				seenSubmissionQueries.push(requestUrl.search);
				const q = requestUrl.searchParams.get('q') ?? '';
				const offset = Number(requestUrl.searchParams.get('offset') ?? '0');
				const perPage = Number(requestUrl.searchParams.get('per_page') ?? '10');
				const matchingRecords = q
					? [
							record(77, {
								captured_at: '2030-01-05T10:17:00Z',
								native_entry_id: 'needle-entry-77',
								logical_fields: {
									name: 'Needle Prospect',
									message: 'needle prospect asks about a custom integration.'
								}
							})
						]
					: Array.from({ length: perPage }, (_, index) => record(offset + index + 1));

				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						form_source: 'gravity_forms',
						form_id: '42',
						submissions: matchingRecords,
						total: q ? 1 : 61,
						count: matchingRecords.length,
						per_page: perPage,
						offset
					})
				});
			}

			return route.continue();
		});

		await page.goto('/#/actions/gravity_forms/42/submissions');
		await expect(page.getByTestId('submission-ledger-results-summary')).toContainText(
			'Showing 1-10 of 61'
		);

		await page.getByTestId('submission-ledger-search').fill('needle prospect');
		await expect(page.getByTestId('submission-ledger-results-summary')).toContainText(
			'Showing 1 of 1'
		);
		await expect(page.getByText('needle-entry-77')).toBeVisible();
		expect(seenSubmissionQueries.some((query) => query.includes('q=needle+prospect'))).toBe(true);
	});

	test('keeps newer filtered results when an older ledger request resolves later', async ({
		page
	}) => {
		let firstSubmissionRequest: (() => void) | null = null;
		const releaseFirstSubmissionRequest = new Promise<void>((resolve) => {
			firstSubmissionRequest = resolve;
		});

		await page.context().route('**/wp-json/sentient-forms/v1/**', async (route) => {
			const requestUrl = new URL(route.request().url());
			const path = requestUrl.pathname;

			if (path.endsWith('/gravity_forms/forms/42/ledger-settings')) {
				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify(ledgerSettings)
				});
			}

			if (path.endsWith('/gravity_forms/forms/42/submissions')) {
				const q = requestUrl.searchParams.get('q') ?? '';
				const offset = Number(requestUrl.searchParams.get('offset') ?? '0');
				const perPage = Number(requestUrl.searchParams.get('per_page') ?? '10');

				if (!q) {
					await releaseFirstSubmissionRequest;
				}

				const matchingRecords = q
					? [
							record(77, {
								captured_at: '2030-01-05T10:17:00Z',
								native_entry_id: 'needle-entry-77',
								logical_fields: {
									name: 'Needle Prospect',
									message: 'needle prospect asks about a custom integration.'
								}
							})
						]
					: Array.from({ length: perPage }, (_, index) => record(offset + index + 1));

				return route.fulfill({
					status: 200,
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({
						form_source: 'gravity_forms',
						form_id: '42',
						submissions: matchingRecords,
						total: q ? 1 : 61,
						count: matchingRecords.length,
						per_page: perPage,
						offset
					})
				});
			}

			return route.continue();
		});

		await page.goto('/#/actions/gravity_forms/42/submissions');
		await page.getByTestId('submission-ledger-search').fill('needle prospect');
		await expect(page.getByTestId('submission-ledger-results-summary')).toContainText(
			'Showing 1 of 1'
		);

		firstSubmissionRequest?.();

		await expect(page.getByTestId('submission-ledger-results-summary')).toContainText(
			'Showing 1 of 1'
		);
		await expect(page.getByText('needle-entry-77')).toBeVisible();
		await expect(page.getByText('Lead 1')).toHaveCount(0);
	});
});
