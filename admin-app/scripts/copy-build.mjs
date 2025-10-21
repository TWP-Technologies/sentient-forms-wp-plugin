#!/usr/bin/env bun
import { cp, mkdir, readFile, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const projectRoot = path.resolve(__dirname, '..');
const buildDir = path.join(projectRoot, 'build');
const clientDir = path.join(buildDir, '_app');
const kitOutputDir = path.join(projectRoot, '.svelte-kit', 'output', 'client');
const outputDir = path.join(projectRoot, '..', 'assets', 'dist');
const manifestSrc = path.join(kitOutputDir, '.vite', 'manifest.json');
const manifestDest = path.join(outputDir, 'manifest.json');

const ensureDir = async (dir) => {
  try {
    await mkdir(dir, { recursive: true });
  } catch (error) {
    if (error.code !== 'EEXIST') {
      throw error;
    }
  }
};

const copyRecursive = async (src, dest) => cp(src, dest, { recursive: true });

const main = async () => {
  if (!existsSync(clientDir)) {
    throw new Error(`Client build directory not found: ${clientDir}`);
  }
  if (!existsSync(manifestSrc)) {
    throw new Error(`Vite manifest not found: ${manifestSrc}`);
  }

  await ensureDir(outputDir);
  await copyRecursive(clientDir, path.join(outputDir, '_app'));

  const indexHtmlSrc = path.join(buildDir, 'index.html');
  if (existsSync(indexHtmlSrc)) {
    await cp(indexHtmlSrc, path.join(outputDir, 'index.html'));
  }

  const manifest = await readFile(manifestSrc);
  await writeFile(manifestDest, manifest);
  console.log('[copy-build] Assets copied to', outputDir);
};

main().catch((error) => {
  console.error('[copy-build] Failed to copy build assets:', error);
  process.exit(1);
});
