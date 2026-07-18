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

const managedCheckoutReturnParamNames = [
	'sentient_managed_checkout',
	'checkout_intent_id',
	'checkout_session_id',
	'stripe_session_id',
	'activation_token'
] as const;

function removeManagedCheckoutParams(params: URLSearchParams): void {
	for (const name of managedCheckoutReturnParamNames) {
		params.delete(name);
	}
}

function managedCheckoutReturnParams(search: string, hash = ''): URLSearchParams {
	const hashQueryIndex = hash.indexOf('?');
	if (hashQueryIndex >= 0) {
		const hashParams = new URLSearchParams(hash.slice(hashQueryIndex + 1));
		if (hashParams.has('sentient_managed_checkout')) {
			return hashParams;
		}
	}

	return new URLSearchParams(search);
}

export function hasManagedCheckoutSuccessMarker(search: string, hash = ''): boolean {
	const params = managedCheckoutReturnParams(search, hash);
	return managedCheckoutSuccessMarkerSchema.safeParse(params.get('sentient_managed_checkout'))
		.success;
}

export function parseManagedCheckoutReturn(search: string, hash = ''): ManagedCheckoutReference | null {
	const params = managedCheckoutReturnParams(search, hash);
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

export function removeManagedCheckoutReturnParams(href: string): string {
	const url = new URL(href);
	removeManagedCheckoutParams(url.searchParams);

	const hashQueryIndex = url.hash.indexOf('?');
	if (hashQueryIndex >= 0) {
		const hashPath = url.hash.slice(0, hashQueryIndex);
		const hashParams = new URLSearchParams(url.hash.slice(hashQueryIndex + 1));
		removeManagedCheckoutParams(hashParams);
		const remainingQuery = hashParams.toString();
		url.hash = remainingQuery ? `${hashPath}?${remainingQuery}` : hashPath;
	}

	return url.toString();
}
