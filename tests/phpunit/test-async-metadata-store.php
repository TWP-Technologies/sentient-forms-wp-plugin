<?php

class AsyncMetadataStoreTest extends WP_UnitTestCase
{
    private Sentient_Forms_Async_Metadata_Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $plugin      = Sentient_Forms_Plugin::instance();
        $this->store = $plugin->get_async_metadata_store();
        $this->store->clear();
    }

    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_async_metadata_action_scheduler_status' );
        remove_all_filters( 'sentient_forms_action_authority_writer_lock_timeout' );
        $this->store->clear();
        parent::tearDown();
    }

    public function test_record_job_propagates_writer_barrier_errors_without_stale_persistence(): void
    {
        $lock_name_method = new ReflectionMethod( Sentient_Forms_Legacy_Action_Authority_Migrator::class, 'database_lock_name' );
        $lock_name        = $lock_name_method->invoke( null );
        $competitor       = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $acquired         = (int) $competitor->get_var(
            $competitor->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name )
        );
        $this->assertSame( 1, $acquired );
        add_filter( 'sentient_forms_action_authority_writer_lock_timeout', '__return_zero' );
        $job_id = wp_generate_uuid4();

        try
        {
            $result = $this->store->record_job(
                $job_id,
                'sentient_forms_process_local_mapping',
                [ 'context' => [ 'action_id' => 'metadata-barrier-fixture' ] ],
                time()
            );
        }
        finally
        {
            $competitor->get_var( $competitor->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
            $competitor->close();
        }

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $result->get_error_code() );
        $this->assertNull( $this->store->get( $job_id ) );
    }

    public function test_reconcile_with_action_scheduler_reports_drift_in_dry_run(): void
    {
        $job_id  = wp_generate_uuid4();
        $payload = [
            'context' => [
                'action_id'   => 'entry_evaluation',
                'form_source' => 'gravity_forms',
            ],
        ];
        $this->store->record_job( $job_id, 'sentient_forms_evaluate_action', $payload, time() - 600, 3105, 'sentient_forms_async' );

        add_filter(
            'sentient_forms_async_metadata_action_scheduler_status',
            static function ( $status, int $action_scheduler_id ): ?string {
                if ( 3105 === $action_scheduler_id )
                {
                    return 'complete';
                }

                return $status;
            },
            10,
            2
        );

        $report = $this->store->reconcile_with_action_scheduler(
            [
                'apply'      => false,
                'limit'      => 25,
                'older_than' => 0,
                'statuses'   => [ 'queued', 'running', 'retry_scheduled' ],
            ]
        );

        $this->assertSame( 1, $report['scanned'] );
        $this->assertSame( 1, $report['candidates'] );
        $this->assertSame( 0, $report['updated'] );
        $this->assertSame( 1, count( $report['rows'] ) );
        $job = $this->store->get( $job_id );
        $this->assertSame( 'queued', $job['status'] ?? null );
    }

    public function test_reconcile_with_action_scheduler_applies_terminal_updates(): void
    {
        $complete_job_id = wp_generate_uuid4();
        $failed_job_id   = wp_generate_uuid4();
        $payload         = [
            'context' => [
                'action_id'   => 'entry_evaluation',
                'form_source' => 'gravity_forms',
            ],
        ];

        $this->store->record_job( $complete_job_id, 'sentient_forms_evaluate_action', $payload, time() - 900, 4101, 'sentient_forms_async' );
        $this->store->record_job( $failed_job_id, 'sentient_forms_evaluate_action', $payload, time() - 900, 4102, 'sentient_forms_async' );
        $this->store->update_status( $failed_job_id, 'running' );

        add_filter(
            'sentient_forms_async_metadata_action_scheduler_status',
            static function ( $status, int $action_scheduler_id ): ?string {
                if ( 4101 === $action_scheduler_id )
                {
                    return 'complete';
                }

                if ( 4102 === $action_scheduler_id )
                {
                    return 'failed';
                }

                return $status;
            },
            10,
            2
        );

        $report = $this->store->reconcile_with_action_scheduler(
            [
                'apply'      => true,
                'limit'      => 25,
                'older_than' => 0,
            ]
        );

        $this->assertSame( 2, $report['scanned'] );
        $this->assertSame( 2, $report['candidates'] );
        $this->assertSame( 2, $report['updated'] );

        $complete_job = $this->store->get( $complete_job_id );
        $failed_job   = $this->store->get( $failed_job_id );
        $this->assertSame( 'success', $complete_job['status'] ?? null );
        $this->assertSame( 'complete', $complete_job['reconciled_as_status'] ?? null );
        $this->assertSame( 'failed', $failed_job['status'] ?? null );
        $this->assertSame( 'failed', $failed_job['reconciled_as_status'] ?? null );
        $this->assertNotEmpty( $failed_job['last_error'] ?? '' );
    }

    public function test_reconcile_with_action_scheduler_honors_processing_limit(): void
    {
        $job_ids = [];
        $payload = [
            'context' => [
                'action_id'   => 'entry_evaluation',
                'form_source' => 'gravity_forms',
            ],
        ];

        for ( $index = 0; $index < 3; $index++ )
        {
            $job_id    = wp_generate_uuid4();
            $job_ids[] = $job_id;
            $this->store->record_job(
                $job_id,
                'sentient_forms_evaluate_action',
                $payload,
                time() - 900,
                5100 + $index,
                'sentient_forms_async'
            );
        }

        add_filter( 'sentient_forms_async_metadata_action_scheduler_status', static fn () => 'complete' );

        $report = $this->store->reconcile_with_action_scheduler(
            [
                'apply'      => true,
                'limit'      => 2,
                'older_than' => 0,
            ]
        );

        $this->assertSame( 2, $report['scanned'] );
        $this->assertSame( 2, $report['candidates'] );
        $this->assertSame( 2, $report['updated'] );

        $statuses = [];
        foreach ( $job_ids as $job_id )
        {
            $job = $this->store->get( $job_id );
            $statuses[] = $job['status'] ?? null;
        }

        $this->assertCount( 2, array_filter( $statuses, static fn ( $status ) => 'success' === $status ) );
        $this->assertCount( 1, array_filter( $statuses, static fn ( $status ) => 'queued' === $status ) );
    }

    public function test_metadata_store_is_scoped_to_current_blog_in_multisite(): void
    {
        if ( ! is_multisite() )
        {
            $this->markTestSkipped( 'Multisite-only metadata isolation coverage.' );
        }

        $main_job_id = wp_generate_uuid4();
        $this->store->record_job(
            $main_job_id,
            'sentient_forms_evaluate_action',
            [ 'context' => [ 'action_id' => 'main_site_action', 'form_source' => 'gravity_forms' ] ],
            time()
        );

        $second_blog_id = self::factory()->blog->create();
        $second_job_id  = wp_generate_uuid4();

        switch_to_blog( $second_blog_id );

        try
        {
            $this->store->clear();
            $this->assertNull( $this->store->get( $main_job_id ) );

            $this->store->record_job(
                $second_job_id,
                'sentient_forms_evaluate_action',
                [ 'context' => [ 'action_id' => 'second_site_action', 'form_source' => 'gravity_forms' ] ],
                time()
            );

            $this->assertNotNull( $this->store->get( $second_job_id ) );
        }
        finally
        {
            restore_current_blog();
        }

        $this->assertNotNull( $this->store->get( $main_job_id ) );
        $this->assertNull( $this->store->get( $second_job_id ) );
    }
}
