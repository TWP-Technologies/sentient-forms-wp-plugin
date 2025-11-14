import { readable, toStore } from 'svelte/store';

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

export const sessionState = $state({ ...initialState });

const sessionReadable = toStore(() => sessionState);

function assignState(partial: Partial<SessionState>) {
	Object.assign(sessionState, partial);
}

export const sessionStore = {
	subscribe: sessionReadable.subscribe,
	reset: () => assignState({ ...initialState }),
	hydrate: (payload: Partial<SessionState>) => assignState(payload)
};

function computeLicenseSummary() {
	return sessionState.licenseStatus === 'active'
		? sessionState.proxyKeyPresent
			? 'License active'
			: 'License active — proxy key missing'
		: sessionState.licenseStatus === 'activating'
		? 'Activating license…'
		: sessionState.licenseStatus === 'error'
		? 'Activation error'
		: 'No active license';
}

export const licenseSummary = readable(computeLicenseSummary(), (set) =>
	sessionReadable.subscribe(() => set(computeLicenseSummary()))
);

export const mockSessionState: SessionState = {
	siteUrl: 'https://example.test',
	licenseStatus: 'active',
	proxyKeyPresent: true,
	creditsRemaining: 842,
	lastSync: new Date().toISOString()
};
