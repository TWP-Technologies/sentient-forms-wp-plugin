<?php
/**
 * REST API controller for real-time form suggestions.
 *
 * @package Sentient_Forms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sentient_Forms_Form_Suggestions_Controller extends Abstract_Sentient_Forms_Base_Controller {
	protected string $rest_base = '(?P<form_source_slug>[a-z0-9_]+)/forms/(?P<form_id>\\d+)/actions';

	private const RATE_LIMIT_PER_MINUTE = 120;

	private Sentient_Forms_Plugin $plugin;
	private Sentient_Forms_Form_Adapter_Registry $adapter_registry;

	public function __construct() {
		parent::__construct();
		$this->plugin = Sentient_Forms_Plugin::instance();
		$this->adapter_registry = $this->plugin->get_form_adapter_registry();
	}

	public function register_routes(): void {
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
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
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
					],
				],
			]
		);
	}

	/**
	 * Public permission callback guarded by a form-scoped nonce.
	 */
	public function permission_callback_public_nonce( WP_REST_Request $request ): bool {
		$form_id = absint( $request->get_param( 'form_id' ) );
		if ( $form_id <= 0 ) {
			return false;
		}

		$nonce = (string) $request->get_header( 'X-Sentient-Forms-Suggest-Nonce' );
		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( 'sentient_forms_nonce' );
		}

		if ( '' === $nonce ) {
			return false;
		}

		$expected_action = 'sentient_forms_realtime_suggest_' . $form_id;
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
		];
		if ( '' !== $execution_request_id ) {
			$context['execution_request_id'] = $execution_request_id;
		}

		$response = $this->plugin->get_action_executor()->suggest(
			$central_action_id,
			$form,
			$known_values,
			$context,
			$suggestion_context
		);

		if ( is_wp_error( $response ) ) {
			$status = (int) ( $response->get_error_data()['status'] ?? 502 );
			return $this->prepare_error_response(
				'sentient_forms_suggest_failed',
				$response->get_error_message(),
				$status
			);
		}

		return $this->prepare_item_for_response( $response );
	}

	/**
	 * @return true|WP_Error
	 */
	private function enforce_rate_limit( int $form_id ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key = 'sentient_forms_rt_suggest_rl_' . md5( $form_id . '|' . $ip );
		$current = (int) get_transient( $key );
		if ( $current >= self::RATE_LIMIT_PER_MINUTE ) {
			return $this->prepare_error_response(
				'rest_too_many_requests',
				__( 'Suggestion rate limit exceeded. Please wait and retry.', 'sentient-forms' ),
				429
			);
		}

		set_transient( $key, $current + 1, MINUTE_IN_SECONDS );
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

			$candidate_id = isset( $candidate['id'] ) && is_scalar( $candidate['id'] )
				? sanitize_text_field( (string) $candidate['id'] )
				: '';
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

		$visible_field_ids = [];
		if ( isset( $request['visible_field_ids'] ) && is_array( $request['visible_field_ids'] ) ) {
			foreach ( $request['visible_field_ids'] as $field_id ) {
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
						static function ( $field_id ): string {
							return sanitize_text_field( (string) $field_id );
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

		return [
			'form_id'               => (string) $form_id,
			'source'                => $form_source_slug,
			'current_page_index'    => $current_page_index,
			'total_pages'           => $total_pages,
			'visible_field_ids'     => $visible_field_ids,
			'checkpoint_field_ids'  => $checkpoint_field_ids,
			'all_known_field_values'=> $known_values,
			'future_field_manifest' => $future_field_manifest,
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
