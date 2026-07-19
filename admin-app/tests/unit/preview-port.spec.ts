import { describe, expect, it } from 'vitest';
import { selectPreviewPort } from '../../scripts/preview-port.mjs';

describe('Playwright preview port selection', () => {
	it('asks the operating system for a free local port by default', async () => {
		const selected = await selectPreviewPort(undefined, '127.0.0.1');

		expect(selected.source).toBe('allocated');
		expect(selected.port).toBeGreaterThan(0);
		expect(selected.port).toBeLessThanOrEqual(65_535);
	});

	it('preserves an explicitly requested valid port', async () => {
		await expect(selectPreviewPort('45175', '127.0.0.1')).resolves.toEqual({
			port: 45_175,
			source: 'env'
		});
	});

	it('rejects malformed explicit ports without touching another process', async () => {
		await expect(selectPreviewPort('not-a-port', '127.0.0.1')).rejects.toThrow(
			'PREVIEW_PORT must be a numeric port'
		);
	});
});
