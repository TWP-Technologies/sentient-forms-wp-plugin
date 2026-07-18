import type { SentientFormsConfig } from '$lib/api/http';
import { safeParseFormActionConfigPayload } from '$lib/schemas/action-config';
import { parseProviderPathPolicy } from '$lib/schemas/provider-path-policy';
import { z } from 'zod';
import {
	announceWordPressSessionExpired,
	isWordPressSessionExpired
} from '$lib/api/session-expiry';
import {
	announceSecurityRoadblock,
	classifySecurityRoadblock,
	isSecurityRoadblockPayload,
	notifySecurityRoadblock
} from '$lib/api/security-roadblock';
import {
	buildInvalidJsonResponsePayload,
	hasContaminatedJsonPrefix,
	readResponseText
} from '$lib/api/invalid-json';
import { notifications } from '$lib/stores/notifications';
import {
	managedCheckoutCompleteResponseSchema,
	managedCheckoutStartResponseSchema,
	type ManagedCheckoutCompleteResponse,
	type ManagedCheckoutStartResponse
} from '$lib/api/managed-checkout-contract';
import type {
	ActionDefinition,
	ActionDefaultsBatchResponse,
	ApiErrorPayload,
	AsyncSettingsResponse,
	BillingCheckoutSessionRequest,
	BillingCheckoutSessionResponse,
	BillingPortalSessionRequest,
	BillingPortalSessionResponse,
	BillingStateResponse,
	AsyncHealthResponse,
	CloneTemplateMappingRequest,
	CreateFormMappingRequest,
	CustomAction,
	CustomActionCreatePayload,
	CustomActionFilters,
	CustomActionQuota,
	CustomActionUpdatePayload,
	DashboardSummaryResponse,
	DuplicateFormActionRequest,
	DuplicateFormActionResponse,
	ExecutionStatus,
	FormActionConfig,
	FormActionConfigResponse,
	FormActionsBootstrapResponse,
	FormDisableStateResponse,
	FormActionLinkage,
	FormActionMutationPayload,
	FormAllActionConfigsResponse,
	FormExecutionStatus,
	FormFieldInfo,
	FormsOverviewResponse,
	SubmissionLedgerRecord,
	SubmissionLedgerRecordsResponse,
	SubmissionLedgerSettingsResponse,
	RequestTraceRequest,
	RequestTraceResponse,
	WorkflowPlanResponse,
	FormMapping,
	FormSummary,
	CapabilitiesResponse,
	LicenseActivationRequest,
	LicenseActivationResponsePayload,
	LicenseActivationResult,
	LicenseInfoResponse,
	LeadProfileResponse,
	LeadProfileGeneratePayload,
	LeadProfileSavePayload,
	LeadScoringCorrectionPayload,
	LeadValueDashboard,
	LeadValueEntrySearchResponse,
	LeadValueHistoricalRunCreatePayload,
	LeadValueHistoricalRunResponse,
	LocalActionTemplate,
	LocalCustomActionCreatePayload,
	LocalCustomActionRecord,
	LocalExecutionEvent,
	LocalFormMappingCreatePayload,
	LocalFormMappingRecord,
	LocalMigrationApprovedResetRequest,
	LocalMigrationApprovedResetResponse,
	LocalMigrationDryRunResponse,
	LocalMigrationImportApplyResponse,
	LocalMigrationImportDryRunResponse,
	LocalMigrationImportRequest,
	LocalMigrationReadinessReport,
	LocalProviderCredential,
	LocalProviderCredentialDeleteResponse,
	LocalSupportBundle,
	ManagedCheckoutCompleteRequest,
	ManagedCheckoutStartRequest,
	OpenRouterConstantRequest,
	OpenRouterModelsRefreshRequest,
	OpenRouterModelsResponse,
	OpenRouterValidateRequest,
	OpenRouterValidateResponse,
	SentientManagedRevokeRequest,
	SentientManagedRevokeResponse,
	SentientManagedSetupRequest,
	SentientManagedSetupResponse,
	SpamGuidanceEntrySearchResponse,
	SpamGuidanceEntryStatusFilter,
	SpamGuidanceExampleAppendPayload,
	SpamGuidanceExampleAppendResponse,
	TelemetrySettingsResponse,
	PluginSettingsResponse,
	TopUpCheckoutSessionRequest,
	TopUpCheckoutSessionResponse,
	UpdateFormMappingRequest
} from '$lib/api/types';

export interface ClientConfig {
	baseUrl: string;
	getNonce?: () => string | undefined;
	fetchImpl?: typeof fetch;
	notifyErrors?: boolean;
	cacheContext?: () => string | undefined;
}

export interface RequestOptions extends Omit<RequestInit, 'body'> {
	body?: unknown;
	showNotifications?: boolean;
	cacheTtlMs?: number;
	cacheTags?: string[];
	cacheStorage?: 'memory' | 'session';
	forceRefresh?: boolean;
	dedupe?: boolean;
	invalidateCacheTags?: string[] | false;
}

interface SubmissionLedgerRecordsRequestOptions extends RequestOptions {
	perPage?: number;
	offset?: number;
	q?: string;
	nativeEntry?: string;
	capturedFrom?: string;
	capturedTo?: string;
	hasFiles?: boolean | null;
	sort?: string;
}

type FormSourceFormId = string | number;

interface RestEnvelope<T> {
	success: boolean;
	data: T;
}

interface AdminApiCacheEntry {
	expiresAt: number;
	tags: string[];
	value: unknown;
}

interface AdminApiInFlightEntry {
	promise: Promise<unknown>;
	showNotifications?: boolean;
	tags: string[];
}

const ledgerFormIdSchema = z.union([z.string(), z.number()]).transform((value) => String(value));
const nullableScalarStringSchema = z
	.union([z.string(), z.number(), z.null()])
	.transform((value) => (value === null ? null : String(value)));
const jsonRecordSchema = z.record(z.string(), z.unknown());
const nullishJsonRecordSchema = jsonRecordSchema.nullish().transform((value) => value ?? {});
const nullableJsonRecordSchema = jsonRecordSchema.nullish().transform((value) => value ?? null);
const submissionLedgerActionRunSchema = z.object({
	execution_request_id: z.string(),
	mapping_id: z.coerce.number().int().nullable().optional().transform((value) => value ?? null),
	status: z.string(),
	provider: nullableScalarStringSchema,
	model: nullableScalarStringSchema,
	last_result: nullableJsonRecordSchema,
	last_error_code: nullableScalarStringSchema,
	last_error_message: nullableScalarStringSchema,
	created_at: z
		.string()
		.nullable()
		.optional()
		.transform((value) => value ?? null),
	updated_at: z
		.string()
		.nullable()
		.optional()
		.transform((value) => value ?? null)
});
const nullishJsonRecordArraySchema = z
	.array(jsonRecordSchema)
	.nullish()
	.transform((value) => value ?? []);
const httpsUrlSchema = z.string().refine((value) => {
	try {
		return new URL(value).protocol === 'https:';
	} catch {
		return false;
	}
}, 'Expected an HTTPS URL');
const billingCheckoutSessionResponseSchema = z
	.object({
		session_id: z.string(),
		checkout_url: httpsUrlSchema,
		customer_id: z.string(),
		subscription_id: z.string().nullable().optional()
	})
	.passthrough();
const billingPortalSessionResponseSchema = z
	.object({
		session_id: z.string(),
		portal_url: httpsUrlSchema,
		customer_id: z.string()
	})
	.passthrough();
const topUpCheckoutSessionResponseSchema = z
	.object({
		session_id: z.string(),
		checkout_url: httpsUrlSchema,
		customer_id: z.string(),
		top_up_credits: z.number().int(),
		pack_code: z.string()
	})
	.passthrough();
const submissionLedgerRecordSchema = z.object({
	id: z.coerce.number().int(),
	submission_uuid: z.string(),
	form_source: z.string(),
	form_id: ledgerFormIdSchema,
	native_entry_id: nullableScalarStringSchema,
	native_entry_url: z.string().nullable(),
	source_submitted_at: z.string().nullable(),
	captured_at: z.string(),
	logical_fields: jsonRecordSchema,
	provider_metadata: nullishJsonRecordSchema,
	file_refs: nullishJsonRecordArraySchema,
	redaction_summary: nullishJsonRecordSchema,
	action_runs: z.array(submissionLedgerActionRunSchema).nullish().transform((value) => value ?? []),
	expires_at: z.string().nullable(),
	detail_endpoint: z.string()
});
const submissionLedgerSettingsResponseSchema = z.object({
	form_source: z.string(),
	form_id: ledgerFormIdSchema,
	enabled: z.boolean(),
	enabled_at: z.string().nullable(),
	enabled_by_user_id: z.number().int().nullable(),
	disabled_at: z.string().nullable(),
	disabled_by_user_id: z.number().int().nullable(),
	settings_source: z.string(),
	ledger_records_endpoint: z.string(),
	record_count: z.number().int().optional()
});
const submissionLedgerRecordsResponseSchema = z
	.object({
		form_source: z.string(),
		form_id: ledgerFormIdSchema,
		records: z.array(submissionLedgerRecordSchema).optional(),
		submissions: z.array(submissionLedgerRecordSchema).optional(),
		total: z.number().int().optional(),
		count: z.number().int().optional(),
		per_page: z.number().int(),
		offset: z.number().int()
	})
	.transform((payload) => {
		const records = payload.records ?? payload.submissions ?? [];
		return {
			form_source: payload.form_source,
			form_id: payload.form_id,
			records,
			total: payload.total ?? payload.count ?? records.length,
			per_page: payload.per_page,
			offset: payload.offset
		};
	});
const spamGuidanceFieldSummarySchema = z.object({
	field_id: z.string(),
	label: z.string(),
	value: z.string()
});
const spamGuidanceEntrySearchEntrySchema = z
	.object({
		id: z.union([z.string(), z.number()]).transform((value) => String(value)),
		source_type: z.enum(['native', 'ledger']),
		submission_uuid: nullableScalarStringSchema.optional(),
		native_entry_id: nullableScalarStringSchema.optional(),
		native_entry_url: z.string().nullable().optional(),
		date_created: z.string().nullable().optional(),
		status: z.string().nullable().optional(),
		field_summary: z.array(spamGuidanceFieldSummarySchema)
	})
	.passthrough();
const spamGuidanceEntrySearchResponseSchema = z
	.object({
		form_source: z.string(),
		form_id: ledgerFormIdSchema,
		availability: z
			.object({
				source: z.enum(['native', 'ledger']),
				native_read: z.boolean(),
				ledger_read: z.boolean(),
				ledger_enabled: z.boolean().optional(),
				unavailable_reason: z.string().nullable().optional(),
				native_unavailable_reason: z.string().nullable().optional()
			})
			.passthrough(),
		entries: z.array(spamGuidanceEntrySearchEntrySchema)
	})
	.passthrough();
