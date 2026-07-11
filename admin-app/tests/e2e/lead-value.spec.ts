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
			grades: ['A', 'B'],
			entry_notes: { lead_grade: true, suggested_reply: true }
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
	metrics: {
		scored_leads: 7,
		priority_leads: 2,
		reply_drafts: 4,
		rejected_leads: 1,
		grades: { A: 2, B: 3, C: 1, Reject: 1, ungraded: 1 }
	},
	entries: [
		{
			form_source: 'gravity_forms',
			form_id: '123',
			form_title: 'Lead intake',
			entry_id: '1001',
			entry_snapshot: {
				date_created: '2030-01-05T10:00:00Z',
				status: 'active',
				field_summary: [
					{ field_id: '1', label: 'Name', value: 'Ada Lovelace' },
					{ field_id: '2', label: 'Project', value: 'Paid implementation' }
				]
			},
			grade: 'A',
			confidence: 0.92,
			priority: 'urgent',
			justification:
				'The entry names a concrete paid implementation project, includes reachable contact details, and matches the service area.',
			next_best_action: 'Route to sales for same-day follow-up.',
			suggested_reply_draft: 'Thanks for reaching out. We can help with that implementation.',
			reply_rationale: 'The lead is specific and time-sensitive.',
			profile_version: 3,
			lead_execution_id: 'lead:1001',
			reply_execution_id: 'reply:1001'
		}
	],
	entry_page: 1,
	entry_per_page: 10,
	entry_total: 1,
	entry_pages: 1,
	forms: [
		{
			form_source: 'gravity_forms',
			form_id: '123',
			form_title: 'Lead intake',
			scored_leads: 7,
			priority_leads: 2,
			reply_drafts: 4,
			latest_at: '2030-01-05T10:00:00Z',
			profile_id: 22,
			profile_version: 3,
			setup_status: 'active'
		},
		{
			form_source: 'gravity_forms',
			form_id: '124',
			form_title: 'Consultation request',
			scored_leads: 4,
			priority_leads: 1,
			reply_drafts: 3,
			latest_at: '2030-01-05T09:00:00Z',
			profile_id: 23,
			profile_version: 2,
			setup_status: 'active'
		},
		{
			form_source: 'gravity_forms',
			form_id: '125',
			form_title: 'Agency intake',
			scored_leads: 9,
			priority_leads: 3,
			reply_drafts: 7,
			latest_at: '2030-01-04T09:00:00Z',
			profile_id: 24,
			profile_version: 5,
			setup_status: 'active'
		},
		{
			form_source: 'gravity_forms',
			form_id: '126',
			form_title: 'Partner inquiry',
			scored_leads: 2,
			priority_leads: 0,
			reply_drafts: 1,
			latest_at: '2030-01-03T09:00:00Z',
			profile_id: 25,
			profile_version: 1,
			setup_status: 'draft'
		},
		{
			form_source: 'gravity_forms',
			form_id: '127',
			form_title: 'Enterprise demo',
			scored_leads: 12,
			priority_leads: 6,
			reply_drafts: 10,
			latest_at: '2030-01-02T09:00:00Z',
			profile_id: 26,
			profile_version: 4,
			setup_status: 'active'
		}
	],
	unconfigured_forms: [
		{
			form_source: 'gravity_forms',
			form_id: '128',
			form_title: 'Newsletter signup',
			scored_leads: 0,
			priority_leads: 0,
			reply_drafts: 0,
			setup_status: 'not_configured'
		},
		{
			form_source: 'gravity_forms',
			form_id: '129',
			form_title: 'Support request',
			scored_leads: 0,
			priority_leads: 0,
			reply_drafts: 0,
			setup_status: 'not_configured'
		},
		{
			form_source: 'gravity_forms',
			form_id: '130',
			form_title: 'Event registration',
			scored_leads: 0,
			priority_leads: 0,
			reply_drafts: 0,
			setup_status: 'not_configured'
		},
		{
			form_source: 'gravity_forms',
			form_id: '131',
			form_title: 'Quote request',
			scored_leads: 0,
			priority_leads: 0,
			reply_drafts: 0,
			setup_status: 'not_configured'
		},
		{
			form_source: 'gravity_forms',
			form_id: '132',
			form_title: 'Careers contact',
			scored_leads: 0,
			priority_leads: 0,
			reply_drafts: 0,
			setup_status: 'not_configured'
		}
	],
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

const actionConfigResponse = {
	form_source: 'gravity_forms',
	form_id: 123,
	action_id: 'spam_detection_v1',
	config: {
		spam_positive_examples: [
			{ text: 'I need a paid implementation quote.', rationale: 'Specific service intent.' }
		],
		spam_negative_examples: [
			{ text: 'Buy cheap links now.', rationale: 'Unrelated promotional spam.' }
		]
	}
};

