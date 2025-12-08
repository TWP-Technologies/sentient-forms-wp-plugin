import type { Page } from '@playwright/test';
import type { SentientFormsConfig } from '$lib/api/http';
import type { FormSourceSummary } from '$lib/api/types';

const defaultFormSources: FormSourceSummary[] = [
	{ slug: 'gravity_forms', label: 'Gravity Forms', isActive: true }
];

const baseConfig: SentientFormsConfig = {
	apiBaseUrl: '/wp-json/sentient-forms/v1/',
	restNonce: 'e2e-rest-nonce',
	ajaxNonce: 'e2e-ajax-nonce',
	siteUrl: 'http://localhost:8080',
	localSiteIdentifier: 'local-dev',
	formSources: defaultFormSources
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
