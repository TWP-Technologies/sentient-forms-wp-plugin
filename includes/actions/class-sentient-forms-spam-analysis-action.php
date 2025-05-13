<?php
/**
 * Spam Analysis Action
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Class Sentient_Forms_Spam_Analysis_Action
 * Analyzes form submissions for spam content
 */
class Sentient_Forms_Spam_Analysis_Action extends Sentient_Forms_Abstract_Action
{

    /**
     * Initialize action properties.
     * Sets the ID, name, description, and specific settings fields for this action.
     */
    protected function init(): void
    {
        $this->id          = 'spam_analysis';
        $this->name        = __( 'Spam Analysis', 'sentient-forms' );
        $this->description = __( 'Analyzes form submissions for spam content using AI.', 'sentient-forms' );

        // Define action-specific settings fields.
        // Common fields like 'llm' and 'enabled' are added by the parent Abstract_Sentient_Forms_Action.
        $this->settings_fields = [
            'prompt_template'   => [
                'type'        => 'textarea',
                'label'       => __( 'Prompt Template', 'sentient-forms' ),
                'default'     => $this->get_default_prompt_template(),
                'description' => __(
                    'The prompt template to use for spam analysis. You can use form field merge tags like {field_id} or {all_fields}.',
                    'sentient-forms',
                ),
            ],
            'spam_threshold'    => [
                'type'        => 'number',
                'label'       => __( 'Spam Threshold', 'sentient-forms' ),
                'default'     => 0.7,
                'min'         => 0,
                'max'         => 1,
                'step'        => 0.1,
                'description' => __( 'Confidence level threshold for marking as spam (0-1).', 'sentient-forms' ),
            ],
            'mark_as_spam'      => [
                'type'        => 'checkbox',
                'label'       => __( 'Mark as Spam (Gravity Forms)', 'sentient-forms' ),
                'default'     => true,
                'description' => __( 'Mark the entry as spam in Gravity Forms if detected as spam.', 'sentient-forms' ),
            ],
            'reject_submission' => [
                'type'        => 'checkbox',
                'label'       => __( 'Reject Submission', 'sentient-forms' ),
                'default'     => true,
                'description' => __( 'Prevent the form from being submitted if detected as spam.', 'sentient-forms' ),
            ],
            'rejection_message' => [
                'type'        => 'text',
                'label'       => __( 'Rejection Message', 'sentient-forms' ),
                'default'     => __(
                    'This submission has been identified as potential spam. Please try again or contact us directly.',
                    'sentient-forms',
                ),
                'description' => __( 'Message to display to the user if the submission is rejected.', 'sentient-forms' ),
            ],
        ];
    }

    /**
     * Get the action icon.
     * This is a custom method for this action.
     *
     * @return string
     */
    public function get_icon(): string
    {
        return 'dashicons-shield';
    }

    /**
     * Get the action hooks.
     * This is a custom method for this action, indicating where it might integrate.
     *
     * @return array
     */
    public function get_hooks(): array
    {
        return [
            'gform_validation' => __( 'During Validation (Gravity Forms)', 'sentient-forms' ),
            // Add other hooks like 'wpforms_process_entry_save' if applicable
        ];
    }

    /**
     * Get default prompt template for this action.
     *
     * @return string
     */
    protected function get_default_prompt_template(): string
    {
        return __(
            "You are a spam detection system for a website form submission. Your task is to analyze the following form submission and determine if it's likely to be spam.\n\nForm Name: {form_title}\nSubmission Data:\n{all_fields}\n\nPlease analyze this submission and respond with a JSON object containing:\n1. 'is_spam': A boolean indicating if this is likely spam (true/false)\n2. 'confidence': A number between 0 and 1 indicating your confidence level\n3. 'reasoning': A brief explanation of why you think this is or isn't spam\n4. 'spam_indicators': An array of specific elements that suggest this might be spam\n\nRespond ONLY with the JSON object and nothing else.",
            'sentient-forms',
        );
    }

