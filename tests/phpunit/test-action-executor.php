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
		$this->assertCount( 1, $client->calls );

		$call = $client->calls[0];
		$this->assertSame( '/actions/execute', $call['path'] );
		$this->assertSame( 'central-123', $call['payload']['central_action_id'] );
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
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $client->calls );
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
}
