<?php

if ( ! class_exists( 'Sentient_Forms_Test_Accepted_Submission_Adapter' ) )
{
    class Sentient_Forms_Test_Accepted_Submission_Adapter implements Sentient_Forms_Accepted_Submission_Adapter_Interface
    {
        public function get_id(): string
        {
            return 'fixture_forms';
        }

        public function get_name(): string
        {
            return 'Fixture Forms';
        }

        public function is_active(): bool
        {
            return true;
        }

        public function get_forms(): array
        {
            return [];
        }

        public function get_form_fields( $form_id ): array
        {
            return [];
        }

        public function get_accepted_submission_native_hook(): string
        {
            return 'fixture_forms_submission_accepted';
        }

        public function normalize_accepted_submission( mixed $native_submission ): array | WP_Error
        {
            return [
                'form_id'        => '99',
                'form'           => [
                    'id'          => '99',
                    'title'       => 'Fixture Form',
                    'form_source' => 'fixture_forms',
                    'fields'      => [],
                ],
                'logical_fields' => [ 'message' => 'Accepted fixture submission' ],
                'files'          => [],
                'native_entry_id' => 'fixture-entry-99',
                'native_entry_url' => 'https://example.test/fixture-forms/entries/fixture-entry-99',
                'source_submitted_at' => '2026-07-10 09:45:00',
                'provider_metadata' => [ 'form_name' => 'Fixture Form' ],
            ];
        }
    }
}

if ( ! class_exists( 'Sentient_Forms_Test_Context_Tracking_Action' ) )
{
    final class Sentient_Forms_Test_Context_Tracking_Action implements Sentient_Forms_Action_Interface
    {
        /** @var callable */
        private $on_execute;

        public function __construct( private string $id, callable $on_execute )
        {
            $this->on_execute = $on_execute;
        }

        public function get_id(): string
        {
            return $this->id;
        }

        public function get_name(): string
        {
            return 'Context tracking Action';
        }

        public function get_description(): string
        {
            return 'Captures the public Action execution payload.';
        }

        public function get_icon(): string
        {
            return 'dashicons-admin-tools';
        }

        public function get_settings(): array
        {
            return [];
        }

        public function get_hooks(): array
        {
            return [ 'fixture_forms_submission_accepted' ];
        }

        public function get_compatibility(): array
        {
            return [ 'fixture_forms' ];
        }

        public function execute( array $form_data, array $settings, int | string $entry_id, int | string $form_id ): WP_Error | bool | array
        {
            return call_user_func( $this->on_execute, $form_data, $settings, $entry_id, $form_id );
        }

        public function estimate_cost( array $data, array $settings ): int
        {
            return 0;
        }

        public function get_settings_fields(): array
        {
            return [];
        }

        public function validate_settings( array $settings ): array
        {
            return $settings;
        }
    }
}

if ( ! class_exists( 'Sentient_Forms_Test_Context_Action_Executor' ) )
{
    final class Sentient_Forms_Test_Context_Action_Executor extends Sentient_Forms_Action_Executor
    {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function __construct( Sentient_Forms_Plugin $plugin )
        {
            parent::__construct( $plugin, null );
        }

        public function execute( string $central_action_id, array $form, array $entry, array $context = [] )
        {
            $this->calls[] = compact( 'central_action_id', 'form', 'entry', 'context' );

            return [
                'result_data' => [ 'summary' => 'Concrete Action completed.' ],
            ];
        }
    }
}

