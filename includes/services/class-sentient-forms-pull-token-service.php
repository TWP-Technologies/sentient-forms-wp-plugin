<?php
/**
 * Signed pull-token issuance and validation for attachment fetches.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sentient_Forms_Pull_Token_Service {
	private const TOKEN_VERSION = 1;
	private const TOKEN_TTL_SECONDS = 300;
	private const CLOCK_SKEW_SECONDS = 30;
	private const TICKET_TRANSIENT_PREFIX = 'sentient_forms_pull_ticket_';
	private const USED_TRANSIENT_PREFIX = 'sentient_forms_pull_used_';

	private Sentient_Forms_Plugin $plugin;

	public function __construct( Sentient_Forms_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * @param array<string, mixed> $ticket
	 * @return array{token:string,expires_at:string,token_id:string}
	 */
	public function issue_token( array $ticket ): array {
		$token_id = wp_generate_uuid4();
		$exp      = time() + self::TOKEN_TTL_SECONDS;

		$record = array(
			'token_id'          => $token_id,
			'license_id'        => sanitize_text_field( (string) ( $ticket['license_id'] ?? '' ) ),
			'site_id'           => sanitize_text_field( (string) ( $ticket['site_id'] ?? '' ) ),
			'local_site_identifier' => sanitize_text_field( (string) ( $ticket['local_site_identifier'] ?? '' ) ),
			'file_ref_id'       => sanitize_text_field( (string) ( $ticket['file_ref_id'] ?? '' ) ),
			'source_type'       => sanitize_key( (string) ( $ticket['source_type'] ?? '' ) ),
			'locator_type'      => sanitize_key( (string) ( $ticket['locator_type'] ?? '' ) ),
			'locator_value'     => sanitize_text_field( (string) ( $ticket['locator_value'] ?? '' ) ),
			'filename'          => sanitize_file_name( (string) ( $ticket['filename'] ?? '' ) ),
			'content_type'      => sanitize_text_field( (string) ( $ticket['content_type'] ?? '' ) ),
			'size_bytes'        => max( 0, (int) ( $ticket['size_bytes'] ?? 0 ) ),
			'hash_sha256'       => isset( $ticket['hash_sha256'] ) ? strtolower( sanitize_text_field( (string) $ticket['hash_sha256'] ) ) : null,
			'expires_at_epoch'  => $exp,
			'execution_request_id' => sanitize_text_field( (string) ( $ticket['execution_request_id'] ?? '' ) ),
			'issued_at_epoch'   => time(),
		);

		set_transient(
			$this->ticket_transient_key( $token_id ),
			$record,
			self::TOKEN_TTL_SECONDS + self::CLOCK_SKEW_SECONDS
		);

		$payload = array(
			'v'   => self::TOKEN_VERSION,
			'tid' => $token_id,
			'exp' => $exp,
		);

		$encoded_payload = self::base64url_encode( wp_json_encode( $payload ) );
		$signature       = self::base64url_encode(
			hash_hmac( 'sha256', $encoded_payload, $this->get_signing_secret(), true )
		);
		$token = $encoded_payload . '.' . $signature;

		return array(
			'token'      => $token,
			'expires_at' => gmdate( DATE_ATOM, $exp ),
			'token_id'   => $token_id,
		);
	}

	/**
	 * Consume a token and return the pull ticket.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function consume_token( string $token ) {
		$parsed = $this->parse_and_verify_token( $token );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$token_id = (string) $parsed['tid'];
		$ticket   = get_transient( $this->ticket_transient_key( $token_id ) );
		if ( ! is_array( $ticket ) ) {
			return $this->unauthorized_error();
		}

		if ( ! $this->validate_ticket_site_context( $ticket ) ) {
			return $this->unauthorized_error();
		}

		$expires_at = isset( $ticket['expires_at_epoch'] ) ? (int) $ticket['expires_at_epoch'] : 0;
		if ( $expires_at <= 0 || time() > ( $expires_at + self::CLOCK_SKEW_SECONDS ) ) {
			return $this->unauthorized_error();
		}

		$used_key = $this->used_transient_key( $token_id );
		if ( false !== get_transient( $used_key ) ) {
			return $this->unauthorized_error();
		}

		set_transient(
			$used_key,
			array(
				'used_at' => time(),
			),
			self::TOKEN_TTL_SECONDS + self::CLOCK_SKEW_SECONDS
		);

		delete_transient( $this->ticket_transient_key( $token_id ) );

		return $ticket;
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	private function parse_and_verify_token( string $token ) {
		$token = trim( $token );
		if ( '' === $token ) {
			return $this->unauthorized_error();
		}

		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return $this->unauthorized_error();
		}

		list( $encoded_payload, $encoded_signature ) = $parts;
		if ( '' === $encoded_payload || '' === $encoded_signature ) {
			return $this->unauthorized_error();
		}

		$expected_signature = self::base64url_encode(
			hash_hmac( 'sha256', $encoded_payload, $this->get_signing_secret(), true )
		);
		if ( ! hash_equals( $expected_signature, $encoded_signature ) ) {
			return $this->unauthorized_error();
		}

		$decoded_payload = self::base64url_decode( $encoded_payload );
		if ( false === $decoded_payload ) {
			return $this->unauthorized_error();
		}

		$payload = json_decode( $decoded_payload, true );
		if ( ! is_array( $payload ) ) {
			return $this->unauthorized_error();
		}

		$version = isset( $payload['v'] ) ? (int) $payload['v'] : 0;
		if ( self::TOKEN_VERSION !== $version ) {
			return $this->unauthorized_error();
		}

		$token_id = sanitize_text_field( (string) ( $payload['tid'] ?? '' ) );
		$exp      = isset( $payload['exp'] ) ? (int) $payload['exp'] : 0;
		if ( '' === $token_id || $exp <= 0 ) {
			return $this->unauthorized_error();
		}

		if ( time() > ( $exp + self::CLOCK_SKEW_SECONDS ) ) {
			return $this->unauthorized_error();
		}

		return array(
			'tid' => $token_id,
			'exp' => $exp,
		);
	}

	/**
	 * @param array<string,mixed> $ticket
	 */
	private function validate_ticket_site_context( array $ticket ): bool {
		$license_data = $this->plugin->get_license_data();
		$current_license_id = sanitize_text_field( (string) ( $license_data['license_id'] ?? '' ) );
		$current_site_id    = sanitize_text_field( (string) ( $license_data['site_id'] ?? '' ) );
		$current_local_id   = sanitize_text_field( (string) $this->plugin->get_local_site_identifier() );

		$ticket_local_id = sanitize_text_field( (string) ( $ticket['local_site_identifier'] ?? '' ) );
		if ( '' === $ticket_local_id || ! hash_equals( $current_local_id, $ticket_local_id ) ) {
			return false;
		}

		$ticket_license_id = sanitize_text_field( (string) ( $ticket['license_id'] ?? '' ) );
		if ( '' !== $ticket_license_id && ! hash_equals( $current_license_id, $ticket_license_id ) ) {
			return false;
		}

		$ticket_site_id = sanitize_text_field( (string) ( $ticket['site_id'] ?? '' ) );
		if ( '' !== $ticket_site_id && ! hash_equals( $current_site_id, $ticket_site_id ) ) {
			return false;
		}

		return true;
	}

	private function get_signing_secret(): string {
		$license_data = $this->plugin->get_license_data();
		$proxy_key    = sanitize_text_field( (string) ( $license_data['proxy_api_key'] ?? '' ) );
		$seed         = implode(
			'|',
			array(
				wp_salt( 'auth' ),
				wp_salt( 'secure_auth' ),
				$this->plugin->get_local_site_identifier(),
				$proxy_key,
			)
		);

		return hash( 'sha256', $seed );
	}

	private function ticket_transient_key( string $token_id ): string {
		return self::TICKET_TRANSIENT_PREFIX . md5( $token_id );
	}

	private function used_transient_key( string $token_id ): string {
		return self::USED_TRANSIENT_PREFIX . md5( $token_id );
	}

	private function unauthorized_error(): WP_Error {
		return new WP_Error(
			'sf_pull_unauthorized',
			__( 'Unauthorized pull token.', 'sentient-forms' ),
			array( 'status' => 401 )
		);
	}

	private static function base64url_encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	/**
	 * @return string|false
	 */
	private static function base64url_decode( string $value ) {
		$padded = $value;
		$remainder = strlen( $padded ) % 4;
		if ( 0 !== $remainder ) {
			$padded .= str_repeat( '=', 4 - $remainder );
		}

		return base64_decode( strtr( $padded, '-_', '+/' ), true );
	}
}
