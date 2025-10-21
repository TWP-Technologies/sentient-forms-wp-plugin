import { describe, it, expect, vi } from 'vitest';
import { notifications, type Notification } from '$lib/stores/notifications';

vi.useFakeTimers();

describe('notifications store', () => {
	it('adds and removes notifications', () => {
	let items: Notification[] = [];
		const unsub = notifications.subscribe((value) => (items = value));

		notifications.success('Saved', 1000);
		expect(items).toHaveLength(1);

		vi.advanceTimersByTime(1000);
		expect(items).toHaveLength(0);
		unsub();
	});
});
