<?php
/**
 * Local-first action execution service.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Local_Action_Execution_Service
{
    public function __construct(
        private ?Sentient_Forms_Form_Mappings_Repository $mappings = null,
        private ?Sentient_Forms_Local_Custom_Actions_Repository $custom_actions = null,
        private ?Sentient_Forms_Provider_Credentials_Repository $credentials = null,
        private ?Sentient_Forms_External_Service_Consent_Repository $consents = null,
        private ?Sentient_Forms_Execution_Events_Repository $events = null,
        private ?Sentient_Forms_Provider_Credential_Vault $vault = null,
        private ?Sentient_Forms_Provider_Client_Interface $openrouter = null,
        private ?Sentient_Forms_Local_Prompt_Renderer $renderer = null,
        private ?Sentient_Forms_Local_Result_Applier $result_applier = null,
        private ?Sentient_Forms_Action_Templates_Repository $templates = null,
        private ?Sentient_Forms_Managed_Proxy_Client $managed_proxy = null,
        private ?Sentient_Forms_Local_Action_Model_Selection_Service $model_selection_service = null
    )
    {
        global $wpdb;

        $this->mappings       = $this->mappings ?? new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->custom_actions = $this->custom_actions ?? new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $this->credentials    = $this->credentials ?? new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->consents       = $this->consents ?? new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $this->events         = $this->events ?? new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $this->vault          = $this->vault ?? new Sentient_Forms_Provider_Credential_Vault();
        $this->openrouter     = $this->openrouter ?? new Sentient_Forms_OpenRouter_Direct_Client();
        $this->renderer       = $this->renderer ?? new Sentient_Forms_Local_Prompt_Renderer();
        $this->result_applier = $this->result_applier ?? new Sentient_Forms_Local_Result_Applier();
        $this->templates      = $this->templates ?? new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $this->managed_proxy  = $this->managed_proxy ?? new Sentient_Forms_Managed_Proxy_Client();
        $this->model_selection_service = $this->model_selection_service ?? new Sentient_Forms_Local_Action_Model_Selection_Service(
            $this->custom_actions,
            $this->credentials,
            $this->mappings
        );
    }

    /**
     * Execute a saved local form mapping.
     *
     * @param int                  $mapping_id Local mapping ID.
     * @param array<string, mixed> $form       Form metadata.
     * @param array<string, mixed> $entry      Form entry values.
     * @param array<string, mixed> $context    Runtime context.
     *
     * @return array<string, mixed>|WP_Error
     */
    public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
    {
        $mapping = $this->mappings->get( $mapping_id );
        if ( null === $mapping )
        {
            return new WP_Error(
                'sentient_forms_local_mapping_not_found',
                __( 'Local form mapping could not be found.', 'sentient-forms' )
            );
        }

        if ( empty( $mapping['enabled'] ) )
        {
            return new WP_Error(
                'sentient_forms_local_mapping_disabled',
                __( 'Local form mapping is disabled.', 'sentient-forms' )
            );
        }

        if ( 'custom_action' !== (string) ( $mapping['action_kind'] ?? '' ) )
        {
            return new WP_Error(
                'sentient_forms_local_action_kind_unsupported',
                __( 'Only local custom actions are supported by the local execution service.', 'sentient-forms' )
            );
        }

        $action = $this->custom_actions->get( (int) ( $mapping['action_id'] ?? 0 ) );
        if ( null === $action )
        {
            return new WP_Error(
                'sentient_forms_local_action_not_found',
                __( 'Local action could not be found.', 'sentient-forms' )
            );
        }

        if ( 'active' !== (string) ( $action['status'] ?? '' ) )
        {
            return new WP_Error(
                'sentient_forms_local_action_inactive',
                __( 'Local action is not active.', 'sentient-forms' )
            );
        }

        $action  = $this->model_selection_service->prepare_bundled_action_for_execution( $action );
        $mapping = $this->model_selection_service->prepare_mapping_for_action( $mapping, $action );

        $definition                 = is_array( $action['definition_json'] ?? null ) ? $action['definition_json'] : [];
        $structured_output_contract = $this->resolve_structured_output_contract( $action, $definition );
        if ( is_wp_error( $structured_output_contract ) )
        {
            return $structured_output_contract;
        }

        $original_model_selection = is_array( $action['model_selection_json'] ?? null ) ? $action['model_selection_json'] : [];
        $base_model_selection     = $this->model_selection_service->prepare_model_selection_for_action( $action );
        if ( $base_model_selection !== $original_model_selection )
        {
            $updated_action = $this->custom_actions->update(
                (int) $action['id'],
                [
                    'model_selection_json' => $base_model_selection,
                ]
            );

            if ( ! is_wp_error( $updated_action ) )
            {
                $action = $updated_action;
            }
        }

        $action['model_selection_json'] = $base_model_selection;
        $model_selection                = $this->model_selection_service->prepare_model_selection_for_execution( $action, $context );
        $provider        = sanitize_key( (string) ( $model_selection['provider'] ?? $definition['provider'] ?? 'openrouter' ) );
        $model           = sanitize_text_field( (string) ( $model_selection['model'] ?? $definition['model'] ?? 'openrouter/auto' ) );

        if ( ! in_array( $provider, [ 'openrouter', 'sentient_managed' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_provider_not_supported_locally',
                __( 'This provider is not supported by local execution yet.', 'sentient-forms' )
            );
        }

        $consent = $this->assert_external_service_consent( $provider );
        if ( is_wp_error( $consent ) )
        {
            return $consent;
        }

        $credential_id = (int) ( $model_selection['credential_id'] ?? $definition['credential_id'] ?? $context['credential_id'] ?? 0 );
        $credential    = $this->model_selection_service->resolve_execution_credential( $provider, $credential_id );
        if ( is_wp_error( $credential ) )
        {
            return $credential;
        }

        $api_key = null;
        if ( 'openrouter' === $provider )
        {
            $api_key = $this->resolve_api_key( $credential );
            if ( is_wp_error( $api_key ) )
            {
                return $api_key;
            }
        }

        $messages = $this->build_messages( $action, $definition, $mapping, $form, $entry, $context );
        if ( is_wp_error( $messages ) )
        {
            return $messages;
        }

        $managed_context = null;
        $payload         = $this->build_provider_payload( $model, $messages, $definition, $model_selection );
        $execution_request_id = $this->resolve_execution_request_id( $mapping, $action, $form, $entry, $context );

        if ( 'sentient_managed' === $provider )
        {
            $managed_context = $this->resolve_managed_proxy_context( $credential );
            if ( is_wp_error( $managed_context ) )
            {
                return $managed_context;
            }

            $payload = $this->build_managed_payload(
                $model,
                $messages,
                $definition,
                $model_selection,
                $mapping,
                $action,
                $form,
                $entry,
                $context,
                $managed_context['site_id'],
                $execution_request_id,
                $structured_output_contract
            );
        }

        $payload_digest       = hash( 'sha256', (string) wp_json_encode( $payload ) );
        $existing             = $this->events->get_by_request_id( $execution_request_id );

        if (
            is_array( $existing )
            && 'succeeded' === (string) ( $existing['status'] ?? '' )
            && $payload_digest === (string) ( $existing['payload_digest'] ?? '' )
        )
        {
            $cached_result = is_array( $existing['result_json'] ?? null ) ? $existing['result_json'] : [];

            return [
                'execution_request_id' => $execution_request_id,
                'status'               => 'succeeded',
                'provider'             => $provider,
                'model'                => $model,
                'cached'               => true,
                'result'               => $cached_result,
                'effects'              => is_array( $cached_result['effects'] ?? null ) ? $cached_result['effects'] : [],
            ];
        }

        $this->events->record(
            [
                'execution_request_id' => $execution_request_id,
                'mapping_id'           => (int) $mapping['id'],
                'form_source'          => $mapping['form_source'] ?? 'gravity_forms',
                'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? null ),
                'entry_id'             => $entry['id'] ?? null,
                'provider'             => $provider,
                'model'                => $model,
                'status'               => 'running',
                'payload_digest'       => $payload_digest,
            ]
        );

        if ( 'sentient_managed' === $provider )
        {
            $response              = $this->managed_proxy->execute( $managed_context['proxy_api_key'], $payload );
            $secret_for_redaction  = $managed_context['proxy_api_key'];
        }
        else
        {
            $response             = $this->openrouter->chat_completion( $api_key, $payload );
            $secret_for_redaction = $api_key;
        }

        if ( is_wp_error( $response ) )
        {
            $redacted_message = $this->redact_secret( $response->get_error_message(), $secret_for_redaction );
            $this->update_credential_status_after_error( (int) $credential['id'], $response, $redacted_message );
            $this->events->record(
                [
                    'execution_request_id' => $execution_request_id,
                    'mapping_id'           => (int) $mapping['id'],
                    'form_source'          => $mapping['form_source'] ?? 'gravity_forms',
                    'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? null ),
                    'entry_id'             => $entry['id'] ?? null,
                    'provider'             => $provider,
                    'model'                => $model,
                    'status'               => 'failed',
                    'error_code'           => $response->get_error_code(),
                    'error_message'        => $redacted_message,
                    'payload_digest'       => $payload_digest,
                ]
            );

            return new WP_Error( $response->get_error_code(), $redacted_message, $response->get_error_data() );
        }

        $result = 'sentient_managed' === $provider
            ? $this->normalize_managed_response( $response )
            : $this->normalize_openrouter_response( $response );
        $result = $this->validate_structured_output( $result, $structured_output_contract );
        if ( is_wp_error( $result ) )
        {
            $this->events->record(
                [
                    'execution_request_id' => $execution_request_id,
                    'mapping_id'           => (int) $mapping['id'],
                    'form_source'          => $mapping['form_source'] ?? 'gravity_forms',
                    'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? null ),
                    'entry_id'             => $entry['id'] ?? null,
                    'provider'             => $provider,
                    'model'                => $model,
                    'status'               => 'failed',
                    'token_usage_json'     => is_array( $response['usage'] ?? null ) ? $response['usage'] : null,
                    'cost_json'            => $this->extract_openrouter_usage_cost( is_array( $response['usage'] ?? null ) ? $response['usage'] : [] ),
                    'error_code'           => $result->get_error_code(),
                    'error_message'        => $result->get_error_message(),
                    'payload_digest'       => $payload_digest,
                ]
            );

            return $result;
        }

        $execution_result = [
            'execution_request_id' => $execution_request_id,
            'status'               => 'succeeded',
            'provider'             => $provider,
            'model'                => $model,
            'cached'               => false,
            'result'               => $result,
        ];
        $effects = $this->result_applier->apply( $mapping, $form, $entry, $execution_result, $action );
        if ( is_wp_error( $effects ) )
        {
            $effects = [
                'applied' => [],
                'skipped' => [
                    [
                        'effect' => 'result_application',
                        'reason' => $effects->get_error_code(),
                    ],
                ],
            ];
        }

        $result['effects'] = $effects;
        $stored_result     = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage( $result );
        $this->events->record(
            [
                'execution_request_id' => $execution_request_id,
                'mapping_id'           => (int) $mapping['id'],
                'form_source'          => $mapping['form_source'] ?? 'gravity_forms',
                'form_id'              => $mapping['form_id'] ?? ( $form['id'] ?? null ),
                'entry_id'             => $entry['id'] ?? null,
                'provider'             => $provider,
                'model'                => $model,
                'status'               => 'succeeded',
                'token_usage_json'     => $result['usage'] ?? null,
                'cost_json'            => $result['cost'] ?? null,
                'result_json'          => $stored_result,
                'payload_digest'       => $payload_digest,
            ]
        );

        return [
            'execution_request_id' => $execution_request_id,
            'status'               => 'succeeded',
            'provider'             => $provider,
            'model'                => $model,
            'cached'               => false,
            'result'               => $result,
            'effects'              => $effects,
        ];
    }

    private function assert_external_service_consent( string $provider ): true | WP_Error
    {
        $latest = $this->consents->latest_for_provider( $provider );
        if ( is_array( $latest ) )
        {
            return true;
        }

        return new WP_Error(
            'sentient_forms_external_service_consent_required',
            __( 'External-service disclosure acceptance is required before local provider execution.', 'sentient-forms' )
        );
    }

    private function resolve_api_key( array $credential ): string | WP_Error
    {
        $auth_mode = sanitize_key( (string) ( $credential['auth_mode'] ?? '' ) );

        if ( 'constant' === $auth_mode )
        {
            return $this->resolve_constant_secret( (string) ( $credential['constant_name'] ?? '' ) );
        }

        if ( ! in_array( $auth_mode, [ 'manual_key', 'oauth_broker' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_provider_auth_mode_unsupported',
                __( 'Provider credential authentication mode is not supported for local execution.', 'sentient-forms' )
            );
        }

        $encrypted = (string) ( $credential['encrypted_secret'] ?? '' );
        if ( '' === $encrypted )
        {
            return new WP_Error(
                'sentient_forms_provider_secret_missing',
                __( 'Provider credential does not contain a stored secret.', 'sentient-forms' )
            );
        }

        return $this->vault->decrypt( $encrypted );
    }

    private function resolve_constant_secret( string $constant_name ): string | WP_Error
    {
        return Sentient_Forms_Provider_Secret_Resolver::resolve_constant_secret( $constant_name );
    }

    /**
     * @return array{proxy_api_key: string, site_id: string}|WP_Error
     */
    private function resolve_managed_proxy_context( array $credential ): array | WP_Error
    {
        $auth_mode = sanitize_key( (string) ( $credential['auth_mode'] ?? '' ) );
        if ( 'sentient_proxy' !== $auth_mode )
        {
            return new WP_Error(
                'sentient_forms_sentient_managed_auth_mode_unsupported',
                __( 'Sentient Forms managed execution requires a managed-service credential.', 'sentient-forms' )
            );
        }

        if ( ! class_exists( 'Sentient_Forms_Plugin' ) )
        {
            return new WP_Error(
                'sentient_forms_sentient_managed_plugin_unavailable',
                __( 'Sentient Forms managed execution could not read the site account state.', 'sentient-forms' )
            );
        }

        $plugin         = Sentient_Forms_Plugin::instance();
        $license        = $plugin->get_license_data();
        $license_status = sanitize_key( (string) ( $license['license_status'] ?? '' ) );
        if ( ! in_array( $license_status, [ 'active', 'trial', 'valid' ], true ) )
        {
            return new WP_Error(
                'sentient_forms_sentient_managed_account_inactive',
                __( 'Sentient Forms managed execution requires an active managed-service account.', 'sentient-forms' )
            );
        }

        $proxy_api_key = trim( (string) ( $license['proxy_api_key'] ?? $plugin->get_proxy_api_key() ) );
        if ( '' === $proxy_api_key )
        {
            return new WP_Error(
                'sentient_forms_sentient_managed_proxy_key_missing',
                __( 'Sentient Forms managed execution requires a site credential key.', 'sentient-forms' )
            );
        }

        $site_id = sanitize_text_field( (string) ( $license['site_id'] ?? '' ) );
        if ( '' === trim( $site_id ) )
        {
            return new WP_Error(
                'sentient_forms_sentient_managed_site_id_missing',
                __( 'Sentient Forms managed execution requires a managed site ID.', 'sentient-forms' )
            );
        }

        return [
            'proxy_api_key' => $proxy_api_key,
            'site_id'       => $site_id,
        ];
    }

    private function build_messages( array $action, array $definition, array $mapping, array $form, array $entry, array $context ): array | WP_Error
    {
        $variables = $this->renderer->build_variables(
            is_array( $mapping['input_bindings_json'] ?? null ) ? $mapping['input_bindings_json'] : [],
            $form,
            $entry,
            $context
        );

        if ( is_wp_error( $variables ) )
        {
            return $variables;
        }

        if ( isset( $definition['messages'] ) && is_array( $definition['messages'] ) )
        {
            $messages = $this->renderer->render_messages( $definition['messages'], $variables );
            return is_wp_error( $messages ) ? $messages : $this->inject_site_context_message( $messages, $mapping, $context );
        }

        $prompt_template = $this->resolve_prompt_template( $action, $definition );
        if ( '' === trim( $prompt_template ) )
        {
            return new WP_Error(
                'sentient_forms_missing_prompt_template',
                __( 'Local action definition must include a prompt template.', 'sentient-forms' )
            );
        }

        $messages = [];
        if ( isset( $definition['system_prompt'] ) && is_scalar( $definition['system_prompt'] ) && '' !== trim( (string) $definition['system_prompt'] ) )
        {
            $messages[] = [
                'role'    => 'system',
                'content' => (string) $definition['system_prompt'],
            ];
        }

        $messages[] = [
            'role'    => 'user',
            'content' => $prompt_template,
        ];

        $rendered = $this->renderer->render_messages( $messages, $variables );
        return is_wp_error( $rendered ) ? $rendered : $this->inject_site_context_message( $rendered, $mapping, $context );
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed>                            $mapping
     * @param array<string, mixed>                            $context
     * @return array<int, array{role: string, content: string}>
     */
    private function inject_site_context_message( array $messages, array $mapping, array $context ): array
    {
        $site_context = $this->resolve_site_context_for_mapping( $mapping, $context );
        if ( '' === $site_context )
        {
            return $messages;
        }

        $context_message = [
            'role'    => 'system',
            'content' => "Site context for this WordPress site:\n" . $site_context,
        ];

        foreach ( $messages as $index => $message )
        {
            if ( 'system' === sanitize_key( (string) ( $message['role'] ?? '' ) ) )
            {
                $messages[ $index ]['content'] = trim( (string) $message['content'] . "\n\n" . $context_message['content'] );
                return $messages;
            }
        }

        array_unshift( $messages, $context_message );
        return $messages;
    }

    /**
     * @param array<string, mixed> $mapping
     * @param array<string, mixed> $context
     */
    private function resolve_site_context_for_mapping( array $mapping, array $context ): string
    {
        $settings = is_array( $context['settings'] ?? null )
            ? $context['settings']
            : ( is_array( $mapping['settings'] ?? null ) ? $mapping['settings'] : [] );
        $mode     = sanitize_key( (string) ( $settings['include_site_context'] ?? 'global' ) );
        if ( 'never' === $mode )
        {
            return '';
        }

        $context = get_option( 'sentient_forms_site_context', null );
        if ( ! is_array( $context ) )
        {
            return '';
        }

        if ( empty( $context['pii_ack'] ) )
        {
            return '';
        }

        if ( 'always' !== $mode && empty( $context['auto_include'] ) )
        {
            return '';
        }

        $summary = isset( $context['summary_text'] ) && is_scalar( $context['summary_text'] )
            ? trim( sanitize_textarea_field( (string) $context['summary_text'] ) )
            : '';

        return mb_substr( $summary, 0, 5000 );
    }

    private function resolve_prompt_template( array $action, array $definition ): string
    {
        foreach ( [ 'prompt_template', 'prompt' ] as $definition_key )
        {
            if ( isset( $definition[ $definition_key ] ) && is_scalar( $definition[ $definition_key ] ) && '' !== trim( (string) $definition[ $definition_key ] ) )
            {
                return (string) $definition[ $definition_key ];
            }
        }

        $template_id = (int) ( $action['template_id'] ?? 0 );
        if ( $template_id <= 0 )
        {
            return '';
        }

        $template = $this->templates->get( $template_id );
        if ( ! is_array( $template ) || ! isset( $template['prompt_template'] ) || ! is_scalar( $template['prompt_template'] ) )
        {
            return '';
        }

        return (string) $template['prompt_template'];
    }

    private function build_provider_payload( string $model, array $messages, array $definition, array $model_selection ): array
    {
        $payload = [
            'model'    => $model,
            'messages' => $messages,
        ];

        foreach ( [ 'temperature', 'top_p' ] as $float_field )
        {
            $value = $model_selection[ $float_field ] ?? $definition[ $float_field ] ?? null;
            if ( is_numeric( $value ) )
            {
                $payload[ $float_field ] = (float) $value;
            }
        }

        foreach ( [ 'max_tokens', 'seed' ] as $int_field )
        {
            $value = $model_selection[ $int_field ] ?? $definition[ $int_field ] ?? null;
            if ( is_numeric( $value ) )
            {
                $payload[ $int_field ] = (int) $value;
            }
        }

        if ( isset( $definition['response_format'] ) && is_array( $definition['response_format'] ) )
        {
            $payload['response_format'] = $definition['response_format'];
        }

        $reasoning = $this->normalize_reasoning_payload( $model_selection['reasoning'] ?? null );
        if ( null !== $reasoning )
        {
            $payload['reasoning'] = $reasoning;
        }

        return $payload;
    }

    /**
     * @param array<int, array{role?: string, content?: mixed}>                 $messages
     * @param array{schema: array<string, mixed>, source: string}|null|WP_Error $structured_output_contract
     *
     * @return array<string, mixed>
     */
    private function build_managed_payload(
        string $model,
        array $messages,
        array $definition,
        array $model_selection,
        array $mapping,
        array $action,
        array $form,
        array $entry,
        array $context,
        string $site_id,
        string $execution_request_id,
        array | WP_Error | null $structured_output_contract
    ): array
    {
        $payload = [
            'site_id'              => $site_id,
            'execution_request_id' => $execution_request_id,
            'provider'             => 'sentient_managed',
            'model'                => $model,
            'prompt'               => $this->messages_to_managed_prompt( $messages ),
            'metadata'             => [
                'mapping_id'  => (int) ( $mapping['id'] ?? 0 ),
                'action_id'   => (int) ( $action['id'] ?? 0 ),
                'action_code' => sanitize_text_field( (string) ( $action['code'] ?? '' ) ),
                'form_source' => sanitize_key( (string) ( $mapping['form_source'] ?? 'gravity_forms' ) ),
                'form_id'     => isset( $form['id'] ) && is_scalar( $form['id'] ) ? sanitize_text_field( (string) $form['id'] ) : null,
                'entry_id'    => isset( $entry['id'] ) && is_scalar( $entry['id'] ) ? sanitize_text_field( (string) $entry['id'] ) : null,
                'hook'        => isset( $context['hook'] ) && is_scalar( $context['hook'] ) ? sanitize_key( (string) $context['hook'] ) : null,
            ],
        ];

        if ( '' !== $payload['metadata']['action_code'] )
        {
            $payload['action_code'] = $payload['metadata']['action_code'];
        }

        foreach ( [ 'temperature' ] as $float_field )
        {
            $value = $model_selection[ $float_field ] ?? $definition[ $float_field ] ?? null;
            if ( is_numeric( $value ) )
            {
                $payload[ $float_field ] = (float) $value;
            }
        }

        $max_output_tokens = $model_selection['max_output_tokens'] ?? $definition['max_output_tokens'] ?? $model_selection['max_tokens'] ?? $definition['max_tokens'] ?? null;
        if ( is_numeric( $max_output_tokens ) )
        {
            $payload['max_output_tokens'] = (int) $max_output_tokens;
        }

        if ( is_array( $structured_output_contract ) )
        {
            $payload['output_contract'] = [
                'schema' => $structured_output_contract['schema'],
                'source' => $structured_output_contract['source'],
            ];
        }

        return $payload;
    }

    /**
     * @param array<int, array{role?: string, content?: mixed}> $messages
     */
    private function messages_to_managed_prompt( array $messages ): string
    {
        $parts = [];
        foreach ( $messages as $message )
        {
            $role    = isset( $message['role'] ) && is_scalar( $message['role'] ) ? sanitize_key( (string) $message['role'] ) : 'user';
            $content = isset( $message['content'] ) && is_scalar( $message['content'] ) ? trim( (string) $message['content'] ) : '';
            if ( '' === $content )
            {
                continue;
            }

            $parts[] = strtoupper( $role ) . ":\n" . $content;
        }

        return trim( implode( "\n\n", $parts ) );
    }

    private function resolve_execution_request_id( array $mapping, array $action, array $form, array $entry, array $context ): string
    {
        if ( isset( $context['execution_request_id'] ) && is_scalar( $context['execution_request_id'] ) )
        {
            $request_id = sanitize_text_field( (string) $context['execution_request_id'] );
            if ( '' !== $request_id )
            {
                return $request_id;
            }
        }

        $action_code = (string) ( $action['code'] ?? 'local_action' );
        $action_id   = sprintf( 'local:%d:%s', (int) ( $mapping['id'] ?? 0 ), $action_code );

        if ( class_exists( 'Sentient_Forms_Action_Executor' ) )
        {
            return Sentient_Forms_Action_Executor::generate_execution_request_id(
                $action_id,
                $form,
                $entry,
                array_merge(
                    $context,
                    [
                        'mapping_id' => (string) ( $mapping['id'] ?? 0 ),
                        'action_id'  => $action_code,
                    ]
                )
            );
        }

        return substr( hash( 'sha256', (string) wp_json_encode( [ $action_id, $form, $entry, $context ] ) ), 0, 32 );
    }

    private function normalize_openrouter_response( array $response ): array
    {
        $choice        = is_array( $response['choices'][0] ?? null ) ? $response['choices'][0] : [];
        $message       = is_array( $choice['message'] ?? null ) ? $choice['message'] : [];
        $content       = is_scalar( $message['content'] ?? null ) ? (string) $message['content'] : '';
        $finish_reason = is_scalar( $choice['finish_reason'] ?? null ) ? (string) $choice['finish_reason'] : null;
        $structured    = $this->decode_structured_content( $content );

        $result = [
            'provider_response_id' => is_scalar( $response['id'] ?? null ) ? (string) $response['id'] : null,
            'model'                => is_scalar( $response['model'] ?? null ) ? (string) $response['model'] : null,
            'content'              => $content,
            'finish_reason'        => $finish_reason,
            'usage'                => is_array( $response['usage'] ?? null ) ? $response['usage'] : null,
        ];

        $cost = $this->extract_openrouter_usage_cost( is_array( $response['usage'] ?? null ) ? $response['usage'] : [] );
        if ( null !== $cost )
        {
            $result['cost'] = $cost;
        }

        if ( null !== $structured )
        {
            $result['structured'] = $structured;
        }

        return $result;
    }

    /**
     * @return array{effort: string}|null
     */
    private function normalize_reasoning_payload( mixed $value ): ?array
    {
        $effort = sanitize_key( (string) $value );
        if ( ! in_array( $effort, [ 'none', 'minimal', 'low', 'medium', 'high', 'xhigh' ], true ) )
        {
            return null;
        }

        return [ 'effort' => $effort ];
    }

    private function normalize_managed_response( array $response ): array
    {
        $output        = is_array( $response['output'] ?? null ) ? $response['output'] : [];
        $content       = is_scalar( $output['text'] ?? null ) ? (string) $output['text'] : '';
        $structured    = $this->decode_structured_content( $content );

        $result = [
            'provider_response_id' => is_scalar( $response['execution_request_id'] ?? null ) ? (string) $response['execution_request_id'] : null,
            'model'                => is_scalar( $response['model'] ?? null ) ? (string) $response['model'] : null,
            'content'              => $content,
            'finish_reason'        => null,
            'usage'                => is_array( $response['token_usage'] ?? null ) ? $response['token_usage'] : null,
            'metering'             => is_array( $response['metering'] ?? null ) ? $response['metering'] : null,
        ];

        if ( is_array( $response['metering'] ?? null ) )
        {
            $result['cost'] = $this->extract_managed_metering_cost( $response['metering'] );
        }

        if ( null !== $structured )
        {
            $result['structured'] = $structured;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $usage
     * @return array<string, mixed>|null
     */
    private function extract_openrouter_usage_cost( array $usage ): ?array
    {
        $amount = $this->numeric_provider_cost( $usage['cost'] ?? null );
        if ( null === $amount && ! isset( $usage['cost_details'] ) && ! isset( $usage['server_tool_use'] ) )
        {
            return null;
        }

        $cost = [
            'provider' => 'openrouter',
            'currency' => 'USD',
            'source'   => null === $amount ? 'openrouter_usage_details' : 'openrouter_usage_cost',
        ];

        if ( null !== $amount )
        {
            $cost['amount_usd'] = $amount;
            $cost['free']       = 0.0 === $amount;
        }

        if ( is_array( $usage['cost_details'] ?? null ) )
        {
            $cost['cost_details'] = $this->sanitize_scalar_map( $usage['cost_details'] );
        }

        if ( is_array( $usage['server_tool_use'] ?? null ) )
        {
            $cost['server_tool_use'] = $this->sanitize_scalar_map( $usage['server_tool_use'] );
        }

        return $cost;
    }

    /**
     * @param array<string, mixed> $metering
     * @return array<string, mixed>
     */
    private function extract_managed_metering_cost( array $metering ): array
    {
        $cost = [
            'provider' => 'sentient_forms',
            'currency' => 'USD',
            'source'   => 'sentient_forms_metering',
        ];

        if ( isset( $metering['debited_credits'] ) && is_numeric( $metering['debited_credits'] ) )
        {
            $cost['debited_credits'] = absint( $metering['debited_credits'] );
        }

        if ( isset( $metering['billed_amount_microusd'] ) && is_numeric( $metering['billed_amount_microusd'] ) )
        {
            $cost['billed_amount_microusd'] = absint( $metering['billed_amount_microusd'] );
        }

        if ( isset( $metering['free_usage'] ) )
        {
            $cost['free'] = rest_sanitize_boolean( $metering['free_usage'] );
        }

        return $cost;
    }

    private function numeric_provider_cost( mixed $value ): ?float
    {
        if ( ! is_numeric( $value ) )
        {
            return null;
        }

        $amount = (float) $value;
        return $amount >= 0 ? $amount : null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function sanitize_scalar_map( array $values ): array
    {
        $sanitized = [];
        foreach ( $values as $key => $value )
        {
            if ( is_scalar( $value ) || null === $value )
            {
                $sanitized[ sanitize_key( (string) $key ) ] = null === $value ? null : sanitize_text_field( (string) $value );
            }
        }

        return $sanitized;
    }

    /**
     * Resolve the schema contract that provider JSON output must satisfy.
     *
     * @param array<string, mixed> $action     Local custom action row.
     * @param array<string, mixed> $definition Decoded action definition.
     *
     * @return array{schema: array<string, mixed>, source: string}|null|WP_Error
     */
    private function resolve_structured_output_contract( array $action, array $definition ): array | WP_Error | null
    {
        foreach ( [ 'structured_output_schema', 'output_schema' ] as $definition_key )
        {
            if ( array_key_exists( $definition_key, $definition ) && null !== $definition[ $definition_key ] )
            {
                if ( ! is_array( $definition[ $definition_key ] ) )
                {
                    return $this->invalid_structured_output_schema_error( 'custom_action' );
                }

                $schema = $this->normalize_structured_output_schema( $definition[ $definition_key ], 'custom_action' );
                if ( is_wp_error( $schema ) )
                {
                    return $schema;
                }

                return [
                    'schema' => $schema,
                    'source' => 'custom_action',
                ];
            }
        }

        $template_id = (int) ( $action['template_id'] ?? 0 );
        if ( $template_id <= 0 )
        {
            return null;
        }

        $template = $this->templates->get( $template_id );
        if ( ! is_array( $template ) || ! is_array( $template['structured_output_schema'] ?? null ) )
        {
            return null;
        }

        $schema = $this->normalize_structured_output_schema( $template['structured_output_schema'], 'template' );
        if ( is_wp_error( $schema ) )
        {
            return $schema;
        }

        return [
            'schema' => $schema,
            'source' => 'template',
        ];
    }

    /**
     * @param array<string, mixed> $schema Schema stored with the action or template.
     * @return array<string, mixed>|WP_Error
     */
    private function normalize_structured_output_schema( array $schema, string $source ): array | WP_Error
    {
        $validation = $this->validate_structured_output_schema_shape( $schema, $source, 'structured_output_schema' );
        if ( is_wp_error( $validation ) )
        {
            return $validation;
        }

        return $schema;
    }

    /**
     * Validate enough schema structure to call WordPress REST validation safely.
     *
     * @param array<string, mixed> $schema Schema node to validate.
     */
    private function validate_structured_output_schema_shape( array $schema, string $source, string $path ): true | WP_Error
    {
        $allowed_types = [ 'array', 'object', 'string', 'number', 'integer', 'boolean', 'null' ];

        if ( ! isset( $schema['type'] ) && ! isset( $schema['anyOf'] ) && ! isset( $schema['oneOf'] ) )
        {
            return $this->invalid_structured_output_schema_error( $source, $path );
        }

        if ( isset( $schema['type'] ) )
        {
            $types = is_array( $schema['type'] ) ? $schema['type'] : [ $schema['type'] ];
            foreach ( $types as $type )
            {
                if ( ! is_string( $type ) || ! in_array( $type, $allowed_types, true ) )
                {
                    return $this->invalid_structured_output_schema_error( $source, $path );
                }
            }
        }

        foreach ( [ 'anyOf', 'oneOf' ] as $compound_key )
        {
            if ( isset( $schema[ $compound_key ] ) )
            {
                if ( ! is_array( $schema[ $compound_key ] ) )
                {
                    return $this->invalid_structured_output_schema_error( $source, $path . '.' . $compound_key );
                }

                foreach ( $schema[ $compound_key ] as $index => $child_schema )
                {
                    if ( ! is_array( $child_schema ) )
                    {
                        return $this->invalid_structured_output_schema_error( $source, $path . '.' . $compound_key . '[' . (string) $index . ']' );
                    }

                    $validation = $this->validate_structured_output_schema_shape( $child_schema, $source, $path . '.' . $compound_key . '[' . (string) $index . ']' );
                    if ( is_wp_error( $validation ) )
                    {
                        return $validation;
                    }
                }
            }
        }

        foreach ( [ 'properties', 'patternProperties' ] as $properties_key )
        {
            if ( isset( $schema[ $properties_key ] ) )
            {
                if ( ! is_array( $schema[ $properties_key ] ) )
                {
                    return $this->invalid_structured_output_schema_error( $source, $path . '.' . $properties_key );
                }

                foreach ( $schema[ $properties_key ] as $property => $child_schema )
                {
                    if ( ! is_array( $child_schema ) )
                    {
                        return $this->invalid_structured_output_schema_error( $source, $path . '.' . $properties_key . '.' . (string) $property );
                    }

                    $validation = $this->validate_structured_output_schema_shape( $child_schema, $source, $path . '.' . $properties_key . '.' . (string) $property );
                    if ( is_wp_error( $validation ) )
                    {
                        return $validation;
                    }
                }
            }
        }

        if ( isset( $schema['items'] ) )
        {
            if ( ! is_array( $schema['items'] ) )
            {
                return $this->invalid_structured_output_schema_error( $source, $path . '.items' );
            }

            $validation = $this->validate_structured_output_schema_shape( $schema['items'], $source, $path . '.items' );
            if ( is_wp_error( $validation ) )
            {
                return $validation;
            }
        }

        if ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) )
        {
            $validation = $this->validate_structured_output_schema_shape( $schema['additionalProperties'], $source, $path . '.additionalProperties' );
            if ( is_wp_error( $validation ) )
            {
                return $validation;
            }
        }

        return true;
    }

    private function invalid_structured_output_schema_error( string $source, string $path = 'structured_output_schema' ): WP_Error
    {
        return new WP_Error(
            'sentient_forms_structured_output_schema_invalid',
            sprintf(
                /* translators: %s: Schema path. */
                __( 'The local action structured output schema is invalid at %s.', 'sentient-forms' ),
                $path
            ),
            [
                'schema_source' => $source,
                'schema_path'   => $path,
            ]
        );
    }

    /**
     * @param array<string, mixed>                                              $result   Normalized provider result.
     * @param array{schema: array<string, mixed>, source: string}|null|WP_Error $contract Output schema contract.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function validate_structured_output( array $result, array | WP_Error | null $contract ): array | WP_Error
    {
        if ( null === $contract )
        {
            return $result;
        }

        if ( is_wp_error( $contract ) )
        {
            return $contract;
        }

        if ( ! is_array( $result['structured'] ?? null ) )
        {
            return new WP_Error(
                'sentient_forms_structured_output_missing',
                __( 'The provider response did not include structured JSON required by the local action schema.', 'sentient-forms' ),
                [ 'schema_source' => $contract['source'] ]
            );
        }

        $validation = rest_validate_value_from_schema( $result['structured'], $contract['schema'], 'structured_output' );
        if ( is_wp_error( $validation ) )
        {
            return new WP_Error(
                'sentient_forms_structured_output_validation_failed',
                sprintf(
                    /* translators: %s: Schema validation failure message. */
                    __( 'The provider response did not match the local action schema: %s', 'sentient-forms' ),
                    $validation->get_error_message()
                ),
                [
                    'schema_source'   => $contract['source'],
                    'validation_code' => $validation->get_error_code(),
                ]
            );
        }

        $result['structured_output_valid']         = true;
        $result['structured_output_schema_source'] = $contract['source'];

        return $result;
    }

    private function update_credential_status_after_error( int $credential_id, WP_Error $error, string $redacted_message ): void
    {
        $error_data  = $error->get_error_data();
        $status_code = is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0;
        $status      = null;

        if ( in_array( $status_code, [ 401, 403 ], true ) )
        {
            $status = 'invalid';
        }
        elseif ( in_array( $status_code, [ 402, 429 ], true ) )
        {
            $status = 'limited';
        }

        if ( null === $status )
        {
            return;
        }

        $this->credentials->update_status(
            $credential_id,
            $status,
            [
                'last_error_code'    => $error->get_error_code(),
                'last_error_message' => $redacted_message,
                'http_status'        => $status_code,
            ]
        );
    }

    private function decode_structured_content( string $content ): ?array
    {
        $content = trim( $content );
        if ( '' === $content )
        {
            return null;
        }

        if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/is', $content, $matches ) )
        {
            $content = trim( (string) $matches[1] );
        }

        $decoded = json_decode( $content, true );
        if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) )
        {
            return $decoded;
        }

        return null;
    }

    private function redact_secret( string $message, string $secret ): string
    {
        $secret = trim( $secret );
        if ( '' === $secret )
        {
            return $message;
        }

        return str_replace( $secret, '[redacted]', $message );
    }
}
