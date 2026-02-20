import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

const devHost = process.env.SENTIENT_FORMS_DEV_HOST ?? '127.0.0.1';
const hmrHost = process.env.SENTIENT_FORMS_HMR_HOST ?? devHost;
const hmrPort = Number(process.env.SENTIENT_FORMS_HMR_PORT ?? 5173);

export default defineConfig({
  plugins: [tailwindcss(), sveltekit()],
  envPrefix: ['VITE_', 'SENTIENT_FORMS_'],
  optimizeDeps: {
    include: ['@dagrejs/dagre'],
    exclude: ['dagre']
  },
  server: {
    strictPort: true,
    port: 5173,
    host: devHost,
    cors: true,
    allowedHosts: ['host.docker.internal', 'localhost', '127.0.0.1', 'admin-app-dev'],
    hmr: {
      host: hmrHost,
      port: hmrPort,
      protocol: 'http'
    }
  }
});
