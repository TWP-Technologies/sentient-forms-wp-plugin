import { afterEach, describe, expect, it } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { tick } from 'svelte';
import { createClassComponent } from 'svelte/legacy';
import InputField from '$lib/components/ui/input-field.svelte';
import MergeTagField from '$lib/components/ui/merge-tag-field.svelte';
import SelectField from '$lib/components/ui/select-field.svelte';
import TextareaField from '$lib/components/ui/textarea-field.svelte';
import Toggle from '$lib/components/ui/toggle.svelte';
import ValidationSummary from '$lib/components/ui/validation-summary.svelte';
import BindableUndefinedFieldHarness from './fixtures/BindableUndefinedFieldHarness.svelte';

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

function collectSvelteFiles(directory: string): string[] {
	const entries = readdirSync(directory);
	const files: string[] = [];

	for (const entry of entries) {
		const path = join(directory, entry);
		const stat = statSync(path);
		if (stat.isDirectory()) {
			files.push(...collectSvelteFiles(path));
		} else if (entry.endsWith('.svelte')) {
			files.push(path);
		}
	}

	return files;
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

	it('keeps search icon spacing out of the placeholder text flow', () => {
		const content = readFileSync(
			join(process.cwd(), 'src/lib/components/ui/search-input.svelte'),
			'utf8'
		);

		expect(content).toContain('sf:inset-y-0');
		expect(content).toContain('sf:w-10');
		expect(content).toContain('sf:pl-10');
	});

	it('allows bound UI primitives to start with undefined values', () => {
		for (const field of ['input', 'select', 'textarea', 'toggle', 'model', 'slug', 'prompt']) {
			const { target, dispose } = mount(BindableUndefinedFieldHarness, { field });
			expect(target.querySelector('input, select, textarea, button')).toBeTruthy();
			dispose();
		}
	});

	it('does not define bindable props with fallback values', () => {
		const srcRoot = join(process.cwd(), 'src');
		const offenders = collectSvelteFiles(srcRoot).flatMap((file) => {
			const content = readFileSync(file, 'utf8');
			return Array.from(content.matchAll(/\$bindable\(\s*[^)\s]/g)).map((match) => ({
				file: file.replace(`${process.cwd()}/`, ''),
				index: match.index
			}));
		});

		expect(offenders).toEqual([]);
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

	it('inserts merge tags at the cursor without replacing live textarea text', async () => {
		const { target, dispose } = mount(MergeTagField, {
			id: 'prompt',
			label: 'Prompt',
			tokens: [{ token: 'summary_text', label: 'Entry summary' }]
		});

		const textarea = target.querySelector('textarea') as HTMLTextAreaElement;
		textarea.value = 'Write a note for ';
		textarea.dispatchEvent(new Event('input', { bubbles: true }));
		textarea.setSelectionRange(textarea.value.length, textarea.value.length);
		await tick();

		(target.querySelector('summary') as HTMLElement).click();
		await tick();
		(
			Array.from(target.querySelectorAll('button')).find((button) =>
				button.textContent?.includes('Entry summary')
			) as HTMLButtonElement
		).click();
		await tick();

		expect(textarea.value).toBe('Write a note for {{summary_text}}');
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
		const thumb = button?.querySelector('span[aria-hidden="true"]') as HTMLSpanElement;
		expect(button).toBeTruthy();
		expect(thumb).toBeTruthy();
		expect(button!.className).toContain('sf:bg-slate-300');
		expect(thumb.style.transform).toBe('translateX(0)');
		button!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await tick();

		expect(button!.className).toContain('sf:bg-primary-600');
		expect(thumb.style.transform).toBe('translateX(1.25rem)');
		dispose();
	});

	it('toggle keeps click handling when rest attributes are forwarded', async () => {
		const changes: boolean[] = [];
		const { target, dispose } = mount(Toggle, {
			id: 'ledger-enabled',
			label: 'Store snapshots',
			'data-testid': 'submission-ledger-toggle',
			onchange: (event: CustomEvent<{ checked: boolean }>) => {
				changes.push(event.detail.checked);
			}
		});

		const button = target.querySelector('[data-testid="submission-ledger-toggle"]') as HTMLButtonElement;
		expect(button).toBeTruthy();
		expect(button.getAttribute('aria-checked')).toBe('false');

		button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await tick();

		expect(button.getAttribute('aria-checked')).toBe('true');
		expect(changes).toEqual([true]);
		dispose();
	});

	it('toggle reports changes when checked is provided as a controlled value', async () => {
		const changes: boolean[] = [];
		const { target, dispose } = mount(Toggle, {
			id: 'controlled-ledger-enabled',
			label: 'Store snapshots',
			checked: false,
			onchange: (event: CustomEvent<{ checked: boolean }>) => {
				changes.push(event.detail.checked);
			}
		});

		const button = target.querySelector('button') as HTMLButtonElement;
		expect(button.getAttribute('aria-checked')).toBe('false');

		button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
		await tick();

		expect(changes).toEqual([true]);
		dispose();
	});
});
