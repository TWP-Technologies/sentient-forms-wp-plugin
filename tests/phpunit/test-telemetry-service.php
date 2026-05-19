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
        $this->assertStringContainsString( 'saved locally', $result['last_error'] );
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

    public function test_legacy_site_id_allows_remote_telemetry_ready_state(): void
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

        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );

        $service->queue_event(
            'async_job_success',
            [
                'action_id' => 'entry_summary_v1',
                'status'    => 'success',
            ]
        );

        $batch = $this->plugin->get_async_request_store()->claim_telemetry_batch( 10 );
        $this->assertCount( 1, $batch );

        $envelope = json_decode( (string) $batch[0]['telemetry_payload'], true );
        $this->assertSame( 'legacy-site-123', $envelope['site_id'] ?? null );
    }

    public function test_metadata_payload_excludes_result_error_and_entry_identifiers(): void
    {
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-123',
                'site_id'       => 'site-123',
            ]
        );
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );

        $service->handle_job_success(
            [
                'action_id'     => 'spam_detection_v1',
                'form_source'   => 'gravity_forms',
                'provider_path' => 'openrouter',
                'execution_request_id' => 'req-test-success',
                'entry_id'      => '123',
                'form_id'       => '4',
                'request_id'    => 'req_123',
            ],
            [
                'entry_id' => '123',
                'result'   => 'contains model output',
            ]
        );
        $service->handle_job_failure(
            [
                'action_id'   => 'entry_summary_v1',
                'form_source' => 'gravity_forms',
            ],
            new WP_Error( 'provider_timeout', 'The provider returned raw content in an error.' )
        );

        $batch = $this->plugin->get_async_request_store()->claim_telemetry_batch( 10 );
        $this->assertCount( 2, $batch );

        $execution_request_ids = [];
        foreach ( $batch as $row )
        {
            $envelope = json_decode( (string) $row['telemetry_payload'], true );
            $payload  = $envelope['payload'] ?? [];
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
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => 'proxy-key-123',
                'site_id'       => 'site-123',
            ]
        );
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );
        $this->assertNotFalse( wp_next_scheduled( 'sentient_forms_flush_telemetry' ) );

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
