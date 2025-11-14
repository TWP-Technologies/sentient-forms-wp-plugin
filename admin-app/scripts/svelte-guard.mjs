#!/usr/bin/env node
import { spawn } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import process from 'node:process';
import { globby } from 'globby';

async function run(command, args) {
	return new Promise((resolve, reject) => {
		const child = spawn(command, args, { stdio: 'inherit', shell: process.platform === 'win32' });
		child.on('close', (code) => {
			if (code === 0) {
				resolve();
			} else {
				reject(new Error(`${command} ${args.join(' ')} exited with code ${code}`));
			}
		});
	});
}

async function ensureNoLegacyPatterns() {
	const files = await globby(['src/**/*.svelte']);
	const violations = [];
	const onDirective = /on:[a-zA-Z0-9_-]+/g;
	const dispatcher = /createEventDispatcher\s*\(/g;

	for (const file of files) {
		const contents = await readFile(file, 'utf8');

		for (const match of contents.matchAll(onDirective)) {
			violations.push({ file, message: `Legacy on: directive detected (${match[0]}). Use native event attributes instead.` });
		}

		if (dispatcher.test(contents)) {
			violations.push({ file, message: 'createEventDispatcher detected. Use callback props or bindables instead.' });
		}
	}

	if (violations.length > 0) {
		console.error('\nSvelte 5 guard found legacy patterns:');
		for (const violation of violations) {
			console.error(` - ${violation.file}: ${violation.message}`);
		}
		throw new Error('Legacy Svelte patterns detected.');
	}
}

(async () => {
	await run('npx', ['sv', 'check']);
	await ensureNoLegacyPatterns();
	console.log('Svelte guard completed successfully.');
})().catch((error) => {
	console.error(error.message);
	process.exit(1);
});
