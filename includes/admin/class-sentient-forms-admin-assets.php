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

    /**
     * Retrieve the decoded Vite manifest.
     *
     * @return array
     * @throws WP_Error If manifest cannot be located or parsed.
     */
    public function get_manifest(): array {
        if ( null !== $this->manifest ) {
            return $this->manifest;
        }

        $manifest_path = $this->get_assets_path( 'manifest.json' );

        if ( ! file_exists( $manifest_path ) ) {
            throw new WP_Error( 'sentient_forms_manifest_missing', sprintf( 'Sentient Forms admin manifest missing: %s', $manifest_path ) );
        }

        $manifest_contents = file_get_contents( $manifest_path );

        if ( false === $manifest_contents ) {
            throw new WP_Error( 'sentient_forms_manifest_read_error', sprintf( 'Unable to read admin manifest: %s', $manifest_path ) );
        }

        $decoded = json_decode( $manifest_contents, true );

        if ( ! is_array( $decoded ) ) {
            throw new WP_Error( 'sentient_forms_manifest_decode_error', sprintf( 'Invalid admin manifest JSON: %s', $manifest_path ) );
        }

        $this->manifest = $decoded;

        return $this->manifest;
    }

    /**
     * Get entry metadata for the SPA bundle.
     *
     * @param string $entry
     *
     * @return array
     * @throws WP_Error When the specified entry is not present.
     */
    public function get_entry( string $entry = self::DEFAULT_ENTRY ): array {
        $manifest = $this->get_manifest();

        if ( ! isset( $manifest[ $entry ] ) ) {
            throw new WP_Error( 'sentient_forms_manifest_entry_missing', sprintf( 'Entry %s not found in admin manifest.', $entry ) );
        }

        return $manifest[ $entry ];
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
        $host = apply_filters( 'sentient_forms_admin_dev_host', 'http://localhost:5173/' );
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

    public function is_dev_mode(): bool {
        return null !== $this->dev_base_url;
    }

    public function get_dev_notice(): ?string {
        return $this->dev_notice_message;
    }
}
