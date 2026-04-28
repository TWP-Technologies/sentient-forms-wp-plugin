import { expect, test, type Locator, type Page } from '@playwright/test';
import { requireWpRestHealthy } from './utils/wp-e2e-helpers';
import { ensureSentientFormsSpa, loginToWpAdmin } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

type RouteAudit = {
	name: string;
	path: string;
	ready: (page: Page) => Locator;
};

type ViewportProfile = {
	name: string;
	width: number;
	height: number;
};

const routeAudits: RouteAudit[] = [
	{
		name: 'dashboard',
		path: '/dashboard',
		ready: (page) => page.getByTestId('dashboard-local-first-summary')
	},
	{
		name: 'providers',
		path: '/providers',
		ready: (page) => page.getByText('Validate OpenRouter key')
	},
	{
		name: 'actions overview',
		path: '/actions',
		ready: (page) => page.getByRole('heading', { name: 'Actions' })
	},
	{
		name: 'action log',
		path: '/actions/log',
		ready: (page) => page.getByRole('heading', { name: 'Action Log' })
	},
	{
		name: 'custom actions',
		path: '/actions/custom',
		ready: (page) => page.getByRole('heading', { name: 'Custom Actions' })
	},
	{
		name: 'licensing',
		path: '/licensing',
		ready: (page) => page.getByRole('heading', { name: 'Managed service' })
	},
	{
		name: 'settings',
		path: '/settings',
		ready: (page) => page.getByRole('heading', { name: 'Privacy & Local Processing' })
	},
	{
		name: 'site context',
		path: '/settings/context',
		ready: (page) => page.getByRole('heading', { name: 'Site Context' })
	},
	{
		name: 'cutover',
		path: '/settings/migration',
		ready: (page) => page.getByRole('heading', { name: 'Local-First Cutover' })
	}
];

const viewportProfiles: ViewportProfile[] = [
	{ name: 'desktop', width: 1440, height: 1000 },
	{ name: 'mobile', width: 390, height: 844 }
];

function attachmentName(routeName: string, viewportName: string): string {
	return `${routeName.replace(/\s+/g, '-')}-${viewportName}.png`;
}

async function assertNoPageOverflow(page: Page, contextLabel: string): Promise<void> {
	const overflow = await page.evaluate(() => {
			const main = document.querySelector('main');
			const appFrame = document.querySelector('[data-testid="app-content-frame"]');
			return {
				documentOverflow: Math.max(0, document.documentElement.scrollWidth - window.innerWidth),
				bodyOverflow: Math.max(0, document.body.scrollWidth - window.innerWidth),
				mainOverflow:
					main instanceof HTMLElement ? Math.max(0, main.scrollWidth - main.clientWidth) : 0,
				appFrameOverflow:
					appFrame instanceof HTMLElement
					? Math.max(0, appFrame.scrollWidth - appFrame.clientWidth)
					: 0
		};
		});

	expect(overflow.documentOverflow, `${contextLabel} document overflow`).toBeLessThanOrEqual(1);
	expect(overflow.bodyOverflow, `${contextLabel} body overflow`).toBeLessThanOrEqual(1);
	expect(overflow.mainOverflow, `${contextLabel} main overflow`).toBeLessThanOrEqual(1);
	expect(overflow.appFrameOverflow, `${contextLabel} app frame overflow`).toBeLessThanOrEqual(1);
}

async function assertElementWithinViewport(
	locator: Locator,
	contextLabel: string
): Promise<void> {
	const bounds = await locator.evaluate((element) => {
		const rect = element.getBoundingClientRect();
		return {
			left: rect.left,
			right: rect.right,
			top: rect.top,
			bottom: rect.bottom,
			viewportWidth: window.innerWidth,
			viewportHeight: window.innerHeight
		};
	});

	expect(bounds.left, `${contextLabel} left bound`).toBeGreaterThanOrEqual(-1);
	expect(bounds.right, `${contextLabel} right bound`).toBeLessThanOrEqual(bounds.viewportWidth + 1);
	expect(bounds.top, `${contextLabel} top bound`).toBeGreaterThanOrEqual(-1);
	expect(bounds.bottom, `${contextLabel} bottom bound`).toBeLessThanOrEqual(
		bounds.viewportHeight + 1
	);
}

async function attachViewportScreenshot(
	page: Page,
	routeName: string,
	viewportName: string
): Promise<void> {
	const screenshot = await page.screenshot({ fullPage: true, type: 'png' });
	await test
		.info()
		.attach(attachmentName(routeName, viewportName), { body: screenshot, contentType: 'image/png' });
}

test.describe('WP admin visual fit and screenshot evidence @wp-visual', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise WordPress admin visual checks.');

	test.beforeEach(async ({ page }) => {
		await requireWpRestHealthy(page);
		await loginToWpAdmin(page);
	});

	for (const viewport of viewportProfiles) {
		test(`core local-first routes stay usable at ${viewport.name}`, async ({ page }) => {
			await page.setViewportSize({ width: viewport.width, height: viewport.height });

			for (const route of routeAudits) {
				await ensureSentientFormsSpa(page, route.path);
				await expect(route.ready(page), `${viewport.name} ${route.name} ready marker`).toBeVisible();
				await expect(page.getByTestId('app-content-frame')).toBeVisible();
				await assertNoPageOverflow(page, `${viewport.name} ${route.path}`);
				await attachViewportScreenshot(page, route.name, viewport.name);
			}
		});
	}

	test('providers delete confirmation stays inside the mobile viewport', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 });
		await ensureSentientFormsSpa(page, '/providers');

		const firstCredential = page.getByTestId('providers-openrouter-credential').first();
		await expect(firstCredential).toBeVisible();

		await firstCredential.getByRole('button', { name: 'Delete' }).click();

		const confirmation = firstCredential.getByTestId('providers-openrouter-delete-confirmation');
		await expect(confirmation).toBeVisible();
		await expect(confirmation.getByRole('button', { name: 'Cancel' })).toBeVisible();
		await expect(confirmation.getByRole('button', { name: 'Delete key' })).toBeVisible();
		await assertElementWithinViewport(confirmation, 'providers delete confirmation');
		await attachViewportScreenshot(page, 'providers-delete-confirmation', 'mobile');

		await confirmation.getByRole('button', { name: 'Cancel' }).click();
		await expect(confirmation).toBeHidden();
	});
});
