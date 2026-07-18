import { createCheckoutAttemptId } from '$lib/api/checkout-attempt-id';

const UUID_V4_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

describe('checkout attempt identity', () => {
	it('prefers the browser native UUID implementation when it is available', () => {
		const expected = '11111111-1111-4111-8111-111111111111';
		const getRandomValues = vi.fn();

		expect(
			createCheckoutAttemptId({
				randomUUID: () => expected,
				getRandomValues
			})
		).toBe(expected);
		expect(getRandomValues).not.toHaveBeenCalled();
	});

	it('creates an RFC 4122 version 4 UUID from secure random bytes without randomUUID', () => {
		const getRandomValues = vi.fn((bytes: Uint8Array) => {
			bytes.set([
				0x00, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, 0x77, 0xff, 0x99, 0xaa, 0xbb, 0xcc, 0xdd, 0xee,
				0xff
			]);
			return bytes;
		});

		const attemptId = createCheckoutAttemptId({ getRandomValues });

		expect(attemptId).toBe('00112233-4455-4677-bf99-aabbccddeeff');
		expect(attemptId).toMatch(UUID_V4_PATTERN);
		expect(getRandomValues).toHaveBeenCalledTimes(1);
	});

	it('falls back to secure random bytes when an exposed randomUUID implementation fails', () => {
		const getRandomValues = vi.fn((bytes: Uint8Array) => {
			bytes.fill(0xaa);
			return bytes;
		});

		expect(
			createCheckoutAttemptId({
				randomUUID: () => {
					throw new DOMException('Secure context required', 'NotAllowedError');
				},
				getRandomValues
			})
		).toBe('aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa');
	});

	it('fails closed rather than using weak randomness when browser crypto is unavailable', () => {
		expect(() => createCheckoutAttemptId(null)).toThrow(
			'Secure checkout identity is unavailable in this browser.'
		);
	});
});
