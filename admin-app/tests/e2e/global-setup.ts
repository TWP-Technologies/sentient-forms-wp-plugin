import type { FullConfig } from '@playwright/test';

function ensureCpsBaseUrl(): void {
	const base = process.env.SENTIENT_FORMS_CPS_BASE_URL;
	if (!base) return; // Defaulted inside specs; nothing to validate.

	const normalized = base.trim();
	if (normalized.length === 0) return;

	// Telemetry endpoints live under /v1; fail fast if misconfigured.
	if (!normalized.endsWith('/v1')) {
		throw new Error(
			`SENTIENT_FORMS_CPS_BASE_URL must include the /v1 suffix (current: "${normalized}").`
		);
	}
}

export default async function globalSetup(_config: FullConfig): Promise<void> {
	ensureCpsBaseUrl();
}
