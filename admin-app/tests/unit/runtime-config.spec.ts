import { afterEach, describe, expect, it, vi } from 'vitest';
import {
	readRuntimeConfig,
	readRuntimeConfigSafely,
	RuntimeConfigError,
	runtimeConfigSchema
} from '$lib/schemas/runtime-config';

const validConfig = {
	apiBaseUrl: '/wp-json/sentient-forms/v1/',
	restNonce: 'rest-nonce',
	ajaxNonce: 'ajax-nonce',
	siteUrl: 'https://example.test',
	formSources: [{ slug: 'gravity_forms', label: 'Gravity Forms', isActive: true }],
	license: { status: 'active', proxyKeyPresent: true, tier: 'starter' },
	telemetry: { enabled: false },
	asyncSettings: { maxAttempts: 3, baseDelaySeconds: 60, maxDelaySeconds: 3600 },
	asyncHealth: { queue_depth: 0, oldest_run_at: null, recent_failures: {}, warnings: [] }
};

describe('runtime config boundary', () => {
	afterEach(() => {
		Reflect.deleteProperty(window, 'sentientFormsConfig');
	});

	it('parses additive top-level config without trusting unknown fields', () => {
		expect(runtimeConfigSchema.parse({ ...validConfig, futureField: 'ignored' })).toEqual(
			validConfig
		);
	});

	it('accepts the tier summary object emitted by the WordPress runtime bootstrap', () => {
		const tier = {
			code: 'starter',
			display_name: 'Starter',
			monthly_credit_quota: 1000,
			site_limit: 1
		};

		expect(
			runtimeConfigSchema.parse({
				...validConfig,
				license: { ...validConfig.license, tier }
			}).license?.tier
		).toEqual(tier);
	});

	it('accepts the local-diagnostics bootstrap emitted by WordPress and rejects retired remote state', () => {
		const retired = runtimeConfigSchema.safeParse({
			...validConfig,
			telemetry: {
				enabled: true,
				updatedAt: '2026-07-11T00:00:00Z',
				syncedAt: '2026-07-10T00:00:00Z',
				remoteUpdatedAt: '2026-07-10T00:00:00Z',
				lastError: 'Remote transport retired.'
			}
		});

		expect(retired.success).toBe(false);
		expect(
			runtimeConfigSchema.parse({
				...validConfig,
				telemetry: {
					enabled: true,
					updatedAt: '2026-07-11T00:00:00Z'
				}
			}).telemetry
		).toEqual({
			enabled: true,
			updatedAt: '2026-07-11T00:00:00Z'
		});
	});

	it.each([
		['base URL', { apiBaseUrl: 'javascript:alert(1)' }],
		['scheme-relative base URL', { apiBaseUrl: '//evil.example/wp-json/' }],
		['foreign absolute base URL', { apiBaseUrl: 'https://evil.example/wp-json/' }],
		['REST nonce', { restNonce: '' }],
		['license', { license: { status: 'active', proxyKeyPresent: 'yes' } }],
		[
			'form sources',
			{ formSources: [{ slug: 'gravity_forms', label: 'Gravity Forms', isActive: 'yes' }] }
		],
		[
			'async settings',
			{ asyncSettings: { maxAttempts: '3', baseDelaySeconds: 60, maxDelaySeconds: 3600 } }
		],
		[
			'async health',
			{ asyncHealth: { queue_depth: '0', oldest_run_at: null, recent_failures: {}, warnings: [] } }
		],
		['telemetry', { telemetry: { enabled: 'yes' } }]
	])('rejects malformed %s without retaining payload data', (_label, override) => {
		window.sentientFormsConfig = { ...validConfig, ...override, secret: 'must-not-escape' };

		let rejected: unknown;
		try {
			readRuntimeConfig();
		} catch (error) {
			rejected = error;
		}

		expect(rejected).toBeInstanceOf(RuntimeConfigError);
		expect(JSON.stringify(rejected)).not.toContain('must-not-escape');
		expect(String(rejected)).not.toContain('must-not-escape');
	});

	it('logs only sanitized issue metadata when a safe read rejects runtime config', () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
		window.sentientFormsConfig = { ...validConfig, restNonce: '', secret: 'must-not-escape' };

		expect(readRuntimeConfigSafely()).toBeUndefined();
		expect(warn).toHaveBeenCalledWith(
			'[SentientForms] Rejected runtime config.',
			expect.objectContaining({ issues: expect.any(Array) })
		);
		expect(JSON.stringify(warn.mock.calls)).not.toContain('must-not-escape');
		warn.mockRestore();
	});

	it('observes config injected after an earlier missing read', () => {
		expect(readRuntimeConfig()).toBeUndefined();
		window.sentientFormsConfig = validConfig;
		expect(readRuntimeConfig()).toMatchObject({ restNonce: 'rest-nonce' });
	});
});
