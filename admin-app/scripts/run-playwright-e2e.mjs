#!/usr/bin/env node
import net from 'node:net';
import { spawn } from 'node:child_process';

const DEFAULT_PREVIEW_HOST = '127.0.0.1';
const DEFAULT_PREVIEW_PORT = 4175;
const MIN_PORT = 1;
const MAX_PORT = 65_535;

function getBunxCommand() {
	return process.platform === 'win32' ? 'bunx.cmd' : 'bunx';
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

function probePortAvailability(host, port) {
	return new Promise((resolve) => {
		const server = net.createServer();

		server.once('error', (error) => {
			resolve({
				available: false,
				code: typeof error === 'object' && error && 'code' in error ? String(error.code) : 'UNKNOWN'
			});
		});

		server.once('listening', () => {
			server.close(() => {
				resolve({ available: true, code: null });
			});
		});

		server.listen({ host, port, exclusive: true });
	});
}

function reserveEphemeralPort(host) {
	return new Promise((resolve, reject) => {
		const server = net.createServer();

		server.once('error', reject);
		server.once('listening', () => {
			const address = server.address();
			if (!address || typeof address !== 'object' || typeof address.port !== 'number') {
				server.close(() => reject(new Error('Unable to resolve ephemeral preview port.')));
				return;
			}

			const port = address.port;
			server.close((closeError) => {
				if (closeError) {
					reject(closeError);
					return;
				}

				resolve(port);
			});
		});

		server.listen({ host, port: 0, exclusive: true });
	});
}

async function resolvePreviewPort(host) {
	const requestedPort = parsePort(process.env.PREVIEW_PORT, 'PREVIEW_PORT');
	if (requestedPort !== null) {
		const requestedPortProbe = await probePortAvailability(host, requestedPort);
		if (!requestedPortProbe.available) {
			throw new Error(
				`PREVIEW_PORT=${requestedPort} is unavailable on ${host} (${requestedPortProbe.code ?? 'UNKNOWN'}).`
			);
		}

		return { port: requestedPort, source: 'env' };
	}

	const defaultPortProbe = await probePortAvailability(host, DEFAULT_PREVIEW_PORT);
	if (defaultPortProbe.available) {
		return { port: DEFAULT_PREVIEW_PORT, source: 'default' };
	}

	const fallbackPort = await reserveEphemeralPort(host);
	return { port: fallbackPort, source: `auto-fallback (${defaultPortProbe.code ?? 'UNKNOWN'})` };
}

function runPlaywrightTest(env, args) {
	return new Promise((resolve, reject) => {
		const child = spawn(getBunxCommand(), ['playwright', 'test', ...args], {
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
	const previewHost = resolvePreviewHost();
	const { port: previewPort, source } = await resolvePreviewPort(previewHost);
	const previewOrigin = `http://${previewHost}:${previewPort}`;
	const env = {
		...process.env,
		PREVIEW_HOST: previewHost,
		PREVIEW_PORT: String(previewPort)
	};

	console.log(`[E2E] Preview origin ${previewOrigin} (${source})`);

	const exitCode = await runPlaywrightTest(env, process.argv.slice(2));
	process.exit(exitCode);
}

main().catch((error) => {
	const message = error instanceof Error ? error.message : String(error);
	console.error(`[E2E] Failed to launch Playwright: ${message}`);
	process.exit(1);
});
