import { expect, type Locator, type Page } from '@playwright/test';

function normalizeAppPath(path: string): string {
	if (!path) return '/';
	const trimmed = path.trim();
	if (!trimmed) return '/';
	const withoutHash = trimmed.replace(/^#/, '');
	const withLeadingSlash = withoutHash.startsWith('/') ? withoutHash : `/${withoutHash}`;
	const normalized = withLeadingSlash.replace(/\/+/g, '/');
	return normalized.length > 1 && normalized.endsWith('/') ? normalized.slice(0, -1) : normalized;
}

export function appUrlMatchesPath(url: URL, path: string): boolean {
	const normalized = normalizeAppPath(path);
	const pathname = normalizeAppPath(url.pathname);
	const hashPath = url.hash.length > 1 ? normalizeAppPath(url.hash.slice(1)) : null;
	return pathname === normalized || (hashPath !== null && hashPath === normalized);
}

export async function expectAppUrl(page: Page, path: string): Promise<void> {
	await expect(page).toHaveURL((url) => appUrlMatchesPath(url, path));
}

export function appNavLink(page: Page, path: string): Locator {
	return page.locator(`nav a[data-nav-path="${normalizeAppPath(path)}"]`);
}
