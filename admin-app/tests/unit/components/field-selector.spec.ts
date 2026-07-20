import { afterEach, describe, expect, it } from 'vitest';
import { createClassComponent } from 'svelte/legacy';
import { tick } from 'svelte';
import FieldSelector from '$lib/components/ui/field-selector.svelte';
import type { InputMapping } from '$lib/api/types';

afterEach(() => {
	document.body.innerHTML = '';
});

describe('FieldSelector', () => {
	it('turns the default-on metadata option off on the first change', async () => {
		const changes: InputMapping[] = [];
		const target = document.createElement('div');
		document.body.appendChild(target);

		const component = createClassComponent({
			target,
			component: FieldSelector,
			props: {
				fields: [],
				value: { mode: 'selected' },
				onchange: (mapping: InputMapping) => changes.push(mapping)
			}
		});

		const checkbox = target.querySelector<HTMLInputElement>('input[type="checkbox"]');
		expect(checkbox?.checked).toBe(true);

		checkbox?.click();
		await tick();

		expect(changes).toEqual([{ mode: 'selected', include_metadata: false }]);

		component.$destroy();
		target.remove();
	});
});
