import { z } from 'zod';

const httpUrlSchema = z.url({ protocol: /^https?$/ });
const httpsUrlSchema = z.url({ protocol: /^https$/ });

const managedBillingBoundarySchema = z.object({
	direct_openrouter_billed_by_sentient: z.literal(false),
	managed_proxy_billed_by_sentient: z.literal(true)
});

const managedTierSchema = z.object({
	code: z.string().min(1),
	display_name: z.string().min(1),
	site_limit: z.number().int(),
	monthly_credit_quota: z.number().int()
});

export const managedCheckoutStartResponseSchema = z.object({
	service: z.literal('sentient-managed'),
	status: z.literal('open'),
	checkout_intent_id: z.uuid(),
	checkout_session_id: z.string().min(1),
	checkout_url: httpsUrlSchema,
	plan_code: z.enum(['starter', 'pro', 'business']),
	billing_interval: z.literal('monthly'),
	billing_boundary: managedBillingBoundarySchema,
	consent_recorded: z.boolean().optional(),
	consent_id: z.number().int().positive().optional(),
	disclosure_version: z.string().min(1).optional()
});

const managedCheckoutPendingResponseSchema = z.object({
	service: z.literal('sentient-managed'),
	status: z.string().min(1),
	activation_ready: z.literal(false),
	pending_reason: z.string().min(1),
	site_url: httpUrlSchema,
	local_site_identifier: z.string().min(1).max(128),
	billing_boundary: managedBillingBoundarySchema,
	license_key: z.never().optional(),
	proxy_api_key: z.never().optional(),
	license_id: z.never().optional(),
	site_id: z.never().optional(),
	tier: z.never().optional(),
	expiry_date: z.never().optional()
});

const managedCheckoutReadyResponseSchema = z.object({
	service: z.literal('sentient-managed'),
	status: z.string().min(1),
	activation_ready: z.literal(true),
	license_key: z.string().min(1),
	proxy_api_key: z.string().min(1),
	license_id: z.uuid(),
	site_id: z.uuid(),
	site_url: httpUrlSchema,
	local_site_identifier: z.string().min(1).max(128),
	tier: managedTierSchema,
	expiry_date: z.iso.date().optional(),
	billing_boundary: managedBillingBoundarySchema,
	credential_id: z.number().int().positive(),
	managed_provider_ready: z.literal(true),
	pending_reason: z.never().optional()
});

export const managedCheckoutCompleteResponseSchema = z.discriminatedUnion('activation_ready', [
	managedCheckoutPendingResponseSchema,
	managedCheckoutReadyResponseSchema
]);

export type ManagedCheckoutStartResponse = z.infer<typeof managedCheckoutStartResponseSchema>;
export type ManagedCheckoutCompleteResponse = z.infer<typeof managedCheckoutCompleteResponseSchema>;
