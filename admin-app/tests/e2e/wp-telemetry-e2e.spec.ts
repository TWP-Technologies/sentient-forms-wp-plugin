import { existsSync } from 'node:fs';
import { expect, test } from '@playwright/test';
import {
	ensureCpsSeeded,
	getLatestTelemetryEvent,
	isTelemetryDbHealthy,
	setTelemetryOptIn
} from './utils/wp-e2e-helpers';
import { wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const defaultCpsBase = existsSync('/.dockerenv') ? 'http://cps-api:8080/v1' : 'http://localhost:10081/v1';
const rawCpsBase = process.env.SENTIENT_FORMS_CPS_BASE_URL ?? process.env.SENTIENT_FORMS_CPS_HOST_URL ?? defaultCpsBase;
const cpsBaseUrl = rawCpsBase.endsWith('/v1')
	? rawCpsBase
	: `${rawCpsBase.replace(/\/$/, '')}/v1`;
const telemetryHealthy = isTelemetryDbHealthy();

test.describe('Telemetry ingestion @telemetry-e2e', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise telemetry ingestion.');
	test.skip(!telemetryHealthy, 'Telemetry DB not healthy; ensure telemetry-db container is running.');

	test('records async telemetry when opted in', async ({ request, page }) => {
		setTelemetryOptIn(true);
		const proxyKey = ensureCpsSeeded();

		const event = `playwright_telemetry_${Date.now()}`;
		const timestamp = new Date().toISOString();
		const payload = {
			entry_id: 777,
			form_id: 3,
			result: {
				status: 'ok'
			}
		};

		const response = await request.post(`${cpsBaseUrl}/telemetry/async`, {
			headers: {
				'x-api-key': proxyKey,
				'content-type': 'application/json'
			},
			data: {
				event,
				site_url: `${wpBaseUrl}/`,
				timestamp,
				payload
			}
		});

		const status = response.status();
		const responseText = await response.text();
		if (!response.ok()) {
			throw new Error(`Telemetry ingest failed (${status}): ${responseText}`);
		}
		const body = JSON.parse(responseText);
		expect(body?.data?.accepted).toBe(true);

		let latest = getLatestTelemetryEvent();
		for (let attempt = 0; attempt < 6 && latest?.event !== event; attempt += 1) {
			await page.waitForTimeout(500);
			latest = getLatestTelemetryEvent();
		}

		expect(latest?.event).toBe(event);
		expect((latest?.payload as { entry_id?: number } | null)?.entry_id).toBe(payload.entry_id);
	});
});
