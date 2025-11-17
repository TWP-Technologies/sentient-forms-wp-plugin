<?php

if ( ! function_exists( 'as_enqueue_async_action' ) )
{
    function as_enqueue_async_action( $hook, $args = [], $group = '', $unique = true )
    {
        $GLOBALS['__sentient_forms_async_queue']['enqueued'][] = compact( 'hook', 'args', 'group', 'unique' );
        return uniqid( 'sentient_async_', true );
    }

    function as_next_scheduled_action( $hook, $args = null, $group = '' )
    {
        foreach ( $GLOBALS['__sentient_forms_async_queue']['enqueued'] ?? [] as $call )
        {
            if ( $call['hook'] === $hook && $call['group'] === $group && $call['args'] === $args )
            {
                return time();
            }
        }

        return false;
    }
}

class AsyncHandlerTest extends WP_UnitTestCase
{
    private Sentient_Forms_Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = Sentient_Forms_Plugin::instance();
        $this->plugin->set_license_data( [ 'proxy_api_key' => 'test-key' ] );
        $GLOBALS['__sentient_forms_async_queue'] = [ 'enqueued' => [] ];
        $this->plugin->get_async_metadata_store()->clear();

        if ( class_exists( 'Sentient_Forms_Test_Gravity_Meta_Store' ) )
        {
            Sentient_Forms_Test_Gravity_Meta_Store::reset();
        }

        $handler = new Sentient_Forms_Async_Handler( $this->plugin, true );
        $reflection = new ReflectionClass( $this->plugin );
        $property   = $reflection->getProperty( 'async_handler' );
        $property->setAccessible( true );
        $property->setValue( $this->plugin, $handler );
        $this->plugin->async = $handler;
    }

    public function test_process_action_async_enqueues_action_scheduler_job(): void
    {
        $data = [
            'form'  => [ 'id' => 42, 'title' => 'Contact' ],
            'entry' => [ 'id' => 101, 'field_1' => 'Hello' ],
        ];

        $settings = [ 'central_action_id' => 'spam_detection_v1' ];
        $context  = [ 'hook' => 'gform_after_submission', 'form_source' => 'gravity_forms' ];

        $result = $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

        $this->assertTrue( $result );
        $this->assertCount( 1, $GLOBALS['__sentient_forms_async_queue']['enqueued'] );

        $job = $GLOBALS['__sentient_forms_async_queue']['enqueued'][0];
        $this->assertSame( 'sentient_forms_process_action', $job['hook'] );
        $this->assertArrayHasKey( 'execution_request_id', $job['args'] );
        $this->assertNotEmpty( $job['args']['execution_request_id'] );
    }

    public function test_process_action_async_is_idempotent_for_same_payload(): void
    {
        $data = [
            'form'  => [ 'id' => 55, 'title' => 'Support' ],
            'entry' => [ 'id' => 202, 'field_1' => 'Help' ],
        ];

        $settings = [ 'central_action_id' => 'spam_detection_v1' ];
        $context  = [ 'hook' => 'gform_after_submission', 'form_source' => 'gravity_forms' ];

        $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );
        $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

        $this->assertCount( 1, $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
    }

    public function test_process_action_async_requires_central_action_id(): void
    {
        $data = [
            'form'  => [ 'id' => 60, 'title' => 'Feedback' ],
            'entry' => [ 'id' => 303, 'field_1' => 'Great job' ],
        ];

        $result = $this->plugin->process_action_async( 'entry_evaluation', $data, [], [ 'form_source' => 'gravity_forms' ] );

        $this->assertFalse( $result );
        $this->assertCount( 0, $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
    }

    public function test_process_action_async_normalizes_context_payload(): void
    {
        $data = [
            'form'  => [ 'id' => 77, 'title' => 'Newsletter' ],
            'entry' => [ 'id' => 404, 'field_1' => 'hi@example.com' ],
        ];

        $settings = [ 'central_action_id' => 'summary_v1' ];
        $context  = [
            'form_source' => 'gravity_forms',
            'action_id'   => 'entry_evaluation',
        ];

        $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

        $job = $GLOBALS['__sentient_forms_async_queue']['enqueued'][0];
        $job_context = $job['args']['context'];

        $this->assertSame( 'sentient_forms_process_action', $job['hook'] );
        $this->assertSame( 'execution', $job_context['job_type'] );
        $this->assertSame( 'gravity_forms', $job_context['form_source'] );
        $this->assertSame( 'entry_evaluation', $job_context['action_id'] );
        $this->assertSame( 1, $job_context['attempt'] );
        $this->assertSame( 'summary_v1', $job_context['central_action_id'] );
    }

    public function test_dispatch_action_evaluation_enqueues_evaluation_job(): void
    {
        $job = [
            'adapter_id' => 'gravity_forms',
            'entry_id'   => 515,
            'form_id'    => 25,
            'action_id'  => 'entry_evaluation',
            'payload'    => [ 'result' => 'ok' ],
        ];

        $this->plugin->dispatch_action_evaluation( $job );

        $queued = $GLOBALS['__sentient_forms_async_queue']['enqueued'];
        $this->assertNotEmpty( $queued );

        $evaluation_job = array_pop( $queued );
        $context = $evaluation_job['args']['context'];

        $this->assertSame( 'sentient_forms_evaluate_action', $evaluation_job['hook'] );
        $this->assertSame( 'evaluation', $context['job_type'] );
        $this->assertSame( 'gravity_forms', $context['adapter_id'] );
        $this->assertSame( 'entry_evaluation', $context['action_id'] );
        $this->assertArrayHasKey( 'evaluation_payload', $context );
        $this->assertSame( $job['payload'], $context['evaluation_payload'] );
    }

    public function test_metadata_store_tracks_job_status(): void
    {
        $data = [
            'form'  => [ 'id' => 88, 'title' => 'Newsletter' ],
            'entry' => [ 'id' => 909, 'field_1' => 'hi@example.com' ],
        ];

        $settings = [ 'central_action_id' => 'spam_detection_v1' ];
        $context  = [ 'form_source' => 'gravity_forms' ];

        $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

        $jobs = $this->plugin->get_async_metadata_store()->all();
        $this->assertNotEmpty( $jobs );
        $job = reset( $jobs );
        $this->assertSame( 'queued', $job['status'] );

        $payload = $GLOBALS['__sentient_forms_async_queue']['enqueued'][0]['args'];
        $handler = $this->plugin->get_async_handler();
        $handler->process_action(
            $payload['action_id'],
            $payload['data'],
            $payload['settings'],
            $payload['execution_request_id'],
            $payload['context'],
        );

        $jobs = $this->plugin->get_async_metadata_store()->all();
        $job  = reset( $jobs );
        $this->assertSame( 'success', $job['status'] );
        $this->assertNotEmpty( $job['completed_at'] );
    }
}
