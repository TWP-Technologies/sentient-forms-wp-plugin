import { toStore } from 'svelte/store';
import { ApiClientError, createClientFromConfig } from '$lib/api/client';
import { notifications } from '$lib/stores/notifications';
import { sortCustomActionsByRecency } from '$lib/utils/date-time';
import type {
	ActionDefinition,
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
	supportsCustomActions: boolean;
	cpsVersion: string | null;
	requiredCustomActionsVersion?: string;
	actions: CustomAction[];
	definitions: ActionDefinition[];
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
		supportsCustomActions: true,
		cpsVersion: null,
		requiredCustomActionsVersion: '1.0.0',
		actions: [],
		definitions: [],
		quota: null,
		filters: { status: 'active' },
		lastLoadedAt: null
	};
}

const friendlyMessages: Record<string, string> = {
	cps_missing_proxy_key:
		'Custom Actions are available locally. Configure Direct OpenRouter or Sentient managed execution before running actions through that provider.',
	quota_exceeded:
		'You reached the custom action quota for the selected provider path. Archive an existing action or adjust provider limits before retrying.'
};

export const customActionsState = $state(initialState());
const readable = toStore(() => customActionsState);

function resetState() {
	Object.assign(customActionsState, initialState());
}

function setState(partial: Partial<CustomActionsState>) {
	Object.assign(customActionsState, partial);
}

function setSortedActions(actions: CustomAction[]): void {
	customActionsState.actions = sortCustomActionsByRecency(actions);
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
		try {
			const caps = await client.getCapabilities({ showNotifications: false });
			customActionsState.supportsCustomActions = caps.supports_custom_actions ?? true;
			customActionsState.cpsVersion = caps.cps_version ?? null;
		} catch {
			// best effort; fall back to 404 detection
		}

			const [response, definitions] = await Promise.all([
				client.getCustomActions(filters, { showNotifications: false }),
				client.getActionDefinitions({ showNotifications: false })
			]);
			setState({
				actions: sortCustomActionsByRecency(response.actions),
				definitions,
				quota: response.quota,
			loading: false,
			error: null,
			supportsCustomActions: true,
			filters,
			lastLoadedAt: Date.now()
		});
	} catch (error) {
		if (error instanceof ApiClientError && error.status === 404) {
			// Endpoint not available yet; treat as empty list but surface a mild warning.
			setState({
				loading: false,
				supportsCustomActions: false,
				error: 'Custom Actions API is not available in this plugin build. Refresh the plugin or enable local custom actions to use this page.',
				actions: [],
				quota: null,
				lastLoadedAt: Date.now()
			});
			return;
		}

		const message = friendlyMessageFromError(error, 'Failed to load custom actions');
		setState({ loading: false, error: message, actions: [], supportsCustomActions: true });
		notifications.error(message);
	}
}

async function create(payload: CustomActionCreatePayload): Promise<void> {
	customActionsState.creating = true;
	try {
		const response = await client.createCustomAction(payload, { showNotifications: true });
		setSortedActions([response.action, ...customActionsState.actions]);
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
		setSortedActions(
			customActionsState.actions.map((action) => (action.id === id ? response.action : action))
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
		setSortedActions(
			customActionsState.actions.map((action) => (action.id === id ? response.action : action))
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
		setSortedActions(
			customActionsState.actions.map((action) => (action.id === id ? response.action : action))
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

function hydrate(
	response: { actions: CustomAction[]; quota: CustomActionQuota | null },
	filters: CustomActionFilters = { status: 'active' }
): void {
	setState({
		actions: sortCustomActionsByRecency(response.actions),
		quota: response.quota,
		loading: false,
		error: null,
		supportsCustomActions: true,
		filters,
		lastLoadedAt: Date.now()
	});
}

export const customActionsStore = {
	subscribe: readable.subscribe,
	load,
	reload: () => load(customActionsState.filters),
	hydrate,
	create,
	update,
	archive,
	reactivate,
	setFilters,
	reset: resetState
};
