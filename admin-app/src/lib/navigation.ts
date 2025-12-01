import { goto } from '$app/navigation';
import { base } from '$app/paths';
import { browser } from '$app/environment';

export type RouterType = 'hash' | 'pathname';

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
		if (!hash) return '/';
		return hash.startsWith('/') ? hash : `/${hash}`;
	}
	const pathname = url.pathname || '/';
	if (!effectiveBase || effectiveBase === '/') return pathname || '/';
	if (pathname.startsWith(effectiveBase)) {
		const trimmed = pathname.slice(effectiveBase.length) || '/';
		return trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
	}
	return pathname;
};

export const navigateToAppPath = async (
	path: string,
	options?: Parameters<typeof goto>[1]
): Promise<void | import('@sveltejs/kit').NavigationResult> => {
	const href = appPath(path);
	const canUseWindow = browser || typeof window !== 'undefined';

	if (routerType === 'hash') {
		if (!canUseWindow) return;
		const nextHash = href.startsWith('#') ? href : `#${href}`;
		if (window.location.hash === nextHash) {
			return;
		}
		window.location.hash = nextHash;
		return;
	}

	return goto(href, options);
};
