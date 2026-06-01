import type { FormSourceSummary } from '$lib/api/types';
import {
	announceWordPressSessionExpired,
	isWordPressSessionExpired
} from '$lib/api/session-expiry';
import {
	announceSecurityRoadblock,
	classifySecurityRoadblock,
	notifySecurityRoadblock
} from '$lib/api/security-roadblock';
import { notifications } from '$lib/stores/notifications';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions
    extends Omit<RequestInit, 'body' | 'method'> {
    method?: HttpMethod;
    body?: unknown;
    showNotifications?: boolean;
}

export interface SentientFormsConfig {
    apiBaseUrl: string;
    restNonce: string;
    ajaxNonce: string;
    siteUrl: string;
    localSiteIdentifier?: string;
    pluginVersion?: string;
    initialRoute?: string;
    formSources?: FormSourceSummary[];
    license?: {
        status?: string;
        licenseKeyMasked?: string;
        proxyKeyPresent?: boolean;
        tier?: string | null;
        expiresAt?: string | null;
        lastSynced?: string | null;
        licenseId?: string | null;
        siteId?: string | null;
    };
    i18n?: Record<string, string>;
    devMode?: boolean;
    demoMode?: boolean;
    devServerUrl?: string | null;
    telemetry?: {
        optIn: boolean;
        updatedAt?: string | null;
        syncedAt?: string | null;
        remoteUpdatedAt?: string | null;
        lastError?: string | null;
    };
    asyncSettings?: {
        maxAttempts: number;
        baseDelaySeconds: number;
        maxDelaySeconds: number;
        updatedAt?: string | null;
        updatedBy?: string | null;
    };
    asyncHealth?: {
        queue_depth: number;
        oldest_run_at: number | null;
        recent_failures: Record<string, number>;
        warnings: Array<{ code: string; level: string; message: string }>;
    };
    currentUser?: {
        id: number;
        canManage: boolean;
    };
}

declare global {
    interface Window {
        sentientFormsConfig?: SentientFormsConfig;
    }
}

export class ApiError extends Error {
    status: number;
    payload: unknown;
    code?: string;

    constructor(message: string, status: number, payload: unknown) {
        super(message);
        this.status = status;
        this.payload = payload;
        if (isApiErrorPayload(payload)) {
            const directCode =
                stringValue(payload.code) || stringValue(payload.error_code);
            const nestedCode =
                payload.error && typeof payload.error === 'object'
                    ? stringValue((payload.error as { code?: unknown }).code)
                    : '';
            if (directCode) {
                this.code = directCode;
            } else if (nestedCode) {
                this.code = nestedCode;
            }
        }
    }
}

function getRuntimeConfig(): SentientFormsConfig {
    if (typeof window === 'undefined' || !window.sentientFormsConfig) {
        throw new Error('Sentient Forms runtime config missing.');
    }
    return window.sentientFormsConfig;
}

export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const config = getRuntimeConfig();

    const { method = 'GET', showNotifications = true, headers, body, ...rest } = options;

    const requestInit: RequestInit = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': config.restNonce,
            ...(headers ?? {})
        },
        credentials: 'same-origin',
        ...rest
    };

    if (body !== undefined) {
        requestInit.body = typeof body === 'string' ? body : JSON.stringify(body);
    }

    // Handle URL construction - if path has query params and baseUrl already has ?, replace ? with &
    let fullUrl = `${config.apiBaseUrl}${path}`;
    if (config.apiBaseUrl.includes('?') && path.includes('?')) {
        // Replace the first ? in path with & since we're appending to a URL that already has query params
        fullUrl = `${config.apiBaseUrl}${path.replace('?', '&')}`;
    }

	const response = await fetch(fullUrl, requestInit);

	const contentType = response.headers.get('content-type');
	const isJson = contentType?.includes('application/json');
	const hasNoBody =
		response.status === 204 || response.status === 205 || response.headers.get('content-length') === '0';
	const payload = hasNoBody ? null : isJson ? await response.json() : await response.text();

	if (!response.ok) {
        const securityRoadblock = classifySecurityRoadblock(response, payload);
        if (securityRoadblock) {
            announceSecurityRoadblock(securityRoadblock);
            if (showNotifications) {
                notifySecurityRoadblock(securityRoadblock);
            }
            throw new ApiError(securityRoadblock.message, response.status, securityRoadblock);
        }

        const error = new ApiError('Request failed', response.status, payload);
        const sessionExpired = isWordPressSessionExpired(response.status, payload);
        if (sessionExpired) {
            announceWordPressSessionExpired(payload);
        } else if (showNotifications) {
            const message = isApiErrorPayload(payload) ? payload.message : null;
            notifications.error(message ?? 'Request failed');
        }
        throw error;
    }

    return payload as T;
}

function stringValue(value: unknown): string {
    return typeof value === 'string' && value.trim().length > 0 ? value.trim() : '';
}

function isApiErrorPayload(payload: unknown): payload is {
	code?: string;
	message?: string;
	error_code?: string;
	error?: { code?: string; message?: string };
} {
    return Boolean(payload && typeof payload === 'object');
}
