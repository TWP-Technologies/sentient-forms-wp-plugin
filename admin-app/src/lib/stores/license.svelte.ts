import { toStore } from 'svelte/store';
import { createClientFromConfig } from '$lib/api/client';
import type { LicenseActivationResult, LicenseInfoResponse, TierSummary } from '$lib/api/types';
import { notifications } from '$lib/stores/notifications';

export interface LicenseState {
	loading: boolean;
	status: string;
	licenseKeyMasked: string;
	proxyKeyPresent: boolean;
	tier: string | null;
	expiresAt: string | null;
	lastSynced: string | null;
	licenseId: string | null;
	siteId: string | null;
	siteUrl: string;
	error: string | null;
}

/** Extract tier display name from string or TierSummary object */
function extractTierName(tier: unknown): string | null {
	if (typeof tier === 'string') return tier;
	if (tier && typeof tier === 'object' && 'display_name' in tier) {
		return (tier as TierSummary).display_name ?? (tier as TierSummary).code ?? null;
	}
	return null;
}

const runtimeConfig = typeof window === 'undefined' ? undefined : window.sentientFormsConfig;
const bootstrap = runtimeConfig?.license ?? {};

const initialState: LicenseState = {
	loading: false,
	status: bootstrap.status ?? 'inactive',
	licenseKeyMasked: bootstrap.licenseKeyMasked ?? '',
	proxyKeyPresent: bootstrap.proxyKeyPresent ?? false,
	tier: extractTierName(bootstrap.tier),
	expiresAt: bootstrap.expiresAt ?? null,
	lastSynced: bootstrap.lastSynced ?? null,
	licenseId: bootstrap.licenseId ?? null,
	siteId: bootstrap.siteId ?? null,
	siteUrl: runtimeConfig?.siteUrl ?? '',
	error: null
};

function mapResponse(payload: LicenseInfoResponse): LicenseState {
	return {
		loading: false,
		status: payload.status,
		licenseKeyMasked: payload.license_key_masked ?? '',
		proxyKeyPresent: Boolean(payload.proxy_key_present),
		tier: extractTierName(payload.tier),
		expiresAt: payload.expires_at ?? null,
		lastSynced: payload.last_synced ?? null,
		licenseId: payload.license_id ?? null,
		siteId: payload.site_id ?? null,
		siteUrl: payload.site_url ?? initialState.siteUrl,
		error: null
	};
}

export const licenseState = $state({ ...initialState });

const client = createClientFromConfig();
const licenseReadable = toStore(() => licenseState);

function assignLicenseState(partial: Partial<LicenseState>) {
	Object.assign(licenseState, partial);
}

export async function loadLicenseInfoSnapshot(): Promise<LicenseInfoResponse> {
	const response = await client.getLicenseInfo({ showNotifications: false });
	if (response.proxy_key_present) {
		return response;
	}

	return client.bootstrapLicense({ showNotifications: false });
}

async function load() {
	assignLicenseState({ loading: true, error: null });

	try {
		assignLicenseState(mapResponse(await loadLicenseInfoSnapshot()));
	} catch (error) {
		console.error('Failed to load license info', error);
		assignLicenseState({ loading: false, error: 'load' });
	}
}

async function activate(licenseKey: string) {
	if (!licenseKey) {
		notifications.error(runtimeConfig?.i18n?.errorOccurred ?? 'License key required');
		return;
	}

	assignLicenseState({ loading: true, status: 'activating', error: null });

	try {
		const result: LicenseActivationResult = await client.activateLicense(
			{
				licenseKey,
				siteUrl: runtimeConfig?.siteUrl ?? '',
				localSiteIdentifier: runtimeConfig?.localSiteIdentifier ?? ''
			},
			{ showNotifications: true }
		);

		notifications.success(result.message);
		await load();
	} catch (error) {
		console.error('License activation failed', error);
		assignLicenseState({ loading: false, status: 'error', error: 'activate' });
	}
}

async function deactivate() {
	assignLicenseState({ loading: true, error: null });

	try {
		await client.deactivateLicense({ showNotifications: true });
		notifications.success('License deactivated');
		await load();
	} catch (error) {
		console.error('License deactivation failed', error);
		assignLicenseState({ loading: false, error: 'deactivate' });
	}
}

export const licenseStore = {
	subscribe: licenseReadable.subscribe,
	load,
	activate,
	deactivate,
	reset: () => assignLicenseState(initialState)
};
