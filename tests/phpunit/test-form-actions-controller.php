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

if ( ! class_exists( 'Sentient_Forms_Test_Opaque_Form_Source_Adapter' ) ) {
	class Sentient_Forms_Test_Opaque_Form_Source_Adapter implements Sentient_Forms_Adapter_Interface {
		/** @var array<int,string> */
		public array $form_exists_calls = [];
		/** @var array<int,string> */
		public array $field_calls = [];

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
				[ 'id' => '42:form-alpha_2026', 'name' => 'Alpha 2026' ],
				[ 'id' => '42_form-alpha_2026', 'name' => 'Underscore Alpha 2026' ],
				[ 'id' => '42.form-alpha_2026', 'name' => 'Dotted Alpha 2026' ],
			];
		}

		public function get_form_fields( $form_id ): array {
			$form_id             = (string) $form_id;
			$this->field_calls[] = $form_id;

			if ( ! in_array( $form_id, [ '42:form-alpha_2026', '42_form-alpha_2026', '42.form-alpha_2026' ], true ) ) {
				return [];
			}

			return [
				[
					'id'               => 'email',
					'label'            => 'Email',
					'type'             => 'email',
					'adminLabel'       => '',
					'storage_eligible' => true,
					'required'         => true,
				],
			];
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

			return in_array( $form_id, [ '42:form-alpha_2026', '42_form-alpha_2026', '42.form-alpha_2026' ], true );
		}
	}
}

class Tests_Form_Actions_Controller extends WP_UnitTestCase {
    private Sentient_Forms_Form_Actions_Controller $controller;
    private ?Sentient_Forms_Test_Opaque_Form_Source_Adapter $opaque_form_adapter = null;
    /** @var string[] */
    private array $dynamic_action_option_keys = [];

    protected function setUp(): void {
        parent::setUp();
        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_workspace_tables();
        Sentient_Forms_Plugin::instance()->clear_license_data();
        $this->controller = new Sentient_Forms_Form_Actions_Controller();
        GFAPI::$entries = [];
        GFAPI::$forms = [];
        $this->reset_entry_meta_store();
        delete_option( 'sentient_forms_action_log' );
        delete_option( 'sentient_forms_form_status_gravity_forms_42' );
        delete_option( 'sentient_forms_actions_gravity_forms_1' );
        delete_option( 'sentient_forms_actions_gravity_forms_2' );
        $this->dynamic_action_option_keys = [];
        $elementor_adapter = Sentient_Forms_Plugin::instance()->get_form_adapter_registry()->get_adapter_by_id( 'elementor_pro_forms' );
        if ( $elementor_adapter && method_exists( $elementor_adapter, 'reset_discovery_cache' ) )
        {
            $elementor_adapter->reset_discovery_cache();
        }
    }

    protected function tearDown(): void {
        remove_filter( 'sentient_forms_supported_form_sources', [ $this, 'add_opaque_form_source' ] );
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        remove_all_filters( 'sentient_forms_contact_form_7_forms' );
        remove_all_filters( 'sentient_forms_contact_form_7_form_object' );
        remove_all_filters( 'sentient_forms_wpforms_is_active' );
        remove_all_filters( 'sentient_forms_wpforms_native_entry_storage_available' );
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_elementor_pro_form_submissions_api_available' );
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );
        Sentient_Forms_Plugin::instance()->get_form_adapter_registry()->unregister_adapter( 'opaque_forms' );
        $this->opaque_form_adapter = null;
        foreach ( $this->dynamic_action_option_keys as $option_key )
        {
            delete_option( $option_key );
        }
        Sentient_Forms_Plugin::instance()->clear_license_data();
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

