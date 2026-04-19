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

        $passthrough_strings = [ 'central_action_id', 'action_name_label', 'action_type_indicator', 'local_mapping_id' ];
        foreach ( $passthrough_strings as $field_key ) {
            if ( isset( $settings[ $field_key ] ) ) {
                $validated_settings[ $field_key ] = sanitize_text_field( (string) $settings[ $field_key ] );
            }
        }

        if ( isset( $settings['hooks'] ) && is_array( $settings['hooks'] ) ) {
            $validated_settings['hooks'] = array_values( array_map( 'sanitize_text_field', $settings['hooks'] ) );
        }

        if ( isset( $settings['execution_priority'] ) ) {
            $validated_settings['execution_priority'] = (int) $settings['execution_priority'];
        }

        return $validated_settings;
    }


    /**

     * Execute the action using the CPS executor.

     */

    public function execute( array $form_data, array $settings, $entry_id, $form_id ): WP_Error | array | bool

    {
        $raw_mapping_settings = isset( $settings['settings'] ) && is_array( $settings['settings'] )
            ? $settings['settings']
            : [];

        $settings = $this->validate_settings( array_merge( $this->get_default_settings_values(), $settings ) );



        if ( ! ( $settings['enabled'] ?? true ) ) {

            return true;

        }



        $central_action_id = $settings['central_action_id'] ?? '';

        if ( empty( $central_action_id ) ) {

            return new WP_Error(

                'sentient_forms_missing_central_action',

                __( 'Entry Evaluation is not linked to a central action. Please select one in the Sentient Forms settings.', 'sentient-forms' ),

                [ 'action_id' => $this->id ],

            );

        }



        $payload = $this->normalize_execution_payload( $form_data, $entry_id, $form_id );



        $context = array_filter( 
            [
                'hook'              => $payload['hook'],
                'form_source'       => $payload['form_source'],
                'form_id'           => isset( $payload['form']['id'] ) ? (string) $payload['form']['id'] : (string) $form_id,
                'entry_id'          => isset( $payload['entry']['id'] ) ? (string) $payload['entry']['id'] : (string) $entry_id,
                'action_id'         => $this->get_id(),
                'action_name_label' => $settings['action_name_label'] ?? $central_action_id,
                'action_type_indicator' => $settings['action_type_indicator'] ?? null,
                'local_mapping_id'       => $settings['local_mapping_id'] ?? null,
                'settings'               => $raw_mapping_settings,
            ],
            static fn( $value ) => null !== $value && '' !== $value,
        );


        $response = $this->plugin->get_action_executor()->execute(

            $central_action_id,

            $payload['form'],

            $payload['entry'],

            $context,

        );



        if ( is_wp_error( $response ) ) {

            return $response;

        }



        return $this->process_response( $response, $payload, $settings );

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

     * Process the CPS response.

     *

     * @param array $response CPS executor payload.

     * @param array $payload  Normalized adapter payload.

     * @param array $settings Action settings.

     * @return array The processed result.

     */

    protected function process_response( array $response, array $payload, array $settings ): array {

        $result = [

            'summary'            => '',

            'key_points'         => [],

            'sentiment'          => '',

            'suggested_tags'     => [],

            'suggested_response' => '',

            'action_taken'       => 'none',

            'error'              => null,

        ];



        // CA-EXEC-001: Prefer structured_output when CPS validates it against the output_contract.
        $structured_output       = $response['result_data']['structured_output'] ?? null;
        $structured_output_valid = ! empty( $response['result_data']['structured_output_valid'] );
        $output_schema_version   = $response['result_data']['output_schema_version'] ?? null;

        if ( $structured_output_valid && is_array( $structured_output ) ) {
            $json_data = $structured_output;

            if ( null !== $output_schema_version ) {
                $result['output_schema_version'] = (int) $output_schema_version;
            }
        } else {
            // Legacy path: extract JSON from llm_output via regex.
            $content = $response['result_data']['llm_output'] ?? '';

            if ( empty( $content ) ) {

                $result['error'] = __( 'Empty response content from CPS.', 'sentient-forms' );

                return $result;

            }



            preg_match('/\{.*?\}/s', $content, $matches);

            $json_string = $matches[0] ?? $content;



            $json_data = json_decode( $json_string, true );



            if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $json_data ) ) {

                $result['error'] = __( 'Invalid JSON response format from CPS: ', 'sentient-forms' ) . json_last_error_msg();

                $result['raw_content'] = $content;

                return $result;

            }
        }



        $result['summary']            = isset( $json_data['summary'] ) ? sanitize_textarea_field( $json_data['summary'] ) : '';

        $result['sentiment']          = isset( $json_data['sentiment'] ) ? sanitize_text_field( $json_data['sentiment'] ) : '';

        $result['suggested_response'] = isset( $json_data['suggested_response'] ) ? sanitize_textarea_field( $json_data['suggested_response'] ) : '';

        $result['key_points']         = isset( $json_data['key_points'] ) && is_array( $json_data['key_points'] ) ? array_map( 'sanitize_text_field', $json_data['key_points'] ) : [];

        $result['suggested_tags']     = isset( $json_data['suggested_tags'] ) && is_array( $json_data['suggested_tags'] ) ? array_map( 'sanitize_text_field', $json_data['suggested_tags'] ) : [];



        $entry_id = $payload['entry']['id'] ?? null;

        $context_data = [

            'entry_id' => $entry_id,

            'form_id'  => $payload['form']['id'] ?? null,

            'form_data'=> $payload['entry'] ?? [],

        ];



        if ( $entry_id ) {

            $actions_taken = [];

            if ( ! empty( $settings['add_note'] ) && class_exists('GFFormsModel') && method_exists('GFFormsModel', 'add_note') ) {

                $note = $this->format_note_from_result( $result );

                GFFormsModel::add_note( $entry_id, 0, 'Sentient Forms AI', $note );

                $actions_taken[] = 'added_note';

            }



            if ( function_exists( 'gform_add_meta' ) ) {

                gform_add_meta( (int) $entry_id, '_sentient_forms_entry_evaluation', $result );

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

        $subject    = sprintf(
            /* translators: 1: site name, 2: form title. */
            __( '[%1$s] AI Evaluation for Submission to "%2$s"', 'sentient-forms' ),
            get_bloginfo( 'name' ),
            $form_title
        );

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
