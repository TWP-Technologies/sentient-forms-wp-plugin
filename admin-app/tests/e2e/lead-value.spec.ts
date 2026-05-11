import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

const profileResponse = {
	profile: {
		id: 22,
		form_source: 'gravity_forms',
		form_id: '123',
		status: 'active',
		profile_version: 3,
		consented_at: '2030-01-05T10:00:00Z',
		good_lead_criteria: {
			summary_text:
				'Good leads have a clear project, reachable contact details, service-area fit, and a practical next step.'
		},
		bad_lead_criteria: {
			summary_text:
				'Bad leads are irrelevant, spam-like, abusive, impossible to contact, or only asking for unrelated promotion.'
		},
		example_entries: [
			{
				entry_id: '1001',
				grade: 'A',
				rationale: 'Known booked project with a clear requested service.',
				snapshot: {
					field_summary: [
						{ field_id: '1', label: 'Name', value: 'Ada Lovelace' },
						{ field_id: '2', label: 'Email', value: 'ada@example.test' }
					]
				}
			}
		],
		grading_rubric: {
			scale: { A: 'Strong fit', B: 'Likely fit', C: 'Possible fit', Reject: 'Reject' }
		},
		generated_profile_prompt: '<TRUSTED_SITE_CONTEXT>ready</TRUSTED_SITE_CONTEXT>',
		generation_metadata: { generation_mode: 'local_readiness_grounded_profile_v1' },
		assistant: {
			status: 'ready',
			questions: [
				{
					key: 'handoff',
					question: 'Which grades should trigger handoff?',
					why: 'Handoff should stay intentional.'
				}
			]
		},
		handoff_rules: {
			email_recipients: ['sales@example.test'],
			webhooks: [{ url: 'https://example.test/hook', method: 'POST' }],
			grades: ['A', 'B']
		}
	},
	readiness: {
		ready: true,
		blockers: [],
		requirements: [
			{
				key: 'lead_profile_consent',
				label: 'Lead-profile consent',
				met: true,
				severity: 'blocker',
				detail: 'Consent granted.'
			},
			{
				key: 'site_context',
				label: 'Site Context depth',
				met: true,
				severity: 'blocker',
				detail: '140 words saved.'
			},
			{
				key: 'spam_guidance_positive',
				label: 'Positive Spam Guidance examples',
				met: true,
				severity: 'blocker',
				detail: '3 legitimate examples saved.'
			}
		],
		site_context: {
			summary_text: 'Long enough context',
			word_count: 140,
			consented: true,
			consent_status: 'granted'
		},
		spam_guidance: {
			positive_count: 3,
			negative_count: 3
		},
		good_word_count: 17,
		bad_word_count: 16
	}
};

const dashboardResponse = {
	form_source: 'gravity_forms',
	form_id: '123',
	event_count: 8,
	successful_events: 7,
	failed_events: 1,
	grades: { A: 2, B: 3, C: 1, Reject: 1, ungraded: 1 },
	suggested_replies: 4,
	historical_runs: [
		{
			id: 50,
			form_source: 'gravity_forms',
			form_id: '123',
			action_code: 'lead_grading_v1',
			selected_entry_ids: ['1001', '1002'],
			filters: {},
			estimated_entry_count: 2,
			estimated_managed_credits: 6,
			dry_run: true,
			status: 'preview_ready',
			progress: { processed: 0, total: 2, errors: [] }
		}
	]
};

test.describe('lead value workspace', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		await page.route('**/wp-json/sentient-forms/v1/lead-value/forms/gravity_forms/123/profile**', async (route) => {
			if (route.request().method() === 'POST') {
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(profileResponse)
				});
			}
			return route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(profileResponse)
			});
		});
		await page.route('**/wp-json/sentient-forms/v1/lead-value/profiles/22/generate**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(profileResponse)
			})
		);
		await page.route('**/wp-json/sentient-forms/v1/lead-value/profiles/22/assistant**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(profileResponse)
			})
		);
		await page.route('**/wp-json/sentient-forms/v1/lead-value/forms/gravity_forms/123/dashboard**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(dashboardResponse)
			})
		);
		await page.route(
			'**/wp-json/sentient-forms/v1/lead-value/forms/gravity_forms/123/historical-runs**',
			(route) => {
				if (route.request().method() === 'POST') {
					return route.fulfill({
						status: 201,
						contentType: 'application/json',
						body: JSON.stringify({
							run: {
								...dashboardResponse.historical_runs[0],
								id: 51,
								dry_run: false,
								status: 'preview_ready'
							}
						})
					});
				}

				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({ runs: dashboardResponse.historical_runs })
				});
			}
		);
		await page.route(
			'**/wp-json/sentient-forms/v1/lead-value/historical-runs/51/start**',
			(route) =>
				route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						run: {
							...dashboardResponse.historical_runs[0],
							id: 51,
							dry_run: false,
							status: 'completed',
							progress: { processed: 2, total: 2, errors: [] }
						}
					})
				})
		);
		await page.route(
			'**/wp-json/sentient-forms/v1/lead-value/forms/gravity_forms/123/entries/search**',
			(route) =>
				route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						form_source: 'gravity_forms',
						form_id: 123,
						entries: [
							{
								id: '1002',
								date_created: '2030-01-06T12:00:00Z',
								status: 'active',
								field_summary: [
									{ field_id: '1', label: 'Name', value: 'Grace Hopper' },
									{ field_id: '2', label: 'Project', value: 'Website rebuild' }
								]
							}
						]
					})
				})
		);
	});

	test('shows readiness, dashboard, and historical scoring controls', async ({ page }) => {
		await page.goto('/#/actions/gravity_forms/123/lead-value', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Lead Value' })).toBeVisible();
		await expect(page.getByTestId('lead-value-summary')).toContainText('Ready');
		await expect(page.getByLabel('Good lead criteria')).toHaveValue(/Good leads have/);
		await expect(page.getByText('Entry #1001')).toBeVisible();
		await page.getByRole('button', { name: 'Search entries' }).click();
		await expect(page.getByText('Entry #1002')).toBeVisible();
		await page.getByRole('button', { name: 'Mark B' }).click();
		await expect(page.getByText('2 selected for profile calibration')).toBeVisible();
		await page.getByRole('button', { name: 'Dashboard' }).click();
		await expect(page.getByRole('heading', { name: 'Grade Distribution' })).toBeVisible();
		await expect(page.getByText('reply drafts')).toBeVisible();
		await page.getByRole('button', { name: 'Historical' }).click();
		await expect(page.getByRole('button', { name: 'Create dry-run preview' })).toBeVisible();
		await page.getByRole('button', { name: 'Run confirmed' }).click();
		await expect(page.getByText('completed')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Start', exact: true })).toHaveCount(0);
	});
});
