import { describe, it, expect } from 'vitest';
import { getHealthBadge } from '$lib/utils/form-health';
import type { FormExecutionStatus } from '$lib/api/types';

describe('getHealthBadge (CB-FORMS-003)', () => {
    it('returns loading state when isLoading is true', () => {
        const result = getHealthBadge(null, true);
        expect(result).toEqual({
            variant: 'neutral',
            label: '…',
            tooltip: 'Checking health…'
        });
    });

    it('returns loading state even when status data exists but isLoading', () => {
        const status: FormExecutionStatus = {
            status: 'success',
            message: null,
            entry_id: 1,
            last_error_code: null,
            last_result: null,
            updated_at: '2026-02-11T00:00:00Z'
        };
        const result = getHealthBadge(status, true);
        expect(result.variant).toBe('neutral');
        expect(result.label).toBe('…');
    });

    it('returns "No runs" for null status', () => {
        const result = getHealthBadge(null, false);
        expect(result).toEqual({
            variant: 'neutral',
            label: 'No runs',
            tooltip: 'No execution data yet'
        });
    });

    it('returns "No runs" for undefined status', () => {
        const result = getHealthBadge(undefined, false);
        expect(result.variant).toBe('neutral');
        expect(result.label).toBe('No runs');
    });

    it('returns "No runs" for unknown status', () => {
        const status: FormExecutionStatus = {
            status: 'unknown',
            message: null,
            entry_id: null,
            last_error_code: null,
            last_result: null,
            updated_at: null
        };
        const result = getHealthBadge(status, false);
        expect(result).toEqual({
            variant: 'neutral',
            label: 'No runs',
            tooltip: 'No execution data yet'
        });
    });

    it('returns "Healthy" for success status without timestamp', () => {
        const status: FormExecutionStatus = {
            status: 'success',
            message: null,
            entry_id: 42,
            last_error_code: null,
            last_result: null,
            updated_at: null
        };
        const result = getHealthBadge(status, false);
        expect(result.variant).toBe('success');
        expect(result.label).toBe('Healthy');
        expect(result.tooltip).toBe('Last run succeeded');
    });

    it('returns "Healthy" with timestamp for success status', () => {
        const status: FormExecutionStatus = {
            status: 'success',
            message: null,
            entry_id: 42,
            last_error_code: null,
            last_result: null,
            updated_at: '2026-02-11T10:30:00Z'
        };
        const result = getHealthBadge(status, false);
        expect(result.variant).toBe('success');
        expect(result.label).toBe('Healthy');
        expect(result.tooltip).toContain('Last run succeeded at');
        // The exact formatted date depends on locale, so just verify it contains the string
        expect(result.tooltip.length).toBeGreaterThan('Last run succeeded'.length);
    });

    it('returns "Error" for error status with message', () => {
        const status: FormExecutionStatus = {
            status: 'error',
            message: 'CPS connection timed out',
            entry_id: 99,
            last_error_code: 'timeout',
            last_result: null,
            updated_at: '2026-02-11T10:30:00Z'
        };
        const result = getHealthBadge(status, false);
        expect(result.variant).toBe('danger');
        expect(result.label).toBe('Error');
        expect(result.tooltip).toBe('CPS connection timed out');
    });

    it('falls back to error_code when message is null', () => {
        const status: FormExecutionStatus = {
            status: 'error',
            message: null,
            entry_id: 99,
            last_error_code: 'insufficient_credits',
            last_result: null,
            updated_at: null
        };
        const result = getHealthBadge(status, false);
        expect(result.variant).toBe('danger');
        expect(result.label).toBe('Error');
        expect(result.tooltip).toBe('insufficient_credits');
    });

    it('falls back to "Unknown error" when both message and error_code are null', () => {
        const status: FormExecutionStatus = {
            status: 'error',
            message: null,
            entry_id: null,
            last_error_code: null,
            last_result: null,
            updated_at: null
        };
        const result = getHealthBadge(status, false);
        expect(result.variant).toBe('danger');
        expect(result.label).toBe('Error');
        expect(result.tooltip).toBe('Unknown error');
    });

    it('prefers message over error_code when both exist', () => {
        const status: FormExecutionStatus = {
            status: 'error',
            message: 'Human-readable error message',
            entry_id: null,
            last_error_code: 'some_code',
            last_result: null,
            updated_at: null
        };
        const result = getHealthBadge(status, false);
        expect(result.tooltip).toBe('Human-readable error message');
    });
});
