import { z } from 'zod';

const httpsUrlSchema = z.string().refine((value) => {
	try {
		return new URL(value).protocol === 'https:';
	} catch {
		return false;
	}
}, 'Expected an HTTPS URL');

export const billingCheckoutSessionResponseSchema = z
	.object({
		session_id: z.string(),
		checkout_url: httpsUrlSchema,
		customer_id: z.string(),
		subscription_id: z.string().min(1).optional()
	})
	.passthrough();

export type BillingCheckoutSessionResponse = z.infer<
	typeof billingCheckoutSessionResponseSchema
>;
