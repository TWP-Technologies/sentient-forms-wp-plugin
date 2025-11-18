import { writable } from 'svelte/store';
import { createClientFromConfig, type SentientFormsApiClient } from '$lib/api/client';
import type { AsyncHealthResponse } from '$lib/api/types';

export interface AsyncHealthState extends AsyncHealthResponse {
	loading: boolean;
	lastFetched: number | null;
}

const runtime = typeof window === 'undefined' ? undefined : window.sentientFormsConfig?.asyncHealth;

const initialState: AsyncHealthState = {
	queue_depth: runtime?.queue_depth ?? 0,
	oldest_run_at: runtime?.oldest_run_at ?? null,
	recent_failures: runtime?.recent_failures ?? {},
	warnings: runtime?.warnings ?? [],
	loading: false,
	lastFetched: runtime ? Date.now() : null
};

export function createAsyncHealthStore(client: SentientFormsApiClient = createClientFromConfig()) {
	const { subscribe, set, update } = writable<AsyncHealthState>({ ...initialState });

	return {
		subscribe,
		async refresh() {
			update((state) => ({ ...state, loading: true }));
			try {
				const response = await client.getAsyncHealth({ showNotifications: false });
				set({ ...response, loading: false, lastFetched: Date.now() });
				return response;
			} catch (error) {
				console.error('Failed to load async health', error);
				update((state) => ({ ...state, loading: false }));
				return null;
			}
		},
		reset() {
			set({ ...initialState });
		}
	};
}

export const asyncHealthStore = createAsyncHealthStore();
