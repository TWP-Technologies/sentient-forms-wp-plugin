#!/usr/bin/env node
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { preview as startVitePreview } from 'vite';
import { startOwnedPreview } from './preview-runtime.mjs';

const DEFAULT_PREVIEW_HOST = '127.0.0.1';

function isBunNodeShim(candidate) {
	if (typeof candidate !== 'string') return false;
	const normalized = candidate.replaceAll('\\', '/').toLowerCase();
	return normalized.includes('/tmp/bun-node-') || normalized.includes('/temp/bun-node-');
}

function resolveRealNodeCommand() {
	const candidates = [];
	if (process.platform === 'win32') {
		const result = spawnSync('where.exe', ['node'], {
			encoding: 'utf8'
		});
		if (result.status === 0 && typeof result.stdout === 'string') {
			candidates.push(
				...result.stdout
					.split(/\r?\n/)
					.map((value) => value.trim())
					.filter(Boolean)
			);
		}
	} else {
		const result = spawnSync('which', ['-a', 'node'], {
			encoding: 'utf8'
		});
		if (result.status === 0 && typeof result.stdout === 'string') {
			candidates.push(
				...result.stdout
					.split('\n')
					.map((value) => value.trim())
					.filter(Boolean)
			);
		}
	}

	for (const candidate of candidates) {
		if (!isBunNodeShim(candidate)) {
			return candidate;
		}
	}

	return process.platform === 'win32' ? 'node.exe' : 'node';
}

function getPlaywrightCliPath() {
	return path.resolve(process.cwd(), 'node_modules', 'playwright', 'cli.js');
}

function getBunCommand() {
	return process.platform === 'win32' ? 'bun.exe' : 'bun';
}

function ensureArtifactDirs() {
	mkdirSync(path.resolve(process.cwd(), 'playwright-report'), { recursive: true });
	mkdirSync(path.resolve(process.cwd(), 'test-results'), { recursive: true });
}

function buildPlaywrightEnv() {
	const env = { ...process.env };
	delete env.INIT_CWD;
	delete env.NODE;
	delete env.NO_COLOR;

	for (const key of Object.keys(env)) {
		if (key.startsWith('npm_')) {
			delete env[key];
		}
	}

	return env;
}

function resolvePreviewHost() {
	const rawHost = process.env.PREVIEW_HOST;
	if (typeof rawHost !== 'string') return DEFAULT_PREVIEW_HOST;
	const normalized = rawHost.trim();
	return normalized.length > 0 ? normalized : DEFAULT_PREVIEW_HOST;
}

function ensureExitCodeZero(command, args, env) {
	const result = spawnSync(command, args, { stdio: 'inherit', env });
	if (result.error) throw result.error;
	if (result.signal) throw new Error(`${command} exited via signal ${result.signal}.`);
	if (result.status !== 0) {
		throw new Error(`${command} ${args.join(' ')} failed with exit code ${result.status ?? 1}.`);
	}
}

function runPlaywrightTest(env, args) {
	return new Promise((resolve, reject) => {
		const child = spawn(resolveRealNodeCommand(), [getPlaywrightCliPath(), 'test', ...args], {
			stdio: 'inherit',
			env
		});

		child.on('error', reject);
		child.on('exit', (code, signal) => {
			if (signal) {
				reject(new Error(`Playwright exited via signal ${signal}.`));
				return;
			}
			resolve(code ?? 1);
		});
	});
}

async function main() {
	ensureArtifactDirs();
	const baseEnv = buildPlaywrightEnv();
	if (process.env.SENTIENT_RUN_WP_E2E === '1') {
		process.exitCode = await runPlaywrightTest(baseEnv, process.argv.slice(2));
		return;
	}

	const previewHost = resolvePreviewHost();
	const previewEnv = {
		...baseEnv,
		SENTIENT_FORMS_ROUTER: process.env.SENTIENT_FORMS_ROUTER ?? 'pathname'
	};
	ensureExitCodeZero(getBunCommand(), ['run', 'build'], previewEnv);

	const ownedPreview = await startOwnedPreview({
		host: previewHost,
		rawPort: process.env.PREVIEW_PORT,
		startPreview: (config) =>
			startVitePreview({
				...config,
				clearScreen: false,
				root: process.cwd()
			})
	});
	const env = {
		...previewEnv,
		PREVIEW_HOST: previewHost,
		PREVIEW_PORT: String(ownedPreview.port),
		PREVIEW_ORIGIN: ownedPreview.origin,
		SENTIENT_FORMS_OWNED_PREVIEW: '1'
	};

	console.log(`[E2E] Preview origin ${ownedPreview.origin} (owned)`);
	try {
		process.exitCode = await runPlaywrightTest(env, process.argv.slice(2));
	} finally {
		await ownedPreview.server.close();
	}
}

main().catch((error) => {
	const message = error instanceof Error ? error.message : String(error);
	console.error(`[E2E] Failed to launch Playwright: ${message}`);
	process.exit(1);
});
