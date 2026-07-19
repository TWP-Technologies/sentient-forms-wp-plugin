<?php
/**
 * Tests for the Svelte admin bootstrap payload.
 *
 * @package Sentient_Forms
 */

final class Tests_Admin_Spa_Bootstrap extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );

        parent::tearDown();
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
