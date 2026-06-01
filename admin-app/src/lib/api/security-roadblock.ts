import { notifications } from '$lib/stores/notifications';

export const SECURITY_ROADBLOCK_EVENT = 'sentient-forms:security-roadblock';
const ROADBLOCK_CODE = 'security_roadblock_interrupted_request';

export type SecurityRoadblockKind =
	| 'cloudflare_challenge'
	| 'cloudflare_block'
	| 'cloudflare_rate_limit'
	| 'site_security_roadblock';

export type SecurityRoadblockConfidence = 'confirmed' | 'suspected' | 'possible';

export interface SecurityRoadblockDetail {
	code: typeof ROADBLOCK_CODE;
	error_code: typeof ROADBLOCK_CODE;
	kind: SecurityRoadblockKind;
	confidence: SecurityRoadblockConfidence;
	label: string;
	message: string;
	status: number;
	provider: 'cloudflare' | 'site_security';
	rayId?: string | null;
	providerDetails?: string[];
	retryEventName?: string;
	data: {
		status: number;
		kind: SecurityRoadblockKind;
		confidence: SecurityRoadblockConfidence;
		provider: 'cloudflare' | 'site_security';
		cf_ray?: string | null;
		provider_details?: string[];
	};
	error: {
		code: typeof ROADBLOCK_CODE;
		message: string;
		meta: {
			status: number;
			kind: SecurityRoadblockKind;
			confidence: SecurityRoadblockConfidence;
			provider: 'cloudflare' | 'site_security';
			cf_ray?: string | null;
			provider_details?: string[];
		};
	};
}

const ROADBLOCK_MESSAGE =
	'This request reached a site security layer before WordPress could process it.';

let announcedKey: string | null = null;

function headerValue(response: Response, name: string): string {
	return response.headers.get(name)?.trim() ?? '';
}

function lowerHeader(response: Response, name: string): string {
	return headerValue(response, name).toLowerCase();
}

function payloadText(payload: unknown): string {
	if (typeof payload === 'string') return payload;
	if (!payload || typeof payload !== 'object') return '';
	try {
		return JSON.stringify(payload);
	} catch {
		return '';
	}
}

function isLikelyHtml(response: Response, text: string): boolean {
	const contentType = lowerHeader(response, 'content-type');
	return contentType.includes('text/html') || /<!doctype html|<html|<title/i.test(text);
}

function rayIdFromResponse(response: Response, text: string): string | null {
	const headerRayId = headerValue(response, 'cf-ray');
	if (headerRayId) return headerRayId;

	const match =
		/(?:cloudflare\s+)?ray\s+id(?:\s*:|\s+)([a-z0-9-]+)/i.exec(text) ??
		/\bRay ID:\s*([a-z0-9-]+)/i.exec(text);
	return match?.[1] ?? null;
}

function hasCloudflareSignal(response: Response, text: string): boolean {
	return Boolean(
		headerValue(response, 'cf-ray') ||
			headerValue(response, 'cf-cache-status') ||
			lowerHeader(response, 'server').includes('cloudflare') ||
			/cloudflare|cf-error|cf-chl|cf-browser-verification|cf-error-details/i.test(text)
	);
}

function hasCloudflareBlockSignal(response: Response, text: string): boolean {
	const isHtml = isLikelyHtml(response, text);
	const cloudflareHtmlSignal =
		isHtml &&
		/cloudflare|cf-error|cf-chl|cf-error-details|ray id|attention required|you are unable to access|sorry, you have been blocked/i.test(
			text
		);

	return Boolean(
		/\berror\s*1020\b/i.test(text) ||
			/(cloudflare.{0,80}(access denied|request blocked|blocked by|firewall|waf|blocked)|(?:access denied|request blocked|blocked by|firewall|waf|blocked).{0,80}cloudflare)/i.test(
				text
			) ||
			cloudflareHtmlSignal
	);
}