    /**
     * Validate the action settings.
     *
     * @param array $settings The settings to validate.
     *
     * @return array The validated settings.
     */
    public function validate_settings( array $settings ): array
    {
        // Start with parent validation (handles 'llm', 'enabled', etc.)
        $validated_settings = parent::validate_settings( $settings );

        // Validate spam threshold (0-1)
        if ( isset( $settings[ 'spam_threshold' ] ) )
        {
            $validated_settings[ 'spam_threshold' ] = max( 0, min( 1, floatval( $settings[ 'spam_threshold' ] ) ) );
        }
        else
        {
            $validated_settings[ 'spam_threshold' ] = $this->settings_fields[ 'spam_threshold' ][ 'default' ] ?? 0.7;
        }

        // Ensure boolean values for checkboxes
        $boolean_fields = [ 'mark_as_spam', 'reject_submission' ];
        foreach ( $boolean_fields as $field_key )
        {
            if ( isset( $settings[ $field_key ] ) )
            {
                $validated_settings[ $field_key ] = rest_sanitize_boolean( $settings[ $field_key ] );
            }
            else
            {
                // If not set (e.g. checkbox unchecked), it should be false.
                // Default is handled if key is entirely missing from $settings.
                $validated_settings[ $field_key ] = $this->settings_fields[ $field_key ][ 'default' ] ?? false;
            }
        }
        // Override default for mark_as_spam and reject_submission if they are not in $settings (e.g. during initial save)
        // The parent::validate_settings would have set them based on its 'enabled' field's default.
        // Here, we ensure our specific defaults are applied if the field isn't explicitly passed.
        if ( !isset( $settings[ 'mark_as_spam' ] ) )
        {
            $validated_settings[ 'mark_as_spam' ] = $this->settings_fields[ 'mark_as_spam' ][ 'default' ] ?? true;
        }
        if ( !isset( $settings[ 'reject_submission' ] ) )
        {
            $validated_settings[ 'reject_submission' ] = $this->settings_fields[ 'reject_submission' ][ 'default' ] ?? true;
        }

        if ( isset( $settings[ 'rejection_message' ] ) )
        {
            $validated_settings[ 'rejection_message' ] = sanitize_textarea_field( $settings[ 'rejection_message' ] );
            if ( empty( $validated_settings[ 'rejection_message' ] ) )
            {
                $validated_settings[ 'rejection_message' ] = $this->settings_fields[ 'rejection_message' ][ 'default' ]
                                                             ??
                                                             __( 'This submission has been identified as potential spam.', 'sentient-forms' );
            }
        }
        else
        {
            $validated_settings[ 'rejection_message' ] = $this->settings_fields[ 'rejection_message' ][ 'default' ]
                                                         ??
                                                         __( 'This submission has been identified as potential spam.', 'sentient-forms' );
        }

        if ( isset( $settings[ 'prompt_template' ] ) )
        {
            $validated_settings[ 'prompt_template' ] = sanitize_textarea_field( $settings[ 'prompt_template' ] );
        }
        else
        {
            $validated_settings[ 'prompt_template' ] = $this->get_default_prompt_template();
        }

        return $validated_settings;
    }

