<?php

if ( ! class_exists( 'GFForms' ) ) {
	class GFForms {}
}

if ( ! class_exists( 'GFAPI' ) ) {
	class GFAPI {
		/** @var array<int,array<string,mixed>> */
		public static array $forms = [];

		public static function get_form( $form_id ) {
			$form_id = (int) $form_id;
			return self::$forms[ $form_id ] ?? false;
		}

		public static function get_entry( $entry_id ) {
			return false;
		}

		public static function get_forms(): array {
			return array_values( self::$forms );
		}

		public static function update_form( $form, $form_id = null ) {
			$form_id = null === $form_id && is_array( $form ) && isset( $form['id'] )
				? (int) $form['id']
				: (int) $form_id;

			if ( $form_id <= 0 ) {
				return new WP_Error( 'missing_form_id', 'Missing form id.' );
			}

			if ( is_array( $form ) ) {
				$form['id'] = $form_id;
			}

			self::$forms[ $form_id ] = $form;

			return true;
		}
	}
}

final class Sentient_Forms_Test_Suggest_Executor extends Sentient_Forms_Action_Executor {
	public array $calls = [];

	public function __construct() {}

	public function suggest(
		string $central_action_id,
		array $form,
		array $entry,
		array $context,
		array $suggestion_context
	) {
		$this->calls[] = [
			'central_action_id' => $central_action_id,
			'form' => $form,
			'entry' => $entry,
			'context' => $context,
			'suggestion_context' => $suggestion_context,
		];

		return [
			'status' => 'success',
			'suggestions' => [
				[
					'suggestion_id' => wp_generate_uuid4(),
					'field_id' => '1',
					'severity' => 'warning',
					'message' => 'Add detail',
					'jump_target_field_id' => '1',
					'is_suppressed' => false,
				],
			],
			'virtual_questions' => [
				[
					'question_id' => 'details-url',
					'question' => 'What URL did this happen on?',
					'target_field_id' => '1',
					'required' => true,
					'answer_type' => 'short_text',
				],
			],
			'meta' => [
				'execution_request_id' => $context['execution_request_id'] ?? 'generated',
				'credits_debited' => 3,
			],
		];
	}
}

final class Sentient_Forms_Test_Local_Form_Mappings_Repository extends Sentient_Forms_Form_Mappings_Repository {
	/** @var array<int,array<string,mixed>> */
	private array $rows;

	/** @param array<int,array<string,mixed>> $rows */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function get( int $id ): ?array {
		return $this->rows[ $id ] ?? null;
	}
}

final class Sentient_Forms_Test_Local_Suggest_Execution_Service extends Sentient_Forms_Local_Action_Execution_Service {
	public array $calls = [];
	public mixed $next_result = null;

	public function __construct() {}

	public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error {
		$this->calls[] = [
			'mapping_id' => $mapping_id,
			'form'       => $form,
			'entry'      => $entry,
			'context'    => $context,
		];

		if ( null !== $this->next_result ) {
			return $this->next_result;
		}

		return [
			'execution_request_id' => $context['execution_request_id'] ?? 'rt-local-first-test',
			'status'               => 'succeeded',
			'provider'             => 'openrouter',
			'model'                => 'openrouter/auto',
			'cached'               => false,
			'result'               => [
				'structured' => [
					'suggestions' => [
						[
							'suggestion_id'        => 'suggest-visible',
							'field_id'             => '1',
							'severity'             => 'warning',
							'message'              => 'Add the product number and quantity.',
							'jump_target_field_id' => '1',
						],
						[
							'suggestion_id'        => 'suggest-hidden',
							'field_id'             => '4',
							'severity'             => 'warning',
							'message'              => 'Hidden field should not render.',
							'jump_target_field_id' => '4',
						],
					],
					'virtual_questions' => [
						[
							'question_id'     => 'need-date',
							'question'        => 'When do you need this quote returned?',
							'reason'          => 'The site owner can prioritize the request.',
							'target_field_id' => '1',
							'required'        => true,
							'answer_type'     => 'short_text',
						],
					],
					'conditional_decisions' => [
						[
							'decision_id'   => 'quote-request',
							'condition_key' => 'quote_request',
							'met'           => true,
							'confidence'    => 0.91,
							'reason'        => 'The visitor asked for a quote.',
						],
					],
				],
			],
		];
	}
}

