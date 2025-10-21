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
}

declare global {
    interface Window {
        sentientFormsConfig?: SentientFormsConfig;
    }
}

export class ApiError extends Error {
	status: number;
	payload: unknown;

	constructor(message: string, status: number, payload: unknown) {
		super(message);
		this.status = status;
		this.payload = payload;
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
			notifications.error(payload?.message ?? 'Request failed');
		}
		throw error;
	}

	return payload as T;
}
