#!/usr/bin/env bun
import { readdir, stat } from 'node:fs/promises';
import path from 'node:path';

// The dependency-graph UX introduced xyflow/dagre runtime chunks; keep a hard
// ceiling, but leave only narrow headroom above the current ~756 KB baseline.
const MAX_TOTAL_KB = Number(process.env.BUNDLE_MAX_KB ?? 760);
const distRoot = path.resolve('..', 'assets', 'dist', '_app', 'immutable');

async function collectSizes(dir) {
	const entries = await readdir(dir, { withFileTypes: true });
	let total = 0;
	for (const entry of entries) {
		const fullPath = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			total += await collectSizes(fullPath);
		} else if (entry.isFile() && entry.name.endsWith('.js')) {
			const info = await stat(fullPath);
			total += info.size;
		}
	}
	return total;
}

async function main() {
	const totalBytes = await collectSizes(distRoot);
	const totalKB = totalBytes / 1024;
	console.log(`[bundle-check] JS payload: ${totalKB.toFixed(1)} KB (limit ${MAX_TOTAL_KB} KB)`);
	if (totalKB > MAX_TOTAL_KB) {
		console.error('[bundle-check] Bundle exceeds size budget.');
		process.exit(1);
	}
}

main().catch((error) => {
	console.error('[bundle-check] Unable to calculate bundle size:', error);
	process.exit(1);
});
