import { describe, expect, it } from 'vitest';
import {
	buildActionLogRowPresentation,
	buildActiveFilterChips,
	buildPaginationPresentation,
	normalizeActionLogFilters,
	statusVariantForActionLog,
	type ActionLogFilters
} from '$lib/utils/action-log-presentation';

describe('action-log-presentation utilities', () => {
	it('normalizes filter values by trimming whitespace', () => {
		const filters: ActionLogFilters = {
			formId: ' 42 ',
			status: 'error',
			actionCode: ' spam_detection_v1 '
		};

		expect(normalizeActionLogFilters(filters)).toEqual({
			formId: '42',
			status: 'error',
			actionCode: 'spam_detection_v1'
		});
	});

	it('builds active filter chips in deterministic order', () => {
		const chips = buildActiveFilterChips({
			formId: '12',
			status: 'pending',
			actionCode: 'entry_summary_v1'
		});

		expect(chips).toEqual([
			{ key: 'form_id', label: 'Form ID', value: '12' },
			{ key: 'status', label: 'Status', value: 'Pending' },
			{ key: 'action_code', label: 'Action', value: 'entry_summary_v1' }
		]);
	});

	it('maps status values to row badge variants', () => {
		expect(statusVariantForActionLog('success')).toBe('success');
		expect(statusVariantForActionLog('pending')).toBe('warning');
		expect(statusVariantForActionLog('blocked')).toBe('danger');
		expect(statusVariantForActionLog('error')).toBe('danger');
	});

	it('builds row presentation for classification outcomes', () => {
		const presentation = buildActionLogRowPresentation({
			status: 'success',
			structuredOutputValid: true,
			classification: 'spam',
			resultSummary: null,
			errorCode: null,
			errorMessage: null
		});

		expect(presentation.statusLabel).toBe('Success');
		expect(presentation.outputLabel).toBe('Structured');
		expect(presentation.resultKind).toBe('badge');
		expect(presentation.resultLabel).toBe('Spam');
		expect(presentation.resultVariant).toBe('danger');
	});

	it('builds row presentation for blocked spam outcomes with output details', () => {
		const presentation = buildActionLogRowPresentation({
			status: 'blocked',
			structuredOutputValid: false,
			classification: 'spam',
			resultSummary: 'Submission blocked as spam.',
			errorCode: null,
			errorMessage: null
		});

		expect(presentation.statusLabel).toBe('Blocked');
		expect(presentation.statusVariant).toBe('danger');
		expect(presentation.outputLabel).toBe('Raw');
		expect(presentation.resultKind).toBe('badge');
		expect(presentation.resultLabel).toBe('Spam');
		expect(presentation.resultVariant).toBe('danger');
	});

	it('builds row presentation for error outcomes with truncated text + title', () => {
		const presentation = buildActionLogRowPresentation({
			status: 'error',
			structuredOutputValid: false,
			classification: null,
			resultSummary: null,
			errorCode: 'timeout',
			errorMessage:
				'CPS request timed out after 30 seconds while waiting for a provider response.'
		});

		expect(presentation.statusVariant).toBe('danger');
		expect(presentation.outputLabel).toBe('Not available');
		expect(presentation.resultKind).toBe('text');
		expect(presentation.resultVariant).toBe('danger');
		expect(presentation.resultLabel.endsWith('...')).toBe(true);
		expect(presentation.resultTitle).toBe(
			'timeout: CPS request timed out after 30 seconds while waiting for a provider response.'
		);
	});

	it('builds pagination labels with clamped page and zero-state handling', () => {
		expect(
			buildPaginationPresentation({
				page: 2,
				perPage: 20,
				total: 25,
				totalPages: 2
			})
		).toMatchObject({
			start: 21,
			end: 25,
			rangeLabel: 'Showing 21-25 of 25',
			pageLabel: 'Page 2 of 2'
		});

		expect(
			buildPaginationPresentation({
				page: 99,
				perPage: 20,
				total: 0,
				totalPages: 0
			})
		).toMatchObject({
			start: 0,
			end: 0,
			page: 1,
			totalPages: 1,
			rangeLabel: 'Showing 0 of 0',
			pageLabel: 'Page 1 of 1'
		});
	});
});
