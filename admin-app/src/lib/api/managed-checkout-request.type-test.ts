import type { ManagedCheckoutCompleteRequest } from './types';

const completeWithIntent: ManagedCheckoutCompleteRequest = {
	activation_token: 'signed-token',
	checkout_intent_id: '11111111-1111-4111-8111-111111111111'
};
const completeWithSession: ManagedCheckoutCompleteRequest = {
	activation_token: 'signed-token',
	checkout_session_id: 'cs_test_123'
};

// @ts-expect-error An activation token alone is not a complete checkout request.
const missingCheckoutReference: ManagedCheckoutCompleteRequest = {
	activation_token: 'signed-token'
};

void completeWithIntent;
void completeWithSession;
void missingCheckoutReference;
