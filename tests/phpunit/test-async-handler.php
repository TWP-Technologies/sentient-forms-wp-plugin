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

if ( ! class_exists( 'GFForms' ) )
{
    class GFForms {}
}

if ( ! class_exists( 'GFAPI' ) )
{
    class GFAPI {
        /** @var array<int,array<string,mixed>> */
        public static array $entries = [];
        /** @var array<int,array<string,mixed>> */
        public static array $forms = [];
        public static int $get_entry_calls = 0;
        public static int $get_form_calls = 0;
        public static mixed $maybe_process_feeds_result = [];
        public static ?Throwable $maybe_process_feeds_exception = null;
        /** @var array<int,array<string,mixed>> */
        public static array $maybe_process_feeds_calls = [];

        public static function get_entry( $entry_id ) {
            ++self::$get_entry_calls;
            $entry_id = (int) $entry_id;
            if ( isset( self::$entries[ $entry_id ] ) )
            {
                return self::$entries[ $entry_id ];
            }

            return new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
        }

        public static function get_form( $form_id ) {
            ++self::$get_form_calls;
            $form_id = (int) $form_id;
            return self::$forms[ $form_id ] ?? false;
        }

        public static function get_forms(): array {
            return array_values( self::$forms );
        }

        public static function update_form( $form, $form_id = null ) {
            $form_id = null === $form_id && is_array( $form ) && isset( $form['id'] )
                ? (int) $form['id']
                : (int) $form_id;

            if ( $form_id <= 0 )
            {
                return new WP_Error( 'missing_form_id', 'Missing form id.' );
            }

            if ( is_array( $form ) )
            {
                $form['id'] = $form_id;
            }

            self::$forms[ $form_id ] = $form;

            return true;
        }

        public static function update_entry( $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['id'] ) )
            {
                return new WP_Error( 'missing_entry_id', 'Missing entry id.' );
            }

            self::$entries[ (int) $entry['id'] ] = $entry;

            return true;
        }

        public static function maybe_process_feeds( $entry, $form, $addon_slug = '', $reset_meta = false, $bypass_feed_delay = false )
        {
            self::$maybe_process_feeds_calls[] = compact( 'entry', 'form', 'addon_slug', 'reset_meta', 'bypass_feed_delay' );
            if ( self::$maybe_process_feeds_exception )
            {
                throw self::$maybe_process_feeds_exception;
            }

            return self::$maybe_process_feeds_result;
        }
    }
}

if ( ! class_exists( 'Sentient_Forms_Test_Gf_Meta_Store' ) )
{
    class Sentient_Forms_Test_Gf_Meta_Store {
        /** @var array<int,array<string,mixed>> */
        private static array $meta = [];

        public static function reset(): void {
            self::$meta = [];
        }

        public static function set_meta( int $entry_id, string $meta_key, mixed $value ): void {
            self::update_meta( $entry_id, $meta_key, $value );
        }

        public static function update_meta( int $entry_id, string $meta_key, mixed $value ): void {
            if ( ! isset( self::$meta[ $entry_id ] ) )
            {
                self::$meta[ $entry_id ] = [];
            }

            self::$meta[ $entry_id ][ $meta_key ] = $value;
        }

        public static function get_meta( int $entry_id, string $meta_key ): mixed {
            return self::$meta[ $entry_id ][ $meta_key ] ?? null;
        }
    }
}

if ( ! function_exists( 'gform_get_meta' ) )
{
    function gform_get_meta( $entry_id, $meta_key ) {
        return Sentient_Forms_Test_Gf_Meta_Store::get_meta( (int) $entry_id, (string) $meta_key );
    }
}

if ( ! function_exists( 'gform_update_meta' ) )
{
    function gform_update_meta( $entry_id, $meta_key, $value ) {
        Sentient_Forms_Test_Gf_Meta_Store::update_meta( (int) $entry_id, (string) $meta_key, $value );
        return true;
    }
}

final class Sentient_Forms_Test_Recording_Gravity_Adapter extends Sentient_Forms_Gravity_Forms_Adapter
{
    public int $success_calls = 0;
    public int $error_calls = 0;
    public bool $throw_after_success = false;
    /** @var array<string,mixed> */
    public array $last_success_context = [];

    public function finalize_async_success( array $context, array $result ): void
    {
        ++$this->success_calls;
        $this->last_success_context = $context;
        parent::finalize_async_success( $context, $result );

        if ( $this->throw_after_success )
        {
            throw new RuntimeException( 'Synthetic adapter finalization failure.' );
        }
    }

    public function finalize_async_error( array $context, WP_Error $error ): void
    {
        ++$this->error_calls;
    }
}

class AsyncHandlerTest extends WP_UnitTestCase
{
    private Sentient_Forms_Plugin $plugin;
    private ?Sentient_Forms_Form_Source_Discovery_Adapter_Interface $original_gravity_adapter = null;
    private ?Closure $evaluation_filter = null;

	protected function setUp(): void
	{
		parent::setUp();

        $this->plugin = Sentient_Forms_Plugin::instance();
        $GLOBALS['__sentient_forms_http_calls'] = [];

        // Record outbound requests; focused tests provide provider responses.
        add_filter(
            'pre_http_request',
            static function ( $preempt, $args, $url ) {
                $GLOBALS['__sentient_forms_http_calls'][] = [
                    'url'    => $url,
                    'method' => $args['method'] ?? 'GET',
                    'body'   => $args['body'] ?? null,
                ];

                return $preempt;
            },
            10,
            3
        );
        $GLOBALS['__sentient_forms_async_queue'] = [ 'enqueued' => [] ];
        remove_all_actions( 'sentient_forms_async_job_scheduled' );
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
        $this->truncate_async_runtime_tables();
		delete_option( 'sentient_forms_async_settings' );
        GFAPI::$entries         = [];
        GFAPI::$forms           = [];
        GFAPI::$get_entry_calls = 0;
        GFAPI::$get_form_calls  = 0;

        if ( class_exists( 'Sentient_Forms_Test_Gf_Meta_Store' ) )
        {
            Sentient_Forms_Test_Gf_Meta_Store::reset();
        }

        if ( class_exists( 'Sentient_Forms_Test_Gravity_Meta_Store' ) )
        {
            Sentient_Forms_Test_Gravity_Meta_Store::reset();
        }

        $handler = new Sentient_Forms_Async_Handler( $this->plugin, true );
        $reflection = new ReflectionClass( $this->plugin );
        $property   = $reflection->getProperty( 'async_handler' );
        $property->setAccessible( true );
        $property->setValue( $this->plugin, $handler );
    }

