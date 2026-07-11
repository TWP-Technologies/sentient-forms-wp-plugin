<?php
/**
 * Tests for admin SPA assets.
 *
 * @package Sentient_Forms
 */

class Tests_Admin_Spa_Assets extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );

        parent::tearDown();
    }

    public function test_admin_enqueue_uses_spa_bootstrap_without_legacy_script(): void
    {
        global $wp_scripts, $wp_styles;

        $previous_scripts = $wp_scripts;
        $previous_styles  = $wp_styles;
        $wp_scripts       = ( new ReflectionClass( WP_Scripts::class ) )->newInstanceWithoutConstructor();
        $wp_styles        = ( new ReflectionClass( WP_Styles::class ) )->newInstanceWithoutConstructor();

        try
        {
            $admin = new Sentient_Forms_Admin( Sentient_Forms_Plugin::instance() );
            $admin->enqueue_scripts( 'toplevel_page_sentient-forms' );

            $this->assertNotContains( 'sentient-forms-admin-legacy', $wp_scripts->queue );
            $property  = new ReflectionProperty( $admin, 'spa_bootstrap_script' );
            $bootstrap = $property->getValue( $admin );
            $this->assertIsString( $bootstrap );
            $this->assertStringContainsString( 'window.sentientFormsConfig', $bootstrap );
            $this->assertStringNotContainsString( 'window.sentientFormsAdmin', $bootstrap );
        }
        finally
        {
            $wp_scripts = $previous_scripts;
            $wp_styles  = $previous_styles;
        }
    }

    public function test_admin_does_not_register_retired_ajax_endpoints(): void
    {
        $admin = new Sentient_Forms_Admin( Sentient_Forms_Plugin::instance() );
        $admin->init();

        foreach (
            [
                'sentient_forms_test_connection',
                'sentient_forms_save_settings',
                'sentient_forms_get_forms_for_provider',
                'sentient_forms_get_actions_for_form',
                'sentient_forms_save_form_settings',
                'sentient_forms_get_action_settings_html',
                'sentient_forms_get_credit_balance',
            ] as $retired_action
        )
        {
            $this->assertFalse( has_action( 'wp_ajax_' . $retired_action ) );
        }

        foreach (
            [
                'ajax_test_connection',
                'ajax_save_settings',
                'ajax_get_forms_for_provider',
                'ajax_get_actions_for_form',
                'ajax_save_form_settings',
                'ajax_get_action_settings_html',
                'ajax_get_credit_balance',
            ] as $retired_method
        )
        {
            $this->assertFalse( method_exists( $admin, $retired_method ) );
        }
    }

    public function test_telemetry_bootstrap_exposes_only_local_diagnostic_settings(): void
    {
        $plugin = Sentient_Forms_Plugin::instance();
        $plugin->set_telemetry_settings(
            [
                'telemetry_opt_in'  => true,
                'updated_at'        => '2026-07-11 00:00:00',
                'synced_at'         => 'retired-remote-state',
                'remote_updated_at' => 'retired-remote-state',
                'last_error'        => 'retired-remote-state',
            ]
        );

        $admin  = new Sentient_Forms_Admin( $plugin );
        $method = new ReflectionMethod( $admin, 'build_telemetry_bootstrap_payload' );
        $method->setAccessible( true );

        $this->assertSame(
            [
                'optIn'     => true,
                'updatedAt' => '2026-07-11 00:00:00',
            ],
            $method->invoke( $admin )
        );
    }

    public function test_spa_bootstrap_payload_includes_elementor_requires_pro_state(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_false' );

        $admin  = new Sentient_Forms_Admin( Sentient_Forms_Plugin::instance() );
        $method = new ReflectionMethod( $admin, 'build_spa_bootstrap_payload' );
        $method->setAccessible( true );

        $payload   = $method->invoke( $admin );
        $elementor = null;
        foreach ( $payload['formSources'] ?? [] as $source )
        {
            if ( 'elementor_pro_forms' === ( $source['slug'] ?? '' ) )
            {
                $elementor = $source;
                break;
            }
        }

        $this->assertIsArray( $elementor );
        $this->assertSame( 'Elementor Pro Forms', $elementor['label'] );
        $this->assertFalse( $elementor['isActive'] );
        $this->assertSame( 'requires_pro', $elementor['availability'] ?? null );
        $this->assertStringContainsString( 'Elementor Pro Forms', $elementor['availabilityMessage'] ?? '' );
        $this->assertTrue( $elementor['requiresPro'] ?? false );
        $this->assertSame( 'requires_pro', $elementor['descriptor']['availability'] ?? null );
        $this->assertTrue( $elementor['descriptor']['requirements']['is_elementor_active'] ?? false );
        $this->assertFalse( $elementor['descriptor']['requirements']['is_pro_forms_api_available'] ?? true );
    }
}
