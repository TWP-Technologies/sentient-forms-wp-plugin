import adapter from '@sveltejs/adapter-static';
import { readFileSync } from 'node:fs';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

const packageJson = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'));
const appVersion = process.env.SENTIENT_FORMS_BUILD_VERSION ?? String(packageJson.version ?? '0.0.0');
const routerType = process.env.SENTIENT_FORMS_ROUTER === 'pathname' ? 'pathname' : 'hash';

const config = {
	preprocess: vitePreprocess(),
	kit: {
		adapter: adapter({ fallback: 'index.html' }),
		embedded: true,
		version: {
			name: appVersion
		},
		alias: {
			$lib: 'src/lib',
			$components: 'src/lib/components'
		},
		router: {
			type: routerType
		},
		prerender: {
			entries: []
		}
	}
};

export default config;
