import { expect, test } from '@playwright/test';
import { ensureSentientFormsSpa, loginToWpAdmin, wpBaseUrl } from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

test.describe('WordPress admin navigation escapes the Sentient SPA', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin flows.');

	type ScrollMetrics = {
		viewportHeight: number;
		scrollHeight: number;
		scrollY: number;
		bodyOverflowY: string;
		docOverflowY: string;
		contentOverflowY: string | null;
	};

	async function collectScrollMetrics(page: import('@playwright/test').Page): Promise<ScrollMetrics> {
		return page.evaluate(() => {
			const contentFrame = document.querySelector('[data-testid="app-content-frame"]');
			const contentOverflowY = contentFrame
				? getComputedStyle(contentFrame).overflowY || getComputedStyle(contentFrame).overflow
				: null;

			return {
				viewportHeight: window.innerHeight,
				scrollHeight: document.documentElement.scrollHeight,
				scrollY: window.scrollY,
				bodyOverflowY: getComputedStyle(document.body).overflowY || getComputedStyle(document.body).overflow,
				docOverflowY:
					getComputedStyle(document.documentElement).overflowY ||
					getComputedStyle(document.documentElement).overflow,
				contentOverflowY
			};
		});
	}

	test('visible WordPress admin links still navigate away from the plugin app', async ({ page }) => {
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/dashboard');

		const aboutWordPressLink = page
			.locator(`#wpadminbar a[href="${wpBaseUrl}/wp-admin/about.php"]`)
			.first();

		await expect(aboutWordPressLink).toBeVisible();

		await Promise.all([
			page.waitForURL(`${wpBaseUrl}/wp-admin/about.php`, { timeout: 10000 }),
			aboutWordPressLink.click()
		]);

		await expect(page).toHaveTitle(/About .* WordPress/i);
	});

	test('left admin menu links still navigate away from the plugin app', async ({ page }) => {
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/providers');

		const pluginsLink = page.locator('#menu-plugins > a[href="plugins.php"]').first();

		await expect(pluginsLink).toBeVisible();

		await Promise.all([
			page.waitForURL(`${wpBaseUrl}/wp-admin/plugins.php`, { timeout: 10000 }),
			pluginsLink.click()
		]);

		await expect(page).toHaveTitle(/Plugins/i);
		await expect(page.locator('body')).not.toContainText(
			'The plugin sentient-forms/sentient-forms.php has been deactivated due to an error: Plugin file does not exist.'
		);

		const sentientRows = page
			.locator('#the-list tr')
			.filter({ has: page.locator('strong', { hasText: 'Sentient Forms' }) });

		await expect(sentientRows.first()).toBeVisible();
	});

	test('dashboard admin menu link is not captured by the Sentient hash router', async ({ page }) => {
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/actions');

		const dashboardLink = page.locator('#menu-dashboard > a[href="index.php"]').first();

		await expect(dashboardLink).toBeVisible();

		await Promise.all([
			page.waitForURL(`${wpBaseUrl}/wp-admin/index.php`, { timeout: 10000 }),
			dashboardLink.click()
		]);

		await expect(page).toHaveTitle(/Dashboard/i);
	});

	test('same-path WordPress admin.php links are not captured by the Sentient hash router', async ({
		page
	}) => {
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/actions');

		const formsLink = page.locator('#adminmenu a[href="admin.php?page=gf_edit_forms"]').first();

		await expect(formsLink).toBeVisible();

		await Promise.all([
			page.waitForURL(`${wpBaseUrl}/wp-admin/admin.php?page=gf_edit_forms`, { timeout: 10000 }),
			formsLink.click()
		]);

		await expect(page).toHaveURL(`${wpBaseUrl}/wp-admin/admin.php?page=gf_edit_forms`);
		await expect(page.locator('#sentient-forms-admin-app')).toHaveCount(0);
	});

	test('providers view does not lock or compress document scrolling', async ({ page }) => {
		await loginToWpAdmin(page);
		await ensureSentientFormsSpa(page, '/providers');

		const before = await collectScrollMetrics(page);

		expect(before.scrollHeight).toBeGreaterThan(before.viewportHeight + 200);
		expect(before.bodyOverflowY).not.toBe('hidden');
		expect(before.docOverflowY).not.toBe('hidden');

		await page.evaluate(() => {
			window.scrollTo(0, document.documentElement.scrollHeight);
		});
		await page.waitForTimeout(150);

		const after = await collectScrollMetrics(page);

		expect(after.scrollY).toBeGreaterThan(200);
		expect(after.contentOverflowY).not.toBe('hidden');
	});
});
