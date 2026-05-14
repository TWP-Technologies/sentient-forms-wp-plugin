<?php

if ( ! class_exists( 'Sentient_Forms_Test_Gravity_Meta_Store' ) ) {
	class Sentient_Forms_Test_Gravity_Meta_Store {
		private static array $meta = [];

		public static function reset(): void {
			self::$meta = [];
		}

		public static function get_meta( int $entry_id, string $meta_key ) {
			return self::$meta[ $entry_id ][ $meta_key ] ?? null;
		}

		public static function update_meta( int $entry_id, string $meta_key, $value ): void {
			if ( ! isset( self::$meta[ $entry_id ] ) ) {
				self::$meta[ $entry_id ] = [];
			}

			self::$meta[ $entry_id ][ $meta_key ] = $value;
		}
	}
}

if ( ! function_exists( 'gform_get_meta' ) ) {
	function gform_get_meta( $entry_id, $meta_key ) {
		return Sentient_Forms_Test_Gravity_Meta_Store::get_meta( (int) $entry_id, (string) $meta_key );
	}
}

if ( ! function_exists( 'gform_update_meta' ) ) {
	function gform_update_meta( $entry_id, $meta_key, $value ) {
		Sentient_Forms_Test_Gravity_Meta_Store::update_meta( (int) $entry_id, (string) $meta_key, $value );
	}
}

