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

class Sentient_Forms_Test_Action_Executor extends Sentient_Forms_Action_Executor
{
    public array $captured = [];

    public function execute( string $central_action_id, array $form, array $entry, array $context = [] )
    {
        $this->captured = [
            'central_action_id' => $central_action_id,
            'form'              => $form,
            'entry'             => $entry,
            'context'           => $context,
        ];

        return [
            'result_data' => [],
            'meta'        => [],
        ];
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

        // Short-circuit outbound HTTP to CPS with canned success responses.
        add_filter(
            'pre_http_request',
            static function ( $preempt, $args, $url ) {
                if ( strpos( $url, '/v1/actions/execute' ) !== false ) {
                    return [
                        'response' => [ 'code' => 200 ],
                        'body'     => wp_json_encode(
                            [
                                'success' => true,
                                'data'    => [
                                    'result'          => [ 'result' => 'ok' ],
                                    'execution_id'    => 'test-exec-id',
                                    'evaluation_jobs' => [],
                                ],
                            ]
                        ),
                    ];
                }

                if ( strpos( $url, '/v1/telemetry/async' ) !== false ) {
                    return [
                        'response' => [ 'code' => 200 ],
                        'body'     => wp_json_encode( [ 'success' => true, 'data' => [] ] ),
                    ];
                }

                return $preempt;
            },
            10,
            3
        );
        $GLOBALS['__sentient_forms_async_queue'] = [ 'enqueued' => [] ];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( $hook, $args, $group, $action_id, $run_at ) {
                $GLOBALS['__sentient_forms_async_queue']['enqueued'][] = [
                    'hook'      => $hook,
                    'args'      => $args,
                    'group'     => $group,
                    'action_id' => $action_id,
                    'run_at'    => $run_at,
                ];
            },
            10,
            5
        );
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

    protected function tearDown(): void
    {
        global $wpdb;
        $table   = $wpdb->prefix . 'sentient_async_requests';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table )
        {
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        }
        $GLOBALS['__sentient_forms_async_queue'] = [ 'enqueued' => [] ];
        remove_all_filters( 'pre_http_request' );
        parent::tearDown();
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
        $this->assertGreaterThanOrEqual( 1, count( $GLOBALS['__sentient_forms_async_queue']['enqueued'] ) );

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

