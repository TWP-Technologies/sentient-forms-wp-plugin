<?php

final class LegacyAsyncJobRetirementTest extends WP_UnitTestCase
{
    private const LEGACY_HOOK = 'sentient_forms_process_action';
    private const RETIREMENT_OPTION = 'sentient_forms_legacy_action_job_retirement_version';
    private const RETIREMENT_VERSION = '2026.07.18.v1';
    private const PREVIOUS_DB_VERSION = '2026.07.17.pre_legacy_action_job_retirement';

    /** @var array<int, int> */
    private array $action_ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue( function_exists( 'as_schedule_single_action' ) );
        $this->assertTrue( function_exists( 'as_get_scheduled_actions' ) );
        $this->assertTrue( function_exists( 'as_unschedule_all_actions' ) );
        $this->assertTrue( class_exists( 'ActionScheduler' ) );
        $this->assertTrue( ActionScheduler::is_initialized() );

        delete_option( self::RETIREMENT_OPTION );
        update_option( 'sentient_forms_db_version', self::PREVIOUS_DB_VERSION, false );
        Sentient_Forms_Plugin::instance()->get_async_metadata_store()->clear();

        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}sentient_async_requests" );
        wp_unschedule_hook( self::LEGACY_HOOK );
    }

    protected function tearDown(): void
    {
        $store = ActionScheduler::store();
        foreach ( $this->action_ids as $action_id )
        {
            try
            {
                $store->delete_action( $action_id );
            }
            catch ( Throwable $throwable )
            {
                // The production contract is asserted before cleanup; a missing
                // fixture row should not mask the test result.
            }
        }

        wp_unschedule_hook( self::LEGACY_HOOK );
        Sentient_Forms_Plugin::instance()->get_async_metadata_store()->clear();
        parent::tearDown();
    }

    public function test_upgrade_cancels_pending_legacy_jobs_across_groups_and_reconciles_plugin_state(): void
    {
        $first = $this->schedule_legacy_job( 'legacy-retirement-a', 'legacy-job-a', 'legacy-request-a' );
        $second = $this->schedule_legacy_job( 'legacy-retirement-b', 'legacy-job-b', 'legacy-request-b' );
        $local = as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            'sentient_forms_process_local_mapping',
            [ 'local_mapping_id' => 91 ],
            'legacy-retirement-a'
        );
        $this->assertIsInt( $local );
        $this->action_ids[] = $local;

        $this->assertTrue( wp_schedule_single_event( time() + HOUR_IN_SECONDS, self::LEGACY_HOOK, [ 'first' ] ) );
        $this->assertTrue( wp_schedule_single_event( time() + ( 2 * HOUR_IN_SECONDS ), self::LEGACY_HOOK, [ 'second' ] ) );

        Sentient_Forms_Installer::maybe_upgrade();

        $store = ActionScheduler::store();
        $this->assertSame( ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $first ) );
        $this->assertSame( ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $second ) );
        $this->assertSame( ActionScheduler_Store::STATUS_PENDING, $store->get_status( $local ) );
        $this->assertFalse( wp_next_scheduled( self::LEGACY_HOOK, [ 'first' ] ) );
        $this->assertFalse( wp_next_scheduled( self::LEGACY_HOOK, [ 'second' ] ) );

        $metadata = Sentient_Forms_Plugin::instance()->get_async_metadata_store();
        $requests = Sentient_Forms_Plugin::instance()->get_async_request_store();
        foreach ( [ 'a', 'b' ] as $suffix )
        {
            $this->assertSame( 'failed', $metadata->get( 'legacy-job-' . $suffix )['status'] ?? null );
            $this->assertSame( 'legacy_cps_action_job_retired', $metadata->get( 'legacy-job-' . $suffix )['last_error'] ?? null );
            $this->assertSame( 'failed', $requests->get( 'legacy-request-' . $suffix )['status'] ?? null );
            $this->assertSame( 'legacy_cps_action_job_retired', $requests->get( 'legacy-request-' . $suffix )['last_error'] ?? null );
        }

        $this->assertSame( self::RETIREMENT_VERSION, get_option( self::RETIREMENT_OPTION ) );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
    }

    public function test_in_progress_job_holds_version_gate_then_resumes_as_indeterminate_without_replay(): void
    {
        $action_id = $this->schedule_legacy_job(
            'legacy-retirement-running',
            'legacy-job-running',
            'legacy-request-running',
            time() - MINUTE_IN_SECONDS
        );
        $claim = ActionScheduler::store()->stake_claim(
            1,
            null,
            [ self::LEGACY_HOOK ]
        );

        $this->assertContains( $action_id, $claim->get_actions() );
        $this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $action_id ) );

        Sentient_Forms_Installer::maybe_upgrade();

        $this->assertFalse( get_option( self::RETIREMENT_OPTION, false ) );
        $this->assertSame( self::PREVIOUS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );
        $this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $action_id ) );

        ActionScheduler::store()->mark_complete( $action_id );
        Sentient_Forms_Installer::maybe_upgrade();

        $metadata = Sentient_Forms_Plugin::instance()->get_async_metadata_store()->get( 'legacy-job-running' );
        $request  = Sentient_Forms_Plugin::instance()->get_async_request_store()->get( 'legacy-request-running' );
        $this->assertSame( 'indeterminate', $metadata['status'] ?? null );
        $this->assertSame( 'legacy_cps_action_job_outcome_indeterminate', $metadata['last_error'] ?? null );
        $this->assertSame( 'indeterminate', $request['status'] ?? null );
        $this->assertSame( 'legacy_cps_action_job_outcome_indeterminate', $request['last_error'] ?? null );
        $this->assertSame( self::RETIREMENT_VERSION, get_option( self::RETIREMENT_OPTION ) );
        $this->assertSame( SENTIENT_FORMS_DB_VERSION, get_option( 'sentient_forms_db_version' ) );

        Sentient_Forms_Installer::maybe_upgrade();

        $this->assertSame( 'indeterminate', Sentient_Forms_Plugin::instance()->get_async_request_store()->get( 'legacy-request-running' )['status'] ?? null );
        $this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $action_id ) );
    }

    public function test_terminal_scheduler_history_is_preserved_and_plugin_owned_success_wins(): void
    {
        $complete = $this->schedule_legacy_job( 'legacy-terminal', 'legacy-job-complete', 'legacy-request-complete' );
        $failed = $this->schedule_legacy_job( 'legacy-terminal', 'legacy-job-failed', 'legacy-request-failed' );
        $canceled = $this->schedule_legacy_job( 'legacy-terminal', 'legacy-job-canceled', 'legacy-request-canceled' );

        ActionScheduler::store()->mark_complete( $complete );
        ActionScheduler::store()->mark_failure( $failed );
        ActionScheduler::store()->cancel_action( $canceled );
        Sentient_Forms_Plugin::instance()->get_async_metadata_store()->update_status( 'legacy-job-complete', 'success' );
        Sentient_Forms_Plugin::instance()->get_async_request_store()->mark_status( 'legacy-request-complete', 'success' );

        Sentient_Forms_Installer::maybe_upgrade();

        $store = ActionScheduler::store();
        $this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $store->get_status( $complete ) );
        $this->assertSame( ActionScheduler_Store::STATUS_FAILED, $store->get_status( $failed ) );
        $this->assertSame( ActionScheduler_Store::STATUS_CANCELED, $store->get_status( $canceled ) );
        $this->assertNotNull( $store->fetch_action( $complete ) );
        $this->assertNotNull( $store->fetch_action( $failed ) );
        $this->assertNotNull( $store->fetch_action( $canceled ) );

        $metadata = Sentient_Forms_Plugin::instance()->get_async_metadata_store();
        $requests = Sentient_Forms_Plugin::instance()->get_async_request_store();
        $this->assertSame( 'success', $metadata->get( 'legacy-job-complete' )['status'] ?? null );
        $this->assertSame( 'success', $requests->get( 'legacy-request-complete' )['status'] ?? null );
        $this->assertSame( 'failed', $metadata->get( 'legacy-job-failed' )['status'] ?? null );
        $this->assertSame( 'failed', $requests->get( 'legacy-request-failed' )['status'] ?? null );
        $this->assertSame( 'failed', $metadata->get( 'legacy-job-canceled' )['status'] ?? null );
        $this->assertSame( 'failed', $requests->get( 'legacy-request-canceled' )['status'] ?? null );
        $this->assertSame( self::RETIREMENT_VERSION, get_option( self::RETIREMENT_OPTION ) );
    }

    private function schedule_legacy_job(
        string $group,
        string $job_id,
        string $execution_request_id,
        ?int $run_at = null
    ): int
    {
        $payload = [
            'action_id'            => 'legacy_master_action',
            'execution_request_id' => $execution_request_id,
            'context'              => [
                'job_id'               => $job_id,
                'execution_request_id' => $execution_request_id,
            ],
        ];
        $run_at = $run_at ?? ( time() + HOUR_IN_SECONDS );
        $action_id = as_schedule_single_action( $run_at, self::LEGACY_HOOK, $payload, $group );
        $this->assertIsInt( $action_id );
        $this->assertGreaterThan( 0, $action_id );
        $this->action_ids[] = $action_id;

        Sentient_Forms_Plugin::instance()->get_async_metadata_store()->record_job(
            $job_id,
            self::LEGACY_HOOK,
            $payload,
            $run_at,
            $action_id,
            $group
        );
        $recorded = Sentient_Forms_Plugin::instance()->get_async_request_store()->record(
            $execution_request_id,
            [
                'action_id'      => 'legacy_master_action',
                'adapter'        => 'gravity_forms',
                'status'         => 'queued',
                'payload_digest' => hash( 'sha256', $execution_request_id ),
            ]
        );
        $this->assertTrue( $recorded );

        return $action_id;
    }
}
