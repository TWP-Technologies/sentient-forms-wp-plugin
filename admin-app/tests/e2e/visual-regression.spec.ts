import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Locator, type Page } from '@playwright/test';
import { mockResponsiveApi } from './utils/mock-responsive-api';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

type RouteVisualAudit = {
	name: string;
	path: string;
	ready: (page: Page) => Locator;
	screenshot: string;
	ariaSnapshot: string;
};

const previewOrigin = getPreviewOrigin();

const routeVisualAudits: RouteVisualAudit[] = [
	{
		name: 'dashboard',
		path: '/#/dashboard',
		ready: (page) => page.getByTestId('dashboard-local-first-summary'),
		screenshot: 'dashboard-shell.png',
		ariaSnapshot: 'dashboard-shell.aria.yml'
	},
	{
		name: 'actions overview',
		path: '/#/actions',
		ready: (page) => page.getByRole('heading', { name: 'Actions' }),
		screenshot: 'actions-shell.png',
		ariaSnapshot: 'actions-shell.aria.yml'
	},
	{
		name: 'licensing',
		path: '/#/licensing',
		ready: (page) => page.getByRole('heading', { name: 'License management' }),
		screenshot: 'licensing-shell.png',
		ariaSnapshot: 'licensing-shell.aria.yml'
	},
	{
		name: 'settings',
		path: '/#/settings',
		ready: (page) => page.getByRole('heading', { name: 'Privacy & Local Processing' }),
		screenshot: 'settings-shell.png',
		ariaSnapshot: 'settings-shell.aria.yml'
	},
	{
		name: 'site context',
		path: '/#/settings/context',
		ready: (page) => page.getByRole('heading', { name: 'Site Context' }),
		screenshot: 'site-context-shell.png',
		ariaSnapshot: 'site-context-shell.aria.yml'
	}
];

async function expectNoSeriousAxeViolations(page: Page, routeName: string): Promise<void> {
	const results = await new AxeBuilder({ page }).include('[data-testid="app-content-frame"]').analyze();
	const blockingViolations = results.violations
		.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''))
		.map((violation) => ({
			id: violation.id,
			impact: violation.impact,
			nodes: violation.nodes.map((node) => ({
				target: node.target.join(' > '),
				summary: (node.failureSummary ?? '').replace(/\s+/g, ' ').trim()
			}))
		}));

	expect(blockingViolations, `${routeName} should not have serious or critical axe violations`).toEqual(
		[]
	);
}

test.describe('Preview visual regression and accessibility @visual @a11y', () => {
	test.beforeEach(async ({ page }) => {
		await seedRuntimeConfig(page, {
			apiBaseUrl: `${previewOrigin}/wp-json/sentient-forms/v1/`,
			siteUrl: previewOrigin,
			formSources: [{ slug: 'gravity_forms', label: 'Gravity Forms', isActive: true }]
		});
		await mockResponsiveApi(page, { formSourceSlug: 'gravity_forms', formId: 123 });
		await page.setViewportSize({ width: 1440, height: 1200 });
	});

	for (const route of routeVisualAudits) {
		test(`${route.name} shell stays visually stable`, async ({ page }) => {
			await page.goto(route.path, { waitUntil: 'networkidle' });
			await expect(route.ready(page)).toBeVisible();
			await expectNoSeriousAxeViolations(page, route.name);

			const frame = page.getByTestId('app-content-frame');
			await expect(frame).toBeVisible();
			await expect(frame).toHaveScreenshot(route.screenshot, {
				animations: 'disabled',
				caret: 'hide',
				scale: 'css'
			});
			await expect(frame).toMatchAriaSnapshot({ name: route.ariaSnapshot });
		});
	}

	test('dashboard shell stays stable on mobile width', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 });
		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('dashboard-local-first-summary')).toBeVisible();
		await expectNoSeriousAxeViolations(page, 'dashboard mobile');

		const frame = page.getByTestId('app-content-frame');
		await expect(frame).toBeVisible();
		await expect(frame).toHaveScreenshot('dashboard-shell-mobile.png', {
			animations: 'disabled',
			caret: 'hide',
			scale: 'css'
		});
	});
});
