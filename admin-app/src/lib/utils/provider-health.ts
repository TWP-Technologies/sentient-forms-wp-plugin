import type { LocalProviderCredential, LocalProviderStatus } from '$lib/api/types';

export type ProviderHealthBadgeVariant = 'neutral' | 'success' | 'warning' | 'danger' | 'info';
export type OpenRouterActionHealthStatus = 'ready' | 'missing' | 'degraded' | 'unknown';

export interface OpenRouterActionHealth {
	status: OpenRouterActionHealthStatus;
	badgeStatus: LocalProviderStatus | 'missing';
	title: string;
	message: string;
	credential: LocalProviderCredential | null;
}

export function providerStatusVariant(
	status: LocalProviderStatus | 'missing'
): ProviderHealthBadgeVariant {
	switch (status) {
		case 'valid':
			return 'success';
		case 'limited':
			return 'warning';
		case 'invalid':
		case 'disabled':
			return 'danger';
		default:
			return 'neutral';
	}
}

export function providerStatusLabel(status: LocalProviderStatus | 'missing'): string {
	switch (status) {
		case 'valid':
			return 'Ready';
		case 'limited':
			return 'Limited';
		case 'invalid':
			return 'Invalid';
		case 'disabled':
			return 'Disabled';
		default:
			return 'Not connected';
	}
}

export function providerCredentialHttpStatus(credential: LocalProviderCredential): number | null {
	const httpStatus = credential.status_json?.http_status;

	if (typeof httpStatus === 'number' && Number.isFinite(httpStatus)) {
		return httpStatus;
	}

	if (typeof httpStatus === 'string' && httpStatus.trim().length > 0) {
		const parsedStatus = Number(httpStatus);
		return Number.isFinite(parsedStatus) ? parsedStatus : null;
	}

	return null;
}

export function providerCredentialStatusDetail(credential: LocalProviderCredential): string | null {
	const httpStatus = providerCredentialHttpStatus(credential);

	if (!credential.secret_configured) {
		return credential.auth_mode === 'constant'
			? 'The server constant or environment variable could not be resolved. Define it on the WordPress host, then refresh Providers before using direct actions.'
			: 'The secret is missing. Save and validate this key before using it.';
	}

	if (credential.status === 'limited') {
		if (httpStatus === 402) {
			return 'OpenRouter reported insufficient credits. Add OpenRouter credits or switch this action to a free or available model before retrying.';
		}

		if (httpStatus === 429) {
			return 'OpenRouter rate-limited this key. Wait for the provider limit to reset or use another OpenRouter key.';
		}

		return 'OpenRouter limited this key. Review your OpenRouter account limits before retrying.';
	}

	if (credential.status === 'invalid') {
		return credential.auth_mode === 'constant'
			? 'OpenRouter rejected the server-backed key. Update the constant or environment variable, then validate it again before running direct actions.'
			: 'OpenRouter rejected this key. Validate a current key before running direct actions.';
	}

	if (credential.status === 'disabled') {
		return 'This key is disabled locally and will not be used for direct actions.';
	}

	return null;
}

export function providerCredentialStatusDetailClass(credential: LocalProviderCredential): string {
	if (credential.status === 'limited') {
		return 'sf:text-warning-700';
	}

	if (credential.status === 'invalid' || credential.status === 'disabled') {
		return 'sf:text-danger-700';
	}

	return 'sf:text-slate-500';
}

export function isReadyOpenRouterCredential(credential: LocalProviderCredential): boolean {
	return (
		credential.provider === 'openrouter' &&
		credential.status === 'valid' &&
		credential.secret_configured
	);
}

export function pickBlockedOpenRouterCredential(
	credentials: LocalProviderCredential[]
): LocalProviderCredential | null {
	const openRouterCredentials = credentials.filter(
		(credential) => credential.provider === 'openrouter'
	);

	return (
		openRouterCredentials.find((credential) => credential.status === 'limited') ??
		openRouterCredentials.find(
			(credential) => credential.status !== 'valid' || !credential.secret_configured
		) ??
		openRouterCredentials[0] ??
		null
	);
}

export function localOpenRouterSetupUnavailableTitle(
	credentials: LocalProviderCredential[]
): string {
	return credentials.some((credential) => credential.provider === 'openrouter')
		? 'OpenRouter key needs attention'
		: 'No ready OpenRouter key';
}

export function localOpenRouterSetupUnavailableMessage(
	credentials: LocalProviderCredential[]
): string {
	const blockedCredential = pickBlockedOpenRouterCredential(credentials);

	if (!blockedCredential) {
		return 'Validate and save a key, or connect a server-backed constant, before creating a Direct OpenRouter action.';
	}

	const detail = providerCredentialStatusDetail(blockedCredential);

	return detail
		? `${detail} Then validate a ready OpenRouter key or server-backed constant before creating a Direct OpenRouter action.`
		: 'Validate and save a key, or connect a server-backed constant, before creating a Direct OpenRouter action.';
}

export function providerCredentialAuthModeLabel(credential: LocalProviderCredential): string {
	switch (credential.auth_mode) {
		case 'manual_key':
			return 'Local vault';
		case 'constant':
			return 'Server secret';
		case 'sentient_proxy':
			return 'Managed proxy';
		case 'oauth_broker':
			return 'OAuth broker';
		default:
			return credential.auth_mode;
	}
}

export function providerCredentialSecretSummary(credential: LocalProviderCredential): string {
	if (credential.auth_mode === 'constant') {
		const location = credential.constant_name?.trim();
		return location
			? `references ${location} on the server`
			: credential.secret_configured
				? 'resolved from the server'
				: 'not found on the server';
	}

	if (credential.auth_mode === 'sentient_proxy') {
		return credential.secret_configured ? 'proxy key available' : 'proxy key missing';
	}

	return credential.secret_configured ? 'secret configured' : 'secret missing';
}

export function openRouterActionHealth(
	credentials: LocalProviderCredential[]
): OpenRouterActionHealth {
	const readyCredential =
		credentials.find((credential) => isReadyOpenRouterCredential(credential)) ?? null;

	if (readyCredential) {
		return {
			status: 'ready',
			badgeStatus: 'valid',
			title: 'OpenRouter ready',
			message: 'Direct actions can run through the saved OpenRouter key.',
			credential: readyCredential
		};
	}

	const blockedCredential = pickBlockedOpenRouterCredential(credentials);

	if (!blockedCredential) {
		return {
			status: 'missing',
			badgeStatus: 'missing',
			title: 'OpenRouter key missing',
			message: 'Connect and validate an OpenRouter key before Direct OpenRouter actions can run.',
			credential: null
		};
	}

	const detail = providerCredentialStatusDetail(blockedCredential);

	return {
		status: 'degraded',
		badgeStatus: blockedCredential.status,
		title: 'OpenRouter key needs attention',
		message: detail
			? `${detail} Direct OpenRouter actions stay blocked until a ready key is available.`
			: 'Direct OpenRouter actions stay blocked until a ready OpenRouter key is available.',
		credential: blockedCredential
	};
}
