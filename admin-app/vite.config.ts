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
    cors: {
      origin: '*',
      methods: ['GET', 'HEAD', 'OPTIONS'],
      allowedHeaders: ['Origin', 'Content-Type', 'Accept', 'Authorization', 'X-WP-Nonce']
    },
    allowedHosts: ['host.docker.internal', 'localhost', '127.0.0.1', 'admin-app-dev'],
    headers: {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, HEAD, OPTIONS',
      'Access-Control-Allow-Headers': 'Origin, Content-Type, Accept, Authorization, X-WP-Nonce'
    },
    hmr: {
      host: hmrHost,
      port: hmrPort,
      protocol: 'http'
    }
  }
});
