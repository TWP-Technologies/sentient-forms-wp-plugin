import { describe, expect, it } from 'vitest';
import { load as loadFormActions } from '../../src/routes/(app)/actions/[formSourceSlug]/[formId]/+page';
import { load as loadLeadValue } from '../../src/routes/(app)/actions/[formSourceSlug]/[formId]/lead-value/+page';

describe('form actions route loader', () => {
	it('preserves provider-native form identifiers as opaque strings', () => {
		expect(
			loadFormActions({
				params: {
					formSourceSlug: 'opaque_forms',
					formId: 'form-alpha_2026'
				}
			} as never)
		).toEqual({
			formSourceSlug: 'opaque_forms',
			formId: 'form-alpha_2026'
		});
	});

	it('preserves opaque form identifiers on the lead-value subroute', () => {
		expect(
			loadLeadValue({
				params: {
					formSourceSlug: 'opaque_forms',
					formId: 'form-alpha_2026'
				}
			})
		).toEqual({
			formSourceSlug: 'opaque_forms',
			formId: 'form-alpha_2026'
		});
	});
});
