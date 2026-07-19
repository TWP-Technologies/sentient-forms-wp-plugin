#!/usr/bin/env node

import path from 'node:path';
import { normalizePath } from 'vite';

export const PATHNAME_LAYOUT_SOURCE = `export const ssr = false;
export const csr = true;
export const prerender = false;
`;

export function createRouterLayoutPlugin({ layoutPath, routerType }) {
	const normalizedLayoutPath = normalizePath(path.resolve(layoutPath));

	return {
		name: 'sentient-forms-router-layout',
		enforce: 'pre',
		load(id) {
			if (routerType !== 'pathname') return null;
			const normalizedId = normalizePath(id.split('?', 1)[0]);
			return normalizedId === normalizedLayoutPath ? PATHNAME_LAYOUT_SOURCE : null;
		}
	};
}
