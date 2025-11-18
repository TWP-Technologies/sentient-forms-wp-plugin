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
        Sentient_Forms_Installer::maybe_upgrade();
        global $wpdb;
        $wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'sentient_async_requests' );
		delete_option( 'sentient_forms_async_settings' );

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
        $this->assertArrayHasKey( 'context', $job['args'] );
        $this->assertSame( 'sentient_forms_async', $job['group'] );
		$this->assertArrayHasKey( 'job_id', $job['args']['context'] );
		$this->assertNotEmpty( $job['args']['context']['job_id'] );
		$this->assertSame( 3, $job['args']['context']['max_attempts'] );
		$this->assertSame( 60, $job['args']['context']['backoff_base_delay'] );
		$this->assertSame( HOUR_IN_SECONDS, $job['args']['context']['backoff_max_delay'] );
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

        $settings = [ 'central_action_id' => 'summary_v1', 'action_name_label' => 'Summary Action' ];
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
		$this->assertSame( 'Summary Action', $job_context['action_name_label'] );
		$this->assertSame( 404, $job_context['entry_id'] );
		$this->assertSame( 60, $job_context['backoff_base_delay'] );
		$this->assertSame( HOUR_IN_SECONDS, $job_context['backoff_max_delay'] );
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
        $this->assertArrayHasKey( 'job_id', $context );
        $this->assertNotEmpty( $context['job_id'] );
    }

    public function test_dispatch_action_evaluation_blocks_duplicate_jobs(): void
    {
        $job = [
            'adapter_id' => 'gravity_forms',
            'entry_id'   => 999,
            'form_id'    => 88,
            'action_id'  => 'entry_evaluation',
            'payload'    => [ 'result' => 'duplicate-check' ],
        ];

        $first = $this->plugin->dispatch_action_evaluation( $job );
        $second = $this->plugin->dispatch_action_evaluation( $job );

        $this->assertTrue( $first );
        $this->assertFalse( $second, 'Second scheduling should be blocked by the evaluation ledger' );

        $rows = $this->plugin->get_async_request_store()->list( [ 'record_type' => 'evaluation', 'limit' => 5 ] );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'queued', $rows[0]['status'] );
    }

    public function test_process_evaluation_marks_request_success(): void
    {
        $job = [
            'adapter_id' => 'gravity_forms',
            'entry_id'   => 1234,
            'form_id'    => 77,
            'action_id'  => 'entry_evaluation',
            'payload'    => [ 'result' => 'ok' ],
        ];

        $this->plugin->dispatch_action_evaluation( $job );
        $evaluation_job = array_pop( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
        $handler        = $this->plugin->get_async_handler();
        $handler->process_evaluation( $evaluation_job['args'] );

        $rows = $this->plugin->get_async_request_store()->list( [ 'record_type' => 'evaluation', 'limit' => 5 ] );
        $this->assertSame( 'success', $rows[0]['status'] );
    }

    public function test_success_can_schedule_evaluation_via_filter(): void
    {
        add_filter(
            'sentient_forms_async_evaluation_jobs',
            static function ( array $jobs, array $job, array $result ): array {
                if ( $result['result'] ?? null )
                {
                    $jobs[] = [
                        'adapter_id' => $job['context']['form_source'] ?? 'gravity_forms',
                        'entry_id'   => $job['context']['entry_id'] ?? 0,
                        'form_id'    => $job['context']['form_id'] ?? 0,
                        'action_id'  => 'entry_evaluation',
                        'payload'    => [ 'copied_result' => $result['result'] ],
                    ];
                }

                return $jobs;
            },
            10,
            3
        );

        $data = [
            'form'  => [ 'id' => 71, 'title' => 'Evaluation' ],
            'entry' => [ 'id' => 701, 'field_1' => 'Example' ],
        ];

        $settings = [ 'central_action_id' => 'summary_v1' ];
        $context  = [ 'hook' => 'gform_after_submission', 'form_source' => 'gravity_forms' ];

        $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

        $job = $GLOBALS['__sentient_forms_async_queue']['enqueued'][0];
        $handler = $this->plugin->get_async_handler();

        // Fake action execution success payload.
        $handler->process_action(
            $job['args']['action_id'],
            $job['args']['data'],
            $job['args']['settings'],
            $job['args']['execution_request_id'],
            $job['args']['context']
        );

        $evaluation_jobs = array_filter(
            $GLOBALS['__sentient_forms_async_queue']['enqueued'],
            static fn( $queued ) => $queued['hook'] === 'sentient_forms_evaluate_action'
        );

        $this->assertNotEmpty( $evaluation_jobs, 'Evaluation job should be scheduled via filter.' );

        remove_all_filters( 'sentient_forms_async_evaluation_jobs' );
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
        $this->assertSame( 'sentient_forms_async', $job['group'] );
        $this->assertIsArray( $job['payload'] );

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

    public function test_metadata_store_payload_filter_applies(): void
    {
        add_filter(
            'sentient_forms_async_metadata_payload',
            static function ( array $payload ): array {
                return [ 'context' => $payload['context'] ?? [] ];
            }
        );

        $data = [
            'form'  => [ 'id' => 1 ],
            'entry' => [ 'id' => 1 ],
        ];

        $settings = [ 'central_action_id' => 'spam_detection_v1' ];
        $context  = [ 'form_source' => 'gravity_forms' ];

        $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

        $jobs = $this->plugin->get_async_metadata_store()->all();
        $job  = reset( $jobs );
        $this->assertSame( [ 'context' => $job['context'] ], $job['payload'] );

        remove_all_filters( 'sentient_forms_async_metadata_payload' );
    }

    public function test_metadata_store_jobs_filter_applies(): void
    {
        $flag = false;
        add_filter(
            'sentient_forms_async_metadata_jobs',
            static function ( array $jobs ) use ( &$flag ): array {
                $flag = true;
                return $jobs;
            }
        );

        $data = [ 'form' => [ 'id' => 10 ], 'entry' => [ 'id' => 20 ] ];
        $settings = [ 'central_action_id' => 'spam_detection_v1' ];

        $this->plugin->process_action_async( 'entry_evaluation', $data, $settings, [ 'form_source' => 'gravity_forms' ] );

        $this->plugin->get_async_metadata_store()->all();
        $this->assertTrue( $flag );

        remove_all_filters( 'sentient_forms_async_metadata_jobs' );
    }

	public function test_metadata_store_purge_removes_matching_jobs(): void
	{
		$data = [ 'form' => [ 'id' => 30 ], 'entry' => [ 'id' => 40 ] ];
		$settings = [ 'central_action_id' => 'spam_detection_v1' ];
		$context  = [ 'form_source' => 'gravity_forms' ];

		$this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

		$store = $this->plugin->get_async_metadata_store();
		$jobs  = $store->all();
		$this->assertCount( 1, $jobs );

		$store->update_status( array_key_first( $jobs ), 'success', [ 'completed_at' => time() - DAY_IN_SECONDS ] );

		$removed = $store->purge(
			static function ( array $job ): bool {
				return 'success' === $job['status'];
			}
		);

		$this->assertSame( 1, $removed );
		$this->assertSame( [], $store->all() );
	}

	public function test_async_handler_uses_configured_retry_policy(): void
	{
		$service = $this->plugin->get_async_settings_service();
		$service->update_settings(
			[
				'max_attempts'       => 5,
				'base_delay_seconds' => 120,
				'max_delay_seconds'  => 900,
			]
		);

		$data = [
			'form'  => [ 'id' => 11, 'title' => 'Contact' ],
			'entry' => [ 'id' => 22 ],
		];
		$settings = [ 'central_action_id' => 'spam_detection_v1' ];
		$context  = [ 'form_source' => 'gravity_forms' ];

		$this->plugin->process_action_async( 'entry_evaluation', $data, $settings, $context );

		$job        = $GLOBALS['__sentient_forms_async_queue']['enqueued'][0];
		$jobContext = $job['args']['context'];
		$this->assertSame( 5, $jobContext['max_attempts'] );
		$this->assertSame( 120, $jobContext['backoff_base_delay'] );
		$this->assertSame( 900, $jobContext['backoff_max_delay'] );

		$handler   = $this->plugin->get_async_handler();
		$method    = new ReflectionMethod( $handler, 'compute_backoff_delay' );
		$method->setAccessible( true );
		$firstDelay = $method->invoke( $handler, 1, $jobContext );
		$secondDelay = $method->invoke( $handler, 2, $jobContext );
		$this->assertSame( 120, $firstDelay );
		$this->assertSame( 240, $secondDelay );
	}
}
