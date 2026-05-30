import { expect, test, type Page, type Request, type Response } from '@playwright/test';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { ensurePlaywrightFixtures } from './utils/wp-fixtures';
import { ensureSentientFormsSpa, loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runPerformanceAudit =
	process.env.SENTIENT_RUN_WP_E2E === '1' &&
	process.env.SENTIENT_RUN_WP_PERFORMANCE_AUDIT === '1';

const artifactDir =
	process.env.SENTIENT_FORMS_PERF_ARTIFACT_DIR ??
	path.resolve(process.cwd(), '..', 'temp', 'wp-admin-performance-audit');

type RouteTarget = {
	name: string;
	slug: string;
	hash: string;
	assertions?: (measurement: RouteMeasurement, formId: number) => void;
};

type RestRequestRecord = {
	method: string;
	route: string;
	status: number;
	durationMs: number;
	bytes: number | null;
	url: string;
};

type DuplicateGroup = {
	key: string;
	count: number;
};

type RouteMeasurement = {
	name: string;
	hash: string;
	requestCount: number;
	duplicates: DuplicateGroup[];
	slowest: RestRequestRecord[];
	requests: RestRequestRecord[];
	screenshot: string;
};

function sentientRestRoute(urlString: string): string | null {
	const url = new URL(urlString);
	const wpJsonPrefix = '/wp-json/sentient-forms/v1/';
	const wpJsonIndex = url.pathname.indexOf(wpJsonPrefix);

	if (wpJsonIndex >= 0) {
		return `${url.pathname.slice(wpJsonIndex + wpJsonPrefix.length)}${url.search}`;
	}

	const restRoute = url.searchParams.get('rest_route');
	const restPrefix = '/sentient-forms/v1/';
	if (restRoute?.startsWith(restPrefix)) {
		const extraParams = new URLSearchParams(url.searchParams);
		extraParams.delete('rest_route');
		const suffix = extraParams.toString();

		return `${restRoute.slice(restPrefix.length)}${suffix ? `?${suffix}` : ''}`;
	}

	return null;
}

function responseByteSize(response: Response): Promise<number | null> {
	const contentLength = Number.parseInt(response.headers()['content-length'] ?? '', 10);
	if (Number.isFinite(contentLength) && contentLength >= 0) {
		return Promise.resolve(contentLength);
	}

	return response
		.body()
		.then((body) => body.byteLength)
		.catch(() => null);
}

function duplicateGroups(records: RestRequestRecord[]): DuplicateGroup[] {
	const counts = new Map<string, number>();
	for (const record of records) {
		const key = `${record.method} ${record.route}`;
		counts.set(key, (counts.get(key) ?? 0) + 1);
	}

	return Array.from(counts.entries())
		.filter(([, count]) => count > 1)
		.map(([key, count]) => ({ key, count }))
		.sort((a, b) => b.count - a.count || a.key.localeCompare(b.key));
}

async function measureRoute(page: Page, target: RouteTarget): Promise<RouteMeasurement> {
	const starts = new Map<Request, number>();
	const records: RestRequestRecord[] = [];
	const pending: Promise<void>[] = [];

	const onRequest = (request: Request) => {
		if (sentientRestRoute(request.url())) {
			starts.set(request, performance.now());
		}
	};

	const onResponse = (response: Response) => {
		const route = sentientRestRoute(response.url());
		if (!route) {
			return;
		}

		const job = (async () => {
			await response.finished().catch(() => null);
			records.push({
				method: response.request().method(),
				route,
				status: response.status(),
				durationMs: Math.round((performance.now() - (starts.get(response.request()) ?? performance.now())) * 10) / 10,
				bytes: await responseByteSize(response),
				url: response.url()
			});
		})();

		pending.push(job);
	};

	page.on('request', onRequest);
	page.on('response', onResponse);

	await page.evaluate(() => {
		sessionStorage.clear();
	}).catch(() => undefined);

	await ensureSentientFormsSpa(page, target.hash);
	await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => undefined);
	await page.waitForTimeout(750);
	await Promise.allSettled(pending);

	page.off('request', onRequest);
	page.off('response', onResponse);

	const screenshot = path.join(artifactDir, `${target.slug}.png`);
	await page.screenshot({ path: screenshot, fullPage: true });

	return {
		name: target.name,
		hash: target.hash,
		requestCount: records.length,
		duplicates: duplicateGroups(records),
		slowest: [...records].sort((a, b) => b.durationMs - a.durationMs).slice(0, 10),
		requests: records.sort((a, b) => a.route.localeCompare(b.route)),
		screenshot
	};
}

