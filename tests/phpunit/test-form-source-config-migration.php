<?php

class Tests_Form_Source_Config_Migration extends WP_UnitTestCase
{
    /**
     * @var array<int, string>
     */
    private array $option_keys = [];

    private mixed $original_db_version = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->original_db_version = get_option( 'sentient_forms_db_version', null );
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Installer dbDelta calls can commit the test transaction. Roll back
        // WordPress fixtures first, then remove migration fixtures durably.
        parent::tearDown();

        foreach ( $this->option_keys as $option_key )
        {
            delete_option( $option_key );
        }

        if ( null === $this->original_db_version )
        {
            delete_option( 'sentient_forms_db_version' );
        }
        else
        {
            update_option( 'sentient_forms_db_version', $this->original_db_version, false );
        }

        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id IN (%s, %s)', $wpdb->prefix . 'sentient_form_mappings', '212', '302:formabc' ) );
        $wpdb->delete( $wpdb->prefix . 'sentient_submission_ledger_settings', [ 'form_id' => '302:formabc' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_submission_ledger', [ 'submission_uuid' => '22222222-2222-4222-8222-222222222222' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_execution_events', [ 'execution_request_id' => 'elementor-migration-event' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_lead_profiles', [ 'form_id' => '302:formabc' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_historical_analysis_runs', [ 'form_id' => '302:formabc' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_lead_scoring_results', [ 'execution_request_id' => 'elementor-migration-score' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_async_requests', [ 'request_hash' => hash( 'sha256', 'elementor-migration-request' ) ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_custom_actions', [ 'code' => 'migration_custom_action' ], [ '%s' ] );

    }

    public function test_option_backed_active_config_migrates_legacy_gravity_hooks_to_canonical_lifecycle_ids(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_211';
        $this->option_keys[] = $option_key;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam'    => [
                    'local_mapping_id' => 'map_spam',
                    'central_action_id' => 'spam_detection_v1',
                    'trigger_hooks'     => [ 'gform_validation', 'gform_after_submission', 'real_time' ],
                    'settings'          => [
                        'trigger_hooks'   => [ 'gform_validation' ],
                        'trigger_sources' => [
                            'gform_validation'       => [ 'type' => 'hook_root' ],
                            'gform_after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'map_parent',
                            ],
                        ],
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
        $stored  = get_option( $option_key, [] );

        $this->assertSame( 1, $summary['options_updated'] ?? null );
        $this->assertSame(
            [ 'validation', 'after_submission', 'real_time' ],
            $stored['map_spam']['trigger_hooks'] ?? null
        );
        $this->assertSame( [ 'validation' ], $stored['map_spam']['settings']['trigger_hooks'] ?? null );
        $this->assertSame(
            [ 'type' => 'hook_root' ],
            $stored['map_spam']['settings']['trigger_sources']['validation'] ?? null
        );
        $this->assertSame(
            [ 'type' => 'mapping', 'mapping_id' => 'map_parent' ],
            $stored['map_spam']['settings']['trigger_sources']['after_submission'] ?? null
        );
        $this->assertArrayNotHasKey( 'gform_validation', $stored['map_spam']['settings']['trigger_sources'] ?? [] );
        $this->assertArrayNotHasKey( 'gform_after_submission', $stored['map_spam']['settings']['trigger_sources'] ?? [] );
    }

    public function test_custom_table_active_config_migrates_legacy_gravity_hooks_to_canonical_lifecycle_ids(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'            => 'migration_custom_action',
                'display_name'    => 'Migration Custom Action',
                'definition_json' => [ 'prompt_template' => 'Migrate {{entry}}.' ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '212',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'settings_json'       => [
                    'trigger_hooks'   => [ 'gform_after_submission' ],
                    'trigger_sources' => [
                        'gform_after_submission' => [
                            'type'       => 'mapping',
                            'mapping_id' => 'map_parent',
                        ],
                    ],
                ],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $summary = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
        $stored  = $mappings->get( $mapping_id );

        $this->assertSame( 1, $summary['mapping_rows_updated'] ?? null );
        $this->assertSame( 'after_submission', $stored['hook'] ?? null );
        $this->assertSame( [ 'after_submission' ], $stored['settings_json']['trigger_hooks'] ?? null );
        $this->assertSame(
            [ 'type' => 'mapping', 'mapping_id' => 'map_parent' ],
            $stored['settings_json']['trigger_sources']['after_submission'] ?? null
        );
        $this->assertArrayNotHasKey( 'gform_after_submission', $stored['settings_json']['trigger_sources'] ?? [] );
    }

    public function test_installer_upgrade_migrates_active_lifecycle_configuration(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_213';
        $this->option_keys[] = $option_key;

        update_option( 'sentient_forms_db_version', '2026.05.30.admin_performance_indexes', false );
        update_option(
            $option_key,
            [
                'map_summary' => [
                    'local_mapping_id' => 'map_summary',
                    'central_action_id' => 'entry_summary_v1',
                    'trigger_hooks'     => [ 'gform_after_submission' ],
                    'settings'          => [
                        'trigger_sources' => [
                            'gform_after_submission' => [ 'type' => 'hook_root' ],
                        ],
                    ],
                ],
            ],
            false
        );

        Sentient_Forms_Installer::maybe_upgrade( true );
        $stored = get_option( $option_key, [] );

        $this->assertSame( [ 'after_submission' ], $stored['map_summary']['trigger_hooks'] ?? null );
        $this->assertSame(
            [ 'type' => 'hook_root' ],
            $stored['map_summary']['settings']['trigger_sources']['after_submission'] ?? null
        );
        $this->assertArrayNotHasKey( 'gform_after_submission', $stored['map_summary']['settings']['trigger_sources'] ?? [] );
    }

    public function test_installer_normal_boot_does_not_repeat_active_config_migration(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_214';
        $this->option_keys[] = $option_key;

        update_option( 'sentient_forms_db_version', SENTIENT_FORMS_DB_VERSION, false );
        update_option(
            $option_key,
            [
                'map_summary' => [
                    'local_mapping_id' => 'map_summary',
                    'central_action_id' => 'entry_summary_v1',
                    'trigger_hooks'     => [ 'gform_after_submission' ],
                    'settings'          => [
                        'trigger_sources' => [
                            'gform_after_submission' => [ 'type' => 'hook_root' ],
                        ],
                    ],
                ],
            ],
            false
        );

        Sentient_Forms_Installer::maybe_upgrade( false );
        $stored = get_option( $option_key, [] );

        $this->assertSame( [ 'gform_after_submission' ], $stored['map_summary']['trigger_hooks'] ?? null );
        $this->assertSame(
            [ 'type' => 'hook_root' ],
            $stored['map_summary']['settings']['trigger_sources']['gform_after_submission'] ?? null
        );
        $this->assertArrayNotHasKey( 'after_submission', $stored['map_summary']['settings']['trigger_sources'] ?? [] );
    }

    public function test_elementor_option_keys_move_forward_without_overwriting_canonical_data(): void
    {
        $legacy_action_key    = 'sentient_forms_actions_elementor_forms_301_formabc';
        $canonical_action_key = 'sentient_forms_actions_elementor_pro_forms_301_formabc';
        $legacy_config_key    = 'sentient_forms_form_config_elementor_forms_301_formabc';
        $canonical_config_key = 'sentient_forms_form_config_elementor_pro_forms_301_formabc';
        $this->option_keys    = array_merge(
            $this->option_keys,
            [ $legacy_action_key, $canonical_action_key, $legacy_config_key, $canonical_config_key ]
        );

        update_option( $legacy_action_key, [ 'source' => 'legacy-action' ], false );
        update_option( $canonical_action_key, [ 'source' => 'canonical-action' ], false );
        update_option( $legacy_config_key, [ 'source' => 'legacy-config' ], false );

        $summary = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();

        $this->assertFalse( get_option( $legacy_action_key, false ) );
        $this->assertFalse( get_option( $legacy_config_key, false ) );
        $this->assertSame( [ 'source' => 'canonical-action' ], get_option( $canonical_action_key ) );
        $this->assertSame( [ 'source' => 'legacy-config' ], get_option( $canonical_config_key ) );
        $this->assertSame( 1, $summary['form_source_options_renamed'] ?? null );
        $this->assertSame( 1, $summary['form_source_option_collisions'] ?? null );
    }

    public function test_elementor_form_source_rows_migrate_across_every_owned_table_and_are_idempotent(): void
    {
        global $wpdb;

        Sentient_Forms_Installer::maybe_upgrade( true );
        $form_id = '302:formabc';
        $now     = current_time( 'mysql', true );

        $this->insert_legacy_elementor_rows( $form_id, $now );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_submission_ledger_settings',
            [
                'form_source' => 'elementor_pro_forms',
                'form_id'     => $form_id,
                'enabled'     => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );

        $first  = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
        $second = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();

        $form_source_tables = [];
        foreach ( Sentient_Forms_Local_Data_Governance::local_table_suffixes() as $table_suffix )
        {
            $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $wpdb->prefix . $table_suffix ) );
            if ( in_array( 'form_source', $columns, true ) )
            {
                $form_source_tables[] = $table_suffix;
            }
        }
        sort( $form_source_tables );
        $expected_form_source_tables = [
            'sentient_form_mappings',
            'sentient_execution_events',
            'sentient_historical_analysis_runs',
            'sentient_lead_profiles',
            'sentient_lead_scoring_results',
            'sentient_submission_ledger',
            'sentient_submission_ledger_settings',
        ];
        sort( $expected_form_source_tables );
        $this->assertSame( $expected_form_source_tables, $form_source_tables, 'Update the canonical Form Source migration when plugin-owned table storage changes.' );

        foreach ( $form_source_tables as $table_suffix )
        {
            $table = $wpdb->prefix . $table_suffix;
            $this->assertSame(
                '0',
                (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_source = %s AND form_id = %s', $table, 'elementor_forms', $form_id ) ),
                $table_suffix . ' retains legacy rows.'
            );
            $this->assertGreaterThanOrEqual(
                1,
                (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_source = %s AND form_id = %s', $table, 'elementor_pro_forms', $form_id ) ),
                $table_suffix . ' is missing canonical rows.'
            );
        }

        $this->assertSame(
            '0',
            (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE adapter = %s', $wpdb->prefix . 'sentient_async_requests', 'elementor_forms' ) )
        );
        $this->assertSame(
            '1',
            (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE adapter = %s', $wpdb->prefix . 'sentient_async_requests', 'elementor_pro_forms' ) )
        );
        $this->assertSame( 1, $first['form_source_row_collisions'] ?? null );
        $this->assertGreaterThanOrEqual( 8, $first['form_source_rows_updated'] ?? 0 );
        $this->assertSame( 0, $second['form_source_rows_updated'] ?? null );
        $this->assertSame( 0, $second['form_source_row_collisions'] ?? null );
    }

    public function test_installer_upgrade_runs_elementor_identifier_migration_once(): void
    {
        $legacy_key       = 'sentient_forms_actions_elementor_forms_303_formabc';
        $canonical_key    = 'sentient_forms_actions_elementor_pro_forms_303_formabc';
        $this->option_keys = array_merge( $this->option_keys, [ $legacy_key, $canonical_key ] );

        update_option( 'sentient_forms_db_version', '2026.06.28.execution_event_identity', false );
        update_option( $legacy_key, [ 'source' => 'legacy' ], false );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $this->assertFalse( get_option( $legacy_key, false ) );
        $this->assertSame( [ 'source' => 'legacy' ], get_option( $canonical_key ) );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    private function insert_legacy_elementor_rows( string $form_id, string $now ): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'sentient_form_mappings',
            [
                'form_source'         => 'elementor_forms',
                'form_id'             => $form_id,
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => 1,
                'input_bindings_json' => '{}',
                'execution_mode'      => 'async',
                'created_at'          => $now,
                'updated_at'          => $now,
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_submission_ledger_settings',
            [
                'form_source' => 'elementor_forms',
                'form_id'     => $form_id,
                'enabled'     => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_submission_ledger',
            [
                'submission_uuid'    => '22222222-2222-4222-8222-222222222222',
                'form_source'        => 'elementor_forms',
                'form_id'            => $form_id,
                'captured_at'        => $now,
                'logical_fields_json' => '{}',
                'created_at'         => $now,
                'updated_at'         => $now,
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_execution_events',
            [
                'execution_request_id' => 'elementor-migration-event',
                'form_source'          => 'elementor_forms',
                'form_id'              => $form_id,
                'provider'             => 'direct',
                'status'               => 'succeeded',
                'created_at'           => $now,
                'updated_at'           => $now,
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_lead_profiles',
            [
                'form_source' => 'elementor_forms',
                'form_id'     => $form_id,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_historical_analysis_runs',
            [
                'form_source' => 'elementor_forms',
                'form_id'     => $form_id,
                'action_code' => 'lead_scoring_v1',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_lead_scoring_results',
            [
                'form_source'         => 'elementor_forms',
                'form_id'             => $form_id,
                'entry_id'            => 'migration-entry',
                'action_code'         => 'lead_scoring_v1',
                'execution_request_id' => 'elementor-migration-score',
                'created_at'          => $now,
                'updated_at'          => $now,
            ]
        );
        $wpdb->insert(
            $wpdb->prefix . 'sentient_async_requests',
            [
                'request_hash'  => hash( 'sha256', 'elementor-migration-request' ),
                'action_id'     => 'lead_scoring_v1',
                'adapter'       => 'elementor_forms',
                'record_type'   => 'job',
                'status'        => 'queued',
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]
        );
    }
}
