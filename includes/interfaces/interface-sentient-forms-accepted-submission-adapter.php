<?php
/**
 * Accepted-submission Form Source lifecycle contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Optional contract for adapters whose source exposes a confirmed,
 * accepted-submission lifecycle.
 */
interface Sentient_Forms_Accepted_Submission_Adapter_Interface extends Sentient_Forms_Form_Source_Discovery_Adapter_Interface
{
    /**
     * Get the source-native hook fired after the submission is accepted.
     */
    public function get_accepted_submission_native_hook(): string;

    /**
     * Normalize one source-native accepted submission for the shared runner.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function normalize_accepted_submission( mixed $native_submission ): array | WP_Error;
}
