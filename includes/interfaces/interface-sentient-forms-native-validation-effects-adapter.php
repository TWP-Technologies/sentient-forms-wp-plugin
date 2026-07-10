<?php
/**
 * Native validation effects contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Optional contract for translating source-neutral validation outcomes into a
 * Form Source's visitor and saved-entry effects.
 */
interface Sentient_Forms_Native_Validation_Effects_Adapter_Interface
{
    /**
     * @return array<string, bool>
     */
    public function get_structural_validation_effect_capabilities(): array;

    /**
     * Apply visitor-visible validation errors.
     */
    public function apply_validation_result(
        mixed $native_validation,
        Sentient_Forms_Validation_Run_Result $result
    ): mixed;

    /**
     * Apply deferred native effects after the Form Source creates an entry.
     *
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $form
     */
    public function apply_validation_entry_effects(
        array $entry,
        array $form,
        Sentient_Forms_Validation_Run_Result $result
    ): void;
}