    protected function tearDown(): void
    {
        $this->truncate_async_runtime_tables();
        $GLOBALS['__sentient_forms_async_queue'] = [ 'enqueued' => [] ];
        $GLOBALS['__sentient_forms_http_calls'] = [];
        GFAPI::$entries         = [];
        GFAPI::$forms           = [];
        GFAPI::$get_entry_calls = 0;
        GFAPI::$get_form_calls  = 0;
        remove_all_filters( 'pre_http_request' );
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );
        remove_all_filters( 'sentient_forms_elementor_data_for_post' );
        remove_all_actions( 'sentient_forms_async_job_scheduled' );
        remove_all_filters( 'sentient_forms_async_queue_threshold' );
        remove_all_filters( 'sentient_forms_async_stale_queue_threshold' );
        if ( $this->evaluation_filter )
        {
            remove_filter( 'sentient_forms_async_evaluation_jobs', $this->evaluation_filter, 99 );
            $this->evaluation_filter = null;
        }
        if ( $this->original_gravity_adapter )
        {
            $this->plugin->get_form_adapter_registry()->register_adapter( $this->original_gravity_adapter );
            $this->original_gravity_adapter = null;
        }
        parent::tearDown();
    }

    private function truncate_async_runtime_tables(): void
    {
        global $wpdb;

        foreach ( [ 'sentient_async_requests', 'sentient_execution_events' ] as $table_name )
        {
            $table = $wpdb->prefix . $table_name;
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table )
            {
                $wpdb->query( "TRUNCATE TABLE {$table}" );
            }
        }
    }


    public function test_process_local_mapping_normalizes_legacy_elementor_identity_from_persisted_payload(): void
    {
        $store = $this->plugin->get_async_request_store();
        $store->record( 'legacy-elementor-dependency', [ 'status' => 'running', 'action_id' => 'local_mapping_900' ] );
        $store->record( 'legacy-elementor-dependent', [ 'status' => 'queued', 'action_id' => 'local_mapping_901' ] );

        $this->plugin->get_async_handler()->process_local_mapping(
            [
                'local_mapping_id'    => 901,
                'form_source'        => 'elementor_forms',
                'adapter_id'         => 'elementor_forms',
                'form_id'            => '91:formabc',
                'entry_id'           => '11111111-1111-4111-8111-111111111111',
                'execution_request_id' => 'legacy-elementor-dependent',
                'context'            => [
                    'form_source'                      => 'elementor_forms',
                    'adapter_id'                       => 'elementor_forms',
                    'form_id'                          => '91:formabc',
                    'entry_id'                         => '11111111-1111-4111-8111-111111111111',
                    'action_id'                        => 'local_first_901',
                    'dependency_mapping_ids'           => [ 'local_first_900' ],
                    'dependency_execution_request_ids' => [ 'local_first_900' => 'legacy-elementor-dependency' ],
                    'dependency_wait_started_at'       => time(),
                    'dependency_wait_max_seconds'      => 120,
                    'dependency_wait_poll_seconds'     => 5,
                    'custom_data'                      => [ 'form_source' => 'elementor_forms' ],
                ],
            ]
        );

        $retry   = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
        $payload = $retry['args'][0] ?? [];

        $this->assertSame( 'sentient_forms_process_local_mapping', $retry['hook'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $payload['form_source'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $payload['context']['form_source'] ?? null );
        $this->assertSame( 'elementor_pro_forms', $payload['context']['adapter_id'] ?? null );
        $this->assertSame( 'elementor_forms', $payload['context']['custom_data']['form_source'] ?? null );
    }















    public function test_process_local_mapping_requeues_identifier_payload_while_dependency_is_pending(): void
    {
        $dependency_id = 'local-pending-dependency';
        $request_id    = 'local-dependent-request';
        $store         = $this->plugin->get_async_request_store();
        $store->record( $dependency_id, [ 'status' => 'running', 'action_id' => 'local_mapping_900' ] );

        $handler = $this->plugin->get_async_handler();
        $this->assertTrue(
            $handler->schedule_local_mapping(
                901,
                [ 'id' => 901 ],
                [ 'id' => 1901 ],
                [
                    'form_source'                      => 'gravity_forms',
                    'form_id'                          => '901',
                    'entry_id'                         => '1901',
                    'central_action_id'                => 'local_dependency_fixture',
                    'execution_request_id'             => $request_id,
                    'dependency_mapping_ids'           => [ 'local_first_900' ],
                    'dependency_execution_request_ids' => [ 'local_first_900' => $dependency_id ],
                    'dependency_wait_started_at'       => time(),
                    'dependency_wait_max_seconds'      => 120,
                    'dependency_wait_poll_seconds'     => 5,
                ]
            )
        );
        $first = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
        $handler->process_local_mapping( $first['args'][0] ?? [] );

        $jobs = $GLOBALS['__sentient_forms_async_queue']['enqueued'];
        $this->assertCount( 2, $jobs );
        $retry = end( $jobs );
        $this->assertSame( 'sentient_forms_process_local_mapping', $retry['hook'] ?? null );
        $this->assertSame( $request_id, $retry['args'][0]['execution_request_id'] ?? null );
        $this->assertArrayNotHasKey( 'form', $retry['args'][0] ?? [] );
        $this->assertArrayNotHasKey( 'entry', $retry['args'][0] ?? [] );
        $this->assertSame( 'queued', $store->get( $request_id )['status'] ?? null );
    }










    public function test_process_local_mapping_marks_non_gravity_dependent_job_skipped_from_upstream_spam_event(): void
    {
        global $wpdb;

        $request_store         = $this->plugin->get_async_request_store();
        $submission_uuid       = '22222222-3333-4444-8555-666666666666';
        $dependency_request_id = 'cf7_local_dep_req_spam_event';

        $request_store->record(
            $dependency_request_id,
            [
                'status'    => 'success',
                'action_id' => 'local_mapping_100',
            ]
        );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => $dependency_request_id,
                'submission_uuid'      => $submission_uuid,
                'form_source'          => 'contact_form_7',
                'form_id'              => '42',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'classification' => 'spam',
                        'confidence'     => 0.97,
                    ],
                ],
            ]
        );

        $scheduled = $this->plugin->get_async_handler()->schedule_local_mapping(
            321,
            [ 'id' => 42, 'title' => 'CF7 Local Dependency Gate' ],
            [
                'id'              => null,
                'submission_uuid' => $submission_uuid,
                'message'         => 'skip local-first downstream work',
            ],
            [
                'hook'                             => 'wpcf7_mail_sent',
                'form_source'                      => 'contact_form_7',
                'form_id'                          => 42,
                'submission_uuid'                  => $submission_uuid,
                'action_id'                        => 'local_first_321',
                'action_name_label'                => 'Entry Summary',
                'local_mapping_id'                 => 'local_first_321',
                'dependency_mapping_ids'           => [ 'local_first_100' ],
                'dependency_execution_request_ids' => [ 'local_first_100' => $dependency_request_id ],
                'dependency_wait_started_at'       => time(),
                'dependency_wait_max_seconds'      => 120,
                'dependency_wait_poll_seconds'     => 5,
                'settings'                         => [
                    'skip_on_upstream_spam' => true,
                ],
            ]
        );

        $this->assertTrue( $scheduled );

        $job     = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
        $payload = $job['args'][0] ?? [];
        $this->assertIsArray( $payload );

        $handler = $this->plugin->get_async_handler();
        $handler->process_local_mapping( $payload );

        $metadata = $this->plugin->get_async_metadata_store()->get( $payload['context']['job_id'] );
        $this->assertSame( 'skipped', $metadata['status'] ?? null );

        $row = $request_store->get( $payload['execution_request_id'], 'job' );
        $this->assertSame( 'skipped', $row['status'] ?? null );
        $this->assertStringContainsString( 'spam', (string) ( $row['last_error'] ?? '' ) );

        $local_events = array_values(
            array_filter(
                $events->list_recent( 10 ),
                static fn( array $event ): bool => (string) ( $event['execution_request_id'] ?? '' ) === (string) $payload['execution_request_id']
            )
        );
        $this->assertNotEmpty( $local_events );
        $this->assertSame( 'skipped', $local_events[0]['status'] ?? null );
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
        $third = $this->plugin->dispatch_action_evaluation( $job );

        $this->assertTrue( $first );
        $this->assertFalse( $second, 'Second scheduling should be blocked by the evaluation ledger' );
        $this->assertFalse( $third, 'Later duplicates must not reclaim the canonical evaluation owner' );
        $this->assertCount( 1, $GLOBALS['__sentient_forms_async_queue']['enqueued'] );

        $rows = $this->plugin->get_async_request_store()->list( [ 'record_type' => 'evaluation', 'limit' => 5 ] );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'queued', $rows[0]['status'] );
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
        $metadata = $this->plugin->get_async_metadata_store()->get( $evaluation_job['args']['context']['job_id'] );
        $this->assertSame( 'success', $metadata['status'] ?? null );
    }

    public function test_process_evaluation_marks_request_success_with_action_scheduler_runtime_shape(): void
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
        $runtime_args   = array_values( $evaluation_job['args'] );
        $handler->process_evaluation( $runtime_args[0] );

        $rows = $this->plugin->get_async_request_store()->list( [ 'record_type' => 'evaluation', 'limit' => 5 ] );
        $this->assertSame( 'success', $rows[0]['status'] );
        $metadata = $this->plugin->get_async_metadata_store()->get( $evaluation_job['args']['context']['job_id'] );
        $this->assertSame( 'success', $metadata['status'] ?? null );
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
        $metadata = $this->plugin->get_async_metadata_store()->get( $evaluation_job['args']['context']['job_id'] );
        $this->assertSame( 'failed', $metadata['status'] ?? null );
    }


    public function test_metadata_store_tracks_job_status(): void
    {
        $this->assertTrue(
            $this->plugin->dispatch_action_evaluation(
                [
                    'adapter_id' => 'gravity_forms', 'entry_id' => 909, 'form_id' => 88,
                    'action_id' => 'entry_evaluation', 'payload' => [ 'result' => 'ok' ],
                ]
            )
        );
        $jobs = $this->plugin->get_async_metadata_store()->all();
        $job  = reset( $jobs );
        $this->assertSame( 'queued', $job['status'] ?? null );
        $this->assertSame( 'sentient_forms_async', $job['group'] ?? null );
        $this->plugin->get_async_handler()->process_evaluation(
            $GLOBALS['__sentient_forms_async_queue']['enqueued'][0]['args']
        );
        $jobs = $this->plugin->get_async_metadata_store()->all();
        $job  = reset( $jobs );
        $this->assertSame( 'success', $job['status'] ?? null );
        $this->assertNotEmpty( $job['completed_at'] ?? null );
    }

    public function test_metadata_store_payload_filter_applies(): void
    {
        add_filter( 'sentient_forms_async_metadata_payload', static fn( array $payload ): array => [ 'context' => $payload['context'] ?? [] ] );
        $this->assertTrue(
            $this->plugin->dispatch_action_evaluation(
                [
                    'adapter_id' => 'gravity_forms', 'entry_id' => 1, 'form_id' => 1,
                    'action_id' => 'entry_evaluation', 'payload' => [ 'result' => 'ok' ],
                ]
            )
        );
        $jobs = $this->plugin->get_async_metadata_store()->all();
        $job  = reset( $jobs );
        $this->assertSame( [ 'context' => $job['context'] ], $job['payload'] );
        remove_all_filters( 'sentient_forms_async_metadata_payload' );
    }

    public function test_metadata_store_jobs_filter_applies(): void
    {
        $flag = false;
        add_filter( 'sentient_forms_async_metadata_jobs', static function ( array $jobs ) use ( &$flag ): array { $flag = true; return $jobs; } );
        $this->assertTrue(
            $this->plugin->dispatch_action_evaluation(
                [
                    'adapter_id' => 'gravity_forms', 'entry_id' => 20, 'form_id' => 10,
                    'action_id' => 'entry_evaluation', 'payload' => [ 'result' => 'ok' ],
                ]
            )
        );
        $this->plugin->get_async_metadata_store()->all();
        $this->assertTrue( $flag );
        remove_all_filters( 'sentient_forms_async_metadata_jobs' );
    }

    public function test_metadata_store_purge_removes_matching_jobs(): void
    {
        $this->assertTrue(
            $this->plugin->dispatch_action_evaluation(
                [
                    'adapter_id' => 'gravity_forms', 'entry_id' => 40, 'form_id' => 30,
                    'action_id' => 'entry_evaluation', 'payload' => [ 'result' => 'ok' ],
                ]
            )
        );
        $store = $this->plugin->get_async_metadata_store();
        $jobs  = $store->all();
        $this->assertCount( 1, $jobs );
        $store->update_status( array_key_first( $jobs ), 'success', [ 'completed_at' => time() - DAY_IN_SECONDS ] );
        $removed = $store->purge( static fn( array $job ): bool => 'success' === $job['status'] );
        $this->assertSame( 1, $removed );
        $this->assertSame( [], $store->all() );
	}

    public function test_async_handler_uses_configured_retry_policy(): void
	{
        $this->plugin->get_async_settings_service()->update_settings(
            [ 'max_attempts' => 5, 'base_delay_seconds' => 120, 'max_delay_seconds' => 900 ]
		);
        $this->assertTrue(
            $this->plugin->get_async_handler()->schedule_local_mapping(
                77,
                [ 'id' => 11 ],
                [ 'id' => 22 ],
                [ 'form_source' => 'gravity_forms', 'form_id' => '11', 'entry_id' => '22' ]
            )
        );
        $job_context = $GLOBALS['__sentient_forms_async_queue']['enqueued'][0]['args'][0]['context'];
        $this->assertSame( 5, $job_context['max_attempts'] );
        $this->assertSame( 120, $job_context['backoff_base_delay'] );
        $this->assertSame( 900, $job_context['backoff_max_delay'] );
        $method = new ReflectionMethod( $this->plugin->get_async_handler(), 'compute_backoff_delay' );
        $this->assertSame( 120, $method->invoke( $this->plugin->get_async_handler(), 1, $job_context ) );
        $this->assertSame( 240, $method->invoke( $this->plugin->get_async_handler(), 2, $job_context ) );
    }







    public function test_process_local_mapping_ignores_stale_option_backed_dependency_policy(): void
    {
        global $wpdb;

        $request_store         = $this->plugin->get_async_request_store();
        $submission_uuid       = '77777777-8888-4999-8aaa-bbbbbbbbbbbb';
        $dependency_request_id = 'cf7_stale_option_dependency';

        $request_store->record(
            $dependency_request_id,
            [
                'status'    => 'success',
                'action_id' => 'local_mapping_100',
            ]
        );

        ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->record(
            [
                'execution_request_id' => $dependency_request_id,
                'submission_uuid'      => $submission_uuid,
                'form_source'          => 'contact_form_7',
                'form_id'              => '42',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'classification' => 'spam',
                        'confidence'     => 0.99,
                    ],
                ],
            ]
        );

        update_option(
            'sentient_forms_actions_contact_form_7_42',
            [
                'local_first_100' => [
                    'central_action_id' => 'spam_detection_v1',
                    'settings'          => [
                        'skip_downstream_on_spam' => true,
                    ],
                ],
            ],
            false
        );

        try
        {
            $scheduled = $this->plugin->get_async_handler()->schedule_local_mapping(
                321,
                [ 'id' => 42, 'title' => 'CF7 Stale Dependency Policy' ],
                [
                    'id'              => null,
                    'submission_uuid' => $submission_uuid,
                    'message'         => 'stale options must not authorize runtime policy',
                ],
                [
                    'hook'                             => 'wpcf7_mail_sent',
                    'form_source'                      => 'contact_form_7',
                    'form_id'                          => 42,
                    'submission_uuid'                  => $submission_uuid,
                    'action_id'                        => 'local_first_321',
                    'action_name_label'                => 'Entry Summary',
                    'local_mapping_id'                 => 'local_first_321',
                    'dependency_mapping_ids'           => [ 'local_first_100' ],
                    'dependency_execution_request_ids' => [ 'local_first_100' => $dependency_request_id ],
                    'dependency_wait_started_at'       => time(),
                    'dependency_wait_max_seconds'      => 120,
                    'dependency_wait_poll_seconds'     => 5,
                ]
            );

            $this->assertTrue( $scheduled );

            $job     = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
            $payload = $job['args'][0] ?? [];
            $this->assertIsArray( $payload );

            $this->plugin->get_async_handler()->process_local_mapping( $payload );

            $row = $request_store->get( $payload['execution_request_id'], 'job' );
            $this->assertSame( 'failed', $row['status'] ?? null );
            $this->assertStringContainsString( 'mapping', strtolower( (string) ( $row['last_error'] ?? '' ) ) );
        }
        finally
        {
            delete_option( 'sentient_forms_actions_contact_form_7_42' );
        }
    }

    public function test_legacy_option_backed_async_entrypoints_are_not_registered(): void
    {
        $handler = $this->plugin->get_async_handler();

        $this->assertFalse( has_action( 'sentient_forms_process_action' ) );
        $this->assertFalse( method_exists( $handler, 'process_action' ) );
        $this->assertFalse( method_exists( $handler, 'schedule_action' ) );
        $this->assertFalse( method_exists( $this->plugin, 'process_action_async' ) );
        $this->assertSame( 10, has_action( 'sentient_forms_process_local_mapping', [ $handler, 'process_local_mapping' ] ) );
    }

    public function test_schedule_local_mapping_is_idempotent_for_same_identifier_payload(): void
    {
        $handler = $this->plugin->get_async_handler();
        $context = [
            'form_source' => 'gravity_forms',
            'form_id' => '901',
            'entry_id' => '1901',
            'central_action_id' => 'local_idempotency_fixture',
            'execution_request_id' => 'local-idempotency-request',
        ];

        $this->assertTrue( $handler->schedule_local_mapping( 901, [ 'id' => 901 ], [ 'id' => 1901 ], $context ) );
        $this->assertFalse( $handler->schedule_local_mapping( 901, [ 'id' => 901 ], [ 'id' => 1901 ], $context ) );

        $jobs = array_values(
            array_filter(
                $GLOBALS['__sentient_forms_async_queue']['enqueued'],
                static fn( array $job ): bool => 'sentient_forms_process_local_mapping' === ( $job['hook'] ?? '' )
            )
        );
        $this->assertCount( 1, $jobs );
        $this->assertArrayNotHasKey( 'form', $jobs[0]['args'][0] ?? [] );
        $this->assertArrayNotHasKey( 'entry', $jobs[0]['args'][0] ?? [] );
    }

    public function test_managed_local_mapping_keeps_provider_identity_across_queued_and_failed_events(): void
    {
        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_first_runtime_tables();

        global $wpdb;

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'managed_async_failure_identity',
                'display_name'         => 'Managed Async Failure Identity',
                'definition_json'      => [
                    'prompt_template' => 'Summarize {{name}}.',
                ],
                'model_selection_json' => [
                    'provider' => 'sentient_managed',
                    'model'    => 'google/gemini-3-flash-preview',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '324',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'name' => '1',
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        GFAPI::$forms[324] = [
            'id'     => 324,
            'title'  => 'Managed Async Failure Form',
            'fields' => [],
        ];

        $handler   = $this->plugin->get_async_handler();
        $scheduled = $handler->schedule_local_mapping(
            $mapping_id,
            [ 'id' => 324 ],
            [ 'id' => 657 ],
            [
                'form_source'          => 'gravity_forms',
                'form_id'              => 324,
                'entry_id'             => 657,
                'action_id'            => 'local_first_' . $mapping_id,
                'central_action_id'    => 'managed_async_failure_identity',
                'execution_request_id' => 'managed-async-failure-identity',
                'max_attempts'         => 1,
            ]
        );
        $this->assertTrue( $scheduled );

        $queued_event = $events->get_by_request_id( 'managed-async-failure-identity' );
        $this->assertIsArray( $queued_event );
        $this->assertSame( 'queued', $queued_event['status'] ?? null );
        $this->assertSame( 'sentient_managed', $queued_event['provider'] ?? null );
        $this->assertSame( 'google/gemini-3-flash-preview', $queued_event['model'] ?? null );

        $job     = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
        $payload = $job['args'][0] ?? [];
        $handler->process_local_mapping( $payload );

        $failed_event = $events->get_by_request_id( 'managed-async-failure-identity' );
        $this->assertIsArray( $failed_event );
        $this->assertSame( 'failed', $failed_event['status'] ?? null );
        $this->assertSame( 'sentient_managed', $failed_event['provider'] ?? null );
        $this->assertSame( 'google/gemini-3-flash-preview', $failed_event['model'] ?? null );
    }

	public function test_schedule_local_mapping_enqueues_identifier_only_payload(): void
	{
		Sentient_Forms_Installer::maybe_upgrade();
		$this->truncate_local_first_runtime_tables();
		$submission_uuid = '33333333-4444-4555-8666-777777777777';

		$scheduled = $this->plugin->get_async_handler()->schedule_local_mapping(
			77,
			[
				'id'     => 321,
				'title'  => 'Local Async Form',
				'fields' => [ 'large form payload should not be queued' ],
			],
			[
				'id' => 654,
				'1'  => 'private field value should not be queued',
			],
			[
				'form_source'          => 'gravity_forms',
					'form_id'              => 321,
					'entry_id'             => 654,
					'action_id'            => 'local_first_77',
					'execution_request_id' => null,
					'submission_uuid'      => $submission_uuid,
				]
			);

		$this->assertTrue( $scheduled );
		$this->assertNotEmpty( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );

		$job = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
		$this->assertSame( 'sentient_forms_process_local_mapping', $job['hook'] );
		$this->assertSame( 'sentient_forms_async', $job['group'] );

		$payload = $job['args'][0] ?? [];
			$this->assertSame( 77, $payload['local_mapping_id'] ?? null );
			$this->assertSame( '321', $payload['form_id'] ?? null );
			$this->assertSame( '654', $payload['entry_id'] ?? null );
			$this->assertNotEmpty( $payload['execution_request_id'] ?? '' );
			$this->assertSame( $payload['execution_request_id'], $payload['context']['execution_request_id'] ?? null );
			$this->assertSame( $submission_uuid, $payload['context']['submission_uuid'] ?? null );
			$this->assertArrayNotHasKey( 'form', $payload );
			$this->assertArrayNotHasKey( 'entry', $payload );

		$metadata = $this->plugin->get_async_metadata_store()->get( $payload['context']['job_id'] );
		$this->assertSame( 'queued', $metadata['status'] ?? null );
		$this->assertArrayNotHasKey( 'form', $metadata['payload'] ?? [] );
		$this->assertArrayNotHasKey( 'entry', $metadata['payload'] ?? [] );

			global $wpdb;
			$events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
			$event  = $events->get_by_request_id( (string) $payload['execution_request_id'] );
		$this->assertIsArray( $event );
		$this->assertSame( 'queued', $event['status'] ?? null );
		$this->assertSame( 77, (int) ( $event['mapping_id'] ?? 0 ) );
		$this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
	}

    public function test_bulk_local_mapping_scheduling_preserves_identifier_only_payloads_under_backlog(): void
    {
        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_first_runtime_tables();
        add_filter( 'sentient_forms_async_queue_threshold', static fn () => 20 );
        add_filter( 'sentient_forms_async_stale_queue_threshold', static fn () => 0 );

        $handler = $this->plugin->get_async_handler();
        for ( $index = 0; $index < 25; $index++ )
        {
            $entry_id  = 9000 + $index;
            $scheduled = $handler->schedule_local_mapping(
                77,
                [
                    'id'     => 900,
                    'title'  => 'Bulk Local Async Form',
                    'fields' => [ 'large form payload should not be queued' ],
                ],
                [
                    'id'      => $entry_id,
                    'raw_key' => 'private field value should not be queued',
                ],
                [
                    'form_source'          => 'gravity_forms',
                    'form_id'              => 900,
                    'entry_id'             => $entry_id,
                    'action_id'            => 'local_first_77',
                    'execution_request_id' => 'bulk-local-' . $entry_id,
                ]
            );

            $this->assertTrue( $scheduled, 'Bulk local mapping job should schedule.' );
        }

        $jobs = $GLOBALS['__sentient_forms_async_queue']['enqueued'] ?? [];
        $this->assertCount( 25, $jobs );

        foreach ( $jobs as $index => $job )
        {
            $entry_id = 9000 + $index;
            $this->assertSame( 'sentient_forms_process_local_mapping', $job['hook'] );
            $this->assertSame( 'sentient_forms_async', $job['group'] );

            $payload = $job['args'][0] ?? [];
            $this->assertSame( 77, $payload['local_mapping_id'] ?? null );
            $this->assertSame( '900', $payload['form_id'] ?? null );
            $this->assertSame( (string) $entry_id, $payload['entry_id'] ?? null );
            $this->assertSame( 'bulk-local-' . $entry_id, $payload['execution_request_id'] ?? null );
            $this->assertSame( 'bulk-local-' . $entry_id, $payload['context']['execution_request_id'] ?? null );
            $this->assertSame( 'local_mapping', $payload['context']['job_type'] ?? null );
            $this->assertArrayNotHasKey( 'form', $payload );
            $this->assertArrayNotHasKey( 'entry', $payload );
            $this->assertArrayNotHasKey( 'raw_key', $payload );
        }

        $health = ( new Sentient_Forms_Async_Health_Service( $this->plugin ) )->evaluate();
        $codes  = wp_list_pluck( $health['warnings'], 'code' );

        $this->assertSame( 25, $health['queue_depth'] );
        $this->assertContains( 'queue_backlog', $codes );
        $this->assertNotContains( 'queue_stalled', $codes );
    }



	public function test_process_local_mapping_executes_openrouter_mapping_from_identifiers(): void
	{
		Sentient_Forms_Installer::maybe_upgrade();
		$this->truncate_local_first_runtime_tables();

		global $wpdb;

		$credentials    = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
		$consents       = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
		$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
		$mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
		$events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );
		$vault          = new Sentient_Forms_Provider_Credential_Vault();
		$encrypted      = $vault->encrypt( 'sk-or-local-async-test-secret' );
		$submission_uuid = '22222222-3333-4444-8555-666666666666';

		$this->assertIsString( $encrypted );

		$credential_id = $credentials->create(
			[
				'provider'          => 'openrouter',
				'label'             => 'Async OpenRouter key',
				'auth_mode'         => 'manual_key',
				'encrypted_secret'  => $encrypted,
				'status'            => 'valid',
				'last_validated_at' => current_time( 'mysql' ),
			]
		);
		$this->assertIsInt( $credential_id );

		$this->assertIsInt( $consents->record( 'openrouter', '2026-04-18', 0 ) );

		$action_id = $custom_actions->create(
			[
				'code'                 => 'local_async_summary',
				'display_name'         => 'Local Async Summary',
				'definition_json'      => [
					'prompt_template' => 'Summarize {{name}} from {{form.title}}.',
				],
				'model_selection_json' => [
					'provider'      => 'openrouter',
					'model'         => 'openrouter/auto',
					'credential_id' => $credential_id,
				],
				'status'               => 'active',
			]
		);
		$this->assertIsInt( $action_id );

		$mapping_id = $mappings->create(
			[
				'form_source'         => 'gravity_forms',
				'form_id'             => '321',
				'hook'                => 'gform_after_submission',
				'action_kind'         => 'custom_action',
				'action_id'           => $action_id,
				'input_bindings_json' => [
					'name' => '1',
				],
				'execution_mode'      => 'async',
				'effect_mapping_json' => [
					'store_result' => true,
					'meta'         => [
						'sentient_forms_async_summary' => 'structured.summary',
					],
				],
				'enabled'             => true,
			]
		);
		$this->assertIsInt( $mapping_id );

		GFAPI::$forms[321] = [
			'id'     => 321,
			'title'  => 'Async Local Form',
			'fields' => [],
		];
		GFAPI::$entries[654] = [
			'id'      => 654,
			'form_id' => 321,
			'1'       => 'Async Lead',
		];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ): mixed {
				if ( false !== strpos( $url, 'openrouter.ai/api/v1/chat/completions' ) )
				{
					return [
						'headers'  => [],
						'body'     => wp_json_encode(
							[
								'id'      => 'chatcmpl-local-async',
								'model'   => 'openrouter/auto',
								'choices' => [
									[
										'message'       => [
											'role'    => 'assistant',
											'content' => wp_json_encode(
												[
													'summary' => 'Async local execution completed.',
												]
											),
										],
										'finish_reason' => 'stop',
									],
								],
								'usage'   => [
									'prompt_tokens'     => 7,
									'completion_tokens' => 5,
									'total_tokens'      => 12,
								],
							]
						),
						'response' => [
							'code'    => 200,
							'message' => 'OK',
						],
						'cookies'  => [],
					];
				}

				return $preempt;
			},
			9,
			3
		);

		$handler   = $this->plugin->get_async_handler();
        $adapter           = new Sentient_Forms_Test_Recording_Gravity_Adapter( $this->plugin );
        $stable_mapping_id = 'local_first_' . $mapping_id;
        $this->original_gravity_adapter = $this->plugin->get_form_adapter_registry()->get_adapter_by_id( 'gravity_forms' );
        $this->plugin->get_form_adapter_registry()->register_adapter( $adapter );
        $adapter->throw_after_success = true;
        gform_update_meta( 654, 'sentient_forms_deferred_webhook_feed_ids', [] );
        gform_update_meta( 654, 'sentient_forms_deferred_webhook_mapping_ids', [ $stable_mapping_id ] );
		$scheduled = $handler->schedule_local_mapping(
			$mapping_id,
			[ 'id' => 321 ],
			[ 'id' => 654 ],
			[
				'form_source'          => 'gravity_forms',
				'form_id'              => 321,
				'entry_id'             => 654,
				'action_id'            => 'local_first_' . $mapping_id,
				'execution_request_id' => 'local-async-request-success',
				'submission_uuid'      => $submission_uuid,
			]
		);
		$this->assertTrue( $scheduled );

        $this->evaluation_filter = static function ( array $jobs, array $job ): array {
                $jobs[] = [
                    'adapter_id' => 'gravity_forms',
                    'entry_id'   => $job['context']['entry_id'] ?? null,
                    'form_id'    => $job['context']['form_id'] ?? null,
                    'action_id'  => $job['context']['action_id'] ?? null,
                    'payload'    => [ 'evaluation_fixture' => true ],
                ];

                return $jobs;
            };
        add_filter(
            'sentient_forms_async_evaluation_jobs',
            $this->evaluation_filter,
            99,
            2
        );

		$job     = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
		$payload = $job['args'][0] ?? [];
        $local_jobs_before_processing = array_values(
            array_filter(
                $GLOBALS['__sentient_forms_async_queue']['enqueued'],
                static fn ( array $queued_job ): bool => 'sentient_forms_process_local_mapping' === ( $queued_job['hook'] ?? '' )
            )
        );
		$handler->process_local_mapping( $payload );

		$this->assertSame( 'Async local execution completed.', gform_get_meta( 654, 'sentient_forms_async_summary' ) );
        $this->assertNotEmpty( gform_get_meta( 654, 'sentient_forms_last_processed_at' ) );
        $this->assertNotEmpty( gform_get_meta( 654, 'sentient_forms_last_response' ) );
        $this->assertNull( gform_get_meta( 654, 'sentient_forms_notes' ) );
        $this->assertSame( 1, $adapter->success_calls );
        $this->assertSame( 0, $adapter->error_calls );
        $this->assertSame( $stable_mapping_id, $adapter->last_success_context['action_id'] ?? null );
        $this->assertSame( [], gform_get_meta( 654, 'sentient_forms_deferred_webhook_feed_ids' ) );
        $this->assertSame( [], gform_get_meta( 654, 'sentient_forms_deferred_webhook_mapping_ids' ) );

		$event = $events->get_by_request_id( 'local-async-request-success' );
		$this->assertIsArray( $event );
		$this->assertSame( 'succeeded', $event['status'] ?? null );
		$this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
		$this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
		$this->assertSame( 'Async local execution completed.', $event['result_json']['structured']['summary'] ?? null );

		$request = $this->plugin->get_async_request_store()->get( 'local-async-request-success' );
		$this->assertSame( 'success', $request['status'] ?? null );
        $local_jobs_after_processing = array_values(
            array_filter(
                $GLOBALS['__sentient_forms_async_queue']['enqueued'],
                static fn ( array $queued_job ): bool => 'sentient_forms_process_local_mapping' === ( $queued_job['hook'] ?? '' )
            )
        );
        $evaluation_jobs = array_values(
            array_filter(
                $GLOBALS['__sentient_forms_async_queue']['enqueued'],
                static fn ( array $queued_job ): bool => 'sentient_forms_evaluate_action' === ( $queued_job['hook'] ?? '' )
            )
        );
        $this->assertCount(
            count( $local_jobs_before_processing ),
            $local_jobs_after_processing,
            'A finalization failure must not retry an already-completed provider action.'
        );
        $this->assertCount( 1, $evaluation_jobs );
        $this->assertTrue( $evaluation_jobs[0]['args']['context']['evaluation_payload']['evaluation_fixture'] ?? false );

		foreach ( $GLOBALS['__sentient_forms_http_calls'] as $call )
		{
			$this->assertStringNotContainsString( 'sentientforms.com', $call['url'] );
		}
	}

    public function test_process_local_mapping_executes_elementor_mapping_from_submission_ledger_identifiers(): void
    {
        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_first_runtime_tables();

        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Elementor Landing',
            ]
        );
        update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $this->elementor_local_mapping_form_tree() ) ) );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn() => [ $page_id ] );

        $credentials     = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
        $consents        = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
        $custom_actions  = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings        = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_capture  = new Sentient_Forms_Submission_Ledger_Capture_Service( $wpdb );
        $events          = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $vault           = new Sentient_Forms_Provider_Credential_Vault();
        $encrypted       = $vault->encrypt( 'sk-or-elementor-local-async-test-secret' );
        $form_id         = $page_id . ':formabc';
        $submission_uuid = '44444444-5555-4666-8777-888888888888';

        $this->assertIsString( $encrypted );

        $ledger_settings->set_enabled( 'elementor_pro_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $captured = $ledger_capture->capture(
            [
                'submission_uuid'  => $submission_uuid,
                'form_source'      => 'elementor_pro_forms',
                'form_id'          => $form_id,
                'logical_fields'   => [
                    'full_name' => 'Elementor Lead',
                ],
                'provider_metadata' => [
                    'form_name' => 'Async Elementor Form',
                ],
            ]
        );
        $this->assertIsArray( $captured );

        $credential_id = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Elementor Async OpenRouter key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted,
                'status'            => 'valid',
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        $this->assertIsInt( $consents->record( 'openrouter', '2026-04-18', 0 ) );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'elementor_local_async_summary',
                'display_name'         => 'Elementor Local Async Summary',
                'definition_json'      => [
                    'prompt_template' => 'Summarize {{name}} from {{form.title}}.',
                ],
                'model_selection_json' => [
                    'provider'      => 'openrouter',
                    'model'         => 'openrouter/auto',
                    'credential_id' => $credential_id,
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'elementor_pro_forms',
                'form_id'             => $form_id,
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'name' => 'full_name',
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        add_filter(
            'pre_http_request',
            static function ( $preempt, array $args, string $url ): mixed {
                if ( false !== strpos( $url, 'openrouter.ai/api/v1/chat/completions' ) )
                {
                    return [
                        'headers'  => [],
                        'body'     => wp_json_encode(
                            [
                                'id'      => 'chatcmpl-elementor-local-async',
                                'model'   => 'openrouter/auto',
                                'choices' => [
                                    [
                                        'message'       => [
                                            'role'    => 'assistant',
                                            'content' => wp_json_encode(
                                                [
                                                    'summary' => 'Elementor local execution completed.',
                                                ]
                                            ),
                                        ],
                                        'finish_reason' => 'stop',
                                    ],
                                ],
                                'usage'   => [
                                    'prompt_tokens'     => 7,
                                    'completion_tokens' => 5,
                                    'total_tokens'      => 12,
                                ],
                            ]
                        ),
                        'response' => [
                            'code'    => 200,
                            'message' => 'OK',
                        ],
                        'cookies'  => [],
                    ];
                }

                return $preempt;
            },
            9,
            3
        );

        $handler   = $this->plugin->get_async_handler();
        $scheduled = $handler->schedule_local_mapping(
            $mapping_id,
            [
                'id'    => $form_id,
                'title' => 'Async Elementor Form',
            ],
            [
                'submission_uuid' => $submission_uuid,
                'full_name'       => 'Elementor Lead',
            ],
            [
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => $form_id,
                'action_id'            => 'local_first_' . $mapping_id,
                'execution_request_id' => 'elementor-local-async-request-success',
                'submission_uuid'      => $submission_uuid,
                'native_effect_outcomes' => [
                    [
                        'effect' => 'entry_note',
                        'status' => 'unsupported',
                        'reason' => 'native_notes_unavailable',
                    ],
                ],
            ]
        );
        $this->assertTrue( $scheduled );

        $job     = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
        $payload = $job['args'][0] ?? [];
        $this->assertNull( $payload['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $payload['submission_uuid'] ?? null );

        $handler->process_local_mapping( $payload );

        $event = $events->get_by_request_id( 'elementor-local-async-request-success' );
        $this->assertIsArray( $event );
        $this->assertSame( 'succeeded', $event['status'] ?? null );
        $this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
        $this->assertSame( 'elementor_pro_forms', $event['form_source'] ?? null );
        $this->assertSame( $form_id, $event['form_id'] ?? null );
        $this->assertNull( $event['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
        $this->assertSame( 'Elementor local execution completed.', $event['result_json']['structured']['summary'] ?? null );
        $this->assertSame(
            [
                [
                    'effect' => 'all',
                    'status' => 'skipped',
                    'reason' => 'no_effect_mapping',
                ],
                [
                    'effect' => 'entry_note',
                    'status' => 'unsupported',
                    'reason' => 'native_notes_unavailable',
                ],
            ],
            $event['result_json']['native_effect_outcomes'] ?? null
        );

        $request = $this->plugin->get_async_request_store()->get( 'elementor-local-async-request-success' );
        $this->assertSame( 'success', $request['status'] ?? null );
    }

	public function test_process_local_mapping_retries_transient_openrouter_failure(): void
	{
		Sentient_Forms_Installer::maybe_upgrade();
		$this->truncate_local_first_runtime_tables();

		global $wpdb;

		$credentials    = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
		$consents       = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
		$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
		$mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
		$vault          = new Sentient_Forms_Provider_Credential_Vault();
		$encrypted      = $vault->encrypt( 'sk-or-local-async-retry-secret' );

		$this->assertIsString( $encrypted );

		$credential_id = $credentials->create(
			[
				'provider'          => 'openrouter',
				'label'             => 'Async retry OpenRouter key',
				'auth_mode'         => 'manual_key',
				'encrypted_secret'  => $encrypted,
				'status'            => 'valid',
				'last_validated_at' => current_time( 'mysql' ),
			]
		);
		$this->assertIsInt( $credential_id );
		$this->assertIsInt( $consents->record( 'openrouter', '2026-04-18', 0 ) );

		$action_id = $custom_actions->create(
			[
				'code'                 => 'local_async_retry_summary',
				'display_name'         => 'Local Async Retry Summary',
				'definition_json'      => [
					'prompt_template' => 'Summarize {{name}}.',
				],
				'model_selection_json' => [
					'provider'      => 'openrouter',
					'model'         => 'openrouter/auto',
					'credential_id' => $credential_id,
				],
				'status'               => 'active',
			]
		);
		$this->assertIsInt( $action_id );

		$mapping_id = $mappings->create(
			[
				'form_source'         => 'gravity_forms',
				'form_id'             => '322',
				'hook'                => 'gform_after_submission',
				'action_kind'         => 'custom_action',
				'action_id'           => $action_id,
				'input_bindings_json' => [
					'name' => '1',
				],
				'execution_mode'      => 'async',
				'effect_mapping_json' => [
					'store_result' => true,
				],
				'enabled'             => true,
			]
		);
		$this->assertIsInt( $mapping_id );

		GFAPI::$forms[322] = [
			'id'     => 322,
			'title'  => 'Async Retry Form',
			'fields' => [],
		];
		GFAPI::$entries[655] = [
			'id'      => 655,
			'form_id' => 322,
			'1'       => 'Retry Lead',
		];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ): mixed {
				if ( false !== strpos( $url, 'openrouter.ai/api/v1/chat/completions' ) )
				{
					return [
						'headers'  => [],
						'body'     => wp_json_encode( [ 'error' => [ 'message' => 'Provider temporarily unavailable.' ] ] ),
						'response' => [
							'code'    => 500,
							'message' => 'Server Error',
						],
						'cookies'  => [],
					];
				}

				return $preempt;
			},
			9,
			3
		);

		$handler   = $this->plugin->get_async_handler();
		$scheduled = $handler->schedule_local_mapping(
			$mapping_id,
			[ 'id' => 322 ],
			[ 'id' => 655 ],
			[
				'form_source'          => 'gravity_forms',
				'form_id'              => 322,
				'entry_id'             => 655,
				'action_id'            => 'local_first_' . $mapping_id,
				'execution_request_id' => 'local-async-request-retry',
				'backoff_base_delay'   => 5,
				'backoff_max_delay'    => 5,
			]
		);
		$this->assertTrue( $scheduled );

		$first_job     = end( $GLOBALS['__sentient_forms_async_queue']['enqueued'] );
		$first_payload = $first_job['args'][0] ?? [];

		$before_retry = time();
		$handler->process_local_mapping( $first_payload );

		$jobs = $GLOBALS['__sentient_forms_async_queue']['enqueued'];
		$this->assertGreaterThanOrEqual( 2, count( $jobs ) );

		$retry_job     = end( $jobs );
		$retry_payload = $retry_job['args'][0] ?? [];
		$this->assertSame( 'sentient_forms_process_local_mapping', $retry_job['hook'] );
		$this->assertSame( 'local-async-request-retry', $retry_payload['execution_request_id'] ?? null );
		$this->assertSame( 2, (int) ( $retry_payload['context']['attempt'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( $before_retry + 5, (int) ( $retry_job['run_at'] ?? 0 ) );

		$request = $this->plugin->get_async_request_store()->get( 'local-async-request-retry' );
		$this->assertSame( 'queued', $request['status'] ?? null );
		$this->assertStringContainsString( 'Provider temporarily unavailable', (string) ( $request['last_error'] ?? '' ) );

		$metadata = $this->plugin->get_async_metadata_store()->get( $first_payload['context']['job_id'] );
		$this->assertSame( 'retry_scheduled', $metadata['status'] ?? null );
	}

	public function test_process_local_mapping_does_not_retry_missing_auth_transport_failure(): void
	{
		Sentient_Forms_Installer::maybe_upgrade();
		$this->truncate_local_first_runtime_tables();

		global $wpdb;

		$credentials    = new Sentient_Forms_Provider_Credentials_Repository( $wpdb );
		$consents       = new Sentient_Forms_External_Service_Consent_Repository( $wpdb );
		$custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
		$mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
		$events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );
		$vault          = new Sentient_Forms_Provider_Credential_Vault();
		$encrypted      = $vault->encrypt( 'sk-or-local-async-terminal-secret' );

		$this->assertIsString( $encrypted );

		$credential_id = $credentials->create(
			[
				'provider'          => 'openrouter',
				'label'             => 'Async terminal OpenRouter key',
				'auth_mode'         => 'manual_key',
				'encrypted_secret'  => $encrypted,
				'status'            => 'valid',
				'last_validated_at' => current_time( 'mysql' ),
			]
		);
		$this->assertIsInt( $credential_id );
		$this->assertIsInt( $consents->record( 'openrouter', '2026-04-18', 0 ) );

		$action_id = $custom_actions->create(
			[
				'code'                 => 'local_async_terminal_summary',
				'display_name'         => 'Local Async Terminal Summary',
				'definition_json'      => [
					'prompt_template' => 'Summarize {{name}}.',
				],
				'model_selection_json' => [
					'provider'      => 'openrouter',
					'model'         => 'openrouter/auto',
					'credential_id' => $credential_id,
				],
				'status'               => 'active',
			]
		);
		$this->assertIsInt( $action_id );

		$mapping_id = $mappings->create(
			[
				'form_source'         => 'gravity_forms',
				'form_id'             => '323',
				'hook'                => 'gform_after_submission',
				'action_kind'         => 'custom_action',
				'action_id'           => $action_id,
				'input_bindings_json' => [
					'name' => '1',
				],
				'execution_mode'      => 'async',
				'effect_mapping_json' => [
					'store_result' => true,
				],
				'enabled'             => true,
			]
		);
		$this->assertIsInt( $mapping_id );

		GFAPI::$forms[323] = [
			'id'     => 323,
			'title'  => 'Async Terminal Form',
			'fields' => [],
		];
		GFAPI::$entries[656] = [
			'id'      => 656,
			'form_id' => 323,
			'1'       => 'Terminal Lead',
		];

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $args, string $url ): mixed {
				if ( false !== strpos( $url, 'openrouter.ai/api/v1/chat/completions' ) )
				{
					return new WP_Error(
						'http_request_failed',
						'Missing Authentication header',
						[ 'status' => 401 ]
					);
				}

				return $preempt;
			},
			9,
			3
		);

		$handler   = $this->plugin->get_async_handler();
		$scheduled = $handler->schedule_local_mapping(
			$mapping_id,
			[ 'id' => 323 ],
			[ 'id' => 656 ],
			[
				'form_source'          => 'gravity_forms',
				'form_id'              => 323,
				'entry_id'             => 656,
				'action_id'            => 'local_first_' . $mapping_id,
				'execution_request_id' => 'local-async-request-terminal',
				'backoff_base_delay'   => 5,
				'backoff_max_delay'    => 5,
			]
		);
		$this->assertTrue( $scheduled );

		$jobs            = $GLOBALS['__sentient_forms_async_queue']['enqueued'];
		$initial_job_cnt = count( $jobs );
		$payload         = end( $jobs )['args'][0] ?? [];

		$handler->process_local_mapping( $payload );

		$this->assertCount( $initial_job_cnt, $GLOBALS['__sentient_forms_async_queue']['enqueued'] );

		$request = $this->plugin->get_async_request_store()->get( 'local-async-request-terminal' );
		$this->assertSame( 'failed', $request['status'] ?? null );
		$this->assertStringContainsString( 'Missing Authentication header', (string) ( $request['last_error'] ?? '' ) );

		$metadata = $this->plugin->get_async_metadata_store()->get( $payload['context']['job_id'] );
		$this->assertSame( 'failed', $metadata['status'] ?? null );

		$event = $events->get_by_request_id( 'local-async-request-terminal' );
		$this->assertIsArray( $event );
		$this->assertSame( 'failed', $event['status'] ?? null );
		$this->assertSame( 'openrouter_http_error', $event['error_code'] ?? null );
		$this->assertSame( 'Missing Authentication header', $event['error_message'] ?? null );

		$notes = gform_get_meta( 656, 'sentient_forms_notes' );
		$this->assertIsArray( $notes );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'Missing Authentication header', (string) ( $notes[0]['content'] ?? '' ) );
	}

	private function truncate_local_first_runtime_tables(): void
	{
		global $wpdb;

		foreach (
			[
				'sentient_provider_credentials',
				'sentient_external_service_consents',
				'sentient_custom_actions',
				'sentient_form_mappings',
				'sentient_execution_events',
				'sentient_submission_ledger_settings',
				'sentient_submission_ledger',
			] as $table
		)
		{
			$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
		}
	}

    /**
     * @return array<int, array<string, mixed>>
     */
    private function elementor_local_mapping_form_tree(): array
    {
        return [
            [
                'id'       => 'container1',
                'elType'   => 'container',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => 'formabc',
                        'elType'     => 'widget',
                        'widgetType' => 'form',
                        'settings'   => [
                            'form_name'   => 'Async Elementor Form',
                            'form_fields' => [
                                [
                                    'custom_id'   => 'full_name',
                                    'field_label' => 'Full name',
                                    'field_type'  => 'text',
                                    'required'    => 'true',
                                ],
                            ],
                        ],
                        'elements'   => [],
                    ],
                ],
            ],
        ];
    }
}