const spamGuidanceExampleSourceSchema = z
	.object({
		kind: z.enum(['manual', 'entry']),
		form_source: z.string().optional(),
		form_id: z.string().optional(),
		entry_id: z.string().optional(),
		native_entry_id: z.string().nullable().optional(),
		selected_at: z.string().optional(),
		selected_by_user_id: z.number().int().nullable().optional()
	})
	.passthrough();
const spamGuidanceExampleSchemaForResponse = z
	.object({
		text: z.string(),
		rationale: z.string(),
		source: spamGuidanceExampleSourceSchema.optional()
	})
	.passthrough();
const spamGuidanceAppendResponseSchema = z
	.object({
		target_scope: z.enum(['form', 'mapping', 'action']),
		label: z.enum(['ham', 'spam']),
		config: z
			.object({
				spam_positive_examples: z.array(spamGuidanceExampleSchemaForResponse).optional(),
				spam_negative_examples: z.array(spamGuidanceExampleSchemaForResponse).optional()
			})
			.passthrough()
	})
	.passthrough();
const formExecutionStatusSchema = z
	.object({
		status: z.enum(['unknown', 'success', 'error']),
		message: z.string().nullable(),
		entry_id: z.number().int().nullable().optional(),
		last_error_code: z.string().nullable(),
		last_result: z.unknown().optional(),
		updated_at: z.string().nullable().optional()
	})
	.passthrough();
const formDisableStateResponseSchema = z
	.object({
		sf_disabled: z.boolean(),
		global_disabled: z.boolean(),
		provider_disabled: z.boolean(),
		effective_disabled: z.boolean()
	})
	.passthrough();
const formActionsBootstrapResponseSchema = z
	.object({
		form_source: z.string(),
		form_id: z.union([z.string(), z.number()]),
		form: jsonRecordSchema.nullable().optional(),
		form_source_descriptor: jsonRecordSchema.nullable().optional(),
		actions: z.array(jsonRecordSchema),
		execution_status: formExecutionStatusSchema,
		disabled_state: formDisableStateResponseSchema,
		ledger_settings: submissionLedgerSettingsResponseSchema.optional(),
		generated_at: z.string()
	})
	.passthrough();
const dashboardSummaryResponseSchema = z
	.object({
		generated_at: z.string(),
		providers: z.array(jsonRecordSchema),
		templates: z.array(jsonRecordSchema),
		custom_actions: z.array(jsonRecordSchema),
		recent_events: z.array(jsonRecordSchema),
		section_errors: z
			.array(
				z
					.object({
						section: z.string(),
						code: z.string(),
						message: z.string()
					})
					.passthrough()
			)
			.optional(),
		license: jsonRecordSchema.optional(),
		async_health: jsonRecordSchema.optional()
	})
	.passthrough();
const openRouterModelCacheItemSchema = z
	.object({
		id: z.string(),
		name: z.string(),
		free: z.boolean(),
		context_length: z.number().int().nullable(),
		input_modalities: z.array(z.string()),
		output_modalities: z.array(z.string()),
		supported_parameters: z.array(z.string()),
		pricing: z.record(z.string(), z.string()),
		fetched_at: z.string().nullable(),
		expires_at: z.string().nullable(),
		stale: z.boolean(),
		zdr_eligible: z.boolean().nullable().optional(),
		zdr_source: z.string().nullable().optional(),
		zdr_checked_at: z.string().nullable().optional(),
		tags: z.array(z.string()).optional()
	})
	.passthrough();
const openRouterModelsResponseSchema = z
	.object({
		provider: z.literal('openrouter'),
		source: z.literal('local_cache'),
		total_cached: z.number().int(),
		total_returned: z.number().int(),
		free_count: z.number().int(),
		stale_count: z.number().int(),
		zdr_filtered: z.boolean().optional(),
		models: z.array(openRouterModelCacheItemSchema),
		refresh_consent: jsonRecordSchema.optional(),
		consent_recorded: z.boolean().optional(),
		consent_id: z.number().int().optional(),
		stored: z.number().int().optional()
	})
	.passthrough();
const localMigrationImportFindingSchema = z
	.object({
		code: z.string(),
		message: z.string(),
		severity: z.string().optional(),
		entity: z.string().optional(),
		field: z.string().optional(),
		value: z.string().optional()
	})
	.passthrough();
const localMigrationImportReportSchema = z
	.object({
		schema_version: z.string(),
		source: z.string(),
		source_version: z.string(),
		generated_at: z.string(),
		exported_at: z.string().nullable(),
		ready_to_import: z.boolean(),
		counts: z.record(z.string(), z.number()),
		changes: z.record(z.string(), z.union([z.number(), z.record(z.string(), z.number())])),
		conflicts: z.array(localMigrationImportFindingSchema),
		warnings: z.array(localMigrationImportFindingSchema),
		mapping: jsonRecordSchema
	})
	.passthrough();
const localMigrationImportDryRunResponseSchema = z
	.object({
		run_id: z.number().int(),
		status: z.string(),
		dry_run: z.literal(true),
		report: localMigrationImportReportSchema
	})
	.passthrough();
const localMigrationImportApplyResponseSchema = z
	.object({
		run_id: z.number().int(),
		status: z.string(),
		dry_run: z.literal(false),
		report: localMigrationImportReportSchema,
		applied: z.record(z.string(), z.number())
	})
	.passthrough();

const adminApiMemoryCache = new Map<string, AdminApiCacheEntry>();
const adminApiInFlight = new Map<string, AdminApiInFlightEntry>();
const adminApiCacheTagVersions = new Map<string, number>();
let adminApiGlobalCacheVersion = 0;
const sessionCachePrefix = 'sentientForms:adminApi:';
const ACTION_DEFAULTS_BATCH_LIMIT = 100;

function withCacheDefaults(
	options: RequestOptions,
	defaults: {
		ttlMs: number;
		tags: string[];
		storage?: 'memory' | 'session';
	}
): RequestOptions {
	return {
		cacheTtlMs: defaults.ttlMs,
		cacheStorage: defaults.storage ?? 'memory',
		...options,
		cacheTags: uniqueCacheTags([...defaults.tags, ...(options.cacheTags ?? [])])
	};
}

function mergeShowNotifications(
	current: boolean | undefined,
	next: boolean | undefined
): boolean | undefined {
	if (current === true || next === true) {
		return true;
	}

	if (current === undefined || next === undefined) {
		return undefined;
	}

	return false;
}

function uniqueCacheTags(tags: string[]): string[] {
	return [...new Set(tags.map((tag) => tag.trim()).filter(Boolean))];
}

function decodePathSegmentForCache(segment: string): string {
	try {
		return decodeURIComponent(segment);
	} catch {
		return segment;
	}
}

function formSourcePathSegment(formSourceSlug: string): string {
	return encodeURIComponent(formSourceSlug);
}

function formIdPathSegment(formId: FormSourceFormId): string {
	return encodeURIComponent(String(formId));
}

function formCacheTag(formSourceSlug: string, formId: FormSourceFormId): string {
	return `form:${formSourceSlug}:${String(formId)}`;
}

function isInvalidFormSourceContext(formSourceSlug: string, formId: FormSourceFormId): boolean {
	if (!formSourceSlug || formSourceSlug === 'undefined') {
		return true;
	}

	if (typeof formId === 'number') {
		return !Number.isFinite(formId);
	}

	return String(formId).trim() === '';
}

function getFormMutationCacheTags(path: string): string[] | null {
	let match = /^([^/]+)\/forms\/([^/]+)\/actions(?:\/|$)/.exec(path);
	if (match) {
		const [, formSourceSlug, formId] = match;
		const decodedSourceSlug = decodePathSegmentForCache(formSourceSlug);
		const decodedFormId = decodePathSegmentForCache(formId);
		return [
			'actions',
			'form-actions',
			'execution-status',
			'dashboard',
			`forms:${decodedSourceSlug}`,
			formCacheTag(decodedSourceSlug, decodedFormId)
		];
	}

	match = /^([^/]+)\/forms\/([^/]+)\/ledger-settings(?:\/|$)/.exec(path);
	if (match) {
		const [, formSourceSlug, formId] = match;
		const decodedSourceSlug = decodePathSegmentForCache(formSourceSlug);
		const decodedFormId = decodePathSegmentForCache(formId);
		return [
			'settings',
			'submission-ledger',
			'form-actions',
			`forms:${decodedSourceSlug}`,
			formCacheTag(decodedSourceSlug, decodedFormId)
		];
	}

	match = /^forms\/([^/]+)\/([^/]+)\/action-config(?:\/|$)/.exec(path);
	if (match) {
		const [, formSourceSlug, formId] = match;
		const decodedSourceSlug = decodePathSegmentForCache(formSourceSlug);
		const decodedFormId = decodePathSegmentForCache(formId);
		return [
			'form-actions',
			'action-defaults',
			'dashboard',
			`forms:${decodedSourceSlug}`,
			formCacheTag(decodedSourceSlug, decodedFormId)
		];
	}

	return null;
}

function inferMutationInvalidationTags(path: string): string[] {
	const normalizedPath = path.split('?')[0]?.replace(/^\/+/, '') ?? '';
	const formTags = getFormMutationCacheTags(normalizedPath);
	if (formTags) {
		return uniqueCacheTags(formTags);
	}

	if (normalizedPath.startsWith('license/managed-checkout')) {
		return ['license', 'billing', 'providers', 'capabilities', 'dashboard'];
	}

	if (normalizedPath.startsWith('license/billing')) {
		return ['license', 'billing', 'dashboard'];
	}

	if (normalizedPath.startsWith('license/')) {
		return ['license', 'billing', 'providers', 'capabilities', 'dashboard'];
	}

	if (normalizedPath === 'settings') {
		return ['settings', 'dashboard'];
	}

	if (normalizedPath === 'telemetry') {
		return ['settings', 'dashboard'];
	}

	if (normalizedPath.startsWith('async-')) {
		return ['async-health', 'dashboard'];
	}

	if (normalizedPath.startsWith('local/providers/')) {
		return [
			'providers',
			'actions',
			'custom-actions',
			'form-actions',
			'action-defaults',
			'dashboard'
		];
	}

	if (
		normalizedPath.startsWith('local/custom-actions') ||
		normalizedPath.startsWith('custom-actions')
	) {
		return ['actions', 'custom-actions', 'action-defaults', 'dashboard'];
	}

	if (normalizedPath.startsWith('local/form-mappings') || normalizedPath.startsWith('mappings')) {
		return ['actions', 'form-actions', 'forms', 'dashboard'];
	}

	if (normalizedPath.startsWith('actions/') && normalizedPath.endsWith('/defaults')) {
		return ['actions', 'action-defaults', 'form-actions', 'dashboard'];
	}

	if (normalizedPath.startsWith('local/migration/')) {
		return [
			'actions',
			'action-defaults',
			'async-health',
			'billing',
			'capabilities',
			'custom-actions',
			'dashboard',
			'definitions',
			'execution-events',
			'form-actions',
			'forms',
			'license',
			'meta',
			'providers',
			'settings',
			'templates'
		];
	}

	return ['dashboard'];
}