class Tests_Contact_Form_7_Adapter extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        $this->set_action_executor( null );
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        remove_all_filters( 'sentient_forms_contact_form_7_forms' );
        remove_all_filters( 'sentient_forms_contact_form_7_form_object' );
        remove_all_filters( 'sentient_forms_contact_form_7_current_submission' );
        remove_all_actions( 'sentient_forms_async_job_scheduled' );
        remove_all_actions( 'wpcf7_before_send_mail' );
        remove_all_actions( 'wpcf7_mail_sent' );
        foreach ( [ '44', '47', '48', '49' ] as $form_id )
        {
            delete_option( 'sentient_forms_actions_contact_form_7_' . $form_id );
        }
        delete_option( 'sentient_forms_actions_fixture_forms_99' );

        global $wpdb;
        foreach ( [
            'sentient_custom_actions',
            'sentient_form_mappings',
            'sentient_execution_events',
            'sentient_async_requests',
            'sentient_submission_ledger_settings',
            'sentient_submission_ledger',
        ] as $table ) {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }

        parent::tearDown();
    }

    private function set_action_executor( ?Sentient_Forms_Action_Executor $executor ): void
    {
        $reflection = new ReflectionClass( Sentient_Forms_Plugin::instance() );
        $property   = $reflection->getProperty( 'action_executor' );
        $property->setValue( Sentient_Forms_Plugin::instance(), $executor );
    }

    public function test_contact_form_7_exposes_only_mail_sent_as_accepted_submission_hook(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_current_submission',
            static fn() => new class {
                public function get_posted_data(): array
                {
                    return [
                        'your-name' => 'Accepted User',
                        '_wpcf7'    => '49',
                    ];
                }
            }
        );

        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );

        $this->assertInstanceOf( Sentient_Forms_Accepted_Submission_Adapter_Interface::class, $adapter );
        $this->assertSame( 'wpcf7_mail_sent', $adapter->get_accepted_submission_native_hook() );

        $normalized = $adapter->normalize_accepted_submission( $this->cf7_form( 49, 'Accepted CF7 Submission' ) );
        $this->assertIsArray( $normalized );
        $this->assertSame( '49', $normalized['form_id'] ?? null );
        $this->assertSame( 'Accepted User', $normalized['logical_fields']['your-name'] ?? null );

        $adapter->init();

        $this->assertSame( 10, has_action( 'wpcf7_mail_sent', [ $adapter, 'handle_mail_sent' ] ) );
        $this->assertFalse( has_action( 'wpcf7_before_send_mail', [ $adapter, 'handle_mail_sent' ] ) );
    }

    public function test_accepted_submission_runner_schedules_source_neutral_mapping(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'fixture_mapping' => [
                    'local_mapping_id'           => 'fixture_mapping',
                    'central_action_id'          => 'entry_evaluation',
                    'action_name_label'          => 'Evaluate fixture submission',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => true ],
                ],
            ],
            false
        );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        $runner          = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [ 'native' => 'payload' ] );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_action', $scheduled_jobs[0]['hook'] ?? null );
        $this->assertSame( 'fixture_forms', $scheduled_jobs[0]['args']['context']['form_source'] ?? null );
        $this->assertSame( 'fixture_forms_submission_accepted', $scheduled_jobs[0]['args']['context']['hook'] ?? null );
        $this->assertSame( $submission_uuid, $scheduled_jobs[0]['args']['context']['submission_uuid'] ?? null );
        $this->assertSame( 'fixture-entry-99', $scheduled_jobs[0]['args']['context']['entry_id'] ?? null );
        $this->assertSame( 'Accepted fixture submission', $scheduled_jobs[0]['args']['data']['entry']['message'] ?? null );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'fixture-entry-99', $stored['native_entry_id'] ?? null );
        $this->assertSame( 'https://example.test/fixture-forms/entries/fixture-entry-99', $stored['native_entry_url'] ?? null );
        $this->assertSame( '2026-07-10 09:45:00', $stored['source_submitted_at'] ?? null );
        $this->assertSame( 'Fixture Form', $stored['provider_metadata_json']['form_name'] ?? null );
    }

    public function test_accepted_runner_records_unsupported_native_effect_outcomes_before_filtering(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $action_id = 'fixture_unsupported_effects';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static fn (): array => [ 'classification' => 'ham' ]
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'unsupported_effects' => [
                    'local_mapping_id'           => 'unsupported_effects',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Unsupported fixture effects',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'async'               => false,
                        'effect_mapping_json' => [
                            'store_result'                   => true,
                            'entry_note'                     => [ 'template' => 'Result: {{classification}}' ],
                            'mark_as_spam'                   => true,
                            'suppress_notifications_on_spam' => true,
                        ],
                    ],
                ],
            ],
            false
        );
        $result = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'unsupported-effects' ]
            );

        $outcomes = $result->get_native_effect_outcomes( 'unsupported_effects' );
        $this->assertSame(
            [ 'entry_note', 'mark_as_spam', 'store_result', 'suppress_notifications' ],
            array_column( $outcomes, 'effect' )
        );
        $this->assertSame( [ 'unsupported' ], array_values( array_unique( array_column( $outcomes, 'status' ) ) ) );
        $reasons = array_values( array_unique( array_column( $outcomes, 'reason' ) ) );
        sort( $reasons );
        $this->assertSame(
            [ 'native_entry_write_unavailable', 'native_notes_unavailable', 'native_spam_unavailable', 'notification_controls_unavailable' ],
            $reasons
        );

        $logs = get_option( 'sentient_forms_action_log', [] );
        $this->assertCount( 1, $logs );
        $this->assertSame( $outcomes, $logs[0]['details']['native_effect_outcomes'] ?? null );

        $event = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )
            ->get_by_request_id( (string) ( $logs[0]['execution_request_id'] ?? '' ) );
        $this->assertIsArray( $event );
        $this->assertSame( $outcomes, $event['result_json']['native_effect_outcomes'] ?? null );
    }

    public function test_synchronous_non_gravity_action_receives_distinct_mapping_execution_context(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $calls     = [];
        $action_id = 'fixture_context_action';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static function ( array $form_data, array $settings ) use ( &$calls ): array {
                    $calls[] = [
                        'hook'              => $form_data['hook'] ?? null,
                        'form_source'       => $form_data['form_source'] ?? null,
                        'execution_context' => $form_data['execution_context'] ?? null,
                        'marker'            => $settings['settings']['marker'] ?? null,
                    ];

                    return [ 'classification' => 'ham' ];
                }
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'fixture_first'  => [
                    'local_mapping_id'           => 'fixture_first',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Fixture first',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false, 'marker' => 'first' ],
                ],
                'fixture_second' => [
                    'local_mapping_id'           => 'fixture_second',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Fixture second',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false, 'marker' => 'second' ],
                ],
            ],
            false
        );

        $runner          = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $runner->run_accepted_submission(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            [ 'native' => 'payload' ]
        );

        $this->assertCount( 2, $calls );
        $this->assertSame( [ 'first', 'second' ], array_column( $calls, 'marker' ) );
        $this->assertSame( [ 'fixture_forms_submission_accepted', 'fixture_forms_submission_accepted' ], array_column( $calls, 'hook' ) );
        $this->assertSame( [ 'fixture_forms', 'fixture_forms' ], array_column( $calls, 'form_source' ) );
        $this->assertSame( [ 'fixture_first', 'fixture_second' ], array_column( array_column( $calls, 'execution_context' ), 'mapping_id' ) );
        $this->assertSame( [ $submission_uuid, $submission_uuid ], array_column( array_column( $calls, 'execution_context' ), 'submission_uuid' ) );
        $request_ids = array_column( array_column( $calls, 'execution_context' ), 'execution_request_id' );
        $this->assertCount( 2, array_unique( $request_ids ) );
        $this->assertNotEmpty( $request_ids[0] );
        $this->assertNotEmpty( $request_ids[1] );
    }

    public function test_concrete_synchronous_action_forwards_distinct_mapping_context_to_executor(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $executor = new Sentient_Forms_Test_Context_Action_Executor( Sentient_Forms_Plugin::instance() );
        $this->set_action_executor( $executor );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'concrete_first'  => [
                    'local_mapping_id'           => 'concrete_first',
                    'central_action_id'          => 'entry_evaluation',
                    'action_name_label'          => 'Concrete first',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false, 'marker' => 'first' ],
                ],
                'concrete_second' => [
                    'local_mapping_id'           => 'concrete_second',
                    'central_action_id'          => 'entry_evaluation',
                    'action_name_label'          => 'Concrete second',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false, 'marker' => 'second' ],
                ],
            ],
            false
        );

        $runner          = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $runner->run_accepted_submission(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            [ 'native' => 'payload' ]
        );
        $contexts = array_column( $executor->calls, 'context' );

        $this->assertCount( 2, $contexts );
        $this->assertSame( [ 'fixture_forms_submission_accepted', 'fixture_forms_submission_accepted' ], array_column( $contexts, 'hook' ) );
        $this->assertSame( [ 'fixture_forms', 'fixture_forms' ], array_column( $contexts, 'form_source' ) );
        $this->assertSame( [ 'concrete_first', 'concrete_second' ], array_column( $contexts, 'mapping_id' ) );
        $this->assertSame( [ $submission_uuid, $submission_uuid ], array_column( $contexts, 'submission_uuid' ) );
        $this->assertSame( [ 'first', 'second' ], array_column( array_column( $contexts, 'settings' ), 'marker' ) );
        $request_ids = array_column( $contexts, 'execution_request_id' );
        $this->assertCount( 2, array_unique( $request_ids ) );
        $this->assertNotEmpty( $request_ids[0] );
        $this->assertNotEmpty( $request_ids[1] );
    }

    public function test_synchronous_accepted_execution_replays_active_and_success_without_repeating_effects(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $calls     = 0;
        $reentered = false;
        $action_id = 'fixture_durable_success';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static function () use ( &$calls, &$reentered ): array {
                    ++$calls;
                    if ( ! $reentered )
                    {
                        $reentered = true;
                        ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
                            ->run_accepted_submission(
                                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                                [ 'native' => 'active-replay' ]
                            );
                    }

                    return [ 'classification' => 'ham' ];
                }
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'durable_success' => [
                    'local_mapping_id'           => 'durable_success',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Durable success',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false ],
                ],
            ],
            false
        );

        $first_uuid = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'first' ]
            );
        $replay_uuid = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'success-replay' ]
            );

        $this->assertSame( $first_uuid, $replay_uuid );
        $this->assertSame( 1, $calls );
        $this->assertCount( 1, get_option( 'sentient_forms_action_log', [] ) );
        $events = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->list_for_submission_uuid( (string) $first_uuid );
        $this->assertCount( 1, $events );
        $this->assertSame( 'succeeded', $events[0]['status'] ?? null );
    }

    public function test_synchronous_success_replay_preserves_stored_effect_outcomes_over_current_preflight(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $action_id = 'fixture_stable_effect_replay';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static fn (): array => [ 'classification' => 'ham' ]
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'stable_effect_replay' => [
                    'local_mapping_id'           => 'stable_effect_replay',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Stable effect replay',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'async'               => false,
                        'effect_mapping_json' => [
                            'entry_note' => [ 'template' => 'Result: {{classification}}' ],
                        ],
                    ],
                ],
            ],
            false
        );

        $runner = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $first  = $runner->run_accepted_submission_with_outcome(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            [ 'native' => 'first' ]
        );
        $events = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )
            ->list_for_submission_uuid( $first->get_submission_uuid() );
        $this->assertCount( 1, $events );

        $wpdb->update(
            $wpdb->prefix . 'sentient_execution_events',
            [
                'result_json' => wp_json_encode(
                    [
                        'native_effect_outcomes' => [
                            [
                                'effect' => 'entry_note',
                                'status' => 'applied',
                                'reason' => 'historical_native_application',
                            ],
                        ],
                    ]
                ),
            ],
            [ 'execution_request_id' => $events[0]['execution_request_id'] ],
            [ '%s' ],
            [ '%s' ]
        );

        $replay = $runner->run_accepted_submission_with_outcome(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            [ 'native' => 'replay' ]
        );

        $this->assertSame( 'replayed_success', $replay->get_mapping_outcomes()['stable_effect_replay'] ?? null );
        $this->assertSame(
            [
                [
                    'effect' => 'entry_note',
                    'status' => 'applied',
                    'reason' => 'historical_native_application',
                ],
            ],
            $replay->get_native_effect_outcomes( 'stable_effect_replay' )
        );
    }

    public function test_synchronous_accepted_execution_rejects_changed_settings_for_the_same_identity(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $calls     = 0;
        $action_id = 'fixture_digest_conflict';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static function () use ( &$calls ): array {
                    ++$calls;

                    return [ 'classification' => 'ham' ];
                }
            )
        );
        $mapping = [
            'local_mapping_id'           => 'digest_conflict',
            'central_action_id'          => $action_id,
            'action_name_label'          => 'Digest conflict fixture',
            'action_type_indicator'      => 'custom',
            'is_action_enabled_for_form' => true,
            'trigger_hooks'              => [ 'after_submission' ],
            'settings'                   => [ 'async' => false, 'marker' => 'first' ],
        ];
        update_option( 'sentient_forms_actions_fixture_forms_99', [ 'digest_conflict' => $mapping ], false );

        $runner = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $first  = $runner->run_accepted_submission_with_outcome( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );

        $mapping['settings']['marker'] = 'changed';
        update_option( 'sentient_forms_actions_fixture_forms_99', [ 'digest_conflict' => $mapping ], false );
        $conflict = $runner->run_accepted_submission_with_outcome( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );

        $this->assertSame( $first->get_submission_uuid(), $conflict->get_submission_uuid() );
        $this->assertSame( 1, $calls );
        $this->assertSame( 'digest_conflict', $conflict->get_mapping_outcomes()['digest_conflict'] ?? null );
        $error = $conflict->get_execution_result( 'digest_conflict' );
        $this->assertWPError( $error );
        $this->assertSame( 'sentient_forms_execution_digest_conflict', $error->get_error_code() );
    }

    public function test_synchronous_accepted_failure_is_terminal_without_explicit_safe_retry(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $calls     = 0;
        $action_id = 'fixture_durable_failure';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static function () use ( &$calls ): WP_Error {
                    ++$calls;

                    return new WP_Error( 'fixture_provider_failed', 'Provider failure details.' );
                }
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'durable_failure' => [
                    'local_mapping_id'           => 'durable_failure',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Durable failure',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false ],
                ],
            ],
            false
        );

        $first_uuid = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
        $replay_uuid = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );

        $this->assertSame( $first_uuid, $replay_uuid );
        $this->assertSame( 1, $calls );
        $this->assertCount( 1, get_option( 'sentient_forms_action_log', [] ) );
        $events = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->list_for_submission_uuid( (string) $first_uuid );
        $this->assertCount( 1, $events );
        $this->assertSame( 'failed', $events[0]['status'] ?? null );
    }

    public function test_synchronous_accepted_failure_retries_only_when_explicitly_safe(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $calls     = 0;
        $action_id = 'fixture_safe_retry';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static function () use ( &$calls ): array | WP_Error {
                    ++$calls;

                    return 1 === $calls
                        ? new WP_Error( 'fixture_retryable_failure', 'Retryable provider failure.' )
                        : [ 'classification' => 'ham' ];
                }
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'safe_retry' => [
                    'local_mapping_id'           => 'safe_retry',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Safe retry',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'async'                   => false,
                        'synchronous_retry_safe' => true,
                    ],
                ],
            ],
            false
        );

        $first_uuid = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
        $retry_uuid = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );

        $this->assertSame( $first_uuid, $retry_uuid );
        $this->assertSame( 2, $calls );
        $this->assertCount( 2, get_option( 'sentient_forms_action_log', [] ) );
        $events = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->list_for_submission_uuid( (string) $first_uuid );
        $this->assertCount( 1, $events );
        $this->assertSame( 'succeeded', $events[0]['status'] ?? null );
    }

    public function test_synchronous_action_log_never_persists_raw_provider_model_or_submission_content(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $action_id = 'fixture_safe_action_log';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static fn (): array => [
                    'classification'    => 'ham',
                    'content'           => 'RAW_PROVIDER_SECRET_92A',
                    'llm_output'        => 'RAW_MODEL_OUTPUT_17B',
                    'model'             => 'private-model-route-44C',
                    'submitted_content' => 'PRIVATE_SUBMISSION_63D',
                    'result_data'       => [ 'llm_output' => 'NESTED_RAW_OUTPUT_81E' ],
                ]
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'safe_log' => [
                    'local_mapping_id'           => 'safe_log',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Safe log',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false ],
                ],
            ],
            false
        );

        ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
        $logs    = get_option( 'sentient_forms_action_log', [] );
        $encoded = wp_json_encode( $logs );

        $this->assertCount( 1, $logs );
        $this->assertSame( 'success', $logs[0]['status'] ?? null );
        $this->assertSame( 'ham', $logs[0]['classification'] ?? null );
        foreach ( [ 'RAW_PROVIDER_SECRET_92A', 'RAW_MODEL_OUTPUT_17B', 'private-model-route-44C', 'PRIVATE_SUBMISSION_63D', 'NESTED_RAW_OUTPUT_81E' ] as $secret )
        {
            $this->assertStringNotContainsString( $secret, (string) $encoded );
        }
        $this->assertLessThanOrEqual( 20, str_word_count( (string) ( $logs[0]['result_summary'] ?? '' ) ) );
    }

    public function test_synchronous_action_log_preserves_safe_error_code_without_raw_error_message(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $action_id = 'fixture_safe_error_log';
        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Context_Tracking_Action(
                $action_id,
                static fn (): WP_Error => new WP_Error(
                    'provider_HTTP_error!',
                    'RAW_PROVIDER_ERROR_71D included PRIVATE_SUBMISSION_82F and RAW_MODEL_OUTPUT_93G.'
                )
            )
        );
        update_option(
            'sentient_forms_actions_fixture_forms_99',
            [
                'safe_error_log' => [
                    'local_mapping_id'           => 'safe_error_log',
                    'central_action_id'          => $action_id,
                    'action_name_label'          => 'Safe error log',
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [ 'async' => false ],
                ],
            ],
            false
        );

        ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() ) )
            ->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
        $logs    = get_option( 'sentient_forms_action_log', [] );
        $encoded = wp_json_encode( $logs );

        $this->assertCount( 1, $logs );
        $this->assertSame( 'error', $logs[0]['status'] ?? null );
        $this->assertSame( 'provider_http_error', $logs[0]['error_code'] ?? null );
        $this->assertSame( 'Synchronous accepted action failed.', $logs[0]['error_message'] ?? null );
        foreach ( [ 'RAW_PROVIDER_ERROR_71D', 'PRIVATE_SUBMISSION_82F', 'RAW_MODEL_OUTPUT_93G' ] as $secret )
        {
            $this->assertStringNotContainsString( $secret, (string) $encoded );
        }
    }

    public function test_form_source_workflow_runner_remains_lifecycle_neutral(): void
    {
        $services_dir = dirname( __DIR__, 2 ) . '/includes/services/';

        $this->assertTrue( class_exists( 'Sentient_Forms_Form_Source_Workflow_Runner' ) );
        $this->assertFalse( class_exists( 'Sentient_Forms_Accepted_Submission_Workflow_Runner' ) );
        $this->assertFalse( class_exists( 'Sentient_Forms_Validation_Workflow_Runner' ) );
        $this->assertFileDoesNotExist( $services_dir . 'class-sentient-forms-accepted-submission-workflow-runner.php' );
        $this->assertFileDoesNotExist( $services_dir . 'class-sentient-forms-validation-workflow-runner.php' );
    }

    public function test_active_contact_form_7_forms_are_discoverable(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_forms',
            static fn(): array => [
                new class {
                    public function id(): int
                    {
                        return 42;
                    }

                    public function title(): string
                    {
                        return 'Contact Form 7 Sales Inquiry';
                    }
                },
            ]
        );

        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $forms   = $adapter->get_forms();

        $this->assertCount( 1, $forms );
        $this->assertSame( 42, $forms[0]['id'] );
        $this->assertSame( 'Contact Form 7 Sales Inquiry', $forms[0]['title'] );
        $this->assertSame( 'contact_form_7', $forms[0]['adapter'] );
        $this->assertSame( 'Contact Form 7', $forms[0]['adapter_name'] );
        $this->assertTrue( $forms[0]['provider_is_active'] );
        $this->assertStringContainsString( 'page=wpcf7', $forms[0]['provider_edit_url'] );
        $this->assertStringContainsString( 'post=42', $forms[0]['provider_edit_url'] );
    }

    public function test_contact_form_7_field_manifest_marks_logical_hidden_and_file_fields(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_form_object',
            fn( $form, $form_id ) => 42 === absint( $form_id )
                ? new class( [
                    $this->cf7_tag( 'text*', 'text', 'your-name' ),
                    $this->cf7_tag( 'email*', 'email', 'your-email' ),
                    $this->cf7_tag( 'textarea', 'textarea', 'message' ),
                    $this->cf7_tag( 'hidden', 'hidden', 'tracking-code' ),
                    $this->cf7_tag( 'file', 'file', 'resume' ),
                    $this->cf7_tag( 'submit', 'submit', '' ),
                ] ) {
                    public function __construct( private array $tags )
                    {
                    }

                    public function scan_form_tags(): array
                    {
                        return $this->tags;
                    }
                }
                : $form,
            10,
            2
        );

        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( 42 );

        $this->assertCount( 5, $fields );
        $this->assertSame( 'your-name', $fields[0]['id'] );
        $this->assertSame( 'Your Name', $fields[0]['label'] );
        $this->assertSame( 'text', $fields[0]['type'] );
        $this->assertSame( 'visible', $fields[0]['visibility'] );
        $this->assertTrue( $fields[0]['storage_eligible'] );
        $this->assertFalse( $fields[0]['file_reference_eligible'] );

        $hidden = $fields[3];
        $this->assertSame( 'tracking-code', $hidden['id'] );
        $this->assertSame( 'hidden', $hidden['visibility'] );
        $this->assertFalse( $hidden['storage_eligible'] );

        $file = $fields[4];
        $this->assertSame( 'resume', $file['id'] );
        $this->assertSame( 'file', $file['type'] );
        $this->assertFalse( $file['storage_eligible'] );
        $this->assertTrue( $file['file_reference_eligible'] );
    }

    public function test_contact_form_7_form_data_returns_async_safe_snapshot(): void
    {
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_form_object',
            fn( $form, $form_id ) => 46 === absint( $form_id )
                ? $this->cf7_form(
                    46,
                    'CF7 Async Snapshot',
                    [
                        $this->cf7_tag( 'text*', 'text', 'your-name' ),
                        $this->cf7_tag( 'textarea', 'textarea', 'message' ),
                    ]
                )
                : $form,
            10,
            2
        );

        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $form    = $adapter->get_form_data( 46 );

        $this->assertSame( '46', $form['id'] ?? null );
        $this->assertSame( 'CF7 Async Snapshot', $form['title'] ?? null );
        $this->assertSame( 'contact_form_7', $form['form_source'] ?? null );
        $this->assertSame( 'your-name', $form['fields'][0]['id'] ?? null );
        $this->assertSame( 'Message', $form['fields'][1]['label'] ?? null );
    }

    public function test_mail_sent_does_not_store_logical_fields_when_ledger_is_disabled(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_current_submission',
            static fn() => new class {
                public function get_posted_data(): array
                {
                    return [
                        'your-name'     => 'Ada Lovelace',
                        'your-email'    => 'ada@example.test',
                        '_wpcf7'        => '42',
                        '_wpcf7_nonce'  => 'do-not-store',
                        'captcha_token' => 'do-not-store',
                    ];
                }
            }
        );

        $adapter      = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $contact_form = $this->cf7_form( 42, 'CF7 Ledger Gate' );
        $result       = $adapter->handle_mail_sent( $contact_form );
        $ledger       = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );

        $this->assertNull( $result );
        $this->assertSame( [], $ledger->list_for_form( 'contact_form_7', '42' ) );
    }

    public function test_mail_sent_stores_redacted_logical_fields_and_file_references_when_ledger_is_enabled(): void
    {
        global $wpdb;

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger   = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $settings->set_enabled( 'contact_form_7', '43', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_current_submission',
            static fn() => new class {
                public function get_posted_data(): array
                {
                    return [
                        'your-name'     => 'Grace Hopper',
                        'your-email'    => 'grace@example.test',
                        'message'       => 'Please send details.',
                        '_wpcf7'        => '43',
                        '_wpcf7_nonce'  => 'do-not-store',
                        'captcha_token' => 'do-not-store',
                    ];
                }

                public function uploaded_files(): array
                {
                    return [
                        'resume' => [ 'C:\\private\\resume.pdf' ],
                    ];
                }
            }
        );

        $adapter         = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_mail_sent( $this->cf7_form( 43, 'CF7 Ledger Capture' ) );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'contact_form_7', $stored['form_source'] ?? null );
        $this->assertSame( '43', $stored['form_id'] ?? null );
        $this->assertNull( $stored['native_entry_id'] ?? null );
        $this->assertNull( $stored['native_entry_url'] ?? null );
        $this->assertSame( 'Grace Hopper', $stored['logical_fields_json']['your-name'] ?? null );
        $this->assertSame( 'grace@example.test', $stored['logical_fields_json']['your-email'] ?? null );
        $this->assertArrayNotHasKey( '_wpcf7', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( '_wpcf7_nonce', $stored['logical_fields_json'] ?? [] );
        $this->assertSame( '[redacted]', $stored['logical_fields_json']['captcha_token'] ?? null );
        $this->assertSame( 'resume', $stored['file_refs_json'][0]['field_id'] ?? null );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertArrayNotHasKey( 'contents', $stored['file_refs_json'][0] ?? [] );
        $this->assertContains( 'captcha_token', $stored['redaction_summary_json']['redacted_fields'] ?? [] );
    }

    public function test_mail_sent_schedules_after_submission_action_only_after_ledger_capture(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'contact_form_7', '44', false );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        update_option(
            'sentient_forms_actions_contact_form_7_44',
            [
                'map_summary' => [
                    'local_mapping_id'           => 'map_summary',
                    'central_action_id'          => 'entry_evaluation',
                    'action_name_label'          => 'Summarize CF7 submission',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'async' => true,
                    ],
                ],
            ],
            false
        );

        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_current_submission',
            static fn() => new class {
                public function get_posted_data(): array
                {
                    return [
                        'your-name'     => 'Katherine Johnson',
                        'your-email'    => 'katherine@example.test',
                        'message'       => 'Please summarize this.',
                        'your-topic'    => [ 'Support', 'Sales' ],
                        'captcha_token' => 'do-not-send',
                        'id'            => 'shadow-id',
                        'submission_uuid' => '00000000-0000-4000-8000-000000000000',
                        'form_source'   => 'gravity_forms',
                        'form_id'       => '999',
                        '_wpcf7'        => '44',
                    ];
                }

                public function uploaded_files(): array
                {
                    return [
                        'brief' => [ 'C:\\private\\brief.pdf' ],
                    ];
                }
            }
        );

        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );

        $this->assertNull( $adapter->handle_mail_sent( $this->cf7_form( 44, 'CF7 Execution Gate' ) ) );
        $this->assertSame( [], $scheduled_jobs );

        $ledger_settings->set_enabled( 'contact_form_7', '44', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $adapter->init();

        do_action( 'wpcf7_before_send_mail', $this->cf7_form( 44, 'CF7 Execution Gate' ) );
        $this->assertSame( [], $scheduled_jobs );

        do_action( 'wpcf7_mail_sent', $this->cf7_form( 44, 'CF7 Execution Gate' ) );
        $submission_uuid = $scheduled_jobs[0]['args']['context']['submission_uuid'] ?? null;

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );

        $this->assertSame( 'sentient_forms_process_action', $scheduled_jobs[0]['hook'] ?? null );
        $this->assertSame( 'sentient_forms_async', $scheduled_jobs[0]['group'] ?? null );

        $job_context = $scheduled_jobs[0]['args']['context'] ?? [];
        $this->assertSame( 'contact_form_7', $job_context['form_source'] ?? null );
        $this->assertSame( 'wpcf7_mail_sent', $job_context['hook'] ?? null );
        $this->assertSame( '44', $job_context['form_id'] ?? null );
        $this->assertNull( $job_context['entry_id'] ?? null );
        $this->assertSame( 'map_summary', $job_context['local_mapping_id'] ?? null );
        $this->assertSame( 'entry_evaluation', $job_context['central_action_id'] ?? null );
        $this->assertSame( $submission_uuid, $job_context['submission_uuid'] ?? null );

        $job_entry = $scheduled_jobs[0]['args']['data']['entry'] ?? [];
        $this->assertSame( 'contact_form_7', $scheduled_jobs[0]['args']['data']['form_source'] ?? null );
        $this->assertNull( $job_entry['id'] ?? null );
        $this->assertSame( $submission_uuid, $job_entry['submission_uuid'] ?? null );
        $this->assertSame( 'contact_form_7', $job_entry['form_source'] ?? null );
        $this->assertSame( '44', $job_entry['form_id'] ?? null );
        $this->assertSame( 'Katherine Johnson', $job_entry['your-name'] ?? null );
        $this->assertSame( [ 'Support', 'Sales' ], $job_entry['your-topic'] ?? null );
        $this->assertSame( 'brief', $job_entry['file_refs'][0]['field_id'] ?? null );
        $this->assertSame( 'brief.pdf', $job_entry['file_refs'][0]['filename'] ?? null );
        $this->assertSame( '[redacted]', $job_entry['captcha_token'] ?? null );
        $this->assertArrayNotHasKey( '_wpcf7', $job_entry );
    }

    public function test_mail_sent_passes_dependency_metadata_to_cf7_async_jobs(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'contact_form_7', '47', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        update_option(
            'sentient_forms_actions_contact_form_7_47',
            [
                'map_first' => [
                    'local_mapping_id'           => 'map_first',
                    'central_action_id'          => 'entry_evaluation',
                    'action_name_label'          => 'First CF7 async action',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'async' => true,
                    ],
                ],
                'map_second' => [
                    'local_mapping_id'           => 'map_second',
                    'central_action_id'          => 'entry_evaluation',
                    'action_name_label'          => 'Dependent CF7 async action',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'async'           => true,
                        'trigger_sources' => [
                            'after_submission' => [
                                'type'       => 'mapping',
                                'mapping_id' => 'map_first',
                            ],
                        ],
                        'batch_settings'   => [
                            'max_wait_seconds' => 45,
                        ],
                    ],
                ],
            ],
            false
        );

        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_current_submission',
            static fn() => new class {
                public function get_posted_data(): array
                {
                    return [
                        'your-name'  => 'Mary Jackson',
                        'your-email' => 'mary@example.test',
                        'message'    => 'Queue dependent async actions.',
                        '_wpcf7'     => '47',
                    ];
                }
            }
        );

        $adapter         = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_mail_sent( $this->cf7_form( 47, 'CF7 Dependency Metadata' ) );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 2, $scheduled_jobs );

        $contexts = [];
        foreach ( $scheduled_jobs as $job )
        {
            $context = $job['args']['context'] ?? [];
            if ( is_array( $context ) && isset( $context['local_mapping_id'] ) )
            {
                $contexts[ $context['local_mapping_id'] ] = $context;
            }
        }

        $this->assertArrayHasKey( 'map_first', $contexts );
        $this->assertArrayHasKey( 'map_second', $contexts );

        $this->assertSame( [ 'map_first' ], $contexts['map_second']['dependency_mapping_ids'] ?? null );
        $this->assertSame( 'queued', $contexts['map_second']['dependency_initial_outcomes']['map_first'] ?? null );
        $this->assertSame(
            $contexts['map_first']['execution_request_id'] ?? null,
            $contexts['map_second']['dependency_execution_request_ids']['map_first'] ?? null
        );
        $this->assertSame( 45, $contexts['map_second']['dependency_wait_max_seconds'] ?? null );
        $this->assertSame( 10, $contexts['map_second']['dependency_wait_poll_seconds'] ?? null );
    }

    public function test_mail_sent_schedules_local_first_mapping_with_submission_uuid_without_native_entry_id(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'contact_form_7', '45', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'cf7_local_summary',
                'display_name'         => 'CF7 Local Summary',
                'definition_json'      => [
                    'prompt' => 'Summarize {{entry}}.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'contact_form_7',
                'form_id'             => '45',
                'hook'                => 'wpcf7_mail_sent',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'summary_source' => 'your-message',
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_current_submission',
            static fn() => new class {
                public function get_posted_data(): array
                {
                    return [
                        'your-name'  => 'Dorothy Vaughan',
                        'your-email' => 'dorothy@example.test',
                        'message'    => 'Queue this local-first CF7 summary.',
                        'id'         => 'shadow-id',
                        'submission_uuid' => '00000000-0000-4000-8000-000000000000',
                        'form_source' => 'gravity_forms',
                        'form_id'    => '999',
                        '_wpcf7'     => '45',
                    ];
                }
            }
        );

        $adapter         = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_mail_sent( $this->cf7_form( 45, 'CF7 Local First Execution' ) );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_local_mapping', $scheduled_jobs[0]['hook'] ?? null );
        $this->assertSame( 'sentient_forms_async', $scheduled_jobs[0]['group'] ?? null );

        $payload = $scheduled_jobs[0]['args'][0] ?? [];
        $this->assertSame( $mapping_id, $payload['local_mapping_id'] ?? null );
        $this->assertSame( 'contact_form_7', $payload['form_source'] ?? null );
        $this->assertSame( '45', $payload['form_id'] ?? null );
        $this->assertNull( $payload['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $payload['submission_uuid'] ?? null );

        $resolved_entry = $adapter->get_entry_data( $submission_uuid, '45' );
        $this->assertIsArray( $resolved_entry );
        $this->assertNull( $resolved_entry['id'] ?? null );
        $this->assertSame( $submission_uuid, $resolved_entry['submission_uuid'] ?? null );
        $this->assertSame( 'contact_form_7', $resolved_entry['form_source'] ?? null );
        $this->assertSame( '45', $resolved_entry['form_id'] ?? null );
        $this->assertSame( 'Dorothy Vaughan', $resolved_entry['your-name'] ?? null );
        $this->assertNull( $adapter->get_entry_data( $submission_uuid, '999' ) );

        $event = $events->list_recent( 1 )[0] ?? null;
        $this->assertIsArray( $event );
        $this->assertSame( 'queued', $event['status'] ?? null );
        $this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
        $this->assertSame( 'contact_form_7', $event['form_source'] ?? null );
        $this->assertSame( '45', $event['form_id'] ?? null );
        $this->assertNull( $event['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
    }

    public function test_mail_sent_passes_dependency_metadata_to_cf7_local_first_jobs(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'contact_form_7', '48', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'cf7_local_dependency_summary',
                'display_name'         => 'CF7 Local Dependency Summary',
                'definition_json'      => [
                    'prompt' => 'Summarize {{entry}} after dependency.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'contact_form_7',
                'form_id'             => '48',
                'hook'                => 'wpcf7_mail_sent',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'summary_source' => 'message',
                ],
                'settings_json'       => [
                    'trigger_sources' => [
                        'after_submission' => [
                            'type'       => 'mapping',
                            'mapping_id' => 'map_first',
                        ],
                    ],
                    'batch_settings'   => [
                        'max_wait_seconds' => 70,
                    ],
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $local_mapping_key = 'local_first_' . $mapping_id;
        $scheduled_jobs    = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        update_option(
            'sentient_forms_actions_contact_form_7_48',
            [
                'map_first' => [
                    'local_mapping_id'           => 'map_first',
                    'central_action_id'          => 'entry_evaluation',
                    'action_name_label'          => 'First CF7 async action',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'after_submission' ],
                    'settings'                   => [
                        'async' => true,
                    ],
                ],
            ],
            false
        );

        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter(
            'sentient_forms_contact_form_7_current_submission',
            static fn() => new class {
                public function get_posted_data(): array
                {
                    return [
                        'your-name'  => 'Christine Darden',
                        'your-email' => 'christine@example.test',
                        'message'    => 'Queue a dependent local-first action.',
                        '_wpcf7'     => '48',
                    ];
                }
            }
        );

        $adapter         = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_mail_sent( $this->cf7_form( 48, 'CF7 Local Dependency Metadata' ) );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 2, $scheduled_jobs );

        $contexts = [];
        foreach ( $scheduled_jobs as $job )
        {
            if ( 'sentient_forms_process_action' === ( $job['hook'] ?? null ) )
            {
                $context = $job['args']['context'] ?? [];
            }
            else
            {
                $payload = $job['args'][0] ?? [];
                $context = is_array( $payload ) ? ( $payload['context'] ?? [] ) : [];
            }

            if ( is_array( $context ) && isset( $context['local_mapping_id'] ) )
            {
                $contexts[ $context['local_mapping_id'] ] = $context;
            }
        }

        $this->assertArrayHasKey( 'map_first', $contexts );
        $this->assertArrayHasKey( $local_mapping_key, $contexts );

        $this->assertSame( [ 'map_first' ], $contexts[ $local_mapping_key ]['dependency_mapping_ids'] ?? null );
        $this->assertSame( 'queued', $contexts[ $local_mapping_key ]['dependency_initial_outcomes']['map_first'] ?? null );
        $this->assertSame(
            $contexts['map_first']['execution_request_id'] ?? null,
            $contexts[ $local_mapping_key ]['dependency_execution_request_ids']['map_first'] ?? null
        );
        $this->assertSame( 70, $contexts[ $local_mapping_key ]['dependency_wait_max_seconds'] ?? null );
        $this->assertSame( 10, $contexts[ $local_mapping_key ]['dependency_wait_poll_seconds'] ?? null );
    }

    private function cf7_tag( string $type, string $basetype, string $name ): object
    {
        return new class( $type, $basetype, $name ) {
            public function __construct(
                public string $type,
                public string $basetype,
                public string $name
            )
            {
            }

            public function is_required(): bool
            {
                return str_ends_with( $this->type, '*' );
            }
        };
    }

    /**
     * @param array<int, object> $tags
     */
    private function cf7_form( int $id, string $title, array $tags = [] ): object
    {
        return new class( $id, $title, $tags ) {
            /**
             * @param array<int, object> $tags
             */
            public function __construct( private int $id, private string $title, private array $tags )
            {
            }

            public function id(): int
            {
                return $this->id;
            }

            public function title(): string
            {
                return $this->title;
            }

            public function scan_form_tags(): array
            {
                return $this->tags;
            }
        };
    }
}
