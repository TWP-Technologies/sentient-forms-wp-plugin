/**
 * Credit-related display utilities.
 *
 * Credit periods in Sentient Forms always reset on the 1st of each calendar
 * month (UTC).  The CPS function `current_period_start()` uses the same rule,
 * so the next reset is always the 1st of the following month.
 */

export interface CreditResetInfo {
    /** ISO date string of the next reset, e.g. "2026-03-01" */
    nextResetIso: string;
    /** Human-readable date, e.g. "Mar 1, 2026" */
    nextResetLabel: string;
    /** Days remaining until reset (0 = today is reset day) */
    daysUntilReset: number;
    /** Short contextual string, e.g. "Resets Mar 1 (18 days)" */
    summary: string;
}

/**
 * Compute the next credit-period reset date relative to `now`.
 *
 * @param now  Override for testing; defaults to `new Date()`.
 */
export function getNextCreditReset(now: Date = new Date()): CreditResetInfo {
    const year = now.getFullYear();
    const month = now.getMonth(); // 0-based

    // Next reset is always the 1st of the next month (UTC).
    // If today IS the 1st, credit has just reset — next reset is next month.
    const nextReset = new Date(year, month + 1, 1);

    const diffMs = nextReset.getTime() - now.getTime();
    const daysUntilReset = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

    const nextResetIso = formatIsoDate(nextReset);
    const nextResetLabel = nextReset.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });

    const shortLabel = nextReset.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric'
    });

    let summary: string;
    if (daysUntilReset <= 1) {
        summary = `Resets tomorrow`;
    } else {
        summary = `Resets ${shortLabel} (${daysUntilReset} days)`;
    }

    return { nextResetIso, nextResetLabel, daysUntilReset, summary };
}

function formatIsoDate(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}
