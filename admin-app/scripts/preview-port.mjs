#!/usr/bin/env node
import { createServer } from 'node:net';

const MIN_PORT = 1;
const MAX_PORT = 65_535;

export function parsePreviewPort(rawValue, fieldName = 'PREVIEW_PORT') {
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

function allocateFreePort(host) {
	return new Promise((resolve, reject) => {
		const server = createServer();
		let settled = false;

		function rejectOnce(error) {
			if (settled) return;
			settled = true;
			reject(error);
		}

		server.once('error', rejectOnce);
		server.listen({ host, port: 0, exclusive: true }, () => {
			const address = server.address();
			if (address === null || typeof address === 'string') {
				server.close();
				rejectOnce(new Error(`Unable to allocate a preview port on ${host}.`));
				return;
			}

			const port = address.port;
			server.close((error) => {
				if (error) {
					rejectOnce(error);
					return;
				}
				if (settled) return;
				settled = true;
				resolve(port);
			});
		});
	});
}

export async function selectPreviewPort(rawValue, host) {
	const requestedPort = parsePreviewPort(rawValue);
	if (requestedPort !== null) {
		return { port: requestedPort, source: 'env' };
	}

	return {
		port: await allocateFreePort(host),
		source: 'allocated'
	};
}
