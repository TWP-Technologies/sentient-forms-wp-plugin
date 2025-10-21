import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vitest/config';

export default defineConfig({
	plugins: [sveltekit()],
	test: {
		environment: 'happy-dom',
		globals: true,
		include: ['tests/unit/**/*.{spec,test}.{ts,js}']
	}
});
