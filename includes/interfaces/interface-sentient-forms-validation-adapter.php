<?php
/**
 * Validation Form Source lifecycle contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Optional contract for Form Sources with a synchronous visitor-validation seam.
 */
interface Sentient_Forms_Validation_Adapter_Interface extends Sentient_Forms_Form_Source_Discovery_Adapter_Interface
{
    /**
     * Get the source-native validation hook.
     */
    public function get_validation_native_hook(): string;

    /**
     * Normalize one source-native validation callback for the shared runner.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function normalize_validation( mixed $native_validation, mixed $native_context = null ): array | WP_Error;
}
