import { notifications } from '$lib/stores/notifications';

export const SESSION_EXPIRED_EVENT = 'sentient-forms:session-expired';

let announced = false;

function isRecord(value: unknown): value is Record<string, unknown> {
	return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function stringValue(value: unknown): string {
	return typeof value === 'string' ? value : '';
}

function payloadCode(payload: unknown): string {
	if (!isRecord(payload)) return '';

	const direct = stringValue(payload.code) || stringValue(payload.error_code);
	if (direct) return direct;

	const error = payload.error;
	return isRecord(error) ? stringValue(error.code) : '';
}

function payloadMessage(payload: unknown): string {
	if (typeof payload === 'string') return payload;
	if (!isRecord(payload)) return '';

	const direct = stringValue(payload.message);
	if (direct) return direct;

	const error = payload.error;
	return isRecord(error) ? stringValue(error.message) : '';
}

export function isWordPressSessionExpired(status: number, payload: unknown): boolean {
	if (status !== 401 && status !== 403) return false;

	const code = payloadCode(payload).toLowerCase();
	if (
		[
			'rest_cookie_invalid_nonce',
			'rest_forbidden',
			'rest_not_logged_in',
			'cookie_check_failed'
		].includes(code)
	) {
		return true;
	}

	const message = payloadMessage(payload).toLowerCase();
	return (
		message.includes('cookie check failed') ||
		message.includes('invalid nonce') ||
		message.includes('rest cookie') ||
		message.includes('not logged in')
	);
}

export function announceWordPressSessionExpired(payload: unknown): void {
	if (announced) return;

	announced = true;
	const message = payloadMessage(payload);
	const detail = {
		message:
			message && !message.toLowerCase().includes('cookie check failed')
				? message
				: 'WordPress session expired. Reload this admin page before retrying.'
	};

	if (typeof window !== 'undefined') {
		window.dispatchEvent(new CustomEvent(SESSION_EXPIRED_EVENT, { detail }));
	}

	notifications.error(detail.message, 0);
}

export function resetSessionExpiryAnnouncementForTests(): void {
	announced = false;
}
