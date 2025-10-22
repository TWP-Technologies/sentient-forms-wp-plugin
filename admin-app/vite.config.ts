import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';

const devHost = process.env.SENTIENT_FORMS_DEV_HOST ?? '127.0.0.1';

export default defineConfig({
	plugins: [sveltekit()],
	server: {
		strictPort: true,
		port: 5173,
		host: devHost
	}
});
