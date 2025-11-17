import { expect, test } from '@playwright/test';
import {
	ensureSentientFormsSpa,
	loginToWpAdmin,
	waitForSentientConfig,
	wpBaseUrl
} from './utils/wp-admin';

const runWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

async function expectNoConsoleErrors(page: Parameters<typeof test>[0]['page']) {
	const consoleErrors: string[] = [];
	const pageErrors: string[] = [];

	page.on('console', (msg) => {
		if (msg.type() === 'error') {
			consoleErrors.push(msg.text());
		}
	});

	page.on('pageerror', (error) => {
		pageErrors.push(error.message);
	});

	return {
		assert: () => {
			expect(consoleErrors, `Console errors encountered: ${consoleErrors.join('\n')}`).toHaveLength(0);
			expect(pageErrors, `Page errors encountered: ${pageErrors.join('\n')}`).toHaveLength(0);
		}
	};
}

test.describe('WordPress runtime smoke', () => {
	test.skip(!runWpE2E, 'Set SENTIENT_RUN_WP_E2E=1 to exercise wp-admin flows.');
	test('home page loads without console errors', async ({ page }) => {
		const watcher = await expectNoConsoleErrors(page);

		const response = await page.goto('http://localhost:8080/', { waitUntil: 'domcontentloaded' });
		expect(response?.ok(), 'Home page should return HTTP 200').toBeTruthy();

		await expect(page).toHaveTitle(/Sentient Forms/i);
		watcher.assert();
	});

	test('wp-admin login loads without console errors', async ({ page }) => {
		const watcher = await expectNoConsoleErrors(page);

		const response = await page.goto(`${wpBaseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
		expect(response?.ok(), 'Login page should return HTTP 200').toBeTruthy();

		await expect(page.locator('#loginform')).toBeVisible();
		watcher.assert();
	});

		test('Sentient Forms admin SPA renders inside wp-admin without console errors', async ({ page }) => {
			const watcher = await expectNoConsoleErrors(page);

			await loginToWpAdmin(page);
			await ensureSentientFormsSpa(page);
			await waitForSentientConfig(page);
			await page.waitForFunction(() => !!document.querySelector('#sentient-forms-admin-app'));

			watcher.assert();
		});
	});
