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

    /**
     * Retrieve the decoded Vite manifest.
     *
     * @return array|WP_Error
     */
    public function get_manifest() {
        if ( null !== $this->manifest ) {
            return $this->manifest;
        }

        $manifest_path = $this->get_assets_path( 'manifest.json' );

        if ( ! file_exists( $manifest_path ) ) {
            return new WP_Error( 'sentient_forms_manifest_missing', sprintf( 'Sentient Forms admin manifest missing: %s', $manifest_path ) );
        }

        $manifest_contents = file_get_contents( $manifest_path );

        if ( false === $manifest_contents ) {
            return new WP_Error( 'sentient_forms_manifest_read_error', sprintf( 'Unable to read admin manifest: %s', $manifest_path ) );
        }

        $decoded = json_decode( $manifest_contents, true );

        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'sentient_forms_manifest_decode_error', sprintf( 'Invalid admin manifest JSON: %s', $manifest_path ) );
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
    public function get_entry( string $entry = self::DEFAULT_ENTRY ) {
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
            return $manifest[ $entry ];
        }

        if ( $this->dev_base_url ) {
            // In dev we don't have hashed entries; fall back to requested path.
            return [ 'file' => ltrim( $entry, '/' ), 'css' => [] ];
        }

        return new WP_Error( 'sentient_forms_manifest_entry_missing', sprintf( 'Entry %s not found in admin manifest.', $entry ) );
    }

    /**
     * Resolve a plugin-relative asset URL.
     */
    public function get_asset_url( string $relative ): string {
        return trailingslashit( $this->get_assets_base_url() ) . ltrim( $relative, '/' );
    }

    /**
     * Resolve absolute filesystem path for assets/dist.
     */
    private function get_assets_path( string $relative ): string {
        return trailingslashit( SENTIENT_FORMS_PLUGIN_DIR ) . 'assets/dist/' . ltrim( $relative, '/' );
    }

    public function get_sveltekit_runtime_key(): string {
        if ( null !== $this->sveltekit_runtime_key ) {
            return $this->sveltekit_runtime_key;
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

    private function get_assets_base_url(): string {
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

        // If an explicit dev host is provided via env/const/filter, trust it and bypass the cached "none".
        $explicit_dev_host = $this->explicit_dev_host();
        if ( $explicit_dev_host ) {
            $this->dev_base_url = trailingslashit( esc_url_raw( $explicit_dev_host ) );
            set_transient( self::DEV_TRANSIENT, untrailingslashit( $this->dev_base_url ), 5 * MINUTE_IN_SECONDS );
            return $this->dev_base_url;
        }

        $default = trailingslashit( SENTIENT_FORMS_PLUGIN_URL ) . 'assets/dist/';

        $cached = get_transient( self::DEV_TRANSIENT );
        if ( is_string( $cached ) && '' !== $cached && 'none' !== $cached ) {
            $this->dev_base_url = trailingslashit( $cached );
            return $this->dev_base_url;
        }
        if ( 'none' === $cached ) {
            return $default;
        }

        $detected = $this->maybe_detect_dev_server();
        if ( $detected ) {
            $this->dev_base_url = $detected;
            set_transient( self::DEV_TRANSIENT, untrailingslashit( $detected ), 5 * MINUTE_IN_SECONDS );
            return $this->dev_base_url;
        }

        set_transient( self::DEV_TRANSIENT, 'none', MINUTE_IN_SECONDS );
        return $default;
    }

    private function maybe_detect_dev_server(): ?string {
        $host = $this->dev_host_candidate();
        $host = trailingslashit( $host );
        $timeout = apply_filters( 'sentient_forms_admin_dev_timeout', 1.5 );

        $response = wp_remote_get( $host, [
            'timeout' => $timeout,
            'headers' => [ 'Accept' => 'text/html' ],
        ] );

        if ( is_wp_error( $response ) ) {
            $this->dev_notice_message = $response->get_error_message();
            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 400 ) {
            return trailingslashit( esc_url_raw( $host ) );
        }

        $this->dev_notice_message = sprintf( 'Dev server responded with HTTP %d.', $code );
        return null;
    }

    /**
     * Determine which dev host to probe.
     */
    private function dev_host_candidate(): string {
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

        return 'http://localhost:5173/';
    }

    /**
     * Return a dev host only if explicitly provided via env/const/filter; otherwise null.
     */
    private function explicit_dev_host(): ?string {
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

    public function is_dev_mode(): bool {
        return null !== $this->dev_base_url;
    }

    public function get_dev_notice(): ?string {
        return $this->dev_notice_message;
    }
}
