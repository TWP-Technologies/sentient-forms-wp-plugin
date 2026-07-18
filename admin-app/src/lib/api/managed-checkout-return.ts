import { z } from 'zod';

export const managedCheckoutSuccessMarkerSchema = z.enum(['success', 'completed']);

export const managedCheckoutReturnSchema = z
	.strictObject({
		checkoutResult: managedCheckoutSuccessMarkerSchema,
		checkoutIntentId: z.uuid().optional(),
		checkoutSessionId: z.string().trim().min(1).max(255).optional(),
		activationToken: z.string().trim().min(1).max(4096).regex(/^\S+$/).optional()
	})
	.refine(
		(value) =>
			Boolean(value.activationToken) && Boolean(value.checkoutIntentId || value.checkoutSessionId),
		{ message: 'A secure checkout reference and activation token are required.' }
	);

type ParsedManagedCheckoutReturn = z.infer<typeof managedCheckoutReturnSchema>;

export type ManagedCheckoutReference = Pick<
	ParsedManagedCheckoutReturn,
	'checkoutIntentId' | 'checkoutSessionId'
> & {
	activationToken: string;
};

export function hasManagedCheckoutSuccessMarker(search: string): boolean {
	const params = new URLSearchParams(search);
	return managedCheckoutSuccessMarkerSchema.safeParse(params.get('sentient_managed_checkout'))
		.success;
}

export function parseManagedCheckoutReturn(search: string): ManagedCheckoutReference | null {
	const params = new URLSearchParams(search);
	const parsed = managedCheckoutReturnSchema.safeParse({
		checkoutResult: params.get('sentient_managed_checkout'),
		checkoutIntentId: params.get('checkout_intent_id')?.trim() || undefined,
		checkoutSessionId:
			params.get('checkout_session_id')?.trim() ||
			params.get('stripe_session_id')?.trim() ||
			undefined,
		activationToken: params.get('activation_token')?.trim() || undefined
	});

	if (!parsed.success || !parsed.data.activationToken) {
		return null;
	}

	return {
		checkoutIntentId: parsed.data.checkoutIntentId,
		checkoutSessionId: parsed.data.checkoutSessionId,
		activationToken: parsed.data.activationToken
	};
}
