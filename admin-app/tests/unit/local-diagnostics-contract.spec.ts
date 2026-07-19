import { describe, expect, it } from 'vitest';
import { localDiagnosticsSettingsResponseSchema } from '$lib/api/local-diagnostics-contract';

describe('local diagnostics contract', () => {
	it('parses the local-only preference and strips additive response fields', () => {
		expect(
			localDiagnosticsSettingsResponseSchema.parse({
				local_diagnostics_enabled: true,
				updated_at: '2026-07-19T00:00:00Z',
				future_field: 'ignored'
			})
		).toEqual({
			local_diagnostics_enabled: true,
			updated_at: '2026-07-19T00:00:00Z'
		});
	});

	it('rejects the retired remote telemetry response shape', () => {
		expect(
			localDiagnosticsSettingsResponseSchema.safeParse({
				telemetry_opt_in: true,
				synced_at: '2026-07-19T00:00:00Z'
			}).success
		).toBe(false);
	});
});
