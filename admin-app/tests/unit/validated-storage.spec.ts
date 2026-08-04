import { describe, expect, it, vi } from 'vitest';
import { z } from 'zod';
import { readValidatedStorage, type KeyValueStorage } from '$lib/storage/validated-storage';

function storageWith(value: string | null): KeyValueStorage {
	return { getItem: vi.fn(() => value), removeItem: vi.fn() };
}

describe('validated browser storage', () => {
	it('returns null for an absent key without attempting eviction', () => {
		const storage = storageWith(null);
		expect(readValidatedStorage(storage, 'hooks', z.array(z.string()))).toBeNull();
		expect(storage.removeItem).not.toHaveBeenCalled();
	});

	it('returns schema-parsed values without exposing the serialized boundary', () => {
		const storage = storageWith('["sync","async"]');
		expect(readValidatedStorage(storage, 'hooks', z.array(z.string()))).toEqual(['sync', 'async']);
		expect(storage.removeItem).not.toHaveBeenCalled();
	});

	it.each(['not-json', '{"unexpected":true}'])('evicts malformed cached values: %s', (value) => {
		const storage = storageWith(value);
		expect(readValidatedStorage(storage, 'hooks', z.array(z.string()))).toBeNull();
		expect(storage.removeItem).toHaveBeenCalledWith('hooks');
	});

	it('fails safely when browser storage cannot be read', () => {
		const storage: KeyValueStorage = {
			getItem: vi.fn(() => {
				throw new DOMException('Access denied', 'SecurityError');
			}),
			removeItem: vi.fn()
		};

		expect(readValidatedStorage(storage, 'hooks', z.array(z.string()))).toBeNull();
		expect(storage.removeItem).not.toHaveBeenCalled();
	});

	it('fails safely when malformed storage cannot be evicted', () => {
		const storage: KeyValueStorage = {
			getItem: vi.fn(() => 'not-json'),
			removeItem: vi.fn(() => {
				throw new DOMException('Access denied', 'SecurityError');
			})
		};

		expect(readValidatedStorage(storage, 'hooks', z.array(z.string()))).toBeNull();
		expect(storage.removeItem).toHaveBeenCalledWith('hooks');
	});
});
