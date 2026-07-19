import { describe, expect, it } from 'vitest';
import playwrightConfig from '../../playwright.config';

describe('Playwright preview ownership', () => {
	it('starts a fresh owned preview instead of reusing an arbitrary listener', () => {
		const configuredWebServer = playwrightConfig.webServer;
		const webServer = Array.isArray(configuredWebServer)
			? configuredWebServer[0]
			: configuredWebServer;

		expect(webServer).toMatchObject({
			command: 'bun run preview:e2e',
			reuseExistingServer: false
		});
	});
});
