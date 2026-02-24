import { afterEach, describe, expect, it } from 'vitest';
import { createClassComponent } from 'svelte/legacy';
import StateTemplate from '$lib/components/ui/state-template.svelte';

afterEach(() => {
	document.body.innerHTML = '';
});

function mount(initialProps: Record<string, unknown>) {
	const target = document.createElement('div');
	document.body.appendChild(target);

	const component = createClassComponent({
		target,
		component: StateTemplate,
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

describe('StateTemplate', () => {
	it('renders loading variant with polite status semantics', () => {
		const { target, dispose } = mount({
			variant: 'loading',
			title: 'Loading action logs',
			message: 'Fetching recent entries.',
			testId: 'state-loading-test'
		});

		const root = target.querySelector('[data-testid="state-loading-test"]');
		expect(root?.getAttribute('role')).toBe('status');
		expect(root?.getAttribute('aria-live')).toBe('polite');
		expect(root?.getAttribute('aria-busy')).toBe('true');
		expect(target.textContent).toContain('Loading action logs');
		expect(target.textContent).toContain('Fetching recent entries.');
		dispose();
	});

	it('renders error variant with retry action callback', () => {
		let retries = 0;
		const { target, dispose } = mount({
			variant: 'error',
			title: 'Unable to load action logs',
			message: 'Network request failed.',
			actionLabel: 'Retry',
			onAction: () => {
				retries += 1;
			},
			testId: 'state-error-test'
		});

		const root = target.querySelector('[data-testid="state-error-test"]');
		expect(root?.getAttribute('role')).toBe('alert');
		expect(root?.getAttribute('aria-live')).toBe('assertive');

		const button = target.querySelector('button');
		expect(button?.textContent).toContain('Retry');
		button?.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		expect(retries).toBe(1);
		dispose();
	});

	it('omits action button when action props are not provided', () => {
		const { target, dispose } = mount({
			variant: 'empty',
			title: 'No data yet',
			message: 'Create an action to continue.'
		});

		expect(target.querySelector('button')).toBeNull();
		expect(target.textContent).toContain('No data yet');
		dispose();
	});
});
