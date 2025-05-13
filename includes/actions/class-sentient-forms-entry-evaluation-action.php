<?php
/**
 * Entry Evaluation Action
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Sentient_Forms_Entry_Evaluation_Action
 * Evaluates and summarizes form entries
 */
class Sentient_Forms_Entry_Evaluation_Action extends Sentient_Forms_Abstract_Action {

    /**
     * Initialize action properties.
     */
    protected function init(): void {
        $this->id          = 'entry_evaluation';
        $this->name        = __( 'Entry Evaluation', 'sentient-forms' );
        $this->description = __( 'Analyzes and summarizes form submissions using AI.', 'sentient-forms' );

        // Define action-specific settings fields.
        $this->settings_fields = [
            'evaluation_type' => [
                'type'    => 'select',
                'label'   => __( 'Evaluation Type', 'sentient-forms' ),
                'options' => [
                    'summary'  => __( 'Summary (Brief overview)', 'sentient-forms' ),
                    'detailed' => __( 'Detailed (In-depth analysis)', 'sentient-forms' ),
                    'custom'   => __( 'Custom (Use custom prompt)', 'sentient-forms' ),
                ],
                'default' => 'summary',
            ],
            'prompt_template'   => [
                'type'        => 'textarea',
                'label'       => __( 'Custom Prompt Template', 'sentient-forms' ),
                'default'     => $this->get_default_prompt_template(),
                'description' => __( 'Used if "Evaluation Type" is set to "Custom". You can use form field merge tags like {field_id} or {all_fields}.', 'sentient-forms' ),
            ],
            'async'             => [
                'type'        => 'checkbox',
                'label'       => __( 'Process Asynchronously', 'sentient-forms' ),
                'default'     => true,
                'description' => __( 'Process the evaluation in the background to avoid delaying form submission.', 'sentient-forms' ),
            ],
            'add_note'          => [
                'type'        => 'checkbox',
                'label'       => __( 'Add as Entry Note (Gravity Forms)', 'sentient-forms' ),
                'default'     => true,
                'description' => __( 'Add the evaluation as a note to the entry in Gravity Forms.', 'sentient-forms' ),
            ],
            'notify_admin'      => [
                'type'        => 'checkbox',
                'label'       => __( 'Notify Admin', 'sentient-forms' ),
                'default'     => false,
                'description' => __( 'Send an email notification with the evaluation to the admin.', 'sentient-forms' ),
            ],
            'admin_email'       => [
                'type'        => 'text',
                'label'       => __( 'Admin Email for Notifications', 'sentient-forms' ),
                'default'     => get_option( 'admin_email' ),
                'description' => __( 'Email address to send notifications to if "Notify Admin" is checked.', 'sentient-forms' ),
            ],
        ];
    }

    /**
     * Get the action icon.
     *
     * @return string
     */
    public function get_icon(): string {
        return 'dashicons-analytics';
    }

    /**
     * Get the action hooks.
     *
     * @return array
     */
    public function get_hooks(): array {
        return [
            'gform_after_submission' => __( 'After Submission (Gravity Forms)', 'sentient-forms' ),
            // Add other hooks like 'wpforms_process_complete' if applicable
        ];
    }

    /**
     * Get default prompt template for this action.
     *
     * @return string
     */
    protected function get_default_prompt_template(): string {
        return __(
            "You are an AI assistant tasked with analyzing form submissions. Your goal is to provide a concise summary and evaluation of the submission.\n\nForm Name: {form_title}\nSubmission Data:\n{all_fields}\n\nPlease analyze this submission and respond with a JSON object containing:\n1. 'summary': A brief summary of the submission (max 2-3 sentences)\n2. 'key_points': An array of the most important points from the submission (max 5)\n3. 'sentiment': An assessment of the overall sentiment (positive, negative, or neutral)\n4. 'suggested_tags': An array of 3-5 tags that would be appropriate for categorizing this submission\n5. 'suggested_response': If this submission requires a response, provide a brief suggested response\n\nRespond ONLY with the JSON object and nothing else.",
            'sentient-forms'
        );
    }

