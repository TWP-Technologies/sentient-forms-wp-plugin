import { z } from 'zod';
import { formSourceDescriptorBoundarySchema } from '$lib/api/endpoint-schemas';

const absoluteOrRootRelativeUrlSchema = z
	.string()
	.min(1)
	.refine((value) => {
		if (value.startsWith('//')) return false;
		if (value.startsWith('/')) return true;
		try {
			const url = new URL(value);
			if (!['http:', 'https:'].includes(url.protocol)) return false;
			return typeof window === 'undefined' || url.origin === window.location.origin;
		} catch {
			return false;
		}
	}, 'Expected a same-origin HTTP(S) or root-relative URL.');

const siteUrlSchema = z.string().refine((value) => {
	if (value === '') return true;
	try {
		return ['http:', 'https:'].includes(new URL(value).protocol);
	} catch {
		return false;
	}
}, 'Expected an HTTP(S) URL.');

const nullableStringSchema = z.string().nullable();

const formSourceSummarySchema = z.object({
	slug: z.string().min(1),
	label: z.string().min(1),
	isActive: z.boolean(),
	availability: z.string().optional(),
	availabilityMessage: nullableStringSchema.optional(),
	requiresPro: z.boolean().optional(),
	descriptor: formSourceDescriptorBoundarySchema.nullable().optional()
});

export const runtimeConfigSchema = z.object({
	apiBaseUrl: absoluteOrRootRelativeUrlSchema,
	restNonce: z.string().min(1),
	ajaxNonce: z.string().default(''),
	siteUrl: siteUrlSchema.default(''),
	localSiteIdentifier: z.string().min(1).optional(),
	pluginVersion: z.string().min(1).optional(),
	initialRoute: z.string().optional(),
	formSources: z.array(formSourceSummarySchema).optional(),
	license: z
		.strictObject({
			status: z.string().optional(),
			licenseKeyMasked: z.string().optional(),
			proxyKeyPresent: z.boolean().optional(),
			tier: nullableStringSchema.optional(),
			expiresAt: nullableStringSchema.optional(),
			lastSynced: nullableStringSchema.optional(),
			licenseId: nullableStringSchema.optional(),
			siteId: nullableStringSchema.optional()
		})
		.optional(),
	i18n: z.record(z.string(), z.string()).optional(),
	devMode: z.boolean().optional(),
	demoMode: z.boolean().optional(),
	devServerUrl: nullableStringSchema.optional(),
	telemetry: z
		.strictObject({
			optIn: z.boolean(),
			updatedAt: nullableStringSchema.optional()
		})
		.optional(),
	asyncSettings: z
		.strictObject({
			maxAttempts: z.number().int(),
			baseDelaySeconds: z.number().int(),
			maxDelaySeconds: z.number().int(),
			updatedAt: nullableStringSchema.optional(),
			updatedBy: nullableStringSchema.optional()
		})
		.optional(),
	asyncHealth: z
		.strictObject({
			queue_depth: z.number().int(),
			oldest_run_at: z.number().nullable(),
			recent_failures: z.record(z.string(), z.number()),
			warnings: z.array(
				z.strictObject({ code: z.string(), level: z.string(), message: z.string() })
			)
		})
		.optional(),
	currentUser: z
		.strictObject({
			id: z.number().int(),
			canManage: z.boolean()
		})
		.optional()
});

export type SentientFormsConfig = z.output<typeof runtimeConfigSchema>;

declare global {
	interface Window {
		sentientFormsConfig?: unknown;
	}
}

export class RuntimeConfigError extends Error {
	readonly issues: ReadonlyArray<{ code: string; path: Array<string | number>; message: string }>;

	constructor(
		issues: ReadonlyArray<{ code: string; path: Array<string | number>; message: string }>
	) {
		super('Sentient Forms runtime config is invalid.');
		this.name = 'RuntimeConfigError';
		this.issues = issues;
	}
}

export function readRuntimeConfig(): SentientFormsConfig | undefined {
	if (typeof window === 'undefined') return undefined;
	const payload = Reflect.get(window, 'sentientFormsConfig');
	if (payload === undefined) return undefined;

	const result = runtimeConfigSchema.safeParse(payload);
	if (result.success) return result.data;

	throw new RuntimeConfigError(
		result.error.issues.map((issue) => ({
			code: issue.code,
			path: issue.path.filter(
				(segment): segment is string | number =>
					typeof segment === 'string' || typeof segment === 'number'
			),
			message: issue.message
		}))
	);
}

export function requireRuntimeConfig(): SentientFormsConfig {
	const config = readRuntimeConfig();
	if (config === undefined) {
		throw new Error('Sentient Forms runtime config missing.');
	}
	return config;
}

export function readRuntimeConfigSafely(): SentientFormsConfig | undefined {
	try {
		return readRuntimeConfig();
	} catch {
		return undefined;
	}
}
