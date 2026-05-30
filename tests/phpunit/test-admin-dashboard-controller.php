<?php

if ( ! class_exists( 'Sentient_Forms_Failing_Async_Health_Service' ) )
{
    class Sentient_Forms_Failing_Async_Health_Service extends Sentient_Forms_Async_Health_Service
    {
        public function evaluate(): array
        {
            throw new RuntimeException( 'Async health fixture failure.' );
        }
    }
}

class Tests_Admin_Dashboard_Controller extends WP_UnitTestCase
{
    private $http_guard = null;

    protected function setUp(): void
    {
        parent::setUp();

        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_workspace_tables();
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'proxy_api_key'  => 'proxy-dashboard-test',
                'license_id'     => 'license-dashboard-test',
                'site_id'        => 'site-dashboard-test',
                'tier'           => [
                    'code' => 'starter',
                ],
            ]
        );
    }

    protected function tearDown(): void
    {
        if ( null !== $this->http_guard )
        {
            remove_filter( 'pre_http_request', $this->http_guard, 10 );
            $this->http_guard = null;
        }

        Sentient_Forms_Plugin::instance()->clear_license_data();
        parent::tearDown();
    }

    public function test_dashboard_summary_returns_local_bootstrap_data_without_remote_calls(): void
    {
        $http_calls = 0;
        $this->http_guard = static function ( $preempt, array $args, string $url ) use ( &$http_calls ) {
            $http_calls++;
            return new WP_Error( 'unexpected_http', 'Dashboard summary should use local data only.' );
        };
        add_filter( 'pre_http_request', $this->http_guard, 10, 3 );

        global $wpdb;

        $providers = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $provider_id = $providers->create(
            [
                'provider'         => 'openrouter',
                'label'            => 'Agency OpenRouter',
                'auth_mode'        => 'manual_key',
                'encrypted_secret' => 'encrypted-secret-fixture',
                'status'           => 'valid',
                'status_json'      => [
                    'api_key'         => 'sk-or-provider-secret-123456',
                    'diagnostic_hint' => 'provider echoed sk-or-status-leak-123456',
                    'request_note'    => 'provider echoed Authorization: Bearer dashboard-bearer-token-123456',
                    'authorization'   => 'Bearer dashboard-authorization-token-123456',
                    'encrypted_blob'  => 'encrypted provider diagnostic payload',
                    'nested'          => [
                        'token' => 'provider-token-secret',
                    ],
                    'safe_label'      => 'OpenRouter validation passed',
                ],
            ]
        );
        $this->assertIsInt( $provider_id );

        $templates = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $template_id = $templates->upsert_by_code(
            [
                'source'          => 'bundled',
                'code'            => 'spam_triage_v1',
                'display_name'    => 'Spam Triage',
                'prompt_template' => 'Classify {{entry}}.',
                'default_model'   => 'openrouter/auto',
                'is_active'       => true,
            ]
        );
        $this->assertIsInt( $template_id );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $custom_action_id = $custom_actions->create(
            [
                'template_id'          => $template_id,
                'code'                 => 'contact_spam_triage',
                'display_name'         => 'Contact Spam Triage',
                'definition_json'      => [
                    'prompt' => 'Classify contact form entry.',
                ],
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'openrouter/auto',
                    'credential_id' => $provider_id,
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $custom_action_id );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $event_id = $events->record(
            [
                'execution_request_id' => 'req-dashboard-1',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '99',
                'provider'             => 'sentient_managed',
                'model'                => 'gemini-flash',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'summary' => 'Entry is legitimate.',
                    ],
                    'usage_cost' => [
                        'currency'        => 'USD',
                        'debited_credits' => 1,
                    ],
                ],
                'cost_json'            => [
                    'total_microusd' => 5000,
                    'currency'       => 'USD',
                ],
            ]
        );
        $this->assertIsInt( $event_id );

        $controller = new Sentient_Forms_Admin_Dashboard_Controller();
        $response   = $controller->get_summary( new WP_REST_Request( 'GET', '/sentient-forms/v1/admin/dashboard-summary' ) );

        $this->assertSame( 0, $http_calls );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'active', $data['license']['status'] ?? null );
        $this->assertTrue( $data['license']['proxy_key_present'] ?? false );
        $this->assertSame( 'Agency OpenRouter', $data['providers'][0]['label'] ?? null );
        $this->assertTrue( $data['providers'][0]['secret_configured'] ?? false );
        $this->assertSame( '[redacted]', $data['providers'][0]['status_json']['api_key'] ?? null );
        $this->assertSame( '[redacted]', $data['providers'][0]['status_json']['authorization'] ?? null );
        $this->assertSame( '[redacted]', $data['providers'][0]['status_json']['encrypted_blob'] ?? null );
        $this->assertSame( '[redacted]', $data['providers'][0]['status_json']['nested']['token'] ?? null );
        $this->assertStringContainsString( 'sk-or-[redacted]', $data['providers'][0]['status_json']['diagnostic_hint'] ?? '' );
        $this->assertStringContainsString( 'Bearer [redacted]', $data['providers'][0]['status_json']['request_note'] ?? '' );
        $this->assertStringNotContainsString( 'sk-or-provider-secret-123456', wp_json_encode( $data ) );
        $this->assertStringNotContainsString( 'sk-or-status-leak-123456', wp_json_encode( $data ) );
        $this->assertStringNotContainsString( 'dashboard-bearer-token-123456', wp_json_encode( $data ) );
        $this->assertStringNotContainsString( 'dashboard-authorization-token-123456', wp_json_encode( $data ) );
        $this->assertStringNotContainsString( 'encrypted provider diagnostic payload', wp_json_encode( $data ) );
        $this->assertStringNotContainsString( 'provider-token-secret', wp_json_encode( $data ) );
        $this->assertSame( 'spam_triage_v1', $data['templates'][0]['code'] ?? null );
        $this->assertSame( 'contact_spam_triage', $data['custom_actions'][0]['code'] ?? null );
        $this->assertSame( 'req-dashboard-1', $data['recent_events'][0]['execution_request_id'] ?? null );
        $this->assertArrayNotHasKey( 'currency', $data['recent_events'][0]['cost_json'] ?? [] );
        $this->assertArrayNotHasKey( 'currency', $data['recent_events'][0]['result_json']['usage_cost'] ?? [] );
        $this->assertArrayNotHasKey( 'async_health', $data );
    }

    public function test_dashboard_summary_requires_admin_permission(): void
    {
        wp_set_current_user( 0 );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/admin/dashboard-summary' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertContains( $response->get_status(), [ 401, 403 ] );

        $subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $subscriber_id );

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 403, $response->get_status() );
    }

    public function test_dashboard_summary_preserves_hundred_event_window(): void
    {
        global $wpdb;

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        for ( $i = 1; $i <= 30; $i++ )
        {
            $event_id = $events->record(
                [
                    'execution_request_id' => 'req-dashboard-window-' . $i,
                    'form_source'          => 'gravity_forms',
                    'form_id'              => '7',
                    'entry_id'             => (string) ( 100 + $i ),
                    'provider'             => 'sentient_managed',
                    'model'                => 'gemini-flash',
                    'status'               => 'succeeded',
                ]
            );
            $this->assertIsInt( $event_id );
        }

        $controller = new Sentient_Forms_Admin_Dashboard_Controller();
        $response   = $controller->get_summary( new WP_REST_Request( 'GET', '/sentient-forms/v1/admin/dashboard-summary' ) );
        $data       = $response->get_data();

        $this->assertCount( 30, $data['recent_events'] ?? [] );
    }

    public function test_dashboard_summary_returns_partial_data_when_async_health_fails(): void
    {
        global $wpdb;

        $providers = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $provider_id = $providers->create(
            [
                'provider'         => 'openrouter',
                'label'            => 'OpenRouter survives partial failure',
                'auth_mode'        => 'manual_key',
                'encrypted_secret' => 'encrypted-secret-fixture',
                'status'           => 'valid',
            ]
        );
        $this->assertIsInt( $provider_id );

        $plugin   = Sentient_Forms_Plugin::instance();
        $property = ( new ReflectionClass( Sentient_Forms_Plugin::class ) )->getProperty( 'async_health_service' );
        $property->setAccessible( true );
        $original_service = $property->getValue( $plugin );
        $property->setValue( $plugin, new Sentient_Forms_Failing_Async_Health_Service( $plugin ) );

        try
        {
            $controller       = new Sentient_Forms_Admin_Dashboard_Controller();
            $default_request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/admin/dashboard-summary' );
            $default_response = $controller->get_summary( $default_request );

            $health_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/admin/dashboard-summary' );
            $health_request->set_param( 'include_health', true );
            $response = $controller->get_summary( $health_request );
        }
        finally
        {
            $property->setValue( $plugin, $original_service );
        }

        $this->assertSame( 200, $default_response->get_status() );
        $default_data = $default_response->get_data();
        $this->assertSame( 'OpenRouter survives partial failure', $default_data['providers'][0]['label'] ?? null );
        $this->assertArrayNotHasKey( 'async_health', $default_data );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'OpenRouter survives partial failure', $data['providers'][0]['label'] ?? null );
        $this->assertSame( 'active', $data['license']['status'] ?? null );
        $this->assertSame( 0, $data['async_health']['queue_depth'] ?? null );
        $this->assertSame(
            'async_health_unavailable',
            $data['async_health']['warnings'][0]['code'] ?? null
        );
        $this->assertSame( 'async_health', $data['section_errors'][0]['section'] ?? null );
        $this->assertSame( 'dashboard_async_health_unavailable', $data['section_errors'][0]['code'] ?? null );
        $this->assertSame(
            'Background health data is temporarily unavailable.',
            $data['section_errors'][0]['message'] ?? null
        );
        $this->assertStringNotContainsString( 'Async health fixture failure', wp_json_encode( $data ) );
    }

    private function truncate_local_workspace_tables(): void
    {
        global $wpdb;

        foreach (
            [
                'sentient_provider_credentials',
                'sentient_action_templates',
                'sentient_custom_actions',
                'sentient_form_mappings',
                'sentient_execution_events',
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }
}