    /**
     * Validate the action settings.
     *
     * @param array $settings The settings to validate.
     * @return array The validated settings.
     */
    public function validate_settings( array $settings ): array {
        $validated_settings = parent::validate_settings( $settings );

        // Validate evaluation type
        $evaluation_types = [ 'summary', 'detailed', 'custom' ];
        if ( isset( $settings['evaluation_type'] ) && ! in_array( $settings['evaluation_type'], $evaluation_types, true ) ) {
            $validated_settings['evaluation_type'] = $this->settings_fields['evaluation_type']['default'] ?? 'summary';
        } elseif (isset($settings['evaluation_type'])) {
            $validated_settings['evaluation_type'] = $settings['evaluation_type'];
        } else {
            $validated_settings['evaluation_type'] = $this->settings_fields['evaluation_type']['default'] ?? 'summary';
        }


        // Ensure boolean values
        $boolean_fields = [ 'async', 'add_note', 'notify_admin' ];
        foreach ( $boolean_fields as $field_key ) {
            if ( isset( $settings[ $field_key ] ) ) {
                $validated_settings[ $field_key ] = rest_sanitize_boolean( $settings[ $field_key ] );
            } else {
                $validated_settings[ $field_key ] = $this->settings_fields[ $field_key ]['default'] ?? false;
            }
        }
        // Specific defaults if not set
        if (!isset($settings['async'])) {
            $validated_settings['async'] = $this->settings_fields['async']['default'] ?? true;
        }
        if (!isset($settings['add_note'])) {
            $validated_settings['add_note'] = $this->settings_fields['add_note']['default'] ?? true;
        }
        if (!isset($settings['notify_admin'])) {
            $validated_settings['notify_admin'] = $this->settings_fields['notify_admin']['default'] ?? false;
        }


        // Validate admin email
        if ( isset( $settings['admin_email'] ) ) {
            $admin_email = sanitize_email( $settings['admin_email'] );
            if ( is_email( $admin_email ) ) {
                $validated_settings['admin_email'] = $admin_email;
            } else {
                $validated_settings['admin_email'] = $this->settings_fields['admin_email']['default'] ?? get_option( 'admin_email' );
            }
        } else {
            $validated_settings['admin_email'] = $this->settings_fields['admin_email']['default'] ?? get_option( 'admin_email' );
        }

        // Validate prompt template
        if ( isset( $settings['prompt_template'] ) ) {
            $validated_settings['prompt_template'] = sanitize_textarea_field( $settings['prompt_template'] );
        } else {
            $validated_settings['prompt_template'] = $this->get_default_prompt_template();
        }

        return $validated_settings;
    }

    /**
     * Execute the action.
     *
     * @param array $form_data Form submission data.
     * @param array $settings Action settings.
     * @param int|string $entry_id The ID of the form entry.
     * @param int|string $form_id The ID of the form.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function execute( array $form_data, array $settings, $entry_id, $form_id ): WP_Error | bool
    {
        $settings = $this->validate_settings( array_merge( $this->get_default_settings_values(), $settings ) );

        if ( ! ( $settings['enabled'] ?? true ) ) {
            return true; // Action is disabled
        }

        $llm_id = $settings['llm'] ?? null;
        if ( empty( $llm_id ) ) {
            $global_options = get_option( 'sentient_forms_options', [] );
            $llm_id = $global_options['default_llm'] ?? SENTIENT_FORMS_DEFAULT_FREE_LLM_ID;
        }

        $api_client = $this->plugin->get_api_client();
        if ( ! $api_client || empty($this->plugin->get_proxy_api_key()) ) {
            error_log( 'Sentient Forms (Entry Evaluation): API client not available or API key not set.' );
            return new WP_Error( 'api_client_unavailable', __( 'API client is not available or API key not set.', 'sentient-forms' ) );
        }

        $prompt = $settings['prompt_template'];
        // If evaluation type is not custom, potentially override prompt with a predefined one based on 'evaluation_type'.
        // For this example, we assume 'custom' uses the template directly, others might be handled by API or different templates.
        // This is a simplified placeholder for merge tag replacement.
        $prompt = str_replace( '{form_title}', "Form ID: " . $form_id, $prompt ); // Placeholder
        $prompt = str_replace( '{all_fields}', print_r( $form_data, true ), $prompt ); // Placeholder

        $api_args = [
            'prompt' => $prompt,
        ];

        // Handle asynchronous processing
        if ( $settings['async'] ?? true ) {
            $async_handler = $this->plugin->async; // Assuming $this->plugin->async is Sentient_Forms_Async_Handler
            if ( $async_handler && method_exists($async_handler, 'dispatch_evaluation') ) {
                $async_handler->dispatch_evaluation( $llm_id, $api_args, $settings, $entry_id, $form_id, get_class($this) );
                return true; // Dispatched asynchronously
            } else {
                // Log error or fall back to synchronous if async handler is not available
                error_log('Sentient Forms (Entry Evaluation): Async handler not available, processing synchronously.');
            }
        }

        // Synchronous processing
        $response = $api_client->make_request( $llm_id, $api_args );
        $processed_result = $this->process_response( $response, [ 'entry_id' => $entry_id, 'form_id' => $form_id, 'form_data' => $form_data ], $settings );

        if ( is_wp_error( $response ) ) {
            error_log( 'Sentient Forms (Entry Evaluation) API Error for entry ' . $entry_id . ': ' . $response->get_error_message() );
            return $response;
        }

        // error_log('Sentient Forms (Entry Evaluation) executed for entry ' . $entry_id . '. Result: ' . print_r($processed_result, true));
        return true;
    }

    /**
     * Get default values for all settings fields defined for this action.
     *
     * @return array
     */
    private function get_default_settings_values(): array {
        $defaults = [];
        foreach ( $this->settings_fields as $key => $field ) {
            if ( isset( $field['default'] ) ) {
                $defaults[ $key ] = $field['default'];
            }
        }
        return $defaults;
    }

