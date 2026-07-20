<?php

final class Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb extends wpdb
{
    private int $reads = 0;

    private bool $first_read_misses;

    /** @var array<string, mixed> */
    private array $owner;

    public function __construct( array $owner, bool $first_read_misses = true )
    {
        $this->prefix            = 'wp_';
        $this->owner             = $owner;
        $this->first_read_misses = $first_read_misses;
    }

    public function prepare( $query, ...$args )
    {
        return $query;
    }

    public function query( $query )
    {
        return 0;
    }

    public function get_row( $query = null, $output = OBJECT, $y = 0 )
    {
        ++$this->reads;

        return $this->first_read_misses && 1 === $this->reads ? null : $this->owner;
    }

    public function update( $table, $data, $where, $format = null, $where_format = null )
    {
        return 1;
    }
}

class AsyncRequestStoreTest extends WP_UnitTestCase
{
    private Sentient_Forms_Async_Request_Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Sentient_Forms_Installer::maybe_upgrade();
        $this->store = new Sentient_Forms_Async_Request_Store( $wpdb );
    }

    public function test_record_and_should_block(): void
    {
        $hash = wp_generate_password( 64, false, false );
        $this->assertFalse( $this->store->should_block( $hash ) );
        $this->store->record( $hash, [ 'action_id' => 'spam_check', 'status' => 'queued' ] );
        $this->assertTrue( $this->store->should_block( $hash ) );
    }

    public function test_mark_status_fails_closed_when_authoritative_request_is_missing(): void
    {
        $result = $this->store->mark_status(
            'missing-authoritative-request-' . wp_generate_password( 24, false, false ),
            'running',
            null,
            'evaluation'
        );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'sentient_forms_async_request_missing', $result->get_error_code() );
    }

    public function test_claim_queued_execution_has_exactly_one_winner(): void
    {
        $request_hash = 'queued-claim-' . wp_generate_password( 24, false, false );
        $digest       = hash( 'sha256', 'queued-claim-payload' );
        $this->assertTrue(
            $this->store->record(
                $request_hash,
                [
                    'action_id'      => 'entry_summary_v1',
                    'record_type'    => 'evaluation',
                    'status'         => 'queued',
                    'payload_digest' => $digest,
                ]
            )
        );

        $first  = $this->store->claim_queued_execution( $request_hash, 'evaluation', $digest );
        $second = $this->store->claim_queued_execution( $request_hash, 'evaluation', $digest );

        $this->assertSame( 'claimed', $first['state'] ?? null );
        $this->assertSame( 'active', $second['state'] ?? null );
        $this->assertSame( 'running', $second['record']['status'] ?? null );
    }

    public function test_claim_queued_execution_rejects_missing_terminal_and_digest_conflicting_rows(): void
    {
        $digest = hash( 'sha256', 'authoritative-queued-payload' );
        $missing = $this->store->claim_queued_execution( 'missing-' . wp_generate_password( 20, false, false ), 'job', $digest );
        $this->assertSame( 'missing', $missing['state'] ?? null );

        $request_hash = 'terminal-claim-' . wp_generate_password( 20, false, false );
        $this->assertTrue(
            $this->store->record(
                $request_hash,
                [ 'status' => 'queued', 'payload_digest' => $digest ]
            )
        );
        $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'job', $digest )['state'] ?? null );
        $this->assertTrue( $this->store->finish_execution( $request_hash, 'success' ) );

        $terminal = $this->store->claim_queued_execution( $request_hash, 'job', $digest );
        $conflict = $this->store->claim_queued_execution( $request_hash, 'job', hash( 'sha256', 'different' ) );
        $this->assertSame( 'terminal', $terminal['state'] ?? null );
        $this->assertSame( 'digest_conflict', $conflict['state'] ?? null );
    }

    public function test_reject_queued_execution_is_digest_aware_and_preserves_terminal_authority(): void
    {
        $digest = hash( 'sha256', 'disabled-callback-payload' );

        $queued_hash = 'disabled-queued-' . wp_generate_password( 20, false, false );
        $this->assertTrue( $this->store->record( $queued_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
        $conflict = $this->store->reject_queued_execution( $queued_hash, 'job', hash( 'sha256', 'other-payload' ), 'disabled' );
        $this->assertSame( 'digest_conflict', $conflict['state'] ?? null );
        $this->assertSame( 'queued', $this->store->get( $queued_hash )['status'] ?? null );

        $rejected = $this->store->reject_queued_execution( $queued_hash, 'job', $digest, 'disabled' );
        $this->assertSame( 'rejected', $rejected['state'] ?? null );
        $this->assertSame( 'failed', $this->store->get( $queued_hash )['status'] ?? null );

        foreach ( [ 'success', 'indeterminate' ] as $terminal_status )
        {
            $request_hash = 'disabled-terminal-' . $terminal_status . '-' . wp_generate_password( 20, false, false );
            $this->assertTrue( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
            $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'job', $digest )['state'] ?? null );
            $this->assertTrue( $this->store->finish_execution( $request_hash, $terminal_status ) );

            $duplicate = $this->store->reject_queued_execution( $request_hash, 'job', $digest, 'disabled' );
            $this->assertSame( 'indeterminate' === $terminal_status ? 'indeterminate' : 'terminal', $duplicate['state'] ?? null );
            $this->assertSame( $terminal_status, $this->store->get( $request_hash )['status'] ?? null );
        }
    }

    public function test_finish_execution_requires_matching_running_row(): void
    {
        $digest       = hash( 'sha256', 'finish-running' );
        $request_hash = 'finish-running-' . wp_generate_password( 20, false, false );
        $this->assertTrue( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );

        $queued = $this->store->finish_execution( $request_hash, 'success' );
        $this->assertInstanceOf( WP_Error::class, $queued );
        $this->assertSame( 'sentient_forms_async_request_transition_conflict', $queued->get_error_code() );

        $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'job', $digest )['state'] ?? null );
        $this->assertTrue( $this->store->finish_execution( $request_hash, 'success' ) );
        $this->assertTrue( $this->store->finish_execution( $request_hash, 'success' ) );

        $missing = $this->store->finish_execution( 'missing-finish-' . wp_generate_password( 20, false, false ), 'success' );
        $this->assertInstanceOf( WP_Error::class, $missing );
        $this->assertSame( 'sentient_forms_async_request_missing', $missing->get_error_code() );
    }

    public function test_prepare_retry_requires_and_releases_the_running_lease(): void
    {
        $digest       = hash( 'sha256', 'prepare-retry' );
        $request_hash = 'prepare-retry-' . wp_generate_password( 20, false, false );
        $this->assertTrue(
            $this->store->record(
                $request_hash,
                [
                    'record_type'    => 'evaluation',
                    'status'         => 'queued',
                    'payload_digest' => $digest,
                ]
            )
        );

        $queued = $this->store->prepare_retry( $request_hash, 'evaluation', 'temporary failure' );
        $this->assertInstanceOf( WP_Error::class, $queued );
        $this->assertSame( 'sentient_forms_async_request_transition_conflict', $queued->get_error_code() );

        $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'evaluation', $digest )['state'] ?? null );
        $this->assertTrue( $this->store->prepare_retry( $request_hash, 'evaluation', 'temporary failure' ) );
        $this->assertSame( 'retry_pending', $this->store->get( $request_hash, 'evaluation' )['status'] ?? null );

        $this->assertTrue(
            $this->store->record(
                $request_hash,
                [
                    'record_type'    => 'evaluation',
                    'status'         => 'queued',
                    'payload_digest' => $digest,
                ]
            )
        );
        $this->assertSame( 'queued', $this->store->get( $request_hash, 'evaluation' )['status'] ?? null );
    }

    public function test_prepare_dependency_wait_requires_and_releases_the_running_lease(): void
    {
        $digest       = hash( 'sha256', 'prepare-dependency-wait' );
        $request_hash = 'prepare-dependency-wait-' . wp_generate_password( 20, false, false );
        $this->assertTrue( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
        $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'job', $digest )['state'] ?? null );
        $this->assertTrue( $this->store->prepare_dependency_wait( $request_hash, 'job', 'dependency pending' ) );
        $this->assertSame( 'dependency_wait', $this->store->get( $request_hash )['status'] ?? null );

        $this->assertTrue( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
        $this->assertSame( 'queued', $this->store->get( $request_hash )['status'] ?? null );
    }

    public function test_purge_preserves_expired_running_execution(): void
    {
        global $wpdb;

        $digest       = hash( 'sha256', 'expired-running' );
        $request_hash = 'expired-running-' . wp_generate_password( 20, false, false );
        $this->assertTrue( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
        $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'job', $digest )['state'] ?? null );
        $wpdb->update(
            $wpdb->prefix . 'sentient_async_requests',
            [ 'last_seen_at' => '2000-01-01 00:00:00' ],
            [ 'request_hash' => $request_hash ],
            [ '%s' ],
            [ '%s' ]
        );

        $this->assertSame( 0, $this->store->purge_older_than( time() ) );
        $this->assertSame( 'running', $this->store->get( $request_hash )['status'] ?? null );
        $this->assertTrue( $this->store->should_block( $request_hash ) );
        $this->assertFalse( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
    }

    public function test_has_active_executions_uses_authoritative_rows_without_metadata(): void
    {
        delete_option( 'sentient_forms_async_jobs' );
        $digest       = hash( 'sha256', 'active-without-metadata' );
        $request_hash = 'active-without-metadata-' . wp_generate_password( 20, false, false );
        $this->assertTrue( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
        $this->assertFalse( $this->store->has_active_executions() );
        $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'job', $digest )['state'] ?? null );
        $this->assertTrue( $this->store->has_active_executions() );
    }

    public function test_active_execution_query_includes_synchronous_and_reschedule_transition_leases(): void
    {
        $digest = hash( 'sha256', 'all-authoritative-active-leases' );
        $sync_request = 'active-sync-' . wp_generate_password( 20, false, false );
        $claim = $this->store->claim_execution(
            $sync_request,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => $digest,
            ]
        );
        $this->assertSame( 'claimed', $claim['state'] ?? null );
        $this->assertTrue( $this->store->has_active_executions() );
        $this->assertTrue( $this->store->finish_execution( $sync_request, 'success', null, 'accepted_sync' ) );
        $this->assertFalse( $this->store->has_active_executions() );

        foreach ( [ 'retry_pending', 'dependency_wait' ] as $transition_status )
        {
            $request_hash = 'active-transition-' . $transition_status . '-' . wp_generate_password( 20, false, false );
            $this->assertTrue( $this->store->record( $request_hash, [ 'status' => 'queued', 'payload_digest' => $digest ] ) );
            $this->assertSame( 'claimed', $this->store->claim_queued_execution( $request_hash, 'job', $digest )['state'] ?? null );
            $prepared = 'retry_pending' === $transition_status
                ? $this->store->prepare_retry( $request_hash, 'job', 'retrying' )
                : $this->store->prepare_dependency_wait( $request_hash, 'job', 'waiting' );
            $this->assertTrue( $prepared );
            $this->assertTrue( $this->store->has_active_executions(), $transition_status );
            $this->assertTrue( $this->store->mark_status( $request_hash, 'failed', 'test cleanup' ) );
            $this->assertFalse( $this->store->has_active_executions() );
        }
    }

    public function test_purge_removes_old_rows(): void
    {
        $hash = 'abc123';
        $this->store->record( $hash, [ 'action_id' => 'summary', 'status' => 'success' ] );
        $removed = $this->store->purge_older_than( time() + DAY_IN_SECONDS );
        $this->assertSame( 1, $removed );
        $this->assertFalse( $this->store->should_block( $hash ) );
    }

    public function test_purge_preserves_every_nonterminal_execution_state(): void
    {
        global $wpdb;

        $nonterminal = [
            'queued'          => 'job',
            'running'         => 'job',
            'retry_pending'   => 'job',
            'dependency_wait' => 'job',
            'indeterminate'   => 'job',
            'telemetry_queued' => 'telemetry',
        ];
        $request_hashes = [];
        foreach ( $nonterminal as $status => $record_type )
        {
            $request_hash = 'purge-nonterminal-' . $status . '-' . wp_generate_password( 12, false, false );
            $request_hashes[ $status ] = [ $request_hash, $record_type ];
            $this->assertTrue(
                $this->store->record(
                    $request_hash,
                    [
                        'status'         => $status,
                        'record_type'    => $record_type,
                        'payload_digest' => hash( 'sha256', $status ),
                    ]
                )
            );
        }
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE %i SET last_seen_at = %s WHERE request_hash LIKE %s',
                $wpdb->prefix . 'sentient_async_requests',
                '2000-01-01 00:00:00',
                'purge-nonterminal-%'
            )
        );

        $this->assertSame( 0, $this->store->purge_older_than( time() ) );
        foreach ( $request_hashes as $status => [ $request_hash, $record_type ] )
        {
            $this->assertSame( $status, $this->store->get( $request_hash, $record_type )['status'] ?? null, $status );
        }
    }

    public function test_claim_execution_rejects_missing_or_mismatched_payload_digest(): void
    {
        $request_hash = 'accepted-sync-' . wp_generate_password( 32, false, false );
        $first_digest = hash( 'sha256', 'first-payload' );
        $other_digest = hash( 'sha256', 'other-payload' );

        $missing = $this->store->claim_execution(
            $request_hash . '-missing',
            [ 'action_id' => 'entry_summary_v1', 'adapter' => 'fixture_forms' ]
        );
        $this->assertSame( 'digest_conflict', $missing['state'] );
        $this->assertNull( $missing['record'] );

        $claimed = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => $first_digest,
            ]
        );
        $this->assertSame( 'claimed', $claimed['state'] );

        $this->store->mark_status( $request_hash, 'success', null, 'accepted_sync' );

        $mismatch = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => $other_digest,
            ]
        );
        $this->assertSame( 'digest_conflict', $mismatch['state'] );
        $this->assertSame( $first_digest, $mismatch['record']['payload_digest'] ?? null );
    }

    public function test_claim_execution_surfaces_initial_insert_and_retry_cas_sql_failures(): void
    {
        global $wpdb;

        $request_table = $wpdb->prefix . 'sentient_async_requests';
        $fail_insert = static function ( string $query ) use ( $request_table ): string {
            if ( str_starts_with( ltrim( $query ), 'INSERT IGNORE' ) && str_contains( $query, $request_table ) )
            {
                return 'SENTIENT FORMS FORCED CLAIM INSERT FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_insert );
        $suppressed = $wpdb->suppress_errors( true );
        try
        {
            $insert_failure = $this->store->claim_execution(
                'claim-insert-failure-' . wp_generate_password( 20, false, false ),
                [ 'payload_digest' => hash( 'sha256', 'claim-insert-failure' ) ]
            );
        }
        finally
        {
            $wpdb->suppress_errors( $suppressed );
            remove_filter( 'query', $fail_insert );
        }
        $this->assertInstanceOf( WP_Error::class, $insert_failure );
        $this->assertSame( 'sentient_forms_async_request_persistence_failed', $insert_failure->get_error_code() );

        $request_hash = 'claim-retry-failure-' . wp_generate_password( 20, false, false );
        $digest       = hash( 'sha256', 'claim-retry-failure' );
        $this->assertTrue(
            $this->store->record(
                $request_hash,
                [
                    'status'         => 'failed',
                    'payload_digest' => $digest,
                    'record_type'    => 'accepted_sync',
                ]
            )
        );
        $fail_retry = static function ( string $query ) use ( $request_table ): string {
            if (
                str_starts_with( ltrim( $query ), 'UPDATE' )
                && str_contains( $query, $request_table )
                && str_contains( $query, "status = 'running'" )
            )
            {
                return 'SENTIENT FORMS FORCED CLAIM RETRY FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_retry );
        $suppressed = $wpdb->suppress_errors( true );
        try
        {
            $retry_failure = $this->store->claim_execution(
                $request_hash,
                [ 'payload_digest' => $digest ],
                true
            );
        }
        finally
        {
            $wpdb->suppress_errors( $suppressed );
            remove_filter( 'query', $fail_retry );
        }
        $this->assertInstanceOf( WP_Error::class, $retry_failure );
        $this->assertSame( 'sentient_forms_async_request_persistence_failed', $retry_failure->get_error_code() );
    }

    public function test_claim_execution_preserves_retryable_local_state_lock_error(): void
    {
        $lock_name_method = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'database_lock_name' );
        $lock_name        = $lock_name_method->invoke( null );
        $competitor       = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $acquired         = (int) $competitor->get_var(
            $competitor->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
        );
        $this->assertSame( 1, $acquired );

        try
        {
            $claim = $this->store->claim_execution(
                'locked-accepted-sync-' . wp_generate_password( 24, false, false ),
                [
                    'action_id'      => 'entry_summary_v1',
                    'adapter'        => 'fixture_forms',
                    'payload_digest' => hash( 'sha256', 'locked-accepted-sync' ),
                ]
            );
        }
        finally
        {
            $competitor->get_var( $competitor->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }

        $this->assertInstanceOf( WP_Error::class, $claim );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $claim->get_error_code() );
        $this->assertSame( 409, $claim->get_error_data()['status'] ?? null );
    }

    public function test_failed_execution_retry_requires_the_original_payload_digest(): void
    {
        $request_hash = 'accepted-retry-' . wp_generate_password( 32, false, false );
        $first_digest = hash( 'sha256', 'retryable-payload' );

        $claimed = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => $first_digest,
            ]
        );
        $this->assertSame( 'claimed', $claimed['state'] );
        $this->store->mark_status( $request_hash, 'failed', 'safe failure', 'accepted_sync' );

        $mismatch = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'changed-payload' ),
            ],
            true
        );

        $this->assertSame( 'digest_conflict', $mismatch['state'] );
        $this->assertSame( 'failed', $mismatch['record']['status'] ?? null );
        $this->assertSame( $first_digest, $mismatch['record']['payload_digest'] ?? null );
    }

    public function test_record_types_cannot_share_one_global_execution_identity(): void
    {
        $job_request = 'global-job-' . wp_generate_password( 32, false, false );
        $this->assertTrue(
            $this->store->record(
                $job_request,
                [
                    'action_id'      => 'entry_summary_v1',
                    'adapter'        => 'fixture_forms',
                    'payload_digest' => hash( 'sha256', 'job-payload' ),
                ]
            )
        );

        $sync_claim = $this->store->claim_execution(
            $job_request,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'sync-payload' ),
            ]
        );

        $this->assertSame( 'record_type_conflict', $sync_claim['state'] );
        $this->assertSame( 'job', $sync_claim['record']['record_type'] ?? null );
        $this->assertNotNull( $this->store->get( $job_request, 'job' ) );
        $this->assertNull( $this->store->get( $job_request, 'accepted_sync' ) );

        $sync_request = 'global-sync-' . wp_generate_password( 32, false, false );
        $claimed      = $this->store->claim_execution(
            $sync_request,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'accepted-sync-payload' ),
            ]
        );
        $this->assertSame( 'claimed', $claimed['state'] );

        $job_record = $this->store->record(
            $sync_request,
            [
                'action_id'      => 'entry_summary_v1',
                'adapter'        => 'fixture_forms',
                'payload_digest' => hash( 'sha256', 'job-payload' ),
            ]
        );

        $this->assertWPError( $job_record );
        $this->assertSame( 'sentient_forms_async_request_record_type_conflict', $job_record->get_error_code() );
        $this->assertNotNull( $this->store->get( $sync_request, 'accepted_sync' ) );
        $this->assertNull( $this->store->get( $sync_request, 'job' ) );
    }

    public function test_same_type_insert_race_does_not_grant_a_second_scheduling_claim(): void
    {
        $request_hash = 'same-type-race';
        $digest       = hash( 'sha256', 'same-payload' );
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash'   => $request_hash,
                'record_type'    => 'job',
                'status'         => 'queued',
                'payload_digest' => $digest,
            ]
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $claim = $store->record(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'record_type'    => 'job',
                'payload_digest' => $digest,
            ]
        );

        $this->assertFalse( $claim );
    }

    public function test_same_type_owner_visible_on_first_record_read_does_not_grant_a_second_claim(): void
    {
        $request_hash = 'same-type-owner-visible';
        $digest       = hash( 'sha256', 'same-payload' );
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash'   => $request_hash,
                'record_type'    => 'job',
                'status'         => 'queued',
                'first_seen_at'  => current_time( 'mysql' ),
                'last_seen_at'   => current_time( 'mysql' ),
                'payload_digest' => $digest,
            ],
            false
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $claim = $store->record(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'record_type'    => 'job',
                'payload_digest' => $digest,
            ]
        );

        $this->assertFalse( $claim );
    }

    public function test_visible_same_type_owner_rejects_a_different_payload_digest(): void
    {
        $request_hash = 'same-type-digest-conflict';
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash'   => $request_hash,
                'record_type'    => 'job',
                'status'         => 'queued',
                'first_seen_at'  => current_time( 'mysql' ),
                'last_seen_at'   => current_time( 'mysql' ),
                'payload_digest' => hash( 'sha256', 'original-payload' ),
            ],
            false
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $claim = $store->record(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'record_type'    => 'job',
                'payload_digest' => hash( 'sha256', 'different-payload' ),
            ]
        );

        $this->assertWPError( $claim );
        $this->assertSame( 'sentient_forms_async_request_digest_conflict', $claim->get_error_code() );
    }

    public function test_malformed_active_timestamp_fails_closed(): void
    {
        $request_hash = 'malformed-active-timestamp';
        $database     = new Sentient_Forms_Test_Async_Request_Insert_Race_Wpdb(
            [
                'request_hash' => $request_hash,
                'record_type'  => 'job',
                'status'       => 'queued',
                'last_seen_at' => 'not-a-mysql-datetime',
            ],
            false
        );
        $store        = new Sentient_Forms_Async_Request_Store( $database );

        $this->assertTrue( $store->should_block( $request_hash ) );
    }

    public function test_failed_same_type_request_has_one_atomic_retry_winner(): void
    {
        $request_hash = 'failed-retry-' . wp_generate_password( 24, false, false );
        $context      = [
            'action_id'      => 'entry_summary_v1',
            'record_type'    => 'job',
            'status'         => 'queued',
            'payload_digest' => hash( 'sha256', 'retry-payload' ),
        ];

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $this->store->mark_status( $request_hash, 'failed', 'Safe retryable failure.' );

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $this->assertFalse( $this->store->record( $request_hash, $context ) );
        $this->assertSame( 'queued', $this->store->get( $request_hash )['status'] ?? null );
    }

    public function test_expired_queued_request_remains_non_replayable_until_explicit_transition(): void
    {
        global $wpdb;

        $request_hash = 'expired-retry-' . wp_generate_password( 24, false, false );
        $context      = [
            'action_id'      => 'entry_summary_v1',
            'record_type'    => 'job',
            'status'         => 'queued',
            'payload_digest' => hash( 'sha256', 'expired-payload' ),
        ];

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $wpdb->update(
            $wpdb->prefix . 'sentient_async_requests',
            [ 'last_seen_at' => wp_date( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ), wp_timezone() ) ],
            [ 'request_hash' => $request_hash ],
            [ '%s' ],
            [ '%s' ]
        );

        $this->assertFalse( $this->store->record( $request_hash, $context ) );
        $this->assertFalse( $this->store->record( $request_hash, $context ) );
        $this->assertTrue( $this->store->should_block( $request_hash ) );
    }

    public function test_indeterminate_request_remains_non_replayable_after_ttl_expiry(): void
    {
        global $wpdb;

        $request_hash = 'indeterminate-' . wp_generate_password( 24, false, false );
        $digest       = hash( 'sha256', 'indeterminate-payload' );
        $context      = [
            'action_id'      => 'entry_summary_v1',
            'record_type'    => 'job',
            'status'         => 'queued',
            'payload_digest' => $digest,
        ];

        $this->assertTrue( $this->store->record( $request_hash, $context ) );
        $this->store->mark_status( $request_hash, 'indeterminate', 'Legacy execution outcome cannot be reconstructed.' );
        $wpdb->update(
            $wpdb->prefix . 'sentient_async_requests',
            [ 'last_seen_at' => wp_date( 'Y-m-d H:i:s', time() - ( 2 * DAY_IN_SECONDS ), wp_timezone() ) ],
            [ 'request_hash' => $request_hash ],
            [ '%s' ],
            [ '%s' ]
        );

        $this->assertTrue( $this->store->should_block( $request_hash ) );
        $this->assertFalse( $this->store->record( $request_hash, $context ) );
        $this->assertSame( 0, $this->store->purge_older_than( time() + DAY_IN_SECONDS ) );
        $this->assertTrue( $this->store->should_block( $request_hash ) );

        $claim = $this->store->claim_execution(
            $request_hash,
            [
                'action_id'      => 'entry_summary_v1',
                'payload_digest' => $digest,
            ],
            true,
            'job'
        );

        $this->assertSame( 'indeterminate', $claim['state'] );
        $this->assertSame( 'indeterminate', $claim['record']['status'] ?? null );
    }

    public function test_fresh_requests_use_the_site_timezone_for_expiry_and_purge_cutoffs(): void
    {
        global $wpdb;

        $request_hash     = 'site-timezone-' . wp_generate_password( 24, false, false );
        $original_timezone = get_option( 'timezone_string' );
        $original_offset   = get_option( 'gmt_offset' );
        $original_default  = date_default_timezone_get();
        $ttl_filter        = static fn(): int => HOUR_IN_SECONDS;

        try
        {
            update_option( 'timezone_string', 'Pacific/Honolulu' );
            update_option( 'gmt_offset', -10 );
            date_default_timezone_set( 'UTC' );
            add_filter( 'sentient_forms_async_request_ttl', $ttl_filter );

            $this->assertTrue(
                $this->store->record(
                    $request_hash,
                    [
                        'action_id' => 'entry_summary_v1',
                        'status'    => 'queued',
                    ]
                )
            );
            $this->assertTrue( $this->store->should_block( $request_hash ) );
            $this->assertSame( 0, $this->store->purge_older_than( time() - MINUTE_IN_SECONDS ) );
            $this->assertTrue( $this->store->should_block( $request_hash ) );
        }
        finally
        {
            remove_filter( 'sentient_forms_async_request_ttl', $ttl_filter );
            date_default_timezone_set( $original_default );
            update_option( 'timezone_string', $original_timezone );
            update_option( 'gmt_offset', $original_offset );
            $wpdb->delete(
                $wpdb->prefix . 'sentient_async_requests',
                [ 'request_hash' => $request_hash ],
                [ '%s' ]
            );
        }
    }
}
