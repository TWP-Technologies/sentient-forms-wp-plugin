import { describe, expect, it } from 'vitest';
import { MockSentientFormsApiClient } from '$lib/api/mock-client';

describe('MockSentientFormsApiClient form source descriptors', () => {
	it('describes Contact Form 7 as after-submission only and ledger-required', async () => {
		const client = new MockSentientFormsApiClient();

		const bootstrap = await client.getFormActionsBootstrap('contact_form_7', 77);

		expect(bootstrap.form_source_descriptor).toMatchObject({
			slug: 'contact_form_7',
			label: 'Contact Form 7',
			is_active: true,
			lifecycles: {
				validation: {
					supported: false,
					native_hook: null
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
