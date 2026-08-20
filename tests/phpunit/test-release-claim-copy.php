<?php

final class Tests_Release_Claim_Copy extends WP_UnitTestCase
{
    private string $wporg_readme;
    private string $github_readme;

    public function setUp(): void
    {
        parent::setUp();

        $root                 = dirname( __DIR__, 2 );
        $this->wporg_readme   = (string) file_get_contents( $root . '/readme.txt' );
        $this->github_readme  = (string) file_get_contents( $root . '/README.md' );
    }

    public function test_public_copy_matches_cross_source_validation_contract(): void
    {
        foreach ( [ $this->wporg_readme, $this->github_readme ] as $copy )
        {
            $this->assertStringContainsString( 'Contact Form 7, WPForms, and Elementor Pro Forms support validation', $copy );
            $this->assertStringContainsString( 'Spam Detection and Content Quality Validation', $copy );
            $this->assertStringNotContainsString( 'do not support validation blocking', $copy );
            $this->assertStringNotContainsString( 'Validation blocking, realtime suggestions', $copy );
        }
    }

    public function test_public_copy_distinguishes_custom_actions_from_internal_policy_facets(): void
    {
        foreach ( [ $this->wporg_readme, $this->github_readme ] as $copy )
        {
            $this->assertStringContainsString( 'create and manage their own Actions', $copy );
            $this->assertStringNotContainsString( 'generic Action facets', $copy );
        }
    }

    public function test_public_copy_discloses_spam_guidance_external_service_data_flow(): void
    {
        foreach ( [ $this->wporg_readme, $this->github_readme ] as $copy )
        {
            $this->assertStringContainsString( 'Spam Guidance', $copy );
            $this->assertStringContainsString( 'selected historical submission excerpts', $copy );
            $this->assertStringContainsString( 'generated rationale', $copy );
            $this->assertStringContainsString( 'OpenRouter direct execution or Sentient Forms Managed Execution', $copy );
        }
    }

    public function test_public_copy_names_both_local_retention_surfaces(): void
    {
        foreach ( [ $this->wporg_readme, $this->github_readme ] as $copy )
        {
            $this->assertStringContainsString( 'Execution Events', $copy );
            $this->assertStringContainsString( 'Submission Ledger records', $copy );
            $this->assertStringContainsString( 'retention', strtolower( $copy ) );
        }
    }
}
