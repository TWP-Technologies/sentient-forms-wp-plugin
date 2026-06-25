<?php

if ( ! class_exists( 'GFAPI' ) ) {
	class GFAPI {
		/** @var array<int,array<string,mixed>> */
		public static array $entries = [];
		/** @var array<int,array<string,mixed>> */
		public static array $forms = [];

		public static function get_entry( $entry_id ) {
			$entry_id = (int) $entry_id;
			if ( isset( self::$entries[ $entry_id ] ) ) {
				return self::$entries[ $entry_id ];
			}

			return new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
		}

		public static function get_form( $form_id ) {
			$form_id = (int) $form_id;
			return self::$forms[ $form_id ] ?? false;
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

if ( ! class_exists( 'GFForms' ) ) {
	class GFForms {}
}

if ( ! class_exists( 'Sentient_Forms_Test_Gf_Meta_Store' ) ) {
	class Sentient_Forms_Test_Gf_Meta_Store {
		/** @var array<int,array<string,mixed>> */
		private static array $meta = [];

		public static function reset(): void {
			self::$meta = [];
		}

		public static function set_meta( int $entry_id, string $key, mixed $value ): void {
			if ( ! isset( self::$meta[ $entry_id ] ) ) {
				self::$meta[ $entry_id ] = [];
			}
			self::$meta[ $entry_id ][ $key ] = $value;
		}

		public static function get_meta( int $entry_id, string $key ): mixed {
			return self::$meta[ $entry_id ][ $key ] ?? null;
		}

		public static function update_meta( int $entry_id, string $key, mixed $value ): void {
			self::set_meta( $entry_id, $key, $value );
		}
	}
}

if ( ! function_exists( 'gform_get_meta' ) ) {
	function gform_get_meta( $entry_id, $meta_key ) {
		return Sentient_Forms_Test_Gf_Meta_Store::get_meta( (int) $entry_id, (string) $meta_key );
	}
}

if ( ! function_exists( 'gform_update_meta' ) ) {
	function gform_update_meta( $entry_id, $meta_key, $value ) {
		Sentient_Forms_Test_Gf_Meta_Store::update_meta( (int) $entry_id, (string) $meta_key, $value );
	}
}

if ( class_exists( 'Sentient_Forms_Mappings_Sync' ) && ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Empty_Workflow_Plan' ) ) {
	class Sentient_Forms_Test_Mappings_Sync_Empty_Workflow_Plan extends Sentient_Forms_Mappings_Sync {
		public function plan_workflow( string $form_source_slug, int $form_id, string $hook_scope = 'all' ) {
			return [
				'authority'         => 'cps',
				'policy_version'    => '2026-02-mixed-sync-async-v1',
				'hook_scope'        => $hook_scope,
				'available_hooks'   => [],
				'nodes'             => [],
				'edges'             => [],
				'hooks'             => [],
				'policy_violations' => [],
			];
		}

		public function fetch_mappings(): array {
			return [];
		}
	}
}

if ( class_exists( 'Sentient_Forms_Mappings_Sync' ) && ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan' ) ) {
	class Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan extends Sentient_Forms_Mappings_Sync {
		public int $plan_calls = 0;
		public int $sync_calls = 0;
		/** @var array<int, array<string,mixed>> */
		public array $synced_actions = [];

		public function plan_workflow( string $form_source_slug, int $form_id, string $hook_scope = 'all' ) {
			$this->plan_calls++;

			if ( 1 === $this->plan_calls ) {
				return [
					'authority'         => 'cps',
					'policy_version'    => '2026-02-mixed-sync-async-v1',
					'hook_scope'        => $hook_scope,
					'available_hooks'   => [],
					'nodes'             => [],
					'edges'             => [],
					'hooks'             => [],
					'policy_violations' => [],
				];
			}

			return [
				'authority'         => 'cps',
				'policy_version'    => '2026-02-mixed-sync-async-v1',
				'hook_scope'        => $hook_scope,
				'available_hooks'   => [ 'gform_validation' ],
				'nodes'             => [
					[
						'mapping_id'        => 'map_spam_v1',
						'label'             => 'Local Spam Detection',
						'central_action_id' => 'spam_detection_v1',
						'trigger_hooks'     => [ 'gform_validation' ],
						'dependency_ids'    => [],
						'is_enabled'        => true,
						'is_async'          => false,
					],
				],
				'edges'             => [],
				'hooks'             => [],
				'policy_violations' => [],
			];
		}

		public function sync_form_mappings_for_form( string $form_source_slug, int $form_id, array $local_actions, bool $include_disabled = true ) {
			$this->sync_calls++;
			$this->synced_actions = $local_actions;

			return [
				'counts' => [
					'create' => 1,
					'update' => 0,
					'delete' => 0,
					'skip'   => 0,
					'error'  => 0,
				],
			];
		}

		public function fetch_mappings(): array {
			return [];
		}
	}
}

if ( class_exists( 'Sentient_Forms_Mappings_Sync' ) && ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Counting_Fetch' ) ) {
	class Sentient_Forms_Test_Mappings_Sync_Counting_Fetch extends Sentient_Forms_Mappings_Sync {
		public int $fetch_calls = 0;

		public function get_site_id(): string {
			return 'site-overview-test';
		}

		public function fetch_mappings(): array {
			$this->fetch_calls++;

			return [
				[
					'id'                   => 'cps-form-1',
					'site_id'              => 'site-overview-test',
					'form_source'          => 'gravity_forms',
					'form_id'              => 1,
					'action_template_code' => 'spam_detection_v1',
					'display_name'         => 'Spam Detection',
					'settings'             => [
						'local_mapping_id'           => 'cps_map_form_1',
						'trigger_hooks'              => [ 'gform_validation' ],
						'is_action_enabled_for_form' => true,
					],
				],
				[
					'id'                   => 'cps-form-2',
					'site_id'              => 'site-overview-test',
					'form_source'          => 'gravity_forms',
					'form_id'              => 2,
					'action_template_code' => 'entry_summary_v1',
					'display_name'         => 'Entry Summary',
					'settings'             => [
						'local_mapping_id'           => 'cps_map_form_2',
						'trigger_hooks'              => [ 'gform_after_submission' ],
						'is_action_enabled_for_form' => false,
					],
				],
			];
		}
	}
}

if ( ! class_exists( 'Sentient_Forms_Test_Opaque_Form_Source_Adapter' ) ) {
	class Sentient_Forms_Test_Opaque_Form_Source_Adapter implements Sentient_Forms_Adapter_Interface {
		/** @var array<int,string> */
		public array $form_exists_calls = [];

		public function get_id(): string {
			return 'opaque_forms';
		}

		public function get_name(): string {
			return 'Opaque Forms';
		}

		public function is_active(): bool {
			return true;
		}

		public function get_forms(): array {
			return [
				[ 'id' => 'form-alpha_2026', 'name' => 'Alpha 2026' ],
			];
		}

		public function get_form_fields( $form_id ): array {
			return [];
		}

		public function get_entry_data( $entry_id, $form_id = null ) {
			return null;
		}

		public function update_entry_meta( $entry_id, string $meta_key, $meta_value ): bool {
			return false;
		}

		public function mark_entry_as_spam( mixed $entry_id ): bool {
			return false;
		}

		public function reject_submission( mixed $entry_id, string $message ): bool {
			return false;
		}

		public function add_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool {
			return false;
		}

		public function get_action_hook_for_event( string $event_name ): ?string {
			return null;
		}

		public function get_form_object( int $form_id ): object | array | null {
			return null;
		}

		public function form_exists( mixed $form_id ): bool {
			$form_id                   = (string) $form_id;
			$this->form_exists_calls[] = $form_id;

			return 'form-alpha_2026' === $form_id;
		}
	}
}

class Tests_Form_Actions_Controller extends WP_UnitTestCase {
    private Sentient_Forms_Form_Actions_Controller $controller;
    private ?Sentient_Forms_Test_Opaque_Form_Source_Adapter $opaque_form_adapter = null;

    protected function setUp(): void {
        parent::setUp();
        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_workspace_tables();
        $this->controller = new Sentient_Forms_Form_Actions_Controller();
        GFAPI::$entries = [];
        GFAPI::$forms = [];
        $this->reset_entry_meta_store();
        delete_option( 'sentient_forms_action_log' );
        delete_option( 'sentient_forms_form_status_gravity_forms_42' );
        delete_option( 'sentient_forms_actions_gravity_forms_1' );
        delete_option( 'sentient_forms_actions_gravity_forms_2' );
    }

    protected function tearDown(): void {
        remove_filter( 'sentient_forms_supported_form_sources', [ $this, 'add_opaque_form_source' ] );
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        remove_all_filters( 'sentient_forms_contact_form_7_forms' );
        remove_all_filters( 'sentient_forms_contact_form_7_form_object' );
        remove_all_filters( 'sentient_forms_wpforms_is_active' );
        remove_all_filters( 'sentient_forms_wpforms_native_entry_storage_available' );
        Sentient_Forms_Plugin::instance()->get_form_adapter_registry()->unregister_adapter( 'opaque_forms' );
        $this->opaque_form_adapter = null;
        parent::tearDown();
    }

    public function add_opaque_form_source( array $sources ): array
    {
        $sources[] = 'opaque_forms';
        return array_values( array_unique( $sources ) );
    }

    private function register_opaque_form_source_adapter(): Sentient_Forms_Test_Opaque_Form_Source_Adapter
    {
        add_filter( 'sentient_forms_supported_form_sources', [ $this, 'add_opaque_form_source' ] );

        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = new Sentient_Forms_Test_Opaque_Form_Source_Adapter();
        $registry->register_adapter( $adapter );

        $this->opaque_form_adapter = $adapter;

        return $adapter;
    }

    private function dispatch_form_actions_request( WP_REST_Request $request ): WP_REST_Response
    {
        add_action( 'rest_api_init', [ $this->controller, 'register_routes' ] );
        do_action( 'rest_api_init' );
        remove_action( 'rest_api_init', [ $this->controller, 'register_routes' ] );

        return rest_get_server()->dispatch( $request );
    }

    private function authenticate_rest_request( WP_REST_Request $request ): WP_REST_Request
    {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        return $request;
    }

    public function test_form_source_and_id_permission_checks_auth_before_source_or_form_existence(): void
    {
        wp_set_current_user( 0 );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/999/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 999 );

        $this->assertFalse( $this->controller->permissions_check_for_form_source_and_id( $request ) );
    }

    public function test_form_source_permission_checks_auth_before_source_validation(): void
    {
        wp_set_current_user( 0 );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/not_supported/forms/actions/overview' );
        $request->set_param( 'form_source_slug', 'not_supported' );

        $this->assertFalse( $this->controller->permissions_check_for_form_source( $request ) );
    }

    public function test_routed_form_actions_request_requires_auth_before_form_existence_validation(): void
    {
        wp_set_current_user( 0 );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/999/actions' );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( rest_authorization_required_code(), $response->get_status() );
        $this->assertSame( 'rest_forbidden', $data['code'] ?? null );
    }

    public function test_routed_form_actions_request_requires_auth_before_source_validation(): void
    {
        wp_set_current_user( 0 );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/not_supported/forms/overview' );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( rest_authorization_required_code(), $response->get_status() );
        $this->assertSame( 'rest_forbidden', $data['code'] ?? null );
    }

    public function test_get_ledger_settings_defaults_disabled_for_form(): void
    {
        GFAPI::$forms[1] = [
            'id'     => 1,
            'title'  => 'Contact Form',
            'fields' => [],
        ];

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/ledger-settings' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'gravity_forms', $data['form_source'] ?? null );
        $this->assertSame( '1', $data['form_id'] ?? null );
        $this->assertFalse( $data['enabled'] ?? true );
        $this->assertSame( 'sentient_submission_ledger_settings', $data['settings_source'] ?? null );
    }

    public function test_get_ledger_settings_includes_form_scoped_record_count(): void
    {
        GFAPI::$forms[1] = [
            'id'     => 1,
            'title'  => 'Contact Form',
            'fields' => [],
        ];

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '11111111-2222-4333-8444-555555555555',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '1',
                    'logical_fields_json' => [ 'name' => 'Ada' ],
                ]
            )
        );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '22222222-3333-4444-8555-666666666666',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '1',
                    'logical_fields_json' => [ 'name' => 'Grace' ],
                ]
            )
        );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '33333333-4444-4555-8666-777777777777',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '2',
                    'logical_fields_json' => [ 'name' => 'Katherine' ],
                ]
            )
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/ledger-settings' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 2, $data['record_count'] ?? null );
    }

    public function test_put_ledger_settings_enables_storage_for_form(): void
    {
        GFAPI::$forms[1] = [
            'id'     => 1,
            'title'  => 'Contact Form',
            'fields' => [],
        ];

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/ledger-settings' ) );
        $request->set_param( 'enabled', true );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $data['enabled'] ?? false );
        $this->assertNotEmpty( $data['enabled_at'] ?? null );
        $this->assertSame( get_current_user_id(), $data['enabled_by_user_id'] ?? null );
        $this->assertNull( $data['disabled_at'] ?? null );
    }

    public function test_put_ledger_settings_accepts_opaque_form_ids_for_non_gravity_sources(): void
    {
        $adapter = $this->register_opaque_form_source_adapter();

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/opaque_forms/forms/form-alpha_2026/ledger-settings' ) );
        $request->set_param( 'enabled', true );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'opaque_forms', $data['form_source'] ?? null );
        $this->assertSame( 'form-alpha_2026', $data['form_id'] ?? null );
        $this->assertTrue( $data['enabled'] ?? false );
        $this->assertSame( [ 'form-alpha_2026' ], $adapter->form_exists_calls );
    }

    public function test_put_ledger_settings_rejects_missing_opaque_form_when_adapter_can_verify(): void
    {
        $adapter = $this->register_opaque_form_source_adapter();

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/opaque_forms/forms/missing-form/ledger-settings' ) );
        $request->set_param( 'enabled', true );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'rest_form_not_found', $data['code'] ?? null );
        $this->assertSame( [ 'missing-form' ], $adapter->form_exists_calls );
    }

    public function test_get_submission_ledger_list_returns_captured_form_submissions(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'        => '44444444-5555-4666-8777-888888888888',
                    'form_source'            => 'gravity_forms',
                    'form_id'                => '1',
                    'native_entry_id'        => '123',
                    'native_entry_url'       => 'https://example.test/wp-admin/admin.php?page=gf_entries&id=1&lid=123',
                    'source_submitted_at'    => '2026-06-19 03:14:15',
                    'logical_fields_json'    => [
                        'name'  => 'Ada Lovelace',
                        'email' => 'ada@example.test',
                    ],
                    'provider_metadata_json' => [
                        'source' => 'gravity_forms',
                    ],
                    'file_refs_json'         => [],
                    'redaction_summary_json' => [
                        'redacted_keys' => [],
                    ],
                ]
            )
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $data['submissions'] ?? [] );
        $this->assertSame( '44444444-5555-4666-8777-888888888888', $data['submissions'][0]['submission_uuid'] ?? null );
        $this->assertSame( '123', $data['submissions'][0]['native_entry_id'] ?? null );
        $this->assertSame( 'Ada Lovelace', $data['submissions'][0]['logical_fields']['name'] ?? null );
        $this->assertSame( 'gravity_forms', $data['form_source'] ?? null );
        $this->assertSame( '1', $data['form_id'] ?? null );
    }

    public function test_get_submission_ledger_detail_returns_scoped_submission(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $submission_uuid = '55555555-6666-4777-8888-999999999999';
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'        => $submission_uuid,
                    'form_source'            => 'gravity_forms',
                    'form_id'                => '1',
                    'native_entry_id'        => '124',
                    'logical_fields_json'    => [
                        'message' => 'Please call me.',
                    ],
                    'provider_metadata_json' => null,
                    'file_refs_json'         => [],
                    'redaction_summary_json' => [
                        'redacted_keys' => [ 'captcha_token' ],
                    ],
                ]
            )
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions/' . $submission_uuid ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $submission_uuid, $data['submission_uuid'] ?? null );
        $this->assertSame( '124', $data['native_entry_id'] ?? null );
        $this->assertSame( 'Please call me.', $data['logical_fields']['message'] ?? null );
        $this->assertSame( [ 'captcha_token' ], $data['redaction_summary']['redacted_keys'] ?? null );
    }

    public function test_form_actions_bootstrap_includes_submission_ledger_settings(): void
    {
        GFAPI::$forms[1] = [
            'id'     => 1,
            'title'  => 'Contact Form',
            'fields' => [],
        ];

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/bootstrap' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'sentient_submission_ledger_settings', $data['ledger_settings']['settings_source'] ?? null );
        $this->assertFalse( $data['ledger_settings']['enabled'] ?? true );
    }

    public function test_get_form_execution_status_falls_back_to_latest_action_log_entry(): void
    {
        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'      => 'gravity_forms',
                    'form_id'          => 42,
                    'entry_id'         => 110,
                    'action_code'      => 'entry_summary_v1',
                    'action_label'     => 'Entry Summary',
                    'status'           => 'success',
                    'result_summary'   => 'Entry summary completed.',
                    'credits_used'     => 12,
                    'created_at'       => '2026-04-14T20:43:26+00:00',
                    'details'          => [
                        'meta' => [
                            'execution_request_id' => 'req-entry-110',
                        ],
                    ],
                ],
                [
                    'form_source'    => 'gravity_forms',
                    'form_id'        => 7,
                    'entry_id'       => 999,
                    'status'         => 'error',
                    'error_code'     => 'wrong_form',
                    'error_message'  => 'Wrong form.',
                    'result_summary' => 'Wrong form.',
                    'created_at'     => '2026-04-14T20:40:00+00:00',
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/status' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_form_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'Entry summary completed.', $data['message'] ?? null );
        $this->assertSame( 110, $data['entry_id'] ?? null );
        $this->assertSame( '2026-04-14T20:43:26+00:00', $data['updated_at'] ?? null );
        $this->assertSame( 'req-entry-110', $data['last_result']['meta']['execution_request_id'] ?? null );
    }

    public function test_get_form_execution_status_prefers_local_execution_event_over_legacy_action_log(): void
    {
        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'    => 'gravity_forms',
                    'form_id'        => 42,
                    'entry_id'       => 762,
                    'action_code'    => 'sentient_forms_local_custom_action',
                    'action_label'   => 'Local OpenRouter action',
                    'status'         => 'error',
                    'error_code'     => '401',
                    'error_message'  => 'Missing Authentication header',
                    'result_summary' => '[]',
                    'created_at'     => '2026-04-21T10:51:48+00:00',
                ],
            ],
            false
        );

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-local-status-1',
                'form_source'          => 'gravity_forms',
                'form_id'              => 42,
                'entry_id'             => 111,
                'provider'             => 'openrouter',
                'model'                => 'openai/gpt-oss-20b:free',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'classification' => 'ham',
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/status' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_form_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'Local action completed.', $data['message'] ?? null );
        $this->assertSame( 111, $data['entry_id'] ?? null );
        $this->assertSame( 'ham', $data['last_result']['structured']['classification'] ?? null );
        $this->assertNull( $data['last_error_code'] ?? null );
    }

    public function test_get_form_execution_status_normalizes_imported_success_local_event(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-imported-success-status',
                'form_source'          => 'gravity_forms',
                'form_id'              => 42,
                'entry_id'             => 112,
                'provider'             => 'openrouter',
                'model'                => 'openai/gpt-oss-20b:free',
                'status'               => 'success',
                'result_json'          => [
                    'structured' => [
                        'summary' => 'Imported local execution completed.',
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/status' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_form_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'Local action completed.', $data['message'] ?? null );
        $this->assertSame( 'Imported local execution completed.', $data['last_result']['structured']['summary'] ?? null );
    }

    public function test_get_form_execution_status_ignores_retired_legacy_proxy_auth_action_log(): void
    {
        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'    => 'gravity_forms',
                    'form_id'        => 42,
                    'entry_id'       => 762,
                    'action_code'    => 'sentient_forms_local_custom_action',
                    'action_label'   => 'Local OpenRouter action',
                    'status'         => 'error',
                    'error_code'     => '401',
                    'error_message'  => 'Missing Authentication header',
                    'result_summary' => '[]',
                    'created_at'     => '2026-04-21T10:51:48+00:00',
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/status' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_form_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'unknown', $data['status'] ?? null );
        $this->assertNull( $data['message'] ?? null );
        $this->assertNull( $data['last_error_code'] ?? null );
        $this->assertNull( $data['last_result'] ?? null );
    }

    public function test_get_form_execution_status_ignores_unbacked_local_first_success_action_log(): void
    {
        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'          => 'gravity_forms',
                    'form_id'              => 42,
                    'entry_id'             => 113,
                    'action_code'          => 'sentient_forms_local_custom_action',
                    'action_label'         => 'Local OpenRouter action',
                    'status'               => 'success',
                    'result_summary'       => 'Synthetic imported success.',
                    'execution_request_id' => 'req-synthetic-local-success',
                    'mapping_id'           => 'local_first_12',
                    'created_at'           => '2026-04-21T11:00:00+00:00',
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/status' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_form_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'unknown', $data['status'] ?? null );
        $this->assertNull( $data['message'] ?? null );
        $this->assertNull( $data['last_error_code'] ?? null );
        $this->assertNull( $data['last_result'] ?? null );
    }

    public function test_get_form_execution_status_ignores_stale_local_action_inactive_failures(): void
    {
        global $wpdb;

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-local-action-inactive',
                'form_source'          => 'gravity_forms',
                'form_id'              => 42,
                'entry_id'             => 113,
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'failed',
                'error_code'           => 'sentient_forms_local_action_inactive',
                'error_message'        => 'Local action is not active.',
            ]
        );

        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'    => 'gravity_forms',
                    'form_id'        => 42,
                    'entry_id'       => 113,
                    'action_code'    => 'sentient_forms_local_custom_action',
                    'action_label'   => 'Local OpenRouter action',
                    'status'         => 'error',
                    'error_code'     => 'sentient_forms_local_action_inactive',
                    'error_message'  => 'Local action is not active.',
                    'result_summary' => 'Local action is not active.',
                    'created_at'     => '2026-04-22T09:31:52+00:00',
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/status' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_form_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'unknown', $data['status'] ?? null );
        $this->assertNull( $data['message'] ?? null );
        $this->assertNull( $data['last_error_code'] ?? null );
        $this->assertNull( $data['last_result'] ?? null );
    }

    public function test_get_entry_execution_status_includes_metering_summary_for_workflow_meta(): void
    {
        GFAPI::$entries[123] = [
            'id' => 123,
            'form_id' => 42,
        ];

        $this->set_entry_meta(
            123,
            'sentient_forms_last_response',
            wp_json_encode(
                [
                    'meta' => [
                        'execution_request_id' => 'req-abc-123',
                        'correlation_id' => 'req-abc-123',
                        'credits_debited' => 9,
                        'pricing' => [
                            'pricing_policy_version' => '2026-02-cps-batch-v1',
                        ],
                        'workflow_execution' => [
                            'status' => 'partial',
                            'credits_total' => 9,
                            'credits_by_node' => [
                                'extract' => 6,
                                'decide' => 3,
                            ],
                            'nodes' => [
                                [
                                    'node_id' => 'extract',
                                    'status' => 'succeeded',
                                ],
                                [
                                    'node_id' => 'finalize',
                                    'status' => 'failed',
                                ],
                            ],
                        ],
                    ],
                ]
            )
        );
        $this->set_entry_meta( 123, 'sentient_forms_last_error', '' );
        $this->set_entry_meta( 123, 'sentient_forms_last_processed_at', '2026-02-27T12:00:00Z' );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/42/actions/entries/123/status' );
        $request->set_param( 'entry_id', 123 );

        $response = $this->controller->get_entry_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'req-abc-123', $data['metering_summary']['correlation_id'] ?? null );
        $this->assertSame( 9, $data['metering_summary']['credits_debited'] ?? null );
        $this->assertSame( 'partial', $data['metering_summary']['workflow']['status'] ?? null );
        $this->assertSame( 9, $data['metering_summary']['workflow']['credits_total'] ?? null );
        $this->assertSame( 6, $data['metering_summary']['workflow']['credits_by_node']['extract'] ?? null );
        $this->assertSame( [ 'finalize' ], $data['metering_summary']['workflow']['failed_nodes'] ?? [] );
    }

    public function test_get_entry_execution_status_sets_metering_summary_null_without_meta(): void
    {
        GFAPI::$entries[456] = [
            'id' => 456,
            'form_id' => 17,
        ];

        $this->set_entry_meta( 456, 'sentient_forms_last_response', null );
        $this->set_entry_meta( 456, 'sentient_forms_last_error', '' );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/17/actions/entries/456/status' );
        $request->set_param( 'entry_id', 456 );

        $response = $this->controller->get_entry_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'unknown', $data['status'] ?? null );
        $this->assertNull( $data['metering_summary'] ?? null );
    }

    public function test_get_wpforms_entry_execution_status_uses_submission_ledger_and_local_execution_events(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $submission_uuid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created         = $ledger->create(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'wpforms',
                'form_id'             => '47',
                'native_entry_id'     => '779',
                'native_entry_url'    => admin_url( 'admin.php?page=wpforms-entries&view=details&entry_id=779' ),
                'logical_fields_json' => [
                    'full_name' => 'Ada Lovelace',
                ],
            ]
        );
        $this->assertIsInt( $created );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-wpforms-native-779',
                'form_source'          => 'wpforms',
                'form_id'              => '47',
                'entry_id'             => '779',
                'submission_uuid'      => $submission_uuid,
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'meta' => [
                        'execution_request_id' => 'req-wpforms-native-779',
                        'correlation_id'       => 'corr-wpforms-native-779',
                        'credits_debited'      => 3,
                    ],
                    'structured' => [
                        'summary' => 'Qualified lead.',
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/wpforms/forms/47/actions/entries/779/status' );
        $request->set_param( 'form_source_slug', 'wpforms' );
        $request->set_param( 'form_id', 47 );
        $request->set_param( 'entry_id', 779 );

        $response = $this->controller->get_entry_execution_status( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'wpforms', $data['form_source'] ?? null );
        $this->assertSame( 47, $data['form_id'] ?? null );
        $this->assertSame( 779, $data['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $data['submission_uuid'] ?? null );
        $this->assertSame( 'Qualified lead.', $data['last_response']['structured']['summary'] ?? null );
        $this->assertNull( $data['last_error'] ?? null );
        $this->assertSame( 'corr-wpforms-native-779', $data['metering_summary']['correlation_id'] ?? null );
        $this->assertSame( 3, $data['metering_summary']['credits_debited'] ?? null );
    }

    public function test_validate_trigger_hooks_accepts_allowed_values(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'gform_validation', 'gform_after_submission', 'real_time' ],
            $request,
            'trigger_hooks'
        );

        $this->assertTrue( $result );
    }

    public function test_validate_trigger_hooks_accepts_canonical_lifecycle_ids_and_legacy_aliases(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'validation', 'gform_after_submission', 'real_time' ],
            $request,
            'trigger_hooks'
        );

        $this->assertTrue( $result );
    }

    public function test_validate_trigger_hooks_rejects_unknown_hook(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'gform_bogus_hook' ],
            $request,
            'trigger_hooks'
        );

        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_hook', $result->get_error_code() );
    }

    public function test_add_form_action_sanitizes_trigger_hooks(): void {
        delete_option( 'sentient_forms_actions_gravity_forms_1' );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation', 'evil_hook', 'gform_validation' ] );

        $response = $this->controller->add_form_action( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data     = $response->get_data();

        $this->assertSame( [ 'validation' ], $data['trigger_hooks'] );

        global $wpdb;
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored   = $mappings->get( (int) str_replace( 'local_first_', '', (string) ( $data['local_mapping_id'] ?? '' ) ) );

        $this->assertSame( 'validation', $stored['hook'] ?? null );
    }

    public function test_add_form_action_rejects_malformed_spam_guidance_settings(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param(
            'settings',
            [
                'spam_negative_examples' => [
                    [
                        'text' => 'Missing rationale',
                    ],
                ],
            ]
        );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'spam_negative_examples', $response->get_error_data()['field'] ?? null );
    }

    public function test_update_form_action_item_rejects_string_spam_policy_setting(): void {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'suppress_webhooks_on_spam' => 'true',
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'suppress_webhooks_on_spam', $response->get_error_data()['field'] ?? null );
    }

    public function test_update_form_action_item_rejects_string_spam_confidence_threshold(): void {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'spam_confidence_threshold' => '0.70',
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'spam_confidence_threshold', $response->get_error_data()['field'] ?? null );
    }

    public function test_update_form_action_item_rejects_invalid_action_customization(): void {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'action_customization' => str_repeat( 'x', 2001 ),
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'action_customization', $response->get_error_data()['field'] ?? null );
    }

    public function test_add_form_action_creates_local_first_bundled_mapping_with_canonical_identity(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $credential_id = $credentials->create(
            [
                'provider'      => 'openrouter',
                'label'         => 'Owner OpenRouter key',
                'auth_mode'     => 'constant',
                'constant_name' => 'SENTIENT_FORMS_OPENROUTER_KEY',
                'status'        => 'valid',
            ]
        );
        $this->assertIsInt( $credential_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/12/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 12 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation', 'evil_hook', 'gform_after_submission' ] );
        $request->set_param(
            'settings',
            [
                'suppress_notifications_on_spam' => false,
                'skip_downstream_on_spam'        => true,
                'spam_confidence_threshold'      => 0.65,
                'spam_result_display_mode'       => 'all_results',
                'spam_indicators_display'        => 'detailed',
                'input_mapping'                  => [
                    'email' => '3',
                ],
            ]
        );

        $response = $this->controller->add_form_action( $request );
        $data     = $response->get_data();

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
        $this->assertSame( 'spam_detection_v1', $data['central_action_id'] ?? null );
        $this->assertSame( 'Spam Detection', $data['action_name_label'] ?? null );
        $this->assertSame( [ 'validation' ], $data['trigger_hooks'] ?? null );
        $this->assertSame( 'active', $data['linked_action_status'] ?? null );
        $this->assertSame( 'ok', $data['repair_state'] ?? null );

        $templates      = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $template = $templates->get_by_code( 'spam_detection_v1' );
        $this->assertIsArray( $template );
        $this->assertSame( 'bundled', $template['source'] ?? null );

        $custom_action = $custom_actions->get_by_code(
            Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'spam_detection_v1' )
        );
        $this->assertIsArray( $custom_action );
        $this->assertSame( (int) ( $template['id'] ?? 0 ), (int) ( $custom_action['template_id'] ?? 0 ) );
        $this->assertSame( 'active', $custom_action['status'] ?? null );
        $this->assertSame( $credential_id, (int) ( $custom_action['model_selection_json']['credential_id'] ?? 0 ) );

        $stored_mappings = $mappings->list_for_form( 'gravity_forms', '12' );
        $this->assertCount( 2, $stored_mappings );

        $validation_mapping = $this->find_local_first_mapping_by_hook( $stored_mappings, 'validation' );
        $submission_mapping = $this->find_local_first_mapping_by_hook( $stored_mappings, 'after_submission' );

        $this->assertIsArray( $validation_mapping );
        $this->assertIsArray( $submission_mapping );
        $this->assertSame( 'sync', $validation_mapping['execution_mode'] ?? null );
        $this->assertSame( 'sync', $submission_mapping['execution_mode'] ?? null );
        $this->assertSame( [ 'email' => '3' ], $validation_mapping['input_bindings_json'] ?? null );
        $this->assertFalse( $validation_mapping['effect_mapping_json']['spam']['suppress_notifications_on_spam'] ?? true );
        $this->assertTrue( $validation_mapping['effect_mapping_json']['spam']['skip_downstream_on_spam'] ?? false );
        $this->assertSame( 0.65, $validation_mapping['effect_mapping_json']['spam']['min_confidence'] ?? null );
        $this->assertSame( 'all_results', $validation_mapping['effect_mapping_json']['spam']['note']['result_display_mode'] ?? null );
        $this->assertSame( 'detailed', $validation_mapping['effect_mapping_json']['spam']['note']['indicators_display'] ?? null );
    }

    public function test_add_form_action_creates_local_first_content_validation_mapping_only_on_supported_hook(): void
    {
        $data = $this->create_bundled_local_first_mapping(
            13,
            'content_validation_v1',
            [ 'gform_validation', 'gform_after_submission' ],
            [
                'input_mapping' => [
                    'message' => '4',
                ],
            ]
        );

        $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
        $this->assertSame( 'content_validation_v1', $data['central_action_id'] ?? null );
        $this->assertSame( 'Content Quality Validation', $data['action_name_label'] ?? null );
        $this->assertSame( [ 'validation' ], $data['trigger_hooks'] ?? null );
        $this->assertSame( 'active', $data['linked_action_status'] ?? null );

        global $wpdb;

        $mappings        = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored_mappings = $mappings->list_for_form( 'gravity_forms', '13' );

        $this->assertCount( 1, $stored_mappings );
        $this->assertSame( 'validation', $stored_mappings[0]['hook'] ?? null );
        $this->assertSame( 'sync', $stored_mappings[0]['execution_mode'] ?? null );
        $this->assertSame( [ 'message' => '4' ], $stored_mappings[0]['input_bindings_json'] ?? null );
        $this->assertTrue( $stored_mappings[0]['effect_mapping_json']['store_result'] ?? false );
        $this->assertSame( 'structured.message', $stored_mappings[0]['effect_mapping_json']['entry_note']['path'] ?? null );
    }

    public function test_add_form_action_rejects_missing_bundled_local_first_dependency(): void
    {
        global $wpdb;

        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/21/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 21 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param(
            'settings',
            [
                'trigger_sources' => [
                    'gform_validation' => [
                        'type'       => 'mapping',
                        'mapping_id' => 'local_first_999999',
                    ],
                ],
                'dependency_ids'   => [ 'local_first_999999' ],
            ]
        );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_dependency_missing', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] ?? null );
        $this->assertSame( [], $mappings->list_for_form( 'gravity_forms', '21' ) );
    }

    public function test_add_form_action_maps_existing_custom_action_to_local_first_execution(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id = $custom_actions->create(
            [
                'code'                 => 'route_lead_custom',
                'display_name'         => 'Route Lead Custom',
                'definition_json'      => [
                    'action_kind'     => 'custom_definition',
                    'prompt_template' => 'Route {{entry}}.',
                    'response_format' => [ 'type' => 'json_object' ],
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'primary'  => 'openrouter/auto',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/15/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 15 );
        $request->set_param( 'central_action_id', 'route_lead_custom' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'gform_after_submission' ] );
        $request->set_param(
            'settings',
            [
                'input_mapping' => [
                    'email' => '3',
                ],
            ]
        );

        $response = $this->controller->add_form_action( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
        $this->assertSame( 'route_lead_custom', $data['central_action_id'] ?? null );
        $this->assertSame( 'Route Lead Custom', $data['action_name_label'] ?? null );

        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored_mappings = $mappings->list_for_form( 'gravity_forms', '15' );

        $this->assertCount( 1, $stored_mappings );
        $this->assertSame( $action_id, (int) ( $stored_mappings[0]['action_id'] ?? 0 ) );
        $this->assertSame( 'custom_action', $stored_mappings[0]['action_kind'] ?? null );
        $this->assertSame( 'async', $stored_mappings[0]['execution_mode'] ?? null );
        $this->assertSame( [ 'email' => '3' ], $stored_mappings[0]['input_bindings_json'] ?? null );
    }

    public function test_add_form_action_preserves_custom_action_trigger_sources(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $parent_action_id = $custom_actions->create(
            [
                'code'                 => 'parent_custom_action',
                'display_name'         => 'Parent Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Parent {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $parent_action_id );

        $parent_mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '16',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $parent_action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $parent_mapping_id );

        $child_action_id = $custom_actions->create(
            [
                'code'                 => 'child_custom_action',
                'display_name'         => 'Child Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Child {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $child_action_id );

        $parent_linkage_id = 'local_first_' . $parent_mapping_id;
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/16/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 16 );
        $request->set_param( 'central_action_id', 'child_custom_action' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'gform_after_submission' ] );
        $request->set_param(
            'settings',
            [
                'trigger_sources' => [
                    'gform_after_submission' => [
                        'type'       => 'mapping',
                        'mapping_id' => $parent_linkage_id,
                    ],
                ],
                'dependency_ids'   => [ $parent_linkage_id ],
            ]
        );

        $response = $this->controller->add_form_action( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( $parent_linkage_id, $data['settings']['trigger_sources']['after_submission']['mapping_id'] ?? null );
        $this->assertSame( [ $parent_linkage_id ], $data['settings']['dependency_ids'] ?? null );

        $stored_mappings = $mappings->list_for_form( 'gravity_forms', '16' );
        $child_mapping = null;
        foreach ( $stored_mappings as $mapping )
        {
            if ( $child_action_id === (int) ( $mapping['action_id'] ?? 0 ) )
            {
                $child_mapping = $mapping;
                break;
            }
        }

        $this->assertIsArray( $child_mapping );
        $this->assertSame( $parent_linkage_id, $child_mapping['settings_json']['trigger_sources']['after_submission']['mapping_id'] ?? null );
        $this->assertSame( [ $parent_linkage_id ], $child_mapping['settings_json']['dependency_ids'] ?? null );
    }

    public function test_add_form_action_allows_existing_custom_action_dependency_on_option_backed_mapping(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'                 => 'option_parent_dependent_custom_action',
                'display_name'         => 'Option Parent Dependent Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Use {{entry}} after parent.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $option_key = 'sentient_forms_actions_gravity_forms_19';
        update_option(
            $option_key,
            [
                'map_parent' => [
                    'local_mapping_id'           => 'map_parent',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'is_action_enabled_for_form' => true,
                    'settings'                   => [
                        'execution_mode' => 'after_submission',
                    ],
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/19/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 19 );
        $request->set_param( 'central_action_id', 'option_parent_dependent_custom_action' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'gform_after_submission' ] );
        $request->set_param(
            'settings',
            [
                'execution_mode'  => 'after_submission',
                'trigger_sources' => [
                    'gform_after_submission' => [
                        'type'       => 'mapping',
                        'mapping_id' => 'map_parent',
                    ],
                ],
                'dependency_ids'   => [ 'map_parent' ],
            ]
        );

        $response = $this->controller->add_form_action( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'map_parent', $data['settings']['trigger_sources']['after_submission']['mapping_id'] ?? null );

        delete_option( $option_key );
    }

    public function test_add_form_action_rejects_missing_custom_action_dependency(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'                 => 'orphan_dependency_custom_action',
                'display_name'         => 'Orphan Dependency Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Run {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/17/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 17 );
        $request->set_param( 'central_action_id', 'orphan_dependency_custom_action' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'gform_after_submission' ] );
        $request->set_param(
            'settings',
            [
                'trigger_sources' => [
                    'gform_after_submission' => [
                        'type'       => 'mapping',
                        'mapping_id' => 'local_first_999999',
                    ],
                ],
                'dependency_ids'   => [ 'local_first_999999' ],
            ]
        );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_dependency_missing', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] ?? null );
        $this->assertSame( [], $mappings->list_for_form( 'gravity_forms', '17' ) );
    }

    public function test_add_form_action_rejects_multi_hook_custom_action_without_partial_write(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'                 => 'multi_hook_partial_custom_action',
                'display_name'         => 'Multi Hook Partial Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Run {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/20/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 20 );
        $request->set_param( 'central_action_id', 'multi_hook_partial_custom_action' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation', 'gform_after_submission' ] );
        $request->set_param(
            'settings',
            [
                'trigger_sources' => [
                    'gform_validation'       => [
                        'type' => 'hook_root',
                    ],
                    'gform_after_submission' => [
                        'type'       => 'mapping',
                        'mapping_id' => 'local_first_999999',
                    ],
                ],
                'dependency_ids'   => [ 'local_first_999999' ],
            ]
        );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_dependency_missing', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] ?? null );
        $this->assertSame( [], $mappings->list_for_form( 'gravity_forms', '20' ) );
    }

    public function test_update_form_action_rejects_missing_custom_action_dependency(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'                 => 'update_dependency_custom_action',
                'display_name'         => 'Update Dependency Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Update {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '18',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $request = new WP_REST_Request( 'PATCH', '/sentient-forms/v1/gravity_forms/forms/18/actions/local_first_' . $mapping_id );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 18 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $mapping_id );
        $request->set_param(
            'settings',
            [
                'trigger_sources' => [
                    'gform_after_submission' => [
                        'type'       => 'mapping',
                        'mapping_id' => 'local_first_999999',
                    ],
                ],
                'dependency_ids'   => [ 'local_first_999999' ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_dependency_missing', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] ?? null );
        $stored = $mappings->get( $mapping_id );
        $this->assertArrayNotHasKey( 'trigger_sources', $stored['settings_json'] ?? [] );
    }

    public function test_add_form_action_creates_local_first_entry_summary_mapping_only_on_supported_hook(): void
    {
        $data = $this->create_bundled_local_first_mapping(
            14,
            'entry_summary_v1',
            [ 'gform_validation', 'gform_after_submission' ],
            [
                'execution_mode' => 'after_submission',
            ]
        );

        $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
        $this->assertSame( 'entry_summary_v1', $data['central_action_id'] ?? null );
        $this->assertSame( 'Entry Summary', $data['action_name_label'] ?? null );
        $this->assertSame( [ 'after_submission' ], $data['trigger_hooks'] ?? null );
        $this->assertSame( 'active', $data['linked_action_status'] ?? null );

        global $wpdb;

        $custom_actions  = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings        = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored_mappings = $mappings->list_for_form( 'gravity_forms', '14' );

        $this->assertCount( 1, $stored_mappings );
        $this->assertSame( 'after_submission', $stored_mappings[0]['hook'] ?? null );
        $this->assertSame( 'async', $stored_mappings[0]['execution_mode'] ?? null );
        $this->assertSame( 'content', $stored_mappings[0]['effect_mapping_json']['meta']['sentient_forms_summary'] ?? null );
        $this->assertSame( 'content', $stored_mappings[0]['effect_mapping_json']['entry_note']['path'] ?? null );

        $custom_action = $custom_actions->get_by_code(
            Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'entry_summary_v1' )
        );
        $this->assertIsArray( $custom_action );
        $this->assertSame( 'active', $custom_action['status'] ?? null );
    }

    public function test_rest_add_form_action_accepts_legacy_gravity_after_submission_hook(): void
    {
        GFAPI::$forms[15] = [
            'id'    => 15,
            'title' => 'REST Hook Validation Fixture',
        ];

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/15/actions' );
        $request->set_body_params(
            [
                'central_action_id'     => 'entry_summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'action_name_label'     => 'Entry Summary',
                'settings'              => [
                    'execution_mode'  => 'after_submission',
                    'trigger_sources' => [
                        'gform_after_submission' => [
                            'type' => 'hook_root',
                        ],
                    ],
                ],
            ]
        );

        $response = $this->dispatch_form_actions_request( $this->authenticate_rest_request( $request ) );

        $this->assertSame( 201, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'entry_summary_v1', $data['central_action_id'] ?? null );
        $this->assertSame( [ 'after_submission' ], $data['trigger_hooks'] ?? null );
    }

    public function test_add_form_action_creates_marketer_ready_bundled_after_submission_mappings(): void
    {
        global $wpdb;

        $expectations = [
            'sentiment_urgency_v1'     => [
                'display_name' => 'Sentiment and Urgency',
                'meta'         => [
                    'sentient_forms_sentiment'            => 'structured.sentiment',
                    'sentient_forms_urgency'              => 'structured.urgency',
                    'sentient_forms_sentiment_confidence' => 'structured.confidence',
                ],
                'note_path'    => 'structured.summary',
            ],
            'missing_information_v1'   => [
                'display_name' => 'Missing Information Review',
                'meta'         => [
                    'sentient_forms_information_status'     => 'structured.status',
                    'sentient_forms_information_confidence' => 'structured.confidence',
                ],
                'note_path'    => 'structured.summary',
            ],
            'pain_point_intent_v1'     => [
                'display_name' => 'Pain Point and Intent',
                'meta'         => [
                    'sentient_forms_intent'            => 'structured.intent',
                    'sentient_forms_buying_stage'      => 'structured.buying_stage',
                    'sentient_forms_intent_confidence' => 'structured.confidence',
                ],
                'note_path'    => 'structured.summary',
            ],
            'routing_recommendation_v1' => [
                'display_name' => 'Routing Recommendation',
                'meta'         => [
                    'sentient_forms_route_to'           => 'structured.route_to',
                    'sentient_forms_route_priority'     => 'structured.priority',
                    'sentient_forms_routing_confidence' => 'structured.confidence',
                ],
                'note_path'    => 'structured.recommendation',
            ],
            'toxicity_moderation_v1'   => [
                'display_name' => 'Toxicity and Safety Review',
                'meta'         => [
                    'sentient_forms_toxicity_severity'    => 'structured.severity',
                    'sentient_forms_toxicity_needs_review' => 'structured.needs_review',
                    'sentient_forms_toxicity_confidence'   => 'structured.confidence',
                ],
                'note_path'    => 'structured.staff_warning',
            ],
        ];

        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $form_id  = 140;

        foreach ( $expectations as $action_code => $expected )
        {
            $template = Sentient_Forms_Bundled_Action_Templates::get( $action_code );
            $this->assertIsArray( $template, sprintf( '%s template should exist', $action_code ) );
            $this->assertSame( [ 'gform_after_submission' ], $template['hooks'] ?? null );
            $this->assertSame( [ 'after_submission' ], $template['definition_json']['supported_execution_modes'] ?? null );
            $this->assertArrayNotHasKey( 'response_format', $template['definition_json'] ?? [] );
            $this->assertSame( 'object', $template['structured_output_schema']['type'] ?? null );

            $data = $this->create_bundled_local_first_mapping(
                $form_id,
                $action_code,
                [ 'gform_validation', 'gform_after_submission' ],
                []
            );

            $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
            $this->assertSame( $action_code, $data['central_action_id'] ?? null );
            $this->assertSame( $expected['display_name'], $data['action_name_label'] ?? null );
            $this->assertSame( [ 'after_submission' ], $data['trigger_hooks'] ?? null );

            $stored_mappings = $mappings->list_for_form( 'gravity_forms', (string) $form_id );
            $this->assertCount( 1, $stored_mappings );
            $this->assertSame( 'async', $stored_mappings[0]['execution_mode'] ?? null );
            $this->assertSame( $expected['meta'], $stored_mappings[0]['effect_mapping_json']['meta'] ?? null );
            $this->assertSame( $expected['note_path'], $stored_mappings[0]['effect_mapping_json']['entry_note']['path'] ?? null );
            $this->assertTrue( $stored_mappings[0]['effect_mapping_json']['store_result'] ?? false );
            $this->assertArrayNotHasKey( 'spam', $stored_mappings[0]['effect_mapping_json'] );

            ++$form_id;
        }
    }

    public function test_add_form_action_creates_local_first_clarification_mapping_on_realtime_hook(): void
    {
        GFAPI::$forms[16] = [
            'id'     => 16,
            'title'  => 'Realtime Clarification Test',
            'fields' => [
                (object) [ 'id' => 1, 'label' => 'Message', 'type' => 'textarea' ],
                (object) [ 'id' => 9, 'label' => 'Sentient Forms Virtual Q&A', 'type' => 'hidden' ],
            ],
        ];

        $data = $this->create_bundled_local_first_mapping(
            16,
            'clarification_assistant_v1',
            [ 'gform_validation', 'real_time' ],
            [
                'execution_mode' => 'real_time',
                'realtime_settings' => [
                    'auto_refresh_enabled' => false,
                    'field_checkpoints_enabled' => true,
                    'checkpoint_field_ids' => [ '1' ],
                    'page_checkpoints_enabled' => true,
                    'page_checkpoint_mode' => 'include_pages',
                    'page_checkpoint_pages' => [ 1 ],
                    'page_checkpoint_timeout_ms' => 2600,
                    'storage_target_field_id' => '9',
                    'blocking_mode' => 'require_answers',
                ],
            ]
        );

        $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
        $this->assertSame( 'clarification_assistant_v1', $data['central_action_id'] ?? null );
        $this->assertSame( [ 'real_time' ], $data['trigger_hooks'] ?? null );
        $this->assertSame( 'real_time', $data['execution_mode'] ?? null );
        $this->assertSame( 'real_time', $data['settings']['execution_mode'] ?? null );
        $this->assertFalse( $data['settings']['realtime_settings']['auto_refresh_enabled'] ?? true );
        $this->assertTrue( $data['settings']['realtime_settings']['field_checkpoints_enabled'] ?? false );
        $this->assertSame( [ '1' ], $data['settings']['realtime_settings']['checkpoint_field_ids'] ?? null );
        $this->assertTrue( $data['settings']['realtime_settings']['page_checkpoints_enabled'] ?? false );
        $this->assertSame( 'include_pages', $data['settings']['realtime_settings']['page_checkpoint_mode'] ?? null );
        $this->assertSame( [ 1 ], $data['settings']['realtime_settings']['page_checkpoint_pages'] ?? null );
        $this->assertSame( 2600, $data['settings']['realtime_settings']['page_checkpoint_timeout_ms'] ?? null );
        $this->assertSame( '9', $data['settings']['realtime_settings']['storage_target_field_id'] ?? null );

        global $wpdb;

        $mappings        = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored_mappings = $mappings->list_for_form( 'gravity_forms', '16' );

        $this->assertCount( 1, $stored_mappings );
        $this->assertSame( 'real_time', $stored_mappings[0]['hook'] ?? null );
        $this->assertSame( 'real_time', $stored_mappings[0]['execution_mode'] ?? null );
        $this->assertSame( '9', $stored_mappings[0]['settings_json']['realtime_settings']['storage_target_field_id'] ?? null );
        $this->assertTrue( $stored_mappings[0]['settings_json']['realtime_settings']['page_checkpoints_enabled'] ?? false );
    }

    public function test_add_form_action_rejects_non_clarification_realtime_trigger(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/18/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 18 );
        $request->set_param( 'central_action_id', 'sentient_forms_local_custom_action' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_realtime_action', $response->get_error_code() );
    }

    public function test_add_form_action_rejects_non_clarification_realtime_execution_mode(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/19/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 19 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param(
            'settings',
            [
                'execution_mode' => 'real_time',
            ]
        );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_realtime_action', $response->get_error_code() );
    }

    public function test_add_form_action_rejects_realtime_storage_target_for_normal_answer_field(): void
    {
        GFAPI::$forms[17] = [
            'id'     => 17,
            'title'  => 'Realtime Invalid Storage Test',
            'fields' => [
                (object) [ 'id' => 1, 'label' => 'Message', 'type' => 'textarea' ],
                (object) [ 'id' => 2, 'label' => 'Email', 'type' => 'email' ],
            ],
        ];

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/17/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 17 );
        $request->set_param( 'central_action_id', 'clarification_assistant_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );
        $request->set_param(
            'settings',
            [
                'execution_mode' => 'real_time',
                'realtime_settings' => [
                    'checkpoint_field_ids' => [ '1' ],
                    'storage_target_field_id' => '2',
                    'blocking_mode' => 'require_answers',
                ],
            ]
        );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_realtime_storage_target', $response->get_error_code() );
    }

    public function test_add_form_action_rejects_bundled_mapping_with_only_unsupported_hooks(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/15/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 15 );
        $request->set_param( 'central_action_id', 'content_validation_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_after_submission' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_bundled_action_hooks', $response->get_error_code() );
    }

    public function test_sanitize_settings_drops_batch_discount_and_clamps_delay(): void
    {
        $settings = [
            'batch_settings' => [
                'enabled'          => true,
                'delay_seconds'    => 1,
                'discount_percent' => 95,
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];

        $this->assertSame( true, $batch['enabled'] ?? null );
        $this->assertSame( 10, $batch['delay_seconds'] ?? null );
        $this->assertSame( DAY_IN_SECONDS, $batch['max_wait_seconds'] ?? null );
        $this->assertArrayNotHasKey( 'discount_percent', $batch );
    }

    public function test_sanitize_settings_clamps_batch_delay_upper_bound(): void
    {
        $settings = [
            'batch_settings' => [
                'enabled'       => true,
                'delay_seconds' => 99999,
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];

        $this->assertSame( 3600, $batch['delay_seconds'] ?? null );
        $this->assertSame( DAY_IN_SECONDS, $batch['max_wait_seconds'] ?? null );
    }

    public function test_sanitize_settings_clamps_batch_max_wait_bounds(): void
    {
        $settings = [
            'batch_settings' => [
                'enabled'          => true,
                'delay_seconds'    => 60,
                'max_wait_seconds' => 10,
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];
        $this->assertSame( 43200, $batch['max_wait_seconds'] ?? null );

        $settings['batch_settings']['max_wait_seconds'] = 9999999;
        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $batch     = $sanitized['batch_settings'] ?? [];
        $this->assertSame( 604800, $batch['max_wait_seconds'] ?? null );
    }

    public function test_sanitize_settings_conditions_keeps_nested_rules_and_numeric_values(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'any',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '1',
                            'operator' => 'contains',
                            'value'    => 'urgent',
                        ],
                        [
                            'type'  => 'group',
                            'logic' => 'all',
                            'rules' => [
                                [
                                    'type'     => 'rule',
                                    'field_id' => '2',
                                    'operator' => 'gte',
                                    'value'    => '10.5',
                                ],
                                [
                                    'type'     => 'rule',
                                    'field_id' => '3',
                                    'operator' => 'in',
                                    'value'    => [ 'sales', 'billing', '' ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $sanitized  = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $conditions = $sanitized['conditions'] ?? null;

        $this->assertIsArray( $conditions );
        $this->assertTrue( $conditions['enabled'] ?? false );
        $this->assertSame( 'group', $conditions['root']['type'] ?? null );
        $this->assertSame( 'any', $conditions['root']['logic'] ?? null );

        $nested_rules = $conditions['root']['rules'][1]['rules'] ?? [];
        $this->assertSame( 10.5, $nested_rules[0]['value'] ?? null );
        $this->assertSame( [ 'sales', 'billing' ], $nested_rules[1]['value'] ?? [] );
    }

    public function test_sanitize_settings_conditions_drops_invalid_rules(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '',
                            'operator' => 'eq',
                            'value'    => 'x',
                        ],
                        [
                            'type'     => 'rule',
                            'field_id' => '4',
                            'operator' => 'invalid_operator',
                            'value'    => 'x',
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $rules     = $sanitized['conditions']['root']['rules'] ?? [];

        $this->assertSame( [], $rules );
    }

    public function test_sanitize_settings_attachment_mapping_deduplicates_and_clamps(): void
    {
        $settings = [
            'attachment_mapping' => [
                'mode' => 'mixed',
                'gf_upload_field_ids' => [ '3', '3', '', '7' ],
                'media_ids' => [ 12, '12', -5, '27' ],
                'max_files' => 999,
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $mapping   = $sanitized['attachment_mapping'] ?? [];

        $this->assertSame( 'mixed', $mapping['mode'] ?? null );
        $this->assertSame( [ '3', '7' ], $mapping['gf_upload_field_ids'] ?? [] );
        $this->assertSame( [ 12, 27 ], $mapping['media_ids'] ?? [] );
        $this->assertSame( 20, $mapping['max_files'] ?? null );
    }

    public function test_sanitize_settings_conditions_preserves_rule_inside_depth_three_group(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'  => 'group',
                            'logic' => 'all',
                            'rules' => [
                                [
                                    'type'  => 'group',
                                    'logic' => 'all',
                                    'rules' => [
                                        [
                                            'type'     => 'rule',
                                            'field_id' => '9',
                                            'operator' => 'eq',
                                            'value'    => 'run',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $rule      = $sanitized['conditions']['root']['rules'][0]['rules'][0]['rules'][0] ?? null;

        $this->assertIsArray( $rule );
        $this->assertSame( '9', $rule['field_id'] ?? null );
        $this->assertSame( 'eq', $rule['operator'] ?? null );
        $this->assertSame( 'run', $rule['value'] ?? null );
    }

    public function test_sanitize_settings_conditions_prunes_nodes_deeper_than_depth_limit(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'  => 'group',
                            'logic' => 'all',
                            'rules' => [
                                [
                                    'type'  => 'group',
                                    'logic' => 'all',
                                    'rules' => [
                                        [
                                            'type'  => 'group',
                                            'logic' => 'all',
                                            'rules' => [
                                                [
                                                    'type'     => 'rule',
                                                    'field_id' => '10',
                                                    'operator' => 'eq',
                                                    'value'    => 'run',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $rules     = $sanitized['conditions']['root']['rules'][0]['rules'][0]['rules'] ?? [];

        $this->assertSame( [], $rules );
    }

    public function test_sanitize_settings_conditions_enforces_node_limit(): void
    {
        $rules = [];
        for ( $index = 0; $index < 60; $index++ )
        {
            $rules[] = [
                'type'     => 'rule',
                'field_id' => (string) ( $index + 1 ),
                'operator' => 'is_empty',
            ];
        }

        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => $rules,
                ],
            ],
        ];

        $sanitized_rules = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'] ?? [];

        $this->assertLessThanOrEqual( 49, count( $sanitized_rules ) );
        $this->assertNotEmpty( $sanitized_rules );
    }

    public function test_sanitize_settings_conditions_keeps_is_empty_without_value(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '5',
                            'operator' => 'is_empty',
                        ],
                    ],
                ],
            ],
        ];

        $rule = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'][0] ?? null;
        $this->assertIsArray( $rule );
        $this->assertSame( 'is_empty', $rule['operator'] ?? null );
        $this->assertArrayNotHasKey( 'value', $rule );
    }

    public function test_sanitize_settings_conditions_drops_eq_rule_when_value_missing(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '5',
                            'operator' => 'eq',
                        ],
                    ],
                ],
            ],
        ];

        $rules = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'] ?? [];
        $this->assertSame( [], $rules );
    }

    public function test_sanitize_settings_conditions_drops_numeric_rule_when_value_not_numeric(): void
    {
        $settings = [
            'conditions' => [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => '2',
                            'operator' => 'gt',
                            'value'    => 'not-a-number',
                        ],
                    ],
                ],
            ],
        ];

        $rules = $this->invoke_private( 'sanitize_settings', [ $settings ] )['conditions']['root']['rules'] ?? [];
        $this->assertSame( [], $rules );
    }

    public function test_sanitize_settings_dependency_ids_deduplicates_and_sanitizes(): void
    {
        $settings = [
            'dependency_ids' => [ ' map_a ', 'map_a', '', 123 ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $this->assertSame( [ 'map_a', '123' ], $sanitized['dependency_ids'] ?? [] );
    }

    public function test_sanitize_settings_normalizes_skip_on_upstream_spam_boolean(): void
    {
        $settings = [
            'skip_on_upstream_spam' => '1',
        ];

        $sanitized = $this->invoke_private( 'sanitize_settings', [ $settings ] );
        $this->assertTrue( $sanitized['skip_on_upstream_spam'] ?? false );
    }

    public function test_validate_mapping_dependencies_rejects_unknown_dependency(): void
    {
        $actions = [
            'map_a' => [
                'local_mapping_id'      => 'map_a',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [ 'dependency_ids' => [ 'missing_map' ] ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_dependency_missing', $result->get_error_code() );
    }

    public function test_validate_mapping_dependencies_rejects_hook_mismatch(): void
    {
        $actions = [
            'map_a' => [
                'local_mapping_id'      => 'map_a',
                'central_action_id'     => 'summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation', 'gform_after_submission' ],
                'settings'              => [ 'dependency_ids' => [ 'map_b' ] ],
            ],
            'map_b' => [
                'local_mapping_id'      => 'map_b',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_dependency_hooks', $result->get_error_code() );
    }

    public function test_validate_mapping_dependencies_rejects_async_dependency_for_sync_after_submission_mapping(): void
    {
        $actions = [
            'map_async' => [
                'local_mapping_id'      => 'map_async',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [],
            ],
            'map_sync'  => [
                'local_mapping_id'      => 'map_sync',
                'central_action_id'     => 'custom_hello',
                'action_type_indicator' => 'custom',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [
                    'dependency_ids' => [ 'map_async' ],
                    'execution_mode' => 'validation',
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_dependency_execution_mode', $result->get_error_code() );
    }

    public function test_validate_mapping_dependencies_allows_async_dependent_mapping_for_async_after_submission_dependency(): void
    {
        $actions = [
            'map_async' => [
                'local_mapping_id'      => 'map_async',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [],
            ],
            'map_child' => [
                'local_mapping_id'      => 'map_child',
                'central_action_id'     => 'custom_hello',
                'action_type_indicator' => 'custom',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [
                    'dependency_ids' => [ 'map_async' ],
                    'execution_mode' => 'after_submission',
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertTrue( $result );
    }

    public function test_validate_mapping_dependencies_allows_hook_scoped_trigger_sources_for_mixed_hooks(): void
    {
        $actions = [
            'map_async' => [
                'local_mapping_id'      => 'map_async',
                'central_action_id'     => 'entry_summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [
                    'execution_mode' => 'after_submission',
                ],
            ],
            'map_dual' => [
                'local_mapping_id'      => 'map_dual',
                'central_action_id'     => 'content_validation_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation', 'gform_after_submission' ],
                'settings'              => [
                    'execution_mode'  => 'after_submission',
                    'trigger_sources' => [
                        'gform_validation'       => [ 'type' => 'hook_root' ],
                        'gform_after_submission' => [
                            'type'       => 'mapping',
                            'mapping_id' => 'map_async',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertTrue( $result );
    }

    public function test_validate_mapping_dependencies_allows_skip_on_upstream_spam_for_validation_spam_dependency(): void
    {
        $actions = [
            'map_spam' => [
                'local_mapping_id'      => 'map_spam',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [],
            ],
            'map_child' => [
                'local_mapping_id'      => 'map_child',
                'central_action_id'     => 'entry_summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [
                    'dependency_ids'        => [ 'map_spam' ],
                    'skip_on_upstream_spam' => true,
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertTrue( $result );
    }

    public function test_validate_mapping_dependencies_rejects_skip_on_upstream_spam_for_non_spam_dependency(): void
    {
        $actions = [
            'map_summary' => [
                'local_mapping_id'      => 'map_summary',
                'central_action_id'     => 'entry_summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [],
            ],
            'map_child' => [
                'local_mapping_id'      => 'map_child',
                'central_action_id'     => 'content_validation_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [
                    'execution_mode'         => 'after_submission',
                    'dependency_ids'         => [ 'map_summary' ],
                    'skip_on_upstream_spam'  => true,
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_skip_on_upstream_spam', $result->get_error_code() );
    }

    public function test_validate_mapping_dependencies_allows_skip_on_upstream_spam_for_single_upstream_spam_mapping(): void
    {
        $actions = [
            'map_spam' => [
                'local_mapping_id'      => 'map_spam',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [],
            ],
            'map_child' => [
                'local_mapping_id'      => 'map_child',
                'central_action_id'     => 'entry_summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [
                    'execution_mode'        => 'after_submission',
                    'dependency_ids'        => [ 'map_spam' ],
                    'skip_on_upstream_spam' => true,
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertTrue( $result );
    }

    public function test_validate_mapping_dependencies_allows_skip_on_upstream_spam_when_only_one_hook_uses_upstream_spam(): void
    {
        $actions = [
            'map_spam' => [
                'local_mapping_id'      => 'map_spam',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [],
            ],
            'map_child' => [
                'local_mapping_id'      => 'map_child',
                'central_action_id'     => 'content_validation_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation', 'gform_after_submission' ],
                'settings'              => [
                    'skip_on_upstream_spam' => true,
                    'trigger_sources'       => [
                        'gform_validation'       => [
                            'type'       => 'mapping',
                            'mapping_id' => 'map_spam',
                        ],
                        'gform_after_submission' => [ 'type' => 'hook_root' ],
                    ],
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertTrue( $result );
    }

    public function test_validate_mapping_dependencies_rejects_hook_scoped_mismatch_for_trigger_sources(): void
    {
        $actions = [
            'map_after_submission_only' => [
                'local_mapping_id'      => 'map_after_submission_only',
                'central_action_id'     => 'entry_summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_after_submission' ],
                'settings'              => [],
            ],
            'map_validation' => [
                'local_mapping_id'      => 'map_validation',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [
                    'trigger_sources' => [
                        'gform_validation' => [
                            'type'       => 'mapping',
                            'mapping_id' => 'map_after_submission_only',
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_dependency_hooks', $result->get_error_code() );
    }

    public function test_validate_mapping_dependencies_rejects_cycles(): void
    {
        $actions = [
            'map_a' => [
                'local_mapping_id'      => 'map_a',
                'central_action_id'     => 'summary_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [ 'dependency_ids' => [ 'map_c' ] ],
            ],
            'map_b' => [
                'local_mapping_id'      => 'map_b',
                'central_action_id'     => 'spam_detection_v1',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [ 'dependency_ids' => [ 'map_a' ] ],
            ],
            'map_c' => [
                'local_mapping_id'      => 'map_c',
                'central_action_id'     => 'entry_evaluation',
                'action_type_indicator' => 'master',
                'trigger_hooks'         => [ 'gform_validation' ],
                'settings'              => [ 'dependency_ids' => [ 'map_b' ] ],
            ],
        ];

        $result = $this->invoke_private( 'validate_mapping_dependencies', [ $actions ] );
        $this->assertWPError( $result );
        $this->assertSame( 'rest_invalid_dependency_cycle', $result->get_error_code() );
    }

    public function test_delete_form_action_item_removes_dependency_reference(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_12';
        update_option(
            $option_key,
            [
                'map_a' => [
                    'local_mapping_id'      => 'map_a',
                    'central_action_id'     => 'spam_detection_v1',
                    'action_type_indicator' => 'master',
                    'trigger_hooks'         => [ 'gform_after_submission' ],
                    'settings'              => [],
                ],
                'map_b' => [
                    'local_mapping_id'      => 'map_b',
                    'central_action_id'     => 'entry_evaluation',
                    'action_type_indicator' => 'master',
                    'trigger_hooks'         => [ 'gform_after_submission' ],
                    'settings'              => [
                        'dependency_ids'  => [ 'map_a', 'map_a' ],
                        'trigger_sources' => [
                            'gform_after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'map_a',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'DELETE', '/sentient-forms/v1/gravity_forms/forms/12/actions/map_a' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 12 );
        $request->set_param( 'local_mapping_id', 'map_a' );

        $response = $this->controller->delete_form_action_item( $request );
        $data     = $response->get_data();
        $stored   = get_option( $option_key, [] );

        $this->assertTrue( $data['deleted'] ?? false );
        $this->assertArrayNotHasKey( 'map_a', $stored );
        $this->assertArrayNotHasKey( 'dependency_ids', $stored['map_b']['settings'] ?? [] );
        $this->assertSame( 'unbound', $stored['map_b']['settings']['trigger_sources']['after_submission']['type'] ?? null );

        delete_option( $option_key );
    }

    public function test_delete_form_action_item_removes_local_first_dependency_reference(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $parent_action_id = $custom_actions->create(
            [
                'code'                 => 'delete_parent_custom_action',
                'display_name'         => 'Delete Parent Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Parent {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $parent_action_id );

        $parent_mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '21',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $parent_action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $parent_mapping_id );

        $child_action_id = $custom_actions->create(
            [
                'code'                 => 'delete_child_custom_action',
                'display_name'         => 'Delete Child Custom Action',
                'definition_json'      => [ 'prompt_template' => 'Child {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $child_action_id );

        $parent_linkage_id = 'local_first_' . $parent_mapping_id;
        $child_mapping_id  = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '21',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $child_action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'settings_json'       => [
                    'trigger_sources' => [
                        'gform_after_submission' => [
                            'type'       => 'mapping',
                            'mapping_id' => $parent_linkage_id,
                        ],
                    ],
                    'dependency_ids'   => [ $parent_linkage_id ],
                ],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $child_mapping_id );

        $request = new WP_REST_Request( 'DELETE', '/sentient-forms/v1/gravity_forms/forms/21/actions/' . $parent_linkage_id );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 21 );
        $request->set_param( 'local_mapping_id', $parent_linkage_id );

        $response = $this->controller->delete_form_action_item( $request );
        $data     = $response->get_data();
        $child    = $mappings->get( $child_mapping_id );

        $this->assertTrue( $data['deleted'] ?? false );
        $this->assertNull( $mappings->get( $parent_mapping_id ) );
        $this->assertSame( 'unbound', $child['settings_json']['trigger_sources']['after_submission']['type'] ?? null );
        $this->assertArrayNotHasKey( 'dependency_ids', $child['settings_json'] ?? [] );
    }

    public function test_duplicate_form_action_item_inserts_duplicate_and_rewires_parent_children(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_13';
        update_option(
            $option_key,
            [
                'map_parent' => [
                    'local_mapping_id'      => 'map_parent',
                    'central_action_id'     => 'parent_v1',
                    'action_type_indicator' => 'master',
                    'trigger_hooks'         => [ 'gform_validation' ],
                    'settings'              => [
                        'trigger_sources' => [
                            'gform_validation' => [ 'type' => 'hook_root' ],
                        ],
                    ],
                ],
                'map_source' => [
                    'local_mapping_id'      => 'map_source',
                    'central_action_id'     => 'source_v1',
                    'action_type_indicator' => 'master',
                    'trigger_hooks'         => [ 'gform_validation' ],
                    'settings'              => [
                        'trigger_sources' => [
                            'gform_validation' => [ 'type' => 'hook_root' ],
                        ],
                    ],
                ],
                'map_child'  => [
                    'local_mapping_id'      => 'map_child',
                    'central_action_id'     => 'child_v1',
                    'action_type_indicator' => 'master',
                    'trigger_hooks'         => [ 'gform_validation' ],
                    'settings'              => [
                        'dependency_ids'  => [ 'map_parent' ],
                        'trigger_sources' => [
                            'gform_validation' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'map_parent',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/13/actions/map_source/duplicate' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 13 );
        $request->set_param( 'local_mapping_id', 'map_source' );
        $request->set_param(
            'parent',
            [
                'type'       => 'mapping',
                'hook'       => 'gform_validation',
                'mapping_id' => 'map_parent',
            ]
        );

        $response = $this->controller->duplicate_form_action_item( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );

        $data         = $response->get_data();
        $duplicate    = $data['duplicate'] ?? [];
        $duplicate_id = $duplicate['local_mapping_id'] ?? '';
        $this->assertIsString( $duplicate_id );
        $this->assertNotSame( '', $duplicate_id );
        $this->assertNotSame( 'map_source', $duplicate_id );

        $moved_children = $data['insertion']['moved_children'] ?? [];
        $this->assertContains( 'map_child', $moved_children );

        $stored = get_option( $option_key, [] );
        $this->assertArrayHasKey( $duplicate_id, $stored );
        $this->assertSame(
            $duplicate_id,
            $stored['map_child']['settings']['trigger_sources']['validation']['mapping_id'] ?? null
        );
        $this->assertSame( [ $duplicate_id ], $stored['map_child']['settings']['dependency_ids'] ?? [] );

        delete_option( $option_key );
    }

    public function test_duplicate_form_action_item_rejects_parent_hook_not_on_source_mapping(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_14';
        update_option(
            $option_key,
            [
                'map_source' => [
                    'local_mapping_id'      => 'map_source',
                    'central_action_id'     => 'source_v1',
                    'action_type_indicator' => 'master',
                    'trigger_hooks'         => [ 'gform_validation' ],
                    'settings'              => [],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/14/actions/map_source/duplicate' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 14 );
        $request->set_param( 'local_mapping_id', 'map_source' );
        $request->set_param(
            'parent',
            [
                'type' => 'hook_root',
                'hook' => 'gform_after_submission',
            ]
        );

        $response = $this->controller->duplicate_form_action_item( $request );
        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_duplicate_parent_hook', $response->get_error_code() );

        delete_option( $option_key );
    }

    public function test_sanitize_trace_entry_values_filters_invalid_values_and_clamps_length(): void
    {
        $raw_values = [
            '1' => ' alpha ',
            2   => 123,
            '3' => [ 'invalid' ],
            '4' => null,
            ''  => 'skip',
            '5' => str_repeat( 'z', 5005 ),
        ];

        $sanitized = $this->invoke_private( 'sanitize_trace_entry_values', [ $raw_values ] );

        $this->assertSame( 'alpha', $sanitized['1'] ?? null );
        $this->assertSame( '123', $sanitized['2'] ?? null );
        $this->assertArrayNotHasKey( '3', $sanitized );
        $this->assertArrayNotHasKey( '4', $sanitized );
        $this->assertArrayNotHasKey( '', $sanitized );
        $this->assertSame( 4096, strlen( $sanitized['5'] ?? '' ) );
    }

    public function test_sanitize_trace_trigger_sources_supports_unbound_and_mapping_aliases(): void
    {
        $raw_sources = [
            'gform_validation'       => [ 'type' => 'unbound' ],
            'gform_after_submission' => [
                'type'              => 'mapping',
                'source_mapping_id' => ' map_upstream ',
            ],
            'gform_unknown'          => [ 'type' => 'hook_root' ],
        ];

        $sanitized = $this->invoke_private( 'sanitize_trace_trigger_sources', [ $raw_sources ] );

        $this->assertSame( 'unbound', $sanitized['validation']['type'] ?? null );
        $this->assertSame( 'mapping', $sanitized['after_submission']['type'] ?? null );
        $this->assertSame( 'map_upstream', $sanitized['after_submission']['mapping_id'] ?? null );
        $this->assertArrayNotHasKey( 'gform_unknown', $sanitized );
    }

    public function test_collect_trace_referenced_field_ids_reads_input_mapping_and_conditions(): void
    {
        $actions = [
            'map_alpha' => [
                'local_mapping_id' => 'map_alpha',
                'settings'         => [
                    'input_mapping' => [
                        'mode'      => 'selected',
                        'field_ids' => [ '1', '2' ],
                    ],
                    'conditions'   => [
                        'enabled' => true,
                        'root'    => [
                            'type'  => 'group',
                            'logic' => 'all',
                            'rules' => [
                                [
                                    'type'     => 'rule',
                                    'field_id' => '5',
                                    'operator' => 'eq',
                                    'value'    => 'yes',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $field_ids = $this->invoke_private(
            'collect_trace_referenced_field_ids',
            [
                $actions,
                [ '1' => 'a', '2' => 'b', '3' => 'c', '5' => 'yes' ],
            ]
        );

        sort( $field_ids );
        $this->assertSame( [ '1', '2', '5' ], $field_ids );
    }

    public function test_build_trace_input_payload_sets_manual_override_source_and_metadata(): void
    {
        $payload = $this->invoke_private(
            'build_trace_input_payload',
            [
                42,
                'mapped_and_rule',
                [ '2' => 'manual-value' ],
                [ '1' => 'imported', '2' => 'manual-value' ],
                [
                    'imported_field_ids'   => [ '1', '2' ],
                    'overridden_field_ids' => [ '2' ],
                    'warnings'             => [ 'example warning' ],
                ],
                true,
                true,
            ]
        );

        $this->assertSame( 'entry_import_with_manual_overrides', $payload['source'] ?? null );
        $this->assertSame( 42, $payload['entry_id'] ?? null );
        $this->assertSame( [ '2' ], $payload['manual_field_ids'] ?? [] );
        $this->assertSame( [ '1', '2' ], $payload['imported_field_ids'] ?? [] );
        $this->assertSame( [ '2' ], $payload['overridden_field_ids'] ?? [] );
        $this->assertSame( true, $payload['include_drafts'] ?? null );
        $this->assertSame( true, $payload['draft_applied'] ?? null );
    }

    public function test_get_request_trace_reads_canonical_lifecycle_mappings(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_73';

        try
        {
            update_option(
                $option_key,
                [
                    'map_canonical_validation' => [
                        'local_mapping_id'           => 'map_canonical_validation',
                        'central_action_id'          => 'spam_detection_v1',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'validation' ],
                    ],
                ],
                false
            );
            $this->set_mappings_sync( null );

            $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/73/actions/request-trace' );
            $request->set_param( 'form_source_slug', 'gravity_forms' );
            $request->set_param( 'form_id', 73 );
            $request->set_param( 'hook_scope', 'validation' );
            $request->set_param( 'entry_values', [ '1' => 'prospect@example.test' ] );

            $response = $this->controller->get_request_trace( $request );
            $this->assertInstanceOf( WP_REST_Response::class, $response );

            $data = $response->get_data();
            $this->assertSame( 'validation', $data['hook_scope'] ?? null );
            $this->assertSame( [ 'validation' ], $data['available_hooks'] ?? null );
            $this->assertSame( 'validation', $data['hooks'][0]['hook'] ?? null );
            $this->assertSame( [ 'map_canonical_validation' ], $data['hooks'][0]['runnable'] ?? null );
            $this->assertSame( 'would_run', $data['hooks'][0]['steps'][0]['outcome'] ?? null );
        }
        finally
        {
            delete_option( $option_key );
        }
    }

    // =========================================================================
    // CB-FORMS-001: Per-Form Master Disable Tests
    // =========================================================================

    /**
     * CB-FORMS-001: sf_disabled flag must NOT leak into the actions array.
     *
     * The sf_disabled boolean lives in the same WP option as action linkages.
     * get_form_actions MUST filter it out, otherwise the frontend receives a
     * phantom "action" whose value is `true` instead of an action object.
     */
    public function test_get_form_actions_excludes_sf_disabled_from_response(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_1';

        // Simulate a form with sf_disabled = true and one real action.
        update_option( $option_key, [
            'sf_disabled' => true,
            'map_spam_v1' => [
                'local_mapping_id'           => 'map_spam_v1',
                'central_action_id'          => 'spam_detection_v1',
                'action_type_indicator'      => 'master',
                'is_action_enabled_for_form' => true,
                'trigger_hooks'              => [ 'gform_validation' ],
            ],
        ] );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions( $request );
        $data     = $response->get_data();

        // Response should be an array of action linkages only — no sf_disabled key.
        $this->assertIsArray( $data );

        // Verify none of the entries is the boolean `true` (the sf_disabled value).
        foreach ( $data as $item ) {
            $this->assertIsArray( $item, 'Every item in get_form_actions must be an action array, not a scalar' );
        }

        // At least one real action should survive.
        $action_ids = array_column( $data, 'central_action_id' );
        $this->assertContains( 'spam_detection_v1', $action_ids );

        delete_option( $option_key );
    }

    public function test_get_form_actions_excludes_wrapped_option_metadata_from_response(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_1';
        $mapping    = [
            'local_mapping_id'           => 'map_spam_v1',
            'central_action_id'          => 'spam_detection_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'gform_validation' ],
        ];

        update_option(
            $option_key,
            [
                'enabled'       => true,
                'actions'       => [
                    'map_spam_v1' => $mapping,
                ],
                'map_spam_v1'   => $mapping,
                'sf_disabled'   => false,
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions( $request );
        $data     = $response->get_data();

        $this->assertCount( 1, $data );
        $this->assertSame( 'map_spam_v1', $data[0]['local_mapping_id'] ?? null );
        $this->assertSame( 'spam_detection_v1', $data[0]['central_action_id'] ?? null );

        delete_option( $option_key );
    }

    public function test_get_form_actions_includes_local_first_custom_table_mappings(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions( $request );
        $data     = $response->get_data();

        $this->assertCount( 1, $data );
        $this->assertSame( 'local_first_' . $record['mapping_id'], $data[0]['local_mapping_id'] ?? null );
        $this->assertSame( 'local_drawer_qualification', $data[0]['central_action_id'] ?? null );
        $this->assertSame( 'local_first', $data[0]['action_type_indicator'] ?? null );
        $this->assertSame( 'Local drawer qualification', $data[0]['action_name_label'] ?? null );
        $this->assertSame( 'active', $data[0]['linked_action_status'] ?? null );
        $this->assertSame( 'ok', $data[0]['repair_state'] ?? null );
        $this->assertSame( [ 'after_submission' ], $data[0]['trigger_hooks'] ?? null );
        $this->assertTrue( $data[0]['is_action_enabled_for_form'] ?? false );
        $this->assertSame( 'after_submission', $data[0]['settings']['execution_mode'] ?? null );
        $this->assertSame( [ 'email' => '3' ], $data[0]['settings']['input_mapping'] ?? null );
        $this->assertSame(
            [ 'sentient_forms_qualification' => 'structured.qualification' ],
            $data[0]['settings']['effect_mapping_json']['meta'] ?? null
        );
    }

    public function test_get_forms_overview_combines_forms_actions_and_statuses(): void
    {
        GFAPI::$forms = [
            1 => [
                'id'        => 1,
                'title'     => 'Contact Form',
                'is_active' => true,
            ],
            2 => [
                'id'        => 2,
                'title'     => 'Quote Request',
                'is_active' => true,
            ],
        ];

        update_option(
            'sentient_forms_actions_gravity_forms_1',
            [
                'map_spam_v1'    => [
                    'local_mapping_id'           => 'map_spam_v1',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                ],
                'map_summary_v1' => [
                    'local_mapping_id'           => 'map_summary_v1',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => false,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                ],
                'sf_disabled'    => false,
            ]
        );
        update_option( 'sentient_forms_actions_gravity_forms_2', [ 'sf_disabled' => true ] );

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-overview-status-1',
                'form_source'          => 'gravity_forms',
                'form_id'              => 1,
                'entry_id'             => 501,
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'classification' => 'ham',
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );

        $response = $this->controller->get_forms_overview( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'gravity_forms', $data['form_source'] ?? null );
        $this->assertCount( 2, $data['forms'] ?? [] );

        $forms_by_id = [];
        foreach ( $data['forms'] as $form )
        {
            $forms_by_id[ (int) $form['id'] ] = $form;
        }

        $this->assertSame( 'Contact Form', $forms_by_id[1]['title'] ?? null );
        $this->assertSame( 2, $forms_by_id[1]['action_count'] ?? null );
        $this->assertSame( 1, $forms_by_id[1]['enabled_action_count'] ?? null );
        $this->assertSame( 'success', $forms_by_id[1]['execution_status']['status'] ?? null );
        $this->assertSame( 'ham', $forms_by_id[1]['execution_status']['last_result']['structured']['classification'] ?? null );
        $this->assertNotContains( 'sf_disabled', array_column( $forms_by_id[1]['actions'], 'local_mapping_id' ) );

        $this->assertSame( 'Quote Request', $forms_by_id[2]['title'] ?? null );
        $this->assertSame( 0, $forms_by_id[2]['action_count'] ?? null );
        $this->assertSame( 0, $forms_by_id[2]['enabled_action_count'] ?? null );
        $this->assertSame( 'unknown', $forms_by_id[2]['execution_status']['status'] ?? null );
    }

    public function test_get_forms_overview_fetches_cps_mappings_once_for_all_forms(): void
    {
        if ( ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Counting_Fetch' ) ) {
            $this->markTestSkipped( 'Mappings sync counting test double is unavailable.' );
        }

        GFAPI::$forms = [
            1 => [
                'id'        => 1,
                'title'     => 'Contact Form',
                'is_active' => true,
            ],
            2 => [
                'id'        => 2,
                'title'     => 'Quote Request',
                'is_active' => true,
            ],
        ];

        $sync = new Sentient_Forms_Test_Mappings_Sync_Counting_Fetch();
        $this->set_mappings_sync( $sync );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );

        $response = $this->controller->get_forms_overview( $request );
        $data     = $response->get_data();

        $this->assertSame( 1, $sync->fetch_calls, 'Overview should not fetch the full CPS mapping list once per form.' );
        $this->assertCount( 2, $data['forms'] ?? [] );
        $this->assertSame( 'cps_map_form_1', $data['forms'][0]['actions'][0]['local_mapping_id'] ?? null );
        $this->assertSame( 'cps_map_form_2', $data['forms'][1]['actions'][0]['local_mapping_id'] ?? null );
    }

    public function test_get_form_actions_bootstrap_combines_actions_status_and_disabled_state(): void
    {
        if ( ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan' ) ) {
            $this->markTestSkipped( 'Mappings sync workflow plan test double is unavailable.' );
        }

        $sync = new Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan();
        $this->set_mappings_sync( $sync );

        update_option(
            'sentient_forms_actions_gravity_forms_1',
            [
                'map_spam_v1' => [
                    'local_mapping_id'           => 'map_spam_v1',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                ],
                'sf_disabled' => true,
            ]
        );

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-bootstrap-status-1',
                'form_source'          => 'gravity_forms',
                'form_id'              => 1,
                'entry_id'             => 601,
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'classification' => 'ham',
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/bootstrap' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions_bootstrap( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data = $response->get_data();
        $this->assertSame( 'gravity_forms', $data['form_source'] ?? null );
        $this->assertSame( 1, $data['form_id'] ?? null );
        $this->assertCount( 1, $data['actions'] ?? [] );
        $this->assertSame( 'spam_detection_v1', $data['actions'][0]['central_action_id'] ?? null );
        $this->assertSame( 'success', $data['execution_status']['status'] ?? null );
        $this->assertSame( 'ham', $data['execution_status']['last_result']['structured']['classification'] ?? null );
        $this->assertTrue( $data['disabled_state']['sf_disabled'] ?? false );
        $this->assertTrue( $data['disabled_state']['effective_disabled'] ?? false );
        $this->assertArrayHasKey( 'form', $data );
        $this->assertArrayHasKey( 'form_source_descriptor', $data );
        $this->assertArrayHasKey( 'capabilities', $data );
        $this->assertArrayHasKey( 'definitions', $data );
        $this->assertArrayHasKey( 'custom_actions', $data );
        $this->assertArrayHasKey( 'provider_credentials', $data );
        $this->assertArrayHasKey( 'form_action_configs', $data );
        $this->assertArrayHasKey( 'form_fields', $data );
        $this->assertArrayHasKey( 'action_defaults', $data );
        $this->assertArrayHasKey( 'workflow_plan', $data );
        $this->assertIsArray( $data['definitions'] );
        $this->assertIsArray( $data['provider_credentials'] );
        $this->assertIsArray( $data['form_action_configs'] );
        $this->assertIsArray( $data['form_fields'] );
        $this->assertIsArray( $data['action_defaults'] );
        $this->assertIsArray( $data['workflow_plan'] );
        $this->assertSame( 'gravity_forms', $data['form_source_descriptor']['slug'] ?? null );
        $this->assertArrayHasKey( 'validation', $data['form_source_descriptor']['lifecycles'] ?? [] );
        $this->assertSame(
            'gform_validation',
            $data['form_source_descriptor']['lifecycles']['validation']['native_hook'] ?? null
        );
        $this->assertSame( 'cps', $data['workflow_plan']['authority'] ?? null );
        $this->assertSame( 2, $sync->plan_calls );
        $this->assertSame( 1, $sync->sync_calls );
    }

    public function test_contact_form_7_bootstrap_exposes_ledger_required_capabilities_without_gravity_only_claims(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_forms',
            static fn(): array => [
                new class {
                    public function id(): int
                    {
                        return 42;
                    }

                    public function title(): string
                    {
                        return 'CF7 Support Intake';
                    }
                },
            ]
        );
        add_filter(
            'sentient_forms_contact_form_7_form_object',
            static fn( $form, $form_id ) => 42 === absint( $form_id )
                ? new class {
                    public function scan_form_tags(): array
                    {
                        return [
                            (object) [
                                'type'     => 'text*',
                                'basetype' => 'text',
                                'name'     => 'your-name',
                            ],
                        ];
                    }
                }
                : $form,
            10,
            2
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/contact_form_7/forms/42/actions/bootstrap' );
        $request->set_param( 'form_source_slug', 'contact_form_7' );
        $request->set_param( 'form_id', 42 );

        $response = $this->controller->get_form_actions_bootstrap( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data       = $response->get_data();
        $descriptor = $data['form_source_descriptor'] ?? [];

        $this->assertSame( 'contact_form_7', $descriptor['slug'] ?? null );
        $this->assertSame( 'Contact Form 7', $descriptor['label'] ?? null );
        $this->assertTrue( $descriptor['is_active'] ?? false );
        $this->assertSame( 'available', $descriptor['availability'] ?? null );
        $this->assertFalse( $descriptor['lifecycles']['validation']['supported'] ?? true );
        $this->assertFalse( $descriptor['lifecycles']['real_time']['supported'] ?? true );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['supported'] ?? false );
        $this->assertSame( 'wpcf7_mail_sent', $descriptor['lifecycles']['after_submission']['native_hook'] ?? null );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['requires_ledger'] ?? false );
        $this->assertFalse( $descriptor['native_entry']['id'] ?? true );
        $this->assertFalse( $descriptor['native_entry']['link'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['notes'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] ?? true );
        $this->assertTrue( $descriptor['ledger']['required_for_parity'] ?? false );
        $this->assertFalse( $data['ledger_settings']['enabled'] ?? true );
        $this->assertSame( 'your-name', $data['form_fields'][0]['id'] ?? null );
        $this->assertTrue( $data['form_fields'][0]['storage_eligible'] ?? false );
    }

    public function test_wpforms_bootstrap_exposes_paid_like_native_links_and_ledger_state_without_unsupported_claims(): void
    {
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );
        add_filter( 'sentient_forms_wpforms_native_entry_storage_available', '__return_true' );

        $form_id = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'WPForms REST Intake',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 47,
                        'settings' => [
                            'form_title' => 'WPForms REST Intake',
                        ],
                        'fields'   => [
                            1 => [
                                'id'    => 1,
                                'type'  => 'name',
                                'label' => 'Full Name',
                            ],
                            2 => [
                                'id'    => 2,
                                'type'  => 'email',
                                'label' => 'Email',
                            ],
                        ],
                    ]
                ),
            ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/wpforms/forms/' . $form_id . '/actions/bootstrap' );
        $request->set_param( 'form_source_slug', 'wpforms' );
        $request->set_param( 'form_id', $form_id );

        $response = $this->controller->get_form_actions_bootstrap( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data       = $response->get_data();
        $descriptor = $data['form_source_descriptor'] ?? [];

        $this->assertSame( 'wpforms', $data['form_source'] ?? null );
        $this->assertSame( $form_id, $data['form_id'] ?? null );
        $this->assertSame( 'WPForms REST Intake', $data['form']['title'] ?? null );
        $this->assertStringContainsString( 'page=wpforms-builder', $data['form']['provider_edit_url'] ?? '' );
        $this->assertStringContainsString( 'view=fields', $data['form']['provider_edit_url'] ?? '' );
        $this->assertStringContainsString( 'form_id=' . $form_id, $data['form']['provider_edit_url'] ?? '' );
        $this->assertSame( 'wpforms', $descriptor['slug'] ?? null );
        $this->assertSame( 'WPForms', $descriptor['label'] ?? null );
        $this->assertTrue( $descriptor['is_active'] ?? false );
        $this->assertSame( 'available', $descriptor['availability'] ?? null );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['supported'] ?? false );
        $this->assertSame( 'wpforms_process_complete', $descriptor['lifecycles']['after_submission']['native_hook'] ?? null );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['requires_ledger'] ?? false );
        $this->assertFalse( $descriptor['lifecycles']['validation']['supported'] ?? true );
        $this->assertFalse( $descriptor['lifecycles']['real_time']['supported'] ?? true );
        $this->assertTrue( $descriptor['native_entry']['id'] ?? false );
        $this->assertTrue( $descriptor['native_entry']['link'] ?? false );
        $this->assertFalse( $descriptor['native_entry']['read'] ?? true );
        $this->assertFalse( $descriptor['native_entry']['write'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['notes'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] ?? true );
        $this->assertTrue( $descriptor['ledger']['required_for_parity'] ?? false );
        $this->assertSame( 'wpforms', $data['ledger_settings']['form_source'] ?? null );
        $this->assertSame( (string) $form_id, $data['ledger_settings']['form_id'] ?? null );
        $this->assertFalse( $data['ledger_settings']['enabled'] ?? true );
        $this->assertStringContainsString( '/wpforms/forms/' . $form_id . '/submissions', $data['ledger_settings']['ledger_records_endpoint'] ?? '' );
        $this->assertSame( '1', $data['form_fields'][0]['id'] ?? null );
        $this->assertSame( 'Full Name', $data['form_fields'][0]['label'] ?? null );
        $this->assertTrue( $data['form_fields'][0]['storage_eligible'] ?? false );
    }

    public function test_bootstrap_action_defaults_chunks_more_than_batch_limit_ids(): void
    {
        $definitions    = [];
        $custom_actions = [
            'actions' => [],
        ];
        $batch_limit = Sentient_Forms_Form_Action_Config_Controller::ACTION_DEFAULTS_BATCH_LIMIT;

        $tail_action_code = sprintf(
            'perf_bootstrap_%03d',
            $batch_limit + 5
        );

        for ( $index = 1; $index <= $batch_limit + 5; $index++ )
        {
            $code = sprintf( 'perf_bootstrap_%03d', $index );
            $custom_actions['actions'][] = [
                'code' => $code,
            ];
        }

        update_option(
            'sentient_forms_action_defaults_' . $tail_action_code,
            [
                'action_customization' => 'Tail action default should survive batching.',
            ]
        );

        try
        {
            $defaults = $this->invoke_private(
                'get_bootstrap_action_defaults',
                [
                    $definitions,
                    $custom_actions,
                ]
            );
        }
        finally
        {
            delete_option( 'sentient_forms_action_defaults_' . $tail_action_code );
        }

        $this->assertCount( $batch_limit + 5, $defaults );
        $this->assertArrayHasKey( $tail_action_code, $defaults );
        $this->assertSame(
            'Tail action default should survive batching.',
            $defaults[ $tail_action_code ]['action_customization'] ?? null
        );
    }

    public function test_get_form_actions_marks_missing_local_first_custom_action_for_repair(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'sentient_custom_actions',
            [ 'id' => $record['action_id'] ],
            [ '%d' ]
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions( $request );
        $data     = $response->get_data();

        $this->assertCount( 1, $data );
        $this->assertSame( 'sentient_forms_local_custom_action', $data[0]['central_action_id'] ?? null );
        $this->assertSame( 'Local OpenRouter action', $data[0]['action_name_label'] ?? null );
        $this->assertSame( 'missing', $data[0]['linked_action_status'] ?? null );
        $this->assertSame( 'needs_repair', $data[0]['repair_state'] ?? null );
    }

    public function test_validate_local_mapping_id_param_allows_local_first_custom_table_mapping(): void
    {
        $record     = $this->create_local_first_mapping_fixture( '1' );
        $mapping_id = 'local_first_' . $record['mapping_id'];

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/' . $mapping_id ) );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', $mapping_id );

        $this->assertTrue(
            $this->controller->validate_local_mapping_id_param( $mapping_id, $request, 'local_mapping_id' )
        );
    }

    public function test_validate_local_mapping_id_param_rejects_local_first_mapping_from_another_form(): void
    {
        $record     = $this->create_local_first_mapping_fixture( '1' );
        $mapping_id = 'local_first_' . $record['mapping_id'];

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/2/actions/' . $mapping_id ) );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 2 );
        $request->set_param( 'local_mapping_id', $mapping_id );

        $result = $this->controller->validate_local_mapping_id_param( $mapping_id, $request, 'local_mapping_id' );

        $this->assertWPError( $result );
        $this->assertSame( 'rest_action_not_found', $result->get_error_code() );
    }

    public function test_update_form_action_item_updates_local_first_custom_table_mapping(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param( 'trigger_hooks', [ 'gform_after_submission' ] );
        $request->set_param( 'is_action_enabled_for_form', false );
        $request->set_param(
            'settings',
            [
                'execution_mode' => 'after_submission',
                'input_mapping'  => [
                    'name'    => '1',
                    'message' => '2',
                ],
                'conditions'     => [
                    'enabled' => true,
                    'root'    => [
                        'type'     => 'rule',
                        'field_id' => '3',
                        'operator' => 'contains',
                        'value'    => 'enterprise',
                    ],
                ],
                'include_site_context' => 'yes',
                'model_selection'      => [
                    'primary'       => 'sf_default',
                    'backup'        => null,
                    'is_preset'     => true,
                    'provider'      => 'sentient_managed',
                    'credential_id' => 1,
                ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );
        $data     = $response->get_data();

        global $wpdb;
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored   = $mappings->get( $record['mapping_id'] );

        $this->assertSame( 'local_first_' . $record['mapping_id'], $data['local_mapping_id'] ?? null );
        $this->assertFalse( $data['is_action_enabled_for_form'] ?? true );
        $this->assertSame( [ 'name' => '1', 'message' => '2' ], $data['settings']['input_mapping'] ?? null );
        $this->assertSame( 'sentient_managed', $data['settings']['model_selection']['provider'] ?? null );
        $this->assertSame( 'sf_default', $data['settings']['model_selection']['primary'] ?? null );
        $this->assertSame( 1, (int) ( $data['settings']['model_selection']['credential_id'] ?? 0 ) );
        $this->assertSame( 'yes', $data['settings']['include_site_context'] ?? null );
        $this->assertIsArray( $stored );
        $this->assertFalse( $stored['enabled'] );
        $this->assertSame( [ 'name' => '1', 'message' => '2' ], $stored['input_bindings_json'] ?? null );
        $this->assertSame( 'sentient_managed', $stored['settings_json']['model_selection']['provider'] ?? null );
        $this->assertSame( 'sf_default', $stored['settings_json']['model_selection']['primary'] ?? null );
        $this->assertSame( 1, (int) ( $stored['settings_json']['model_selection']['credential_id'] ?? 0 ) );
        $this->assertSame( 'yes', $stored['settings_json']['include_site_context'] ?? null );
        $this->assertTrue( $stored['conditions_json']['enabled'] ?? false );
        $this->assertSame( 'enterprise', $stored['conditions_json']['root']['value'] ?? null );
    }

    public function test_update_form_action_item_updates_wrapped_option_duplicate(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_1';
        $mapping_id = 'map_rt_stale_wrapper';
        $base_mapping = [
            'local_mapping_id'           => $mapping_id,
            'central_action_id'          => 'clarification_assistant_v1',
            'action_type_indicator'      => 'master',
            'trigger_hooks'              => [ 'gform_validation' ],
            'is_action_enabled_for_form' => true,
            'settings'                   => [
                'execution_mode'     => 'real_time',
                'realtime_settings'  => [
                    'storage_target_field_id'   => '9',
                    'pre_submit_run_enabled'    => false,
                    'hidden_field_exposure_mode' => 'label_hidden',
                ],
            ],
        ];

        update_option(
            $option_key,
            [
                'enabled' => true,
                'actions' => [
                    $mapping_id => $base_mapping,
                ],
                $mapping_id => $base_mapping,
            ],
            false
        );

        GFAPI::$forms[1] = [
            'id'     => 1,
            'title'  => 'Realtime wrapper save',
            'fields' => [
                (object) [ 'id' => 9, 'label' => 'Sentient Forms Realtime Q&A', 'type' => 'hidden' ],
            ],
        ];

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/' . $mapping_id );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', $mapping_id );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param(
            'settings',
            [
                'execution_mode'    => 'real_time',
                'realtime_settings' => [
                    'storage_target_field_id'    => '9',
                    'pre_submit_run_enabled'     => true,
                    'pre_submit_timeout_ms'      => 2500,
                    'hidden_field_exposure_mode' => 'label_hidden',
                ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $stored = get_option( $option_key, [] );

        $this->assertTrue(
            $stored[ $mapping_id ]['settings']['realtime_settings']['pre_submit_run_enabled'] ?? false
        );
        $this->assertTrue(
            $stored['actions'][ $mapping_id ]['settings']['realtime_settings']['pre_submit_run_enabled'] ?? false
        );

        delete_option( $option_key );
    }

    public function test_update_form_action_item_rejects_realtime_for_non_clarification_local_first_mapping(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );
        $request->set_param(
            'settings',
            [
                'execution_mode' => 'real_time',
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_realtime_action', $response->get_error_code() );
    }

    public function test_update_form_action_item_reactivates_archived_local_action_when_enabled(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        global $wpdb;
        $actions  = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $this->assertTrue( $actions->update_status( $record['action_id'], 'archived' ) );
        $updated_mapping = $mappings->update( $record['mapping_id'], [ 'enabled' => false ] );
        $this->assertIsArray( $updated_mapping );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param( 'is_action_enabled_for_form', true );

        $response = $this->controller->update_form_action_item( $request );
        $data     = $response->get_data();

        $stored_action  = $actions->get( $record['action_id'] );
        $stored_mapping = $mappings->get( $record['mapping_id'] );

        $this->assertSame( 'local_first_' . $record['mapping_id'], $data['local_mapping_id'] ?? null );
        $this->assertTrue( $data['is_action_enabled_for_form'] ?? false );
        $this->assertSame( 'active', $data['linked_action_status'] ?? null );
        $this->assertSame( 'ok', $data['repair_state'] ?? null );
        $this->assertSame( 'active', $stored_action['status'] ?? null );
        $this->assertTrue( $stored_mapping['enabled'] ?? false );
    }

    public function test_update_form_action_item_syncs_local_first_spam_note_controls_to_effect_mapping(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'suppress_notifications_on_spam' => true,
                'skip_downstream_on_spam'        => false,
                'spam_confidence_threshold'      => 0.7,
                'spam_result_display_mode'       => 'none',
                'spam_indicators_display'        => 'detailed',
            ]
        );

        $response = $this->controller->update_form_action_item( $request );
        $data     = $response->get_data();

        global $wpdb;
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored   = $mappings->get( $record['mapping_id'] );

        $this->assertSame( 'none', $data['settings']['spam_result_display_mode'] ?? null );
        $this->assertSame( 'detailed', $data['settings']['spam_indicators_display'] ?? null );
        $this->assertTrue( $data['settings']['suppress_notifications_on_spam'] ?? false );
        $this->assertFalse( $data['settings']['skip_downstream_on_spam'] ?? true );
        $this->assertSame( 0.7, $data['settings']['spam_confidence_threshold'] ?? null );
        $this->assertIsArray( $stored );
        $this->assertSame(
            [ 'sentient_forms_qualification' => 'structured.qualification' ],
            $stored['effect_mapping_json']['meta'] ?? null
        );
        $this->assertTrue( $stored['effect_mapping_json']['spam']['suppress_notifications_on_spam'] ?? false );
        $this->assertFalse( $stored['effect_mapping_json']['spam']['skip_downstream_on_spam'] ?? true );
        $this->assertSame( 0.7, $stored['effect_mapping_json']['spam']['min_confidence'] ?? null );
        $this->assertSame( 'none', $stored['effect_mapping_json']['spam']['note']['result_display_mode'] ?? null );
        $this->assertSame( 'detailed', $stored['effect_mapping_json']['spam']['note']['indicators_display'] ?? null );
    }

    public function test_delete_form_action_item_deletes_local_first_mapping_and_unbinds_option_dependencies(): void
    {
        $record     = $this->create_local_first_mapping_fixture( '1' );
        $mapping_id = 'local_first_' . $record['mapping_id'];
        $option_key = 'sentient_forms_actions_gravity_forms_1';

        update_option(
            $option_key,
            [
                'map_downstream' => [
                    'local_mapping_id'      => 'map_downstream',
                    'central_action_id'     => 'entry_evaluation',
                    'action_type_indicator' => 'master',
                    'trigger_hooks'         => [ 'gform_after_submission' ],
                    'settings'              => [
                        'dependency_ids'  => [ $mapping_id ],
                        'trigger_sources' => [
                            'gform_after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => $mapping_id,
                            ],
                        ],
                    ],
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'DELETE', '/sentient-forms/v1/gravity_forms/forms/1/actions/' . $mapping_id );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', $mapping_id );

        $response = $this->controller->delete_form_action_item( $request );
        $data     = $response->get_data();
        $stored   = get_option( $option_key, [] );

        global $wpdb;
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $this->assertTrue( $data['deleted'] ?? false );
        $this->assertNull( $mappings->get( $record['mapping_id'] ) );
        $this->assertArrayNotHasKey( 'dependency_ids', $stored['map_downstream']['settings'] ?? [] );
        $this->assertSame( 'unbound', $stored['map_downstream']['settings']['trigger_sources']['after_submission']['type'] ?? null );

        delete_option( $option_key );
    }

    public function test_get_workflow_plan_falls_back_when_cps_plan_omits_local_mappings(): void
    {
        if ( ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Empty_Workflow_Plan' ) ) {
            $this->markTestSkipped( 'Mappings sync test double is unavailable.' );
        }

        $option_key = 'sentient_forms_actions_gravity_forms_1';
        update_option( $option_key, [
            'sf_disabled' => false,
            'map_spam_v1' => [
                'local_mapping_id'           => 'map_spam_v1',
                'central_action_id'          => 'spam_detection_v1',
                'action_type_indicator'      => 'master',
                'is_action_enabled_for_form' => true,
                'trigger_hooks'              => [ 'gform_validation' ],
                'action_name_label'          => 'Local Spam Detection',
                'settings'                   => [
                    'trigger_sources' => [
                        'gform_validation' => [ 'type' => 'hook_root' ],
                    ],
                ],
            ],
        ] );

        $this->set_mappings_sync( new Sentient_Forms_Test_Mappings_Sync_Empty_Workflow_Plan() );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/workflow-plan' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'hook_scope', 'all' );

        $response = $this->controller->get_workflow_plan( $request );
        $data     = $response->get_data();

        $this->assertSame( 'local', $data['authority'] ?? null );
        $this->assertSame( 'cps_mismatch', $data['authority_reason'] ?? null );
        $this->assertFalse( $data['cps_unreachable'] ?? true );
        $this->assertContains( 'validation', $data['available_hooks'] ?? [] );

        $node_ids = array_column( $data['nodes'] ?? [], 'mapping_id' );
        $this->assertContains( 'map_spam_v1', $node_ids );

        delete_option( $option_key );
    }

    public function test_get_workflow_plan_syncs_and_retries_when_cps_plan_omits_local_mappings(): void
    {
        if ( ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan' ) ) {
            $this->markTestSkipped( 'Mappings sync reconciliation test double is unavailable.' );
        }

        $option_key = 'sentient_forms_actions_gravity_forms_1';
        update_option( $option_key, [
            'sf_disabled' => false,
            'map_spam_v1' => [
                'local_mapping_id'           => 'map_spam_v1',
                'central_action_id'          => 'spam_detection_v1',
                'action_type_indicator'      => 'master',
                'is_action_enabled_for_form' => true,
                'trigger_hooks'              => [ 'gform_validation' ],
                'action_name_label'          => 'Local Spam Detection',
            ],
        ] );

        $sync = new Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan();
        $this->set_mappings_sync( $sync );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/workflow-plan' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'hook_scope', 'all' );

        $response = $this->controller->get_workflow_plan( $request );
        $data     = $response->get_data();

        $this->assertSame( 'cps', $data['authority'] ?? null );
        $this->assertFalse( $data['cps_unreachable'] ?? true );
        $this->assertSame( 2, $sync->plan_calls, 'Planner should retry once after reconciliation.' );
        $this->assertSame( 1, $sync->sync_calls, 'Local mappings should be reconciled to CPS before retrying.' );
        $this->assertSame( 'map_spam_v1', $sync->synced_actions[0]['local_mapping_id'] ?? null );

        delete_option( $option_key );
    }

    public function test_get_workflow_plan_syncs_only_real_mappings_from_wrapped_option_shape(): void
    {
        if ( ! class_exists( 'Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan' ) ) {
            $this->markTestSkipped( 'Mappings sync reconciliation test double is unavailable.' );
        }

        $option_key = 'sentient_forms_actions_gravity_forms_1';
        $mapping    = [
            'local_mapping_id'           => 'map_spam_v1',
            'central_action_id'          => 'spam_detection_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'gform_validation' ],
            'action_name_label'          => 'Local Spam Detection',
        ];

        update_option(
            $option_key,
            [
                'enabled'       => true,
                'actions'       => [
                    'map_spam_v1' => $mapping,
                ],
                'map_spam_v1'   => $mapping,
                'sf_disabled'   => false,
            ]
        );

        $sync = new Sentient_Forms_Test_Mappings_Sync_Reconciles_Workflow_Plan();
        $this->set_mappings_sync( $sync );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/workflow-plan' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'hook_scope', 'all' );

        $response = $this->controller->get_workflow_plan( $request );
        $data     = $response->get_data();

        $this->assertSame( 'cps', $data['authority'] ?? null );
        $this->assertSame( 1, $sync->sync_calls );
        $this->assertCount( 1, $sync->synced_actions );
        $this->assertSame( 'map_spam_v1', $sync->synced_actions[0]['local_mapping_id'] ?? null );
        $this->assertArrayNotHasKey( 'cps_sync_error_code', $data );

        delete_option( $option_key );
    }

    /**
     * CB-FORMS-001: toggle_form_disabled round-trip — set, read, clear.
     */
    public function test_toggle_form_disabled_round_trip(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_2';
        delete_option( $option_key );

        // — Enable disable flag
        $put_request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/2/actions/disable' );
        $put_request->set_param( 'form_source_slug', 'gravity_forms' );
        $put_request->set_param( 'form_id', 2 );
        $put_request->set_param( 'sf_disabled', true );

        $response = $this->controller->toggle_form_disabled( $put_request );
        $data     = $response->get_data();
        $this->assertTrue( $data['sf_disabled'], 'After toggling ON, sf_disabled should be true' );

        // — READ it back via get_form_disabled
        $get_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/2/actions/disable' );
        $get_request->set_param( 'form_source_slug', 'gravity_forms' );
        $get_request->set_param( 'form_id', 2 );

        $response = $this->controller->get_form_disabled( $get_request );
        $data     = $response->get_data();
        $this->assertTrue( $data['sf_disabled'], 'GET should reflect the stored disabled state' );

        // — Disable it again
        $put_request->set_param( 'sf_disabled', false );
        $response = $this->controller->toggle_form_disabled( $put_request );
        $data     = $response->get_data();
        $this->assertFalse( $data['sf_disabled'], 'After toggling OFF, sf_disabled should be false' );

        // — Verify GET reflects the cleared state
        $response = $this->controller->get_form_disabled( $get_request );
        $data     = $response->get_data();
        $this->assertFalse( $data['sf_disabled'], 'GET should reflect the cleared disabled state' );

        delete_option( $option_key );
    }

    public function test_get_form_disabled_includes_global_and_provider_disable_flags(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_3';
        delete_option( $option_key );
        update_option( $option_key, [ 'sf_disabled' => false ] );

        update_option(
            'sentient_forms_plugin_settings',
            [
                'execution_global_disabled'   => true,
                'execution_provider_disabled' => [ 'gravity_forms' => true ],
            ]
        );

        $get_request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/3/actions/disable' );
        $get_request->set_param( 'form_source_slug', 'gravity_forms' );
        $get_request->set_param( 'form_id', 3 );

        $response = $this->controller->get_form_disabled( $get_request );
        $data     = $response->get_data();

        $this->assertFalse( $data['sf_disabled'] );
        $this->assertTrue( $data['global_disabled'] );
        $this->assertTrue( $data['provider_disabled'] );
        $this->assertTrue( $data['effective_disabled'] );

        delete_option( $option_key );
        delete_option( 'sentient_forms_plugin_settings' );
    }

    private function create_local_first_mapping_fixture( string $form_id ): array
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'local_drawer_qualification',
                'display_name'         => 'Local drawer qualification',
                'definition_json'      => [
                    'builder_template' => 'lead_qualification',
                    'prompt_template'  => 'Qualify {{entry}}.',
                ],
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'openai/gpt-oss-20b:free',
                    'credential_id' => 42,
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => $form_id,
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'email' => '3',
                ],
                'effect_mapping_json' => [
                    'store_result' => true,
                    'meta'         => [
                        'sentient_forms_qualification' => 'structured.qualification',
                    ],
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        return [
            'action_id'  => $action_id,
            'mapping_id' => $mapping_id,
        ];
    }

    /**
     * @param array<int, string>    $trigger_hooks
     * @param array<string, mixed>  $settings
     *
     * @return array<string, mixed>
     */
    private function create_bundled_local_first_mapping( int $form_id, string $action_id, array $trigger_hooks, array $settings = [] ): array
    {
        $request = new WP_REST_Request( 'POST', sprintf( '/sentient-forms/v1/gravity_forms/forms/%d/actions', $form_id ) );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', $form_id );
        $request->set_param( 'central_action_id', $action_id );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', $trigger_hooks );
        $request->set_param( 'settings', $settings );

        $response = $this->controller->add_form_action( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 201, $response->get_status() );

        return $response->get_data();
    }

    /**
     * @param array<int, array<string, mixed>> $mappings
     */
    private function find_local_first_mapping_by_hook( array $mappings, string $hook ): ?array
    {
        foreach ( $mappings as $mapping )
        {
            if ( $hook === (string) ( $mapping['hook'] ?? '' ) )
            {
                return $mapping;
            }
        }

        return null;
    }

    private function truncate_local_workspace_tables(): void
    {
        global $wpdb;

        foreach (
            [
                'sentient_provider_credentials',
                'sentient_action_templates',
                'sentient_custom_actions',
                'sentient_form_mappings',
                'sentient_execution_events',
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invoke_private( string $method, array $args = [] ): mixed
    {
        $reflection = new ReflectionMethod( $this->controller, $method );
        $reflection->setAccessible( true );

        return $reflection->invokeArgs( $this->controller, $args );
    }

    private function set_mappings_sync( ?Sentient_Forms_Mappings_Sync $mappings_sync ): void
    {
        $property = new ReflectionProperty( $this->controller, 'mappings_sync' );
        $property->setAccessible( true );
        $property->setValue( $this->controller, $mappings_sync );
    }

    private function reset_entry_meta_store(): void
    {
        if ( class_exists( 'Sentient_Forms_Test_Gravity_Meta_Store' ) && method_exists( 'Sentient_Forms_Test_Gravity_Meta_Store', 'reset' ) ) {
            Sentient_Forms_Test_Gravity_Meta_Store::reset();
        }

        Sentient_Forms_Test_Gf_Meta_Store::reset();
    }

    private function set_entry_meta( int $entry_id, string $meta_key, mixed $value ): void
    {
        if ( function_exists( 'gform_update_meta' ) ) {
            gform_update_meta( $entry_id, $meta_key, $value );
        }

        if ( class_exists( 'Sentient_Forms_Test_Gravity_Meta_Store' ) && method_exists( 'Sentient_Forms_Test_Gravity_Meta_Store', 'update_meta' ) ) {
            Sentient_Forms_Test_Gravity_Meta_Store::update_meta( $entry_id, $meta_key, $value );
        }

        Sentient_Forms_Test_Gf_Meta_Store::set_meta( $entry_id, $meta_key, $value );
    }
}
