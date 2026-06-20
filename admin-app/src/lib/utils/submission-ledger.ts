import type { SubmissionLedgerRecord } from '$lib/api/types';

function formatLogicalFieldValue(value: unknown): string {
	if (typeof value === 'string') return value;
	if (value === null) return 'null';
	if (typeof value === 'number' || typeof value === 'boolean') return String(value);

	try {
		return JSON.stringify(value) ?? String(value);
	} catch {
		return String(value);
	}
}

export function formatSubmissionLedgerFieldPreview(record: SubmissionLedgerRecord): string {
	const entries = Object.entries(record.logical_fields ?? {});
	if (entries.length === 0) return 'No stored logical fields';

	return entries
		.slice(0, 3)
		.map(([key, value]) => `${key}: ${formatLogicalFieldValue(value)}`)
		.join(', ');
}

export function safeSubmissionNativeEntryUrl(value: string | null | undefined): string | null {
	const trimmed = typeof value === 'string' ? value.trim() : '';
	if (!trimmed) return null;
	if (trimmed.startsWith('//')) return null;
	if (trimmed.startsWith('/')) return trimmed;

	try {
		const url = new URL(trimmed);
		return url.protocol === 'http:' || url.protocol === 'https:' ? url.toString() : null;
	} catch {
		return null;
	}
}
