import { writable } from 'svelte/store';
import { createClientFromConfig } from '$lib/api/client';
import type { LicenseActivationResult, LicenseInfoResponse } from '$lib/api/types';
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

const runtimeConfig = window.sentientFormsConfig;
const bootstrap = runtimeConfig?.license ?? {};

const initialState: LicenseState = {
	loading: false,
	status: bootstrap.status ?? 'inactive',
	licenseKeyMasked: bootstrap.licenseKeyMasked ?? '',
	proxyKeyPresent: bootstrap.proxyKeyPresent ?? false,
	tier: bootstrap.tier ?? null,
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
		tier: payload.tier ?? null,
		expiresAt: payload.expires_at ?? null,
		lastSynced: payload.last_synced ?? null,
		licenseId: payload.license_id ?? null,
		siteId: payload.site_id ?? null,
		siteUrl: payload.site_url ?? initialState.siteUrl,
		error: null
	};
}

function createLicenseStore() {
	const client = createClientFromConfig();
	const { subscribe, set, update } = writable<LicenseState>(initialState);

	async function load() {
		update((state) => ({ ...state, loading: true, error: null }));

		try {
			const response = await client.getLicenseInfo({ showNotifications: false });
			set(mapResponse(response));
		} catch (error) {
			console.error('Failed to load license info', error);
			update((state) => ({ ...state, loading: false, error: 'load' }));
		}
	}

	async function activate(licenseKey: string) {
		if (!licenseKey) {
			notifications.error(runtimeConfig?.i18n?.errorOccurred ?? 'License key required');
			return;
		}

		update((state) => ({ ...state, loading: true, status: 'activating', error: null }));

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
			update((state) => ({ ...state, loading: false, status: 'error', error: 'activate' }));
		}
	}

	async function deactivate() {
		update((state) => ({ ...state, loading: true, error: null }));

		try {
			await client.deactivateLicense({ showNotifications: true });
			notifications.success('License deactivated');
			await load();
		} catch (error) {
			console.error('License deactivation failed', error);
			update((state) => ({ ...state, loading: false, error: 'deactivate' }));
		}
	}

	return {
		subscribe,
		load,
		activate,
		deactivate,
		reset: () => set(initialState)
	};
}

export const licenseStore = createLicenseStore();
