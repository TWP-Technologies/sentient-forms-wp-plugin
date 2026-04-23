import { expect, type Page } from '@playwright/test';
import { existsSync } from 'node:fs';
import { installSentientCorsProxy } from './cors-proxy';

const defaultWpBaseUrl = existsSync('/.dockerenv')
	? 'http://host.docker.internal:8080'
	: 'http://localhost:8080';

export const wpBaseUrl = process.env.SENTIENT_WP_BASE_URL ?? defaultWpBaseUrl;
const wpAdminUser = process.env.SENTIENT_WP_ADMIN_USER ?? 'admin';
const wpAdminPass = process.env.SENTIENT_WP_ADMIN_PASS ?? 'password';

type SentientWindow = Window & { sentientFormsConfig?: unknown };
type SentientAppStatus = 'bootstrapping' | 'ready' | 'failed';

function currentWpPluginMode(): 'source' | 'package' {
	return process.env.SENTIENT_WP_PLUGIN_MODE === 'package' ? 'package' : 'source';
}

async function assertExpectedPluginAssets(page: Page): Promise<void> {
	const assetBaseUrl = await page.evaluate(() => {
		const config = (window as Window & {
			sentientFormsConfig?: { assetBaseUrl?: unknown };
		}).sentientFormsConfig;

		if (!config || typeof config !== 'object') {
			return null;
		}

		const { assetBaseUrl: runtimeAssetBaseUrl } = config as { assetBaseUrl?: unknown };
		return typeof runtimeAssetBaseUrl === 'string' ? runtimeAssetBaseUrl : null;
	});

	if (currentWpPluginMode() === 'package') {
		if (!assetBaseUrl?.includes('/sentient-forms-wporg-check/')) {
			throw new Error(
				`Package WP E2E expected sentient-forms-wporg-check assets, but runtime assetBaseUrl was ${assetBaseUrl}`
			);
		}
		return;
	}

	if (assetBaseUrl?.includes('/sentient-forms-wporg-check/')) {
		throw new Error(
			`Source WP E2E expected sentient-forms assets, but runtime assetBaseUrl was ${assetBaseUrl}`
		);
	}
}

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

async function maybeCompletePrivacySetupAssistant(page: Page): Promise<void> {
	const assistant = page.getByTestId('privacy-setup-assistant');
	const deadline = Date.now() + 5000;

	while (Date.now() < deadline) {
		if (await assistant.isVisible().catch(() => false)) {
			await assistant.getByRole('button', { name: 'Skip Customized Setup' }).click();
			await expect(assistant).toBeHidden();
			return;
		}

		await page.waitForTimeout(100);
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
	const deadline = Date.now() + 15000;
	let bootState: {
		appReady: SentientAppStatus | 'unknown';
		hasContent: boolean;
	} = {
		appReady: 'unknown',
		hasContent: false
	};
	while (Date.now() < deadline) {
		try {
			bootState = await page.evaluate(() => {
				const appReady = (window as Window & { sentientFormsAppReady?: SentientAppStatus })
					.sentientFormsAppReady;
				const root = document.querySelector('#sentient-forms-admin-app');
				const hasContent =
					!!root && Array.from(root.children).some((child) => child.tagName !== 'SCRIPT');

				return {
					appReady: appReady ?? 'unknown',
					hasContent
				};
			});
		} catch {
			bootState = {
				appReady: 'unknown',
				hasContent: false
			};
		}

		if (bootState.appReady === 'failed') {
			break;
		}

		if (bootState.appReady === 'ready' && bootState.hasContent) {
			break;
		}

		await page.waitForTimeout(200);
	}

	const { appReady, hasContent } = bootState;
	if (appReady === 'failed') {
		const rootHtml = await page
			.locator('#sentient-forms-admin-app')
			.evaluate((element) => element.innerHTML)
			.catch(() => '<missing mount root>');
		throw new Error(`Sentient Forms SPA failed to start. Root HTML: ${rootHtml}`);
	}
	if (appReady !== 'ready' || !hasContent) {
		throw new Error(
			`Sentient Forms SPA did not reach ready state within 15s (appReady=${appReady}, hasContent=${hasContent})`
		);
	}
	await assertExpectedPluginAssets(page);
	await page.evaluate((desiredHash) => {
		if (typeof window !== 'undefined' && window.location.hash !== desiredHash) {
			window.location.hash = desiredHash;
		}
	}, target);
	await maybeCompletePrivacySetupAssistant(page);
}

export async function waitForSentientConfig(page: Page): Promise<void> {
	await page.waitForFunction(
		() => typeof (window as SentientWindow).sentientFormsConfig !== 'undefined'
	);
}