    /**
     * Execute the action.
     * This method is called when the associated form hook triggers.
     *
     * @param array      $form_data Form submission data.
     * @param array      $settings  Action settings.
     * @param int|string $entry_id  The ID of the form entry.
     * @param int|string $form_id   The ID of the form.
     *
     * @return WP_Error|bool True on success, WP_Error on failure, or an array for validation hooks.
     */
    public function execute( array $form_data, array $settings, int | string $entry_id, int | string $form_id ): WP_Error | bool
    {
        // Ensure settings are complete with defaults
        $settings = $this->validate_settings( array_merge( $this->get_default_settings_values(), $settings ) );

        if ( !( $settings[ 'enabled' ] ?? true ) )
        {
            return true; // Action is disabled
        }

        $llm_id = $settings[ 'llm' ] ?? null;
        if ( empty( $llm_id ) )
        {
            $global_options = get_option( 'sentient_forms_options', [] );
            $llm_id         = $global_options[ 'default_llm' ] ?? SENTIENT_FORMS_DEFAULT_FREE_LLM_ID;
        }

        $api_client = $this->plugin->get_api_client();
        if ( !$api_client || empty( $this->plugin->get_proxy_api_key() ) )
        {
            error_log( 'Sentient Forms (Spam Analysis): API client not available or API key not set.' );
            return new WP_Error( 'api_client_unavailable', __( 'API client is not available or API key not set.', 'sentient-forms' ) );
        }

        // Prepare prompt
        // This is a simplified placeholder for merge tag replacement.
        $prompt = str_replace( '{form_title}', "Form ID: " . $form_id, $settings[ 'prompt_template' ] ); // Placeholder
        $prompt = str_replace( '{all_fields}', print_r( $form_data, true ), $prompt );                   // Placeholder

        $api_args = [
            'prompt' => $prompt,
            // Add other API args like temperature, max_tokens if configurable or fixed for this action
        ];

        $response = $api_client->make_request( $llm_id, $api_args );

        // Process the response
        $processed_result = $this->process_response(
            $response,
            [ 'form_data' => $form_data, 'entry_id' => $entry_id, 'form_id' => $form_id ],
            $settings,
        );

        // Handle Gravity Forms validation hook if applicable
        // This part is highly dependent on how you integrate with specific form plugin hooks.
        // The $data argument in process_response was used for this.
        // If current hook is 'gform_validation', $entry_id might be the $validation_result array.
        if ( current_filter() === 'gform_validation' && isset( $processed_result[ 'validation_result' ] ) )
        {
            return $processed_result[ 'validation_result' ];
        }

        if ( is_wp_error( $response ) )
        {
            error_log( 'Sentient Forms (Spam Analysis) API Error for entry ' . $entry_id . ': ' . $response->get_error_message() );
            return $response;
        }

        // Log success or further actions
        // error_log('Sentient Forms (Spam Analysis) executed for entry ' . $entry_id . '. Result: ' . print_r($processed_result, true));

        return true;
    }

    /**
     * Get default values for all settings fields defined for this action.
     *
     * @return array
     */
    private function get_default_settings_values(): array
    {
        $defaults = [];
        foreach ( $this->settings_fields as $key => $field )
        {
            if ( isset( $field[ 'default' ] ) )
            {
                $defaults[ $key ] = $field[ 'default' ];
            }
        }
        return $defaults;
    }

