<?php
/**
 * Historical entry search adapter capability.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Optional capability for Form Source adapters that can read historical native entries.
 */
interface Sentient_Forms_Historical_Entries_Adapter_Interface
{
    /**
     * Search historical native entries for a form.
     *
     * Implementations return the normalized spam-guidance search envelope:
     * entries plus an availability descriptor. If native entries are unavailable
     * but the Sentient Forms ledger can be used, set allow_ledger_fallback true.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function search_historical_entries( mixed $form_id, string $query = '', int $limit = 10, string $status = 'active' ): array | WP_Error;

    /**
     * Resolve one historical native entry by picker entry ID.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get_historical_entry( mixed $form_id, string $entry_id ): array | WP_Error;
}
