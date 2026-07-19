import path from 'node:path';
import { describe, expect, it } from 'vitest';
import {
	PATHNAME_LAYOUT_SOURCE,
	createRouterLayoutPlugin
} from '../../scripts/router-layout-plugin.mjs';

const layoutPath = path.resolve('src', 'routes', '+layout.ts');

describe('router layout Vite plugin', () => {
	it('provides pathname page options without rewriting the tracked layout file', () => {
		const plugin = createRouterLayoutPlugin({ layoutPath, routerType: 'pathname' });

		expect(plugin.enforce).toBe('pre');
		expect(plugin.load(`${layoutPath}?svelte-kit`)).toBe(PATHNAME_LAYOUT_SOURCE);
	});

	it('leaves hash-router and unrelated modules on their normal filesystem source', () => {
		const hashPlugin = createRouterLayoutPlugin({ layoutPath, routerType: 'hash' });
		const pathnamePlugin = createRouterLayoutPlugin({ layoutPath, routerType: 'pathname' });

		expect(hashPlugin.load(layoutPath)).toBeNull();
		expect(pathnamePlugin.load(path.resolve('src', 'routes', '+page.ts'))).toBeNull();
	});
});
