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
        $this->assertSame( 'local-openrouter-v1', $data['pricing_policy_version'] );
        $this->assertCount( 2, $data['models'] );
        $this->assertGreaterThanOrEqual( 8, count( $data['presets'] ) );

        $this->assertSame( 'openai/gpt-oss-20b:free', $data['models'][0]['id'] );
        $this->assertSame( 'free', $data['models'][0]['cost_tier'] );
        $this->assertTrue( $data['models'][0]['capabilities']['long_context'] );
        $this->assertContains( 'structured-output', $data['models'][0]['tags'] );

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
        $this->assertSame( 'openai/gpt-oss-20b:free', $data['presets'][0]['resolved_model_id'] );
    }

    public function test_list_models_returns_bundled_recommendations_when_cache_empty(): void
    {
        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/models' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data      = $response->get_data();
        $model_ids = wp_list_pluck( $data['models'], 'id' );

        $this->assertContains( 'openai/gpt-5.1', $model_ids );
        $this->assertContains( 'anthropic/claude-sonnet-4.5', $model_ids );
        $this->assertContains( 'openrouter/free', $model_ids );
        $this->assertContains( 'openrouter/auto', $model_ids );
        $this->assertContains( 'bundled-recommendation', $data['models'][0]['tags'] );
        $this->assertSame( 'openai/gpt-5.1', $data['presets'][0]['resolved_model_id'] );

        $presets_by_code = [];
        foreach ( $data['presets'] as $preset )
        {
            $presets_by_code[ $preset['code'] ] = $preset;
        }

        $this->assertSame( 'openrouter/free', $presets_by_code['sf_free']['resolved_model_id'] );
        $this->assertSame( 'anthropic/claude-sonnet-4.5', $presets_by_code['sf_quality']['resolved_model_id'] );
    }

    public function test_resolve_model_prefers_mapping_selection_over_lower_scopes(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $request->set_body_params(
            [
                'global_selection' => [
                    'primary'   => 'anthropic/claude-sonnet-4.5',
                    'is_preset' => false,
                ],
                'mapping_selection' => [
                    'primary'   => 'sf_free',
                    'backup'    => 'anthropic/claude-sonnet-4.5',
                    'is_preset' => true,
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'openai/gpt-oss-20b:free', $data['model_id'] );
        $this->assertSame( 'OpenAI: GPT OSS 20B (free)', $data['display_name'] );
        $this->assertSame( 'mapping', $data['resolution_source'] );
        $this->assertSame( 'anthropic/claude-sonnet-4.5', $data['backup_model_id'] );

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
        $this->assertSame( 'gemini-3-flash-preview', $data['model_id'] );
        $this->assertSame( 'Sentient Forms managed default', $data['display_name'] );
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

    public function test_resolve_model_uses_template_hint_when_no_override_exists(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $request->set_body_params( [ 'template_model_hint' => 'anthropic/claude-sonnet-4.5' ] );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'anthropic/claude-sonnet-4.5', $data['model_id'] );
        $this->assertSame( 'template', $data['resolution_source'] );
    }

    public function test_resolve_model_falls_back_to_bundled_recommended_model_when_cache_empty(): void
    {
        $request  = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/resolve' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'openai/gpt-5.1', $data['model_id'] );
        $this->assertSame( 'fallback', $data['resolution_source'] );
        $this->assertSame( 'OpenAI: GPT-5.1', $data['display_name'] );
    }

    public function test_estimate_model_reports_no_sentient_debit_for_local_openrouter(): void
    {
        $this->seed_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/models/estimate' );
        $request->set_body_params(
            [
                'action_id'        => 'entry_summary',
                'base_credit_cost' => 7,
                'mapping_selection' => [
                    'primary'   => 'anthropic/claude-sonnet-4.5',
                    'is_preset' => false,
                ],
            ]
        );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'anthropic/claude-sonnet-4.5', $data['resolved_model']['model_id'] );
        $this->assertSame( 'entry_summary', $data['pricing_estimate']['action_id'] );
        $this->assertSame( 7, $data['pricing_estimate']['base_floor_credits'] );
        $this->assertSame( 7, $data['pricing_estimate']['normalized_actual_credits'] );
        $this->assertSame( 0, $data['pricing_estimate']['estimated_debit_credits'] );
        $this->assertSame( 'local-openrouter-v1', $data['pricing_estimate']['pricing_policy_version'] );
        $this->assertSame( 'local_cache_no_sentient_debit', $data['pricing_estimate']['estimate_source'] );
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
                'anthropic/claude-sonnet-4.5',
                [
                    'id'                   => 'anthropic/claude-sonnet-4.5',
                    'name'                 => 'Anthropic: Claude Sonnet 4.5',
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
    }
}
