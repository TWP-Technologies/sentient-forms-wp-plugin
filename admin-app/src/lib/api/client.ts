import { readRuntimeConfig, type SentientFormsConfig } from '$lib/schemas/runtime-config';
import { safeParseFormActionConfigPayload } from '$lib/schemas/action-config';
import { z, type ZodType } from 'zod';
import {
	endpointRegistry,
	parseRegisteredEndpointRequest,
	type EndpointName,
	type RegisteredEndpointRequest,
	type RegisteredEndpointResponse
} from '$lib/api/endpoint-schemas';
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
import type {
	ActionDefaultsBatchResponse,
	ActionCompatibilityEvidence,
	ActionCompatibilityLifecycle,
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
	CustomActionCreatePayload,
	CustomActionFilters,
	CustomActionUpdatePayload,
	DashboardSummaryResponse,
	DuplicateFormActionRequest,
	DuplicateFormActionResponse,
	FormActionConfig,
	FormActionConfigResponse,
	FormActionsBootstrapResponse,
	FormDisableStateResponse,
	FormActionMutationPayload,
	FormAllActionConfigsResponse,
	FormsOverviewResponse,
	SubmissionLedgerRecordsResponse,
	SubmissionLedgerSettingsResponse,
	RequestTraceRequest,
	RequestTraceResponse,
	WorkflowPlanResponse,
	CapabilitiesResponse,
	LicenseActivationRequest,
	LicenseActivationResult,
	LicenseInfoResponse,
	LeadProfileResponse,
	LeadProfileGeneratePayload,
	LeadProfileSavePayload,
	LeadScoringCorrectionPayload,
	LeadValueEntrySearchResponse,
	LeadValueHistoricalRunCreatePayload,
	LeadValueHistoricalRunResponse,
	LocalCustomActionCreatePayload,
	LocalFormMappingCreatePayload,
	LocalMigrationApprovedResetRequest,
	LocalMigrationApprovedResetResponse,
	LocalMigrationDryRunResponse,
	LocalMigrationImportApplyResponse,
	LocalMigrationImportApplyRequest,
	LocalMigrationImportDryRunResponse,
	LocalMigrationImportRequest,
	LocalProviderCredentialDeleteResponse,
	ManagedCheckoutCompleteRequest,
	ManagedCheckoutCompleteResponse,
	ManagedCheckoutStartRequest,
	ManagedCheckoutStartResponse,
	OpenRouterConstantRequest,
	OpenRouterModelsRefreshRequest,
	OpenRouterModelsResponse,
	OpenRouterValidateRequest,
	SentientManagedRevokeRequest,
	SentientManagedSetupRequest,
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

type RuntimeClientOverrides = Pick<ClientConfig, 'fetchImpl' | 'notifyErrors' | 'cacheContext'>;

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

interface RuntimeResponseContract {
	schema: ZodType;
	name: string;
	errorSchema?: ZodType;
	errorName?: string;
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

const adminApiCacheEntrySchema = z
	.strictObject({
		expiresAt: z.number(),
		tags: z.array(z.string()),
		value: z.json()
	})
	.refine((entry) => Object.prototype.hasOwnProperty.call(entry, 'value'), {
		message: 'Cache entry value is required.',
		path: ['value']
	});

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
		const parsed = adminApiCacheEntrySchema.safeParse(JSON.parse(raw));
		if (parsed.success) {
			return parsed.data;
		}
	} catch {
		// Fall through to the shared eviction path.
	}

	deleteSessionCacheEntry(key);
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
			const directCode = typeof payload.code === 'string' ? payload.code.trim() : '';
			const topLevelCode = typeof payload.error_code === 'string' ? payload.error_code.trim() : '';
			const nestedCode = typeof payload.error?.code === 'string' ? payload.error.code.trim() : '';
			if (directCode.length > 0) {
				this.code = directCode;
			} else if (topLevelCode.length > 0) {
				this.code = topLevelCode;
			} else if (nestedCode.length > 0) {
				this.code = nestedCode;
			}
		}
	}
}

export interface ApiContractIssue {
	code: string;
	path: Array<string | number>;
	message: string;
}

export class ApiContractError extends Error {
	readonly endpoint: string;
	readonly schema: string;
	readonly issues: ApiContractIssue[];

