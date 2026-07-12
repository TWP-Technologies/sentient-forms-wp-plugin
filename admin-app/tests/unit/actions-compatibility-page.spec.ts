import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/svelte';
import { afterEach, describe, expect, it, vi } from 'vitest';

const harness = vi.hoisted(() => {
	const descriptor = {
		slug: 'contact_form_7',
		label: 'Contact Form 7',
		is_active: true,
		availability: 'available',
		lifecycles: {
			validation: {
				supported: false,
				label: 'During validation',
				native_hook: null,
				execution_mode: 'blocking',
				requires_ledger: false,
				unsupported_reason: 'Validation unavailable'
			},
			after_submission: {
				supported: true,
				label: 'After submission',
				native_hook: 'wpcf7_mail_sent',
				execution_mode: 'async',
				requires_ledger: true,
				unsupported_reason: null
			},
			real_time: {
				supported: false,
				label: 'Realtime',
				native_hook: null,
				execution_mode: 'real_time',
				requires_ledger: false,
				unsupported_reason: 'Realtime lifecycle unavailable'
			}
		}
	};
	const definitions = [
		{
			id: 'clarification_assistant_v1',
			label: 'Realtime Clarification Assistant',
			description: 'Analyze visible answers while a visitor is filling out the form.',
			source: 'bundled',
			hooks: ['real_time'],
			modelHint: 'openrouter/auto'
		},
		{
			id: 'entry_summary_v1',
			label: 'Entry Summary',
			description: 'Summarize an accepted submission.',
			source: 'bundled',
			hooks: ['after_submission'],
			modelHint: 'openrouter/auto'
		}
	];
	const providerActions = Object.fromEntries(
		[
			'spam_detection_v1',
			'content_validation_v1',
			'entry_summary_v1',
			'clarification_assistant_v1'
		].map((actionId) => [
			actionId,
			{
				selected_provider: 'openrouter',
				model_selection: null,
				blocked_reason_code: null,
				requires_structured_output: false
			}
		])
	);
	return {
		api: {
			checkActionCompatibility: vi.fn(),
			getActionDefaultsBatch: vi.fn().mockResolvedValue({ defaults: {} })
		},
		createFormAction: vi.fn(),
		formActionsState: {
			loading: false,
			error: null,
			items: [],
			definitions,
			status: null,
			supportsStatus: false,
			cpsVersion: null,
			requiredStatusVersion: '1.0.0',
			sfDisabled: false,
			globalDisabled: false,
			providerDisabled: false,
			effectiveDisabled: false,
			submissionLedgerSaving: false,
			bootstrap: {
				form_source: 'contact_form_7',
				form_id: 42,
				form: { id: 42, title: 'Support intake', adapter: 'contact_form_7' },
				form_source_descriptor: descriptor,
				actions: [],
				execution_status: {
					status: 'unknown',
					message: null,
					entry_id: null,
					last_error_code: null,
					last_result: null
				},
				disabled_state: {
					sf_disabled: false,
					global_disabled: false,
					provider_disabled: false,
					effective_disabled: false
				},
				definitions,
				custom_actions: { actions: [], quota: null },
				provider_credentials: [],
				form_action_configs: {},
				action_defaults: {},
				provider_path_policy: {
					default_provider: 'openrouter',
					providers: {
						openrouter: { ready: true },
						sentient_managed: { ready: false }
					},
					actions: providerActions
				},
				form_fields: [],
				generated_at: '2030-01-05T10:00:00Z'
			}
		}
	};
});

vi.mock('$lib/api/client', () => ({
	createClientFromConfig: () => harness.api
}));
vi.mock('$lib/stores/form-actions.svelte', () => ({
	formActionsState: harness.formActionsState,
	formActionsStore: {
		load: vi.fn(),
		refresh: vi.fn(),
		reset: vi.fn(),
		create: harness.createFormAction,
		remove: vi.fn(),
		toggleEnabled: vi.fn(),
		updateAction: vi.fn(),
		toggleFormDisabled: vi.fn(),
		updateSubmissionLedgerSettings: vi.fn(),
		fetchExecutionStatus: vi.fn()
	}
}));
vi.mock('$lib/stores/custom-actions', () => ({
	customActionsState: { actions: [], loading: false, error: null },
	customActionsStore: { hydrate: vi.fn() }
}));
import ActionsPage from '../../src/routes/(app)/actions/[formSourceSlug]/[formId]/+page.svelte';