class ActionExecutorTest extends WP_UnitTestCase {
	private Sentient_Forms_Plugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->plugin = Sentient_Forms_Plugin::instance();
		update_option( 'sentient_forms_settings', [] );
		delete_option( 'sentient_forms_forced_execution_request_id' );
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => '',
			]
		);
		Sentient_Forms_Test_Gravity_Meta_Store::reset();
	}

	public function test_execute_requires_proxy_key(): void {
		$executor = new Sentient_Forms_Action_Executor( $this->plugin );
		$this->assertSame( '', $this->plugin->get_proxy_api_key(), 'Proxy key should default to empty for this test.' );
		$result   = $executor->execute( 'central', [ 'id' => 1 ], [], [] );

		$this->assertWPError( $result );
		$this->assertSame( 'cps_missing_proxy_key', $result->get_error_code() );
	}

	public function test_generate_execution_request_id_applies_filter_override(): void {
		$filter = static function ( $execution_request_id, ...$unused ) {
			return 'filtered-request-id-42';
		};

		add_filter( 'sentient_forms_execution_request_id', $filter, 10, 5 );
		$request_id = Sentient_Forms_Action_Executor::generate_execution_request_id(
			'spam_detection_v1',
			[ 'id' => 12, 'title' => 'Contact' ],
			[ 'id' => 456, 'field_1' => 'Hello' ],
			[ 'hook' => 'gform_after_submission', 'action_id' => 'map_spam' ]
		);
		remove_filter( 'sentient_forms_execution_request_id', $filter, 10 );

		$this->assertSame( 'filtered-request-id-42', $request_id );
	}

	public function test_execute_invokes_client_and_returns_response(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-123',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'ham',
						'llm_output'    => 'All good',
					],
					'meta'        => [
						'credits_debited' => 25,
					],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );

		$form     = [ 'id' => 12, 'title' => 'Contact' ];
		$entry    = [ 'id' => 99, 'field_1' => 'Hello' ];
		$context  = [ 'hook' => 'gform_after_submission', 'action_id' => 'spam_detection' ];
		$result   = $executor->execute( 'central-123', $form, $entry, $context );

		$this->assertIsArray( $result );
		$this->assertSame( 'ham', $result['result_data']['classification'] );
		$this->assertArrayHasKey( 'evaluation_payload', $result );
		$this->assertSame( 'ham', $result['evaluation_payload']['result_data']['classification'] );
		$this->assertCount( 1, $client->calls );

		$call = $client->calls[0];
		$this->assertSame( '/actions/execute', $call['path'] );
		$this->assertSame( 'central-123', $call['payload']['central_action_id'] );
		$this->assertArrayHasKey( 'file_refs', $call['payload'] );
		$this->assertSame( [], $call['payload']['file_refs'] );
		$this->assertArrayHasKey( 'input_manifest', $call['payload'] );
		$this->assertSame( 'legacy_fallback_field_ids', $call['payload']['input_manifest']['mapping_source'] ?? null );
		$this->assertSame( 'selected', $call['payload']['input_manifest']['mode'] ?? null );
		$this->assertFalse( $call['payload']['input_manifest']['full_entry_sent'] ?? true );
		$this->assertArrayHasKey( 'execution_request_id', $call['payload'] );
		$this->assertSame( 32, strlen( $call['payload']['execution_request_id'] ) );
		$this->assertSame(
			$call['payload']['execution_request_id'],
			$call['payload']['action_context']['execution_request_id']
		);
		$this->assertSame(
			'entry:99',
			$call['payload']['action_context']['submission_token']
		);
		$this->assertSame( 'proxy-123', $call['options']['bearer_token'] );
	}

	public function test_execute_includes_attachment_file_refs_when_builder_returns_values(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-attachments',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'ham',
						'llm_output'    => 'with attachment',
					],
					'meta'        => [
						'credits_debited' => 40,
					],
				];
			}
		};

		$builder = new class( $this->plugin ) extends Sentient_Forms_Attachment_File_Ref_Builder {
			public function build_for_execution( array $form, array $entry, array $context = array() ): array {
				return [
					'file_refs' => [
						[
							'file_ref_id'  => '5c6690fa-b084-4a95-a1f8-d2ffea84ad13',
							'source_type'  => 'media',
							'media_id'     => 55,
							'filename'     => 'brochure.pdf',
							'content_type' => 'application/pdf',
							'size_bytes'   => 1024,
							'pull_url'     => 'https://example.test/wp-json/sentient-forms/v1/files/pull/abc',
							'expires_at'   => '2026-02-26T20:00:00Z',
						],
					],
					'attachment_manifest' => [
						'attachment_mode' => 'media_library',
						'resolved_file_ref_count' => 1,
						'drop_reasons' => [],
					],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client, $builder );
		$result   = $executor->execute(
			'central-with-attachments',
			[ 'id' => 31, 'title' => 'Upload Form' ],
			[ 'id' => 990, 'field_1' => 'hello' ],
			[ 'hook' => 'gform_after_submission' ]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$payload = $client->calls[0]['payload'];
		$this->assertCount( 1, $payload['file_refs'] ?? [] );
		$this->assertSame( 'media', $payload['file_refs'][0]['source_type'] ?? null );
		$this->assertSame( 55, $payload['file_refs'][0]['media_id'] ?? null );
		$this->assertSame(
			'media_library',
			$payload['input_manifest']['attachment_manifest']['attachment_mode'] ?? null
		);
		$this->assertSame(
			1,
			$payload['input_manifest']['attachment_manifest']['resolved_file_ref_count'] ?? null
		);
	}

	public function test_suggest_invokes_suggest_endpoint_with_normalized_context(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-suggest',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'status'      => 'success',
					'suggestions' => [
						[
							'suggestion_id' => wp_generate_uuid4(),
							'field_id' => '1',
							'severity' => 'warning',
							'message' => 'Needs more detail.',
							'jump_target_field_id' => '1',
							'is_suppressed' => false,
						],
					],
					'meta' => [
						'execution_request_id' => $payload['execution_request_id'] ?? '',
						'credits_debited' => 4,
					],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form = [ 'id' => 55, 'title' => 'Realtime Form' ];
		$entry = [ '1' => 'hello', '2' => 'world' ];
		$context = [
			'hook' => 'real_time',
			'action_id' => 'map_rt_1',
			'settings' => [
				'execution_mode' => 'real_time',
			],
		];
		$suggestion_context = [
			'form_id' => '55',
			'source' => 'gravity_forms',
			'current_page_index' => 1,
			'total_pages' => 2,
			'visible_field_ids' => [ '1', '2' ],
			'all_known_field_values' => [ '1' => 'hello', '2' => 'world' ],
			'future_field_manifest' => [
				[
					'field_id' => '4',
					'type' => 'text',
					'page_index' => 2,
				],
			],
		];

		$response = $executor->suggest( 'central-rt-1', $form, $entry, $context, $suggestion_context );

		$this->assertIsArray( $response );
		$this->assertCount( 1, $client->calls );

		$call = $client->calls[0];
		$this->assertSame( '/actions/suggest', $call['path'] );
		$this->assertSame( 'proxy-suggest', $call['options']['bearer_token'] );
		$this->assertSame( 'central-rt-1', $call['payload']['central_action_id'] );
		$this->assertArrayHasKey( 'execution_request_id', $call['payload'] );
		$this->assertNotSame( '', $call['payload']['execution_request_id'] );
		$this->assertSame( [ '1', '2' ], $call['payload']['suggestion_context']['visible_field_ids'] ?? [] );
		$this->assertSame( '55', $call['payload']['suggestion_context']['form_id'] ?? null );
		$this->assertSame( 'gravity_forms', $call['payload']['suggestion_context']['source'] ?? null );
		$this->assertArrayHasKey( 'file_refs', $call['payload'] );
	}

	public function test_execute_reuses_cached_result_without_additional_requests(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-123',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'spam',
						'llm_output'     => 'Blocked',
					],
					'meta'        => [
						'credits_debited' => 10,
						'new_balance'     => 90,
					],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );

		$form    = [ 'id' => 44, 'title' => 'Signup' ];
		$entry   = [ 'id' => 555, 'field_1' => 'Hi' ];
		$context = [ 'hook' => 'gform_after_submission', 'action_id' => 'spam_detection' ];

		$first  = $executor->execute( 'central-dup', $form, $entry, $context );
		$second = $executor->execute( 'central-dup', $form, $entry, $context );

		$this->assertIsArray( $first );
		$this->assertArrayHasKey( 'evaluation_payload', $first );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $client->calls );
	}

	public function test_enqueue_async_invokes_execute_async_endpoint_with_normalized_options(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-123',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'job_id'               => wp_generate_uuid4(),
					'status'               => 'queued',
					'execution_request_id' => $payload['execution_request_id'] ?? '',
					'not_before'           => gmdate( DATE_ATOM, time() + 60 ),
					'max_wait_at'          => gmdate( DATE_ATOM, time() + DAY_IN_SECONDS ),
					'idempotent_reuse'     => false,
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form     = [ 'id' => 21, 'title' => 'Async Form' ];
		$entry    = [ 'id' => 707, 'field_1' => 'Hello async' ];
		$context  = [
			'hook'       => 'gform_after_submission',
			'action_id'  => 'entry_evaluation',
			'settings'   => [
				'batch_settings' => [
					'enabled'       => true,
					'delay_seconds' => 120,
				],
			],
		];

		$result = $executor->enqueue_async(
			'central-async-1',
			$form,
			$entry,
			$context,
			[
				'delay_seconds'    => 5,
				'max_wait_seconds' => 10,
			]
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$call = $client->calls[0];
		$this->assertSame( '/actions/execute-async', $call['path'] );
		$this->assertSame( 'proxy-123', $call['options']['bearer_token'] );
		$this->assertSame( 'central-async-1', $call['payload']['central_action_id'] );
		$this->assertSame( '21', $call['payload']['action_context']['form_id'] ?? null );
		$this->assertSame( 'gravity_forms', $call['payload']['action_context']['source'] ?? null );
		$this->assertSame( 'Hello async', $call['payload']['form_data_payload']['entry']['field_1'] ?? null );
		$this->assertArrayHasKey( 'file_refs', $call['payload'] );
		$this->assertSame( [], $call['payload']['file_refs'] );
		$this->assertArrayHasKey( 'input_manifest', $call['payload'] );
		$this->assertSame( 'legacy_fallback_field_ids', $call['payload']['input_manifest']['mapping_source'] ?? null );
		$this->assertSame( [ 'enabled' => true ], $call['payload']['callback'] ?? null );
		$this->assertSame( 10, $call['payload']['async_options']['delay_seconds'] );
		$this->assertSame( 43200, $call['payload']['async_options']['max_wait_seconds'] );
		$this->assertArrayHasKey( 'execution_request_id', $call['payload'] );
		$this->assertSame(
			$call['payload']['execution_request_id'],
			$call['payload']['action_context']['execution_request_id']
		);
	}

	public function test_enqueue_async_uses_context_batch_settings_when_async_options_omitted(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-ctx-batch',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'job_id'               => wp_generate_uuid4(),
					'status'               => 'queued',
					'execution_request_id' => $payload['execution_request_id'] ?? '',
					'not_before'           => gmdate( DATE_ATOM, time() + 60 ),
					'max_wait_at'          => gmdate( DATE_ATOM, time() + DAY_IN_SECONDS ),
					'idempotent_reuse'     => false,
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form     = [ 'id' => 99, 'title' => 'Batch Defaults' ];
		$entry    = [ 'id' => 808, 'field_1' => 'payload' ];
		$context  = [
			'hook'      => 'gform_after_submission',
			'action_id' => 'entry_evaluation',
			'settings'  => [
				'batch_settings' => [
					'enabled'          => true,
					'delay_seconds'    => 123,
					'max_wait_seconds' => 234567,
				],
			],
		];

		$result = $executor->enqueue_async(
			'central-async-ctx',
			$form,
			$entry,
			$context
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$call = $client->calls[0];
		$this->assertSame( '/actions/execute-async', $call['path'] );
		$this->assertSame( [ 'enabled' => true ], $call['payload']['callback'] ?? null );
		$this->assertSame( 123, $call['payload']['async_options']['delay_seconds'] );
		$this->assertSame( 234567, $call['payload']['async_options']['max_wait_seconds'] );
	}

	public function test_execute_uses_nested_input_mapping_and_includes_manifest(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-mapping',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'ham',
						'llm_output'     => 'Mapped payload',
					],
					'meta'        => [
						'credits_debited' => 5,
					],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form     = [ 'id' => 55, 'title' => 'Mapping Test' ];
		$entry    = [ 'id' => 123, 'field_1' => 'One', 'field_2' => 'Two', 'status' => 'active' ];
		$context  = [
			'hook'     => 'gform_validation',
			'action_id' => 'spam_detection',
			'settings' => [
				'settings' => [
					'input_mapping' => [
						'mode'             => 'selected',
						'field_ids'        => [ 'field_2' ],
						'include_metadata' => true,
					],
				],
			],
		];

		$result = $executor->execute( 'central-mapping', $form, $entry, $context );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$payload = $client->calls[0]['payload'];
		$this->assertSame( [ 'field_2' => 'Two' ], $payload['form_data_payload']['entry'] ?? [] );
		$this->assertSame( '55', $payload['form_data_payload']['form']['id'] ?? null );
		$this->assertSame( 'explicit_mapping', $payload['input_manifest']['mapping_source'] ?? null );
		$this->assertSame( [ 'field_2' ], $payload['input_manifest']['applied_entry_keys'] ?? [] );
		$this->assertSame( [ 'field_2' ], $payload['input_manifest']['requested_field_ids'] ?? [] );
	}

	public function test_execute_selected_mode_with_empty_field_ids_sends_no_entry_fields(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-selected-empty',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'ham',
						'llm_output'     => 'Selected empty',
					],
					'meta'        => [],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form     = [ 'id' => 90, 'title' => 'Selected Empty' ];
		$entry    = [ 'id' => 333, 'field_1' => 'A', 'field_2' => 'B', 'status' => 'active' ];
		$context  = [
			'hook'          => 'gform_after_submission',
			'input_mapping' => [
				'mode'             => 'selected',
				'field_ids'        => [],
				'include_metadata' => false,
			],
		];

		$result = $executor->execute( 'central-selected-empty', $form, $entry, $context );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$payload = $client->calls[0]['payload'];
		$this->assertSame( [], $payload['form_data_payload']['entry'] ?? [] );
		$this->assertArrayNotHasKey( 'form', $payload['form_data_payload'] );
		$this->assertSame( 'explicit_mapping', $payload['input_manifest']['mapping_source'] ?? null );
		$this->assertSame( 'selected', $payload['input_manifest']['mode'] ?? null );
		$this->assertSame( [], $payload['input_manifest']['requested_field_ids'] ?? [] );
		$this->assertSame( [], $payload['input_manifest']['applied_entry_keys'] ?? [] );
		$this->assertFalse( $payload['input_manifest']['full_entry_sent'] ?? true );
		$this->assertSame( 0, $payload['input_manifest']['entry_key_count_after'] ?? null );
	}

	public function test_execute_exclude_mode_omits_only_specified_fields(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-exclude',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'ham',
						'llm_output'     => 'Exclude one field',
					],
					'meta'        => [],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form     = [ 'id' => 91, 'title' => 'Exclude Mode' ];
		$entry    = [ 'field_1' => 'One', 'field_2' => 'Two', 'field_3' => 'Three' ];
		$context  = [
			'hook'          => 'gform_after_submission',
			'input_mapping' => [
				'mode'             => 'exclude',
				'field_ids'        => [ 'field_2' ],
				'include_metadata' => false,
			],
		];

		$result = $executor->execute( 'central-exclude', $form, $entry, $context );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$payload = $client->calls[0]['payload'];
		$this->assertSame(
			[
				'field_1' => 'One',
				'field_3' => 'Three',
			],
			$payload['form_data_payload']['entry'] ?? []
		);
		$this->assertSame( 'exclude', $payload['input_manifest']['mode'] ?? null );
		$this->assertSame( [ 'field_2' ], $payload['input_manifest']['requested_field_ids'] ?? [] );
		$this->assertSame( [ 'field_1', 'field_3' ], $payload['input_manifest']['applied_entry_keys'] ?? [] );
		$this->assertFalse( $payload['input_manifest']['full_entry_sent'] ?? true );
	}

	public function test_execute_legacy_fallback_avoids_entry_metadata_by_default(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-legacy',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'ham',
						'llm_output'     => 'Fallback payload',
					],
					'meta'        => [],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form     = [ 'id' => 88, 'title' => 'Legacy Fallback' ];
		$entry    = [
			'id'          => 909,
			'field_1'     => 'Hello',
			'field_2'     => 'World',
			'status'      => 'spam',
			'date_created' => '2026-02-16 00:00:00',
		];

		$result = $executor->execute( 'central-fallback', $form, $entry, [ 'hook' => 'gform_after_submission' ] );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$payload = $client->calls[0]['payload'];
		$this->assertSame(
			[
				'field_1' => 'Hello',
				'field_2' => 'World',
			],
			$payload['form_data_payload']['entry'] ?? []
		);
		$this->assertArrayNotHasKey( 'form', $payload['form_data_payload'] );
		$this->assertSame( 'legacy_fallback_field_ids', $payload['input_manifest']['mapping_source'] ?? null );
		$this->assertFalse( $payload['input_manifest']['include_metadata'] ?? true );
	}

	public function test_execute_explicit_all_mode_marks_manifest_and_includes_full_entry(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-all',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification' => 'ham',
						'llm_output'     => 'All fields',
					],
					'meta'        => [],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$form     = [ 'id' => 66, 'title' => 'All Mode' ];
		$entry    = [ 'field_1' => 'A', 'field_2' => 'B', 'status' => 'active' ];
		$context  = [
			'hook'          => 'gform_after_submission',
			'input_mapping' => [
				'mode'             => 'all',
				'include_metadata' => true,
			],
		];

		$result = $executor->execute( 'central-all', $form, $entry, $context );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $client->calls );

		$payload = $client->calls[0]['payload'];
		$this->assertSame( 'A', $payload['form_data_payload']['entry']['field_1'] ?? null );
		$this->assertSame( 'active', $payload['form_data_payload']['entry']['status'] ?? null );
		$this->assertSame( '66', $payload['form_data_payload']['form']['id'] ?? null );
		$this->assertSame( 'explicit_all', $payload['input_manifest']['mapping_source'] ?? null );
		$this->assertTrue( $payload['input_manifest']['full_entry_sent'] ?? false );
	}

	public function test_execute_returns_cached_result_when_duplicate_error_reported(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-123',
			]
		);

		$responses = [
			[
				'result_data' => [
					'classification' => 'ham',
					'llm_output'     => 'Looks fine',
				],
				'meta'        => [
					'credits_debited' => 15,
					'new_balance'     => 85,
				],
			],
		];

		$priming_client = new class( $responses ) extends Sentient_Forms_Api_Client {
			public array $calls = [];
			private array $responses;

			public function __construct( array $responses ) {
				$this->responses = $responses;
			}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return array_shift( $this->responses );
			}
		};

		$form    = [ 'id' => 77, 'title' => 'Support' ];
		$entry   = [ 'id' => 702, 'field_1' => 'Help' ];
		$context = [ 'hook' => 'gform_after_submission', 'action_id' => 'spam_detection' ];

		$executor       = new Sentient_Forms_Action_Executor( $this->plugin, $priming_client );
		$prime_response = $executor->execute( 'central-duplicate', $form, $entry, $context );

		$this->assertIsArray( $prime_response );
		$this->assertCount( 1, $priming_client->calls );

		$duplicate_client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return new WP_Error( 'duplicate_execution', 'Already processed' );
			}
		};

		$duplicate_executor = new Sentient_Forms_Action_Executor( $this->plugin, $duplicate_client );
		$duplicate_response = $duplicate_executor->execute( 'central-duplicate', $form, $entry, $context );

		$this->assertIsArray( $duplicate_response );
		$this->assertSame( $prime_response['result_data']['llm_output'], $duplicate_response['result_data']['llm_output'] );
		$this->assertCount( 0, $duplicate_client->calls );
	}

	/**
	 * T-PHP-033: Structured output fields from CPS pass through the executor unchanged.
	 * Tests CA-EXEC-001: structured_output, output_schema_version, structured_output_valid
	 * must be present in both result_data and evaluation_payload.
	 */
	public function test_execute_passes_through_structured_output_fields(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-structured',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public array $calls = [];

			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				$this->calls[] = [
					'path'    => $path,
					'payload' => $payload,
					'options' => $options,
				];

				return [
					'result_data' => [
						'classification'          => 'ham',
						'llm_output'              => '{"summary":"Looks good"}',
						'structured_output'       => [ 'summary' => 'Looks good' ],
						'output_schema_version'   => 5,
						'structured_output_valid' => true,
					],
					'meta'        => [
						'credits_debited' => 20,
						'new_balance'     => 80,
					],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );

		$form    = [ 'id' => 30, 'title' => 'Structured Form' ];
		$entry   = [ 'id' => 400, 'field_1' => 'Test' ];
		$context = [ 'hook' => 'gform_after_submission', 'action_id' => 'entry_evaluation' ];
		$result  = $executor->execute( 'central-structured', $form, $entry, $context );

		$this->assertIsArray( $result );

		// Verify structured output fields in result_data.
		$this->assertArrayHasKey( 'structured_output', $result['result_data'] );
		$this->assertSame( [ 'summary' => 'Looks good' ], $result['result_data']['structured_output'] );
		$this->assertSame( 5, $result['result_data']['output_schema_version'] );
		$this->assertTrue( $result['result_data']['structured_output_valid'] );

		// Verify structured output fields in evaluation_payload.
		$this->assertArrayHasKey( 'evaluation_payload', $result );
		$this->assertArrayHasKey( 'structured_output', $result['evaluation_payload']['result_data'] );
		$this->assertSame( [ 'summary' => 'Looks good' ], $result['evaluation_payload']['result_data']['structured_output'] );
		$this->assertTrue( $result['evaluation_payload']['result_data']['structured_output_valid'] );
	}

	public function test_execute_bridges_content_validation_structured_output_to_validation(): void {
		$this->plugin->set_license_data(
			[
				'proxy_api_key' => 'proxy-validation',
			]
		);

		$client = new class extends Sentient_Forms_Api_Client {
			public function __construct() {}

			public function post( string $path, array $payload, array $options = [] ): WP_Error | array {
				return [
					'result_data' => [
						'llm_output'              => '{"is_valid":false}',
						'structured_output'       => [
							'is_valid' => false,
							'message'  => 'Please provide more detail.',
							'fields'   => [
								[
									'field_id' => '3',
									'is_valid' => false,
									'message'  => 'Tell us more about your project.',
								],
							],
						],
						'structured_output_valid' => true,
					],
					'meta'        => [
						'credits_debited' => 8,
					],
				];
			}
		};

		$executor = new Sentient_Forms_Action_Executor( $this->plugin, $client );
		$result   = $executor->execute(
			'content_validation_v1',
			[ 'id' => 61, 'title' => 'Validation Form' ],
			[ 'id' => 910, '3' => 'short' ],
			[ 'hook' => 'gform_validation', 'action_id' => 'map_validation' ]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'validation', $result );
		$this->assertFalse( $result['validation']['is_valid'] );
		$this->assertSame( 'Please provide more detail.', $result['validation']['message'] );
		$this->assertSame( '3', $result['validation']['fields'][0]['field_id'] ?? null );
		$this->assertFalse( $result['validation']['fields'][0]['is_valid'] ?? true );
		$this->assertSame(
			'Tell us more about your project.',
			$result['validation']['fields'][0]['message'] ?? null
		);
	}
}
