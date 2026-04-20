import { defineConfig, devices } from '@playwright/test';
import { getPreviewHost, getPreviewOrigin, getPreviewPort } from './tests/e2e/utils/preview-origin';

const PREVIEW_HOST = getPreviewHost();
const PREVIEW_PORT = getPreviewPort();
const PREVIEW_ORIGIN = getPreviewOrigin();

const isWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';

const previewWebServer = isWpE2E
	? undefined
	: {
			command: 'bun run preview:e2e',
			url: PREVIEW_ORIGIN,
			env: {
				...process.env,
				PREVIEW_HOST,
				PREVIEW_PORT: String(PREVIEW_PORT)
			},
			reuseExistingServer: !process.env.CI,
			timeout: 900_000
		};

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 300_000,
	globalSetup: './tests/e2e/global-setup.ts',
	expect: {
		timeout: 5_000
	},
	// WP E2E tests share WordPress/Gravity Forms state; legacy CPS specs also share license and credit state.
	workers: isWpE2E ? 1 : undefined,
	fullyParallel: !isWpE2E,
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: PREVIEW_ORIGIN,
		trace: 'on-first-retry'
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] }
		}
	],
	webServer: previewWebServer
});
