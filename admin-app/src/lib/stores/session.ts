import { derived, writable } from 'svelte/store';

export type LicenseStatus = 'inactive' | 'activating' | 'active' | 'error';

export interface SessionState {
	siteUrl: string;
	licenseStatus: LicenseStatus;
	proxyKeyPresent: boolean;
	creditsRemaining: number | null;
	lastSync: string | null;
}

const initialState: SessionState = {
	siteUrl: '',
	licenseStatus: 'inactive',
	proxyKeyPresent: false,
	creditsRemaining: null,
	lastSync: null
};

function createSessionStore() {
	const { subscribe, update, set } = writable<SessionState>(initialState);

	return {
		subscribe,
		reset: () => set(initialState),
		hydrate: (payload: Partial<SessionState>) =>
			update((state) => ({
				...state,
				...payload
			}))
	};
}

export const sessionStore = createSessionStore();

export const licenseSummary = derived(sessionStore, ($session) => {
	switch ($session.licenseStatus) {
		case 'active':
			return $session.proxyKeyPresent ? 'License active' : 'License active — proxy key missing';
		case 'activating':
			return 'Activating license…';
		case 'error':
			return 'Activation error';
		default:
			return 'No active license';
	}
});

export const mockSessionState: SessionState = {
	siteUrl: 'https://example.test',
	licenseStatus: 'active',
	proxyKeyPresent: true,
	creditsRemaining: 842,
	lastSync: new Date().toISOString()
};
