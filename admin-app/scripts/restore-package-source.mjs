#!/usr/bin/env node
import { copyFileSync, existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');
const packagedSourceRoot = path.join(projectRoot, 'source');
const sourceMapPath = path.join(packagedSourceRoot, 'source-map.json');
const targetSourceRoot = path.join(projectRoot, 'src');

if (!existsSync(sourceMapPath)) {
	if (existsSync(targetSourceRoot)) {
		console.log('[restore-package-source] Source tree already exists; nothing to restore.');
		process.exit(0);
	}

	console.error('[restore-package-source] Missing source/source-map.json.');
	process.exit(1);
}

const manifest = JSON.parse(readFileSync(sourceMapPath, 'utf8'));
if (!manifest || !Array.isArray(manifest.files)) {
	console.error('[restore-package-source] Invalid source/source-map.json.');
	process.exit(1);
}

rmSync(targetSourceRoot, { recursive: true, force: true });

for (const entry of manifest.files) {
	if (!entry || typeof entry.original !== 'string' || typeof entry.packaged !== 'string') {
		console.error('[restore-package-source] Invalid source-map entry.');
		process.exit(1);
	}

	if (!entry.original.startsWith('src/')) {
		console.error(`[restore-package-source] Refusing to restore outside src/: ${entry.original}`);
		process.exit(1);
	}

	const sourceFile = path.join(packagedSourceRoot, entry.packaged);
	const targetFile = path.join(projectRoot, entry.original);

	if (!existsSync(sourceFile)) {
		console.error(`[restore-package-source] Missing packaged source file: ${entry.packaged}`);
		process.exit(1);
	}

	mkdirSync(path.dirname(targetFile), { recursive: true });
	copyFileSync(sourceFile, targetFile);
}

console.log(`[restore-package-source] Restored ${manifest.files.length} admin source files.`);
