import {
	managedCheckoutCompleteRequestSchema,
	type ManagedCheckoutCompleteRequest
} from '$lib/api/managed-checkout-contract';

describe('managed checkout contract', () => {
	it('accepts an activation token with either durable checkout reference', () => {
		const byIntent: ManagedCheckoutCompleteRequest = {
			activation_token: 'signed-token',
			checkout_intent_id: '11111111-1111-4111-8111-111111111111'
		};
		const bySession: ManagedCheckoutCompleteRequest = {
			activation_token: 'signed-token',
			checkout_session_id: 'cs_test_123'
		};

		expect(managedCheckoutCompleteRequestSchema.parse(byIntent)).toEqual(byIntent);
		expect(managedCheckoutCompleteRequestSchema.parse(bySession)).toEqual(bySession);
	});

	it('rejects missing, malformed, and unexpected checkout references', () => {
		expect(() =>
			managedCheckoutCompleteRequestSchema.parse({ activation_token: 'signed-token' })
		).toThrow();
		expect(() =>
			managedCheckoutCompleteRequestSchema.parse({
				activation_token: 'signed-token',
				checkout_intent_id: 'not-a-uuid'
			})
		).toThrow();
		expect(() =>
			managedCheckoutCompleteRequestSchema.parse({
				activation_token: 'signed-token',
				checkout_session_id: 'cs_test_123',
				unexpected: true
			})
		).toThrow();
	});
});
