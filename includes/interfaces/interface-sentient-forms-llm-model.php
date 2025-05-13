<?php

/**
 * LLM Model Metadata Management - Interfaces, Abstract Classes, Concrete Classes, and Registry.
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly.
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

require_once SENTIENT_FORMS_PLUGIN_DIR . 'includes/enums/LLMs.php';


interface Sentient_Forms_Llm_Model_Interface
{
    /**
     * Gets the unique machine-readable ID of the model.
     * This ID is used for API calls and internal logic.
     *
     * @return string The model ID.
     */
    public function get_id(): string;

    /**
     * Gets the human-readable name of the model.
     *
     * @return string The model name.
     */
    public function get_name(): string;

    /**
     * Gets a short description of the model.
     *
     * @return string The model description.
     */
    public function get_description(): string;

    /**
     * Gets the provider of the model.
     *
     * @return Sentient_Forms_Llm_Provider The model provider.
     */
    public function get_provider(): Sentient_Forms_Llm_Provider;

    /**
     * Gets the capabilities of the model.
     *
     * @return array<Sentient_Forms_Llm_Capability> An array of capabilities.
     */
    public function get_capabilities(): array;

    /**
     * Gets the status of the model.
     *
     * @return Sentient_Forms_Llm_Status The model status.
     */
    public function get_status(): Sentient_Forms_Llm_Status;

    /**
     * Gets the cost tier of the model.
     *
     * @return Sentient_Forms_Llm_Cost_Tier The model cost tier.
     */
    public function get_cost_tier(): Sentient_Forms_Llm_Cost_Tier;

    /**
     * Checks if the model is a suitable default for free tier users.
     *
     * @return bool True if it's a default for free tier, false otherwise.
     */
    public function is_default_for_free_tier(): bool;

    /**
     * Gets the model family, if applicable (e.g., "Gemini", "GPT-3.5").
     *
     * @return string|null The model family or null.
     */
    public function get_family(): ?string;
}