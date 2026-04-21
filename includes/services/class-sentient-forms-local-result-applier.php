<?php
/**
 * Applies local execution results back to WordPress/form records.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Result_Applier
{
    /**
     * Apply configured local mapping effects.
     *
     * @param array<string, mixed> $mapping          Local form mapping row.
     * @param array<string, mixed> $form             Form metadata.
     * @param array<string, mixed> $entry            Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action           Local custom action row.
     *
     * @return array{applied: array<int, string>, skipped: array<int, array<string, string>>}|WP_Error
     */
    public function apply( array $mapping, array $form, array $entry, array $execution_result, array $action = [] ): array | WP_Error
    {
        $effects                = is_array( $mapping['effect_mapping_json'] ?? null ) ? $mapping['effect_mapping_json'] : [];
        $post_execution_actions = $this->get_configured_post_execution_actions( $effects, $action );
        if ( [] === $effects && [] === $post_execution_actions )
        {
            return [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'all',
                        'reason' => 'no_effect_mapping',
                    ],
                ],
            ];
        }

        if ( 'gravity_forms' !== sanitize_key( (string) ( $mapping['form_source'] ?? 'gravity_forms' ) ) )
        {
            return [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'all',
                        'reason' => 'unsupported_form_source',
                    ],
                ],
            ];
        }

        $entry_id = absint( $entry['id'] ?? 0 );
        if ( 0 === $entry_id )
        {
            return [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'all',
                        'reason' => 'missing_entry_id',
                    ],
                ],
            ];
        }

        $applied = [];
        $skipped = [];
        $result  = is_array( $execution_result['result'] ?? null ) ? $execution_result['result'] : [];

        if ( $this->bool_effect( $effects, [ 'store_result', 'store_result_meta' ] ) )
        {
            if ( function_exists( 'gform_update_meta' ) )
            {
                $stored_execution_result = Sentient_Forms_Local_Data_Governance::sanitize_execution_payload_for_storage( $execution_result );
                $stored_result           = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $result );
                gform_update_meta( $entry_id, 'sentient_forms_last_response', wp_json_encode( $stored_execution_result ) );
                gform_update_meta( $entry_id, '_sentient_forms_local_result', $stored_result );
                $applied[] = 'store_result';
            }
            else
            {
                $skipped[] = [
                    'effect' => 'store_result',
                    'reason' => 'gform_update_meta_unavailable',
                ];
            }
        }

        $meta_effects = is_array( $effects['meta'] ?? null ) ? $effects['meta'] : [];
        foreach ( $meta_effects as $meta_key => $path_config )
        {
            $meta_key = sanitize_key( (string) $meta_key );
            if ( '' === $meta_key )
            {
                $skipped[] = [
                    'effect' => 'meta',
                    'reason' => 'invalid_meta_key',
                ];
                continue;
            }

            $path  = is_array( $path_config ) ? (string) ( $path_config['path'] ?? '' ) : (string) $path_config;
            $value = $this->resolve_path( $result, $path );
            if ( null === $value )
            {
                $skipped[] = [
                    'effect' => 'meta:' . $meta_key,
                    'reason' => 'path_not_found',
                ];
                continue;
            }

            if ( function_exists( 'gform_update_meta' ) )
            {
                gform_update_meta( $entry_id, $meta_key, $value );
                $applied[] = 'meta:' . $meta_key;
            }
            else
            {
                $skipped[] = [
                    'effect' => 'meta:' . $meta_key,
                    'reason' => 'gform_update_meta_unavailable',
                ];
            }
        }

        if ( array_key_exists( 'entry_note', $effects ) )
        {
            $note_result = $this->apply_entry_note( $entry_id, $effects['entry_note'], $result );
            if ( true === $note_result )
            {
                $applied[] = 'entry_note';
            }
            else
            {
                $skipped[] = [
                    'effect' => 'entry_note',
                    'reason' => $note_result,
                ];
            }
        }

        if ( $this->spam_effect_enabled( $effects ) )
        {
            $spam_result = $this->apply_spam_status( $entry_id, $effects, $result );
            if ( true === $spam_result )
            {
                $applied[] = 'mark_as_spam';
            }
            else
            {
                $skipped[] = [
                    'effect' => 'mark_as_spam',
                    'reason' => $spam_result,
                ];
            }
        }

        if ( [] !== $post_execution_actions )
        {
            $post_execution_results = $this->run_post_execution_actions(
                $entry_id,
                $mapping,
                $form,
                $entry,
                $execution_result,
                $action,
                $post_execution_actions
            );
            $this->record_post_execution_action_results( $entry_id, $post_execution_results );

            foreach ( $post_execution_results as $post_execution_result )
            {
                $effect_name = 'post_execution:' . sanitize_key( (string) ( $post_execution_result['type'] ?? 'unknown' ) );
                if ( 'success' === (string) ( $post_execution_result['status'] ?? '' ) )
                {
                    $applied[] = $effect_name;
                    continue;
                }

                $skipped[] = [
                    'effect' => $effect_name,
                    'reason' => sanitize_key( (string) ( $post_execution_result['status'] ?? 'failed' ) ),
                ];
            }
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    private function apply_entry_note( int $entry_id, mixed $config, array $result ): true | string
    {
        if ( false === rest_sanitize_boolean( $config ) && ! is_array( $config ) )
        {
            return 'disabled';
        }

        if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'add_note' ) )
        {
            return 'gf_notes_unavailable';
        }

        $path = is_array( $config ) ? (string) ( $config['path'] ?? $config['content_path'] ?? 'content' ) : 'content';
        $body = $this->resolve_path( $result, $path );
        if ( null === $body )
        {
            return 'path_not_found';
        }

        $body = $this->stringify_value( $body );
        if ( '' === trim( $body ) )
        {
            return 'empty_note';
        }

        $prefix = is_array( $config ) && isset( $config['prefix'] )
            ? sanitize_text_field( (string) $config['prefix'] )
            : __( 'Sentient Forms local action result:', 'sentient-forms' );
        $note = sanitize_textarea_field( $prefix . "\n\n" . $body );

        GFFormsModel::add_note( $entry_id, 0, 'Sentient Forms AI', $note, 'sentient_forms_local_action' );

        return true;
    }

    private function apply_spam_status( int $entry_id, array $effects, array $result ): true | string
    {
        $config              = is_array( $effects['spam'] ?? null ) ? $effects['spam'] : [];
        $classification_path = (string) ( $config['classification_path'] ?? 'structured.classification' );
        $confidence_path     = (string) ( $config['confidence_path'] ?? 'structured.confidence' );
        $threshold           = is_numeric( $config['min_confidence'] ?? null ) ? (float) $config['min_confidence'] : 0.8;
        $classification      = strtolower( sanitize_key( (string) $this->resolve_path( $result, $classification_path ) ) );

        if ( '' === $classification )
        {
            $is_spam = $this->resolve_path( $result, 'structured.is_spam' );
            if ( true === $is_spam || 'true' === strtolower( (string) $is_spam ) )
            {
                $classification = 'spam';
            }
        }

        if ( ! in_array( $classification, [ 'spam', 'likely_spam' ], true ) )
        {
            return 'classification_not_spam';
        }

        $confidence = $this->resolve_path( $result, $confidence_path );
        if ( is_numeric( $confidence ) && (float) $confidence < $threshold )
        {
            return 'confidence_below_threshold';
        }

        $filter_result = apply_filters( 'sentient_forms_local_mark_entry_as_spam', null, $entry_id, $result );
        if ( true === $filter_result )
        {
            if ( function_exists( 'gform_update_meta' ) )
            {
                gform_update_meta( $entry_id, 'sentient_forms_spam_classification', $classification );
            }

            return true;
        }

        if ( false === $filter_result )
        {
            return 'spam_mark_rejected_by_filter';
        }

        if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'update_entry_property' ) )
        {
            return 'gfapi_unavailable';
        }

        GFAPI::update_entry_property( $entry_id, 'status', 'spam' );

        if ( function_exists( 'gform_update_meta' ) )
        {
            gform_update_meta( $entry_id, 'sentient_forms_spam_classification', $classification );
        }

        return true;
    }

    private function spam_effect_enabled( array $effects ): bool
    {
        if ( isset( $effects['spam'] ) )
        {
            if ( is_array( $effects['spam'] ) )
            {
                return ! array_key_exists( 'enabled', $effects['spam'] ) || rest_sanitize_boolean( $effects['spam']['enabled'] );
            }

            return rest_sanitize_boolean( $effects['spam'] );
        }

        return ! empty( $effects['mark_as_spam'] );
    }

    /**
     * @param array<string, mixed> $effects Local effect mapping JSON.
     * @param array<string, mixed> $action  Local custom action row.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_configured_post_execution_actions( array $effects, array $action ): array
    {
        $definition = isset( $action['definition_json'] ) && is_array( $action['definition_json'] )
            ? $action['definition_json']
            : [];
        $defaults = isset( $definition['execution_defaults'] ) && is_array( $definition['execution_defaults'] )
            ? $definition['execution_defaults']
            : [];

        $candidates = [
            $effects['post_execution_actions'] ?? null,
            $effects['custom_effects'] ?? null,
            $effects['effects'] ?? null,
            $defaults['post_execution_actions'] ?? null,
        ];

        foreach ( $candidates as $candidate )
        {
            $actions = $this->normalize_post_execution_actions( $candidate );
            if ( [] !== $actions )
            {
                return $actions;
            }
        }

        return [];
    }

    /**
     * @param mixed $candidate Effect action config or list.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalize_post_execution_actions( mixed $candidate ): array
    {
        if ( ! is_array( $candidate ) || [] === $candidate )
        {
            return [];
        }

        if ( isset( $candidate['type'] ) || isset( $candidate['kind'] ) )
        {
            $candidate = [ $candidate ];
        }

        $actions = [];
        foreach ( $candidate as $action )
        {
            if ( ! is_array( $action ) )
            {
                continue;
            }

            if ( isset( $action['enabled'] ) && false === rest_sanitize_boolean( $action['enabled'] ) )
            {
                continue;
            }

            if ( ! isset( $action['type'] ) && isset( $action['kind'] ) )
            {
                $action['type'] = $action['kind'];
            }

            if ( isset( $action['type'] ) && is_scalar( $action['type'] ) )
            {
                $actions[] = $action;
            }
        }

        return array_values( $actions );
    }

    /**
     * @param array<string, mixed>               $mapping Local form mapping row.
     * @param array<string, mixed>               $form Form metadata.
     * @param array<string, mixed>               $entry Form entry values.
     * @param array<string, mixed>               $execution_result Normalized execution result.
     * @param array<string, mixed>               $action Local custom action row.
     * @param array<int, array<string, mixed>>   $actions Post-execution actions.
     *
     * @return array<int, array<string, mixed>>
     */
    private function run_post_execution_actions(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $actions
    ): array
    {
        $results = [];
        foreach ( $actions as $index => $post_execution_action )
        {
            $type = isset( $post_execution_action['type'] ) && is_scalar( $post_execution_action['type'] )
                ? sanitize_key( (string) $post_execution_action['type'] )
                : 'unknown';

            try
            {
                $results[] = $this->run_post_execution_action(
                    $entry_id,
                    $mapping,
                    $form,
                    $entry,
                    $execution_result,
                    $action,
                    $post_execution_action,
                    $index
                );
            }
            catch ( Throwable $throwable )
            {
                $results[] = [
                    'index'   => $index,
                    'type'    => $type,
                    'status'  => 'failed',
                    'message' => $throwable->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index
    ): array
    {
        $type = isset( $post_execution_action['type'] ) && is_scalar( $post_execution_action['type'] )
            ? sanitize_key( (string) $post_execution_action['type'] )
            : '';

        if ( '' === $type )
        {
            return [
                'index'   => $index,
                'type'    => 'unknown',
                'status'  => 'failed',
                'message' => __( 'Missing post-execution action type.', 'sentient-forms' ),
            ];
        }

        return match ( $type )
        {
            'entry_note' => $this->run_post_execution_entry_note_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            'send_email' => $this->run_post_execution_email_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            'wp_hook' => $this->run_post_execution_hook_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            'webhook' => $this->run_post_execution_webhook_action( $entry_id, $mapping, $form, $entry, $execution_result, $action, $post_execution_action, $index, $type ),
            default => [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => sprintf(
                    /* translators: %s is the unsupported local post-execution action type. */
                    __( 'Unsupported post-execution action type: %s', 'sentient-forms' ),
                    $type
                ),
            ],
        };
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_entry_note_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        if ( ! class_exists( 'GFFormsModel' ) || ! method_exists( 'GFFormsModel', 'add_note' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Gravity Forms notes are unavailable.', 'sentient-forms' ),
            ];
        }

        $message = isset( $post_execution_action['message'] ) && is_scalar( $post_execution_action['message'] )
            ? (string) $post_execution_action['message']
            : (string) ( $post_execution_action['template'] ?? __( 'Sentient Forms completed {{action_label}}. Result: {{llm_output}}', 'sentient-forms' ) );
        $note = $this->render_post_execution_template( $message, $mapping, $form, $entry, $execution_result, $action );

        if ( '' === trim( $note ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Entry note template rendered empty.', 'sentient-forms' ),
            ];
        }

        GFFormsModel::add_note(
            $entry_id,
            0,
            'Sentient Forms AI',
            sanitize_textarea_field( $note ),
            'sentient_forms_local_post_execution'
        );

        return [
            'index'   => $index,
            'type'    => $type,
            'status'  => 'success',
            'message' => $note,
        ];
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_email_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        if ( ! function_exists( 'wp_mail' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'WordPress mail is unavailable.', 'sentient-forms' ),
            ];
        }

        $raw_recipients = $post_execution_action['to'] ?? $post_execution_action['recipients'] ?? '';
        if ( is_string( $raw_recipients ) )
        {
            $raw_recipients = array_filter( array_map( 'trim', explode( ',', $raw_recipients ) ) );
        }
        elseif ( ! is_array( $raw_recipients ) )
        {
            $raw_recipients = [];
        }

        if ( [] === $raw_recipients )
        {
            $raw_recipients[] = get_option( 'admin_email' );
        }

        $recipients = [];
        foreach ( $raw_recipients as $recipient )
        {
            if ( ! is_scalar( $recipient ) )
            {
                continue;
            }

            $email = sanitize_email(
                $this->render_post_execution_template( (string) $recipient, $mapping, $form, $entry, $execution_result, $action )
            );
            if ( is_email( $email ) )
            {
                $recipients[] = $email;
            }
        }

        if ( [] === $recipients )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'No valid email recipients were configured.', 'sentient-forms' ),
            ];
        }

        $subject_template = isset( $post_execution_action['subject'] ) && is_scalar( $post_execution_action['subject'] )
            ? (string) $post_execution_action['subject']
            : __( 'Sentient Forms completed {{action_label}}', 'sentient-forms' );
        $body_template = isset( $post_execution_action['body'] ) && is_scalar( $post_execution_action['body'] )
            ? (string) $post_execution_action['body']
            : (string) ( $post_execution_action['message'] ?? "{{llm_output}}\n\n{{justification}}" );

        $sent = wp_mail(
            $recipients,
            $this->render_post_execution_template( $subject_template, $mapping, $form, $entry, $execution_result, $action ),
            $this->render_post_execution_template( $body_template, $mapping, $form, $entry, $execution_result, $action ),
            [ 'Content-Type: text/plain; charset=UTF-8' ]
        );

        return [
            'index'      => $index,
            'type'       => $type,
            'status'     => $sent ? 'success' : 'failed',
            'recipients' => $recipients,
        ];
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_hook_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        $hook_template = isset( $post_execution_action['hook_name'] ) && is_scalar( $post_execution_action['hook_name'] )
            ? (string) $post_execution_action['hook_name']
            : '';
        $hook_name = preg_replace(
            '/[^A-Za-z0-9_.-]/',
            '',
            $this->render_post_execution_template( $hook_template, $mapping, $form, $entry, $execution_result, $action )
        );

        if ( '' === $hook_name )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Missing WordPress hook name.', 'sentient-forms' ),
            ];
        }

        $context = $this->build_post_execution_context( $mapping, $form, $entry, $execution_result, $action );

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook name is an admin-configured local result effect and is sanitized before dispatch.
        do_action( $hook_name, $context, $execution_result, $post_execution_action, $entry_id );

        return [
            'index'     => $index,
            'type'      => $type,
            'status'    => 'success',
            'hook_name' => $hook_name,
        ];
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     * @param array<string, mixed> $post_execution_action Effect configuration.
     *
     * @return array<string, mixed>
     */
    private function run_post_execution_webhook_action(
        int $entry_id,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action,
        array $post_execution_action,
        int $index,
        string $type
    ): array
    {
        if ( ! function_exists( 'wp_remote_request' ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'WordPress HTTP API is unavailable.', 'sentient-forms' ),
            ];
        }

        $url_template = isset( $post_execution_action['url'] ) && is_scalar( $post_execution_action['url'] )
            ? (string) $post_execution_action['url']
            : '';
        $url = esc_url_raw( $this->render_post_execution_template( $url_template, $mapping, $form, $entry, $execution_result, $action ) );
        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => __( 'Webhook URL must use http or https.', 'sentient-forms' ),
            ];
        }

        $method = isset( $post_execution_action['method'] ) && is_scalar( $post_execution_action['method'] )
            ? strtoupper( sanitize_key( (string) $post_execution_action['method'] ) )
            : 'POST';
        $headers = $this->sanitize_webhook_headers( $post_execution_action['headers'] ?? [] );
        $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';

        $response = wp_remote_request(
            $url,
            [
                'method'  => in_array( $method, [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ? $method : 'POST',
                'timeout' => 5,
                'headers' => $headers,
                'body'    => wp_json_encode(
                    [
                        'entry_id' => $entry_id,
                        'context'  => $this->build_post_execution_context( $mapping, $form, $entry, $execution_result, $action ),
                        'result'   => $execution_result,
                        'action'   => $post_execution_action,
                    ]
                ),
            ]
        );

        if ( is_wp_error( $response ) )
        {
            return [
                'index'   => $index,
                'type'    => $type,
                'status'  => 'failed',
                'message' => $response->get_error_message(),
            ];
        }

        $status_code = function_exists( 'wp_remote_retrieve_response_code' )
            ? (int) wp_remote_retrieve_response_code( $response )
            : 0;

        return [
            'index'       => $index,
            'type'        => $type,
            'status'      => $status_code >= 200 && $status_code < 400 ? 'success' : 'failed',
            'status_code' => $status_code,
        ];
    }

    /**
     * @param mixed $headers Raw webhook headers.
     *
     * @return array<string, string>
     */
    private function sanitize_webhook_headers( mixed $headers ): array
    {
        if ( ! is_array( $headers ) )
        {
            return [];
        }

        $sanitized = [];
        foreach ( $headers as $key => $value )
        {
            if ( ! is_scalar( $key ) || ! is_scalar( $value ) )
            {
                continue;
            }

            $name = sanitize_text_field( (string) $key );
            if ( '' === $name )
            {
                continue;
            }

            $sanitized[ $name ] = sanitize_text_field( (string) $value );
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     *
     * @return array<string, mixed>
     */
    private function build_post_execution_context( array $mapping, array $form, array $entry, array $execution_result, array $action ): array
    {
        return [
            'form_source'          => $mapping['form_source'] ?? 'gravity_forms',
            'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? null ),
            'local_form_mapping_id'=> isset( $mapping['id'] ) ? (int) $mapping['id'] : null,
            'action_kind'          => $mapping['action_kind'] ?? 'custom_action',
            'action_id'            => isset( $mapping['action_id'] ) ? (int) $mapping['action_id'] : null,
            'action_label'         => $this->get_action_label( $action ),
            'provider'             => $execution_result['provider'] ?? null,
            'model'                => $execution_result['model'] ?? null,
            'execution_request_id' => $execution_result['execution_request_id'] ?? null,
            'form'                 => $form,
            'entry'                => $entry,
        ];
    }

    /**
     * @param array<string, mixed> $mapping Local form mapping row.
     * @param array<string, mixed> $form Form metadata.
     * @param array<string, mixed> $entry Form entry values.
     * @param array<string, mixed> $execution_result Normalized execution result.
     * @param array<string, mixed> $action Local custom action row.
     */
    private function render_post_execution_template(
        string $template,
        array $mapping,
        array $form,
        array $entry,
        array $execution_result,
        array $action
    ): string
    {
        $result = is_array( $execution_result['result'] ?? null ) ? $execution_result['result'] : [];

        return (string) preg_replace_callback(
            '/{{\s*([A-Za-z0-9_.:-]+)\s*}}/',
            function ( array $matches ) use ( $mapping, $form, $entry, $execution_result, $action, $result ): string
            {
                $key = strtolower( (string) $matches[1] );

                if ( str_starts_with( $key, 'field:' ) )
                {
                    $field_id = substr( $key, strlen( 'field:' ) );
                    return $this->stringify_value( $entry[ $field_id ] ?? '' );
                }

                $structured = is_array( $result['structured'] ?? null ) ? $result['structured'] : [];
                $values     = [
                    'entry_id'             => $entry['id'] ?? '',
                    'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? '' ),
                    'form_title'           => $form['title'] ?? '',
                    'local_form_mapping_id'=> $mapping['id'] ?? '',
                    'action_label'         => $this->get_action_label( $action ),
                    'provider'             => $execution_result['provider'] ?? '',
                    'model'                => $execution_result['model'] ?? '',
                    'execution_request_id' => $execution_result['execution_request_id'] ?? '',
                    'llm_output'           => $result['content'] ?? '',
                    'content'              => $result['content'] ?? '',
                    'classification'       => $structured['classification'] ?? $result['classification'] ?? '',
                    'confidence'           => $structured['confidence'] ?? $result['confidence'] ?? '',
                    'summary'              => $structured['summary'] ?? $result['summary'] ?? '',
                    'justification'        => $structured['justification'] ?? $result['justification'] ?? '',
                    'structured_output'    => wp_json_encode( $structured ),
                    'result_json'          => wp_json_encode( $result ),
                ];

                if ( array_key_exists( $key, $values ) )
                {
                    return $this->stringify_value( $values[ $key ] );
                }

                foreach ( [ $result, $execution_result, $mapping, $form, $entry ] as $source )
                {
                    if ( ! is_array( $source ) )
                    {
                        continue;
                    }

                    $value = $this->resolve_path( $source, $key );
                    if ( null !== $value )
                    {
                        return $this->stringify_value( $value );
                    }
                }

                return '';
            },
            $template
        );
    }

    /**
     * @param array<string, mixed> $action Local custom action row.
     */
    private function get_action_label( array $action ): string
    {
        if ( isset( $action['display_name'] ) && is_scalar( $action['display_name'] ) )
        {
            return sanitize_text_field( (string) $action['display_name'] );
        }

        if ( isset( $action['code'] ) && is_scalar( $action['code'] ) )
        {
            return sanitize_text_field( (string) $action['code'] );
        }

        return __( 'Local OpenRouter action', 'sentient-forms' );
    }

    /**
     * Persist post-execution effect audit entries on the Gravity Forms entry.
     *
     * @param array<int, array<string, mixed>> $results Effect results.
     */
    private function record_post_execution_action_results( int $entry_id, array $results ): void
    {
        if ( [] === $results || ! function_exists( 'gform_update_meta' ) )
        {
            return;
        }

        $existing = function_exists( 'gform_get_meta' )
            ? gform_get_meta( $entry_id, 'sentient_forms_post_execution_actions' )
            : null;
        if ( is_string( $existing ) )
        {
            $decoded  = json_decode( $existing, true );
            $existing = is_array( $decoded ) ? $decoded : [];
        }

        if ( ! is_array( $existing ) )
        {
            $existing = [];
        }

        $existing[] = [
            'ran_at'  => current_time( 'mysql' ),
            'results' => $results,
        ];

        gform_update_meta( $entry_id, 'sentient_forms_post_execution_actions', wp_json_encode( $existing ) );
    }

    /**
     * @param array<int, string> $keys
     */
    private function bool_effect( array $effects, array $keys ): bool
    {
        foreach ( $keys as $key )
        {
            if ( array_key_exists( $key, $effects ) && rest_sanitize_boolean( $effects[ $key ] ) )
            {
                return true;
            }
        }

        return false;
    }

    private function resolve_path( array $root, string $path ): mixed
    {
        $path = trim( $path );
        if ( '' === $path )
        {
            return $root;
        }

        $value = $root;
        foreach ( explode( '.', $path ) as $segment )
        {
            if ( is_array( $value ) && array_key_exists( $segment, $value ) )
            {
                $value = $value[ $segment ];
                continue;
            }

            return null;
        }

        return $value;
    }

    private function stringify_value( mixed $value ): string
    {
        if ( null === $value )
        {
            return '';
        }

        if ( is_scalar( $value ) )
        {
            return (string) $value;
        }

        $encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        return is_string( $encoded ) ? $encoded : '';
    }
}
