<?php
/**
 * Minimal Form Source identity and discovery contract.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Defines the source-neutral surface required to identify a Form Source and
 * discover its forms and fields.
 */
interface Sentient_Forms_Form_Source_Discovery_Adapter_Interface
{
    /**
     * Get the canonical Form Source identifier.
     */
    public function get_id(): string;

    /**
     * Get the human-readable Form Source name.
     */
    public function get_name(): string;

    /**
     * Determine whether the corresponding Form Source plugin is available.
     */
    public function is_active(): bool;

    /**
     * Retrieve forms available from the Form Source.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_forms(): array;

    /**
     * Retrieve fields for one form.
     *
     * @param mixed $form_id The source-native form identifier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_form_fields( $form_id ): array;
}
