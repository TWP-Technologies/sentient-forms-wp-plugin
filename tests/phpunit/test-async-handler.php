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
}