    /**
     * Process the LLM API response.
     * This might be called synchronously or by the async handler.
     *
     * @param array|WP_Error $response The API response.
     * @param array $context_data Contextual data (entry_id, form_id, form_data).
     * @param array $settings The action settings.
     * @return array The processed result.
     */
    public function process_response( $response, array $context_data, array $settings ): array {
        $result = [
            'summary'            => '',
            'key_points'         => [],
            'sentiment'          => '',
            'suggested_tags'     => [],
            'suggested_response' => '',
            'action_taken'       => 'none',
            'error'              => null,
        ];

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $content = $response['content'] ?? ( $response['choices'][0]['message']['content'] ?? '' );

        if ( empty( $content ) ) {
            $result['error'] = __( 'Empty response content from LLM.', 'sentient-forms' );
            return $result;
        }

        preg_match('/\{.*?\}/s', $content, $matches);
        $json_string = $matches[0] ?? $content;

        $json_data = json_decode( $json_string, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $json_data ) ) {
            $result['error'] = __( 'Invalid JSON response format from LLM: ', 'sentient-forms' ) . json_last_error_msg();
            $result['raw_content'] = $content;
            return $result;
        }

        $result['summary']            = isset( $json_data['summary'] ) ? sanitize_textarea_field( $json_data['summary'] ) : '';
        $result['sentiment']          = isset( $json_data['sentiment'] ) ? sanitize_text_field( $json_data['sentiment'] ) : '';
        $result['suggested_response'] = isset( $json_data['suggested_response'] ) ? sanitize_textarea_field( $json_data['suggested_response'] ) : '';
        $result['key_points']         = isset( $json_data['key_points'] ) && is_array( $json_data['key_points'] ) ? array_map( 'sanitize_text_field', $json_data['key_points'] ) : [];
        $result['suggested_tags']     = isset( $json_data['suggested_tags'] ) && is_array( $json_data['suggested_tags'] ) ? array_map( 'sanitize_text_field', $json_data['suggested_tags'] ) : [];

        $entry_id = $context_data['entry_id'] ?? null;

