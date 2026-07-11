import { existsSync } from 'node:fs';
import type { Page } from '@playwright/test';
import type { SentientFormsConfig } from '$lib/schemas/runtime-config';
import type { FormSourceSummary } from '$lib/api/types';

const defaultFormSources: FormSourceSummary[] = [
	{ slug: 'gravity_forms', label: 'Gravity Forms', isActive: true }
];

const defaultWpHost = existsSync('/.dockerenv')
	? 'http://host.docker.internal:8080'
	: 'http://localhost:8080';
const wpHost = process.env.SENTIENT_WP_BASE_URL ?? defaultWpHost;
const useRestRoute = process.env.SENTIENT_WP_USE_REST_ROUTE === '1';
const apiBase = useRestRoute
	? '/index.php?rest_route=/sentient-forms/v1/'
	: '/wp-json/sentient-forms/v1/';

const baseConfig: SentientFormsConfig = {
	apiBaseUrl: apiBase,
	restNonce: 'e2e-rest-nonce',
	ajaxNonce: 'e2e-ajax-nonce',
	siteUrl: wpHost,
	localSiteIdentifier: 'local-dev',
	formSources: defaultFormSources,
	license: {
		status: 'inactive',
		licenseKeyMasked: '',
		proxyKeyPresent: false,
		tier: null,
		expiresAt: null,
		lastSynced: null,
		licenseId: null,
		siteId: null
	}
};

export async function seedRuntimeConfig(
	page: Page,
	overrides: Partial<SentientFormsConfig> = {}
): Promise<void> {
	const config: SentientFormsConfig = { ...baseConfig, ...overrides };

	await page.addInitScript((cfg) => {
		// eslint-disable-next-line @typescript-eslint/ban-ts-comment
		// @ts-ignore - window shape is augmented at runtime
		window.sentientFormsConfig = cfg;
	}, config);
}
