import { describe, expect, it, vi } from 'vitest';
import { startOwnedPreview } from '../../scripts/preview-runtime.mjs';

function createPreviewServer(port: number) {
	return {
		close: vi.fn(async () => undefined),
		httpServer: {
			address: () => ({ address: '127.0.0.1', family: 'IPv4', port })
		}
	};
}

describe('owned Playwright preview', () => {
	it('binds atomically on port zero and propagates the actual server origin', async () => {
		const previewServer = createPreviewServer(51_234);
		const startPreview = vi.fn(async () => previewServer);

		const owned = await startOwnedPreview({
			host: '127.0.0.1',
			rawPort: undefined,
			startPreview
		});

		expect(startPreview).toHaveBeenCalledWith(
			expect.objectContaining({
				preview: {
					host: '127.0.0.1',
					port: 0,
					strictPort: false
				}
			})
		);
		expect(owned).toMatchObject({
			origin: 'http://127.0.0.1:51234',
			port: 51_234,
			server: previewServer
		});
	});

	it('atomically binds an explicitly requested valid port', async () => {
		const previewServer = createPreviewServer(45_175);
		const startPreview = vi.fn(async () => previewServer);

		await expect(
			startOwnedPreview({ host: '127.0.0.1', rawPort: '45175', startPreview })
		).resolves.toMatchObject({ port: 45_175, origin: 'http://127.0.0.1:45175' });
		expect(startPreview).toHaveBeenCalledWith(
			expect.objectContaining({
				preview: expect.objectContaining({ port: 45_175, strictPort: true })
			})
		);
	});

	it('rejects malformed explicit ports before starting Vite', async () => {
		const startPreview = vi.fn();

		await expect(
			startOwnedPreview({ host: '127.0.0.1', rawPort: 'not-a-port', startPreview })
		).rejects.toThrow('PREVIEW_PORT must be a numeric port');
		expect(startPreview).not.toHaveBeenCalled();
	});
});
