#!/usr/bin/env node
import { createRequire } from 'node:module';
import * as vite from 'vite';

const require = createRequire(import.meta.url);
const packageMetadata = require('vite/package.json');
const projectMetadata = require('../../package.json');

if (packageMetadata.name !== 'vite') {
  throw new Error(
    `Deterministic generated assets require standard Vite; resolved ${packageMetadata.name}.`
  );
}

if ('rolldownVersion' in vite) {
  throw new Error('Deterministic generated assets must not use the Rolldown Vite preview.');
}

if (projectMetadata.scripts?.build !== 'svelte-kit sync && vite build') {
  throw new Error(
    'Deterministic generated assets require SvelteKit sync before Vite reads the generated tsconfig.'
  );
}

console.log(`Deterministic Vite toolchain contract passed (${packageMetadata.version}).`);
