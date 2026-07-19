#!/usr/bin/env node

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

function formatOriginHost(host) {
	return host.includes(':') && !host.startsWith('[') ? `[${host}]` : host;
}

export async function startOwnedPreview({ host, rawPort, startPreview }) {
	const explicitPort = parsePreviewPort(rawPort);
	const server = await startPreview({
		build: { outDir: 'build' },
		preview: {
			host,
			port: explicitPort ?? 0,
			strictPort: explicitPort !== null
		}
	});

	try {
		const address = server.httpServer.address();
		if (address === null || typeof address === 'string') {
			throw new Error(`Vite did not expose a bound preview port on ${host}.`);
		}

		return {
			origin: `http://${formatOriginHost(host)}:${address.port}`,
			port: address.port,
			server
		};
	} catch (error) {
		await server.close();
		throw error;
	}
}
