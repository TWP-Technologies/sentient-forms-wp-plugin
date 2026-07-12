import { describe, expect, it, vi } from 'vitest';

import type { Notification } from '$lib/stores/notifications';
import { createNotificationForwarder } from '../../src/routes/(app)/+layout.svelte';

function createToastSink() {
	return {
		success: vi.fn(),
		error: vi.fn(),
		warning: vi.fn(),
		info: vi.fn()
	};
}

describe('layout notification bridge', () => {
	it.each([0, -1])('maps a %i timeout to a persistent Sonner toast', (timeout) => {
		const sink = createToastSink();
		const forwardNotifications = createNotificationForwarder(sink);
		const notification: Notification = {
			id: 41,
			type: 'success',
			message: `Persistent ${timeout}`,
			timeout
		};

		forwardNotifications([notification]);

		expect(sink.success).toHaveBeenCalledWith(notification.message, {
			id: 'sentient-notification-41',
			duration: Number.POSITIVE_INFINITY
		});
	});

	it('preserves positive durations and forwards each notification ID once', () => {
		const sink = createToastSink();
		const forwardNotifications = createNotificationForwarder(sink);
		const first: Notification = {
			id: 51,
			type: 'success',
			message: 'First',
			timeout: 2500
		};
		const second: Notification = {
			id: 52,
			type: 'success',
			message: 'Second',
			timeout: 6000
		};

		forwardNotifications([first]);
		forwardNotifications([first, second]);

		expect(sink.success).toHaveBeenCalledTimes(2);
		expect(sink.success).toHaveBeenNthCalledWith(1, 'First', {
			id: 'sentient-notification-51',
			duration: 2500
		});
		expect(sink.success).toHaveBeenNthCalledWith(2, 'Second', {
			id: 'sentient-notification-52',
			duration: 6000
		});
	});
});
