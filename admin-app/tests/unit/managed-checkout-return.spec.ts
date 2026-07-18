import {
	hasManagedCheckoutSuccessMarker,
	parseManagedCheckoutReturn
} from '$lib/api/managed-checkout-return';
import { describe, expect, it } from 'vitest';

describe('managed checkout browser return parsing', () => {
	it('parses a completed return through the strict runtime schema', () => {
		expect(
			parseManagedCheckoutReturn(
				'?sentient_managed_checkout=completed&checkout_intent_id=11111111-1111-4111-8111-111111111111&activation_token=%20signed-token%20'
			)
		).toEqual({
			checkoutIntentId: '11111111-1111-4111-8111-111111111111',
			checkoutSessionId: undefined,
			activationToken: 'signed-token'
		});
	});

	it('accepts the legacy Stripe session query key as the bounded session reference', () => {
		expect(
			parseManagedCheckoutReturn(
				'?sentient_managed_checkout=success&stripe_session_id=cs_test_123&activation_token=signed-token'
			)
		).toEqual({
			checkoutIntentId: undefined,
			checkoutSessionId: 'cs_test_123',
			activationToken: 'signed-token'
		});
	});

	it.each([
		[
			'non-UUID intent',
			'?sentient_managed_checkout=success&checkout_intent_id=not-a-uuid&activation_token=signed-token'
		],
		[
			'missing secure reference',
			'?sentient_managed_checkout=success&activation_token=signed-token'
		],
		[
			'empty activation token',
			'?sentient_managed_checkout=success&checkout_session_id=cs_test_123&activation_token=%20'
		],
		[
			'oversized activation token',
			'?sentient_managed_checkout=success&checkout_session_id=cs_test_123&activation_token=' +
				'a'.repeat(4097)
		]
	])('rejects an invalid %s return', (_label, search) => {
		expect(parseManagedCheckoutReturn(search)).toBeNull();
	});

	it('recognizes only the canonical successful return markers', () => {
		expect(hasManagedCheckoutSuccessMarker('?sentient_managed_checkout=success')).toBe(true);
		expect(hasManagedCheckoutSuccessMarker('?sentient_managed_checkout=completed')).toBe(true);
		expect(hasManagedCheckoutSuccessMarker('?sentient_managed_checkout=cancelled')).toBe(false);
	});
});
