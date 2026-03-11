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

function escapeRegex(value: string): string {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function appRouteRegex(path: string): RegExp {
	const normalized = escapeRegex(normalizeAppPath(path));
	return new RegExp(`(?:${normalized}|#${normalized})$`);
}

export async function expectAppUrl(page: Page, path: string): Promise<void> {
	await expect(page).toHaveURL(appRouteRegex(path));
}

export function appNavLink(page: Page, path: string): Locator {
	return page.locator(`nav a[data-nav-path="${normalizeAppPath(path)}"]`);
}
