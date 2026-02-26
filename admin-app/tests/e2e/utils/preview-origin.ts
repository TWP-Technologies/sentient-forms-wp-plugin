const DEFAULT_PREVIEW_HOST = '127.0.0.1';
const DEFAULT_PREVIEW_PORT = 4175;
const MIN_PORT = 1;
const MAX_PORT = 65_535;

function parsePort(rawValue: string | undefined): number {
	if (typeof rawValue !== 'string') return DEFAULT_PREVIEW_PORT;
	const normalized = rawValue.trim();
	if (normalized.length === 0) return DEFAULT_PREVIEW_PORT;
	if (!/^\d+$/.test(normalized)) {
		throw new Error(`PREVIEW_PORT must be numeric (received "${normalized}").`);
	}

	const parsed = Number(normalized);
	if (!Number.isInteger(parsed) || parsed < MIN_PORT || parsed > MAX_PORT) {
		throw new Error(`PREVIEW_PORT must be between ${MIN_PORT} and ${MAX_PORT}.`);
	}

	return parsed;
}

export function getPreviewHost(): string {
	const rawValue = process.env.PREVIEW_HOST;
	if (typeof rawValue !== 'string') return DEFAULT_PREVIEW_HOST;
	const normalized = rawValue.trim();
	return normalized.length > 0 ? normalized : DEFAULT_PREVIEW_HOST;
}

export function getPreviewPort(): number {
	return parsePort(process.env.PREVIEW_PORT);
}

export function getPreviewOrigin(): string {
	return `http://${getPreviewHost()}:${getPreviewPort()}`;
}
