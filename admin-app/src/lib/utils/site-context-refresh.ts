export type SiteContextRegenerationMode =
    | 'first_generation_free'
    | 'free_refresh'
    | 'paid_refresh';

export interface SiteContextRefreshDescriptor {
    free_refresh_available: boolean;
}

export function resolveSiteContextRegenerationMode(
    context: SiteContextRefreshDescriptor | null
): SiteContextRegenerationMode {
    if (context === null) {
        return 'first_generation_free';
    }

    return context.free_refresh_available ? 'free_refresh' : 'paid_refresh';
}

export function canConfirmSiteContextRegeneration(
    mode: SiteContextRegenerationMode
): boolean {
    return [
        'first_generation_free',
        'free_refresh',
        'paid_refresh'
    ].includes(mode);
}
