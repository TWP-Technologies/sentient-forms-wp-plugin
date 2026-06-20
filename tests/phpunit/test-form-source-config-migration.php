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

        parent::tearDown();
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
}
