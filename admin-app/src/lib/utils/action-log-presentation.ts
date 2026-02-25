export type ActionLogStatus = 'pending' | 'success' | 'error';

export type ActionLogFilters = {
	formId: string;
	status: '' | ActionLogStatus;
	actionCode: string;
};

export type ActiveFilterChipKey = 'form_id' | 'status' | 'action_code';

export type ActiveFilterChip = {
	key: ActiveFilterChipKey;
	label: string;
	value: string;
};

export type ActionLogStatusVariant = 'success' | 'warning' | 'danger';
export type ActionLogOutputVariant = 'success' | 'warning' | 'neutral';
export type ActionLogResultVariant = 'success' | 'danger' | 'neutral';

export type ActionLogRowInput = {
	status: ActionLogStatus;
	structuredOutputValid: boolean;
	classification: string | null;
	resultSummary: string | null;
	errorCode: string | null;
	errorMessage: string | null;
};

export type ActionLogRowPresentation = {
	statusLabel: string;
	statusVariant: ActionLogStatusVariant;
	outputLabel: string;
	outputVariant: ActionLogOutputVariant;
	resultLabel: string;
	resultVariant: ActionLogResultVariant;
	resultKind: 'badge' | 'text';
	resultTitle?: string;
};

export type PaginationInput = {
	page: number;
	perPage: number;
	total: number;
	totalPages: number;
};

export type PaginationPresentation = {
	start: number;
	end: number;
	total: number;
	page: number;
	totalPages: number;
	rangeLabel: string;
	pageLabel: string;
};

const STATUS_LABELS: Record<ActionLogStatus, string> = {
	success: 'Success',
	pending: 'Pending',
	error: 'Error'
};

function toTitleCase(value: string): string {
	return value
		.split(/[\s_-]+/)
		.filter(Boolean)
		.map((part) => `${part.slice(0, 1).toUpperCase()}${part.slice(1).toLowerCase()}`)
		.join(' ');
}

function truncate(value: string, maxLength: number): string {
	if (value.length <= maxLength) {
		return value;
	}

	return `${value.slice(0, maxLength - 3)}...`;
}

export function normalizeActionLogFilters(filters: ActionLogFilters): ActionLogFilters {
	return {
		formId: filters.formId.trim(),
		status: filters.status,
		actionCode: filters.actionCode.trim()
	};
}

export function buildActiveFilterChips(filters: ActionLogFilters): ActiveFilterChip[] {
	const chips: ActiveFilterChip[] = [];
	const normalized = normalizeActionLogFilters(filters);

	if (normalized.formId) {
		chips.push({
			key: 'form_id',
			label: 'Form ID',
			value: normalized.formId
		});
	}

	if (normalized.status) {
		chips.push({
			key: 'status',
			label: 'Status',
			value: STATUS_LABELS[normalized.status]
		});
	}

	if (normalized.actionCode) {
		chips.push({
			key: 'action_code',
			label: 'Action',
			value: normalized.actionCode
		});
	}

	return chips;
}

export function statusVariantForActionLog(status: ActionLogStatus): ActionLogStatusVariant {
	switch (status) {
		case 'success':
			return 'success';
		case 'pending':
			return 'warning';
		case 'error':
			return 'danger';
		default:
			return 'warning';
	}
}

export function buildActionLogRowPresentation(entry: ActionLogRowInput): ActionLogRowPresentation {
	const statusLabel = STATUS_LABELS[entry.status];
	const statusVariant = statusVariantForActionLog(entry.status);

	const outputLabel = entry.status === 'success'
		? entry.structuredOutputValid
			? 'Structured'
			: 'Raw'
		: 'Not available';
	const outputVariant = entry.status === 'success'
		? entry.structuredOutputValid
			? 'success'
			: 'warning'
		: 'neutral';

	const classification = entry.classification?.trim() ?? '';
	if (classification) {
		const normalized = classification.toLowerCase();
		return {
			statusLabel,
			statusVariant,
			outputLabel,
			outputVariant,
			resultLabel: toTitleCase(classification),
			resultVariant: normalized === 'spam' ? 'danger' : 'success',
			resultKind: 'badge'
		};
	}

	const resultSummary = entry.resultSummary?.trim() ?? '';
	if (resultSummary) {
		return {
			statusLabel,
			statusVariant,
			outputLabel,
			outputVariant,
			resultLabel: truncate(resultSummary, 80),
			resultVariant: 'neutral',
			resultKind: 'text',
			resultTitle: resultSummary
		};
	}

	const errorMessage = entry.errorMessage?.trim() ?? '';
	if (errorMessage) {
		const errorLabel = `${entry.errorCode ?? 'Error'}: ${errorMessage}`;
		return {
			statusLabel,
			statusVariant,
			outputLabel,
			outputVariant,
			resultLabel: truncate(errorLabel, 80),
			resultVariant: 'danger',
			resultKind: 'text',
			resultTitle: errorLabel
		};
	}

	return {
		statusLabel,
		statusVariant,
		outputLabel,
		outputVariant,
		resultLabel: 'No result',
		resultVariant: 'neutral',
		resultKind: 'text'
	};
}

function clamp(value: number, min: number, max: number): number {
	return Math.min(Math.max(value, min), max);
}

export function buildPaginationPresentation(input: PaginationInput): PaginationPresentation {
	const total = Math.max(0, input.total);
	const perPage = Math.max(1, input.perPage);
	const totalPages = Math.max(1, input.totalPages);
	const page = clamp(input.page, 1, totalPages);

	if (total === 0) {
		return {
			start: 0,
			end: 0,
			total,
			page,
			totalPages,
			rangeLabel: 'Showing 0 of 0',
			pageLabel: `Page ${page} of ${totalPages}`
		};
	}

	const start = (page - 1) * perPage + 1;
	const end = Math.min(page * perPage, total);

	return {
		start,
		end,
		total,
		page,
		totalPages,
		rangeLabel: `Showing ${start}-${end} of ${total}`,
		pageLabel: `Page ${page} of ${totalPages}`
	};
}
