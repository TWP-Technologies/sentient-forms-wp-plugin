<?php

class Tests_Models_Controller extends WP_UnitTestCase
{
    private static int $admin_id;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = (int) $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );

        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_model_tables();

        add_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        new Sentient_Forms_REST_API();
        do_action( 'rest_api_init', rest_get_server() );
    }

    protected function tearDown(): void
    {
        remove_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        parent::tearDown();
    }

    public function controller_classes(): array
    {
        return [ Sentient_Forms_Models_Controller::class ];
    }

    public function test_list_models_uses_local_cache_without_proxy_key(): void
    {
        $this->seed_model_cache();
        delete_option( 'sentient_forms_proxy_api_key' );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/models' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'local-openrouter-v2', $data['pricing_policy_version'] );
        $this->assertGreaterThanOrEqual( 20, count( $data['models'] ) );
        $this->assertGreaterThanOrEqual( 8, count( $data['presets'] ) );

        $model_ids = wp_list_pluck( $data['models'], 'id' );
        $this->assertContains( 'openai/gpt-oss-20b:free', $model_ids );
        $this->assertContains( 'openai/gpt-5.5', $model_ids );
        $this->assertContains( 'anthropic/claude-sonnet-4.6', $model_ids );

        $models_by_id = [];
        foreach ( $data['models'] as $model )
        {
            $models_by_id[ $model['id'] ] = $model;
        }
        $this->assertSame( 'free', $models_by_id['openai/gpt-oss-20b:free']['cost_tier'] );
        $this->assertTrue( $models_by_id['openai/gpt-oss-20b:free']['capabilities']['long_context'] );
        $this->assertContains( 'structured-output', $models_by_id['openai/gpt-oss-20b:free']['tags'] );

        $preset_codes = wp_list_pluck( $data['presets'], 'code' );
        $this->assertContains( 'sf_default', $preset_codes );
        $this->assertContains( 'sf_free', $preset_codes );
        $this->assertContains( 'sf_general', $preset_codes );
        $this->assertContains( 'sf_quality', $preset_codes );
        $this->assertContains( 'sf_structured', $preset_codes );
        $this->assertContains( 'sf_fast', $preset_codes );
        $this->assertContains( 'sf_low_cost', $preset_codes );
        $this->assertContains( 'sf_long_context', $preset_codes );
        $this->assertContains( 'sf_reasoning', $preset_codes );
        $this->assertContains( 'sf_code', $preset_codes );
        $this->assertContains( 'sf_legal', $preset_codes );
        $this->assertContains( 'sf_financial', $preset_codes );
        $this->assertContains( 'sf_realtime', $preset_codes );
        $this->assertSame( '~openai/gpt-latest', $data['presets'][0]['resolved_model_id'] );
    }

    public function test_list_models_returns_bundled_recommendations_when_cache_empty(): void
    {
        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/models' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data      = $response->get_data();
        $model_ids = wp_list_pluck( $data['models'], 'id' );

        $this->assertContains( 'openai/gpt-5.5', $model_ids );
        $this->assertContains( 'openai/gpt-5.4-nano', $model_ids );
        $this->assertContains( 'openai/gpt-5-nano', $model_ids );
        $this->assertContains( 'openai/gpt-5.4', $model_ids );
        $this->assertContains( 'openai/gpt-5.4-mini', $model_ids );
        $this->assertContains( 'google/gemini-3.1-pro-preview', $model_ids );
        $this->assertContains( 'google/gemini-3-flash-preview', $model_ids );
        $this->assertContains( 'anthropic/claude-sonnet-4.6', $model_ids );
        $this->assertContains( 'anthropic/claude-haiku-4.5', $model_ids );
        $this->assertContains( 'anthropic/claude-opus-4.7', $model_ids );
        $this->assertContains( 'deepseek/deepseek-v4-flash', $model_ids );
        $this->assertContains( 'deepseek/deepseek-v3.2', $model_ids );
        $this->assertContains( 'moonshotai/kimi-k2.6', $model_ids );
        $this->assertContains( 'z-ai/glm-5.1', $model_ids );
        $this->assertContains( 'qwen/qwen3.6-max-preview', $model_ids );
        $this->assertContains( 'poolside/laguna-m.1:free', $model_ids );
        $this->assertContains( 'openrouter/free', $model_ids );
        $this->assertContains( 'openrouter/auto', $model_ids );
        $this->assertContains( '~openai/gpt-latest', $model_ids );
        $this->assertContains( '~openai/gpt-mini-latest', $model_ids );
        $this->assertContains( '~google/gemini-pro-latest', $model_ids );
        $this->assertContains( '~google/gemini-flash-latest', $model_ids );
        $this->assertContains( '~anthropic/claude-opus-latest', $model_ids );
        $this->assertContains( '~anthropic/claude-sonnet-latest', $model_ids );
        $this->assertContains( '~anthropic/claude-haiku-latest', $model_ids );
        $this->assertContains( 'bundled-recommendation', $data['models'][0]['tags'] );
        $this->assertSame( '~openai/gpt-latest', $data['presets'][0]['resolved_model_id'] );

        $models_by_id = [];
        foreach ( $data['models'] as $model )
        {
            $models_by_id[ $model['id'] ] = $model;
        }
        $this->assertArrayHasKey( 'cost_symbol', $models_by_id['anthropic/claude-haiku-4.5'] );
        $this->assertNotSame( 'low', $models_by_id['anthropic/claude-haiku-4.5']['cost_symbol'] );
        $this->assertIsArray( $models_by_id['moonshotai/kimi-k2.6']['category_rankings'] ?? null );
        $this->assertArrayHasKey( 'programming', $models_by_id['moonshotai/kimi-k2.6']['category_rankings'] );

        $presets_by_code = [];
        foreach ( $data['presets'] as $preset )
        {
            $presets_by_code[ $preset['code'] ] = $preset;
        }

        $expected_preset_models = [
            'sf_default'      => '~openai/gpt-latest',
            'sf_general'      => '~openai/gpt-latest',
            'sf_quality'      => 'openai/gpt-5.5-pro',
            'sf_free'         => 'openrouter/free',
            'sf_structured'   => '~openai/gpt-latest',
            'sf_fast'         => '~google/gemini-flash-latest',
            'sf_low_cost'     => 'deepseek/deepseek-v4-flash',
            'sf_long_context' => '~openai/gpt-latest',
            'sf_reasoning'    => 'openai/gpt-5.5-pro',
            'sf_code'         => 'moonshotai/kimi-k2.6',
            'sf_legal'        => '~google/gemini-pro-latest',
            'sf_financial'    => '~anthropic/claude-sonnet-latest',
            'sf_privacy'      => '~openai/gpt-latest',
            'sf_realtime'     => '~google/gemini-flash-latest',
            'sf_multimodal'   => '~google/gemini-pro-latest',
            'sf_research'     => '~openai/gpt-latest',
            'sf_agentic'      => '~google/gemini-pro-latest',
        ];
        foreach ( $expected_preset_models as $preset_code => $model_id )
        {
            $this->assertArrayHasKey( $preset_code, $presets_by_code );
            $this->assertSame( $model_id, $presets_by_code[ $preset_code ]['resolved_model_id'], $preset_code );
            $this->assertArrayHasKey( 'rationale', $presets_by_code[ $preset_code ], $preset_code );
            $this->assertArrayHasKey( 'score', $presets_by_code[ $preset_code ], $preset_code );
            $this->assertNotEmpty( $presets_by_code[ $preset_code ]['source_urls'], $preset_code );
        }

        $this->assertSame( 93, $presets_by_code['sf_long_context']['score'] );
        $this->assertSame( 'high', $presets_by_code['sf_long_context']['evidence_confidence'] );
        $this->assertStringContainsString( 'context-rot', $presets_by_code['sf_long_context']['rationale'] );
        $this->assertContains( 'https://openrouter.ai/openai/gpt-5.5', $presets_by_code['sf_long_context']['source_urls'] );
        $this->assertArrayHasKey( 'category_fit', $presets_by_code['sf_long_context']['score_breakdown'] );
        $this->assertIsArray( $presets_by_code['sf_code']['top_candidates'] );
    }

    public function test_long_context_preset_uses_evidence_not_largest_advertised_window(): void
    {
        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'moonshotai/kimi-k2.5',
                [
                    'id'                   => 'moonshotai/kimi-k2.5',
                    'name'                 => 'MoonshotAI: Kimi K2.5',
                    'free'                 => false,
                    'context_length'       => 2000000,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format', 'tools' ],
                    'pricing'              => [
                        'prompt'     => '0.00000044',
                        'completion' => '0.000002',
                    ],
                ],
                $expires_at
            )
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/models' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data            = $response->get_data();
        $presets_by_code = [];
        foreach ( $data['presets'] as $preset )
        {
            $presets_by_code[ $preset['code'] ] = $preset;
        }

        $this->assertSame( '~openai/gpt-latest', $presets_by_code['sf_long_context']['resolved_model_id'] );
        $this->assertStringContainsString( 'not just the largest advertised context window', $presets_by_code['sf_long_context']['description'] );
    }

    public function test_resolve_model_prefers_mapping_selection_over_lower_scopes(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $request->set_body_params(
            [
                'global_selection' => [
                    'primary'   => 'anthropic/claude-sonnet-4.6',
                    'is_preset' => false,
                ],
                'mapping_selection' => [
                    'primary'   => 'sf_free',
                    'backup'    => 'anthropic/claude-sonnet-4.6',
                    'is_preset' => true,
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'openrouter/free', $data['model_id'] );
        $this->assertSame( 'OpenRouter Free Models Router', $data['display_name'] );
        $this->assertSame( 'mapping', $data['resolution_source'] );
        $this->assertSame( 'anthropic/claude-sonnet-4.6', $data['backup_model_id'] );

        $applied = array_values(
            array_filter(
                $data['override_chain'],
                static fn ( array $step ): bool => ! empty( $step['applied'] )
            )
        );

        $this->assertCount( 1, $applied );
        $this->assertSame( 'mapping', $applied[0]['level'] );
        $this->assertSame( 'sf_free', $applied[0]['selection'] );
    }

    public function test_resolve_model_preserves_managed_route_preview(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $request->set_body_params(
            [
                'mapping_selection' => [
                    'primary'   => 'sf_default',
                    'is_preset' => true,
                    'provider'  => 'sentient_managed',
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( '~openai/gpt-latest', $data['model_id'] );
        $this->assertSame( 'OpenAI: GPT-5.5 (latest alias)', $data['display_name'] );
        $this->assertSame( 'mapping', $data['resolution_source'] );

        $applied = array_values(
            array_filter(
                $data['override_chain'],
                static fn ( array $step ): bool => ! empty( $step['applied'] )
            )
        );

        $this->assertCount( 1, $applied );
        $this->assertSame( 'mapping', $applied[0]['level'] );
        $this->assertStringContainsString( 'managed service', $applied[0]['reason'] );
    }

    public function test_resolve_model_accepts_custom_openrouter_model_id_outside_cached_catalog(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $request->set_body_params(
            [
                'mapping_selection' => [
                    'primary'   => 'moonshotai/new-model-preview',
                    'is_preset' => false,
                    'provider'  => 'openrouter',
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'moonshotai/new-model-preview', $data['model_id'] );
        $this->assertSame( 'moonshotai/new-model-preview', $data['display_name'] );
        $this->assertSame( 'mapping', $data['resolution_source'] );
    }

    public function test_resolve_model_uses_template_hint_when_no_override_exists(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $request->set_body_params( [ 'template_model_hint' => 'anthropic/claude-sonnet-4.6' ] );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'anthropic/claude-sonnet-4.6', $data['model_id'] );
        $this->assertSame( 'template', $data['resolution_source'] );
    }

    public function test_resolve_model_falls_back_to_bundled_recommended_model_when_cache_empty(): void
    {
        $request  = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( '~openai/gpt-latest', $data['model_id'] );
        $this->assertSame( 'fallback', $data['resolution_source'] );
        $this->assertSame( 'OpenAI: GPT-5.5 (latest alias)', $data['display_name'] );
    }

    public function test_estimate_model_reports_openrouter_currency_for_local_openrouter(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/estimate' );
        $request->set_body_params(
            [
                'action_id'        => 'entry_summary',
                'base_credit_cost' => 7,
                'mapping_selection' => [
                    'primary'   => 'anthropic/claude-sonnet-4.6',
                    'is_preset' => false,
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'anthropic/claude-sonnet-4.6', $data['resolved_model']['model_id'] );
        $this->assertSame( 'entry_summary', $data['pricing_estimate']['action_id'] );
        $this->assertSame( 7, $data['pricing_estimate']['base_floor_credits'] );
        $this->assertSame( 0, $data['pricing_estimate']['normalized_actual_credits'] );
        $this->assertSame( 0, $data['pricing_estimate']['estimated_debit_credits'] );
        $this->assertSame( 'local-openrouter-v2', $data['pricing_estimate']['pricing_policy_version'] );
        $this->assertSame( 'baseline_profile', $data['pricing_estimate']['estimate_source'] );
        $this->assertSame( 'openrouter', $data['pricing_estimate']['route'] );
        $this->assertSame( 'openrouter_currency', $data['pricing_estimate']['kind'] );
        $this->assertStringStartsWith( 'OR est. $', $data['pricing_estimate']['label'] );
        $this->assertGreaterThan( 0, $data['pricing_estimate']['amount_usd'] );
        $this->assertSame( 1900, $data['pricing_estimate']['estimated_input_tokens'] );
        $this->assertSame( 320, $data['pricing_estimate']['estimated_output_tokens'] );
        $this->assertSame( 0, $data['pricing_estimate']['sample_count'] );
        $this->assertSame( 'baseline', $data['pricing_estimate']['confidence'] );
    }

    public function test_estimate_model_calibrates_openrouter_estimate_from_action_log_costs(): void
    {
        $this->seed_model_cache();

        $events = new Sentient_Forms_Execution_Events_Repository( $GLOBALS['wpdb'] );
        $record = $events->record(
            [
                'execution_request_id' => 'estimate-calibration-openrouter',
                'provider'             => 'openrouter',
                'model'                => 'anthropic/claude-sonnet-4.6',
                'status'               => 'succeeded',
                'token_usage_json'     => [
                    'prompt_tokens'     => 1900,
                    'completion_tokens' => 320,
                ],
                'cost_json'            => [
                    'amount_usd' => 0.02,
                    'currency'   => 'USD',
                ],
            ]
        );
        $this->assertIsInt( $record );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/estimate' );
        $request->set_body_params(
            [
                'action_id'         => 'entry_summary',
                'base_credit_cost'  => 7,
                'mapping_selection' => [
                    'primary'   => 'anthropic/claude-sonnet-4.6',
                    'is_preset' => false,
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'action_log_openrouter_cost', $data['pricing_estimate']['estimate_source'] );
        $this->assertSame( 1, $data['pricing_estimate']['sample_count'] );
        $this->assertSame( 'low', $data['pricing_estimate']['confidence'] );
        $this->assertSame( 'USD', $data['pricing_estimate']['estimate_range']['currency'] );
        $this->assertGreaterThan( 0, $data['pricing_estimate']['estimate_range']['high'] );
    }

    public function test_estimate_model_reports_managed_service_credits_for_managed_route(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/estimate' );
        $request->set_body_params(
            [
                'action_id'        => 'entry_summary',
                'base_credit_cost' => 7,
                'mapping_selection' => [
                    'primary'   => 'sf_quality',
                    'is_preset' => true,
                    'provider'  => 'sentient_managed',
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'sentient_managed', $data['pricing_estimate']['route'] );
        $this->assertSame( 'sentient_credits', $data['pricing_estimate']['kind'] );
        $this->assertSame( 'SF: 7 credits', $data['pricing_estimate']['label'] );
        $this->assertSame( 6, $data['pricing_estimate']['normalized_actual_credits'] );
        $this->assertSame( 7, $data['pricing_estimate']['estimated_debit_credits'] );
        $this->assertSame( 'baseline_profile', $data['pricing_estimate']['estimate_source'] );
        $this->assertSame( 0, $data['pricing_estimate']['sample_count'] );
        $this->assertSame( 'baseline', $data['pricing_estimate']['confidence'] );
    }

    private function seed_model_cache(): void
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
                    'supported_parameters' => [ 'response_format', 'tools' ],
                    'pricing'              => [
                        'prompt'     => '0.000003',
                        'completion' => '0.000015',
                    ],
                ],
                $expires_at
            )
        );
    }

    private function truncate_model_tables(): void
    {
        global $wpdb;

        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sentient_model_cache" );
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sentient_execution_events" );
    }
}
