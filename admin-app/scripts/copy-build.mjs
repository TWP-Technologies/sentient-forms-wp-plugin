#!/usr/bin/env bun
import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
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

const resetDir = async (dir) => {
  await rm(dir, { recursive: true, force: true });
  await ensureDir(dir);
};

const collectManifestAssetPaths = (manifest) => {
  const files = new Set();

  for (const entry of Object.values(manifest)) {
    if (!entry || typeof entry !== 'object') {
      continue;
    }

    const { file, css } = entry;

    if (typeof file === 'string' && file.length > 0) {
      files.add(file);
    }

    if (Array.isArray(css)) {
      for (const candidate of css) {
        if (typeof candidate === 'string' && candidate.length > 0) {
          files.add(candidate);
        }
      }
    }
  }

  return [...files];
};

const assertManifestAssetsExist = async (outputRoot, manifest) => {
  const missing = collectManifestAssetPaths(manifest).filter(
    (relativePath) => !existsSync(path.join(outputRoot, relativePath))
  );

  if (missing.length > 0) {
    throw new Error(
      `Copied build assets do not match manifest references: ${missing.join(', ')}`
    );
  }
};

const main = async () => {
  if (!existsSync(clientDir)) {
    throw new Error(`Client build directory not found: ${clientDir}`);
  }
  if (!existsSync(manifestSrc)) {
    throw new Error(`Vite manifest not found: ${manifestSrc}`);
  }

  await resetDir(outputDir);
  await copyRecursive(clientDir, path.join(outputDir, '_app'));

  const indexHtmlSrc = path.join(buildDir, 'index.html');
  if (existsSync(indexHtmlSrc)) {
    await cp(indexHtmlSrc, path.join(outputDir, 'index.html'));
  }

  const manifest = await readFile(manifestSrc, 'utf8');
  await writeFile(manifestDest, manifest);
  await assertManifestAssetsExist(outputDir, JSON.parse(manifest));
  console.log('[copy-build] Assets copied to', outputDir);
};

main().catch((error) => {
  console.error('[copy-build] Failed to copy build assets:', error);
  process.exit(1);
});
