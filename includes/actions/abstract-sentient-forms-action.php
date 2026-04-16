<?php
/**
 * Abstract Action Class for Sentient Forms.
 * Provides a base for all action types within the plugin.
 *
 * @package SentientForms
 * @since   1.0.0
 */

if ( !defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly.
}

/**
 * Abstract Sentient_Forms_Action.
 * Defines common methods and properties for all actions.
 *
 * @since 1.0.0
 * @abstract
 */
abstract class Sentient_Forms_Abstract_Action implements Sentient_Forms_Action_Interface
{

    /**
     * Action ID.
     *
     * @var string
     */
    protected string $id = '';

    /**
     * Action name.
     *
     * @var string
     */
    protected string $name = '';

    /**
     * Action description.
     *
     * @var string
     */
    protected string $description = '';

    /**
     * Settings fields for the action.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $settings_fields = [];

    /**
     * Reference to the main plugin instance.
     *
     * @var Sentient_Forms_Plugin
     */
    protected Sentient_Forms_Plugin $plugin;

    /**
     * Constructor.
     *
     * @param Sentient_Forms_Plugin $plugin Main plugin instance.
     */
    public function __construct( Sentient_Forms_Plugin $plugin )
    {
        $this->plugin = $plugin;
        $this->init();
    }

    /**
     * Initialize action properties.
     * To be implemented by child classes.
     */
    abstract protected function init(): void;

    /**
     * Get action ID.
     *
     * @return string Action ID.
     */
    public function get_id(): string
    {
        return $this->id;
    }

    /**
     * Get action name.
     *
     * @return string Action name.
     */
    public function get_name(): string
    {
        return $this->name;
    }

    /**
     * Get action description.
     *
     * @return string Action description.
     */
    public function get_description(): string
    {
        return $this->description;
    }

    /**
     * Get settings fields for the action.
     *
     * @return array<string, array<string, mixed>> Settings fields.
     */
    public function get_settings_fields(): array
    {
        // Common fields can be added here if needed by all actions.
        $common_fields = [
            'llm' => [
                'label'       => __( 'LLM Model', 'sentient-forms' ),
                'type'        => 'llm_model_select', // Custom type to be handled by the view.
                'description' => __( 'Select the LLM to use for this action. Leave blank to use the global default.', 'sentient-forms' ),
                'default'     => '', // Empty means use global default.
                // 'required_capabilities' => $this->get_required_llm_capabilities(), // This can be set dynamically
            ],
            // Add other common fields like 'prompt', 'temperature' if applicable to many actions.
        ];
        return array_merge( $this->settings_fields, $common_fields );
    }

    /**
     * Get the LLM capabilities required by this action.
     * Child classes should override this if they have specific capability requirements.
     *
     * @return Sentient_Forms_Llm_Capability[] Array of required capability enums.
     */
    public function get_required_llm_capabilities(): array
    {
        return []; // Default to no specific capabilities required.
    }

