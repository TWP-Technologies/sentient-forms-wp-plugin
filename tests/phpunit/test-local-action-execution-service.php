<?php

if ( ! class_exists( 'Sentient_Forms_Local_Result_Test_Gravity_Meta_Store' ) )
{
    class Sentient_Forms_Local_Result_Test_Gravity_Meta_Store
    {
        private static array $meta = [];

        public static function reset(): void
        {
            self::$meta = [];
        }

        public static function get_meta( int $entry_id, string $meta_key )
        {
            return self::$meta[ $entry_id ][ $meta_key ] ?? null;
        }

        public static function update_meta( int $entry_id, string $meta_key, $value ): void
        {
            if ( ! isset( self::$meta[ $entry_id ] ) )
            {
                self::$meta[ $entry_id ] = [];
            }

            self::$meta[ $entry_id ][ $meta_key ] = $value;
        }
    }
}

if ( ! function_exists( 'gform_get_meta' ) )
{
    function gform_get_meta( $entry_id, $meta_key )
    {
        return Sentient_Forms_Local_Result_Test_Gravity_Meta_Store::get_meta( (int) $entry_id, (string) $meta_key );
    }
}

if ( ! function_exists( 'gform_update_meta' ) )
{
    function gform_update_meta( $entry_id, $meta_key, $value )
    {
        Sentient_Forms_Local_Result_Test_Gravity_Meta_Store::update_meta( (int) $entry_id, (string) $meta_key, $value );
    }
}

if ( ! class_exists( 'GFFormsModel' ) )
{
    class GFFormsModel
    {
        public static array $notes = [];

        public static function add_note( $entry_id, $user_id, $user_name, $note, $note_type = '' ): void
        {
            self::$notes[] = [
                'entry_id'  => (int) $entry_id,
                'user_id'   => (int) $user_id,
                'user_name' => (string) $user_name,
                'note'      => (string) $note,
                'note_type' => (string) $note_type,
            ];
        }
    }
}

if ( ! class_exists( 'GFAPI' ) )
{
    class GFAPI
    {
        public static array $entry_property_updates = [];

        public static function update_entry_property( $entry_id, $property_name, $property_value ): void
        {
            $GLOBALS['__sentient_forms_local_spam_updates'][] = [
                'entry_id' => (int) $entry_id,
                'property' => (string) $property_name,
                'value'    => $property_value,
            ];
        }
    }
}

class Tests_Local_Action_Execution_Service extends WP_UnitTestCase
{
    private Sentient_Forms_Provider_Credentials_Repository $credentials;
    private Sentient_Forms_External_Service_Consent_Repository $consents;
    private Sentient_Forms_Local_Custom_Actions_Repository $custom_actions;
    private Sentient_Forms_Form_Mappings_Repository $mappings;
    private Sentient_Forms_Execution_Events_Repository $events;
    private Sentient_Forms_Action_Templates_Repository $templates;

    protected function setUp(): void
    {
        parent::setUp();

        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_execution_tables();

        global $wpdb;
        $this->credentials    = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $this->consents       = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $this->custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $this->mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $this->templates      = new Sentient_Forms_Action_Templates_Repository( $wpdb );

        Sentient_Forms_Plugin::instance()->clear_license_data();
        delete_option( 'sentient_forms_forced_execution_request_id' );
        delete_option( 'sentient_forms_site_context' );
        $GLOBALS['__sentient_forms_local_spam_updates'] = [];
        if ( class_exists( 'Sentient_Forms_Local_Result_Test_Gravity_Meta_Store' ) )
        {
            Sentient_Forms_Local_Result_Test_Gravity_Meta_Store::reset();
        }

        if ( class_exists( 'Sentient_Forms_Test_Gravity_Meta_Store' ) )
        {
            Sentient_Forms_Test_Gravity_Meta_Store::reset();
        }

        GFFormsModel::$notes = [];
        add_filter( 'sentient_forms_local_mark_entry_as_spam', [ $this, 'capture_spam_mark' ], 10, 3 );
    }

    protected function tearDown(): void
    {
        delete_option( 'sentient_forms_site_context' );
        remove_filter( 'sentient_forms_local_mark_entry_as_spam', [ $this, 'capture_spam_mark' ], 10 );
        parent::tearDown();
    }

    public function capture_spam_mark( mixed $handled, int $entry_id, array $result ): true
    {
        $GLOBALS['__sentient_forms_local_spam_updates'][] = [
            'entry_id' => $entry_id,
            'property' => 'status',
            'value'    => 'spam',
            'result'   => $result,
        ];

        return true;
    }

    public function test_executes_local_openrouter_mapping_and_records_success(): void
    {
        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $this->assertFalse( $result['cached'] );
        $this->assertSame( 'succeeded', $result['status'] );
        $this->assertSame( 'openrouter', $result['provider'] );
        $this->assertSame( 'openrouter/auto', $result['model'] );
        $this->assertSame( 'Contact looks legitimate.', $result['result']['content'] );

        $this->assertCount( 1, $client->chat_calls );
        $this->assertSame( $fixture['secret'], $client->chat_calls[0]['api_key'] );
        $payload = $client->chat_calls[0]['payload'];
        $this->assertSame( 'openrouter/auto', $payload['model'] );
        $this->assertSame( 'system', $payload['messages'][0]['role'] );
        $this->assertSame( 'user', $payload['messages'][1]['role'] );
        $this->assertStringContainsString( 'Ada Lovelace', $payload['messages'][1]['content'] );
        $this->assertStringContainsString( 'ada@example.test', $payload['messages'][1]['content'] );
        $this->assertStringContainsString( 'Contact Form', $payload['messages'][1]['content'] );
        $this->assertStringNotContainsString( '{{', wp_json_encode( $payload ) );

        $event = $this->events->get_by_request_id( $result['execution_request_id'] );
        $this->assertIsArray( $event );
        $this->assertSame( 'succeeded', $event['status'] );
        $this->assertSame( 'gravity_forms', $event['form_source'] );
        $this->assertSame( '7', $event['form_id'] );
        $this->assertSame( '99', $event['entry_id'] );
        $this->assertSame( 8, $event['token_usage_json']['prompt_tokens'] );
        $this->assertSame( 5, $event['token_usage_json']['completion_tokens'] );
        $this->assertArrayNotHasKey( 'content', $event['result_json'] );
        $this->assertSame( 'Contact looks legitimate.', $event['result_json']['result_summary'] );
        $this->assertNotEmpty( $event['payload_digest'] );
        $this->assertStringNotContainsString( $fixture['secret'], wp_json_encode( $event ) );
    }

