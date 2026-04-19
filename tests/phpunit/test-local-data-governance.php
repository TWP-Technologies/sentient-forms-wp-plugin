<?php

class Tests_Local_Data_Governance extends WP_UnitTestCase
{
    private wpdb $wpdb;
    private Sentient_Forms_Execution_Events_Repository $events;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        Sentient_Forms_Installer::maybe_upgrade();
        $this->events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
    }

    protected function tearDown(): void
    {
        delete_option( 'sentient_forms_execution_event_retention_days' );
        delete_option( 'sentient_forms_delete_data_on_uninstall' );
        Sentient_Forms_Installer::maybe_upgrade();

        parent::tearDown();
    }

    public function test_privacy_exporter_and_eraser_handle_local_execution_content(): void
    {
        $email = 'privacy-person@example.test';

        $this->events->record(
            [
                'execution_request_id' => 'privacy-export-1',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'failed',
                'result_json'          => [
                    'content' => 'Follow up with ' . $email,
                ],
                'error_message'        => 'Provider rejected payload for ' . $email,
                'expires_at'           => null,
            ]
        );

        $export = Sentient_Forms_Local_Data_Governance::export_personal_data( $email, 1 );
        $this->assertFalse( $export['done'] === false && [] === $export['data'] );
        $this->assertCount( 1, $export['data'] );
        $this->assertStringContainsString( $email, wp_json_encode( $export['data'] ) );

        $erase = Sentient_Forms_Local_Data_Governance::erase_personal_data( $email, 1 );
        $this->assertTrue( $erase['items_removed'] );
        $this->assertTrue( $erase['items_retained'] );
        $this->assertTrue( $erase['done'] );

        $event = $this->events->get_by_request_id( 'privacy-export-1' );
        $this->assertTrue( $event['result_json']['personal_data_erased'] );
        $this->assertNull( $event['error_message'] );

        $after_export = Sentient_Forms_Local_Data_Governance::export_personal_data( $email, 1 );
        $this->assertSame( [], $after_export['data'] );
        $this->assertTrue( $after_export['done'] );
    }

    public function test_execution_event_retention_defaults_and_cleanup(): void
    {
        update_option( 'sentient_forms_execution_event_retention_days', 7 );

        $this->events->record(
            [
                'execution_request_id' => 'retention-default-1',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
            ]
        );

        $event = $this->events->get_by_request_id( 'retention-default-1' );
        $this->assertNotEmpty( $event['expires_at'] );

        $this->events->record(
            [
                'execution_request_id' => 'retention-expired-1',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
                'expires_at'           => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
            ]
        );

        $deleted = Sentient_Forms_Local_Data_Governance::run_retention_cleanup();
        $this->assertGreaterThanOrEqual( 1, $deleted );
        $this->assertNull( $this->events->get_by_request_id( 'retention-expired-1' ) );
    }

    public function test_uninstall_preserves_data_by_default_and_deletes_when_configured(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';

        update_option( 'sentient_forms_delete_data_on_uninstall', false );
        Sentient_Forms_Installer::uninstall();
        $this->assertSame( $table, $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

        update_option( 'sentient_forms_delete_data_on_uninstall', true );
        remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
        remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

        try
        {
            Sentient_Forms_Installer::uninstall();
            $this->assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

            Sentient_Forms_Installer::maybe_upgrade();
            $this->assertSame( $table, $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
        }
        finally
        {
            add_filter( 'query', [ $this, '_create_temporary_tables' ] );
            add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
        }
    }

    public function test_support_bundle_omits_secrets_results_and_error_messages(): void
    {
        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $credentials->create(
            [
                'provider'         => 'openrouter',
                'label'            => 'Support key',
                'auth_mode'        => 'manual_key',
                'encrypted_secret' => 'sk-or-secret',
                'status'           => 'valid',
                'status_json'      => [
                    'last_token' => 'also-secret',
                ],
            ]
        );

        $this->events->record(
            [
                'execution_request_id' => 'support-bundle-1',
                'provider'             => 'openrouter',
                'status'               => 'failed',
                'result_json'          => [
                    'content' => 'private result for bundle-person@example.test',
                ],
                'error_code'           => 'provider_error',
                'error_message'        => 'private error for bundle-person@example.test',
                'expires_at'           => null,
            ]
        );

        $bundle = ( new Sentient_Forms_Local_Support_Bundle_Service( $this->wpdb ) )->build();
        $json   = (string) wp_json_encode( $bundle );

        $this->assertStringNotContainsString( 'sk-or-secret', $json );
        $this->assertStringNotContainsString( 'also-secret', $json );
        $this->assertStringNotContainsString( 'bundle-person@example.test', $json );
        $this->assertTrue( $bundle['execution_summary']['recent'][0]['has_result'] );
        $this->assertTrue( $bundle['execution_summary']['recent'][0]['has_error_message'] );
    }
}
