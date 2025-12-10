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

	const original = new URL(req.url());
	const target = new URL(req.url());
	// Force traffic to real WP host:port and use ?rest_route for reliability.
	target.hostname = 'localhost';
	target.port = '8080';

	if (target.pathname.startsWith('/wp-json/')) {
		const restRoute = target.pathname.replace('/wp-json', '');
		target.pathname = '/index.php';
		target.searchParams.set('rest_route', restRoute);
	}

	const upstream = await route.fetch({
		url: target.toString(),
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
	await page.route('**/index.php?rest_route=/sentient-forms/v1/**', (route) =>
		fulfillWithCors(route, route.request().headers().origin)
	);
}
