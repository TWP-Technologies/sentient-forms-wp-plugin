<?php
/**
 * Realtime Form Source lifecycle contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Optional contract for adapters whose source exposes realtime execution.
 */
interface Sentient_Forms_Realtime_Adapter_Interface extends Sentient_Forms_Form_Source_Discovery_Adapter_Interface
{
    /**
     * Get the source-native realtime hook or event identifier.
     *
     * @return string
     */
    public function get_realtime_native_hook(): string;

    /**
     * Describe realtime capabilities independent of runtime availability.
     *
     * @return array<string, bool>
     */
    public function get_structural_realtime_capabilities(): array;
}
