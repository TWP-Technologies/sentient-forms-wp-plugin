<?php
/**
 * Dev-only mail capture for local E2E verification.
 *
 * Records wp_mail() attempts in an option and short-circuits delivery so tests
 * can assert notification behavior without relying on SMTP infrastructure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sentient_forms_dev_mail_capture_env = function_exists( 'wp_get_environment_type' )
	? wp_get_environment_type()
	: ( getenv( 'WP_ENVIRONMENT_TYPE' ) ?: null );

if ( 'development' !== $sentient_forms_dev_mail_capture_env ) {
	return;
}

if ( getenv( 'SENTIENT_E2E_DISABLE_MAIL_CAPTURE' ) ) {
	return;
}

if ( ! function_exists( 'sentient_forms_dev_mail_capture_option_name' ) ) {
	/**
	 * Get the option name used for dev mail capture.
	 */
	function sentient_forms_dev_mail_capture_option_name(): string {
		return 'sentient_forms_dev_mail_capture';
	}
}

if ( ! function_exists( 'sentient_forms_dev_mail_capture_normalize_list' ) ) {
	/**
	 * Normalize wp_mail() list-like values to a string array.
	 *
	 * @param mixed $value Raw wp_mail() list value.
	 * @return array<int, string>
	 */
	function sentient_forms_dev_mail_capture_normalize_list( $value ): array {
		if ( empty( $value ) ) {
			return [];
		}

		if ( is_string( $value ) ) {
			return [ $value ];
		}

		if ( is_array( $value ) ) {
			$normalized = [];
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$normalized[] = (string) $item;
				}
			}

			return $normalized;
		}

		if ( is_scalar( $value ) ) {
			return [ (string) $value ];
		}

		return [];
	}
}

if ( ! function_exists( 'sentient_forms_dev_mail_capture_get_records' ) ) {
	/**
	 * Get captured mail records.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function sentient_forms_dev_mail_capture_get_records(): array {
		$records = get_option( sentient_forms_dev_mail_capture_option_name(), [] );

		return is_array( $records ) ? array_values( $records ) : [];
	}
}

if ( ! function_exists( 'sentient_forms_dev_mail_capture_clear_records' ) ) {
	/**
	 * Clear captured mail records.
	 */
	function sentient_forms_dev_mail_capture_clear_records(): void {
		update_option( sentient_forms_dev_mail_capture_option_name(), [], false );
	}
}

if ( ! function_exists( 'sentient_forms_dev_mail_capture_store_record' ) ) {
	/**
	 * Append a captured wp_mail() attempt.
	 *
	 * @param array<string, mixed> $atts   Raw wp_mail() arguments.
	 * @param mixed                $return Incoming pre_wp_mail filter value.
	 */
	function sentient_forms_dev_mail_capture_store_record( array $atts, $return ): void {
		$records   = sentient_forms_dev_mail_capture_get_records();
		$records[] = [
			'captured_at_gmt' => gmdate( 'c' ),
			'preexisting_return' => is_scalar( $return ) ? (string) $return : null,
			'to'              => sentient_forms_dev_mail_capture_normalize_list( $atts['to'] ?? [] ),
			'subject'         => isset( $atts['subject'] ) && is_scalar( $atts['subject'] ) ? (string) $atts['subject'] : '',
			'message'         => isset( $atts['message'] ) && is_scalar( $atts['message'] ) ? (string) $atts['message'] : '',
			'headers'         => sentient_forms_dev_mail_capture_normalize_list( $atts['headers'] ?? [] ),
			'attachments'     => sentient_forms_dev_mail_capture_normalize_list( $atts['attachments'] ?? [] ),
		];

		if ( count( $records ) > 100 ) {
			$records = array_slice( $records, -100 );
		}

		update_option( sentient_forms_dev_mail_capture_option_name(), $records, false );
	}
}

add_filter(
	'pre_wp_mail',
	static function ( $return, $atts ) {
		sentient_forms_dev_mail_capture_store_record( is_array( $atts ) ? $atts : [], $return );

		// Returning a non-null value short-circuits wp_mail() and reports success.
		return true;
	},
	5,
	2
);
