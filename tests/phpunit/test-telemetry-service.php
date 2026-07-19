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
        remove_all_filters( 'sentient_forms_debug_log_enabled' );
        remove_all_actions( 'sentient_forms_debug_log' );
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
        $this->assertNull( $result['last_error'] );
        $this->assertNull( $result['synced_at'] );
        $this->assertNull( $result['remote_updated_at'] );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );
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

    public function test_remote_identity_does_not_reenable_retired_telemetry_queue(): void
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

    public function test_local_diagnostic_metadata_excludes_result_error_and_entry_identifiers(): void
    {
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );
        $events  = [];
        add_filter( 'sentient_forms_debug_log_enabled', '__return_true' );
        add_action(
            'sentient_forms_debug_log',
            static function ( string $message, array $context ) use ( &$events ): void {
                if ( str_contains( $message, '[sentient-forms][diagnostics] local diagnostic event' ) )
                {
                    $events[] = $context;
                }
            },
            10,
            2
        );

        $service->queue_event(
            'async_job_success',
            [
                'action_id'     => 'spam_detection_v1',
                'form_source'   => 'gravity_forms',
                'provider_path' => 'openrouter',
                'execution_request_id' => 'req-test-success',
                'entry_id'      => '123',
                'form_id'       => '4',
                'request_id'    => 'req_123',
                'entry_id' => '123',
                'result'   => 'contains model output',
            ]
        );
        $service->queue_event(
            'async_job_failure',
            [
                'action_id'   => 'entry_summary_v1',
                'form_source' => 'gravity_forms',
                'error_code'  => 'provider_timeout',
                'error_msg'   => 'The provider returned raw content in an error.',
                'entry_id'    => '456',
                'form_id'     => '7',
                'request_id'  => 'req_456',
            ]
        );

        $this->assertCount( 2, $events );
        $this->assertSame( [], $this->plugin->get_async_request_store()->claim_telemetry_batch( 10 ) );

        $execution_request_ids = [];
        foreach ( $events as $event )
        {
            $this->assertSame( [ 'event', 'metadata' ], array_keys( $event ) );
            $payload = $event['metadata'] ?? [];
            if ( isset( $payload['execution_request_id'] ) )
            {
                $execution_request_ids[] = $payload['execution_request_id'];
            }

            $this->assertSame( 'sentient_forms_telemetry_metadata.v1', $payload['schema_version'] ?? null );
            $this->assertArrayNotHasKey( 'result', $payload );
            $this->assertArrayNotHasKey( 'error_msg', $payload );
            $this->assertArrayNotHasKey( 'entry_id', $payload );
            $this->assertArrayNotHasKey( 'form_id', $payload );
            $this->assertArrayNotHasKey( 'request_id', $payload );
        }

        $this->assertContains( 'req-test-success', $execution_request_ids );
    }

    public function test_opt_out_unschedules_flush_cron(): void
    {
        wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', 'sentient_forms_flush_telemetry' );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );

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
