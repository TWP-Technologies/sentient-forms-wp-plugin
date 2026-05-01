<?php

class Tests_Telemetry_Service extends WP_UnitTestCase
{
    private Sentient_Forms_Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = Sentient_Forms_Plugin::instance();
        update_option( 'sentient_forms_settings', [] );
        $this->plugin->set_license_data(
            [
                'proxy_api_key' => '',
            ]
        );
    }

    public function test_update_and_sync_persists_local_consent_without_proxy_key(): void
    {
        $service = new Sentient_Forms_Telemetry_Service( $this->plugin );

        $result = $service->update_and_sync( true, 'wp_user:1' );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['telemetry_opt_in'] );
        $this->assertNotEmpty( $result['updated_at'] );
        $this->assertStringContainsString( 'saved locally', $result['last_error'] );
    }
}
