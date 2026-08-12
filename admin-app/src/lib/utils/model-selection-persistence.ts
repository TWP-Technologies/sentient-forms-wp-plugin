export function normalizeModelSelectionForPersistence(value: unknown): unknown {
	if (!value || typeof value !== 'object' || Array.isArray(value)) return value;

	const selection = { ...(value as Record<string, unknown>) };
	const credentialId = selection.credential_id;
	selection.credential_id =
		typeof credentialId === 'number' && Number.isSafeInteger(credentialId) && credentialId > 0
			? credentialId
			: null;

	return selection;
}
