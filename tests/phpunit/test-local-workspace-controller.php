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
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_elementor_pro_form_submissions_api_available' );
        delete_option( 'sentient_forms_action_defaults_reset_race' );
        delete_option( 'sentient_forms_action_defaults_external_race' );
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

    public function test_support_bundle_includes_elementor_pro_requirement_diagnostics(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_false' );

        $support_bundle = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/support-bundle' );
        $elementor      = $support_bundle['form_sources']['elementor_pro_forms'] ?? null;

        $this->assertIsArray( $elementor );
        $this->assertSame( 'Elementor Pro Forms', $elementor['label'] );
        $this->assertFalse( $elementor['is_active'] );
        $this->assertSame( 'requires_pro', $elementor['availability'] );
        $this->assertStringContainsString( 'Elementor Pro Forms', $elementor['availability_message'] );
        $this->assertTrue( $elementor['requirements']['requires_pro'] );
        $this->assertTrue( $elementor['requirements']['is_elementor_active'] );
        $this->assertFalse( $elementor['requirements']['is_pro_forms_api_available'] );
        $this->assertTrue( $elementor['ledger']['required_for_parity'] );
        $this->assertFalse( $elementor['lifecycles']['after_submission']['supported'] );
    }

    public function test_support_bundle_marks_elementor_pro_forms_without_form_submissions_as_paid_but_limited(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $support_bundle = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/support-bundle' );
        $elementor      = $support_bundle['form_sources']['elementor_pro_forms'] ?? null;

        $this->assertIsArray( $elementor );
        $this->assertTrue( $elementor['is_active'] );
        $this->assertSame( 'available', $elementor['availability'] );
        $this->assertTrue( $elementor['lifecycles']['after_submission']['supported'] );
        $this->assertFalse( $elementor['native_entry']['id'] );
        $this->assertFalse( $elementor['native_entry']['link'] );
        $this->assertTrue( $elementor['requirements']['is_pro_forms_api_available'] );
        $this->assertFalse( $elementor['requirements']['is_form_submissions_api_available'] );
        $this->assertSame( 'unavailable', $elementor['requirements']['native_submission_parity'] );
        $this->assertSame(
            'elementor_pro_advanced_solo_or_higher',
            $elementor['requirements']['minimum_native_submission_plan']
        );
        $this->assertStringContainsString(
            'Form Submissions',
            $elementor['requirements']['native_submission_parity_reason']
        );
    }

    public function test_support_bundle_suppresses_elementor_native_entry_ids_when_form_submissions_are_unavailable(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $created = $ledger->create(
            [
                'submission_uuid'        => '55555555-5555-4555-8555-555555555555',
                'form_source'            => 'elementor_pro_forms',
                'form_id'                => '91:formabc',
                'native_entry_id'        => 'elementor-submission-123',
                'native_entry_url'       => 'https://example.test/wp-admin/admin.php?page=e-form-submissions&submission=123',
                'logical_fields_json'    => [
                    'email' => 'elementor-diagnostic@example.test',
                ],
                'provider_metadata_json' => [
                    'source' => 'elementor_pro_forms',
                ],
            ]
        );
        $this->assertIsInt( $created );

        $support_bundle = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/support-bundle' );
        $recent         = $support_bundle['submission_ledger']['recent'][0] ?? [];

        $this->assertSame( 'elementor_pro_forms', $recent['form_source'] ?? null );
        $this->assertSame( '91:formabc', $recent['form_id'] ?? null );
        $this->assertNull( $recent['native_entry_id'] ?? null );
        $this->assertStringNotContainsString( 'elementor-submission-123', wp_json_encode( $support_bundle ) );
        $this->assertStringNotContainsString( 'e-form-submissions', wp_json_encode( $support_bundle ) );
        $this->assertStringNotContainsString( 'elementor-diagnostic@example.test', wp_json_encode( $support_bundle ) );
    }

    public function test_local_execution_events_suppress_elementor_entry_ids_when_form_submissions_are_unavailable(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $event = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/execution-events',
            [
                'execution_request_id' => 'request-elementor-native-entry-suppression',
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => '91:formabc',
                'entry_id'             => 'elementor-submission-123',
                'submission_uuid'      => '66666666-6666-4666-8666-666666666666',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
            ],
            201
        );
        $this->assertArrayHasKey( 'entry_id', $event );
        $this->assertNull( $event['entry_id'] );

        $events = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/execution-events?limit=10' );
        $this->assertSame( 'elementor_pro_forms', $events[0]['form_source'] ?? null );
        $this->assertSame( '91:formabc', $events[0]['form_id'] ?? null );
        $this->assertArrayHasKey( 'entry_id', $events[0] );
        $this->assertNull( $events[0]['entry_id'] );
        $this->assertStringNotContainsString( 'elementor-submission-123', wp_json_encode( $events ) );

        $support_bundle = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/support-bundle' );
        $recent         = $support_bundle['execution_summary']['recent'][0] ?? [];

        $this->assertSame( 'elementor_pro_forms', $recent['form_source'] ?? null );
        $this->assertSame( '91:formabc', $recent['form_id'] ?? null );
        $this->assertArrayHasKey( 'entry_id', $recent );
        $this->assertNull( $recent['entry_id'] );
        $this->assertStringNotContainsString( 'elementor-submission-123', wp_json_encode( $support_bundle ) );
    }

    public function test_mapping_list_requires_form_filter(): void
    {
        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/local/form-mappings' );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 400, $response->get_status() );
    }

    public function test_contact_form_7_native_after_submission_hook_creates_canonical_mapping(): void
    {
        $custom_action = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/custom-actions',
            [
                'code'                 => 'cf7_summary',
                'display_name'         => 'CF7 Summary',
                'definition_json'      => [
                    'prompt' => 'Summarize the Contact Form 7 submission.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ],
            201
        );

        $mapping = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/form-mappings',
            [
                'form_source'         => 'contact_form_7',
                'form_id'             => '646',
                'hook'                => 'wpcf7_mail_sent',
                'action_kind'         => 'custom_action',
                'action_id'           => $custom_action['id'],
                'input_bindings_json' => [
                    'email' => 'your-email',
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ],
            201
        );

        $this->assertSame( 'contact_form_7', $mapping['form_source'] );
        $this->assertSame( '646', $mapping['form_id'] );
        $this->assertSame( 'after_submission', $mapping['hook'] );

        $mappings = $this->dispatch_json( 'GET', '/sentient-forms/v1/local/form-mappings?form_source=contact_form_7&form_id=646' );

        $this->assertCount( 1, $mappings );
        $this->assertSame( $mapping['id'], $mappings[0]['id'] );
        $this->assertSame( 'after_submission', $mappings[0]['hook'] );
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

    public function test_migration_approved_reset_reports_failed_option_deletion_and_retries(): void
    {
        global $wpdb;

        $this->seed_local_cutover_state();
        $failed_option = 'sentient_forms_action_log';
        $intercepted   = false;
        $fail_option_delete = static function ( string $query ) use ( $failed_option, &$intercepted ): string {
            if ( ! $intercepted && str_starts_with( ltrim( $query ), 'DELETE' ) && str_contains( $query, $failed_option ) )
            {
                $intercepted = true;
                return 'SENTIENT FORMS FORCED OPTION DELETE FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_option_delete, PHP_INT_MAX );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            $failure = $this->dispatch_json(
                'POST',
                '/sentient-forms/v1/local/migration/approved-reset',
                [
                    'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                ],
                500
            );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_option_delete, PHP_INT_MAX );
        }

        $this->assertTrue( $intercepted );
        $this->assertSame( 'sentient_forms_local_cutover_option_delete_failed', $failure['code'] ?? null );
        $this->assertIsArray( get_option( $failed_option ) );
        $run_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i ORDER BY id DESC LIMIT 1',
                $wpdb->prefix . 'sentient_migration_runs'
            )
        );
        $run = ( new Sentient_Forms_Migration_Runs_Repository( $wpdb ) )->get( $run_id );
        $this->assertSame( 'failed', $run['status'] ?? null );
        $this->assertSame(
            'sentient_forms_local_cutover_option_delete_failed',
            $run['summary_json']['error_code'] ?? null
        );

        $retry = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ]
        );
        $this->assertSame( 'completed', $retry['status'] ?? null );
        $this->assertFalse( get_option( $failed_option, false ) );
    }

    public function test_migration_approved_reset_cannot_race_action_authority_cutover(): void
    {
        $this->seed_local_cutover_state();

        $nested_migration = null;
        $ran_nested_migration = false;
        $run_migration_before_delete = static function ( string $option_name ) use ( &$nested_migration, &$ran_nested_migration ): void {
            if ( $ran_nested_migration || 'sentient_forms_actions_gravity_forms_42' !== $option_name )
            {
                return;
            }

            $ran_nested_migration = true;
            $nested_migration     = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        };
        add_action( 'delete_option', $run_migration_before_delete );
        try
        {
            $result = $this->dispatch_json(
                'POST',
                '/sentient-forms/v1/local/migration/approved-reset',
                [
                    'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                ]
            );
        }
        finally
        {
            remove_action( 'delete_option', $run_migration_before_delete );
        }

        $this->assertSame( 'completed', $result['status'] ?? null );
        $this->assertSame( 0, $nested_migration['migration_complete'] ?? null );
        $this->assertSame( 0, $this->table_count( 'sentient_form_mappings' ) );
    }

    public function test_migration_approved_reset_requires_global_execution_quiescence(): void
    {
        $this->seed_local_cutover_state();
        update_option(
            'sentient_forms_plugin_settings',
            [
                'execution_global_disabled'   => false,
                'execution_provider_disabled' => [ 'gravity_forms' => true ],
            ],
            false
        );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/migration/approved-reset' );
        $request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
        $request->set_body_params(
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ]
        );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 409, $response->get_status() );
        $this->assertSame( 'sentient_forms_local_cutover_execution_not_quiesced', $response->get_data()['code'] ?? null );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertIsArray( get_option( 'sentient_forms_action_log' ) );
    }

    public function test_migration_approved_reset_rejects_running_local_mapping_metadata(): void
    {
        $this->seed_local_cutover_state();
        update_option(
            'sentient_forms_async_jobs',
            [
                'running-local-mapping' => [
                    'job_id' => 'running-local-mapping',
                    'hook'   => 'sentient_forms_process_local_mapping',
                    'status' => 'running',
                ],
            ],
            false
        );

        $failure = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ],
            409
        );

        $this->assertSame( 'sentient_forms_local_cutover_execution_not_quiesced', $failure['code'] ?? null );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
    }

    public function test_migration_approved_reset_rejects_authoritative_running_request_without_metadata(): void
    {
        global $wpdb;

        $this->seed_local_cutover_state();
        delete_option( 'sentient_forms_async_jobs' );
        $updated = $wpdb->update(
            $wpdb->prefix . 'sentient_async_requests',
            [ 'status' => 'running' ],
            [ 'request_hash' => str_repeat( 'a', 64 ) ],
            [ '%s' ],
            [ '%s' ]
        );
        $this->assertSame( 1, $updated );

        $failure = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ],
            409
        );

        $this->assertSame( 'sentient_forms_local_cutover_execution_not_quiesced', $failure['code'] ?? null );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
    }

    public function test_migration_approved_reset_rejects_running_synchronous_accepted_execution(): void
    {
        $this->seed_local_cutover_state();
        delete_option( 'sentient_forms_async_jobs' );

        $request_id = 'accepted-sync-reset-race-' . wp_generate_password( 20, false, false );
        $claim = Sentient_Forms_Plugin::instance()->get_async_request_store()->claim_execution(
            $request_id,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'gravity_forms',
                'payload_digest' => hash( 'sha256', 'accepted-sync-reset-race' ),
            ],
            false,
            'accepted_sync'
        );
        $this->assertSame( 'claimed', $claim['state'] ?? null );

        $failure = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ],
            409
        );

        $this->assertSame( 'sentient_forms_local_cutover_execution_not_quiesced', $failure['code'] ?? null );
        $this->assertSame( 1, $this->table_count( 'sentient_form_mappings' ) );
        $this->assertSame(
            'running',
            Sentient_Forms_Plugin::instance()->get_async_request_store()->get( $request_id, 'accepted_sync' )['status'] ?? null
        );
    }

    public function test_migration_approved_reset_cancels_pending_local_mapping_jobs_and_clears_metadata(): void
    {
        $this->seed_local_cutover_state();
        $action_id = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            'sentient_forms_process_local_mapping',
            [ [ 'local_mapping_id' => 1 ] ],
            'sentient_forms_async'
        );
        $this->assertIsInt( $action_id );
        ( new Sentient_Forms_Async_Metadata_Store() )->record_job(
            'pending-local-mapping',
            'sentient_forms_process_local_mapping',
            [ 'local_mapping_id' => 1 ],
            time() + HOUR_IN_SECONDS,
            $action_id,
            'sentient_forms_async'
        );

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ]
        );

        $this->assertSame( 'completed', $result['status'] ?? null );
        $this->assertSame( ActionScheduler_Store::STATUS_CANCELED, ActionScheduler::store()->get_status( $action_id ) );
        $this->assertFalse( get_option( 'sentient_forms_async_jobs', false ) );
    }

    public function test_migration_approved_reset_cancels_pending_local_mapping_jobs_across_custom_groups(): void
    {
        $this->seed_local_cutover_state();
        $action_id = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            'sentient_forms_process_local_mapping',
            [ [ 'local_mapping_id' => 1 ] ],
            'sentient_forms_custom_async_group'
        );
        $this->assertIsInt( $action_id );

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ]
        );

        $this->assertSame( 'completed', $result['status'] ?? null );
        $this->assertSame( ActionScheduler_Store::STATUS_CANCELED, ActionScheduler::store()->get_status( $action_id ) );
    }

    public function test_migration_approved_reset_clears_wp_cron_local_mapping_fallbacks(): void
    {
        $this->seed_local_cutover_state();
        $payload = [ [ 'local_mapping_id' => 1, 'execution_request_id' => 'wp-cron-reset-race' ] ];
        $run_at  = time() + HOUR_IN_SECONDS;
        $this->assertTrue( wp_schedule_single_event( $run_at, 'sentient_forms_process_local_mapping', $payload ) );
        $this->assertSame( $run_at, wp_next_scheduled( 'sentient_forms_process_local_mapping', $payload ) );

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ]
        );

        $this->assertSame( 'completed', $result['status'] ?? null );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_process_local_mapping', $payload ) );
    }

    public function test_migration_approved_reset_cancels_evaluation_jobs_across_action_scheduler_and_wp_cron(): void
    {
        $this->seed_local_cutover_state();
        $evaluation_payload = [ 'context' => [ 'job_id' => 'evaluation-reset-fixture' ] ];
        $action_id = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            'sentient_forms_evaluate_action',
            $evaluation_payload,
            'sentient_forms_custom_evaluation_group'
        );
        $this->assertIsInt( $action_id );

        $wp_cron_payload = [ 'context' => [ 'job_id' => 'evaluation-wp-cron-reset-fixture' ] ];
        $run_at = time() + HOUR_IN_SECONDS;
        $this->assertTrue( wp_schedule_single_event( $run_at, 'sentient_forms_evaluate_action', $wp_cron_payload ) );
        $this->assertSame( $run_at, wp_next_scheduled( 'sentient_forms_evaluate_action', $wp_cron_payload ) );

        $result = $this->dispatch_json(
            'POST',
            '/sentient-forms/v1/local/migration/approved-reset',
            [
                'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
            ]
        );

        $this->assertSame( 'completed', $result['status'] ?? null );
        $this->assertSame( ActionScheduler_Store::STATUS_CANCELED, ActionScheduler::store()->get_status( $action_id ) );
        $this->assertFalse( wp_next_scheduled( 'sentient_forms_evaluate_action', $wp_cron_payload ) );
    }

    public function test_migration_approved_reset_retains_lock_across_wordpress_database_reconnect(): void
    {
        global $wpdb;

        $this->seed_local_cutover_state();
        $lock_database = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $lock_observer = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $lock_name_method = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'database_lock_name' );
        $lock_name        = $lock_name_method->invoke( null );
        $lock_connection_id = (int) $lock_database->get_var( 'SELECT CONNECTION_ID()' );
        $lock_filter   = static function ( mixed $candidate ) use ( $lock_database ): wpdb {
            return $lock_database;
        };
        $reconnected   = false;
        $observed_lock_owner = null;
        $reconnect_during_reset = static function ( string $query ) use ( $wpdb, $lock_observer, $lock_name, &$observed_lock_owner, &$reconnected ): string {
            if ( ! $reconnected && preg_match( '/^DELETE\s+FROM/i', ltrim( $query ) ) )
            {
                $reconnected = true;
                $wpdb->close();
                $observed_lock_owner = (int) $lock_observer->get_var(
                    $lock_observer->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name )
                );
            }

            return $query;
        };

        add_filter( 'sentient_forms_action_authority_lock_database', $lock_filter, 10, 3 );
        add_filter( 'query', $reconnect_during_reset );
        try
        {
            $result = ( new Sentient_Forms_Local_Cutover_Service() )->approved_reset(
                Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                self::$admin_id
            );
        }
        finally
        {
            remove_filter( 'sentient_forms_action_authority_lock_database', $lock_filter, 10 );
            remove_filter( 'query', $reconnect_during_reset );
            $lock_database->close();
            $lock_observer->close();
        }

        $this->assertTrue( $reconnected );
        $this->assertSame( $lock_connection_id, $observed_lock_owner );
        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'sentient_forms_local_cutover_postcondition_failed', $result->get_error_code() );
    }

    public function test_migration_approved_reset_fences_plugin_owned_runtime_writers(): void
    {
        global $wpdb;

        $this->seed_local_cutover_state();
        $outcomes = [];
        $attempted = false;
        $attempt_writes_during_finalization = static function ( string $option_name ) use ( $wpdb, &$attempted, &$outcomes ): void {
            if ( $attempted || 'sentient_forms_settings' !== $option_name )
            {
                return;
            }
            $attempted = true;

            $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/actions/reset_race/defaults' );
            $request->set_param( 'action_id', 'reset_race' );
            $request->set_param( 'action_customization', 'Must not survive reset.' );
            $outcomes['action_defaults'] = ( new Sentient_Forms_Form_Action_Config_Controller() )->update_action_defaults( $request );
            $outcomes['action_log'] = Sentient_Forms_Action_Log_Controller::log_execution(
                [
                    'execution_request_id' => 'reset-race-action-log',
                    'status'               => 'pending',
                ]
            );
            $outcomes['managed_usage_scrub'] = Sentient_Forms_Managed_Usage_Sanitizer::scrub_local_storage();
            $outcomes['execution_event'] = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->record(
                [
                    'execution_request_id' => 'reset-race-event',
                    'provider'             => 'openrouter',
                    'status'               => 'queued',
                ]
            );
            $outcomes['async_request'] = ( new Sentient_Forms_Async_Request_Store( $wpdb ) )->record(
                'reset-race-async',
                [
                    'action_id'      => 'entry_summary_v1',
                    'record_type'    => 'job',
                    'status'         => 'queued',
                    'payload_digest' => hash( 'sha256', 'reset-race-async' ),
                ]
            );
            $outcomes['action_template'] = ( new Sentient_Forms_Action_Templates_Repository( $wpdb ) )->upsert_by_code(
                [
                    'code'            => 'reset_race_template',
                    'display_name'    => 'Reset Race Template',
                    'prompt_template' => 'Do not persist.',
                ]
            );
            $outcomes['custom_action'] = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->create(
                [
                    'code'            => 'reset_race_action',
                    'display_name'    => 'Reset Race Action',
                    'definition_json' => [ 'prompt' => 'Do not persist.' ],
                ]
            );
            $outcomes['form_mapping'] = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->create(
                [
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'reset-race',
                    'hook'                => 'after_submission',
                    'action_kind'         => 'custom_action',
                    'action_id'           => 1,
                    'input_bindings_json' => [],
                    'enabled'             => true,
                ]
            );
            $outcomes['lead_scoring'] = ( new Sentient_Forms_Lead_Scoring_Results_Repository( $wpdb ) )->upsert_from_execution(
                [
                    'form_source'          => 'gravity_forms',
                    'form_id'              => 'reset-race',
                    'entry_id'             => '1',
                    'action_code'          => 'lead_grading_v1',
                    'execution_request_id' => 'reset-race-lead',
                ]
            );
            $settings_request = new WP_REST_Request( 'POST', '/sentient-forms/v1/settings' );
            $settings_request->set_param( 'execution_global_disabled', false );
            $outcomes['settings'] = ( new Sentient_Forms_Settings_Controller() )->update_settings( $settings_request );
        };
        add_action( 'updated_option', $attempt_writes_during_finalization, 10, 1 );
        try
        {
            $result = $this->dispatch_json(
                'POST',
                '/sentient-forms/v1/local/migration/approved-reset',
                [
                    'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                ]
            );
        }
        finally
        {
            remove_action( 'updated_option', $attempt_writes_during_finalization, 10 );
        }

        $this->assertTrue( $attempted );
        $this->assertSame( 'completed', $result['status'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['action_defaults'] ?? null );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $outcomes['action_defaults']->get_error_code() );
        $this->assertFalse( $outcomes['action_log'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['managed_usage_scrub'] ?? null );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $outcomes['managed_usage_scrub']->get_error_code() );
        $this->assertInstanceOf( WP_Error::class, $outcomes['execution_event'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['async_request'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['action_template'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['custom_action'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['form_mapping'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['lead_scoring'] ?? null );
        $this->assertInstanceOf( WP_Error::class, $outcomes['settings'] ?? null );
        $this->assertFalse( get_option( 'sentient_forms_action_defaults_reset_race', false ) );
        $this->assertSame( 0, $this->table_count( 'sentient_execution_events' ) );
        $this->assertSame( 0, $this->table_count( 'sentient_async_requests' ) );
    }

    public function test_migration_approved_reset_fails_closed_when_unfenced_state_reappears(): void
    {
        global $wpdb;

        $this->seed_local_cutover_state();
        $repopulated = false;
        $repopulate_after_final_option_delete = static function ( string $option_name ) use ( &$repopulated ): void {
            if ( $repopulated || 'sentient_forms_settings' !== $option_name )
            {
                return;
            }
            $repopulated = true;
            update_option( 'sentient_forms_action_defaults_external_race', [ 'model_override' => 'openrouter/auto' ], false );
        };
        add_action( 'updated_option', $repopulate_after_final_option_delete, 10, 1 );
        try
        {
            $failure = $this->dispatch_json(
                'POST',
                '/sentient-forms/v1/local/migration/approved-reset',
                [
                    'confirmation_phrase' => Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                ],
                500
            );
        }
        finally
        {
            remove_action( 'updated_option', $repopulate_after_final_option_delete, 10 );
        }

        $this->assertTrue( $repopulated );
        $this->assertSame( 'sentient_forms_local_cutover_postcondition_failed', $failure['code'] ?? null );
        $this->assertIsArray( get_option( 'sentient_forms_action_defaults_external_race', false ) );
        $run_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM %i ORDER BY id DESC LIMIT 1',
                $wpdb->prefix . 'sentient_migration_runs'
            )
        );
        $run = ( new Sentient_Forms_Migration_Runs_Repository( $wpdb ) )->get( $run_id );
        $this->assertSame( 'failed', $run['status'] ?? null );
        $this->assertSame( 'sentient_forms_local_cutover_postcondition_failed', $run['summary_json']['error_code'] ?? null );
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

    public function test_execute_form_mapping_drops_caller_supplied_policy_attestations(): void
    {
        $service = new Sentient_Forms_Test_Local_Action_Execution_Service(
            [
                'execution_request_id' => 'local-request-policy-boundary',
                'status'               => 'succeeded',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'result'               => [],
            ]
        );
        $controller = new Sentient_Forms_Local_Workspace_Controller( null, null, null, null, $service );
        $request    = new WP_REST_Request( 'POST', '/sentient-forms/v1/local/form-mappings/42/execute-test' );
        $request->set_url_params( [ 'id' => 42 ] );
        $request->set_body_params(
            [
                'form'    => [ 'id' => 7, 'title' => 'Contact' ],
                'entry'   => [ 'id' => 99, '1' => 'Ada' ],
                'context' => [
                    'hook'                         => 'gform_after_submission',
                    'form_source_capabilities'     => [ 'realtime_qna_storage' ],
                    'secondary_preflight_complete' => true,
                ],
            ]
        );

        $response = $controller->execute_form_mapping( $request );

        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $this->assertCount( 1, $service->calls );
        $this->assertSame( 'gform_after_submission', $service->calls[0]['context']['hook'] );
        $this->assertArrayNotHasKey( 'form_source_capabilities', $service->calls[0]['context'] );
        $this->assertArrayNotHasKey( 'secondary_preflight_complete', $service->calls[0]['context'] );
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
        $this->assertSame( $expected_status, $response->get_status(), wp_json_encode( $response->get_data() ) );

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
        $action_option_names = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s',
                $wpdb->options,
                $wpdb->esc_like( 'sentient_forms_actions_' ) . '%'
            )
        );
        foreach ( is_array( $action_option_names ) ? $action_option_names : [] as $action_option_name )
        {
            if ( is_string( $action_option_name ) )
            {
                delete_option( $action_option_name );
            }
        }
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
