import { afterEach, expect, it } from 'vitest';
import { mount, unmount, tick } from 'svelte';
import { fireEvent, within } from '@testing-library/svelte';
import SpamFailureDeliverySetting from '$lib/components/spam-failure-delivery-setting.svelte';
afterEach(() => { document.body.innerHTML = ''; });
it('describes classification failures to keyboard users and dismisses help without moving focus', async () => {
	const target = document.createElement('div'); document.body.append(target);
	const component = mount(SpamFailureDeliverySetting, { target, props: {
		policy: 'hold', holds: { state: 'active', channels: ['notifications'] }, onchange: () => {}
	} });
	await tick();
	const help = within(target).getByRole('button', { name: 'About classification failures' });
	help.focus(); await tick();
	expect(within(target).getByRole('button', {
		name: 'About classification failures',
		description: 'A failed request or invalid spam result counts as a failure. A check still running does not.'
	})).toBe(help);
	await fireEvent.keyDown(help, { key: 'Escape' }); await tick();
	expect(within(target).queryByRole('tooltip')).toBeNull();
	expect(document.activeElement).toBe(help);
	await unmount(component);
});
it('lets a WebMaster choose Allow beside its delivery risk without changing downstream settings', async () => {
	const target = document.createElement('div'); document.body.append(target);
	let choice: string | undefined;
	const component = mount(SpamFailureDeliverySetting, { target, props: {
		policy: 'hold', holds: { state: 'active', channels: ['notifications'] },
		onchange: (value) => { choice = value; }
	} });
	await tick();
	const allow = target.querySelector<HTMLInputElement>('input[value="allow_delivery"]');
	expect(allow).not.toBeNull();
	allow!.checked = true; allow!.dispatchEvent(new Event('change', { bubbles: true })); await tick();
	expect(choice).toBe('allow_delivery');
	expect(target.textContent).toContain('Spam may reach recipients or connected services.');
	expect(target.textContent).toContain('Saving affects future spam checks.');
	await unmount(component);
});
