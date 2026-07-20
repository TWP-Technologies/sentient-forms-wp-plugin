<?php

class Tests_Legacy_Action_Authority_Migration extends WP_UnitTestCase
{
    /** @var array<int, string> */
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

        remove_all_filters( 'sentient_forms_action_authority_lock_database' );
        remove_all_filters( 'sentient_forms_action_authority_writer_lock_timeout' );
        parent::tearDown();

        foreach ( $this->option_keys as $option_key )
        {
            delete_option( $option_key );
        }

        delete_option( 'sentient_forms_action_authority_migration_journal' );
        delete_option( 'sentient_forms_action_authority_migration_lock' );
        if ( null === $this->original_db_version )
        {
            delete_option( 'sentient_forms_db_version' );
        }
        else
        {
            update_option( 'sentient_forms_db_version', $this->original_db_version, false );
        }
        foreach (
            [
                [ 'gravity_forms', '9916' ],
                [ 'gravity_forms', '9917' ],
                [ 'gravity_forms', '9918' ],
                [ 'gravity_forms', '9919' ],
                [ 'gravity_forms', '9920' ],
                [ 'gravity_forms', '9921' ],
                [ 'contact_form_7', '9922' ],
                [ 'wpforms', '9923' ],
                [ 'gravity_forms', '9924' ],
                [ 'gravity_forms', '9925' ],
                [ 'gravity_forms', '9926' ],
                [ 'gravity_forms', '9927' ],
                [ 'gravity_forms', '9928' ],
                [ 'gravity_forms', '9929' ],
                [ 'gravity_forms', '9930' ],
                [ 'gravity_forms', '9931' ],
                [ 'gravity_forms', '9932' ],
                [ 'gravity_forms', '9933' ],
                [ 'gravity_forms', '9934' ],
                [ 'gravity_forms', '9935' ],
                [ 'gravity_forms', '9936' ],
                [ 'gravity_forms', '9937' ],
                [ 'gravity_forms', '9938' ],
                [ 'gravity_forms', '9939' ],
                [ 'gravity_forms', '9940' ],
                [ 'gravity_forms', '9942' ],
                [ 'gravity_forms', '9953' ],
                [ 'gravity_forms', '9954' ],
                [ 'gravity_forms', '9955' ],
                [ 'elementor_pro_forms', '321:opaque-form' ],
            ] as [ $form_source, $form_id ]
        )
        {
            $wpdb->delete(
                $wpdb->prefix . 'sentient_form_mappings',
                [ 'form_source' => $form_source, 'form_id' => $form_id ],
                [ '%s', '%s' ]
            );
        }
    }

    public function test_migrates_bundled_option_mapping_to_plugin_owned_rows_idempotently(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9916';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'legacy_summary' => [
                    'local_mapping_id'           => 'legacy_summary',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Entry Summary',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'execution_mode'  => 'after_submission',
                        'input_mapping'   => [
                            'mode'             => 'selected',
                            'field_ids'        => [ '1' ],
                            'include_metadata' => false,
                        ],
                        'trigger_sources' => [
                            'after_submission' => [ 'type' => 'hook_root' ],
                        ],
                    ],
                ],
            ],
            false
        );

        $first = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        $second = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $first['migration_complete'] ?? null );
        $this->assertSame( 1, $first['mappings_migrated'] ?? null );
        $this->assertSame( 0, $second['mappings_migrated'] ?? null );
        $this->assertSame( [ 'sf_disabled' => false ], get_option( $option_key ) );

        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9916' );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'after_submission', $rows[0]['hook'] ?? null );
        $this->assertSame( 'custom_action', $rows[0]['action_kind'] ?? null );
        $this->assertTrue( $rows[0]['enabled'] ?? false );
        $this->assertSame(
            [],
            $rows[0]['input_bindings_json'] ?? null
        );
        $this->assertSame(
            [ 'mode' => 'selected', 'field_ids' => [ '1' ], 'include_metadata' => false ],
            $rows[0]['settings_json']['input_mapping'] ?? null
        );
        $projection = ( new Sentient_Forms_Action_Input_Projector() )->project(
            $rows[0]['settings_json']['input_mapping'] ?? null,
            $rows[0]['input_bindings_json'] ?? [],
            [ 'id' => 9916, 'fields' => [ [ 'id' => '1' ], [ 'id' => '2' ] ] ],
            [ 'id' => 41, '1' => 'allowed', '2' => 'private' ]
        );
        $this->assertIsArray( $projection );
        $this->assertSame( [ 1 ], array_keys( $projection['entry'] ?? [] ) );
        $this->assertSame( [], $projection['form'] ?? null );
        $this->assertStringStartsWith( 'legacy_action_authority_', (string) ( $rows[0]['external_id'] ?? '' ) );

        $action = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->get( absint( $rows[0]['action_id'] ?? 0 ) );
        $this->assertSame( 'bundled__entry_summary_v1', $action['code'] ?? null );
        $this->assertSame( 'active', $action['status'] ?? null );
    }

    public function test_migration_invalidates_primed_autoloaded_legacy_action_option(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9955';
        $this->option_keys[] = $option_key;
        $mapping             = [
            'local_mapping_id'           => 'autoloaded_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        add_option( $option_key, [ 'autoloaded_summary' => $mapping ], '', true );
        $alloptions = wp_load_alloptions( true );
        $this->assertArrayHasKey( $option_key, $alloptions );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( [], get_option( $option_key ) );
    }

    public function test_migration_invalidates_primed_autoloaded_journal_after_delete(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_autoloaded_journal';
        $this->option_keys[] = $option_key;
        $stored              = [ 'sf_disabled' => false ];
        $journal             = [
            'option_key'   => $option_key,
            'option_value' => maybe_serialize( $stored ),
            'wrapped'      => false,
            'mappings'     => [],
            'rows'         => [],
        ];
        add_option( $option_key, $stored, '', true );
        add_option( 'sentient_forms_action_authority_migration_journal', $journal, '', true );
        $alloptions = wp_load_alloptions( true );
        $this->assertArrayHasKey( 'sentient_forms_action_authority_migration_journal', $alloptions );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertFalse( get_option( 'sentient_forms_action_authority_migration_journal', false ) );
    }

    public function test_migrates_released_gravity_option_prefix_and_preserves_binding_named_mode(): void
    {
        $option_key          = 'sentient_forms_gravity_forms_9925';
        $this->option_keys[] = $option_key;
        $bindings            = [
            'mode'  => '3',
            'email' => '2',
        ];
        update_option(
            $option_key,
            [
                'legacy_summary' => [
                    'local_mapping_id'           => 'legacy_summary',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Entry Summary',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'input_mapping' => $bindings,
                    ],
                ],
            ],
            false
        );

        $first  = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        $second = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $first['migration_complete'] ?? null );
        $this->assertSame( 1, $first['mappings_migrated'] ?? null );
        $this->assertSame( 0, $second['mappings_migrated'] ?? null );
        $this->assertSame( [], get_option( $option_key ) );

        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9925' );
        $this->assertCount( 1, $rows );
        $this->assertSame( $bindings, $rows[0]['input_bindings_json'] ?? null );
        $this->assertArrayNotHasKey( 'input_mapping', $rows[0]['settings_json'] ?? [] );
        $projection = ( new Sentient_Forms_Action_Input_Projector() )->project(
            $rows[0]['settings_json']['input_mapping'] ?? null,
            $rows[0]['input_bindings_json'] ?? [],
            [ 'id' => 9925, 'fields' => [ [ 'id' => '1' ], [ 'id' => '2' ] ] ],
            [ 'id' => 42, '1' => 'full', '2' => 'entry' ]
        );
        $this->assertIsArray( $projection );
        $this->assertSame( $bindings, $projection['bindings'] ?? null );
        $this->assertSame( [ 'id', 1, 2 ], array_keys( $projection['entry'] ?? [] ) );
        $this->assertSame( 'variable_bindings', $projection['manifest']['mapping_source'] ?? null );
    }

    public function test_canonical_gravity_option_wins_when_released_fallback_also_exists(): void
    {
        $canonical_key = 'sentient_forms_actions_gravity_forms_9926';
        $released_key  = 'sentient_forms_gravity_forms_9926';
        $this->option_keys[] = $canonical_key;
        $this->option_keys[] = $released_key;
        $canonical_mapping = [
            'local_mapping_id'           => 'legacy_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'action_name_label'          => 'Canonical Entry Summary',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [ 'input_mapping' => [ 'email' => '2' ] ],
        ];
        $released_mapping = array_replace_recursive(
            $canonical_mapping,
            [
                'action_name_label'          => 'Stale Entry Summary',
                'is_action_enabled_for_form' => false,
                'settings'                   => [ 'input_mapping' => [ 'email' => '999' ] ],
            ]
        );
        update_option( $canonical_key, [ 'actions' => [ 'legacy_summary' => $canonical_mapping ] ], false );
        update_option( $released_key, [ 'legacy_summary' => $released_mapping ], false );

        $first  = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        $second = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $first['migration_complete'] ?? null );
        $this->assertSame( 1, $first['mappings_migrated'] ?? null );
        $this->assertSame( 0, $second['mappings_migrated'] ?? null );
        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9926' );
        $this->assertCount( 1, $rows );
        $this->assertTrue( $rows[0]['enabled'] ?? false );
        $this->assertSame( [ 'email' => '2' ], $rows[0]['input_bindings_json'] ?? null );
        $this->assertSame( [ 'actions' => [] ], get_option( $canonical_key ) );
        $this->assertSame( [ 'legacy_summary' => $released_mapping ], get_option( $released_key ) );
    }

    public function test_released_gravity_option_is_used_when_canonical_option_is_empty(): void
    {
        $canonical_key = 'sentient_forms_actions_gravity_forms_9927';
        $released_key  = 'sentient_forms_gravity_forms_9927';
        $this->option_keys[] = $canonical_key;
        $this->option_keys[] = $released_key;
        $released_mapping = [
            'local_mapping_id'           => 'legacy_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'action_name_label'          => 'Released Entry Summary',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [ 'input_mapping' => [ 'email' => '2' ] ],
        ];
        update_option( $canonical_key, [], false );
        update_option( $released_key, [ 'legacy_summary' => $released_mapping ], false );

        $first  = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        $second = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $first['migration_complete'] ?? null );
        $this->assertSame( 1, $first['mappings_migrated'] ?? null );
        $this->assertSame( 0, $second['mappings_migrated'] ?? null );
        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9927' );
        $this->assertCount( 1, $rows );
        $this->assertTrue( $rows[0]['enabled'] ?? false );
        $this->assertSame( [ 'email' => '2' ], $rows[0]['input_bindings_json'] ?? null );
        $this->assertSame( [], get_option( $canonical_key ) );
        $this->assertSame( [], get_option( $released_key ) );
    }

    public function test_preserves_mode_only_enum_value_as_prompt_binding(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9928';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'actions' => [
                    'legacy_summary' => [
                        'local_mapping_id'           => 'legacy_summary',
                        'central_action_id'          => 'entry_summary_v1',
                        'action_type_indicator'      => 'master',
                        'action_name_label'          => 'Metadata-only Entry Summary',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [ 'input_mapping' => [ 'mode' => 'selected' ] ],
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9928' );
        $this->assertCount( 1, $rows );
        $this->assertSame( [ 'mode' => 'selected' ], $rows[0]['input_bindings_json'] ?? null );
        $this->assertArrayNotHasKey( 'input_mapping', $rows[0]['settings_json'] ?? [] );

        $projection = ( new Sentient_Forms_Action_Input_Projector() )->project(
            $rows[0]['settings_json']['input_mapping'] ?? null,
            $rows[0]['input_bindings_json'] ?? [],
            [ 'id' => 9928, 'title' => 'Metadata-only', 'fields' => [ [ 'id' => '1' ], [ 'id' => '2' ] ] ],
            [ 'id' => 43, '1' => 'private-one', '2' => 'private-two' ]
        );
        $this->assertIsArray( $projection );
        $this->assertSame( [ 'mode' => 'selected' ], $projection['bindings'] ?? null );
        $this->assertSame( [ 'id', 1, 2 ], array_keys( $projection['entry'] ?? [] ) );
    }

    public function test_preserves_mode_only_all_value_as_prompt_binding(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9954';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'actions' => [
                    'legacy_summary' => [
                        'local_mapping_id'           => 'legacy_summary',
                        'central_action_id'          => 'entry_summary_v1',
                        'action_type_indicator'      => 'master',
                        'action_name_label'          => 'All Binding Entry Summary',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [ 'input_mapping' => [ 'mode' => 'all' ] ],
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9954' );
        $this->assertCount( 1, $rows );
        $this->assertSame( [ 'mode' => 'all' ], $rows[0]['input_bindings_json'] ?? null );
        $this->assertArrayNotHasKey( 'input_mapping', $rows[0]['settings_json'] ?? [] );
    }

    public function test_preserves_enum_valued_binding_names_as_bindings(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9929';
        $this->option_keys[] = $option_key;
        $bindings            = [
            'mode'      => 'selected',
            'field_ids' => '2',
        ];
        update_option(
            $option_key,
            [
                'actions' => [
                    'legacy_summary' => [
                        'local_mapping_id'           => 'legacy_summary',
                        'central_action_id'          => 'entry_summary_v1',
                        'action_type_indicator'      => 'master',
                        'action_name_label'          => 'Binding Collision Summary',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [ 'input_mapping' => $bindings ],
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9929' );
        $this->assertCount( 1, $rows );
        $this->assertSame( $bindings, $rows[0]['input_bindings_json'] ?? null );
        $this->assertArrayNotHasKey( 'input_mapping', $rows[0]['settings_json'] ?? [] );
    }

    public function test_preserves_malformed_projection_intent_and_fails_closed(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9930';
        $this->option_keys[] = $option_key;
        $mapping             = [
            'local_mapping_id'           => 'legacy_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'action_name_label'          => 'Malformed Projection Summary',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [
                'input_mapping' => [
                    'mode'             => 'selected',
                    'field_ids'        => [ '1' ],
                    'include_metadata' => 'yes',
                ],
            ],
        ];
        update_option( $option_key, [ 'actions' => [ 'legacy_summary' => $mapping ] ], false );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 0, $summary['migration_complete'] ?? null );
        $this->assertSame( 1, $summary['mappings_failed'] ?? null );
        $this->assertSame( [ 'actions' => [ 'legacy_summary' => $mapping ] ], get_option( $option_key ) );
        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9930' );
        $this->assertSame( [], $rows );
    }

    public function test_remaps_hook_scoped_dependencies_to_canonical_row_ids(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9917';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'actions' => [
                    'legacy_spam' => [
                        'local_mapping_id'           => 'legacy_spam',
                        'central_action_id'          => 'spam_detection_v1',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'validation' ],
                        'settings'                   => [
                            'execution_mode'  => 'validation',
                            'trigger_sources' => [
                                'validation' => [ 'type' => 'hook_root' ],
                            ],
                        ],
                    ],
                    'legacy_validation' => [
                        'local_mapping_id'           => 'legacy_validation',
                        'central_action_id'          => 'content_validation_v1',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'validation' ],
                        'settings'                   => [
                            'execution_mode'         => 'validation',
                            'dependency_ids'        => [ 'legacy_spam' ],
                            'skip_on_upstream_spam' => true,
                            'trigger_sources'       => [
                                'validation' => [
                                    'type'       => 'mapping',
                                    'mapping_id' => 'legacy_spam',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( [ 'actions' => [] ], get_option( $option_key ) );

        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9917' );
        $this->assertCount( 2, $rows );
        $rows_by_action = [];
        $actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        foreach ( $rows as $row )
        {
            $action = $actions->get( absint( $row['action_id'] ?? 0 ) );
            $rows_by_action[ $action['code'] ?? '' ] = $row;
        }

        $parent = $rows_by_action['bundled__spam_detection_v1'];
        $child  = $rows_by_action['bundled__content_validation_v1'];
        $parent_runtime_id = 'local_first_' . absint( $parent['id'] ?? 0 );
        $this->assertSame( [ $parent_runtime_id ], $child['settings_json']['dependency_ids'] ?? null );
        $this->assertSame(
            [ 'type' => 'mapping', 'mapping_id' => $parent_runtime_id ],
            $child['settings_json']['trigger_sources']['validation'] ?? null
        );
    }

    public function test_remaps_validation_dependencies_for_after_submission_rows(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9931';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'actions' => [
                    'legacy_validation' => [
                        'local_mapping_id'           => 'legacy_validation',
                        'central_action_id'          => 'content_validation_v1',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'validation' ],
                        'settings'                   => [
                            'execution_mode'  => 'validation',
                            'trigger_sources' => [
                                'validation' => [ 'type' => 'hook_root' ],
                            ],
                        ],
                    ],
                    'legacy_after_submission' => [
                        'local_mapping_id'           => 'legacy_after_submission',
                        'central_action_id'          => 'spam_detection_v1',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [
                            'dependency_ids'  => [ 'legacy_validation' ],
                            'trigger_sources' => [
                                'after_submission' => [
                                    'type'       => 'mapping',
                                    'mapping_id' => 'legacy_validation',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( [ 'actions' => [] ], get_option( $option_key ) );

        global $wpdb;
        $rows           = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9931' );
        $actions        = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $rows_by_action = [];
        foreach ( $rows as $row )
        {
            $action = $actions->get( absint( $row['action_id'] ?? 0 ) );
            $rows_by_action[ $action['code'] ?? '' ] = $row;
        }

        $parent = $rows_by_action['bundled__content_validation_v1'];
        $child  = $rows_by_action['bundled__spam_detection_v1'];
        $parent_runtime_id = 'local_first_' . absint( $parent['id'] ?? 0 );
        $this->assertSame( 'validation', $parent['hook'] ?? null );
        $this->assertSame( 'after_submission', $child['hook'] ?? null );
        $this->assertSame( [ $parent_runtime_id ], $child['settings_json']['dependency_ids'] ?? null );
        $this->assertSame(
            [ 'type' => 'mapping', 'mapping_id' => $parent_runtime_id ],
            $child['settings_json']['trigger_sources']['after_submission'] ?? null
        );
    }

    public function test_preserves_unconvertible_mapping_and_fails_closed(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9918';
        $this->option_keys[] = $option_key;
        $mapping = [
            'local_mapping_id'           => 'unknown_legacy_action',
            'central_action_id'          => 'removed_cps_only_action',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        update_option( $option_key, [ 'unknown_legacy_action' => $mapping ], false );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 0, $summary['migration_complete'] ?? null );
        $this->assertSame( 1, $summary['mappings_failed'] ?? null );
        $this->assertSame( $mapping, get_option( $option_key )['unknown_legacy_action'] ?? null );

        global $wpdb;
        $this->assertSame(
            [],
            ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9918' )
        );
    }

    public function test_migrates_existing_site_owned_custom_action_without_rewriting_it(): void
    {
        global $wpdb;

        $option_key          = 'sentient_forms_actions_gravity_forms_9933';
        $this->option_keys[] = $option_key;
        $actions             = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id           = $actions->create(
            [
                'code'                 => 'route_lead_custom_migration',
                'display_name'         => 'Route Lead Custom Migration',
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
        $before = $actions->get( $action_id );

        update_option(
            $option_key,
            [
                'custom_route' => [
                    'local_mapping_id'           => 'custom_route',
                    'central_action_id'          => 'route_lead_custom_migration',
                    'action_id'                  => $action_id,
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'input_mapping' => [
                            'mode'             => 'selected',
                            'field_ids'        => [ '2' ],
                            'include_metadata' => false,
                        ],
                    ],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( 1, $summary['mappings_migrated'] ?? null );
        $this->assertSame( [], get_option( $option_key ) );
        $this->assertSame( $before, $actions->get( $action_id ) );

        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9933' );
        $this->assertCount( 1, $rows );
        $this->assertSame( $action_id, absint( $rows[0]['action_id'] ?? 0 ) );
        $this->assertSame( 'after_submission', $rows[0]['hook'] ?? null );
        $this->assertTrue( $rows[0]['enabled'] ?? false );
        $this->assertSame(
            [ 'mode' => 'selected', 'field_ids' => [ '2' ], 'include_metadata' => false ],
            $rows[0]['settings_json']['input_mapping'] ?? null
        );
    }

    public function test_wrapped_migration_uses_top_level_precedence_and_removes_every_alias(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9934';
        $this->option_keys[] = $option_key;
        $nested = [
            'local_mapping_id'           => 'wrapped_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => false,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [
                'input_mapping' => [
                    'mode'             => 'selected',
                    'field_ids'        => [ '1' ],
                    'include_metadata' => false,
                ],
            ],
        ];
        $top_level = $nested;
        $top_level['is_action_enabled_for_form'] = true;
        $top_level['settings']['input_mapping']['field_ids'] = [ '2' ];

        update_option(
            $option_key,
            [
                'actions'         => [ 'wrapped_summary' => $nested ],
                'sf_disabled'     => false,
                'fixture_metadata' => 'preserve-me',
                'wrapped_summary' => $top_level,
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( 1, $summary['mappings_migrated'] ?? null );
        $this->assertSame(
            [
                'actions'          => [],
                'sf_disabled'      => false,
                'fixture_metadata' => 'preserve-me',
            ],
            get_option( $option_key )
        );

        global $wpdb;
        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9934' );
        $this->assertCount( 1, $rows );
        $this->assertTrue( $rows[0]['enabled'] ?? false );
        $this->assertSame( [ '2' ], $rows[0]['settings_json']['input_mapping']['field_ids'] ?? null );
    }

    public function test_wrapped_migration_preserves_all_aliases_for_failed_mapping(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9935';
        $this->option_keys[] = $option_key;
        $failed_nested = [
            'local_mapping_id'           => 'failed_alias',
            'central_action_id'          => 'removed_cps_only_action',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [ 'fixture' => 'nested' ],
        ];
        $failed_top = $failed_nested;
        $failed_top['settings']['fixture'] = 'top-level';
        $successful = [
            'local_mapping_id'           => 'successful_alias',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];

        update_option(
            $option_key,
            [
                'actions'          => [
                    'failed_alias'     => $failed_nested,
                    'successful_alias' => $successful,
                ],
                'failed_alias'     => $failed_top,
                'successful_alias' => $successful,
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 0, $summary['migration_complete'] ?? null );
        $this->assertSame( 1, $summary['mappings_failed'] ?? null );
        $this->assertSame( 1, $summary['mappings_migrated'] ?? null );
        $remaining = get_option( $option_key );
        $this->assertSame( $failed_nested, $remaining['actions']['failed_alias'] ?? null );
        $this->assertSame( $failed_top, $remaining['failed_alias'] ?? null );
        $this->assertArrayNotHasKey( 'successful_alias', $remaining['actions'] ?? [] );
        $this->assertArrayNotHasKey( 'successful_alias', $remaining );

        global $wpdb;
        $this->assertCount(
            1,
            ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9935' )
        );
    }

    public function test_migrates_disabled_archived_custom_action_without_reactivating_it(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9936';
        $this->option_keys[] = $option_key;

        global $wpdb;
        $actions   = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $action_id = $actions->create(
            [
                'code'                 => 'archived_custom_migration',
                'display_name'         => 'Archived custom migration',
                'definition_json'      => [ 'prompt_template' => 'Preserve {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'primary' => 'openrouter/auto' ],
                'status'               => 'archived',
            ]
        );
        $this->assertIsInt( $action_id );
        $before = $actions->get( $action_id );

        update_option(
            $option_key,
            [
                'archived_route' => [
                    'local_mapping_id'           => 'archived_route',
                    'central_action_id'          => 'archived_custom_migration',
                    'action_id'                  => $action_id,
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => false,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [],
                ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( 1, $summary['mappings_migrated'] ?? null );
        $this->assertSame( [], get_option( $option_key ) );
        $this->assertSame( $before, $actions->get( $action_id ) );

        $rows = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9936' );
        $this->assertCount( 1, $rows );
        $this->assertFalse( $rows[0]['enabled'] ?? true );
        $this->assertSame( $action_id, absint( $rows[0]['action_id'] ?? 0 ) );
    }

    public function test_old_wrapped_journal_never_enables_stale_row_before_top_level_rebuild(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9937';
        $this->option_keys[] = $option_key;
        $nested = [
            'local_mapping_id'           => 'wrapped_resume',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [ 'input_mapping' => [ 'mode' => 'selected', 'field_ids' => [ '1' ] ] ],
        ];
        $top_level = $nested;
        $top_level['is_action_enabled_for_form'] = false;
        $top_level['settings']['input_mapping']['field_ids'] = [ '2' ];

        update_option( $option_key, [ 'actions' => [ 'nested_slot' => $nested ] ], false );
        Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        global $wpdb;
        $rows       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored_rows = $rows->list_for_form( 'gravity_forms', '9937' );
        $this->assertCount( 1, $stored_rows );
        $row_id = absint( $stored_rows[0]['id'] ?? 0 );
        $this->assertNotWPError( $rows->update( $row_id, [ 'enabled' => false ] ) );

        update_option(
            $option_key,
            [
                'actions'  => [ 'nested_slot' => $nested ],
                'top_slot' => $top_level,
            ],
            false
        );
        update_option(
            'sentient_forms_action_authority_migration_journal',
            [
                'option_key' => $option_key,
                'wrapped'    => true,
                'mappings'   => [
                    [
                        'key'  => 'nested_slot',
                        'hash' => hash( 'sha256', wp_json_encode( $nested ) ),
                    ],
                ],
                'rows'       => [ $row_id => true ],
            ],
            false
        );

        $stale_enable_seen = false;
        $observe_updates   = static function ( string $query ) use ( $wpdb, &$stale_enable_seen ): string {
            if (
                str_contains( $query, 'UPDATE `' . $wpdb->prefix . 'sentient_form_mappings`' )
                && preg_match( '/`enabled`\s*=\s*1/', $query )
            )
            {
                $stale_enable_seen = true;
            }
            return $query;
        };
        add_filter( 'query', $observe_updates );
        try
        {
            $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        }
        finally
        {
            remove_filter( 'query', $observe_updates );
        }

        $this->assertFalse( $stale_enable_seen );
        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( [ 'actions' => [] ], get_option( $option_key ) );
        $row = $rows->get( $row_id );
        $this->assertFalse( $row['enabled'] ?? true );
        $this->assertSame( [ '2' ], $row['settings_json']['input_mapping']['field_ids'] ?? null );
    }

    public function test_concurrent_alias_addition_keeps_rows_disabled_until_snapshot_rebuild(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9938';
        $this->option_keys[] = $option_key;
        $original = [
            'local_mapping_id'           => 'original_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        $concurrent = $original;
        $concurrent['local_mapping_id']  = 'concurrent_summary';
        update_option( $option_key, [ 'original_summary' => $original ], false );

        global $wpdb;
        $mutated = false;
        $add_alias = static function ( mixed $value ) use ( $wpdb, $option_key, $original, $concurrent, &$mutated ): mixed {
            if ( $mutated )
            {
                return $value;
            }
            $mutated = true;
            $wpdb->update(
                $wpdb->options,
                [ 'option_value' => maybe_serialize( [ 'original_summary' => $original, 'concurrent_summary' => $concurrent ] ) ],
                [ 'option_name' => $option_key ],
                [ '%s' ],
                [ '%s' ]
            );
            wp_cache_delete( $option_key, 'options' );
            return $value;
        };
        add_filter( 'pre_update_option_sentient_forms_action_authority_migration_journal', $add_alias );
        try
        {
            $first = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        }
        finally
        {
            remove_filter( 'pre_update_option_sentient_forms_action_authority_migration_journal', $add_alias );
        }

        $this->assertSame( 0, $first['migration_complete'] ?? null );
        $this->assertSame(
            [ 'original_summary', 'concurrent_summary' ],
            array_keys( get_option( $option_key ) )
        );
        $rows = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $first_rows = $rows->list_for_form( 'gravity_forms', '9938' );
        $this->assertCount( 1, $first_rows );
        $this->assertFalse( $first_rows[0]['enabled'] ?? true );

        $second = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $second['migration_complete'] ?? null );
        $this->assertSame( [], get_option( $option_key ) );
        $second_rows = $rows->list_for_form( 'gravity_forms', '9938' );
        $this->assertCount( 2, $second_rows );
        foreach ( $second_rows as $row )
        {
            $this->assertTrue( $row['enabled'] ?? false );
        }
    }

    public function test_concurrent_unrelated_option_mutation_is_preserved_across_snapshot_rebuild(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9939';
        $this->option_keys[] = $option_key;
        $mapping = [
            'local_mapping_id'           => 'metadata_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        update_option( $option_key, [ 'metadata_summary' => $mapping, 'fixture_metadata' => 'before' ], false );

        global $wpdb;
        $mutated = false;
        $change_metadata = static function ( mixed $value ) use ( $wpdb, $option_key, $mapping, &$mutated ): mixed {
            if ( $mutated )
            {
                return $value;
            }
            $mutated = true;
            $wpdb->update(
                $wpdb->options,
                [ 'option_value' => maybe_serialize( [ 'metadata_summary' => $mapping, 'fixture_metadata' => 'after' ] ) ],
                [ 'option_name' => $option_key ],
                [ '%s' ],
                [ '%s' ]
            );
            wp_cache_delete( $option_key, 'options' );
            return $value;
        };
        add_filter( 'pre_update_option_sentient_forms_action_authority_migration_journal', $change_metadata );
        try
        {
            $first = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        }
        finally
        {
            remove_filter( 'pre_update_option_sentient_forms_action_authority_migration_journal', $change_metadata );
        }

        $this->assertSame( 0, $first['migration_complete'] ?? null );
        $this->assertSame( 'after', get_option( $option_key )['fixture_metadata'] ?? null );
        $rows = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $first_rows = $rows->list_for_form( 'gravity_forms', '9939' );
        $this->assertCount( 1, $first_rows );
        $this->assertFalse( $first_rows[0]['enabled'] ?? true );

        $second = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $second['migration_complete'] ?? null );
        $this->assertSame( [ 'fixture_metadata' => 'after' ], get_option( $option_key ) );
        $this->assertTrue( $rows->list_for_form( 'gravity_forms', '9939' )[0]['enabled'] ?? false );
    }

    public function test_case_only_concurrent_option_mutation_is_not_overwritten_by_collated_cas(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9940';
        $this->option_keys[] = $option_key;
        $mapping = [
            'local_mapping_id'           => 'case_sensitive_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        update_option(
            $option_key,
            [
                'case_sensitive_summary' => $mapping,
                'operator_label'         => 'Case Sensitive',
            ],
            false
        );

        global $wpdb;
        $mutated    = false;
        $change_case = null;
        $change_case = static function ( string $query ) use ( $wpdb, $option_key, $mapping, &$mutated, &$change_case ): string {
            if (
                $mutated
                || ! str_starts_with( $query, 'UPDATE `' . $wpdb->options . '` SET `option_value`' )
                || ! str_contains( $query, $option_key )
            )
            {
                return $query;
            }
            $mutated = true;
            remove_filter( 'query', $change_case );
            $wpdb->update(
                $wpdb->options,
                [
                    'option_value' => maybe_serialize(
                        [
                            'case_sensitive_summary' => $mapping,
                            'operator_label'         => 'case sensitive',
                        ]
                    ),
                ],
                [ 'option_name' => $option_key ],
                [ '%s' ],
                [ '%s' ]
            );
            wp_cache_delete( $option_key, 'options' );
            return $query;
        };
        add_filter( 'query', $change_case );
        try
        {
            $first = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        }
        finally
        {
            remove_filter( 'query', $change_case );
        }

        $this->assertTrue( $mutated );
        $this->assertSame( 0, $first['migration_complete'] ?? null );
        $this->assertSame( 'case sensitive', get_option( $option_key )['operator_label'] ?? null );
        $this->assertArrayHasKey( 'case_sensitive_summary', get_option( $option_key ) );

        $rows = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $first_rows = $rows->list_for_form( 'gravity_forms', '9940' );
        $this->assertCount( 1, $first_rows );
        $this->assertFalse( $first_rows[0]['enabled'] ?? true );

        $second = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $second['migration_complete'] ?? null );
        $this->assertSame( [ 'operator_label' => 'case sensitive' ], get_option( $option_key ) );
        $second_rows = $rows->list_for_form( 'gravity_forms', '9940' );
        $this->assertCount( 1, $second_rows );
        $this->assertTrue( $second_rows[0]['enabled'] ?? false );
    }

    public function test_installer_normalizes_stored_mapping_before_advancing_db_version(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9919';
        $this->option_keys[] = $option_key;
        update_option( 'sentient_forms_db_version', '2026.07.10.pre_action_authority_cutover', false );
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

        Sentient_Forms_Installer::maybe_upgrade( true );

        $this->assertSame( [], get_option( $option_key ) );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        global $wpdb;
        $this->assertCount(
            1,
            ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9919' )
        );
    }

    public function test_resumes_option_swap_from_durable_journal_without_duplicate_rows(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9920';
        $this->option_keys[] = $option_key;
        $mapping = [
            'local_mapping_id'           => 'legacy_summary',
            'central_action_id'          => 'entry_summary_v1',
            'action_type_indicator'      => 'master',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        update_option( $option_key, [ 'legacy_summary' => $mapping ], false );
        Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        global $wpdb;
        $rows = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $stored_rows = $rows->list_for_form( 'gravity_forms', '9920' );
        $this->assertCount( 1, $stored_rows );
        $row_id = absint( $stored_rows[0]['id'] ?? 0 );
        $this->assertNotWPError( $rows->update( $row_id, [ 'enabled' => false ] ) );
        update_option( $option_key, [ 'legacy_summary' => $mapping ], false );
        update_option(
            'sentient_forms_action_authority_migration_journal',
            [
                'option_key' => $option_key,
                'wrapped'    => false,
                'mappings'   => [
                    [
                        'key'  => 'legacy_summary',
                        'hash' => hash( 'sha256', wp_json_encode( $mapping ) ),
                    ],
                ],
                'rows'       => [ $row_id => true ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( [], get_option( $option_key ) );
        $this->assertTrue( $rows->get( $row_id )['enabled'] ?? false );
        $this->assertCount( 1, $rows->list_for_form( 'gravity_forms', '9920' ) );
        $this->assertFalse( get_option( 'sentient_forms_action_authority_migration_journal', false ) );
    }

    public function test_failed_mapping_rows_remain_disabled_when_another_mapping_activates(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9921';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'failing_parent' => [
                    'local_mapping_id'           => 'failing_parent',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [],
                ],
                'dependent' => [
                    'local_mapping_id'           => 'dependent',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'dependency_ids'  => [ 'failing_parent' ],
                        'trigger_sources' => [
                            'after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'failing_parent',
                            ],
                        ],
                    ],
                ],
                'successful' => [
                    'local_mapping_id'           => 'successful',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [],
                ],
            ],
            false
        );

        global $wpdb;
        $failed_external_id = 'legacy_action_authority_' . substr( hash( 'sha256', $option_key . '|failing_parent|after_submission' ), 0, 40 );
        $fail_parent_insert = static function ( string $query ) use ( $wpdb, $failed_external_id ): string {
            if (
                str_contains( $query, 'INSERT INTO `' . $wpdb->prefix . 'sentient_form_mappings`' )
                && str_contains( $query, $failed_external_id )
            )
            {
                return str_replace(
                    '`' . $wpdb->prefix . 'sentient_form_mappings`',
                    '`' . $wpdb->prefix . 'sentient_form_mappings_missing_fixture`',
                    $query
                );
            }

            return $query;
        };
        add_filter( 'query', $fail_parent_insert );
        $previous_suppress_errors = $wpdb->suppress_errors();
        try
        {
            $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        }
        finally
        {
            $wpdb->suppress_errors( $previous_suppress_errors );
            remove_filter( 'query', $fail_parent_insert );
        }

        $this->assertSame( 0, $summary['migration_complete'] ?? null );
        $this->assertSame( 2, $summary['mappings_failed'] ?? null );
        $this->assertSame( 1, $summary['mappings_migrated'] ?? null );
        $remaining = get_option( $option_key );
        $this->assertArrayHasKey( 'failing_parent', $remaining );
        $this->assertArrayHasKey( 'dependent', $remaining );
        $this->assertArrayNotHasKey( 'successful', $remaining );

        $rows           = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9921' );
        $actions        = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $rows_by_action = [];
        foreach ( $rows as $row )
        {
            $action = $actions->get( absint( $row['action_id'] ?? 0 ) );
            $rows_by_action[ $action['code'] ?? '' ] = $row;
        }

        $this->assertCount( 2, $rows );
        $this->assertFalse( $rows_by_action['bundled__spam_detection_v1']['enabled'] ?? true );
        $this->assertTrue( $rows_by_action['bundled__entry_summary_v1']['enabled'] ?? false );
    }

    public function test_partial_multi_hook_failure_keeps_transitive_dependants_legacy_and_disabled(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9932';
        $this->option_keys[] = $option_key;
        update_option(
            $option_key,
            [
                'failing_parent' => [
                    'local_mapping_id'           => 'failing_parent',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'validation', 'after_submission' ],
                    'settings'                   => [],
                ],
                'dependent' => [
                    'local_mapping_id'           => 'dependent',
                    'central_action_id'          => 'entry_summary_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'dependency_ids'  => [ 'failing_parent' ],
                        'trigger_sources' => [
                            'after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'failing_parent',
                            ],
                        ],
                    ],
                ],
                'transitive_dependent' => [
                    'local_mapping_id'           => 'transitive_dependent',
                    'central_action_id'          => 'sentiment_urgency_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'dependency_ids'  => [ 'dependent' ],
                        'trigger_sources' => [
                            'after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'dependent',
                            ],
                        ],
                    ],
                ],
                'successful' => [
                    'local_mapping_id'           => 'successful',
                    'central_action_id'          => 'missing_information_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [],
                ],
            ],
            false
        );

        global $wpdb;
        $failed_external_id = 'legacy_action_authority_' . substr( hash( 'sha256', $option_key . '|failing_parent|after_submission' ), 0, 40 );
        $fail_parent_after_submission_insert = static function ( string $query ) use ( $wpdb, $failed_external_id ): string {
            if (
                str_contains( $query, 'INSERT INTO `' . $wpdb->prefix . 'sentient_form_mappings`' )
                && str_contains( $query, $failed_external_id )
            )
            {
                return str_replace(
                    '`' . $wpdb->prefix . 'sentient_form_mappings`',
                    '`' . $wpdb->prefix . 'sentient_form_mappings_missing_fixture`',
                    $query
                );
            }

            return $query;
        };
        add_filter( 'query', $fail_parent_after_submission_insert );
        $previous_suppress_errors = $wpdb->suppress_errors();
        try
        {
            $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        }
        finally
        {
            $wpdb->suppress_errors( $previous_suppress_errors );
            remove_filter( 'query', $fail_parent_after_submission_insert );
        }

        $this->assertSame( 0, $summary['migration_complete'] ?? null );
        $this->assertSame( 3, $summary['mappings_failed'] ?? null );
        $this->assertSame( 1, $summary['mappings_migrated'] ?? null );
        $remaining = get_option( $option_key );
        $this->assertArrayHasKey( 'failing_parent', $remaining );
        $this->assertArrayHasKey( 'dependent', $remaining );
        $this->assertArrayHasKey( 'transitive_dependent', $remaining );
        $this->assertArrayNotHasKey( 'successful', $remaining );

        $rows                = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9932' );
        $actions             = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $rows_by_hook_action = [];
        foreach ( $rows as $row )
        {
            $action = $actions->get( absint( $row['action_id'] ?? 0 ) );
            $key    = ( $row['hook'] ?? '' ) . '|' . ( $action['code'] ?? '' );
            $rows_by_hook_action[ $key ] = $row;
        }

        $this->assertCount( 4, $rows );
        $this->assertFalse( $rows_by_hook_action['validation|bundled__spam_detection_v1']['enabled'] ?? true );
        $this->assertArrayNotHasKey( 'after_submission|bundled__spam_detection_v1', $rows_by_hook_action );
        $this->assertFalse( $rows_by_hook_action['after_submission|bundled__entry_summary_v1']['enabled'] ?? true );
        $this->assertFalse( $rows_by_hook_action['after_submission|bundled__sentiment_urgency_v1']['enabled'] ?? true );
        $this->assertTrue( $rows_by_hook_action['after_submission|bundled__missing_information_v1']['enabled'] ?? false );
    }

    public function test_cutover_preserves_custom_uuid_mapping_and_holds_db_version_for_operator_remediation(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9924';
        $this->option_keys[] = $option_key;
        $previous_version    = '2026.07.10.pre_action_authority_cutover';
        $mapping             = [
            'local_mapping_id'           => 'legacy_custom_uuid',
            'central_action_id'          => '6f10dc40-7c1a-4ae4-8dc6-177fb4f8289d',
            'action_type_indicator'      => 'custom',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [],
        ];
        update_option( 'sentient_forms_db_version', $previous_version, false );
        update_option( $option_key, [ 'legacy_custom_uuid' => $mapping ], false );

        Sentient_Forms_Installer::maybe_upgrade( true );

        $this->assertSame( $previous_version, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( $mapping, get_option( $option_key )['legacy_custom_uuid'] ?? null );

        global $wpdb;
        $this->assertSame(
            [],
            ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->list_for_form( 'gravity_forms', '9924' )
        );
    }

    public function test_migrates_all_form_sources_with_disabled_multi_hook_and_opaque_id_variants(): void
    {
        $cases = [
            [ 'gravity_forms', '9916', 'spam_detection_v1', [ 'validation', 'after_submission' ], true ],
            [ 'contact_form_7', '9922', 'entry_summary_v1', [ 'after_submission' ], false ],
            [ 'wpforms', '9923', 'entry_summary_v1', [ 'after_submission' ], true ],
            [ 'elementor_pro_forms', '321:opaque-form', 'entry_summary_v1', [ 'after_submission' ], true ],
        ];

        foreach ( $cases as [ $form_source, $form_id, $action_code, $hooks, $enabled ] )
        {
            $option_key          = 'sentient_forms_actions_' . $form_source . '_' . Sentient_Forms_Provider_Form_Id_Keys::option_suffix( $form_id );
            $this->option_keys[] = $option_key;
            update_option(
                $option_key,
                [
                    'mapping_' . $form_source => [
                        'local_mapping_id'           => 'mapping_' . $form_source,
                        'central_action_id'          => $action_code,
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => $enabled,
                        'trigger_hooks'              => $hooks,
                        'settings'                   => [],
                    ],
                ],
                false
            );
        }

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertSame( 4, $summary['mappings_migrated'] ?? null );

        global $wpdb;
        $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $gravity_rows = $repository->list_for_form( 'gravity_forms', '9916' );
        $this->assertCount( 2, $gravity_rows );
        $this->assertSame( [ 'validation', 'after_submission' ], array_values( array_unique( array_column( $gravity_rows, 'hook' ) ) ) );
        foreach ( $gravity_rows as $row )
        {
            $this->assertTrue( $row['enabled'] ?? false );
        }

        $cf7_rows = $repository->list_for_form( 'contact_form_7', '9922' );
        $this->assertCount( 1, $cf7_rows );
        $this->assertFalse( $cf7_rows[0]['enabled'] ?? true );
        $this->assertCount( 1, $repository->list_for_form( 'wpforms', '9923' ) );
        $this->assertCount( 1, $repository->list_for_form( 'elementor_pro_forms', '321:opaque-form' ) );
    }

    public function test_stale_diagnostic_lock_cannot_steal_an_active_database_lock(): void
    {
        $lock_option = 'sentient_forms_action_authority_migration_lock';
        $stale_lock  = [ 'token' => 'stale-owner', 'created_at' => time() - 600 ];
        $lock_name_method = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'database_lock_name' );
        $lock_name        = $lock_name_method->invoke( null );
        $competitor       = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $connection_id    = (int) $competitor->get_var( 'SELECT CONNECTION_ID()' );
        $acquired         = (int) $competitor->get_var(
            $competitor->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
        );
        $this->assertSame( 1, $acquired );
        update_option( $lock_option, $stale_lock, false );

        try
        {
            $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
            $owner   = (int) $competitor->get_var(
                $competitor->prepare( 'SELECT IS_USED_LOCK(%s)', $lock_name )
            );
        }
        finally
        {
            $competitor->get_var( $competitor->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            $competitor->close();
        }

        $this->assertSame( 0, $summary['migration_complete'] ?? null );
        $this->assertSame( $connection_id, $owner );

        $completed = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
        $this->assertSame( 1, $completed['migration_complete'] ?? null );
    }

    public function test_lost_database_lock_after_option_swap_preserves_disabled_rows_and_resumes(): void
    {
        global $wpdb;

        $option_key          = 'sentient_forms_actions_gravity_forms_9953';
        $this->option_keys[] = $option_key;
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

        $lock_name_method = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'database_lock_name' );
        $lock_name        = $lock_name_method->invoke( null );
        $lock_database    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $lock_filter      = static fn(): wpdb => $lock_database;
        $released         = false;
        $release_before_option_swap = static function ( string $query ) use ( $wpdb, $option_key, $lock_database, $lock_name, &$released ): string {
            if (
                ! $released
                && str_starts_with( $query, 'UPDATE `' . $wpdb->options . '` SET `option_value`' )
                && str_contains( $query, $option_key )
            )
            {
                $released = true;
                $lock_database->get_var( $lock_database->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            }

            return $query;
        };
        add_filter( 'sentient_forms_action_authority_lock_database', $lock_filter, 10, 3 );
        add_filter( 'query', $release_before_option_swap );
        try
        {
            $first = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
            remove_filter( 'query', $release_before_option_swap );

            $repository = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
            $first_rows = $repository->list_for_form( 'gravity_forms', '9953' );
            $this->assertTrue( $released );
            $this->assertSame( 0, $first['migration_complete'] ?? null );
            $this->assertNotFalse( get_option( 'sentient_forms_action_authority_migration_journal', false ) );
            $this->assertSame( [], get_option( $option_key ) );
            $this->assertCount( 1, $first_rows );
            $this->assertFalse( $first_rows[0]['enabled'] ?? true );

            $second      = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();
            $second_rows = $repository->list_for_form( 'gravity_forms', '9953' );
            $this->assertSame( 1, $second['migration_complete'] ?? null );
            $this->assertFalse( get_option( 'sentient_forms_action_authority_migration_journal', false ) );
            $this->assertCount( 1, $second_rows );
            $this->assertTrue( $second_rows[0]['enabled'] ?? false );
        }
        finally
        {
            remove_filter( 'query', $release_before_option_swap );
            remove_filter( 'sentient_forms_action_authority_lock_database', $lock_filter, 10 );
            $lock_database->close();
        }
    }

    public function test_ordinary_writer_retains_lock_across_wordpress_database_reconnect(): void
    {
        global $wpdb;

        $option_key          = 'sentient_forms_action_defaults_reconnect_fixture';
        $this->option_keys[] = $option_key;
        $reconnected         = false;
        $reconnect_before_write = static function ( string $query ) use ( $wpdb, $option_key, &$reconnected ): string {
            if ( ! $reconnected && str_contains( $query, $option_key ) && preg_match( '/^(?:INSERT|UPDATE)/i', ltrim( $query ) ) )
            {
                $reconnected = true;
                $wpdb->close();
            }

            return $query;
        };
        add_filter( 'query', $reconnect_before_write );
        try
        {
            $result = Sentient_Forms_Legacy_Action_Authority_Migrator::with_option_write_lock(
                static fn(): bool => update_option( $option_key, [ 'model_override' => 'openrouter/auto' ], false )
            );
        }
        finally
        {
            remove_filter( 'query', $reconnect_before_write );
        }

        $this->assertTrue( $reconnected );
        $this->assertTrue( $result );
        $this->assertSame( [ 'model_override' => 'openrouter/auto' ], get_option( $option_key ) );
    }

    public function test_ordinary_writer_uses_bounded_wait_for_benign_lock_contention(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_bounded_wait_fixture';
        $this->option_keys[] = $option_key;
        $lock_name_method    = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'database_lock_name' );
        $lock_name           = $lock_name_method->invoke( null );
        $competitor          = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $acquired            = (int) $competitor->get_var(
            $competitor->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
        );
        $this->assertSame( 1, $acquired );
        add_filter( 'sentient_forms_action_authority_writer_lock_timeout', static fn(): int => 1 );
        $observed_timeout = null;
        $avoid_wall_clock_wait = static function ( string $query ) use ( &$observed_timeout ): string {
            if ( preg_match( '/GET_LOCK\(.+,\s*(\d+)\s*\)/i', $query, $matches ) )
            {
                $observed_timeout = (int) $matches[1];
                return (string) preg_replace( '/,\s*\d+\s*\)$/', ', 0)', $query );
            }
            return $query;
        };
        add_filter( 'query', $avoid_wall_clock_wait );

        try
        {
            $result = Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
                $option_key,
                [ 'model_override' => 'openrouter/auto' ]
            );
        }
        finally
        {
            remove_filter( 'query', $avoid_wall_clock_wait );
            $competitor->get_var( $competitor->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            $competitor->close();
        }

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 1, $observed_timeout );
        $this->assertFalse( get_option( $option_key, false ) );
    }

    public function test_lock_database_factory_accepts_topology_compatible_connection(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_topology_fixture';
        $this->option_keys[] = $option_key;
        $provided            = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $factory_called      = false;
        $factory             = static function ( mixed $candidate, wpdb $primary, string $mode ) use ( $provided, &$factory_called ): wpdb {
            $factory_called = true;
            return $provided;
        };
        add_filter( 'sentient_forms_action_authority_lock_database', $factory, 10, 3 );

        try
        {
            $result = Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
                $option_key,
                [ 'model_override' => 'openrouter/auto' ]
            );
        }
        finally
        {
            remove_filter( 'sentient_forms_action_authority_lock_database', $factory, 10 );
            $provided->close();
        }

        $this->assertTrue( $factory_called );
        $this->assertTrue( $result );
        $this->assertSame( [ 'model_override' => 'openrouter/auto' ], get_option( $option_key ) );
    }

    public function test_transparent_wpdb_subclass_uses_core_dedicated_lock_connection(): void
    {
        global $wpdb;

        $option_key          = 'sentient_forms_actions_gravity_forms_transparent_db';
        $this->option_keys[] = $option_key;
        $original_database   = $wpdb;
        $transparent         = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {};
        $transparent->set_prefix( $original_database->prefix );
        $this->reset_dedicated_lock_database();
        $wpdb = $transparent;

        try
        {
            $result = Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
                $option_key,
                [ 'model_override' => 'openrouter/auto' ]
            );
        }
        finally
        {
            $wpdb = $original_database;
            $this->reset_dedicated_lock_database();
            $transparent->close();
        }

        $this->assertTrue( $result );
        $this->assertSame( [ 'model_override' => 'openrouter/auto' ], get_option( $option_key ) );
    }

    public function test_wpdb_subclass_with_query_override_requires_topology_filter(): void
    {
        global $wpdb;

        $option_key          = 'sentient_forms_actions_gravity_forms_routed_db';
        $this->option_keys[] = $option_key;
        $original_database   = $wpdb;
        $routed_database     = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
            public function query( $query )
            {
                return parent::query( $query );
            }
        };
        $provided_lock_database = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $lock_filter = static function ( mixed $candidate, wpdb $primary, string $mode ) use ( $provided_lock_database ): wpdb {
            return $provided_lock_database;
        };
        $routed_database->set_prefix( $original_database->prefix );
        $this->reset_dedicated_lock_database();
        $wpdb = $routed_database;

        try
        {
            $result = Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
                $option_key,
                [ 'model_override' => 'openrouter/auto' ]
            );
            add_filter( 'sentient_forms_action_authority_lock_database', $lock_filter, 10, 3 );
            $filtered_result = Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
                $option_key,
                [ 'model_override' => 'openrouter/auto' ]
            );
        }
        finally
        {
            remove_filter( 'sentient_forms_action_authority_lock_database', $lock_filter, 10 );
            $wpdb = $original_database;
            $this->reset_dedicated_lock_database();
            $routed_database->close();
            $provided_lock_database->close();
        }

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $result->get_error_code() );
        $this->assertTrue( $filtered_result );
        $this->assertSame( [ 'model_override' => 'openrouter/auto' ], get_option( $option_key ) );
    }

    public function test_pending_journal_blocks_ordinary_legacy_option_writer(): void
    {
        $option_key          = 'sentient_forms_actions_gravity_forms_9941';
        $this->option_keys[] = $option_key;
        update_option( 'sentient_forms_action_authority_migration_journal', [ 'pending' => true ], false );

        $result = Sentient_Forms_Legacy_Action_Authority_Migrator::update_action_option(
            $option_key,
            [ 'must_not_persist' => [ 'enabled' => true ] ]
        );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $result->get_error_code() );
        $this->assertFalse( get_option( $option_key, false ) );
        delete_option( 'sentient_forms_action_authority_migration_journal' );
    }

    public function test_abandoning_stale_journal_disables_rows_before_releasing_writer_barrier(): void
    {
        global $wpdb;

        $actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $rows    = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id = $actions->create(
            [
                'code'            => 'stale_journal_fixture',
                'display_name'    => 'Stale Journal Fixture',
                'definition_json' => [ 'prompt_template' => 'Summarize {{entry}}.' ],
            ]
        );
        $this->assertIsInt( $action_id );
        $row_id = $rows->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '9942',
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $row_id );

        $option_key          = 'sentient_forms_stale_journal_fixture';
        $this->option_keys[] = $option_key;
        update_option( $option_key, [ 'concurrent' => 'writer' ], false );
        update_option(
            'sentient_forms_action_authority_migration_journal',
            [
                'option_key'   => $option_key,
                'option_value' => maybe_serialize( [ 'original' => 'snapshot' ] ),
                'wrapped'      => false,
                'mappings'     => [],
                'rows'         => [ $row_id => true ],
            ],
            false
        );

        $summary = Sentient_Forms_Legacy_Action_Authority_Migrator::migrate();

        $this->assertSame( 1, $summary['migration_complete'] ?? null );
        $this->assertFalse( get_option( 'sentient_forms_action_authority_migration_journal', false ) );
        $this->assertFalse( $rows->get( $row_id )['enabled'] ?? true );
    }

    public function test_lock_release_does_not_delete_a_newer_competing_lock(): void
    {
        global $wpdb;

        $lock_option = 'sentient_forms_action_authority_migration_lock';
        $owner_lock  = [ 'token' => 'original-owner', 'created_at' => time() - 600 ];
        $newer_lock  = [ 'token' => 'newer-owner', 'created_at' => time() ];
        add_option( $lock_option, $owner_lock, '', false );

        $lock_token = new ReflectionProperty( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'lock_token' );
        $lock_token->setValue( null, $owner_lock['token'] );

        $competitor_installed = false;
        $install_competitor = static function ( mixed $value ) use ( $wpdb, $lock_option, $newer_lock, &$competitor_installed ): mixed {
            if ( $competitor_installed )
            {
                return $value;
            }

            $competitor_installed = true;
            $wpdb->update(
                $wpdb->options,
                [ 'option_value' => maybe_serialize( $newer_lock ) ],
                [ 'option_name' => $lock_option ],
                [ '%s' ],
                [ '%s' ]
            );
            wp_cache_delete( $lock_option, 'options' );

            return $value;
        };
        add_filter( 'option_' . $lock_option, $install_competitor );
        try
        {
            $method = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'release_lock' );
            $method->invoke( null );
        }
        finally
        {
            remove_filter( 'option_' . $lock_option, $install_competitor );
        }

        wp_cache_delete( $lock_option, 'options' );
        $this->assertSame( $newer_lock, get_option( $lock_option ) );
    }

    private function reset_dedicated_lock_database(): void
    {
        $property = new ReflectionProperty( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'dedicated_lock_database' );
        $database = $property->getValue();
        if ( $database instanceof wpdb )
        {
            $database->close();
        }
        $property->setValue( null, null );
    }

}
