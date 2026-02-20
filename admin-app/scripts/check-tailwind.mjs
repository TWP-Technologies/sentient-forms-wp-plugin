#!/usr/bin/env bun
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { globby } from 'globby';

const allowList = ['prose', 'dark', 'nodrag', 'nopan'];

function hasDisallowedClass(className) {
	const parts = className.split(':');
	const base = parts[parts.length - 1];
	if (className.startsWith('sf:')) return false;
	if (parts.some((part) => part === 'sf' || part.startsWith('sf-'))) return false;
	if (base.startsWith('sf-')) return false;
	if (allowList.includes(base)) return false;
	if (/^aria-/.test(base)) return false;
	return true;
}

const HEX_PATTERN = /#[0-9a-fA-F]{3,8}\b/g;
const HEX_ALLOW_LIST = new Set([
	'src/lib/styles/tailwind.css',
	'src/lib/components/ui/TemplateLibrary.svelte',
	// XYFlow handle rendering currently relies on inline SVG/canvas-style colors.
	'src/lib/utils/mapping-dependency-xyflow.ts',
	'src/lib/components/ui/mapping-dependency-graph-node.svelte',
	'src/lib/components/ui/mapping-dependency-hook-root-node.svelte'
]);

function stripSvelteExpressions(value) {
	let depth = 0;
	let output = '';
	for (const char of value) {
		if (char === '{') {
			depth += 1;
			continue;
		}
		if (char === '}' && depth > 0) {
			depth -= 1;
			continue;
		}
		if (depth === 0) {
			output += char;
		}
	}
	return output;
}

async function main() {
	const files = await globby(['src/**/*.{svelte,ts,js}']);
	const violations = [];
	const hexViolations = [];
	const classRegex = /class="([^"]*)"/g;

	for (const file of files) {
		const content = await readFile(file, 'utf8');
		let match;
		while ((match = classRegex.exec(content)) !== null) {
			const staticClassValue = stripSvelteExpressions(match[1]);
			const classes = staticClassValue
				.split(/\s+/)
				.map((className) => className.replace(/^['"`]+|['"`]+$/g, ''))
				.filter(Boolean);
			for (const cls of classes) {
				if (hasDisallowedClass(cls)) {
					violations.push({ file, className: cls });
				}
			}
		}

		HEX_PATTERN.lastIndex = 0;
		if (!HEX_ALLOW_LIST.has(file) && HEX_PATTERN.test(content)) {
			hexViolations.push(file);
		}
	}

	const messages = [];

	if (violations.length) {
		messages.push('[tailwind-check] Non-prefixed classes detected:');
		for (const violation of violations) {
			messages.push(` - ${violation.className} (${violation.file})`);
		}
	}

	if (hexViolations.length) {
		messages.push('[tailwind-check] Raw hex colors detected (define tokens instead):');
		for (const file of [...new Set(hexViolations)]) {
			messages.push(` - ${file}`);
		}
	}

	if (messages.length) {
		console.error(messages.join('\n'));
		process.exit(1);
	}

	console.log('[tailwind-check] Prefix and token enforcement passed.');
}

main().catch((error) => {
	console.error('[tailwind-check] Failed:', error);
	process.exit(1);
});
