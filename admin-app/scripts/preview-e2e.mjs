#!/usr/bin/env node
import path from 'node:path';
import { spawn } from 'node:child_process';

const DEFAULT_PREVIEW_HOST = '127.0.0.1';
const DEFAULT_PREVIEW_PORT = 4175;
const MIN_PORT = 1;
const MAX_PORT = 65_535;

function getBunCommand() {
	return process.platform === 'win32' ? 'bun.exe' : 'bun';
}

function getNodeCommand() {
	return process.platform === 'win32' ? 'node.exe' : 'node';
}

function getViteCommand() {
	return path.resolve(
		process.cwd(),
		'node_modules',
		'.bin',
		process.platform === 'win32' ? 'vite.cmd' : 'vite'
	);
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
	return parsePort(process.env.PREVIEW_PORT, 'PREVIEW_PORT') ?? DEFAULT_PREVIEW_PORT;
}

function runCommand(command, args, env) {
	return new Promise((resolve, reject) => {
		const child = spawn(command, args, {
			stdio: 'inherit',
			env
		});

		child.on('error', reject);
		child.on('exit', (code, signal) => {
			if (signal) {
				reject(new Error(`${command} ${args.join(' ')} exited via signal ${signal}.`));
				return;
			}

			resolve(code ?? 1);
		});
	});
}

async function ensureExitCodeZero(command, args, env) {
	const exitCode = await runCommand(command, args, env);
	if (exitCode !== 0) {
		throw new Error(`${command} ${args.join(' ')} failed with exit code ${exitCode}.`);
	}
}

async function main() {
	const previewHost = resolvePreviewHost();
	const previewPort = resolvePreviewPort();
	const env = {
		...process.env,
		SENTIENT_FORMS_ROUTER: process.env.SENTIENT_FORMS_ROUTER ?? 'pathname',
		PREVIEW_HOST: previewHost,
		PREVIEW_PORT: String(previewPort)
	};

	await ensureExitCodeZero('node', ['scripts/kill-preview-port.mjs'], env);
	await ensureExitCodeZero(getBunCommand(), ['run', 'build'], env);
	await ensureExitCodeZero(getNodeCommand(), ['scripts/select-layout.mjs'], env);
	await ensureExitCodeZero(getViteCommand(), [
		'preview',
		'--host',
		previewHost,
		'--port',
		String(previewPort),
		'--strictPort',
		'--outDir',
		'build'
	], env);
}

main().catch((error) => {
	const message = error instanceof Error ? error.message : String(error);
	console.error(`[preview:e2e] ${message}`);
	process.exit(1);
});
