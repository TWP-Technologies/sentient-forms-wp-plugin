import { toStore } from 'svelte/store';
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
	supportsCredits: boolean;
	definitions: ActionDefinition[];
	status: FormExecutionStatus | null;
	supportsStatus: boolean;
	cpsVersion: string | null;
	requiredCreditsVersion?: string;
	requiredStatusVersion?: string;
}

const client = createClientFromConfig();

function initialState(): FormActionsState {
	return {
		loading: false,
		error: null,
		items: [],
		balance: null,
		supportsCredits: true,
		definitions: [],
		status: null,
		supportsStatus: true,
		cpsVersion: null,
		requiredCreditsVersion: '1.0.0',
		requiredStatusVersion: '1.0.0'
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

export const formActionsState = $state(initialState());
const readable = toStore(() => formActionsState);
let refreshInFlight = false;

function resetState() {
	Object.assign(formActionsState, initialState());
}

function setState(partial: Partial<FormActionsState>) {
	Object.assign(formActionsState, partial);
}

async function load(formSourceSlug: string, formId: number) {
	// Guard against undefined or invalid parameters during hydration race conditions
	if (!formSourceSlug || formSourceSlug === 'undefined' || !formId || Number.isNaN(formId)) {
		console.warn('[formActionsStore] load called with invalid params:', { formSourceSlug, formId });
		return;
	}

	resetState();
	formActionsState.loading = true;

	try {
		try {
			const caps = await client.getCapabilities({ showNotifications: false });
			setState({
				supportsCredits: caps.supports_credits ?? true,
				supportsStatus: caps.supports_status ?? true,
				cpsVersion: caps.cps_version ?? null
			});
		} catch {
			// Capability fetch is best-effort; ignore failures and fall back.
		}

		const [items, definitions, status] = await Promise.all([
			client.getFormActions(formSourceSlug, formId, { showNotifications: false }),
			client.getActionDefinitions({ showNotifications: false }),
			client.getFormExecutionStatus(formSourceSlug, formId, { showNotifications: false })
		]);

		setState({ loading: false, error: null, items, definitions, status });
		await refreshBalance();
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to load actions');
		resetState();
		setState({ loading: false, error: message });
		notifications.error(message);
	}
}

async function create(formSourceSlug: string, formId: number, payload: FormActionMutationPayload) {
	console.log('formActionsStore.create payload', formSourceSlug, formId, payload);
	try {
		const created = await client.createFormAction(formSourceSlug, formId, payload);
		console.log('formActionsStore.create success', created);
		formActionsState.items = [...formActionsState.items, created];
		notifications.success('Action mapping created');
		await refresh(formSourceSlug, formId);
	} catch (error) {
		console.error('formActionsStore.create failed', error);
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
	const previousItems = [...formActionsState.items];
	// optimistic update
	formActionsState.items = formActionsState.items.map((item) =>
		item.local_mapping_id === linkage.local_mapping_id
			? { ...item, is_action_enabled_for_form: enabled }
			: item
	);

	try {
		const updated = await client.updateFormAction(formSourceSlug, formId, linkage.local_mapping_id, {
			is_action_enabled_for_form: enabled
		});

		formActionsState.items = formActionsState.items.map((item) =>
			item.local_mapping_id === updated.local_mapping_id ? updated : item
		);
		await refresh(formSourceSlug, formId);
	} catch (error) {
		// rollback
		formActionsState.items = previousItems;
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

	const previousItems = [...formActionsState.items];
	// optimistic update
	formActionsState.items = formActionsState.items.map((item) =>
		item.local_mapping_id === linkage.local_mapping_id
			? { ...item, trigger_hooks: normalizedHooks }
			: item
	);
	formActionsState.error = null;

	try {
		const updated = await client.updateFormAction(
			formSourceSlug,
			formId,
			linkage.local_mapping_id,
			{ trigger_hooks: normalizedHooks }
		);

		formActionsState.items = formActionsState.items.map((item) =>
			item.local_mapping_id === updated.local_mapping_id ? updated : item
		);
		formActionsState.error = null;

		notifications.success('Trigger hooks updated');
		await refresh(formSourceSlug, formId);
	} catch (error) {
		formActionsState.items = previousItems;
		const message = friendlyMessageFromError(error, 'Failed to update trigger hooks');
		notifications.error(message);
	}
}

async function updateAction(
	formSourceSlug: string,
	formId: number,
	linkage: FormActionLinkage,
	payload: Partial<FormActionMutationPayload>,
	successMessage = 'Action updated'
) {
	const previousItems = [...formActionsState.items];
	// optimistic update
	formActionsState.items = formActionsState.items.map((item) =>
		item.local_mapping_id === linkage.local_mapping_id ? { ...item, ...payload } : item
	);

	try {
		const updated = await client.updateFormAction(
			formSourceSlug,
			formId,
			linkage.local_mapping_id,
			payload
		);

		formActionsState.items = formActionsState.items.map((item) =>
			item.local_mapping_id === updated.local_mapping_id ? updated : item
		);

		notifications.success(successMessage);
		await refresh(formSourceSlug, formId);
	} catch (error) {
		formActionsState.items = previousItems;
		const message = friendlyMessageFromError(error, 'Failed to update action');
		notifications.error(message);
	}
}

async function remove(formSourceSlug: string, formId: number, linkage: FormActionLinkage) {
	try {
		await client.deleteFormAction(formSourceSlug, formId, linkage.local_mapping_id);
		formActionsState.items = formActionsState.items.filter(
			(item) => item.local_mapping_id !== linkage.local_mapping_id
		);
		notifications.success('Action mapping deleted');
		await refresh(formSourceSlug, formId);
	} catch (error) {
		const message = friendlyMessageFromError(error, 'Failed to delete action mapping');
		notifications.error(message);
	}
}

async function refresh(formSourceSlug: string, formId: number) {
	// Guard against undefined or invalid parameters during hydration race conditions
	if (!formSourceSlug || formSourceSlug === 'undefined' || !formId || Number.isNaN(formId)) {
		console.warn('[formActionsStore] refresh called with invalid params:', { formSourceSlug, formId });
		return;
	}

	if (refreshInFlight) {
		return;
	}
	refreshInFlight = true;
	try {
		const status = await client.getFormExecutionStatus(formSourceSlug, formId, {
			showNotifications: false
		});

		setState({ status, error: null, supportsStatus: true });
		await refreshBalance();
	} catch (error) {
		if (error instanceof ApiClientError && error.status === 404) {
			setState({ supportsStatus: false, error: null });
			refreshInFlight = false;
			return;
		}
		const message = friendlyMessageFromError(error, 'Failed to refresh Sentient Forms status');
		notifications.error(message);
		setState({ error: message });
	}
	refreshInFlight = false;
}

async function refreshBalance() {
	try {
		const balance = await client.getCreditBalance({ showNotifications: false });
		setState({ balance, error: null, supportsCredits: true });
	} catch (error) {
		const fallbackMessage = 'Credit balance unavailable right now.';
		if (error instanceof ApiClientError && error.status === 404) {
			setState({ balance: null, supportsCredits: false });
			notifications.warning(fallbackMessage);
			return;
		}
		const friendly = friendlyMessageFromError(error, fallbackMessage);
		const message =
			!friendly || friendly === 'Not Found' || friendly === 'Request failed'
				? fallbackMessage
				: friendly;
		// Show a warning but do not fail the page.
		notifications.warning(message);
		setState({ balance: null });
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
	subscribe: readable.subscribe,
	load,
	create,
	toggleEnabled,
	updateHooks,
	updateAction,
	remove,
	refresh,
	fetchExecutionStatus,
	reset: resetState
};
