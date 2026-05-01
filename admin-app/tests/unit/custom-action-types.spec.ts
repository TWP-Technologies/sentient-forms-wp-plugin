/**
 * Unit tests for Custom Action TypeScript types (CA-DEF-001)
 * Verifies new definition fields are correctly typed.
 */

import { describe, expect, it } from 'vitest';
import type {
	CustomAction,
	ActionKind,
	ExecutionMode,
	OutputContract,
	ActionDefinitionPayload
} from '$lib/api/types';

describe('Custom Action Types (CA-DEF-001)', () => {
	describe('CustomAction interface', () => {
		it('includes all new definition fields', () => {
			const action: CustomAction = {
				id: 'test-id',
				template_id: 'tmpl-id',
				code: 'test-action',
				display_name: 'Test Action',
				description: null,
				prompt_overrides: {},
				model_hint: null,
				base_credit_cost: null,
				status: 'active',
				archived_at: null,
				created_at: '2026-01-13T00:00:00Z',
				updated_at: '2026-01-13T00:00:00Z',
				// New definition fields
				action_kind: 'template_override',
				definition: null,
				definition_version: 1,
				output_contract: null,
				supported_execution_modes: ['after_submission']
			};

			expect(action.action_kind).toBe('template_override');
			expect(action.definition).toBeNull();
			expect(action.definition_version).toBe(1);
			expect(action.output_contract).toBeNull();
			expect(action.supported_execution_modes).toEqual(['after_submission']);
		});

		it('supports custom_definition action_kind with full definition', () => {
			const definition: ActionDefinitionPayload = {
				meta_prompt: 'Analyze the form submission.',
				goal: 'Determine quality score',
				success_criteria: ['Complete data', 'Valid email'],
				failure_criteria: ['Spam indicators'],
				examples: [
					{
						type: 'positive',
						input: 'Valid form data',
						expected_output: '{"quality": "high"}',
						explanation: 'Complete submission'
					}
				],
				input_requirements: { email: 'required' },
				execution_defaults: { max_tokens: 500 }
			};

			const outputContract: OutputContract = {
				response_type: 'structured',
				json_schema: { type: 'object', properties: { quality: { type: 'string' } } },
				confidence_score_required: true
			};

			const action: CustomAction = {
				id: 'custom-action-id',
				template_id: 'tmpl-base',
				code: 'quality-check',
				display_name: 'Quality Check',
				description: 'Custom quality assessment action',
				prompt_overrides: {},
				model_hint: 'gemini-2.5-pro',
				base_credit_cost: 10,
				status: 'active',
				archived_at: null,
				created_at: '2026-01-13T00:00:00Z',
				updated_at: '2026-01-13T00:00:00Z',
				action_kind: 'custom_definition',
				definition: definition,
				definition_version: 2,
				output_contract: outputContract,
				supported_execution_modes: ['validation', 'after_submission']
			};

			expect(action.action_kind).toBe('custom_definition');
			expect(action.definition?.meta_prompt).toBe('Analyze the form submission.');
			expect(action.definition?.examples?.[0]?.type).toBe('positive');
			expect(action.definition_version).toBe(2);
			expect(action.output_contract?.response_type).toBe('structured');
			expect(action.output_contract?.confidence_score_required).toBe(true);
			expect(action.supported_execution_modes).toContain('validation');
			expect(action.supported_execution_modes).not.toContain('real_time');
		});
	});

	describe('ActionKind type', () => {
		it('accepts valid action_kind values', () => {
			const templateOverride: ActionKind = 'template_override';
			const customDefinition: ActionKind = 'custom_definition';

			expect(templateOverride).toBe('template_override');
			expect(customDefinition).toBe('custom_definition');
		});
	});

	describe('ExecutionMode type', () => {
		it('accepts all valid execution modes', () => {
			const modes: ExecutionMode[] = ['validation', 'after_submission', 'real_time'];

			expect(modes).toContain('validation');
			expect(modes).toContain('after_submission');
			expect(modes).toContain('real_time');
		});
	});

	describe('OutputContract interface', () => {
		it('supports all response_type values', () => {
			const textContract: OutputContract = { response_type: 'text' };
			const boolContract: OutputContract = { response_type: 'boolean' };
			const classContract: OutputContract = { response_type: 'classification' };
			const structContract: OutputContract = {
				response_type: 'structured',
				json_schema: { type: 'object' },
				confidence_score_required: true
			};

			expect(textContract.response_type).toBe('text');
			expect(boolContract.response_type).toBe('boolean');
			expect(classContract.response_type).toBe('classification');
			expect(structContract.response_type).toBe('structured');
			expect(structContract.json_schema).toBeDefined();
		});
	});

	describe('ActionDefinitionPayload interface', () => {
		it('supports all optional definition fields', () => {
			const minimalDefinition: ActionDefinitionPayload = {};

			const fullDefinition: ActionDefinitionPayload = {
				meta_prompt: 'Instructions',
				goal: 'Goal description',
				success_criteria: ['Criteria 1'],
				failure_criteria: ['Failure 1'],
				examples: [
					{
						type: 'negative',
						input: 'Bad input',
						expected_output: 'Reject'
					}
				],
				input_requirements: { field1: 'required' },
				execution_defaults: { timeout: 30 }
			};

			expect(minimalDefinition).toBeDefined();
			expect(fullDefinition.meta_prompt).toBe('Instructions');
			expect(fullDefinition.examples?.[0]?.type).toBe('negative');
		});
	});
});