    public function test_executes_imported_bundled_openrouter_mapping_without_saved_credential_id(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [
                'source'          => 'cps_template_mapping_import',
                'template_code'   => 'entry_summary_v1',
                'prompt_template' => 'Provide a brief, human-readable summary of this form submission.',
            ],
            [
                'code'                 => 'imported_entry_summary_v1_5cfa445eee8f',
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'gemini-3-flash-preview',
                ],
            ]
        );
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'succeeded', $result['status'] );
        $this->assertSame( 'openrouter/auto', $result['model'] );
        $this->assertCount( 1, $client->chat_calls );
        $this->assertSame( $fixture['secret'], $client->chat_calls[0]['api_key'] );
        $this->assertSame( 'openrouter/auto', $client->chat_calls[0]['payload']['model'] );
        $this->assertStringContainsString( 'Submission data:', $client->chat_calls[0]['payload']['messages'][1]['content'] );
        $this->assertStringContainsString( 'Ada Lovelace', $client->chat_calls[0]['payload']['messages'][1]['content'] );
        $this->assertStringContainsString( 'Contact Form', $client->chat_calls[0]['payload']['messages'][1]['content'] );

        $action = $this->custom_actions->get_by_code( 'imported_entry_summary_v1_5cfa445eee8f' );
        $this->assertIsArray( $action );
        $this->assertSame( $fixture['credential_id'], (int) ( $action['model_selection_json']['credential_id'] ?? 0 ) );
        $this->assertSame( 'openrouter/auto', $action['model_selection_json']['model'] ?? null );
        $this->assertStringContainsString( '{{entry}}', $action['definition_json']['prompt_template'] ?? '' );

        $mapping = $this->mappings->get( $fixture['mapping_id'] );
        $this->assertIsArray( $mapping );
        $this->assertSame( 'content', $mapping['effect_mapping_json']['meta']['sentient_forms_summary'] ?? null );
        $this->assertSame( 'content', $mapping['effect_mapping_json']['entry_note']['path'] ?? null );

        $event = $this->events->get_by_request_id( $result['execution_request_id'] );
        $this->assertIsArray( $event );
        $this->assertContains( 'meta:sentient_forms_summary', $event['result_json']['effects']['applied'] ?? [] );
        $this->assertContains( 'entry_note', $event['result_json']['effects']['applied'] ?? [] );
    }

    public function test_includes_site_context_from_runtime_settings_when_enabled(): void
    {
        update_option(
            'sentient_forms_site_context',
            [
                'summary_text' => 'This WordPress site sells industrial components to plant managers.',
                'auto_include' => false,
                'pii_ack'      => true,
            ],
            false
        );

        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'     => 'gform_after_submission',
                'settings' => [
                    'include_site_context' => 'always',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $client->chat_calls );

        $system_message = $client->chat_calls[0]['payload']['messages'][0]['content'] ?? '';
        $this->assertStringContainsString( 'Classify contact form submissions.', $system_message );
        $this->assertStringContainsString( 'Site context for this WordPress site:', $system_message );
        $this->assertStringContainsString( 'industrial components to plant managers', $system_message );
    }

    public function test_never_setting_omits_site_context_from_runtime_prompt(): void
    {
        update_option(
            'sentient_forms_site_context',
            [
                'summary_text' => 'This context should not be sent for this mapping.',
                'auto_include' => true,
                'pii_ack'      => true,
            ],
            false
        );

        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'     => 'gform_after_submission',
                'settings' => [
                    'include_site_context' => 'never',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $client->chat_calls );

        $payload_json = (string) wp_json_encode( $client->chat_calls[0]['payload'] );
        $this->assertStringNotContainsString( 'Site context for this WordPress site:', $payload_json );
        $this->assertStringNotContainsString( 'This context should not be sent', $payload_json );
    }

    public function test_site_context_requires_runtime_pii_acknowledgement(): void
    {
        update_option(
            'sentient_forms_site_context',
            [
                'summary_text' => 'This site context lacks the required acknowledgement.',
                'auto_include' => true,
                'pii_ack'      => false,
            ],
            false
        );

        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'     => 'gform_after_submission',
                'settings' => [
                    'include_site_context' => 'always',
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $client->chat_calls );

        $payload_json = (string) wp_json_encode( $client->chat_calls[0]['payload'] );
        $this->assertStringNotContainsString( 'Site context for this WordPress site:', $payload_json );
        $this->assertStringNotContainsString( 'lacks the required acknowledgement', $payload_json );
    }

    public function test_runtime_model_selection_overrides_action_default_and_passes_reasoning_effort(): void
    {
        $this->seed_openrouter_model_cache();

        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'     => 'gform_after_submission',
                'settings' => [
                    'model_selection' => [
                        'primary'   => 'anthropic/claude-sonnet-4.6',
                        'is_preset' => false,
                        'reasoning' => 'high',
                    ],
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'anthropic/claude-sonnet-4.6', $result['model'] );
        $this->assertCount( 1, $client->chat_calls );
        $payload = $client->chat_calls[0]['payload'];
        $this->assertSame( 'anthropic/claude-sonnet-4.6', $payload['model'] );
        $this->assertSame( [ 'effort' => 'high' ], $payload['reasoning'] ?? null );
    }

    public function test_runtime_model_selection_drops_reasoning_for_models_without_reasoning_support(): void
    {
        $this->seed_openrouter_model_cache();

        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'     => 'gform_after_submission',
                'settings' => [
                    'model_selection' => [
                        'primary'   => 'openai/gpt-oss-20b:free',
                        'is_preset' => false,
                        'reasoning' => 'high',
                    ],
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'openai/gpt-oss-20b:free', $result['model'] );
        $this->assertCount( 1, $client->chat_calls );
        $payload = $client->chat_calls[0]['payload'];
        $this->assertSame( 'openai/gpt-oss-20b:free', $payload['model'] );
        $this->assertArrayNotHasKey( 'reasoning', $payload );
    }

    public function test_saved_model_selection_drops_reasoning_for_models_without_reasoning_support(): void
    {
        $this->seed_openrouter_model_cache();

        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [],
            [
                'model_selection_json' => [
                    'provider'  => 'openrouter',
                    'model'     => 'openai/gpt-oss-20b:free',
                    'reasoning' => 'high',
                ],
            ]
        );
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'openai/gpt-oss-20b:free', $result['model'] );
        $this->assertCount( 1, $client->chat_calls );
        $payload = $client->chat_calls[0]['payload'];
        $this->assertSame( 'openai/gpt-oss-20b:free', $payload['model'] );
        $this->assertArrayNotHasKey( 'reasoning', $payload );
    }

    public function test_runtime_preset_model_selection_resolves_from_local_model_cache(): void
    {
        $this->seed_openrouter_model_cache();

        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'     => 'gform_after_submission',
                'settings' => [
                    'model_selection' => [
                        'primary'   => 'sf_free',
                        'is_preset' => true,
                    ],
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'openai/gpt-oss-20b:free', $result['model'] );
        $this->assertCount( 1, $client->chat_calls );
        $this->assertSame( 'openai/gpt-oss-20b:free', $client->chat_calls[0]['payload']['model'] );
    }

    public function test_runtime_model_selection_can_route_openrouter_action_through_sentient_managed(): void
    {
        $fixture = $this->create_local_openrouter_mapping();
        $managed = $this->create_ready_managed_service_credential();

        $openrouter    = new Sentient_Forms_Test_OpenRouter_Client();
        $managed_proxy = new Sentient_Forms_Test_Managed_Proxy_Client(
            [
                'execution_request_id' => 'runtime-managed-req',
                'provider'             => 'sentient_managed',
                'model'                => 'gemini-3-flash-preview',
                'status'               => 'succeeded',
                'output'               => [
                    'text' => 'Managed runtime route succeeded.',
                ],
                'token_usage'          => [
                    'input_tokens'  => 12,
                    'output_tokens' => 6,
                    'total_tokens'  => 18,
                ],
                'metering'             => [
                    'event_id'               => '44444444-4444-4444-8444-444444444444',
                    'billed_amount_microusd' => 1200,
                    'currency'               => 'USD',
                    'free_usage'             => false,
                ],
            ]
        );
        $service       = $this->create_service( $openrouter, $managed_proxy );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'                 => 'gform_after_submission',
                'execution_request_id' => 'runtime-managed-req',
                'settings'             => [
                    'model_selection' => [
                        'primary'       => 'sf_default',
                        'is_preset'     => true,
                        'provider'      => 'sentient_managed',
                        'credential_id' => $managed['credential_id'],
                    ],
                ],
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'succeeded', $result['status'] );
        $this->assertSame( 'sentient_managed', $result['provider'] );
        $this->assertSame( 'gemini-3-flash-preview', $result['model'] );
        $this->assertCount( 0, $openrouter->chat_calls );
        $this->assertCount( 1, $managed_proxy->execute_calls );
        $this->assertSame( $managed['proxy_api_key'], $managed_proxy->execute_calls[0]['proxy_api_key'] );

        $payload = $managed_proxy->execute_calls[0]['payload'];
        $this->assertSame( 'sentient_managed', $payload['provider'] );
        $this->assertSame( $managed['site_id'], $payload['site_id'] );
        $this->assertSame( 'runtime-managed-req', $payload['execution_request_id'] );
        $this->assertSame( 'gemini-3-flash-preview', $payload['model'] );
        $this->assertSame( 'contact_spam_triage', $payload['action_code'] );
    }

    public function test_rejects_ambiguous_openrouter_credential_fallback_before_provider_call(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [],
            [
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ]
        );
        $second_credential = $this->create_ready_openrouter_credential( 'Secondary OpenRouter', 'sk-or-secondary-secret' );
        $this->assertGreaterThan( $fixture['credential_id'], $second_credential );

        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [ 'id' => 99, '1' => 'Ada Lovelace', '2' => 'ada@example.test' ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_provider_credential_ambiguous', $result->get_error_code() );
        $this->assertCount( 0, $client->chat_calls );
    }

    public function test_rejects_missing_explicit_openrouter_credential_before_provider_call(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [],
            [
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'openrouter/auto',
                    'credential_id' => 999999,
                ],
            ]
        );

        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [ 'id' => 99, '1' => 'Ada Lovelace', '2' => 'ada@example.test' ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_provider_credential_not_found', $result->get_error_code() );
        $this->assertCount( 0, $client->chat_calls );
    }

    public function test_executes_local_mapping_and_persists_full_output_when_enabled(): void
    {
        update_option( 'sentient_forms_store_full_ai_outputs', true );

        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $event = $this->events->get_by_request_id( $result['execution_request_id'] );
        $this->assertIsArray( $event );
        $this->assertSame( 'Contact looks legitimate.', $event['result_json']['content'] );
    }

    public function test_executes_sentient_managed_mapping_with_proxy_key_and_records_success(): void
    {
        $fixture       = $this->create_local_managed_mapping();
        $openrouter    = new Sentient_Forms_Test_OpenRouter_Client();
        $managed_proxy = new Sentient_Forms_Test_Managed_Proxy_Client(
            [
                'execution_request_id' => 'managed-local-req',
                'provider'             => 'sentient_managed',
                'model'                => 'openai/gpt-4.1-mini',
                'status'               => 'succeeded',
                'output'               => [
                    'text' => '{"summary":"Managed contact looks legitimate."}',
                ],
                'token_usage'          => [
                    'input_tokens'  => 14,
                    'output_tokens' => 9,
                    'total_tokens'  => 23,
                ],
                'metering'             => [
                    'event_id'               => '33333333-3333-4333-8333-333333333333',
                    'billed_amount_microusd' => 1000,
                    'currency'               => 'USD',
                    'free_usage'             => false,
                ],
            ]
        );
        $service       = $this->create_service( $openrouter, $managed_proxy );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [
                'hook'                 => 'gform_after_submission',
                'execution_request_id' => 'managed-local-req',
            ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'succeeded', $result['status'] );
        $this->assertSame( 'sentient_managed', $result['provider'] );
        $this->assertSame( 'openai/gpt-4.1-mini', $result['model'] );
        $this->assertSame( 'Managed contact looks legitimate.', $result['result']['structured']['summary'] );
        $this->assertCount( 0, $openrouter->chat_calls );
        $this->assertCount( 1, $managed_proxy->execute_calls );
        $this->assertSame( $fixture['proxy_api_key'], $managed_proxy->execute_calls[0]['proxy_api_key'] );

        $payload = $managed_proxy->execute_calls[0]['payload'];
        $this->assertSame( 'sentient_managed', $payload['provider'] );
        $this->assertSame( $fixture['site_id'], $payload['site_id'] );
        $this->assertSame( 'managed-local-req', $payload['execution_request_id'] );
        $this->assertSame( 'contact_spam_triage', $payload['action_code'] );
        $this->assertStringContainsString( "SYSTEM:\nClassify contact form submissions.", $payload['prompt'] );
        $this->assertStringContainsString( 'Ada Lovelace', $payload['prompt'] );
        $this->assertArrayNotHasKey( 'input', $payload );
        $this->assertSame( 99, (int) $payload['metadata']['entry_id'] );
        $this->assertStringNotContainsString( 'Ada Lovelace', wp_json_encode( $payload['metadata'] ) );
        $this->assertStringNotContainsString( 'ada@example.test', wp_json_encode( $payload['metadata'] ) );

        $event = $this->events->get_by_request_id( 'managed-local-req' );
        $this->assertIsArray( $event );
        $this->assertSame( 'succeeded', $event['status'] );
        $this->assertSame( 'sentient_managed', $event['provider'] );
        $this->assertSame( 14, $event['token_usage_json']['input_tokens'] );
        $this->assertSame( 1000, $event['result_json']['metering']['billed_amount_microusd'] );
        $this->assertStringNotContainsString( $fixture['proxy_api_key'], wp_json_encode( $event ) );
    }

    public function test_sentient_managed_execution_requires_external_service_consent(): void
    {
        $fixture       = $this->create_local_managed_mapping( false );
        $managed_proxy = new Sentient_Forms_Test_Managed_Proxy_Client();
        $service       = $this->create_service( new Sentient_Forms_Test_OpenRouter_Client(), $managed_proxy );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [ 'id' => 99, '1' => 'Ada Lovelace', '2' => 'ada@example.test' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_external_service_consent_required', $result->get_error_code() );
        $this->assertCount( 0, $managed_proxy->execute_calls );
        $this->assertSame( [], $this->events->list_recent() );
    }

    public function test_sentient_managed_execution_requires_proxy_key_before_remote_call(): void
    {
        $fixture       = $this->create_local_managed_mapping( true, [ 'proxy_api_key' => '' ] );
        $managed_proxy = new Sentient_Forms_Test_Managed_Proxy_Client();
        $service       = $this->create_service( new Sentient_Forms_Test_OpenRouter_Client(), $managed_proxy );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [ 'id' => 99, '1' => 'Ada Lovelace', '2' => 'ada@example.test' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_sentient_managed_proxy_key_missing', $result->get_error_code() );
        $this->assertCount( 0, $managed_proxy->execute_calls );
        $this->assertSame( [], $this->events->list_recent() );
    }

    public function test_successful_local_execution_is_idempotent_for_same_payload(): void
    {
        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );
        $form    = [ 'id' => 7, 'title' => 'Contact Form' ];
        $entry   = [
            'id' => 99,
            '1'  => 'Ada Lovelace',
            '2'  => 'ada@example.test',
        ];
        $context = [ 'hook' => 'gform_after_submission' ];

        $first  = $service->execute_mapping( $fixture['mapping_id'], $form, $entry, $context );
        $second = $service->execute_mapping( $fixture['mapping_id'], $form, $entry, $context );

        $this->assertIsArray( $first );
        $this->assertIsArray( $second );
        $this->assertFalse( $first['cached'] );
        $this->assertTrue( $second['cached'] );
        $this->assertSame( $first['execution_request_id'], $second['execution_request_id'] );
        $this->assertSame( 'Contact looks legitimate.', $second['result']['result_summary'] );
        $this->assertCount( 1, $client->chat_calls );
    }

    public function test_local_execution_requires_external_service_consent(): void
    {
        $fixture = $this->create_local_openrouter_mapping( false );
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [ 'id' => 99, '1' => 'Ada Lovelace', '2' => 'ada@example.test' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_external_service_consent_required', $result->get_error_code() );
        $this->assertCount( 0, $client->chat_calls );
        $this->assertSame( [], $this->events->list_recent() );
    }

    public function test_provider_failure_records_redacted_failed_event(): void
    {
        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client(
            new WP_Error( 'openrouter_http_error', 'Provider rejected ' . $fixture['secret'], [ 'status' => 401 ] )
        );
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'openrouter_http_error', $result->get_error_code() );
        $this->assertStringNotContainsString( $fixture['secret'], $result->get_error_message() );
        $this->assertStringContainsString( '[redacted]', $result->get_error_message() );
        $this->assertCount( 1, $client->chat_calls );

        $events = $this->events->list_recent();
        $this->assertCount( 1, $events );
        $this->assertSame( 'failed', $events[0]['status'] );
        $this->assertSame( 'openrouter_http_error', $events[0]['error_code'] );
        $this->assertStringNotContainsString( $fixture['secret'], $events[0]['error_message'] );
        $this->assertStringContainsString( '[redacted]', $events[0]['error_message'] );

        $credential = $this->credentials->get( $fixture['credential_id'] );
        $this->assertIsArray( $credential );
        $this->assertSame( 'invalid', $credential['status'] );
        $this->assertSame( 401, $credential['status_json']['http_status'] );
        $this->assertSame( 'openrouter_http_error', $credential['status_json']['last_error_code'] );
        $this->assertStringNotContainsString( $fixture['secret'], wp_json_encode( $credential['status_json'] ) );
    }

    public function test_openrouter_insufficient_credits_marks_credential_limited(): void
    {
        $fixture = $this->create_local_openrouter_mapping();
        $client  = new Sentient_Forms_Test_OpenRouter_Client(
            new WP_Error( 'insufficient_credits', 'OpenRouter account has insufficient credits.', [ 'status' => 402 ] )
        );
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'insufficient_credits', $result->get_error_code() );

        $credential = $this->credentials->get( $fixture['credential_id'] );
        $this->assertIsArray( $credential );
        $this->assertSame( 'limited', $credential['status'] );
        $this->assertSame( 402, $credential['status_json']['http_status'] );
        $this->assertSame( 'insufficient_credits', $credential['status_json']['last_error_code'] );
    }

    public function test_applies_gravity_forms_effects_from_structured_result(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            [
                'store_result' => true,
                'entry_note'   => [
                    'path' => 'structured.summary',
                ],
                'meta'         => [
                    'sentient_forms_summary'        => 'structured.summary',
                    'sentient_forms_classification' => 'structured.classification',
                ],
                'spam'         => [
                    'enabled'             => true,
                    'classification_path' => 'structured.classification',
                    'confidence_path'     => 'structured.confidence',
                    'min_confidence'      => 0.8,
                ],
            ]
        );
        $client = new Sentient_Forms_Test_OpenRouter_Client(
            [
                'id'      => 'chatcmpl-spam-test',
                'model'   => 'openrouter/auto',
                'choices' => [
                    [
                        'message'       => [
                            'role'    => 'assistant',
                            'content' => wp_json_encode(
                                [
                                    'classification' => 'spam',
                                    'confidence'     => 0.91,
                                    'summary'        => 'Spam lead from disposable account.',
                                ]
                            ),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage'   => [
                    'prompt_tokens'     => 9,
                    'completion_tokens' => 7,
                    'total_tokens'      => 16,
                ],
            ]
        );
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'spam', $result['result']['structured']['classification'] );
        $this->assertSame( 'Spam lead from disposable account.', $result['result']['structured']['summary'] );
        $this->assertContains( 'store_result', $result['effects']['applied'] );
        $this->assertContains( 'entry_note', $result['effects']['applied'] );
        $this->assertContains( 'meta:sentient_forms_summary', $result['effects']['applied'] );
        $this->assertContains( 'mark_as_spam', $result['effects']['applied'] );

        $this->assertSame(
            'Spam lead from disposable account.',
            gform_get_meta( 99, 'sentient_forms_summary' )
        );
        $this->assertSame( 'spam', gform_get_meta( 99, 'sentient_forms_classification' ) );
        $this->assertSame( 'spam', gform_get_meta( 99, 'sentient_forms_spam_classification' ) );
        $this->assertIsArray( gform_get_meta( 99, '_sentient_forms_local_result' ) );
        $this->assertIsString( gform_get_meta( 99, 'sentient_forms_last_response' ) );

        $this->assertCount( 1, GFFormsModel::$notes );
        $this->assertSame( 99, GFFormsModel::$notes[0]['entry_id'] );
        $this->assertSame( 'sentient_forms_local_action', GFFormsModel::$notes[0]['note_type'] );
        $this->assertStringContainsString( 'Spam lead from disposable account.', GFFormsModel::$notes[0]['note'] );

        $this->assertCount( 1, $GLOBALS['__sentient_forms_local_spam_updates'] );
        $this->assertSame( 99, $GLOBALS['__sentient_forms_local_spam_updates'][0]['entry_id'] );
        $this->assertSame( 'status', $GLOBALS['__sentient_forms_local_spam_updates'][0]['property'] );
        $this->assertSame( 'spam', $GLOBALS['__sentient_forms_local_spam_updates'][0]['value'] );

        $event = $this->events->get_by_request_id( $result['execution_request_id'] );
        $this->assertIsArray( $event );
        $this->assertSame( 'spam', $event['result_json']['structured']['classification'] );
        $this->assertContains( 'mark_as_spam', $event['result_json']['effects']['applied'] );
    }

    public function test_applies_custom_action_post_execution_defaults_for_local_mapping(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [
                'execution_defaults' => [
                    'post_execution_actions' => [
                        [
                            'type'    => 'entry_note',
                            'message' => 'Follow up with {{field:1}} about {{structured.summary}} from {{action_label}}.',
                        ],
                        [
                            'type'      => 'wp_hook',
                            'hook_name' => 'sentient_forms_local_post_execution_test',
                        ],
                    ],
                ],
            ],
            [
                'display_name' => 'Local follow-up router',
            ]
        );

        $client = new Sentient_Forms_Test_OpenRouter_Client(
            $this->openrouter_json_response(
                [
                    'classification' => 'ham',
                    'confidence'     => 0.98,
                    'summary'        => 'enterprise support plan',
                ]
            )
        );
        $service = $this->create_service( $client );

        $hook_calls = [];
        $hook       = static function ( array $context, array $execution_result, array $effect, int $entry_id ) use ( &$hook_calls ): void {
            $hook_calls[] = [
                'context'          => $context,
                'execution_result' => $execution_result,
                'effect'           => $effect,
                'entry_id'         => $entry_id,
            ];
        };

        add_action( 'sentient_forms_local_post_execution_test', $hook, 10, 4 );

        try
        {
            $result = $service->execute_mapping(
                $fixture['mapping_id'],
                [ 'id' => 7, 'title' => 'Contact Form' ],
                [
                    'id' => 99,
                    '1'  => 'Ada Lovelace',
                    '2'  => 'ada@example.test',
                ],
                [ 'hook' => 'gform_after_submission' ]
            );
        }
        finally
        {
            remove_action( 'sentient_forms_local_post_execution_test', $hook, 10 );
        }

        $this->assertIsArray( $result );
        $this->assertContains( 'post_execution:entry_note', $result['effects']['applied'] );
        $this->assertContains( 'post_execution:wp_hook', $result['effects']['applied'] );

        $this->assertCount( 1, GFFormsModel::$notes );
        $this->assertSame( 99, GFFormsModel::$notes[0]['entry_id'] );
        $this->assertSame( 'sentient_forms_local_post_execution', GFFormsModel::$notes[0]['note_type'] );
        $this->assertStringContainsString(
            'Follow up with Ada Lovelace about enterprise support plan from Local follow-up router.',
            GFFormsModel::$notes[0]['note']
        );

        $this->assertCount( 1, $hook_calls );
        $this->assertSame( 99, $hook_calls[0]['entry_id'] );
        $this->assertSame( 'Local follow-up router', $hook_calls[0]['context']['action_label'] );
        $this->assertSame( $fixture['mapping_id'], $hook_calls[0]['context']['local_form_mapping_id'] );
        $this->assertSame( 'ham', $hook_calls[0]['execution_result']['result']['structured']['classification'] );
        $this->assertSame( 'wp_hook', $hook_calls[0]['effect']['type'] );

        $audit_json = gform_get_meta( 99, 'sentient_forms_post_execution_actions' );
        $audit      = json_decode( (string) $audit_json, true );

        $this->assertIsArray( $audit );
        $this->assertSame( 'success', $audit[0]['results'][0]['status'] ?? null );
        $this->assertSame( 'entry_note', $audit[0]['results'][0]['type'] ?? null );
        $this->assertSame( 'success', $audit[0]['results'][1]['status'] ?? null );
        $this->assertSame( 'wp_hook', $audit[0]['results'][1]['type'] ?? null );

        $event = $this->events->get_by_request_id( $result['execution_request_id'] );
        $this->assertIsArray( $event );
        $this->assertContains( 'post_execution:entry_note', $event['result_json']['effects']['applied'] );
        $this->assertContains( 'post_execution:wp_hook', $event['result_json']['effects']['applied'] );
    }

    public function test_applies_mapping_level_email_and_webhook_post_execution_effects(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            [
                'post_execution_actions' => [
                    [
                        'type'    => 'send_email',
                        'to'      => '{{field:2}}',
                        'subject' => 'Follow-up: {{summary}}',
                        'body'    => 'Result: {{structured.summary}}',
                    ],
                    [
                        'type'   => 'webhook',
                        'url'    => 'https://hooks.example.test/sentient/{{entry_id}}',
                        'method' => 'POST',
                    ],
                ],
            ]
        );

        $client = new Sentient_Forms_Test_OpenRouter_Client(
            $this->openrouter_json_response(
                [
                    'classification' => 'ham',
                    'confidence'     => 0.94,
                    'summary'        => 'priority sales inquiry',
                ]
            )
        );
        $service = $this->create_service( $client );

        $mail_calls = [];
        $mail_filter = static function ( $pre, array $atts ) use ( &$mail_calls ): bool {
            $mail_calls[] = $atts;
            return true;
        };

        $http_calls = [];
        $http_filter = static function ( $pre, array $args, string $url ) use ( &$http_calls ): array {
            $http_calls[] = [
                'url'  => $url,
                'args' => $args,
            ];

            return [
                'headers'  => [],
                'body'     => '',
                'response' => [
                    'code'    => 202,
                    'message' => 'Accepted',
                ],
                'cookies'  => [],
            ];
        };

        add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
        add_filter( 'pre_http_request', $http_filter, 10, 3 );

        try
        {
            $result = $service->execute_mapping(
                $fixture['mapping_id'],
                [ 'id' => 7, 'title' => 'Contact Form' ],
                [
                    'id' => 99,
                    '1'  => 'Ada Lovelace',
                    '2'  => 'ada@example.test',
                ],
                [ 'hook' => 'gform_after_submission' ]
            );
        }
        finally
        {
            remove_filter( 'pre_wp_mail', $mail_filter, 10 );
            remove_filter( 'pre_http_request', $http_filter, 10 );
        }

        $this->assertIsArray( $result );
        $this->assertContains( 'post_execution:send_email', $result['effects']['applied'] );
        $this->assertContains( 'post_execution:webhook', $result['effects']['applied'] );

        $this->assertCount( 1, $mail_calls );
        $this->assertSame( [ 'ada@example.test' ], $mail_calls[0]['to'] );
        $this->assertSame( 'Follow-up: priority sales inquiry', $mail_calls[0]['subject'] );
        $this->assertSame( 'Result: priority sales inquiry', $mail_calls[0]['message'] );

        $this->assertCount( 1, $http_calls );
        $this->assertSame( 'https://hooks.example.test/sentient/99', $http_calls[0]['url'] );
        $this->assertSame( 'POST', $http_calls[0]['args']['method'] );
        $webhook_payload = json_decode( (string) $http_calls[0]['args']['body'], true );
        $this->assertIsArray( $webhook_payload );
        $this->assertSame( 99, $webhook_payload['entry_id'] );
        $this->assertSame( 'ham', $webhook_payload['result']['result']['structured']['classification'] );

        $audit = json_decode( (string) gform_get_meta( 99, 'sentient_forms_post_execution_actions' ), true );
        $this->assertIsArray( $audit );
        $this->assertSame( 'send_email', $audit[0]['results'][0]['type'] ?? null );
        $this->assertSame( 'success', $audit[0]['results'][0]['status'] ?? null );
        $this->assertSame( 'webhook', $audit[0]['results'][1]['type'] ?? null );
        $this->assertSame( 'success', $audit[0]['results'][1]['status'] ?? null );
        $this->assertSame( 202, $audit[0]['results'][1]['status_code'] ?? null );
    }

    public function test_validates_structured_result_against_custom_action_schema(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [ 'structured_output_schema' => $this->structured_output_schema() ]
        );
        $client = new Sentient_Forms_Test_OpenRouter_Client(
            $this->openrouter_json_response(
                [
                    'classification' => 'ham',
                    'confidence'     => 0.97,
                    'summary'        => 'Legitimate sales inquiry.',
                ]
            )
        );
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['result']['structured_output_valid'] );
        $this->assertSame( 'custom_action', $result['result']['structured_output_schema_source'] );
        $this->assertSame( 'ham', $result['result']['structured']['classification'] );

        $event = $this->events->get_by_request_id( $result['execution_request_id'] );
        $this->assertIsArray( $event );
        $this->assertSame( 'succeeded', $event['status'] );
        $this->assertTrue( $event['result_json']['structured_output_valid'] );
        $this->assertSame( 'custom_action', $event['result_json']['structured_output_schema_source'] );
    }

    public function test_uses_template_structured_output_schema_when_custom_action_has_no_schema(): void
    {
        $template_id = $this->templates->upsert_by_code(
            [
                'source'                   => 'bundled',
                'code'                     => 'template_spam_triage',
                'display_name'             => 'Template Spam Triage',
                'prompt_template'          => 'Classify this submission.',
                'structured_output_schema' => $this->structured_output_schema(),
                'version'                  => '1',
                'is_active'                => true,
            ]
        );
        $this->assertIsInt( $template_id );

        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [],
            [ 'template_id' => $template_id ]
        );
        $client = new Sentient_Forms_Test_OpenRouter_Client(
            $this->openrouter_json_response(
                [
                    'classification' => 'spam',
                    'confidence'     => 0.84,
                    'summary'        => 'Generic spam pitch.',
                ]
            )
        );
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['result']['structured_output_valid'] );
        $this->assertSame( 'template', $result['result']['structured_output_schema_source'] );
        $this->assertSame( 'spam', $result['result']['structured']['classification'] );
    }

    public function test_uses_template_prompt_when_custom_action_has_no_prompt_template(): void
    {
        $template_id = $this->templates->upsert_by_code(
            [
                'source'          => 'bundled',
                'code'            => 'template_prompt_fallback',
                'display_name'    => 'Template Prompt Fallback',
                'prompt_template' => 'Fallback prompt for {{form.title}} from {{name}}.',
                'version'         => '1',
                'is_active'       => true,
            ]
        );
        $this->assertIsInt( $template_id );

        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [ 'prompt_template' => '' ],
            [ 'template_id' => $template_id ]
        );
        $client = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertIsArray( $result );
        $this->assertCount( 1, $client->chat_calls );
        $payload = $client->chat_calls[0]['payload'];
        $this->assertStringContainsString( 'Fallback prompt for Contact Form from Ada Lovelace.', $payload['messages'][1]['content'] );
    }

    public function test_fails_structured_result_when_schema_required_property_is_missing(): void
    {
        $fixture = $this->create_local_openrouter_mapping(
            true,
            [
                'entry_note' => [
                    'path' => 'structured.summary',
                ],
                'meta'       => [
                    'sentient_forms_summary' => 'structured.summary',
                ],
            ],
            [ 'structured_output_schema' => $this->structured_output_schema() ]
        );
        $client = new Sentient_Forms_Test_OpenRouter_Client(
            $this->openrouter_json_response(
                [
                    'classification' => 'ham',
                    'confidence'     => 0.96,
                ]
            )
        );
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_structured_output_validation_failed', $result->get_error_code() );
        $this->assertStringContainsString( 'summary', $result->get_error_message() );
        $this->assertCount( 1, $client->chat_calls );
        $this->assertSame( [], GFFormsModel::$notes );
        $this->assertNull( gform_get_meta( 99, 'sentient_forms_summary' ) );

        $events = $this->events->list_recent();
        $this->assertCount( 1, $events );
        $this->assertSame( 'failed', $events[0]['status'] );
        $this->assertSame( 'sentient_forms_structured_output_validation_failed', $events[0]['error_code'] );
        $this->assertStringContainsString( 'summary', $events[0]['error_message'] );
        $this->assertSame( 9, $events[0]['token_usage_json']['prompt_tokens'] );
        $this->assertNull( $events[0]['result_json'] );

        $credential = $this->credentials->get( $fixture['credential_id'] );
        $this->assertIsArray( $credential );
        $this->assertSame( 'valid', $credential['status'] );
    }

    public function test_rejects_invalid_structured_output_schema_before_provider_call(): void
    {
        $schema = $this->structured_output_schema();
        unset( $schema['properties']['summary']['type'] );

        $fixture = $this->create_local_openrouter_mapping(
            true,
            null,
            [ 'structured_output_schema' => $schema ]
        );
        $client  = new Sentient_Forms_Test_OpenRouter_Client();
        $service = $this->create_service( $client );

        $result = $service->execute_mapping(
            $fixture['mapping_id'],
            [ 'id' => 7, 'title' => 'Contact Form' ],
            [
                'id' => 99,
                '1'  => 'Ada Lovelace',
                '2'  => 'ada@example.test',
            ],
            [ 'hook' => 'gform_after_submission' ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_structured_output_schema_invalid', $result->get_error_code() );
        $this->assertSame( 'structured_output_schema.properties.summary', $result->get_error_data()['schema_path'] );
        $this->assertCount( 0, $client->chat_calls );
        $this->assertSame( [], $this->events->list_recent() );
    }

    /**
     * @return array{credential_id: int, mapping_id: int, secret: string}
     */
    private function create_local_openrouter_mapping(
        bool $record_consent = true,
        ?array $effect_mapping = null,
        array $definition_overrides = [],
        array $custom_action_overrides = []
    ): array
    {
        $secret = 'sk-or-local-exec-secret';
        $vault  = new Sentient_Forms_Provider_Credential_Vault();
        $encrypted = $vault->encrypt( $secret );
        $this->assertIsString( $encrypted );

        $credential_id = $this->credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Primary OpenRouter',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted,
                'status'            => 'valid',
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        if ( $record_consent )
        {
            $consent_id = $this->consents->record( 'openrouter', '2026-04-16', get_current_user_id() );
            $this->assertIsInt( $consent_id );
        }

        $definition = array_merge(
            [
                'system_prompt'   => 'Classify contact form submissions.',
                'prompt_template' => 'Name: {{name}} Email: {{email}} Form: {{form.title}}',
            ],
            $definition_overrides
        );

        $action_data = array_merge(
            [
                'code'                 => 'contact_spam_triage',
                'display_name'         => 'Contact Spam Triage',
                'definition_json'      => $definition,
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'openrouter/auto',
                    'credential_id' => $credential_id,
                ],
            ],
            $custom_action_overrides
        );

        $action_id = $this->custom_actions->create(
            $action_data
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $this->mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'name'  => '1',
                    'email' => '2',
                ],
                'effect_mapping_json' => $effect_mapping,
                'execution_mode'      => 'sync',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        return [
            'credential_id' => $credential_id,
            'mapping_id'    => $mapping_id,
            'secret'        => $secret,
        ];
    }

    private function create_ready_openrouter_credential( string $label, string $secret ): int
    {
        $vault     = new Sentient_Forms_Provider_Credential_Vault();
        $encrypted = $vault->encrypt( $secret );
        $this->assertIsString( $encrypted );

        $credential_id = $this->credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => $label,
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted,
                'status'            => 'valid',
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );

        $this->assertIsInt( $credential_id );
        return $credential_id;
    }

    /**
     * @param array<string, mixed> $license_overrides
     * @return array{credential_id: int, proxy_api_key: string, site_id: string}
     */
    private function create_ready_managed_service_credential( bool $record_consent = true, array $license_overrides = [] ): array
    {
        $site_id       = '22222222-2222-4222-8222-222222222222';
        $proxy_api_key = 'proxy-local-managed-secret';
        Sentient_Forms_Plugin::instance()->set_license_data(
            array_merge(
                [
                    'license_status' => 'active',
                    'license_id'     => 'license-managed-test',
                    'site_id'        => $site_id,
                    'proxy_api_key'  => $proxy_api_key,
                    'tier'           => 'pro',
                ],
                $license_overrides
            )
        );

        $credential_id = $this->credentials->create(
            [
                'provider'          => 'sentient_managed',
                'label'             => 'Sentient Forms managed service',
                'auth_mode'         => 'sentient_proxy',
                'status'            => 'valid',
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        if ( $record_consent )
        {
            $consent_id = $this->consents->record( 'sentient_managed', '2026-04-19', get_current_user_id() );
            $this->assertIsInt( $consent_id );
        }

        return [
            'credential_id' => $credential_id,
            'proxy_api_key' => (string) ( $license_overrides['proxy_api_key'] ?? $proxy_api_key ),
            'site_id'       => (string) ( $license_overrides['site_id'] ?? $site_id ),
        ];
    }

    /**
     * @param array<string, mixed> $license_overrides
     * @return array{credential_id: int, mapping_id: int, proxy_api_key: string, site_id: string}
     */
    private function create_local_managed_mapping( bool $record_consent = true, array $license_overrides = [] ): array
    {
        $site_id       = '22222222-2222-4222-8222-222222222222';
        $proxy_api_key = 'proxy-local-managed-secret';
        Sentient_Forms_Plugin::instance()->set_license_data(
            array_merge(
                [
                    'license_status' => 'active',
                    'license_id'     => 'license-managed-test',
                    'site_id'        => $site_id,
                    'proxy_api_key'  => $proxy_api_key,
                    'tier'           => 'pro',
                ],
                $license_overrides
            )
        );

        $credential_id = $this->credentials->create(
            [
                'provider'          => 'sentient_managed',
                'label'             => 'Sentient Forms managed service',
                'auth_mode'         => 'sentient_proxy',
                'status'            => 'valid',
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        if ( $record_consent )
        {
            $consent_id = $this->consents->record( 'sentient_managed', '2026-04-19', get_current_user_id() );
            $this->assertIsInt( $consent_id );
        }

        $action_id = $this->custom_actions->create(
            [
                'code'                 => 'contact_spam_triage',
                'display_name'         => 'Contact Spam Triage',
                'definition_json'      => [
                    'system_prompt'   => 'Classify contact form submissions.',
                    'prompt_template' => 'Name: {{name}} Email: {{email}} Form: {{form.title}}',
                    'max_tokens'      => 256,
                    'temperature'     => 0.2,
                ],
                'model_selection_json' => [
                    'provider'      => 'sentient_managed',
                    'model'         => 'openai/gpt-4.1-mini',
                    'credential_id' => $credential_id,
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $this->mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'name'  => '1',
                    'email' => '2',
                ],
                'execution_mode'      => 'sync',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        return [
            'credential_id'  => $credential_id,
            'mapping_id'     => $mapping_id,
            'proxy_api_key'  => (string) ( $license_overrides['proxy_api_key'] ?? $proxy_api_key ),
            'site_id'        => (string) ( $license_overrides['site_id'] ?? $site_id ),
        ];
    }

    private function create_service(
        Sentient_Forms_Test_OpenRouter_Client $client,
        ?Sentient_Forms_Test_Managed_Proxy_Client $managed_proxy = null
    ): Sentient_Forms_Local_Action_Execution_Service
    {
        return new Sentient_Forms_Local_Action_Execution_Service(
            $this->mappings,
            $this->custom_actions,
            $this->credentials,
            $this->consents,
            $this->events,
            new Sentient_Forms_Provider_Credential_Vault(),
            $client,
            new Sentient_Forms_Local_Prompt_Renderer(),
            new Sentient_Forms_Local_Result_Applier(),
            $this->templates,
            $managed_proxy ?? new Sentient_Forms_Test_Managed_Proxy_Client()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function structured_output_schema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => [ 'classification', 'confidence', 'summary' ],
            'additionalProperties' => false,
            'properties'           => [
                'classification' => [
                    'type' => 'string',
                    'enum' => [ 'spam', 'ham' ],
                ],
                'confidence'     => [
                    'type'    => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'summary'        => [
                    'type'      => 'string',
                    'minLength' => 1,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $structured Structured JSON response payload.
     * @return array<string, mixed>
     */
    private function openrouter_json_response( array $structured ): array
    {
        return [
            'id'      => 'chatcmpl-schema-test',
            'model'   => 'openrouter/auto',
            'choices' => [
                [
                    'message'       => [
                        'role'    => 'assistant',
                        'content' => wp_json_encode( $structured ),
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage'   => [
                'prompt_tokens'     => 9,
                'completion_tokens' => 7,
                'total_tokens'      => 16,
            ],
        ];
    }

    private function truncate_local_execution_tables(): void
    {
        global $wpdb;

        foreach (
            [
                'sentient_provider_credentials',
                'sentient_external_service_consents',
                'sentient_action_templates',
                'sentient_custom_actions',
                'sentient_form_mappings',
                'sentient_execution_events',
                'sentient_model_cache',
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }

    private function seed_openrouter_model_cache(): void
    {
        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openai/gpt-oss-20b:free',
                [
                    'id'                   => 'openai/gpt-oss-20b:free',
                    'name'                 => 'OpenAI: GPT OSS 20B (free)',
                    'free'                 => true,
                    'context_length'       => 131072,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format' ],
                    'pricing'              => [
                        'prompt'     => '0',
                        'completion' => '0',
                        'request'    => '0',
                    ],
                ],
                $expires_at
            )
        );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'anthropic/claude-sonnet-4.6',
                [
                    'id'                   => 'anthropic/claude-sonnet-4.6',
                    'name'                 => 'Anthropic: Claude Sonnet 4.6',
                    'free'                 => false,
                    'context_length'       => 200000,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format', 'reasoning', 'tools' ],
                    'pricing'              => [
                        'prompt'     => '0.000003',
                        'completion' => '0.000015',
                    ],
                ],
                $expires_at
            )
        );
    }
}

class Sentient_Forms_Test_OpenRouter_Client implements Sentient_Forms_Provider_Client_Interface
{
    /** @var array<int, array{api_key: string, payload: array<string, mixed>, options: array<string, mixed>}> */
    public array $chat_calls = [];

    public function __construct( private WP_Error | array | null $chat_response = null )
    {
    }

    public function validate_key( string $api_key ): array | WP_Error
    {
        return [
            'data' => [
                'label' => 'Test key',
            ],
        ];
    }

    public function chat_completion( string $api_key, array $payload, array $options = [] ): array | WP_Error
    {
        $this->chat_calls[] = [
            'api_key' => $api_key,
            'payload' => $payload,
            'options' => $options,
        ];

        if ( null !== $this->chat_response )
        {
            return $this->chat_response;
        }

        return [
            'id'      => 'chatcmpl-local-test',
            'model'   => $payload['model'] ?? 'openrouter/auto',
            'choices' => [
                [
                    'message'       => [
                        'role'    => 'assistant',
                        'content' => 'Contact looks legitimate.',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage'   => [
                'prompt_tokens'     => 8,
                'completion_tokens' => 5,
                'total_tokens'      => 13,
            ],
        ];
    }
}

class Sentient_Forms_Test_Managed_Proxy_Client extends Sentient_Forms_Managed_Proxy_Client
{
    /** @var array<int, array{proxy_api_key: string, payload: array<string, mixed>}> */
    public array $execute_calls = [];

    public function __construct( private WP_Error | array | null $execute_response = null )
    {
    }

    public function execute( string $proxy_api_key, array $payload ): array | WP_Error
    {
        $this->execute_calls[] = [
            'proxy_api_key' => $proxy_api_key,
            'payload'       => $payload,
        ];

        if ( null !== $this->execute_response )
        {
            return $this->execute_response;
        }

        return [
            'execution_request_id' => $payload['execution_request_id'] ?? 'managed-local-req',
            'provider'             => 'sentient_managed',
            'model'                => $payload['model'] ?? 'openai/gpt-4.1-mini',
            'status'               => 'succeeded',
            'output'               => [
                'text' => 'Managed contact looks legitimate.',
            ],
            'token_usage'          => [
                'input_tokens'  => 10,
                'output_tokens' => 5,
                'total_tokens'  => 15,
            ],
            'metering'             => [
                'event_id'               => '33333333-3333-4333-8333-333333333333',
                'billed_amount_microusd' => 1000,
                'currency'               => 'USD',
                'free_usage'             => false,
            ],
        ];
    }
}
