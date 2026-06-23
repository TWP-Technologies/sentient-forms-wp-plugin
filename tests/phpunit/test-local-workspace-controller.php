<?php

class Tests_Local_Workspace_Controller extends WP_UnitTestCase
{
    private static int $admin_id;

    public static function wpSetUpBeforeClass( $factory ): void
    {
        self::$admin_id = (int) $factory->user->create( [ 'role' => 'administrator' ] );
    }

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user( self::$admin_id );
        update_option( 'sentient_forms_settings', [ 'enforce_nonce_verification' => false ] );

        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_workspace_tables();

        add_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        $rest_api = new Sentient_Forms_REST_API();
        do_action( 'rest_api_init', rest_get_server() );
    }

    protected function tearDown(): void
    {
        remove_filter( 'sentient_forms_rest_api_controller_classes', [ $this, 'controller_classes' ], 99 );
        parent::tearDown();
    }

    public function controller_classes(): array
    {
        return [ Sentient_Forms_Local_Workspace_Controller::class ];
    }

    public function test_local_templates_custom_actions_mappings_and_events_round_trip(): void
    {
        $template = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/action-templates',
            [
                'source'                   => 'bundled',
                'code'                     => 'spam_triage_v1',
                'display_name'             => 'Spam Triage',
                'prompt_template'          => 'Classify {{entry}}.',
                'default_model'            => 'openrouter/auto',
                'structured_output_schema' => [ 'type' => 'object' ],
                'version'                  => '1.0.0',
                'is_active'                => true,
            ],
            201
        );

        $this->assertSame( 'spam_triage_v1', $template['code'] );
        $this->assertSame( [ 'type' => 'object' ], $template['structured_output_schema'] );

        $templates = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/action-templates' );
        $this->assertCount( 1, $templates );

        $custom_action = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/custom-actions',
            [
                'template_id'          => $template['id'],
                'code'                 => 'contact_spam_triage',
                'display_name'         => 'Contact Spam Triage',
                'definition_json'      => [
                    'prompt' => 'Classify contact form entry.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ],
            201
        );

        $this->assertSame( 'contact_spam_triage', $custom_action['code'] );
        $this->assertSame( 'openrouter', $custom_action['model_selection_json']['provider'] );

        $mapping = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/form-mappings',
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $custom_action['id'],
                'input_bindings_json' => [
                    'email' => '3',
                ],
                'effect_mapping_json' => [
                    'entry_note' => true,
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ],
            201
        );

        $this->assertSame( 'gravity_forms', $mapping['form_source'] );
        $this->assertSame( 'after_submission', $mapping['hook'] );
        $this->assertSame( [ 'email' => '3' ], $mapping['input_bindings_json'] );

        $mappings = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/form-mappings?form_source=gravity_forms&form_id=7' );
        $this->assertCount( 1, $mappings );
        $this->assertSame( $mapping['id'], $mappings[0]['id'] );
        $this->assertSame( 'after_submission', $mappings[0]['hook'] );

        $event = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/execution-events',
            [
                'execution_request_id' => 'request-123',
                'mapping_id'           => $mapping['id'],
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '99',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'queued',
                'token_usage_json'     => [
                    'input'  => 10,
                    'output' => 5,
                ],
            ],
            201
        );

        $this->assertSame( 'queued', $event['status'] );

        $updated_event = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/execution-events',
            [
                'execution_request_id' => 'request-123',
                'mapping_id'           => $mapping['id'],
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
                'result_json'          => [
                    'classification' => 'ham',
                ],
            ],
            201
        );

        $this->assertSame( $event['id'], $updated_event['id'] );
        $this->assertSame( 'succeeded', $updated_event['status'] );
        $this->assertSame( 'ham', $updated_event['result_json']['classification'] );

        $events = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/execution-events?limit=10' );
        $this->assertCount( 1, $events );
        $this->assertSame( 'request-123', $events[0]['execution_request_id'] );

        $support_bundle = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/support-bundle' );
        $this->assertArrayHasKey( 'local_tables', $support_bundle );
        $this->assertGreaterThanOrEqual( 1, $support_bundle['local_tables']['sentient_execution_events'] );
        $this->assertSame( 'request-123', $support_bundle['execution_summary']['recent'][0]['execution_request_id'] );
        $this->assertArrayNotHasKey( 'result_json', $support_bundle['execution_summary']['recent'][0] );
        $this->assertTrue( $support_bundle['execution_summary']['recent'][0]['has_result'] );
    }

    public function test_support_bundle_summarizes_submission_ledger_without_field_values(): void
    {
        global $wpdb;

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger   = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );

        $settings->set_enabled( 'gravity_forms', '7', true, self::$admin_id );
        $created = $ledger->create(
            [
                'submission_uuid'        => '44444444-4444-4444-8444-444444444444',
                'form_source'            => 'gravity_forms',
                'form_id'                => '7',
                'native_entry_id'        => '101',
                'logical_fields_json'    => [
                    'email'   => 'diagnostic-person@example.test',
                    'message' => 'Do not expose this.',
                ],
                'provider_metadata_json' => [ 'source' => 'gravity_forms' ],
            ]
        );
        $this->assertIsInt( $created );

        $support_bundle = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/support-bundle' );

        $this->assertSame( 1, $support_bundle['submission_ledger']['enabled_form_count'] );
        $this->assertSame( 1, $support_bundle['submission_ledger']['record_count'] );
        $this->assertSame( 'gravity_forms', $support_bundle['submission_ledger']['recent'][0]['form_source'] );
        $this->assertSame( '7', $support_bundle['submission_ledger']['recent'][0]['form_id'] );
        $this->assertTrue( $support_bundle['submission_ledger']['recent'][0]['has_logical_fields'] );
        $this->assertStringNotContainsString(
            'diagnostic-person@example.test',
            wp_json_encode( $support_bundle )
        );
    }

    public function test_mapping_list_requires_form_filter(): void
    {
        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/form-mappings' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_managed_execution_events_are_sanitized_on_write_and_read(): void
    {
        $event = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/execution-events',
            [
                'execution_request_id' => 'managed-local-workspace-1',
                'provider'             => 'sentient_managed',
                'model'                => 'openai/gpt-4.1-mini',
                'status'               => 'succeeded',
                'cost_json'            => [
                    'provider'               => 'sentient_forms',
                    'currency'               => 'USD',
                    'source'                 => 'sentient_forms_metering',
                    'debited_credits'        => 4,
                    'billed_amount_microusd' => 4000,
                ],
                'result_json'          => [
                    'metering' => [
                        'debited_credits'        => 4,
                        'billed_amount_microusd' => 4000,
                        'currency'               => 'USD',
                    ],
                ],
            ],
            201
        );

        $this->assertSame( 4, $event['cost_json']['debited_credits'] );
        $this->assertArrayNotHasKey( 'billed_amount_microusd', $event['cost_json'] );
        $this->assertArrayNotHasKey( 'currency', $event['cost_json'] );
        $this->assertArrayNotHasKey( 'billed_amount_microusd', $event['result_json']['metering'] );
        $this->assertArrayNotHasKey( 'currency', $event['result_json']['metering'] );

        $events = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/execution-events?limit=10' );
        $this->assertStringNotContainsString( 'microusd', wp_json_encode( $events ) );
        $this->assertStringNotContainsString( '"currency"', wp_json_encode( $events ) );
    }

    public function test_migration_readiness_reports_local_and_legacy_cutover_state(): void
    {
        $this->seed_local_cutover_state();

        $report = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/migration/readiness' );

        $this->assertTrue( $report['ready_for_reset'] );
        $this->assertTrue( $report['ready_for_local_execution'] );
        $this->assertSame( Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE, $report['confirmation_phrase'] );
        $this->assertSame( 1, $report['local_tables']['sentient_provider_credentials'] );
        $this->assertSame( 1, $report['local_tables']['sentient_form_mappings'] );
        $this->assertSame( 1, $report['runtime_tables']['sentient_async_requests'] );
        $this->assertTrue( $report['legacy_options']['exact_options']['sentient_forms_action_log']['exists'] );
        $this->assertSame( 1, $report['legacy_options']['option_prefixes']['sentient_forms_actions_']['count'] );
        $this->assertTrue( $report['settings']['legacy_option_present'] );
        $this->assertTrue( $report['settings']['plugin_settings_option_present'] );
        $this->assertTrue( $report['settings']['execution_global_disabled'] );
        $this->assertSame( 1, $report['settings']['execution_provider_disabled_count'] );
        $this->assertContains( 'sentient_forms_plugin_settings', $report['reset_plan']['settings_preserved'] );
        $this->assertContains(
            'local_runtime_data_will_be_removed',
            wp_list_pluck( $report['warnings'], 'code' )
        );
    }

    public function test_migration_dry_run_records_audit_row_without_resetting_data(): void
    {
        $this->seed_local_cutover_state();

        $result = $this->dispatch_json( 'POST', '/sentient-forms/v1/local/migration/dry-run', [], 201 );

        $this->assertSame( 'dry_run_complete', $result['status'] );
        $this->assertGreaterThan( 0, $result['run_id'] );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_execution_events' ) );
        $this->assertIsArray( get_option( 'sentient_forms_action_log' ) );

        global $wpdb;
        $runs = new Sentient_Forms_Migration_Runs_Repository( $wpdb );
        $row  = $runs->get( (int) $result['run_id'] );

        $this->assertSame( 'dry_run_complete', $row['status'] );
        $this->assertTrue( (bool) $row['dry_run'] );
        $this->assertSame( 1, $row['summary_json']['local_tables']['sentient_form_mappings'] );
    }

    public function test_migration_import_dry_run_reports_bundle_without_mutating_local_tables(): void
    {
        $bundle = $this->sample_cps_export_bundle();

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/import/dry-run',
            [
                'bundle' => $bundle,
            ],
            201
        );

        $this->assertSame( 'dry_run_complete', $result['status'] );
        $this->assertTrue( $result['dry_run'] );
        $this->assertTrue( $result['report']['ready_to_import'] );
        $this->assertSame( Sentient_Forms_Local_Import_Service::SCHEMA_VERSION, $result['report']['schema_version'] );
        $this->assertSame( 1, $result['report']['counts']['action_templates'] );
        $this->assertSame( 1, $result['report']['counts']['custom_actions'] );
        $this->assertSame( 1, $result['report']['counts']['form_mappings'] );
        $this->assertSame( 1, $result['report']['counts']['execution_events'] );
        $this->assertSame( 4, $result['report']['changes']['total_writes'] );
        $this->assertSame( [], $result['report']['conflicts'] );
        $this->assertSame( 'create', $result['report']['mapping']['action_templates']['template-cps-1']['operation'] );
        $this->assertSame( 'create', $result['report']['mapping']['custom_actions']['custom-cps-1']['operation'] );
        $this->assertSame( 'create', $result['report']['mapping']['form_mappings']['mapping-cps-1']['operation'] );
        $this->assertSame( 'review', $result['report']['mapping']['settings']['operation'] );

        $this->assertSame( 0, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_execution_events' ) );

        global $wpdb;
        $runs = new Sentient_Forms_Migration_Runs_Repository( $wpdb );
        $row  = $runs->get( (int) $result['run_id'] );

        $this->assertSame( 'cps_export', $row['source'] );
        $this->assertSame( 'dry_run_complete', $row['status'] );
        $this->assertTrue( (bool) $row['dry_run'] );
        $this->assertTrue( $row['summary_json']['ready_to_import'] );
        $this->assertSame( 4, $row['summary_json']['changes']['total_writes'] );
        $this->assertSame( [], $row['conflicts_json']['conflicts'] );
        $this->assertSame( 'create', $row['mapping_json']['form_mappings']['mapping-cps-1']['operation'] );
    }

    public function test_migration_import_dry_run_reports_conflicts_and_existing_records(): void
    {
        $this->seed_local_cutover_state();
        $bundle = $this->sample_cps_export_bundle(
            [
                'action_templates' => [
                    [
                        'external_id'     => 'template-cps-1',
                        'code'            => 'spam_triage_v1',
                        'display_name'    => 'Spam Triage Remote',
                        'prompt_template' => 'Classify {{entry}}.',
                        'version'         => '2.0.0',
                        'is_active'       => true,
                    ],
                    [
                        'external_id'     => 'template-cps-duplicate',
                        'code'            => 'spam_triage_v1',
                        'display_name'    => 'Duplicate Spam Triage',
                        'prompt_template' => 'Duplicate.',
                        'version'         => '2.0.0',
                        'is_active'       => true,
                    ],
                ],
                'custom_actions' => [
                    [
                        'external_id'          => 'custom-cps-1',
                        'template_code'        => 'spam_triage_v1',
                        'code'                 => 'contact_spam_triage',
                        'display_name'         => 'Contact Spam Triage Remote',
                        'definition_json'      => [
                            'prompt' => 'Classify contact form entry.',
                        ],
                        'model_selection_json' => [
                            'provider' => 'openrouter',
                            'model'    => 'openrouter/auto',
                        ],
                    ],
                ],
                'form_mappings' => [
                    [
                        'external_id'         => 'mapping-cps-1',
                        'form_source'         => 'gravity_forms',
                        'form_id'             => '42',
                        'hook'                => 'gform_after_submission',
                        'action_kind'         => 'custom_action',
                        'action_code'         => 'contact_spam_triage',
                        'input_bindings_json' => [
                            'email' => '3',
                        ],
                        'execution_mode'      => 'async',
                        'enabled'             => true,
                    ],
                    [
                        'external_id'         => 'mapping-cps-missing-action',
                        'form_source'         => 'gravity_forms',
                        'form_id'             => '99',
                        'hook'                => 'gform_after_submission',
                        'action_kind'         => 'custom_action',
                        'action_code'         => 'missing_remote_action',
                        'input_bindings_json' => [
                            'email' => '3',
                        ],
                        'execution_mode'      => 'async',
                        'enabled'             => true,
                    ],
                ],
                'execution_events' => [
                    [
                        'execution_request_id' => 'cutover-request-1',
                        'mapping_external_id'  => 'mapping-cps-1',
                        'provider'             => 'openrouter',
                        'model'                => 'openrouter/auto',
                        'status'               => 'succeeded',
                    ],
                ],
            ]
        );

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/import/dry-run',
            [
                'bundle' => $bundle,
            ],
            201
        );

        $this->assertFalse( $result['report']['ready_to_import'] );
        $this->assertContains(
            'duplicate_action_template_code',
            wp_list_pluck( $result['report']['conflicts'], 'code' )
        );
        $this->assertContains(
            'form_mapping_action_missing',
            wp_list_pluck( $result['report']['conflicts'], 'code' )
        );
        $this->assertSame( 'blocked', $result['report']['mapping']['action_templates']['template-cps-1']['operation'] );
        $this->assertSame( 'update', $result['report']['mapping']['custom_actions']['custom-cps-1']['operation'] );
        $this->assertSame( 'update', $result['report']['mapping']['form_mappings']['mapping-cps-1']['operation'] );
        $this->assertSame( 'blocked', $result['report']['mapping']['form_mappings']['mapping-cps-missing-action']['operation'] );
        $this->assertSame( 'update', $result['report']['mapping']['execution_events']['cutover-request-1']['operation'] );

        $this->assertSame( 1, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_execution_events' ) );

        global $wpdb;
        $runs = new Sentient_Forms_Migration_Runs_Repository( $wpdb );
        $row  = $runs->get( (int) $result['run_id'] );

        $this->assertFalse( $row['summary_json']['ready_to_import'] );
        $this->assertGreaterThanOrEqual( 2, $row['summary_json']['conflict_count'] );
        $this->assertSame( 'blocked', $row['mapping_json']['form_mappings']['mapping-cps-missing-action']['operation'] );
    }

    public function test_migration_import_apply_writes_bundle_and_is_idempotent(): void
    {
        $bundle = $this->sample_cps_export_bundle();

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

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/import/apply',
            [
                'bundle' => $bundle,
            ],
            201
        );

        $this->assertSame( 'completed', $result['status'] );
        $this->assertFalse( $result['dry_run'] );
        $this->assertTrue( $result['report']['ready_to_import'] );
        $this->assertSame( 1, $result['applied']['action_templates'] );
        $this->assertSame( 1, $result['applied']['custom_actions'] );
        $this->assertSame( 1, $result['applied']['form_mappings'] );
        $this->assertSame( 1, $result['applied']['execution_events'] );

        $this->assertSame( 1, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_execution_events' ) );

        $templates = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $template  = $templates->get_by_code( 'remote_spam_triage_v1' );
        $this->assertSame( 'imported', $template['source'] );
        $this->assertSame( 'template-cps-1', $template['external_id'] );

        $actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action  = $actions->get_by_code( 'remote_contact_spam_triage' );
        $this->assertSame( (int) $template['id'], (int) $action['template_id'] );
        $this->assertSame( 'custom-cps-1', $action['external_id'] );
        $this->assertSame( $credential_id, (int) ( $action['model_selection_json']['credential_id'] ?? 0 ) );

        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $mapping_rows = $mappings->list_for_form( 'gravity_forms', '7' );
        $this->assertCount( 1, $mapping_rows );
        $this->assertSame( (int) $action['id'], (int) $mapping_rows[0]['action_id'] );
        $this->assertSame( 'mapping-cps-1', $mapping_rows[0]['external_id'] );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $event  = $events->get_by_request_id( 'remote-request-1' );
        $this->assertSame( (int) $mapping_rows[0]['id'], (int) $event['mapping_id'] );
        $this->assertSame( 'succeeded', $event['status'] );

        $runs = new Sentient_Forms_Migration_Runs_Repository( $wpdb );
        $row  = $runs->get( (int) $result['run_id'] );
        $this->assertSame( 'completed', $row['status'] );
        $this->assertFalse( (bool) $row['dry_run'] );
        $this->assertSame( (int) $template['id'], (int) $row['mapping_json']['action_templates']['template-cps-1']['local_id'] );
        $this->assertSame( (int) $mapping_rows[0]['id'], (int) $row['mapping_json']['execution_events']['remote-request-1']['mapping_id'] );

        $second = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/import/apply',
            [
                'bundle' => $bundle,
            ],
            201
        );

        $this->assertSame( 'completed', $second['status'] );
        $this->assertSame( 'update', $second['report']['mapping']['action_templates']['template-cps-1']['operation'] );
        $this->assertSame( 'update', $second['report']['mapping']['custom_actions']['custom-cps-1']['operation'] );
        $this->assertSame( 'update', $second['report']['mapping']['form_mappings']['mapping-cps-1']['operation'] );
        $this->assertSame( 'update', $second['report']['mapping']['execution_events']['remote-request-1']['operation'] );
        $this->assertSame( 1, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_execution_events' ) );
    }

    public function test_migration_import_apply_blocks_conflicts_without_mutating_local_tables(): void
    {
        $this->seed_local_cutover_state();
        $bundle = $this->sample_cps_export_bundle(
            [
                'action_templates' => [
                    [
                        'external_id'     => 'template-cps-1',
                        'code'            => 'duplicate_import_template',
                        'display_name'    => 'Duplicate Import Template',
                        'prompt_template' => 'First.',
                        'version'         => '1.0.0',
                        'is_active'       => true,
                    ],
                    [
                        'external_id'     => 'template-cps-2',
                        'code'            => 'duplicate_import_template',
                        'display_name'    => 'Duplicate Import Template 2',
                        'prompt_template' => 'Second.',
                        'version'         => '1.0.0',
                        'is_active'       => true,
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/migration/import/apply' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_body_params( [ 'bundle' => $bundle ] );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 409, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_forms_import_bundle_not_ready', $data['code'] );
        $this->assertSame( 1, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_execution_events' ) );

        global $wpdb;
        $runs = new Sentient_Forms_Migration_Runs_Repository( $wpdb );
        $row  = $runs->get( (int) $data['data']['run_id'] );

        $this->assertSame( 'import_blocked', $row['status'] );
        $this->assertFalse( (bool) $row['dry_run'] );
        $this->assertFalse( $row['summary_json']['ready_to_import'] );
        $this->assertContains(
            'duplicate_action_template_code',
            wp_list_pluck( $row['conflicts_json']['conflicts'], 'code' )
        );
    }

    public function test_migration_import_apply_blocks_invalid_json_shapes_before_any_writes(): void
    {
        $bundle = $this->sample_cps_export_bundle(
            [
                'custom_actions' => [
                    [
                        'external_id'          => 'custom-cps-1',
                        'template_code'        => 'remote_spam_triage_v1',
                        'code'                 => 'remote_contact_spam_triage',
                        'display_name'         => 'Remote Contact Spam Triage',
                        'definition_json'      => 'not-an-object',
                        'model_selection_json' => [
                            'provider' => 'openrouter',
                            'model'    => 'openrouter/auto',
                        ],
                    ],
                ],
            ]
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/migration/import/apply' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_body_params( [ 'bundle' => $bundle ] );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 409, $response->get_status() );
        $data = $response->get_data();
        $this->assertSame( 'sentient_forms_import_bundle_not_ready', $data['code'] );
        $this->assertSame( 0, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_execution_events' ) );

        global $wpdb;
        $runs = new Sentient_Forms_Migration_Runs_Repository( $wpdb );
        $row  = $runs->get( (int) $data['data']['run_id'] );

        $this->assertSame( 'import_blocked', $row['status'] );
        $this->assertContains(
            'custom_action_invalid_definition_json',
            wp_list_pluck( $row['conflicts_json']['conflicts'], 'code' )
        );
    }

    public function test_migration_import_blocks_duplicate_source_identifiers_before_any_writes(): void
    {
        $bundle = $this->sample_cps_export_bundle(
            [
                'action_templates' => [
                    [
                        'external_id'     => 'shared-template-id',
                        'code'            => 'remote_spam_triage_v1',
                        'display_name'    => 'Remote Spam Triage',
                        'prompt_template' => 'Classify {{entry}}.',
                        'version'         => '1.0.0',
                        'is_active'       => true,
                    ],
                    [
                        'external_id'     => 'shared-template-id',
                        'code'            => 'remote_summary_v1',
                        'display_name'    => 'Remote Summary',
                        'prompt_template' => 'Summarize {{entry}}.',
                        'version'         => '1.0.0',
                        'is_active'       => true,
                    ],
                ],
                'custom_actions'   => [
                    [
                        'external_id'          => 'shared-action-id',
                        'template_code'        => 'remote_spam_triage_v1',
                        'code'                 => 'remote_contact_spam_triage',
                        'display_name'         => 'Remote Contact Spam Triage',
                        'definition_json'      => [
                            'prompt' => 'Classify contact form entry.',
                        ],
                        'model_selection_json' => [
                            'provider' => 'openrouter',
                            'model'    => 'openrouter/auto',
                        ],
                    ],
                    [
                        'external_id'          => 'shared-action-id',
                        'template_code'        => 'remote_summary_v1',
                        'code'                 => 'remote_contact_summary',
                        'display_name'         => 'Remote Contact Summary',
                        'definition_json'      => [
                            'prompt' => 'Summarize contact form entry.',
                        ],
                        'model_selection_json' => [
                            'provider' => 'openrouter',
                            'model'    => 'openrouter/auto',
                        ],
                    ],
                ],
                'form_mappings'    => [
                    [
                        'external_id'         => 'shared-mapping-id',
                        'form_source'         => 'gravity_forms',
                        'form_id'             => '7',
                        'hook'                => 'gform_after_submission',
                        'action_kind'         => 'custom_action',
                        'action_code'         => 'remote_contact_spam_triage',
                        'input_bindings_json' => [
                            'email' => '3',
                        ],
                        'execution_mode'      => 'async',
                        'enabled'             => true,
                    ],
                    [
                        'external_id'         => 'shared-mapping-id',
                        'form_source'         => 'gravity_forms',
                        'form_id'             => '8',
                        'hook'                => 'gform_after_submission',
                        'action_kind'         => 'custom_action',
                        'action_code'         => 'remote_contact_summary',
                        'input_bindings_json' => [
                            'email' => '3',
                        ],
                        'execution_mode'      => 'async',
                        'enabled'             => true,
                    ],
                ],
                'execution_events' => [
                    [
                        'execution_request_id' => 'shared-request-id',
                        'mapping_external_id'  => 'shared-mapping-id',
                        'provider'             => 'openrouter',
                        'model'                => 'openrouter/auto',
                        'status'               => 'succeeded',
                    ],
                    [
                        'execution_request_id' => 'shared-request-id',
                        'mapping_external_id'  => 'shared-mapping-id',
                        'provider'             => 'openrouter',
                        'model'                => 'openrouter/auto',
                        'status'               => 'failed',
                    ],
                ],
            ]
        );

        $dry_run = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/import/dry-run',
            [
                'bundle' => $bundle,
            ],
            201
        );

        $this->assertFalse( $dry_run['report']['ready_to_import'] );
        $conflict_codes = wp_list_pluck( $dry_run['report']['conflicts'], 'code' );
        $this->assertContains( 'duplicate_action_template_external_id', $conflict_codes );
        $this->assertContains( 'duplicate_custom_action_external_id', $conflict_codes );
        $this->assertContains( 'duplicate_form_mapping_external_id', $conflict_codes );
        $this->assertContains( 'duplicate_execution_event_execution_request_id', $conflict_codes );
        $this->assertSame( 0, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_execution_events' ) );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/migration/import/apply' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_body_params( [ 'bundle' => $bundle ] );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 0, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_execution_events' ) );
    }

    public function test_migration_approved_reset_requires_confirmation_phrase(): void
    {
        $this->seed_local_cutover_state();

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/migration/approved-reset' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_body_params( [ 'confirmation_phrase' => 'reset now' ] );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertIsArray( get_option( 'sentient_forms_action_log' ) );
    }

    public function test_migration_approved_reset_clears_runtime_state_and_preserves_local_credentials(): void
    {
        $this->seed_local_cutover_state();

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ]
        );

        $this->assertSame( 'completed', $result['status'] );
        $this->assertSame( 1, $result['deleted_tables']['sentient_action_templates'] );
        $this->assertSame( 1, $result['deleted_tables']['sentient_custom_actions'] );
        $this->assertSame( 1, $result['deleted_tables']['sentient_form_mappings'] );
        $this->assertSame( 1, $result['deleted_tables']['sentient_execution_events'] );
        $this->assertSame( 1, $result['deleted_tables']['sentient_async_requests'] );
        $this->assertSame( 1, $result['deleted_options']['option_prefixes']['sentient_forms_actions_']['count'] );
        $this->assertTrue( $result['deleted_options']['exact_options']['sentient_forms_action_log'] );

        $this->assertSame( 0, $this->table_count( 'sentient_action_templates' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_custom_actions' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_execution_events' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_async_requests' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_provider_credentials' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_external_service_consents' ) );
        $this->assertSame( 1, $this->table_count( 'sentient_model_cache' ) );
        $this->assertFalse( get_option( 'sentient_forms_action_log' ) );
        $this->assertFalse( get_option( 'sentient_forms_actions_gravity_forms_42' ) );
        $settings = get_option( 'sentient_forms_settings' );
        $this->assertIsArray( $settings );
        $this->assertArrayNotHasKey( 'action_results', $settings );
        $this->assertTrue( $result['deleted_options']['settings_keys']['action_results'] );
        $plugin_settings = get_option( 'sentient_forms_plugin_settings' );
        $this->assertIsArray( $plugin_settings );
        $this->assertTrue( $plugin_settings['execution_global_disabled'] );
        $this->assertTrue( $plugin_settings['execution_provider_disabled']['gravity_forms'] );

        global $wpdb;
        $runs = new Sentient_Forms_Migration_Runs_Repository( $wpdb );
        $row  = $runs->get( (int) $result['run_id'] );

        $this->assertSame( 'completed', $row['status'] );
        $this->assertFalse( (bool) $row['dry_run'] );
        $this->assertSame( 0, $row['summary_json']['after']['local_tables']['sentient_form_mappings'] );
    }

    public function test_execute_form_mapping_delegates_to_local_execution_service(): void
    {
        $service    = new Sentient_Forms_Test_Local_Action_Execution_Service(
            [
                'execution_request_id' => 'local-request-1',
                'status'               => 'succeeded',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'result'               => [
                    'content' => 'Test run complete.',
                ],
            ]
        );
        $controller = new Sentient_Forms_Local_Workspace_Controller( null, null, null, null, $service );
        $request    = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/form-mappings/42/execute-test' );
        $request->set_url_params( [ 'id' => 42 ] );
        $request->set_body_params(
            [
                'form'    => [ 'id' => 7, 'title' => 'Contact' ],
                'entry'   => [ 'id' => 99, '1' => 'Ada' ],
                'context' => [ 'hook' => 'gform_after_submission' ],
            ]
        );

        $response = $controller->execute_form_mapping( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 'local-request-1', $response->get_data()['execution_request_id'] );
        $this->assertCount( 1, $service->calls );
        $this->assertSame( 42, $service->calls[0]['mapping_id'] );
        $this->assertSame( 'Contact', $service->calls[0]['form']['title'] );
        $this->assertSame( 'Ada', $service->calls[0]['entry']['1'] );
        $this->assertSame( 'gform_after_submission', $service->calls[0]['context']['hook'] );
    }

    public function test_execute_form_mapping_requires_form_object(): void
    {
        $controller = new Sentient_Forms_Local_Workspace_Controller(
            null,
            null,
            null,
            null,
            new Sentient_Forms_Test_Local_Action_Execution_Service( [] )
        );
        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/form-mappings/42/execute-test' );
        $request->set_url_params( [ 'id' => 42 ] );
        $request->set_body_params( [ 'entry' => [] ] );

        $response = $controller->execute_form_mapping( $request );

        $this->assertWPError( $response );
        $this->assertSame( 'sentient_forms_missing_test_form', $response->get_error_code() );
        $this->assertSame( 400, $response->get_error_data()['status'] );
    }

    private function dispatch_json( string $method, string $route, array $body = [], int $expected_status = 200 ): array
    {
        $query = [];
        if ( str_contains( $route, '?' ) )
        {
            $parts = wp_parse_url( $route );
            $route = $parts['path'] ?? $route;
            if ( isset( $parts['query'] ) )
            {
                parse_str( $parts['query'], $query );
            }
        }

        $request = new WP_REST_Request( $method, $route );
        if ( in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) )
        {
            $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        }

        if ( [] !== $query )
        {
            $request->set_query_params( $query );
        }

        if ( [] !== $body )
        {
            $request->set_body_params( $body );
        }

        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( $expected_status, $response->get_status() );

        $data = $response->get_data();
        $this->assertIsArray( $data );

        return $data;
    }

    private function truncate_local_workspace_tables(): void
    {
        global $wpdb;

        foreach (
            [
                'sentient_provider_credentials',
                'sentient_external_service_consents',
                'sentient_action_templates',
                'sentient_custom_actions',
                'sentient_form_mappings',
                'sentient_execution_events',
                'sentient_submission_ledger_settings',
                'sentient_submission_ledger',
                'sentient_migration_runs',
                'sentient_model_cache',
                'sentient_async_requests',
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }

        delete_option( 'sentient_forms_action_log' );
        delete_option( 'sentient_forms_actions_gravity_forms_42' );
        delete_option( 'sentient_forms_action_defaults_spam_detection_v1' );
        delete_option( 'sentient_forms_proxy_api_key' );
        delete_option( '_transient_sentient_forms_cps_version' );
        delete_option( '_transient_timeout_sentient_forms_cps_version' );
    }

    private function seed_local_cutover_state(): void
    {
        global $wpdb;

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $credential_id = $credentials->create(
            [
                'provider'      => 'openrouter',
                'label'         => 'Owner key',
                'auth_mode'     => 'constant',
                'constant_name' => 'SENTIENT_FORMS_OPENROUTER_KEY',
                'status'        => 'valid',
            ]
        );
        $this->assertIsInt( $credential_id );

        $consents = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $consent_id = $consents->record( 'openrouter', '2026-04-16', self::$admin_id );
        $this->assertIsInt( $consent_id );

        $templates = new Sentient_Forms_Action_Templates_Repository( $wpdb );
        $template_id = $templates->upsert_by_code(
            [
                'source'          => 'bundled',
                'code'            => 'spam_triage_v1',
                'display_name'    => 'Spam Triage',
                'prompt_template' => 'Classify {{entry}}.',
                'version'         => '1.0.0',
                'is_active'       => true,
            ]
        );
        $this->assertIsInt( $template_id );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $custom_action_id = $custom_actions->create(
            [
                'template_id'          => $template_id,
                'code'                 => 'contact_spam_triage',
                'display_name'         => 'Contact Spam Triage',
                'definition_json'      => [
                    'prompt' => 'Classify contact form entry.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ]
        );
        $this->assertIsInt( $custom_action_id );

        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '42',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $custom_action_id,
                'input_bindings_json' => [
                    'email' => '3',
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $event_id = $events->record(
            [
                'execution_request_id' => 'cutover-request-1',
                'mapping_id'           => $mapping_id,
                'form_source'          => 'gravity_forms',
                'form_id'              => '42',
                'entry_id'             => '99',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
            ]
        );
        $this->assertIsInt( $event_id );

        $models = new Sentient_Forms_Model_Cache_Repository( $wpdb );
        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openrouter/auto',
                [
                    'id'   => 'openrouter/auto',
                    'free' => true,
                ],
                gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
            )
        );

        $wpdb->insert(
            $wpdb->prefix . 'sentient_async_requests',
            [
                'request_hash'      => str_repeat( 'a', 64 ),
                'action_id'         => 'contact_spam_triage',
                'adapter'           => 'gravity_forms',
                'record_type'       => 'job',
                'status'            => 'queued',
                'first_seen_at'     => current_time( 'mysql' ),
                'last_seen_at'      => current_time( 'mysql' ),
                'last_error'        => null,
                'payload_digest'    => str_repeat( 'b', 64 ),
                'telemetry_payload' => null,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        update_option(
            'sentient_forms_action_log',
            [
                [
                    'request_id' => 'legacy-log-1',
                ],
            ]
        );
        update_option(
            'sentient_forms_actions_gravity_forms_42',
            [
                'legacy_mapping' => [
                    'central_action_id' => 'spam_detection_v1',
                ],
            ]
        );
        update_option(
            'sentient_forms_action_defaults_spam_detection_v1',
            [
                'model_override' => 'openrouter/auto',
            ]
        );
        update_option(
            'sentient_forms_settings',
            [
                'enforce_nonce_verification' => false,
                'action_results'             => [
                    'spam_analysis' => [
                        [
                            'timestamp' => time(),
                            'result'    => [
                                'content' => 'Legacy full output that should be removed during reset.',
                            ],
                        ],
                    ],
                ],
                'license'                    => [
                    'license_key'   => 'LIC-LOCAL-DEV',
                    'proxy_api_key' => 'proxy-local-123',
                    'site_id'       => 'site-local-123',
                ],
            ]
        );
        update_option(
            'sentient_forms_plugin_settings',
            [
                'execution_global_disabled'         => true,
                'execution_provider_disabled'       => [
                    'gravity_forms' => true,
                ],
            ]
        );
        update_option( 'sentient_forms_proxy_api_key', 'old-proxy-key' );
        update_option( '_transient_sentient_forms_cps_version', 'old-cps-version' );
        update_option( '_transient_timeout_sentient_forms_cps_version', time() + HOUR_IN_SECONDS );
    }

    private function table_count( string $suffix ): int
    {
        global $wpdb;

        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( $wpdb->prefix . $suffix ) );
    }

    private function sample_cps_export_bundle( array $overrides = [] ): array
    {
        $fixture_path = dirname( __DIR__ ) . '/fixtures/cps-export/minimal-local-first-export.json';
        $fixture      = file_get_contents( $fixture_path );

        if ( false === $fixture )
        {
            throw new RuntimeException( 'Unable to read CPS export fixture.' );
        }

        $bundle = json_decode( $fixture, true );
        if ( ! is_array( $bundle ) )
        {
            throw new RuntimeException( 'CPS export fixture is not valid JSON.' );
        }

        return array_merge( $bundle, $overrides );
    }
}

class Sentient_Forms_Test_Local_Action_Execution_Service extends Sentient_Forms_Local_Action_Execution_Service
{
    /** @var array<int, array{mapping_id: int, form: array<string, mixed>, entry: array<string, mixed>, context: array<string, mixed>}> */
    public array $calls = [];

    public function __construct( private array | WP_Error $response )
    {
    }

    public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
    {
        $this->calls[] = [
            'mapping_id' => $mapping_id,
            'form'       => $form,
            'entry'      => $entry,
            'context'    => $context,
        ];

        return $this->response;
    }
}
