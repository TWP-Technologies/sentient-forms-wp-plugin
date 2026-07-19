<?php

class AsyncHealthServiceTest extends WP_UnitTestCase
{
    private Sentient_Forms_Plugin $plugin;
    private Sentient_Forms_Async_Metadata_Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = Sentient_Forms_Plugin::instance();
        $this->store  = $this->plugin->get_async_metadata_store();
        $this->store->clear();
    }

    protected function tearDown(): void
    {
        $this->store->clear();
        remove_all_filters( 'sentient_forms_async_queue_threshold' );
        remove_all_filters( 'sentient_forms_async_failure_threshold' );
        remove_all_filters( 'sentient_forms_async_failure_window' );
        remove_all_filters( 'sentient_forms_async_stale_queue_threshold' );
        parent::tearDown();
    }

    public function test_queue_warning_triggers_when_threshold_exceeded(): void
    {
        add_filter( 'sentient_forms_async_queue_threshold', static fn () => 1 );
        $emitted_warnings = [];
        $capture_warning = static function ( array $warning ) use ( &$emitted_warnings ): void {
            $emitted_warnings[] = $warning;
        };
        add_action( 'sentient_forms_async_health_warning', $capture_warning );

        $payload = [
            'context' => [ 'action_id' => 'test_action', 'form_source' => 'gravity_forms' ],
        ];
        $this->store->record_job( wp_generate_uuid4(), 'sentient_forms_process_local_mapping', $payload, time() );

        $service = new Sentient_Forms_Async_Health_Service( $this->plugin );
        $result  = $service->evaluate();

        $this->assertSame( 1, $result['queue_depth'] );
        $codes = wp_list_pluck( $result['warnings'], 'code' );
        $this->assertContains( 'queue_backlog', $codes );
        $warning = $result['warnings'][0] ?? [];
        $this->assertStringContainsString( 'Background queue backlog', (string) ( $warning['message'] ?? '' ) );
        $this->assertContains( 'queue_backlog', wp_list_pluck( $emitted_warnings, 'code' ) );

        remove_action( 'sentient_forms_async_health_warning', $capture_warning );
    }

    public function test_failure_warning_triggers_for_recent_failures(): void
    {
        add_filter( 'sentient_forms_async_failure_threshold', static fn () => 1 );

        $job_id = wp_generate_uuid4();
        $payload = [ 'context' => [ 'action_id' => 'summary_v1', 'form_source' => 'gravity_forms' ] ];
        $this->store->record_job( $job_id, 'sentient_forms_process_local_mapping', $payload, time() );
        $this->store->update_status( $job_id, 'failed' );

        $service = new Sentient_Forms_Async_Health_Service( $this->plugin );
        $result  = $service->evaluate();
        $codes   = wp_list_pluck( $result['warnings'], 'code' );
        $this->assertContains( 'consecutive_failures', $codes );
    }

    public function test_stale_queue_warning_triggers_when_oldest_due_job_is_overdue(): void
    {
        add_filter( 'sentient_forms_async_stale_queue_threshold', static fn () => 30 );

        $run_at = time() - 120;
        $payload = [
            'context' => [ 'action_id' => 'summary_v1', 'form_source' => 'gravity_forms' ],
        ];

        $this->store->record_job( wp_generate_uuid4(), 'sentient_forms_process_local_mapping', $payload, $run_at );

        $service = new Sentient_Forms_Async_Health_Service( $this->plugin );
        $result  = $service->evaluate();
        $codes   = wp_list_pluck( $result['warnings'], 'code' );

        $this->assertSame( 1, $result['queue_depth'] );
        $this->assertSame( $run_at, $result['oldest_run_at'] );
        $this->assertGreaterThanOrEqual( 30, $result['oldest_overdue_seconds'] );
        $this->assertContains( 'queue_stalled', $codes );

        $warning = $this->find_warning( $result['warnings'], 'queue_stalled' );
        $this->assertSame( $run_at, $warning['data']['oldest_run_at'] ?? null );
        $this->assertSame( 30, $warning['data']['threshold'] ?? null );
        $this->assertGreaterThanOrEqual( 30, $warning['data']['overdue_seconds'] ?? 0 );
    }

    public function test_backlog_health_preserves_oldest_due_job_under_load(): void
    {
        add_filter( 'sentient_forms_async_queue_threshold', static fn () => 20 );
        add_filter( 'sentient_forms_async_stale_queue_threshold', static fn () => 0 );

        $oldest_run_at = time() + 300;
        $payload = [
            'context' => [ 'action_id' => 'bulk_summary_v1', 'form_source' => 'gravity_forms' ],
        ];

        for ( $index = 0; $index < 25; $index++ )
        {
            $this->store->record_job(
                wp_generate_uuid4(),
                'sentient_forms_process_local_mapping',
                $payload,
                $oldest_run_at + $index
            );
        }

        $service = new Sentient_Forms_Async_Health_Service( $this->plugin );
        $result  = $service->evaluate();
        $codes   = wp_list_pluck( $result['warnings'], 'code' );

        $this->assertSame( 25, $result['queue_depth'] );
        $this->assertSame( $oldest_run_at, $result['oldest_run_at'] );
        $this->assertSame( 0, $result['oldest_overdue_seconds'] );
        $this->assertContains( 'queue_backlog', $codes );
        $this->assertNotContains( 'queue_stalled', $codes );

        $warning = $this->find_warning( $result['warnings'], 'queue_backlog' );
        $this->assertSame( 25, $warning['data']['queue_depth'] ?? null );
        $this->assertSame( 20, $warning['data']['threshold'] ?? null );
        $this->assertSame( $oldest_run_at, $warning['data']['oldest_run_at'] ?? null );
    }

    private function find_warning( array $warnings, string $code ): array
    {
        foreach ( $warnings as $warning )
        {
            if ( $code === ( $warning['code'] ?? '' ) )
            {
                return $warning;
            }
        }

        $this->fail( sprintf( 'Warning with code %s was not found.', $code ) );
    }
}
