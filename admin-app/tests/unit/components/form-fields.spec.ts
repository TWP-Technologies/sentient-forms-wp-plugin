import { afterEach, describe, expect, it } from 'vitest';
import { tick } from 'svelte';
import { createClassComponent } from 'svelte/legacy';
import InputField from '$lib/components/ui/input-field.svelte';
import SelectField from '$lib/components/ui/select-field.svelte';
import TextareaField from '$lib/components/ui/textarea-field.svelte';
import Toggle from '$lib/components/ui/toggle.svelte';
import ValidationSummary from '$lib/components/ui/validation-summary.svelte';

afterEach(() => {
	document.body.innerHTML = '';
});

function mount(Component: unknown, initialProps: Record<string, unknown>) {
	const target = document.createElement('div');
	document.body.appendChild(target);
	const props = { ...initialProps };

	const component = createClassComponent({
		target,
		component: Component as unknown as typeof InputField,
		props: props
	});

	return {
		component,
		target,
		dispose: () => {
			component.$destroy();
			target.remove();
		}
	};
}

describe('Form field primitives', () => {
	it('binds and displays errors for InputField', async () => {
		const { target, dispose } = mount(InputField, {
			id: 'license',
			label: 'License key',
			help: 'Find this on your receipt',
			error: 'License key is required',
			required: true
		});

		const input = target.querySelector('input') as HTMLInputElement;
		input.value = 'abc';
		input.dispatchEvent(new Event('input'));
		await tick();

		expect(input.value).toBe('abc');
		expect(target.textContent).toContain('License key is required');
		dispose();
	});

	it('supports select options', async () => {
		const { target, component, dispose } = mount(SelectField, {
			id: 'tier',
			label: 'Tier',
			options: [
				{ label: 'Free', value: 'free' },
				{ label: 'Pro', value: 'pro' }
			]
		});

		const select = target.querySelector('select') as HTMLSelectElement;
		component.$set?.({ value: 'pro' });
		await tick();

		expect(select.value).toBe('pro');
		dispose();
	});

	it('binds textarea content', async () => {
		const { target, dispose } = mount(TextareaField, {
			id: 'notes',
			label: 'Notes',
			placeholder: 'Add details'
		});

		const textarea = target.querySelector('textarea') as HTMLTextAreaElement;
		textarea.value = 'hello';
		textarea.dispatchEvent(new Event('input'));
		await tick();

		expect(textarea.value).toBe('hello');
		dispose();
	});

	it('renders validation summary with anchors', () => {
		const { target, dispose } = mount(ValidationSummary, {
			heading: 'Fix the following',
			issues: [
				{ id: 'license', message: 'Enter your license key' },
				{ message: 'Acknowledge terms of service' }
			]
		});

		const links = Array.from(target.querySelectorAll('a'));
		expect(links[0]?.getAttribute('href')).toBe('#license');
		dispose();
	});

	it('toggle dispatches change events', async () => {
		const { target, dispose } = mount(Toggle, {
			id: 'sync-enabled',
			label: 'Enable sync'
		});

		const button = target.querySelector('button');
		expect(button).toBeTruthy();
		expect(button!.className).toContain('sf:bg-muted-400');
		button!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await tick();

		expect(button!.className).toContain('sf:bg-primary-600');
		dispose();
	});
});
