<?php

class Tests_Local_First_Schema_Repositories extends WP_UnitTestCase
{
    private wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        Sentient_Forms_Installer::maybe_upgrade();
    }

    public function test_local_first_tables_are_created(): void
    {
        $tables = [
            'sentient_provider_credentials',
            'sentient_external_service_consents',
            'sentient_action_templates',
            'sentient_custom_actions',
            'sentient_form_mappings',
            'sentient_submission_ledger_settings',
            'sentient_submission_ledger',
            'sentient_execution_events',
            'sentient_migration_runs',
            'sentient_model_cache',
        ];

        foreach ( $tables as $suffix )
        {
            $table = $this->wpdb->prefix . $suffix;
            $this->assertSame(
                $table,
                $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ),
                "{$suffix} table should exist"
            );
        }
    }

    public function test_execution_events_table_has_recent_read_index(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';
        $index = $this->wpdb->get_results(
            'SHOW INDEX FROM ' . esc_sql( $table ) . " WHERE Key_name = 'created_id_idx'",
            ARRAY_A
        );

        $this->assertCount( 2, $index );
        $this->assertSame( [ 'created_at', 'id' ], array_column( $index, 'Column_name' ) );
    }

    public function test_execution_events_table_has_form_recent_read_index(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';
        $index = $this->wpdb->get_results(
            'SHOW INDEX FROM ' . esc_sql( $table ) . " WHERE Key_name = 'form_created_id_idx'",
            ARRAY_A
        );

        $this->assertCount( 4, $index );
        $this->assertSame( [ 'form_source', 'form_id', 'created_at', 'id' ], array_column( $index, 'Column_name' ) );
    }

    public function test_form_mapping_transactions_are_reentrant_with_independent_savepoints(): void
    {
        $repository = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $create = static function ( string $form_id ) use ( $repository ): int | WP_Error {
            return $repository->create(
                [
                    'form_source'         => 'gravity_forms',
                    'form_id'             => $form_id,
                    'hook'                => 'after_submission',
                    'action_kind'         => 'custom_action',
                    'action_id'           => 1,
                    'input_bindings_json' => [],
                    'execution_mode'      => 'async',
                    'settings_json'       => [],
                    'enabled'             => true,
                ]
            );
        };

        $outer = $repository->transaction(
            function () use ( $repository, $create ): int | WP_Error {
                $outer_id = $create( 'nested-outer' );
                if ( is_wp_error( $outer_id ) )
                {
                    return $outer_id;
                }

                $inner = $repository->transaction(
                    function () use ( $create ): WP_Error {
                        $inner_id = $create( 'nested-inner' );
                        if ( is_wp_error( $inner_id ) )
                        {
                            return $inner_id;
                        }
                        return new WP_Error( 'sentient_forms_nested_fixture_rollback' );
                    }
                );
                $this->assertWPError( $inner );
                $this->assertSame( 'sentient_forms_nested_fixture_rollback', $inner->get_error_code() );
                return $outer_id;
            }
        );

        $this->assertIsInt( $outer );
        $this->assertCount( 1, $repository->list_for_form( 'gravity_forms', 'nested-outer' ) );
        $this->assertSame( [], $repository->list_for_form( 'gravity_forms', 'nested-inner' ) );
    }

    public function test_form_mapping_transaction_rejects_nontransactional_storage_before_callback(): void
    {
        $table = $this->wpdb->prefix . 'sentient_form_mappings_myisam_fixture';
        $this->wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
        $this->wpdb->query(
            'CREATE TEMPORARY TABLE `' . esc_sql( $table ) . '` ENGINE=MyISAM AS SELECT * FROM `'
            . esc_sql( $this->wpdb->prefix . 'sentient_form_mappings' )
            . '` WHERE 1 = 0'
        );
        $repository = new class( $this->wpdb, $table ) extends Sentient_Forms_Form_Mappings_Repository {
            public function __construct( wpdb $wpdb, private string $fixture_table )
            {
                parent::__construct( $wpdb );
            }

            protected function table_name(): string
            {
                return $this->fixture_table;
            }
        };
        $callback_ran = false;

        try
        {
            $result = $repository->transaction(
                static function () use ( &$callback_ran ): bool {
                    $callback_ran = true;
                    return true;
                }
            );
        }
        finally
        {
            $this->wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS `' . esc_sql( $table ) . '`' );
        }

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_nontransactional_mapping_store', $result->get_error_code() );
        $this->assertFalse( $callback_ran );
    }

    public function test_maybe_upgrade_converts_existing_form_mapping_table_to_innodb(): void
    {
        $table = $this->wpdb->prefix . 'sentient_form_mappings';
        $previous_engine_version = get_option( 'sentient_forms_form_mappings_engine_version', null );
        $this->assertNotFalse( $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) ) );
        delete_option( 'sentient_forms_form_mappings_engine_version' );
        $repository = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $before = $repository->transaction( static fn(): bool => true );
        $this->assertWPError( $before );
        $this->assertSame( 'sentient_forms_nontransactional_mapping_store', $before->get_error_code() );

        try
        {
            update_option( 'sentient_forms_db_version', SENTIENT_FORMS_DB_VERSION, false );
            Sentient_Forms_Installer::maybe_upgrade();

            $definition = $this->wpdb->get_row(
                $this->wpdb->prepare( 'SHOW CREATE TABLE %i', $table ),
                ARRAY_N
            );
            $this->assertIsArray( $definition );
            $this->assertMatchesRegularExpression( '/\bENGINE=InnoDB\b/i', (string) ( $definition[1] ?? '' ) );
            $this->assertSame( '2026.07.19.v1', get_option( 'sentient_forms_form_mappings_engine_version' ) );
            $this->assertTrue( $repository->transaction( static fn(): bool => true ) );
        }
        finally
        {
            $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
            if ( null === $previous_engine_version )
            {
                delete_option( 'sentient_forms_form_mappings_engine_version' );
            }
            else
            {
                update_option( 'sentient_forms_form_mappings_engine_version', $previous_engine_version, false );
            }
        }
    }

    public function test_maybe_upgrade_converts_existing_async_request_table_to_innodb(): void
    {
        $table = $this->wpdb->prefix . 'sentient_async_requests';
        $previous_engine_version = get_option( 'sentient_forms_async_requests_engine_version', null );
        $this->assertNotFalse( $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) ) );
        delete_option( 'sentient_forms_async_requests_engine_version' );
        $store = new Sentient_Forms_Async_Request_Store( $this->wpdb );
        $this->assertFalse( $store->uses_transactional_storage() );

        try
        {
            update_option( 'sentient_forms_db_version', SENTIENT_FORMS_DB_VERSION, false );
            Sentient_Forms_Installer::maybe_upgrade();

            $definition = $this->wpdb->get_row(
                $this->wpdb->prepare( 'SHOW CREATE TABLE %i', $table ),
                ARRAY_N
            );
            $this->assertIsArray( $definition );
            $this->assertMatchesRegularExpression( '/\bENGINE=InnoDB\b/i', (string) ( $definition[1] ?? '' ) );
            $this->assertSame( '2026.08.14.v1', get_option( 'sentient_forms_async_requests_engine_version' ) );
            $this->assertTrue( $store->uses_transactional_storage() );
        }
        finally
        {
            $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
            if ( null === $previous_engine_version )
            {
                delete_option( 'sentient_forms_async_requests_engine_version' );
            }
            else
            {
                update_option( 'sentient_forms_async_requests_engine_version', $previous_engine_version, false );
            }
        }
    }

    public function test_maybe_upgrade_converts_credential_authority_tables_to_innodb(): void
    {
        $tables = [
            $this->wpdb->prefix . 'sentient_provider_credentials',
            $this->wpdb->prefix . 'sentient_custom_actions',
        ];
        $option = 'sentient_forms_credential_authority_engine_version';
        $previous_engine_version = get_option( $option, null );
        foreach ( $tables as $table )
        {
            $this->assertNotFalse( $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ENGINE=MyISAM', $table ) ) );
        }
        delete_option( $option );

        try
        {
            update_option( 'sentient_forms_db_version', SENTIENT_FORMS_DB_VERSION, false );
            Sentient_Forms_Installer::maybe_upgrade();

            foreach ( $tables as $table )
            {
                $definition = $this->wpdb->get_row(
                    $this->wpdb->prepare( 'SHOW CREATE TABLE %i', $table ),
                    ARRAY_N
                );
                $this->assertIsArray( $definition );
                $this->assertMatchesRegularExpression( '/\bENGINE=InnoDB\b/i', (string) ( $definition[1] ?? '' ) );
            }
            $this->assertSame( '2026.08.15.v1', get_option( $option ) );
        }
        finally
        {
            foreach ( $tables as $table )
            {
                $this->wpdb->query( $this->wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
            }
            if ( null === $previous_engine_version )
            {
                delete_option( $option );
            }
            else
            {
                update_option( $option, $previous_engine_version, false );
            }
        }
    }

    public function test_maybe_upgrade_repairs_a_missing_async_request_table_when_requested(): void
    {
        $table        = $this->wpdb->prefix . 'sentient_async_requests';
        $backup_table = $this->wpdb->prefix . 'sentient_async_requests_missing_repair_fixture';
        $this->assertNotFalse( $this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $backup_table ) ) );
        $this->assertNotFalse(
            $this->wpdb->query(
                $this->wpdb->prepare( 'RENAME TABLE %i TO %i', $table, $backup_table )
            )
        );

        try
        {
            update_option( 'sentient_forms_db_version', SENTIENT_FORMS_DB_VERSION, false );
            Sentient_Forms_Installer::maybe_upgrade( true );

            $this->assertTrue( ( new Sentient_Forms_Async_Request_Store( $this->wpdb ) )->uses_transactional_storage() );
        }
        finally
        {
            $this->wpdb->query( $this->wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
            $this->wpdb->query(
                $this->wpdb->prepare( 'RENAME TABLE %i TO %i', $backup_table, $table )
            );
        }
    }

    public function test_submission_ledger_tables_and_execution_submission_uuid_index_exist(): void
    {
        $settings_table = $this->wpdb->prefix . 'sentient_submission_ledger_settings';
        $ledger_table   = $this->wpdb->prefix . 'sentient_submission_ledger';
        $events_table   = $this->wpdb->prefix . 'sentient_execution_events';

        $this->assertSame(
            $settings_table,
            $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $settings_table ) )
        );
        $this->assertSame(
            $ledger_table,
            $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $ledger_table ) )
        );

        $event_columns = $this->wpdb->get_results( 'DESCRIBE ' . esc_sql( $events_table ), ARRAY_A );
        $this->assertContains( 'submission_uuid', array_column( $event_columns, 'Field' ) );

        $event_index = $this->wpdb->get_results(
            'SHOW INDEX FROM ' . esc_sql( $events_table ) . " WHERE Key_name = 'form_submission_idx'",
            ARRAY_A
        );
        $this->assertCount( 3, $event_index );
        $this->assertSame( [ 'form_source', 'form_id', 'submission_uuid' ], array_column( $event_index, 'Column_name' ) );

        $settings_index = $this->wpdb->get_results(
            'SHOW INDEX FROM ' . esc_sql( $settings_table ) . " WHERE Key_name = 'form_unique'",
            ARRAY_A
        );
        $this->assertCount( 2, $settings_index );
        $this->assertSame( [ 'form_source', 'form_id' ], array_column( $settings_index, 'Column_name' ) );

        $ledger_index = $this->wpdb->get_results(
            'SHOW INDEX FROM ' . esc_sql( $ledger_table ) . " WHERE Key_name = 'submission_unique'",
            ARRAY_A
        );
        $this->assertCount( 1, $ledger_index );
        $this->assertSame( [ 'submission_uuid' ], array_column( $ledger_index, 'Column_name' ) );

        $ledger_columns = $this->wpdb->get_results( 'DESCRIBE ' . esc_sql( $ledger_table ), ARRAY_A );
        $this->assertContains( 'native_correlation_hash', array_column( $ledger_columns, 'Field' ) );

        $native_correlation_index = $this->wpdb->get_results(
            'SHOW INDEX FROM ' . esc_sql( $ledger_table ) . " WHERE Key_name = 'native_correlation_unique'",
            ARRAY_A
        );
        $this->assertCount( 1, $native_correlation_index );
        $this->assertSame( [ 'native_correlation_hash' ], array_column( $native_correlation_index, 'Column_name' ) );
        $this->assertSame( '0', (string) ( $native_correlation_index[0]['Non_unique'] ?? '' ) );
    }

    public function test_provider_credentials_repository_records_local_openrouter_credentials(): void
    {
        $repository = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );

        $id = $repository->create(
            [
                'provider'      => 'openrouter',
                'label'         => 'Owner key',
                'auth_mode'     => 'constant',
                'constant_name' => 'SENTIENT_FORMS_OPENROUTER_KEY',
                'status_json'   => [
                    'limit_remaining' => 99,
                ],
            ]
        );

        $this->assertIsInt( $id );

        $credential = $repository->get( $id );
        $this->assertSame( 'openrouter', $credential['provider'] );
        $this->assertSame( 'constant', $credential['auth_mode'] );
        $this->assertSame( [ 'limit_remaining' => 99 ], $credential['status_json'] );

        $updated = $repository->update_status( $id, 'valid', [ 'validated' => true ] );
        $this->assertTrue( $updated );
        $this->assertSame( 'valid', $repository->get( $id )['status'] );
    }

    public function test_local_first_repository_timestamps_use_utc_mysql_values(): void
    {
        $original_timezone  = get_option( 'timezone_string' );
        $original_offset    = get_option( 'gmt_offset' );
        $timezone_was_set   = update_option( 'timezone_string', 'Pacific/Kiritimati' );
        $offset_was_set     = update_option( 'gmt_offset', 14 );
        $credential_records = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $consent_records    = new Sentient_Forms_External_Service_Consent_Repository( $this->wpdb );
        $template_records   = new Sentient_Forms_Action_Templates_Repository( $this->wpdb );
        $custom_records     = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $mapping_records    = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $event_records      = new Sentient_Forms_Execution_Events_Repository( $this->wpdb );
        $migration_records  = new Sentient_Forms_Migration_Runs_Repository( $this->wpdb );
        $model_records      = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );

        try
        {
            $window_started = time();

            $credential_id = $credential_records->create(
                [
                    'provider'  => 'openrouter',
                    'label'     => 'UTC credential',
                    'auth_mode' => 'constant',
                ]
            );
            $this->assertIsInt( $credential_id );
            $credential = $credential_records->get( $credential_id );

            $consent_id = $consent_records->record( 'openrouter', 'timestamp-policy' );
            $this->assertIsInt( $consent_id );
            $consent = $consent_records->latest_for_provider( 'openrouter' );

            $template_id = $template_records->upsert_by_code(
                [
                    'source'          => 'bundled',
                    'code'            => 'utc_template',
                    'display_name'    => 'UTC Template',
                    'prompt_template' => 'Check UTC timestamps.',
                ]
            );
            $this->assertIsInt( $template_id );
            $template = $template_records->get( $template_id );

            $custom_id = $custom_records->create(
                [
                    'code'            => 'utc_custom',
                    'display_name'    => 'UTC Custom',
                    'definition_json' => [
                        'prompt' => 'Use UTC.',
                    ],
                ]
            );
            $this->assertIsInt( $custom_id );
            $custom = $custom_records->get( $custom_id );

            $mapping_id = $mapping_records->create(
                [
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'utc-form',
                    'hook'                => 'gform_after_submission',
                    'action_kind'         => 'custom',
                    'action_id'           => $custom_id,
                    'input_bindings_json' => [
                        'message' => 'field_1',
                    ],
                ]
            );
            $this->assertIsInt( $mapping_id );
            $mapping = $mapping_records->get( $mapping_id );

            $event_id = $event_records->record(
                [
                    'execution_request_id' => 'req-utc-timestamp',
                    'mapping_id'           => $mapping_id,
                    'provider'             => 'openrouter',
                ]
            );
            $this->assertIsInt( $event_id );
            $event = $event_records->get_by_request_id( 'req-utc-timestamp' );

            $migration_id = $migration_records->create(
                [
                    'source'       => 'cps_export',
                    'summary_json' => [
                        'checked' => true,
                    ],
                ]
            );
            $this->assertIsInt( $migration_id );
            $this->assertTrue( $migration_records->mark_finished( $migration_id, 'completed' ) );
            $migration = $migration_records->get( $migration_id );

            $model_expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
            $this->assertTrue(
                $model_records->upsert(
                    'openrouter',
                    'utc/model',
                    [
                        'free' => true,
                    ],
                    $model_expires_at
                )
            );
            $model = $model_records->get( 'openrouter', 'utc/model', false );

            $window_finished = time();

            foreach (
                [
                    'credential.created_at' => $credential['created_at'] ?? '',
                    'credential.updated_at' => $credential['updated_at'] ?? '',
                    'consent.accepted_at'   => $consent['accepted_at'] ?? '',
                    'template.created_at'   => $template['created_at'] ?? '',
                    'template.updated_at'   => $template['updated_at'] ?? '',
                    'custom.created_at'     => $custom['created_at'] ?? '',
                    'custom.updated_at'     => $custom['updated_at'] ?? '',
                    'mapping.created_at'    => $mapping['created_at'] ?? '',
                    'mapping.updated_at'    => $mapping['updated_at'] ?? '',
                    'event.created_at'      => $event['created_at'] ?? '',
                    'event.updated_at'      => $event['updated_at'] ?? '',
                    'migration.started_at'  => $migration['started_at'] ?? '',
                    'migration.finished_at' => $migration['finished_at'] ?? '',
                    'model.fetched_at'      => $model['fetched_at'] ?? '',
                ] as $field => $value
            )
            {
                $this->assert_mysql_datetime_within_utc_window( $field, (string) $value, $window_started, $window_finished );
            }
        }
        finally
        {
            if ( false !== $original_timezone || $timezone_was_set )
            {
                update_option( 'timezone_string', $original_timezone );
            }

            if ( false !== $original_offset || $offset_was_set )
            {
                update_option( 'gmt_offset', $original_offset );
            }
        }
    }

    public function test_external_service_consent_repository_keeps_provider_version_history(): void
    {
        $repository = new Sentient_Forms_External_Service_Consent_Repository( $this->wpdb );
        $user_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $id = $repository->record(
            'openrouter',
            '2026-04-16',
            $user_id,
            [
                'terms_url'   => 'https://openrouter.ai/terms',
                'privacy_url' => 'https://openrouter.ai/privacy',
            ]
        );

        $this->assertIsInt( $id );

        $latest = $repository->latest_for_provider( 'openrouter' );
        $this->assertSame( '2026-04-16', $latest['disclosure_version'] );
        $this->assertSame( $user_id, (int) $latest['accepted_by_user_id'] );
        $this->assertSame( 'https://openrouter.ai/terms', $latest['metadata_json']['terms_url'] );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $latest['site_url_hash'] );
    }

    public function test_submission_ledger_settings_repository_defaults_disabled_and_tracks_opt_in_actor(): void
    {
        $repository = new Sentient_Forms_Submission_Ledger_Settings_Repository( $this->wpdb );
        $user_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $default = $repository->get_or_default( 'gravity_forms', '301' );
        $this->assertFalse( $default['enabled'] );
        $this->assertNull( $default['enabled_at'] );
        $this->assertNull( $default['enabled_by_user_id'] );

        $enabled = $repository->set_enabled( 'gravity_forms', '301', true, $user_id );
        $this->assertIsArray( $enabled );
        $this->assertTrue( $enabled['enabled'] );
        $this->assertSame( $user_id, (int) $enabled['enabled_by_user_id'] );
        $this->assertNotEmpty( $enabled['enabled_at'] );
        $this->assertNull( $enabled['disabled_at'] );

        $disabled = $repository->set_enabled( 'gravity_forms', '301', false, $user_id );
        $this->assertIsArray( $disabled );
        $this->assertFalse( $disabled['enabled'] );
        $this->assertSame( $user_id, (int) $disabled['disabled_by_user_id'] );
        $this->assertNotEmpty( $disabled['disabled_at'] );
        $this->assertSame( $enabled['id'], $disabled['id'] );
    }

    public function test_submission_ledger_repository_round_trips_logical_snapshot_by_submission_uuid(): void
    {
        $repository      = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );
        $submission_uuid = wp_generate_uuid4();

        $id = $repository->create(
            [
                'submission_uuid'        => $submission_uuid,
                'form_source'            => 'gravity_forms',
                'form_id'                => '302',
                'native_entry_id'        => '88',
                'native_entry_url'       => 'https://example.test/wp-admin/admin.php?page=gf_entries&view=entry&id=302&lid=88',
                'source_submitted_at'    => '2026-06-18 18:45:00',
                'logical_fields_json'    => [
                    'email'   => 'person@example.test',
                    'message' => 'Need help with pricing.',
                ],
                'provider_metadata_json' => [
                    'entry_type' => 'gravity_forms_entry',
                ],
                'file_refs_json'         => [
                    [
                        'field_id' => '9',
                        'filename' => 'proposal.pdf',
                    ],
                ],
                'redaction_summary_json' => [
                    'redacted_fields' => [ 'captcha' ],
                ],
                'expires_at'             => '2026-09-18 18:45:00',
            ]
        );

        $this->assertIsInt( $id );

        $stored = $repository->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'gravity_forms', $stored['form_source'] ?? null );
        $this->assertSame( '302', $stored['form_id'] ?? null );
        $this->assertSame( '88', $stored['native_entry_id'] ?? null );
        $this->assertSame( 'person@example.test', $stored['logical_fields_json']['email'] ?? null );
        $this->assertSame( 'gravity_forms_entry', $stored['provider_metadata_json']['entry_type'] ?? null );
        $this->assertSame( 'proposal.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertSame( [ 'captcha' ], $stored['redaction_summary_json']['redacted_fields'] ?? null );

        $list = $repository->list_for_form( 'gravity_forms', '302' );
        $this->assertCount( 1, $list );
        $this->assertSame( $submission_uuid, $list[0]['submission_uuid'] ?? null );
    }

    public function test_submission_ledger_repository_rejects_empty_form_scope(): void
    {
        $repository = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );

        $missing_source = $repository->create(
            [
                'submission_uuid'     => wp_generate_uuid4(),
                'form_source'         => '',
                'form_id'             => '302',
                'logical_fields_json' => [ 'email' => 'person@example.test' ],
            ]
        );
        $missing_form_id = $repository->create(
            [
                'submission_uuid'     => wp_generate_uuid4(),
                'form_source'         => 'gravity_forms',
                'form_id'             => '',
                'logical_fields_json' => [ 'email' => 'person@example.test' ],
            ]
        );

        $this->assertWPError( $missing_source );
        $this->assertSame( 'sentient_forms_invalid_submission_ledger_scope', $missing_source->get_error_code() );
        $this->assertWPError( $missing_form_id );
        $this->assertSame( 'sentient_forms_invalid_submission_ledger_scope', $missing_form_id->get_error_code() );
        $this->assertSame( 0, $repository->count_all() );
    }

    public function test_submission_ledger_repository_enforces_one_correlation_claim_per_native_identity(): void
    {
        $repository = new Sentient_Forms_Submission_Ledger_Repository( $this->wpdb );
        $first_uuid = wp_generate_uuid4();

        $first = $repository->create(
            [
                'submission_uuid'     => $first_uuid,
                'form_source'         => 'gravity_forms',
                'form_id'             => 'native-correlation-unique',
                'native_entry_id'     => 'native-entry-1',
                'logical_fields_json' => [ 'email' => 'first@example.test' ],
            ]
        );
        $suppress_errors = $this->wpdb->suppress_errors( true );
        try
        {
            $duplicate = $repository->create(
                [
                    'submission_uuid'     => wp_generate_uuid4(),
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'native-correlation-unique',
                    'native_entry_id'     => 'native-entry-1',
                    'logical_fields_json' => [ 'email' => 'second@example.test' ],
                ]
            );
        }
        finally
        {
            $this->wpdb->suppress_errors( $suppress_errors );
        }
        $hash = Sentient_Forms_Submission_Ledger_Repository::native_correlation_hash(
            'gravity_forms',
            'native-correlation-unique',
            'native-entry-1'
        );

        $this->assertIsInt( $first );
        $this->assertWPError( $duplicate );
        $this->assertSame( 'sentient_forms_db_insert_failed', $duplicate->get_error_code() );
        $this->assertSame( $first_uuid, $repository->get_by_native_correlation_hash( (string) $hash )['submission_uuid'] ?? null );
    }

    public function test_execution_events_repository_records_submission_uuid_without_requiring_it_for_legacy_rows(): void
    {
        $events          = new Sentient_Forms_Execution_Events_Repository( $this->wpdb );
        $submission_uuid = wp_generate_uuid4();

        $linked_id = $events->record(
            [
                'execution_request_id' => 'req-ledger-linked',
                'form_source'          => 'gravity_forms',
                'form_id'              => '303',
                'submission_uuid'      => $submission_uuid,
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
            ]
        );
        $legacy_id = $events->record(
            [
                'execution_request_id' => 'req-ledger-legacy',
                'form_source'          => 'gravity_forms',
                'form_id'              => '303',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
            ]
        );

        $this->assertIsInt( $linked_id );
        $this->assertIsInt( $legacy_id );
        $this->assertSame( $submission_uuid, $events->get_by_request_id( 'req-ledger-linked' )['submission_uuid'] ?? null );
        $this->assertNull( $events->get_by_request_id( 'req-ledger-legacy' )['submission_uuid'] ?? null );
    }

    public function test_execution_events_repository_preserves_action_identity_across_lifecycle_updates(): void
    {
        $events = new Sentient_Forms_Execution_Events_Repository( $this->wpdb );

        $event_id = $events->record(
            [
                'execution_request_id' => 'req-preserve-action-identity',
                'mapping_id'           => 112,
                'mapping_key'          => 'entry-summary-mapping',
                'action_code'          => 'entry_summary_v1',
                'action_label'         => 'Entry Summary',
                'form_source'          => 'gravity_forms',
                'form_id'              => '793',
                'entry_id'             => '1629',
                'provider'             => 'sentient_managed',
                'status'               => 'queued',
            ]
        );

        $updated_event_id = $events->record(
            [
                'execution_request_id' => 'req-preserve-action-identity',
                'mapping_id'           => 112,
                'form_source'          => 'gravity_forms',
                'form_id'              => '793',
                'entry_id'             => '1629',
                'provider'             => 'sentient_managed',
                'model'                => 'google/gemini-3-flash-preview',
                'status'               => 'succeeded',
                'result_json'          => [ 'summary' => 'Qualified browser result.' ],
            ]
        );

        $event = $events->get_by_request_id( 'req-preserve-action-identity' );

        $this->assertSame( $event_id, $updated_event_id );
        $this->assertSame( 'succeeded', $event['status'] ?? null );
        $this->assertSame( 'entry-summary-mapping', $event['mapping_key'] ?? null );
        $this->assertSame( 'entry_summary_v1', $event['action_code'] ?? null );
        $this->assertSame( 'Entry Summary', $event['action_label'] ?? null );
    }

    public function test_template_custom_action_mapping_and_execution_event_repositories_round_trip(): void
    {
        $templates = new Sentient_Forms_Action_Templates_Repository( $this->wpdb );
        $custom    = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $mappings  = new Sentient_Forms_Form_Mappings_Repository( $this->wpdb );
        $events    = new Sentient_Forms_Execution_Events_Repository( $this->wpdb );

        $template_id = $templates->upsert_by_code(
            [
                'source'                   => 'bundled',
                'code'                     => 'spam_check',
                'display_name'             => 'Spam Check',
                'prompt_template'          => 'Classify {{entry}}.',
                'version'                  => '1.0.0',
                'is_active'                => true,
                'structured_output_schema' => [
                    'type' => 'object',
                ],
            ]
        );

        $this->assertIsInt( $template_id );
        $this->assertSame( 'Spam Check', $templates->get_by_code( 'spam_check' )['display_name'] );

        $custom_id = $custom->create(
            [
                'template_id'          => $template_id,
                'code'                 => 'spam_check_site',
                'display_name'         => 'Site Spam Check',
                'definition_json'      => [
                    'prompt' => 'Use local policy.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ]
        );

        $this->assertIsInt( $custom_id );
        $this->assertSame( 'Use local policy.', $custom->get_by_code( 'spam_check_site' )['definition_json']['prompt'] );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '12',
                'hook'                => 'gform_validation',
                'action_kind'         => 'custom',
                'action_id'           => $custom_id,
                'input_bindings_json' => [
                    'message' => 'field_1',
                ],
                'execution_mode'      => 'sync',
                'effect_mapping_json' => [
                    'reject_on_spam' => true,
                ],
                'enabled'             => true,
            ]
        );

        $this->assertIsInt( $mapping_id );
        $this->assertCount( 1, $mappings->list_for_form( 'gravity_forms', '12' ) );

        $event_id = $events->record(
            [
                'execution_request_id' => 'req-local-first-1',
                'mapping_id'           => $mapping_id,
                'form_source'          => 'gravity_forms',
                'form_id'              => '12',
                'entry_id'             => '34',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'token_usage_json'     => [
                    'prompt_tokens'     => 10,
                    'completion_tokens' => 4,
                ],
                'result_json'          => [
                    'classification' => 'ham',
                ],
            ]
        );

        $this->assertIsInt( $event_id );
        $event = $events->get_by_request_id( 'req-local-first-1' );
        $this->assertSame( 'ham', $event['result_json']['classification'] );

        $same_event_id = $events->record(
            [
                'execution_request_id' => 'req-local-first-1',
                'mapping_id'           => $mapping_id,
                'provider'             => 'openrouter',
                'status'               => 'failed',
                'error_code'           => 'provider_timeout',
            ]
        );

        $this->assertSame( $event_id, $same_event_id );
        $this->assertSame( 'failed', $events->get_by_request_id( 'req-local-first-1' )['status'] );
    }

    public function test_execution_events_repository_loads_only_latest_event_per_form(): void
    {
        $events = new Sentient_Forms_Execution_Events_Repository( $this->wpdb );
        $table  = $this->wpdb->prefix . 'sentient_execution_events';

        $old_form_one_id = $events->record(
            [
                'execution_request_id' => 'req-latest-form-101-old',
                'form_source'          => 'gravity_forms',
                'form_id'              => '101',
                'provider'             => 'openrouter',
                'status'               => 'queued',
            ]
        );
        $new_form_one_id = $events->record(
            [
                'execution_request_id' => 'req-latest-form-101-new',
                'form_source'          => 'gravity_forms',
                'form_id'              => '101',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
            ]
        );
        $backfilled_form_one_id = $events->record(
            [
                'execution_request_id' => 'req-latest-form-101-backfilled',
                'form_source'          => 'gravity_forms',
                'form_id'              => '101',
                'provider'             => 'openrouter',
                'status'               => 'skipped',
            ]
        );
        $old_form_two_id = $events->record(
            [
                'execution_request_id' => 'req-latest-form-102-old',
                'form_source'          => 'gravity_forms',
                'form_id'              => '102',
                'provider'             => 'openrouter',
                'status'               => 'running',
            ]
        );
        $new_form_two_id = $events->record(
            [
                'execution_request_id' => 'req-latest-form-102-new',
                'form_source'          => 'gravity_forms',
                'form_id'              => '102',
                'provider'             => 'openrouter',
                'status'               => 'failed',
            ]
        );

        foreach (
            [
                $old_form_one_id => '2026-05-30 10:00:00',
                $new_form_one_id => '2026-05-30 10:05:00',
                $backfilled_form_one_id => '2026-05-30 09:55:00',
                $old_form_two_id => '2026-05-30 10:00:00',
                $new_form_two_id => '2026-05-30 10:00:00',
            ] as $event_id => $created_at
        )
        {
            $this->assertNotFalse(
                $this->wpdb->update(
                    $table,
                    [
                        'created_at' => $created_at,
                        'updated_at' => $created_at,
                    ],
                    [ 'id' => $event_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                )
            );
        }

        $latest = $events->get_latest_for_forms( 'gravity_forms', [ 101, 102, 102 ] );

        $this->assertCount( 2, $latest );
        $this->assertSame( 'succeeded', $latest['101']['status'] );
        $this->assertSame( (string) $new_form_one_id, (string) $latest['101']['id'] );
        $this->assertSame( 'failed', $latest['102']['status'] );
        $this->assertSame( (string) $new_form_two_id, (string) $latest['102']['id'] );
    }

    public function test_action_template_upsert_recovers_from_duplicate_insert_race(): void
    {
        $initial_repository = new Sentient_Forms_Action_Templates_Repository( $this->wpdb );
        $template_id        = $initial_repository->upsert_by_code(
            [
                'source'          => 'bundled',
                'code'            => 'race_template',
                'display_name'    => 'Race Template',
                'prompt_template' => 'Initial prompt.',
            ]
        );

        $this->assertIsInt( $template_id );

        $race_repository = new class( $this->wpdb ) extends Sentient_Forms_Action_Templates_Repository
        {
            private bool $first_lookup = true;

            public function get_by_code( string $code ): ?array
            {
                if ( $this->first_lookup )
                {
                    $this->first_lookup = false;
                    return null;
                }

                return parent::get_by_code( $code );
            }
        };

        $upserted_id = $race_repository->upsert_by_code(
            [
                'source'          => 'bundled',
                'code'            => 'race_template',
                'display_name'    => 'Race Template Updated',
                'prompt_template' => 'Recovered prompt.',
            ]
        );

        $this->assertSame( $template_id, $upserted_id );
        $this->assertSame( 'Race Template Updated', $initial_repository->get_by_code( 'race_template' )['display_name'] );
    }

    public function test_local_custom_action_upsert_recovers_from_duplicate_insert_race(): void
    {
        $initial_repository = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );
        $action_id          = $initial_repository->upsert_by_code(
            [
                'code'            => 'race_custom_action',
                'display_name'    => 'Race Custom Action',
                'definition_json' => [
                    'prompt' => 'Initial custom prompt.',
                ],
            ]
        );

        $this->assertIsInt( $action_id );

        $race_repository = new class( $this->wpdb ) extends Sentient_Forms_Local_Custom_Actions_Repository
        {
            private bool $first_lookup = true;

            public function get_by_code( string $code ): ?array
            {
                if ( $this->first_lookup )
                {
                    $this->first_lookup = false;
                    return null;
                }

                return parent::get_by_code( $code );
            }
        };

        $upserted_id = $race_repository->upsert_by_code(
            [
                'code'            => 'race_custom_action',
                'display_name'    => 'Race Custom Action Updated',
                'definition_json' => [
                    'prompt' => 'Recovered custom prompt.',
                ],
            ]
        );

        $this->assertSame( $action_id, $upserted_id );
        $this->assertSame( 'Race Custom Action Updated', $initial_repository->get_by_code( 'race_custom_action' )['display_name'] );
    }

    public function test_repository_json_fields_reject_non_array_values(): void
    {
        $custom = new Sentient_Forms_Local_Custom_Actions_Repository( $this->wpdb );

        $result = $custom->create(
            [
                'code'            => 'bad_json',
                'display_name'    => 'Bad JSON',
                'definition_json' => '{"not":"an array for this API"}',
            ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_invalid_json_field', $result->get_error_code() );
    }

    public function test_migration_runs_and_model_cache_support_cutover_foundation(): void
    {
        $migrations = new Sentient_Forms_Migration_Runs_Repository( $this->wpdb );
        $models     = new Sentient_Forms_Model_Cache_Repository( $this->wpdb );

        $run_id = $migrations->create(
            [
                'source'       => 'cps_export',
                'status'       => 'dry_run',
                'dry_run'      => true,
                'summary_json' => [
                    'templates' => 2,
                    'mappings'  => 1,
                ],
            ]
        );

        $this->assertIsInt( $run_id );
        $this->assertTrue( $migrations->mark_finished( $run_id, 'completed', [ 'imported' => 3 ] ) );
        $this->assertSame( 3, $migrations->get( $run_id )['summary_json']['imported'] );

        $this->assertTrue(
            $models->upsert(
                'openrouter',
                'openrouter/auto',
                [
                    'free'       => true,
                    'capability' => 'text',
                ],
                gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
            )
        );

        $model = $models->get( 'openrouter', 'openrouter/auto' );
        $this->assertSame( true, $model['metadata_json']['free'] );
    }

    private function assert_mysql_datetime_within_utc_window( string $field, string $value, int $window_started, int $window_finished ): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $value,
            "{$field} should be stored as a MySQL datetime string."
        );

        $epoch = strtotime( $value . ' UTC' );
        $this->assertIsInt( $epoch, "{$field} should parse as a UTC timestamp." );
        $this->assertGreaterThanOrEqual( $window_started - 2, $epoch, "{$field} should not be stored in site-local future time." );
        $this->assertLessThanOrEqual( $window_finished + 2, $epoch, "{$field} should not be stored in site-local past time." );
    }
}
