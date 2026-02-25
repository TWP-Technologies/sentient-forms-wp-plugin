import type { CreditBalanceResponse } from '$lib/api/types';
import {
	buildCreditPresentation,
	creditSeverityToBadgeVariant,
	formatCreditSeverityLabel,
	licenseStatusToBadgeVariant,
	resolveCreditSeverity,
	resolveTierDisplayName
} from '$lib/utils/license-health-presentation';
import { describe, expect, it } from 'vitest';

function withCredits(balance: number, quota: number | null): CreditBalanceResponse {
	return {
		current_balance: balance,
		tier:
			quota === null
				? null
				: {
					code: 'starter',
					display_name: 'Starter',
					monthly_credit_quota: quota
				}
	};
}

describe('license-health-presentation', () => {
	it('marks credits as normal when usage is above warning threshold', () => {
		const presentation = buildCreditPresentation(withCredits(75, 100), 'Resets Mar 1 (5 days)');
		expect(presentation.severity).toBe('normal');
		expect(presentation.headline).toBe('75 / 100 credits remaining');
		expect(presentation.detail).toContain('Usage is healthy.');
		expect(presentation.percentage).toBe(75);
		expect(presentation.quotaCta).toBeNull();
	});

	it('marks credits as warning when usage is at or below 10%', () => {
		expect(resolveCreditSeverity(10, 100)).toBe('warning');
		expect(resolveCreditSeverity(1, 100)).toBe('warning');
		const presentation = buildCreditPresentation(withCredits(10, 100), 'Resets Mar 1 (5 days)');
		expect(presentation.headline).toBe('Low credits: 10 / 100');
		expect(presentation.detail).toContain('Low balance');
		expect(presentation.quotaCta).toEqual({
			label: 'Review licensing',
			enabled: true,
			reason: 'Open Licensing to review current credit status and next steps.',
			action: 'navigate_licensing'
		});
	});

	it('marks credits as critical when balance is zero', () => {
		const presentation = buildCreditPresentation(withCredits(0, 100), 'Resets Mar 1 (5 days)');
		expect(presentation.severity).toBe('critical');
		expect(presentation.headline).toBe('No credits remaining');
		expect(presentation.detail).toContain('Actions may pause');
		expect(presentation.percentage).toBe(0);
		expect(presentation.quotaCta?.enabled).toBe(true);
		expect(presentation.quotaCta?.action).toBe('navigate_licensing');
	});

	it('returns unknown severity when quota metadata is missing', () => {
		const presentation = buildCreditPresentation(withCredits(42, null), 'Resets Mar 1 (5 days)');
		expect(presentation.severity).toBe('unknown');
		expect(presentation.headline).toBe('42 credits remaining');
		expect(presentation.detail).toContain('currently unavailable');
		expect(presentation.percentage).toBeNull();
		expect(presentation.quotaCta).toEqual({
			label: 'Review licensing',
			enabled: false,
			reason: 'Credit quota details are unavailable right now. Refresh and try again.',
			action: 'none'
		});
	});

	it('returns unknown values when credits payload is absent', () => {
		const presentation = buildCreditPresentation(null, 'Resets Mar 1 (5 days)');
		expect(presentation.severity).toBe('unknown');
		expect(presentation.balance).toBeNull();
		expect(presentation.quota).toBeNull();
		expect(presentation.headline).toBe('Credit balance unavailable');
		expect(presentation.quotaCta?.enabled).toBe(false);
		expect(presentation.quotaCta?.action).toBe('none');
	});

	it('uses disabled placeholder CTA in licensing context for warning and critical states', () => {
		const warningPresentation = buildCreditPresentation(
			withCredits(5, 100),
			'Resets Mar 1 (5 days)',
			'licensing'
		);
		const criticalPresentation = buildCreditPresentation(
			withCredits(0, 100),
			'Resets Mar 1 (5 days)',
			'licensing'
		);

		expect(warningPresentation.quotaCta).toEqual({
			label: 'Billing controls coming soon',
			enabled: false,
			reason:
				'In-app billing, plan details, and auto top-up controls are not available in this build yet.',
			action: 'none'
		});
		expect(criticalPresentation.quotaCta).toEqual({
			label: 'Billing controls coming soon',
			enabled: false,
			reason:
				'In-app billing, plan details, and auto top-up controls are not available in this build yet.',
			action: 'none'
		});
	});

	it('maps severity and status to badge variants', () => {
		expect(creditSeverityToBadgeVariant('normal')).toBe('success');
		expect(creditSeverityToBadgeVariant('warning')).toBe('warning');
		expect(creditSeverityToBadgeVariant('critical')).toBe('danger');
		expect(creditSeverityToBadgeVariant('unknown')).toBe('neutral');
		expect(licenseStatusToBadgeVariant('active')).toBe('success');
		expect(licenseStatusToBadgeVariant('error')).toBe('danger');
		expect(licenseStatusToBadgeVariant('inactive')).toBe('warning');
		expect(formatCreditSeverityLabel('normal')).toBe('Healthy');
		expect(formatCreditSeverityLabel('warning')).toBe('Low');
		expect(formatCreditSeverityLabel('critical')).toBe('Exhausted');
		expect(formatCreditSeverityLabel('unknown')).toBe('Unknown');
	});

	it('prefers tier display name over code and supports string tiers', () => {
		expect(resolveTierDisplayName('pro')).toBe('pro');
		expect(
			resolveTierDisplayName({
				code: 'starter',
				display_name: 'Starter',
				monthly_credit_quota: 100
			})
		).toBe('Starter');
		expect(
			resolveTierDisplayName({
				code: 'starter',
				monthly_credit_quota: 100
			})
		).toBe('starter');
		expect(resolveTierDisplayName(null)).toBeNull();
	});
});
