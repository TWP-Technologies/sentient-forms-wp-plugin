import { describe, expect, it } from 'vitest';
import { normalizeModelSelectionForPersistence } from '$lib/utils/model-selection-persistence';

describe('model selection persistence', () => {
	it.each([null, '', 0, -1, 1.5, Number.MAX_SAFE_INTEGER + 1, 1e20])(
		'canonicalizes a non-positive or missing credential %j to null',
		(credentialId) => {
			expect(
				normalizeModelSelectionForPersistence({
					provider: 'openrouter',
					primary: 'sf_default',
					credential_id: credentialId
				})
			).toEqual({
				provider: 'openrouter',
				primary: 'sf_default',
				credential_id: null
			});
		}
	);

	it('preserves a positive integer credential', () => {
		expect(normalizeModelSelectionForPersistence({ credential_id: 64 })).toEqual({
			credential_id: 64
		});
	});
});
