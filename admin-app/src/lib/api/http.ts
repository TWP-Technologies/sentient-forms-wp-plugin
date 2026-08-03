import { requireRuntimeConfig } from '$lib/schemas/runtime-config';
import {
	announceWordPressSessionExpired,
	isWordPressSessionExpired
} from '$lib/api/session-expiry';
import {
	announceSecurityRoadblock,
	classifySecurityRoadblock,
	notifySecurityRoadblock
} from '$lib/api/security-roadblock';
import {
	buildInvalidJsonResponsePayload,
	hasContaminatedJsonPrefix,
	readResponseText
} from '$lib/api/invalid-json';
import { notifications } from '$lib/stores/notifications';

export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export interface RequestOptions extends Omit<RequestInit, 'body' | 'method'> {
	method?: HttpMethod;
	body?: unknown;
	showNotifications?: boolean;
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
			const directCode = stringValue(payload.code) || stringValue(payload.error_code);
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

export async function apiFetch(path: string, options: RequestOptions = {}): Promise<unknown> {
	const config = requireRuntimeConfig();

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
		response.status === 204 ||
		response.status === 205 ||
		response.headers.get('content-length') === '0';
	const bodyText = hasNoBody ? '' : await readResponseText(response);
	let payload: unknown = null;
	if (!hasNoBody) {
		if (isJson) {
			if (hasContaminatedJsonPrefix(bodyText)) {
				const invalidJson = buildInvalidJsonResponsePayload(response, bodyText, fullUrl);
				throw new ApiError(invalidJson.message, response.status, invalidJson);
			}

			try {
				payload = bodyText.length ? JSON.parse(bodyText) : null;
			} catch {
				const invalidJson = buildInvalidJsonResponsePayload(response, bodyText, fullUrl);
				throw new ApiError(invalidJson.message, response.status, invalidJson);
			}
		} else {
			payload = bodyText;
		}
	}

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

	return payload;
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
