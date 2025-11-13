import { beforeEach, describe, expect, it, vi } from 'vitest';

type StubClient = {
	getFormActions: ReturnType<typeof vi.fn>;
	getCreditBalance: ReturnType<typeof vi.fn>;
	getActionDefinitions: ReturnType<typeof vi.fn>;
	getFormExecutionStatus: ReturnType<typeof vi.fn>;
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

	beforeEach(() => {
		formActionsStore.reset();
		Object.values(stubClient).forEach((fn) => fn.mockReset());
		notifyErrorSpy.mockReset();
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

	it('updates error state when refresh encounters timeout', async () => {
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

		expect(state.error).toBe(
			'Sentient Forms timed out while contacting the CPS service. Retry the request shortly.'
		);
		expect(notifyErrorSpy).toHaveBeenLastCalledWith(
			'Sentient Forms timed out while contacting the CPS service. Retry the request shortly.'
		);
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
});
