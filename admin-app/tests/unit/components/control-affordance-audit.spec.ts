import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const srcRoot = resolve(process.cwd(), 'src');

const allowedRawButtons = new Map<string, number>([
	['lib/components/ui/button.svelte', 1],
	['lib/components/ui/toggle.svelte', 1],
	['lib/components/ui/mapping-dependency-graph-node.svelte', 1],
	['routes/(app)/actions/[formSourceSlug]/[formId]/+page.svelte', 6]
]);

function toPosixPath(value: string): string {
	return value.split('\\').join('/');
}

function listSvelteFiles(dir: string): string[] {
	const entries = readdirSync(dir, { withFileTypes: true });
	const files: string[] = [];

	for (const entry of entries) {
		const absolutePath = join(dir, entry.name);
		if (entry.isDirectory()) {
			files.push(...listSvelteFiles(absolutePath));
			continue;
		}

		if (entry.isFile() && entry.name.endsWith('.svelte')) {
			files.push(absolutePath);
		}
	}

	return files;
}

describe('control affordance audit', () => {
	it('contains raw <button> only in allowlisted structural files', () => {
		const files = listSvelteFiles(srcRoot);
		const rawButtonHits = new Map<string, number[]>();

		for (const filePath of files) {
			const relativePath = toPosixPath(relative(srcRoot, filePath));
			const content = readFileSync(filePath, 'utf8');
			const lines = content.split('\n');
			const hits: number[] = [];

			for (let index = 0; index < lines.length; index += 1) {
				if (/<button\b/.test(lines[index] ?? '')) {
					hits.push(index + 1);
				}
			}

			if (hits.length > 0) {
				rawButtonHits.set(relativePath, hits);
			}
		}

		const unexpectedRawButtons = Array.from(rawButtonHits.entries())
			.filter(([filePath]) => !allowedRawButtons.has(filePath))
			.map(([filePath, lines]) => `${filePath}:${lines.join(',')}`);

		expect(unexpectedRawButtons).toEqual([]);

		for (const [filePath, expectedCount] of allowedRawButtons.entries()) {
			const actualCount = rawButtonHits.get(filePath)?.length ?? 0;
			expect(actualCount, `Unexpected raw <button> count in ${filePath}`).toBe(expectedCount);
		}
	});
});
