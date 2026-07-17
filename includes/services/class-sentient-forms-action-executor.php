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
	const DEFAULT_ASYNC_DELAY_SECONDS = 60;
	const DEFAULT_ASYNC_MAX_WAIT_SECONDS = DAY_IN_SECONDS;
	const MIN_ASYNC_DELAY_SECONDS = 10;
	const MAX_ASYNC_DELAY_SECONDS = 3600;
	const MIN_ASYNC_MAX_WAIT_SECONDS = 43200;
	const MAX_ASYNC_MAX_WAIT_SECONDS = 604800;
	const INPUT_MAPPING_SOURCE_EXPLICIT = 'explicit_mapping';
	const INPUT_MAPPING_SOURCE_EXPLICIT_ALL = 'explicit_all';
	const INPUT_MAPPING_SOURCE_LEGACY = 'legacy_fallback_field_ids';

	private const LEGACY_ENTRY_METADATA_KEYS = array(
		'id',
		'form_id',
		'post_id',
		'date_created',
		'date_updated',
		'is_starred',
		'is_read',
		'ip',
		'source_url',
		'user_agent',
		'currency',
		'payment_status',
		'payment_date',
		'payment_amount',
		'payment_method',
		'transaction_id',
		'transaction_type',
		'is_fulfilled',
		'created_by',
		'status',
	);

	private Sentient_Forms_Plugin $plugin;
	private ?Sentient_Forms_Api_Client $client;
	private ?Sentient_Forms_Attachment_File_Ref_Builder $attachment_file_ref_builder;
	private array $execution_cache = array();

	public function __construct(
		Sentient_Forms_Plugin $plugin,
		?Sentient_Forms_Api_Client $client = null,
		?Sentient_Forms_Attachment_File_Ref_Builder $attachment_file_ref_builder = null
	) {
		$this->plugin = $plugin;
		$this->client = $client;
		$this->attachment_file_ref_builder = $attachment_file_ref_builder;
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
		$submission_token     = self::derive_submission_token( $form, $entry );
		$explicit_request_id  = isset( $context['execution_request_id'] ) && is_scalar( $context['execution_request_id'] )
			? sanitize_text_field( trim( (string) $context['execution_request_id'] ) )
			: '';
		$execution_request_id = '' !== $explicit_request_id
			? $explicit_request_id
			: self::resolve_execution_request_id( $central_action_id, $form, $entry, $context, $submission_token );
		$entry_id              = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		$cached_result         = $this->get_cached_execution_result( $execution_request_id, $entry_id, $context );

		if ( null !== $cached_result ) {
			return $cached_result;
		}

		$payload_data       = $this->build_execution_payload_data( $form, $entry, $context );
		$attachment_payload = $this->build_attachment_payload( $form, $entry, $context );

		$payload = array(
			'central_action_id'     => $central_action_id,
			'execution_request_id'  => $execution_request_id,
			'form_data_payload'     => $payload_data['form_data_payload'],
			'input_manifest'        => array_merge(
				$payload_data['input_manifest'],
				array(
					'attachment_manifest' => $attachment_payload['attachment_manifest'],
				)
			),
			'action_context'        => $this->build_action_context( $form, $entry, $context, $execution_request_id, $submission_token ),
			'file_refs'             => $attachment_payload['file_refs'],
		);

		if ( defined( 'SENTIENT_FORMS_DEBUG_CPS_PAYLOAD' ) && SENTIENT_FORMS_DEBUG_CPS_PAYLOAD ) {
			sentient_forms_debug_log(
				'Sentient Forms CPS execution payload prepared.',
				[
					'payload' => $payload,
				]
			);
		}

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

				// CPS processed this request successfully but we don't have the result cached.
				// Return the error WITHOUT caching so Action Scheduler can retry and
				// eventually find the successful cached result after finalize completes.
				return new WP_Error(
					'duplicate_execution',
					__( 'Sentient Forms already processed this submission.', 'sentient-forms' ),
					$response->get_error_data()
				);
			}

			$this->cache_execution_result( $execution_request_id, $response, $entry_id, $context );

			return $response;
		}

		$response = $this->ensure_validation_payload( $response, $central_action_id );
		$response = $this->ensure_evaluation_payload( $response, $central_action_id, $context );
		$this->cache_execution_result( $execution_request_id, $response, $entry_id, $context );

		return $response;
	}

	/**
	 * Queue a configured action for asynchronous CPS execution.
	 *
	 * @param string $central_action_id CPS central action identifier.
	 * @param array  $form              Gravity Forms form array.
	 * @param array  $entry             Gravity Forms entry array.
	 * @param array  $context           Additional metadata to send to CPS.
	 * @param array  $async_options     Optional async delay/max wait overrides.
	 *
	 * @return array|WP_Error Queue response payload or WP_Error on failure.
	 */
	public function enqueue_async(
		string $central_action_id,
		array $form,
		array $entry,
		array $context = array(),
		array $async_options = array()
	) {
		$proxy_key = $this->plugin->get_proxy_api_key();
		if ( empty( $proxy_key ) ) {
			return new WP_Error(
				'cps_missing_proxy_key',
				__( 'Sentient Forms proxy API key is missing.', 'sentient-forms' )
			);
		}

		$client           = $this->client ?? $this->plugin->get_cps_api_client();
		$submission_token = self::derive_submission_token( $form, $entry );
		$execution_request_id = isset( $context['execution_request_id'] )
			? sanitize_text_field( (string) $context['execution_request_id'] )
			: self::resolve_execution_request_id(
				$central_action_id,
				$form,
				$entry,
				$context,
				$submission_token
			);

		$payload_data       = $this->build_execution_payload_data( $form, $entry, $context );
		$attachment_payload = $this->build_attachment_payload( $form, $entry, $context );

		$payload = array(
			'central_action_id'    => $central_action_id,
			'execution_request_id' => $execution_request_id,
			'form_data_payload'    => $payload_data['form_data_payload'],
			'input_manifest'       => array_merge(
				$payload_data['input_manifest'],
				array(
					'attachment_manifest' => $attachment_payload['attachment_manifest'],
				)
			),
			'action_context'       => $this->build_action_context( $form, $entry, $context, $execution_request_id, $submission_token ),
			'async_options'        => $this->normalize_async_options( $context, $async_options ),
			'callback'             => [
				'enabled' => true,
			],
			'file_refs'            => $attachment_payload['file_refs'],
		);

		if ( defined( 'SENTIENT_FORMS_DEBUG_CPS_PAYLOAD' ) && SENTIENT_FORMS_DEBUG_CPS_PAYLOAD ) {
			sentient_forms_debug_log(
				'Sentient Forms CPS async execution payload prepared.',
				[
					'payload' => $payload,
				]
			);
		}

		return $client->post(
			'/actions/execute-async',
			$payload,
			array(
				'bearer_token' => $proxy_key,
			)
		);
	}

	/**
	 * Execute a real-time suggestion run via the CPS suggest endpoint.
	 *
	 * @param string $central_action_id CPS central action identifier.
	 * @param array  $form              Gravity Forms form array.
	 * @param array  $entry             Known field values keyed by field/input id.
	 * @param array  $context           Runtime context merged into action_context.
	 * @param array  $suggestion_context Suggestion context payload.
	 *
	 * @return array|WP_Error Suggestion response payload or WP_Error on failure.
	 */
	public function suggest(
		string $central_action_id,
		array $form,
		array $entry,
		array $context,
		array $suggestion_context
	) {
		$proxy_key = $this->plugin->get_proxy_api_key();
		if ( empty( $proxy_key ) ) {
			return new WP_Error(
				'cps_missing_proxy_key',
				__( 'Sentient Forms proxy API key is missing.', 'sentient-forms' )
			);
		}

		$client           = $this->client ?? $this->plugin->get_cps_api_client();
		$submission_token = self::derive_submission_token( $form, $entry );
		$execution_request_id = isset( $context['execution_request_id'] )
			? sanitize_text_field( (string) $context['execution_request_id'] )
			: 'rt-' . str_replace( '-', '', wp_generate_uuid4() );

		$payload_data = $this->build_execution_payload_data( $form, $entry, $context );
		$attachment_payload = $this->build_attachment_payload( $form, $entry, $context );
		$normalized_suggestion_context = $this->normalize_suggestion_context( $suggestion_context, $form, $entry );

		$payload = array(
			'central_action_id'    => $central_action_id,
			'execution_request_id' => $execution_request_id,
			'form_data_payload'    => $payload_data['form_data_payload'],
			'input_manifest'       => array_merge(
				$payload_data['input_manifest'],
				array(
					'attachment_manifest' => $attachment_payload['attachment_manifest'],
				)
			),
			'action_context'       => $this->build_action_context(
				$form,
				$entry,
				$context,
				$execution_request_id,
				$submission_token
			),
			'suggestion_context'   => $normalized_suggestion_context,
			'file_refs'            => $attachment_payload['file_refs'],
		);

		if ( defined( 'SENTIENT_FORMS_DEBUG_CPS_PAYLOAD' ) && SENTIENT_FORMS_DEBUG_CPS_PAYLOAD ) {
			sentient_forms_debug_log(
				'Sentient Forms CPS suggest payload prepared.',
				[
					'payload' => $payload,
				]
			);
		}

		return $client->post(
			'/actions/suggest',
			$payload,
			array(
				'bearer_token' => $proxy_key,
			)
		);
	}

	private function ensure_evaluation_payload( array $response, string $central_action_id, array $context ): array {
		if ( isset( $response['evaluation_payload'] ) && is_array( $response['evaluation_payload'] ) ) {
			return $response;
		}

		$payload = array(
			'central_action_id' => $central_action_id,
			'result_data'      => $response['result_data'] ?? array(),
			'meta'             => $response['meta'] ?? array(),
		);

		if ( ! empty( $context['action_id'] ) ) {
			$payload['action_id'] = $context['action_id'];
		}

		if ( ! empty( $context['action_name_label'] ) ) {
			$payload['action_name_label'] = $context['action_name_label'];
		}

		if ( ! empty( $context['form_id'] ) ) {
			$payload['form_id'] = $context['form_id'];
		}

		if ( ! empty( $context['entry_id'] ) ) {
			$payload['entry_id'] = $context['entry_id'];
		}

		if ( ! empty( $context['form_source'] ) ) {
			$payload['form_source'] = $context['form_source'];
		}

		$response['evaluation_payload'] = $payload;
		return $response;
	}

	/**
	 * Bridge content_validation_v1 structured output into the top-level validation shape
	 * consumed by the Gravity Forms validation adapter.
	 *
	 * @param array  $response          CPS response payload.
	 * @param string $central_action_id CPS central action identifier.
	 *
	 * @return array
	 */
	private function ensure_validation_payload( array $response, string $central_action_id ): array {
		if ( 'content_validation_v1' !== sanitize_key( $central_action_id ) ) {
			return $response;
		}


		// A provider-supplied top-level bridge is not itself proof of parsing.
		unset( $response['validation'] );

		$result_data = isset( $response['result_data'] ) && is_array( $response['result_data'] )
			? $response['result_data']
			: array();

		$validation = $this->extract_content_validation_payload( $result_data );
		if ( null === $validation ) {
			return $response;
		}

		$response['validation'] = $validation;

		return $response;
	}

	/**
	 * Extract a normalized validation payload from content_validation_v1 result data.
	 *
	 * @param array<string, mixed> $result_data CPS result_data payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extract_content_validation_payload( array $result_data ): ?array {
		if (
			true !== ( $result_data['structured_output_valid'] ?? null )
			|| ! isset( $result_data['structured_output'] )
			|| ! is_array( $result_data['structured_output'] )
		) {
			return null;
		}

		$candidate = $result_data['structured_output'];
		if ( ! Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid( 'content_validation_v1', $candidate ) ) {
			return null;
		}

		return $this->normalize_content_validation_payload( $candidate );
	}

	/**
	 * Normalize a content-validation payload into the adapter contract.
	 *
	 * @param array<string, mixed> $candidate Candidate payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normalize_content_validation_payload( array $candidate ): ?array {
		if ( ! array_key_exists( 'is_valid', $candidate ) ) {
			return null;
		}

		$validation = array(
			'is_valid' => rest_sanitize_boolean( $candidate['is_valid'] ),
			'message'  => isset( $candidate['message'] ) && is_scalar( $candidate['message'] )
				? sanitize_text_field( (string) $candidate['message'] )
				: '',
			'fields'   => array(),
		);

		if ( isset( $candidate['fields'] ) && is_array( $candidate['fields'] ) ) {
			foreach ( $candidate['fields'] as $field ) {
				if ( ! is_array( $field ) || ! isset( $field['field_id'] ) || ! is_scalar( $field['field_id'] ) ) {
					continue;
				}

				$field_id = sanitize_text_field( (string) $field['field_id'] );
				if ( '' === $field_id ) {
					continue;
				}

				$validation['fields'][] = array(
					'field_id' => $field_id,
					'is_valid' => array_key_exists( 'is_valid', $field ) ? rest_sanitize_boolean( $field['is_valid'] ) : true,
					'message'  => isset( $field['message'] ) && is_scalar( $field['message'] )
						? sanitize_text_field( (string) $field['message'] )
						: '',
				);
			}
		}

		return $validation;
	}

	/**
	 * Normalize suggestion_context payload for CPS suggest endpoint.
	 *
	 * @param array $suggestion_context Raw context from runtime request.
	 * @param array $form               Gravity Forms form array.
	 * @param array $entry              Known field values.
	 *
	 * @return array<string,mixed>
	 */
	private function normalize_suggestion_context( array $suggestion_context, array $form, array $entry ): array {
		$form_id = isset( $suggestion_context['form_id'] )
			? sanitize_text_field( (string) $suggestion_context['form_id'] )
			: ( isset( $form['id'] ) ? (string) $form['id'] : '' );
		$source = isset( $suggestion_context['source'] )
			? sanitize_key( (string) $suggestion_context['source'] )
			: 'gravity_forms';
		$current_page_index = isset( $suggestion_context['current_page_index'] )
			? max( 1, (int) $suggestion_context['current_page_index'] )
			: 1;
		$total_pages = isset( $suggestion_context['total_pages'] )
			? max( 1, (int) $suggestion_context['total_pages'] )
			: 1;

		$visible_field_ids = array();
		if ( isset( $suggestion_context['visible_field_ids'] ) && is_array( $suggestion_context['visible_field_ids'] ) ) {
			foreach ( $suggestion_context['visible_field_ids'] as $field_id ) {
				if ( ! is_scalar( $field_id ) ) {
					continue;
				}
				$normalized = sanitize_text_field( (string) $field_id );
				if ( '' !== $normalized ) {
					$visible_field_ids[] = $normalized;
				}
			}
		}
		$visible_field_ids = array_values( array_unique( $visible_field_ids ) );
		if ( empty( $visible_field_ids ) ) {
			$visible_field_ids = array_values(
				array_filter(
					array_map(
						static fn( $key ): string => sanitize_text_field( (string) $key ),
						array_keys( $entry )
					),
					static fn( string $field_id ): bool => '' !== $field_id
				)
			);
		}

		$checkpoint_field_ids = array();
		if ( isset( $suggestion_context['checkpoint_field_ids'] ) && is_array( $suggestion_context['checkpoint_field_ids'] ) ) {
			foreach ( $suggestion_context['checkpoint_field_ids'] as $field_id ) {
				if ( ! is_scalar( $field_id ) ) {
					continue;
				}
				$normalized = sanitize_text_field( (string) $field_id );
				if ( '' !== $normalized ) {
					$checkpoint_field_ids[] = $normalized;
				}
			}
		}
		$checkpoint_field_ids = array_values( array_unique( $checkpoint_field_ids ) );

		$future_field_manifest = array();
		if ( isset( $suggestion_context['future_field_manifest'] ) && is_array( $suggestion_context['future_field_manifest'] ) ) {
			foreach ( $suggestion_context['future_field_manifest'] as $future_field ) {
				if ( ! is_array( $future_field ) ) {
					continue;
				}
				$field_id = isset( $future_field['field_id'] )
					? sanitize_text_field( (string) $future_field['field_id'] )
					: '';
				$field_type = isset( $future_field['type'] )
					? sanitize_key( (string) $future_field['type'] )
					: '';
				$page_index = isset( $future_field['page_index'] )
					? max( 1, (int) $future_field['page_index'] )
					: 1;
				if ( '' === $field_id || '' === $field_type ) {
					continue;
				}
				$manifest_entry = array(
					'field_id'   => $field_id,
					'type'       => $field_type,
					'page_index' => $page_index,
				);
				if ( isset( $future_field['label'] ) && is_scalar( $future_field['label'] ) ) {
					$manifest_entry['label'] = sanitize_text_field( (string) $future_field['label'] );
				}
				$future_field_manifest[] = $manifest_entry;
			}
		}

		$hidden_field_exposure_mode = isset( $suggestion_context['hidden_field_exposure_mode'] ) && is_scalar( $suggestion_context['hidden_field_exposure_mode'] )
			? sanitize_key( (string) $suggestion_context['hidden_field_exposure_mode'] )
			: 'label_hidden';
		if ( ! in_array( $hidden_field_exposure_mode, [ 'omit_hidden', 'label_hidden', 'label_hidden_value', 'label_value' ], true ) ) {
			$hidden_field_exposure_mode = 'label_hidden';
		}

		$supplemental_field_context = array();
		if ( isset( $suggestion_context['supplemental_field_context'] ) && is_array( $suggestion_context['supplemental_field_context'] ) ) {
			foreach ( $suggestion_context['supplemental_field_context'] as $field_context ) {
				if ( ! is_array( $field_context ) ) {
					continue;
				}
				$field_id = isset( $field_context['field_id'] ) && is_scalar( $field_context['field_id'] )
					? sanitize_text_field( (string) $field_context['field_id'] )
					: '';
				if ( '' === $field_id ) {
					continue;
				}
				$entry = array(
					'field_id'   => $field_id,
					'label'      => isset( $field_context['label'] ) && is_scalar( $field_context['label'] ) ? sanitize_text_field( (string) $field_context['label'] ) : '',
					'type'       => isset( $field_context['type'] ) && is_scalar( $field_context['type'] ) ? sanitize_key( (string) $field_context['type'] ) : '',
					'page_index' => isset( $field_context['page_index'] ) ? max( 1, (int) $field_context['page_index'] ) : 1,
				);
				if ( array_key_exists( 'hidden', $field_context ) ) {
					$entry['hidden'] = rest_sanitize_boolean( $field_context['hidden'] );
				}
				if ( array_key_exists( 'value', $field_context ) ) {
					if ( is_array( $field_context['value'] ) ) {
						$entry['value'] = array_values(
							array_filter(
								array_map(
									static fn( mixed $item ): string => is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '',
									$field_context['value']
								),
								static fn( string $item ): bool => '' !== $item
							)
						);
					} else {
						$entry['value'] = is_scalar( $field_context['value'] )
							? sanitize_text_field( (string) $field_context['value'] )
							: '';
					}
				}
				$supplemental_field_context[] = $entry;
			}
		}

			$all_known_field_values = isset( $suggestion_context['all_known_field_values'] ) && is_array( $suggestion_context['all_known_field_values'] )
				? $suggestion_context['all_known_field_values']
				: $entry;
			$request_reason = isset( $suggestion_context['request_reason'] ) && is_scalar( $suggestion_context['request_reason'] )
				? sanitize_key( (string) $suggestion_context['request_reason'] )
				: 'field_change';
			$panel_state = isset( $suggestion_context['panel_state'] ) && is_array( $suggestion_context['panel_state'] )
				? $suggestion_context['panel_state']
				: array(
					'suggestions'       => array(),
					'virtual_questions' => array(),
				);

			return array(
				'form_id'               => $form_id,
				'source'                => $source,
				'request_reason'        => $request_reason,
				'current_page_index'    => $current_page_index,
				'total_pages'           => $total_pages,
				'visible_field_ids'     => $visible_field_ids,
				'checkpoint_field_ids'  => $checkpoint_field_ids,
				'all_known_field_values'=> $all_known_field_values,
				'future_field_manifest' => $future_field_manifest,
				'hidden_field_exposure_mode' => $hidden_field_exposure_mode,
				'supplemental_field_context' => $supplemental_field_context,
				'panel_state'           => $panel_state,
			);
		}

	/**
	 * Resolve async queue options from linkage settings and explicit overrides.
	 *
	 * @param array $context       Runtime context including optional settings.
	 * @param array $async_options Explicit delay/max wait overrides.
	 *
	 * @return array{delay_seconds:int,max_wait_seconds:int}
	 */
	private function normalize_async_options( array $context, array $async_options = array() ): array {
		$batch_settings = isset( $context['settings']['batch_settings'] ) && is_array( $context['settings']['batch_settings'] )
			? $context['settings']['batch_settings']
			: array();

		$delay_seconds = isset( $async_options['delay_seconds'] )
			? (int) $async_options['delay_seconds']
			: (int) ( $batch_settings['delay_seconds'] ?? self::DEFAULT_ASYNC_DELAY_SECONDS );
		$max_wait_seconds = isset( $async_options['max_wait_seconds'] )
			? (int) $async_options['max_wait_seconds']
			: (int) ( $batch_settings['max_wait_seconds'] ?? self::DEFAULT_ASYNC_MAX_WAIT_SECONDS );

		return array(
			'delay_seconds'    => max( self::MIN_ASYNC_DELAY_SECONDS, min( self::MAX_ASYNC_DELAY_SECONDS, $delay_seconds ) ),
			'max_wait_seconds' => max( self::MIN_ASYNC_MAX_WAIT_SECONDS, min( self::MAX_ASYNC_MAX_WAIT_SECONDS, $max_wait_seconds ) ),
		);
	}

	/**
	 * Build execution payload data including form payload and input manifest metadata.
	 *
	 * @param array $form    Gravity Forms form array.
	 * @param array $entry   Gravity Forms entry array.
	 * @param array $context Runtime execution context.
	 *
	 * @return array{form_data_payload:array,input_manifest:array}
	 */
	private function build_execution_payload_data( array $form, array $entry, array $context ): array {
		$input_mapping_context = $this->resolve_input_mapping_context( $context, $entry );
		$form_data_payload     = $this->build_payload_from_entry( $form, $entry, $input_mapping_context['input_mapping'] );
		$input_manifest        = $this->build_input_manifest(
			$input_mapping_context['source'],
			$input_mapping_context['input_mapping'],
			$entry,
			$form_data_payload
		);

		return array(
			'form_data_payload' => $form_data_payload,
			'input_manifest'    => $input_manifest,
		);
	}

	/**
	 * Resolve input mapping from runtime context using precedence:
	 * 1) context.input_mapping
	 * 2) context.settings.input_mapping
	 * 3) context.settings.settings.input_mapping
	 * 4) legacy field-id-only fallback
	 *
	 * @param array $context Runtime context.
	 * @param array $entry   Gravity Forms entry array.
	 *
	 * @return array{input_mapping:array,source:string}
	 */
	private function resolve_input_mapping_context( array $context, array $entry ): array {
		$candidates = array();

		if ( isset( $context['input_mapping'] ) && is_array( $context['input_mapping'] ) ) {
			$candidates[] = $context['input_mapping'];
		}

		if ( isset( $context['settings'] ) && is_array( $context['settings'] ) ) {
			if ( isset( $context['settings']['input_mapping'] ) && is_array( $context['settings']['input_mapping'] ) ) {
				$candidates[] = $context['settings']['input_mapping'];
			}

			if (
				isset( $context['settings']['settings'] )
				&& is_array( $context['settings']['settings'] )
				&& isset( $context['settings']['settings']['input_mapping'] )
				&& is_array( $context['settings']['settings']['input_mapping'] )
			) {
				$candidates[] = $context['settings']['settings']['input_mapping'];
			}
		}

		foreach ( $candidates as $candidate ) {
			$sanitized_candidate = $this->sanitize_input_mapping( $candidate );
			if ( null !== $sanitized_candidate ) {
				$source = 'all' === $sanitized_candidate['mode']
					? self::INPUT_MAPPING_SOURCE_EXPLICIT_ALL
					: self::INPUT_MAPPING_SOURCE_EXPLICIT;

				return array(
					'input_mapping' => $sanitized_candidate,
					'source'        => $source,
				);
			}
		}

		return array(
			'input_mapping' => array(
				'mode'             => 'selected',
				'field_ids'        => $this->infer_legacy_field_ids( $entry ),
				'include_metadata' => false,
			),
			'source'        => self::INPUT_MAPPING_SOURCE_LEGACY,
		);
	}

	/**
	 * Sanitize input mapping settings and normalize shape.
	 *
	 * @param mixed $mapping Raw mapping value from linkage settings.
	 *
	 * @return array|null Sanitized mapping or null when invalid.
	 */
	private function sanitize_input_mapping( $mapping ): ?array {
		if ( ! is_array( $mapping ) ) {
			return null;
		}

		$mode = isset( $mapping['mode'] ) ? sanitize_key( (string) $mapping['mode'] ) : 'selected';
		if ( ! in_array( $mode, array( 'all', 'selected', 'exclude' ), true ) ) {
			$mode = 'selected';
		}

		$field_ids = array();
		if ( isset( $mapping['field_ids'] ) && is_array( $mapping['field_ids'] ) ) {
			foreach ( $mapping['field_ids'] as $field_id ) {
				if ( ! is_scalar( $field_id ) ) {
					continue;
				}

				$field_id = sanitize_text_field( (string) $field_id );
				if ( '' !== $field_id ) {
					$field_ids[] = $field_id;
				}
			}
		}

		$include_metadata = isset( $mapping['include_metadata'] )
			? rest_sanitize_boolean( $mapping['include_metadata'] )
			: true;

		return array(
			'mode'             => $mode,
			'field_ids'        => array_values( array_unique( $field_ids ) ),
			'include_metadata' => (bool) $include_metadata,
		);
	}

	/**
	 * Infer entry field IDs for legacy mappings that lack explicit input_mapping.
	 *
	 * @param array $entry Gravity Forms entry array.
	 *
	 * @return array<int,string>
	 */
	private function infer_legacy_field_ids( array $entry ): array {
		$scalar_entry = $this->extract_scalar_entry_values( $entry );
		$field_ids    = array();

		foreach ( array_keys( $scalar_entry ) as $key ) {
			$key_string = (string) $key;
			if ( preg_match( '/^\d+(?:\.\d+)?$/', $key_string ) || preg_match( '/^field_\d+(?:_\d+)?$/', $key_string ) ) {
				$field_ids[] = $key_string;
			}
		}

		if ( ! empty( $field_ids ) ) {
			return array_values( array_unique( $field_ids ) );
		}

		foreach ( array_keys( $scalar_entry ) as $key ) {
			$key_string = (string) $key;
			if ( in_array( $key_string, self::LEGACY_ENTRY_METADATA_KEYS, true ) ) {
				continue;
			}
			$field_ids[] = $key_string;
		}

		return array_values( array_unique( $field_ids ) );
	}

	/**
	 * Extract scalar entry values and sanitize them as strings.
	 *
	 * @param array $entry Raw Gravity Forms entry array.
	 *
	 * @return array<string,string>
	 */
	private function extract_scalar_entry_values( array $entry ): array {
		$scalar_entry = array();

		foreach ( $entry as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$scalar_entry[ (string) $key ] = sanitize_text_field( (string) $value );
		}

		return $scalar_entry;
	}

	/**
	 * Build an input manifest describing the effective payload selection.
	 *
	 * @param string $mapping_source    Mapping resolution source.
	 * @param array  $input_mapping     Effective input mapping.
	 * @param array  $entry             Raw Gravity Forms entry.
	 * @param array  $form_data_payload Effective payload sent to CPS.
	 *
	 * @return array
	 */
	private function build_input_manifest(
		string $mapping_source,
		array $input_mapping,
		array $entry,
		array $form_data_payload
	): array {
		$scalar_entry       = $this->extract_scalar_entry_values( $entry );
		$applied_entry_keys = isset( $form_data_payload['entry'] ) && is_array( $form_data_payload['entry'] )
			? array_values( array_map( 'strval', array_keys( $form_data_payload['entry'] ) ) )
			: array();
		$requested_field_ids = isset( $input_mapping['field_ids'] ) && is_array( $input_mapping['field_ids'] )
			? array_values( array_map( 'strval', $input_mapping['field_ids'] ) )
			: array();
		$mode = isset( $input_mapping['mode'] ) ? (string) $input_mapping['mode'] : 'selected';

		return array(
			'mapping_source'        => $mapping_source,
			'mode'                  => $mode,
			'requested_field_ids'   => $requested_field_ids,
			'applied_entry_keys'    => $applied_entry_keys,
			'include_metadata'      => ! empty( $input_mapping['include_metadata'] ),
			'full_entry_sent'       => 'all' === $mode,
			'entry_key_count_before' => count( $scalar_entry ),
			'entry_key_count_after' => count( $applied_entry_keys ),
		);
	}

	/**
	 * Build the form data payload from entry, optionally filtered by input_mapping.
	 *
	 * CA-MAP-001: Supports field selection modes:
	 * - 'all': Send all fields (default for backward compatibility)
	 * - 'selected': Only send specified field_ids
	 * - 'exclude': Send all fields except specified field_ids
	 *
	 * @param array      $form          Gravity Forms form array.
	 * @param array      $entry         Gravity Forms entry array.
	 * @param array|null $input_mapping Optional input mapping configuration from settings.
	 *
	 * @return array Form data payload for CPS.
	 */
	private function build_payload_from_entry( array $form, array $entry, ?array $input_mapping = null ): array {
		$field_values = array();
		$scalar_entry = $this->extract_scalar_entry_values( $entry );

		// Determine input mapping settings
		$mode            = $input_mapping['mode'] ?? 'selected';
		$field_ids       = $input_mapping['field_ids'] ?? array();
		$include_metadata = isset( $input_mapping['include_metadata'] )
			? (bool) $input_mapping['include_metadata']
			: false;

		if ( ! empty( $scalar_entry ) ) {
			foreach ( $scalar_entry as $key => $value ) {
				if ( 'selected' === $mode ) {
					// Explicitly selecting no fields should send no fields.
					if ( empty( $field_ids ) || ! in_array( (string) $key, $field_ids, true ) ) {
						continue;
					}
				} elseif ( 'exclude' === $mode && in_array( (string) $key, $field_ids, true ) ) {
					continue;
				}

				// mode === 'all' includes all scalar entry values.
				$field_values[ $key ] = $value;
			}
		}

		$payload = array( 'entry' => $field_values );

		// Include form metadata if enabled
		if ( $include_metadata ) {
			$payload['form'] = array(
				'id'    => isset( $form['id'] ) ? (string) $form['id'] : null,
				'title' => $form['title'] ?? '',
			);
		}

		return $payload;
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

		$action_context = array_merge( $defaults, $context );

		if ( isset( $action_context['form_id'] ) ) {
			$action_context['form_id'] = (string) $action_context['form_id'];
		}

		if ( isset( $action_context['entry_id'] ) ) {
			$action_context['entry_id'] = (string) $action_context['entry_id'];
		}

		return $action_context;
	}

	public static function generate_execution_request_id( string $central_action_id, array $form, array $entry, array $context = array() ): string {
		$submission_token = self::derive_submission_token( $form, $entry );

		return self::resolve_execution_request_id( $central_action_id, $form, $entry, $context, $submission_token );
	}

	private static function resolve_execution_request_id( string $central_action_id, array $form, array $entry, array $context, string $submission_token ): string {
		$generated_request_id = self::build_execution_request_id( $central_action_id, $form, $entry, $context, $submission_token );
		$execution_request_id = $generated_request_id;

		if ( self::is_local_or_development_environment() ) {
			$forced = get_option( 'sentient_forms_forced_execution_request_id', '' );
			if ( is_scalar( $forced ) ) {
				$forced = sanitize_text_field( (string) $forced );
				if ( '' !== $forced ) {
					$execution_request_id = $forced;
				}
			}
		}

		$execution_request_id = apply_filters(
			'sentient_forms_execution_request_id',
			$execution_request_id,
			$central_action_id,
			$form,
			$entry,
			$context
		);

		if ( ! is_string( $execution_request_id ) ) {
			return $generated_request_id;
		}

		$execution_request_id = sanitize_text_field( trim( $execution_request_id ) );
		if ( '' === $execution_request_id ) {
			return $generated_request_id;
		}

		return $execution_request_id;
	}

	private static function is_local_or_development_environment(): bool {
		$environment = function_exists( 'wp_get_environment_type' )
			? wp_get_environment_type()
			: getenv( 'WP_ENVIRONMENT_TYPE' );
		$environment = strtolower( trim( (string) $environment ) );

		return in_array( $environment, array( 'local', 'development' ), true );
	}

	private static function derive_submission_token( array $form, array $entry ): string {
		if ( isset( $entry['submission_uuid'] ) && is_scalar( $entry['submission_uuid'] ) ) {
			$submission_uuid = strtolower( sanitize_text_field( (string) $entry['submission_uuid'] ) );
			if ( wp_is_uuid( $submission_uuid ) ) {
				return 'submission:' . $submission_uuid;
			}
		}

		if ( isset( $entry['id'] ) && $entry['id'] ) {
			return 'entry:' . (string) $entry['id'];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
		if ( isset( $_POST['gform_unique_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Gravity Forms owns frontend submission verification before this hook.
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

		if ( ! self::submission_token_has_stable_uuid( $submission_token ) && ! empty( $entry ) ) {
			$components[] = hash( 'sha256', wp_json_encode( $entry ) );
		}

		return substr( hash( 'sha256', implode( '|', $components ) ), 0, 32 );
	}

	private static function submission_token_has_stable_uuid( string $submission_token ): bool {
		$prefix = 'submission:';
		if ( ! str_starts_with( $submission_token, $prefix ) ) {
			return false;
		}

		return wp_is_uuid( substr( $submission_token, strlen( $prefix ) ) );
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

			// Fallback: check if finalize_async_success already saved a response
			// This catches cases where CPS succeeded but the primary cache wasn't written
			$last_response = gform_get_meta( $entry_id, 'sentient_forms_last_response' );
			if ( is_string( $last_response ) && ! empty( $last_response ) ) {
				$decoded = json_decode( $last_response, true );
				if ( is_array( $decoded ) && ! empty( $decoded['meta'] ) ) {
					$this->execution_cache[ $execution_request_id ] = $decoded;
					return $decoded;
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

	/**
	 * @return array{file_refs:array<int,array<string,mixed>>,attachment_manifest:array<string,mixed>}
	 */
	private function build_attachment_payload( array $form, array $entry, array $context ): array {
		$builder = $this->get_attachment_file_ref_builder();
		if ( null === $builder ) {
			return array(
				'file_refs'           => array(),
				'attachment_manifest' => array(
					'mapping_source'          => 'service_unavailable',
					'attachment_mode'         => 'none',
					'requested_gf_fields'     => array(),
					'requested_media_ids'     => array(),
					'max_files'               => 0,
					'resolved_file_ref_count' => 0,
					'drop_reasons'            => array(),
				),
			);
		}

		$payload = $builder->build_for_execution( $form, $entry, $context );
		$file_refs = isset( $payload['file_refs'] ) && is_array( $payload['file_refs'] )
			? array_values( $payload['file_refs'] )
			: array();
		$manifest = isset( $payload['attachment_manifest'] ) && is_array( $payload['attachment_manifest'] )
			? $payload['attachment_manifest']
			: array();

		return array(
			'file_refs'           => $file_refs,
			'attachment_manifest' => $manifest,
		);
	}

	private function get_attachment_file_ref_builder(): ?Sentient_Forms_Attachment_File_Ref_Builder {
		if ( null !== $this->attachment_file_ref_builder ) {
			return $this->attachment_file_ref_builder;
		}

		if ( ! class_exists( 'Sentient_Forms_Attachment_File_Ref_Builder' ) ) {
			return null;
		}

		$this->attachment_file_ref_builder = new Sentient_Forms_Attachment_File_Ref_Builder( $this->plugin );
		return $this->attachment_file_ref_builder;
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
