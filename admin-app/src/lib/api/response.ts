export type RestEnvelope<T> = {
	success: boolean;
	data: T;
};

function isRecord(value: unknown): value is Record<string, unknown> {
	return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

function isRestEnvelope<T>(payload: T | RestEnvelope<T>): payload is RestEnvelope<T> {
	return isRecord(payload) && payload.success === true && 'data' in payload;
}

export function unwrapRestResponse<T>(
	payload: T | RestEnvelope<T> | null | undefined
): T | null | undefined {
	if (payload === null || payload === undefined) {
		return payload;
	}

	if (isRestEnvelope(payload)) {
		return payload.data;
	}

	return payload;
}
