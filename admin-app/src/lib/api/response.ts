export type RestEnvelope<T> = {
	success?: boolean;
	data?: T;
};

function isRecord(value: unknown): value is Record<string, unknown> {
	return Boolean(value && typeof value === 'object' && !Array.isArray(value));
}

export function unwrapRestResponse<T>(payload: T | RestEnvelope<T> | null | undefined): T | null | undefined {
	if (payload === null || payload === undefined) {
		return payload as null | undefined;
	}

	const value: unknown = payload;
	if (isRecord(value) && 'success' in value && 'data' in value) {
		return value.data as T;
	}

	return value as T;
}
