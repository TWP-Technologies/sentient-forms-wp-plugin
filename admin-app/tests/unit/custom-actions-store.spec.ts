import { beforeEach, describe, expect, it, vi } from 'vitest';
import { customActionsStore, customActionsState } from '$lib/stores/custom-actions';
import { notifications } from '$lib/stores/notifications';
import { ApiClientError } from '$lib/api/client';
import type { CustomAction } from '$lib/api/types';

type StubClient = {
	getCustomActions: ReturnType<typeof vi.fn>;
	getActionDefinitions: ReturnType<typeof vi.fn>;
	getCapabilities: ReturnType<typeof vi.fn>;
	createCustomAction: ReturnType<typeof vi.fn>;
	updateCustomAction: ReturnType<typeof vi.fn>;
	archiveCustomAction: ReturnType<typeof vi.fn>;
	reactivateCustomAction: ReturnType<typeof vi.fn>;
};

const stubClient = vi.hoisted(() => {
	return {
		getCustomActions: vi.fn(),
		getActionDefinitions: vi.fn(),
		getCapabilities: vi.fn(),
		createCustomAction: vi.fn(),
		updateCustomAction: vi.fn(),
		archiveCustomAction: vi.fn(),
		reactivateCustomAction: vi.fn()
	} as StubClient;
});

vi.mock('$lib/api/client', async () => {
	const actual = await vi.importActual<typeof import('$lib/api/client')>('$lib/api/client');
	return {
		...actual,
		createClientFromConfig: vi.fn(() => stubClient)
	};
});

function snapshotState() {
	let current = customActionsState;
	const unsubscribe = customActionsStore.subscribe((value) => {
		current = value;
	});
	unsubscribe();
	return current;
}

const sampleActions: CustomAction[] = [
	{
		id: 'a',
		template_id: 'tmpl-a',
		code: 'alpha',
		display_name: 'Alpha',
		description: null,
		prompt_overrides: {},
		model_hint: null,
		base_credit_cost: null,
		status: 'active',
		archived_at: null,
		created_at: '2025-11-14T00:00:00Z',
		updated_at: '2025-11-14T05:00:00Z',
		// New definition fields
		action_kind: 'template_override',
		definition: null,
		definition_version: 1,
		output_contract: null,
		supported_execution_modes: ['after_submission']
	},
	{
		id: 'b',
		template_id: 'tmpl-b',
		code: 'beta',
		display_name: 'Beta',
		description: null,
		prompt_overrides: {},
		model_hint: null,
		base_credit_cost: null,
		status: 'active',
		archived_at: null,
		created_at: '2025-11-13T00:00:00Z',
		updated_at: '2025-11-15T07:00:00Z',
		// New definition fields
		action_kind: 'template_override',
		definition: null,
		definition_version: 1,
		output_contract: null,
		supported_execution_modes: ['after_submission']
	}
];

