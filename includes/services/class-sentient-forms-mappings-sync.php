<?php
/**
 * CPS Form Mappings Sync Service.
 *
 * Phase 7 CSM: Handles syncing form mappings between CPS and WordPress.
 *
 * @package Sentient_Forms
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Sentient_Forms_Mappings_Sync
 *
 * Syncs form mappings from CPS to local WordPress cache.
 * Provides offline fallback to cached mappings when CPS is unreachable.
 */
class Sentient_Forms_Mappings_Sync {

    private const CACHE_OPTION_KEY = 'sentient_forms_mappings_cache';
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    /**
     * Get CPS API client instance from the main plugin.
     *
     * @return Sentient_Forms_Api_Client|null
     */
    private function get_cps_client(): ?Sentient_Forms_Api_Client {
        if ( ! class_exists( 'Sentient_Forms_Plugin' ) ) {
            return null;
        }
        return Sentient_Forms_Plugin::instance()->get_cps_api_client();
    }

    /**
     * Get API key for CPS authentication.
     *
     * @return string|null
     */
    private function get_api_key(): ?string {
        if ( ! class_exists( 'Sentient_Forms_Plugin' ) ) {
            return null;
        }
        return Sentient_Forms_Plugin::instance()->get_proxy_api_key();
    }

    /**
     * Fetch mappings from CPS, with local cache fallback.
     *
     * @return array List of mappings.
     */
    public function fetch_mappings(): array {
        // Try CPS first
        $cps_mappings = $this->fetch_from_cps();

        if ( ! is_wp_error( $cps_mappings ) && is_array( $cps_mappings ) ) {
            // Update local cache
            $this->update_cache( $cps_mappings );
            return $cps_mappings;
        }

        // Fall back to local cache
        return $this->get_cached_mappings();
    }

    /**
     * Fetch template mappings (is_template = true) from CPS.
     *
     * @return array List of template mappings.
     */
    public function fetch_templates(): array {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return [];
        }

        $response = $client->get( '/mappings/templates', [
            'bearer_token' => $api_key,
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        return $response['mappings'] ?? [];
    }

    /**
     * Create a new mapping in CPS.
     *
     * @param array $mapping_data Mapping data to create.
     * @return array|WP_Error Created mapping or error.
     */
    public function create_mapping( array $mapping_data ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->post( '/mappings', $mapping_data, [
            'bearer_token' => $api_key,
        ] );
    }

    /**
     * Clone a template mapping to a new site/form.
     *
     * @param string $template_id Template mapping ID.
     * @param array  $clone_data  Clone request data.
     * @return array|WP_Error Cloned mapping or error.
     */
    public function clone_template( string $template_id, array $clone_data ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->post( "/mappings/{$template_id}/clone", $clone_data, [
            'bearer_token' => $api_key,
        ] );
    }

    /**
     * Request a dependency workflow plan from CPS for a specific form.
     *
     * @param string $form_source_slug Form adapter slug.
     * @param int    $form_id          Form identifier.
     * @param string $hook_scope       Hook scope ("all" or hook slug).
     *
     * @return array|WP_Error
     */
    public function plan_workflow( string $form_source_slug, int $form_id, string $hook_scope = 'all' ) {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        return $client->post(
            '/workflows/plan',
            [
                'form_source' => sanitize_key( $form_source_slug ),
                'form_id'     => absint( $form_id ),
                'hook_scope'  => sanitize_text_field( $hook_scope ),
            ],
            [
                'bearer_token' => $api_key,
            ],
        );
    }

    /**
     * Fetch mappings from CPS.
     *
     * @return array|WP_Error Mappings array or error.
     */
    private function fetch_from_cps() {
        $client = $this->get_cps_client();
        $api_key = $this->get_api_key();

        if ( ! $client || ! $api_key ) {
            return new WP_Error( 'cps_unavailable', 'CPS is not configured.' );
        }

        $response = $client->get( '/mappings', [
            'bearer_token' => $api_key,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response['mappings'] ?? [];
    }

    /**
     * Get cached mappings from WordPress options.
     *
     * @return array
     */
    private function get_cached_mappings(): array {
        $cache = get_option( self::CACHE_OPTION_KEY, [] );

        if ( ! is_array( $cache ) || empty( $cache['mappings'] ) ) {
            return [];
        }

        return $cache['mappings'];
    }

    /**
     * Update local cache with fresh mappings.
     *
     * @param array $mappings Mappings to cache.
     */
    private function update_cache( array $mappings ): void {
        update_option( self::CACHE_OPTION_KEY, [
            'mappings'   => $mappings,
            'updated_at' => time(),
        ], false );
    }

    /**
     * Check if cache is stale.
     *
     * @return bool
     */
    public function is_cache_stale(): bool {
        $cache = get_option( self::CACHE_OPTION_KEY, [] );

        if ( ! is_array( $cache ) || empty( $cache['updated_at'] ) ) {
            return true;
        }

        return ( time() - $cache['updated_at'] ) > self::CACHE_TTL_SECONDS;
    }

    /**
     * Invalidate the local cache.
     */
    public function invalidate_cache(): void {
        delete_option( self::CACHE_OPTION_KEY );
    }
}
