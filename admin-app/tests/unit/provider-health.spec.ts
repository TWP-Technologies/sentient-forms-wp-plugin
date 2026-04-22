import type { LocalProviderCredential } from '$lib/api/types';
import {
	localOpenRouterSetupUnavailableMessage,
	openRouterActionHealth,
	providerCredentialAuthModeLabel,
	providerCredentialHttpStatus,
	providerCredentialSecretSummary,
	providerCredentialStatusDetail,
	providerStatusLabel,
	providerStatusVariant
} from '$lib/utils/provider-health';
import { describe, expect, it } from 'vitest';

function credential(
	status: LocalProviderCredential['status'],
	overrides: Partial<LocalProviderCredential> = {}
): LocalProviderCredential {
	return {
		id: 1,
		provider: 'openrouter',
		label: 'OpenRouter key',
		auth_mode: 'manual_key',
		constant_name: null,
		status,
		status_json: null,
		last_validated_at: null,
		created_at: null,
		updated_at: null,
		secret_configured: true,
		...overrides
	};
}

describe('provider-health presentation', () => {
	it('maps OpenRouter status values to badge labels and variants', () => {
		expect(providerStatusVariant('valid')).toBe('success');
		expect(providerStatusLabel('valid')).toBe('Ready');
		expect(providerStatusVariant('limited')).toBe('warning');
		expect(providerStatusLabel('limited')).toBe('Limited');
		expect(providerStatusVariant('invalid')).toBe('danger');
		expect(providerStatusLabel('missing')).toBe('Not connected');
	});

	it('normalizes numeric and string HTTP status metadata', () => {
		expect(
			providerCredentialHttpStatus(credential('limited', { status_json: { http_status: 402 } }))
		).toBe(402);
		expect(
			providerCredentialHttpStatus(credential('limited', { status_json: { http_status: '429' } }))
		).toBe(429);
		expect(
			providerCredentialHttpStatus(credential('limited', { status_json: { http_status: 'nope' } }))
		).toBeNull();
	});

	it('explains insufficient OpenRouter credits for setup and action health', () => {
		const limited = credential('limited', { status_json: { http_status: 402 } });

		expect(providerCredentialStatusDetail(limited)).toContain('insufficient credits');
		expect(localOpenRouterSetupUnavailableMessage([limited])).toContain('insufficient credits');

		const health = openRouterActionHealth([limited]);
		expect(health.status).toBe('degraded');
		expect(health.badgeStatus).toBe('limited');
		expect(health.message).toContain('insufficient credits');
		expect(health.message).toContain('stay blocked');
	});

	it('prefers a ready OpenRouter key when one is available', () => {
		const health = openRouterActionHealth([
			credential('limited', { id: 1 }),
			credential('valid', { id: 2 })
		]);

		expect(health.status).toBe('ready');
		expect(health.badgeStatus).toBe('valid');
		expect(health.credential?.id).toBe(2);
	});

	it('reports missing OpenRouter setup when no credential exists', () => {
		const health = openRouterActionHealth([]);

		expect(health.status).toBe('missing');
		expect(health.badgeStatus).toBe('missing');
		expect(health.message).toContain('Connect and validate');
	});

	it('describes unresolved constant-backed credentials clearly', () => {
		const unresolved = credential('invalid', {
			auth_mode: 'constant',
			constant_name: 'SENTIENT_FORMS_OPENROUTER_KEY',
			secret_configured: false
		});

		expect(providerCredentialAuthModeLabel(unresolved)).toBe('Server secret');
		expect(providerCredentialSecretSummary(unresolved)).toContain(
			'SENTIENT_FORMS_OPENROUTER_KEY'
		);
		expect(providerCredentialStatusDetail(unresolved)).toContain('environment variable');
		expect(localOpenRouterSetupUnavailableMessage([unresolved])).toContain('server-backed constant');
	});
});