function getMutationInvalidationTags(
	path: string,
	explicitTags: RequestOptions['invalidateCacheTags']
): string[] | null {
	if (explicitTags === false) {
		return null;
	}

	if (Array.isArray(explicitTags)) {
		return uniqueCacheTags(explicitTags);
	}

	return inferMutationInvalidationTags(path);
}

function hasAnyCacheTag(candidateTags: string[], targetTags: string[]): boolean {
	return candidateTags.some((tag) => targetTags.includes(tag));
}

function bumpCacheTagVersions(tags: string[]): void {
	for (const tag of tags) {
		adminApiCacheTagVersions.set(tag, (adminApiCacheTagVersions.get(tag) ?? 0) + 1);
	}
}

function getCacheVersionSnapshot(tags: string[]): { global: number; tags: Map<string, number> } {
	return {
		global: adminApiGlobalCacheVersion,
		tags: new Map(tags.map((tag) => [tag, adminApiCacheTagVersions.get(tag) ?? 0]))
	};
}

function isCacheVersionSnapshotCurrent(snapshot: {
	global: number;
	tags: Map<string, number>;
}): boolean {
	if (snapshot.global !== adminApiGlobalCacheVersion) {
		return false;
	}

	for (const [tag, version] of snapshot.tags.entries()) {
		if ((adminApiCacheTagVersions.get(tag) ?? 0) !== version) {
			return false;
		}
	}

	return true;
}

export function clearSentientFormsApiCache(tags?: string[]): void {
	const normalizedTags = tags?.map((tag) => tag.trim()).filter(Boolean) ?? [];

	if (normalizedTags.length === 0) {
		adminApiMemoryCache.clear();
		adminApiInFlight.clear();
		adminApiCacheTagVersions.clear();
		adminApiGlobalCacheVersion += 1;
		clearSessionCache();
		return;
	}

	bumpCacheTagVersions(normalizedTags);

	for (const [key, entry] of adminApiMemoryCache.entries()) {
		if (hasAnyCacheTag(entry.tags, normalizedTags)) {
			adminApiMemoryCache.delete(key);
			deleteSessionCacheEntry(key);
		}
	}

	for (const [key, entry] of adminApiInFlight.entries()) {
		if (hasAnyCacheTag(entry.tags, normalizedTags)) {
			adminApiInFlight.delete(key);
		}
	}

	for (const key of listSessionCacheKeys()) {
		const entry = readSessionCacheEntry(key);
		if (entry && hasAnyCacheTag(entry.tags, normalizedTags)) {
			deleteSessionCacheEntry(key);
		}
	}
}

function readAdminApiCache(key: string, storage: 'memory' | 'session'): AdminApiCacheEntry | null {
	const now = Date.now();
	const memoryEntry = adminApiMemoryCache.get(key);
	if (memoryEntry) {
		if (memoryEntry.expiresAt > now) {
			return memoryEntry;
		}
		adminApiMemoryCache.delete(key);
		deleteSessionCacheEntry(key);
		return null;
	}

	if (storage !== 'session') {
		return null;
	}

	const sessionEntry = readSessionCacheEntry(key);
	if (!sessionEntry) {
		return null;
	}

	if (sessionEntry.expiresAt <= now) {
		deleteSessionCacheEntry(key);
		return null;
	}

	adminApiMemoryCache.set(key, sessionEntry);
	return sessionEntry;
}

function writeAdminApiCache(
	key: string,
	value: unknown,
	options: { ttlMs: number; tags: string[]; storage: 'memory' | 'session' }
): void {
	const entry: AdminApiCacheEntry = {
		expiresAt: Date.now() + options.ttlMs,
		tags: options.tags,
		value
	};

	adminApiMemoryCache.set(key, entry);

	if (options.storage === 'session') {
		writeSessionCacheEntry(key, entry);
	}
}

function retireAdminApiCacheKeyForForcedRefresh(key: string, tags: string[]): void {
	adminApiMemoryCache.delete(key);
	deleteSessionCacheEntry(key);
	adminApiInFlight.delete(key);

	if (tags.length > 0) {
		bumpCacheTagVersions(tags);
		return;
	}

	adminApiGlobalCacheVersion += 1;
}

function clearSessionCache(): void {
	for (const key of listSessionCacheKeys()) {
		deleteSessionCacheEntry(key);
	}
}

function listSessionCacheKeys(): string[] {
	const storage = getSessionStorage();
	if (!storage) {
		return [];
	}

	const keys: string[] = [];
	for (let index = 0; index < storage.length; index += 1) {
		const key = storage.key(index);
		if (key?.startsWith(sessionCachePrefix)) {
			keys.push(key.slice(sessionCachePrefix.length));
		}
	}
	return keys;
}

function readSessionCacheEntry(key: string): AdminApiCacheEntry | null {
	const storage = getSessionStorage();
	if (!storage) {
		return null;
	}

	const raw = storage.getItem(`${sessionCachePrefix}${key}`);
	if (!raw) {
		return null;
	}

	try {
		const parsed = JSON.parse(raw) as Partial<AdminApiCacheEntry>;
		if (typeof parsed.expiresAt === 'number' && Array.isArray(parsed.tags) && 'value' in parsed) {
			return {
				expiresAt: parsed.expiresAt,
				tags: parsed.tags.filter((tag): tag is string => typeof tag === 'string'),
				value: parsed.value
			};
		}
	} catch {
		deleteSessionCacheEntry(key);
	}

	return null;
}

function writeSessionCacheEntry(key: string, entry: AdminApiCacheEntry): void {
	const storage = getSessionStorage();
	if (!storage) {
		return;
	}

	try {
		storage.setItem(`${sessionCachePrefix}${key}`, JSON.stringify(entry));
	} catch {
		// Browsers may reject storage writes in private mode or when quota is full.
	}
}

function deleteSessionCacheEntry(key: string): void {
	const storage = getSessionStorage();
	if (!storage) {
		return;
	}

	try {
		storage.removeItem(`${sessionCachePrefix}${key}`);
	} catch {
		// Best-effort cleanup only.
	}
}

function getSessionStorage(): Storage | null {
	if (typeof window === 'undefined') {
		return null;
	}

	try {
		return window.sessionStorage ?? null;
	} catch {
		return null;
	}
}

export interface AsyncSettingsPayload {
	maxAttempts?: number;
	baseDelaySeconds?: number;
	maxDelaySeconds?: number;
}

export class ApiClientError extends Error {
	status: number;
	payload: unknown;
	code?: string;

	constructor(message: string, status: number, payload: unknown) {
		super(message);
		this.status = status;
		this.payload = payload;
		if (isApiErrorPayload(payload)) {
			const topLevelCode = typeof payload.error_code === 'string' ? payload.error_code.trim() : '';
			const nestedCode = typeof payload.error?.code === 'string' ? payload.error.code.trim() : '';
			if (topLevelCode.length > 0) {
				this.code = topLevelCode;
			} else if (nestedCode.length > 0) {
				this.code = nestedCode;
			}
		}
	}
}

export class SentientFormsApiClient {
	private baseUrl: URL;
	private getNonce?: () => string | undefined;
	private fetchImpl: typeof fetch;
	private notifyErrors: boolean;
	private cacheContext?: () => string | undefined;

	constructor(config: ClientConfig) {
		const fallbackOrigin =
			typeof window === 'undefined'
				? ['https:', '', 'sentientforms.invalid'].join('/')
				: window.location.origin;
		this.baseUrl = new URL(config.baseUrl, fallbackOrigin);
		this.getNonce = config.getNonce;
		this.fetchImpl = config.fetchImpl ?? fetch;
		this.notifyErrors = config.notifyErrors ?? true;
		this.cacheContext = config.cacheContext;
	}

	async activateLicense(
		payload: LicenseActivationRequest,
		options: RequestOptions = {}
	): Promise<LicenseActivationResult> {
		const response = await this.request<RestEnvelope<LicenseActivationResponsePayload>>(
			'license/activate',
			{
				method: 'POST',
				body: {
					license_key: payload.licenseKey,
					site_url: payload.siteUrl,
					local_site_identifier: payload.localSiteIdentifier
				},
				showNotifications: options.showNotifications
			}
		);

		const data = this.unwrap<LicenseActivationResponsePayload>(response);
		return {
			success: Boolean(data.success ?? true),
			message: String(data.message ?? 'License activated successfully.'),
			status: String(data.status ?? 'active'),
			proxyApiKey: typeof data.proxy_api_key === 'string' ? data.proxy_api_key : undefined,
			tier:
				typeof data.tier === 'string' || (data.tier && typeof data.tier === 'object')
					? data.tier
					: undefined,
			expiryDate: typeof data.expiry_date === 'string' ? data.expiry_date : undefined,
			licenseId: typeof data.license_id === 'string' ? data.license_id : undefined,
			siteId: typeof data.site_id === 'string' ? data.site_id : undefined
		};
	}

