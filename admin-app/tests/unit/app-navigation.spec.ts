import { describe, expect, it } from 'vitest';

import { appUrlMatchesPath } from '../e2e/utils/app-navigation';

describe('app navigation url matcher', () => {
	it('matches exact pathname routes', () => {
		const url = new URL('http://127.0.0.1:4173/actions/custom/new');
		expect(appUrlMatchesPath(url, '/actions/custom/new')).toBe(true);
	});

	it('matches exact legacy hash routes', () => {
		const url = new URL('http://127.0.0.1:4173/#/actions/custom/new');
		expect(appUrlMatchesPath(url, '/actions/custom/new')).toBe(true);
	});

	it('rejects unexpected prefixed pathname routes', () => {
		const url = new URL('http://127.0.0.1:4173/unexpected-prefix/actions/custom/new');
		expect(appUrlMatchesPath(url, '/actions/custom/new')).toBe(false);
	});

	it('normalizes leading hashes and missing slashes in expected paths', () => {
		const pathnameUrl = new URL('http://127.0.0.1:4173/actions/custom/new');
		const hashUrl = new URL('http://127.0.0.1:4173/#/actions/custom/new');
		expect(appUrlMatchesPath(pathnameUrl, 'actions/custom/new')).toBe(true);
		expect(appUrlMatchesPath(hashUrl, '#/actions/custom/new')).toBe(true);
	});
});
