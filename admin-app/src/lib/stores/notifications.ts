import { writable } from 'svelte/store';

export type NotificationType = 'success' | 'error' | 'info' | 'warning';

export interface Notification {
	id: number;
	type: NotificationType;
	message: string;
	timeout?: number;
}

const factory = () => {
	const { subscribe, update } = writable<Notification[]>([]);
	let counter = 0;

	function push(type: NotificationType, message: string, timeout = 4000) {
		const id = ++counter;
		update((items) => [...items, { id, type, message, timeout }]);

		if (timeout > 0) {
			setTimeout(() => {
				remove(id);
			}, timeout);
		}
	}

	function remove(id: number) {
		update((items) => items.filter((item) => item.id !== id));
	}

	return {
		subscribe,
		success: (message: string, timeout?: number) => push('success', message, timeout),
		error: (message: string, timeout?: number) => push('error', message, timeout),
		info: (message: string, timeout?: number) => push('info', message, timeout),
		warning: (message: string, timeout?: number) => push('warning', message, timeout),
		remove
	};
};

export const notifications = factory();
