import { expect, test } from '@playwright/test';
import { saveGreenlightScreenshot } from './utils/greenlight-artifacts';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

function isSiteContextUrl(url: URL): boolean {
	const decodedHref = decodeURIComponent(url.href);
	return (
		decodedHref.includes('/wp-json/sentient-forms/v1/site-context') ||
		decodedHref.includes('rest_route=/sentient-forms/v1/site-context')
	);
}

function isExactSiteContextUrl(url: URL): boolean {
	const decodedHref = decodeURIComponent(url.href);
	return (
		!decodedHref.includes('/site-context/generate') &&
		!decodedHref.includes('/site-context/refresh') &&
		isSiteContextUrl(url)
	);
}

test.describe('WP admin security-roadblock greenlight @wp-greenlight', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise WordPress admin flows.');

	test('Site Context save classifies a Cloudflare challenge without blaming Sentient Forms', async ({
		page
	}) => {
		await requireWpRestHealthy(page);
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/settings/context');

		let savePayload: Record<string, unknown> | null = null;
		await page.route(
			(url) => isSiteContextUrl(url) && isExactSiteContextUrl(url),
			async (route) => {
				const request = route.request();
				if (request.method() === 'PUT') {
					savePayload = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;
					await route.fulfill({
						status: 403,
						headers: {
							'content-type': 'text/html; charset=UTF-8',
							'cf-mitigated': 'challenge',
							'cf-ray': '89abc12345def678-ORD',
							server: 'cloudflare'
						},
						body: '<!doctype html><html><title>Just a moment...</title><body>Checking if the site connection is secure.</body></html>'
					});
					return;
				}

				await route.continue();
			}
		);

		const textarea = page.getByTestId('site-context-textarea');
		await expect(textarea).toBeVisible();
		await textarea.fill(
			`Greenlight context save blocked by an upstream security checkpoint ${Date.now()}.`
		);
		const saveButton = page.getByTestId('site-context-save');
		await expect(saveButton).toBeEnabled();
		await saveButton.click();

		const banner = page.getByTestId('security-roadblock-banner');
		await expect(banner).toBeVisible();
		await expect(banner).toContainText('Confirmed Cloudflare challenge');
		await expect(banner).toContainText('Request interrupted');
		await expect(banner).toContainText(
			'This request reached a site security layer before WordPress could process it.'
		);
		await expect(page.getByTestId('session-expired-banner')).toHaveCount(0);

		await banner.getByText('Technical details').click();
		await expect(banner).toContainText('Cloudflare Ray ID');
		await expect(banner).toContainText('89abc12345def678-ORD');
		await expect(banner).toContainText('cf-mitigated: challenge');
		await expect(banner).toContainText('server: cloudflare');
		await expect(banner).not.toContainText('Sentient Forms failed');

		expect(savePayload).not.toBeNull();
		const modelSelection = savePayload?.generation_model_selection as
			| { tools?: unknown }
			| undefined;
		expect(modelSelection?.tools).toBeUndefined();

		await saveGreenlightScreenshot(banner, 'admin-site-context-cloudflare-roadblock');
	});
});
