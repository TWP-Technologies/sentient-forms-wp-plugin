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
	const raw = storage.getItem(key);
	if (raw === null) return null;

	try {
		const result = schema.safeParse(JSON.parse(raw));
		if (result.success) return result.data;
	} catch {
		// Invalid JSON is handled the same way as a schema mismatch.
	}

	storage.removeItem(key);
	return null;
}
