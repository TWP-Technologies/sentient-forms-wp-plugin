const UUID_V4_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const SECURE_IDENTITY_UNAVAILABLE = 'Secure checkout identity is unavailable in this browser.';

interface CheckoutCrypto {
	randomUUID?: () => string;
	getRandomValues?: (bytes: Uint8Array) => Uint8Array;
}

function browserCrypto(): CheckoutCrypto | null {
	return typeof globalThis.crypto === 'undefined' ? null : globalThis.crypto;
}

function formatUuid(bytes: Uint8Array): string {
	const hexadecimal = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

	return [
		hexadecimal.slice(0, 8),
		hexadecimal.slice(8, 12),
		hexadecimal.slice(12, 16),
		hexadecimal.slice(16, 20),
		hexadecimal.slice(20)
	].join('-');
}

export function createCheckoutAttemptId(
	cryptoSource: CheckoutCrypto | null = browserCrypto()
): string {
	if (typeof cryptoSource?.randomUUID === 'function') {
		try {
			const nativeUuid = cryptoSource.randomUUID();
			if (UUID_V4_PATTERN.test(nativeUuid)) {
				return nativeUuid;
			}
		} catch {
			// Some insecure contexts expose randomUUID but reject calls. Use getRandomValues below.
		}
	}

	if (typeof cryptoSource?.getRandomValues !== 'function') {
		throw new Error(SECURE_IDENTITY_UNAVAILABLE);
	}

	const bytes = cryptoSource.getRandomValues(new Uint8Array(16));
	if (bytes.byteLength !== 16) {
		throw new Error(SECURE_IDENTITY_UNAVAILABLE);
	}

	bytes[6] = (bytes[6] & 0x0f) | 0x40;
	bytes[8] = (bytes[8] & 0x3f) | 0x80;

	return formatUuid(bytes);
}
