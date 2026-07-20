import { defineConfig, devices } from '@playwright/test';
import { getPreviewHost, getPreviewOrigin, getPreviewPort } from './tests/e2e/utils/preview-origin';

const PREVIEW_HOST = getPreviewHost();
const PREVIEW_PORT = getPreviewPort();
const PREVIEW_ORIGIN = getPreviewOrigin();

const isWpE2E = process.env.SENTIENT_RUN_WP_E2E === '1';
const isOwnedPreview = process.env.SENTIENT_FORMS_OWNED_PREVIEW === '1';
const configuredWorkers = Number(process.env.SENTIENT_FORMS_PLAYWRIGHT_WORKERS ?? '1');

const previewWebServer =
	isWpE2E || isOwnedPreview
		? undefined
		: {
				command: 'bun run preview:e2e',
				url: PREVIEW_ORIGIN,
				env: {
					...process.env,
					PREVIEW_HOST,
					PREVIEW_PORT: String(PREVIEW_PORT)
				},
				// Direct Playwright CLI use starts its own preview and must never reuse an
				// arbitrary listener. The normal E2E wrapper supplies an already owned server.
				reuseExistingServer: false,
				timeout: 900_000
			};

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 300_000,
	expect: {
		timeout: 5_000
	},
	// WP E2E tests share marker-owned WordPress and Gravity Forms fixtures.
	workers: isWpE2E ? 1 : undefined,
	fullyParallel: !isWpE2E,
	reporter: [['list'], ['html', { open: 'never' }]],
	use: {
		baseURL: PREVIEW_ORIGIN,
		trace: 'on-first-retry'
	},
	// The admin preview suite exercises a single mocked frontend surface and has proven
	// flaky under high local parallelism. Default to deterministic workers unless an
	// explicit override is provided.
	workers: isWpE2E ? 1 : configuredWorkers,
	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] }
		}
	],
	webServer: previewWebServer
});