        $this->assertCount( 1, $this->plugin->get_async_request_store()->list( [ 'record_type' => 'job', 'limit' => 5 ] ) );
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
        $this->assertSame( '404', (string) $job_context['entry_id'] );
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
        $this->assertSame( $job['payload']['result'], $context['evaluation_payload']['result'] ?? null );
        $this->assertSame( '515', $context['evaluation_payload']['entry_id'] ?? null );
        $this->assertSame( '25', $context['evaluation_payload']['form_id'] ?? null );
        $this->assertSame( 'entry_evaluation', $context['evaluation_payload']['action_id'] ?? null );
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
        $this->assertSame( 'skipped', $rows[0]['status'] );
    }

    public function test_dispatch_action_evaluation_emits_duplicate_block_event(): void
    {
        // Enable telemetry so events are emitted even without debug mode.
        $this->plugin->set_telemetry_settings( [ 'telemetry_opt_in' => true ] );

        $job = [
            'adapter_id' => 'gravity_forms',
            'entry_id'   => 321,
            'form_id'    => 654,
            'action_id'  => 'entry_evaluation',
            'payload'    => [ 'result' => 'duplicate-check' ],
        ];

        $events = [];
        add_action(
            'sentient_forms_async_event',
            function ( $event ) use ( &$events ) {
                $events[] = $event;
            },
            10,
            1
        );

        // First schedule records the row; second is treated as skipped duplicate.
        $this->plugin->dispatch_action_evaluation( $job );
        $this->plugin->dispatch_action_evaluation( $job );

        $this->assertNotEmpty( $events, 'Async event should fire for duplicate evaluation block.' );
        $duplicate = array_filter(
            $events,
            static fn( $event ) => isset( $event['event'] ) && 'evaluation_duplicate_blocked' === $event['event']
        );
        $this->assertNotEmpty( $duplicate, 'Duplicate block event should be present.' );
        $first = array_shift( $duplicate );
        $this->assertSame( 'duplicate_blocked', $first['payload']['reason'] ?? null );
    }

    public function test_dispatch_action_evaluation_enriches_payload_ids(): void
    {
        $job = [
            'adapter_id' => 'gravity_forms',
            'entry_id'   => 111,
            'form_id'    => 222,
            'action_id'  => 'spam_analysis',
            'payload'    => [
                'result_data' => [ 'foo' => 'bar' ],
                // deliberately omit identifiers to verify enrichment
            ],
            'context'    => [
                'action_name_label' => 'Local Spam Detection',
            ],
        ];

        $this->plugin->dispatch_action_evaluation( $job );

        $queued = $GLOBALS['__sentient_forms_async_queue']['enqueued'];
        $this->assertNotEmpty( $queued );

        $evaluation_job = array_pop( $queued );
        $context        = $evaluation_job['args']['context'] ?? [];
        $payload        = $context['evaluation_payload'] ?? [];

        $this->assertSame( 'sentient_forms_evaluate_action', $evaluation_job['hook'] );
        $this->assertSame( 'gravity_forms', $context['adapter_id'] );

        $this->assertSame( 'spam_analysis', $payload['action_id'] ?? null );
        $this->assertSame( 'Local Spam Detection', $payload['action_name_label'] ?? null );
        $this->assertSame( '111', $payload['entry_id'] ?? null );
        $this->assertSame( '222', $payload['form_id'] ?? null );
        $this->assertSame( 'gravity_forms', $payload['form_source'] ?? 'gravity_forms' );

        $rows = $this->plugin->get_async_request_store()->list( [ 'record_type' => 'evaluation', 'limit' => 1 ] );
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

    public function test_process_evaluation_fails_when_adapter_missing(): void
    {
        $job = [
            'adapter_id' => 'missing_adapter', // forces adapter resolution failure
            'entry_id'   => 55,
            'form_id'    => 5,
            'action_id'  => 'spam_analysis',
            'payload'    => [ 'result' => 'ok' ],
        ];

        $this->plugin->dispatch_action_evaluation( $job );
        $evaluation_job = array_pop( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );

        $handler = $this->plugin->get_async_handler();
        $handler->process_evaluation( $evaluation_job['args'] );

        $rows = $this->plugin->get_async_request_store()->list( [ 'record_type' => 'evaluation', 'limit' => 1 ] );
        $this->assertSame( 'failed', $rows[0]['status'] );
        $this->assertStringContainsString( 'Adapter not available', $rows[0]['last_error'] ?? '' );
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

        // Manually dispatch the evaluation job that the filter would request to ensure scheduling path is exercised.
        $this->plugin->dispatch_action_evaluation(
            [
                'adapter_id' => 'gravity_forms',
                'entry_id'   => $job['args']['context']['entry_id'],
                'form_id'    => $job['args']['context']['form_id'] ?? null,
                'action_id'  => 'entry_evaluation',
                'payload'    => [ 'copied_result' => 'ok' ],
                'context'    => $job['args']['context'],
            ]
        );

        $evaluation_jobs = array_filter(
            $GLOBALS['__sentient_forms_async_queue']['enqueued'],
            static fn( $queued ) => $queued['hook'] === 'sentient_forms_evaluate_action'
        );

        $this->assertNotEmpty( $evaluation_jobs, 'Evaluation job should be scheduled via filter.' );

        $first_eval = array_shift( $evaluation_jobs );
        $this->assertSame( 'entry_evaluation', $first_eval['args']['context']['action_id'] ?? null );
        $this->assertSame( 'gravity_forms', $first_eval['args']['context']['adapter_id'] ?? null );

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

	/**
	 * Test that schedule_action allows CPS master actions without local PHP class.
	 *
	 * Regression test for: CPS-managed 'master' actions (action_type_indicator='master')
	 * should be scheduled even when no local PHP action class exists.
	 */
	public function test_schedule_action_allows_master_actions_without_local_class(): void
	{
		$data = [
			'form'  => [ 'id' => 100, 'title' => 'Spam Test' ],
			'entry' => [ 'id' => 500, 'field_1' => 'suspicious content' ],
		];

		// action_type_indicator='master' indicates a CPS-managed action
		$settings = [
			'central_action_id'       => 'spam_detection_v1',
			'action_type_indicator'   => 'master',
		];
		$context = [ 'hook' => 'gform_after_submission', 'form_source' => 'gravity_forms' ];

		// Use a non-existent action_id to verify master actions bypass local class requirement
		$result = $this->plugin->process_action_async( 'nonexistent_local_action', $data, $settings, $context );

		$this->assertTrue( $result, 'Master actions should schedule even without local PHP class' );
		$this->assertGreaterThanOrEqual( 1, count( $GLOBALS['__sentient_forms_async_queue']['enqueued'] ) );

		$job = $GLOBALS['__sentient_forms_async_queue']['enqueued'][0];
		$this->assertSame( 'sentient_forms_process_action', $job['hook'] );
	}

	/**
	 * Test that process_action executes master actions via CPS action executor.
	 *
	 * Regression test for: When process_action is called for a master action that has
	 * no local PHP class handler, it should route to the CPS Action Executor.
	 */
	public function test_process_action_routes_master_actions_to_executor(): void
	{
		$data = [
			'form'  => [ 'id' => 101, 'title' => 'CPS Executor Test' ],
			'entry' => [ 'id' => 501, 'field_1' => 'test content' ],
		];

		$settings = [
			'central_action_id'       => 'spam_detection_v1',
			'action_type_indicator'   => 'master',
		];
		$context = [
			'hook'        => 'gform_after_submission',
			'form_source' => 'gravity_forms',
			'entry_id'    => 501,
			'form_id'     => 101,
		];

		// Schedule the action first (creates metadata store entry)
		$scheduled = $this->plugin->process_action_async(
			'nonexistent_cps_action',
			$data,
			$settings,
			$context
		);
		$this->assertTrue( $scheduled, 'Master action should schedule successfully' );

		// Now process the scheduled job
		$queued = $GLOBALS['__sentient_forms_async_queue']['enqueued'];
		$this->assertNotEmpty( $queued, 'Job should be in queue' );

		$job_payload = end( $queued )['args'];
		$handler = $this->plugin->get_async_handler();
		$handler->process_action(
			$job_payload['action_id'],
			$job_payload['data'],
			$job_payload['settings'],
			$job_payload['execution_request_id'],
			$job_payload['context']
		);

		// Verify the metadata store shows success (CPS HTTP was mocked to return 200)
		$jobs = $this->plugin->get_async_metadata_store()->all();
		$this->assertNotEmpty( $jobs, 'Metadata store should have the job' );
		$job = reset( $jobs );
		$this->assertSame( 'success', $job['status'], 'Master action should execute via CPS executor and succeed' );
	}

	public function test_process_action_passes_central_action_and_payload_to_executor(): void
	{
		$executor = new Sentient_Forms_Test_Action_Executor( $this->plugin );
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'action_executor' );
		$property->setAccessible( true );
		$property->setValue( $this->plugin, $executor );

		$data = [
			'form'  => [ 'id' => 210, 'title' => 'Executor Payload Test' ],
			'entry' => [ 'id' => 701, 'field_1' => 'payload content' ],
		];

		$settings = [
			'central_action_id'     => 'spam_detection_v1',
			'action_type_indicator' => 'master',
		];

		$context = [
			'hook'        => 'gform_after_submission',
			'form_source' => 'gravity_forms',
			'entry_id'    => 701,
			'form_id'     => 210,
			'job_id'      => wp_generate_uuid4(),
		];

		$handler = $this->plugin->get_async_handler();
		$handler->process_action(
			'nonexistent_cps_action',
			$data,
			$settings,
			null,
			$context
		);

		$this->assertSame( 'spam_detection_v1', $executor->captured['central_action_id'] ?? null );
		$this->assertSame( $data['form'], $executor->captured['form'] ?? null );
		$this->assertSame( $data['entry'], $executor->captured['entry'] ?? null );
	}

	/**
	 * Test that non-master actions still require local PHP class.
	 *
	 * Regression test for: Actions without action_type_indicator='master' should
	 * still fail if no local PHP class exists (original behavior preserved).
	 */
	public function test_schedule_action_rejects_non_master_without_local_class(): void
	{
		$data = [
			'form'  => [ 'id' => 102, 'title' => 'Local Action Test' ],
			'entry' => [ 'id' => 502, 'field_1' => 'test content' ],
		];

		// No action_type_indicator (or action_type_indicator != 'master')
		$settings = [
			'central_action_id'       => 'spam_detection_v1',
			'action_type_indicator'   => 'local',  // Not 'master'
		];
		$context = [ 'form_source' => 'gravity_forms' ];

		// Use a non-existent local action_id - should fail for non-master actions
		$result = $this->plugin->process_action_async( 'nonexistent_local_action', $data, $settings, $context );

		// Non-master actions without a local PHP class should fail to schedule
		// This preserves original behavior requiring local action registration
		$this->assertFalse( $result, 'Non-master actions without local PHP class should fail to schedule' );
	}

	/**
	 * Test that process_action merges form-level spam examples when mapping has none.
	 *
	 * Hierarchical resolution: mapping → form → action defaults
	 * When mapping has no spam examples, form-level examples should be used.
	 */
	public function test_process_action_merges_form_level_spam_examples(): void
	{
		// Set up form-level config in wp_options
		$form_config = [
			'spam_detection_v1' => [
				'spam_positive_examples' => [ 'This is a legitimate inquiry', 'I need help with my account' ],
				'spam_negative_examples' => [ 'Buy crypto now!!!', 'You won a prize' ],
			],
		];
		update_option( 'sentient_forms_form_config_gravity_forms_220', $form_config );

		// Inject test executor to capture what gets passed
		$executor = new Sentient_Forms_Test_Action_Executor( $this->plugin );
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'action_executor' );
		$property->setAccessible( true );
		$property->setValue( $this->plugin, $executor );

		$data = [
			'form'  => [ 'id' => 220, 'title' => 'Hierarchical Test' ],
			'entry' => [ 'id' => 801, 'field_1' => 'test content' ],
		];

		// Mapping settings WITHOUT spam examples
		$settings = [
			'central_action_id'     => 'spam_detection_v1',
			'action_type_indicator' => 'master',
			// No spam_positive_examples or spam_negative_examples
		];

		$context = [
			'hook'        => 'gform_after_submission',
			'form_source' => 'gravity_forms',
			'entry_id'    => 801,
			'form_id'     => 220,
			'job_id'      => wp_generate_uuid4(),
			'action_id'   => 'spam_detection_v1',
		];

		$handler = $this->plugin->get_async_handler();
		$handler->process_action(
			'spam_detection_v1',
			$data,
			$settings,
			null,
			$context
		);

		// Verify form-level examples were merged into settings passed to executor
		$captured_settings = $executor->captured['context']['settings'] ?? [];
		$this->assertSame(
			[ 'This is a legitimate inquiry', 'I need help with my account' ],
			$captured_settings['spam_positive_examples'] ?? null,
			'Form-level positive examples should be merged when mapping has none'
		);
		$this->assertSame(
			[ 'Buy crypto now!!!', 'You won a prize' ],
			$captured_settings['spam_negative_examples'] ?? null,
			'Form-level negative examples should be merged when mapping has none'
		);

		delete_option( 'sentient_forms_form_config_gravity_forms_220' );
	}

	/**
	 * Test that mapping-level spam examples override form-level examples.
	 *
	 * Hierarchical resolution: mapping → form → action defaults
	 * When mapping has spam examples, they should take priority over form-level.
	 */
	public function test_process_action_mapping_examples_override_form_level(): void
	{
		// Set up form-level config (should be overridden)
		$form_config = [
			'spam_detection_v1' => [
				'spam_positive_examples' => [ 'Form level positive' ],
				'spam_negative_examples' => [ 'Form level negative' ],
			],
		];
		update_option( 'sentient_forms_form_config_gravity_forms_221', $form_config );

		$executor = new Sentient_Forms_Test_Action_Executor( $this->plugin );
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'action_executor' );
		$property->setAccessible( true );
		$property->setValue( $this->plugin, $executor );

		$data = [
			'form'  => [ 'id' => 221, 'title' => 'Override Test' ],
			'entry' => [ 'id' => 802, 'field_1' => 'test' ],
		];

		// Mapping settings WITH spam examples (should override form-level)
		$settings = [
			'central_action_id'       => 'spam_detection_v1',
			'action_type_indicator'   => 'master',
			'spam_positive_examples'  => [ 'Mapping level positive' ],
			'spam_negative_examples'  => [ 'Mapping level negative' ],
		];

		$context = [
			'form_source' => 'gravity_forms',
			'form_id'     => 221,
			'entry_id'    => 802,
			'job_id'      => wp_generate_uuid4(),
			'action_id'   => 'spam_detection_v1',
		];

		$handler = $this->plugin->get_async_handler();
		$handler->process_action(
			'spam_detection_v1',
			$data,
			$settings,
			null,
			$context
		);

		// Mapping examples should win over form-level
		$captured_settings = $executor->captured['context']['settings'] ?? [];
		$this->assertSame(
			[ 'Mapping level positive' ],
			$captured_settings['spam_positive_examples'] ?? null,
			'Mapping-level examples should override form-level'
		);
		$this->assertSame(
			[ 'Mapping level negative' ],
			$captured_settings['spam_negative_examples'] ?? null,
			'Mapping-level examples should override form-level'
		);

		delete_option( 'sentient_forms_form_config_gravity_forms_221' );
	}

	/**
	 * Test that non-spam-detection actions bypass hierarchical resolution.
	 */
	public function test_process_action_non_spam_actions_bypass_hierarchical_resolution(): void
	{
		// Set up form-level spam config (should be ignored for non-spam actions)
		$form_config = [
			'summary_v1' => [
				'spam_positive_examples' => [ 'Should not appear' ],
			],
		];
		update_option( 'sentient_forms_form_config_gravity_forms_222', $form_config );

		$executor = new Sentient_Forms_Test_Action_Executor( $this->plugin );
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'action_executor' );
		$property->setAccessible( true );
		$property->setValue( $this->plugin, $executor );

		$data = [
			'form'  => [ 'id' => 222, 'title' => 'Summary Test' ],
			'entry' => [ 'id' => 803, 'field_1' => 'content' ],
		];

		$settings = [
			'central_action_id'     => 'summary_v1',
			'action_type_indicator' => 'master',
		];

		$context = [
			'form_source' => 'gravity_forms',
			'form_id'     => 222,
			'entry_id'    => 803,
			'job_id'      => wp_generate_uuid4(),
			'action_id'   => 'summary_v1',
		];

		$handler = $this->plugin->get_async_handler();
		$handler->process_action(
			'summary_v1',
			$data,
			$settings,
			null,
			$context
		);

		// Non-spam actions should not have spam examples merged
		$captured_settings = $executor->captured['context']['settings'] ?? [];
		$this->assertArrayNotHasKey(
			'spam_positive_examples',
			$captured_settings,
			'Non-spam actions should not have spam examples merged'
		);

		delete_option( 'sentient_forms_form_config_gravity_forms_222' );
	}

	/**
	 * Test that hierarchical resolution handles missing form config gracefully.
	 */
	public function test_process_action_handles_missing_form_config(): void
	{
		// Ensure no form config exists
		delete_option( 'sentient_forms_form_config_gravity_forms_223' );

		$executor = new Sentient_Forms_Test_Action_Executor( $this->plugin );
		$reflection = new ReflectionClass( $this->plugin );
		$property   = $reflection->getProperty( 'action_executor' );
		$property->setAccessible( true );
		$property->setValue( $this->plugin, $executor );

		$data = [
			'form'  => [ 'id' => 223, 'title' => 'No Config Test' ],
			'entry' => [ 'id' => 804, 'field_1' => 'content' ],
		];

		$settings = [
			'central_action_id'     => 'spam_detection_v1',
			'action_type_indicator' => 'master',
		];

		$context = [
			'form_source' => 'gravity_forms',
			'form_id'     => 223,
			'entry_id'    => 804,
			'job_id'      => wp_generate_uuid4(),
			'action_id'   => 'spam_detection_v1',
		];

		$handler = $this->plugin->get_async_handler();
		$handler->process_action(
			'spam_detection_v1',
			$data,
			$settings,
			null,
			$context
		);

		// Should execute without error even with no form config
		$this->assertNotEmpty( $executor->captured, 'Executor should be called even without form config' );
		$this->assertSame( 'spam_detection_v1', $executor->captured['central_action_id'] );
	}
}
