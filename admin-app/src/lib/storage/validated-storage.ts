import { type ZodType, type output } from 'zod';

export interface KeyValueStorage {
	getItem(key: string): string | null;
	removeItem(key: string): void;
}

export function readValidatedStorage<TSchema extends ZodType>(
	storage: KeyValueStorage,
	key: string,
	schema: TSchema
): output<TSchema> | null {
	let raw: string | null;
	try {
		raw = storage.getItem(key);
	} catch {
		return null;
	}
	if (raw === null) return null;

	try {
		const result = schema.safeParse(JSON.parse(raw));
		if (result.success) return result.data;
	} catch {
		// Invalid JSON is handled the same way as a schema mismatch.
	}

	try {
		storage.removeItem(key);
	} catch {
		// Eviction is best effort when browser storage is unavailable.
	}
	return null;
}