describe('customActionsStore', () => {
	const notifyErrorSpy = vi.spyOn(notifications, 'error');
	const notifySuccessSpy = vi.spyOn(notifications, 'success');

	beforeEach(() => {
		customActionsStore.reset();
		Object.values(stubClient).forEach((fn) => fn.mockReset());
		notifyErrorSpy.mockReset();
		notifySuccessSpy.mockReset();

		// Default mock implementations for methods called by load()
		stubClient.getCapabilities.mockResolvedValue({
			supports_custom_actions: true,
			cps_version: '1.0.0'
		});
		stubClient.getActionDefinitions.mockResolvedValue([]);
	});

	it('loads actions and sorts by updated time descending', async () => {
		stubClient.getCustomActions.mockResolvedValue({
			actions: sampleActions,
			quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
		});

		await customActionsStore.load();
		const state = snapshotState();

		expect(state.actions.map((a) => a.id)).toEqual(['b', 'a']);
		expect(state.quota?.quota_used).toBe(2);
		expect(state.error).toBeNull();
	});

	it('keeps valid timestamps first when some updated_at values are invalid', async () => {
		stubClient.getCustomActions.mockResolvedValue({
			actions: [
				{
					...sampleActions[0],
					id: 'invalid-a',
					code: 'legacy_1770000000001',
					updated_at: 'Array',
					created_at: '2025-11-14T05:00:00Z'
				},
				{
					...sampleActions[1],
					id: 'valid-b',
					code: 'beta',
					updated_at: '2025-11-15T07:00:00Z'
				},
				{
					...sampleActions[1],
					id: 'invalid-c',
					code: 'legacy_1770000000000',
					updated_at: 'not-a-date',
					created_at: '2025-11-13T00:00:00Z'
				}
			],
			quota: { quota_max: 5, quota_used: 3, quota_remaining: 2 }
		});

		await customActionsStore.load();
		const state = snapshotState();

		expect(state.actions.map((action) => action.id)).toEqual(['valid-b', 'invalid-a', 'invalid-c']);
	});

	it('surfaces friendly error message when CPS rejects request', async () => {
		const apiError = new ApiClientError('Request failed', 422, {
			error_code: 'quota_exceeded',
			error: { code: 'quota_exceeded', message: 'quota exceeded' }
		});
		stubClient.getCustomActions.mockRejectedValue(apiError);

		await customActionsStore.load();
		const state = snapshotState();

		expect(state.error).toContain('You reached the custom action quota');
		expect(state.actions).toEqual([]);
		expect(notifyErrorSpy).toHaveBeenCalled();
	});

	it('creates actions and updates quota data', async () => {
		stubClient.getCustomActions.mockResolvedValue({
			actions: [],
			quota: { quota_max: 5, quota_used: 1, quota_remaining: 4 }
		});

		await customActionsStore.load();

		stubClient.createCustomAction.mockResolvedValue({
			action: sampleActions[0],
			quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
		});

		await customActionsStore.create({
			template_id: 'tmpl-a',
			code: 'alpha',
			display_name: 'Alpha',
			action_kind: 'template_override',
			definition_version: 1,
			supported_execution_modes: ['after_submission']
		});

		const state = snapshotState();
		expect(state.actions[0]?.id).toBe('a');
		expect(state.quota?.quota_used).toBe(2);
		expect(notifySuccessSpy).toHaveBeenCalledWith('Custom action created');
	});

	it('archives actions and updates status', async () => {
		stubClient.getCustomActions.mockResolvedValue({
			actions: sampleActions,
			quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
		});
		await customActionsStore.load();

		stubClient.archiveCustomAction.mockResolvedValue({
			action: { ...sampleActions[0], status: 'archived', archived_at: '2025-11-15T00:00:00Z' },
			quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
		});

		await customActionsStore.archive('a');
		const state = snapshotState();

		expect(state.actions.find((a) => a.id === 'a')?.status).toBe('archived');
		expect(notifySuccessSpy).toHaveBeenCalledWith('Custom action archived');
	});

	it('updates actions and quota on update', async () => {
		stubClient.getCustomActions.mockResolvedValue({
			actions: sampleActions,
			quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
		});
		await customActionsStore.load();

		stubClient.updateCustomAction.mockResolvedValue({
			action: { ...sampleActions[0], display_name: 'Alpha V2' },
			quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
		});

		await customActionsStore.update('a', { display_name: 'Alpha V2' });
		const state = snapshotState();
		expect(state.actions.find((a) => a.id === 'a')?.display_name).toBe('Alpha V2');
		expect(notifySuccessSpy).toHaveBeenCalledWith('Custom action updated');
	});

	it('reactivates archived actions', async () => {
		stubClient.getCustomActions.mockResolvedValue({
			actions: [{ ...sampleActions[0], status: 'archived', archived_at: '2025-11-15T00:00:00Z' }],
			quota: { quota_max: 5, quota_used: 1, quota_remaining: 4 }
		});
		await customActionsStore.load();

		stubClient.reactivateCustomAction.mockResolvedValue({
			action: { ...sampleActions[0], status: 'active', archived_at: null },
			quota: { quota_max: 5, quota_used: 2, quota_remaining: 3 }
		});

		await customActionsStore.reactivate('a');
		const state = snapshotState();
		expect(state.actions[0]?.status).toBe('active');
		expect(notifySuccessSpy).toHaveBeenCalledWith('Custom action reactivated');
	});

	it('updates filters via setFilters', () => {
		customActionsStore.setFilters({ status: 'archived' });
		const state = snapshotState();
		expect(state.filters.status).toBe('archived');
	});
});
