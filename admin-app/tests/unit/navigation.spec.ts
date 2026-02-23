import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('$app/navigation', () => ({
	goto: vi.fn(() => Promise.reject(new Error('goto unavailable')))
}));

vi.mock('$app/environment', () => ({ browser: true }));

import { goto } from '$app/navigation';
import {
	appHref,
	deriveActivePath,
	navigateToAppPath,
	normalizeRoutePath,
	readHashPathFromLocation,
	resolveRouterType,
	resolveActiveNavPath,
	routerType
} from '../../src/lib/navigation';

afterEach(() => {
	window.location.hash = '';
	vi.clearAllMocks();
});

describe('navigation helpers', () => {
	it('defaults router type to hash when env is not set', () => {
		expect(resolveRouterType(undefined)).toBe('hash');
		expect(routerType).toBe('hash');
	});

	it('builds hash-based hrefs by default', () => {
		expect(appHref('/actions/demo/42')).toBe('#/actions/demo/42');
		expect(appHref('actions/demo/42')).toBe('#/actions/demo/42');
	});

	it('derives active path from hash URL', () => {
		const url = new URL('https://example.com/admin.php?page=sentient-forms#/actions/demo/42');
		expect(deriveActivePath(url)).toBe('/actions/demo/42');
	});

	it('normalizes route paths for matching and recovery', () => {
		expect(normalizeRoutePath('#/actions/demo/42/?tab=raw')).toBe('/actions/demo/42');
		expect(normalizeRoutePath('/settings/context/?section=telemetry')).toBe('/settings/context');
		expect(normalizeRoutePath('')).toBe('/');
	});

	it('maps nested routes to the correct sidebar parent link', () => {
		expect(resolveActiveNavPath('/actions/gravity_forms/123')).toBe('/actions');
		expect(resolveActiveNavPath('/actions/custom/new')).toBe('/actions/custom');
		expect(resolveActiveNavPath('/actions/log/details/1')).toBe('/actions/log');
		expect(resolveActiveNavPath('/settings/context')).toBe('/settings');
	});

	it('builds pathname hrefs when requested', () => {
		expect(appHref('/licensing', { routerType: 'pathname', basePath: '/sentient-forms' })).toBe(
			'/sentient-forms/licensing'
		);
	});

	it('navigates via hash by updating location.hash', async () => {
		await navigateToAppPath('/actions/demo/42');
		expect(vi.mocked(goto)).toHaveBeenCalled();
		expect(window.location.hash).toBe('#/actions/demo/42');
	});

	it('reads and normalizes the hash path from browser location', () => {
		window.location.hash = '#/actions/custom/new/?step=1';
		expect(readHashPathFromLocation()).toBe('/actions/custom/new');
	});
});
