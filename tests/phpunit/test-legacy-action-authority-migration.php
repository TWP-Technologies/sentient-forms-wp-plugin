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

    public function test_migrates_mode_only_projection_policy_without_widening_input(): void
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
        $this->assertSame( [], $rows[0]['input_bindings_json'] ?? null );
        $this->assertSame( [ 'mode' => 'selected' ], $rows[0]['settings_json']['input_mapping'] ?? null );

        $projection = ( new Sentient_Forms_Action_Input_Projector() )->project(
            $rows[0]['settings_json']['input_mapping'] ?? null,
            $rows[0]['input_bindings_json'] ?? [],
            [ 'id' => 9928, 'title' => 'Metadata-only', 'fields' => [ [ 'id' => '1' ], [ 'id' => '2' ] ] ],
            [ 'id' => 43, '1' => 'private-one', '2' => 'private-two' ]
        );
        $this->assertIsArray( $projection );
        $this->assertSame( [ 'id' => 43 ], $projection['entry'] ?? null );
        $this->assertSame( [], $projection['form']['fields'] ?? null );
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

    public function test_stale_lock_takeover_does_not_delete_a_newer_competing_lock(): void
    {
        global $wpdb;

        $lock_option = 'sentient_forms_action_authority_migration_lock';
        $stale_lock  = [ 'token' => 'stale-owner', 'created_at' => time() - 600 ];
        $newer_lock  = [ 'token' => 'newer-owner', 'created_at' => time() ];
        add_option( $lock_option, $stale_lock, '', false );

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
            $method   = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'acquire_lock' );
            $acquired = $method->invoke( null );
        }
        finally
        {
            remove_filter( 'option_' . $lock_option, $install_competitor );
        }

        wp_cache_delete( $lock_option, 'options' );
        $this->assertFalse( $acquired );
        $this->assertSame( $newer_lock, get_option( $lock_option ) );
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

}
