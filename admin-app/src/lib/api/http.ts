import type { FormSourceSummary } from '$lib/api/types';
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
		if (isApiErrorPayload(payload) && payload.error_code) {
			this.code = payload.error_code;
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

	const response = await fetch(`${config.apiBaseUrl}${path}`, requestInit);

	const contentType = response.headers.get('content-type');
	const isJson = contentType?.includes('application/json');
	const payload = isJson ? await response.json() : await response.text();

	if (!response.ok) {
		const error = new ApiError('Request failed', response.status, payload);
		if (showNotifications) {
			const message = isApiErrorPayload(payload) ? payload.message : null;
			notifications.error(message ?? 'Request failed');
		}
		throw error;
	}

	return payload as T;
}

function isApiErrorPayload(payload: unknown): payload is { message?: string; error_code?: string } {
	return Boolean(payload && typeof payload === 'object');
}
