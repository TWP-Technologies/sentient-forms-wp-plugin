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
			base_credit_cost: 42
		} as unknown);

		expect((parsed as Record<string, unknown>).base_credit_cost).toBeUndefined();
	});

	it('strips deprecated base_credit_cost from update payloads', () => {
		const parsed = customActionUpdateSchema.parse({
			display_name: 'Updated Name',
			base_credit_cost: 7
		} as unknown);

		expect((parsed as Record<string, unknown>).base_credit_cost).toBeUndefined();
	});

	it('validateCreatePayload returns sanitized data without pricing override fields', () => {
		const result = validateCreatePayload({
			template_id: '550e8400-e29b-41d4-a716-446655440000',
			code: 'schema-guard',
			display_name: 'Schema Guard',
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
			base_credit_cost: 99
		});

		expect(result.success).toBe(true);
		if (result.success) {
			expect((result.data as Record<string, unknown>).base_credit_cost).toBeUndefined();
		}
	});
});
