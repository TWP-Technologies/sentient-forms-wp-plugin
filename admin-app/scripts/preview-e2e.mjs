#!/usr/bin/env node
import path from 'node:path';
import { spawn } from 'node:child_process';
import { parsePreviewPort } from './preview-runtime.mjs';

const DEFAULT_PREVIEW_HOST = '127.0.0.1';
const DEFAULT_PREVIEW_PORT = 4175;

function getBunCommand() {
	return process.platform === 'win32' ? 'bun.exe' : 'bun';
}

function getNodeCommand() {
	return process.platform === 'win32' ? 'node.exe' : 'node';
}

function getViteCliPath() {
	return path.resolve(process.cwd(), 'node_modules', 'vite', 'bin', 'vite.js');
}

function resolvePreviewHost() {
	const rawHost = process.env.PREVIEW_HOST;
	if (typeof rawHost !== 'string') return DEFAULT_PREVIEW_HOST;
	const normalized = rawHost.trim();
	return normalized.length > 0 ? normalized : DEFAULT_PREVIEW_HOST;
}

function resolvePreviewPort() {
	return parsePreviewPort(process.env.PREVIEW_PORT) ?? DEFAULT_PREVIEW_PORT;
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

	await ensureExitCodeZero(getBunCommand(), ['run', 'build'], env);
	await ensureExitCodeZero(
		getNodeCommand(),
		[
			getViteCliPath(),
			'preview',
			'--host',
			previewHost,
			'--port',
			String(previewPort),
			'--strictPort',
			'--outDir',
			'build'
		],
		env
	);
}

main().catch((error) => {
	const message = error instanceof Error ? error.message : String(error);
	console.error(`[preview:e2e] ${message}`);
	process.exit(1);
});
