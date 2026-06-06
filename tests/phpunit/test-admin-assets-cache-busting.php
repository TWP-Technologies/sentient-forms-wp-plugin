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
    /** @var callable|null */
    private $http_request_filter = null;
    /** @var callable|null */
    private $dev_host_filter = null;
    /** @var callable|null */
    private $dev_probe_notice_filter = null;
    /** @var callable */
    private $dev_assets_enabled_filter;
    /** @var string|false */
    private $original_admin_dev_host_env = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original_admin_dev_host_env = getenv( 'SENTIENT_FORMS_ADMIN_DEV_HOST' );

        // Force production-style asset URL resolution so cache-busting assertions are deterministic.
        $this->asset_base_url_filter = static function () {
            return trailingslashit( SENTIENT_FORMS_PLUGIN_URL ) . 'assets/dist/';
        };

        add_filter( 'sentient_forms_admin_asset_base_url', $this->asset_base_url_filter );
        $this->dev_assets_enabled_filter = static function () {
            return true;
        };
        add_filter( 'sentient_forms_admin_dev_assets_enabled', $this->dev_assets_enabled_filter );
        delete_transient( 'sentient_forms_admin_dev_url' );

        $this->assets = new Sentient_Forms_Admin_Assets();
    }

    protected function tearDown(): void
    {
        if ( $this->http_request_filter ) {
            remove_filter( 'pre_http_request', $this->http_request_filter, 10 );
            $this->http_request_filter = null;
        }

        if ( $this->dev_host_filter ) {
            remove_filter( 'sentient_forms_admin_dev_host', $this->dev_host_filter );
            $this->dev_host_filter = null;
        }

        if ( $this->dev_probe_notice_filter ) {
            remove_filter( 'sentient_forms_admin_show_dev_probe_failures', $this->dev_probe_notice_filter );
            $this->dev_probe_notice_filter = null;
        }

        remove_filter( 'sentient_forms_admin_asset_base_url', $this->asset_base_url_filter );
        remove_filter( 'sentient_forms_admin_dev_assets_enabled', $this->dev_assets_enabled_filter );
        delete_transient( 'sentient_forms_admin_dev_url' );
        $this->restore_admin_dev_host_env();
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

    public function test_sveltekit_runtime_key_reads_generated_metadata(): void
    {
        $runtime_path = trailingslashit( SENTIENT_FORMS_PLUGIN_DIR ) . 'assets/dist/runtime.json';
        if ( ! file_exists( $runtime_path ) )
        {
            $this->markTestSkipped( 'Built admin runtime metadata is not available.' );
        }

        $metadata = json_decode( (string) file_get_contents( $runtime_path ), true );
        $expected = is_array( $metadata ) ? ( $metadata['sveltekitRuntimeKey'] ?? null ) : null;

        $this->assertIsString( $expected );
        $this->assertMatchesRegularExpression( '/^__sveltekit_[a-z0-9]+$/', $expected );
        $this->assertSame( $expected, $this->assets->get_sveltekit_runtime_key() );
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

    /**
     * Dev-server probing must not happen without an explicit dev host.
     */
    public function test_dev_probe_is_skipped_without_explicit_host(): void
    {
        $this->clear_admin_dev_host_env_for_probe_tests();
        remove_filter( 'sentient_forms_admin_asset_base_url', $this->asset_base_url_filter );
        delete_transient( 'sentient_forms_admin_dev_url' );

        $this->http_request_filter = static function () {
            return new WP_Error( 'unexpected_probe', 'Dev host should not be probed without explicit opt-in host.' );
        };
        add_filter( 'pre_http_request', $this->http_request_filter, 10, 3 );

        $assets = new Sentient_Forms_Admin_Assets();
        $assets->get_entry( '.svelte-kit/generated/client-optimized/app.js' );

        $this->assertNull(
            $assets->get_dev_notice(),
            'Dev host probing should stay disabled unless a host is explicitly provided'
        );
    }

    /**
     * Probe failures can be surfaced when the implicit-probe notice filter is enabled.
     */
    public function test_implicit_dev_probe_failure_sets_notice_when_filter_enabled(): void
    {
        $this->clear_admin_dev_host_env_for_probe_tests();
        remove_filter( 'sentient_forms_admin_asset_base_url', $this->asset_base_url_filter );
        delete_transient( 'sentient_forms_admin_dev_url' );

        $this->dev_probe_notice_filter = static function () {
            return true;
        };
        add_filter( 'sentient_forms_admin_show_dev_probe_failures', $this->dev_probe_notice_filter );

        $this->dev_host_filter = static function () {
            return 'http://localhost:5173/';
        };
        add_filter( 'sentient_forms_admin_dev_host', $this->dev_host_filter );

        $this->http_request_filter = static function () {
            return new WP_Error( 'http_request_failed', 'Synthetic probe failure' );
        };
        add_filter( 'pre_http_request', $this->http_request_filter, 10, 3 );

        $assets = new Sentient_Forms_Admin_Assets();
        $assets->get_entry( '.svelte-kit/generated/client-optimized/app.js' );

        $this->assertSame(
            'Synthetic probe failure',
            $assets->get_dev_notice(),
            'Probe failures should show a notice when the implicit-probe notice filter is enabled'
        );
    }

    private function clear_admin_dev_host_env_for_probe_tests(): void
    {
        putenv( 'SENTIENT_FORMS_ADMIN_DEV_HOST' );
    }

    private function restore_admin_dev_host_env(): void
    {
        if ( false === $this->original_admin_dev_host_env ) {
            putenv( 'SENTIENT_FORMS_ADMIN_DEV_HOST' );
            return;
        }

        putenv( 'SENTIENT_FORMS_ADMIN_DEV_HOST=' . (string) $this->original_admin_dev_host_env );
    }
}
