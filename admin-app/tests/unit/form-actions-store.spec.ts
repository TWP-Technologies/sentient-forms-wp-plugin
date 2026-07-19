import { beforeEach, describe, expect, it, vi } from 'vitest';

type StubClient = {
	getFormActions: ReturnType<typeof vi.fn>;
	getFormActionsBootstrap: ReturnType<typeof vi.fn>;
	updateSubmissionLedgerSettings: ReturnType<typeof vi.fn>;
	getCapabilities: ReturnType<typeof vi.fn>;
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
		getFormActionsBootstrap: vi.fn(),
		updateSubmissionLedgerSettings: vi.fn(),
		getCapabilities: vi.fn(),
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

function deferred<T>() {
	let resolve!: (value: T) => void;
	let reject!: (reason?: unknown) => void;
	const promise = new Promise<T>((promiseResolve, promiseReject) => {
		resolve = promiseResolve;
		reject = promiseReject;
	});
	return { promise, resolve, reject };
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
		stubClient.getFormDisabled.mockResolvedValue({
			sf_disabled: false,
			global_disabled: false,
			provider_disabled: false,
			effective_disabled: false
		});
		stubClient.getFormActionsBootstrap.mockImplementation(async (formSourceSlug, formId) => ({
			form_source: formSourceSlug,
			form_id: formId,
			actions: await stubClient.getFormActions(formSourceSlug, formId),
			execution_status: await stubClient.getFormExecutionStatus(formSourceSlug, formId),
			disabled_state: await stubClient.getFormDisabled(formSourceSlug, formId),
			generated_at: '2030-01-01T00:00:00Z'
		}));
		notifyErrorSpy.mockReset();
		notifyWarningSpy.mockReset();
		notifySuccessSpy.mockReset();
	});

	it('stores friendly error message when initial load fails', async () => {
		const apiError = new ApiClientError('Request failed', 402, {
			error: { code: 'insufficient_credits', message: 'Insufficient credits' }
		});

		stubClient.getFormActions.mockRejectedValue(apiError);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.load('gravity_forms', 1);
		const state = snapshotState();

		expect(state.error).toBe(
			'Sentient Forms could not run because this managed license is out of credits. Visit the Licensing tab to review billing before retrying.'
		);
		expect(notifyErrorSpy).toHaveBeenCalledWith(
			'Sentient Forms could not run because this managed license is out of credits. Visit the Licensing tab to review billing before retrying.'
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
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.load('gravity_forms', 1);
		const state = snapshotState();

		expect(state.error).toBe(
			'Sentient Forms paused new runs because this managed license has a negative balance of -4 credits. Visit the Licensing tab to review billing before retrying.'
		);
		expect(notifyErrorSpy).toHaveBeenCalledWith(
			'Sentient Forms paused new runs because this managed license has a negative balance of -4 credits. Visit the Licensing tab to review billing before retrying.'
		);
	});

	it('refreshes execution status without requesting legacy credit balance', async () => {
		stubClient.getFormActions.mockResolvedValue([]);
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

		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.refresh('gravity_forms', 1);
		const state = snapshotState();

		expect(state.error).toBeNull();
		expect(state.status).toEqual(noopStatus);
		expect(stubClient.getCreditBalance).not.toHaveBeenCalled();
		expect(notifyWarningSpy).not.toHaveBeenCalled();
	});

	it('reloads bootstrap data for explicit refreshes', async () => {
		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.load('gravity_forms', 1);
		stubClient.getFormActionsBootstrap.mockClear();

		await formActionsStore.refresh('gravity_forms', 1, { forceRefresh: true });

		expect(stubClient.getFormActionsBootstrap).toHaveBeenCalledWith('gravity_forms', 1, {
			showNotifications: false,
			forceRefresh: true
		});
	});

	it('preserves the last loaded bootstrap when an explicit refresh fails', async () => {
		const linkage = {
			local_mapping_id: 'map_1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			execution_priority: 10
		};
		const apiError = new ApiClientError('Request failed', 503, {
			error: { code: 'service_unavailable', message: 'Bootstrap temporarily unavailable' }
		});

		stubClient.getFormActions.mockResolvedValue([linkage]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);

		await formActionsStore.load('gravity_forms', 1);
		const previousState = snapshotState();

		stubClient.getFormActionsBootstrap.mockRejectedValueOnce(apiError);

		await formActionsStore.refresh('gravity_forms', 1, { forceRefresh: true });
		const state = snapshotState();

		expect(state.loading).toBe(false);
		expect(state.error).toBe('Bootstrap temporarily unavailable');
		expect(state.items).toEqual([linkage]);
		expect(state.bootstrap).toBe(previousState.bootstrap);
		expect(state.effectiveDisabled).toBe(previousState.effectiveDisabled);
		expect(notifyErrorSpy).toHaveBeenCalledWith('Bootstrap temporarily unavailable');
	});

	it('keeps actions usable without a legacy credit balance request', async () => {
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

		await formActionsStore.load('gravity_forms', 1);
		const state = snapshotState();

		expect(state.items).toHaveLength(1);
		expect(state.error).toBeNull();
		expect(stubClient.getCreditBalance).not.toHaveBeenCalled();
		expect(notifyWarningSpy).not.toHaveBeenCalled();
	});

	it('rejects action creation failures so the add action drawer can stay open', async () => {
		const friendlyMessage =
			'Sentient Forms sent an invalid execution payload. Review your action configuration and try again.';
		const apiError = new ApiClientError('Request failed', 400, {
			error: { code: 'invalid_request', message: 'Action mapping could not be created.' }
		});

		stubClient.createFormAction.mockRejectedValue(apiError);

		await expect(
			formActionsStore.create('gravity_forms', 1, {
				central_action_id: 'entry_summary_v1',
				action_type_indicator: 'master',
				trigger_hooks: ['gform_after_submission']
			})
		).rejects.toThrow(friendlyMessage);

		expect(snapshotState().items).toEqual([]);
		expect(notifyErrorSpy).toHaveBeenCalledWith(friendlyMessage);
		expect(notifySuccessSpy).not.toHaveBeenCalled();
		expect(stubClient.getExecutionStatus).not.toHaveBeenCalled();
		expect(stubClient.getFormExecutionStatus).not.toHaveBeenCalled();
	});

	it('reloads the full bootstrap after creating a multi-hook action', async () => {
		const validationLinkage = {
			local_mapping_id: 'local_first_41',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'local_first',
			trigger_hooks: ['validation'],
			is_action_enabled_for_form: true,
			execution_priority: 41
		};
		const afterSubmissionLinkage = {
			...validationLinkage,
			local_mapping_id: 'local_first_42',
			trigger_hooks: ['after_submission'],
			execution_priority: 42
		};

		stubClient.getFormActions
			.mockResolvedValueOnce([])
			.mockResolvedValueOnce([validationLinkage, afterSubmissionLinkage]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.createFormAction.mockResolvedValue(validationLinkage);

		await formActionsStore.load('gravity_forms', 1);
		stubClient.getFormActionsBootstrap.mockClear();
		await formActionsStore.create('gravity_forms', 1, {
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['validation', 'after_submission']
		});

		expect(stubClient.getFormActionsBootstrap).toHaveBeenCalledWith('gravity_forms', 1, {
			showNotifications: false,
			forceRefresh: true
		});
		expect(snapshotState().items).toEqual([validationLinkage, afterSubmissionLinkage]);
	});

	it('updates submission ledger settings in the loaded bootstrap state', async () => {
		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.getFormActionsBootstrap.mockResolvedValue({
			form_source: 'gravity_forms',
			form_id: 1,
			actions: [],
			execution_status: noopStatus,
			disabled_state: {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			},
			ledger_settings: {
				form_source: 'gravity_forms',
				form_id: 1,
				enabled: false,
				enabled_at: null,
				enabled_by_user_id: null,
				disabled_at: null,
				disabled_by_user_id: null,
				settings_source: 'sentient_submission_ledger_settings',
				ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/1/submissions',
				record_count: 0
			},
			generated_at: '2030-01-01T00:00:00Z'
		});
		stubClient.updateSubmissionLedgerSettings.mockResolvedValue({
			form_source: 'gravity_forms',
			form_id: 1,
			enabled: true,
			enabled_at: '2030-01-01T00:01:00Z',
			enabled_by_user_id: 7,
			disabled_at: null,
			disabled_by_user_id: null,
			settings_source: 'sentient_submission_ledger_settings',
			ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/1/submissions',
			record_count: 0
		});

		await formActionsStore.load('gravity_forms', 1);
		await formActionsStore.updateSubmissionLedgerSettings('gravity_forms', 1, true);
		const state = snapshotState();

		expect(stubClient.updateSubmissionLedgerSettings).toHaveBeenCalledWith('gravity_forms', 1, true, {
			showNotifications: false
		});
		expect(state.bootstrap?.ledger_settings?.enabled).toBe(true);
		expect(state.bootstrap?.ledger_settings?.enabled_by_user_id).toBe(7);
		expect(state.submissionLedgerSaving).toBe(false);
		expect(notifySuccessSpy).toHaveBeenCalledWith('Submission ledger storage enabled.');
	});

	it('ignores overlapping submission ledger setting writes for the active form', async () => {
		const pending = deferred({
			form_source: 'gravity_forms',
			form_id: 1,
			enabled: true,
			enabled_at: '2030-01-01T00:01:00Z',
			enabled_by_user_id: 7,
			disabled_at: null,
			disabled_by_user_id: null,
			settings_source: 'sentient_submission_ledger_settings',
			ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/1/submissions',
			record_count: 0
		});

		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.getFormActionsBootstrap.mockResolvedValue({
			form_source: 'gravity_forms',
			form_id: 1,
			actions: [],
			execution_status: noopStatus,
			disabled_state: {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			},
			ledger_settings: {
				form_source: 'gravity_forms',
				form_id: 1,
				enabled: false,
				enabled_at: null,
				enabled_by_user_id: null,
				disabled_at: null,
				disabled_by_user_id: null,
				settings_source: 'sentient_submission_ledger_settings',
				ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/1/submissions',
				record_count: 0
			},
			generated_at: '2030-01-01T00:00:00Z'
		});
		stubClient.updateSubmissionLedgerSettings.mockReturnValue(pending.promise);

		await formActionsStore.load('gravity_forms', 1);
		const first = formActionsStore.updateSubmissionLedgerSettings('gravity_forms', 1, true);
		const second = formActionsStore.updateSubmissionLedgerSettings('gravity_forms', 1, false);
		expect(snapshotState().submissionLedgerSaving).toBe(true);

		pending.resolve({
			form_source: 'gravity_forms',
			form_id: 1,
			enabled: true,
			enabled_at: '2030-01-01T00:01:00Z',
			enabled_by_user_id: 7,
			disabled_at: null,
			disabled_by_user_id: null,
			settings_source: 'sentient_submission_ledger_settings',
			ledger_records_endpoint: '/sentient-forms/v1/gravity_forms/forms/1/submissions',
			record_count: 0
		});
		await Promise.all([first, second]);

		expect(stubClient.updateSubmissionLedgerSettings).toHaveBeenCalledTimes(1);
		expect(snapshotState().bootstrap?.ledger_settings?.enabled).toBe(true);
		expect(snapshotState().submissionLedgerSaving).toBe(false);
	});

	it('updates submission ledger settings for opaque provider-native form IDs', async () => {
		const opaqueFormId = 'form alpha/2026#north%';
		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus.mockResolvedValue(noopStatus);
		stubClient.getFormDisabled.mockResolvedValue({
			sf_disabled: false,
			global_disabled: false,
			provider_disabled: false,
			effective_disabled: false
		});
		stubClient.getFormActionsBootstrap.mockResolvedValue({
			form_source: 'opaque_forms',
			form_id: opaqueFormId,
			actions: [],
			execution_status: noopStatus,
			disabled_state: {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			},
			ledger_settings: {
				form_source: 'opaque_forms',
				form_id: opaqueFormId,
				enabled: false,
				enabled_at: null,
				enabled_by_user_id: null,
				disabled_at: null,
				disabled_by_user_id: null,
				settings_source: 'sentient_submission_ledger_settings',
				ledger_records_endpoint: '/sentient-forms/v1/opaque_forms/forms/form%20alpha%2F2026%23north%25/submissions',
				record_count: 0
			},
			generated_at: '2030-01-01T00:00:00Z'
		});
		stubClient.updateSubmissionLedgerSettings.mockResolvedValue({
			form_source: 'opaque_forms',
			form_id: opaqueFormId,
			enabled: true,
			enabled_at: '2030-01-01T00:01:00Z',
			enabled_by_user_id: 7,
			disabled_at: null,
			disabled_by_user_id: null,
			settings_source: 'sentient_submission_ledger_settings',
			ledger_records_endpoint: '/sentient-forms/v1/opaque_forms/forms/form%20alpha%2F2026%23north%25/submissions',
			record_count: 0
		});

		await formActionsStore.load('opaque_forms', opaqueFormId);
		await formActionsStore.updateSubmissionLedgerSettings('opaque_forms', opaqueFormId, true);
		const state = snapshotState();

		expect(stubClient.updateSubmissionLedgerSettings).toHaveBeenCalledWith(
			'opaque_forms',
			opaqueFormId,
			true,
			{ showNotifications: false }
		);
		expect(state.bootstrap?.ledger_settings?.form_id).toBe(opaqueFormId);
		expect(state.bootstrap?.ledger_settings?.enabled).toBe(true);
	});

	it('ignores stale refresh results after loading a different form', async () => {
		const formOneStatus: FormExecutionStatus = {
			status: 'success',
			message: 'Form one loaded',
			entry_id: 1,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:00:00Z'
		};
		const staleRefreshStatus: FormExecutionStatus = {
			status: 'failed',
			message: 'Stale form one refresh',
			entry_id: 2,
			last_error_code: 'stale',
			last_result: null,
			updated_at: '2030-01-01T00:01:00Z'
		};
		const formTwoStatus: FormExecutionStatus = {
			status: 'pending',
			message: 'Form two loaded',
			entry_id: 3,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:02:00Z'
		};
		const refreshDeferred = deferred<FormExecutionStatus>();

		stubClient.getFormActions.mockResolvedValue([]);
		stubClient.getActionDefinitions.mockResolvedValue([]);
		stubClient.getFormExecutionStatus
			.mockResolvedValueOnce(formOneStatus)
			.mockReturnValueOnce(refreshDeferred.promise)
			.mockResolvedValueOnce(formTwoStatus);

		await formActionsStore.load('gravity_forms', 1);
		const refreshPromise = formActionsStore.refresh('gravity_forms', 1);
		await formActionsStore.load('gravity_forms', 2);

		refreshDeferred.resolve(staleRefreshStatus);
		await refreshPromise;

		const state = snapshotState();
		expect(state.status).toEqual(formTwoStatus);
		expect(state.bootstrap?.form_id).toBe(2);
	});

	it('keeps bootstrap loads current when status refreshes resolve during load', async () => {
		const refreshedStatus: FormExecutionStatus = {
			status: 'pending',
			message: 'Refresh completed before bootstrap',
			entry_id: 10,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:00:30Z'
		};
		const bootstrapStatus: FormExecutionStatus = {
			status: 'success',
			message: 'Bootstrap completed',
			entry_id: 11,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:01:00Z'
		};
		const linkage = {
			local_mapping_id: 'map_1',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			execution_priority: 10
		};
		const bootstrapDeferred = deferred<unknown>();

		stubClient.getFormActionsBootstrap.mockReturnValue(bootstrapDeferred.promise);
		stubClient.getFormExecutionStatus.mockResolvedValue(refreshedStatus);

		const loadPromise = formActionsStore.load('gravity_forms', 1);
		await formActionsStore.refresh('gravity_forms', 1);

		bootstrapDeferred.resolve({
			form_source: 'gravity_forms',
			form_id: 1,
			actions: [linkage],
			definitions: [],
			capabilities: { supports_status: true, cps_version: 'test' },
			execution_status: bootstrapStatus,
			disabled_state: {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			},
			generated_at: '2030-01-01T00:00:00Z'
		});
		await loadPromise;

		const state = snapshotState();
		expect(state.loading).toBe(false);
		expect(state.items).toEqual([linkage]);
		expect(state.status).toEqual(bootstrapStatus);
		expect(state.bootstrap?.form_id).toBe(1);
		expect(notifyErrorSpy).not.toHaveBeenCalled();
	});

	it('ignores stale load results after navigating to another form', async () => {
		const formOneStatus: FormExecutionStatus = {
			status: 'success',
			message: 'Form one loaded late',
			entry_id: 1,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:00:00Z'
		};
		const formTwoStatus: FormExecutionStatus = {
			status: 'pending',
			message: 'Form two loaded',
			entry_id: 2,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:01:00Z'
		};
		const formOneLinkage = {
			local_mapping_id: 'map_form_one',
			central_action_id: 'spam_detection_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_validation'],
			is_action_enabled_for_form: true,
			execution_priority: 10
		};
		const formTwoLinkage = {
			local_mapping_id: 'map_form_two',
			central_action_id: 'entry_summary_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_after_submission'],
			is_action_enabled_for_form: true,
			execution_priority: 20
		};
		const formOneLoad = deferred<unknown>();

		stubClient.getFormActionsBootstrap.mockImplementation((formSourceSlug, formId) => {
			const bootstrap = {
				form_source: formSourceSlug,
				form_id: formId,
				actions: formId === 1 ? [formOneLinkage] : [formTwoLinkage],
				definitions: [],
				capabilities: { supports_status: true, cps_version: 'test' },
				execution_status: formId === 1 ? formOneStatus : formTwoStatus,
				disabled_state: {
					sf_disabled: false,
					global_disabled: false,
					provider_disabled: false,
					effective_disabled: false
				},
				generated_at: '2030-01-01T00:00:00Z'
			};
			return formId === 1 ? formOneLoad.promise : Promise.resolve(bootstrap);
		});

		const staleLoadPromise = formActionsStore.load('gravity_forms', 1);
		await formActionsStore.load('gravity_forms', 2);
		formOneLoad.resolve({
			form_source: 'gravity_forms',
			form_id: 1,
			actions: [formOneLinkage],
			definitions: [],
			capabilities: { supports_status: true, cps_version: 'test' },
			execution_status: formOneStatus,
			disabled_state: {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			},
			generated_at: '2030-01-01T00:00:00Z'
		});
		await staleLoadPromise;

		const state = snapshotState();
		expect(state.bootstrap?.form_id).toBe(2);
		expect(state.items).toEqual([formTwoLinkage]);
		expect(state.status).toEqual(formTwoStatus);
		expect(notifyErrorSpy).not.toHaveBeenCalled();
	});

	it('ignores stale load errors after navigating to another form', async () => {
		const formTwoStatus: FormExecutionStatus = {
			status: 'pending',
			message: 'Form two loaded',
			entry_id: 2,
			last_error_code: null,
			last_result: null,
			updated_at: '2030-01-01T00:01:00Z'
		};
		const formTwoLinkage = {
			local_mapping_id: 'map_form_two',
			central_action_id: 'entry_summary_v1',
			action_type_indicator: 'master',
			trigger_hooks: ['gform_after_submission'],
			is_action_enabled_for_form: true,
			execution_priority: 20
		};
		const staleLoad = deferred<unknown>();

		stubClient.getFormActionsBootstrap.mockImplementation((formSourceSlug, formId) => {
			if (formId === 1) {
				return staleLoad.promise;
			}
			return Promise.resolve({
				form_source: formSourceSlug,
				form_id: formId,
				actions: [formTwoLinkage],
				definitions: [],
				capabilities: { supports_status: true, cps_version: 'test' },
				execution_status: formTwoStatus,
				disabled_state: {
					sf_disabled: false,
					global_disabled: false,
					provider_disabled: false,
					effective_disabled: false
				},
				generated_at: '2030-01-01T00:00:00Z'
			});
		});

		const staleLoadPromise = formActionsStore.load('gravity_forms', 1);
		await formActionsStore.load('gravity_forms', 2);
		staleLoad.reject(new ApiClientError('Request failed', 500, { message: 'stale form failed' }));
		await staleLoadPromise;

		const state = snapshotState();
		expect(state.bootstrap?.form_id).toBe(2);
		expect(state.items).toEqual([formTwoLinkage]);
		expect(state.status).toEqual(formTwoStatus);
		expect(state.error).toBeNull();
		expect(notifyErrorSpy).not.toHaveBeenCalled();
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
