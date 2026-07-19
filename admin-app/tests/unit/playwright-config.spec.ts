import { afterEach, describe, expect, it, vi } from 'vitest';

afterEach(() => {
	vi.unstubAllEnvs();
	vi.resetModules();
});

describe('Playwright preview ownership', () => {
	it('starts a fresh web server instead of reusing an arbitrary listener for direct CLI use', async () => {
		const { default: playwrightConfig } = await import('../../playwright.config');
		const configuredWebServer = playwrightConfig.webServer;
		const webServer = Array.isArray(configuredWebServer)
			? configuredWebServer[0]
			: configuredWebServer;

		expect(webServer).toMatchObject({
			command: 'bun run preview:e2e',
			reuseExistingServer: false
		});
	});

	it('does not launch a second server when the E2E wrapper owns the preview', async () => {
		vi.stubEnv('SENTIENT_FORMS_OWNED_PREVIEW', '1');
		vi.resetModules();

		const { default: playwrightConfig } = await import('../../playwright.config');

		expect(playwrightConfig.webServer).toBeUndefined();
	});
});
