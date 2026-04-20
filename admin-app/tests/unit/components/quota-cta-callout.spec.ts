import { afterEach, describe, expect, it } from 'vitest';
import { createClassComponent } from 'svelte/legacy';
import QuotaCtaCallout from '$lib/components/ui/quota-cta-callout.svelte';

afterEach(() => {
	document.body.innerHTML = '';
});

function mount(initialProps: Record<string, unknown>) {
	const target = document.createElement('div');
	document.body.appendChild(target);

	const component = createClassComponent({
		target,
		component: QuotaCtaCallout,
		props: { ...initialProps }
	});

	return {
		target,
		dispose: () => {
			component.$destroy();
			target.remove();
		}
	};
}

describe('QuotaCtaCallout', () => {
	it('renders enabled CTA and emits action callback', () => {
		let action: string | null = null;
		const { target, dispose } = mount({
			severity: 'warning',
			title: 'Low credits remaining',
			message: 'Resets Mar 1 (5 days).',
			cta: {
				label: 'Review licensing',
				enabled: true,
				reason: 'Open Licensing to review current credit status and next steps.',
				action: 'navigate_licensing'
			},
			onAction: (nextAction: string) => {
				action = nextAction;
			},
			testId: 'quota-cta-callout',
			ctaTestId: 'quota-cta-button',
			reasonTestId: 'quota-cta-reason'
		});

		const button = target.querySelector('[data-testid="quota-cta-button"]') as HTMLButtonElement | null;
		const reason = target.querySelector('[data-testid="quota-cta-reason"]');
		expect(target.querySelector('[data-testid="quota-cta-callout"]')).not.toBeNull();
		expect(button?.disabled).toBe(false);
		expect(reason?.textContent).toContain('Open Licensing');
		button?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		expect(action).toBe('navigate_licensing');
		dispose();
	});

	it('keeps CTA disabled and does not invoke callback when action is unavailable', () => {
		let invoked = false;
		const { target, dispose } = mount({
			severity: 'critical',
			title: 'No credits remaining',
			message: 'Actions may pause until credits reset.',
			cta: {
				label: 'Review billing unavailable',
				enabled: false,
				reason:
					'In-app plan management is not available in this build yet.',
				action: 'none'
			},
			onAction: () => {
				invoked = true;
			},
			ctaTestId: 'quota-cta-button',
			reasonTestId: 'quota-cta-reason'
		});

		const button = target.querySelector('[data-testid="quota-cta-button"]') as HTMLButtonElement | null;
		const reason = target.querySelector('[data-testid="quota-cta-reason"]');
		expect(button?.disabled).toBe(true);
		expect(reason?.textContent).toContain('not available in this build yet');
		button?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		expect(invoked).toBe(false);
		dispose();
	});
});
