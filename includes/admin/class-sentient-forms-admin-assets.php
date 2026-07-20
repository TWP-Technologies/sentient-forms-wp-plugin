<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles loading hashed SPA assets built by the SvelteKit admin application.
 */
class Sentient_Forms_Admin_Assets {

    private const DEFAULT_ENTRY = '.svelte-kit/generated/client-optimized/app.js';
    private const DEV_TRANSIENT = 'sentient_forms_admin_dev_url';

    /** @var array|null */
    private $manifest;
    private ?string $dev_base_url = null;
    private ?string $dev_notice_message = null;
    private ?string $sveltekit_runtime_key = null;
    private ?string $cache_version = null;

    /**
     * Get a cache-busting version string based on manifest modification time.
     * This ensures CDN caches are invalidated when assets are rebuilt.
     *
     * @return string Version string for cache busting.
     */
    public function get_cache_version(): string
    {
        if ( null !== $this->cache_version ) {
            return $this->cache_version;
        }

        // In dev mode, use current time to always bypass cache.
        if ( $this->dev_base_url ) {
            $this->cache_version = (string) time();
            return $this->cache_version;
        }

        $manifest_path = $this->get_assets_path( 'manifest.json' );

        if ( file_exists( $manifest_path ) ) {
            $mtime = filemtime( $manifest_path );
            if ( false !== $mtime ) {
                // Use base36 encoding for a shorter version string.
                $this->cache_version = base_convert( (string) $mtime, 10, 36 );
                return $this->cache_version;
            }
        }

        // Fallback to a stable base36 hash of plugin version when manifest doesn't exist.
        $fallback_version = defined( 'SENTIENT_FORMS_VERSION' ) ? SENTIENT_FORMS_VERSION : '1.0.0';
        $checksum         = sprintf( '%u', crc32( (string) $fallback_version ) );
        $this->cache_version = base_convert( $checksum, 10, 36 );
        return $this->cache_version;
    }

    /**
     * Retrieve the decoded Vite manifest.
     *
     * @return array|WP_Error
     */
    public function get_manifest()
    {
        if ( null !== $this->manifest ) {
            return $this->manifest;
        }

        $manifest_path = $this->get_assets_path( 'manifest.json' );

        if ( ! file_exists( $manifest_path ) ) {
            /* translators: %s is the expected Vite manifest path. */
            $message = sprintf( __( 'Sentient Forms admin manifest missing: %s', 'sentient-forms' ), $manifest_path );
            return new WP_Error( 'sentient_forms_manifest_missing', $message );
        }

        $manifest_contents = file_get_contents( $manifest_path );

        if ( false === $manifest_contents ) {
            /* translators: %s is the Vite manifest path that could not be read. */
            $message = sprintf( __( 'Unable to read admin manifest: %s', 'sentient-forms' ), $manifest_path );
            return new WP_Error( 'sentient_forms_manifest_read_error', $message );
        }

        $decoded = json_decode( $manifest_contents, true );

        if ( ! is_array( $decoded ) ) {
            /* translators: %s is the Vite manifest path containing invalid JSON. */
            $message = sprintf( __( 'Invalid admin manifest JSON: %s', 'sentient-forms' ), $manifest_path );
            return new WP_Error( 'sentient_forms_manifest_decode_error', $message );
        }

        $this->manifest = $decoded;

        return $this->manifest;
    }