    /**
     * Validate action settings.
     *
     * @param array<string, mixed> $settings Settings to validate.
     *
     * @return array<string, mixed> Validated settings.
     */
    public function validate_settings( array $settings ): array
    {
        $validated_settings    = [];
        $llm_registry          = $this->plugin->get_llm_model_registry();
        $global_settings       = get_option( 'sentient_forms_settings', [] );
        $default_global_llm_id = $global_settings[ 'default_llm' ] ?? '';

        // If the global default itself is not set or invalid, try the system's free default.
        if ( $llm_registry instanceof Sentient_Forms_Llm_Model_Registry )
        {
            if ( empty( $default_global_llm_id ) || !$llm_registry->get_model_by_id( $default_global_llm_id ) )
            {
                $default_global_llm_id = SENTIENT_FORMS_DEFAULT_FREE_LLM_ID; // Fallback to constant.
            }
            // Ensure even this constant-defined ID is valid.
            if ( !$llm_registry->get_model_by_id( $default_global_llm_id ) )
            {
                // If still no valid default, this is problematic.
                // Maybe pick the first available active model as a last resort.
                $active_models = $llm_registry->get_models( [ Sentient_Forms_Llm_Status::ACTIVE ] );
                if ( !empty( $active_models ) )
                {
                    $first_active_model    = reset( $active_models );
                    $default_global_llm_id = $first_active_model->get_id();
                }
                else
                {
                    $default_global_llm_id = ''; // No models available.
                }
            }
        }
        else
        {
            // Registry not available, critical issue.
            // Use a hardcoded fallback if absolutely necessary, though this state should be avoided.
            $default_global_llm_id = defined( 'SENTIENT_FORMS_DEFAULT_FALLBACK_MODEL_ID' ) ? SENTIENT_FORMS_DEFAULT_FALLBACK_MODEL_ID
                : 'gemini-3-flash-preview';
        }

        foreach ( $this->get_settings_fields() as $key => $field )
        {
            $value = $settings[ $key ] ?? $field[ 'default' ] ?? null;

            if ( $key === 'llm' )
            {
                $selected_llm_id = sanitize_text_field( $value );
                $model           = null;

                if ( $llm_registry instanceof Sentient_Forms_Llm_Model_Registry )
                {
                    if ( !empty( $selected_llm_id ) )
                    {
                        $model = $llm_registry->get_model_by_id( $selected_llm_id );
                        // Also check if the selected model meets action's capability requirements.
                        if ( $model )
                        {
                            $action_capabilities = $this->get_required_llm_capabilities();
                            if ( !empty( $action_capabilities ) )
                            {
                                $model_capabilities = $model->get_capabilities();
                                $has_all_required   = true;
                                foreach ( $action_capabilities as $required_cap )
                                {
                                    if ( !in_array( $required_cap, $model_capabilities, true ) )
                                    {
                                        $has_all_required = false;
                                        break;
                                    }
                                }
                                if ( !$has_all_required )
                                {
                                    $model = null; // Model does not meet requirements.
                                    // Optionally log this or provide feedback.
                                }
                            }
                        }
                    }

                    if ( !$model )
                    {
                        // If selected LLM is invalid, or doesn't meet capabilities, or empty, use the global default.
                        $validated_settings[ $key ] = $default_global_llm_id;
                    }
                    else
                    {
                        $validated_settings[ $key ] = $model->get_id();
                    }
                }
                else
                {
                    // LLM Registry not available, use a safe fallback.
                    $validated_settings[ $key ] = !empty( $selected_llm_id ) ? $selected_llm_id : $default_global_llm_id;
                }
            }
            elseif ( isset( $field[ 'sanitize_callback' ] ) && is_callable( $field[ 'sanitize_callback' ] ) )
            {
                $validated_settings[ $key ] = call_user_func( $field[ 'sanitize_callback' ], $value );
            }
            elseif ( $field[ 'type' ] === 'textarea' )
            {
                $validated_settings[ $key ] = sanitize_textarea_field( $value );
            }
            elseif ( $field[ 'type' ] === 'number' )
            {
                $validated_settings[ $key ] = (float)$value;
            }
            else
            {
                $validated_settings[ $key ] = sanitize_text_field( $value );
            }
        }
        return $validated_settings;
    }

    /**
     * Normalize execution payload so child classes can talk to the CPS executor consistently.
     *
     * @param array      $data     Raw adapter payload.
     * @param int|string $entry_id Entry identifier supplied by adapters/async handler.
     * @param int|string $form_id  Form identifier supplied by adapters/async handler.
     *
     * @return array<string, mixed>
     */
    protected function normalize_execution_payload( array $data, int | string $entry_id = 0, int | string $form_id = 0 ): array
    {
        $form  = is_array( $data[ 'form' ] ?? null ) ? $data[ 'form' ] : [];
        $entry = is_array( $data[ 'entry' ] ?? null ) ? $data[ 'entry' ] : [];

        if ( $form_id && !isset( $form[ 'id' ] ) )
        {
            $form[ 'id' ] = $form_id;
        }

        if ( $entry_id && !isset( $entry[ 'id' ] ) )
        {
            $entry[ 'id' ] = $entry_id;
        }

        $validation_result = isset( $data[ 'validation_result' ] ) && is_array( $data[ 'validation_result' ] )
            ? $data[ 'validation_result' ]
            : null;

        $hook = $data[ 'hook' ]
                ??
                ( $validation_result ? 'gform_validation' : 'gform_after_submission' );

        return [
            'form'              => $form,
            'entry'             => $entry,
            'validation_result' => $validation_result,
            'hook'              => $hook,
            'form_source'       => $data[ 'form_source' ] ?? 'gravity_forms',
        ];
    }

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
    abstract public function execute( array $form_data, array $settings, int | string $entry_id, int | string $form_id ): WP_Error | bool | array;

