import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

const projectRoot = resolve(import.meta.dirname, '../..');

describe('Svelte guard command contract', () => {
	it('uses the locked local checker without dynamic package execution', () => {
		const packageJson = JSON.parse(readFileSync(resolve(projectRoot, 'package.json'), 'utf8')) as {
			scripts?: Record<string, string>;
		};
		const guardSource = readFileSync(resolve(projectRoot, 'scripts/svelte-guard.mjs'), 'utf8');

		expect(packageJson.scripts?.['svelte:guard']).toBe(
			'bun run check && node scripts/svelte-guard.mjs'
		);
		expect(guardSource).not.toMatch(/\b(?:bunx|npx)\b/);
	});

	it('detects createEventDispatcher independently in every Svelte file', () => {
		const fixtureRoot = mkdtempSync(resolve(tmpdir(), 'sentient-forms-svelte-guard-'));
		const sourceRoot = resolve(fixtureRoot, 'src');
		mkdirSync(sourceRoot, { recursive: true });
		writeFileSync(
			resolve(sourceRoot, 'first.svelte'),
			`${' '.repeat(128)}createEventDispatcher()`,
			'utf8'
		);
		writeFileSync(resolve(sourceRoot, 'second.svelte'), 'createEventDispatcher()', 'utf8');

		try {
			const result = spawnSync(
				process.execPath,
				[resolve(projectRoot, 'scripts/svelte-guard.mjs')],
				{
					cwd: fixtureRoot,
					encoding: 'utf8'
				}
			);
			const output = `${result.stdout}${result.stderr}`;

			expect(result.status).toBe(1);
			expect(output).toContain('src/first.svelte');
			expect(output).toContain('src/second.svelte');
		} finally {
			rmSync(fixtureRoot, { force: true, recursive: true });
		}
	});
});
