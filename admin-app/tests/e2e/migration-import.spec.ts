import { expect, test } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

const readinessReport = {
	generated_at: '2030-01-05T10:00:00Z',
	source: 'local_cutover',
	source_version: null,
	confirmation_phrase: 'RESET LOCAL-FIRST RUNTIME',
	ready_for_reset: true,
	ready_for_local_execution: true,
	local_tables: {
		sentient_action_templates: 0,
		sentient_custom_actions: 0,
		sentient_form_mappings: 0,
		sentient_execution_events: 0,
		sentient_provider_credentials: 1,
		sentient_external_service_consents: 1,
		sentient_migration_runs: 0,
		sentient_model_cache: 1
	},
	runtime_tables: {
		sentient_async_requests: 0
	},
	legacy_options: {
		exact_options: {
			sentient_forms_action_log: { exists: false, will_delete: true }
		},
		option_prefixes: {
			sentient_forms_actions_: { count: 0, sample: [], will_delete: true }
		}
	},
	settings: {},
	reset_plan: {
		tables_cleared: [],
		tables_preserved_by_default: [],
		exact_options_deleted: [],
		option_prefixes_deleted: [],
		settings_preserved: []
	},
	warnings: []
};

const importReport = {
	schema_version: 'sentient_forms_cps_export_v1',
	source: 'cps_export',
	source_version: 'cps-dev-export-1',
	generated_at: '2030-01-05T10:01:00Z',
	exported_at: '2030-01-05T10:00:00Z',
	ready_to_import: true,
	counts: {
		action_templates: 1,
		custom_actions: 1,
		form_mappings: 1,
		execution_events: 1,
		settings_keys: 1
	},
	changes: {
		action_templates: { create: 1, update: 0, blocked: 0 },
		custom_actions: { create: 1, update: 0, blocked: 0 },
		form_mappings: { create: 1, update: 0, blocked: 0 },
		execution_events: { create: 1, update: 0, blocked: 0 },
		settings: { review: 1, blocked: 0 },
		total_writes: 4
	},
	conflicts: [],
	warnings: [],
	mapping: {}
};

test.describe('Migration import assistant', () => {
	test.beforeEach(async ({ page }) => {
		const previewHost = getPreviewOrigin();
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost
		});

		await page.route('**/wp-json/sentient-forms/v1/local/migration/readiness**', (route) =>
			route.fulfill({
				status: 200,
				contentType: 'application/json',
				body: JSON.stringify(readinessReport)
			})
		);
	});

	test('previews and applies the current CPS export JSON', async ({ page }) => {
		const bundle = {
			schema_version: 'sentient_forms_cps_export_v1',
			source_version: 'cps-dev-export-1',
			action_templates: [{ external_id: 'template-1', code: 'spam_triage' }]
		};
		let dryRunPayload: Record<string, unknown> | null = null;
		let applyPayload: Record<string, unknown> | null = null;

		await page.route('**/wp-json/sentient-forms/v1/local/migration/import/dry-run**', async (route) => {
			dryRunPayload = route.request().postDataJSON() as Record<string, unknown>;
			return route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({
					run_id: 51,
					status: 'dry_run_complete',
					dry_run: true,
					report: importReport
				})
			});
		});

		await page.route('**/wp-json/sentient-forms/v1/local/migration/import/apply**', async (route) => {
			applyPayload = route.request().postDataJSON() as Record<string, unknown>;
			return route.fulfill({
				status: 201,
				contentType: 'application/json',
				body: JSON.stringify({
					run_id: 52,
					status: 'completed',
					dry_run: false,
					report: importReport,
					applied: {
						action_templates: 1,
						custom_actions: 1,
						form_mappings: 1,
						execution_events: 1,
						total: 4
					}
				})
			});
		});

		await page.goto('/#/settings/migration', { waitUntil: 'networkidle' });
		await page.getByLabel('Export bundle JSON').fill(JSON.stringify(bundle, null, 2));
		await page.getByRole('button', { name: 'Preview import' }).click();

		await expect(page.getByText('Preview #51 recorded')).toBeVisible();
		await expect(page.getByText('Ready to import')).toBeVisible();
		await expect(page.getByRole('button', { name: 'Apply current preview' })).toBeEnabled();

		await page.getByRole('button', { name: 'Apply current preview' }).click();

		await expect(page.getByText('Import #52 completed with 4 applied records.')).toBeVisible();
		expect(dryRunPayload).toEqual({ bundle });
		expect(applyPayload).toEqual({ bundle });
	});
});
