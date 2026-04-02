import { beforeEach, describe, expect, it, vi } from 'vitest';

type StubClient = {
	getFormActions: ReturnType<typeof vi.fn>;
	getCreditBalance: ReturnType<typeof vi.fn>;
	getActionDefinitions: ReturnType<typeof vi.fn>;
	getFormExecutionStatus: ReturnType<typeof vi.fn>;
	getFormDisabled: ReturnType<typeof vi.fn>;
	toggleFormDisabled: ReturnType<typeof vi.fn>;
	createFormAction: ReturnType<typeof vi.fn>;
	updateFormAction: ReturnType<typeof vi.fn>;
	deleteFormAction: ReturnType<typeof vi.fn>;
	getExecutionStatus: ReturnType<typeof vi.fn>;
};

const stubClient = vi.hoisted(() => {
	return {
		getFormActions: vi.fn(),
		getCreditBalance: vi.fn(),
		getActionDefinitions: vi.fn(),
		getFormExecutionStatus: vi.fn(),
		getFormDisabled: vi.fn(),
		toggleFormDisabled: vi.fn(),
		createFormAction: vi.fn(),
		updateFormAction: vi.fn(),
		deleteFormAction: vi.fn(),
		getExecutionStatus: vi.fn()
	} as StubClient;
});

vi.mock('$lib/api/client', async () => {
	const actual = await vi.importActual<typeof import('$lib/api/client')>('$lib/api/client');
	return {
		...actual,
		createClientFromConfig: vi.fn(() => stubClient)
	};
});

import { notifications } from '$lib/stores/notifications';
import type { FormExecutionStatus } from '$lib/api/types';
import { ApiClientError } from '$lib/api/client';
import { formActionsStore } from '$lib/stores/form-actions';
import type { FormActionsState } from '$lib/stores/form-actions';

type StoreValue = FormActionsState;

function snapshotState() {
	let current: StoreValue | undefined;
	const unsubscribe = formActionsStore.subscribe((value) => {
		current = value;
	});
	unsubscribe();
	return current!;
}