    /**
     * Get entry metadata for the SPA bundle.
     *
     * @param string $entry
     *
     * @return array|WP_Error
     */
    public function get_entry( string $entry = self::DEFAULT_ENTRY )
    {
        // Ensure dev server detection has run so $this->dev_base_url is set when available.
        $this->get_assets_base_url();

        $manifest = $this->get_manifest();

        // Dev mode fallback: when probing a Vite dev server, the manifest will not exist.
        if ( is_wp_error( $manifest ) && $this->dev_base_url ) {
            return [ 'file' => ltrim( $entry, '/' ), 'css' => [] ];
        }

        if ( is_wp_error( $manifest ) ) {
            return $manifest;
        }

        if ( isset( $manifest[ $entry ] ) ) {
            return $this->validate_manifest_entry( $manifest[ $entry ], $entry );
        }

        $normalized_entry = ltrim( str_replace( '\\', '/', $entry ), '/' );
        $source_matches   = [];

        foreach ( $manifest as $manifest_key => $candidate ) {
            if ( ! is_array( $candidate ) || empty( $candidate['isEntry'] ) || ! is_string( $candidate['src'] ?? null ) ) {
                continue;
            }

            $normalized_key    = ltrim( str_replace( '\\', '/', (string) $manifest_key ), '/' );
            $normalized_source = ltrim( str_replace( '\\', '/', $candidate['src'] ), '/' );
            $path_matches      = $normalized_key === $normalized_entry
                || str_ends_with( $normalized_key, '/' . $normalized_entry )
                || $normalized_source === $normalized_entry
                || str_ends_with( $normalized_source, '/' . $normalized_entry );
            $is_svelte_start   = 'node_modules/@sveltejs/kit/src/runtime/client/entry.js' === $normalized_entry
                && 'entry/start' === ( $candidate['name'] ?? null );

            if ( $path_matches || $is_svelte_start ) {
                $source_matches[ (string) $manifest_key ] = $candidate;
            }
        }

        if ( 1 === count( $source_matches ) ) {
            return $this->validate_manifest_entry( reset( $source_matches ), $entry );
        }

        if ( count( $source_matches ) > 1 ) {
            return new WP_Error(
                'sentient_forms_manifest_entry_ambiguous',
                sprintf(
                    /* translators: %s: admin manifest entry. */
                    __( 'Entry %s matched multiple admin manifest sources.', 'sentient-forms' ),
                    $entry
                )
            );
        }

        if ( $this->dev_base_url ) {
            // In dev we don't have hashed entries; fall back to requested path.
            return [ 'file' => ltrim( $entry, '/' ), 'css' => [] ];
        }

        return new WP_Error(
            'sentient_forms_manifest_entry_missing',
            sprintf(
                /* translators: %s: admin manifest entry. */
                __( 'Entry %s not found in admin manifest.', 'sentient-forms' ),
                $entry
            )
        );
    }

    /**
     * Validate production module metadata before constructing a public asset URL.
     *
     * @param mixed  $candidate
     * @param string $entry
     * @return array|WP_Error
     */
    private function validate_manifest_entry( $candidate, string $entry )
    {
        $file = is_array( $candidate ) && is_string( $candidate['file'] ?? null )
            ? str_replace( '\\', '/', trim( $candidate['file'] ) )
            : '';

        if (
            '' === $file
            || str_starts_with( $file, '/' )
            || preg_match( '#(?:^|/)\.\.(?:/|$)#', $file )
            || ! str_ends_with( strtolower( $file ), '.js' )
        ) {
            return new WP_Error(
                'sentient_forms_manifest_entry_invalid',
                sprintf(
                    /* translators: %s: admin manifest entry. */
                    __( 'Entry %s has invalid admin manifest metadata.', 'sentient-forms' ),
                    $entry
                )
            );
        }

        return $candidate;
    }

    /**
     * Resolve a plugin-relative asset URL with cache-busting version parameter.
     *
     * @param string $relative Relative path to the asset.
     * @param bool   $with_version Whether to append cache-busting version parameter. Default true.
     *
     * @return string Full URL to the asset.
     */
    public function get_asset_url( string $relative, bool $with_version = true ): string
    {
        $url = trailingslashit( $this->get_assets_base_url() ) . ltrim( $relative, '/' );

        // Add cache-busting version parameter for production assets.
        if ( $with_version && ! $this->dev_base_url && '' !== $relative ) {
            $version = $this->get_cache_version();
            $url = add_query_arg( 'v', $version, $url );
        }

        return $url;
    }

    /**
     * Resolve absolute filesystem path for assets/dist.
     */
    private function get_assets_path( string $relative ): string
    {
        return trailingslashit( SENTIENT_FORMS_PLUGIN_DIR ) . 'assets/dist/' . ltrim( $relative, '/' );
    }

    public function get_sveltekit_runtime_key(): string
    {
        if ( null !== $this->sveltekit_runtime_key ) {
            return $this->sveltekit_runtime_key;
        }

        $runtime_metadata_path = $this->get_assets_path( 'runtime.json' );
        if ( file_exists( $runtime_metadata_path ) ) {
            $contents = file_get_contents( $runtime_metadata_path );
            $decoded  = is_string( $contents ) ? json_decode( $contents, true ) : null;
            $key      = is_array( $decoded ) ? ( $decoded['sveltekitRuntimeKey'] ?? null ) : null;
            if ( is_string( $key ) && preg_match( '/^__sveltekit_[a-z0-9]+$/', $key ) ) {
                $this->sveltekit_runtime_key = $key;
                return $this->sveltekit_runtime_key;
            }
        }

        $index_path = $this->get_assets_path( 'index.html' );
        if ( file_exists( $index_path ) ) {
            $contents = file_get_contents( $index_path );
            if ( is_string( $contents ) && preg_match( '/__sveltekit_[a-z0-9]+/', $contents, $matches ) ) {
                $this->sveltekit_runtime_key = $matches[0];
                return $this->sveltekit_runtime_key;
            }
        }

        $this->sveltekit_runtime_key = '__sveltekit_legacy';
        return $this->sveltekit_runtime_key;
    }

