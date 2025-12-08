import type { Page, Route } from '@playwright/test';

const ACA_HEADERS = {
	'access-control-allow-headers': 'authorization, content-type, x-wp-nonce',
	'access-control-allow-methods': 'GET, POST, PUT, PATCH, DELETE, OPTIONS'
};

async function fulfillWithCors(route: Route, origin: string | undefined): Promise<void> {
	const req = route.request();

	if (req.method() === 'OPTIONS') {
		return route.fulfill({
			status: 200,
			headers: {
				...ACA_HEADERS,
				'access-control-allow-origin': origin ?? '*',
				vary: 'Origin'
			},
			body: ''
		});
	}

	if (req.url().includes('/meta/capabilities')) {
		return route.fulfill({
			status: 200,
			headers: {
				...ACA_HEADERS,
				'access-control-allow-origin': origin ?? '*',
				vary: 'Origin',
				'content-type': 'application/json'
			},
			body: JSON.stringify({
				success: true,
				data: {
					supports_custom_actions: true,
					supports_status: true,
					supports_credits: true,
					cps_version: 'mock-e2e'
				}
			})
		});
	}

	const targetUrl = req
		.url()
		.replace('127.0.0.1:4175', 'localhost:8080')
		.replace('localhost:4175', 'localhost:8080');

	const upstream = await req.fetch({
		url: targetUrl,
		headers: {
			...req.headers(),
			host: 'localhost:8080'
		}
	});
	const body = await upstream.text();
	const headers: Record<string, string> = { ...upstream.headers() } as Record<string, string>;
	headers['access-control-allow-origin'] = origin ?? '*';
	headers['access-control-allow-headers'] = ACA_HEADERS['access-control-allow-headers'];
	headers['access-control-allow-methods'] = ACA_HEADERS['access-control-allow-methods'];
	headers['vary'] = 'Origin';

	await route.fulfill({
		status: upstream.status(),
		headers,
		body
	});
}

/**
 * Inject CORS headers for Sentient Forms REST routes to allow the Playwright preview
 * origin (127.0.0.1:4175) to call WP REST (localhost:8080) during E2E.
 */
export async function installSentientCorsProxy(page: Page): Promise<void> {
	await page.route('**/wp-json/sentient-forms/v1/**', (route) =>
		fulfillWithCors(route, route.request().headers().origin)
	);
}
