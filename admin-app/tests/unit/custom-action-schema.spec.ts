import { describe, expect, it } from 'vitest';
import {
	customActionCreateSchema,
	customActionUpdateSchema,
	validateCreatePayload,
	validateUpdatePayload
} from '$lib/schemas/custom-action';

describe('custom action schema pricing authority', () => {
	it('strips deprecated base_credit_cost from create payloads', () => {
		const parsed = customActionCreateSchema.parse({
			template_id: '550e8400-e29b-41d4-a716-446655440000',
			code: 'pricing-locked',
			display_name: 'Pricing Locked',
			action_kind: 'template_override',
			definition_version: 1,
			supported_execution_modes: ['after_submission'],
			base_credit_cost: 42
		} as unknown);

		expect((parsed as Record<string, unknown>).base_credit_cost).toBeUndefined();
	});

	it('strips deprecated base_credit_cost from update payloads', () => {
		const parsed = customActionUpdateSchema.parse({
			display_name: 'Updated Name',
			action_kind: 'template_override',
			definition_version: 1,
			supported_execution_modes: ['after_submission'],
			base_credit_cost: 7
		} as unknown);

		expect((parsed as Record<string, unknown>).base_credit_cost).toBeUndefined();
	});

	it('validateCreatePayload returns sanitized data without pricing override fields', () => {
		const result = validateCreatePayload({
			template_id: '550e8400-e29b-41d4-a716-446655440000',
			code: 'schema-guard',
			display_name: 'Schema Guard',
			action_kind: 'template_override',
			definition_version: 1,
			supported_execution_modes: ['after_submission'],
			base_credit_cost: 99
		});

		expect(result.success).toBe(true);
		if (result.success) {
			expect((result.data as Record<string, unknown>).base_credit_cost).toBeUndefined();
		}
	});

	it('validateUpdatePayload returns sanitized data without pricing override fields', () => {
		const result = validateUpdatePayload({
			display_name: 'Schema Guard Updated',
			action_kind: 'template_override',
			definition_version: 1,
			supported_execution_modes: ['after_submission'],
			base_credit_cost: 99
		});

		expect(result.success).toBe(true);
		if (result.success) {
			expect((result.data as Record<string, unknown>).base_credit_cost).toBeUndefined();
		}
	});

	it('requires definition when action_kind is custom_definition', () => {
		const result = validateCreatePayload({
			template_id: '550e8400-e29b-41d4-a716-446655440000',
			code: 'custom-def-required',
			display_name: 'Custom Def Required',
			action_kind: 'custom_definition',
			definition_version: 1,
			supported_execution_modes: ['after_submission']
		});

		expect(result.success).toBe(false);
		if (!result.success) {
			expect(result.errors.some((error) => error.path === 'definition')).toBe(true);
		}
	});

	it('accepts valid workflow definitions for custom_definition payloads', () => {
		const result = validateCreatePayload({
			template_id: '550e8400-e29b-41d4-a716-446655440000',
			code: 'workflow-valid',
			display_name: 'Workflow Valid',
			action_kind: 'custom_definition',
			definition: {
				workflow: {
					version: 1,
					nodes: [
						{
							node_id: 'extract',
							kind: 'llm_step',
							prompt_template: 'Extract entities',
							output_key: 'entities'
						},
						{
							node_id: 'summarize',
							kind: 'transform_step',
							output_key: 'summary'
						}
					],
					edges: [{ from: 'extract', to: 'summarize' }],
					max_parallelism: 4
				}
			},
			definition_version: 2,
			supported_execution_modes: ['validation', 'after_submission']
		});

		expect(result.success).toBe(true);
	});

	it('rejects workflow edges that reference unknown nodes', () => {
		const result = validateCreatePayload({
			template_id: '550e8400-e29b-41d4-a716-446655440000',
			code: 'workflow-invalid-edge',
			display_name: 'Workflow Invalid Edge',
			action_kind: 'custom_definition',
			definition: {
				workflow: {
					nodes: [
						{
							node_id: 'extract',
							kind: 'llm_step',
							prompt_template: 'Extract entities',
							output_key: 'entities'
						}
					],
					edges: [{ from: 'extract', to: 'missing' }]
				}
			},
			definition_version: 1,
			supported_execution_modes: ['after_submission']
		});

		expect(result.success).toBe(false);
		if (!result.success) {
			expect(result.errors.some((error) => error.path.includes('edges.0.to'))).toBe(true);
		}
	});
});
