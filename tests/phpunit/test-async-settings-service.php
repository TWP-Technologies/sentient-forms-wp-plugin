<?php

class AsyncSettingsServiceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option( 'sentient_forms_async_settings' );
    }

    public function test_defaults_return_expected_values(): void
    {
        $service  = new Sentient_Forms_Async_Settings_Service();
        $settings = $service->get_settings();

        $this->assertSame( 3, $settings['max_attempts'] );
        $this->assertSame( 60, $settings['base_delay_seconds'] );
        $this->assertSame( HOUR_IN_SECONDS, $settings['max_delay_seconds'] );
    }

    public function test_update_settings_normalizes_values(): void
    {
        $service = new Sentient_Forms_Async_Settings_Service();
        $result  = $service->update_settings(
            [
                'max_attempts'       => 0,
                'base_delay_seconds' => 10,
                'max_delay_seconds'  => 5,
            ],
            'wp_cli'
        );

        $this->assertSame( 1, $result['max_attempts'] );
        $this->assertSame( 10, $result['base_delay_seconds'] );
        $this->assertSame( 10, $result['max_delay_seconds'] );
        $this->assertSame( 'wp_cli', $result['updated_by'] );
        $this->assertNotEmpty( $result['updated_at'] );
    }
}
