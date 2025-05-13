<?php

if ( !defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Llm_Model_Registry
{
    /**
     * @var array<string, Sentient_Forms_Llm_Model_Interface>
     */
    private array $models = [];

    public function __construct()
    {
        $this->discover_models();
    }

    /**
     * Registers a model instance.
     *
     * @param Sentient_Forms_Llm_Model_Interface $model The model instance to register.
     */
    public function register_model( Sentient_Forms_Llm_Model_Interface $model ): void
    {
        $this->models[ $model->get_id() ] = $model;
    }

    /**
     * Discovers and registers built-in models.
     * This can be extended to discover models from other plugins or themes via hooks.
     */
    private function discover_models(): void
    {
        $this->register_model( new Sentient_Forms_Google_Gemini_2_0_Flash_Lite_Llm() );
        $this->register_model( new Sentient_Forms_Google_Gemini_2_0_Flash_Llm() );
        $this->register_model( new Sentient_Forms_Google_Gemini_2_5_Flash_Preview_Llm() );
        $this->register_model( new Sentient_Forms_Google_Gemini_2_5_Pro_Preview_Llm() );

        /**
         * Action hook to allow other plugins/themes to register their own LLM models.
         *
         * @param Sentient_Forms_Llm_Model_Registry $this The instance of the model registry.
         */
        do_action( 'sentient_forms_register_llm_models', $this );
    }

    /**
     * Gets a model by its ID.
     *
     * @param string $id The model ID.
     *
     * @return Sentient_Forms_Llm_Model_Interface|null The model instance or null if not found.
     */
    public function get_model_by_id( string $id ): ?Sentient_Forms_Llm_Model_Interface
    {
        return $this->models[ $id ] ?? null;
    }

    /**
     * Gets all registered models, optionally filtered.
     *
     * @param array<Sentient_Forms_Llm_Status>|null     $statuses     Array of Sentient_Forms_Llm_Status enums to filter by.
     * @param array<Sentient_Forms_Llm_Provider>|null   $providers    Array of Sentient_Forms_Llm_Provider enums to filter by.
     * @param array<Sentient_Forms_Llm_Capability>|null $capabilities Array of Sentient_Forms_Llm_Capability enums to filter by (model must have ALL
     *                                                                specified capabilities).
     * @param array<Sentient_Forms_Llm_Cost_Tier>|null  $cost_tiers   Array of Sentient_Forms_Llm_Cost_Tier enums to filter by.
     *
     * @return array<Sentient_Forms_Llm_Model_Interface> Filtered list of model instances.
     */
    public function get_models(
        ?array $statuses = null,
        ?array $providers = null,
        ?array $capabilities = null,
        ?array $cost_tiers = null,
    ): array {
        $filtered_models = [];

        foreach ( $this->models as $model )
        {
            $match = true;

            if ( $statuses !== null && !in_array( $model->get_status(), $statuses, true ) )
            {
                $match = false;
            }

            if ( $match && $providers !== null && !in_array( $model->get_provider(), $providers, true ) )
            {
                $match = false;
            }

            if ( $match && $capabilities !== null )
            {
                $model_capabilities = $model->get_capabilities();
                foreach ( $capabilities as $required_capability )
                {
                    if ( !in_array( $required_capability, $model_capabilities, true ) )
                    {
                        $match = false;
                        break;
                    }
                }
            }

            if ( $match && $cost_tiers !== null && !in_array( $model->get_cost_tier(), $cost_tiers, true ) )
            {
                $match = false;
            }

            if ( $match )
            {
                $filtered_models[] = $model;
            }
        }
        return $filtered_models;
    }

    /**
     * Gets all registered models.
     *
     * @return array<Sentient_Forms_Llm_Model_Interface> All registered model instances.
     */
    public function get_all_models(): array
    {
        return array_values( $this->models );
    }
}