    /**
     * Estimate the cost of executing the action.
     *
     * @param array<string, mixed> $form_data Form submission data (for context like message length).
     * @param array<string, mixed> $settings  Action settings.
     *
     * @return int Cost estimation details in credits
     */
    public function estimate_cost( array $form_data, array $settings ): int
    {
        $llm_id = $settings[ 'llm' ] ?? null;
        if ( empty( $llm_id ) )
        {
            $global_settings = get_option( 'sentient_forms_settings', [] );
            $llm_id          = $global_settings[ 'default_llm' ] ?? SENTIENT_FORMS_DEFAULT_FREE_LLM_ID;
        }

        $llm_registry = $this->plugin->get_llm_model_registry();
        $model        = null;

        if ( $llm_registry instanceof Sentient_Forms_Llm_Model_Registry )
        {
            $model = $llm_registry->get_model_by_id( $llm_id );
        }

        if ( !$model )
        {
            // Fallback if model not found, though validation should prevent this.
            // This could indicate an issue or a model was removed after being configured.
            return 0; // No cost estimation possible.
        }

        // Basic token estimation (very rough).
        // A more sophisticated approach would involve a proper tokenizer for the specific model.
        $prompt_text      = $settings[ 'prompt' ] ?? ''; // Assuming 'prompt' is a setting.
        $combined_text    = $prompt_text . ' ' . implode( ' ', array_values( $form_data ) );
        $estimated_tokens = str_word_count( $combined_text ) * 1.3; // Approximation: 1 word ~ 1.3 tokens.

        // Get cost tier and potentially specific costs from the model object.
        // The current Sentient_Forms_Llm_Model_Interface doesn't define methods for get_input_cost_per_token() etc.
        // So we'll rely on cost_tier for now.
        // The instruction "The current hardcoded token costs in estimate_cost might need to be re-evaluated or made more dynamic"
        // implies we should try to make it more dynamic if possible.
        // If the $model object had methods like $model->get_input_token_price() and $model->get_output_token_price(), we could use them.
        // For now, let's use a placeholder logic based on cost tier.

        $cost_tier      = $model->get_cost_tier(); // This is a Sentient_Forms_Llm_Cost_Tier enum.
        $cost_per_token = 0.000002;                // Default very low cost.

        // gx todo - make models themselves responsible for their own costs.
        switch ( $cost_tier )
        {
            case Sentient_Forms_Llm_Cost_Tier::LOWEST:
                $cost_per_token = 0.000001;
                break;
            case Sentient_Forms_Llm_Cost_Tier::LOW:
                $cost_per_token = 0.0000025;
                break;
            case Sentient_Forms_Llm_Cost_Tier::MEDIUM:
                $cost_per_token = 0.000005;
                break;
            case Sentient_Forms_Llm_Cost_Tier::HIGH:
                $cost_per_token = 0.000020;
                break;
            case Sentient_Forms_Llm_Cost_Tier::PREMIUM:
                $cost_per_token = 0.000050;
        }
        
        // This assumes input and output tokens cost the same, which is often not true.
        // And that the proxy API doesn't provide pre-estimation.
        // If the model object itself could store token prices, that would be ideal.
        // e.g., $model->get_price_per_input_token(), $model->get_price_per_output_token()

        $estimated_cost_usd = $estimated_tokens * $cost_per_token;

        return round( $estimated_cost_usd, 2 );
    }

    /**
     * Helper to get the API client.
     *
     * @return Sentient_Forms_Llm_Api_Client
     */
    protected function get_api_client(): Sentient_Forms_Llm_Api_Client
    {
        return new Sentient_Forms_Llm_Api_Client( $this->plugin );
    }
}
