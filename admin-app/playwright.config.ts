import { defineConfig, devices } from '@playwright/test';

const PREVIEW_PORT = 4173;

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 300_000,
	expect: {
		timeout: 5_000
	},
	fullyParallel: true,
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
		command: 'bun run preview:serve',
		url: `http://127.0.0.1:${PREVIEW_PORT}`,
		reuseExistingServer: true,
		timeout: 900_000
	}
});