    private function get_assets_base_url(): string
    {
        $default = trailingslashit( SENTIENT_FORMS_PLUGIN_URL ) . 'assets/dist/';

        if ( ! $this->development_asset_overrides_allowed() ) {
            return $default;
        }

        if ( defined( 'SENTIENT_FORMS_ADMIN_ASSET_BASE_URL' ) ) {
            return trailingslashit( esc_url_raw( SENTIENT_FORMS_ADMIN_ASSET_BASE_URL ) );
        }

        $filtered = apply_filters( 'sentient_forms_admin_asset_base_url', null );
        if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
            return trailingslashit( esc_url_raw( $filtered ) );
        }

        if ( $this->dev_base_url ) {
            return $this->dev_base_url;
        }

        $explicit_dev_host = $this->explicit_dev_host();
        if ( $explicit_dev_host ) {
            $detected = $this->maybe_detect_dev_server( $explicit_dev_host );
            if ( $detected ) {
                $this->dev_base_url = $detected;
                set_transient( self::DEV_TRANSIENT, untrailingslashit( $detected ), 5 * MINUTE_IN_SECONDS );
                return $this->dev_base_url;
            }
        }

        $cached = get_transient( self::DEV_TRANSIENT );
        if ( is_string( $cached ) && '' !== $cached && 'none' !== $cached ) {
            $this->dev_base_url = trailingslashit( $cached );
            return $this->dev_base_url;
        }
        if ( 'none' === $cached ) {
            return $default;
        }

        set_transient( self::DEV_TRANSIENT, 'none', MINUTE_IN_SECONDS );
        return $default;
    }

    private function maybe_detect_dev_server( string $host ): ?string
    {
        $host = trailingslashit( $host );
        $timeout = apply_filters( 'sentient_forms_admin_dev_timeout', 1.5 );
        $show_probe_notice = (bool) apply_filters( 'sentient_forms_admin_show_dev_probe_failures', false );

        $response = wp_remote_get( $host, [
            'timeout' => $timeout,
            'headers' => [ 'Accept' => 'text/html' ],
        ] );

        if ( is_wp_error( $response ) ) {
            if ( $show_probe_notice ) {
                $this->dev_notice_message = $response->get_error_message();
            }
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 400 ) {
            return trailingslashit( esc_url_raw( $host ) );
        }

        if ( $show_probe_notice ) {
            $this->dev_notice_message = sprintf( 'Dev server responded with HTTP %d.', $code );
        }
        return null;
    }

    /**
     * Return a dev host only if explicitly provided via env/const/filter; otherwise null.
     */
    private function explicit_dev_host(): ?string
    {
        if ( defined( 'SENTIENT_FORMS_ADMIN_DEV_HOST' ) && is_string( constant( 'SENTIENT_FORMS_ADMIN_DEV_HOST' ) ) ) {
            return constant( 'SENTIENT_FORMS_ADMIN_DEV_HOST' );
        }

        $env = getenv( 'SENTIENT_FORMS_ADMIN_DEV_HOST' );
        if ( $env ) {
            return (string) $env;
        }

        $filtered = apply_filters( 'sentient_forms_admin_dev_host', null );
        if ( is_string( $filtered ) && '' !== trim( $filtered ) ) {
            return $filtered;
        }

        return null;
    }

    private function development_asset_overrides_allowed(): bool
    {
        $allowed = false;

        if ( defined( 'SENTIENT_FORMS_ENABLE_ADMIN_DEV_ASSETS' ) ) {
            $allowed = true === constant( 'SENTIENT_FORMS_ENABLE_ADMIN_DEV_ASSETS' );
        }

        $env = getenv( 'SENTIENT_FORMS_ENABLE_ADMIN_DEV_ASSETS' );
        if ( false !== $env && '' !== trim( (string) $env ) ) {
            $allowed = in_array( strtolower( trim( (string) $env ) ), [ '1', 'true', 'yes', 'on' ], true );
        }

        return (bool) apply_filters( 'sentient_forms_admin_dev_assets_enabled', $allowed );
    }

    public function is_dev_mode(): bool
    {
        return null !== $this->dev_base_url;
    }

    public function get_dev_notice(): ?string
    {
        return $this->dev_notice_message;
    }
}
