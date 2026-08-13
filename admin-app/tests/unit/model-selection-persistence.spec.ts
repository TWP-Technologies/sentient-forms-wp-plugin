import { describe, expect, it } from 'vitest';
import {
	normalizeModelSelectionForPersistence,
	reconcileModelSelectionCredentialForPersistence
} from '$lib/utils/model-selection-persistence';

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

	it('replaces a stale credential with the only ready credential for its provider', () => {
		expect(
			reconcileModelSelectionCredentialForPersistence(
				{ provider: 'openrouter', primary: 'sf_default', credential_id: 61 },
				[
					{ id: 62, provider: 'sentient_managed', status: 'valid' },
					{ id: 64, provider: 'openrouter', status: 'valid' }
				]
			)
		).toEqual({ provider: 'openrouter', primary: 'sf_default', credential_id: 64 });
	});

	it('fails closed when a stale credential has multiple ready replacements', () => {
		expect(
			reconcileModelSelectionCredentialForPersistence(
				{ provider: 'openrouter', primary: 'sf_default', credential_id: 61 },
				[
					{ id: 64, provider: 'openrouter', status: 'valid' },
					{ id: 65, provider: 'openrouter', status: 'limited' }
				]
			)
		).toEqual({ provider: 'openrouter', primary: 'sf_default', credential_id: null });
	});

	it('preserves the requested credential when it is ready for the selected provider', () => {
		expect(
			reconcileModelSelectionCredentialForPersistence(
				{ provider: 'openrouter', primary: 'sf_default', credential_id: 64 },
				[{ id: 64, provider: 'openrouter', status: 'limited' }]
			)
		).toEqual({ provider: 'openrouter', primary: 'sf_default', credential_id: 64 });
	});
});
