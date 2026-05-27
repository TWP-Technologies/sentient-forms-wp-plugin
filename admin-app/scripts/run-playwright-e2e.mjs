#!/usr/bin/env node
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const DEFAULT_PREVIEW_HOST = '127.0.0.1';
const DEFAULT_PREVIEW_PORT = 4175;
const MIN_PORT = 1;
const MAX_PORT = 65_535;

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

function parsePort(rawValue, fieldName) {
	if (typeof rawValue !== 'string') return null;
	const normalized = rawValue.trim();
	if (normalized.length === 0) return null;
	if (!/^\d+$/.test(normalized)) {
		throw new Error(`${fieldName} must be a numeric port between ${MIN_PORT} and ${MAX_PORT}.`);
	}

	const parsed = Number(normalized);
	if (!Number.isInteger(parsed) || parsed < MIN_PORT || parsed > MAX_PORT) {
		throw new Error(`${fieldName} must be between ${MIN_PORT} and ${MAX_PORT}.`);
	}

	return parsed;
}

function resolvePreviewHost() {
	const rawHost = process.env.PREVIEW_HOST;
	if (typeof rawHost !== 'string') return DEFAULT_PREVIEW_HOST;
	const normalized = rawHost.trim();
	return normalized.length > 0 ? normalized : DEFAULT_PREVIEW_HOST;
}

function resolvePreviewPort() {
	const requestedPort = parsePort(process.env.PREVIEW_PORT, 'PREVIEW_PORT');
	if (requestedPort !== null) {
		return { port: requestedPort, source: 'env' };
	}

	return { port: DEFAULT_PREVIEW_PORT, source: 'default' };
}

function runPlaywrightTest(env, args) {
	const result = spawnSync(resolveRealNodeCommand(), [getPlaywrightCliPath(), 'test', ...args], {
		stdio: 'inherit',
		env
	});

	if (result.error) {
		throw result.error;
	}

	if (result.signal) {
		throw new Error(`Playwright exited via signal ${result.signal}.`);
	}

	return result.status ?? 1;
}

function main() {
	const previewHost = resolvePreviewHost();
	const { port: previewPort, source } = resolvePreviewPort();
	const previewOrigin = `http://${previewHost}:${previewPort}`;
	const env = {
		...buildPlaywrightEnv(),
		PREVIEW_HOST: previewHost,
		PREVIEW_PORT: String(previewPort)
	};

	console.log(`[E2E] Preview origin ${previewOrigin} (${source})`);

	ensureArtifactDirs();
	const exitCode = runPlaywrightTest(env, process.argv.slice(2));
	process.exit(exitCode);
}

main().catch((error) => {
	const message = error instanceof Error ? error.message : String(error);
	console.error(`[E2E] Failed to launch Playwright: ${message}`);
	process.exit(1);
});
