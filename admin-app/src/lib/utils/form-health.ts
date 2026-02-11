import type { FormExecutionStatus } from '$lib/api/types';

export interface HealthBadgeInfo {
    variant: 'neutral' | 'success' | 'danger';
    label: string;
    tooltip: string;
}

/**
 * CB-FORMS-003: Derive badge display properties from a form's execution status.
 *
 * @param status - The execution status for this form, or null/undefined if not yet loaded.
 * @param isLoading - Whether the status is still being fetched.
 * @returns Badge variant, label, and tooltip for rendering.
 */
export function getHealthBadge(
    status: FormExecutionStatus | null | undefined,
    isLoading: boolean
): HealthBadgeInfo {
    if (isLoading) {
        return { variant: 'neutral', label: '…', tooltip: 'Checking health…' };
    }

    if (!status || status.status === 'unknown') {
        return { variant: 'neutral', label: 'No runs', tooltip: 'No execution data yet' };
    }

    if (status.status === 'success') {
        const when = status.updated_at
            ? ` at ${new Date(status.updated_at).toLocaleString()}`
            : '';
        return { variant: 'success', label: 'Healthy', tooltip: `Last run succeeded${when}` };
    }

    // status === 'error'
    const detail = status.message || status.last_error_code || 'Unknown error';
    return { variant: 'danger', label: 'Error', tooltip: detail };
}