function hasCloudflareRateLimitSignal(response: Response, text: string): boolean {
	const lower = text.toLowerCase();
	const isHtml = isLikelyHtml(response, text);
	const cfMitigated = lowerHeader(response, 'cf-mitigated');
	const cloudflareHtmlSignal =
		isHtml && /cloudflare|cf-error|cf-error-details|ray id/i.test(text);

	return Boolean(
		/\berror\s*1015\b/i.test(text) ||
			(cfMitigated === 'challenge' && lower.includes('rate limit')) ||
			(cloudflareHtmlSignal &&
				(lower.includes('you are being rate limited') || lower.includes('rate limit')))
	);
}

function providerDetailsFor(response: Response): string[] {
	const details: string[] = [];
	const cfMitigated = headerValue(response, 'cf-mitigated');
	const server = headerValue(response, 'server');

	if (cfMitigated) details.push(`cf-mitigated: ${cfMitigated}`);
	if (server && server.toLowerCase().includes('cloudflare')) details.push(`server: ${server}`);
	return details;
}

function makeRoadblock(
	response: Response,
	payload: unknown,
	kind: SecurityRoadblockKind,
	confidence: SecurityRoadblockConfidence,
	provider: 'cloudflare' | 'site_security',
	label: string
): SecurityRoadblockDetail {
	const text = payloadText(payload);
	const rayId = rayIdFromResponse(response, text);
	const providerDetails = providerDetailsFor(response);
	const meta = {
		status: response.status,
		kind,
		confidence,
		provider,
		...(rayId ? { cf_ray: rayId } : {}),
		...(providerDetails.length ? { provider_details: providerDetails } : {})
	};

	return {
		code: ROADBLOCK_CODE,
		error_code: ROADBLOCK_CODE,
		kind,
		confidence,
		label,
		message: ROADBLOCK_MESSAGE,
		status: response.status,
		provider,
		rayId,
		providerDetails,
		data: meta,
		error: {
			code: ROADBLOCK_CODE,
			message: ROADBLOCK_MESSAGE,
			meta
		}
	};
}

export function classifySecurityRoadblock(
	response: Response,
	payload: unknown
): SecurityRoadblockDetail | null {
	const text = payloadText(payload);
	const isHtml = isLikelyHtml(response, text);
	const cloudflareSignal = hasCloudflareSignal(response, text);
	const cfMitigated = lowerHeader(response, 'cf-mitigated');

	if (cfMitigated === 'challenge') {
		return makeRoadblock(
			response,
			payload,
			'cloudflare_challenge',
			'confirmed',
			'cloudflare',
			'Confirmed Cloudflare challenge'
		);
	}

	if (
		cloudflareSignal &&
		hasCloudflareRateLimitSignal(response, text)
	) {
		return makeRoadblock(
			response,
			payload,
			'cloudflare_rate_limit',
			'suspected',
			'cloudflare',
			'Cloudflare rate limit suspected'
		);
	}

	if (
		cloudflareSignal &&
		(response.status === 403 || response.status === 429) &&
		hasCloudflareBlockSignal(response, text)
	) {
		return makeRoadblock(
			response,
			payload,
			'cloudflare_block',
			'suspected',
			'cloudflare',
			'Cloudflare block suspected'
		);
	}

	if (
		isHtml &&
		(response.status === 401 ||
			response.status === 403 ||
			response.status === 406 ||
			response.status === 429 ||
			response.status === 503) &&
		/(access denied|blocked|challenge|captcha|verify you are human|just a moment|rate limit|security)/i.test(
			text
		)
	) {
		return makeRoadblock(
			response,
			payload,
			'site_security_roadblock',
			cloudflareSignal ? 'suspected' : 'possible',
			cloudflareSignal ? 'cloudflare' : 'site_security',
			'Site security roadblock'
		);
	}

	return null;
}