const rejectedEvidence = {
	form_source: 'contact_form_7',
	form_id: 42,
	action_code: 'clarification_assistant_v1',
	lifecycle: 'real_time' as const,
	policy_decision: 'rejected' as const,
	rejection_code: 'rest_unsupported_form_source_lifecycle',
	reason: 'Validation lifecycle unavailable for this source',
	request_trace_id: 'request-trace:123e4567-e89b-42d3-a456-426614174000',
	rejection_trace_id: 'source-rejection:123e4567-e89b-42d3-a456-426614174001',
	mapping_created: false as const,
	provider_request_executed: false as const,
	generated_at: '2030-01-05T10:00:00Z'
};

const actionSpecificRejectedEvidence = {
	...rejectedEvidence,
	action_code: 'entry_summary_v1',
	lifecycle: 'after_submission' as const,
	reason: 'Entry Summary is intentionally unavailable for this source contract'
};

describe('Add action compatibility evidence', () => {
	afterEach(() => {
		cleanup();
		harness.api.checkActionCompatibility.mockReset();
		harness.createFormAction.mockReset();
		harness.formActionsState.bootstrap.form_source_descriptor.lifecycles.validation.supported = false;
	});

	it('reapplies lifecycle defaults when the add-action drawer is reopened for the same action', async () => {
		harness.formActionsState.bootstrap.form_source_descriptor.lifecycles.validation.supported = true;
		render(ActionsPage, { data: { formSourceSlug: 'contact_form_7', formId: '42' } });

		await fireEvent.click(screen.getAllByRole('button', { name: 'Add action' })[0]);
		let drawer = screen.getByTestId('add-action-drawer');
		await fireEvent.click(within(drawer).getByRole('radio', { name: /Spam Detection/i }));

		const afterSubmission = within(drawer).getByRole('checkbox', {
			name: 'After submission'
		}) as HTMLInputElement;
		const validation = within(drawer).getByRole('checkbox', {
			name: 'During validation'
		}) as HTMLInputElement;
		await waitFor(() => expect(validation.checked).toBe(true));
		await fireEvent.click(afterSubmission);
		await fireEvent.click(validation);
		expect(afterSubmission.checked).toBe(true);
		expect(validation.checked).toBe(false);

		await fireEvent.click(within(drawer).getByRole('button', { name: 'Close' }));
		await fireEvent.click(screen.getAllByRole('button', { name: 'Add action' })[0]);
		drawer = screen.getByTestId('add-action-drawer');

		await waitFor(() =>
			expect(
				(within(drawer).getByRole('checkbox', { name: 'During validation' }) as HTMLInputElement)
					.checked
			).toBe(true)
		);
		expect(
			(within(drawer).getByRole('checkbox', { name: 'After submission' }) as HTMLInputElement)
				.checked
		).toBe(false);
	});

	it('keeps unsupported realtime actions visible and shows nonmutating rejection evidence', async () => {
		harness.api.checkActionCompatibility.mockResolvedValue(rejectedEvidence);
		render(ActionsPage, { data: { formSourceSlug: 'contact_form_7', formId: '42' } });

		await fireEvent.click(screen.getAllByRole('button', { name: 'Add action' })[0]);
		const drawer = screen.getByTestId('add-action-drawer');
		expect(within(drawer).getAllByText('Unsupported on Contact Form 7')).not.toHaveLength(0);
		expect(
			within(screen.getByTestId('built-in-action-option-spam_detection_v1')).queryByText(
				'Unsupported on Contact Form 7'
			)
		).toBeNull();
		await fireEvent.click(
			within(drawer).getByRole('radio', { name: /Realtime Clarification Assistant/i })
		);
		expect(
			(within(drawer).getByRole('button', { name: 'Link action' }) as HTMLButtonElement).disabled
		).toBe(true);

		await fireEvent.click(within(drawer).getByRole('button', { name: 'Check compatibility' }));
		const heading = await within(drawer).findByRole('heading', {
			name: 'Validation lifecycle unavailable for this source'
		});
		expect(document.activeElement).toBe(heading);
		expect(
			(within(drawer).getByRole('button', { name: 'Compatibility checked' }) as HTMLButtonElement)
				.disabled
		).toBe(true);
		expect(
			within(drawer).getByText('No mapping was created and no provider request ran.')
		).toBeTruthy();
		expect(harness.api.checkActionCompatibility).toHaveBeenCalledWith(
			'contact_form_7',
			'42',
			{ action_code: 'clarification_assistant_v1', lifecycle: 'real_time' },
			expect.objectContaining({ showNotifications: false })
		);

		await fireEvent.click(within(drawer).getByText('Technical details'));
		expect(within(drawer).getByText(rejectedEvidence.request_trace_id)).toBeTruthy();
		expect(within(drawer).getByText(rejectedEvidence.rejection_trace_id)).toBeTruthy();
	});

	it('preflights an apparently supported Action/Form Source cell before creating a mapping', async () => {
		harness.api.checkActionCompatibility.mockResolvedValue(actionSpecificRejectedEvidence);
		render(ActionsPage, { data: { formSourceSlug: 'contact_form_7', formId: '42' } });

		await fireEvent.click(screen.getAllByRole('button', { name: 'Add action' })[0]);
		const drawer = screen.getByTestId('add-action-drawer');
		await fireEvent.click(within(drawer).getByRole('radio', { name: /Entry Summary/i }));
		const linkAction = within(drawer).getByRole('button', { name: 'Link action' });
		await waitFor(() => expect((linkAction as HTMLButtonElement).disabled).toBe(false));
		await fireEvent.click(linkAction);

		const heading = await within(drawer).findByRole('heading', {
			name: actionSpecificRejectedEvidence.reason
		});
		expect(document.activeElement).toBe(heading);
		expect(harness.api.checkActionCompatibility).toHaveBeenCalledWith(
			'contact_form_7',
			'42',
			{ action_code: 'entry_summary_v1', lifecycle: 'after_submission' },
			expect.objectContaining({ showNotifications: false })
		);
		expect(harness.createFormAction).not.toHaveBeenCalled();
	});

	it('shows loading and system-error states while preserving retry focus', async () => {
		let rejectCheck: ((reason?: unknown) => void) | undefined;
		harness.api.checkActionCompatibility.mockImplementation(
			() => new Promise((_resolve, reject) => (rejectCheck = reject))
		);
		render(ActionsPage, { data: { formSourceSlug: 'contact_form_7', formId: '42' } });
		await fireEvent.click(screen.getAllByRole('button', { name: 'Add action' })[0]);
		const drawer = screen.getByTestId('add-action-drawer');
		await fireEvent.click(
			within(drawer).getByRole('radio', { name: /Realtime Clarification Assistant/i })
		);
		await fireEvent.click(within(drawer).getByRole('button', { name: 'Check compatibility' }));
		expect(
			(
				within(drawer).getByRole('button', {
					name: 'Checking compatibility…'
				}) as HTMLButtonElement
			).disabled
		).toBe(true);
		rejectCheck?.(new Error('backend unavailable'));

		const errorHeading = await within(drawer).findByRole('heading', {
			name: 'Compatibility check unavailable'
		});
		expect(document.activeElement).toBe(errorHeading);
		const retry = within(drawer).getByRole('button', { name: 'Retry check' });
		await fireEvent.click(retry);
		await waitFor(() => expect(document.activeElement).toBe(retry));
	});
});
