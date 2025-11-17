<?php
/**
 * Telemetry opt-in management for Sentient Forms.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sentient_Forms_Telemetry_Service {
	private Sentient_Forms_Plugin $plugin;
	private Sentient_Forms_Api_Client $client;

	public function __construct( Sentient_Forms_Plugin $plugin, ?Sentient_Forms_Api_Client $client = null ) {
		$this->plugin = $plugin;
		$this->client = $client ?? $plugin->get_cps_api_client();
	}

	public function get_settings(): array {
		return $this->plugin->get_telemetry_settings();
	}

	public function update_and_sync( bool $opt_in, string $actor_hint ): array | WP_Error {
		$settings                     = $this->plugin->get_telemetry_settings();
		$settings['telemetry_opt_in'] = $opt_in;
		$settings['updated_at']       = current_time( 'mysql', true );
		$settings['last_error']       = null;
		$this->plugin->set_telemetry_settings( $settings );

		$proxy_key = $this->plugin->get_proxy_api_key();
		if ( empty( $proxy_key ) ) {
			return new WP_Error(
				'cps_missing_proxy_key',
				__( 'Sentient Forms proxy key is missing; activate your license before enabling telemetry.', 'sentient-forms' )
			);
		}

		$response = $this->client->put(
			'/sites/telemetry',
			[
				'telemetry_opt_in' => $opt_in,
				'actor_hint'       => $actor_hint,
			],
			[
				'bearer_token' => $proxy_key,
			]
		);

		if ( is_wp_error( $response ) ) {
			$settings['last_error'] = $response->get_error_message();
			$this->plugin->set_telemetry_settings( $settings );
			return $response;
		}

		$settings['telemetry_opt_in']  = isset( $response['telemetry_opt_in'] ) ? (bool) $response['telemetry_opt_in'] : $opt_in;
		$settings['remote_updated_at'] = isset( $response['updated_at'] ) ? sanitize_text_field( (string) $response['updated_at'] ) : null;
		$settings['synced_at']         = current_time( 'mysql', true );
		$settings['last_error']        = null;

		$this->plugin->set_telemetry_settings( $settings );

		return $settings;
	}
}