        if ( $entry_id ) {
            $actions_taken = [];
            if ( ! empty( $settings['add_note'] ) && class_exists('GFFormsModel') && method_exists('GFFormsModel', 'add_note') ) { // Check for GF specific function
                $note = $this->format_note_from_result( $result );
                GFFormsModel::add_note( $entry_id, 0, 'Sentient Forms AI', $note ); // User ID 0 for system
                $actions_taken[] = 'added_note';
            }

            // Store the full evaluation in entry meta (Gravity Forms example)
            if ( function_exists( 'gform_add_meta' ) ) {
                gform_add_meta( $entry_id, '_sentient_forms_entry_evaluation', $result );
            }

            if ( ! empty( $settings['notify_admin'] ) ) {
                $this->send_notification( $result, $context_data, $settings );
                $actions_taken[] = 'sent_notification';
            }
            $result['action_taken'] = empty($actions_taken) ? 'none' : implode('_and_', $actions_taken);
        }
        return $result;
    }

    /**
     * Format note from result for Gravity Forms.
     *
     * @param array $result The evaluation result.
     * @return string The formatted note.
     */
    private function format_note_from_result( array $result ): string {
        $note_parts = [];
        $note_parts[] = '<strong>' . __( 'AI Entry Evaluation:', 'sentient-forms' ) . "</strong>\n";

        if ( ! empty( $result['summary'] ) ) {
            $note_parts[] = '<strong>' . __( 'Summary:', 'sentient-forms' ) . '</strong> ' . esc_html( $result['summary'] );
        }
        if ( ! empty( $result['key_points'] ) ) {
            $note_parts[] = "<strong>" . __( 'Key Points:', 'sentient-forms' ) . "</strong>\n" . '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $result['key_points'] ) ) . '</li></ul>';
        }
        if ( ! empty( $result['sentiment'] ) ) {
            $note_parts[] = '<strong>' . __( 'Sentiment:', 'sentient-forms' ) . '</strong> ' . esc_html( $result['sentiment'] );
        }
        if ( ! empty( $result['suggested_tags'] ) ) {
            $note_parts[] = '<strong>' . __( 'Suggested Tags:', 'sentient-forms' ) . '</strong> ' . esc_html( implode( ', ', $result['suggested_tags'] ) );
        }
        if ( ! empty( $result['suggested_response'] ) ) {
            $note_parts[] = "<strong>" . __( 'Suggested Response:', 'sentient-forms' ) . "</strong>\n" . nl2br( esc_html( $result['suggested_response'] ) );
        }
        if ( ! empty( $result['error'] ) ) {
            $note_parts[] = '<strong>' . __( 'Error during evaluation:', 'sentient-forms' ) . '</strong> ' . esc_html( $result['error'] );
        }

        return implode( "\n\n", $note_parts );
    }

    /**
     * Send notification email.
     *
     * @param array $result       The evaluation result.
     * @param array $context_data Contextual data (form_data, entry_id, form_id).
     * @param array $settings     The action settings.
     * @return bool Whether the email was sent.
     */
    private function send_notification( array $result, array $context_data, array $settings ): bool {
        $to = $settings['admin_email'] ?? get_option( 'admin_email' );
        if ( empty( $to ) || ! is_email( $to ) ) {
            return false;
        }

        $form_title = $context_data['form_data']['form_title'] ?? (isset($context_data['form_id']) ? 'Form ID ' . $context_data['form_id'] : 'N/A');
        $subject    = sprintf( __( '[%1$s] AI Evaluation for Submission to "%2$s"', 'sentient-forms' ), get_bloginfo( 'name' ), $form_title );
        $body       = $this->format_note_from_result( $result );

        // Add link to entry if available (Gravity Forms example)
        if ( isset( $context_data['entry_id'], $context_data['form_id'] ) && class_exists('GFCommon') ) {
            $entry_url = admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $context_data['form_id'] . '&lid=' . $context_data['entry_id'] );
            $body     .= "\n\n" . __( 'View Entry:', 'sentient-forms' ) . ' ' . esc_url( $entry_url );
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ]; // Use HTML for email if note is HTML
        return wp_mail( $to, $subject, wpautop($body), $headers ); // wpautop for better HTML formatting
    }

    /**
     * Get the LLM capabilities required by this action.
     *
     * @return Sentient_Forms_Llm_Capability[] Array of required capability enums.
     */
    public function get_required_llm_capabilities(): array {
        if ( class_exists('Sentient_Forms_Llm_Capability') && defined('Sentient_Forms_Llm_Capability::TEXT_GENERATION')) {
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
                'hooks'      => [ 'gform_after_submission' ],
            ],
        ];
    }
}
