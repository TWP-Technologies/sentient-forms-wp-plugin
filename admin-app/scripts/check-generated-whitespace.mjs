#!/usr/bin/env node
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const outputDir = path.resolve(__dirname, '..', '..', 'assets', 'dist');
const textExtensions = new Set(['.css', '.html', '.js', '.json', '.md', '.svg']);

async function collectTextFiles(directory) {
	const entries = await readdir(directory, { withFileTypes: true });
	const files = [];
	for (const entry of entries) {
		const absolute = path.join(directory, entry.name);
		if (entry.isDirectory()) {
			files.push(...(await collectTextFiles(absolute)));
		} else if (entry.isFile() && textExtensions.has(path.extname(entry.name))) {
			files.push(absolute);
		}
	}
	return files;
}

const failures = [];
for (const file of await collectTextFiles(outputDir)) {
	const lines = (await readFile(file, 'utf8')).split(/\r?\n/);
	for (const [index, line] of lines.entries()) {
		if (/^[\t ]+$/.test(line)) {
			failures.push(`${path.relative(outputDir, file)}:${index + 1}`);
		}
	}
}

if (failures.length > 0) {
	console.error(`Generated assets contain whitespace-only lines:\n${failures.join('\n')}`);
	process.exit(1);
}

console.log('[generated-whitespace] Generated text assets are clean.');
