import { describe, it, expect } from 'vitest';
import {
    formatBatchDelay,
    sanitizeBatchSettings,
    DEFAULT_BATCH_SETTINGS,
    MIN_DELAY_SECONDS,
    MAX_DELAY_SECONDS
} from '$lib/utils/batch';

describe('formatBatchDelay (CB-EXEC-003/004)', () => {
    it('formats 1 second as singular', () => {
        expect(formatBatchDelay(1)).toBe('1 second');
    });

    it('formats seconds under 60', () => {
        expect(formatBatchDelay(30)).toBe('30 seconds');
    });

    it('formats 60 seconds as 1 minute', () => {
        expect(formatBatchDelay(60)).toBe('1 minute');
    });

    it('formats minutes under 3600', () => {
        expect(formatBatchDelay(300)).toBe('5 minutes');
    });

    it('formats 3600 seconds as 1 hour', () => {
        expect(formatBatchDelay(3600)).toBe('1 hour');
    });

    it('formats hours for values above 3600', () => {
        expect(formatBatchDelay(7200)).toBe('2 hours');
    });

    it('rounds partial minutes', () => {
        // 90 seconds → rounds to 2 minutes
        expect(formatBatchDelay(90)).toBe('2 minutes');
    });
});

describe('sanitizeBatchSettings (CB-EXEC-003/004)', () => {
    it('returns defaults for empty input', () => {
        const result = sanitizeBatchSettings({});
        expect(result).toEqual(DEFAULT_BATCH_SETTINGS);
    });

    it('preserves valid settings', () => {
        const result = sanitizeBatchSettings({
            enabled: true,
            delay_seconds: 120
        });
        expect(result).toEqual({
            enabled: true,
            delay_seconds: 120
        });
    });

    it('clamps delay_seconds to minimum', () => {
        const result = sanitizeBatchSettings({ delay_seconds: 1 });
        expect(result.delay_seconds).toBe(MIN_DELAY_SECONDS);
    });

    it('clamps delay_seconds to maximum', () => {
        const result = sanitizeBatchSettings({ delay_seconds: 99999 });
        expect(result.delay_seconds).toBe(MAX_DELAY_SECONDS);
    });

    it('rounds fractional values', () => {
        const result = sanitizeBatchSettings({
            delay_seconds: 45.7
        });
        expect(result.delay_seconds).toBe(46);
    });

    it('coerces enabled to boolean', () => {
        expect(sanitizeBatchSettings({ enabled: undefined }).enabled).toBe(false);
        expect(sanitizeBatchSettings({ enabled: true }).enabled).toBe(true);
    });

    it('drops legacy discount fields from sanitized output', () => {
        const result = sanitizeBatchSettings({
            enabled: true,
            delay_seconds: 90,
            discount_percent: 80
        } as unknown as Parameters<typeof sanitizeBatchSettings>[0]);

        expect(result).toEqual({
            enabled: true,
            delay_seconds: 90
        });
        expect((result as Record<string, unknown>).discount_percent).toBeUndefined();
    });
});

describe('DEFAULT_BATCH_SETTINGS', () => {
    it('has expected default values', () => {
        expect(DEFAULT_BATCH_SETTINGS.enabled).toBe(false);
        expect(DEFAULT_BATCH_SETTINGS.delay_seconds).toBe(60);
    });
});
