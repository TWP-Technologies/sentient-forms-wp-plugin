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
        $this->store->clear();
        parent::tearDown();
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
}
