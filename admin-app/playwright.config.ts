import { defineConfig, devices } from '@playwright/test';

const PREVIEW_PORT = 4175;

const isWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 300_000,
	globalSetup: './tests/e2e/global-setup.ts',
	expect: {
		timeout: 5_000
	},
	// WP E2E tests share global state (CPS license, credits) and must run serially
	workers: isWpE2E ? 1 : undefined,
	fullyParallel: !isWpE2E,
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: `http://127.0.0.1:${PREVIEW_PORT}`,
		trace: 'on-first-retry'
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] }
		}
	],
	webServer: {
		command: 'bun run preview:e2e',
		url: `http://127.0.0.1:${PREVIEW_PORT}`,
		reuseExistingServer: !process.env.CI,
		timeout: 900_000
	}
});
