import { afterEach, describe, expect, it, vi } from 'vitest';
import { MockSentientFormsApiClient } from '$lib/api/mock-client';

afterEach(() => {
	vi.useRealTimers();
});

describe('MockSentientFormsApiClient form source descriptors', () => {
	it('describes Contact Form 7 validation plus ledger-backed after-submission', async () => {
		const client = new MockSentientFormsApiClient();

		const bootstrap = await client.getFormActionsBootstrap('contact_form_7', 77);

		expect(bootstrap.form_source_descriptor).toMatchObject({
			slug: 'contact_form_7',
			label: 'Contact Form 7',
			is_active: true,
			lifecycles: {
				validation: {
					supported: true,
					native_hook: 'wpcf7_validate'
				},
				after_submission: {
					supported: true,
					native_hook: 'wpcf7_mail_sent',
					requires_ledger: true
				},
				real_time: {
					supported: false,
					native_hook: null
				}
			},
			ledger: {
				required_for_parity: true
			}
		});
	});
});

describe('MockSentientFormsApiClient local diagnostics settings', () => {
	it('persists the requested local diagnostics value', async () => {
		vi.useFakeTimers();
		vi.setSystemTime(new Date('2026-07-19T10:00:00.000Z'));
		const client = new MockSentientFormsApiClient();

		vi.setSystemTime(new Date('2026-07-19T10:01:00.000Z'));
		const disabled = await client.updateLocalDiagnosticsSettings(false);
		expect(disabled).toEqual({
			local_diagnostics_enabled: false,
			updated_at: '2026-07-19T10:01:00.000Z'
		});

		vi.setSystemTime(new Date('2026-07-19T10:02:00.000Z'));
		await expect(client.getLocalDiagnosticsSettings()).resolves.toEqual(disabled);

		await expect(client.updateLocalDiagnosticsSettings(true)).resolves.toEqual({
			local_diagnostics_enabled: true,
			updated_at: '2026-07-19T10:02:00.000Z'
		});
	});
});
