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

        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id IN (%s, %s, %s)', $wpdb->prefix . 'sentient_form_mappings', '212', '214', '302:formabc' ) );
        $wpdb->delete( $wpdb->prefix . 'sentient_submission_ledger_settings', [ 'form_id' => '302:formabc' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_submission_ledger', [ 'submission_uuid' => '22222222-2222-4222-8222-222222222222' ], [ '%s' ] );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id = %s', $wpdb->prefix . 'sentient_submission_ledger', 'native-correlation-upgrade' ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id = %s', $wpdb->prefix . 'sentient_submission_ledger', 'native-correlation-retry' ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id = %s', $wpdb->prefix . 'sentient_submission_ledger', 'native-correlation-batched' ) );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE form_id = %s', $wpdb->prefix . 'sentient_submission_ledger', 'native-correlation-overlap' ) );
        delete_option( 'sentient_forms_native_correlation_cursor' );
        delete_option( 'sentient_forms_native_correlation_backfill_version' );
        $wpdb->delete( $wpdb->prefix . 'sentient_execution_events', [ 'execution_request_id' => 'elementor-migration-event' ], [ '%s' ] );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE execution_request_id IN (%s, %s)', $wpdb->prefix . 'sentient_execution_events', 'native-correlation-event-one', 'native-correlation-event-two' ) );
        $wpdb->delete( $wpdb->prefix . 'sentient_lead_profiles', [ 'form_id' => '302:formabc' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_historical_analysis_runs', [ 'form_id' => '302:formabc' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_lead_scoring_results', [ 'execution_request_id' => 'elementor-migration-score' ], [ '%s' ] );
        $wpdb->delete( $wpdb->prefix . 'sentient_async_requests', [ 'request_hash' => hash( 'sha256', 'elementor-migration-request' ) ], [ '%s' ] );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE code IN (%s, %s)', $wpdb->prefix . 'sentient_custom_actions', 'migration_custom_action', 'migration_retry_custom_action' ) );

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

    public function test_option_backed_elementor_config_migrates_legacy_new_record_hook(): void
    {
        $legacy_key          = 'sentient_forms_actions_elementor_forms_301_legacyhook';
        $canonical_key       = 'sentient_forms_actions_elementor_pro_forms_301_legacyhook';
        $this->option_keys[] = $legacy_key;
        $this->option_keys[] = $canonical_key;

        update_option(
            $legacy_key,
            [
                'map_summary' => [
                    'local_mapping_id' => 'map_summary',
                    'central_action_id' => 'entry_summary_v1',
                    'trigger_hooks'     => [ 'elementor_pro_forms_new_record' ],
                    'settings'          => [
                        'trigger_hooks'   => [ 'elementor_pro_forms_new_record' ],
                        'trigger_sources' => [
                            'elementor_pro_forms_new_record' => [ 'type' => 'hook_root' ],
                        ],
                    ],
                ],
            ],
            false
        );

        Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
        $stored = get_option( $canonical_key, [] );

        $this->assertFalse( get_option( $legacy_key, false ) );
        $this->assertSame( [ 'after_submission' ], $stored['map_summary']['trigger_hooks'] ?? null );
        $this->assertSame( [ 'after_submission' ], $stored['map_summary']['settings']['trigger_hooks'] ?? null );
        $this->assertSame(
            [ 'type' => 'hook_root' ],
            $stored['map_summary']['settings']['trigger_sources']['after_submission'] ?? null
        );
        $this->assertArrayNotHasKey( 'elementor_pro_forms_new_record', $stored['map_summary']['settings']['trigger_sources'] ?? [] );
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

    public function test_installer_migrates_elementor_action_log_identity_without_rewriting_nested_content(): void
    {
        $option_key          = 'sentient_forms_action_log';
        $this->option_keys[] = $option_key;

        update_option( 'sentient_forms_db_version', '2026.06.28.execution_event_identity', false );
        update_option(
            $option_key,
            [
                [
                    'id'          => 'legacy-elementor-log',
                    'form_source' => 'elementor_forms',
                    'details'     => [
                        'form_source' => 'elementor_forms',
                        'message'     => 'elementor_forms remains ordinary historical text here',
                    ],
                ],
                'malformed-entry-is-preserved',
            ],
            false
        );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $stored = get_option( $option_key, [] );
        $this->assertSame( 'elementor_pro_forms', $stored[0]['form_source'] ?? null );
        $this->assertSame( 'elementor_forms', $stored[0]['details']['form_source'] ?? null );
        $this->assertSame( 'elementor_forms remains ordinary historical text here', $stored[0]['details']['message'] ?? null );
        $this->assertSame( 'malformed-entry-is-preserved', $stored[1] ?? null );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_installer_moves_legacy_elementor_provider_disable_setting_to_canonical_key(): void
    {
        $option_key          = 'sentient_forms_plugin_settings';
        $this->option_keys[] = $option_key;

        update_option( 'sentient_forms_db_version', '2026.06.28.execution_event_identity', false );
        update_option(
            $option_key,
            [
                'execution_provider_disabled' => [
                    'gravity_forms'  => false,
                    'elementor_forms' => true,
                ],
                'unrelated' => [ 'elementor_forms' => 'ordinary nested value' ],
            ],
            false
        );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $stored       = get_option( $option_key, [] );
        $provider_map = $stored['execution_provider_disabled'] ?? [];
        $this->assertTrue( $provider_map['elementor_pro_forms'] ?? false );
        $this->assertArrayNotHasKey( 'elementor_forms', $provider_map );
        $this->assertFalse( $provider_map['gravity_forms'] ?? true );
        $this->assertSame( [ 'elementor_forms' => 'ordinary nested value' ], $stored['unrelated'] ?? null );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_installer_preserves_false_canonical_provider_disable_setting_on_legacy_collision(): void
    {
        $option_key          = 'sentient_forms_plugin_settings';
        $this->option_keys[] = $option_key;

        update_option( 'sentient_forms_db_version', '2026.06.28.execution_event_identity', false );
        update_option(
            $option_key,
            [
                'execution_provider_disabled' => [
                    'elementor_forms'     => true,
                    'elementor_pro_forms' => false,
                ],
            ],
            false
        );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $stored       = get_option( $option_key, [] );
        $provider_map = $stored['execution_provider_disabled'] ?? [];
        $this->assertArrayHasKey( 'elementor_pro_forms', $provider_map );
        $this->assertFalse( $provider_map['elementor_pro_forms'] );
        $this->assertArrayNotHasKey( 'elementor_forms', $provider_map );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_installer_retries_elementor_action_log_identity_after_transient_write_failure(): void
    {
        global $wpdb;

        $option_key          = 'sentient_forms_action_log';
        $this->option_keys[] = $option_key;
        $old_db_version      = '2026.06.28.execution_event_identity';

        update_option( 'sentient_forms_db_version', $old_db_version, false );
        update_option( $option_key, [ [ 'form_source' => 'elementor_forms' ] ], false );

        $forced_writes = 0;
        $fail_update   = static function ( string $query ) use ( $option_key, &$forced_writes ): string
        {
            if ( str_contains( $query, 'UPDATE' ) && str_contains( $query, $option_key ) )
            {
                ++$forced_writes;
                return 'SENTIENT FORMS FORCED ACTION LOG UPDATE FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_update );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_update );
        }

        $this->assertSame( 1, $forced_writes );
        $this->assertSame( $old_db_version, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( 'elementor_forms', get_option( $option_key )[0]['form_source'] ?? null );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $this->assertSame( 'elementor_pro_forms', get_option( $option_key )[0]['form_source'] ?? null );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_installer_retries_elementor_provider_disable_setting_after_transient_write_failure(): void
    {
        global $wpdb;

        $option_key          = 'sentient_forms_plugin_settings';
        $this->option_keys[] = $option_key;
        $old_db_version      = '2026.06.28.execution_event_identity';

        update_option( 'sentient_forms_db_version', $old_db_version, false );
        update_option(
            $option_key,
            [ 'execution_provider_disabled' => [ 'elementor_forms' => true ] ],
            false
        );

        $forced_writes = 0;
        $fail_update   = static function ( string $query ) use ( $option_key, &$forced_writes ): string
        {
            if ( str_contains( $query, 'UPDATE' ) && str_contains( $query, $option_key ) )
            {
                ++$forced_writes;
                return 'SENTIENT FORMS FORCED PLUGIN SETTINGS UPDATE FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_update );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_update );
        }

        $stored = get_option( $option_key, [] );
        $this->assertSame( 1, $forced_writes );
        $this->assertSame( $old_db_version, get_option( 'sentient_forms_db_version' ) );
        $this->assertTrue( $stored['execution_provider_disabled']['elementor_forms'] ?? false );
        $this->assertArrayNotHasKey( 'elementor_pro_forms', $stored['execution_provider_disabled'] ?? [] );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $stored = get_option( $option_key, [] );
        $this->assertTrue( $stored['execution_provider_disabled']['elementor_pro_forms'] ?? false );
        $this->assertArrayNotHasKey( 'elementor_forms', $stored['execution_provider_disabled'] ?? [] );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
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
        $this->assertSame( 7, $first['form_source_rows_updated'] ?? null );
        $this->assertSame( 0, $second['form_source_rows_updated'] ?? null );
        $this->assertSame( 0, $second['form_source_row_collisions'] ?? null );
    }

    public function test_installer_retries_elementor_option_rename_after_transient_write_failure(): void
    {
        global $wpdb;

        $legacy_key        = 'sentient_forms_actions_elementor_forms_304_formabc';
        $canonical_key     = 'sentient_forms_actions_elementor_pro_forms_304_formabc';
        $this->option_keys = array_merge( $this->option_keys, [ $legacy_key, $canonical_key ] );
        $old_db_version    = '2026.06.28.execution_event_identity';

        update_option( 'sentient_forms_db_version', $old_db_version, false );
        update_option( $legacy_key, [ 'source' => 'legacy' ], false );

        $fail_canonical_insert = static function ( string $query ) use ( $canonical_key ): string
        {
            if ( str_contains( $query, 'INSERT INTO' ) && str_contains( $query, $canonical_key ) )
            {
                return 'SENTIENT FORMS FORCED OPTION WRITE FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_canonical_insert );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_canonical_insert );
        }

        $this->assertSame( $old_db_version, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( [ 'source' => 'legacy' ], get_option( $legacy_key ) );
        $this->assertFalse( get_option( $canonical_key, false ) );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $this->assertFalse( get_option( $legacy_key, false ) );
        $this->assertSame( [ 'source' => 'legacy' ], get_option( $canonical_key ) );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_installer_backfills_one_canonical_native_correlation_without_rewriting_duplicate_evidence(): void
    {
        global $wpdb;

        $ledger_table = $wpdb->prefix . 'sentient_submission_ledger';
        $events_table = $wpdb->prefix . 'sentient_execution_events';
        $now          = current_time( 'mysql', true );
        $first_uuid   = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $second_uuid  = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        delete_option( 'sentient_forms_native_correlation_cursor' );
        delete_option( 'sentient_forms_native_correlation_backfill_version' );
        update_option( 'sentient_forms_db_version', '2026.07.10.elementor_pro_forms_identifier', false );

        foreach ( [ $first_uuid, $second_uuid ] as $submission_uuid )
        {
            $inserted = $wpdb->insert(
                $ledger_table,
                [
                    'submission_uuid'     => $submission_uuid,
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'native-correlation-upgrade',
                    'native_entry_id'     => 'legacy-duplicate-1',
                    'captured_at'         => $now,
                    'logical_fields_json' => '{}',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]
            );
            $this->assertSame( 1, $inserted );
        }

        foreach (
            [
                'native-correlation-event-one' => $first_uuid,
                'native-correlation-event-two' => $second_uuid,
            ] as $execution_request_id => $submission_uuid
        )
        {
            $inserted = $wpdb->insert(
                $events_table,
                [
                    'execution_request_id' => $execution_request_id,
                    'submission_uuid'      => $submission_uuid,
                    'provider'             => 'openrouter',
                    'status'               => 'succeeded',
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]
            );
            $this->assertSame( 1, $inserted );
        }

        Sentient_Forms_Installer::maybe_upgrade( false );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT submission_uuid, native_correlation_hash FROM %i WHERE form_id = %s ORDER BY id ASC',
                $ledger_table,
                'native-correlation-upgrade'
            ),
            ARRAY_A
        );
        $event_uuids = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT submission_uuid FROM %i WHERE execution_request_id IN (%s, %s) ORDER BY execution_request_id ASC',
                $events_table,
                'native-correlation-event-one',
                'native-correlation-event-two'
            )
        );

        $this->assertCount( 2, $rows, 'Historical duplicate ledger rows must remain intact.' );
        $this->assertSame( $first_uuid, $rows[0]['submission_uuid'] ?? null );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', (string) ( $rows[0]['native_correlation_hash'] ?? '' ) );
        $this->assertSame( $second_uuid, $rows[1]['submission_uuid'] ?? null );
        $this->assertNull( $rows[1]['native_correlation_hash'] ?? null );
        $this->assertSame( [ $first_uuid, $second_uuid ], $event_uuids );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_installer_retries_native_correlation_backfill_after_schema_version_advances(): void
    {
        global $wpdb;

        $ledger_table   = $wpdb->prefix . 'sentient_submission_ledger';
        $old_db_version = '2026.07.10.elementor_pro_forms_identifier';
        $submission_uuid = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $now             = current_time( 'mysql', true );

        delete_option( 'sentient_forms_native_correlation_cursor' );
        delete_option( 'sentient_forms_native_correlation_backfill_version' );
        update_option( 'sentient_forms_db_version', $old_db_version, false );
        $inserted = $wpdb->insert(
            $ledger_table,
            [
                'submission_uuid'     => $submission_uuid,
                'form_source'         => 'gravity_forms',
                'form_id'             => 'native-correlation-retry',
                'native_entry_id'     => 'legacy-retry-1',
                'captured_at'         => $now,
                'logical_fields_json' => '{}',
                'created_at'          => $now,
                'updated_at'          => $now,
            ]
        );
        $this->assertSame( 1, $inserted );

        $fail_backfill = static function ( string $query ) use ( $ledger_table ): string
        {
            if ( str_contains( $query, 'UPDATE `' . $ledger_table . '` SET native_correlation_hash' ) )
            {
                return 'SENTIENT FORMS FORCED NATIVE CORRELATION FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_backfill );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_backfill );
        }

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertNull(
            $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT native_correlation_hash FROM %i WHERE submission_uuid = %s',
                    $ledger_table,
                    $submission_uuid
                )
            )
        );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT native_correlation_hash FROM %i WHERE submission_uuid = %s',
                    $ledger_table,
                    $submission_uuid
                )
            )
        );
    }

    public function test_installer_resumes_native_correlation_backfill_from_a_bounded_cursor(): void
    {
        global $wpdb;

        $ledger_table   = $wpdb->prefix . 'sentient_submission_ledger';
        $old_db_version = '2026.07.10.elementor_pro_forms_identifier';
        $now            = current_time( 'mysql', true );

        update_option( 'sentient_forms_db_version', $old_db_version, false );
        delete_option( 'sentient_forms_native_correlation_cursor' );
        delete_option( 'sentient_forms_native_correlation_backfill_version' );

        for ( $index = 1; $index <= 51; ++$index )
        {
            $inserted = $wpdb->insert(
                $ledger_table,
                [
                    'submission_uuid'     => sprintf( 'dddddddd-dddd-4ddd-8ddd-%012d', $index ),
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'native-correlation-batched',
                    'native_entry_id'     => 'batched-entry-' . $index,
                    'captured_at'         => $now,
                    'logical_fields_json' => '{}',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]
            );
            $this->assertSame( 1, $inserted );
        }

        Sentient_Forms_Installer::maybe_upgrade( false );

        $first_batch_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE form_id = %s AND native_correlation_hash IS NOT NULL',
                $ledger_table,
                'native-correlation-batched'
            )
        );
        $cursor = (int) get_option( 'sentient_forms_native_correlation_cursor', 0 );

        $this->assertSame( 50, $first_batch_count );
        $this->assertGreaterThan( 0, $cursor );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertFalse( get_option( 'sentient_forms_native_correlation_backfill_version', false ) );

        $repeated_schema_or_config_queries = 0;
        $track_repeated_migrations = static function ( string $query ) use ( $wpdb, &$repeated_schema_or_config_queries ): string
        {
            $is_schema_repeat = str_contains( $query, 'CREATE TABLE' ) && str_contains( $query, 'sentient_' );
            $is_config_repeat = str_contains( $query, 'SELECT option_name' ) && str_contains( $query, $wpdb->options );
            if ( $is_schema_repeat || $is_config_repeat )
            {
                ++$repeated_schema_or_config_queries;
            }

            return $query;
        };
        add_filter( 'query', $track_repeated_migrations );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            remove_filter( 'query', $track_repeated_migrations );
        }

        $this->assertSame( 0, $repeated_schema_or_config_queries );
        $this->assertSame(
            51,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE form_id = %s AND native_correlation_hash IS NOT NULL',
                    $ledger_table,
                    'native-correlation-batched'
                )
            )
        );
        $this->assertFalse( get_option( 'sentient_forms_native_correlation_cursor', false ) );
        $this->assertSame( 'v1', get_option( 'sentient_forms_native_correlation_backfill_version' ) );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_completed_native_correlation_backfill_removes_a_stale_overlapping_cursor(): void
    {
        global $wpdb;

        $ledger_table = $wpdb->prefix . 'sentient_submission_ledger';
        $now          = current_time( 'mysql', true );

        update_option( 'sentient_forms_db_version', '2026.07.10.elementor_pro_forms_identifier', false );
        delete_option( 'sentient_forms_native_correlation_cursor' );
        delete_option( 'sentient_forms_native_correlation_backfill_version' );

        for ( $index = 1; $index <= 51; ++$index )
        {
            $inserted = $wpdb->insert(
                $ledger_table,
                [
                    'submission_uuid'     => sprintf( 'eeeeeeee-eeee-4eee-8eee-%012d', $index ),
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'native-correlation-overlap',
                    'native_entry_id'     => 'overlap-entry-' . $index,
                    'captured_at'         => $now,
                    'logical_fields_json' => '{}',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]
            );
            $this->assertSame( 1, $inserted );
        }

        $complete_overlapping_request = static function ( mixed $value ) use ( $wpdb, $ledger_table ): mixed
        {
            $remaining = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT id, form_source, form_id, native_entry_id FROM %i WHERE form_id = %s AND native_correlation_hash IS NULL ORDER BY id ASC LIMIT 1',
                    $ledger_table,
                    'native-correlation-overlap'
                ),
                ARRAY_A
            );
            if ( is_array( $remaining ) )
            {
                $hash = Sentient_Forms_Submission_Ledger_Repository::native_correlation_hash(
                    (string) $remaining['form_source'],
                    (string) $remaining['form_id'],
                    (string) $remaining['native_entry_id']
                );
                $repository = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
                $repository->assign_native_correlation_hash( (int) $remaining['id'], (string) $hash );
            }
            update_option( 'sentient_forms_native_correlation_backfill_version', 'v1', false );

            return $value;
        };
        add_filter( 'pre_update_option_sentient_forms_native_correlation_cursor', $complete_overlapping_request );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            remove_filter( 'pre_update_option_sentient_forms_native_correlation_cursor', $complete_overlapping_request );
        }

        $this->assertSame(
            51,
            (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE form_id = %s AND native_correlation_hash IS NOT NULL',
                    $ledger_table,
                    'native-correlation-overlap'
                )
            )
        );
        $this->assertSame( 'v1', get_option( 'sentient_forms_native_correlation_backfill_version' ) );
        $this->assertFalse( get_option( 'sentient_forms_native_correlation_cursor', false ) );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_installer_retries_after_transient_option_discovery_failures(): void
    {
        global $wpdb;

        $action_key        = 'sentient_forms_actions_gravity_forms_215';
        $legacy_key        = 'sentient_forms_actions_elementor_forms_306_formabc';
        $canonical_key     = 'sentient_forms_actions_elementor_pro_forms_306_formabc';
        $this->option_keys = array_merge( $this->option_keys, [ $action_key, $legacy_key, $canonical_key ] );
        $old_db_version    = '2026.06.28.execution_event_identity';

        update_option( 'sentient_forms_db_version', $old_db_version, false );
        update_option(
            $action_key,
            [
                'mapping' => [
                    'trigger_hooks' => [ 'gform_after_submission' ],
                ],
            ],
            false
        );
        update_option( $legacy_key, [ 'source' => 'legacy' ], false );

        $forced_discovery_failures = 0;
        $fail_option_discovery     = static function ( string $query ) use ( $wpdb, &$forced_discovery_failures ): string
        {
            if ( str_contains( $query, 'SELECT option_name' )
                && str_contains( $query, $wpdb->options ) )
            {
                ++$forced_discovery_failures;
                return 'SENTIENT FORMS FORCED OPTION DISCOVERY FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_option_discovery );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_option_discovery );
        }

        $this->assertGreaterThanOrEqual( 3, $forced_discovery_failures );
        $this->assertSame( $old_db_version, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( [ 'gform_after_submission' ], get_option( $action_key )['mapping']['trigger_hooks'] ?? null );
        $this->assertSame( [ 'source' => 'legacy' ], get_option( $legacy_key ) );
        $this->assertFalse( get_option( $canonical_key, false ) );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( [ 'after_submission' ], get_option( $action_key )['mapping']['trigger_hooks'] ?? null );
        $this->assertFalse( get_option( $legacy_key, false ) );
        $this->assertSame( [ 'source' => 'legacy' ], get_option( $canonical_key ) );
    }

    public function test_installer_retries_false_valued_legacy_option_deletion_failure(): void
    {
        global $wpdb;

        $legacy_key        = 'sentient_forms_actions_elementor_forms_307_formabc';
        $canonical_key     = 'sentient_forms_actions_elementor_pro_forms_307_formabc';
        $this->option_keys = array_merge( $this->option_keys, [ $legacy_key, $canonical_key ] );
        $old_db_version    = '2026.06.28.execution_event_identity';

        update_option( 'sentient_forms_db_version', $old_db_version, false );
        add_option( $legacy_key, false, '', false );
        add_option( $canonical_key, [ 'source' => 'canonical' ], '', false );

        $forced_delete = 0;
        $fail_delete   = static function ( string $query ) use ( $legacy_key, &$forced_delete ): string
        {
            if ( str_contains( $query, 'DELETE FROM' ) && str_contains( $query, $legacy_key ) )
            {
                ++$forced_delete;
                return 'SENTIENT FORMS FORCED OPTION DELETE FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_delete );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_delete );
        }

        $this->assertSame( 1, $forced_delete );
        $this->assertSame( $old_db_version, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame(
            '1',
            (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, $legacy_key ) )
        );
        $this->assertSame( [ 'source' => 'canonical' ], get_option( $canonical_key ) );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame(
            '0',
            (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->options, $legacy_key ) )
        );
        $this->assertSame( [ 'source' => 'canonical' ], get_option( $canonical_key ) );
    }

    public function test_installer_retries_lifecycle_mapping_after_transient_write_failure(): void
    {
        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $old_db_version = '2026.06.28.execution_event_identity';

        $action_id = $custom_actions->create(
            [
                'code'            => 'migration_retry_custom_action',
                'display_name'    => 'Migration Retry Custom Action',
                'definition_json' => [ 'prompt_template' => 'Retry {{entry}}.' ],
                'status'          => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'sentient_form_mappings',
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '214',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => '{}',
                'execution_mode'      => 'async',
                'settings_json'       => '{}',
                'enabled'             => 1,
                'created_at'          => current_time( 'mysql', true ),
                'updated_at'          => current_time( 'mysql', true ),
            ]
        );
        $this->assertSame( 1, $inserted );
        $mapping_id = (int) $wpdb->insert_id;
        $this->assertSame(
            'gform_after_submission',
            $wpdb->get_var( $wpdb->prepare( 'SELECT hook FROM %i WHERE id = %d', $wpdb->prefix . 'sentient_form_mappings', $mapping_id ) )
        );
        update_option( 'sentient_forms_db_version', $old_db_version, false );

        $forced_failures         = 0;
        $observed_mapping_queries = [];
        $fail_mapping_update     = static function ( string $query ) use ( $wpdb, &$forced_failures, &$observed_mapping_queries ): string
        {
            if ( str_contains( $query, $wpdb->prefix . 'sentient_form_mappings' ) )
            {
                $observed_mapping_queries[] = $query;
            }

            if ( str_contains( $query, 'UPDATE' )
                && str_contains( $query, $wpdb->prefix . 'sentient_form_mappings' )
                && str_contains( $query, 'after_submission' ) )
            {
                ++$forced_failures;
                return 'SENTIENT FORMS FORCED MAPPING WRITE FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_mapping_update );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade( false );
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_mapping_update );
        }

        $this->assertSame( 1, $forced_failures, implode( "\n", $observed_mapping_queries ) );
        $this->assertSame( $old_db_version, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame(
            'gform_after_submission',
            $wpdb->get_var( $wpdb->prepare( 'SELECT hook FROM %i WHERE id = %d', $wpdb->prefix . 'sentient_form_mappings', $mapping_id ) )
        );

        Sentient_Forms_Installer::maybe_upgrade( false );

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame(
            'after_submission',
            $wpdb->get_var( $wpdb->prepare( 'SELECT hook FROM %i WHERE id = %d', $wpdb->prefix . 'sentient_form_mappings', $mapping_id ) )
        );
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

    public function test_elementor_async_metadata_migrates_queued_identity_fields_only(): void
    {
        $option_key          = 'sentient_forms_async_jobs';
        $this->option_keys[] = $option_key;

        update_option(
            $option_key,
            [
                'legacy-job' => [
                    'context' => [
                        'form_source' => 'elementor_forms',
                        'adapter_id'  => 'elementor_forms',
                    ],
                    'payload' => [
                        'form_source' => 'elementor_forms',
                        'adapter_id'  => 'elementor_forms',
                        'context'     => [
                            'form_source' => 'elementor_forms',
                            'adapter_id'  => 'elementor_forms',
                        ],
                        'data'        => [
                            'form_source' => 'elementor_forms',
                            'form'        => [
                                'form_source' => 'elementor_forms',
                                'adapter_id'  => 'elementor_forms',
                            ],
                            'entry'       => [
                                'form_source' => 'elementor_forms',
                                'adapter_id'  => 'elementor_forms',
                                'nested'      => [ 'form_source' => 'elementor_forms' ],
                            ],
                        ],
                        'message'     => 'elementor_forms is not an identity here',
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
        $stored  = get_option( $option_key, [] );

        $this->assertSame( 1, $summary['async_metadata_jobs_updated'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['context']['form_source'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['context']['adapter_id'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['payload']['form_source'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['payload']['adapter_id'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['payload']['context']['form_source'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['payload']['context']['adapter_id'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['payload']['data']['form_source'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['payload']['data']['form']['form_source'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $stored['legacy-job']['payload']['data']['form']['adapter_id'] ?? null );
        $this->assertSame( 'elementor_forms', $stored['legacy-job']['payload']['data']['entry']['form_source'] ?? null );
        $this->assertSame( 'elementor_forms', $stored['legacy-job']['payload']['data']['entry']['adapter_id'] ?? null );
        $this->assertSame( 'elementor_forms', $stored['legacy-job']['payload']['data']['entry']['nested']['form_source'] ?? null );
        $this->assertSame( 'elementor_forms is not an identity here', $stored['legacy-job']['payload']['message'] ?? null );
    }

    public function test_migration_summary_reports_failed_mapping_discovery(): void
    {
        global $wpdb;

        $fail_mapping_discovery = static function ( string $query ) use ( $wpdb ): string
        {
            if ( str_contains( $query, 'SELECT id, hook, settings_json' )
                && str_contains( $query, $wpdb->prefix . 'sentient_form_mappings' ) )
            {
                return 'SENTIENT FORMS FORCED MAPPING DISCOVERY FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_mapping_discovery );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            $summary = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_mapping_discovery );
        }

        $this->assertSame( 1, $summary['mapping_row_failures'] ?? null );
        $this->assertSame( 0, $summary['migration_complete'] ?? null );
    }

    public function test_migration_summary_reports_failed_form_source_storage_update(): void
    {
        global $wpdb;

        $form_id = '302:formabc';
        $this->insert_legacy_elementor_rows( $form_id, current_time( 'mysql', true ) );

        $fail_execution_event_update = static function ( string $query ) use ( $wpdb ): string
        {
            if ( str_contains( $query, 'UPDATE' )
                && str_contains( $query, $wpdb->prefix . 'sentient_execution_events' )
                && str_contains( $query, 'elementor_pro_forms' ) )
            {
                return 'SENTIENT FORMS FORCED STORAGE WRITE FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_execution_event_update );
        $suppress_errors = $wpdb->suppress_errors( true );
        try
        {
            $summary = Sentient_Forms_Form_Source_Config_Migrator::migrate_active_configuration();
        }
        finally
        {
            $wpdb->suppress_errors( $suppress_errors );
            remove_filter( 'query', $fail_execution_event_update );
        }

        $this->assertSame( 1, $summary['form_source_storage_failures'] ?? null );
        $this->assertSame( 1, $summary['migration_failures'] ?? null );
        $this->assertSame( 0, $summary['migration_complete'] ?? null );
        $this->assertSame(
            '1',
            (string) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE execution_request_id = %s AND form_source = %s',
                    $wpdb->prefix . 'sentient_execution_events',
                    'elementor-migration-event',
                    'elementor_forms'
                )
            )
        );
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