export function isSecurityRoadblockPayload(payload: unknown): payload is SecurityRoadblockDetail {
	if (!isRecord(payload)) return false;
	if (payload.code !== ROADBLOCK_CODE || payload.error_code !== ROADBLOCK_CODE) return false;
	if (!isSecurityRoadblockKind(payload.kind)) return false;
	if (!isSecurityRoadblockConfidence(payload.confidence)) return false;
	if (!isSecurityRoadblockProvider(payload.provider)) return false;
	if (typeof payload.label !== 'string' || payload.label.trim().length === 0) return false;
	if (typeof payload.message !== 'string' || payload.message.trim().length === 0) return false;
	if (typeof payload.status !== 'number' || !Number.isFinite(payload.status)) return false;
	if (
		payload.rayId !== undefined &&
		payload.rayId !== null &&
		typeof payload.rayId !== 'string'
	) {
		return false;
	}
	if (payload.providerDetails !== undefined && !isStringArray(payload.providerDetails)) return false;
	if (payload.retryEventName !== undefined && typeof payload.retryEventName !== 'string') return false;
	if (!isRecord(payload.data)) return false;
	if (payload.data.status !== payload.status) return false;
	if (payload.data.kind !== payload.kind) return false;
	if (payload.data.confidence !== payload.confidence) return false;
	if (payload.data.provider !== payload.provider) return false;
	if (
		payload.data.cf_ray !== undefined &&
		payload.data.cf_ray !== null &&
		typeof payload.data.cf_ray !== 'string'
	) {
		return false;
	}
	if (payload.data.provider_details !== undefined && !isStringArray(payload.data.provider_details)) {
		return false;
	}
	if (!isRecord(payload.error)) return false;
	if (payload.error.code !== ROADBLOCK_CODE) return false;
	if (typeof payload.error.message !== 'string' || payload.error.message.trim().length === 0) {
		return false;
	}
	if (!isRecord(payload.error.meta)) return false;
	if (payload.error.meta.status !== payload.status) return false;
	if (payload.error.meta.kind !== payload.kind) return false;
	if (payload.error.meta.confidence !== payload.confidence) return false;
	if (payload.error.meta.provider !== payload.provider) return false;
	if (
		payload.error.meta.cf_ray !== undefined &&
		payload.error.meta.cf_ray !== null &&
		typeof payload.error.meta.cf_ray !== 'string'
	) {
		return false;
	}
	if (
		payload.error.meta.provider_details !== undefined &&
		!isStringArray(payload.error.meta.provider_details)
	) {
		return false;
	}
	return true;
}

export function announceSecurityRoadblock(detail: SecurityRoadblockDetail): void {
	const key = detail.rayId ? `${detail.kind}:${detail.rayId}` : null;
	if (key && announcedKey === key) return;
	if (key) announcedKey = key;

	if (typeof window !== 'undefined') {
		window.dispatchEvent(new CustomEvent(SECURITY_ROADBLOCK_EVENT, { detail }));
	}
}

export function securityRoadblockNotificationMessage(detail: SecurityRoadblockDetail): string {
	return `${detail.label}: ${detail.message}`;
}

export function notifySecurityRoadblock(detail: SecurityRoadblockDetail): void {
	notifications.error(securityRoadblockNotificationMessage(detail));
}

export function resetSecurityRoadblockAnnouncementForTests(): void {
	announcedKey = null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
	return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function isStringArray(value: unknown): value is string[] {
	return Array.isArray(value) && value.every((item) => typeof item === 'string');
}

function isSecurityRoadblockKind(value: unknown): value is SecurityRoadblockKind {
	return (
		value === 'cloudflare_challenge' ||
		value === 'cloudflare_block' ||
		value === 'cloudflare_rate_limit' ||
		value === 'site_security_roadblock'
	);
}

function isSecurityRoadblockConfidence(
	value: unknown
): value is SecurityRoadblockConfidence {
	return value === 'confirmed' || value === 'suspected' || value === 'possible';
}

function isSecurityRoadblockProvider(
	value: unknown
): value is SecurityRoadblockDetail['provider'] {
	return value === 'cloudflare' || value === 'site_security';
}
