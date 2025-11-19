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

        // Preserve orchestration fields that are not part of the action-specific schema but are
        // required for CPS execution/async orchestration.
        $passthrough_strings = [ 'central_action_id', 'action_name_label', 'action_type_indicator', 'local_mapping_id' ];
        foreach ( $passthrough_strings as $field_key )
        {
            if ( isset( $settings[ $field_key ] ) )
            {
                $validated_settings[ $field_key ] = sanitize_text_field( (string) $settings[ $field_key ] );
            }
        }

        if ( isset( $settings['hooks'] ) && is_array( $settings['hooks'] ) )
        {
            $validated_settings['hooks'] = array_values( array_map( 'sanitize_text_field', $settings['hooks'] ) );
        }

        if ( isset( $settings['async'] ) )
        {
            $validated_settings['async'] = rest_sanitize_boolean( $settings['async'] );
        }
        if ( isset( $settings['execution_priority'] ) )
        {
            $validated_settings['execution_priority'] = (int) $settings['execution_priority'];
        }

        return $validated_settings;
    }

    /**
     * Execute the action against the CPS executor.
     */
    public function execute( array $form_data, array $settings, int | string $entry_id, int | string $form_id ): WP_Error | array | bool
    {
        $settings = $this->validate_settings( array_merge( $this->get_default_settings_values(), $settings ) );

        if ( !( $settings[ 'enabled' ] ?? true ) )
        {
            return true;
        }

        $central_action_id = $settings[ 'central_action_id' ] ?? '';
        if ( empty( $central_action_id ) )
        {
            return new WP_Error(
                'sentient_forms_missing_central_action',
                __( 'Spam Analysis is not linked to a central action. Please select one in the Sentient Forms settings.', 'sentient-forms' ),
                [ 'action_id' => $this->id ],
            );
        }

        $payload = $this->normalize_execution_payload( $form_data, $entry_id, $form_id );

        $context = array_filter(
            [
                'hook'              => $payload[ 'hook' ],
                'form_source'       => $payload[ 'form_source' ],
                'form_id'           => isset( $payload['form']['id'] ) ? (string) $payload['form']['id'] : (string) $form_id,
                'entry_id'          => isset( $payload['entry']['id'] ) ? (string) $payload['entry']['id'] : (string) $entry_id,
                'action_id'         => $this->get_id(),
                'action_name_label' => $settings[ 'action_name_label' ] ?? $central_action_id,
            ],
            static fn ( $value ) => null !== $value && '' !== $value,
        );

        $response = $this->plugin->get_action_executor()->execute(
            $central_action_id,
            $payload[ 'form' ],
            $payload[ 'entry' ],
            $context,
        );

        if ( is_wp_error( $response ) )
        {
            return $response;
        }

        return $this->process_response( $response, $payload, $settings );
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
     * Process the CPS response and apply spam decisions locally.
     */
    protected function process_response( array $response, array $payload, array $settings ): array
    {
        $result_data    = is_array( $response[ 'result_data' ] ?? null ) ? $response[ 'result_data' ] : [];
        $meta           = is_array( $response[ 'meta' ] ?? null ) ? $response[ 'meta' ] : [];
        $classification = isset( $result_data[ 'classification' ] ) ? strtolower( (string) $result_data[ 'classification' ] ) : '';
        $is_spam        = in_array( $classification, [ 'spam', 'likely_spam' ], true );

        $result = [
            'result_data'        => $result_data,
            'meta'               => $meta,
            'classification'     => $classification,
            'is_spam'            => $is_spam,
            'evaluation_payload' => $response[ 'evaluation_payload' ] ?? [
                'result_data' => $result_data,
                'meta'        => $meta,
            ],
        ];

        $entry_id = $payload[ 'entry' ][ 'id' ] ?? null;
        $form_id  = $payload[ 'form' ][ 'id' ] ?? null;

        if ( $entry_id && function_exists( 'gform_add_meta' ) )
        {
            gform_add_meta( (int) $entry_id, '_sentient_forms_spam_analysis', $result );
        }

        if ( $is_spam )
        {
            if ( !empty( $settings[ 'reject_submission' ] ) && isset( $payload[ 'validation_result' ] ) )
            {
                $validation_result                      = $payload[ 'validation_result' ];
                $validation_result[ 'is_valid' ]        = false;
                $validation_result[ 'form' ][ 'failed_validation' ] = true;
                $validation_result[ 'form' ][ 'validation_message' ] = $settings[ 'rejection_message' ]
                                                                          ??
                                                                          __( 'This submission has been identified as potential spam.', 'sentient-forms' );
                $result[ 'validation_result' ] = $validation_result;
            }

            if ( $entry_id && !empty( $settings[ 'mark_as_spam' ] ) && class_exists( 'GFAPI' ) )
            {
                GFAPI::update_entry_property( (int) $entry_id, 'is_spam', 1 );
                $result[ 'action_taken' ] = 'marked_as_spam';
            }
        }

        if ( $entry_id && $form_id && function_exists( 'gform_update_meta' ) )
        {
            gform_update_meta( (int) $entry_id, '_sentient_forms_last_action_meta', [
                'action_id'      => $this->get_id(),
                'form_id'        => $form_id,
                'ran_at'         => time(),
                'classification' => $classification,
            ] );
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
