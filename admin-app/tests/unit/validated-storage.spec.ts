import { describe, expect, it, vi } from 'vitest';
import { z } from 'zod';
import { readValidatedStorage, type KeyValueStorage } from '$lib/storage/validated-storage';

function storageWith(value: string | null): KeyValueStorage {
	return { getItem: vi.fn(() => value), removeItem: vi.fn() };
}

describe('validated browser storage', () => {
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
});