describe('formActionsStore', () => {
	const noopStatus: FormExecutionStatus = {
		status: 'unknown',
		message: null,
		entry_id: null,
		last_error_code: null,
		last_result: null,
		updated_at: null
	};

	const notifyErrorSpy = vi.spyOn(notifications, 'error');
	const notifyWarningSpy = vi.spyOn(notifications, 'warning');
	const notifySuccessSpy = vi.spyOn(notifications, 'success');

	beforeEach(() => {
		formActionsStore.reset();
		Object.values(stubClient).forEach((fn) => fn.mockReset());
		notifyErrorSpy.mockReset();
		notifyWarningSpy.mockReset();
		notifySuccessSpy.mockReset();
	});

	it('stores friendly error message when initial load fails', async () => {
		const apiError = new ApiClientError('Request failed', 402, {
			error: { code: 'insufficient_credits', message: 'Insufficient credits' }
		});

		stubClient.getFormActions.mockRejectedValue(apiError);
		stubClient.getCreditBalance.mockResolvedValue({
			current_balance: 0,
			ledger_delta: 0,
			tier: null
		});
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.load('gravity_forms', 1);
		const state = snapshotState();

		expect(state.error).toBe(
			'Sentient Forms could not run because this license is out of credits. Visit the Licensing tab to add credits before retrying.'
		);
		expect(notifyErrorSpy).toHaveBeenCalledWith(
			'Sentient Forms could not run because this license is out of credits. Visit the Licensing tab to add credits before retrying.'
		);
	});

	it('uses debt-carry wording when insufficient credits are caused by a negative balance', async () => {
		const apiError = new ApiClientError('Request failed', 402, {
			error: {
				code: 'insufficient_credits',
				message: 'Insufficient credits',
				meta: {
					current_balance: -4,
					required_credits: 16,
					deficit_credits: 20,
					balance_state: 'negative_carry'
				}
			}
		});

		stubClient.getFormActions.mockRejectedValue(apiError);
		stubClient.getCreditBalance.mockResolvedValue({
			current_balance: -4,
			ledger_delta: -24,
			tier: null
		});
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.load('gravity_forms', 1);
		const state = snapshotState();

		expect(state.error).toBe(
			'Sentient Forms paused new runs because this license has a negative balance of -4 credits. Visit the Licensing tab to add credits before retrying.'
		);
		expect(notifyErrorSpy).toHaveBeenCalledWith(
			'Sentient Forms paused new runs because this license has a negative balance of -4 credits. Visit the Licensing tab to add credits before retrying.'
		);
	});

	it('warns but keeps state when credit balance refresh times out', async () => {
		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getCreditBalance.mockResolvedValue({
			current_balance: 100,
			ledger_delta: 0,
			tier: null
		});
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue({
			status: 'success',
			message: 'Sentient Forms executed successfully.',
			entry_id: 1,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:00:00Z'
		});

		await formActionsStore.load('gravity_forms', 1);

		const timeoutError = new ApiClientError('Request failed', 504, {
			error: { code: 'timeout', message: 'Timed out contacting CPS' }
		});

		stubClient.getCreditBalance.mockRejectedValue(timeoutError);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.refresh('gravity_forms', 1);
		const state = snapshotState();

		expect(state.error).toBeNull();
		expect(state.status).toEqual(noopStatus);
		expect(notifyWarningSpy).toHaveBeenLastCalledWith(
			'Sentient Forms timed out while contacting the CPS service. Retry the request shortly.'
		);
	});

	it('keeps actions usable when credit balance is missing', async () => {
		const balanceError = new ApiClientError('Request failed', 404, { message: 'Not Found' });

		const linkage = {
			local_mapping_id: 'map_1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			execution_priority: 10
		};

		stubClient.getFormActions.mockResolvedValue([linkage]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.getCreditBalance.mockRejectedValue(balanceError);

		await formActionsStore.load('gravity_forms', 1);
		const state = snapshotState();

		expect(state.items).toHaveLength(1);
		expect(state.balance).toBeNull();
		expect(state.error).toBeNull();
		expect(notifyWarningSpy).toHaveBeenCalledWith('Credit balance unavailable right now.');
	});

	it('surfaces friendly message when execution status lookup fails', async () => {
		const lookupError = new ApiClientError('Request failed', 400, {
			error: { code: 'invalid_action_id', message: 'Invalid action' }
		});

		stubClient.getExecutionStatus.mockRejectedValue(lookupError);

		await expect(
			formActionsStore.fetchExecutionStatus('gravity_forms', 1, 123)
		).rejects.toBeInstanceOf(ApiClientError);

		expect(notifyErrorSpy).toHaveBeenCalledWith(
			'This Sentient Forms action mapping is no longer valid. Reconfigure the action before retrying.'
		);
	});

	it('updates trigger hooks and refreshes the status', async () => {
		const linkage = {
			local_mapping_id: 'map_1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			execution_priority: 10
		};

		stubClient.getFormActions.mockResolvedValue([linkage]);
		stubClient.getCreditBalance.mockResolvedValue({
			current_balance: 250,
			ledger_delta: 0,
			tier: null
		});
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.updateFormAction.mockResolvedValue({
			...linkage,
			trigger_hooks: ['gform_after_submission']
		});

		await formActionsStore.load('gravity_forms', 1);
		await formActionsStore.updateHooks('gravity_forms', 1, linkage, ['gform_after_submission']);

		expect(stubClient.updateFormAction).toHaveBeenCalledWith(
			'gravity_forms',
			1,
			'map_1',
			{ trigger_hooks: ['gform_after_submission'] }
		);
		expect(stubClient.getFormExecutionStatus).toHaveBeenCalledTimes(2);
		const state = snapshotState();
		expect(state.items[0]?.trigger_hooks).toEqual(['gform_after_submission']);
	});

	it('rolls back optimistic toggle when update fails', async () => {
		const linkage = {
			local_mapping_id: 'map_1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			execution_priority: 10
		};

		stubClient.getFormActions.mockResolvedValue([linkage]);
		stubClient.getCreditBalance.mockResolvedValue({ current_balance: 100, ledger_delta: 0, tier: null });
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		const apiError = new ApiClientError('Request failed', 500, { message: 'boom' });
		stubClient.updateFormAction.mockRejectedValue(apiError);

		await formActionsStore.load('gravity_forms', 1);
		await formActionsStore.toggleEnabled('gravity_forms', 1, linkage, false);
		const state = snapshotState();
		expect(state.items[0]?.is_action_enabled_for_form).toBe(true);
		expect(notifyErrorSpy).toHaveBeenCalledWith('boom');
	});

	it('rolls back optimistic hook edit when update fails', async () => {
		const linkage = {
			local_mapping_id: 'map_1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			execution_priority: 10
		};

		stubClient.getFormActions.mockResolvedValue([linkage]);
		stubClient.getCreditBalance.mockResolvedValue({ current_balance: 100, ledger_delta: 0, tier: null });
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		const apiError = new ApiClientError('Request failed', 400, { message: 'bad hooks' });
		stubClient.updateFormAction.mockRejectedValue(apiError);

		await formActionsStore.load('gravity_forms', 1);
		await formActionsStore.updateHooks('gravity_forms', 1, linkage, ['gform_after_submission']);

		const state = snapshotState();
		expect(state.items[0]?.trigger_hooks).toEqual(['gform_validation']);
		expect(notifyErrorSpy).toHaveBeenCalledWith('bad hooks');
	});

	it('keeps per-form disabled state after successful toggle', async () => {
		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getCreditBalance.mockResolvedValue({
			current_balance: 100,
			ledger_delta: 0,
			tier: null
		});
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.getFormDisabled.mockResolvedValue({ sf_disabled: false });
		stubClient.toggleFormDisabled.mockResolvedValue({
			sf_disabled: true,
			message: 'Sentient Forms disabled for this form.'
		});

		await formActionsStore.load('gravity_forms', 1);
		await formActionsStore.toggleFormDisabled('gravity_forms', 1, true);

		const state = snapshotState();
		expect(stubClient.toggleFormDisabled).toHaveBeenCalledWith('gravity_forms', 1, true);
		expect(state.sfDisabled).toBe(true);
		expect(notifySuccessSpy).toHaveBeenCalledWith('Sentient Forms disabled for this form.');
	});

	it('tracks effective disable flags from API response', async () => {
		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getCreditBalance.mockResolvedValue({
			current_balance: 100,
			ledger_delta: 0,
			tier: null
		});
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.getFormDisabled.mockResolvedValue({
			sf_disabled: false,
			global_disabled: true,
			provider_disabled: false,
			effective_disabled: true
		});

		await formActionsStore.load('gravity_forms', 1);
		const state = snapshotState();

		expect(state.sfDisabled).toBe(false);
		expect(state.globalDisabled).toBe(true);
		expect(state.providerDisabled).toBe(false);
		expect(state.effectiveDisabled).toBe(true);
	});
});
