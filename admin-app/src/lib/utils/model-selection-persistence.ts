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

interface PersistableProviderCredential {
	id: number;
	provider: string;
	status: string;
}

const READY_PROVIDER_STATUSES = new Set(['valid', 'limited']);

export function reconcileModelSelectionCredentialForPersistence(
	value: unknown,
	credentials: PersistableProviderCredential[] | null | undefined
): unknown {
	const normalized = normalizeModelSelectionForPersistence(value);
	if (!normalized || typeof normalized !== 'object' || Array.isArray(normalized)) return normalized;
	if (!Array.isArray(credentials)) return normalized;

	const selection = { ...(normalized as Record<string, unknown>) };
	const provider = typeof selection.provider === 'string' ? selection.provider.trim() : '';
	if (!provider) return selection;

	const readyCredentials = credentials.filter(
		(credential) =>
			credential.provider === provider &&
			READY_PROVIDER_STATUSES.has(String(credential.status ?? '').trim())
	);
	const requestedCredentialId = selection.credential_id;
	if (
		typeof requestedCredentialId === 'number' &&
		readyCredentials.some((credential) => credential.id === requestedCredentialId)
	) {
		return selection;
	}

	selection.credential_id = readyCredentials.length === 1 ? readyCredentials[0].id : null;
	return selection;
}
