import { expect, test, type Locator, type Page } from '@playwright/test';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';
import { mockResponsiveApi } from './utils/mock-responsive-api';

type ViewportProfile = {
	name: string;
	width: number;
	height: number;
};

type RouteCheck = {
	path: string;
	ready: (page: Page) => Locator;
};

const previewHost = getPreviewOrigin();

const viewports: ViewportProfile[] = [
	{ name: 'mobile-360', width: 360, height: 800 },
	{ name: 'wp-collapse-782', width: 782, height: 900 },
	{ name: 'desktop-1280', width: 1280, height: 900 }
];

const routeChecks: RouteCheck[] = [
	{ path: '/#/dashboard', ready: (page) => page.getByRole('heading', { name: 'Dashboard' }) },
	{ path: '/#/licensing', ready: (page) => page.getByRole('heading', { name: 'License management' }) },
	{ path: '/#/actions', ready: (page) => page.getByRole('heading', { name: 'Actions' }) },
	{ path: '/#/actions/log', ready: (page) => page.getByRole('heading', { name: 'Action Log' }) },
	{ path: '/#/actions/custom', ready: (page) => page.getByRole('heading', { name: 'Custom Actions' }) },
	{
		path: '/#/actions/custom/new',
		ready: (page) => page.getByTestId('custom-action-form')
	},
	{
		path: '/#/actions/custom/action-alpha',
		ready: (page) => page.getByTestId('custom-action-form')
	},
	{
		path: '/#/actions/gravity_forms/123',
		ready: (page) => page.getByTestId('action-definitions-card')
	},
	{
		path: '/#/settings',
		ready: (page) => page.getByRole('heading', { name: /Telemetry .* Background Processing/i })
	},
	{
		path: '/#/settings/context',
		ready: (page) => page.getByRole('heading', { name: 'Site Context' })
	}
];

async function assertNoPageOverflow(page: Page, contextLabel: string): Promise<void> {
	const overflow = await page.evaluate(() => {
		const documentOverflow = Math.max(0, document.documentElement.scrollWidth - window.innerWidth);
		const bodyOverflow = Math.max(0, document.body.scrollWidth - window.innerWidth);
		const main = document.querySelector('main');
		const mainOverflow =
			main instanceof HTMLElement ? Math.max(0, main.scrollWidth - main.clientWidth) : 0;

		return {
			documentOverflow,
			bodyOverflow,
			mainOverflow
		};
	});

	expect(overflow.documentOverflow, `${contextLabel} document overflow`).toBeLessThanOrEqual(1);
	expect(overflow.bodyOverflow, `${contextLabel} body overflow`).toBeLessThanOrEqual(1);
	expect(overflow.mainOverflow, `${contextLabel} main overflow`).toBeLessThanOrEqual(1);
}

async function assertHorizontalOverflowContainer(
	locator: Locator,
	contextLabel: string
): Promise<void> {
	const overflowX = await locator.evaluate((element) => getComputedStyle(element).overflowX);
	expect(['auto', 'scroll'], `${contextLabel} overflow-x`).toContain(overflowX);
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
	expect(bounds.bottom, `${contextLabel} bottom bound`).toBeLessThanOrEqual(bounds.viewportHeight + 1);
}

test.describe('Responsive WP admin fit (FR-UI-015)', () => {
	test.beforeEach(async ({ page }) => {
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewHost}/wp-json/sentient-forms/v1/`,
			siteUrl: previewHost,
			formSources: [{ slug: 'gravity_forms', label: 'Gravity Forms', isActive: true }]
		});
		await mockResponsiveApi(page, { formSourceSlug: 'gravity_forms', formId: 123 });
	});

	for (const viewport of viewports) {
		test(`all app routes avoid page-level overflow at ${viewport.name}`, async ({ page }) => {
			await page.setViewportSize({ width: viewport.width, height: viewport.height });

			for (const route of routeChecks) {
				await page.goto(route.path, { waitUntil: 'networkidle' });
				await expect(route.ready(page), `${viewport.name} ${route.path} ready marker`).toBeVisible();
				await assertNoPageOverflow(page, `${viewport.name} ${route.path}`);
			}
		});
	}

	test('dense data routes keep local horizontal overflow wrappers on narrow widths', async ({ page }) => {
		await page.setViewportSize({ width: 360, height: 800 });

		await page.goto('/#/actions/log', { waitUntil: 'networkidle' });
		const actionLogScroll = page.getByTestId('action-log-table-scroll');
		await expect(actionLogScroll).toBeVisible();
		await assertHorizontalOverflowContainer(actionLogScroll, 'action-log-table-scroll');
		await assertNoPageOverflow(page, 'mobile /#/actions/log');

		await page.goto('/#/actions/custom', { waitUntil: 'networkidle' });
		const customActionsScroll = page.getByTestId('custom-actions-table-scroll');
		await expect(customActionsScroll).toBeVisible();
		await assertHorizontalOverflowContainer(customActionsScroll, 'custom-actions-table-scroll');
		await assertNoPageOverflow(page, 'mobile /#/actions/custom');

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.getByTestId('linked-actions-view-table').click();
		const formActionsScroll = page.getByTestId('form-actions-table-scroll');
		await expect(formActionsScroll).toBeVisible();
		await assertHorizontalOverflowContainer(formActionsScroll, 'form-actions-table-scroll');
		await assertNoPageOverflow(page, 'mobile /#/actions/gravity_forms/123 table');
	});

	test('defaults and mapping modals remain inside viewport on narrow widths', async ({ page }) => {
		await page.setViewportSize({ width: 360, height: 800 });

		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await page.getByTestId('action-defaults-button-spam_detection_v1').click();

		const defaultsModal = page.getByTestId('action-defaults-modal');
		await expect(defaultsModal).toBeVisible();
		await expect(defaultsModal.getByRole('button', { name: 'Cancel' })).toBeVisible();
		await expect(defaultsModal.getByRole('button', { name: /Save Global Defaults/ })).toBeVisible();
		await assertElementWithinViewport(defaultsModal, 'action-defaults-modal');
		await assertNoPageOverflow(page, 'mobile /#/actions defaults modal');

		await defaultsModal.getByTestId('action-defaults-close').click();

		await page.goto('/#/actions/gravity_forms/123', { waitUntil: 'networkidle' });
		await page.getByTestId('linked-actions-view-table').click();
		await page
			.getByTestId('form-actions-table')
			.locator('tbody tr')
			.first()
			.getByRole('button', { name: 'Configure' })
			.click();

		const mappingModal = page.getByTestId('mapping-config-modal');
		await expect(mappingModal).toBeVisible();
		await expect(mappingModal.getByTestId('mapping-config-close-header')).toBeVisible();
		await mappingModal.getByTestId('mapping-config-save').scrollIntoViewIfNeeded();
		await expect(mappingModal.getByTestId('mapping-config-save')).toBeVisible();
		await assertElementWithinViewport(mappingModal, 'mapping-config-modal');
		await assertNoPageOverflow(page, 'mobile /#/actions/gravity_forms/123 mapping modal');
	});
});
