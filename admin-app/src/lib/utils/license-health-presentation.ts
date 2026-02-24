import type { CreditBalanceResponse, TierSummary } from '$lib/api/types';

export type CreditSeverity = 'normal' | 'warning' | 'critical' | 'unknown';

export interface CreditPresentationState {
	balance: number | null;
	quota: number | null;
	percentage: number | null;
	severity: CreditSeverity;
	headline: string;
	detail: string;
}

function isFiniteNumber(value: unknown): value is number {
	return typeof value === 'number' && Number.isFinite(value);
}

function normalizeBalance(credits: CreditBalanceResponse | null | undefined): number | null {
	return isFiniteNumber(credits?.current_balance) ? credits.current_balance : null;
}

function normalizeQuota(credits: CreditBalanceResponse | null | undefined): number | null {
	const quota = credits?.tier?.monthly_credit_quota;
	if (!isFiniteNumber(quota)) {
		return null;
	}

	return quota > 0 ? quota : null;
}

function clampPercentage(value: number): number {
	if (value < 0) return 0;
	if (value > 100) return 100;
	return value;
}

export function resolveTierDisplayName(tier: string | TierSummary | null | undefined): string | null {
	if (typeof tier === 'string') {
		const trimmed = tier.trim();
		return trimmed.length > 0 ? trimmed : null;
	}

	if (tier && typeof tier === 'object') {
		if (typeof tier.display_name === 'string' && tier.display_name.trim().length > 0) {
			return tier.display_name.trim();
		}
		if (typeof tier.code === 'string' && tier.code.trim().length > 0) {
			return tier.code.trim();
		}
	}

	return null;
}

export function resolveCreditSeverity(balance: number | null, quota: number | null): CreditSeverity {
	if (balance === null || quota === null) {
		return 'unknown';
	}

	if (balance <= 0) {
		return 'critical';
	}

	const percentage = (balance / quota) * 100;
	if (percentage <= 10) {
		return 'warning';
	}

	return 'normal';
}

export function formatCreditSeverityLabel(severity: CreditSeverity): string {
	switch (severity) {
		case 'normal':
			return 'Healthy';
		case 'warning':
			return 'Low';
		case 'critical':
			return 'Exhausted';
		default:
			return 'Unknown';
	}
}

export function creditSeverityToBadgeVariant(
	severity: CreditSeverity
): 'neutral' | 'success' | 'warning' | 'danger' {
	switch (severity) {
		case 'normal':
			return 'success';
		case 'warning':
			return 'warning';
		case 'critical':
			return 'danger';
		default:
			return 'neutral';
	}
}

export function licenseStatusToBadgeVariant(
	status: string | null | undefined
): 'success' | 'warning' | 'danger' {
	if (status === 'active') return 'success';
	if (status === 'error') return 'danger';
	return 'warning';
}

export function buildCreditPresentation(
	credits: CreditBalanceResponse | null | undefined,
	resetSummary: string
): CreditPresentationState {
	const balance = normalizeBalance(credits);
	const quota = normalizeQuota(credits);
	const severity = resolveCreditSeverity(balance, quota);

	const percentage =
		balance !== null && quota !== null ? clampPercentage(Math.round((balance / quota) * 100)) : null;

	let headline = 'Credit balance unavailable';
	if (balance !== null && quota !== null) {
		headline = `${balance} / ${quota} credits remaining`;
	}

	if (severity === 'critical') {
		headline = 'No credits remaining';
	} else if (severity === 'warning' && balance !== null && quota !== null) {
		headline = `Low credits: ${balance} / ${quota}`;
	} else if (severity === 'unknown' && balance !== null) {
		headline = `${balance} credits remaining`;
	}

	let detail = `${resetSummary}.`;
	if (severity === 'normal') {
		detail = `${resetSummary}. Usage is healthy.`;
	} else if (severity === 'warning') {
		detail = `${resetSummary}. Low balance, consider upgrading soon to avoid interruptions.`;
	} else if (severity === 'critical') {
		detail = `Actions may pause until credits reset. ${resetSummary}.`;
	} else if (severity === 'unknown') {
		detail = `Credit details are currently unavailable. ${resetSummary}.`;
	}

	return {
		balance,
		quota,
		percentage,
		severity,
		headline,
		detail
	};
}
