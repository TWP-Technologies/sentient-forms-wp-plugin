#!/usr/bin/env bun
import { readdir, stat } from 'node:fs/promises';
import path from 'node:path';

// This totals all JS emitted by the static admin SPA, including lazy route
// chunks. The all-model selector, action log, managed-service setup, realtime
// assistant controls, model ranking metadata, and dependency-graph UX currently
// sit above 1 MB after the Lead Scoring dashboard/setup expansion; keep a hard
// ceiling with deliberate headroom so accidental
// payload growth still fails. Zod is intentionally included for stricter
// admin config validation during private beta. The first AI impact recap and
// Lead Scoring workspace add lazy admin chunks for reporting, setup,
// historical scoring, corrections, and self-improvement controls; revisit after
// the workflows settle and route-level chunk splitting can be compacted
// deliberately.
const MAX_TOTAL_KB = Number(process.env.BUNDLE_MAX_KB ?? 1150);
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
