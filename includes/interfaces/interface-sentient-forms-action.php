<?php
/**
 * Action interface
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Interface Sentient_Forms_Action_Interface
 * Defines the contract for all actions
 */
interface Sentient_Forms_Action_Interface
{

    /**
     * Get the action ID
     *
     * @return string
     */
    public function get_id(): string;

    /**
     * Get the action name
     *
     * @return string
     */
    public function get_name(): string;

    /**
     * Get the action description
     *
     * @return string
     */
    public function get_description(): string;

    /**
     * Get the action icon
     *
     * @return string
     */
    public function get_icon(): string;

    /**
     * Get the action settings
     *
     * @return array
     */
    public function get_settings(): array;

    /**
     * Get the action hooks
     *
     * @return array
     */
    public function get_hooks(): array;

    /**
     * Get the action form builder compatibility
     *
     * @return array
     */
    public function get_compatibility(): array;

    /**
     * Execute the action.
     *
     * @param array<string, mixed> $form_data Form submission data.
     * @param array<string, mixed> $settings  Action settings.
     * @param int|string           $entry_id  The ID of the form entry.
     * @param int|string           $form_id   The ID of the form.
     *
     * @return bool|array|WP_Error True/array on success, WP_Error on failure.
     */
    public function execute( array $form_data, array $settings, int | string $entry_id, int | string $form_id ): WP_Error | bool | array;

    /**
     * Estimate the cost of the action
     *
     * @param array $data     The data to process.
     * @param array $settings The action settings.
     *
     * @return int The estimated cost in credits.
     */
    public function estimate_cost( array $data, array $settings ): int;

    /**
     * Get the action settings fields
     *
     * @return array
     */
    public function get_settings_fields(): array;

    /**
     * Validate the action settings
     *
     * @param array $settings The settings to validate.
     *
     * @return array The validated settings.
     */
    public function validate_settings( array $settings ): array;
}