function hasRoute(measurement: RouteMeasurement, routePattern: RegExp): boolean {
	return measurement.requests.some((request) => routePattern.test(request.route));
}

function assertNoRoute(measurement: RouteMeasurement, routePattern: RegExp, message: string): void {
	expect(
		measurement.requests.filter((request) => routePattern.test(request.route)),
		message
	).toEqual([]);
}

function writeMarkdownReport(measurements: RouteMeasurement[]): void {
	const lines = [
		'# Sentient Forms WP Admin Performance Audit',
		'',
		`- Site: ${wpBaseUrl}`,
		`- Generated: ${new Date().toISOString()}`,
		'',
		'| Route | Sentient REST requests | Duplicate groups | Slowest request | Screenshot |',
		'| --- | ---: | ---: | --- | --- |'
	];

	for (const measurement of measurements) {
		const slowest = measurement.slowest[0];
		lines.push(
			`| ${measurement.name} | ${measurement.requestCount} | ${measurement.duplicates.length} | ${
				slowest ? `${slowest.method} ${slowest.route} (${slowest.durationMs}ms)` : 'none'
			} | ${path.basename(measurement.screenshot)} |`
		);
	}

	lines.push('', '## Details', '');
	for (const measurement of measurements) {
		lines.push(`### ${measurement.name}`, '', `Hash: \`${measurement.hash}\``, '');
		if (measurement.duplicates.length > 0) {
			lines.push('Duplicate groups:');
			for (const duplicate of measurement.duplicates) {
				lines.push(`- ${duplicate.key}: ${duplicate.count}`);
			}
			lines.push('');
		}

		lines.push('| Method | Route | Status | Duration ms | Bytes |', '| --- | --- | ---: | ---: | ---: |');
		for (const request of measurement.requests) {
			lines.push(
				`| ${request.method} | \`${request.route}\` | ${request.status} | ${request.durationMs} | ${
					request.bytes ?? ''
				} |`
			);
		}
		lines.push('');
	}

	writeFileSync(path.join(artifactDir, 'wp-admin-performance-audit.md'), `${lines.join('\n')}\n`);
}

