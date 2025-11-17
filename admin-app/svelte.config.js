import adapter from '@sveltejs/adapter-static';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

const routerType = process.env.SENTIENT_FORMS_ROUTER === 'pathname' ? 'pathname' : 'hash';

const config = {
	preprocess: vitePreprocess(),
	kit: {
		adapter: adapter({ fallback: 'index.html' }),
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
