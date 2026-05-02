#!/usr/bin/env bun
import { readdir, stat } from 'node:fs/promises';
import path from 'node:path';

// This totals all JS emitted by the static admin SPA, including lazy route
// chunks. The all-model selector, action log, managed-service setup, realtime
// assistant controls, model ranking metadata, and dependency-graph UX currently
// sit at ~865 KB; keep a hard ceiling with narrow headroom so accidental
// payload growth still fails.
const MAX_TOTAL_KB = Number(process.env.BUNDLE_MAX_KB ?? 875);
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
