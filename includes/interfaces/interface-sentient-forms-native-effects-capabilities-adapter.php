<?php
/**
 * Installation-invariant native-effect capability contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Optional contract for Form Sources that expose native enrichment effects.
 */
interface Sentient_Forms_Native_Effects_Capabilities_Adapter_Interface
{
    /**
     * Describe native effect capabilities independent of runtime availability.
     *
     * @return array<string, bool>
     */
    public function get_structural_native_effect_capabilities(): array;
}
