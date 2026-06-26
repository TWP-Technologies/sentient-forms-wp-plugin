import { expect, test, type Locator, type Page } from '@playwright/test';
import { mockResponsiveApi } from './utils/mock-responsive-api';
import { getPreviewOrigin } from './utils/preview-origin';
import { seedRuntimeConfig } from './utils/runtime-config';

const previewOrigin = getPreviewOrigin();

async function seedPreviewApp(page: Page): Promise<void> {
	await seedRuntimeConfig(page, {
		apiBaseUrl: `${previewOrigin}/wp-json/sentient-forms/v1/`,
		siteUrl: previewOrigin,
		formSources: [{ slug: 'gravity_forms', label: 'Gravity Forms', isActive: true }]
	});
	await mockResponsiveApi(page, { formSourceSlug: 'gravity_forms', formId: 123 });
	await page.setViewportSize({ width: 1440, height: 1000 });
}

async function injectHostileWpAdminCss(page: Page): Promise<void> {
	await page.addStyleTag({
		content: `
			button,
			[role='button'],
			input,
			select,
			textarea {
				border-radius: 0;
			}

			[role='dialog'],
			.postbox,
			.card,
			.notice {
				border-radius: 0;
			}

			.sf-card {
				border-width: 0;
				border-color: transparent;
				border-radius: 0;
				background-color: transparent;
				box-shadow: none;
			}

			.sf-card-header {
				display: block;
				gap: 0;
			}

			.sf-card-body > :not(:last-child) {
				margin-block: 0;
			}
		`
	});
}

async function expectPositiveRadius(locator: Locator, label: string): Promise<void> {
	const radius = await locator.evaluate((element) =>
		Number.parseFloat(getComputedStyle(element).borderTopLeftRadius)
	);
	expect(radius, `${label} should retain a positive border radius`).toBeGreaterThan(0);
}

async function expectCardChrome(locator: Locator, label: string): Promise<void> {
	const styles = await locator.evaluate((element) => {
		const computed = getComputedStyle(element);
		return {
			borderTopWidth: Number.parseFloat(computed.borderTopWidth),
			borderTopLeftRadius: Number.parseFloat(computed.borderTopLeftRadius),
			backgroundColor: computed.backgroundColor
		};
	});

	expect(styles.borderTopWidth, `${label} should retain a visible border`).toBeGreaterThan(0);
	expect(
		styles.borderTopLeftRadius,
		`${label} should retain a positive border radius`
	).toBeGreaterThan(0);
	expect(styles.backgroundColor, `${label} should retain a non-transparent background`).not.toBe(
		'rgba(0, 0, 0, 0)'
	);
}

test.describe('Host CSS hardening', () => {
	test.beforeEach(async ({ page }) => {
		await seedPreviewApp(page);
	});

	test('prefixed rounded button utilities survive an unlayered host button reset', async ({
		page
	}) => {
		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('dashboard-local-first-summary')).toBeVisible();
		await injectHostileWpAdminCss(page);

		const refreshButton = page.getByRole('button', { name: 'Refresh' });
		await expect(refreshButton).toBeVisible();
		await expect(refreshButton).toHaveClass(/sf:rounded/);
		await expectPositiveRadius(refreshButton, 'Dashboard refresh button');
	});

	test('card component classes emit visible border and radius styles', async ({ page }) => {
		await page.goto('/#/dashboard', { waitUntil: 'networkidle' });
		await expect(page.getByTestId('dashboard-local-first-summary')).toBeVisible();
		await injectHostileWpAdminCss(page);

		const card = page.locator('.sf-card').first();
		await expect(card).toBeVisible();
		await expectCardChrome(card, 'Dashboard card');
	});

	test('settings privacy modal keeps rounded shell under hostile wp-admin CSS', async ({
		page
	}) => {
		await page.goto('/#/settings', { waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('heading', { name: 'Privacy & Local Processing' })).toBeVisible();
		await injectHostileWpAdminCss(page);

		await page.getByRole('button', { name: 'Review setup' }).click();
		const modal = page.getByTestId('privacy-setup-assistant');
		await expect(modal).toBeVisible();
		await expectPositiveRadius(modal, 'Privacy setup modal');
	});

	test('actions defaults modal keeps rounded shell and buttons under hostile wp-admin CSS', async ({
		page
	}) => {
		await page.goto('/#/actions', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Actions' })).toBeVisible();
		await injectHostileWpAdminCss(page);

		await page.getByRole('button', { name: 'Defaults' }).first().click();
		const modal = page.getByTestId('action-defaults-modal');
		await expect(modal).toBeVisible();
		await expectPositiveRadius(modal, 'Action defaults modal');
		await expectPositiveRadius(
			modal.getByRole('button', { name: /Save Global Defaults/ }),
			'Save defaults button'
		);
	});

	test('card-heavy providers route keeps emitted cards styled under hostile wp-admin CSS', async ({
		page
	}) => {
		await page.goto('/#/providers', { waitUntil: 'networkidle' });
		await expect(page.getByRole('heading', { name: 'Providers' })).toBeVisible();
		await injectHostileWpAdminCss(page);

		const cards = page.locator('.sf-card');
		expect(await cards.count()).toBeGreaterThanOrEqual(4);
		await expectCardChrome(cards.nth(0), 'First providers card');
		await expectCardChrome(cards.nth(1), 'Second providers card');
	});
});