    private function create_elementor_form_page_for_controller(): int
    {
        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Landing Page',
            ]
        );

        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [
                                        'form_name'   => 'Known Elementor Form',
                                        'form_fields' => [
                                            [
                                                'custom_id'   => 'full_name',
                                                'field_label' => 'Full name',
                                                'field_type'  => 'text',
                                            ],
                                        ],
                                    ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        return $page_id;
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

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/opaque_forms/forms/42:form-alpha_2026/ledger-settings' ) );
        $request->set_param( 'enabled', true );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'opaque_forms', $data['form_source'] ?? null );
        $this->assertSame( '42:form-alpha_2026', $data['form_id'] ?? null );
        $this->assertTrue( $data['enabled'] ?? false );
        $this->assertSame( [ '42:form-alpha_2026' ], $adapter->form_exists_calls );
    }

    public function test_put_ledger_settings_accepts_url_encoded_opaque_form_ids_for_non_gravity_sources(): void
    {
        $adapter = $this->register_opaque_form_source_adapter();

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/opaque_forms/forms/42%3Aform-alpha_2026/ledger-settings' ) );
        $request->set_param( 'enabled', true );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'opaque_forms', $data['form_source'] ?? null );
        $this->assertSame( '42:form-alpha_2026', $data['form_id'] ?? null );
        $this->assertTrue( $data['enabled'] ?? false );
        $this->assertSame( [ '42:form-alpha_2026' ], $adapter->form_exists_calls );
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

    public function test_elementor_ledger_settings_rejects_missing_opaque_form_id(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Landing Page',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Known Elementor Form' ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/elementor_pro_forms/forms/' . $page_id . ':missing-widget/ledger-settings' ) );
        $request->set_param( 'enabled', true );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 404, $response->get_status() );
        $this->assertSame( 'rest_form_not_found', $data['code'] ?? null );
    }

    public function test_elementor_actions_allow_supported_validation_lifecycle(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        $this->create_ready_managed_credential();

        $page_id = $this->create_elementor_form_page_for_controller();
        $form_id = $page_id . ':formabc';

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'POST', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/actions' ) );
        $request->set_param( 'central_action_id', 'content_validation_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'validation' ] );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        global $wpdb;
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored   = $mappings->list_for_form( 'elementor_pro_forms', $form_id );

        $this->assertSame( 201, $response->get_status(), wp_json_encode( $data ) );
        $this->assertSame( 'content_validation_v1', $data['central_action_id'] ?? null );
        $this->assertSame( [ 'validation' ], $data['trigger_hooks'] ?? null );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'validation', $stored[0]['hook'] ?? null );
    }

    public function test_elementor_actions_strip_native_result_writing_effects(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );
        $this->create_ready_managed_credential();

        $page_id = $this->create_elementor_form_page_for_controller();
        $form_id = $page_id . ':formabc';

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'POST', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/actions' ) );
        $request->set_param( 'central_action_id', 'entry_summary_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'after_submission' ] );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        global $wpdb;
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored   = $mappings->list_for_form( 'elementor_pro_forms', $form_id );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
        $this->assertSame( 'entry_summary_v1', $data['central_action_id'] ?? null );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'after_submission', $stored[0]['hook'] ?? null );
        $this->assertArrayNotHasKey( 'store_result', $stored[0]['effect_mapping_json'] ?? [] );
        $this->assertArrayNotHasKey( 'meta', $stored[0]['effect_mapping_json'] ?? [] );
        $this->assertArrayNotHasKey( 'entry_note', $stored[0]['effect_mapping_json'] ?? [] );
    }


    public function test_elementor_spam_actions_strip_native_spam_status_effects(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );
        $this->create_ready_managed_credential();

        $page_id = $this->create_elementor_form_page_for_controller();
        $form_id = $page_id . ':formabc';

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'POST', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/actions' ) );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'after_submission' ] );
        $request->set_param(
            'settings',
            [
                'suppress_notifications_on_spam' => true,
                'suppress_webhooks_on_spam'      => true,
                'skip_downstream_on_spam'        => true,
                'spam_confidence_threshold'      => 0.72,
                'spam_result_display_mode'       => 'all_results',
                'spam_indicators_display'        => 'detailed',
            ]
        );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        global $wpdb;
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored   = $mappings->list_for_form( 'elementor_pro_forms', $form_id );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 'local_first', $data['action_type_indicator'] ?? null );
        $this->assertSame( 'spam_detection_v1', $data['central_action_id'] ?? null );
        $this->assertCount( 1, $stored );
        $this->assertSame( 'after_submission', $stored[0]['hook'] ?? null );
        $this->assertArrayNotHasKey( 'store_result', $stored[0]['effect_mapping_json'] ?? [] );
        $this->assertArrayNotHasKey( 'meta', $stored[0]['effect_mapping_json'] ?? [] );
        $this->assertArrayNotHasKey( 'entry_note', $stored[0]['effect_mapping_json'] ?? [] );
        $this->assertSame(
            [ 'skip_downstream_on_spam' => true ],
            $stored[0]['effect_mapping_json']['spam'] ?? null
        );
    }

    public function test_elementor_actions_reject_free_elementor_requires_pro_state_before_form_lookup(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_false' );

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'POST', '/sentient-forms/v1/elementor_pro_forms/forms/123:formabc/actions' ) );
        $request->set_param( 'central_action_id', 'remote_summary_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'after_submission' ] );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'rest_form_source_unavailable', $data['code'] ?? null );
        $this->assertStringContainsString( 'Elementor Pro Forms', $data['message'] ?? '' );
        $this->assertSame( [], get_option( 'sentient_forms_actions_elementor_pro_forms_123_formabc', [] ) );
    }

    public function test_form_action_routes_preserve_opaque_form_ids_without_persisting_unknown_actions(): void
    {
        $adapter = $this->register_opaque_form_source_adapter();
        $form_id = '42:form-alpha_2026';

        $fields_request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/opaque_forms/forms/' . $form_id . '/actions/fields' ) );
        $fields_response = $this->dispatch_form_actions_request( $fields_request );
        $fields_data     = $fields_response->get_data();

        $this->assertSame( 200, $fields_response->get_status() );
        $this->assertSame( 'email', $fields_data['data'][0]['id'] ?? null );
        $this->assertSame( [ $form_id ], $adapter->field_calls );

        $create_request = $this->authenticate_rest_request( new WP_REST_Request( 'POST', '/sentient-forms/v1/opaque_forms/forms/' . $form_id . '/actions' ) );
        $create_request->set_param( 'central_action_id', 'remote_summary_v1' );
        $create_request->set_param( 'action_type_indicator', 'master' );
        $create_request->set_param( 'trigger_hooks', [ 'after_submission' ] );

        $create_response = $this->dispatch_form_actions_request( $create_request );
        $created         = $create_response->get_data();

        $this->assertSame( 409, $create_response->get_status() );
        $this->assertSame( 'rest_local_action_mapping_required', $created['code'] ?? null );

        $stored = get_option( 'sentient_forms_actions_opaque_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id ), [] );
        $this->assertSame( [], $stored );

        $bootstrap_request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/opaque_forms/forms/' . $form_id . '/actions/bootstrap' ) );
        $bootstrap_response = $this->dispatch_form_actions_request( $bootstrap_request );
        $bootstrap          = $bootstrap_response->get_data();

        $this->assertSame( 200, $bootstrap_response->get_status() );
        $this->assertSame( $form_id, $bootstrap['form_id'] ?? null );
        $this->assertSame( 'Alpha 2026', $bootstrap['form']['name'] ?? null );
        $this->assertSame( 'email', $bootstrap['form_fields'][0]['id'] ?? null );
        $this->assertSame( [], $bootstrap['actions'] ?? null );

        $disable_request = $this->authenticate_rest_request( new WP_REST_Request( 'PUT', '/sentient-forms/v1/opaque_forms/forms/' . $form_id . '/actions/disable' ) );
        $disable_request->set_param( 'sf_disabled', true );

        $disable_response = $this->dispatch_form_actions_request( $disable_request );
        $disable_data     = $disable_response->get_data();

        $this->assertSame( 200, $disable_response->get_status() );
        $this->assertTrue( $disable_data['sf_disabled'] ?? false );

        $stored = get_option( 'sentient_forms_actions_opaque_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id ), [] );
        $this->assertTrue( $stored['sf_disabled'] ?? false );
    }

    public function test_unknown_actions_do_not_create_option_keys_for_provider_native_ids(): void
    {
        $this->register_opaque_form_source_adapter();

        $form_ids = [
            '42:form-alpha_2026',
            '42_form-alpha_2026',
            '42.form-alpha_2026',
        ];
        foreach ( $form_ids as $form_id )
        {
            $create_request = $this->authenticate_rest_request( new WP_REST_Request( 'POST', '/sentient-forms/v1/opaque_forms/forms/' . rawurlencode( $form_id ) . '/actions' ) );
            $create_request->set_param( 'central_action_id', 'remote_summary_v1' );
            $create_request->set_param( 'action_type_indicator', 'master' );
            $create_request->set_param( 'trigger_hooks', [ 'after_submission' ] );

            $create_response = $this->dispatch_form_actions_request( $create_request );
            $created         = $create_response->get_data();

            $this->assertSame( 409, $create_response->get_status() );
            $this->assertSame( 'rest_local_action_mapping_required', $created['code'] ?? null );
            $this->assertSame(
                [],
                get_option( 'sentient_forms_actions_opaque_forms_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id ), [] )
            );
        }

        global $wpdb;
        $this->dynamic_action_option_keys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'sentient_forms_actions_opaque_forms_' ) . '%'
            )
        );

        $this->assertSame( [], $this->dynamic_action_option_keys );
    }

    public function test_get_submission_ledger_list_returns_captured_form_submissions(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

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

    public function test_submission_ledger_list_normalizes_empty_native_entry_fields_to_null(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '44444444-5555-4666-8777-999999999999',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '1',
                    'native_entry_id'     => '',
                    'native_entry_url'    => '',
                    'logical_fields_json' => [
                        'name' => 'Empty native fields',
                    ],
                ]
            )
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertArrayHasKey( 'native_entry_id', $data['submissions'][0] ?? [] );
        $this->assertArrayHasKey( 'native_entry_url', $data['submissions'][0] ?? [] );
        $this->assertNull( $data['submissions'][0]['native_entry_id'] );
        $this->assertNull( $data['submissions'][0]['native_entry_url'] );
    }

    public function test_submission_ledger_search_filters_server_side_before_pagination(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );

        for ( $index = 1; $index <= 60; $index++ )
        {
            $this->assertIsInt(
                $ledger->create(
                    [
                        'submission_uuid'     => sprintf( '%08d-1111-4111-8111-%012d', $index, $index ),
                        'form_source'         => 'gravity_forms',
                        'form_id'             => '1',
                        'native_entry_id'     => (string) ( 1000 + $index ),
                        'captured_at'         => sprintf( '2026-06-20 %02d:00:00', $index % 24 ),
                        'logical_fields_json' => [
                            'name'    => 'Ordinary Lead ' . $index,
                            'message' => 'General inquiry without the search token.',
                        ],
                    ]
                )
            );
        }

        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '99999999-1111-4111-8111-999999999999',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '1',
                    'native_entry_id'     => 'needle-entry-77',
                    'captured_at'         => '2026-06-01 00:00:00',
                    'logical_fields_json' => [
                        'name'    => 'Late Prospect',
                        'message' => 'needle-prospect asks about a custom integration.',
                    ],
                ]
            )
        );

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions' ) );
        $request->set_param( 'per_page', 10 );
        $request->set_param( 'offset', 0 );
        $request->set_param( 'q', 'needle-prospect' );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $data['total'] ?? null );
        $this->assertCount( 1, $data['submissions'] ?? [] );
        $this->assertSame( 'needle-entry-77', $data['submissions'][0]['native_entry_id'] ?? null );
    }

    public function test_submission_ledger_sort_orders_server_side_records(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        foreach ( [ 'entry-c', 'entry-a', 'entry-b' ] as $index => $native_entry_id )
        {
            $this->assertIsInt(
                $ledger->create(
                    [
                        'submission_uuid'     => sprintf( '78787878-1111-4111-8111-00000000000%d', $index + 1 ),
                        'form_source'         => 'gravity_forms',
                        'form_id'             => '1',
                        'native_entry_id'     => $native_entry_id,
                        'captured_at'         => sprintf( '2026-06-20 10:0%d:00', $index ),
                        'logical_fields_json' => [
                            'name' => 'Sorted Lead ' . $index,
                        ],
                    ]
                )
            );
        }

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions' ) );
        $request->set_param( 'sort', 'native_entry_asc' );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 3, $data['total'] ?? null );
        $this->assertSame(
            [ 'entry-a', 'entry-b', 'entry-c' ],
            array_column( $data['submissions'] ?? [], 'native_entry_id' )
        );
    }

    public function test_submission_ledger_sorts_numeric_native_entry_ids_by_number(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        foreach ( [ '10', '1', '2' ] as $index => $native_entry_id )
        {
            $this->assertIsInt(
                $ledger->create(
                    [
                        'submission_uuid'     => sprintf( '98989898-1111-4111-8111-00000000000%d', $index + 1 ),
                        'form_source'         => 'gravity_forms',
                        'form_id'             => '1',
                        'native_entry_id'     => $native_entry_id,
                        'captured_at'         => sprintf( '2026-06-20 11:0%d:00', $index ),
                        'logical_fields_json' => [
                            'name' => 'Numeric Entry Lead ' . $index,
                        ],
                    ]
                )
            );
        }

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions' ) );
        $request->set_param( 'sort', 'native_entry_asc' );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [ '1', '2', '10' ],
            array_column( $data['submissions'] ?? [], 'native_entry_id' )
        );

        $request->set_param( 'sort', 'native_entry_desc' );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame(
            [ '10', '2', '1' ],
            array_column( $data['submissions'] ?? [], 'native_entry_id' )
        );
    }

    public function test_submission_ledger_datetime_local_filters_match_mysql_captured_timestamps(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '12121212-3434-4567-8abc-121212121212',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '1',
                    'native_entry_id'     => 'same-day-entry',
                    'captured_at'         => '2026-06-28 13:00:00',
                    'logical_fields_json' => [
                        'name' => 'Same Day Prospect',
                    ],
                ]
            )
        );

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions' ) );
        $request->set_param( 'captured_from', '2026-06-28T12:00' );
        $request->set_param( 'captured_to', '2026-06-28T14:00' );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $data['total'] ?? null );
        $this->assertCount( 1, $data['submissions'] ?? [] );
        $this->assertSame( 'same-day-entry', $data['submissions'][0]['native_entry_id'] ?? null );
    }

    public function test_submission_ledger_ignores_invalid_captured_datetime_filters(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '34343434-5656-4789-8abc-343434343434',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '1',
                    'native_entry_id'     => 'invalid-date-survivor',
                    'captured_at'         => '2026-06-28 13:00:00',
                    'logical_fields_json' => [
                        'name' => 'Invalid Date Survivor',
                    ],
                ]
            )
        );

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/submissions' ) );
        $request->set_param( 'captured_from', 'not-a-date' );
        $request->set_param( 'captured_to', 'also-not-a-date' );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $data['total'] ?? null );
        $this->assertCount( 1, $data['submissions'] ?? [] );
        $this->assertSame( 'invalid-date-survivor', $data['submissions'][0]['native_entry_id'] ?? null );
    }

    public function test_submission_ledger_repository_ignores_invalid_captured_datetime_filters(): void
    {
        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'     => '45454545-6767-489a-8abc-454545454545',
                    'form_source'         => 'gravity_forms',
                    'form_id'             => '1',
                    'native_entry_id'     => 'repository-invalid-date-survivor',
                    'captured_at'         => '2026-06-28 13:00:00',
                    'logical_fields_json' => [
                        'name' => 'Repository Invalid Date Survivor',
                    ],
                ]
            )
        );

        $filters = [
            'captured_from' => 'not-a-date',
            'captured_to'   => 'also-not-a-date',
        ];
        $records = $ledger->list_for_form( 'gravity_forms', '1', 10, 0, $filters );

        $this->assertSame( 1, $ledger->count_for_form( 'gravity_forms', '1', $filters ) );
        $this->assertCount( 1, $records );
        $this->assertSame( 'repository-invalid-date-survivor', $records[0]['native_entry_id'] ?? null );
    }

    public function test_get_submission_ledger_detail_returns_scoped_submission(): void
    {
        GFAPI::$forms[1] = [
            'id'    => 1,
            'title' => 'Contact Form',
        ];

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'gravity_forms', '1', true, 1 ) );

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

    public function test_elementor_submission_ledger_list_groups_linked_action_runs(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Ledger Page',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Elementor Ledger Form' ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        $form_id         = $page_id . ':formabc';
        $submission_uuid = '66666666-7777-4888-9999-aaaaaaaaaaaa';

        global $wpdb;
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $this->assertIsArray( $settings->set_enabled( 'elementor_pro_forms', $form_id, true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'        => $submission_uuid,
                    'form_source'            => 'elementor_pro_forms',
                    'form_id'                => $form_id,
                    'native_entry_id'        => null,
                    'logical_fields_json'    => [
                        'email' => 'lead@example.test',
                    ],
                    'provider_metadata_json' => [
                        'form_name' => 'Elementor Ledger Form',
                    ],
                    'file_refs_json'         => [],
                    'redaction_summary_json' => [
                        'redacted_keys' => [],
                    ],
                ]
            )
        );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-elementor-ledger-run',
                'mapping_id'           => 987,
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => $form_id,
                'submission_uuid'      => $submission_uuid,
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'summary' => 'Elementor action result is grouped.',
                    ],
                ],
            ]
        );
        $events->record(
            [
                'execution_request_id' => 'req-other-ledger-run',
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => $form_id,
                'submission_uuid'      => '77777777-8888-4999-aaaa-bbbbbbbbbbbb',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
            ]
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/submissions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $submission_uuid, $data['submissions'][0]['submission_uuid'] ?? null );
        $this->assertCount( 1, $data['submissions'][0]['action_runs'] ?? [] );
        $this->assertSame( 'req-elementor-ledger-run', $data['submissions'][0]['action_runs'][0]['execution_request_id'] ?? null );
        $this->assertSame( 987, $data['submissions'][0]['action_runs'][0]['mapping_id'] ?? null );
        $this->assertSame( 'success', $data['submissions'][0]['action_runs'][0]['status'] ?? null );
        $this->assertSame(
            'Elementor action result is grouped.',
            $data['submissions'][0]['action_runs'][0]['last_result']['structured']['summary'] ?? null
        );
    }

    public function test_elementor_submission_ledger_list_includes_more_than_default_execution_event_page(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page_for_controller();
        $form_id         = $page_id . ':formabc';
        $submission_uuid = '66666666-7777-4888-9999-bbbbbbbbbbbb';

        global $wpdb;
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $this->assertIsArray( $settings->set_enabled( 'elementor_pro_forms', $form_id, true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'        => $submission_uuid,
                    'form_source'            => 'elementor_pro_forms',
                    'form_id'                => $form_id,
                    'native_entry_id'        => null,
                    'logical_fields_json'    => [
                        'email' => 'many-actions@example.test',
                    ],
                    'provider_metadata_json' => [
                        'form_name' => 'Known Elementor Form',
                    ],
                    'file_refs_json'         => [],
                    'redaction_summary_json' => [
                        'redacted_keys' => [],
                    ],
                ]
            )
        );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        for ( $i = 0; $i < 25; $i++ )
        {
            $events->record(
                [
                    'execution_request_id' => sprintf( 'req-ledger-many-%02d', $i ),
                    'mapping_id'           => 900 + $i,
                    'form_source'          => 'elementor_pro_forms',
                    'form_id'              => $form_id,
                    'submission_uuid'      => $submission_uuid,
                    'provider'             => 'openrouter',
                    'model'                => 'openrouter/auto',
                    'status'               => 'succeeded',
                ]
            );
        }

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/submissions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $runs        = $data['submissions'][0]['action_runs'] ?? [];
        $request_ids = array_column( $runs, 'execution_request_id' );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 25, $runs );
        $this->assertContains( 'req-ledger-many-00', $request_ids );
        $this->assertContains( 'req-ledger-many-24', $request_ids );
    }

    public function test_elementor_submission_ledger_list_includes_central_action_log_runs(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Central Ledger Page',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Elementor Central Ledger Form' ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        $form_id         = $page_id . ':formabc';
        $submission_uuid = '99999999-aaaa-4bbb-8ccc-dddddddddddd';

        global $wpdb;
        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $this->assertIsArray( $settings->set_enabled( 'elementor_pro_forms', $form_id, true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'        => $submission_uuid,
                    'form_source'            => 'elementor_pro_forms',
                    'form_id'                => $form_id,
                    'native_entry_id'        => null,
                    'logical_fields_json'    => [
                        'email' => 'central-lead@example.test',
                    ],
                    'provider_metadata_json' => [
                        'form_name' => 'Elementor Central Ledger Form',
                    ],
                    'file_refs_json'         => [],
                    'redaction_summary_json' => [
                        'redacted_keys' => [],
                    ],
                ]
            )
        );

        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'          => 'elementor_pro_forms',
                    'form_id'              => $form_id,
                    'submission_uuid'      => $submission_uuid,
                    'execution_request_id' => 'req-elementor-central-run',
                    'action_code'          => 'entry_summary_v1',
                    'action_label'         => 'Entry Summary',
                    'status'               => 'success',
                    'provider'             => 'openrouter',
                    'model'                => 'openrouter/auto',
                    'result_summary'       => 'Central Elementor action completed.',
                    'created_at'           => '2026-06-27T22:00:00+00:00',
                    'details'              => [
                        'structured' => [
                            'summary' => 'Central Elementor action completed.',
                        ],
                    ],
                ],
                [
                    'form_source'          => 'elementor_pro_forms',
                    'form_id'              => $form_id,
                    'submission_uuid'      => '77777777-8888-4999-aaaa-bbbbbbbbbbbb',
                    'execution_request_id' => 'req-other-elementor-central-run',
                    'action_code'          => 'entry_summary_v1',
                    'action_label'         => 'Entry Summary',
                    'status'               => 'success',
                    'result_summary'       => 'Wrong Elementor submission completed.',
                    'created_at'           => '2026-06-27T22:01:00+00:00',
                ],
            ],
            false
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/submissions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $submission_uuid, $data['submissions'][0]['submission_uuid'] ?? null );
        $this->assertCount( 1, $data['submissions'][0]['action_runs'] ?? [] );
        $this->assertSame( 'req-elementor-central-run', $data['submissions'][0]['action_runs'][0]['execution_request_id'] ?? null );
        $this->assertNull( $data['submissions'][0]['action_runs'][0]['mapping_id'] ?? null );
        $this->assertSame( 'success', $data['submissions'][0]['action_runs'][0]['status'] ?? null );
        $this->assertSame( 'openrouter', $data['submissions'][0]['action_runs'][0]['provider'] ?? null );
        $this->assertSame( 'openrouter/auto', $data['submissions'][0]['action_runs'][0]['model'] ?? null );
        $this->assertSame(
            'Central Elementor action completed.',
            $data['submissions'][0]['action_runs'][0]['last_result']['structured']['summary'] ?? null
        );
    }

    public function test_elementor_submission_ledger_records_require_enabled_ledger_settings(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Ledger Disabled Page',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Elementor Ledger Disabled Form' ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        $form_id         = $page_id . ':formabc';
        $submission_uuid = '88888888-9999-4aaa-bbbb-cccccccccccc';

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'        => $submission_uuid,
                    'form_source'            => 'elementor_pro_forms',
                    'form_id'                => $form_id,
                    'native_entry_id'        => null,
                    'logical_fields_json'    => [
                        'email' => 'lead@example.test',
                    ],
                    'provider_metadata_json' => [
                        'form_name' => 'Elementor Ledger Disabled Form',
                    ],
                    'file_refs_json'         => [],
                    'redaction_summary_json' => [
                        'redacted_keys' => [],
                    ],
                ]
            )
        );

        $list_request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/submissions' ) );
        $list_response = $this->dispatch_form_actions_request( $list_request );
        $list_data     = $list_response->get_data();

        $this->assertSame( 403, $list_response->get_status() );
        $this->assertSame( 'sentient_forms_submission_ledger_disabled', $list_data['code'] ?? null );

        $detail_request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/submissions/' . $submission_uuid ) );
        $detail_response = $this->dispatch_form_actions_request( $detail_request );
        $detail_data     = $detail_response->get_data();

        $this->assertSame( 403, $detail_response->get_status() );
        $this->assertSame( 'sentient_forms_submission_ledger_disabled', $detail_data['code'] ?? null );
    }

    public function test_elementor_paid_limited_submission_ledger_list_suppresses_native_submission_links(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Paid Limited Page',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Elementor Paid Limited Form' ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        $form_id         = $page_id . ':formabc';
        $submission_uuid = '77777777-8888-4999-aaaa-bbbbbbbbbbbb';

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $GLOBALS['wpdb'] );
        $this->assertIsArray( $settings->set_enabled( 'elementor_pro_forms', $form_id, true, 1 ) );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $GLOBALS['wpdb'] );
        $this->assertIsInt(
            $ledger->create(
                [
                    'submission_uuid'        => $submission_uuid,
                    'form_source'            => 'elementor_pro_forms',
                    'form_id'                => $form_id,
                    'native_entry_id'        => 'elementor-submission-123',
                    'native_entry_url'       => 'https://example.test/wp-admin/admin.php?page=e-form-submissions&submission=123',
                    'logical_fields_json'    => [
                        'email' => 'lead@example.test',
                    ],
                    'provider_metadata_json' => [
                        'form_name' => 'Elementor Paid Limited Form',
                    ],
                    'file_refs_json'         => [],
                    'redaction_summary_json' => [
                        'redacted_keys' => [],
                    ],
                ]
            )
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/submissions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( $submission_uuid, $data['submissions'][0]['submission_uuid'] ?? null );
        $this->assertNull( $data['submissions'][0]['native_entry_id'] ?? null );
        $this->assertNull( $data['submissions'][0]['native_entry_url'] ?? null );
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

    public function test_elementor_opaque_form_execution_status_uses_local_execution_event(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Status Page',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Elementor Status Form' ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        $form_id = $page_id . ':formabc';

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-elementor-opaque-status',
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => $form_id,
                'entry_id'             => '123',
                'submission_uuid'      => '77777777-7777-4777-8777-777777777777',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'summary' => 'Elementor lead looks qualified.',
                    ],
                ],
            ]
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/actions/status' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'Local action completed.', $data['message'] ?? null );
        $this->assertArrayHasKey( 'entry_id', $data );
        $this->assertNull( $data['entry_id'] );
        $this->assertSame( 'Elementor lead looks qualified.', $data['last_result']['structured']['summary'] ?? null );
    }

    public function test_elementor_opaque_form_execution_status_uses_exact_action_log_form_id(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Action Log Status Page',
            ]
        );
        update_post_meta(
            $page_id,
            '_elementor_data',
            wp_slash(
                wp_json_encode(
                    [
                        [
                            'id'       => 'container1',
                            'elType'   => 'container',
                            'settings' => [],
                            'elements' => [
                                [
                                    'id'         => 'formabc',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Elementor Action Log Status Form' ],
                                    'elements'   => [],
                                ],
                                [
                                    'id'         => 'otherwidget',
                                    'elType'     => 'widget',
                                    'widgetType' => 'form',
                                    'settings'   => [ 'form_name' => 'Different Elementor Widget' ],
                                    'elements'   => [],
                                ],
                            ],
                        ],
                    ]
                )
            )
        );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn(): array => [ $page_id ] );

        $form_id       = $page_id . ':formabc';
        $other_form_id = $page_id . ':otherwidget';

        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'    => 'elementor_pro_forms',
                    'form_id'        => $other_form_id,
                    'entry_id'       => '123',
                    'action_code'    => 'entry_summary_v1',
                    'status'         => 'success',
                    'result_summary' => 'Wrong Elementor widget completed.',
                    'created_at'     => '2026-06-25T10:01:00+00:00',
                ],
                [
                    'form_source'    => 'elementor_pro_forms',
                    'form_id'        => $form_id,
                    'entry_id'       => '456',
                    'action_code'    => 'entry_summary_v1',
                    'status'         => 'success',
                    'result_summary' => 'Target Elementor widget completed.',
                    'created_at'     => '2026-06-25T10:02:00+00:00',
                ],
            ],
            false
        );

        $request  = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/actions/status' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'Target Elementor widget completed.', $data['message'] ?? null );
        $this->assertArrayHasKey( 'entry_id', $data );
        $this->assertNull( $data['entry_id'] );
        $this->assertSame( 'Target Elementor widget completed.', $data['last_result'] ?? null );
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
        $form_id         = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'WPForms REST Status',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 0,
                        'settings' => [
                            'form_title' => 'WPForms REST Status',
                        ],
                        'fields'   => [],
                    ]
                ),
            ]
        );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created         = $ledger->create(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'wpforms',
                'form_id'             => (string) $form_id,
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
                'form_id'              => (string) $form_id,
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

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/wpforms/forms/' . $form_id . '/actions/entries/779/status' );
        $request->set_param( 'form_source_slug', 'wpforms' );
        $request->set_param( 'form_id', $form_id );
        $request->set_param( 'entry_id', 779 );

        $response = $this->dispatch_form_actions_request( $this->authenticate_rest_request( $request ) );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 'wpforms', $data['form_source'] ?? null );
        $this->assertSame( $form_id, $data['form_id'] ?? null );
        $this->assertSame( 779, $data['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $data['submission_uuid'] ?? null );
        $this->assertSame( 'Qualified lead.', $data['last_response']['structured']['summary'] ?? null );
        $this->assertNull( $data['last_error'] ?? null );
        $this->assertSame( 'corr-wpforms-native-779', $data['metering_summary']['correlation_id'] ?? null );
        $this->assertSame( 3, $data['metering_summary']['credits_debited'] ?? null );
    }

    public function test_get_wpforms_entry_execution_status_falls_back_to_action_log_for_async_jobs(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $submission_uuid = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
        $form_id         = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'WPForms Async Status',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 0,
                        'settings' => [
                            'form_title' => 'WPForms Async Status',
                        ],
                        'fields'   => [],
                    ]
                ),
            ]
        );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created         = $ledger->create(
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'wpforms',
                'form_id'             => (string) $form_id,
                'native_entry_id'     => '780',
                'native_entry_url'    => admin_url( 'admin.php?page=wpforms-entries&view=details&entry_id=780' ),
                'logical_fields_json' => [
                    'full_name' => 'Katherine Johnson',
                ],
            ]
        );
        $this->assertIsInt( $created );

        update_option(
            'sentient_forms_action_log',
            [
                [
                    'form_source'    => 'wpforms',
                    'form_id'        => $form_id,
                    'entry_id'       => 780,
                    'action_code'    => 'entry_evaluation',
                    'action_label'   => 'Entry Evaluation',
                    'status'         => 'success',
                    'result_summary' => 'Entry evaluation completed.',
                    'created_at'     => '2026-06-25T11:20:00+00:00',
                    'details'        => [
                        'meta'       => [
                            'execution_request_id' => 'req-wpforms-async-780',
                            'correlation_id'       => 'corr-wpforms-async-780',
                            'credits_debited'      => 5,
                        ],
                        'structured' => [
                            'summary' => 'Qualified WPForms lead.',
                        ],
                    ],
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/wpforms/forms/' . $form_id . '/actions/entries/780/status' );
        $request->set_param( 'form_source_slug', 'wpforms' );
        $request->set_param( 'form_id', $form_id );
        $request->set_param( 'entry_id', 780 );

        $response = $this->dispatch_form_actions_request( $this->authenticate_rest_request( $request ) );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );

        $data = $response->get_data();
        $this->assertSame( 'success', $data['status'] ?? null );
        $this->assertSame( 780, $data['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $data['submission_uuid'] ?? null );
        $this->assertSame( 'Qualified WPForms lead.', $data['last_response']['structured']['summary'] ?? null );
        $this->assertSame( '2026-06-25T11:20:00+00:00', $data['processed_at'] ?? null );
        $this->assertSame( 'corr-wpforms-async-780', $data['metering_summary']['correlation_id'] ?? null );
        $this->assertSame( 5, $data['metering_summary']['credits_debited'] ?? null );
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
        $this->create_ready_managed_credential();

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

    public function test_update_form_action_item_rejects_selected_input_mapping_without_fields_or_metadata(): void {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'input_mapping' => [
                    'mode'             => 'selected',
                    'field_ids'        => [],
                    'include_metadata' => false,
                ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'input_mapping.field_ids', $response->get_error_data()['field'] ?? null );
    }

    public function test_update_form_action_item_rejects_input_mapping_without_explicit_mode(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param( 'settings', [ 'input_mapping' => [ 'email' => '3' ] ] );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'input_mapping.mode', $response->get_error_data()['field'] ?? null );
    }

    public function test_update_form_action_item_rejects_null_input_mapping(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param( 'settings', [ 'input_mapping' => null ] );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'input_mapping', $response->get_error_data()['field'] ?? null );
    }

    public function test_update_form_action_item_rejects_excluding_every_form_field_without_metadata(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );
        GFAPI::$forms[1] = [
            'id'     => 1,
            'title'  => 'Projection validation',
            'fields' => [
                [ 'id' => '1', 'label' => 'Name', 'type' => 'text' ],
                [ 'id' => '2', 'label' => 'Email', 'type' => 'email' ],
            ],
        ];

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'input_mapping' => [
                    'mode'             => 'exclude',
                    'field_ids'        => [ '1', '2' ],
                    'include_metadata' => false,
                ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'input_mapping.field_ids', $response->get_error_data()['field'] ?? null );
    }

    public function test_item_schema_publishes_the_input_mapping_structure(): void
    {
        $schema        = $this->controller->get_item_schema();
        $input_mapping = $schema['properties']['settings']['properties']['input_mapping'] ?? null;

        $this->assertIsArray( $input_mapping );
        $this->assertSame( 'object', $input_mapping['type'] ?? null );
        $this->assertFalse( $input_mapping['additionalProperties'] ?? true );
        $this->assertSame( [ 'mode' ], $input_mapping['required'] ?? null );
        $this->assertSame( [ 'all', 'selected', 'exclude' ], $input_mapping['properties']['mode']['enum'] ?? null );
        $this->assertSame( 'array', $input_mapping['properties']['field_ids']['type'] ?? null );
        $this->assertSame( 'string', $input_mapping['properties']['field_ids']['items']['type'] ?? null );
        $this->assertSame( 'boolean', $input_mapping['properties']['include_metadata']['type'] ?? null );
    }

    public function test_update_form_action_item_rejects_nested_input_mapping_field_ids(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'input_mapping' => [
                    'mode'             => 'selected',
                    'field_ids'        => [ [ 'nested' => '1' ] ],
                    'include_metadata' => false,
                ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_config', $response->get_error_code() );
        $this->assertSame( 'input_mapping.field_ids', $response->get_error_data()['field'] ?? null );
    }

    public function test_update_form_action_item_normalizes_explicit_input_mapping_field_ids_before_storage(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );
        GFAPI::$forms[1] = [
            'id'     => 1,
            'title'  => 'Projection normalization',
            'fields' => [
                [ 'id' => '1', 'label' => 'Name', 'type' => 'text' ],
                [ 'id' => '2', 'label' => 'Email', 'type' => 'email' ],
            ],
        ];

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param(
            'settings',
            [
                'input_mapping' => [
                    'mode'             => 'selected',
                    'field_ids'        => [ ' 2 ', 2, '1', '1' ],
                    'include_metadata' => false,
                ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        global $wpdb;
        $stored = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->get( (int) $record['mapping_id'] );
        $this->assertSame(
            [ 'mode' => 'selected', 'field_ids' => [ '2', '1' ], 'include_metadata' => false ],
            $stored['settings_json']['input_mapping'] ?? null
        );
        $this->assertSame( [ 'email' => '3' ], $stored['input_bindings_json'] ?? null );
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
        $this->record_provider_consent( 'openrouter' );
        $this->seed_structured_openrouter_model_cache();

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
                    'mode'             => 'all',
                    'field_ids'        => [],
                    'include_metadata' => true,
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
        $this->assertSame( 'openrouter', $custom_action['model_selection_json']['provider'] ?? null );
        $this->assertSame( $credential_id, (int) ( $custom_action['model_selection_json']['credential_id'] ?? 0 ) );
        $this->assertSame( '~openai/gpt-latest', $custom_action['model_selection_json']['model'] ?? null );

        $catalog_definition = Sentient_Forms_Bundled_Action_Templates::get( 'spam_detection_v1' );
        $this->assertIsArray( $catalog_definition );
        $this->assertArrayHasKey( 'action_policy', $catalog_definition );
        $this->assertArrayHasKey( 'allowed_facets', $catalog_definition );
        $this->assertArrayHasKey( 'enabled_facets', $catalog_definition );
        $this->assertIsArray( $catalog_definition['allowed_facets'] );
        $this->assertIsArray( $catalog_definition['enabled_facets'] );
        $stored_definition = $custom_action['definition_json'] ?? null;
        $this->assertIsArray( $stored_definition );
        $this->assertArrayHasKey( 'action_policy', $stored_definition );
        $this->assertArrayHasKey( 'allowed_facets', $stored_definition );
        $this->assertArrayHasKey( 'enabled_facets', $stored_definition );
        $this->assertIsArray( $stored_definition['allowed_facets'] );
        $this->assertIsArray( $stored_definition['enabled_facets'] );
        $this->assertSame( $catalog_definition['action_policy'], $stored_definition['action_policy'] );
        $this->assertSame( $catalog_definition['allowed_facets'], $stored_definition['allowed_facets'] );
        $this->assertSame( $catalog_definition['enabled_facets'], $stored_definition['enabled_facets'] );

        $stored_mappings = $mappings->list_for_form( 'gravity_forms', '12' );
        $this->assertCount( 2, $stored_mappings );

        $validation_mapping = $this->find_local_first_mapping_by_hook( $stored_mappings, 'validation' );
        $submission_mapping = $this->find_local_first_mapping_by_hook( $stored_mappings, 'after_submission' );

        $this->assertIsArray( $validation_mapping );
        $this->assertIsArray( $submission_mapping );
        $this->assertSame( 'sync', $validation_mapping['execution_mode'] ?? null );
        $this->assertSame( 'sync', $submission_mapping['execution_mode'] ?? null );
        $this->assertSame( [], $validation_mapping['input_bindings_json'] ?? null );
        $this->assertSame(
            [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ],
            $validation_mapping['settings_json']['input_mapping'] ?? null
        );
        $this->assertFalse( $validation_mapping['effect_mapping_json']['spam']['suppress_notifications_on_spam'] ?? true );
        $this->assertTrue( $validation_mapping['effect_mapping_json']['spam']['skip_downstream_on_spam'] ?? false );
        $this->assertSame( 0.65, $validation_mapping['effect_mapping_json']['spam']['min_confidence'] ?? null );
        $this->assertSame( 'all_results', $validation_mapping['effect_mapping_json']['spam']['note']['result_display_mode'] ?? null );
        $this->assertSame( 'detailed', $validation_mapping['effect_mapping_json']['spam']['note']['indicators_display'] ?? null );
    }

    public function test_add_form_action_defaults_bundled_actions_to_managed_when_managed_and_openrouter_are_ready(): void
    {
        $openrouter_credential_id = $this->create_ready_openrouter_credential();
        $managed_credential_id    = $this->create_ready_managed_credential();
        $this->seed_structured_openrouter_model_cache();

        $this->create_bundled_local_first_mapping( 121, 'spam_detection_v1', [ 'gform_validation' ] );

        $selection = $this->get_bundled_custom_action_model_selection( 'spam_detection_v1' );

        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, (int) ( $selection['credential_id'] ?? 0 ) );
        $this->assertSame( 'sf_default', $selection['model'] ?? null );
        $this->assertSame( 'sentient_managed', $selection['selection']['provider'] ?? null );
        $this->assertSame( 'sf_default', $selection['selection']['primary'] ?? null );
        $this->assertSame( $managed_credential_id, (int) ( $selection['selection']['credential_id'] ?? 0 ) );
        $this->assertSame( 'openrouter', $selection['backup_provider'] ?? null );
        $this->assertSame( $openrouter_credential_id, (int) ( $selection['backup_credential_id'] ?? 0 ) );
        $this->assertSame( '~openai/gpt-latest', $selection['backup_model'] ?? null );
    }

    public function test_add_form_action_defaults_bundled_actions_to_managed_when_only_managed_is_ready(): void
    {
        $managed_credential_id = $this->create_ready_managed_credential();

        $this->create_bundled_local_first_mapping( 122, 'spam_detection_v1', [ 'gform_validation' ] );

        $selection = $this->get_bundled_custom_action_model_selection( 'spam_detection_v1' );

        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, (int) ( $selection['credential_id'] ?? 0 ) );
        $this->assertSame( 'sf_default', $selection['model'] ?? null );
        $this->assertArrayNotHasKey( 'backup_provider', $selection );
        $this->assertArrayNotHasKey( 'backup_credential_id', $selection );
    }

    public function test_add_form_action_rejects_bundled_action_when_managed_consent_is_missing(): void
    {
        $this->create_ready_managed_credential( false );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/127/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 127 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param( 'settings', [] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_bundled_action_provider_path_unavailable', $response->get_error_code() );
        $this->assertSame( 'managed_external_service_consent_required', $response->get_error_data()['blocked_reason_code'] ?? null );
    }

    public function test_add_form_action_defaults_bundled_actions_to_managed_when_openrouter_credentials_are_ambiguous(): void
    {
        $managed_credential_id = $this->create_ready_managed_credential();
        $this->create_ready_openrouter_credential();
        $this->create_ready_openrouter_credential();
        $this->seed_structured_openrouter_model_cache();

        $this->create_bundled_local_first_mapping( 125, 'spam_detection_v1', [ 'gform_validation' ] );

        $selection = $this->get_bundled_custom_action_model_selection( 'spam_detection_v1' );

        $this->assertSame( 'sentient_managed', $selection['provider'] ?? null );
        $this->assertSame( $managed_credential_id, (int) ( $selection['credential_id'] ?? 0 ) );
        $this->assertSame( 'sf_default', $selection['model'] ?? null );
        $this->assertArrayNotHasKey( 'backup_provider', $selection );
    }

    public function test_add_form_action_rejects_openrouter_only_route_when_credentials_are_ambiguous(): void
    {
        $this->create_ready_openrouter_credential();
        $this->create_ready_openrouter_credential();
        $this->seed_structured_openrouter_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/130/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 130 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param( 'settings', [] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_bundled_action_provider_path_unavailable', $response->get_error_code() );
        $this->assertSame( 409, $response->get_error_data()['status'] ?? null );
        $this->assertSame( 'multiple_ready_credentials', $response->get_error_data()['blocked_reason_code'] ?? null );
    }

    public function test_add_form_action_uses_openrouter_when_it_is_the_only_ready_route_and_model_supports_structured_output(): void
    {
        $credential_id = $this->create_ready_openrouter_credential();
        $this->seed_structured_openrouter_model_cache();

        $this->create_bundled_local_first_mapping( 123, 'spam_detection_v1', [ 'gform_validation' ] );

        $selection = $this->get_bundled_custom_action_model_selection( 'spam_detection_v1' );

        $this->assertSame( 'openrouter', $selection['provider'] ?? null );
        $this->assertSame( $credential_id, (int) ( $selection['credential_id'] ?? 0 ) );
        $this->assertSame( '~openai/gpt-latest', $selection['model'] ?? null );
        $this->assertSame( 'openrouter', $selection['selection']['provider'] ?? null );
        $this->assertSame( '~openai/gpt-latest', $selection['selection']['primary'] ?? null );
        $this->assertFalse( $selection['selection']['is_preset'] ?? true );
    }

    public function test_add_form_action_preserves_realtime_openrouter_preset_for_clarification_assistant(): void
    {
        $credential_id = $this->create_ready_openrouter_credential();

        $this->create_bundled_local_first_mapping( 129, 'clarification_assistant_v1', [ 'real_time' ] );

        $selection = $this->get_bundled_custom_action_model_selection( 'clarification_assistant_v1' );

        $this->assertSame( 'openrouter', $selection['provider'] ?? null );
        $this->assertSame( $credential_id, (int) ( $selection['credential_id'] ?? 0 ) );
        $this->assertSame( '~google/gemini-flash-latest', $selection['model'] ?? null );
        $this->assertSame( 'openrouter', $selection['selection']['provider'] ?? null );
        $this->assertSame( '~google/gemini-flash-latest', $selection['selection']['primary'] ?? null );
    }

    public function test_add_form_action_rejects_openrouter_route_when_openrouter_consent_is_missing(): void
    {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . 'sentient_provider_credentials', [ 'provider' => 'sentient_managed' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_provider_credentials', [ 'provider' => 'openrouter' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_external_service_consents', [ 'provider' => 'sentient_managed' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_external_service_consents', [ 'provider' => 'openrouter' ] );

        $this->create_ready_openrouter_credential( false );
        $this->seed_structured_openrouter_model_cache();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/128/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 128 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param( 'settings', [] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_bundled_action_provider_path_unavailable', $response->get_error_code() );
        $this->assertSame( 'openrouter_external_service_consent_required', $response->get_error_data()['blocked_reason_code'] ?? null );
    }

    public function test_add_form_action_uses_openrouter_when_managed_account_lacks_ready_credential(): void
    {
        $credential_id = $this->create_ready_openrouter_credential();
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'license_id'     => 'license-form-actions-provider-path-no-credential-test',
                'site_id'        => '55555555-5555-4555-8555-555555555555',
                'proxy_api_key'  => 'proxy-form-actions-provider-path-no-credential-test',
                'tier'           => 'pro',
            ]
        );
        $this->seed_structured_openrouter_model_cache();

        $this->create_bundled_local_first_mapping( 126, 'spam_detection_v1', [ 'gform_validation' ] );

        $selection = $this->get_bundled_custom_action_model_selection( 'spam_detection_v1' );

        $this->assertSame( 'openrouter', $selection['provider'] ?? null );
        $this->assertSame( $credential_id, (int) ( $selection['credential_id'] ?? 0 ) );
        $this->assertSame( '~openai/gpt-latest', $selection['model'] ?? null );
    }

    public function test_add_form_action_rejects_bundled_action_when_no_ready_execution_route_exists(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/124/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 124 );
        $request->set_param( 'central_action_id', 'spam_detection_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'gform_validation' ] );
        $request->set_param( 'settings', [] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_bundled_action_provider_path_unavailable', $response->get_error_code() );
        $this->assertSame( 409, $response->get_error_data()['status'] ?? null );
    }

    public function test_add_form_action_creates_local_first_content_validation_mapping_only_on_supported_hook(): void
    {
        $this->create_ready_managed_credential();

        $data = $this->create_bundled_local_first_mapping(
            13,
            'content_validation_v1',
            [ 'gform_validation' ],
            [
                'input_mapping' => [
                    'mode'             => 'all',
                    'field_ids'        => [],
                    'include_metadata' => true,
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
        $this->assertSame( [], $stored_mappings[0]['input_bindings_json'] ?? null );
        $this->assertSame(
            [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ],
            $stored_mappings[0]['settings_json']['input_mapping'] ?? null
        );
        $this->assertTrue( $stored_mappings[0]['effect_mapping_json']['store_result'] ?? false );
        $this->assertSame( 'structured.message', $stored_mappings[0]['effect_mapping_json']['entry_note']['path'] ?? null );
    }

    public function test_add_form_action_rejects_missing_bundled_local_first_dependency(): void
    {
        global $wpdb;

        $this->create_ready_managed_credential();

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
                    'mode'             => 'all',
                    'field_ids'        => [],
                    'include_metadata' => true,
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
        $this->assertSame( [], $stored_mappings[0]['input_bindings_json'] ?? null );
        $this->assertSame(
            [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ],
            $stored_mappings[0]['settings_json']['input_mapping'] ?? null
        );
    }

    public function test_add_form_action_applies_bundled_contract_to_existing_bundled_local_custom_action(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id = $custom_actions->create(
            [
                'code'                 => Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'content_validation_v1' ),
                'display_name'         => 'Bundled Content Validation',
                'definition_json'      => [
                    'supported_execution_modes' => [ 'validation' ],
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'primary'  => 'openrouter/auto',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/155/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 155 );
        $request->set_param(
            'central_action_id',
            Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'content_validation_v1' )
        );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'after_submission' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'after_submission' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
    }

    public function test_add_form_action_reserves_catalog_code_when_database_row_claims_custom_definition(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id = $custom_actions->create(
            [
                'code'                 => 'content_validation_v1',
                'display_name'         => 'Custom Content Follow-up',
                'definition_json'      => [
                    'supported_execution_modes' => [ 'after_submission' ],
                    'prompt_template'            => 'Summarize {{entry}}.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'primary'  => 'openrouter/auto',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/165/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 165 );
        $request->set_param( 'central_action_id', 'content_validation_v1' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'after_submission' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'after_submission' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
    }

    public function test_add_form_action_rejects_true_custom_realtime_even_when_definition_declares_it(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id = $custom_actions->create(
            [
                'code'                 => 'custom_realtime_claim',
                'display_name'         => 'Custom Realtime Claim',
                'definition_json'      => [
                    'supported_execution_modes' => [ 'real_time' ],
                    'prompt_template'            => 'Ask about {{entry}}.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'primary'  => 'openrouter/auto',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/167/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 167 );
        $request->set_param( 'central_action_id', 'custom_realtime_claim' );
        $request->set_param( 'action_type_indicator', 'custom' );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'real_time' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_realtime_action', $response->get_error_code() );
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
        $this->create_ready_managed_credential();

        $data = $this->create_bundled_local_first_mapping(
            14,
            'entry_summary_v1',
            [ 'gform_after_submission' ],
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
        $this->create_ready_managed_credential();

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

        $this->create_ready_managed_credential();

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
            $this->assertSame( [ 'after_submission' ], $template['hooks'] ?? null );
            $this->assertSame( [ 'after_submission' ], $template['definition_json']['supported_execution_modes'] ?? null );
            $this->assertArrayNotHasKey( 'response_format', $template['definition_json'] ?? [] );
            $this->assertSame( 'object', $template['structured_output_schema']['type'] ?? null );

            $data = $this->create_bundled_local_first_mapping(
                $form_id,
                $action_code,
                [ 'gform_after_submission' ],
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
        $this->create_ready_managed_credential();

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
            [ 'real_time' ],
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

    public function test_add_form_action_allows_spam_validation_and_after_submission_for_all_canonical_form_sources(): void
    {
        $this->create_ready_managed_credential();
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $sources = [
            'gravity_forms',
            'contact_form_7',
            'wpforms',
            'elementor_pro_forms',
        ];

        foreach ( $sources as $index => $form_source )
        {
            $form_id = (string) ( 160 + $index );
            $request = new WP_REST_Request(
                'POST',
                sprintf( '/sentient-forms/v1/%s/forms/%s/actions', $form_source, $form_id )
            );
            $request->set_param( 'form_source_slug', $form_source );
            $request->set_param( 'form_id', $form_id );
            $request->set_param( 'central_action_id', 'spam_detection_v1' );
            $request->set_param( 'action_type_indicator', 'master' );
            $request->set_param( 'trigger_hooks', [ 'validation', 'after_submission' ] );

            $response = $this->controller->add_form_action( $request );

            $this->assertInstanceOf( WP_REST_Response::class, $response, $form_source );
            $this->assertSame( 201, $response->get_status(), $form_source );
            $this->assertSame( 'spam_detection_v1', $response->get_data()['central_action_id'] ?? null, $form_source );
        }
    }

    public function test_add_form_action_rejects_clarification_realtime_for_non_gravity_source(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/contact_form_7/forms/161/actions' );
        $request->set_param( 'form_source_slug', 'contact_form_7' );
        $request->set_param( 'form_id', 161 );
        $request->set_param( 'central_action_id', 'clarification_assistant_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'real_time' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_unsupported_form_source_lifecycle', $response->get_error_code() );
    }

    public function test_add_form_action_fails_closed_when_manifest_canonical_adapter_is_missing(): void
    {
        $registry = Sentient_Forms_Plugin::instance()->get_form_adapter_registry();
        $adapter  = $registry->get_adapter_by_id( 'contact_form_7' );
        $this->assertInstanceOf( Sentient_Forms_Adapter_Interface::class, $adapter );
        $registry->unregister_adapter( 'contact_form_7' );

        try
        {
            $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/contact_form_7/forms/164/actions' );
            $request->set_param( 'form_source_slug', 'contact_form_7' );
            $request->set_param( 'form_id', 164 );
            $request->set_param( 'central_action_id', 'spam_detection_v1' );
            $request->set_param( 'action_type_indicator', 'master' );
            $request->set_param( 'trigger_hooks', [ 'validation' ] );

            $response = $this->controller->add_form_action( $request );
        }
        finally
        {
            $registry->register_adapter( $adapter );
        }

        $this->assertWPError( $response );
        $this->assertSame( 'rest_action_source_contract_missing', $response->get_error_code() );
        $this->assertSame( 500, $response->get_error_data()['status'] ?? null );
    }

    public function test_add_form_action_rejects_non_clarification_realtime_trigger(): void
    {
        $this->create_ready_managed_credential();
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/18/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 18 );
        $request->set_param( 'central_action_id', 'entry_summary_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
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
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
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
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
    }

    public function test_add_form_action_rejects_bundled_mapping_when_any_requested_lifecycle_is_unsupported(): void
    {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/156/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 156 );
        $request->set_param( 'central_action_id', 'entry_summary_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'validation', 'after_submission' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
    }

    public function test_update_bundled_local_first_mapping_rejects_lifecycle_outside_action_source_contract(): void
    {
        $this->create_ready_managed_credential();
        $created = $this->create_bundled_local_first_mapping(
            151,
            'content_validation_v1',
            [ 'validation' ]
        );

        $request = new WP_REST_Request(
            'PUT',
            '/sentient-forms/v1/gravity_forms/forms/151/actions/' . $created['local_mapping_id']
        );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 151 );
        $request->set_param( 'local_mapping_id', $created['local_mapping_id'] );
        $request->set_param( 'trigger_hooks', [ 'after_submission' ] );
        $request->set_param(
            'settings',
            [
                'execution_mode' => 'after_submission',
            ]
        );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
    }

    public function test_rest_update_fails_closed_for_legacy_option_backed_mapping(): void
    {
        GFAPI::$forms[401] = [ 'id' => 401, 'title' => 'Legacy update fixture' ];
        $option_key        = 'sentient_forms_actions_gravity_forms_401';
        $stored            = [
            'map_legacy' => [
                'local_mapping_id'           => 'map_legacy',
                'central_action_id'          => 'entry_summary_v1',
                'action_type_indicator'      => 'master',
                'trigger_hooks'              => [ 'after_submission' ],
                'is_action_enabled_for_form' => true,
                'settings'                   => [ 'execution_mode' => 'after_submission' ],
            ],
        ];
        update_option( $option_key, $stored, false );

        $request = $this->authenticate_rest_request(
            new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/401/actions/map_legacy' )
        );
        $request->set_param( 'is_action_enabled_for_form', false );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'rest_legacy_action_mapping_read_only', $data['code'] ?? null );
        $this->assertStringContainsString( 'create', strtolower( (string) ( $data['message'] ?? '' ) ) );
        $this->assertSame( $stored, get_option( $option_key, [] ) );
        delete_option( $option_key );
    }

    public function test_rest_duplicate_fails_closed_for_legacy_option_backed_mapping(): void
    {
        GFAPI::$forms[402] = [ 'id' => 402, 'title' => 'Legacy duplicate fixture' ];
        $option_key        = 'sentient_forms_actions_gravity_forms_402';
        $stored            = [
            'map_legacy' => [
                'local_mapping_id'           => 'map_legacy',
                'central_action_id'          => 'entry_summary_v1',
                'action_type_indicator'      => 'master',
                'trigger_hooks'              => [ 'after_submission' ],
                'is_action_enabled_for_form' => true,
                'settings'                   => [ 'execution_mode' => 'after_submission' ],
            ],
        ];
        update_option( $option_key, $stored, false );

        $request = $this->authenticate_rest_request(
            new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/402/actions/map_legacy/duplicate' )
        );
        $request->set_param(
            'parent',
            [
                'type' => 'hook_root',
                'hook' => 'after_submission',
            ]
        );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'rest_legacy_action_mapping_read_only', $data['code'] ?? null );
        $this->assertStringContainsString( 'create', strtolower( (string) ( $data['message'] ?? '' ) ) );
        $this->assertSame( $stored, get_option( $option_key, [] ) );
        delete_option( $option_key );
    }

    public function test_rest_duplicate_inserts_local_first_mapping_and_rewires_exact_children(): void
    {
        global $wpdb;

        GFAPI::$forms[403] = [ 'id' => 403, 'title' => 'Local-first duplicate fixture' ];
        $actions           = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings          = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id         = $actions->create(
            [
                'code'                 => 'duplicate_graph_custom',
                'display_name'         => 'Duplicate graph custom',
                'definition_json'      => [ 'prompt_template' => 'Classify {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'primary' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $create_row = function ( string $form_id, ?int $parent_id = null ) use ( $mappings, $action_id ): int {
            $settings = [];
            if ( null !== $parent_id )
            {
                $parent_mapping_id = 'local_first_' . $parent_id;
                $settings = [
                    'trigger_sources' => [
                        'after_submission' => [ 'type' => 'mapping', 'mapping_id' => $parent_mapping_id ],
                    ],
                    'dependency_ids' => [ $parent_mapping_id ],
                ];
            }

            $row_id = $mappings->create(
                [
                    'form_source'         => 'gravity_forms',
                    'form_id'             => $form_id,
                    'hook'                => 'after_submission',
                    'action_kind'         => 'custom_action',
                    'action_id'           => $action_id,
                    'input_bindings_json' => [],
                    'execution_mode'      => 'async',
                    'settings_json'       => $settings,
                    'enabled'             => true,
                ]
            );
            $this->assertIsInt( $row_id );
            return $row_id;
        };

        $parent_id    = $create_row( '403' );
        $source_id    = $create_row( '403', $parent_id );
        $child_id     = $create_row( '403', $parent_id );
        $unrelated_id = $create_row( '403' );
        $foreign_id   = $create_row( '9999' );

        $request = $this->authenticate_rest_request(
            new WP_REST_Request(
                'POST',
                '/sentient-forms/v1/gravity_forms/forms/403/actions/local_first_' . $source_id . '/duplicate'
            )
        );
        $request->set_param(
            'parent',
            [
                'type'       => 'mapping',
                'hook'       => 'after_submission',
                'mapping_id' => 'local_first_' . $parent_id,
            ]
        );

        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 201, $response->get_status() );
        $duplicate_id = absint( $data['duplicate']['local_form_mapping_id'] ?? 0 );
        $this->assertGreaterThan( $foreign_id, $duplicate_id );
        $this->assertSame( $action_id, absint( $mappings->get( $duplicate_id )['action_id'] ?? 0 ) );
        $this->assertSame(
            [ 'type' => 'mapping', 'mapping_id' => 'local_first_' . $parent_id ],
            $mappings->get( $duplicate_id )['settings_json']['trigger_sources']['after_submission'] ?? null
        );
        $this->assertSame(
            [ 'local_first_' . $duplicate_id ],
            $mappings->get( $source_id )['settings_json']['dependency_ids'] ?? null
        );
        $this->assertSame(
            [ 'local_first_' . $duplicate_id ],
            $mappings->get( $child_id )['settings_json']['dependency_ids'] ?? null
        );
        $this->assertArrayNotHasKey( 'dependency_ids', $mappings->get( $unrelated_id )['settings_json'] ?? [] );
        $this->assertSame(
            [ 'local_first_' . $source_id, 'local_first_' . $child_id ],
            $data['insertion']['moved_children'] ?? []
        );
        $this->assertSame( [], $data['insertion']['skipped_children'] ?? null );
    }

    public function test_rest_duplicate_rolls_back_new_row_when_child_rewire_fails(): void
    {
        global $wpdb;

        GFAPI::$forms[404] = [ 'id' => 404, 'title' => 'Duplicate rollback fixture' ];
        $actions           = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings          = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id         = $actions->create(
            [
                'code'                 => 'duplicate_rollback_custom',
                'display_name'         => 'Duplicate rollback custom',
                'definition_json'      => [ 'prompt_template' => 'Classify {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'primary' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $parent_id = $mappings->create(
            [
                'form_source' => 'gravity_forms', 'form_id' => '404', 'hook' => 'after_submission',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async', 'settings_json' => [], 'enabled' => true,
            ]
        );
        $source_id = $mappings->create(
            [
                'form_source' => 'gravity_forms', 'form_id' => '404', 'hook' => 'after_submission',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async',
                'settings_json' => [
                    'trigger_sources' => [
                        'after_submission' => [ 'type' => 'mapping', 'mapping_id' => 'local_first_' . $parent_id ],
                    ],
                    'dependency_ids' => [ 'local_first_' . $parent_id ],
                ],
                'enabled' => true,
            ]
        );
        $second_child_id = $mappings->create(
            [
                'form_source' => 'gravity_forms', 'form_id' => '404', 'hook' => 'after_submission',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async',
                'settings_json' => [
                    'trigger_sources' => [
                        'after_submission' => [ 'type' => 'mapping', 'mapping_id' => 'local_first_' . $parent_id ],
                    ],
                    'dependency_ids' => [ 'local_first_' . $parent_id ],
                ],
                'enabled' => true,
            ]
        );
        $this->assertIsInt( $parent_id );
        $this->assertIsInt( $source_id );
        $this->assertIsInt( $second_child_id );
        $before = $mappings->list_for_form( 'gravity_forms', '404' );

        $mapping_update_count = 0;
        $break_second_mapping_update = static function ( string $query ) use ( $wpdb, &$mapping_update_count ): string {
            if ( str_contains( $query, 'UPDATE `' . $wpdb->prefix . 'sentient_form_mappings`' ) )
            {
                $mapping_update_count++;
                if ( 2 !== $mapping_update_count )
                {
                    return $query;
                }
                return str_replace(
                    '`' . $wpdb->prefix . 'sentient_form_mappings`',
                    '`' . $wpdb->prefix . 'sentient_form_mappings_missing_fixture`',
                    $query
                );
            }

            return $query;
        };
        add_filter( 'query', $break_second_mapping_update );
        $previous_suppress_errors = $wpdb->suppress_errors();
        try
        {
            $request = $this->authenticate_rest_request(
                new WP_REST_Request(
                    'POST',
                    '/sentient-forms/v1/gravity_forms/forms/404/actions/local_first_' . $source_id . '/duplicate'
                )
            );
            $request->set_param(
                'parent',
                [
                    'type'       => 'mapping',
                    'hook'       => 'after_submission',
                    'mapping_id' => 'local_first_' . $parent_id,
                ]
            );
            $response = $this->dispatch_form_actions_request( $request );
        }
        finally
        {
            $wpdb->suppress_errors( $previous_suppress_errors );
            remove_filter( 'query', $break_second_mapping_update );
        }

        $this->assertSame( 500, $response->get_status() );
        $this->assertSame( 'rest_duplicate_transaction_failed', $response->get_data()['code'] ?? null );
        $this->assertGreaterThanOrEqual( 2, $mapping_update_count );
        $this->assertSame( $before, $mappings->list_for_form( 'gravity_forms', '404' ) );
    }

    public function test_rest_duplicate_locks_and_replans_children_from_current_graph(): void
    {
        global $wpdb;

        GFAPI::$forms[405] = [ 'id' => 405, 'title' => 'Concurrent duplicate graph fixture' ];
        $actions           = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings          = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id         = $actions->create(
            [
                'code'                 => 'duplicate_concurrent_custom',
                'display_name'         => 'Duplicate concurrent custom',
                'definition_json'      => [ 'prompt_template' => 'Classify {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'primary' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $create_row = static function ( ?int $parent_id = null ) use ( $mappings, $action_id ): int | WP_Error {
            $settings = [];
            if ( null !== $parent_id )
            {
                $settings = [
                    'trigger_sources' => [
                        'after_submission' => [ 'type' => 'mapping', 'mapping_id' => 'local_first_' . $parent_id ],
                    ],
                    'dependency_ids' => [ 'local_first_' . $parent_id ],
                ];
            }
            return $mappings->create(
                [
                    'form_source' => 'gravity_forms', 'form_id' => '405', 'hook' => 'after_submission',
                    'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                    'execution_mode' => 'async', 'settings_json' => $settings, 'enabled' => true,
                ]
            );
        };
        $first_parent_id  = $create_row();
        $second_parent_id = $create_row();
        $source_id        = $create_row( $first_parent_id );
        $detached_id      = $create_row( $first_parent_id );
        $this->assertIsInt( $first_parent_id );
        $this->assertIsInt( $second_parent_id );
        $this->assertIsInt( $source_id );
        $this->assertIsInt( $detached_id );

        $new_child_id = 0;
        $injected     = false;
        $mutate_before_lock = function ( string $query ) use (
            $mappings,
            $create_row,
            $first_parent_id,
            $second_parent_id,
            $detached_id,
            &$new_child_id,
            &$injected
        ): string {
            if ( $injected || 1 !== preg_match( '/^SAVEPOINT `sentient_forms_mapping_graph_\d+`$/', $query ) )
            {
                return $query;
            }
            $injected = true;
            $updated  = $mappings->update(
                $detached_id,
                [
                    'settings_json' => [
                        'trigger_sources' => [
                            'after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'local_first_' . $second_parent_id,
                            ],
                        ],
                        'dependency_ids' => [ 'local_first_' . $second_parent_id ],
                    ],
                ]
            );
            $this->assertNotWPError( $updated );
            $created = $create_row( $first_parent_id );
            $this->assertIsInt( $created );
            $new_child_id = $created;
            return $query;
        };
        add_filter( 'query', $mutate_before_lock );
        try
        {
            $request = $this->authenticate_rest_request(
                new WP_REST_Request(
                    'POST',
                    '/sentient-forms/v1/gravity_forms/forms/405/actions/local_first_' . $source_id . '/duplicate'
                )
            );
            $request->set_param(
                'parent',
                [
                    'type'       => 'mapping',
                    'hook'       => 'after_submission',
                    'mapping_id' => 'local_first_' . $first_parent_id,
                ]
            );
            $response = $this->dispatch_form_actions_request( $request );
        }
        finally
        {
            remove_filter( 'query', $mutate_before_lock );
        }

        $this->assertTrue( $injected );
        $this->assertGreaterThan( 0, $new_child_id );
        $this->assertSame( 201, $response->get_status() );
        $duplicate_id = absint( $response->get_data()['duplicate']['local_form_mapping_id'] ?? 0 );
        $this->assertGreaterThan( 0, $duplicate_id );
        $this->assertSame(
            [ 'local_first_' . $duplicate_id ],
            $mappings->get( $new_child_id )['settings_json']['dependency_ids'] ?? null
        );
        $this->assertSame(
            [ 'local_first_' . $second_parent_id ],
            $mappings->get( $detached_id )['settings_json']['dependency_ids'] ?? null
        );
        $this->assertContains( 'local_first_' . $new_child_id, $response->get_data()['insertion']['moved_children'] ?? [] );
        $this->assertNotContains( 'local_first_' . $detached_id, $response->get_data()['insertion']['moved_children'] ?? [] );
    }

    public function test_rest_duplicate_preserves_locked_graph_conflict_as_http_409(): void
    {
        global $wpdb;

        GFAPI::$forms[406] = [ 'id' => 406, 'title' => 'Duplicate conflict fixture' ];
        $actions           = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings          = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id         = $actions->create(
            [
                'code'                 => 'duplicate_conflict_custom',
                'display_name'         => 'Duplicate conflict custom',
                'definition_json'      => [ 'prompt_template' => 'Classify {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'primary' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );
        $source_id = $mappings->create(
            [
                'form_source' => 'gravity_forms', 'form_id' => '406', 'hook' => 'after_submission',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async', 'settings_json' => [], 'enabled' => true,
            ]
        );
        $this->assertIsInt( $source_id );

        $mutated = false;
        $change_before_lock = static function ( string $query ) use ( $mappings, $source_id, &$mutated ): string {
            if ( $mutated || 1 !== preg_match( '/^SAVEPOINT `sentient_forms_mapping_graph_\d+`$/', $query ) )
            {
                return $query;
            }
            $mutated = true;
            $updated = $mappings->update( $source_id, [ 'hook' => 'validation' ] );
            if ( is_wp_error( $updated ) )
            {
                throw new RuntimeException( $updated->get_error_message() );
            }
            return $query;
        };
        add_filter( 'query', $change_before_lock );
        try
        {
            $request = $this->authenticate_rest_request(
                new WP_REST_Request(
                    'POST',
                    '/sentient-forms/v1/gravity_forms/forms/406/actions/local_first_' . $source_id . '/duplicate'
                )
            );
            $request->set_param( 'parent', [ 'type' => 'hook_root', 'hook' => 'after_submission' ] );
            $response = $this->dispatch_form_actions_request( $request );
        }
        finally
        {
            remove_filter( 'query', $change_before_lock );
        }

        $this->assertTrue( $mutated );
        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'rest_duplicate_transaction_conflict', $response->get_data()['code'] ?? null );
        $this->assertCount( 1, $mappings->list_for_form( 'gravity_forms', '406' ) );
    }



    public function test_add_bundled_mapping_rejects_execution_mode_outside_action_source_contract(): void
    {
        $this->create_ready_managed_credential();
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/153/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 153 );
        $request->set_param( 'central_action_id', 'content_validation_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'validation' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'after_submission' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_lifecycle', $response->get_error_code() );
    }

    public function test_add_bundled_mapping_rejects_unknown_execution_mode_instead_of_ignoring_it(): void
    {
        $this->create_ready_managed_credential();
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/169/actions' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 169 );
        $request->set_param( 'central_action_id', 'content_validation_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'validation' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'synchronous_typo' ] );

        $response = $this->controller->add_form_action( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_action_execution_mode', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] ?? null );
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

    public function test_sanitize_settings_normalizes_empty_model_selection_credential_to_null(): void
    {
        $sanitized = $this->invoke_private(
            'sanitize_settings',
            [
                [
                    'model_selection' => [
                        'provider'      => 'openrouter',
                        'primary'       => 'sf_default',
                        'credential_id' => '',
                    ],
                ],
            ]
        );

        $this->assertArrayHasKey( 'credential_id', $sanitized['model_selection'] );
        $this->assertNull( $sanitized['model_selection']['credential_id'] );
    }

    public function test_sanitize_settings_rejects_non_positive_or_fractional_credential_ids(): void
    {
        foreach ( [ -1, 0, 1.5, '-1', '1.5', 9007199254740992, str_repeat( '9', 40 ) ] as $credential_id )
        {
            $sanitized = $this->invoke_private(
                'sanitize_settings',
                [ [ 'model_selection' => [ 'credential_id' => $credential_id ] ] ]
            );

            $this->assertNull( $sanitized['model_selection']['credential_id'] );
        }
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
            'trigger_hooks'              => [ 'real_time' ],
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
        $this->assertSame(
            [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ],
            $data[0]['settings']['input_mapping'] ?? null
        );
        global $wpdb;
        $stored = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->get( (int) $record['mapping_id'] );
        $this->assertSame( [ 'email' => '3' ], $stored['input_bindings_json'] ?? null );
        $this->assertSame(
            [ 'sentient_forms_qualification' => 'structured.qualification' ],
            $data[0]['settings']['effect_mapping_json']['meta'] ?? null
        );
    }

    public function test_elementor_form_actions_read_suppresses_stale_native_effects(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $page_id = $this->create_elementor_form_page_for_controller();
        $form_id = $page_id . ':formabc';

        global $wpdb;
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'spam_detection_v1' ),
                'display_name'         => 'Spam Detection',
                'definition_json'      => [
                    'template_code' => 'spam_detection_v1',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openai/gpt-oss-20b:free',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'elementor_pro_forms',
                'form_id'             => $form_id,
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'email' => 'email',
                ],
                'effect_mapping_json' => [
                    'store_result' => true,
                    'meta'         => [
                        'sentient_forms_spam_classification' => 'structured.classification',
                    ],
                    'entry_note'   => [
                        'path' => 'structured.message',
                    ],
                    'spam'         => [
                        'enabled'                         => true,
                        'classification_path'             => 'structured.classification',
                        'confidence_path'                 => 'structured.confidence',
                        'min_confidence'                  => 0.72,
                        'suppress_notifications_on_spam'  => true,
                        'suppress_webhooks_on_spam'       => true,
                        'skip_downstream_on_spam'         => true,
                        'note'                            => [
                            'result_display_mode' => 'all_results',
                            'indicators_display'  => 'detailed',
                        ],
                    ],
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/' . $form_id . '/actions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $data );
        $this->assertSame( 'spam_detection_v1', $data[0]['central_action_id'] ?? null );
        $this->assertSame( 'local_first_' . $mapping_id, $data[0]['local_mapping_id'] ?? null );
        $this->assertArrayNotHasKey( 'store_result', $data[0]['settings']['effect_mapping_json'] ?? [] );
        $this->assertArrayNotHasKey( 'meta', $data[0]['settings']['effect_mapping_json'] ?? [] );
        $this->assertArrayNotHasKey( 'entry_note', $data[0]['settings']['effect_mapping_json'] ?? [] );
        $this->assertSame(
            [ 'skip_downstream_on_spam' => true ],
            $data[0]['settings']['effect_mapping_json']['spam'] ?? null
        );
        $this->assertArrayNotHasKey( 'suppress_notifications_on_spam', $data[0]['settings'] ?? [] );
        $this->assertArrayNotHasKey( 'suppress_webhooks_on_spam', $data[0]['settings'] ?? [] );
        $this->assertTrue( $data[0]['settings']['skip_downstream_on_spam'] ?? false );
        $this->assertArrayNotHasKey( 'spam_confidence_threshold', $data[0]['settings'] ?? [] );
        $this->assertArrayNotHasKey( 'spam_result_display_mode', $data[0]['settings'] ?? [] );
        $this->assertArrayNotHasKey( 'spam_indicators_display', $data[0]['settings'] ?? [] );
    }

    public function test_wpforms_form_actions_read_serializes_fully_filtered_effects_as_null(): void
    {
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $form_id = wp_insert_post(
            [
                'post_type'   => 'wpforms',
                'post_status' => 'publish',
                'post_title'  => 'WPForms filtered effects',
            ]
        );
        $this->assertIsInt( $form_id );

        global $wpdb;
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'entry_summary_v1' ),
                'display_name'         => 'Entry Summary',
                'definition_json'      => [ 'template_code' => 'entry_summary_v1' ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openai/gpt-oss-20b:free',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'wpforms',
                'form_id'             => (string) $form_id,
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'effect_mapping_json' => [
                    'store_result' => true,
                    'meta'         => [
                        'sentient_forms_summary' => 'content',
                    ],
                    'entry_note'   => [
                        'path' => 'content',
                    ],
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $request = $this->authenticate_rest_request( new WP_REST_Request( 'GET', '/sentient-forms/v1/wpforms/forms/' . $form_id . '/actions' ) );
        $response = $this->dispatch_form_actions_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $data );
        $this->assertSame( 'local_first_' . $mapping_id, $data[0]['local_mapping_id'] ?? null );
        $this->assertArrayHasKey( 'effect_mapping_json', $data[0]['settings'] );
        $this->assertNull( $data[0]['settings']['effect_mapping_json'] ?? null );
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

    public function test_get_forms_overview_normalizes_legacy_local_first_conditions(): void
    {
        GFAPI::$forms = [
            7 => [
                'id'        => 7,
                'title'     => 'Legacy conditions form',
                'is_active' => true,
            ],
        ];

        global $wpdb;
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'            => 'legacy_conditions_action',
                'display_name'    => 'Legacy conditions action',
                'definition_json' => [
                    'prompt_template' => 'Summarize {{entry}}.',
                ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'effect_mapping_json' => [],
                'conditions_json'     => [
                    'mode'  => 'all',
                    'rules' => [],
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );

        $response   = $this->controller->get_forms_overview( $request );
        $conditions = $response->get_data()['forms'][0]['actions'][0]['settings']['conditions'] ?? null;

        $this->assertSame(
            [
                'enabled' => false,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [],
                ],
            ],
            $conditions
        );
    }

    public function test_get_forms_overview_preserves_enabled_type_less_legacy_condition_nodes(): void
    {
        GFAPI::$forms = [
            8 => [
                'id'        => 8,
                'title'     => 'Enabled legacy conditions form',
                'is_active' => true,
            ],
        ];

        global $wpdb;
        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'            => 'enabled_legacy_conditions_action',
                'display_name'    => 'Enabled legacy conditions action',
                'definition_json' => [
                    'prompt_template' => 'Summarize {{entry}}.',
                ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $legacy_conditions = [
            'enabled' => true,
            'root'    => [
                'logic' => 'all',
                'rules' => [
                    [
                        'field_id' => 'email',
                        'operator' => 'contains',
                        'value'    => '@example.com',
                    ],
                ],
            ],
        ];
        $mapping_id       = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '8',
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'effect_mapping_json' => [],
                'conditions_json'     => $legacy_conditions,
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $evaluator = new Sentient_Forms_Condition_Evaluator();
        $this->assertTrue(
            $evaluator->should_execute(
                [ 'settings' => [ 'conditions' => $legacy_conditions ] ],
                [ 'email' => 'person@example.com' ]
            ),
            'The runtime evaluator must continue to recognize type-less legacy nodes.'
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );

        $response   = $this->controller->get_forms_overview( $request );
        $conditions = $response->get_data()['forms'][0]['actions'][0]['settings']['conditions'] ?? null;

        $this->assertSame(
            [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => 'email',
                            'operator' => 'contains',
                            'value'    => '@example.com',
                        ],
                    ],
                ],
            ],
            $conditions
        );
    }

    public function test_get_forms_overview_keeps_malformed_enabled_legacy_tree_fail_closed(): void
    {
        GFAPI::$forms = [
            9 => [
                'id'        => 9,
                'title'     => 'Malformed legacy conditions form',
                'is_active' => true,
            ],
        ];
        $legacy_conditions = [
            'enabled' => true,
            'root'    => [
                'logic' => 'all',
                'rules' => [
                    [
                        'field_id' => 'email',
                        'operator' => 'contains',
                        'value'    => '@example.com',
                    ],
                    [ 'unexpected' => 'malformed' ],
                ],
            ],
        ];
        $this->create_overview_conditions_mapping_fixture( '9', 'malformed_legacy_conditions_action', $legacy_conditions );

        $evaluator = new Sentient_Forms_Condition_Evaluator();
        $this->assertFalse(
            $evaluator->should_execute(
                [ 'settings' => [ 'conditions' => $legacy_conditions ] ],
                [ 'email' => 'person@example.com' ]
            )
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );

        $conditions = $this->controller->get_forms_overview( $request )
            ->get_data()['forms'][0]['actions'][0]['settings']['conditions'] ?? null;
        $this->assertSame(
            [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [],
                ],
            ],
            $conditions
        );
    }

    public function test_get_forms_overview_wraps_type_less_legacy_root_rule_in_canonical_group(): void
    {
        GFAPI::$forms = [
            10 => [
                'id'        => 10,
                'title'     => 'Legacy root rule form',
                'is_active' => true,
            ],
        ];
        $legacy_conditions = [
            'enabled' => true,
            'root'    => [
                'field_id' => 'email',
                'operator' => 'contains',
                'value'    => '@example.com',
            ],
        ];
        $this->create_overview_conditions_mapping_fixture( '10', 'legacy_root_rule_action', $legacy_conditions );

        $evaluator = new Sentient_Forms_Condition_Evaluator();
        $this->assertTrue(
            $evaluator->should_execute(
                [ 'settings' => [ 'conditions' => $legacy_conditions ] ],
                [ 'email' => 'person@example.com' ]
            )
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );

        $conditions = $this->controller->get_forms_overview( $request )
            ->get_data()['forms'][0]['actions'][0]['settings']['conditions'] ?? null;
        $this->assertSame(
            [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [
                        [
                            'type'     => 'rule',
                            'field_id' => 'email',
                            'operator' => 'contains',
                            'value'    => '@example.com',
                        ],
                    ],
                ],
            ],
            $conditions
        );
    }

    public function test_get_forms_overview_keeps_over_depth_legacy_tree_fail_closed(): void
    {
        GFAPI::$forms = [
            11 => [
                'id'        => 11,
                'title'     => 'Over-depth legacy conditions form',
                'is_active' => true,
            ],
        ];
        $rule = [
            'field_id' => 'email',
            'operator' => 'contains',
            'value'    => '@example.com',
        ];
        for ( $depth = 0; $depth < 4; $depth++ )
        {
            $rule = [
                'logic' => 'all',
                'rules' => [ $rule ],
            ];
        }

        $legacy_conditions = [
            'enabled' => true,
            'root'    => $rule,
        ];
        $this->create_overview_conditions_mapping_fixture( '11', 'over_depth_legacy_conditions_action', $legacy_conditions );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $conditions = $this->controller->get_forms_overview( $request )
            ->get_data()['forms'][0]['actions'][0]['settings']['conditions'] ?? null;

        $this->assertSame(
            [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [],
                ],
            ],
            $conditions
        );
    }

    public function test_get_forms_overview_keeps_over_node_limit_legacy_tree_fail_closed(): void
    {
        GFAPI::$forms = [
            12 => [
                'id'        => 12,
                'title'     => 'Over-node-limit legacy conditions form',
                'is_active' => true,
            ],
        ];
        $legacy_conditions = [
            'enabled' => true,
            'root'    => [
                'logic' => 'any',
                'rules' => array_fill(
                    0,
                    50,
                    [
                        'field_id' => 'email',
                        'operator' => 'contains',
                        'value'    => '@example.com',
                    ]
                ),
            ],
        ];
        $this->create_overview_conditions_mapping_fixture( '12', 'over_node_limit_legacy_conditions_action', $legacy_conditions );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $conditions = $this->controller->get_forms_overview( $request )
            ->get_data()['forms'][0]['actions'][0]['settings']['conditions'] ?? null;

        $this->assertSame(
            [
                'enabled' => true,
                'root'    => [
                    'type'  => 'group',
                    'logic' => 'all',
                    'rules' => [],
                ],
            ],
            $conditions
        );
    }


    public function test_elementor_pro_forms_overview_includes_descriptor_and_provider_native_form_id(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $page_id = $this->create_elementor_form_page_for_controller();
        $form_id = $page_id . ':formabc';

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/elementor_pro_forms/forms/overview' );
        $request->set_param( 'form_source_slug', 'elementor_pro_forms' );

        $response = $this->controller->get_forms_overview( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'elementor_pro_forms', $data['form_source'] ?? null );
        $this->assertSame( $form_id, $data['forms'][0]['id'] ?? null );
        $this->assertSame( 'Known Elementor Form', $data['forms'][0]['title'] ?? null );
        $this->assertSame( 'available', $data['form_source_descriptor']['availability'] ?? null );
        $this->assertTrue( $data['form_source_descriptor']['lifecycles']['validation']['supported'] ?? false );
        $this->assertSame(
            'elementor_pro/forms/validation',
            $data['form_source_descriptor']['lifecycles']['validation']['native_hook'] ?? null
        );
        $this->assertTrue( $data['form_source_descriptor']['validation_effects']['field_errors'] ?? false );
        $this->assertTrue( $data['form_source_descriptor']['validation_effects']['form_errors'] ?? false );
        $this->assertFalse( $data['form_source_descriptor']['validation_effects']['submission_spam'] ?? true );
        $this->assertFalse( $data['form_source_descriptor']['native_enrichment']['spam'] ?? true );
        $this->assertSame(
            'unavailable',
            $data['form_source_descriptor']['requirements']['native_submission_parity'] ?? null
        );
        $this->assertFalse( $data['form_source_descriptor']['native_entry']['link'] ?? true );
    }




    public function test_get_form_actions_bootstrap_combines_actions_status_and_disabled_state(): void
    {
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
        $this->assertArrayHasKey( 'provider_path_policy', $data );
        $this->assertArrayHasKey( 'workflow_plan', $data );
        $this->assertIsArray( $data['definitions'] );
        $this->assertIsArray( $data['provider_credentials'] );
        $this->assertIsArray( $data['form_action_configs'] );
        $this->assertIsArray( $data['form_fields'] );
        $this->assertIsArray( $data['action_defaults'] );
        $this->assertIsArray( $data['provider_path_policy'] );
        $this->assertIsArray( $data['workflow_plan'] );
        $this->assertSame( 'gravity_forms', $data['form_source_descriptor']['slug'] ?? null );
        $this->assertArrayHasKey( 'validation', $data['form_source_descriptor']['lifecycles'] ?? [] );
        $this->assertSame(
            'gform_validation',
            $data['form_source_descriptor']['lifecycles']['validation']['native_hook'] ?? null
        );
        $this->assertSame( 'local', $data['workflow_plan']['authority'] ?? null );
        $this->assertSame( 'plugin_local_authority', $data['workflow_plan']['authority_reason'] ?? null );
        $this->assertFalse( $data['workflow_plan']['cps_unreachable'] ?? true );
    }

    public function test_form_actions_bootstrap_includes_bundled_provider_path_policy(): void
    {
        $openrouter_credential_id = $this->create_ready_openrouter_credential();
        $managed_credential_id    = $this->create_ready_managed_credential();
        $this->seed_structured_openrouter_model_cache();

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/bootstrap' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions_bootstrap( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data   = $response->get_data();
        $policy = $data['provider_path_policy'] ?? null;

        $this->assertIsArray( $policy );
        $this->assertSame( 'sentient_managed', $policy['default_provider'] ?? null );
        $this->assertTrue( $policy['providers']['sentient_managed']['ready'] ?? false );
        $this->assertSame( $managed_credential_id, (int) ( $policy['providers']['sentient_managed']['credential_id'] ?? 0 ) );
        $this->assertTrue( $policy['providers']['openrouter']['ready'] ?? false );
        $this->assertSame( $openrouter_credential_id, (int) ( $policy['providers']['openrouter']['credential_id'] ?? 0 ) );
        $this->assertSame( 'sentient_managed', $policy['actions']['spam_detection_v1']['selected_provider'] ?? null );
        $this->assertSame(
            '~openai/gpt-latest',
            $policy['actions']['spam_detection_v1']['model_selection']['backup_model'] ?? null
        );
    }

    public function test_form_actions_bootstrap_marks_bundled_actions_blocked_when_no_provider_is_ready(): void
    {
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/bootstrap' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions_bootstrap( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data   = $response->get_data();
        $policy = $data['provider_path_policy'] ?? null;

        $this->assertIsArray( $policy );
        $this->assertNull( $policy['default_provider'] ?? null );
        $this->assertFalse( $policy['providers']['sentient_managed']['ready'] ?? true );
        $this->assertFalse( $policy['providers']['openrouter']['ready'] ?? true );

        $action = $policy['actions']['spam_detection_v1'] ?? null;
        $this->assertIsArray( $action );
        $this->assertArrayHasKey( 'selected_provider', $action );
        $this->assertArrayHasKey( 'model_selection', $action );
        $this->assertNull( $action['selected_provider'] );
        $this->assertNull( $action['model_selection'] );
        $this->assertSame( 'no_ready_provider', $action['blocked_reason_code'] ?? null );
        $this->assertTrue( $action['requires_structured_output'] ?? false );
    }

    public function test_form_actions_bootstrap_provider_policy_fallback_uses_object_shaped_actions(): void
    {
        $this->set_provider_path_policy( null );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/gravity_forms/forms/1/actions/bootstrap' );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );

        $response = $this->controller->get_form_actions_bootstrap( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $data   = $response->get_data();
        $policy = $data['provider_path_policy'] ?? null;

        $this->assertIsArray( $policy );
        $this->assertArrayHasKey( 'default_provider', $policy );
        $this->assertNull( $policy['default_provider'] );
        $this->assertSame( 'policy_unavailable', $policy['providers']['sentient_managed']['blocked_reason_code'] ?? null );
        $this->assertSame( 'policy_unavailable', $policy['providers']['openrouter']['blocked_reason_code'] ?? null );
        $this->assertInstanceOf( stdClass::class, $policy['actions'] ?? null );
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
        $this->assertTrue( $descriptor['lifecycles']['validation']['supported'] ?? false );
        $this->assertSame( 'wpcf7_validate', $descriptor['lifecycles']['validation']['native_hook'] ?? null );
        $this->assertFalse( $descriptor['lifecycles']['real_time']['supported'] ?? true );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['supported'] ?? false );
        $this->assertSame( 'wpcf7_mail_sent', $descriptor['lifecycles']['after_submission']['native_hook'] ?? null );
        $this->assertTrue( $descriptor['lifecycles']['after_submission']['requires_ledger'] ?? false );
        $this->assertFalse( $descriptor['native_entry']['id'] ?? true );
        $this->assertFalse( $descriptor['native_entry']['link'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['notes'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] ?? true );
        $this->assertTrue( $descriptor['validation_effects']['submission_spam'] ?? false );
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
        $this->assertSame( (string) $form_id, $data['form_id'] ?? null );
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
        $this->assertTrue( $descriptor['lifecycles']['validation']['supported'] ?? false );
        $this->assertSame( 'wpforms_process', $descriptor['lifecycles']['validation']['native_hook'] ?? null );
        $this->assertFalse( $descriptor['lifecycles']['real_time']['supported'] ?? true );
        $this->assertTrue( $descriptor['native_entry']['id'] ?? false );
        $this->assertTrue( $descriptor['native_entry']['link'] ?? false );
        $this->assertFalse( $descriptor['native_entry']['read'] ?? true );
        $this->assertFalse( $descriptor['native_entry']['write'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['notes'] ?? true );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] ?? true );
        $this->assertTrue( $descriptor['validation_effects']['field_errors'] ?? false );
        $this->assertTrue( $descriptor['validation_effects']['form_errors'] ?? false );
        $this->assertFalse( $descriptor['validation_effects']['submission_spam'] ?? true );
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
                    'mode'             => 'all',
                    'field_ids'        => [],
                    'include_metadata' => true,
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
        $this->assertSame( [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ], $data['settings']['input_mapping'] ?? null );
        $this->assertSame( 'sentient_managed', $data['settings']['model_selection']['provider'] ?? null );
        $this->assertSame( 'sf_default', $data['settings']['model_selection']['primary'] ?? null );
        $this->assertSame( 1, (int) ( $data['settings']['model_selection']['credential_id'] ?? 0 ) );
        $this->assertSame( 'yes', $data['settings']['include_site_context'] ?? null );
        $this->assertIsArray( $stored );
        $this->assertFalse( $stored['enabled'] );
        $this->assertSame( [ 'email' => '3' ], $stored['input_bindings_json'] ?? null );
        $this->assertSame(
            [ 'mode' => 'all', 'field_ids' => [], 'include_metadata' => true ],
            $stored['settings_json']['input_mapping'] ?? null
        );
        $this->assertSame( 'sentient_managed', $stored['settings_json']['model_selection']['provider'] ?? null );
        $this->assertSame( 'sf_default', $stored['settings_json']['model_selection']['primary'] ?? null );
        $this->assertSame( 1, (int) ( $stored['settings_json']['model_selection']['credential_id'] ?? 0 ) );
        $this->assertSame( 'yes', $stored['settings_json']['include_site_context'] ?? null );
        $this->assertTrue( $stored['conditions_json']['enabled'] ?? false );
        $this->assertSame( 'enterprise', $stored['conditions_json']['root']['value'] ?? null );
    }

    public function test_update_form_action_item_normalizes_empty_model_selection_credential_to_null(): void
    {
        $record     = $this->create_local_first_mapping_fixture( '1' );
        $mapping_id = 'local_first_' . $record['mapping_id'];
        $request    = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/' . $mapping_id );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', $mapping_id );
        $request->set_param( 'trigger_hooks', [ 'gform_after_submission' ] );
        $request->set_param(
            'settings',
            [
                'execution_mode' => 'after_submission',
                'model_selection' => [
                    'primary'       => 'sf_default',
                    'provider'      => 'openrouter',
                    'credential_id' => '',
                ],
            ]
        );

        $response = $this->controller->update_form_action_item( $request );
        $data     = $response->get_data();

        global $wpdb;
        $stored = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->get( $record['mapping_id'] );

        $this->assertArrayHasKey( 'credential_id', $data['settings']['model_selection'] );
        $this->assertNull( $data['settings']['model_selection']['credential_id'] );
        $this->assertIsArray( $stored );
        $this->assertArrayHasKey( 'credential_id', $stored['settings_json']['model_selection'] );
        $this->assertNull( $stored['settings_json']['model_selection']['credential_id'] );
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

    public function test_update_form_action_item_preserves_reactivation_writer_barrier_error(): void
    {
        $record = $this->create_local_first_mapping_fixture( '1' );

        global $wpdb;
        $actions  = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->assertTrue( $actions->update_status( $record['action_id'], 'archived' ) );
        $this->assertIsArray( $mappings->update( $record['mapping_id'], [ 'enabled' => false ] ) );

        $locked_actions = new class( $wpdb ) extends Sentient_Forms_Local_Custom_Actions_Repository {
            public function update_status( int $id, string $status ): bool | WP_Error
            {
                return new WP_Error(
                    'sentient_forms_action_authority_write_locked',
                    'Local state is locked.',
                    [ 'status' => 409 ]
                );
            }
        };
        $property = new ReflectionProperty( Sentient_Forms_Form_Actions_Controller::class, 'local_custom_actions' );
        $property->setValue( $this->controller, $locked_actions );

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/local_first_' . $record['mapping_id'] );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 1 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param( 'is_action_enabled_for_form', true );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $response->get_error_code() );
        $this->assertFalse( $mappings->get( $record['mapping_id'] )['enabled'] ?? true );
    }

    public function test_update_form_action_item_does_not_reactivate_archived_action_before_validation(): void
    {
        $record = $this->create_local_first_mapping_fixture( '168' );

        global $wpdb;
        $actions  = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $this->assertTrue( $actions->update_status( $record['action_id'], 'archived' ) );
        $updated_mapping = $mappings->update( $record['mapping_id'], [ 'enabled' => false ] );
        $this->assertIsArray( $updated_mapping );

        $request = new WP_REST_Request(
            'PUT',
            '/sentient-forms/v1/gravity_forms/forms/168/actions/local_first_' . $record['mapping_id']
        );
        $request->set_param( 'form_source_slug', 'gravity_forms' );
        $request->set_param( 'form_id', 168 );
        $request->set_param( 'local_mapping_id', 'local_first_' . $record['mapping_id'] );
        $request->set_param( 'is_action_enabled_for_form', true );
        $request->set_param( 'trigger_hooks', [ 'real_time' ] );
        $request->set_param( 'settings', [ 'execution_mode' => 'real_time' ] );

        $response = $this->controller->update_form_action_item( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'rest_invalid_realtime_action', $response->get_error_code() );
        $this->assertSame( 'archived', $actions->get( $record['action_id'] )['status'] ?? null );
        $this->assertFalse( $mappings->get( $record['mapping_id'] )['enabled'] ?? true );
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

    public function test_delete_local_first_mapping_cannot_restore_legacy_option_during_authority_cutover(): void
    {
        $record     = $this->create_local_first_mapping_fixture( '408' );
        $mapping_id = 'local_first_' . $record['mapping_id'];
        $option_key = 'sentient_forms_actions_gravity_forms_408';
        $this->dynamic_action_option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'legacy_summary' => [
                    'local_mapping_id'           => 'legacy_summary',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [],
                ],
            ],
            false
        );

        $nested_migration = null;
        $run_migration_before_stale_write = static function ( mixed $value ) use ( &$nested_migration ): mixed {
            $nested_migration = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
            return $value;
        };
        add_filter( 'pre_update_option_' . $option_key, $run_migration_before_stale_write );
        try
        {
            $request = new WP_REST_Request( 'DELETE', '/sentient-forms/v1/gravity_forms/forms/408/actions/' . $mapping_id );
            $request->set_param( 'form_source_slug', 'gravity_forms' );
            $request->set_param( 'form_id', 408 );
            $request->set_param( 'local_mapping_id', $mapping_id );
            $response = $this->controller->delete_form_action_item( $request );
        }
        finally
        {
            remove_filter( 'pre_update_option_' . $option_key, $run_migration_before_stale_write );
        }

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 0, $nested_migration['migration_complete'] ?? null );
        $this->assertArrayHasKey( 'legacy_summary', get_option( $option_key ) );

        global $wpdb;
        $rows = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $this->assertNull( $rows->get( $record['mapping_id'] ) );

        $completed = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $completed['migration_complete'] ?? null );
        $this->assertSame( [], get_option( $option_key ) );
        $migrated = $rows->list_for_form( 'gravity_forms', '408' );
        $this->assertCount( 1, $migrated );
        $this->assertTrue( $migrated[0]['enabled'] ?? false );
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

    public function test_toggle_form_disabled_cannot_restore_legacy_mapping_during_authority_cutover(): void
    {
        $option_key = 'sentient_forms_actions_gravity_forms_407';
        $this->dynamic_action_option_keys[] = $option_key;
        $legacy_mapping = [
            'local_mapping_id'           => 'stale_writer_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        update_option( $option_key, [ 'stale_writer_summary' => $legacy_mapping ], false );

        $nested_migration = null;
        $run_migration_before_stale_write = static function ( mixed $value ) use ( &$nested_migration ): mixed {
            $nested_migration = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
            return $value;
        };
        add_filter( 'pre_update_option_' . $option_key, $run_migration_before_stale_write );
        try
        {
            $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/407/actions/disable' );
            $request->set_param( 'form_source_slug', 'gravity_forms' );
            $request->set_param( 'form_id', 407 );
            $request->set_param( 'sf_disabled', true );
            $response = $this->controller->toggle_form_disabled( $request );
        }
        finally
        {
            remove_filter( 'pre_update_option_' . $option_key, $run_migration_before_stale_write );
        }

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 0, $nested_migration['migration_complete'] ?? null );
        $this->assertArrayHasKey( 'stale_writer_summary', get_option( $option_key ) );
        $this->assertTrue( get_option( $option_key )['sf_disabled'] ?? false );

        $completed = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $completed['migration_complete'] ?? null );
        $this->assertSame( [ 'sf_disabled' => true ], get_option( $option_key ) );
        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '407' );
        $this->assertCount( 1, $rows );
        $this->assertTrue( $rows[0]['enabled'] ?? false );
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
     * @param array<string, mixed> $conditions
     */
    private function create_overview_conditions_mapping_fixture(
        string $form_id,
        string $action_code,
        array $conditions
    ): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id      = $custom_actions->create(
            [
                'code'            => $action_code,
                'display_name'    => $action_code,
                'definition_json' => [ 'prompt_template' => 'Summarize {{entry}}.' ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => $form_id,
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'effect_mapping_json' => [],
                'conditions_json'     => $conditions,
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );
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

    private function create_ready_openrouter_credential( bool $record_consent = true ): int
    {
        global $wpdb;

        $credentials   = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
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
        if ( $record_consent )
        {
            $this->record_provider_consent( 'openrouter' );
        }

        return $credential_id;
    }

    private function create_ready_managed_credential( bool $record_consent = true ): int
    {
        global $wpdb;

        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'license_status' => 'active',
                'license_id'     => 'license-form-actions-provider-path-test',
                'site_id'        => '44444444-4444-4444-8444-444444444444',
                'proxy_api_key'  => 'proxy-form-actions-provider-path-test',
                'tier'           => 'pro',
            ]
        );

        if ( $record_consent )
        {
            $this->record_provider_consent( 'sentient_managed', 'setup_managed_proxy' );
        }

        $credentials   = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $credential_id = $credentials->create(
            [
                'provider'  => 'sentient_managed',
                'label'     => 'Sentient Forms Managed Service',
                'auth_mode' => 'sentient_proxy',
                'status'    => 'valid',
            ]
        );

        $this->assertIsInt( $credential_id );
        return $credential_id;
    }

    private function record_provider_consent( string $provider, string $action = 'setup_provider' ): void
    {
        global $wpdb;

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $consent_id = $consents->record(
            $provider,
            '2026-04-sentient-provider-path-test-v1',
            get_current_user_id() ?: null,
            [
                'action' => $action,
            ]
        );

        $this->assertIsInt( $consent_id );
    }

    /**
     * @return array<string, mixed>
     */
    private function get_bundled_custom_action_model_selection( string $template_code ): array
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $custom_action  = $custom_actions->get_by_code(
            Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( $template_code )
        );

        $this->assertIsArray( $custom_action );
        $selection = $custom_action['model_selection_json'] ?? null;
        $this->assertIsArray( $selection );

        return $selection;
    }

    private function seed_structured_openrouter_model_cache(): void
    {
        $models     = new Sentient_Forms_Model_Cache_Repository( $GLOBALS['wpdb'] );
        $expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openai/gpt-5.5',
                [
                    'id'                   => 'openai/gpt-5.5',
                    'name'                 => 'OpenAI: GPT-5.5',
                    'free'                 => false,
                    'context_length'       => 400000,
                    'input_modalities'     => [ 'text' ],
                    'output_modalities'    => [ 'text' ],
                    'supported_parameters' => [ 'response_format', 'structured_outputs', 'max_tokens' ],
                    'pricing'              => [
                        'prompt'     => '0.000002',
                        'completion' => '0.000008',
                    ],
                ],
                $expires_at
            )
        );
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
                'sentient_model_cache',
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


    private function set_provider_path_policy( ?Sentient_Forms_Provider_Path_Policy_Service $policy ): void
    {
        $property = new ReflectionProperty( $this->controller, 'provider_path_policy' );
        $property->setValue( $this->controller, $policy );
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
