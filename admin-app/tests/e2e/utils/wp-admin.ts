import { expect, type Page } from '@playwright/test';

export const wpBaseUrl = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
const wpAdminUser = process.env.SENTIENT_WP_ADMIN_USER ?? 'sentient_admin';
const wpAdminPass = process.env.SENTIENT_WP_ADMIN_PASS ?? 'sentient_admin';

async function maybeHandleAdminVerification(page: Page): Promise<void> {
	const confirmButton = page.locator('button', { hasText: 'The email is correct' });
	const remindLink = page.locator('a', { hasText: 'Remind me later' });

	if (await confirmButton.isVisible().catch(() => false)) {
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			confirmButton.click()
		]);
		return;
	}

	if (await remindLink.isVisible().catch(() => false)) {
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			remindLink.click()
		]);
	}
}

export async function loginToWpAdmin(page: Page): Promise<void> {
	await page.goto(`${wpBaseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await expect(page.locator('#loginform')).toBeVisible();
	await page.fill('#user_login', wpAdminUser);
	await page.fill('#user_pass', wpAdminPass);
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
		page.click('#wp-submit')
	]);

	await maybeHandleAdminVerification(page);
}

export async function ensureSentientFormsSpa(page: Page, hash = '/dashboard'): Promise<void> {
	const normalized = hash.startsWith('#') ? hash : `#${hash.replace(/^\//, '')}`;
	await page.goto(`${wpBaseUrl}/wp-admin/admin.php?page=sentient-forms${normalized}`, {
		waitUntil: 'domcontentloaded'
	});
	await page.waitForFunction(() => typeof (window as any).sentientFormsConfig !== 'undefined');
}
