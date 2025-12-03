import { toStore } from 'svelte/store';
import { ApiClientError, createClientFromConfig } from '$lib/api/client';
import { notifications } from '$lib/stores/notifications';
import type {
	CustomAction,
	CustomActionCreatePayload,
	CustomActionFilters,
	CustomActionQuota,
	CustomActionUpdatePayload
} from '$lib/api/types';

export interface CustomActionsState {
	loading: boolean;
	creating: boolean;
	error: string | null;
	actions: CustomAction[];
	quota: CustomActionQuota | null;
	filters: CustomActionFilters;
	lastLoadedAt: number | null;
}

const client = createClientFromConfig();

function initialState(): CustomActionsState {
	return {
		loading: false,
		creating: false,
		error: null,
		actions: [],
		quota: null,
		filters: { status: 'active' },
		lastLoadedAt: null
	};
}

const friendlyMessages: Record<string, string> = {
	cps_missing_proxy_key:
		'Activate your Sentient Forms license before managing custom actions.',
	quota_exceeded:
		'You reached the custom action quota for your tier. Archive an existing one or upgrade your plan.'
};

export const customActionsState = $state(initialState());
const readable = toStore(() => customActionsState);

function resetState() {
	Object.assign(customActionsState, initialState());
}

function setState(partial: Partial<CustomActionsState>) {
	Object.assign(customActionsState, partial);
}

function friendlyMessageFromError(error: unknown, fallback: string): string {
	if (error instanceof ApiClientError) {
		if (error.code && friendlyMessages[error.code]) {
			return friendlyMessages[error.code];
		}

		const payload = error.payload as { message?: string } | null;
		if (payload?.message) {
			return payload.message;
		}

		return error.message || fallback;
	}

	if (error instanceof Error) {
		return error.message || fallback;
	}

	return fallback;
}

async function load(filters: CustomActionFilters = customActionsState.filters): Promise<void> {
	customActionsState.loading = true;
	customActionsState.error = null;

	try {
		const response = await client.getCustomActions(filters, { showNotifications: false });
		const sorted = [...response.actions].sort((a, b) =>
			new Date(b.updated_at).getTime() - new Date(a.updated_at).getTime()
		);
		setState({
			actions: sorted,
			quota: response.quota,
			loading: false,
			error: null,
			filters,
			lastLoadedAt: Date.now()
		});
	} catch (error) {
		if (error instanceof ApiClientError && error.status === 404) {
			// Endpoint not available yet; treat as empty list but surface a mild warning.
			setState({
				loading: false,
				error: 'Custom Actions API is not available on this backend. Deploy or enable CPS custom actions to use this page.',
				actions: [],
				quota: null,
				lastLoadedAt: Date.now()
			});
			return;
		}

		const message = friendlyMessageFromError(error, 'Failed to load custom actions');
		setState({ loading: false, error: message, actions: [] });
		notifications.error(message);
	}
}

async function create(payload: CustomActionCreatePayload): Promise<void> {
	customActionsState.creating = true;
	try {
		const response = await client.createCustomAction(payload, { showNotifications: true });
		customActionsState.actions = [response.action, ...customActionsState.actions];
		customActionsState.quota = response.quota;
		customActionsState.error = null;
		notifications.success('Custom action created');
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Unable to create custom action');
		notifications.error(message);
		throw error;
	} finally {
		customActionsState.creating = false;
	}
}

async function update(id: string, payload: CustomActionUpdatePayload): Promise<void> {
	try {
		const response = await client.updateCustomAction(id, payload, { showNotifications: true });
		customActionsState.actions = customActionsState.actions.map((action) =>
			action.id === id ? response.action : action
		);
		customActionsState.quota = response.quota;
		notifications.success('Custom action updated');
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Unable to update custom action');
		notifications.error(message);
		throw error;
	}
}

async function archive(id: string): Promise<void> {
	try {
		const response = await client.archiveCustomAction(id, { showNotifications: true });
		customActionsState.actions = customActionsState.actions.map((action) =>
			action.id === id ? response.action : action
		);
		customActionsState.quota = response.quota;
		notifications.success('Custom action archived');
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Unable to archive custom action');
		notifications.error(message);
		throw error;
	}
}

async function reactivate(id: string): Promise<void> {
	try {
		const response = await client.reactivateCustomAction(id, { showNotifications: true });
		customActionsState.actions = customActionsState.actions.map((action) =>
			action.id === id ? response.action : action
		);
		customActionsState.quota = response.quota;
		notifications.success('Custom action reactivated');
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Unable to reactivate custom action');
		notifications.error(message);
		throw error;
	}
}

function setFilters(filters: CustomActionFilters): void {
	setState({ filters });
}

export const customActionsStore = {
	subscribe: readable.subscribe,
	load,
	reload: () => load(customActionsState.filters),
	create,
	update,
	archive,
	reactivate,
	setFilters,
	reset: resetState
};