	async getLicenseInfo(options: RequestOptions = {}): Promise<LicenseInfoResponse> {
		const response = await this.request<RestEnvelope<LicenseInfoResponse>>(
			'license',
			withCacheDefaults(options, {
				ttlMs: 60_000,
				tags: ['license'],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async deactivateLicense(options: RequestOptions = {}): Promise<void> {
		await this.request('license/deactivate', { method: 'POST', ...options });
	}

	async bootstrapLicense(options: RequestOptions = {}): Promise<LicenseInfoResponse> {
		const response = await this.request<RestEnvelope<LicenseInfoResponse>>('license/bootstrap', {
			method: 'POST',
			...options
		});
		return this.unwrap(response);
	}

	async getBillingState(
		options: RequestOptions & { forceServerRefresh?: boolean } = {}
	): Promise<BillingStateResponse> {
		const { forceServerRefresh = false, ...requestOptions } = options;
		if (forceServerRefresh) {
			clearSentientFormsApiCache(['billing']);
		}
		const response = await this.request<RestEnvelope<BillingStateResponse>>(
			forceServerRefresh ? 'license/billing-state?force_refresh=1' : 'license/billing-state',
			withCacheDefaults(
				{ ...requestOptions, ...(forceServerRefresh ? { forceRefresh: true } : {}) },
				{
					ttlMs: 60_000,
					tags: ['license', 'billing'],
					storage: 'session'
				}
			)
		);
		return this.unwrap(response);
	}

	async createCheckoutSession(
		payload: BillingCheckoutSessionRequest,
		options: RequestOptions = {}
	): Promise<BillingCheckoutSessionResponse> {
		const response = await this.request<RestEnvelope<BillingCheckoutSessionResponse>>(
			'license/billing/checkout-session',
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return billingCheckoutSessionResponseSchema.parse(
			this.unwrap(response)
		) as BillingCheckoutSessionResponse;
	}

	async startManagedCheckout(
		payload: ManagedCheckoutStartRequest,
		options: RequestOptions = {}
	): Promise<ManagedCheckoutStartResponse> {
		const response = await this.request<RestEnvelope<unknown>>('license/managed-checkout/start', {
			method: 'POST',
			body: payload,
			...options
		});
		return managedCheckoutStartResponseSchema.parse(this.unwrap<unknown>(response));
	}

	async completeManagedCheckout(
		payload: ManagedCheckoutCompleteRequest,
		options: RequestOptions = {}
	): Promise<ManagedCheckoutCompleteResponse> {
		const response = await this.request<RestEnvelope<unknown>>(
			'license/managed-checkout/complete',
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return managedCheckoutCompleteResponseSchema.parse(this.unwrap<unknown>(response));
	}

	async createPortalSession(
		payload: BillingPortalSessionRequest,
		options: RequestOptions = {}
	): Promise<BillingPortalSessionResponse> {
		const response = await this.request<RestEnvelope<BillingPortalSessionResponse>>(
			'license/billing/portal-session',
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return billingPortalSessionResponseSchema.parse(
			this.unwrap(response)
		) as BillingPortalSessionResponse;
	}

	async createTopUpCheckoutSession(
		payload: TopUpCheckoutSessionRequest,
		options: RequestOptions = {}
	): Promise<TopUpCheckoutSessionResponse> {
		const response = await this.request<RestEnvelope<TopUpCheckoutSessionResponse>>(
			'license/billing/top-up-session',
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return topUpCheckoutSessionResponseSchema.parse(
			this.unwrap(response)
		) as TopUpCheckoutSessionResponse;
	}

	async getTelemetrySettings(options: RequestOptions = {}): Promise<TelemetrySettingsResponse> {
		const response = await this.request<RestEnvelope<TelemetrySettingsResponse>>(
			'telemetry',
			options
		);
		return this.unwrap(response);
	}

	async updateTelemetrySettings(
		optIn: boolean,
		options: RequestOptions = {}
	): Promise<TelemetrySettingsResponse> {
		const response = await this.request<RestEnvelope<TelemetrySettingsResponse>>('telemetry', {
			method: 'PUT',
			body: { telemetry_opt_in: optIn },
			...options
		});
		return this.unwrap(response);
	}

	async getAsyncSettings(options: RequestOptions = {}): Promise<AsyncSettingsResponse> {
		const response = await this.request<RestEnvelope<AsyncSettingsResponse>>(
			'async-settings',
			options
		);
		return this.unwrap(response);
	}

	async getSettings(options: RequestOptions = {}): Promise<PluginSettingsResponse> {
		const response = await this.request<RestEnvelope<PluginSettingsResponse>>(
			'settings',
			withCacheDefaults(options, {
				ttlMs: 60_000,
				tags: ['settings'],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async updateSettings(
		payload: Partial<PluginSettingsResponse>,
		options: RequestOptions = {}
	): Promise<PluginSettingsResponse> {
		const response = await this.request<
			RestEnvelope<PluginSettingsResponse | { settings: PluginSettingsResponse }>
		>('settings', {
			method: 'PUT',
			body: payload,
			...options
		});
		const data = this.unwrap<PluginSettingsResponse | { settings: PluginSettingsResponse }>(
			response
		);
		if (
			data &&
			typeof data === 'object' &&
			'settings' in data &&
			data.settings &&
			typeof data.settings === 'object'
		) {
			return data.settings;
		}

		return data as PluginSettingsResponse;
	}

	async updateAsyncSettings(
		payload: AsyncSettingsPayload,
		options: RequestOptions = {}
	): Promise<AsyncSettingsResponse> {
		const body: Record<string, number> = {};
		if (typeof payload.maxAttempts === 'number') {
			body.max_attempts = payload.maxAttempts;
		}
		if (typeof payload.baseDelaySeconds === 'number') {
			body.base_delay_seconds = payload.baseDelaySeconds;
		}
		if (typeof payload.maxDelaySeconds === 'number') {
			body.max_delay_seconds = payload.maxDelaySeconds;
		}

		const response = await this.request<RestEnvelope<AsyncSettingsResponse>>('async-settings', {
			method: 'PUT',
			body,
			...options
		});

		return this.unwrap(response);
	}

	async getAsyncHealth(options: RequestOptions = {}): Promise<AsyncHealthResponse> {
		const response = await this.request<RestEnvelope<AsyncHealthResponse>>(
			'async-health',
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: ['async-health']
			})
		);
		return this.unwrap(response);
	}

	/**
	 * Purge async jobs based on status and age filters.
	 * @param status - Comma-separated statuses to purge (default: 'queued,failed')
	 * @param olderThan - Purge jobs older than this many minutes (default: 10080 = 1 week)
	 * @param clearAll - If true, clear all job metadata
	 */
	async purgeAsyncJobs(
		options: {
			status?: string;
			olderThan?: number;
			clearAll?: boolean;
		} = {},
		requestOptions: RequestOptions = {}
	): Promise<{ removed: number; message: string }> {
		const params = new URLSearchParams();
		if (options.status) {
			params.set('status', options.status);
		}
		if (options.olderThan !== undefined) {
			params.set('older_than', String(options.olderThan));
		}
		if (options.clearAll) {
			params.set('clear_all', 'true');
		}

		const query = params.toString();
		const path = query ? `async-health?${query}` : 'async-health';
		const response = await this.request<RestEnvelope<{ removed: number; message: string }>>(path, {
			method: 'DELETE',
			...requestOptions
		});
		return this.unwrap(response);
	}

	async getLocalProviderCredentials(
		options: RequestOptions = {}
	): Promise<LocalProviderCredential[]> {
		return this.request<LocalProviderCredential[]>('local/providers/credentials', {
			cacheTtlMs: 60_000,
			cacheTags: ['providers'],
			cacheStorage: 'session',
			showNotifications: false,
			...options
		});
	}

	async deleteLocalProviderCredential(
		id: number,
		options: RequestOptions = {}
	): Promise<LocalProviderCredentialDeleteResponse> {
		return this.request<LocalProviderCredentialDeleteResponse>(
			`local/providers/credentials/${encodeURIComponent(String(id))}`,
			{
				method: 'DELETE',
				...options
			}
		);
	}

	async validateOpenRouterKey(
		payload: OpenRouterValidateRequest,
		options: RequestOptions = {}
	): Promise<OpenRouterValidateResponse> {
		return this.request<OpenRouterValidateResponse>('local/providers/openrouter/validate', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async saveOpenRouterConstant(
		payload: OpenRouterConstantRequest,
		options: RequestOptions = {}
	): Promise<OpenRouterValidateResponse> {
		return this.request<OpenRouterValidateResponse>('local/providers/openrouter/constant', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async setupSentientManagedProvider(
		payload: SentientManagedSetupRequest,
		options: RequestOptions = {}
	): Promise<SentientManagedSetupResponse> {
		return this.request<SentientManagedSetupResponse>('local/providers/sentient-managed/setup', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async revokeSentientManagedProvider(
		payload: SentientManagedRevokeRequest,
		options: RequestOptions = {}
	): Promise<SentientManagedRevokeResponse> {
		return this.request<SentientManagedRevokeResponse>('local/providers/sentient-managed/revoke', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async getOpenRouterModels(
		params: { freeOnly?: boolean; limit?: number } = {},
		options: RequestOptions = {}
	): Promise<OpenRouterModelsResponse> {
		const query = new URLSearchParams();
		if (params.freeOnly !== undefined) {
			query.set('free_only', String(params.freeOnly));
		}
		if (params.limit !== undefined) {
			query.set('limit', String(params.limit));
		}

		const suffix = query.toString();
		const path = suffix
			? `local/providers/openrouter/models?${suffix}`
			: 'local/providers/openrouter/models';

		const response = await this.request<OpenRouterModelsResponse>(path, {
			showNotifications: false,
			...options
		});
		return openRouterModelsResponseSchema.parse(response) as unknown as OpenRouterModelsResponse;
	}

	async refreshOpenRouterModels(
		payload: OpenRouterModelsRefreshRequest,
		options: RequestOptions = {}
	): Promise<OpenRouterModelsResponse> {
		const response = await this.request<OpenRouterModelsResponse>(
			'local/providers/openrouter/models/refresh',
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return openRouterModelsResponseSchema.parse(response) as unknown as OpenRouterModelsResponse;
	}

	async getLocalActionTemplates(options: RequestOptions = {}): Promise<LocalActionTemplate[]> {
		return this.request<LocalActionTemplate[]>('local/action-templates', {
			cacheTtlMs: 300_000,
			cacheTags: ['templates'],
			cacheStorage: 'session',
			showNotifications: false,
			...options
		});
	}

	async getLocalCustomActions(
		status = 'active',
		options: RequestOptions = {}
	): Promise<LocalCustomActionRecord[]> {
		const params = new URLSearchParams({ status });

		return this.request<LocalCustomActionRecord[]>(`local/custom-actions?${params}`, {
			cacheTtlMs: 60_000,
			cacheTags: ['actions', 'custom-actions'],
			cacheStorage: 'session',
			showNotifications: false,
			...options
		});
	}

	async createLocalCustomAction(
		payload: LocalCustomActionCreatePayload,
		options: RequestOptions = {}
	): Promise<LocalCustomActionRecord> {
		return this.request<LocalCustomActionRecord>('local/custom-actions', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async getLocalFormMappings(
		formSource: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<LocalFormMappingRecord[]> {
		const params = new URLSearchParams({
			form_source: formSource,
			form_id: String(formId)
		});

		return this.request<LocalFormMappingRecord[]>(`local/form-mappings?${params}`, {
			showNotifications: false,
			...options
		});
	}

	async createLocalFormMapping(
		payload: LocalFormMappingCreatePayload,
		options: RequestOptions = {}
	): Promise<LocalFormMappingRecord> {
		return this.request<LocalFormMappingRecord>('local/form-mappings', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async getLocalExecutionEvents(
		limit = 5,
		options: RequestOptions = {}
	): Promise<LocalExecutionEvent[]> {
		const params = new URLSearchParams({ limit: String(limit) });

		return this.request<LocalExecutionEvent[]>(`local/execution-events?${params}`, {
			cacheTtlMs: 30_000,
			cacheTags: ['execution-events', 'dashboard'],
			showNotifications: false,
			...options
		});
	}

	async getLeadProfile(
		formSource: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<LeadProfileResponse> {
		return this.request<LeadProfileResponse>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/profile`,
			{ showNotifications: false, ...options }
		);
	}

	async saveLeadProfile(
		formSource: string,
		formId: string | number,
		payload: LeadProfileSavePayload,
		options: RequestOptions = {}
	): Promise<LeadProfileResponse> {
		return this.request<LeadProfileResponse>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/profile`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
	}

	async generateLeadProfile(
		profileId: number,
		payload: LeadProfileGeneratePayload = {},
		options: RequestOptions = {}
	): Promise<LeadProfileResponse> {
		return this.request<LeadProfileResponse>(
			`lead-value/profiles/${encodeURIComponent(String(profileId))}/generate`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
	}

	async selfImproveLeadProfile(
		profileId: number,
		payload: { async?: boolean; force?: boolean } = {},
		options: RequestOptions = {}
	): Promise<LeadProfileResponse & { self_improvement?: Record<string, unknown> }> {
		return this.request<LeadProfileResponse & { self_improvement?: Record<string, unknown> }>(
			`lead-value/profiles/${encodeURIComponent(String(profileId))}/self-improve`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
	}

	async refreshLeadProfileAssistant(
		profileId: number,
		options: RequestOptions = {}
	): Promise<LeadProfileResponse & { assistant?: Record<string, unknown> }> {
		return this.request<LeadProfileResponse & { assistant?: Record<string, unknown> }>(
			`lead-value/profiles/${encodeURIComponent(String(profileId))}/assistant`,
			{
				method: 'POST',
				...options
			}
		);
	}

	async searchLeadValueEntries(
		formSource: string,
		formId: string | number,
		params: { q?: string; limit?: number } = {},
		options: RequestOptions = {}
	): Promise<LeadValueEntrySearchResponse> {
		const query = new URLSearchParams();
		if (params.q) query.set('q', params.q);
		if (params.limit) query.set('limit', String(params.limit));
		const suffix = query.toString() ? `?${query}` : '';
		return this.request<LeadValueEntrySearchResponse>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/entries/search${suffix}`,
			{ showNotifications: false, ...options }
		);
	}

	async searchSpamGuidanceEntries(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions & {
			q?: string;
			limit?: number;
			status?: SpamGuidanceEntryStatusFilter;
		} = {}
	): Promise<SpamGuidanceEntrySearchResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const params = new URLSearchParams();
		if (options.q) params.set('q', options.q);
		if (typeof options.limit === 'number') params.set('limit', String(options.limit));
		if (options.status) params.set('status', options.status);
		const { q: _q, limit: _limit, status: _status, ...requestOptions } = options;
		const response = await this.request<RestEnvelope<unknown>>(
			`spam-guidance/forms/${slug}/${formIdSegment}/entries/search${params.toString() ? `?${params}` : ''}`,
			withCacheDefaults(
				{ showNotifications: false, ...requestOptions },
				{
					ttlMs: 15_000,
					tags: ['spam-guidance', 'submission-ledger', formCacheTag(formSourceSlug, formId)]
				}
			)
		);
		return spamGuidanceEntrySearchResponseSchema.parse(
			this.unwrap(response)
		) as SpamGuidanceEntrySearchResponse;
	}

	async appendSpamGuidanceExample(
		formSourceSlug: string,
		formId: FormSourceFormId,
		payload: SpamGuidanceExampleAppendPayload,
		options: RequestOptions = {}
	): Promise<SpamGuidanceExampleAppendResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<unknown>>(
			`spam-guidance/forms/${slug}/${formIdSegment}/examples`,
			{
				method: 'POST',
				body: payload,
				invalidateCacheTags: [
					'spam-guidance',
					'action-defaults',
					'form-actions',
					formCacheTag(formSourceSlug, formId)
				],
				...options
			}
		);
		return spamGuidanceAppendResponseSchema.parse(
			this.unwrap(response)
		) as SpamGuidanceExampleAppendResponse;
	}

	async correctLeadScoringEntry(
		formSource: string,
		formId: string | number,
		entryId: string | number,
		payload: LeadScoringCorrectionPayload,
		options: RequestOptions = {}
	): Promise<LeadProfileResponse & { entry?: unknown; dashboard?: LeadValueDashboard }> {
		return this.request<LeadProfileResponse & { entry?: unknown; dashboard?: LeadValueDashboard }>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/entries/${encodeURIComponent(String(entryId))}/correction`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
	}

	async generateLeadSuggestedReply(
		formSource: string,
		formId: string | number,
		entryId: string | number,
		options: RequestOptions = {}
	): Promise<{ execution?: unknown; entry?: unknown; dashboard?: LeadValueDashboard }> {
		return this.request<{ execution?: unknown; entry?: unknown; dashboard?: LeadValueDashboard }>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/entries/${encodeURIComponent(String(entryId))}/suggested-reply`,
			{
				method: 'POST',
				...options
			}
		);
	}

	async getLeadValueDashboard(
		formSource: string,
		formId: string | number,
		params: { page?: number; per_page?: number; q?: string } = {},
		options: RequestOptions = {}
	): Promise<LeadValueDashboard> {
		const query = new URLSearchParams();
		if (params.page) query.set('page', String(params.page));
		if (params.per_page) query.set('per_page', String(params.per_page));
		if (params.q) query.set('q', params.q);
		const suffix = query.toString() ? `?${query}` : '';
		return this.request<LeadValueDashboard>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/dashboard${suffix}`,
			{ showNotifications: false, ...options }
		);
	}

	async getLeadScoringDashboard(
		params: { page?: number; per_page?: number; q?: string } = {},
		options: RequestOptions = {}
	): Promise<LeadValueDashboard> {
		const query = new URLSearchParams();
		if (params.page) query.set('page', String(params.page));
		if (params.per_page) query.set('per_page', String(params.per_page));
		if (params.q) query.set('q', params.q);
		const suffix = query.toString() ? `?${query}` : '';
		return this.request<LeadValueDashboard>(`lead-value/dashboard${suffix}`, {
			showNotifications: false,
			...options
		});
	}

	async importLeadProfile(
		formSource: string,
		formId: string | number,
		payload: { source_profile_id: number; include_examples?: boolean },
		options: RequestOptions = {}
	): Promise<LeadProfileResponse> {
		return this.request<LeadProfileResponse>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/profile/import`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
	}

	async listLeadValueHistoricalRuns(
		formSource: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<{ runs: LeadValueHistoricalRunResponse['run'][] }> {
		return this.request<{ runs: LeadValueHistoricalRunResponse['run'][] }>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/historical-runs`,
			{ showNotifications: false, ...options }
		);
	}

	async createLeadValueHistoricalRun(
		formSource: string,
		formId: string | number,
		payload: LeadValueHistoricalRunCreatePayload,
		options: RequestOptions = {}
	): Promise<LeadValueHistoricalRunResponse> {
		return this.request<LeadValueHistoricalRunResponse>(
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/historical-runs`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
	}

	async startLeadValueHistoricalRun(
		runId: number,
		payload: { confirm_costs?: boolean } = {},
		options: RequestOptions = {}
	): Promise<LeadValueHistoricalRunResponse> {
		return this.request<LeadValueHistoricalRunResponse>(
			`lead-value/historical-runs/${encodeURIComponent(String(runId))}/start`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
	}

	async getLocalSupportBundle(options: RequestOptions = {}): Promise<LocalSupportBundle> {
		return this.request<LocalSupportBundle>('local/support-bundle', {
			showNotifications: false,
			...options
		});
	}

	async getDashboardSummary(options: RequestOptions = {}): Promise<DashboardSummaryResponse> {
		const response = await this.request<RestEnvelope<DashboardSummaryResponse>>(
			'admin/dashboard-summary',
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: ['dashboard', 'providers', 'actions', 'execution-events', 'license']
			})
		);
		return dashboardSummaryResponseSchema.parse(
			this.unwrap(response)
		) as unknown as DashboardSummaryResponse;
	}

	async getLocalMigrationReadiness(
		options: RequestOptions = {}
	): Promise<LocalMigrationReadinessReport> {
		return this.request<LocalMigrationReadinessReport>('local/migration/readiness', {
			showNotifications: false,
			...options
		});
	}

	async createLocalMigrationDryRun(
		options: RequestOptions = {}
	): Promise<LocalMigrationDryRunResponse> {
		return this.request<LocalMigrationDryRunResponse>('local/migration/dry-run', {
			method: 'POST',
			...options
		});
	}

	async createLocalMigrationImportDryRun(
		payload: LocalMigrationImportRequest,
		options: RequestOptions = {}
	): Promise<LocalMigrationImportDryRunResponse> {
		const response = await this.request<LocalMigrationImportDryRunResponse>(
			'local/migration/import/dry-run',
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return localMigrationImportDryRunResponseSchema.parse(
			response
		) as LocalMigrationImportDryRunResponse;
	}

	async runLocalMigrationImportApply(
		payload: LocalMigrationImportRequest,
		options: RequestOptions = {}
	): Promise<LocalMigrationImportApplyResponse> {
		const response = await this.request<LocalMigrationImportApplyResponse>(
			'local/migration/import/apply',
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return localMigrationImportApplyResponseSchema.parse(
			response
		) as LocalMigrationImportApplyResponse;
	}

	async runLocalMigrationApprovedReset(
		payload: LocalMigrationApprovedResetRequest,
		options: RequestOptions = {}
	): Promise<LocalMigrationApprovedResetResponse> {
		return this.request<LocalMigrationApprovedResetResponse>('local/migration/approved-reset', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async getActionDefinitions(options: RequestOptions = {}): Promise<ActionDefinition[]> {
		const response = await this.request<RestEnvelope<ActionDefinition[]>>(
			'actions/definitions',
			withCacheDefaults(options, {
				ttlMs: 300_000,
				tags: ['actions', 'definitions'],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async getForms(formSourceSlug: string, options: RequestOptions = {}): Promise<FormSummary[]> {
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<FormSummary[]>>(
			`${slug}/forms`,
			withCacheDefaults(options, {
				ttlMs: 60_000,
				tags: ['forms', `forms:${formSourceSlug}`],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async getFormsOverview(
		formSourceSlug: string,
		options: RequestOptions = {}
	): Promise<FormsOverviewResponse> {
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.request<RestEnvelope<FormsOverviewResponse>>(
			`${slug}/forms/overview`,
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: [
					'forms',
					'actions',
					'form-actions',
					'custom-actions',
					'execution-status',
					`forms:${formSourceSlug}`
				]
			})
		);
		return this.unwrap(response);
	}

	async getFormActionsBootstrap(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<FormActionsBootstrapResponse> {
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] getFormActionsBootstrap called with invalid params:', {
				formSourceSlug,
				formId
			});
			return {
				form_source: formSourceSlug,
				form_id: formId,
				actions: [],
				execution_status: {
					status: 'unknown',
					message: 'Page loading...',
					updated_at: null,
					entry_id: null,
					last_error_code: null,
					last_result: null
				},
				disabled_state: {
					sf_disabled: false,
					global_disabled: false,
					provider_disabled: false,
					effective_disabled: false
				},
				generated_at: new Date().toISOString()
			};
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormActionsBootstrapResponse>>(
			`${slug}/forms/${formIdSegment}/actions/bootstrap`,
			withCacheDefaults(options, {
				ttlMs: 15_000,
				tags: [
					'form-actions',
					'execution-status',
					'submission-ledger',
					'settings',
					'providers',
					'custom-actions',
					'action-defaults',
					formCacheTag(formSourceSlug, formId)
				]
			})
		);
		const data = formActionsBootstrapResponseSchema.parse(
			this.unwrap(response)
		) as unknown as FormActionsBootstrapResponse;
		return {
			...data,
			provider_path_policy: parseProviderPathPolicy(data.provider_path_policy)
		};
	}

	async getSubmissionLedgerSettings(
		formSourceSlug: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<SubmissionLedgerSettingsResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<SubmissionLedgerSettingsResponse>>(
			`${slug}/forms/${formIdSegment}/ledger-settings`,
			withCacheDefaults(options, {
				ttlMs: 15_000,
				tags: ['submission-ledger', 'settings', formCacheTag(formSourceSlug, formId)]
			})
		);
		return submissionLedgerSettingsResponseSchema.parse(
			this.unwrap(response)
		) as SubmissionLedgerSettingsResponse;
	}

	async updateSubmissionLedgerSettings(
		formSourceSlug: string,
		formId: string | number,
		enabled: boolean,
		options: RequestOptions = {}
	): Promise<SubmissionLedgerSettingsResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<SubmissionLedgerSettingsResponse>>(
			`${slug}/forms/${formIdSegment}/ledger-settings`,
			{
				method: 'PUT',
				body: { enabled },
				...options
			}
		);
		return submissionLedgerSettingsResponseSchema.parse(
			this.unwrap(response)
		) as SubmissionLedgerSettingsResponse;
	}

	async getSubmissionLedgerRecords(
		formSourceSlug: string,
		formId: string | number,
		options: SubmissionLedgerRecordsRequestOptions = {}
	): Promise<SubmissionLedgerRecordsResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const params = new URLSearchParams();
		if (typeof options.perPage === 'number') {
			params.set('per_page', String(options.perPage));
		}
		if (typeof options.offset === 'number') {
			params.set('offset', String(options.offset));
		}
		if (options.q?.trim()) {
			params.set('q', options.q.trim());
		}
		if (options.nativeEntry?.trim()) {
			params.set('native_entry', options.nativeEntry.trim());
		}
		if (options.capturedFrom?.trim()) {
			params.set('captured_from', options.capturedFrom.trim());
		}
		if (options.capturedTo?.trim()) {
			params.set('captured_to', options.capturedTo.trim());
		}
		if (typeof options.hasFiles === 'boolean') {
			params.set('has_files', String(options.hasFiles));
		}
		if (options.sort?.trim()) {
			params.set('sort', options.sort.trim());
		}
		const query = params.toString();
		const {
			perPage: _perPage,
			offset: _offset,
			q: _q,
			nativeEntry: _nativeEntry,
			capturedFrom: _capturedFrom,
			capturedTo: _capturedTo,
			hasFiles: _hasFiles,
			sort: _sort,
			...requestOptions
		} = options;
		const response = await this.request<RestEnvelope<unknown>>(
			`${slug}/forms/${formIdSegment}/submissions${query ? `?${query}` : ''}`,
			withCacheDefaults(requestOptions, {
				ttlMs: 15_000,
				tags: ['submission-ledger', formCacheTag(formSourceSlug, formId)]
			})
		);
		return submissionLedgerRecordsResponseSchema.parse(
			this.unwrap(response)
		) as SubmissionLedgerRecordsResponse;
	}

	async getSubmissionLedgerRecord(
		formSourceSlug: string,
		formId: string | number,
		submissionUuid: string,
		options: RequestOptions = {}
	): Promise<SubmissionLedgerRecord> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const uuid = encodeURIComponent(submissionUuid);
		const response = await this.request<RestEnvelope<SubmissionLedgerRecord>>(
			`${slug}/forms/${formIdSegment}/submissions/${uuid}`,
			withCacheDefaults(options, {
				ttlMs: 15_000,
				tags: ['submission-ledger', formCacheTag(formSourceSlug, formId)]
			})
		);
		return submissionLedgerRecordSchema.parse(this.unwrap(response)) as SubmissionLedgerRecord;
	}

	async getFormActions(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<FormActionLinkage[]> {
		// Guard against undefined parameters during hydration race conditions
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] getFormActions called with invalid params:', {
				formSourceSlug,
				formId
			});
			return [];
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormActionLinkage[]>>(
			`${slug}/forms/${formIdSegment}/actions`,
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: ['actions', 'form-actions', formCacheTag(formSourceSlug, formId)]
			})
		);
		return this.unwrap(response);
	}

	async getWorkflowPlan(
		formSourceSlug: string,
		formId: FormSourceFormId,
		hookScope: 'all' | string = 'all',
		options: RequestOptions = {}
	): Promise<WorkflowPlanResponse> {
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] getWorkflowPlan called with invalid params:', {
				formSourceSlug,
				formId,
				hookScope
			});
			return {
				authority: 'local',
				authority_reason: 'invalid_request',
				cps_unreachable: true,
				policy_version: '2026-02-mixed-sync-async-v1',
				hook_scope: hookScope,
				available_hooks: [],
				nodes: [],
				edges: [],
				hooks: [],
				policy_violations: []
			};
		}

		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const scope = encodeURIComponent(hookScope);
		const response = await this.request<RestEnvelope<WorkflowPlanResponse>>(
			`${slug}/forms/${formIdSegment}/actions/workflow-plan?hook_scope=${scope}`,
			options
		);
		return this.unwrap(response);
	}

	async runRequestTrace(
		formSourceSlug: string,
		formId: FormSourceFormId,
		payload: RequestTraceRequest,
		options: RequestOptions = {}
	): Promise<RequestTraceResponse> {
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] runRequestTrace called with invalid params:', {
				formSourceSlug,
				formId
			});
			return {
				authority: 'wp_rest',
				policy_version: '2026-02-request-tracer-v1',
				hook_scope: payload.hook_scope ?? 'all',
				available_hooks: [],
				input: {
					source: 'empty',
					entry_id: null,
					field_scope: 'mapped_and_rule',
					values: {},
					manual_field_ids: [],
					imported_field_ids: [],
					overridden_field_ids: [],
					warnings: ['Invalid form context for request trace.'],
					include_drafts: Boolean(payload.include_drafts),
					draft_applied: false
				},
				hooks: [],
				policy_violations: []
			};
		}

		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<RequestTraceResponse>>(
			`${slug}/forms/${formIdSegment}/actions/request-trace`,
			{
				method: 'POST',
				body: payload,
				...options
			}
		);
		return this.unwrap(response);
	}

	/**
	 * CB-FORMS-001: Get the per-form disabled state.
	 */
	async getFormDisabled(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<FormDisableStateResponse> {
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			return {
				sf_disabled: false,
				global_disabled: false,
				provider_disabled: false,
				effective_disabled: false
			};
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormDisableStateResponse>>(
			`${slug}/forms/${formIdSegment}/actions/disable`,
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: ['settings', 'form-actions', formCacheTag(formSourceSlug, formId)]
			})
		);
		return this.unwrap(response);
	}

	/**
	 * CB-FORMS-001: Toggle the per-form disabled state.
	 */
	async toggleFormDisabled(
		formSourceSlug: string,
		formId: FormSourceFormId,
		disabled: boolean,
		options: RequestOptions = {}
	): Promise<FormDisableStateResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormDisableStateResponse>>(
			`${slug}/forms/${formIdSegment}/actions/disable`,
			{
				...options,
				method: 'PUT',
				body: { sf_disabled: disabled }
			}
		);
		return this.unwrap(response);
	}

	/**
	 * CA-MAP-001: Get form fields for FieldSelector component.
	 * Returns field metadata (id, label, type, adminLabel) filtered to user-input fields.
	 */
	async getFormFields(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<FormFieldInfo[]> {
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] getFormFields called with invalid params:', {
				formSourceSlug,
				formId
			});
			return [];
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormFieldInfo[]>>(
			`${slug}/forms/${formIdSegment}/actions/fields`,
			withCacheDefaults(options, {
				ttlMs: 300_000,
				tags: ['forms', formCacheTag(formSourceSlug, formId)],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async getFormExecutionStatus(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<FormExecutionStatus> {
		// Guard against undefined parameters during hydration race conditions
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] getFormExecutionStatus called with invalid params:', {
				formSourceSlug,
				formId
			});
			return {
				status: 'unknown',
				message: 'Page loading...',
				updated_at: null,
				entry_id: null,
				last_error_code: null,
				last_result: null
			};
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormExecutionStatus>>(
			`${slug}/forms/${formIdSegment}/actions/status`,
			withCacheDefaults(options, {
				ttlMs: 15_000,
				tags: ['execution-status', formCacheTag(formSourceSlug, formId)]
			})
		);
		return this.unwrap(response);
	}

	async getCapabilities(options: RequestOptions = {}): Promise<CapabilitiesResponse> {
		const response = await this.request<RestEnvelope<CapabilitiesResponse>>('meta/capabilities', {
			cacheTtlMs: 300_000,
			cacheTags: ['meta', 'capabilities'],
			cacheStorage: 'session',
			...options,
			showNotifications: false
		});
		return this.unwrap(response);
	}

	async createFormAction(
		formSourceSlug: string,
		formId: FormSourceFormId,
		payload: FormActionMutationPayload,
		options: RequestOptions = {}
	): Promise<FormActionLinkage> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormActionLinkage>>(
			`${slug}/forms/${formIdSegment}/actions`,
			{ method: 'POST', body: payload, ...options }
		);
		return this.unwrap(response);
	}

	async duplicateFormAction(
		formSourceSlug: string,
		formId: FormSourceFormId,
		localMappingId: string,
		payload: DuplicateFormActionRequest,
		options: RequestOptions = {}
	): Promise<DuplicateFormActionResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<DuplicateFormActionResponse>>(
			`${slug}/forms/${formIdSegment}/actions/${encodeURIComponent(localMappingId)}/duplicate`,
			{ method: 'POST', body: payload, ...options }
		);
		return this.unwrap(response);
	}

	async updateFormAction(
		formSourceSlug: string,
		formId: FormSourceFormId,
		localMappingId: string,
		payload: FormActionMutationPayload,
		options: RequestOptions = {}
	): Promise<FormActionLinkage> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormActionLinkage>>(
			`${slug}/forms/${formIdSegment}/actions/${encodeURIComponent(localMappingId)}`,
			{ method: 'PUT', body: payload, ...options }
		);
		return this.unwrap(response);
	}

	async deleteFormAction(
		formSourceSlug: string,
		formId: FormSourceFormId,
		localMappingId: string,
		options: RequestOptions = {}
	): Promise<void> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		await this.request(
			`${slug}/forms/${formIdSegment}/actions/${encodeURIComponent(localMappingId)}`,
			{
				method: 'DELETE',
				...options
			}
		);
	}

	// ==========================================================================
	// Form-Level Action Configuration (Hierarchical Examples Storage)
	// ==========================================================================

	/**
	 * Get all form-level action configs for a form.
	 * These configs persist at the form level, surviving action mapping deletion.
	 */
	async getFormActionConfigs(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<Record<string, FormActionConfig>> {
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] getFormActionConfigs called with invalid params:', {
				formSourceSlug,
				formId
			});
			return {};
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormAllActionConfigsResponse>>(
			`forms/${slug}/${formIdSegment}/action-config`,
			{ showNotifications: false, ...options }
		);
		return this.unwrap<FormAllActionConfigsResponse>(response).configs;
	}

	/**
	 * Get form-level config for a specific action on a form.
	 */
	async getFormActionConfig(
		formSourceSlug: string,
		formId: FormSourceFormId,
		actionId: string,
		options: RequestOptions = {}
	): Promise<FormActionConfig> {
		if (isInvalidFormSourceContext(formSourceSlug, formId) || !actionId) {
			console.warn('[ApiClient] getFormActionConfig called with invalid params:', {
				formSourceSlug,
				formId,
				actionId
			});
			return {};
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<FormActionConfigResponse>>(
			`forms/${slug}/${formIdSegment}/action-config/${encodeURIComponent(actionId)}`,
			{ showNotifications: false, ...options }
		);
		return this.unwrap<FormActionConfigResponse>(response).config;
	}

	/**
	 * Update form-level config for a specific action on a form.
	 * These settings act as defaults for all mappings of this action on this form.
	 */
	async updateFormActionConfig(
		formSourceSlug: string,
		formId: FormSourceFormId,
		actionId: string,
		config: Partial<FormActionConfig>,
		options: RequestOptions = {}
	): Promise<FormActionConfig> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const payload = this.validateFormActionConfigPayload(config);
		const response = await this.request<RestEnvelope<FormActionConfigResponse>>(
			`forms/${slug}/${formIdSegment}/action-config/${encodeURIComponent(actionId)}`,
			{ method: 'POST', body: payload, ...options }
		);
		return this.unwrap<FormActionConfigResponse>(response).config;
	}

	/**
	 * Delete form-level config for a specific action on a form.
	 */
	async deleteFormActionConfig(
		formSourceSlug: string,
		formId: FormSourceFormId,
		actionId: string,
		options: RequestOptions = {}
	): Promise<void> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		await this.request(
			`forms/${slug}/${formIdSegment}/action-config/${encodeURIComponent(actionId)}`,
			{
				method: 'DELETE',
				...options
			}
		);
	}

	// ============================================================
	// Global Action Defaults (top of hierarchy)
	// ============================================================

	/**
	 * Get global defaults for a specific action (applies across all forms).
	 */
	async getActionDefaults(
		actionId: string,
		options: RequestOptions = {}
	): Promise<FormActionConfig> {
		if (!actionId) {
			console.warn('[ApiClient] getActionDefaults called without actionId');
			return {};
		}
		const response = await this.request<RestEnvelope<FormActionConfigResponse>>(
			`actions/${encodeURIComponent(actionId)}/defaults`,
			withCacheDefaults(
				{ showNotifications: false, ...options },
				{
					ttlMs: 300_000,
					tags: ['action-defaults'],
					storage: 'session'
				}
			)
		);
		return this.unwrap<FormActionConfigResponse>(response).config;
	}

	/**
	 * Get global defaults for multiple actions in one request.
	 */
	async getActionDefaultsBatch(
		actionIds: string[],
		options: RequestOptions = {}
	): Promise<Record<string, FormActionConfig>> {
		const ids = [...new Set(actionIds.map((id) => id.trim()).filter(Boolean))].sort();
		if (ids.length === 0) {
			return {};
		}

		const defaults: Record<string, FormActionConfig> = {};
		for (let index = 0; index < ids.length; index += ACTION_DEFAULTS_BATCH_LIMIT) {
			const batchIds = ids.slice(index, index + ACTION_DEFAULTS_BATCH_LIMIT);
			const response = await this.request<RestEnvelope<ActionDefaultsBatchResponse>>(
				`actions/defaults?ids=${encodeURIComponent(batchIds.join(','))}`,
				withCacheDefaults(
					{ showNotifications: false, ...options },
					{
						ttlMs: 300_000,
						tags: ['action-defaults'],
						storage: 'session'
					}
				)
			);
			Object.assign(defaults, this.unwrap<ActionDefaultsBatchResponse>(response).defaults ?? {});
		}
		return defaults;
	}

	/**
	 * Update global defaults for a specific action.
	 * These settings act as the base defaults for all forms unless overridden.
	 */
	async updateActionDefaults(
		actionId: string,
		config: Partial<FormActionConfig>,
		options: RequestOptions = {}
	): Promise<FormActionConfig> {
		const payload = this.validateFormActionConfigPayload(config);
		const response = await this.request<RestEnvelope<FormActionConfigResponse>>(
			`actions/${encodeURIComponent(actionId)}/defaults`,
			{ method: 'POST', body: payload, ...options }
		);
		return this.unwrap<FormActionConfigResponse>(response).config;
	}

	private validateFormActionConfigPayload(
		config: Partial<FormActionConfig>
	): Partial<FormActionConfig> {
		const result = safeParseFormActionConfigPayload(config);
		if (result.success === false) {
			const message = result.error.issues
				.map((issue) => `${issue.path.join('.') || 'config'}: ${issue.message}`)
				.join('; ');
			throw new Error(`Invalid action configuration: ${message}`);
		}

		return result.data as Partial<FormActionConfig>;
	}

	async getCustomActions(
		filters: CustomActionFilters = {},
		options: RequestOptions = {}
	): Promise<{ actions: CustomAction[]; quota: CustomActionQuota }> {
		const params = new URLSearchParams();
		if (filters.status) {
			params.set('status', filters.status);
		}
		if (filters.include_archived) {
			params.set('include_archived', 'true');
		}
		if (filters.template_id) {
			params.set('template_id', filters.template_id);
		}

		const query = params.toString();
		const path = query ? `custom-actions?${query}` : 'custom-actions';
		return this.request(path, { showNotifications: false, ...options });
	}

	async createCustomAction(
		payload: CustomActionCreatePayload,
		options: RequestOptions = {}
	): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request('custom-actions', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async updateCustomAction(
		id: string,
		payload: CustomActionUpdatePayload,
		options: RequestOptions = {}
	): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request(`custom-actions/${encodeURIComponent(id)}`, {
			method: 'PUT',
			body: payload,
			...options
		});
	}

	async archiveCustomAction(
		id: string,
		options: RequestOptions = {}
	): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request(`custom-actions/${encodeURIComponent(id)}`, {
			method: 'DELETE',
			...options
		});
	}

	async reactivateCustomAction(
		id: string,
		options: RequestOptions = {}
	): Promise<{ action: CustomAction; quota: CustomActionQuota }> {
		return this.request(`custom-actions/${encodeURIComponent(id)}/reactivate`, {
			method: 'POST',
			...options
		});
	}

	// ==========================================================================
	// Phase 7: Form Mappings (CSM - Cross-Site Mapping Portability)
	// ==========================================================================

	/**
	 * Get all form mappings for the current license.
	 * CSM-001: local mapping storage
	 */
	async getFormMappings(options: RequestOptions = {}): Promise<FormMapping[]> {
		const response = await this.request<{ success: boolean; data: FormMapping[] }>('mappings', {
			showNotifications: false,
			...options
		});
		return response.data;
	}

	/**
	 * Get template mappings only (reusable across sites).
	 * CSM-003: Save as Template
	 */
	async getFormMappingTemplates(options: RequestOptions = {}): Promise<FormMapping[]> {
		const response = await this.request<{ success: boolean; data: FormMapping[] }>(
			'mappings/templates',
			{ showNotifications: false, ...options }
		);
		return response.data;
	}

	/**
	 * Get a single form mapping by ID.
	 */
	async getFormMapping(id: string, options: RequestOptions = {}): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>(
			`mappings/${encodeURIComponent(id)}`,
			{ showNotifications: false, ...options }
		);
		return response.data;
	}

	/**
	 * Create a new form mapping.
	 * CSM-001: local mapping storage
	 */
	async createFormMapping(
		payload: CreateFormMappingRequest,
		options: RequestOptions = {}
	): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>('mappings', {
			method: 'POST',
			body: payload,
			...options
		});
		return response.data;
	}

	/**
	 * Update an existing form mapping.
	 */
	async updateFormMapping(
		id: string,
		payload: UpdateFormMappingRequest,
		options: RequestOptions = {}
	): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>(
			`mappings/${encodeURIComponent(id)}`,
			{ method: 'PUT', body: payload, ...options }
		);
		return response.data;
	}

	/**
	 * Delete a form mapping.
	 */
	async deleteFormMapping(id: string, options: RequestOptions = {}): Promise<void> {
		await this.request(`mappings/${encodeURIComponent(id)}`, {
			method: 'DELETE',
			...options
		});
	}

	/**
	 * Clone a template mapping to a specific site and form.
	 * CSM-004: Import from Library
	 */
	async cloneFormMappingTemplate(
		templateId: string,
		payload: CloneTemplateMappingRequest,
		options: RequestOptions = {}
	): Promise<FormMapping> {
		const response = await this.request<{ success: boolean; data: FormMapping }>(
			`mappings/${encodeURIComponent(templateId)}/clone`,
			{ method: 'POST', body: payload, ...options }
		);
		return response.data;
	}

	async getExecutionStatus(
		formSourceSlug: string,
		formId: FormSourceFormId,
		entryId: number,
		options: RequestOptions = {}
	): Promise<ExecutionStatus> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.request<RestEnvelope<ExecutionStatus>>(
			`${slug}/forms/${formIdSegment}/actions/entries/${entryId}/status`,
			options
		);
		return this.unwrap(response);
	}

	async request<T>(path: string, options: RequestOptions = {}): Promise<T> {
		let url: URL;
		const base = new URL(this.baseUrl.toString());
		const restRoute = base.searchParams.get('rest_route');

		if (restRoute) {
			const [rawPath, rawQuery] = path.split('?');
			const normalizedRoute = restRoute.replace(/\/+$/, '');
			const normalizedPath = rawPath.replace(/^\/+/, '');
			base.searchParams.set(
				'rest_route',
				`${normalizedRoute}/${normalizedPath}`.replace(/\/{2,}/g, '/')
			);

			if (rawQuery) {
				const extra = new URLSearchParams(rawQuery);
				extra.forEach((value, key) => {
					base.searchParams.set(key, value);
				});
			}

			url = base;
		} else {
			url = new URL(path, base);
		}

		const {
			body,
			headers,
			showNotifications,
			cacheTtlMs = 0,
			cacheTags = [],
			cacheStorage = 'memory',
			forceRefresh = false,
			dedupe = true,
			invalidateCacheTags,
			...rest
		} = options;
		const nonce = this.getNonce?.();
		const method = String(rest.method ?? 'GET').toUpperCase();
		const canUseCache = method === 'GET' && body === undefined && cacheTtlMs > 0;
		const cacheKey = canUseCache ? this.buildCacheKey(url) : null;
		const normalizedCacheTags = canUseCache ? uniqueCacheTags(cacheTags) : [];
		if (cacheKey && forceRefresh) {
			retireAdminApiCacheKeyForForcedRefresh(cacheKey, normalizedCacheTags);
		}
		const cacheVersionSnapshot = cacheKey ? getCacheVersionSnapshot(normalizedCacheTags) : null;

		if (cacheKey && !forceRefresh) {
			const cached = readAdminApiCache(cacheKey, cacheStorage);
			if (cached) {
				return cached.value as T;
			}

			const inFlight = adminApiInFlight.get(cacheKey);
			if (dedupe && inFlight) {
				inFlight.showNotifications = mergeShowNotifications(
					inFlight.showNotifications,
					showNotifications
				);
				try {
					return (await inFlight.promise) as T;
				} catch (error) {
					throw coerceToApiClientError(error);
				}
			}
		}

		let parsed: unknown;

		const requestPromise = (async () => {
			const response = await this.fetchImpl(url.toString(), {
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					...(nonce ? { 'X-WP-Nonce': nonce } : {}),
					...(headers as Record<string, string>)
				},
				body: body ? JSON.stringify(body) : undefined,
				...rest
			});

			parsed = await this.parseResponseBody(response, url.toString());

			if (!response.ok) {
				const securityRoadblock = classifySecurityRoadblock(response, parsed);
				if (securityRoadblock) {
					throw new ApiClientError(securityRoadblock.message, response.status, securityRoadblock);
				}
				throw new ApiClientError('Request failed', response.status, parsed);
			}

			if (cacheKey && cacheVersionSnapshot && isCacheVersionSnapshotCurrent(cacheVersionSnapshot)) {
				writeAdminApiCache(cacheKey, parsed, {
					ttlMs: cacheTtlMs,
					tags: normalizedCacheTags,
					storage: cacheStorage
				});
			} else if (method !== 'GET') {
				const tagsToInvalidate = getMutationInvalidationTags(path, invalidateCacheTags);
				if (tagsToInvalidate && tagsToInvalidate.length > 0) {
					clearSentientFormsApiCache(tagsToInvalidate);
				}
			}

			if (response.status === 204) {
				return undefined as T;
			}

			return parsed as T;
		})();

		if (cacheKey && dedupe) {
			adminApiInFlight.set(cacheKey, {
				promise: requestPromise as Promise<unknown>,
				showNotifications,
				tags: normalizedCacheTags
			});
		}

		try {
			return await requestPromise;
		} catch (error) {
			const currentInFlightEntry = cacheKey && dedupe ? adminApiInFlight.get(cacheKey) : null;
			const effectiveShowNotifications =
				currentInFlightEntry?.promise === requestPromise
					? currentInFlightEntry.showNotifications
					: showNotifications;
			this.handleRequestError(error, parsed, effectiveShowNotifications);
		} finally {
			if (cacheKey && adminApiInFlight.get(cacheKey)?.promise === requestPromise) {
				adminApiInFlight.delete(cacheKey);
			}
		}
	}

	private handleRequestError(error: unknown, parsed: unknown, showNotifications?: boolean): never {
		const clientError =
			error instanceof ApiClientError ? error : coerceToApiClientError(error, parsed);
		if (isSecurityRoadblockPayload(clientError.payload)) {
			announceSecurityRoadblock(clientError.payload);
			if (showNotifications ?? this.notifyErrors) {
				notifySecurityRoadblock(clientError.payload);
			}
			throw clientError;
		}

		const sessionExpired = isWordPressSessionExpired(clientError.status, clientError.payload);
		if (sessionExpired) {
			announceWordPressSessionExpired(clientError.payload);
		}
		if (
			!sessionExpired &&
			(showNotifications ?? this.notifyErrors) &&
			isApiErrorPayload(clientError.payload)
		) {
			const message =
				clientError.payload.message ?? clientError.payload.error?.message ?? clientError.message;
			notifications.error(message ?? 'Request failed');
		}
		throw clientError;
	}

	private buildCacheKey(url: URL): string {
		const context = this.cacheContext?.() ?? 'default';
		const canonical = new URL(url.toString());
		canonical.searchParams.delete('force_refresh');
		return `${context}|${canonical.toString()}`;
	}

	private async parseResponseBody(response: Response, requestUrl: string): Promise<unknown> {
		if (response.status === 204) {
			return undefined;
		}

		const contentType = response.headers.get('content-type') ?? '';
		if (contentType.includes('application/json')) {
			const text = await readResponseText(response);
			if (text.length === 0) {
				return undefined;
			}

			if (hasContaminatedJsonPrefix(text)) {
				const payload = buildInvalidJsonResponsePayload(response, text, requestUrl);
				throw new ApiClientError(payload.message, response.status, payload);
			}

			try {
				return JSON.parse(text);
			} catch {
				const payload = buildInvalidJsonResponsePayload(response, text, requestUrl);
				throw new ApiClientError(payload.message, response.status, payload);
			}
		}

		const text = await readResponseText(response);
		try {
			return text.length ? JSON.parse(text) : undefined;
		} catch {
			return text;
		}
	}

	private unwrap<T>(payload: unknown): T {
		if (isRestEnvelope<T>(payload)) {
			return payload.data;
		}

		return payload as T;
	}
}

function isRestEnvelope<T>(payload: unknown): payload is RestEnvelope<T> {
	return Boolean(
		payload && typeof payload === 'object' && 'success' in payload && 'data' in payload
	);
}

function isApiErrorPayload(payload: unknown): payload is ApiErrorPayload {
	return Boolean(payload && typeof payload === 'object');
}

function coerceToApiClientError(original: unknown, parsed?: unknown): ApiClientError {
	if (original instanceof ApiClientError) {
		return original;
	}

	if (original instanceof Error) {
		return new ApiClientError(original.message, 500, parsed ?? null);
	}

	return new ApiClientError('Unknown error', 500, parsed ?? null);
}

export function createClientFromConfig(
	overrides: Partial<ClientConfig> = {}
): SentientFormsApiClient {
	const config = resolveRuntimeConfig();
	const getNonce = overrides.getNonce ?? (() => resolveRuntimeConfig().restNonce);
	const cacheContext =
		overrides.cacheContext ?? (() => buildRuntimeCacheContext(resolveRuntimeConfig()));

	return new SentientFormsApiClient({
		baseUrl: config.apiBaseUrl,
		...overrides,
		getNonce,
		cacheContext
	});
}

function buildRuntimeCacheContext(config: SentientFormsConfig): string {
	const userId = config.currentUser?.id ?? 'anon';
	return [
		config.localSiteIdentifier ?? config.siteUrl,
		userId,
		config.pluginVersion ?? 'unknown'
	].join(':');
}

function defaultRuntimeConfig(): SentientFormsConfig {
	const { apiBaseUrl, siteUrl } = fallbackWordPressRuntimeUrls();

	return {
		apiBaseUrl,
		restNonce: '',
		ajaxNonce: '',
		siteUrl,
		localSiteIdentifier: siteUrl || 'sentient-forms-runtime',
		pluginVersion: 'unknown',
		devMode: false,
		license: {
			status: 'inactive',
			licenseKeyMasked: '',
			proxyKeyPresent: false,
			tier: null,
			expiresAt: null,
			lastSynced: null,
			licenseId: null,
			siteId: null
		},
		telemetry: {
			optIn: false,
			updatedAt: null,
			syncedAt: null,
			remoteUpdatedAt: null,
			lastError: null
		},
		i18n: {}
	};
}

function fallbackWordPressRuntimeUrls(): Pick<SentientFormsConfig, 'apiBaseUrl' | 'siteUrl'> {
	if (typeof window === 'undefined') {
		return {
			apiBaseUrl: '/wp-json/sentient-forms/v1/',
			siteUrl: ''
		};
	}

	const { origin, pathname } = window.location;
	const adminSegment = '/wp-admin';
	const adminPathIndex = pathname.indexOf(`${adminSegment}/`);
	const sitePath =
		adminPathIndex >= 0
			? pathname.slice(0, adminPathIndex)
			: pathname.endsWith(adminSegment)
				? pathname.slice(0, -adminSegment.length)
				: '';
	const normalizedSitePath = sitePath.replace(/\/+$/, '');
	const apiBasePath = `${normalizedSitePath}/wp-json/sentient-forms/v1/`;

	return {
		apiBaseUrl: origin ? `${origin}${apiBasePath}` : apiBasePath,
		siteUrl: origin ? `${origin}${normalizedSitePath}` : ''
	};
}

function resolveRuntimeConfig(): SentientFormsConfig {
	if (typeof window === 'undefined') {
		return defaultRuntimeConfig();
	}

	if (!window.sentientFormsConfig) {
		window.sentientFormsConfig = {
			...defaultRuntimeConfig()
		};
	}

	return window.sentientFormsConfig;
}