	constructor(endpoint: string, schema: string, issues: ApiContractIssue[]) {
		super(`The response from ${endpoint} did not match the ${schema} contract.`);
		this.name = 'ApiContractError';
		this.endpoint = endpoint;
		this.schema = schema;
		this.issues = issues;
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
		const response: RegisteredEndpointResponse<'license.activate'> = await this.requestEndpoint(
			'license.activate',
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

		const data = response;
		return {
			success: true,
			message: 'License activated successfully.',
			status: data.status,
			tier: data.tier ?? undefined,
			expiryDate: data.expires_at,
			licenseId: data.license_id ?? undefined,
			siteId: data.site_id ?? undefined
		};
	}

	async getLicenseInfo(options: RequestOptions = {}): Promise<LicenseInfoResponse> {
		const response = await this.requestEndpoint(
			'license.read',
			withCacheDefaults(options, {
				ttlMs: 60_000,
				tags: ['license'],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async deactivateLicense(options: RequestOptions = {}): Promise<void> {
		const response: RegisteredEndpointResponse<'license.deactivate'> = await this.requestEndpoint(
			'license.deactivate',
			{ method: 'POST', ...options }
		);
		void response;
	}

	async bootstrapLicense(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'license.bootstrap'>> {
		const response = await this.requestEndpoint('license.bootstrap', {
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
		const response = await this.requestEndpoint(
			'billing.state',
			withCacheDefaults(
				{ ...requestOptions, ...(forceServerRefresh ? { forceRefresh: true } : {}) },
				{
					ttlMs: 60_000,
					tags: ['license', 'billing'],
					storage: 'session'
				}
			),
			forceServerRefresh ? 'license/billing-state?force_refresh=1' : undefined
		);
		return this.unwrap(response);
	}

	async createCheckoutSession(
		payload: BillingCheckoutSessionRequest,
		options: RequestOptions = {}
	): Promise<BillingCheckoutSessionResponse> {
		const response = await this.requestEndpoint('billing.checkout.create', {
			method: 'POST',
			body: payload,
			...options
		});
		return response;
	}

	async startManagedCheckout(
		payload: ManagedCheckoutStartRequest,
		options: RequestOptions = {}
	): Promise<ManagedCheckoutStartResponse> {
		const response = await this.requestEndpoint('billing.managedCheckout.start', {
			method: 'POST',
			body: payload,
			...options
		});
		return response;
	}

	async completeManagedCheckout(
		payload: ManagedCheckoutCompleteRequest,
		options: RequestOptions = {}
	): Promise<ManagedCheckoutCompleteResponse> {
		const response = await this.requestEndpoint('billing.managedCheckout.complete', {
			method: 'POST',
			body: payload,
			...options
		});
		return this.unwrap(response);
	}

	async createPortalSession(
		payload: BillingPortalSessionRequest,
		options: RequestOptions = {}
	): Promise<BillingPortalSessionResponse> {
		const response = await this.requestEndpoint('billing.portal.create', {
			method: 'POST',
			body: payload,
			...options
		});
		return response;
	}

	async createTopUpCheckoutSession(
		payload: TopUpCheckoutSessionRequest,
		options: RequestOptions = {}
	): Promise<TopUpCheckoutSessionResponse> {
		const response = await this.requestEndpoint('billing.topUp.create', {
			method: 'POST',
			body: payload,
			...options
		});
		return response;
	}

	async getTelemetrySettings(options: RequestOptions = {}): Promise<TelemetrySettingsResponse> {
		const response = await this.requestEndpoint('telemetry.read', options);
		return this.unwrap(response);
	}

	async updateTelemetrySettings(
		optIn: boolean,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'telemetry.update'>> {
		const response = await this.requestEndpoint('telemetry.update', {
			method: 'PUT',
			body: { telemetry_opt_in: optIn },
			...options
		});
		return this.unwrap(response);
	}

	async getAsyncSettings(options: RequestOptions = {}): Promise<AsyncSettingsResponse> {
		const response = await this.requestEndpoint('asyncSettings.read', options);
		return this.unwrap(response);
	}

	async getSettings(options: RequestOptions = {}): Promise<PluginSettingsResponse> {
		const response = await this.requestEndpoint(
			'settings.read',
			withCacheDefaults(options, {
				ttlMs: 60_000,
				tags: ['settings'],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async updateSettings(
		payload: RegisteredEndpointRequest<'settings.update'>,
		options: RequestOptions = {}
	): Promise<PluginSettingsResponse> {
		const response = await this.requestEndpoint('settings.update', {
			method: 'PUT',
			body: payload,
			...options
		});
		const data = this.unwrap<RegisteredEndpointResponse<'settings.update'>>(response);
		return 'settings' in data ? data.settings : data;
	}

	async updateAsyncSettings(
		payload: AsyncSettingsPayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'asyncSettings.update'>> {
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

		const response = await this.requestEndpoint('asyncSettings.update', {
			method: 'PUT',
			body,
			...options
		});

		return this.unwrap(response);
	}

	async getAsyncHealth(options: RequestOptions = {}): Promise<AsyncHealthResponse> {
		const response = await this.requestEndpoint(
			'asyncHealth.read',
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
	): Promise<RegisteredEndpointResponse<'asyncHealth.purge'>> {
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
		const response = await this.requestEndpoint(
			'asyncHealth.purge',
			{ method: 'DELETE', ...requestOptions },
			path
		);
		return this.unwrap(response);
	}

	async getLocalProviderCredentials(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'providers.credentials.list'>> {
		return this.requestEndpoint('providers.credentials.list', {
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
		return this.requestEndpoint(
			'providers.credentials.delete',
			{
				method: 'DELETE',
				...options
			},
			`local/providers/credentials/${encodeURIComponent(String(id))}`
		);
	}

	async validateOpenRouterKey(
		payload: OpenRouterValidateRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'provider.openrouter.validate'>> {
		return this.requestEndpoint('provider.openrouter.validate', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async saveOpenRouterConstant(
		payload: OpenRouterConstantRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'provider.openrouter.constant'>> {
		return this.requestEndpoint('provider.openrouter.constant', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async setupSentientManagedProvider(
		payload: SentientManagedSetupRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'provider.sentientManaged.setup'>> {
		return this.requestEndpoint('provider.sentientManaged.setup', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async revokeSentientManagedProvider(
		payload: SentientManagedRevokeRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'provider.sentientManaged.revoke'>> {
		return this.requestEndpoint('provider.sentientManaged.revoke', {
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

		const response = await this.requestEndpoint(
			'provider.openrouter.models',
			{
				showNotifications: false,
				...options
			},
			path
		);
		return response;
	}

	async refreshOpenRouterModels(
		payload: OpenRouterModelsRefreshRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'provider.openrouter.modelsRefresh'>> {
		const response = await this.requestEndpoint('provider.openrouter.modelsRefresh', {
			method: 'POST',
			body: payload,
			...options
		});
		return response;
	}

	async getLocalActionTemplates(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'local.actionTemplates.list'>> {
		return this.requestEndpoint('local.actionTemplates.list', {
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
	): Promise<RegisteredEndpointResponse<'local.customActions.list'>> {
		const params = new URLSearchParams({ status });

		return this.requestEndpoint(
			'local.customActions.list',
			{
				cacheTtlMs: 60_000,
				cacheTags: ['actions', 'custom-actions'],
				cacheStorage: 'session',
				showNotifications: false,
				...options
			},
			`local/custom-actions?${params}`
		);
	}

	async createLocalCustomAction(
		payload: LocalCustomActionCreatePayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'local.customActions.create'>> {
		return this.requestEndpoint('local.customActions.create', {
			method: 'POST',
			body: parseRegisteredEndpointRequest('local.customActions.create', payload),
			...options
		});
	}

	async getLocalFormMappings(
		formSource: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'local.formMappings.list'>> {
		const params = new URLSearchParams({
			form_source: formSource,
			form_id: String(formId)
		});

		return this.requestEndpoint(
			'local.formMappings.list',
			{
				showNotifications: false,
				...options
			},
			`local/form-mappings?${params}`
		);
	}

	async createLocalFormMapping(
		payload: LocalFormMappingCreatePayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'local.formMappings.create'>> {
		return this.requestEndpoint('local.formMappings.create', {
			method: 'POST',
			body: parseRegisteredEndpointRequest('local.formMappings.create', payload),
			...options
		});
	}

	async getLocalExecutionEvents(
		limit = 5,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'local.executionEvents.list'>> {
		const params = new URLSearchParams({ limit: String(limit) });

		return this.requestEndpoint(
			'local.executionEvents.list',
			{
				cacheTtlMs: 30_000,
				cacheTags: ['execution-events', 'dashboard'],
				showNotifications: false,
				...options
			},
			`local/execution-events?${params}`
		);
	}

	async getLeadProfile(
		formSource: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<LeadProfileResponse> {
		return this.requestEndpoint(
			'lead.profile.read',
			{ showNotifications: false, ...options },
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/profile`
		);
	}

	async saveLeadProfile(
		formSource: string,
		formId: string | number,
		payload: LeadProfileSavePayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.profile.save'>> {
		return this.requestEndpoint(
			'lead.profile.save',
			{
				method: 'POST',
				body: parseRegisteredEndpointRequest('lead.profile.save', payload),
				...options
			},
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/profile`
		);
	}

	async generateLeadProfile(
		profileId: number,
		payload: LeadProfileGeneratePayload = {},
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.profile.generate'>> {
		return this.requestEndpoint(
			'lead.profile.generate',
			{
				method: 'POST',
				body: parseRegisteredEndpointRequest('lead.profile.generate', payload),
				...options
			},
			`lead-value/profiles/${encodeURIComponent(String(profileId))}/generate`
		);
	}

	async selfImproveLeadProfile(
		profileId: number,
		payload: { async?: boolean; force?: boolean } = {},
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.profile.selfImprove'>> {
		return this.requestEndpoint(
			'lead.profile.selfImprove',
			{
				method: 'POST',
				body: parseRegisteredEndpointRequest('lead.profile.selfImprove', payload),
				...options
			},
			`lead-value/profiles/${encodeURIComponent(String(profileId))}/self-improve`
		);
	}

	async refreshLeadProfileAssistant(
		profileId: number,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.profile.assistant'>> {
		return this.requestEndpoint(
			'lead.profile.assistant',
			{
				method: 'POST',
				...options
			},
			`lead-value/profiles/${encodeURIComponent(String(profileId))}/assistant`
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
		return this.requestEndpoint(
			'lead.entries.search',
			{ showNotifications: false, ...options },
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/entries/search${suffix}`
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
		const response = await this.requestEndpoint(
			'spamGuidance.entries.search',
			withCacheDefaults(
				{ showNotifications: false, ...requestOptions },
				{
					ttlMs: 15_000,
					tags: ['spam-guidance', 'submission-ledger', formCacheTag(formSourceSlug, formId)]
				}
			),
			`spam-guidance/forms/${slug}/${formIdSegment}/entries/search${params.toString() ? `?${params}` : ''}`
		);
		return this.unwrap(response);
	}

	async appendSpamGuidanceExample(
		formSourceSlug: string,
		formId: FormSourceFormId,
		payload: SpamGuidanceExampleAppendPayload,
		options: RequestOptions = {}
	): Promise<SpamGuidanceExampleAppendResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'spamGuidance.examples.append',
			{
				method: 'POST',
				body: parseRegisteredEndpointRequest('spamGuidance.examples.append', payload),
				invalidateCacheTags: [
					'spam-guidance',
					'action-defaults',
					'form-actions',
					formCacheTag(formSourceSlug, formId)
				],
				...options
			},
			`spam-guidance/forms/${slug}/${formIdSegment}/examples`
		);
		return this.unwrap(response);
	}

	async correctLeadScoringEntry(
		formSource: string,
		formId: string | number,
		entryId: string | number,
		payload: LeadScoringCorrectionPayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.entry.correct'>> {
		return this.requestEndpoint(
			'lead.entry.correct',
			{
				method: 'POST',
				body: parseRegisteredEndpointRequest('lead.entry.correct', payload),
				...options
			},
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/entries/${encodeURIComponent(String(entryId))}/correction`
		);
	}

	async generateLeadSuggestedReply(
		formSource: string,
		formId: string | number,
		entryId: string | number,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.entry.suggestReply'>> {
		return this.requestEndpoint(
			'lead.entry.suggestReply',
			{
				method: 'POST',
				...options
			},
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/entries/${encodeURIComponent(String(entryId))}/suggested-reply`
		);
	}

	async getLeadValueDashboard(
		formSource: string,
		formId: string | number,
		params: { page?: number; per_page?: number; q?: string } = {},
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.dashboard.form'>> {
		const query = new URLSearchParams();
		if (params.page) query.set('page', String(params.page));
		if (params.per_page) query.set('per_page', String(params.per_page));
		if (params.q) query.set('q', params.q);
		const suffix = query.toString() ? `?${query}` : '';
		return this.requestEndpoint(
			'lead.dashboard.form',
			{ showNotifications: false, ...options },
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/dashboard${suffix}`
		);
	}

	async getLeadScoringDashboard(
		params: { page?: number; per_page?: number; q?: string } = {},
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.dashboard.all'>> {
		const query = new URLSearchParams();
		if (params.page) query.set('page', String(params.page));
		if (params.per_page) query.set('per_page', String(params.per_page));
		if (params.q) query.set('q', params.q);
		const suffix = query.toString() ? `?${query}` : '';
		return this.requestEndpoint(
			'lead.dashboard.all',
			{ showNotifications: false, ...options },
			`lead-value/dashboard${suffix}`
		);
	}

	async importLeadProfile(
		formSource: string,
		formId: string | number,
		payload: RegisteredEndpointRequest<'lead.profile.import'>,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.profile.import'>> {
		return this.requestEndpoint(
			'lead.profile.import',
			{
				method: 'POST',
				body: payload,
				...options
			},
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/profile/import`
		);
	}

	async listLeadValueHistoricalRuns(
		formSource: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.historicalRuns.list'>> {
		return this.requestEndpoint(
			'lead.historicalRuns.list',
			{ showNotifications: false, ...options },
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/historical-runs`
		);
	}

	async createLeadValueHistoricalRun(
		formSource: string,
		formId: string | number,
		payload: LeadValueHistoricalRunCreatePayload,
		options: RequestOptions = {}
	): Promise<LeadValueHistoricalRunResponse> {
		return this.requestEndpoint(
			'lead.historicalRuns.create',
			{
				method: 'POST',
				body: parseRegisteredEndpointRequest('lead.historicalRuns.create', payload),
				...options
			},
			`lead-value/forms/${encodeURIComponent(formSource)}/${encodeURIComponent(String(formId))}/historical-runs`
		);
	}

	async startLeadValueHistoricalRun(
		runId: number,
		payload: RegisteredEndpointRequest<'lead.historicalRuns.start'> = {},
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'lead.historicalRuns.start'>> {
		return this.requestEndpoint(
			'lead.historicalRuns.start',
			{
				method: 'POST',
				body: payload,
				...options
			},
			`lead-value/historical-runs/${encodeURIComponent(String(runId))}/start`
		);
	}

	async getLocalSupportBundle(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'local.supportBundle.read'>> {
		return this.requestEndpoint('local.supportBundle.read', {
			showNotifications: false,
			...options
		});
	}

	async getDashboardSummary(options: RequestOptions = {}): Promise<DashboardSummaryResponse> {
		const response = await this.requestEndpoint(
			'dashboard.summary',
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: ['dashboard', 'providers', 'actions', 'execution-events', 'license']
			})
		);
		return this.unwrap(response);
	}

	async getLocalMigrationReadiness(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'migration.readiness'>> {
		return this.requestEndpoint('migration.readiness', {
			showNotifications: false,
			...options
		});
	}

	async createLocalMigrationDryRun(
		options: RequestOptions = {}
	): Promise<LocalMigrationDryRunResponse> {
		return this.requestEndpoint('migration.dryRun', {
			method: 'POST',
			...options
		});
	}

	async createLocalMigrationImportDryRun(
		payload: LocalMigrationImportRequest,
		options: RequestOptions = {}
	): Promise<LocalMigrationImportDryRunResponse> {
		return this.requestEndpoint('migration.import.dryRun', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async runLocalMigrationImportApply(
		payload: LocalMigrationImportApplyRequest,
		options: RequestOptions = {}
	): Promise<LocalMigrationImportApplyResponse> {
		return this.requestEndpoint('migration.import.apply', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async runLocalMigrationApprovedReset(
		payload: LocalMigrationApprovedResetRequest,
		options: RequestOptions = {}
	): Promise<LocalMigrationApprovedResetResponse> {
		return this.requestEndpoint('migration.approvedReset', {
			method: 'POST',
			body: payload,
			...options
		});
	}

	async getActionDefinitions(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'actions.definitions'>> {
		const response = await this.requestEndpoint(
			'actions.definitions',
			withCacheDefaults(options, {
				ttlMs: 300_000,
				tags: ['actions', 'definitions'],
				storage: 'session'
			})
		);
		return this.unwrap(response);
	}

	async getForms(
		formSourceSlug: string,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'forms.list'>> {
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.requestEndpoint(
			'forms.list',
			withCacheDefaults(options, {
				ttlMs: 60_000,
				tags: ['forms', `forms:${formSourceSlug}`],
				storage: 'session'
			}),
			`${slug}/forms`
		);
		return this.unwrap(response);
	}

	async getFormsOverview(
		formSourceSlug: string,
		options: RequestOptions = {}
	): Promise<FormsOverviewResponse> {
		const slug = encodeURIComponent(formSourceSlug);
		const response = await this.requestEndpoint(
			'forms.overview',
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
			}),
			`${slug}/forms/overview`
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
		const response = await this.requestEndpoint(
			'forms.actions.bootstrap',
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
			}),
			`${slug}/forms/${formIdSegment}/actions/bootstrap`
		);
		return response;
	}

	async getSubmissionLedgerSettings(
		formSourceSlug: string,
		formId: string | number,
		options: RequestOptions = {}
	): Promise<SubmissionLedgerSettingsResponse> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'forms.ledger.settings.read',
			withCacheDefaults(options, {
				ttlMs: 15_000,
				tags: ['submission-ledger', 'settings', formCacheTag(formSourceSlug, formId)]
			}),
			`${slug}/forms/${formIdSegment}/ledger-settings`
		);
		return this.unwrap(response);
	}

	async updateSubmissionLedgerSettings(
		formSourceSlug: string,
		formId: string | number,
		enabled: boolean,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'forms.ledger.settings.update'>> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'forms.ledger.settings.update',
			{
				method: 'PUT',
				body: { enabled },
				...options
			},
			`${slug}/forms/${formIdSegment}/ledger-settings`
		);
		return this.unwrap(response);
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
		const response = await this.requestEndpoint(
			'forms.ledger.records.list',
			withCacheDefaults(requestOptions, {
				ttlMs: 15_000,
				tags: ['submission-ledger', formCacheTag(formSourceSlug, formId)]
			}),
			`${slug}/forms/${formIdSegment}/submissions${query ? `?${query}` : ''}`
		);
		return this.unwrap(response);
	}

	async getSubmissionLedgerRecord(
		formSourceSlug: string,
		formId: string | number,
		submissionUuid: string,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'forms.ledger.records.read'>> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const uuid = encodeURIComponent(submissionUuid);
		const response = await this.requestEndpoint(
			'forms.ledger.records.read',
			withCacheDefaults(options, {
				ttlMs: 15_000,
				tags: ['submission-ledger', formCacheTag(formSourceSlug, formId)]
			}),
			`${slug}/forms/${formIdSegment}/submissions/${uuid}`
		);
		return this.unwrap(response);
	}

	async getFormActions(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'forms.actions.list'>> {
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
		const response = await this.requestEndpoint(
			'forms.actions.list',
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: ['actions', 'form-actions', formCacheTag(formSourceSlug, formId)]
			}),
			`${slug}/forms/${formIdSegment}/actions`
		);
		return this.unwrap(response);
	}

	async checkActionCompatibility(
		formSourceSlug: string,
		formId: FormSourceFormId,
		actionCode: string,
		lifecycle: ActionCompatibilityLifecycle,
		options: RequestOptions = {}
	): Promise<ActionCompatibilityEvidence> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const query = new URLSearchParams({ action_code: actionCode, lifecycle });
		const response = await this.requestEndpoint(
			'forms.actions.compatibility',
			{ ...options, method: 'GET' },
			`${slug}/forms/${formIdSegment}/actions/compatibility?${query.toString()}`
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
		const response = await this.requestEndpoint(
			'forms.workflowPlan.read',
			options,
			`${slug}/forms/${formIdSegment}/actions/workflow-plan?hook_scope=${scope}`
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
		const response = await this.requestEndpoint(
			'forms.requestTrace.run',
			{
				method: 'POST',
				body: payload,
				...options
			},
			`${slug}/forms/${formIdSegment}/actions/request-trace`
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
		const response = await this.requestEndpoint(
			'forms.disabled.read',
			withCacheDefaults(options, {
				ttlMs: 30_000,
				tags: ['settings', 'form-actions', formCacheTag(formSourceSlug, formId)]
			}),
			`${slug}/forms/${formIdSegment}/actions/disable`
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
	): Promise<RegisteredEndpointResponse<'forms.disabled.update'>> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'forms.disabled.update',
			{
				...options,
				method: 'PUT',
				body: { sf_disabled: disabled }
			},
			`${slug}/forms/${formIdSegment}/actions/disable`
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
	): Promise<RegisteredEndpointResponse<'forms.fields.list'>> {
		if (isInvalidFormSourceContext(formSourceSlug, formId)) {
			console.warn('[ApiClient] getFormFields called with invalid params:', {
				formSourceSlug,
				formId
			});
			return [];
		}
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'forms.fields.list',
			withCacheDefaults(options, {
				ttlMs: 300_000,
				tags: ['forms', formCacheTag(formSourceSlug, formId)],
				storage: 'session'
			}),
			`${slug}/forms/${formIdSegment}/actions/fields`
		);
		return this.unwrap(response);
	}

	async getFormExecutionStatus(
		formSourceSlug: string,
		formId: FormSourceFormId,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'forms.executionStatus.read'>> {
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
		const response = await this.requestEndpoint(
			'forms.executionStatus.read',
			withCacheDefaults(options, {
				ttlMs: 15_000,
				tags: ['execution-status', formCacheTag(formSourceSlug, formId)]
			}),
			`${slug}/forms/${formIdSegment}/actions/status`
		);
		return this.unwrap(response);
	}

	async getCapabilities(options: RequestOptions = {}): Promise<CapabilitiesResponse> {
		const response = await this.requestEndpoint('meta.capabilities', {
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
	): Promise<RegisteredEndpointResponse<'forms.actions.create'>> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'forms.actions.create',
			{
				method: 'POST',
				body: parseRegisteredEndpointRequest('forms.actions.create', payload),
				...options
			},
			`${slug}/forms/${formIdSegment}/actions`
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
		const response = await this.requestEndpoint(
			'forms.actions.duplicate',
			{ method: 'POST', body: payload, ...options },
			`${slug}/forms/${formIdSegment}/actions/${encodeURIComponent(localMappingId)}/duplicate`
		);
		return this.unwrap(response);
	}

	async updateFormAction(
		formSourceSlug: string,
		formId: FormSourceFormId,
		localMappingId: string,
		payload: FormActionMutationPayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'forms.actions.update'>> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'forms.actions.update',
			{
				method: 'PUT',
				body: parseRegisteredEndpointRequest('forms.actions.update', payload),
				...options
			},
			`${slug}/forms/${formIdSegment}/actions/${encodeURIComponent(localMappingId)}`
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
		const response: RegisteredEndpointResponse<'forms.actions.delete'> = await this.requestEndpoint(
			'forms.actions.delete',
			{
				method: 'DELETE',
				...options
			},
			`${slug}/forms/${formIdSegment}/actions/${encodeURIComponent(localMappingId)}`
		);
		void response;
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
		const response = await this.requestEndpoint(
			'forms.actionConfigs.list',
			{ showNotifications: false, ...options },
			`forms/${slug}/${formIdSegment}/action-config`
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
		const response = await this.requestEndpoint(
			'forms.actionConfigs.read',
			{ showNotifications: false, ...options },
			`forms/${slug}/${formIdSegment}/action-config/${encodeURIComponent(actionId)}`
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
		const response = await this.requestEndpoint(
			'forms.actionConfigs.update',
			{ method: 'POST', body: payload, ...options },
			`forms/${slug}/${formIdSegment}/action-config/${encodeURIComponent(actionId)}`
		);
		return this.unwrap<RegisteredEndpointResponse<'forms.actionConfigs.update'>>(response).config;
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
		const response: RegisteredEndpointResponse<'forms.actionConfigs.delete'> =
			await this.requestEndpoint(
				'forms.actionConfigs.delete',
				{
					method: 'DELETE',
					...options
				},
				`forms/${slug}/${formIdSegment}/action-config/${encodeURIComponent(actionId)}`
			);
		void response;
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
		const response = await this.requestEndpoint(
			'actions.defaults.read',
			withCacheDefaults(
				{ showNotifications: false, ...options },
				{
					ttlMs: 300_000,
					tags: ['action-defaults'],
					storage: 'session'
				}
			),
			`actions/${encodeURIComponent(actionId)}/defaults`
		);
		return this.unwrap<RegisteredEndpointResponse<'actions.defaults.read'>>(response).config;
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
			const response = await this.requestEndpoint(
				'actions.defaults.batch',
				withCacheDefaults(
					{ showNotifications: false, ...options },
					{
						ttlMs: 300_000,
						tags: ['action-defaults'],
						storage: 'session'
					}
				),
				`actions/defaults?ids=${encodeURIComponent(batchIds.join(','))}`
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
		const response = await this.requestEndpoint(
			'actions.defaults.update',
			{ method: 'POST', body: payload, ...options },
			`actions/${encodeURIComponent(actionId)}/defaults`
		);
		return this.unwrap<RegisteredEndpointResponse<'actions.defaults.update'>>(response).config;
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
	): Promise<RegisteredEndpointResponse<'customActions.list'>> {
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
		return this.requestEndpoint(
			'customActions.list',
			{ showNotifications: false, ...options },
			path
		);
	}

	async createCustomAction(
		payload: CustomActionCreatePayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'customActions.create'>> {
		return this.requestEndpoint('customActions.create', {
			method: 'POST',
			body: parseRegisteredEndpointRequest('customActions.create', payload),
			...options
		});
	}

	async updateCustomAction(
		id: string,
		payload: CustomActionUpdatePayload,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'customActions.update'>> {
		return this.requestEndpoint(
			'customActions.update',
			{
				method: 'PUT',
				body: parseRegisteredEndpointRequest('customActions.update', payload),
				...options
			},
			`custom-actions/${encodeURIComponent(id)}`
		);
	}

	async archiveCustomAction(
		id: string,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'customActions.archive'>> {
		return this.requestEndpoint(
			'customActions.archive',
			{
				method: 'DELETE',
				...options
			},
			`custom-actions/${encodeURIComponent(id)}`
		);
	}

	async reactivateCustomAction(
		id: string,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'customActions.reactivate'>> {
		return this.requestEndpoint(
			'customActions.reactivate',
			{
				method: 'POST',
				...options
			},
			`custom-actions/${encodeURIComponent(id)}/reactivate`
		);
	}

	// ==========================================================================
	// Phase 7: Form Mappings (CSM - Cross-Site Mapping Portability)
	// ==========================================================================

	/**
	 * Get all form mappings for the current license.
	 * CSM-001: local mapping storage
	 */
	async getFormMappings(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'mappings.list'>> {
		const response = await this.requestEndpoint('mappings.list', {
			showNotifications: false,
			...options
		});
		return response;
	}

	/**
	 * Get template mappings only (reusable across sites).
	 * CSM-003: Save as Template
	 */
	async getFormMappingTemplates(
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'mappings.templates'>> {
		const response = await this.requestEndpoint('mappings.templates', {
			showNotifications: false,
			...options
		});
		return response;
	}

	/**
	 * Get a single form mapping by ID.
	 */
	async getFormMapping(
		id: string,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'mappings.read'>> {
		const response = await this.requestEndpoint(
			'mappings.read',
			{ showNotifications: false, ...options },
			`mappings/${encodeURIComponent(id)}`
		);
		return response;
	}

	/**
	 * Create a new form mapping.
	 * CSM-001: local mapping storage
	 */
	async createFormMapping(
		payload: CreateFormMappingRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'mappings.create'>> {
		const response = await this.requestEndpoint('mappings.create', {
			method: 'POST',
			body: payload,
			...options
		});
		return response;
	}

	/**
	 * Update an existing form mapping.
	 */
	async updateFormMapping(
		id: string,
		payload: UpdateFormMappingRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'mappings.update'>> {
		const response = await this.requestEndpoint(
			'mappings.update',
			{ method: 'PUT', body: payload, ...options },
			`mappings/${encodeURIComponent(id)}`
		);
		return response;
	}

	/**
	 * Delete a form mapping.
	 */
	async deleteFormMapping(id: string, options: RequestOptions = {}): Promise<void> {
		const response: RegisteredEndpointResponse<'mappings.delete'> = await this.requestEndpoint(
			'mappings.delete',
			{
				method: 'DELETE',
				...options
			},
			`mappings/${encodeURIComponent(id)}`
		);
		void response;
	}

	/**
	 * Clone a template mapping to a specific site and form.
	 * CSM-004: Import from Library
	 */
	async cloneFormMappingTemplate(
		templateId: string,
		payload: CloneTemplateMappingRequest,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'mappings.clone'>> {
		const response = await this.requestEndpoint(
			'mappings.clone',
			{ method: 'POST', body: payload, ...options },
			`mappings/${encodeURIComponent(templateId)}/clone`
		);
		return response;
	}

	async getExecutionStatus(
		formSourceSlug: string,
		formId: FormSourceFormId,
		entryId: number,
		options: RequestOptions = {}
	): Promise<RegisteredEndpointResponse<'forms.entryExecutionStatus.read'>> {
		const slug = formSourcePathSegment(formSourceSlug);
		const formIdSegment = formIdPathSegment(formId);
		const response = await this.requestEndpoint(
			'forms.entryExecutionStatus.read',
			options,
			`${slug}/forms/${formIdSegment}/actions/entries/${entryId}/status`
		);
		return this.unwrap(response);
	}

	async requestParsed<TSchema extends ZodType>(
		path: string,
		schema: TSchema,
		options: RequestOptions = {},
		schemaName = schema.description ?? 'inline response',
		errorSchema?: ZodType,
		errorName = errorSchema?.description ?? 'endpoint error'
	): Promise<z.output<TSchema>> {
		return (await this.requestUnknown(path, options, {
			schema,
			name: schemaName,
			errorSchema,
			errorName
		})) as z.output<TSchema>;
	}

	async requestEndpoint<TName extends EndpointName>(
		name: TName,
		options: RequestOptions = {},
		pathOverride?: string
	): Promise<RegisteredEndpointResponse<TName>> {
		const definition = endpointRegistry[name];
		const path = pathOverride ?? definition.path;
		let body = options.body;

		if ('request' in definition) {
			body = this.parseContractPayload(path, body, {
				schema: definition.request,
				name: definition.request.description ?? `${name} request`
			});
		}

		return (await this.requestParsed(
			path,
			definition.response,
			{ ...options, body },
			definition.response.description ?? `${name} response`,
			definition.error,
			definition.error.description ?? `${name} error`
		)) as RegisteredEndpointResponse<TName>;
	}

	private async requestUnknown(
		path: string,
		options: RequestOptions = {},
		contract?: RuntimeResponseContract
	): Promise<unknown> {
		let url: URL;
		const base = new URL(this.baseUrl.toString());
		const restRoute = base.searchParams.get('rest_route');
		if (path.startsWith('//')) {
			throw new Error('Sentient Forms REST requests must remain same-origin.');
		}

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
		if (url.origin !== base.origin) {
			throw new Error('Sentient Forms REST requests must remain same-origin.');
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
		let cacheVersionSnapshot = cacheKey ? getCacheVersionSnapshot(normalizedCacheTags) : null;

		if (cacheKey && !forceRefresh) {
			const cached = readAdminApiCache(cacheKey, cacheStorage);
			if (cached) {
				try {
					return this.parseContractPayload(path, cached.value, contract);
				} catch (error) {
					if (!(error instanceof ApiContractError)) {
						throw error;
					}
					retireAdminApiCacheKeyForForcedRefresh(cacheKey, normalizedCacheTags);
					cacheVersionSnapshot = getCacheVersionSnapshot(normalizedCacheTags);
				}
			}

			const inFlight = adminApiInFlight.get(cacheKey);
			if (dedupe && inFlight) {
				inFlight.showNotifications = mergeShowNotifications(
					inFlight.showNotifications,
					showNotifications
				);
				try {
					return this.parseContractPayload(path, await inFlight.promise, contract);
				} catch (error) {
					if (error instanceof ApiContractError) {
						retireAdminApiCacheKeyForForcedRefresh(cacheKey, normalizedCacheTags);
						cacheVersionSnapshot = getCacheVersionSnapshot(normalizedCacheTags);
					} else {
						throw coerceToApiClientError(error);
					}
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
				const safeError = contract?.errorSchema
					? this.parseContractPayload(path, parsed, {
							schema: contract.errorSchema,
							name: contract.errorName ?? 'endpoint error'
						})
					: parsed;
				throw new ApiClientError('Request failed', response.status, safeError);
			}

			const trustedPayload = this.parseContractPayload(path, parsed, contract);

			if (cacheKey && cacheVersionSnapshot && isCacheVersionSnapshotCurrent(cacheVersionSnapshot)) {
				writeAdminApiCache(cacheKey, trustedPayload, {
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
				return undefined;
			}

			return trustedPayload;
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
		if (error instanceof ApiContractError) {
			throw error;
		}

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

	private parseContractPayload(
		path: string,
		payload: unknown,
		contract?: RuntimeResponseContract
	): unknown {
		if (!contract) {
			return payload;
		}

		const result = contract.schema.safeParse(payload);
		if (result.success) {
			return result.data;
		}

		throw new ApiContractError(
			path,
			contract.name,
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

	private unwrap<T>(payload: T | RestEnvelope<T>): T {
		if (isRestEnvelope<T>(payload)) {
			return payload.data;
		}

		return payload;
	}
}

function isRestEnvelope<T>(payload: T | RestEnvelope<T>): payload is RestEnvelope<T> {
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
	overrides: Partial<RuntimeClientOverrides> = {}
): SentientFormsApiClient {
	if ('baseUrl' in overrides || 'getNonce' in overrides) {
		throw new Error(
			'createClientFromConfig does not accept baseUrl or getNonce overrides; use validated runtime config.'
		);
	}
	const config = resolveRuntimeConfig();
	const getNonce = () => resolveRuntimeConfig().restNonce;
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
			updatedAt: null
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

	return readRuntimeConfig() ?? defaultRuntimeConfig();
}