test.describe('Sentient Forms WP admin performance audit', () => {
	test.skip(
		!runPerformanceAudit,
		'Set SENTIENT_RUN_WP_E2E=1 and SENTIENT_RUN_WP_PERFORMANCE_AUDIT=1 to run the local WP admin performance audit.'
	);

	let seededFormId = 0;

	test.beforeAll(() => {
		if (runPerformanceAudit) {
			seededFormId = ensurePlaywrightFixtures();
		}
	});

	test('records real wp-admin REST request shape and guards collapsed routes', async ({
		page,
		context
	}) => {
		mkdirSync(artifactDir, { recursive: true });
		await loginToWpAdmin(page);

		const targets: RouteTarget[] = [
			{
				name: 'Dashboard',
				slug: 'dashboard',
				hash: '/dashboard',
				assertions: (measurement) => {
					expect(hasRoute(measurement, /^admin\/dashboard-summary(?:\?|$)/)).toBe(true);
					assertNoRoute(
						measurement,
						/^local\/support-bundle(?:\?|$)/,
						'Dashboard first paint must not request the full support bundle.'
					);
					assertNoRoute(
						measurement,
						/^local\/(?:providers\/credentials|action-templates|custom-actions|execution-events)(?:\?|$)/,
						'Dashboard first paint must not fan out to legacy summary endpoints.'
					);
				}
			},
			{
				name: 'Actions List',
				slug: 'actions-list',
				hash: '/actions',
				assertions: (measurement) => {
					expect(measurement.requestCount).toBeLessThanOrEqual(10);
					expect(hasRoute(measurement, /^gravity_forms\/forms\/overview(?:\?|$)/)).toBe(true);
					expect(hasRoute(measurement, /^actions\/defaults(?:\?|$)/)).toBe(true);
					assertNoRoute(
						measurement,
						/^actions\/(?!defaults(?:\?|$))[a-z0-9_-]+\/defaults(?:\?|$)/,
						'Actions list must batch action defaults instead of requesting one defaults endpoint per action.'
					);
					assertNoRoute(
						measurement,
						/^gravity_forms\/forms\/\d+\/actions(?:\/status)?(?:\?|$)/,
						'Actions list must not request per-form action/status endpoints.'
					);
				}
			},
			{
				name: 'Form Detail',
				slug: 'form-detail',
				hash: `/actions/gravity_forms/${seededFormId}`,
				assertions: (measurement, formId) => {
					expect(measurement.requestCount).toBeLessThanOrEqual(3);
					expect(hasRoute(measurement, new RegExp(`^gravity_forms/forms/${formId}/actions/bootstrap(?:\\?|$)`))).toBe(
						true
					);
					assertNoRoute(
						measurement,
						/^actions\/(?!defaults(?:\?|$))[a-z0-9_-]+\/defaults(?:\?|$)/,
						'Form detail must batch action defaults instead of requesting one defaults endpoint per action.'
					);
					assertNoRoute(
						measurement,
						/^actions\/defaults(?:\?|$)/,
						'Form detail first paint must receive visible action defaults from the bootstrap payload.'
					);
					assertNoRoute(
						measurement,
						new RegExp(`^gravity_forms/forms/${formId}/actions(?:\\?|$)`),
						'Form detail must not request legacy standalone action mappings.'
					);
					assertNoRoute(
						measurement,
						new RegExp(`^gravity_forms/forms/${formId}/actions/(?:status|disable)(?:\\?|$)`),
						'Form detail must not request legacy startup status/disable endpoints.'
					);
					assertNoRoute(
						measurement,
						new RegExp(
							`^(?:meta/capabilities|actions/definitions|custom-actions|local/providers/credentials|gravity_forms/forms(?:\\?|$)|gravity_forms/forms/${formId}/actions/(?:fields|workflow-plan)|forms/gravity_forms/${formId}/action-config)(?:\\?|$)`
						),
						'Form detail first paint must not fan out to metadata, provider, field, workflow, or config startup endpoints.'
					);
				}
			},
			{
				name: 'Licensing',
				slug: 'licensing',
				hash: '/licensing',
				assertions: (measurement) => {
					const billingReads = measurement.requests.filter((request) =>
						/^license\/billing-state(?:\?|$)/.test(request.route)
					);
					expect(billingReads.length).toBeLessThanOrEqual(1);
				}
			},
			{
				name: 'Settings',
				slug: 'settings',
				hash: '/settings'
			},
			{
				name: 'Action Log',
				slug: 'action-log',
				hash: '/actions/log'
			}
		];

		const measurements: RouteMeasurement[] = [];
		for (const target of targets) {
			const routePage = await context.newPage();
			try {
				const measurement = await measureRoute(routePage, target);
				target.assertions?.(measurement, seededFormId);
				measurements.push(measurement);
			} finally {
				await routePage.close();
			}
		}

		writeFileSync(
			path.join(artifactDir, 'wp-admin-performance-audit.json'),
			`${JSON.stringify({ site: wpBaseUrl, generatedAt: new Date().toISOString(), measurements }, null, 2)}\n`
		);
		writeMarkdownReport(measurements);
	});
});
