<?php
/**
 * Sentient Forms CPS Action Executor.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes configured Sentient Forms actions against the CPS `/v1/actions/execute` endpoint.
 */
class Sentient_Forms_Action_Executor {
	const TRANSIENT_PREFIX = 'sentient_forms_exec_';
	const TRANSIENT_TTL    = 600; // 10 minutes.

	private Sentient_Forms_Plugin $plugin;
	private ?Sentient_Forms_Api_Client $client;
	private array $execution_cache = array();

	public function __construct( Sentient_Forms_Plugin $plugin, ?Sentient_Forms_Api_Client $client = null ) {
		$this->plugin = $plugin;
		$this->client = $client;
	}

	/**
	 * Execute a configured action via the CPS API.
	 *
	 * @param string $central_action_id CPS central action identifier.
	 * @param array  $form              Gravity Forms form array.
	 * @param array  $entry             Gravity Forms entry array (may be partial during validation).
	 * @param array  $context           Additional metadata to send to CPS.
	 *
	 * @return array|WP_Error CPS response data or WP_Error on failure.
	 */
	public function execute( string $central_action_id, array $form, array $entry, array $context = array() ) {
		$proxy_key = $this->plugin->get_proxy_api_key();
		if ( empty( $proxy_key ) ) {
			return new WP_Error(
				'cps_missing_proxy_key',
				__( 'Sentient Forms proxy API key is missing.', 'sentient-forms' )
			);
		}

		$client                = $this->client ?? $this->plugin->get_cps_api_client();
		$submission_token      = self::derive_submission_token( $form, $entry );
		$execution_request_id  = self::build_execution_request_id( $central_action_id, $form, $entry, $context, $submission_token );
		$entry_id              = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		$cached_result         = $this->get_cached_execution_result( $execution_request_id, $entry_id, $context );

		if ( null !== $cached_result ) {
			return $cached_result;
		}

		$payload = array(
			'central_action_id'     => $central_action_id,
			'execution_request_id'  => $execution_request_id,
			'form_data_payload'     => $this->build_payload_from_entry( $form, $entry ),
			'action_context'        => $this->build_action_context( $form, $entry, $context, $execution_request_id, $submission_token ),
		);

		$response = $client->post(
			'/actions/execute',
			$payload,
			array(
				'bearer_token' => $proxy_key,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( 'duplicate_execution' === $response->get_error_code() ) {
				$duplicate = $this->get_cached_execution_result( $execution_request_id, $entry_id, $context );
				if ( null !== $duplicate ) {
					return $duplicate;
				}

				$response = new WP_Error(
					'duplicate_execution',
					__( 'Sentient Forms already processed this submission.', 'sentient-forms' ),
					$response->get_error_data()
				);
			}

			$this->cache_execution_result( $execution_request_id, $response, $entry_id, $context );

			return $response;
		}

		$this->cache_execution_result( $execution_request_id, $response, $entry_id, $context );

		return $response;
	}

	private function build_payload_from_entry( array $form, array $entry ): array {
		$field_values = array();

		if ( ! empty( $entry ) ) {
			foreach ( $entry as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$field_values[ $key ] = sanitize_text_field( (string) $value );
				}
			}
		}

		return array(
			'form'  => array(
				'id'    => $form['id'] ?? null,
				'title' => $form['title'] ?? '',
			),
			'entry' => $field_values,
		);
	}

	private function build_action_context( array $form, array $entry, array $context, string $execution_request_id, string $submission_token ): array {
		$defaults = array(
			'form_id'   => isset( $form['id'] ) ? (string) $form['id'] : '',
			'source'    => 'gravity_forms',
			'execution_request_id' => $execution_request_id,
			'submission_token'     => $submission_token,
		);

		if ( isset( $entry['id'] ) ) {
			$defaults['entry_id'] = (string) $entry['id'];
		}

		if ( isset( $form['title'] ) ) {
			$defaults['form_title'] = sanitize_text_field( $form['title'] );
		}

		return array_merge( $defaults, $context );
	}

	public static function generate_execution_request_id( string $central_action_id, array $form, array $entry, array $context = array() ): string {
		$submission_token = self::derive_submission_token( $form, $entry );

		return self::build_execution_request_id( $central_action_id, $form, $entry, $context, $submission_token );
	}

	private static function derive_submission_token( array $form, array $entry ): string {
		if ( isset( $entry['id'] ) && $entry['id'] ) {
			return 'entry:' . (string) $entry['id'];
		}

		if ( isset( $_POST['gform_unique_id'] ) ) {
			$unique_id = sanitize_text_field( wp_unslash( (string) $_POST['gform_unique_id'] ) );
			if ( ! empty( $unique_id ) ) {
				return 'submission:' . $unique_id;
			}
		}

		$form_id      = isset( $form['id'] ) ? (string) $form['id'] : '';
		$current_user = get_current_user_id();
		$payload_hash = hash(
			'sha256',
			wp_json_encode(
				array(
					'form_id' => $form_id,
					'entry'   => $entry,
					'user'    => $current_user,
				)
			)
		);

		return 'hash:' . $payload_hash;
	}

