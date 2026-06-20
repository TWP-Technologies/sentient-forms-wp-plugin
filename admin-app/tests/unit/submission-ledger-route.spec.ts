import { describe, expect, it } from 'vitest';
import { load } from '../../src/routes/(app)/actions/[formSourceSlug]/[formId]/submissions/+page';

describe('submission ledger route loader', () => {
	it('preserves provider-native form identifiers as opaque strings', () => {
		expect(
			load({
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
});
