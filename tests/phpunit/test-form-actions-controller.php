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
	}
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

class Tests_Form_Actions_Controller extends WP_UnitTestCase {
    private Sentient_Forms_Form_Actions_Controller $controller;

    protected function setUp(): void {
        parent::setUp();
        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_workspace_tables();
        $this->controller = new Sentient_Forms_Form_Actions_Controller();
        GFAPI::$entries = [];
        $this->reset_entry_meta_store();
        delete_option( 'sentient_forms_action_log' );
        delete_option( 'sentient_forms_form_status_gravity_forms_42' );
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

    public function test_validate_trigger_hooks_accepts_allowed_values(): void {
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/gravity_forms/forms/1/actions' );
        $result  = $this->controller->validate_trigger_hooks_param(
            [ 'gform_validation', 'gform_after_submission' ],
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
        $data     = $response->get_data();

        $this->assertSame( [ 'gform_validation' ], $data['trigger_hooks'] );
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
        $this->assertSame( 'unbound', $stored['map_b']['settings']['trigger_sources']['gform_after_submission']['type'] ?? null );

        delete_option( $option_key );
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
            $stored['map_child']['settings']['trigger_sources']['gform_validation']['mapping_id'] ?? null
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

        $this->assertSame( 'unbound', $sanitized['gform_validation']['type'] ?? null );
        $this->assertSame( 'mapping', $sanitized['gform_after_submission']['type'] ?? null );
        $this->assertSame( 'map_upstream', $sanitized['gform_after_submission']['mapping_id'] ?? null );
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
        $this->assertSame( [ 'gform_after_submission' ], $data[0]['trigger_hooks'] ?? null );
        $this->assertTrue( $data[0]['is_action_enabled_for_form'] ?? false );
        $this->assertSame( 'after_submission', $data[0]['settings']['execution_mode'] ?? null );
        $this->assertSame( [ 'email' => '3' ], $data[0]['settings']['input_mapping'] ?? null );
        $this->assertSame(
            [ 'sentient_forms_qualification' => 'structured.qualification' ],
            $data[0]['settings']['effect_mapping_json']['meta'] ?? null
        );
    }

    public function test_validate_local_mapping_id_param_allows_local_first_custom_table_mapping(): void
    {
        $record     = $this->create_local_first_mapping_fixture( '1' );
        $mapping_id = 'local_first_' . $record['mapping_id'];

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/1/actions/' . $mapping_id );
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

        $request = new WP_REST_Request( 'PUT', '/sentient-forms/v1/gravity_forms/forms/2/actions/' . $mapping_id );
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
        $this->assertIsArray( $stored );
        $this->assertFalse( $stored['enabled'] );
        $this->assertSame( [ 'name' => '1', 'message' => '2' ], $stored['input_bindings_json'] ?? null );
        $this->assertTrue( $stored['conditions_json']['enabled'] ?? false );
        $this->assertSame( 'enterprise', $stored['conditions_json']['root']['value'] ?? null );
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
        $this->assertSame( 'unbound', $stored['map_downstream']['settings']['trigger_sources']['gform_after_submission']['type'] ?? null );

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

        $this->assertSame( 'local_fallback', $data['authority'] ?? null );
        $this->assertSame( 'cps_mismatch', $data['authority_reason'] ?? null );
        $this->assertFalse( $data['cps_unreachable'] ?? true );
        $this->assertContains( 'gform_validation', $data['available_hooks'] ?? [] );

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

    private function truncate_local_workspace_tables(): void
    {
        global $wpdb;

        foreach (
            [
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
