import { describe, expect, it } from 'vitest';
import {
	formatSubmissionLedgerFieldPreview,
	safeSubmissionNativeEntryUrl
} from '$lib/utils/submission-ledger';
import type { SubmissionLedgerRecord } from '$lib/api/types';

function ledgerRecord(fields: Record<string, unknown>): SubmissionLedgerRecord {
	return {
		id: 1,
		submission_uuid: '123e4567-e89b-42d3-a456-426614174000',
		form_source: 'gravity_forms',
		form_id: '42',
		native_entry_id: '99',
		native_entry_url: 'https://example.test/entry/99',
		source_submitted_at: null,
		captured_at: '2030-01-05T10:00:01Z',
		logical_fields: fields,
		provider_metadata: {},
		file_refs: [],
		redaction_summary: {},
		expires_at: null,
		detail_endpoint:
			'/sentient-forms/v1/gravity_forms/forms/42/submissions/123e4567-e89b-42d3-a456-426614174000'
	};
}

describe('submission ledger utils', () => {
	it('formats nested logical field previews as JSON values', () => {
		expect(
			formatSubmissionLedgerFieldPreview(
				ledgerRecord({
					profile: { tier: 'agency', score: 91 },
					tags: ['priority', 'demo']
				})
			)
		).toContain('profile: {"tier":"agency","score":91}');
	});

	it('suppresses unsafe native entry links', () => {
		expect(safeSubmissionNativeEntryUrl('javascript:alert(1)')).toBeNull();
		expect(safeSubmissionNativeEntryUrl('https://example.test/wp-admin/admin.php?page=gf_entries')).toBe(
			'https://example.test/wp-admin/admin.php?page=gf_entries'
		);
	});
});
