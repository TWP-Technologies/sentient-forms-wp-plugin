<?php
/**
 * Installation-invariant native-entry capability contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Optional contract for Form Sources that can expose native entry surfaces.
 */
interface Sentient_Forms_Native_Entry_Capabilities_Adapter_Interface
{
    /**
     * Describe native entry capabilities independent of runtime availability.
     *
     * @return array<string, bool>
     */
    public function get_structural_native_entry_capabilities(): array;
}