    /**
     * Process the LLM API response.
     *
     * @param WP_Error|array $response     The API response.
     * @param array          $context_data Contextual data (e.g., form_data, entry_id, form_id, validation_result for GF).
     * @param array          $settings     The action settings.
     *
     * @return array The processed result.
     */
    protected function process_response( WP_Error | array $response, array $context_data, array $settings ): array
    {
        $result = [
            'is_spam'         => false,
            'confidence'      => 0,
            'reasoning'       => '',
            'spam_indicators' => [],
            'action_taken'    => 'none',
            'error'           => null,
        ];

        if ( is_wp_error( $response ) )
        {
            $result[ 'error' ] = $response->get_error_message();
            return $result;
        }

        $content = $response[ 'content' ]
                   ??
                   ( $response[ 'choices' ][ 0 ][ 'message' ][ 'content' ] ?? '' ); // Adjust based on actual API response structure

        if ( empty( $content ) )
        {
            $result[ 'error' ] = __( 'Empty response content from LLM.', 'sentient-forms' );
            return $result;
        }

        // Attempt to find JSON within the content, as LLMs sometimes add extra text.
        preg_match( '/\{.*?\}/s', $content, $matches );
        $json_string = $matches[ 0 ] ?? $content;

        $json_data = json_decode( $json_string, true );

        if ( json_last_error() !== JSON_ERROR_NONE || !is_array( $json_data ) )
        {
            $result[ 'error' ]       = __( 'Invalid JSON response format from LLM: ', 'sentient-forms' ) . json_last_error_msg();
            $result[ 'raw_content' ] = $content; // Store raw content for debugging
            return $result;
        }

        $result[ 'is_spam' ]         = isset( $json_data[ 'is_spam' ] ) && rest_sanitize_boolean( $json_data[ 'is_spam' ] );
        $result[ 'confidence' ]      = isset( $json_data[ 'confidence' ] ) ? floatval( $json_data[ 'confidence' ] ) : 0;
        $result[ 'reasoning' ]       = isset( $json_data[ 'reasoning' ] ) ? sanitize_textarea_field( $json_data[ 'reasoning' ] ) : '';
        $result[ 'spam_indicators' ] = isset( $json_data[ 'spam_indicators' ] ) && is_array( $json_data[ 'spam_indicators' ] ) ? array_map(
            'sanitize_text_field',
            $json_data[ 'spam_indicators' ],
        ) : [];

        $threshold = $settings[ 'spam_threshold' ] ?? 0.7;

        if ( $result[ 'is_spam' ] && $result[ 'confidence' ] >= $threshold )
        {
            // Handling for Gravity Forms validation hook
            if ( isset( $context_data[ 'validation_result' ] ) && is_array( $context_data[ 'validation_result' ] ) )
            {
                $validation_result = $context_data[ 'validation_result' ]; // This is passed by reference or needs to be returned

                if ( !empty( $settings[ 'mark_as_spam' ] ) )
                {
                    // For GF, is_spam is set on the $validation_result array directly.
                    $validation_result[ 'is_spam' ] = true; // This tells GF to mark the entry as spam.
                    $result[ 'action_taken' ]       = 'marked_as_spam';
                }

                if ( !empty( $settings[ 'reject_submission' ] ) )
                {
                    $validation_result[ 'is_valid' ] = false; // Mark the submission as invalid.
                    // Add a validation message to a field or globally
                    // For simplicity, this example adds a general message.
                    // You might want to target a specific field.
                    // Find first field to attach message to, or add a general form validation message.
                    if ( !empty( $validation_result[ 'form' ][ 'fields' ] ) )
                    {
                        // $validation_result['form']['fields'][0]->validation_message = $settings['rejection_message'];
                        // Or, add a general validation summary message if supported by the form plugin
                    }
                    // A common way for Gravity Forms is to iterate fields and set validation_message
                    // For now, we rely on a global message or the form handling is_valid = false.
                    // Gravity Forms might show a generic error if no field has a specific message.
                    // The below is a more direct way to add a validation message to the form object.
                    // This might not be directly displayed by GF without custom handling.
                    // GF usually expects validation messages on individual fields.
                    // $validation_result['form']['validation_message'] = $settings['rejection_message'];

                    // The most reliable way for GF validation message is to find a field and set its message
                    // or use a hook like gform_validation_message if you want a global message above the form.
                    // For now, setting is_valid to false is the primary action.
                    $result[ 'action_taken' ]           = 'rejected';
                    $result[ 'rejection_message_used' ] = $settings[ 'rejection_message' ];
                }
                $result[ 'validation_result' ] = $validation_result; // Store modified validation result
            }
            // Handling for after submission (e.g., gform_after_submission)
            elseif ( isset( $context_data[ 'entry_id' ] ) && function_exists( 'GFAPI' ) && class_exists( 'GFAPI' ) )
            {
                if ( !empty( $settings[ 'mark_as_spam' ] ) )
                {
                    $entry = GFAPI::get_entry( $context_data[ 'entry_id' ] );
                    if ( $entry && !is_wp_error( $entry ) )
                    {
                        GFAPI::update_entry_property( $context_data[ 'entry_id' ], 'is_spam', 1 );
                        $result[ 'action_taken' ] = 'marked_as_spam_post_submission';
                    }
                }
            }
        }

        // Store the analysis in entry meta if we have an entry ID (Gravity Forms example)
        if ( isset( $context_data[ 'entry_id' ] ) && function_exists( 'gform_add_meta' ) )
        {
            gform_add_meta( $context_data[ 'entry_id' ], '_sentient_forms_spam_analysis', $result );
        }

        return $result;
    }

    /**
     * Get the LLM capabilities required by this action.
     * Spam analysis primarily requires text generation/understanding.
     *
     * @return Sentient_Forms_Llm_Capability[] Array of required capability enums.
     */
    public function get_required_llm_capabilities(): array
    {
        if ( class_exists( 'Sentient_Forms_Llm_Capability' ) && defined( 'Sentient_Forms_Llm_Capability::TEXT_GENERATION' ) )
        {
            return [ Sentient_Forms_Llm_Capability::TEXT_GENERATION ];
        }

        return [];
    }

    public function get_settings(): array
    {
        return $this->get_default_settings_values();
    }

    public function get_compatibility(): array
    {
        return [
            'gravity_forms' => [
                'min_version' => '2.5.0',
                'hooks'       => [ 'gform_validation', 'gform_after_submission', ],
            ],
        ];
    }
}
