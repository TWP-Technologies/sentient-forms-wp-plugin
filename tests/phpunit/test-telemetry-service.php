<?php

class Tests_Telemetry_Service extends WP_UnitTestCase
{
    private Sentient_Forms_Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = Sentient_Forms_Plugin::instance();
        update_option( 'sentient_forms_settings', [] );
        wp_clear_scheduled_hook( 'sentient_forms_flush_telemetry' );
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => '',
            ]
        );
        $this->clear_telemetry_rows();
    }

    protected function tearDown(): void
    {
        wp_clear_scheduled_hook( 'sentient_forms_flush_telemetry' );
        $this->plugin->clear_license_data();
        delete_option( 'sentient_forms_site_id' );
        $this->clear_telemetry_rows();
        parent::tearDown();
    }

    public function test_constructor_does_not_schedule_cron_without_remote_identity(): void
    {
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );

        new Sentient_Forms_Telemetry_Service( $this->plugin );

        $this->assertFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );
    }

    public function test_update_and_sync_persists_local_consent_without_proxy_key(): void
    {
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );

        $result = $service->update_and_sync( true, 'wp_user:1' );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['telemetry_opt_in'] );
        $this->assertNotEmpty( $result['updated_at'] );
        $this->assertArrayNotHasKey( 'last_error', $result );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );
    }

    public function test_plugin_drops_retired_remote_state_on_read_and_write(): void
    {
        $this->plugin->update_options(
            [
                'telemetry' => [
                    'telemetry_opt_in'  => true,
                    'updated_at'        => '2026-07-11 00:00:00',
                    'synced_at'         => 'retired-remote-state',
                    'remote_updated_at' => 'retired-remote-state',
                    'last_error'        => 'retired-remote-state',
                ],
            ]
        );

        $this->assertSame(
            [
                'telemetry_opt_in' => true,
                'updated_at'       => '2026-07-11 00:00:00',
            ],
            $this->plugin->get_telemetry_settings()
        );

        $this->plugin->set_telemetry_settings(
            [
                'telemetry_opt_in'  => false,
                'updated_at'        => '2026-07-11 01:00:00',
                'synced_at'         => 'must-not-persist',
                'remote_updated_at' => 'must-not-persist',
                'last_error'        => 'must-not-persist',
            ]
        );

        $this->assertSame(
            [
                'telemetry_opt_in' => false,
                'updated_at'       => '2026-07-11 01:00:00',
            ],
            $this->plugin->get_options()['telemetry']
        );
    }

    public function test_rest_contract_exposes_only_local_diagnostic_settings(): void
    {
        $controller = new Sentient_Forms_Telemetry_Controller();
        $method     = new ReflectionMethod( $controller, 'format_response' );
        $method->setAccessible( true );

        $response = $method->invoke(
            $controller,
            [
                'telemetry_opt_in'  => true,
                'updated_at'        => '2026-07-11 00:00:00',
                'synced_at'         => 'retired-remote-state',
                'remote_updated_at' => 'retired-remote-state',
                'last_error'        => 'retired-remote-state',
            ]
        );

        $this->assertSame(
            [
                'telemetry_opt_in' => true,
                'updated_at'       => '2026-07-11 00:00:00',
            ],
            $response
        );
    }

    public function test_queue_event_does_not_store_rows_without_remote_identity(): void
    {
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );

        $service->queue_event(
            'async_job_success',
            [
                'action_id' => 'spam_detection_v1',
                'adapter'   => 'gravity_forms',
                'status'    => 'success',
            ]
        );

        $this->assertSame( [], $this->plugin->get_async_request_store()->claim_telemetry_batch( 10 ) );
    }

    public function test_connected_identity_does_not_activate_retired_remote_transport(): void
    {
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-123',
                'site_id'       => '',
            ]
        );
        update_option( 'sentient_forms_site_id', 'legacy-site-123', false );
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );

        $this->assertFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );

        $service->queue_event(
            'async_job_success',
            [
                'action_id' => 'entry_summary_v1',
                'status'    => 'success',
            ]
        );

        $this->assertSame( [], $this->plugin->get_async_request_store()->claim_telemetry_batch( 10 ) );
    }

    public function test_update_and_sync_never_calls_retired_remote_transport(): void
    {
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-123',
                'site_id'       => 'site-123',
            ]
        );
        $http_calls = 0;
        $listener   = static function ( $preempt ) use ( &$http_calls ) {
            ++$http_calls;

            return $preempt;
        };
        add_filter( 'pre_http_request', $listener, 10, 1 );

        try
        {
            $result = ( new Sentient_Forms_Telemetry_Service( $this->plugin ) )->update_and_sync( true, 'wp_user:1' );
        }
        finally
        {
            remove_filter( 'pre_http_request', $listener, 10 );
        }

        $this->assertSame( 0, $http_calls );
        $this->assertIsArray( $result );
        $this->assertTrue( $result['telemetry_opt_in'] ?? false );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );
    }

    public function test_metadata_payload_excludes_result_error_and_entry_identifiers(): void
    {
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );
        $reflection = new ReflectionMethod( $service, 'metadata_payload' );
        $payload    = $reflection->invoke(
            $service,
            [
                'action_id'            => 'spam_detection_v1',
                'form_source'          => 'gravity_forms',
                'provider_path'        => 'openrouter',
                'execution_request_id' => 'req-test-success',
                'entry_id'             => '123',
                'form_id'              => '4',
                'request_id'           => 'req_123',
                'result'               => 'contains model output',
                'error_msg'            => 'contains raw provider content',
            ]
        );

        $this->assertSame( 'sentient_forms_telemetry_metadata.v1', $payload['schema_version'] ?? null );
        $this->assertSame( 'req-test-success', $payload['execution_request_id'] ?? null );
        $this->assertArrayNotHasKey( 'result', $payload );
        $this->assertArrayNotHasKey( 'error_msg', $payload );
        $this->assertArrayNotHasKey( 'entry_id', $payload );
        $this->assertArrayNotHasKey( 'form_id', $payload );
        $this->assertArrayNotHasKey( 'request_id', $payload );
    }

    public function test_opt_out_unschedules_flush_cron(): void
    {
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-123',
                'site_id'       => 'site-123',
            ]
        );
        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', 'sentient_forms_flush_telemetry' );
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );

        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => false ] );
        $service->maybe_schedule_flush();

        $this->assertFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );
    }

    private function clear_telemetry_rows(): void
    {
        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'sentient_async_requests',
            [ 'record_type' => 'telemetry' ],
            [ '%s' ]
        );
    }
}
