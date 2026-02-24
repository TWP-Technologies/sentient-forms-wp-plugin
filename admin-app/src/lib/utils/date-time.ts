import type { CustomAction } from '$lib/api/types';

const UPDATE_FORMATTER = new Intl.DateTimeFormat(undefined, {
	dateStyle: 'medium',
	timeStyle: 'short'
});

const TIME_ONLY_FORMATTER = new Intl.DateTimeFormat(undefined, {
	timeStyle: 'short'
});

const EPOCH_MS_PATTERN = /(^|[^0-9])(1\d{12,16})([^0-9]|$)/;
const MYSQL_DATETIME_PATTERN =
	/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}:\d{2}:\d{2})(?:\.\d+)?(?:\s*([+-]\d{2}:\d{2}(?::\d{2})?|Z))?$/;

export function safeParseTimestamp(value: unknown): number | null {
	if (value === null || value === undefined) {
		return null;
	}

	if (typeof value === 'number') {
		return Number.isFinite(value) ? value : null;
	}

	if (value instanceof Date) {
		const dateValue = value.getTime();
		return Number.isFinite(dateValue) ? dateValue : null;
	}

	if (typeof value !== 'string') {
		return null;
	}

	const trimmed = value.trim();
	if (!trimmed || trimmed.toLowerCase() === 'array') {
		return null;
	}

	const parsed = Date.parse(trimmed);
	if (Number.isFinite(parsed)) {
		return parsed;
	}

	const mysqlStyle = trimmed.match(MYSQL_DATETIME_PATTERN);
	if (mysqlStyle) {
		const [, datePart, timePart, timezone] = mysqlStyle;
		const normalized = `${datePart}T${timePart}${timezone ?? 'Z'}`;
		const normalizedParsed = Date.parse(normalized);
		if (Number.isFinite(normalizedParsed)) {
			return normalizedParsed;
		}
	}

	return null;
}

export function formatTimestamp(value: unknown, placeholder = '—'): string {
	const epochMs = safeParseTimestamp(value);
	if (epochMs === null) {
		return placeholder;
	}

	return UPDATE_FORMATTER.format(new Date(epochMs));
}

export function formatTimeOnly(value: unknown, placeholder = 'never'): string {
	const epochMs = safeParseTimestamp(value);
	if (epochMs === null) {
		return placeholder;
	}

	return TIME_ONLY_FORMATTER.format(new Date(epochMs));
}

function parseNumericIdHint(id: string): number | null {
	if (/^\d+$/.test(id)) {
		const numericId = Number.parseInt(id, 10);
		return Number.isFinite(numericId) ? numericId : null;
	}

	return null;
}

function parseEpochFromCodeHint(code: string): number | null {
	const match = code.match(EPOCH_MS_PATTERN);
	if (!match?.[2]) {
		return null;
	}

	const numericHint = Number.parseInt(match[2], 10);
	return Number.isFinite(numericHint) ? numericHint : null;
}

function compareNullableDesc(a: number | null, b: number | null): number {
	if (a !== null && b !== null) {
		return b - a;
	}
	if (a !== null) {
		return -1;
	}
	if (b !== null) {
		return 1;
	}
	return 0;
}

export function sortCustomActionsByRecency(actions: CustomAction[]): CustomAction[] {
	const decorated = actions.map((action, index) => ({
		action,
		index,
		updatedAt: safeParseTimestamp(action.updated_at),
		createdAt: safeParseTimestamp(action.created_at),
		numericIdHint: parseNumericIdHint(action.id),
		epochFromCodeHint: parseEpochFromCodeHint(action.code)
	}));

	decorated.sort((left, right) => {
		const byUpdatedAt = compareNullableDesc(left.updatedAt, right.updatedAt);
		if (byUpdatedAt !== 0) return byUpdatedAt;

		const byCreatedAt = compareNullableDesc(left.createdAt, right.createdAt);
		if (byCreatedAt !== 0) return byCreatedAt;

		const byNumericId = compareNullableDesc(left.numericIdHint, right.numericIdHint);
		if (byNumericId !== 0) return byNumericId;

		const byCodeEpoch = compareNullableDesc(left.epochFromCodeHint, right.epochFromCodeHint);
		if (byCodeEpoch !== 0) return byCodeEpoch;

		const byCode = left.action.code.localeCompare(right.action.code);
		if (byCode !== 0) return byCode;

		const byId = left.action.id.localeCompare(right.action.id);
		if (byId !== 0) return byId;

		return left.index - right.index;
	});

	return decorated.map(({ action }) => action);
}
