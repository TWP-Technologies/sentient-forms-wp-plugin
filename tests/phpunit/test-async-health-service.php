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

    public function test_queue_warning_triggers_when_threshold_exceeded(): void
    {
        add_filter( 'sentient_forms_async_queue_threshold', static fn () => 1 );

        $payload = [
            'context' => [ 'action_id' => 'test_action', 'form_source' => 'gravity_forms' ],
        ];
        $this->store->record_job( wp_generate_uuid4(), 'sentient_forms_process_action', $payload, time() );

        $service = new Sentient_Forms_Async_Health_Service( $this->plugin );
        $result  = $service->evaluate();

        $this->assertSame( 1, $result['queue_depth'] );
        $codes = wp_list_pluck( $result['warnings'], 'code' );
        $this->assertContains( 'queue_backlog', $codes );

        remove_all_filters( 'sentient_forms_async_queue_threshold' );
    }

    public function test_failure_warning_triggers_for_recent_failures(): void
    {
        add_filter( 'sentient_forms_async_failure_threshold', static fn () => 1 );

        $job_id = wp_generate_uuid4();
        $payload = [ 'context' => [ 'action_id' => 'summary_v1', 'form_source' => 'gravity_forms' ] ];
        $this->store->record_job( $job_id, 'sentient_forms_process_action', $payload, time() );
        $this->store->update_status( $job_id, 'failed' );

        $service = new Sentient_Forms_Async_Health_Service( $this->plugin );
        $result  = $service->evaluate();
        $codes   = wp_list_pluck( $result['warnings'], 'code' );
        $this->assertContains( 'consecutive_failures', $codes );

        remove_all_filters( 'sentient_forms_async_failure_threshold' );
    }
}