	private static function build_execution_request_id( string $central_action_id, array $form, array $entry, array $context, string $submission_token ): string {
		$components = array(
			strtolower( trim( $central_action_id ) ),
			$submission_token,
		);

		if ( isset( $context['hook'] ) ) {
			$components[] = (string) $context['hook'];
		}

		if ( isset( $context['action_id'] ) ) {
			$components[] = (string) $context['action_id'];
		}

		if ( isset( $form['id'] ) ) {
			$components[] = (string) $form['id'];
		}

		if ( ! empty( $entry ) ) {
			$components[] = hash( 'sha256', wp_json_encode( $entry ) );
		}

		return substr( hash( 'sha256', implode( '|', $components ) ), 0, 32 );
	}

	private function get_cached_execution_result( string $execution_request_id, int $entry_id, array $context ) {
		if ( isset( $this->execution_cache[ $execution_request_id ] ) ) {
			return $this->execution_cache[ $execution_request_id ];
		}

		if ( $entry_id > 0 && function_exists( 'gform_get_meta' ) ) {
			$state = gform_get_meta( $entry_id, 'sentient_forms_execution_state' );
			if ( is_array( $state ) ) {
				$key = $this->execution_state_key( $context );
				if ( isset( $state[ $key ] ) && ( $state[ $key ]['execution_request_id'] ?? '' ) === $execution_request_id ) {
					$result = $this->inflate_cached_result( $state[ $key ] );
					if ( null !== $result ) {
						$this->execution_cache[ $execution_request_id ] = $result;
						return $result;
					}
				}
			}
		}

		$transient = get_transient( $this->execution_transient_key( $execution_request_id ) );
		if ( false !== $transient ) {
			$decoded = json_decode( $transient, true );
			if ( is_array( $decoded ) && ( $decoded['execution_request_id'] ?? '' ) === $execution_request_id ) {
				$result = $this->inflate_cached_result( $decoded );
				if ( null !== $result ) {
					$this->execution_cache[ $execution_request_id ] = $result;
					return $result;
				}
			}
		}

		return null;
	}

	private function cache_execution_result( string $execution_request_id, $result, int $entry_id, array $context ): void {
		$this->execution_cache[ $execution_request_id ] = $result;
		$payload                                           = $this->flatten_result_for_storage( $result, $execution_request_id, $context );

		if ( $entry_id > 0 && function_exists( 'gform_get_meta' ) && function_exists( 'gform_update_meta' ) ) {
			$state = gform_get_meta( $entry_id, 'sentient_forms_execution_state' );
			if ( ! is_array( $state ) ) {
				$state = array();
			}

			$key          = $this->execution_state_key( $context );
			$state[ $key ] = $payload;

			gform_update_meta( $entry_id, 'sentient_forms_execution_state', $state );
		}

		set_transient(
			$this->execution_transient_key( $execution_request_id ),
			wp_json_encode( $payload ),
			self::TRANSIENT_TTL
		);
	}

	private function execution_state_key( array $context ): string {
		$action_id = isset( $context['action_id'] ) ? sanitize_key( (string) $context['action_id'] ) : 'action';
		$hook      = isset( $context['hook'] ) ? sanitize_key( (string) $context['hook'] ) : 'hook';

		return $action_id . '|' . $hook;
	}

	private function execution_transient_key( string $execution_request_id ): string {
		return self::TRANSIENT_PREFIX . md5( $execution_request_id );
	}

	private function flatten_result_for_storage( $result, string $execution_request_id, array $context ): array {
		if ( $result instanceof WP_Error ) {
			return array(
				'execution_request_id' => $execution_request_id,
				'type'                 => 'error',
				'code'                 => $result->get_error_code(),
				'message'              => $result->get_error_message(),
				'data'                 => $result->get_error_data(),
				'context_key'          => $this->execution_state_key( $context ),
			);
		}

		return array(
			'execution_request_id' => $execution_request_id,
			'type'                 => 'success',
			'payload'              => $result,
			'context_key'          => $this->execution_state_key( $context ),
		);
	}

	private function inflate_cached_result( array $payload ) {
		if ( ( $payload['type'] ?? '' ) === 'error' ) {
			$code    = $payload['code'] ?? 'sentient_forms_execution_error';
			$message = $payload['message'] ?? __( 'Sentient Forms action failed to execute.', 'sentient-forms' );
			$data    = isset( $payload['data'] ) ? $payload['data'] : array();

			return new WP_Error( $code, $message, $data );
		}

		return isset( $payload['payload'] ) && is_array( $payload['payload'] ) ? $payload['payload'] : null;
	}
}
