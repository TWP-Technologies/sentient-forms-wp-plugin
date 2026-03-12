import { existsSync } from 'node:fs';
import type { Page, Route } from '@playwright/test';

const ACA_HEADERS = {
	'access-control-allow-headers': 'authorization, content-type, x-wp-nonce',
	'access-control-allow-methods': 'GET, POST, PUT, PATCH, DELETE, OPTIONS'
};

const defaultWpOrigin = existsSync('/.dockerenv')
	? 'http://host.docker.internal:8080'
	: 'http://localhost:8080';
const wpOrigin = new URL(process.env.SENTIENT_WP_BASE_URL ?? defaultWpOrigin);

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

	const target = new URL(req.url());
	target.protocol = wpOrigin.protocol;
	target.hostname = wpOrigin.hostname;
	target.port = wpOrigin.port;

	if (target.pathname.startsWith('/wp-json/')) {
		const restRoute = target.pathname.replace('/wp-json', '');
		target.pathname = '/index.php';
		target.searchParams.set('rest_route', restRoute);
	}

	try {
		const upstream = await route.fetch({
			url: target.toString(),
			headers: {
				...req.headers(),
				host: wpOrigin.host
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
	} catch (error) {
		const message = error instanceof Error ? error.message : String(error);
		if (
			/Target page, context or browser has been closed/i.test(message) ||
			/Response has been disposed/i.test(message)
		) {
			try {
				await route.abort();
			} catch {
				// No-op: page/context is already closed.
			}
			return;
		}
		throw error;
	}
}

/**
 * Inject CORS headers for Sentient Forms REST routes to allow the Playwright preview
 * origin (resolved via PREVIEW_HOST/PREVIEW_PORT) to call WordPress REST during E2E.
 */
export async function installSentientCorsProxy(page: Page): Promise<void> {
	await page.route('**/wp-json/sentient-forms/v1/**', (route) =>
		fulfillWithCors(route, route.request().headers().origin)
	);
	await page.route('**/index.php?rest_route=/sentient-forms/v1/**', (route) =>
		fulfillWithCors(route, route.request().headers().origin)
	);
}
