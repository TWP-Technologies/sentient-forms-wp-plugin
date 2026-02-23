import { describe, expect, it } from 'vitest';
import {
    canConfirmSiteContextRegeneration,
    resolveSiteContextRegenerationMode
} from '$lib/utils/site-context-refresh';

describe('resolveSiteContextRegenerationMode', () => {
    it('returns first_generation_free when no context exists', () => {
        expect(resolveSiteContextRegenerationMode(null)).toBe('first_generation_free');
    });

    it('returns free_refresh when context has free entitlement', () => {
        expect(resolveSiteContextRegenerationMode({ free_refresh_available: true })).toBe(
            'free_refresh'
        );
    });

    it('returns paid_refresh when free entitlement is unavailable', () => {
        expect(resolveSiteContextRegenerationMode({ free_refresh_available: false })).toBe(
            'paid_refresh'
        );
    });
});

describe('canConfirmSiteContextRegeneration', () => {
    it('allows confirmation for first generation without credits', () => {
        expect(canConfirmSiteContextRegeneration('first_generation_free', true)).toBe(true);
    });

    it('allows confirmation for yearly free refresh without credits', () => {
        expect(canConfirmSiteContextRegeneration('free_refresh', true)).toBe(true);
    });

    it('blocks paid refresh when credits are insufficient', () => {
        expect(canConfirmSiteContextRegeneration('paid_refresh', true)).toBe(false);
    });

    it('allows paid refresh when credits are available', () => {
        expect(canConfirmSiteContextRegeneration('paid_refresh', false)).toBe(true);
    });
});
