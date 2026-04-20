import type { CreditBalanceResponse, TierSummary } from '$lib/api/types';

export type CreditSeverity = 'normal' | 'warning' | 'critical' | 'unknown';
export type QuotaCtaAction =
	| 'navigate_licensing'
	| 'focus_licensing_billing'
	| 'open_billing'
	| 'none';
export type QuotaUiContext = 'dashboard' | 'licensing' | 'global';

export interface QuotaCtaState {
	label: string;
	enabled: boolean;
	reason: string;
	action: QuotaCtaAction;
}

export interface CreditPresentationState {
	balance: number | null;
	quota: number | null;
	percentage: number | null;
	severity: CreditSeverity;
	headline: string;
	detail: string;
	calloutTitle: string;
	quotaCta: QuotaCtaState | null;
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

export function resolveTierDisplayName(
	tier: string | TierSummary | null | undefined
): string | null {
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

export function isConnectedLicenseStatus(status: string | null | undefined): boolean {
	return status === 'active' || status === 'trial';
}

export function resolveCreditSeverity(
	balance: number | null,
	quota: number | null
): CreditSeverity {
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
	if (isConnectedLicenseStatus(status)) return 'success';
	if (status === 'error') return 'danger';
	return 'warning';
}

function buildQuotaCtaState(
	severity: CreditSeverity,
	context: QuotaUiContext,
	balance: number | null
): QuotaCtaState | null {
	if (severity === 'normal') {
		return null;
	}

	const hasNegativeBalance = balance !== null && balance < 0;

	if (context === 'global' && (severity === 'warning' || severity === 'critical')) {
		return {
			label: hasNegativeBalance ? 'Resolve balance' : 'Review billing',
			enabled: true,
			reason: hasNegativeBalance
				? 'Open Licensing to review billing and restore managed execution.'
				: 'Open Licensing to review managed usage and plan options.',
			action: 'focus_licensing_billing'
		};
	}

	if (context === 'dashboard' && (severity === 'warning' || severity === 'critical')) {
		return {
			label: hasNegativeBalance ? 'Resolve balance' : 'Review billing',
			enabled: true,
			reason: hasNegativeBalance
				? 'Open Licensing to review billing and restore managed execution.'
				: 'Open Licensing to review managed usage and plan options.',
			action: 'focus_licensing_billing'
		};
	}

	if (context === 'dashboard' && severity === 'unknown') {
		return {
			label: 'Review licensing',
			enabled: false,
			reason: 'Credit quota details are unavailable right now. Refresh and try again.',
			action: 'none'
		};
	}

	if (context === 'licensing' && (severity === 'warning' || severity === 'critical')) {
		return {
			label: hasNegativeBalance ? 'Resolve balance' : 'Review billing',
			enabled: true,
			reason: hasNegativeBalance
				? 'Jump to the billing section to restore managed execution.'
				: 'Jump to the billing section to review managed usage and plan options.',
			action: 'focus_licensing_billing'
		};
	}

	if (context === 'licensing' && severity === 'unknown') {
		return {
			label: 'Refresh billing state',
			enabled: false,
			reason:
				'Billing details are temporarily unavailable. Retry after the current refresh finishes.',
			action: 'none'
		};
	}

	return {
		label: 'Review licensing',
		enabled: false,
		reason: 'Review licensing details to confirm credit status and next actions.',
		action: 'none'
	};
}

export function buildCreditPresentation(
	credits: CreditBalanceResponse | null | undefined,
	resetSummary: string,
	context: QuotaUiContext = 'dashboard'
): CreditPresentationState {
	const balance = normalizeBalance(credits);
	const quota = normalizeQuota(credits);
	const severity = resolveCreditSeverity(balance, quota);
	const hasNegativeBalance = balance !== null && balance < 0;

	const percentage =
		balance !== null && quota !== null
			? clampPercentage(Math.round((balance / quota) * 100))
			: null;

	let headline = 'Credit balance unavailable';
	let calloutTitle = 'Credit balance unavailable';
	if (balance !== null && quota !== null) {
		headline = `${balance} / ${quota} credits remaining`;
	}

	if (hasNegativeBalance && balance !== null) {
		headline = `Negative balance: ${balance} credits`;
		calloutTitle = 'Negative credit balance';
	} else if (severity === 'critical') {
		headline = 'No credits remaining';
		calloutTitle = 'No credits remaining';
	} else if (severity === 'warning' && balance !== null && quota !== null) {
		headline = `Low credits: ${balance} / ${quota}`;
		calloutTitle = 'Low credits remaining';
	} else if (severity === 'unknown' && balance !== null) {
		headline = `${balance} credits remaining`;
		calloutTitle = 'Credit balance unavailable';
	} else if (severity === 'normal') {
		calloutTitle = 'Credit usage healthy';
	}

	let detail = `${resetSummary}.`;
	if (hasNegativeBalance) {
		detail = `A recent run settled above its estimate. New runs are paused until the balance returns to zero or above. ${resetSummary}.`;
	} else if (severity === 'normal') {
		detail = `${resetSummary}. Usage is healthy.`;
	} else if (severity === 'warning') {
		detail = `${resetSummary}. Low balance, consider upgrading soon to avoid interruptions.`;
	} else if (severity === 'critical') {
		detail = `Actions may pause until credits reset or you add more. ${resetSummary}.`;
	} else if (severity === 'unknown') {
		detail = `Credit details are currently unavailable. ${resetSummary}.`;
	}

	return {
		balance,
		quota,
		percentage,
		severity,
		headline,
		detail,
		calloutTitle,
		quotaCta: buildQuotaCtaState(severity, context, balance)
	};
}
