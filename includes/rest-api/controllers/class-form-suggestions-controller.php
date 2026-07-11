<?php
/**
 * REST API controller for real-time form suggestions.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sentient_Forms_Form_Suggestions_Controller extends Sentient_Forms_Abstract_Base_Controller {
	protected string $rest_base = '(?P<form_source_slug>[a-z0-9_]+)/forms/(?P<form_id>[A-Za-z0-9._:%-]+)/actions';

	private const DEFAULT_RATE_LIMIT_PER_MINUTE = 60;
	private const DEFAULT_RUNTIME_CONFIG_RATE_LIMIT_PER_MINUTE = 300;
	private const DEFAULT_MAX_PAYLOAD_BYTES = 32768;
	private const FORM_ID_PATTERN = '[A-Za-z0-9._:%-]+';
	private const RUNTIME_CONFIG_TOKEN_HEADER = 'X-Sentient-Forms-Runtime-Config-Token';
	private const HIDDEN_FIELD_EXPOSURE_MODES = [
		'omit_hidden',
		'label_hidden',
		'label_hidden_value',
		'label_value',
	];
	private static bool $runtime_config_no_store_filter_registered = false;

	private Sentient_Forms_Plugin $plugin;
	private Sentient_Forms_Form_Adapter_Registry $adapter_registry;
	private Sentient_Forms_Form_Mappings_Repository $local_form_mappings;
	private Sentient_Forms_Local_Action_Execution_Service $local_execution;

	public function __construct(
		?Sentient_Forms_Form_Mappings_Repository $local_form_mappings = null,
		?Sentient_Forms_Local_Action_Execution_Service $local_execution = null
	) {
		parent::__construct();
		global $wpdb;

		$this->plugin = Sentient_Forms_Plugin::instance();
		$this->adapter_registry = $this->plugin->get_form_adapter_registry();
		$this->local_form_mappings = $local_form_mappings ?? new Sentient_Forms_Form_Mappings_Repository( $wpdb );
		$this->local_execution = $local_execution ?? new Sentient_Forms_Local_Action_Execution_Service(
			$this->local_form_mappings,
			null,
			null,
			null
		);

		if ( ! self::$runtime_config_no_store_filter_registered ) {
			add_filter( 'rest_post_dispatch', [ $this, 'maybe_add_runtime_config_no_store_headers' ], 10, 3 );
			self::$runtime_config_no_store_filter_registered = true;
		}
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/runtime-config',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_runtime_config' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'form_source_slug' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'form_id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => [ $this, 'sanitize_form_id_param' ],
							'validate_callback' => [ $this, 'validate_form_id_param' ],
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/suggest',
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'suggest' ],
					'permission_callback' => [ $this, 'permission_callback_public_nonce' ],
					'args'                => [
						'form_source_slug' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						],
						'form_id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => [ $this, 'sanitize_form_id_param' ],
							'validate_callback' => [ $this, 'validate_form_id_param' ],
						],
						'mapping_id' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'all_known_field_values' => [
							'required' => true,
							'type'     => 'object',
						],
						'visible_field_ids' => [
							'required' => true,
							'type'     => 'array',
						],
						'current_page_index' => [
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
							'total_pages' => [
								'required'          => true,
								'type'              => 'integer',
								'sanitize_callback' => 'absint',
							],
							'request_reason' => [
								'required'          => false,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_key',
							],
							'panel_state' => [
								'required' => false,
								'type'     => 'object',
							],
							'hidden_field_exposure_mode' => [
								'required'          => false,
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_key',
							],
							'supplemental_field_context' => [
								'required' => false,
								'type'     => 'array',
							],
						],
					],
				]
		);
	}

	public function sanitize_form_id_param( mixed $value ): string {
		return $this->normalize_provider_form_id( $value );
	}

	public function validate_form_id_param( mixed $value, WP_REST_Request $request, string $param ): bool | WP_Error {
		$form_id = $this->normalize_provider_form_id( $value );
		if ( '' === $form_id || strlen( $form_id ) > 100 || ! preg_match( '/^' . self::FORM_ID_PATTERN . '$/', $form_id ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Form ID must be a valid provider-native identifier.', 'sentient-forms' ), [ 'status' => 400 ] );
		}

		if ( 'gravity_forms' === sanitize_key( (string) $request->get_param( 'form_source_slug' ) ) && ! $this->is_positive_integer_form_id( $form_id ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Gravity Forms form ID must be a positive integer.', 'sentient-forms' ), [ 'status' => 400 ] );
		}

		return true;
	}

	private function normalize_provider_form_id( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return sanitize_text_field( rawurldecode( trim( (string) $value ) ) );
	}

	private function is_positive_integer_form_id( string $form_id ): bool {
		return ctype_digit( $form_id ) && absint( $form_id ) > 0;
	}

	public function get_runtime_config( WP_REST_Request $request ): WP_REST_Response | WP_Error {
		$form_source_slug = sanitize_key( (string) $request->get_param( 'form_source_slug' ) );
		$form_id = $this->normalize_provider_form_id( $request->get_param( 'form_id' ) );

		if ( 'gravity_forms' !== $form_source_slug ) {
			return $this->prepare_error_response(
				'rest_invalid_form_source',
				__( 'Real-time suggestions are currently available only for Gravity Forms.', 'sentient-forms' ),
				400
			);
		}

		if ( ! $this->is_positive_integer_form_id( $form_id ) ) {
			return $this->prepare_error_response(
				'rest_invalid_form_id',
				__( 'Invalid form ID provided.', 'sentient-forms' ),
				400
			);
		}

		$numeric_form_id = absint( $form_id );

		if ( ! $this->has_valid_runtime_config_token( $request, $form_source_slug, $numeric_form_id ) ) {
			return $this->prepare_error_response(
				'rest_invalid_runtime_config_token',
				__( 'Runtime config token is invalid or missing. Reload this page before trying again.', 'sentient-forms' ),
				403
			);
		}

		$rate_limit_result = $this->enforce_rate_limit( $numeric_form_id, 'runtime_config' );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		$adapter = $this->adapter_registry->get_adapter_by_id( $form_source_slug );
		if ( ! ( $adapter instanceof Sentient_Forms_Gravity_Forms_Adapter ) || ! $adapter->is_active() ) {
			return $this->prepare_error_response(
				'rest_form_source_unavailable',
				__( 'Requested form source is unavailable.', 'sentient-forms' ),
				503
			);
		}

		$runtime_config = $adapter->get_realtime_runtime_config( $numeric_form_id );
		if ( null === $runtime_config ) {
			return $this->prepare_error_response(
				'rest_realtime_runtime_config_not_found',
				__( 'Real-time runtime config was not found for this form.', 'sentient-forms' ),
				404
			);
		}

		$response = $this->prepare_item_for_response( $runtime_config );
		$this->add_runtime_config_no_store_headers( $response );

		return $response;
	}

	public function maybe_add_runtime_config_no_store_headers(
		WP_HTTP_Response $response,
		WP_REST_Server $server,
		WP_REST_Request $request
	): WP_HTTP_Response {
		unset( $server );

		if ( $this->is_runtime_config_request( $request ) ) {
			$this->add_runtime_config_no_store_headers( $response );
		}

		return $response;
	}

	private function is_runtime_config_request( WP_REST_Request $request ): bool {
		$route = $request->get_route();
		return 1 === preg_match(
			'#^/' . preg_quote( $this->namespace, '#' ) . '/[a-z0-9_]+/forms/' . self::FORM_ID_PATTERN . '/actions/runtime-config$#',
			$route
		);
	}

	private function add_runtime_config_no_store_headers( WP_HTTP_Response $response ): void {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
	}

	private function has_valid_runtime_config_token( WP_REST_Request $request, string $form_source_slug, int $form_id ): bool {
		$token = $request->get_header( self::RUNTIME_CONFIG_TOKEN_HEADER );
		if ( ! is_scalar( $token ) || '' === trim( (string) $token ) ) {
			$token = $request->get_param( 'runtime_config_token' );
		}

		return Sentient_Forms_Gravity_Forms_Adapter::is_valid_realtime_runtime_config_token(
			$form_source_slug,
			$form_id,
			is_scalar( $token ) ? sanitize_text_field( (string) $token ) : ''
		);
	}

	/**
	 * Public permission callback guarded by a form-scoped nonce.
	 */
	public function permission_callback_public_nonce( WP_REST_Request $request ): bool {
		$form_source_slug = sanitize_key( (string) $request->get_param( 'form_source_slug' ) );
		if ( '' !== $form_source_slug && 'gravity_forms' !== $form_source_slug ) {
			return true;
		}

		$form_id = $this->normalize_provider_form_id( $request->get_param( 'form_id' ) );
		if ( ! $this->is_positive_integer_form_id( $form_id ) ) {
			return false;
		}

		$nonce = (string) $request->get_header( 'X-Sentient-Forms-Suggest-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( 'sentient_forms_nonce' );
		}

		if ( '' === $nonce ) {
			return false;
		}

		$expected_action = 'sentient_forms_realtime_suggest_' . absint( $form_id );
		return (bool) wp_verify_nonce( $nonce, $expected_action );
	}

	public function suggest( WP_REST_Request $request ): WP_REST_Response | WP_Error {
		$form_source_slug = sanitize_key( (string) $request->get_param( 'form_source_slug' ) );
		$form_id = absint( $request->get_param( 'form_id' ) );
		$mapping_id = sanitize_text_field( (string) $request->get_param( 'mapping_id' ) );

		if ( 'gravity_forms' !== $form_source_slug ) {
			return $this->prepare_error_response(
				'rest_invalid_form_source',
				__( 'Real-time suggestions are currently available only for Gravity Forms.', 'sentient-forms' ),
				400
			);
		}

		$rate_limit_result = $this->enforce_rate_limit( $form_id );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result;
		}

		$payload_size_result = $this->validate_payload_size( $request );
		if ( is_wp_error( $payload_size_result ) ) {
			return $payload_size_result;
		}

		$adapter = $this->adapter_registry->get_adapter_by_id( $form_source_slug );
		if ( ! ( $adapter instanceof Sentient_Forms_Gravity_Forms_Adapter ) || ! $adapter->is_active() ) {
			return $this->prepare_error_response(
				'rest_form_source_unavailable',
				__( 'Requested form source is unavailable.', 'sentient-forms' ),
				503
			);
		}

		$form_settings = $adapter->get_form_settings( $form_id );
		$mapping = $this->find_realtime_mapping( $form_settings, $mapping_id );
		if ( null === $mapping ) {
			return $this->prepare_error_response(
				'rest_invalid_mapping',
				__( 'Mapping is not enabled for real-time suggestions.', 'sentient-forms' ),
				404
			);
		}

		$central_action_id = isset( $mapping['central_action_id'] ) && is_scalar( $mapping['central_action_id'] )
			? sanitize_text_field( (string) $mapping['central_action_id'] )
			: '';
		if ( '' === $central_action_id ) {
			return $this->prepare_error_response(
				'rest_missing_central_action',
				__( 'Mapping is missing a central action identifier.', 'sentient-forms' ),
				422
			);
		}

		$form = $adapter->get_form_data( $form_id );
		if ( ! is_array( $form ) ) {
			return $this->prepare_error_response(
				'rest_form_not_found',
				__( 'Requested form was not found.', 'sentient-forms' ),
				404
			);
		}

		$known_values = $this->sanitize_known_field_values( $request->get_param( 'all_known_field_values' ) );
		$suggestion_context = $this->build_suggestion_context( $request, $form_source_slug, $form_id, $mapping, $known_values, $form );
		$execution_known_values = $this->known_values_for_realtime_execution( $suggestion_context );
		$execution_request_id = isset( $request['execution_request_id'] ) && is_scalar( $request['execution_request_id'] )
			? sanitize_text_field( (string) $request['execution_request_id'] )
			: '';

		$context = [
			'hook'                  => 'real_time',
			'form_source'           => $form_source_slug,
			'source'                => $form_source_slug,
			'form_id'               => (string) $form_id,
			'action_id'             => isset( $mapping['id'] ) ? sanitize_text_field( (string) $mapping['id'] ) : $central_action_id,
			'action_name_label'     => isset( $mapping['action_name_label'] ) ? sanitize_text_field( (string) $mapping['action_name_label'] ) : $central_action_id,
			'action_type_indicator' => isset( $mapping['action_type_indicator'] ) ? sanitize_key( (string) $mapping['action_type_indicator'] ) : 'master',
			'local_mapping_id'      => $mapping_id,
			'mapping_id'            => $mapping_id,
				'settings'              => isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ? $mapping['settings'] : [],
				'suggestion_context'    => $suggestion_context,
			];
		if ( '' !== $execution_request_id ) {
			$context['execution_request_id'] = $execution_request_id;
		}

		$local_response = $this->execute_local_first_suggestion_mapping( $mapping, $form, $execution_known_values, $context, $suggestion_context );
		if ( is_wp_error( $local_response ) ) {
			return $local_response;
		}
		if ( null !== $local_response ) {
			return $this->prepare_item_for_response( $local_response );
		}

        return $this->prepare_error_response(
            'sentient_forms_local_mapping_required',
            __( 'This suggestion mapping predates local Action authority and must be saved again before it can run.', 'sentient-forms' ),
            409
		);
	}

	/**
	 * @param array<string,mixed>  $mapping
	 * @param array<string,mixed>  $form
	 * @param array<string,string> $known_values
	 * @param array<string,mixed>  $context
	 * @param array<string,mixed>  $suggestion_context
	 *
	 * @return array<string,mixed>|WP_Error|null
	 */
	private function execute_local_first_suggestion_mapping(
		array $mapping,
		array $form,
		array $known_values,
		array $context,
		array $suggestion_context
	): array | WP_Error | null {
		$local_mapping_id = $this->resolve_local_mapping_id( $mapping );
		if ( $local_mapping_id <= 0 ) {
			return null;
		}

		$local_mapping = $this->local_form_mappings->get( $local_mapping_id );
		if ( ! is_array( $local_mapping ) ) {
			return $this->prepare_error_response(
				'sentient_forms_local_mapping_not_found',
				__( 'Local real-time action mapping could not be found.', 'sentient-forms' ),
				404
			);
		}

		if (
			sanitize_key( (string) ( $local_mapping['form_source'] ?? '' ) ) !== sanitize_key( (string) ( $context['form_source'] ?? '' ) )
			|| sanitize_text_field( (string) ( $local_mapping['form_id'] ?? '' ) ) !== sanitize_text_field( (string) ( $context['form_id'] ?? '' ) )
			|| 'real_time' !== sanitize_key( (string) ( $local_mapping['execution_mode'] ?? $local_mapping['hook'] ?? '' ) )
		) {
			return $this->prepare_error_response(
				'sentient_forms_local_mapping_mismatch',
				__( 'Local real-time action mapping does not match this form.', 'sentient-forms' ),
				404
			);
		}

		$local_context = array_merge(
			$context,
			[
				'hook'                  => 'real_time',
				'local_form_mapping_id' => $local_mapping_id,
				'suggestion_context'    => $suggestion_context,
			]
		);

		$result = $this->local_execution->execute_mapping( $local_mapping_id, $form, $known_values, $local_context );
		if ( is_wp_error( $result ) ) {
			$status = $this->status_for_local_execution_error( $result );
			$result = $this->sanitize_public_local_execution_error( $result, $status );

			return $result;
		}

		return $this->format_local_suggestion_response( $result, $suggestion_context );
	}

	/**
	 * @param array<string,mixed> $mapping
	 */
	private function resolve_local_mapping_id( array $mapping ): int {
		if ( isset( $mapping['settings'] ) && is_array( $mapping['settings'] ) ) {
			$settings = $mapping['settings'];
			if ( isset( $settings['local_form_mapping_id'] ) && is_numeric( $settings['local_form_mapping_id'] ) ) {
				return absint( $settings['local_form_mapping_id'] );
			}
		}

		if ( isset( $mapping['local_form_mapping_id'] ) && is_numeric( $mapping['local_form_mapping_id'] ) ) {
			return absint( $mapping['local_form_mapping_id'] );
		}

		foreach ( [ 'id', 'local_mapping_id' ] as $key ) {
			if ( ! isset( $mapping[ $key ] ) || ! is_scalar( $mapping[ $key ] ) ) {
				continue;
			}

			$mapping_id = sanitize_text_field( (string) $mapping[ $key ] );
			if ( str_starts_with( $mapping_id, 'local_first_' ) ) {
				return absint( substr( $mapping_id, strlen( 'local_first_' ) ) );
			}
		}

		return 0;
	}

	/**
	 * @param array<string,mixed> $suggestion_context
	 *
	 * @return array<string,string>
	 */
	private function known_values_for_realtime_execution( array $suggestion_context ): array {
		$known_values = is_array( $suggestion_context['all_known_field_values'] ?? null )
			? $suggestion_context['all_known_field_values']
			: [];
		$filtered = [];

		foreach ( $known_values as $field_id => $value ) {
			if ( ! is_scalar( $field_id ) || ! is_scalar( $value ) ) {
				continue;
			}

			$key = sanitize_text_field( (string) $field_id );
			if ( '' === $key ) {
				continue;
			}

			$filtered[ $key ] = mb_substr( sanitize_textarea_field( (string) $value ), 0, 500 );
		}

		return $filtered;
	}

	private function status_for_local_execution_error( WP_Error $error ): int {
		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) ) {
			return (int) $data['status'];
		}

		return match ( $error->get_error_code() ) {
			'sentient_forms_structured_output_schema_invalid',
			'sentient_forms_structured_output_model_unsupported',
			'sentient_forms_structured_output_missing',
			'sentient_forms_structured_output_validation_failed',
			'sentient_forms_local_action_kind_unsupported',
			'sentient_forms_local_action_not_found',
			'sentient_forms_local_action_inactive',
			'sentient_forms_local_mapping_disabled',
			'sentient_forms_provider_not_supported_locally',
			'sentient_forms_lead_profile_required' => 422,
			default => 502,
		};
	}

	private function sanitize_public_local_execution_error( WP_Error $error, int $status ): WP_Error {
		$data = is_array( $error->get_error_data() ) ? $error->get_error_data() : [];
		$data['status'] = $status;

		if ( $this->is_structured_output_execution_error( $error ) ) {
			return new WP_Error(
				$error->get_error_code(),
				__( 'Suggestions are temporarily unavailable. Try again shortly.', 'sentient-forms' ),
				$data
			);
		}

		$error->add_data( $data );
		return $error;
	}

	private function is_structured_output_execution_error( WP_Error $error ): bool {
		return in_array(
			$error->get_error_code(),
			[
				'sentient_forms_structured_output_schema_invalid',
				'sentient_forms_structured_output_model_unsupported',
				'sentient_forms_structured_output_missing',
				'sentient_forms_structured_output_validation_failed',
			],
			true
		);
	}

	/**
	 * @param array<string,mixed> $result
	 * @param array<string,mixed> $suggestion_context
	 *
	 * @return array<string,mixed>
	 */
	private function format_local_suggestion_response( array $result, array $suggestion_context ): array {
		$structured = $this->extract_local_suggestion_payload( $result );

		return [
			'status'                => 'success',
			'suggestions'           => $this->normalize_local_suggestions( $structured['suggestions'] ?? [], $suggestion_context ),
			'virtual_questions'     => $this->normalize_local_virtual_questions( $structured['virtual_questions'] ?? [] ),
			'conditional_decisions' => $this->normalize_local_conditional_decisions( $structured['conditional_decisions'] ?? [] ),
			'meta'                  => array_filter(
				[
					'execution_request_id' => isset( $result['execution_request_id'] ) && is_scalar( $result['execution_request_id'] ) ? (string) $result['execution_request_id'] : null,
					'provider'             => isset( $result['provider'] ) && is_scalar( $result['provider'] ) ? (string) $result['provider'] : null,
					'model'                => isset( $result['model'] ) && is_scalar( $result['model'] ) ? (string) $result['model'] : null,
					'cached'               => ! empty( $result['cached'] ),
				],
				static fn( mixed $value ): bool => null !== $value
			),
		];
	}

	/**
	 * @param array<string,mixed> $result
	 *
	 * @return array<string,mixed>
	 */
	private function extract_local_suggestion_payload( array $result ): array {
		$candidates = [];
		if ( isset( $result['result'] ) && is_array( $result['result'] ) ) {
			$candidates[] = $result['result'];
			if ( isset( $result['result']['structured'] ) && is_array( $result['result']['structured'] ) ) {
				$candidates[] = $result['result']['structured'];
			}
		}
		if ( isset( $result['structured'] ) && is_array( $result['structured'] ) ) {
			$candidates[] = $result['structured'];
		}

		foreach ( $candidates as $candidate ) {
			if (
				array_key_exists( 'suggestions', $candidate )
				|| array_key_exists( 'virtual_questions', $candidate )
				|| array_key_exists( 'conditional_decisions', $candidate )
			) {
				return $candidate;
			}
		}

		return [];
	}

	/**
	 * @param mixed               $raw
	 * @param array<string,mixed> $suggestion_context
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_local_suggestions( mixed $raw, array $suggestion_context ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$visible_field_ids = array_fill_keys(
			array_values(
				array_filter(
					array_map(
						static fn( mixed $field_id ): string => is_scalar( $field_id ) ? sanitize_text_field( (string) $field_id ) : '',
						is_array( $suggestion_context['visible_field_ids'] ?? null ) ? $suggestion_context['visible_field_ids'] : []
					),
					static fn( string $field_id ): bool => '' !== $field_id
				)
			),
			true
		);
		$future_field_ids = [];
		foreach ( is_array( $suggestion_context['future_field_manifest'] ?? null ) ? $suggestion_context['future_field_manifest'] : [] as $future_field ) {
			if ( is_array( $future_field ) && isset( $future_field['field_id'] ) && is_scalar( $future_field['field_id'] ) ) {
				$future_field_ids[ sanitize_text_field( (string) $future_field['field_id'] ) ] = true;
			}
		}

		$suggestions = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$message = isset( $item['message'] ) && is_scalar( $item['message'] ) ? trim( sanitize_textarea_field( (string) $item['message'] ) ) : '';
			if ( '' === $message ) {
				continue;
			}

			$field_id = isset( $item['field_id'] ) && is_scalar( $item['field_id'] ) ? sanitize_text_field( (string) $item['field_id'] ) : '';
			if ( '' !== $field_id && [] !== $visible_field_ids && ! isset( $visible_field_ids[ $field_id ] ) ) {
				continue;
			}

			$depends_on_future_field_ids = [];
			if ( isset( $item['depends_on_future_field_ids'] ) && is_array( $item['depends_on_future_field_ids'] ) ) {
				foreach ( $item['depends_on_future_field_ids'] as $future_field_id ) {
					if ( is_scalar( $future_field_id ) ) {
						$future_field_id = sanitize_text_field( (string) $future_field_id );
						if ( '' !== $future_field_id && isset( $future_field_ids[ $future_field_id ] ) ) {
							$depends_on_future_field_ids[] = $future_field_id;
						}
					}
				}
			}
			if ( [] !== $depends_on_future_field_ids ) {
				continue;
			}

			$severity = isset( $item['severity'] ) && is_scalar( $item['severity'] ) ? sanitize_key( (string) $item['severity'] ) : 'info';
			if ( ! in_array( $severity, [ 'info', 'warning', 'critical' ], true ) ) {
				$severity = 'info';
			}

			$suggestions[] = [
				'suggestion_id'           => isset( $item['suggestion_id'] ) && is_scalar( $item['suggestion_id'] ) ? sanitize_text_field( (string) $item['suggestion_id'] ) : wp_generate_uuid4(),
				'field_id'                => $field_id,
				'severity'                => $severity,
				'message'                 => $message,
				'jump_target_field_id'    => isset( $item['jump_target_field_id'] ) && is_scalar( $item['jump_target_field_id'] ) ? sanitize_text_field( (string) $item['jump_target_field_id'] ) : $field_id,
				'is_suppressed'           => rest_sanitize_boolean( $item['is_suppressed'] ?? false ),
				'depends_on_future_field_ids' => [],
			];
		}

		return $suggestions;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_local_virtual_questions( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$questions = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$question = isset( $item['question'] ) && is_scalar( $item['question'] ) ? trim( sanitize_text_field( (string) $item['question'] ) ) : '';
			if ( '' === $question ) {
				continue;
			}

			$answer_type = isset( $item['answer_type'] ) && is_scalar( $item['answer_type'] ) ? sanitize_key( (string) $item['answer_type'] ) : 'long_text';
			if ( ! in_array( $answer_type, [ 'short_text', 'long_text', 'choice' ], true ) ) {
				$answer_type = 'long_text';
			}

			$choices = [];
			if ( isset( $item['choices'] ) && is_array( $item['choices'] ) ) {
				foreach ( $item['choices'] as $choice ) {
					if ( is_scalar( $choice ) ) {
						$choice = trim( sanitize_text_field( (string) $choice ) );
						if ( '' !== $choice ) {
							$choices[] = $choice;
						}
					}
				}
			}

			$questions[] = [
				'question_id'     => isset( $item['question_id'] ) && is_scalar( $item['question_id'] ) ? sanitize_text_field( (string) $item['question_id'] ) : wp_generate_uuid4(),
				'question'        => $question,
				'reason'          => isset( $item['reason'] ) && is_scalar( $item['reason'] ) ? sanitize_text_field( (string) $item['reason'] ) : null,
				'target_field_id' => isset( $item['target_field_id'] ) && is_scalar( $item['target_field_id'] ) ? sanitize_text_field( (string) $item['target_field_id'] ) : null,
				'required'        => rest_sanitize_boolean( $item['required'] ?? false ),
				'answer_type'     => $answer_type,
				'choices'         => $choices,
			];

			if ( count( $questions ) >= 5 ) {
				break;
			}
		}

		return $questions;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_local_conditional_decisions( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$decisions = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$condition_key = isset( $item['condition_key'] ) && is_scalar( $item['condition_key'] ) ? sanitize_key( (string) $item['condition_key'] ) : '';
			if ( '' === $condition_key ) {
				continue;
			}

			$decision = [
				'decision_id'   => isset( $item['decision_id'] ) && is_scalar( $item['decision_id'] ) ? sanitize_text_field( (string) $item['decision_id'] ) : wp_generate_uuid4(),
				'condition_key' => $condition_key,
				'met'           => rest_sanitize_boolean( $item['met'] ?? false ),
				'reason'        => isset( $item['reason'] ) && is_scalar( $item['reason'] ) ? sanitize_text_field( (string) $item['reason'] ) : null,
			];

			if ( isset( $item['confidence'] ) && is_numeric( $item['confidence'] ) ) {
				$decision['confidence'] = max( 0.0, min( 1.0, (float) $item['confidence'] ) );
			}

			$decisions[] = $decision;
		}

		return $decisions;
	}

	/**
	 * @return true|WP_Error
	 */
	private function enforce_rate_limit( int $form_id, string $bucket = 'suggest' ) {
		$is_runtime_config = 'runtime_config' === $bucket;
		$default_limit = $is_runtime_config
			? self::DEFAULT_RUNTIME_CONFIG_RATE_LIMIT_PER_MINUTE
			: self::DEFAULT_RATE_LIMIT_PER_MINUTE;
		$key_prefix = $is_runtime_config
			? 'sentient_forms_rt_config_rl_'
			: 'sentient_forms_rt_suggest_rl_';
		$error_message = $is_runtime_config
			? __( 'Runtime config rate limit exceeded. Please wait and retry.', 'sentient-forms' )
			: __( 'Suggestion rate limit exceeded. Please wait and retry.', 'sentient-forms' );

		if ( $is_runtime_config ) {
			$limit = (int) apply_filters(
				'sentient_forms_realtime_runtime_config_rate_limit_per_minute',
				$default_limit,
				$form_id
			);
		} else {
			$limit = (int) apply_filters(
				'sentient_forms_realtime_suggest_rate_limit_per_minute',
				$default_limit,
				$form_id
			);
		}

		if ( $limit < 1 ) {
			$limit = $default_limit;
		}

		$ip = $this->get_rate_limit_client_identifier( $form_id );
		$key = $key_prefix . md5( $form_id . '|' . $ip );
		$current = (int) get_transient( $key );
		if ( $current >= $limit ) {
			return $this->prepare_error_response(
				'rest_too_many_requests',
				$error_message,
				429
			);
		}

		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function get_rate_limit_client_identifier( int $form_id ): string {
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$identifier = apply_filters( 'sentient_forms_realtime_suggest_client_identifier', $remote_addr, $form_id );

		return is_scalar( $identifier ) && '' !== trim( (string) $identifier )
			? sanitize_text_field( (string) $identifier )
			: 'unknown';
	}

	/**
	 * @return true|WP_Error
	 */
	private function validate_payload_size( WP_REST_Request $request ) {
		$limit = (int) apply_filters( 'sentient_forms_realtime_suggest_max_payload_bytes', self::DEFAULT_MAX_PAYLOAD_BYTES, $request );
		if ( $limit < 1024 ) {
			$limit = self::DEFAULT_MAX_PAYLOAD_BYTES;
		}

		$payload = wp_json_encode(
			[
					'all_known_field_values' => $request->get_param( 'all_known_field_values' ),
					'visible_field_ids'      => $request->get_param( 'visible_field_ids' ),
					'supplemental_field_context' => $request->get_param( 'supplemental_field_context' ),
					'panel_state'            => $request->get_param( 'panel_state' ),
				]
			);
		if ( false === $payload ) {
			return $this->prepare_error_response(
				'rest_invalid_suggestion_payload',
				__( 'Suggestion payload could not be encoded.', 'sentient-forms' ),
				400
			);
		}

		if ( strlen( $payload ) > $limit ) {
			return $this->prepare_error_response(
				'rest_suggestion_payload_too_large',
				__( 'Suggestion payload is too large.', 'sentient-forms' ),
				413
			);
		}

		return true;
	}

	/**
	 * @param mixed $raw
	 *
	 * @return array<string,string>
	 */
	private function sanitize_known_field_values( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$values = [];
		foreach ( $raw as $field_id => $field_value ) {
			if ( ! is_scalar( $field_id ) ) {
				continue;
			}
			$normalized_field_id = sanitize_text_field( (string) $field_id );
			if ( '' === $normalized_field_id ) {
				continue;
			}

			if ( is_array( $field_value ) ) {
				$sanitized_items = [];
				foreach ( $field_value as $item ) {
					if ( ! is_scalar( $item ) ) {
						continue;
					}
					$sanitized_items[] = sanitize_text_field( (string) $item );
				}
				$values[ $normalized_field_id ] = implode( ', ', array_filter( $sanitized_items, static fn( string $item ): bool => '' !== $item ) );
				continue;
			}

			if ( is_scalar( $field_value ) ) {
				$values[ $normalized_field_id ] = sanitize_text_field( (string) $field_value );
			}
		}

		return $values;
	}

	/**
	 * @param array<string,mixed> $form_settings
	 * @param string              $mapping_id
	 *
	 * @return array<string,mixed>|null
	 */
	private function find_realtime_mapping( array $form_settings, string $mapping_id ): ?array {
		$actions = isset( $form_settings['actions'] ) && is_array( $form_settings['actions'] )
			? $form_settings['actions']
			: [];

		foreach ( $actions as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$candidate_id = '';
			foreach ( [ 'id', 'local_mapping_id' ] as $candidate_id_key ) {
				if ( isset( $candidate[ $candidate_id_key ] ) && is_scalar( $candidate[ $candidate_id_key ] ) ) {
					$candidate_id = sanitize_text_field( (string) $candidate[ $candidate_id_key ] );
					break;
				}
			}
			if ( '' !== $mapping_id && $candidate_id !== $mapping_id ) {
				continue;
			}

			$enabled = array_key_exists( 'is_action_enabled_for_form', $candidate )
				? rest_sanitize_boolean( $candidate['is_action_enabled_for_form'] )
				: true;
			if ( ! $enabled ) {
				continue;
			}

			$settings = isset( $candidate['settings'] ) && is_array( $candidate['settings'] )
				? $candidate['settings']
				: [];
			$execution_mode = isset( $settings['execution_mode'] ) && is_scalar( $settings['execution_mode'] )
				? sanitize_key( (string) $settings['execution_mode'] )
				: 'after_submission';
			if ( 'real_time' !== $execution_mode ) {
				continue;
			}

			return $candidate;
		}

		return null;
	}

	private function normalize_hidden_field_exposure_mode( array $realtime_settings ): string {
		$mode = isset( $realtime_settings['hidden_field_exposure_mode'] ) && is_scalar( $realtime_settings['hidden_field_exposure_mode'] )
			? sanitize_key( (string) $realtime_settings['hidden_field_exposure_mode'] )
			: 'label_hidden';

		return in_array( $mode, self::HIDDEN_FIELD_EXPOSURE_MODES, true ) ? $mode : 'label_hidden';
	}

	private function root_field_id( string $field_id ): string {
		$dot_position = strpos( $field_id, '.' );
		return false === $dot_position ? $field_id : substr( $field_id, 0, $dot_position );
	}

	/**
	 * @param array<int,string> $visible_field_ids
	 */
	private function is_visible_value_field( string $field_id, array $visible_field_ids ): bool {
		$visible_lookup = array_fill_keys( $visible_field_ids, true );
		return isset( $visible_lookup[ $field_id ] ) || isset( $visible_lookup[ $this->root_field_id( $field_id ) ] );
	}

	private function is_realtime_storage_field( string $field_id, array $realtime_settings ): bool {
		$target_field_id = isset( $realtime_settings['storage_target_field_id'] ) && is_scalar( $realtime_settings['storage_target_field_id'] )
			? sanitize_text_field( (string) $realtime_settings['storage_target_field_id'] )
			: '';
		if ( '' === $target_field_id || '__sentient_forms_realtime_qna' === $target_field_id ) {
			return false;
		}

		return $field_id === $target_field_id || $this->root_field_id( $field_id ) === $target_field_id;
	}

	private function is_client_visible_form_value_field( string $field_id, array $form, array $realtime_settings ): bool {
		if ( '' === $field_id || $this->is_realtime_storage_field( $field_id, $realtime_settings ) ) {
			return false;
		}

		$field_meta = $this->form_field_meta_for_value_id( $form, $field_id );
		if ( 'hidden' === ( $field_meta['type'] ?? '' ) ) {
			return false;
		}

		return ! in_array( $field_meta['visibility'] ?? '', [ 'hidden', 'administrative' ], true );
	}

	/**
	 * @return array{field_id:string,label:string,type:string,visibility:string,page_index:int}|null
	 */
	private function form_field_meta_for_value_id( array $form, string $field_id ): ?array {
		$root_field_id = $this->root_field_id( $field_id );
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];

		foreach ( $fields as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$current_field_id = isset( $field->id ) ? sanitize_text_field( (string) $field->id ) : '';
			if ( '' === $current_field_id ) {
				continue;
			}

			$matches_field = $field_id === $current_field_id || $root_field_id === $current_field_id;
			if ( ! $matches_field && isset( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as $input ) {
					$input_id = is_array( $input ) && isset( $input['id'] )
						? sanitize_text_field( (string) $input['id'] )
						: ( is_object( $input ) && isset( $input->id ) ? sanitize_text_field( (string) $input->id ) : '' );
					if ( $field_id === $input_id ) {
						$matches_field = true;
						break;
					}
				}
			}
			if ( ! $matches_field ) {
				continue;
			}

			return [
				'field_id'   => $current_field_id,
				'label'      => isset( $field->label ) ? sanitize_text_field( (string) $field->label ) : '',
				'type'       => isset( $field->type ) ? sanitize_key( (string) $field->type ) : '',
				'visibility' => isset( $field->visibility ) ? sanitize_key( (string) $field->visibility ) : '',
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field objects expose pageNumber.
				'page_index' => isset( $field->pageNumber ) ? max( 1, (int) $field->pageNumber ) : 1,
			];
		}

		return null;
	}

	private function sanitize_realtime_context_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$items = [];
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$items[] = sanitize_text_field( (string) $item );
				}
			}
			return $items;
		}

		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	/**
	 * @param array<string,string> $known_values
	 * @param array<int,string>    $visible_field_ids
	 *
	 * @return array<string,string>
	 */
	private function filter_known_values_for_realtime_policy( array $known_values, array $visible_field_ids, array $form, array $realtime_settings, string $hidden_field_exposure_mode, int $current_page_index ): array {
		$include_hidden_values = in_array( $hidden_field_exposure_mode, [ 'label_hidden_value', 'label_value' ], true );
		$filtered = [];

		foreach ( $known_values as $field_id => $value ) {
			$field_id = sanitize_text_field( (string) $field_id );
			if ( '' === $field_id || $this->is_realtime_storage_field( $field_id, $realtime_settings ) ) {
				continue;
			}

			$field_meta = $this->form_field_meta_for_value_id( $form, $field_id );
			$field_page_index = max( 1, (int) ( $field_meta['page_index'] ?? 1 ) );
			$is_prior_public_value = $field_page_index < $current_page_index
				&& $this->is_client_visible_form_value_field( $field_id, $form, $realtime_settings );

			if ( $this->is_visible_value_field( $field_id, $visible_field_ids ) || $is_prior_public_value || $include_hidden_values ) {
				$filtered[ $field_id ] = sanitize_text_field( (string) $value );
			}
		}

		return $filtered;
	}

	/**
	 * @param array<string,string> $known_values
	 * @param array<int,string>    $visible_field_ids
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_supplemental_field_context( mixed $raw_context, array $known_values, array $visible_field_ids, array $form, array $realtime_settings, string $hidden_field_exposure_mode, int $current_page_index ): array {
		if ( 'omit_hidden' === $hidden_field_exposure_mode ) {
			return [];
		}

		$include_hidden_values = in_array( $hidden_field_exposure_mode, [ 'label_hidden_value', 'label_value' ], true );
		$candidate_ids = [];
		foreach ( is_array( $raw_context ) ? $raw_context : [] as $item ) {
			if ( is_array( $item ) && isset( $item['field_id'] ) && is_scalar( $item['field_id'] ) ) {
				$field_id = sanitize_text_field( (string) $item['field_id'] );
				if ( '' !== $field_id ) {
					$candidate_ids[ $field_id ] = is_array( $item ) && array_key_exists( 'value', $item )
						? $this->sanitize_realtime_context_value( $item['value'] )
						: null;
				}
			}
		}
		foreach ( $known_values as $field_id => $value ) {
			$field_id = sanitize_text_field( (string) $field_id );
			if ( '' !== $field_id && ! isset( $candidate_ids[ $field_id ] ) ) {
				$candidate_ids[ $field_id ] = $value;
			}
		}

		$context = [];
		foreach ( $candidate_ids as $raw_field_id => $value ) {
			$field_id = sanitize_text_field( (string) $raw_field_id );
			if ( '' === $field_id ) {
				continue;
			}

			if (
				$this->is_visible_value_field( $field_id, $visible_field_ids )
				|| $this->is_realtime_storage_field( $field_id, $realtime_settings )
			) {
				continue;
			}

			$field_meta = $this->form_field_meta_for_value_id( $form, $field_id );
			$field_page_index = max( 1, (int) ( $field_meta['page_index'] ?? 1 ) );
			if (
				$field_page_index < $current_page_index
				&& $this->is_client_visible_form_value_field( $field_id, $form, $realtime_settings )
			) {
				continue;
			}

			$entry = [
				'field_id'   => $field_id,
				'label'      => $field_meta['label'] ?? '',
				'type'       => $field_meta['type'] ?? '',
				'page_index' => $field_meta['page_index'] ?? 1,
			];
			if ( 'label_value' !== $hidden_field_exposure_mode ) {
				$entry['hidden'] = true;
			}
			if ( $include_hidden_values ) {
				$entry['value'] = $this->sanitize_realtime_context_value( $value );
			}
			$context[] = $entry;
		}

		return $context;
	}

	/**
	 * @param array<string,mixed> $mapping
	 * @param array<string,string> $known_values
	 * @param array<string,mixed> $form
	 *
	 * @return array<string,mixed>
	 */
	private function build_suggestion_context(
		WP_REST_Request $request,
		string $form_source_slug,
		int $form_id,
		array $mapping,
		array $known_values,
		array $form
	): array {
		$settings = isset( $mapping['settings'] ) && is_array( $mapping['settings'] )
			? $mapping['settings']
			: [];
			$realtime_settings = isset( $settings['realtime_settings'] ) && is_array( $settings['realtime_settings'] )
				? $settings['realtime_settings']
				: [];
			$hidden_field_exposure_mode = $this->normalize_hidden_field_exposure_mode( $realtime_settings );

		$visible_field_ids = [];
		if ( isset( $request['visible_field_ids'] ) && is_array( $request['visible_field_ids'] ) ) {
			foreach ( $request['visible_field_ids'] as $field_id ) {
				if ( ! is_scalar( $field_id ) ) {
					continue;
				}
				$normalized = sanitize_text_field( (string) $field_id );
				if ( $this->is_client_visible_form_value_field( $normalized, $form, $realtime_settings ) ) {
					$visible_field_ids[] = $normalized;
				}
			}
		}
			$visible_field_ids = array_values( array_unique( $visible_field_ids ) );
			if ( empty( $visible_field_ids ) ) {
				$visible_field_ids = array_values(
					array_filter(
						array_map(
							function ( $field_id ) use ( $form, $realtime_settings ): string {
								$normalized = sanitize_text_field( (string) $field_id );
								if (
									'' === $normalized
									|| $this->is_realtime_storage_field( $normalized, $realtime_settings )
									|| ! $this->is_client_visible_form_value_field( $normalized, $form, $realtime_settings )
								) {
									return '';
								}
								return $normalized;
							},
							array_keys( $known_values )
						),
					static fn( string $field_id ): bool => '' !== $field_id
				)
			);
		}

		$checkpoint_field_ids = [];
		if ( isset( $realtime_settings['checkpoint_field_ids'] ) && is_array( $realtime_settings['checkpoint_field_ids'] ) ) {
			foreach ( $realtime_settings['checkpoint_field_ids'] as $field_id ) {
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

		$current_page_index = max( 1, absint( $request['current_page_index'] ) );
		$total_pages = max( 1, absint( $request['total_pages'] ) );
		if ( $current_page_index > $total_pages ) {
			$current_page_index = $total_pages;
		}

		$future_field_manifest = [];
		if ( isset( $request['future_field_manifest'] ) && is_array( $request['future_field_manifest'] ) ) {
			foreach ( $request['future_field_manifest'] as $future_field ) {
				if ( ! is_array( $future_field ) ) {
					continue;
				}

				$field_id = isset( $future_field['field_id'] ) && is_scalar( $future_field['field_id'] )
					? sanitize_text_field( (string) $future_field['field_id'] )
					: '';
				$field_type = isset( $future_field['type'] ) && is_scalar( $future_field['type'] )
					? sanitize_key( (string) $future_field['type'] )
					: '';
				$page_index = isset( $future_field['page_index'] )
					? max( 1, (int) $future_field['page_index'] )
					: 1;

				if ( '' === $field_id || '' === $field_type ) {
					continue;
				}

				$manifest_entry = [
					'field_id'   => $field_id,
					'type'       => $field_type,
					'page_index' => $page_index,
				];
				if ( isset( $future_field['label'] ) && is_scalar( $future_field['label'] ) ) {
					$manifest_entry['label'] = sanitize_text_field( (string) $future_field['label'] );
				}
				$future_field_manifest[] = $manifest_entry;
			}
		}
			if ( empty( $future_field_manifest ) ) {
				$future_field_manifest = $this->build_future_field_manifest( $form, $current_page_index );
			}
				$request_reason = isset( $request['request_reason'] ) && is_scalar( $request['request_reason'] )
					? sanitize_key( (string) $request['request_reason'] )
					: 'manual_refresh';
				$panel_state = $this->sanitize_panel_state( $request['panel_state'] ?? [] );
				$filtered_known_values = $this->filter_known_values_for_realtime_policy(
					$known_values,
					$visible_field_ids,
					$form,
					$realtime_settings,
					$hidden_field_exposure_mode,
					$current_page_index
				);
				$supplemental_field_context = $this->build_supplemental_field_context(
					$request['supplemental_field_context'] ?? [],
					$known_values,
					$visible_field_ids,
					$form,
					$realtime_settings,
					$hidden_field_exposure_mode,
					$current_page_index
				);

				return [
					'form_id'               => (string) $form_id,
				'source'                => $form_source_slug,
				'request_reason'        => $request_reason,
				'current_page_index'    => $current_page_index,
				'total_pages'           => $total_pages,
					'visible_field_ids'     => $visible_field_ids,
					'checkpoint_field_ids'  => $checkpoint_field_ids,
					'all_known_field_values'=> $filtered_known_values,
					'future_field_manifest' => $future_field_manifest,
					'hidden_field_exposure_mode' => $hidden_field_exposure_mode,
					'supplemental_field_context' => $supplemental_field_context,
					'panel_state'           => $panel_state,
				];
		}

		/**
		 * @param mixed $raw
		 *
		 * @return array<string,mixed>
		 */
		private function sanitize_panel_state( mixed $raw ): array {
			if ( ! is_array( $raw ) ) {
				return [
					'suggestions'       => [],
					'virtual_questions' => [],
				];
			}

			$suggestions = [];
			foreach ( is_array( $raw['suggestions'] ?? null ) ? $raw['suggestions'] : [] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$suggestions[] = [
					'suggestion_id' => isset( $item['suggestion_id'] ) && is_scalar( $item['suggestion_id'] ) ? sanitize_text_field( (string) $item['suggestion_id'] ) : '',
					'field_id'      => isset( $item['field_id'] ) && is_scalar( $item['field_id'] ) ? sanitize_text_field( (string) $item['field_id'] ) : '',
					'message'       => isset( $item['message'] ) && is_scalar( $item['message'] ) ? sanitize_textarea_field( (string) $item['message'] ) : '',
					'severity'      => isset( $item['severity'] ) && is_scalar( $item['severity'] ) ? sanitize_key( (string) $item['severity'] ) : 'info',
					'completed'     => rest_sanitize_boolean( $item['completed'] ?? false ),
				];
				if ( count( $suggestions ) >= 20 ) {
					break;
				}
			}

			$questions = [];
			foreach ( is_array( $raw['virtual_questions'] ?? null ) ? $raw['virtual_questions'] : [] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$questions[] = [
					'question_id'     => isset( $item['question_id'] ) && is_scalar( $item['question_id'] ) ? sanitize_text_field( (string) $item['question_id'] ) : '',
					'question'        => isset( $item['question'] ) && is_scalar( $item['question'] ) ? sanitize_text_field( (string) $item['question'] ) : '',
					'answer'          => isset( $item['answer'] ) && is_scalar( $item['answer'] ) ? sanitize_textarea_field( (string) $item['answer'] ) : '',
					'target_field_id' => isset( $item['target_field_id'] ) && is_scalar( $item['target_field_id'] ) ? sanitize_text_field( (string) $item['target_field_id'] ) : '',
					'completed'       => rest_sanitize_boolean( $item['completed'] ?? false ),
				];
				if ( count( $questions ) >= 20 ) {
					break;
				}
			}

			return [
				'suggestions'       => $suggestions,
				'virtual_questions' => $questions,
			];
		}

		/**
	 * @param array<string,mixed> $form
	 * @return array<int,array<string,mixed>>
	 */
	private function build_future_field_manifest( array $form, int $current_page_index ): array {
		$manifest = [];
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : [];

		foreach ( $fields as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$field_id = isset( $field->id ) ? sanitize_text_field( (string) $field->id ) : '';
			$field_type = isset( $field->type ) ? sanitize_key( (string) $field->type ) : '';
			if ( '' === $field_id || '' === $field_type || 'page' === $field_type ) {
				continue;
			}
			$page_index = isset( $field->pageNumber ) ? max( 1, (int) $field->pageNumber ) : 1;
			if ( $page_index <= $current_page_index ) {
				continue;
			}

			$entry = [
				'field_id'   => $field_id,
				'type'       => $field_type,
				'page_index' => $page_index,
			];
			if ( isset( $field->label ) ) {
				$entry['label'] = sanitize_text_field( (string) $field->label );
			}
			$manifest[] = $entry;
		}

		return $manifest;
	}
}