class Tests_Form_Suggestions_Controller extends WP_UnitTestCase {
	private Sentient_Forms_Form_Suggestions_Controller $controller;
	private ReflectionProperty $executor_property;
	private Sentient_Forms_Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = new Sentient_Forms_Form_Suggestions_Controller();
		$this->plugin = Sentient_Forms_Plugin::instance();
		$this->executor_property = new ReflectionProperty( $this->plugin, 'action_executor' );
		$this->executor_property->setAccessible( true );

		GFAPI::$forms = [
			42 => [
				'id' => 42,
				'title' => 'Realtime Test Form',
				'fields' => [
					(object) [
						'id' => 1,
						'label' => 'Name',
						'type' => 'text',
						'pageNumber' => 1,
					],
						(object) [
							'id' => 4,
							'label' => 'Notes',
							'type' => 'textarea',
							'pageNumber' => 2,
						],
						(object) [
							'id' => 9,
							'label' => 'Internal routing',
							'type' => 'hidden',
							'pageNumber' => 1,
						],
					],
				],
		];

		update_option(
			'sentient_forms_actions_gravity_forms_42',
			[
				'actions' => [
					[
						'id' => 'map_rt_1',
						'central_action_id' => 'central_rt_1',
						'action_name_label' => 'Realtime Summary',
						'action_type_indicator' => 'master',
						'is_action_enabled_for_form' => true,
						'settings' => [
							'execution_mode' => 'real_time',
							'realtime_settings' => [
								'checkpoint_field_ids' => [ '1' ],
								'debounce_ms' => 700,
								'cooldown_ms' => 9000,
							],
						],
					],
				],
			]
		);
	}

	protected function tearDown(): void {
		$this->executor_property->setValue( $this->plugin, null );
		delete_option( 'sentient_forms_actions_gravity_forms_42' );
		delete_transient( 'sentient_forms_rt_suggest_rl_' . md5( '42|203.0.113.10' ) );
		unset( $_SERVER['REMOTE_ADDR'] );
		GFAPI::$forms = [];
		parent::tearDown();
	}

	public function test_permission_callback_public_nonce_validates_form_scoped_nonce(): void {
		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_id', 42 );
		$request->set_header( 'X-Sentient-Forms-Suggest-Nonce', wp_create_nonce( 'sentient_forms_realtime_suggest_42' ) );

		$this->assertTrue( $this->controller->permission_callback_public_nonce( $request ) );
	}

	public function test_permission_callback_public_nonce_rejects_missing_nonce(): void {
		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_id', 42 );

		$this->assertFalse( $this->controller->permission_callback_public_nonce( $request ) );
	}

	public function test_rest_dispatch_prefers_suggest_route_over_local_mapping_item_route(): void {
		$stub_executor = new Sentient_Forms_Test_Suggest_Executor();
		$this->executor_property->setValue( $this->plugin, $stub_executor );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_header( 'X-Sentient-Forms-Suggest-Nonce', wp_create_nonce( 'sentient_forms_realtime_suggest_42' ) );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'map_rt_1' );
		$request->set_param( 'execution_request_id', 'rt-route-dispatch-42' );
		$request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );
		$request->set_param( 'request_reason', 'manual_refresh' );
		$request->set_param(
			'panel_state',
			[
				'virtual_questions' => [
					[
						'question_id' => 'affected-url',
						'question' => 'What page URL did this happen on?',
						'answer' => 'https://example.test/pricing',
						'completed' => true,
					],
				],
			]
		);
		$request->set_param(
			'future_field_manifest',
			[
				[
					'field_id' => '4',
					'type' => 'textarea',
					'page_index' => 2,
				],
			]
		);

		$response = rest_do_request( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['status'] ?? null );
		$this->assertCount( 1, $stub_executor->calls );
	}

	public function test_suggest_endpoint_executes_realtime_mapping_via_action_executor(): void {
		$stub_executor = new Sentient_Forms_Test_Suggest_Executor();
		$this->executor_property->setValue( $this->plugin, $stub_executor );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'map_rt_1' );
		$request->set_param( 'execution_request_id', 'rt-request-42' );
		$request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );
		$request->set_param( 'request_reason', 'manual_refresh' );
		$request->set_param(
			'panel_state',
			[
				'virtual_questions' => [
					[
						'question_id' => 'affected-url',
						'question' => 'What page URL did this happen on?',
						'answer' => 'https://example.test/pricing',
						'completed' => true,
					],
				],
			]
		);
		$request->set_param(
			'future_field_manifest',
			[
				[
					'field_id' => '4',
					'type' => 'textarea',
					'page_index' => 2,
				],
			]
		);

		$response = $this->controller->suggest( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['status'] ?? null );
		$this->assertCount( 1, $data['suggestions'] ?? [] );
		$this->assertSame( 'What URL did this happen on?', $data['virtual_questions'][0]['question'] ?? null );
		$this->assertCount( 1, $stub_executor->calls );
		$this->assertSame( 'central_rt_1', $stub_executor->calls[0]['central_action_id'] );
		$this->assertSame( 'rt-request-42', $stub_executor->calls[0]['context']['execution_request_id'] ?? null );
		$this->assertSame( [ '1' ], $stub_executor->calls[0]['suggestion_context']['visible_field_ids'] ?? [] );
		$this->assertSame( 'manual_refresh', $stub_executor->calls[0]['suggestion_context']['request_reason'] ?? null );
		$this->assertSame(
			'https://example.test/pricing',
			$stub_executor->calls[0]['suggestion_context']['panel_state']['virtual_questions'][0]['answer'] ?? null
		);
		$this->assertTrue( $stub_executor->calls[0]['context']['suggestion_context']['panel_state']['virtual_questions'][0]['completed'] ?? false );
	}

	public function test_suggest_endpoint_strips_hidden_values_by_default_and_keeps_label_context(): void {
		$stub_executor = new Sentient_Forms_Test_Suggest_Executor();
		$this->executor_property->setValue( $this->plugin, $stub_executor );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'map_rt_1' );
		$request->set_param( 'all_known_field_values', [ '1' => 'hello', '9' => 'route-secret' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );
		$request->set_param(
			'supplemental_field_context',
			[
				[
					'field_id' => '9',
					'label' => 'Attacker label',
					'type' => 'hidden',
					'page_index' => 1,
					'hidden' => true,
					'value' => 'route-secret',
				],
			]
		);

		$response = $this->controller->suggest( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 1, $stub_executor->calls );
		$context = $stub_executor->calls[0]['suggestion_context'];
		$this->assertSame( [ '1' => 'hello' ], $context['all_known_field_values'] ?? [] );
		$this->assertSame( [ '1' => 'hello' ], $stub_executor->calls[0]['entry'] );
		$this->assertSame( 'label_hidden', $context['hidden_field_exposure_mode'] ?? null );
		$this->assertSame( '9', $context['supplemental_field_context'][0]['field_id'] ?? null );
		$this->assertSame( 'Internal routing', $context['supplemental_field_context'][0]['label'] ?? null );
		$this->assertTrue( $context['supplemental_field_context'][0]['hidden'] ?? false );
		$this->assertArrayNotHasKey( 'value', $context['supplemental_field_context'][0] ?? [] );
	}

	public function test_suggest_endpoint_allows_hidden_values_when_mapping_policy_allows_them(): void {
		$settings = get_option( 'sentient_forms_actions_gravity_forms_42' );
		$settings['actions'][0]['settings']['realtime_settings']['hidden_field_exposure_mode'] = 'label_hidden_value';
		update_option( 'sentient_forms_actions_gravity_forms_42', $settings );

		$stub_executor = new Sentient_Forms_Test_Suggest_Executor();
		$this->executor_property->setValue( $this->plugin, $stub_executor );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'map_rt_1' );
		$request->set_param( 'all_known_field_values', [ '1' => 'hello', '9' => 'route-secret' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );
		$request->set_param(
			'supplemental_field_context',
			[
				[
					'field_id' => '9',
					'value' => 'route-secret',
				],
			]
		);

		$response = $this->controller->suggest( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$context = $stub_executor->calls[0]['suggestion_context'];
		$this->assertSame( [ '1' => 'hello', '9' => 'route-secret' ], $context['all_known_field_values'] ?? [] );
		$this->assertSame( [ '1' => 'hello', '9' => 'route-secret' ], $stub_executor->calls[0]['entry'] );
		$this->assertSame( 'route-secret', $context['supplemental_field_context'][0]['value'] ?? null );
	}

	public function test_suggest_endpoint_executes_local_first_realtime_mapping_without_cps_fallback(): void {
		update_option(
			'sentient_forms_actions_gravity_forms_42',
			[
				'actions' => [
					[
						'id' => 'local_first_99',
						'central_action_id' => 'clarification_assistant_v1',
						'action_name_label' => 'Realtime Clarification Assistant',
						'action_type_indicator' => 'master',
						'is_action_enabled_for_form' => true,
						'settings' => [
							'execution_mode' => 'real_time',
							'realtime_settings' => [
								'checkpoint_field_ids' => [ '1' ],
							],
						],
					],
				],
			]
		);

		$legacy_executor = new Sentient_Forms_Test_Suggest_Executor();
		$this->executor_property->setValue( $this->plugin, $legacy_executor );

		$local_repository = new Sentient_Forms_Test_Local_Form_Mappings_Repository(
			[
				99 => [
					'id' => 99,
					'form_source' => 'gravity_forms',
					'form_id' => '42',
					'hook' => 'real_time',
					'execution_mode' => 'real_time',
					'enabled' => 1,
				],
			]
		);
		$local_execution = new Sentient_Forms_Test_Local_Suggest_Execution_Service();
		$controller = new Sentient_Forms_Form_Suggestions_Controller( $local_repository, $local_execution );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'local_first_99' );
		$request->set_param( 'execution_request_id', 'rt-local-first-42' );
		$request->set_param( 'all_known_field_values', [ '1' => 'Quote product 183671 at 500pcs', '9' => 'route-secret' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );
		$request->set_param(
			'future_field_manifest',
			[
				[
					'field_id' => '4',
					'type' => 'textarea',
					'page_index' => 2,
				],
			]
		);

		$response = $controller->suggest( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['status'] ?? null );
		$this->assertCount( 1, $local_execution->calls );
		$this->assertSame( 99, $local_execution->calls[0]['mapping_id'] );
		$this->assertSame( 'real_time', $local_execution->calls[0]['context']['hook'] ?? null );
		$this->assertSame( [ '1' => 'Quote product 183671 at 500pcs' ], $local_execution->calls[0]['entry'] );
		$this->assertSame( [], $legacy_executor->calls );
		$this->assertSame( 'Add the product number and quantity.', $data['suggestions'][0]['message'] ?? null );
		$this->assertCount( 1, $data['suggestions'] ?? [] );
		$this->assertSame( 'When do you need this quote returned?', $data['virtual_questions'][0]['question'] ?? null );
		$this->assertSame( 'quote_request', $data['conditional_decisions'][0]['condition_key'] ?? null );
		$this->assertSame( 'rt-local-first-42', $data['meta']['execution_request_id'] ?? null );
	}

	public function test_suggest_endpoint_maps_local_schema_errors_to_unprocessable_json_error(): void {
		update_option(
			'sentient_forms_actions_gravity_forms_42',
			[
				'actions' => [
					[
						'id' => 'local_first_99',
						'central_action_id' => 'clarification_assistant_v1',
						'action_name_label' => 'Realtime Clarification Assistant',
						'action_type_indicator' => 'master',
						'is_action_enabled_for_form' => true,
						'settings' => [
							'execution_mode' => 'real_time',
							'realtime_settings' => [
								'checkpoint_field_ids' => [ '1' ],
							],
						],
					],
				],
			]
		);

		$legacy_executor = new Sentient_Forms_Test_Suggest_Executor();
		$this->executor_property->setValue( $this->plugin, $legacy_executor );

		$local_repository = new Sentient_Forms_Test_Local_Form_Mappings_Repository(
			[
				99 => [
					'id' => 99,
					'form_source' => 'gravity_forms',
					'form_id' => '42',
					'hook' => 'real_time',
					'execution_mode' => 'real_time',
					'enabled' => 1,
				],
			]
		);
		$local_execution = new Sentient_Forms_Test_Local_Suggest_Execution_Service();
		$local_execution->next_result = new WP_Error(
			'sentient_forms_structured_output_validation_failed',
			'The provider response did not match the local action schema: virtual_questions is a required property of structured_output.',
			[ 'schema_source' => 'template' ]
		);
		$controller = new Sentient_Forms_Form_Suggestions_Controller( $local_repository, $local_execution );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'local_first_99' );
		$request->set_param( 'all_known_field_values', [ '1' => 'Quote product 183671 at 500pcs' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );

		$response = $controller->suggest( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'sentient_forms_structured_output_validation_failed', $response->get_error_code() );
		$this->assertSame( 'Suggestions are temporarily unavailable. Try again shortly.', $response->get_error_message() );
		$this->assertStringNotContainsString( 'virtual_questions', $response->get_error_message() );
		$this->assertStringNotContainsString( 'structured_output', $response->get_error_message() );
		$this->assertSame( 422, (int) ( $response->get_error_data()['status'] ?? 0 ) );
		$this->assertSame( 'template', $response->get_error_data()['schema_source'] ?? null );
		$this->assertCount( 1, $local_execution->calls );
		$this->assertSame( [], $legacy_executor->calls );
	}

	public function test_suggest_endpoint_preserves_local_execution_error_status(): void {
		update_option(
			'sentient_forms_actions_gravity_forms_42',
			[
				'actions' => [
					[
						'id' => 'local_first_99',
						'central_action_id' => 'clarification_assistant_v1',
						'action_name_label' => 'Realtime Clarification Assistant',
						'action_type_indicator' => 'master',
						'is_action_enabled_for_form' => true,
						'settings' => [
							'execution_mode' => 'real_time',
							'realtime_settings' => [
								'checkpoint_field_ids' => [ '1' ],
							],
						],
					],
				],
			]
		);

		$local_repository = new Sentient_Forms_Test_Local_Form_Mappings_Repository(
			[
				99 => [
					'id' => 99,
					'form_source' => 'gravity_forms',
					'form_id' => '42',
					'hook' => 'real_time',
					'execution_mode' => 'real_time',
					'enabled' => 1,
				],
			]
		);
		$local_execution = new Sentient_Forms_Test_Local_Suggest_Execution_Service();
		$local_execution->next_result = new WP_Error(
			'sentient_forms_provider_rate_limited',
			'Provider rate limit exceeded.',
			[ 'status' => 429 ]
		);
		$controller = new Sentient_Forms_Form_Suggestions_Controller( $local_repository, $local_execution );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'local_first_99' );
		$request->set_param( 'all_known_field_values', [ '1' => 'Quote product 183671 at 500pcs' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );

		$response = $controller->suggest( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'sentient_forms_provider_rate_limited', $response->get_error_code() );
		$this->assertSame( 429, (int) ( $response->get_error_data()['status'] ?? 0 ) );
	}

	public function test_suggest_endpoint_falls_back_to_known_values_for_visible_fields_and_builds_future_manifest(): void {
		$stub_executor = new Sentient_Forms_Test_Suggest_Executor();
		$this->executor_property->setValue( $this->plugin, $stub_executor );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'map_rt_1' );
		$request->set_param( 'all_known_field_values', [ '1' => 'hello', '2' => 'world' ] );
		$request->set_param( 'visible_field_ids', [] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 2 );

		$response = $this->controller->suggest( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 1, $stub_executor->calls );
		$context = $stub_executor->calls[0]['suggestion_context'];
		$this->assertSame( [ '1', '2' ], $context['visible_field_ids'] ?? [] );
		$this->assertNotEmpty( $context['future_field_manifest'] ?? [] );
		$this->assertSame( '4', $context['future_field_manifest'][0]['field_id'] ?? null );
		$this->assertSame( 2, $context['future_field_manifest'][0]['page_index'] ?? null );
	}

	public function test_suggest_endpoint_returns_not_found_for_non_realtime_mapping(): void {
		update_option(
			'sentient_forms_actions_gravity_forms_42',
			[
				'actions' => [
					[
						'id' => 'map_rt_1',
						'central_action_id' => 'central_rt_1',
						'is_action_enabled_for_form' => true,
						'settings' => [
							'execution_mode' => 'after_submission',
						],
					],
				],
			]
		);

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'map_rt_1' );
		$request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 1 );

		$response = $this->controller->suggest( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'rest_invalid_mapping', $response->get_error_code() );
		$this->assertSame( 404, (int) ( $response->get_error_data()['status'] ?? 0 ) );
	}

	public function test_suggest_endpoint_enforces_rate_limit_per_form_and_ip(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		set_transient( 'sentient_forms_rt_suggest_rl_' . md5( '42|203.0.113.10' ), 120, MINUTE_IN_SECONDS );

		$request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/42/actions/suggest' );
		$request->set_param( 'form_source_slug', 'gravity_forms' );
		$request->set_param( 'form_id', 42 );
		$request->set_param( 'mapping_id', 'map_rt_1' );
		$request->set_param( 'all_known_field_values', [ '1' => 'hello' ] );
		$request->set_param( 'visible_field_ids', [ '1' ] );
		$request->set_param( 'current_page_index', 1 );
		$request->set_param( 'total_pages', 1 );

		$response = $this->controller->suggest( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'rest_too_many_requests', $response->get_error_code() );
		$this->assertSame( 429, (int) ( $response->get_error_data()['status'] ?? 0 ) );
	}
}
