import { writable } from 'svelte/store';
import { ApiClientError, createClientFromConfig } from '$lib/api/client';
import { notifications } from '$lib/stores/notifications';
import type {
	FormActionLinkage,
	FormActionMutationPayload,
	CreditBalanceResponse,
	ActionDefinition,
	ExecutionStatus,
	FormExecutionStatus,
	ApiErrorPayload
} from '$lib/api/types';

export interface FormActionsState {
	loading: boolean;
	error: string | null;
	items: FormActionLinkage[];
	balance: CreditBalanceResponse | null;
	definitions: ActionDefinition[];
	status: FormExecutionStatus | null;
}

const client = createClientFromConfig();

function initialState(): FormActionsState {
	return {
		loading: false,
		error: null,
		items: [],
		balance: null,
		definitions: [],
		status: null
	};
}

const friendlyMessages: Record<string, string> = {
	insufficient_credits:
		'Sentient Forms could not run because this license is out of credits. Visit the Licensing tab to add credits before retrying.',
	rate_limited:
		'Sentient Forms is temporarily rate limiting requests. Please wait a minute and try again.',
	timeout:
		'Sentient Forms timed out while contacting the CPS service. Retry the request shortly.',
	llm_error: 'Sentient Forms encountered an upstream LLM error. Try again in a few moments.',
	invalid_action_id:
		'This Sentient Forms action mapping is no longer valid. Reconfigure the action before retrying.',
	invalid_request:
		'Sentient Forms sent an invalid payload to CPS. Review your action configuration and try again.',
	cps_missing_proxy_key:
		'Sentient Forms proxy key is missing. Activate your license on the Licensing tab to resume execution.',
	duplicate_execution:
		'Sentient Forms already processed this submission. Refresh the status to review the previous result.'
};

function friendlyMessageFromError(error: unknown, fallback: string): string {
	if (error instanceof ApiClientError) {
		const payload = (error.payload ?? null) as ApiErrorPayload | null;
		const nestedCode =
			payload?.error && typeof payload.error === 'object'
				? (payload.error as { code?: string }).code ?? ''
				: '';
		const code = error.code || nestedCode || '';
		const payloadMessage =
			typeof payload?.error?.message === 'string'
				? payload?.error?.message
				: typeof payload?.message === 'string'
				? payload?.message
				: undefined;

		if (code && friendlyMessages[code]) {
			return friendlyMessages[code];
		}

		if (payloadMessage) {
			return payloadMessage;
		}

		return error.message || fallback;
	}

	if (error instanceof Error) {
		return error.message || fallback;
	}

	return fallback;
}

const { subscribe, set, update } = writable<FormActionsState>(initialState());

function resetState() {
	set(initialState());
}

function setState(partial: Partial<FormActionsState>) {
	update((previous) => ({ ...previous, ...partial }));
}

async function load(formSourceSlug: string, formId: number) {
	resetState();
	setState({ loading: true });

	try {
		const [items, balance, definitions, status] = await Promise.all([
			client.getFormActions(formSourceSlug, formId, { showNotifications: false }),
			client.getCreditBalance({ showNotifications: false }),
			client.getActionDefinitions({ showNotifications: false }),
			client.getFormExecutionStatus(formSourceSlug, formId, { showNotifications: false })
		]);

		set({ loading: false, error: null, items, balance, definitions, status });
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to load actions');
		set({ ...initialState(), error: message });
		notifications.error(message);
	}
}

async function create(formSourceSlug: string, formId: number, payload: FormActionMutationPayload) {
	try {
		const created = await client.createFormAction(formSourceSlug, formId, payload);
		update((previous) => ({
			...previous,
			items: [...previous.items, created]
		}));
		notifications.success('Action mapping created');
		await refresh(formSourceSlug, formId);
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to create action mapping');
		notifications.error(message);
	}
}

async function toggleEnabled(
	formSourceSlug: string,
	formId: number,
	linkage: FormActionLinkage,
	enabled: boolean
) {
	try {
		const updated = await client.updateFormAction(formSourceSlug, formId, linkage.local_mapping_id, {
			is_action_enabled_for_form: enabled
		});

		update((previous) => ({
			...previous,
			items: previous.items.map((item) =>
				item.local_mapping_id === updated.local_mapping_id ? updated : item
			)
		}));
		await refresh(formSourceSlug, formId);
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to update action mapping');
		notifications.error(message);
	}
}

async function updateHooks(
	formSourceSlug: string,
	formId: number,
	linkage: FormActionLinkage,
	hooks: string[]
) {
	const normalizedHooks = hooks
		.map((hook) => hook?.toString().trim())
		.filter((hook): hook is string => Boolean(hook));

	if (normalizedHooks.length === 0) {
		notifications.error('Select at least one trigger hook.');
		return;
	}

	try {
		const updated = await client.updateFormAction(
			formSourceSlug,
			formId,
			linkage.local_mapping_id,
			{ trigger_hooks: normalizedHooks }
		);

		update((previous) => ({
			...previous,
			items: previous.items.map((item) =>
				item.local_mapping_id === updated.local_mapping_id ? updated : item
			),
			error: null
		}));

		notifications.success('Trigger hooks updated');
		await refresh(formSourceSlug, formId);
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to update trigger hooks');
		notifications.error(message);
	}
}

async function remove(formSourceSlug: string, formId: number, linkage: FormActionLinkage) {
	try {
		await client.deleteFormAction(formSourceSlug, formId, linkage.local_mapping_id);
		update((previous) => ({
			...previous,
			items: previous.items.filter((item) => item.local_mapping_id !== linkage.local_mapping_id)
		}));
		notifications.success('Action mapping deleted');
		await refresh(formSourceSlug, formId);
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to delete action mapping');
		notifications.error(message);
	}
}

async function refresh(formSourceSlug: string, formId: number) {
	try {
		const [balance, status] = await Promise.all([
			client.getCreditBalance({ showNotifications: false }),
			client.getFormExecutionStatus(formSourceSlug, formId, { showNotifications: false })
		]);

		setState({ balance, status, error: null });
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to refresh Sentient Forms status');
		notifications.error(message);
		setState({ error: message });
	}
}

async function fetchExecutionStatus(
	formSourceSlug: string,
	formId: number,
	entryId: number
): Promise<ExecutionStatus> {
	try {
		return await client.getExecutionStatus(formSourceSlug, formId, entryId, {
			showNotifications: false
		});
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Unable to fetch execution status');
		notifications.error(message);
		throw error;
	}
}

export const formActionsStore = {
	subscribe,
	load,
	create,
	toggleEnabled,
	updateHooks,
	remove,
	refresh,
	fetchExecutionStatus,
	reset: resetState
};
