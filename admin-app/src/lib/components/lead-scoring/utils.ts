import type { LeadGrade, LeadScoringEntry, LeadScoringFormSummary } from '$lib/api/types';

export const gradeOrder: Array<LeadGrade | 'ungraded'> = ['A', 'B', 'C', 'Reject', 'ungraded'];
export type LeadScoringViewKey = 'dashboard' | 'setup' | 'historical';

export function providerLabel(source: string, label?: string | null): string {
	if (label) return label;
	if (source === 'gravity_forms' || source === 'gravity-forms') return 'Gravity Forms';
	return source
		.split(/[_-]+/)
		.filter(Boolean)
		.map((part) => `${part.charAt(0).toUpperCase()}${part.slice(1)}`)
		.join(' ');
}

export function entryDateValue(entry: LeadScoringEntry): string {
	return entry.entry_snapshot?.date_created ?? entry.updated_at ?? '';
}

export function parseDateTime(value?: string | null): Date | null {
	if (!value) return null;
	const normalized = value.includes('T') ? value : value.replace(' ', 'T');
	const hasZone = /(?:Z|[+-]\d\d:?\d\d)$/.test(normalized);
	const date = new Date(hasZone ? normalized : `${normalized}Z`);
	return Number.isNaN(date.getTime()) ? null : date;
}

export function absoluteDateTime(value?: string | null): string {
	const date = parseDateTime(value);
	return date
		? new Intl.DateTimeFormat(undefined, {
				dateStyle: 'medium',
				timeStyle: 'medium'
			}).format(date)
		: '';
}

export function relativeDateTime(value?: string | null, nowMs = Date.now()): string {
	const date = parseDateTime(value);
	if (!date) return '';
	const seconds = Math.round((date.getTime() - nowMs) / 1000);
	const absSeconds = Math.abs(seconds);
	const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
	if (absSeconds < 60) return formatter.format(seconds, 'second');
	const minutes = Math.round(seconds / 60);
	if (Math.abs(minutes) < 60) return formatter.format(minutes, 'minute');
	const hours = Math.round(minutes / 60);
	if (Math.abs(hours) < 24) return formatter.format(hours, 'hour');
	const days = Math.round(hours / 24);
	if (Math.abs(days) < 30) return formatter.format(days, 'day');
	const months = Math.round(days / 30);
	if (Math.abs(months) < 12) return formatter.format(months, 'month');
	return formatter.format(Math.round(months / 12), 'year');
}

export function gradeBadgeVariant(grade?: LeadGrade | '' | null): 'success' | 'danger' | 'neutral' {
	if (grade === 'A') return 'success';
	if (grade === 'Reject') return 'danger';
	return 'neutral';
}

export function gradeTone(grade: LeadGrade | 'ungraded'): string {
	if (grade === 'A') return 'sf:bg-emerald-500';
	if (grade === 'B') return 'sf:bg-sky-500';
	if (grade === 'C') return 'sf:bg-amber-500';
	if (grade === 'Reject') return 'sf:bg-rose-500';
	return 'sf:bg-slate-300';
}

export function gradeWidth(
	grades: Record<LeadGrade | 'ungraded', number> | undefined,
	grade: LeadGrade | 'ungraded'
): string {
	const total = Object.values(grades ?? {}).reduce((sum, count) => sum + Number(count ?? 0), 0);
	const count = Number(grades?.[grade] ?? 0);
	return `${Math.max(total > 0 ? (count / total) * 100 : 0, count > 0 ? 8 : 0)}%`;
}

export function setupPath(
	entry: Pick<LeadScoringEntry | LeadScoringFormSummary, 'form_source' | 'form_id'>
): string {
	return `/actions/${entry.form_source}/${entry.form_id}/lead-value`;
}

export function setupJumpPath(
	entry: Pick<LeadScoringEntry | LeadScoringFormSummary, 'form_source' | 'form_id'>
): string {
	return `${setupPath(entry)}?view=setup`;
}

export function leadScoringViewFromLocation(
	search: string,
	hash: string
): LeadScoringViewKey | null {
	const hashQuery = hash.includes('?') ? (hash.split('?')[1] ?? '') : '';
	const value =
		new URLSearchParams(hashQuery).get('view') ?? new URLSearchParams(search).get('view');
	return value === 'setup' || value === 'historical' || value === 'dashboard' ? value : null;
}

export function detailPath(basePath: string, entry: LeadScoringEntry): string {
	const params = new URLSearchParams({
		entry: String(entry.entry_id),
		form_source: String(entry.form_source),
		form_id: String(entry.form_id)
	});
	return `${basePath}?${params.toString()}`;
}
