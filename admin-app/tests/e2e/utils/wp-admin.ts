import { expect, type Page } from '@playwright/test';
import { installSentientCorsProxy } from './cors-proxy';

export const wpBaseUrl = process.env.SENTIENT_WP_BASE_URL ?? 'http://localhost:8080';
const wpAdminUser = process.env.SENTIENT_WP_ADMIN_USER ?? 'sentient_admin';
const wpAdminPass = process.env.SENTIENT_WP_ADMIN_PASS ?? 'sentient_admin';

type SentientWindow = Window & { sentientFormsConfig?: unknown };

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
	if (process.env.SENTIENT_RUN_WP_E2E === '1') {
		await installSentientCorsProxy(page);
	}

	const normalizedHash = hash.startsWith('#') ? hash : `#/${hash.replace(/^\/+/, '')}`;
	const target = normalizedHash.replace('#//', '#/');
	const destination = `${wpBaseUrl}/wp-admin/admin.php?page=sentient-forms${target}`;
	await page.goto(destination, {
		waitUntil: 'domcontentloaded'
	});
	await waitForSentientConfig(page);
	await page.waitForFunction(() => {
		const root = document.querySelector('#sentient-forms-admin-app');
		if (!root) {
			return false;
		}
		return Array.from(root.children).some((child) => child.tagName !== 'SCRIPT');
	}, { timeout: 15000 });
	await page.evaluate((desiredHash) => {
		if (typeof window !== 'undefined' && window.location.hash !== desiredHash) {
			window.location.hash = desiredHash;
		}
	}, target);
}

export async function waitForSentientConfig(page: Page): Promise<void> {
	await page.waitForFunction(
		() => typeof (window as SentientWindow).sentientFormsConfig !== 'undefined'
	);
}