test.describe('lead scoring workspace', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		await page.route(
			'**/wp-json/sentient-forms/v1/lead-value/forms/gravity_forms/123/profile**',
			async (route) => {
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
			}
		);
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
		await page.route(
			'**/wp-json/sentient-forms/v1/lead-value/forms/gravity_forms/123/dashboard**',
			(route) =>
				route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(dashboardResponse)
				})
		);
		await page.route('**/wp-json/sentient-forms/v1/lead-value/dashboard**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(dashboardResponse)
			})
		);
		await page.route('**/wp-json/sentient-forms/v1/actions/spam_detection_v1/defaults**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(actionConfigResponse)
			})
		);
		await page.route(
			'**/wp-json/sentient-forms/v1/forms/gravity_forms/123/action-config/spam_detection_v1**',
			(route) =>
				route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify(actionConfigResponse)
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

		await expect(page.getByRole('heading', { name: 'Lead Scoring' })).toBeVisible();
		await expect(page.getByTestId('lead-scoring-summary')).toContainText('Scored leads');
		await expect(page.getByRole('heading', { name: 'Scored entries' })).toBeVisible();
		await expect(
			page.getByRole('table').getByText('The entry names a concrete paid implementation project')
		).toBeVisible();
		await page.getByRole('link', { name: 'Open detail' }).first().click();
		await expect(page.getByRole('dialog')).toContainText('Route to sales for same-day follow-up.');
		await page.getByRole('button', { name: 'Close' }).click();

		await page.getByRole('button', { name: 'Setup' }).click();
		await expect(page.getByLabel('Good lead criteria')).toHaveValue(/Good leads have/);
		await expect(page.getByRole('heading', { name: 'Spam Guidance' })).toBeVisible();
		await expect(page.getByText('Entry #1001')).toBeVisible();
		await page.getByRole('button', { name: 'Search entries' }).click();
		await expect(page.getByText('Entry #1002')).toBeVisible();
		await page.getByRole('button', { name: 'Mark B' }).click();
		await expect(page.getByText('2 selected for setup calibration')).toBeVisible();
		await page.getByRole('button', { name: 'Dashboard' }).click();
		await expect(page.getByRole('heading', { name: 'Grade Distribution' })).toBeVisible();
		await expect(page.getByTestId('lead-scoring-summary')).toContainText('Follow-up drafts');
		await page.getByRole('button', { name: 'Historical' }).click();
		await expect(page.getByRole('button', { name: 'Create dry-run preview' })).toBeVisible();
		await page.getByRole('button', { name: 'Run confirmed' }).click();
		await expect(page.getByText('completed')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Start', exact: true })).toHaveCount(0);
	});

	test('shows aggregate lead scoring dashboard across forms', async ({ page }) => {
		await page.goto('/#/lead-scoring', { waitUntil: 'networkidle' });

		await expect(page.getByRole('heading', { name: 'Lead Scoring' })).toBeVisible();
		await expect(page.getByTestId('lead-scoring-aggregate-summary')).toContainText('Scored leads');
		await expect(page.getByRole('heading', { name: 'Scored entries' })).toBeVisible();
		await expect(page.getByRole('table').getByText('Lead intake').first()).toBeVisible();
		await expect(page.getByRole('table').getByText('#1001')).toBeVisible();
		await expect(
			page.getByRole('table').getByText('Route to sales for same-day follow-up.')
		).toBeVisible();
		await expect(page.getByTestId('lead-scoring-grade-distribution')).toBeVisible();
		await expect(page.getByRole('heading', { name: 'Quick Jump' })).toBeVisible();
		await expect(page.getByRole('heading', { name: 'Configured Forms' })).toBeVisible();

		const gradeBox = await page.getByTestId('lead-scoring-grade-distribution').boundingBox();
		const quickJumpBox = await page.getByRole('heading', { name: 'Quick Jump' }).boundingBox();
		const configuredBox = await page
			.getByRole('heading', { name: 'Configured Forms' })
			.boundingBox();
		expect(gradeBox?.y ?? 0).toBeLessThan(quickJumpBox?.y ?? 0);
		expect(quickJumpBox?.y ?? 0).toBeLessThan(configuredBox?.y ?? 0);

		const quickJumpList = page.getByTestId('lead-scoring-quick-jump-list');
		const configuredFormsList = page.getByTestId('lead-scoring-configured-forms-list');
		await expect(quickJumpList).toContainText('Gravity Forms');
		await expect(configuredFormsList).toContainText('Gravity Forms');
		expect(await quickJumpList.evaluate((node) => node.scrollHeight > node.clientHeight)).toBe(
			true
		);
		expect(
			await configuredFormsList.evaluate((node) => node.scrollHeight > node.clientHeight)
		).toBe(true);
	});

	test('keeps local correction and hides Gravity-only replies for Elementor aggregate rows', async ({
		page
	}) => {
		const elementorFormId = '91:formabc';
		const encodedElementorFormId = encodeURIComponent(elementorFormId);
		const elementorDashboardResponse = {
			...dashboardResponse,
			form_source: 'elementor_pro_forms',
			form_id: elementorFormId,
			entries: [
				{
					...dashboardResponse.entries[0],
					form_source: 'elementor_pro_forms',
					form_id: elementorFormId,
					form_title: 'Elementor lead form',
					provider_label: 'Elementor Pro Forms',
					entry_id: 'sf-ledger-1',
					lead_execution_id: 'lead:elementor:sf-ledger-1',
					reply_execution_id: 'reply:elementor:sf-ledger-1'
				}
			],
			forms: [
				{
					...dashboardResponse.forms[0],
					form_source: 'elementor_pro_forms',
					form_id: elementorFormId,
					form_title: 'Elementor lead form',
					provider_label: 'Elementor Pro Forms'
				}
			],
			unconfigured_forms: []
		};

		await page.route('**/wp-json/sentient-forms/v1/lead-value/dashboard**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(elementorDashboardResponse)
			})
		);
		let correctionRequests = 0;
		await page.route(
			`**/wp-json/sentient-forms/v1/lead-value/forms/elementor_pro_forms/${encodedElementorFormId}/entries/sf-ledger-1/correction`,
			async (route) => {
				correctionRequests += 1;
				expect(route.request().method()).toBe('POST');
				const payload = route.request().postDataJSON() as {
					grade?: string;
					justification?: string;
				};
				expect(payload.grade).toBe('B');
				expect(payload.justification).toBe('Human review found a likely fit.');
				return route.fulfill({
					status: 200,
					contentType: 'application/json',
					body: JSON.stringify({
						entry: {
							...elementorDashboardResponse.entries[0],
							grade: 'B',
							correction: {
								original_grade: 'A',
								grade: 'B',
								justification: 'Human review found a likely fit.'
							}
						},
						dashboard: elementorDashboardResponse
					})
				});
			}
		);

		await page.goto(
			`/lead-scoring?entry=sf-ledger-1&form_source=elementor_pro_forms&form_id=${encodedElementorFormId}`,
			{ waitUntil: 'networkidle' }
		);
		await expect(page.getByRole('table').getByText('Elementor lead form')).toBeVisible();

		const dialog = page.getByRole('dialog');
		await expect(dialog).toContainText('Entry #sf-ledger-1');
		await expect(dialog.getByRole('button', { name: 'Correct grade' })).toBeVisible();
		await expect(dialog.getByRole('button', { name: 'Generate reply' })).toHaveCount(0);
		await expect(dialog).toContainText(
			'Manual reply generation for Elementor Pro Forms requires proven native Form Submissions support.'
		);
		await dialog.getByRole('button', { name: 'Correct grade' }).click();
		const correctionDialog = page.getByRole('dialog', { name: 'Correct this grade' });
		await correctionDialog.getByLabel('Corrected Grade').selectOption('B');
		await correctionDialog
			.getByLabel('Correction Justification')
			.fill('Human review found a likely fit.');
		await correctionDialog.getByRole('button', { name: 'Save Correction' }).click();
		await expect(correctionDialog).toHaveCount(0);
		expect(correctionRequests).toBe(1);
	});

	test('routes Elementor aggregate setup links to form actions instead of Lead Scoring setup', async ({
		page
	}) => {
		const elementorDashboardResponse = {
			...dashboardResponse,
			form_source: 'elementor_pro_forms',
			form_id: '91:formabc',
			entries: [
				{
					...dashboardResponse.entries[0],
					form_source: 'elementor_pro_forms',
					form_id: '91:formabc',
					form_title: 'Elementor lead form',
					provider_label: 'Elementor Pro Forms',
					entry_id: 'sf-ledger-1'
				}
			],
			forms: [
				{
					...dashboardResponse.forms[0],
					form_source: 'elementor_pro_forms',
					form_id: '91:formabc',
					form_title: 'Elementor lead form',
					provider_label: 'Elementor Pro Forms'
				}
			],
			unconfigured_forms: [
				{
					...dashboardResponse.unconfigured_forms[0],
					form_source: 'elementor_pro_forms',
					form_id: '92:quote-widget',
					form_title: 'Elementor quote form',
					provider_label: 'Elementor Pro Forms'
				}
			]
		};

		await page.route('**/wp-json/sentient-forms/v1/lead-value/dashboard**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(elementorDashboardResponse)
			})
		);

		await page.goto('/lead-scoring', { waitUntil: 'networkidle' });

		await expect(
			page.getByRole('row', { name: /Elementor lead form/ }).getByRole('link', { name: 'Setup' })
		).toHaveAttribute('href', /\/actions\/elementor_pro_forms\/91(?::|%3A)formabc$/);
		await expect(
			page.getByTestId('lead-scoring-configured-forms-list').getByRole('link', { name: 'Setup' })
		).toHaveAttribute('href', /\/actions\/elementor_pro_forms\/91(?::|%3A)formabc$/);
		await expect(
			page.getByTestId('lead-scoring-quick-jump-list').getByRole('link', { name: 'Set Up' })
		).toHaveAttribute('href', /\/actions\/elementor_pro_forms\/92(?::|%3A)quote-widget$/);
	});
});
