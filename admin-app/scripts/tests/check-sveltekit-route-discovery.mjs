#!/usr/bin/env node
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import fs from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, sep } from 'node:path';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const kitRoot = dirname(require.resolve('@sveltejs/kit/package.json'));
const { load_config: loadConfig } = await import(
	pathToFileURL(join(kitRoot, 'src/core/config/index.js')).href
);
const { default: createManifestData } = await import(
	pathToFileURL(join(kitRoot, 'src/core/sync/create_manifest_data/index.js')).href
);

async function routeNodeOrder(directoryOrder, reverseEnumeration = false) {
	const root = await mkdtemp(join(tmpdir(), 'sentient-forms-route-order-'));

	try {
		const routesRoot = join(root, 'src', 'routes');
		await mkdir(routesRoot, { recursive: true });
		await writeFile(join(root, 'svelte.config.js'), 'export default {};\n', 'utf8');

		for (const directory of directoryOrder) {
			const routeRoot = join(routesRoot, directory);
			await mkdir(routeRoot);
			await writeFile(join(routeRoot, '+page.svelte'), `<h1>${directory}</h1>\n`, 'utf8');
		}

		const config = await loadConfig({ cwd: root });
		const originalReaddirSync = fs.readdirSync;
		const canonicalRoutesRoot = `${resolve(routesRoot)}${sep}`;
		fs.readdirSync = function deterministicRouteOrderProbe(directory, options) {
			const entries = originalReaddirSync.call(fs, directory, options);
			const canonicalDirectory = `${resolve(String(directory))}${sep}`;
			if (
				reverseEnumeration &&
				canonicalDirectory.startsWith(canonicalRoutesRoot) &&
				Array.isArray(entries)
			) {
				return [...entries].reverse();
			}
			return entries;
		};

		let manifest;
		try {
			manifest = createManifestData({ config, cwd: root });
		} finally {
			fs.readdirSync = originalReaddirSync;
		}

		return manifest.nodes
			.map((node) => node.component ?? node.universal ?? node.server)
			.filter(Boolean)
			.map((filePath) => filePath.split(sep).join('/'))
			.filter((filePath) => filePath.startsWith('src/routes/'));
	} finally {
		await rm(root, { recursive: true, force: true });
	}
}

const expected = [
	'src/routes/alpha/+page.svelte',
	'src/routes/beta/+page.svelte',
	'src/routes/gamma/+page.svelte'
];
const forward = await routeNodeOrder(['alpha', 'beta', 'gamma']);
const reverse = await routeNodeOrder(['gamma', 'beta', 'alpha'], true);

if (JSON.stringify(forward) !== JSON.stringify(expected)) {
	throw new Error(
		`Forward-created routes were not discovered canonically: ${JSON.stringify(forward)}`
	);
}

if (JSON.stringify(reverse) !== JSON.stringify(expected)) {
	throw new Error(
		`Reverse-created routes were not discovered canonically: ${JSON.stringify(reverse)}`
	);
}

console.log('SvelteKit route discovery determinism contract passed.');
