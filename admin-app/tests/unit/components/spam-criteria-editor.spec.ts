import { afterEach, describe, expect, it } from 'vitest';
import { createClassComponent } from 'svelte/legacy';
import SpamCriteriaEditor from '$lib/components/spam-criteria-editor.svelte';

afterEach(() => {
	document.body.innerHTML = '';
});

function mount(initialProps: Record<string, unknown>) {
	const target = document.createElement('div');
	document.body.appendChild(target);

	const component = createClassComponent({
		target,
		component: SpamCriteriaEditor,
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

describe('SpamCriteriaEditor', () => {
	it('starts collapsed by default', () => {
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: []
		});

		expect(target.textContent).not.toContain('Legitimate examples');
		expect(target.textContent).not.toContain('Spam examples');
		dispose();
	});

	it('respects initiallyExpanded when provided', () => {
		const { target, dispose } = mount({
			positiveExamples: [],
			negativeExamples: [],
			initiallyExpanded: true
		});

		expect(target.textContent).toContain('Legitimate examples');
		expect(target.textContent).toContain('Spam examples');
		dispose();
	});
});
