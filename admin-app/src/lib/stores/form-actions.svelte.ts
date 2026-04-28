import { toStore } from 'svelte/store';
import { ApiClientError, createClientFromConfig } from '$lib/api/client';
import { notifications } from '$lib/stores/notifications';
import type {
	FormActionLinkage,
	FormActionMutationPayload,
	ActionDefinition,
	ExecutionStatus,
	FormExecutionStatus,
	ApiErrorPayload
} from '$lib/api/types';

export interface FormActionsState {
	loading: boolean;
	error: string | null;
	items: FormActionLinkage[];
	definitions: ActionDefinition[];
	status: FormExecutionStatus | null;
	supportsStatus: boolean;
	cpsVersion: string | null;
	requiredStatusVersion?: string;
	/** CB-FORMS-001: Per-form master disable */
	sfDisabled: boolean;
	/** CB-FORMS-002: Global execution disable flag */
	globalDisabled: boolean;
	/** CB-FORMS-002: Provider-level execution disable flag */
	providerDisabled: boolean;
	/** CB-FORMS-002: Effective disable state (form OR global OR provider) */
	effectiveDisabled: boolean;
}

const client = createClientFromConfig();

function initialState(): FormActionsState {
	return {
		loading: false,
		error: null,
		items: [],
		definitions: [],
		status: null,
		supportsStatus: true,
		cpsVersion: null,
		requiredStatusVersion: '1.0.0',
		sfDisabled: false,
		globalDisabled: false,
		providerDisabled: false,
		effectiveDisabled: false
	};
}

const friendlyMessages: Record<string, string> = {
	insufficient_credits:
		'Sentient Forms could not run because this managed license is out of credits. Visit the Licensing tab to review billing before retrying.',
	rate_limited:
		'Sentient Forms is temporarily rate limiting requests. Please wait a minute and try again.',
	timeout:
		'Sentient Forms timed out while contacting the execution service. Retry the request shortly.',
	llm_error: 'Sentient Forms encountered an upstream LLM error. Try again in a few moments.',
	invalid_action_id:
		'This Sentient Forms action mapping is no longer valid. Reconfigure the action before retrying.',
	invalid_request:
		'Sentient Forms sent an invalid execution payload. Review your action configuration and try again.',
	cps_missing_proxy_key:
		'Sentient Forms managed service key is missing. Activate your license on the Managed Service tab to resume execution.',
	duplicate_execution:
		'Sentient Forms already processed this submission. Refresh the status to review the previous result.'
};

function formatCreditCount(value: number): string {
	const absolute = Math.abs(value).toLocaleString();
	const label = Math.abs(value) === 1 ? 'credit' : 'credits';
	return `${value < 0 ? '-' : ''}${absolute} ${label}`;
}

function insufficientCreditsMessage(payload: ApiErrorPayload | null): string {
	const meta = payload?.error?.meta;
	const currentBalance =
		typeof meta?.current_balance === 'number' && Number.isFinite(meta.current_balance)
			? meta.current_balance
			: null;
	const requiredCredits =
		typeof meta?.required_credits === 'number' && Number.isFinite(meta.required_credits)
			? meta.required_credits
			: null;

	if (currentBalance !== null && currentBalance < 0) {
		return `Sentient Forms paused new runs because this managed license has a negative balance of ${formatCreditCount(currentBalance)}. Visit the Licensing tab to review billing before retrying.`;
	}

	if (currentBalance !== null && requiredCredits !== null) {
		return `Sentient Forms needs ${formatCreditCount(requiredCredits)} for this managed run, but only ${formatCreditCount(currentBalance)} remain. Visit the Licensing tab to review billing before retrying.`;
	}

	return friendlyMessages.insufficient_credits;
}

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

			if (code === 'insufficient_credits') {
				return insufficientCreditsMessage(payload);
			}

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

		// CB-FORMS-001: Load per-form disabled state (best-effort)
		try {
			const disableResult = await client.getFormDisabled(formSourceSlug, formId, { showNotifications: false });
			setState({
				sfDisabled: disableResult.sf_disabled,
				globalDisabled: disableResult.global_disabled ?? false,
				providerDisabled: disableResult.provider_disabled ?? false,
				effectiveDisabled:
					disableResult.effective_disabled ??
					(disableResult.sf_disabled ||
						disableResult.global_disabled === true ||
						disableResult.provider_disabled === true)
			});
		} catch {
			// Endpoint may not exist on older plugin versions; default false.
		}
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

/** CB-FORMS-001: Toggle per-form master disable. */
async function toggleFormDisabled(
	formSourceSlug: string,
	formId: number,
	disabled: boolean
) {
	const previous = {
		sfDisabled: formActionsState.sfDisabled,
		globalDisabled: formActionsState.globalDisabled,
		providerDisabled: formActionsState.providerDisabled,
		effectiveDisabled: formActionsState.effectiveDisabled
	};
	// optimistic update
	formActionsState.sfDisabled = disabled;
	formActionsState.effectiveDisabled =
		disabled || formActionsState.globalDisabled || formActionsState.providerDisabled;

	try {
		const result = await client.toggleFormDisabled(formSourceSlug, formId, disabled);
		formActionsState.sfDisabled = result.sf_disabled;
		formActionsState.globalDisabled = result.global_disabled ?? false;
		formActionsState.providerDisabled = result.provider_disabled ?? false;
		formActionsState.effectiveDisabled =
			result.effective_disabled ??
			(result.sf_disabled ||
				result.global_disabled === true ||
				result.provider_disabled === true);
		notifications.success(
			result.message ??
				(result.sf_disabled
					? 'Sentient Forms disabled for this form.'
					: 'Sentient Forms enabled for this form.')
		);
	} catch (error) {
		formActionsState.sfDisabled = previous.sfDisabled;
		formActionsState.globalDisabled = previous.globalDisabled;
		formActionsState.providerDisabled = previous.providerDisabled;
		formActionsState.effectiveDisabled = previous.effectiveDisabled;
		const message = friendlyMessageFromError(error, 'Failed to update form disabled state');
		notifications.error(message);
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
	toggleFormDisabled,
	reset: resetState
};
