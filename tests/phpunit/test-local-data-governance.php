<?php

class Tests_Local_Data_Governance extends WP_UnitTestCase
{
    private wpdb $wpdb;
    private Sentient_Forms_Execution_Events_Repository $events;
    private Sentient_Forms_Submission_Ledger_Repository $submission_ledger;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;

        Sentient_Forms_Installer::maybe_upgrade();
        $this->events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $this->submission_ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
    }

    protected function tearDown(): void
    {
        delete_option( 'sentient_forms_execution_event_retention_days' );
        delete_option( 'sentient_forms_submission_ledger_retention_days' );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_version' );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1' );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1' );
        delete_option( 'sentient_forms_delete_data_on_uninstall' );
        delete_option( 'sentient_forms_store_full_ai_outputs' );
        delete_option( 'sentient_forms_privacy_setup_profile' );
        delete_option( 'sentient_forms_privacy_setup_completed_at' );
        Sentient_Forms_Installer::maybe_upgrade();

        parent::tearDown();
    }

    public function test_privacy_exporter_and_eraser_handle_local_execution_content(): void
    {
        $email = 'privacy-person@example.test';

        $this->events->record(
            [
                'execution_request_id' => 'privacy-export-1',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'failed',
                'result_json'          => [
                    'content' => 'Follow up with ' . $email,
                ],
                'error_message'        => 'Provider rejected payload for ' . $email,
                'expires_at'           => null,
            ]
        );

        $export = Sentient_Forms_Local_Data_Governance::export_personal_data( $email, 1 );
        $this->assertFalse( $export['done'] === false && [] === $export['data'] );
        $this->assertCount( 1, $export['data'] );
        $this->assertStringContainsString( $email, wp_json_encode( $export['data'] ) );

        $erase = Sentient_Forms_Local_Data_Governance::erase_personal_data( $email, 1 );
        $this->assertTrue( $erase['items_removed'] );
        $this->assertTrue( $erase['items_retained'] );
        $this->assertTrue( $erase['done'] );

        $event = $this->events->get_by_request_id( 'privacy-export-1' );
        $this->assertTrue( $event['result_json']['personal_data_erased'] );
        $this->assertNull( $event['error_message'] );

        $after_export = Sentient_Forms_Local_Data_Governance::export_personal_data( $email, 1 );
        $this->assertSame( [], $after_export['data'] );
        $this->assertTrue( $after_export['done'] );
    }

    public function test_execution_event_retention_defaults_and_cleanup(): void
    {
        update_option( 'sentient_forms_execution_event_retention_days', 7 );

        $this->events->record(
            [
                'execution_request_id' => 'retention-default-1',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
            ]
        );

        $event = $this->events->get_by_request_id( 'retention-default-1' );
        $this->assertNotEmpty( $event['expires_at'] );

        $this->events->record(
            [
                'execution_request_id' => 'retention-expired-1',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
                'expires_at'           => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
            ]
        );

        $deleted = Sentient_Forms_Local_Data_Governance::run_retention_cleanup();
        $this->assertGreaterThanOrEqual( 1, $deleted );
        $this->assertNull( $this->events->get_by_request_id( 'retention-expired-1' ) );
    }

    public function test_submission_ledger_retention_cleanup_removes_expired_rows(): void
    {
        $expired_id = $this->submission_ledger->create(
            [
                'submission_uuid'     => '11111111-1111-4111-8111-111111111111',
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'logical_fields_json' => [ 'email' => 'expired@example.test' ],
                'expires_at'          => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
            ]
        );
        $fresh_id = $this->submission_ledger->create(
            [
                'submission_uuid'     => '22222222-2222-4222-8222-222222222222',
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'logical_fields_json' => [ 'email' => 'fresh@example.test' ],
                'expires_at'          => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
            ]
        );

        $this->assertIsInt( $expired_id );
        $this->assertIsInt( $fresh_id );

        $deleted = Sentient_Forms_Local_Data_Governance::run_retention_cleanup();

        $this->assertGreaterThanOrEqual( 1, $deleted );
        $this->assertNull( $this->submission_ledger->get_by_submission_uuid( '11111111-1111-4111-8111-111111111111' ) );
        $this->assertNotNull( $this->submission_ledger->get_by_submission_uuid( '22222222-2222-4222-8222-222222222222' ) );
    }

    public function test_new_submission_ledger_retention_inherits_existing_execution_policy(): void
    {
        delete_option( 'sentient_forms_submission_ledger_retention_days' );
        Sentient_Forms_Local_Data_Governance::update_execution_event_retention_days( 30 );

        $this->assertSame( 30, Sentient_Forms_Local_Data_Governance::current_submission_ledger_retention_days() );
    }

    public function test_upgrade_backfills_ledger_expiry_with_migration_floor(): void
    {
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 90 );
        $migration_time = '2026-07-10 12:00:00';
        $filter = static fn (): string => $migration_time;
        add_filter( 'sentient_forms_submission_ledger_retention_migration_time', $filter );

        try
        {
            $old_uuid    = wp_generate_uuid4();
            $recent_uuid = wp_generate_uuid4();
            $this->assertIsInt(
                $this->submission_ledger->create(
                    [
                        'submission_uuid'     => $old_uuid,
                        'form_source'         => 'gravity_forms',
                        'form_id'             => 'backfill',
                        'captured_at'         => '2025-01-01 00:00:00',
                        'logical_fields_json' => [ 'email' => 'old@example.test' ],
                    ]
                )
            );
            $this->assertIsInt(
                $this->submission_ledger->create(
                    [
                        'submission_uuid'     => $recent_uuid,
                        'form_source'         => 'gravity_forms',
                        'form_id'             => 'backfill',
                        'captured_at'         => '2026-07-01 00:00:00',
                        'logical_fields_json' => [ 'email' => 'recent@example.test' ],
                    ]
                )
            );

            delete_option( 'sentient_forms_submission_ledger_retention_backfill_version' );
            update_option( 'sentient_forms_db_version', '2026.07.10.elementor_pro_forms_identifier' );
            Sentient_Forms_Installer::maybe_upgrade();

            $this->assertSame( '2026-08-09 12:00:00', $this->submission_ledger->get_by_submission_uuid( $old_uuid )['expires_at'] );
            $this->assertSame( '2026-09-29 00:00:00', $this->submission_ledger->get_by_submission_uuid( $recent_uuid )['expires_at'] );
            $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
            $this->assertSame( '2026.07.10.v1', get_option( 'sentient_forms_submission_ledger_retention_backfill_version' ) );
        }
        finally
        {
            remove_filter( 'sentient_forms_submission_ledger_retention_migration_time', $filter );
        }
    }

    public function test_upgrade_leaves_existing_ledger_rows_unexpired_for_manual_retention(): void
    {
        $uuid = wp_generate_uuid4();
        $this->assertIsInt(
            $this->submission_ledger->create(
                [
                    'submission_uuid'     => $uuid,
                    'form_source'         => 'contact_form_7',
                    'form_id'             => 'manual-backfill',
                    'captured_at'         => '2026-07-01 00:00:00',
                    'logical_fields_json' => [ 'email' => 'manual-backfill@example.test' ],
                    'expires_at'          => '2026-07-20 00:00:00',
                ]
            )
        );

        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 0 );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_version' );
        update_option( 'sentient_forms_db_version', '2026.07.10.elementor_pro_forms_identifier' );

        Sentient_Forms_Installer::maybe_upgrade();

        $this->assertNull( $this->submission_ledger->get_by_submission_uuid( $uuid )['expires_at'] );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_upgrade_does_not_advance_version_when_ledger_backfill_fails_and_can_retry(): void
    {
        $uuid = wp_generate_uuid4();
        $this->assertIsInt(
            $this->submission_ledger->create(
                [
                    'submission_uuid'     => $uuid,
                    'form_source'         => 'wpforms',
                    'form_id'             => 'retry-backfill',
                    'captured_at'         => '2026-07-01 00:00:00',
                    'logical_fields_json' => [ 'email' => 'retry@example.test' ],
                ]
            )
        );

        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 90 );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_version' );
        $previous_version = '2026.07.10.elementor_pro_forms_identifier';
        update_option( 'sentient_forms_db_version', $previous_version );

        $fail_backfill = static function ( string $query ): string {
            if ( str_contains( $query, 'sentient_submission_ledger' ) && str_contains( $query, 'SET expires_at' ) )
            {
                return 'UPDATE sentient_forms_missing_backfill_table SET expires_at = NULL';
            }

            return $query;
        };
        add_filter( 'query', $fail_backfill );
        $suppress_errors = $this->wpdb->suppress_errors( true );

        try
        {
            Sentient_Forms_Installer::maybe_upgrade();

            $this->assertSame( $previous_version, get_option( 'sentient_forms_db_version' ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_version', false ) );
            $this->assertNull( $this->submission_ledger->get_by_submission_uuid( $uuid )['expires_at'] );
        }
        finally
        {
            remove_filter( 'query', $fail_backfill );
            $this->wpdb->suppress_errors( $suppress_errors );
        }

        Sentient_Forms_Installer::maybe_upgrade();

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertNotNull( $this->submission_ledger->get_by_submission_uuid( $uuid )['expires_at'] );
        $this->assertSame( '2026.07.10.v1', get_option( 'sentient_forms_submission_ledger_retention_backfill_version' ) );
    }

    public function test_upgrade_resumes_bounded_keyset_batches_across_requests(): void
    {
        $this->reset_submission_ledger_backfill_state();
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 90 );

        $ids = [];
        foreach ( [ 'first', 'second', 'third' ] as $label )
        {
            $ids[] = $this->submission_ledger->create(
                [
                    'submission_uuid'     => wp_generate_uuid4(),
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'bounded-backfill',
                    'captured_at'         => '2026-07-01 00:00:00',
                    'logical_fields_json' => [ 'label' => $label ],
                ]
            );
        }
        $this->assertContainsOnly( 'integer', $ids );

        $batch_size = static fn (): int => 1;
        $max_batches = static fn (): int => 1;
        $bounded_updates = [];
        $capture_updates = static function ( string $query ) use ( &$bounded_updates ): string {
            if ( str_contains( $query, 'sentient_submission_ledger' ) && str_contains( $query, 'SET expires_at' ) )
            {
                $bounded_updates[] = $query;
            }

            return $query;
        };
        add_filter( 'sentient_forms_submission_ledger_retention_backfill_batch_size', $batch_size );
        add_filter( 'sentient_forms_submission_ledger_retention_backfill_max_batches', $max_batches );
        add_filter( 'query', $capture_updates );

        try
        {
            Sentient_Forms_Installer::maybe_upgrade();
            $this->assertSame( '2026.07.10.elementor_pro_forms_identifier', get_option( 'sentient_forms_db_version' ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_version', false ) );
            $this->assertSame( $ids[0], (int) get_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1' ) );

            Sentient_Forms_Installer::maybe_upgrade();
            $this->assertSame( '2026.07.10.elementor_pro_forms_identifier', get_option( 'sentient_forms_db_version' ) );
            $this->assertSame( $ids[1], (int) get_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1' ) );

            Sentient_Forms_Installer::maybe_upgrade();
            $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
            $this->assertSame( '2026.07.10.v1', get_option( 'sentient_forms_submission_ledger_retention_backfill_version' ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1', false ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1', false ) );
        }
        finally
        {
            remove_filter( 'query', $capture_updates );
            remove_filter( 'sentient_forms_submission_ledger_retention_backfill_batch_size', $batch_size );
            remove_filter( 'sentient_forms_submission_ledger_retention_backfill_max_batches', $max_batches );
        }

        $this->assertCount( 3, $bounded_updates );
        foreach ( $bounded_updates as $query )
        {
            $this->assertMatchesRegularExpression( '/WHERE id > \d+\s+AND id <= \d+\s+AND id <= \d+/', $query );
        }
    }

    public function test_upgrade_freezes_backfill_policy_and_horizon_until_completion(): void
    {
        $this->reset_submission_ledger_backfill_state();
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 7 );

        $old_uuid    = wp_generate_uuid4();
        $future_uuid = wp_generate_uuid4();
        $this->submission_ledger->create(
            [
                'submission_uuid'     => $old_uuid,
                'form_source'         => 'wpforms',
                'form_id'             => 'frozen-backfill',
                'captured_at'         => '2025-01-01 00:00:00',
                'logical_fields_json' => [ 'label' => 'old' ],
            ]
        );
        $this->submission_ledger->create(
            [
                'submission_uuid'     => $future_uuid,
                'form_source'         => 'wpforms',
                'form_id'             => 'frozen-backfill',
                'captured_at'         => '2026-08-15 00:00:00',
                'logical_fields_json' => [ 'label' => 'future' ],
            ]
        );

        $batch_size = static fn (): int => 1;
        $max_batches = static fn (): int => 1;
        $first_time = static fn (): string => '2026-07-10 12:00:00';
        add_filter( 'sentient_forms_submission_ledger_retention_backfill_batch_size', $batch_size );
        add_filter( 'sentient_forms_submission_ledger_retention_backfill_max_batches', $max_batches );
        add_filter( 'sentient_forms_submission_ledger_retention_migration_time', $first_time );

        try
        {
            Sentient_Forms_Installer::maybe_upgrade();
            $snapshot = get_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1' );
            $this->assertSame( 7, $snapshot['retention_days'] );
            $this->assertSame( '2026-08-09 12:00:00', $snapshot['migration_floor'] );

            $late_uuid = wp_generate_uuid4();
            $this->assertIsInt(
                $this->submission_ledger->create(
                    [
                        'submission_uuid'     => $late_uuid,
                        'form_source'         => 'wpforms',
                        'form_id'             => 'frozen-backfill',
                        'captured_at'         => '2026-07-11 00:00:00',
                        'logical_fields_json' => [ 'label' => 'late' ],
                        'expires_at'          => '2099-01-01 00:00:00',
                    ]
                )
            );

            Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 180 );
            remove_filter( 'sentient_forms_submission_ledger_retention_migration_time', $first_time );
            $second_time = static fn (): string => '2027-01-01 00:00:00';
            add_filter( 'sentient_forms_submission_ledger_retention_migration_time', $second_time );

            try
            {
                Sentient_Forms_Installer::maybe_upgrade();
            }
            finally
            {
                remove_filter( 'sentient_forms_submission_ledger_retention_migration_time', $second_time );
            }

            $this->assertSame( '2026-08-09 12:00:00', $this->submission_ledger->get_by_submission_uuid( $old_uuid )['expires_at'] );
            $this->assertSame( '2026-08-22 00:00:00', $this->submission_ledger->get_by_submission_uuid( $future_uuid )['expires_at'] );
            $this->assertSame( '2099-01-01 00:00:00', $this->submission_ledger->get_by_submission_uuid( $late_uuid )['expires_at'] );
        }
        finally
        {
            remove_filter( 'sentient_forms_submission_ledger_retention_migration_time', $first_time );
            remove_filter( 'sentient_forms_submission_ledger_retention_backfill_batch_size', $batch_size );
            remove_filter( 'sentient_forms_submission_ledger_retention_backfill_max_batches', $max_batches );
        }
    }

    public function test_upgrade_retries_an_idempotent_batch_when_cursor_persistence_fails(): void
    {
        $this->reset_submission_ledger_backfill_state();
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 90 );
        $uuid = wp_generate_uuid4();
        $this->submission_ledger->create(
            [
                'submission_uuid'     => $uuid,
                'form_source'         => 'elementor_pro_forms',
                'form_id'             => 'cursor-retry',
                'captured_at'         => '2026-07-01 00:00:00',
                'logical_fields_json' => [ 'label' => 'retry' ],
            ]
        );

        $deny_cursor_persist = static fn (): bool => false;
        add_filter( 'sentient_forms_submission_ledger_retention_backfill_allow_cursor_persist', $deny_cursor_persist );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade();
            $first_expiry = $this->submission_ledger->get_by_submission_uuid( $uuid )['expires_at'];
            $snapshot     = get_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1' );
            $expected     = gmdate(
                'Y-m-d H:i:s',
                max(
                    strtotime( '2026-09-29 00:00:00 UTC' ),
                    strtotime( $snapshot['migration_floor'] . ' UTC' )
                )
            );
            $this->assertSame( $expected, $first_expiry );
            $this->assertSame( 0, (int) get_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1', 0 ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_version', false ) );
            $this->assertSame( '2026.07.10.elementor_pro_forms_identifier', get_option( 'sentient_forms_db_version' ) );
        }
        finally
        {
            remove_filter( 'sentient_forms_submission_ledger_retention_backfill_allow_cursor_persist', $deny_cursor_persist );
        }

        Sentient_Forms_Installer::maybe_upgrade();

        $this->assertSame( $first_expiry, $this->submission_ledger->get_by_submission_uuid( $uuid )['expires_at'] );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( '2026.07.10.v1', get_option( 'sentient_forms_submission_ledger_retention_backfill_version' ) );
    }

    public function test_privacy_exporter_and_eraser_handle_submission_ledger_content(): void
    {
        $email = 'ledger-person@example.test';

        $created = $this->submission_ledger->create(
            [
                'submission_uuid'         => '33333333-3333-4333-8333-333333333333',
                'form_source'             => 'gravity_forms',
                'form_id'                 => '7',
                'native_entry_id'         => '91',
                'logical_fields_json'     => [
                    'email'   => $email,
                    'message' => 'Please follow up.',
                ],
                'provider_metadata_json'  => [ 'source' => 'gravity_forms' ],
                'redaction_summary_json'  => [ 'redacted_keys' => [] ],
                'expires_at'              => null,
            ]
        );
        $this->assertIsInt( $created );

        $export = Sentient_Forms_Local_Data_Governance::export_personal_data( $email, 1 );
        $this->assertCount( 1, $export['data'] );
        $this->assertSame( 'sentient-forms-submission-ledger', $export['data'][0]['group_id'] );
        $this->assertStringContainsString( $email, wp_json_encode( $export['data'] ) );

        $erase = Sentient_Forms_Local_Data_Governance::erase_personal_data( $email, 1 );
        $this->assertTrue( $erase['items_removed'] );
        $this->assertTrue( $erase['items_retained'] );
        $this->assertTrue( $erase['done'] );

        $row = $this->submission_ledger->get_by_submission_uuid( '33333333-3333-4333-8333-333333333333' );
        $this->assertTrue( $row['logical_fields_json']['personal_data_erased'] ?? false );
        $this->assertNull( $row['native_entry_id'] );

        $after_export = Sentient_Forms_Local_Data_Governance::export_personal_data( $email, 1 );
        $this->assertSame( [], $after_export['data'] );
        $this->assertTrue( $after_export['done'] );
    }

    public function test_execution_event_retention_manual_only_disables_new_expiry(): void
    {
        Sentient_Forms_Local_Data_Governance::update_execution_event_retention_days( 0 );

        $this->events->record(
            [
                'execution_request_id' => 'retention-manual-only-1',
                'provider'             => 'openrouter',
                'status'               => 'succeeded',
            ]
        );

        $event = $this->events->get_by_request_id( 'retention-manual-only-1' );
        $this->assertNull( $event['expires_at'] );
        $this->assertSame( 0, Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days() );
    }

    public function test_invalid_retention_option_falls_back_to_default(): void
    {
        update_option( 'sentient_forms_execution_event_retention_days', -14 );

        $this->assertSame( 90, Sentient_Forms_Local_Data_Governance::current_execution_event_retention_days() );
        $this->assertNotEmpty( Sentient_Forms_Local_Data_Governance::default_execution_event_expires_at() );
    }

    public function test_activation_lifecycle_schedules_and_unschedules_retention(): void
    {
        Sentient_Forms_Local_Data_Governance::unschedule_retention_cleanup();
        $this->assertFalse( wp_next_scheduled( Sentient_Forms_Local_Data_Governance::RETENTION_HOOK ) );

        try
        {
            Sentient_Forms_Installer::activate( false );
            $this->assertNotFalse( wp_next_scheduled( Sentient_Forms_Local_Data_Governance::RETENTION_HOOK ) );

            Sentient_Forms_Installer::deactivate( false );
            $this->assertFalse( wp_next_scheduled( Sentient_Forms_Local_Data_Governance::RETENTION_HOOK ) );
        }
        finally
        {
            Sentient_Forms_Installer::activate( false );
        }
    }

    public function test_maybe_upgrade_restores_execution_event_recent_read_index(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';

        $this->assertSame( [ 'created_at', 'id' ], $this->execution_event_index_columns( $table, 'created_id_idx' ) );
        $this->assertSame( [ 'form_source', 'form_id', 'created_at', 'id' ], $this->execution_event_index_columns( $table, 'form_created_id_idx' ) );
        $this->assertNotFalse( $this->wpdb->query( 'ALTER TABLE ' . esc_sql( $table ) . ' DROP INDEX created_id_idx' ) );
        $this->assertNotFalse( $this->wpdb->query( 'ALTER TABLE ' . esc_sql( $table ) . ' DROP INDEX form_created_id_idx' ) );
        $this->assertSame( [], $this->execution_event_index_columns( $table, 'created_id_idx' ) );
        $this->assertSame( [], $this->execution_event_index_columns( $table, 'form_created_id_idx' ) );

        update_option( 'sentient_forms_db_version', '2026.05.26.managed_credits_boundary' );
        Sentient_Forms_Installer::maybe_upgrade();

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( [ 'created_at', 'id' ], $this->execution_event_index_columns( $table, 'created_id_idx' ) );
        $this->assertSame( [ 'form_source', 'form_id', 'created_at', 'id' ], $this->execution_event_index_columns( $table, 'form_created_id_idx' ) );
    }

    public function test_maybe_upgrade_restores_execution_event_identity_columns(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';

        foreach ( [ 'mapping_key', 'action_code', 'action_label' ] as $column )
        {
            $this->assertContains( $column, $this->execution_event_columns( $table ) );
        }

        $this->assertSame( [ 'mapping_key' ], $this->execution_event_index_columns( $table, 'mapping_key_idx' ) );
        $this->assertNotFalse( $this->wpdb->query( 'ALTER TABLE ' . esc_sql( $table ) . ' DROP INDEX mapping_key_idx' ) );
        foreach ( [ 'mapping_key', 'action_code', 'action_label' ] as $column )
        {
            $this->assertNotFalse( $this->wpdb->query( 'ALTER TABLE ' . esc_sql( $table ) . ' DROP COLUMN ' . esc_sql( $column ) ) );
        }

        foreach ( [ 'mapping_key', 'action_code', 'action_label' ] as $column )
        {
            $this->assertNotContains( $column, $this->execution_event_columns( $table ) );
        }

        update_option( 'sentient_forms_db_version', '2026.06.18.form_source_ledger' );
        Sentient_Forms_Installer::maybe_upgrade();

        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        foreach ( [ 'mapping_key', 'action_code', 'action_label' ] as $column )
        {
            $this->assertContains( $column, $this->execution_event_columns( $table ) );
        }
        $this->assertSame( [ 'mapping_key' ], $this->execution_event_index_columns( $table, 'mapping_key_idx' ) );
    }

    public function test_maybe_upgrade_scrubs_existing_managed_currency_fields(): void
    {
        $table      = $this->wpdb->prefix . 'sentient_execution_events';
        $now        = current_time( 'mysql' );
        $request_id = 'managed-repair-' . wp_generate_uuid4();

        $this->reset_submission_ledger_backfill_state();
        $this->assertIsInt(
            $this->submission_ledger->create(
                [
                    'submission_uuid'     => wp_generate_uuid4(),
                    'form_source'         => 'gravity_forms',
                    'form_id'             => 'partial-retention-upgrade',
                    'captured_at'         => '2026-07-01 00:00:00',
                    'logical_fields_json' => [ 'label' => 'partial' ],
                ]
            )
        );

        $inserted = $this->wpdb->insert(
            $table,
            [
                'execution_request_id' => $request_id,
                'provider'             => 'sentient_managed',
                'model'                => 'openai/gpt-4.1-mini',
                'status'               => 'succeeded',
                'cost_json'            => wp_json_encode(
                    [
                        'provider'               => 'sentient_forms',
                        'currency'               => 'USD',
                        'source'                 => 'sentient_forms_metering',
                        'debited_credits'        => 3,
                        'billed_amount_microusd' => 2300,
                    ]
                ),
                'result_json'          => wp_json_encode(
                    [
                        'metering' => [
                            'debited_credits'        => 3,
                            'billed_amount_microusd' => 2300,
                            'currency'               => 'USD',
                        ],
                        'details'  => [
                            'cost' => [
                                'amount_usd' => 0.0023,
                                'currency'   => 'USD',
                            ],
                        ],
                    ]
                ),
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        $this->assertNotFalse( $inserted );

        update_option(
            'sentient_forms_site_context',
            [
                'summary_text'  => 'AI generated context.',
                'source'        => 'ai_generated',
                'auto_include'  => true,
                'pii_ack'       => true,
                'metadata'      => [
                    'route'    => 'sentient_managed',
                    'metering' => [
                        'debited_credits'        => 1,
                        'billed_amount_microusd' => 1000,
                        'currency'               => 'USD',
                    ],
                ],
            ],
            false
        );

        update_option( 'sentient_forms_db_version', '2026.05.10.lead_value_workflows' );
        $deny_cursor_persist = static fn (): bool => false;
        add_filter( 'sentient_forms_submission_ledger_retention_backfill_allow_cursor_persist', $deny_cursor_persist );
        try
        {
            Sentient_Forms_Installer::maybe_upgrade();
        }
        finally
        {
            remove_filter( 'sentient_forms_submission_ledger_retention_backfill_allow_cursor_persist', $deny_cursor_persist );
        }

        $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_version', false ) );
        $this->assertSame( '2026.05.10.lead_value_workflows', get_option( 'sentient_forms_db_version' ) );

        $event = $this->events->get_by_request_id( $request_id );
        $this->assertSame( 3, $event['cost_json']['debited_credits'] );
        $this->assertArrayNotHasKey( 'billed_amount_microusd', $event['cost_json'] );
        $this->assertArrayNotHasKey( 'currency', $event['cost_json'] );
        $this->assertArrayNotHasKey( 'billed_amount_microusd', $event['result_json']['metering'] );
        $this->assertArrayNotHasKey( 'currency', $event['result_json']['metering'] );
        $this->assertArrayNotHasKey( 'details', $event['result_json'] );

        $context = get_option( 'sentient_forms_site_context' );
        $this->assertArrayNotHasKey( 'billed_amount_microusd', $context['metadata']['metering'] );
        $this->assertArrayNotHasKey( 'currency', $context['metadata']['metering'] );
    }

    public function test_managed_usage_scrub_selects_rows_with_provider_payload_only(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';
        $now   = current_time( 'mysql' );

        $inserted = $this->wpdb->insert(
            $table,
            [
                'execution_request_id' => 'managed-provider-payload-only',
                'provider'             => 'sentient_managed',
                'model'                => 'google/gemini-3-flash-preview',
                'status'               => 'succeeded',
                'cost_json'            => wp_json_encode( [ 'debited_credits' => 1 ] ),
                'result_json'          => wp_json_encode(
                    [
                        'privacy_route_fallback' => [
                            'schema'         => 'sentient_forms_privacy_route_fallback.v1',
                            'policy_version' => '2026-06-managed-zdr-fallback-v1',
                            'reason_code'    => 'managed_zdr_primary_route_unavailable',
                            'original_model' => 'openai/gpt-5.5',
                            'fallback_model' => 'google/gemini-3-flash-preview',
                            'attempts'       => 1,
                        ],
                        'provider_payload'       => [
                            'raw_error' => 'Provider route details must not remain in local storage.',
                        ],
                    ]
                ),
                'created_at'           => $now,
                'updated_at'           => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        $this->assertNotFalse( $inserted );

        $summary = Sentient_Forms_Managed_Usage_Sanitizer::scrub_local_storage();

        $this->assertSame( 1, $summary['execution_events_scanned'] );
        $this->assertSame( 1, $summary['execution_events_updated'] );

        $event = $this->events->get_by_request_id( 'managed-provider-payload-only' );
        $this->assertArrayHasKey( 'privacy_route_fallback', $event['result_json'] );
        $this->assertArrayNotHasKey( 'provider_payload', $event['result_json'] );
    }

    public function test_uninstall_deletes_data_by_default_and_can_be_disabled(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';

        update_option( 'sentient_forms_delete_data_on_uninstall', false );
        Sentient_Forms_Installer::uninstall();
        $this->assertSame( $table, $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

        update_option( 'sentient_forms_delete_data_on_uninstall', true );
        remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
        remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

        try
        {
            Sentient_Forms_Installer::uninstall();
            $this->assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

            Sentient_Forms_Installer::maybe_upgrade();
            $this->assertSame( $table, $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
        }
        finally
        {
            add_filter( 'query', [ $this, '_create_temporary_tables' ] );
            add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
        }
    }

    public function test_uninstall_deletes_plugin_options_transients_and_gravity_forms_meta_when_enabled(): void
    {
        $table = $this->wpdb->prefix . 'sentient_execution_events';
        $gf_entry_meta_table = $this->wpdb->prefix . 'gf_entry_meta';

        update_option(
            'sentient_forms_settings',
            [
                'api_key'       => 'legacy-api-key',
                'license_key'   => 'legacy-license-key',
                'proxy_api_key' => 'legacy-proxy-key',
            ]
        );
        update_option( 'sentient_forms_plugin_settings', [ 'enable_logging' => true ] );
        update_option( 'sentient_forms_submission_ledger_retention_days', 30 );
        update_option( 'sentient_forms_submission_ledger_retention_backfill_version', '2026.07.10.v1' );
        update_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1', [ 'pending' => true ] );
        update_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1', 42 );
        set_transient( 'sentient_forms_cps_version', 'test-version', MINUTE_IN_SECONDS );
        update_option( 'sentient_forms_delete_data_on_uninstall', true );
        remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
        remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );

        try
        {
            $this->wpdb->query(
                "CREATE TABLE IF NOT EXISTS {$gf_entry_meta_table} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    meta_key VARCHAR(255) NULL,
                    meta_value LONGTEXT NULL,
                    PRIMARY KEY  (id)
                )"
            );
            $this->wpdb->insert(
                $gf_entry_meta_table,
                [
                    'meta_key'   => 'sentient_forms_realtime_clarification_qna.v1',
                    'meta_value' => 'stored qna',
                ],
                [ '%s', '%s' ]
            );
            $this->wpdb->insert(
                $gf_entry_meta_table,
                [
                    'meta_key'   => '_sentient_forms_local_result',
                    'meta_value' => 'hidden stored result',
                ],
                [ '%s', '%s' ]
            );

            Sentient_Forms_Installer::uninstall();

            $this->assertNull( $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
            $this->assertFalse( get_option( 'sentient_forms_settings', false ) );
            $this->assertFalse( get_option( 'sentient_forms_plugin_settings', false ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_days', false ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_version', false ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1', false ) );
            $this->assertFalse( get_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1', false ) );
            $this->assertFalse( get_transient( 'sentient_forms_cps_version' ) );
            $this->assertSame(
                '0',
                (string) $this->wpdb->get_var(
                    $this->wpdb->prepare(
                        "SELECT COUNT(*) FROM {$gf_entry_meta_table} WHERE meta_key LIKE %s",
                        $this->wpdb->esc_like( 'sentient_forms_' ) . '%'
                    )
                )
            );
            $this->assertSame(
                '0',
                (string) $this->wpdb->get_var(
                    $this->wpdb->prepare(
                        "SELECT COUNT(*) FROM {$gf_entry_meta_table} WHERE meta_key LIKE %s",
                        $this->wpdb->esc_like( '_sentient_forms_' ) . '%'
                    )
                )
            );

            Sentient_Forms_Installer::maybe_upgrade();
        }
        finally
        {
            add_filter( 'query', [ $this, '_create_temporary_tables' ] );
            add_filter( 'query', [ $this, '_drop_temporary_tables' ] );
        }
    }

    public function test_sanitize_execution_result_for_storage_strips_full_output_by_default(): void
    {
        $result = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage(
            [
                'content'                => 'Full AI reply that should not persist by default.',
                'structured'             => [
                    'classification' => 'spam',
                    'summary'        => 'Derived spam summary.',
                ],
                'usage'                  => [ 'prompt_tokens' => 10 ],
                'structured_output_valid' => true,
            ]
        );

        $this->assertArrayNotHasKey( 'content', $result );
        $this->assertSame( 'Derived spam summary.', $result['result_summary'] );
        $this->assertSame( 'spam', $result['structured']['classification'] );
    }

    public function test_sanitize_execution_result_for_storage_preserves_managed_privacy_route_assertion(): void
    {
        $result = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage(
            [
                'content'                  => 'Full managed reply that should not persist by default.',
                'privacy_route_assertion'  => [
                    'schema'              => 'sentient_forms_privacy_route_assertion.v1',
                    'zdr_enforced'        => true,
                    'data_collection'     => 'deny',
                    'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
                ],
                'usage'                    => [ 'prompt_tokens' => 10 ],
            ],
            'sentient_managed'
        );

        $this->assertArrayNotHasKey( 'content', $result );
        $this->assertSame(
            [
                'schema'              => 'sentient_forms_privacy_route_assertion.v1',
                'zdr_enforced'        => true,
                'data_collection'     => 'deny',
                'route_policy_schema' => 'sentient_forms_privacy_route_policy.v1',
            ],
            $result['privacy_route_assertion'] ?? null
        );
    }

    public function test_sanitize_execution_result_for_storage_preserves_managed_privacy_route_fallback_and_failure_metadata(): void
    {
        $result = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage(
            [
                'provider_response_id'   => 'managed-execution-request-id',
                'model'                  => 'google/gemini-3-flash-preview',
                'content'                => 'Raw model content should not be stored.',
                'privacy_route_fallback' => [
                    'schema'         => 'sentient_forms_privacy_route_fallback.v1',
                    'policy_version' => '2026-06-managed-zdr-fallback-v1',
                    'reason_code'    => 'managed_zdr_primary_route_unavailable',
                    'original_model' => '~openai/gpt-latest',
                    'fallback_model' => 'google/gemini-3-flash-preview',
                    'attempts'       => 2,
                ],
                'privacy_route_failure'  => [
                    'schema'         => 'sentient_forms_privacy_route_failure.v1',
                    'policy_version' => '2026-06-managed-zdr-fallback-v1',
                    'reason_code'    => 'managed_zdr_route_unavailable',
                    'selected_model' => 'google/gemini-3-flash-preview',
                ],
                'provider_payload'       => [
                    'raw_error' => 'No ZDR route is available for this model.',
                ],
                'execution_request_id'   => 'cps-request-id-should-not-be-stored',
            ],
            'sentient_managed'
        );

        $this->assertSame(
            [
                'schema'         => 'sentient_forms_privacy_route_fallback.v1',
                'policy_version' => '2026-06-managed-zdr-fallback-v1',
                'reason_code'    => 'managed_zdr_primary_route_unavailable',
                'original_model' => '~openai/gpt-latest',
                'fallback_model' => 'google/gemini-3-flash-preview',
                'attempts'       => 2,
            ],
            $result['privacy_route_fallback'] ?? null
        );
        $this->assertSame(
            [
                'schema'         => 'sentient_forms_privacy_route_failure.v1',
                'policy_version' => '2026-06-managed-zdr-fallback-v1',
                'reason_code'    => 'managed_zdr_route_unavailable',
                'selected_model' => 'google/gemini-3-flash-preview',
            ],
            $result['privacy_route_failure'] ?? null
        );
        $this->assertArrayNotHasKey( 'content', $result );
        $this->assertArrayNotHasKey( 'provider_payload', $result );
        $this->assertArrayNotHasKey( 'execution_request_id', $result );
    }

    public function test_sanitize_execution_result_for_storage_keeps_full_output_when_enabled(): void
    {
        update_option( 'sentient_forms_store_full_ai_outputs', true );

        $result = Sentient_Forms_Local_Data_Governance::sanitize_execution_result_for_storage(
            [
                'content' => 'Full AI reply that should persist when enabled.',
            ]
        );

        $this->assertSame( 'Full AI reply that should persist when enabled.', $result['content'] );
    }

    public function test_sanitize_execution_payload_for_storage_strips_legacy_cached_outputs_by_default(): void
    {
        $payload = Sentient_Forms_Local_Data_Governance::sanitize_execution_payload_for_storage(
            [
                'cost'        => 12,
                'content'     => 'Full raw reply that should not persist.',
                'result_data' => [
                    'llm_output'     => 'Legacy raw response should be removed.',
                    'classification' => 'spam',
                ],
            ]
        );

        $this->assertSame( 12, $payload['cost'] );
        $this->assertArrayNotHasKey( 'content', $payload );
        $this->assertArrayNotHasKey( 'llm_output', $payload['result_data'] );
        $this->assertSame( 'spam', $payload['result_data']['classification'] );
        $this->assertNotEmpty( $payload['result_summary'] );
        $this->assertNotEmpty( $payload['result_data']['result_summary'] );
    }

    public function test_apply_privacy_preset_updates_defaults_and_marks_completion(): void
    {
        $applied = Sentient_Forms_Local_Data_Governance::apply_privacy_preset( 'maximum_privacy' );

        $this->assertSame( 'maximum_privacy', $applied['privacy_setup_profile'] );
        $this->assertSame( 7, $applied['execution_event_retention_days'] );
        $this->assertTrue( $applied['delete_data_on_uninstall'] );
        $this->assertFalse( $applied['store_full_ai_outputs'] );
        $this->assertNotNull( $applied['privacy_setup_completed_at'] );
    }

    public function test_privacy_presets_initialize_both_retention_windows(): void
    {
        $expected_retention = [
            'balanced'          => 90,
            'privacy_focused'   => 30,
            'maximum_privacy'   => 7,
            'maximum_visibility' => 180,
        ];

        foreach ( $expected_retention as $profile => $days )
        {
            $applied = Sentient_Forms_Local_Data_Governance::apply_privacy_preset( $profile );

            $this->assertSame( $days, $applied['execution_event_retention_days'], $profile );
            $this->assertSame( $days, $applied['submission_ledger_retention_days'], $profile );
        }
    }

    public function test_support_bundle_omits_secrets_results_and_error_messages(): void
    {
        Sentient_Forms_Local_Data_Governance::update_submission_ledger_retention_days( 30 );

        $credentials = new Sentient_Forms_Provider_Credentials_Repository( $this->wpdb );
        $credentials->create(
            [
                'provider'         => 'openrouter',
                'label'            => 'Support key',
                'auth_mode'        => 'manual_key',
                'encrypted_secret' => 'sk-or-secret',
                'status'           => 'valid',
                'status_json'      => [
                    'last_token'      => 'also-secret',
                    'diagnostic_hint' => 'provider returned sk-or-support-bundle-leak-123456',
                ],
            ]
        );

        $this->events->record(
            [
                'execution_request_id' => 'support-bundle-1',
                'provider'             => 'openrouter',
                'status'               => 'failed',
                'result_json'          => [
                    'content' => 'private result for bundle-person@example.test',
                ],
                'error_code'           => 'provider_error',
                'error_message'        => 'private error for bundle-person@example.test',
                'expires_at'           => null,
            ]
        );

        $bundle = ( new Sentient_Forms_Local_Support_Bundle_Service( $this->wpdb ) )->build();
        $json   = (string) wp_json_encode( $bundle );

        $this->assertStringNotContainsString( 'sk-or-secret', $json );
        $this->assertStringNotContainsString( 'also-secret', $json );
        $this->assertStringNotContainsString( 'sk-or-support-bundle-leak-123456', $json );
        $this->assertStringNotContainsString( 'bundle-person@example.test', $json );
        $this->assertTrue( $bundle['execution_summary']['recent'][0]['has_result'] );
        $this->assertTrue( $bundle['execution_summary']['recent'][0]['has_error_message'] );
        $this->assertSame( 30, $bundle['retention']['submission_ledger_retention_days'] );
    }

    /**
     * @return array<int, string>
     */
    private function execution_event_columns( string $table ): array
    {
        $rows = $this->wpdb->get_results( 'DESCRIBE ' . esc_sql( $table ), ARRAY_A );

        if ( ! is_array( $rows ) )
        {
            return [];
        }

        return array_values( array_map( static fn ( array $row ): string => (string) $row['Field'], $rows ) );
    }

    private function reset_submission_ledger_backfill_state(): void
    {
        $this->wpdb->query(
            $this->wpdb->prepare(
                'DELETE FROM %i',
                $this->wpdb->prefix . 'sentient_submission_ledger'
            )
        );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_version' );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_snapshot_v1' );
        delete_option( 'sentient_forms_submission_ledger_retention_backfill_cursor_v1' );
        update_option( 'sentient_forms_db_version', '2026.07.10.elementor_pro_forms_identifier' );
    }

    /**
     * @return array<int, string>
     */
    private function execution_event_index_columns( string $table, string $index_name ): array
    {
        $rows = $this->wpdb->get_results(
            'SHOW INDEX FROM ' . esc_sql( $table ) . ' WHERE Key_name = \'' . esc_sql( $index_name ) . '\'',
            ARRAY_A
        );

        if ( ! is_array( $rows ) )
        {
            return [];
        }

        usort(
            $rows,
            static fn ( array $left, array $right ): int => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index']
        );

        return array_values( array_map( static fn ( array $row ): string => (string) $row['Column_name'], $rows ) );
    }
}
