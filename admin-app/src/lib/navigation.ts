import { goto } from '$app/navigation';
import { base } from '$app/paths';
import { browser } from '$app/environment';

export type RouterType = 'hash' | 'pathname';
export type NavigationLinkPath =
	| '/dashboard'
	| '/providers'
	| '/licensing'
	| '/actions'
	| '/actions/log'
	| '/actions/custom'
	| '/settings';

export const resolveRouterType = (envValue?: string): RouterType => {
	return envValue === 'pathname' ? 'pathname' : 'hash';
};

// Expose router type derived from build-time env; default to hash for WP-admin.
export const routerType: RouterType = resolveRouterType(import.meta.env.SENTIENT_FORMS_ROUTER);

type PathOptions = {
	routerType?: RouterType;
	basePath?: string;
};

const normalizePath = (path: string): string => {
	if (!path) return '/';
	const trimmed = path.trim();
	if (!trimmed) return '/';
	const ensured = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
	return ensured.replace(/\/+/g, '/');
};

export const normalizeRoutePath = (path: string): string => {
	if (!path) return '/';
	const trimmed = path.trim();
	if (!trimmed) return '/';
	const withoutHash = trimmed.replace(/^#/, '');
	const [withoutQuery] = withoutHash.split('?');
	const normalized = normalizePath(withoutQuery ?? withoutHash);
	if (normalized.length > 1 && normalized.endsWith('/')) {
		return normalized.slice(0, -1);
	}
	return normalized;
};

const APP_ROUTE_PREFIXES = ['/', '/dashboard', '/providers', '/licensing', '/actions', '/settings'] as const;
const ABSOLUTE_OR_PROTOCOL_RELATIVE_URL = /^(?:[a-z][a-z0-9+.-]*:)?\/\//i;

export const isInternalAppPath = (path: string): boolean => {
	if (!path) return true;
	const trimmed = path.trim();
	if (!trimmed) return true;
	if (ABSOLUTE_OR_PROTOCOL_RELATIVE_URL.test(trimmed)) return false;

	const normalized = normalizeRoutePath(trimmed);
	return APP_ROUTE_PREFIXES.some((prefix) => {
		if (prefix === '/') return normalized === '/';
		return normalized === prefix || normalized.startsWith(`${prefix}/`);
	});
};

const NAV_MATCHERS: Array<{ path: NavigationLinkPath; matches: (value: string) => boolean }> = [
	{
		path: '/providers',
		matches: (value) => value === '/providers' || value.startsWith('/providers/')
	},
	{
		path: '/actions/custom',
		matches: (value) => value === '/actions/custom' || value.startsWith('/actions/custom/')
	},
	{
		path: '/actions/log',
		matches: (value) => value === '/actions/log' || value.startsWith('/actions/log/')
	},
	{
		path: '/actions',
		matches: (value) => value === '/actions' || value.startsWith('/actions/')
	},
	{
		path: '/settings',
		matches: (value) => value === '/settings' || value.startsWith('/settings/')
	},
	{
		path: '/dashboard',
		matches: (value) => value === '/dashboard'
	},
	{
		path: '/licensing',
		matches: (value) => value === '/licensing'
	}
];

export const resolveActiveNavPath = (path: string): NavigationLinkPath | null => {
	const normalized = normalizeRoutePath(path);
	const matcher = NAV_MATCHERS.find((candidate) => candidate.matches(normalized));
	return matcher?.path ?? null;
};

export const readHashPathFromLocation = (): string => {
	if (typeof window === 'undefined' || typeof window.location === 'undefined') {
		return '/';
	}
	return normalizeRoutePath(window.location.hash.replace(/^#/, ''));
};

export const appPath = (path: string, options?: PathOptions): string => {
	const effectiveRouter = options?.routerType ?? routerType;
	const effectiveBase = options?.basePath ?? base;
	const normalized = normalizePath(path);
	if (effectiveRouter === 'hash') {
		// Hash router ignores base; we keep a single leading slash after the hash.
		return `#${normalized}`.replace('#//', '#/');
	}
	const joined = `${effectiveBase}${normalized}`.replace(/\/+/g, '/');
	return joined === '' ? '/' : joined;
};

export const appHref = appPath;

export const deriveActivePath = (url: URL, options?: PathOptions): string => {
	const effectiveRouter = options?.routerType ?? routerType;
	const effectiveBase = options?.basePath ?? base;

	if (effectiveRouter === 'hash') {
		const hash = url.hash?.replace(/^#/, '') ?? '';
		return normalizeRoutePath(hash);
	}
	const pathname = url.pathname || '/';
	if (!effectiveBase || effectiveBase === '/') return normalizeRoutePath(pathname || '/');
	if (pathname.startsWith(effectiveBase)) {
		const trimmed = pathname.slice(effectiveBase.length) || '/';
		return normalizeRoutePath(trimmed.startsWith('/') ? trimmed : `/${trimmed}`);
	}
	return normalizeRoutePath(pathname);
};

export const navigateToAppPath = async (
	path: string,
	options?: Parameters<typeof goto>[1]
): Promise<void> => {
	if (!browser) return;

	if (!isInternalAppPath(path)) {
		window.location.assign(path);
		return;
	}

	const href = appPath(path);

	try {
		// Always delegate to SvelteKit's router so that navigation triggers load/hydration
		// even when we're using hash-based routing.
		await goto(href, options);
	} catch (error) {
		// Fall back to a hash update when the router is unavailable (e.g., SSR, tests).
		console.error('navigateToAppPath fallback', error);
		if (typeof window !== 'undefined' && typeof window.location !== 'undefined') {
			window.location.hash = href.startsWith('#') ? href : `#${href.replace(/^#/, '')}`;
		}
	}
};
