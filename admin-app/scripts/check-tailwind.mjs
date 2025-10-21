#!/usr/bin/env bun
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { globby } from 'globby';

const allowList = ['prose', 'dark'];

function hasDisallowedClass(className) {
	const parts = className.split(':');
	const base = parts[parts.length - 1];
	if (base.startsWith('sf-')) return false;
	if (allowList.includes(base)) return false;
	if (/^aria-/.test(base)) return false;
	return true;
}

async function main() {
	const files = await globby('src/**/*.svelte');
	const violations = [];
	const classRegex = /class="([^"]*)"/g;

	for (const file of files) {
		const content = await readFile(file, 'utf8');
		let match;
		while ((match = classRegex.exec(content)) !== null) {
			const classes = match[1].split(/\s+/).filter(Boolean);
			for (const cls of classes) {
				if (hasDisallowedClass(cls)) {
					violations.push({ file, className: cls });
				}
			}
		}
	}

	if (violations.length) {
		console.error('[tailwind-check] Non-prefixed classes detected:');
		for (const violation of violations) {
			console.error(` - ${violation.className} (${violation.file})`);
		}
		process.exit(1);
	}

	console.log('[tailwind-check] All classes use the sf- prefix.');
}

main().catch((error) => {
	console.error('[tailwind-check] Failed:', error);
	process.exit(1);
});
