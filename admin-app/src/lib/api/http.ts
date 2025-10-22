import { notifications } from '$lib/stores/notifications';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions
    extends Omit<RequestInit, 'body' | 'method'> {
    method?: HttpMethod;
    body?: unknown;
    showNotifications?: boolean;
}

interface SentientFormsConfig {
    apiBaseUrl: string;
    restNonce: string;
    ajaxNonce: string;
    siteUrl: string;
    localSiteIdentifier?: string;
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

export async function apiFetch<T>(path: string, options: RequestOptions = {}): Promise<T> {
	const config = window.sentientFormsConfig;
	if (!config) {
		throw new Error('Sentient Forms runtime config missing.');
	}

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
