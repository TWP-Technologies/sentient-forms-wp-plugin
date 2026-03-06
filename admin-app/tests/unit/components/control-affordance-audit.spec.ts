import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const srcRoot = resolve(process.cwd(), 'src');
const mappingRoutePath = 'routes/(app)/actions/[formSourceSlug]/[formId]/+page.svelte';

const allowedPrimitiveRawButtons = new Map<string, number>([
	['lib/components/ui/button.svelte', 1],
	['lib/components/ui/toggle.svelte', 1],
	['lib/components/ui/mapping-dependency-graph-node.svelte', 1]
]);

type RawButtonHit = {
	line: number;
	openingTag: string;
};

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

function findRawButtonHits(content: string): RawButtonHit[] {
	const hits: RawButtonHit[] = [];
	const lines = content.split('\n');

	for (let index = 0; index < lines.length; index += 1) {
		const line = lines[index] ?? '';
		if (!/<button\b/.test(line)) {
			continue;
		}

		let openingTag = line;
		let cursor = index;

		while (!/(?<![=])>\s*$/.test(lines[cursor] ?? '')) {
			cursor += 1;
			openingTag += `\n${lines[cursor] ?? ''}`;
		}

		hits.push({
			line: index + 1,
			openingTag
		});

		index = cursor;
	}

	return hits;
}

function isMappingSectionToggleButton(hit: RawButtonHit): boolean {
	return (
		/type="button"/.test(hit.openingTag) &&
		/data-testid="mapping-section-toggle-[^"]+"/.test(hit.openingTag) &&
		/aria-controls="mapping-section-content-[^"]+"/.test(hit.openingTag) &&
		/aria-expanded=\{mappingSectionExpansion\./.test(hit.openingTag) &&
		/onclick=\{\(\)\s*=>\s*toggleMappingSection\(/.test(hit.openingTag)
	);
}

describe('control affordance audit', () => {
	it('contains raw <button> only in allowlisted primitive files and mapping disclosure toggles', () => {
		const files = listSvelteFiles(srcRoot);
		const primitiveRawButtonHits = new Map<string, RawButtonHit[]>();
		const invalidMappingButtons: string[] = [];
		const unexpectedRawButtons: string[] = [];

		for (const filePath of files) {
			const relativePath = toPosixPath(relative(srcRoot, filePath));
			const content = readFileSync(filePath, 'utf8');
			const hits = findRawButtonHits(content);

			if (hits.length === 0) {
				continue;
			}

			if (relativePath === mappingRoutePath) {
				for (const hit of hits) {
					if (!isMappingSectionToggleButton(hit)) {
						invalidMappingButtons.push(`${relativePath}:${hit.line}`);
					}
				}
				continue;
			}

			if (allowedPrimitiveRawButtons.has(relativePath)) {
				primitiveRawButtonHits.set(relativePath, hits);
				continue;
			}

			unexpectedRawButtons.push(`${relativePath}:${hits.map((hit) => hit.line).join(',')}`);
		}

		expect(invalidMappingButtons).toEqual([]);
		expect(unexpectedRawButtons).toEqual([]);

		for (const [filePath, expectedCount] of allowedPrimitiveRawButtons.entries()) {
			const actualCount = primitiveRawButtonHits.get(filePath)?.length ?? 0;
			expect(actualCount, `Unexpected raw <button> count in ${filePath}`).toBe(expectedCount);
		}
	});
});
