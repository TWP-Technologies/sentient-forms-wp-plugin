<?php
/**
 * PHPUnit tests for Admin Assets cache-busting functionality.
 *
 * @package Sentient_Forms
 */

class Tests_Admin_Assets_Cache_Busting extends WP_UnitTestCase
{
    private Sentient_Forms_Admin_Assets $assets;
    /** @var callable */
    private $asset_base_url_filter;

    protected function setUp(): void
    {
        parent::setUp();

        // Force production-style asset URL resolution so cache-busting assertions are deterministic.
        $this->asset_base_url_filter = static function () {
            return trailingslashit( SENTIENT_FORMS_PLUGIN_URL ) . 'assets/dist/';
        };

        add_filter( 'sentient_forms_admin_asset_base_url', $this->asset_base_url_filter );
        delete_transient( 'sentient_forms_admin_dev_url' );

        $this->assets = new Sentient_Forms_Admin_Assets();
    }

    protected function tearDown(): void
    {
        remove_filter( 'sentient_forms_admin_asset_base_url', $this->asset_base_url_filter );
        delete_transient( 'sentient_forms_admin_dev_url' );
        parent::tearDown();
    }

    /**
     * Test that get_cache_version returns a non-empty string.
     */
    public function test_get_cache_version_returns_non_empty_string(): void
    {
        $version = $this->assets->get_cache_version();

        $this->assertNotEmpty( $version, 'Cache version should not be empty' );
        $this->assertIsString( $version, 'Cache version should be a string' );
    }

    /**
     * Test that get_cache_version returns consistent value within same request.
     */
    public function test_get_cache_version_is_memoized(): void
    {
        $version1 = $this->assets->get_cache_version();
        $version2 = $this->assets->get_cache_version();

        $this->assertSame(
            $version1,
            $version2,
            'Cache version should be memoized and return same value'
        );
    }

    /**
     * Test that get_asset_url appends version parameter by default.
     */
    public function test_get_asset_url_appends_version_by_default(): void
    {
        $url = $this->assets->get_asset_url( '_app/immutable/chunks/test.js' );

        $this->assertStringContainsString(
            '?v=',
            $url,
            'Asset URL should contain version query parameter'
        );
    }

    /**
     * Test that get_asset_url can skip version parameter when requested.
     */
    public function test_get_asset_url_can_skip_version(): void
    {
        $url = $this->assets->get_asset_url( '_app/immutable/chunks/test.js', false );

        $this->assertStringNotContainsString(
            '?v=',
            $url,
            'Asset URL should not contain version when with_version is false'
        );
    }

    /**
     * Test that empty relative path does not get version appended.
     */
    public function test_get_asset_url_empty_path_no_version(): void
    {
        $url = $this->assets->get_asset_url( '' );

        $this->assertStringNotContainsString(
            '?v=',
            $url,
            'Empty path should not have version appended'
        );
    }

    /**
     * Test that version is a valid base36 string when manifest exists.
     */
    public function test_cache_version_is_base36_encoded(): void
    {
        $version = $this->assets->get_cache_version();

        // Base36 contains only alphanumeric characters (0-9, a-z)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-z]+$/i',
            $version,
            'Cache version should be base36 encoded (alphanumeric only)'
        );
    }

    /**
     * Test that asset URL preserves the relative path correctly.
     */
    public function test_get_asset_url_preserves_path(): void
    {
        $relativePath = '_app/immutable/nodes/13.abc123.js';
        $url = $this->assets->get_asset_url( $relativePath, false );

        $this->assertStringContainsString(
            $relativePath,
            $url,
            'Asset URL should contain the relative path'
        );
    }

    /**
     * Test that leading slashes are handled correctly.
     */
    public function test_get_asset_url_handles_leading_slash(): void
    {
        $urlWithSlash = $this->assets->get_asset_url( '/test.js', false );
        $urlWithoutSlash = $this->assets->get_asset_url( 'test.js', false );

        $this->assertSame(
            $urlWithSlash,
            $urlWithoutSlash,
            'Leading slash should be normalized'
        );
    }
}
