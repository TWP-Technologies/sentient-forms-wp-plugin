import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vitest/config';

export default defineConfig({
	plugins: [sveltekit()],
	envPrefix: ['VITE_', 'SENTIENT_FORMS_'],
	test: {
		environment: 'happy-dom',
		// Windows fork workers have intermittently exited after otherwise-green
		// runs. The suite is small enough that serial file execution is the
		// safer deterministic CI contract.
		fileParallelism: false,
		globals: true,
		include: ['tests/unit/**/*.{spec,test}.{ts,js}']
	}
});
