/**
 * Batch execution utilities (CB-EXEC-003/004).
 *
 * Pure helper functions for batching delay defaults and formatting.
 */

import type { BatchSettings } from '$lib/api/types';

/** Sensible defaults when batching is first enabled. */
export const DEFAULT_BATCH_SETTINGS: BatchSettings = {
    enabled: false,
    delay_seconds: 60,
    max_wait_seconds: 86400
} as const;

/** Minimum allowed delay in seconds. */
export const MIN_DELAY_SECONDS = 10;
/** Maximum allowed delay in seconds. */
export const MAX_DELAY_SECONDS = 3600;
/** Minimum allowed max wait in seconds (12 hours). */
export const MIN_MAX_WAIT_SECONDS = 43200;
/** Maximum allowed max wait in seconds (7 days). */
export const MAX_MAX_WAIT_SECONDS = 604800;

/**
 * Format a delay duration in seconds to a human-readable string.
 *
 * @param seconds Delay in seconds (≥ 0).
 * @returns A short human-readable label, e.g. "30 seconds", "2 minutes", "1 hour".
 */
export function formatBatchDelay(seconds: number): string {
    if (seconds < 60) {
        return seconds === 1 ? '1 second' : `${seconds} seconds`;
    }
    if (seconds < 3600) {
        const mins = Math.round(seconds / 60);
        return mins === 1 ? '1 minute' : `${mins} minutes`;
    }
    const hrs = Math.round(seconds / 3600);
    return hrs === 1 ? '1 hour' : `${hrs} hours`;
}

/**
 * Clamp batch settings to valid ranges.
 *
 * @param settings Possibly-invalid batch settings from user input.
 * @returns A sanitised copy with values clamped to allowed ranges.
 */
export function sanitizeBatchSettings(settings: Partial<BatchSettings>): BatchSettings {
    return {
        enabled: Boolean(settings.enabled),
        delay_seconds: Math.max(
            MIN_DELAY_SECONDS,
            Math.min(MAX_DELAY_SECONDS, Math.round(settings.delay_seconds ?? DEFAULT_BATCH_SETTINGS.delay_seconds))
        ),
        max_wait_seconds: Math.max(
            MIN_MAX_WAIT_SECONDS,
            Math.min(
                MAX_MAX_WAIT_SECONDS,
                Math.round(settings.max_wait_seconds ?? DEFAULT_BATCH_SETTINGS.max_wait_seconds)
            )
        )
    };
}
