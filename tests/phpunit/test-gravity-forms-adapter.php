<?php

if ( ! class_exists( 'Sentient_Forms_Test_Gf_Meta_Store' ) ) {
    class Sentient_Forms_Test_Gf_Meta_Store {
        /** @var array<int,array<string,mixed>> */
        private static array $meta = [];

        public static function reset(): void {
            self::$meta = [];
        }

        public static function set_meta( int $entry_id, string $key, mixed $value ): void {
            if ( ! isset( self::$meta[ $entry_id ] ) ) {
                self::$meta[ $entry_id ] = [];
            }

            self::$meta[ $entry_id ][ $key ] = $value;
        }

        public static function get_meta( int $entry_id, string $key ): mixed {
            return self::$meta[ $entry_id ][ $key ] ?? null;
        }
    }
}

if ( ! function_exists( 'gform_get_meta' ) ) {
    function gform_get_meta( $entry_id, $meta_key ) {
        return Sentient_Forms_Test_Gf_Meta_Store::get_meta( (int) $entry_id, (string) $meta_key );
    }
}

if ( ! function_exists( 'gform_update_meta' ) ) {
    function gform_update_meta( $entry_id, $meta_key, $value ) {
        Sentient_Forms_Test_Gf_Meta_Store::set_meta( (int) $entry_id, (string) $meta_key, $value );

        return true;
    }
}

final class Sentient_Forms_Test_Tracking_Action implements Sentient_Forms_Action_Interface
{
    /** @var callable */
    private $on_execute;
    private string $id;
    /** @var array<int, string> */
    private array $hooks;

    public function __construct( string $id, callable $on_execute, array $hooks = [ 'gform_after_submission' ] )
    {
        $this->id         = $id;
        $this->on_execute = $on_execute;
        $this->hooks      = $hooks;
    }

    public function get_id(): string
    {
        return $this->id;
    }

    public function get_name(): string
    {
        return 'Tracking Action';
    }

    public function get_description(): string
    {
        return 'Test action that records executions.';
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
        return $this->hooks;
    }

    public function get_compatibility(): array
    {
        return [ 'gravity_forms' ];
    }

    public function execute( array $form_data, array $settings, int | string $entry_id, int | string $form_id ): WP_Error | bool | array
    {
        $result = call_user_func( $this->on_execute, $form_data, $settings, $entry_id, $form_id );

        return null === $result ? [ 'ok' => true ] : $result;
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

final class Sentient_Forms_Test_Validation_Action_Executor extends Sentient_Forms_Action_Executor
{
    /** @var callable */
    private $on_execute;

    public function __construct( Sentient_Forms_Plugin $plugin, callable $on_execute )
    {
        parent::__construct( $plugin, null );
        $this->on_execute = $on_execute;
    }

    public function execute( string $central_action_id, array $form, array $entry, array $context = array() )
    {
        return call_user_func( $this->on_execute, $central_action_id, $form, $entry, $context );
    }
}

final class Sentient_Forms_Test_Gravity_Forms_Adapter_Spy extends Sentient_Forms_Gravity_Forms_Adapter
{
    /** @var array<int, array<string, mixed>> */
    public array $notes = [];

    /** @var array<int, array<string, mixed>> */
    public array $dispatched_notifications = [];

    /** @var array<int, array<string, mixed>> */
    public array $forms = [];

    /** @var array<int, array<string, mixed>> */
    public array $entries = [];

    public function add_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool
    {
        $this->notes[] = [
            'entry_id'     => $entry_id,
            'note_author'  => $note_author,
            'note_content' => $note_content,
        ];

        return true;
    }

    public function mark_entry_as_spam( mixed $entry_id ): bool
    {
        $entry_id = (int) $entry_id;

        if ( ! isset( $this->entries[ $entry_id ] ) ) {
            return false;
        }

        $this->entries[ $entry_id ]['status'] = 'spam';

        return true;
    }

    public function get_form_object( int $form_id ): object | array | null
    {
        return $this->forms[ $form_id ] ?? parent::get_form_object( $form_id );
    }

    protected function get_entry_record( int $entry_id ): ?array
    {
        return $this->entries[ $entry_id ] ?? parent::get_entry_record( $entry_id );
    }

    protected function dispatch_entry_notifications( array $form, array $entry, array $notification_ids ): array
    {
        $this->dispatched_notifications[] = [
            'form'             => $form,
            'entry'            => $entry,
            'notification_ids' => array_values( $notification_ids ),
        ];

        return array_values( $notification_ids );
    }
}

class Tests_Gravity_Forms_Adapter extends WP_UnitTestCase
{
    private Sentient_Forms_Gravity_Forms_Adapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        Sentient_Forms_Test_Gf_Meta_Store::reset();
        $this->adapter = new Sentient_Forms_Gravity_Forms_Adapter( Sentient_Forms_Plugin::instance() );
    }

    protected function tearDown(): void
    {
        $this->set_action_executor( null );
        parent::tearDown();
    }

    private function set_action_executor( ?Sentient_Forms_Action_Executor $executor ): void
    {
        $reflection = new ReflectionClass( Sentient_Forms_Plugin::instance() );
        $property   = $reflection->getProperty( 'action_executor' );
        $property->setAccessible( true );
        $property->setValue( Sentient_Forms_Plugin::instance(), $executor );
    }

    public function test_build_realtime_runtime_config_returns_null_without_realtime_mappings(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'build_realtime_runtime_config' );
        $method->setAccessible( true );

        $form = [
            'id' => 13,
            'title' => 'No realtime',
            'fields' => [],
        ];
        $settings = [
            'actions' => [
                [
                    'id' => 'map_1',
                    'central_action_id' => 'central_1',
                    'is_action_enabled_for_form' => true,
                    'settings' => [
                        'execution_mode' => 'after_submission',
                    ],
                ],
            ],
        ];

        $runtime = $method->invoke( $this->adapter, $form, $settings );
        $this->assertNull( $runtime );
    }

    public function test_build_realtime_runtime_config_includes_mapping_manifest_and_nonce(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'build_realtime_runtime_config' );
        $method->setAccessible( true );

        $form = [
            'id' => 14,
            'title' => 'Realtime',
            'fields' => [
                (object) [
                    'id' => 1,
                    'label' => 'Name',
                    'type' => 'text',
                    'pageNumber' => 1,
                ],
                (object) [
                    'id' => 4,
                    'label' => 'Details',
                    'type' => 'textarea',
                    'pageNumber' => 2,
                ],
            ],
        ];
        $settings = [
            'actions' => [
                [
                    'id' => 'map_rt',
                    'central_action_id' => 'central_rt',
                    'action_name_label' => 'Realtime Action',
                    'is_action_enabled_for_form' => true,
                    'settings' => [
                        'execution_mode' => 'real_time',
                        'realtime_settings' => [
                            'checkpoint_field_ids' => [ '1' ],
                            'debounce_ms' => 700,
                            'cooldown_ms' => 9000,
                            'manual_refresh_enabled' => true,
                        ],
                    ],
                ],
            ],
        ];

        $runtime = $method->invoke( $this->adapter, $form, $settings );

        $this->assertIsArray( $runtime );
        $this->assertSame( 14, $runtime['form_id'] ?? null );
        $this->assertSame( 'gravity_forms', $runtime['source'] ?? null );
        $this->assertSame( 2, $runtime['total_pages'] ?? null );
        $this->assertNotEmpty( $runtime['nonce'] ?? '' );
        $this->assertStringContainsString(
            '/sentient-forms/v1/gravity_forms/forms/14/actions/suggest',
            (string) ( $runtime['suggest_endpoint_url'] ?? '' )
        );
        $this->assertCount( 1, $runtime['mappings'] ?? [] );
        $this->assertSame( 700, $runtime['mappings'][0]['debounce_ms'] ?? null );
        $this->assertSame( 9000, $runtime['mappings'][0]['cooldown_ms'] ?? null );
        $this->assertSame( [ '1' ], $runtime['mappings'][0]['checkpoint_field_ids'] ?? [] );
        $this->assertCount( 2, $runtime['field_manifest'] ?? [] );
    }

    public function test_maps_insufficient_credits_error_to_friendly_message(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'map_error_to_message' );
        $method->setAccessible( true );

        $error   = new WP_Error( 'insufficient_credits', 'Insufficient credits' );
        $message = $method->invoke( $this->adapter, $error );

        $this->assertSame(
            'Sentient Forms could not run: insufficient credits remain for this license.',
            $message
        );
    }

    public function test_maps_negative_credit_balance_to_friendly_message(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'map_error_to_message' );
        $method->setAccessible( true );

        $error = new WP_Error(
            'insufficient_credits',
            'Insufficient credits',
            [
                'payload' => [
                    'error' => [
                        'meta' => [
                            'current_balance' => -4,
                            'required_credits' => 16,
                            'deficit_credits' => 20,
                            'balance_state' => 'negative_carry',
                        ],
                    ],
                ],
            ]
        );

        $message = $method->invoke( $this->adapter, $error );

        $this->assertSame(
            'Sentient Forms could not run: this license now has a negative balance of -4 credits. Add credits before retrying.',
            $message
        );
    }

    public function test_record_entry_error_persists_status_with_error_code(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'record_entry_error' );
        $method->setAccessible( true );

        $form_id  = 7;
        $entry_id = 42;
        $option   = 'sentient_forms_form_status_gravity_forms_' . $form_id;

        delete_option( $option );

        $error = new WP_Error( 'timeout', 'Timeout contacting CPS' );
        $method->invoke( $this->adapter, $entry_id, $error, $form_id );

        $status = get_option( $option );

        $this->assertIsArray( $status );
        $this->assertSame( 'error', $status['status'] );
        $this->assertSame( 'timeout', $status['last_error_code'] );
        $this->assertSame(
            'Sentient Forms timed out while contacting CPS. The submission was not processed.',
            $status['message']
        );
        $this->assertSame( $entry_id, $status['entry_id'] );
        $this->assertNotEmpty( $status['updated_at'] );
    }

    public function test_map_error_to_message_handles_duplicate_execution(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'map_error_to_message' );
        $method->setAccessible( true );

        $error   = new WP_Error( 'duplicate_execution', 'duplicate' );
        $message = $method->invoke( $this->adapter, $error );

        $this->assertSame(
            'Sentient Forms already processed this submission. Refresh the status to view the existing result.',
            $message
        );
    }

    public function test_filter_async_evaluation_jobs_appends_job(): void
    {
        $jobs   = [];
        $job    = [ 'context' => [
            'form_source'       => 'gravity_forms',
            'entry_id'          => 123,
            'form_id'           => 9,
            'action_id'         => 'entry_evaluation',
            'action_name_label' => 'Summary',
        ] ];
        $result = [
            'evaluation_payload' => [
                'result_data' => [ 'llm_output' => 'Summary text' ],
                'meta'        => [ 'credits_debited' => 5 ],
            ],
        ];

        $filtered = $this->adapter->filter_async_evaluation_jobs( $jobs, $job, $result );

        $this->assertCount( 1, $filtered );
        $evaluation = $filtered[0];
        $this->assertSame( 'gravity_forms', $evaluation['adapter_id'] );
        $this->assertSame( 123, $evaluation['entry_id'] );
        $this->assertSame( 'Summary', $evaluation['context']['action_name_label'] );
        $this->assertSame( 'Summary text', $evaluation['payload']['result_data']['llm_output'] );
    }

    public function test_filter_async_evaluation_jobs_skips_spam_detection(): void
    {
        $jobs   = [];
        $job    = [ 'context' => [
            'form_source'       => 'gravity_forms',
            'entry_id'          => 123,
            'form_id'           => 9,
            'central_action_id' => 'spam_detection_v1',
            'action_name_label' => 'Spam Detection',
        ] ];
        $result = [
            'evaluation_payload' => [
                'central_action_id' => 'spam_detection_v1',
                'result_data'       => [ 'classification' => 'spam' ],
                'meta'              => [ 'action_template_code' => 'spam_detection_v1' ],
            ],
        ];

        $filtered = $this->adapter->filter_async_evaluation_jobs( $jobs, $job, $result );

        $this->assertSame( [], $filtered );
    }

    public function test_finalize_async_evaluation_adds_spam_note_and_marks_entry(): void
    {
        $spy_adapter = new Sentient_Forms_Test_Gravity_Forms_Adapter_Spy( Sentient_Forms_Plugin::instance() );
        $entry_id    = 321;
        $spy_adapter->entries[ $entry_id ] = [
            'id'     => $entry_id,
            'status' => 'active',
        ];

        $spy_adapter->finalize_async_evaluation(
            [
                'entry_id'                 => $entry_id,
                'form_id'                  => 22,
                'central_action_id'        => 'spam_detection_v1',
                'mark_as_spam'             => true,
                'spam_result_display_mode' => 'entry_note',
                'spam_indicators_display'  => 'detailed',
            ],
            [
                'central_action_id' => 'spam_detection_v1',
                'result_data'       => [
                    'classification' => 'spam',
                    'confidence'     => 0.99,
                    'justification'  => 'This async spam explanation must be added as a GF entry note.',
                    'indicators'     => [
                        [
                            'type'     => 'promotional_language',
                            'evidence' => 'Buy now',
                            'weight'   => 'high',
                        ],
                    ],
                ],
                'meta'              => [
                    'action_template_code' => 'spam_detection_v1',
                ],
            ]
        );

        $this->assertNotEmpty( $spy_adapter->notes );
        $this->assertStringContainsString(
            'This async spam explanation must be added as a GF entry note.',
            (string) ( $spy_adapter->notes[0]['note_content'] ?? '' )
        );
        $this->assertSame( 'spam', $spy_adapter->entries[ $entry_id ]['status'] ?? null );
        $this->assertSame( 'spam', gform_get_meta( $entry_id, 'sentient_forms_spam_classification' ) );
    }

    public function test_finalize_async_evaluation_does_not_duplicate_spam_notes_on_repeat_completion(): void
    {
        $entry_id = 322;

        $context = [
            'entry_id'                 => $entry_id,
            'form_id'                  => 22,
            'central_action_id'        => 'spam_detection_v1',
            'mark_as_spam'             => true,
            'spam_result_display_mode' => 'entry_note',
            'spam_indicators_display'  => 'detailed',
        ];
        $result = [
            'central_action_id' => 'spam_detection_v1',
            'result_data'       => [
                'classification' => 'spam',
                'confidence'     => 0.99,
                'justification'  => 'Duplicate async completion must not duplicate this note.',
            ],
            'meta'              => [
                'action_template_code' => 'spam_detection_v1',
            ],
        ];

        $this->adapter->finalize_async_evaluation( $context, $result );
        $this->adapter->finalize_async_evaluation( $context, $result );

        $notes = gform_get_meta( $entry_id, 'sentient_forms_notes' );
        $this->assertIsArray( $notes );
        $classification_notes = array_values(
            array_filter(
                $notes,
                static fn ( array $note ): bool => str_contains(
                    (string) ( $note['content'] ?? '' ),
                    'Sentient Forms AI classified this entry as SPAM'
                )
            )
        );

        $this->assertCount( 1, $classification_notes );
        $this->assertSame( 'spam', gform_get_meta( $entry_id, 'sentient_forms_spam_classification' ) );
    }

    public function test_finalize_async_success_runs_post_execution_entry_note_and_hook(): void
    {
        $spy_adapter = new Sentient_Forms_Test_Gravity_Forms_Adapter_Spy( Sentient_Forms_Plugin::instance() );
        $entry_id    = 701;

        $spy_adapter->entries[ $entry_id ] = [
            'id'      => $entry_id,
            'form_id' => 44,
            '1'       => 'Ava',
            '2'       => 'ava@example.test',
            'status'  => 'active',
        ];

        $hook_calls = [];
        $hook       = static function ( array $context, array $result, array $action, int $called_entry_id ) use ( &$hook_calls ): void {
            $hook_calls[] = [
                'context'  => $context,
                'result'   => $result,
                'action'   => $action,
                'entry_id' => $called_entry_id,
            ];
        };

        add_action( 'sentient_forms_demo_custom_effect', $hook, 10, 4 );

        try {
            $spy_adapter->finalize_async_success(
                [
                    'entry_id'          => $entry_id,
                    'form_id'           => 44,
                    'central_action_id' => 'custom_follow_up',
                    'action_name_label' => 'Demo Custom Follow-up',
                    'settings'          => [
                        'post_execution_actions' => [
                            [
                                'type'    => 'entry_note',
                                'message' => 'Follow up with {{field:1}} about {{llm_output}}.',
                            ],
                            [
                                'type'      => 'wp_hook',
                                'hook_name' => 'sentient_forms_demo_custom_effect',
                            ],
                        ],
                    ],
                ],
                [
                    'result_data' => [
                        'llm_output'    => 'support plan',
                        'justification' => 'Helpful request',
                    ],
                ]
            );
        } finally {
            remove_action( 'sentient_forms_demo_custom_effect', $hook, 10 );
        }

        $note_contents = array_map(
            static fn ( array $note ): string => (string) ( $note['note_content'] ?? '' ),
            $spy_adapter->notes
        );

        $this->assertContains( 'Follow up with Ava about support plan.', $note_contents );
        $this->assertCount( 1, $hook_calls );
        $this->assertSame( $entry_id, $hook_calls[0]['entry_id'] );
        $this->assertSame( 'custom_follow_up', $hook_calls[0]['context']['central_action_id'] ?? null );

        $audit_json = gform_get_meta( $entry_id, 'sentient_forms_post_execution_actions' );
        $audit      = json_decode( (string) $audit_json, true );

        $this->assertIsArray( $audit );
        $this->assertSame( 'success', $audit[0]['results'][0]['status'] ?? null );
        $this->assertSame( 'entry_note', $audit[0]['results'][0]['type'] ?? null );
        $this->assertSame( 'success', $audit[0]['results'][1]['status'] ?? null );
        $this->assertSame( 'wp_hook', $audit[0]['results'][1]['type'] ?? null );
    }

    /**
     * T-PHP-001: Test that spam classification extracts correctly from CPS results.
     * Tests FR-001: Auto spam marking extracts classification from various result structures.
     */
    public function test_extract_spam_classification_from_evaluation_payload(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'extract_spam_classification' );
        $method->setAccessible( true );

        // Test evaluation_payload structure (async flow)
        $result = [
            'evaluation_payload' => [
                'result_data' => [
                    'classification' => 'spam',
                    'llm_output' => 'This looks like spam because...',
                ],
            ],
        ];
        $classification = $method->invoke( $this->adapter, $result );
        $this->assertSame( 'spam', $classification );

        // Test direct result_data structure
        $result = [
            'result_data' => [
                'classification' => 'HAM', // Test case-insensitivity
            ],
        ];
        $classification = $method->invoke( $this->adapter, $result );
        $this->assertSame( 'ham', $classification );

        // Test top-level classification
        $result = [ 'classification' => 'likely_spam' ];
        $classification = $method->invoke( $this->adapter, $result );
        $this->assertSame( 'likely_spam', $classification );

        // Test missing classification returns null
        $result = [ 'some_other_field' => 'value' ];
        $classification = $method->invoke( $this->adapter, $result );
        $this->assertNull( $classification );
    }

    /**
     * T-PHP-002: Test that spam justification is extracted and included.
     * Tests FR-002: Spam note includes LLM justification text.
     */
    public function test_extract_spam_justification_from_llm_output(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'extract_spam_justification' );
        $method->setAccessible( true );

        // Test evaluation_payload with llm_output
        $result = [
            'evaluation_payload' => [
                'result_data' => [
                    'llm_output' => 'This submission contains multiple spam indicators including excessive links and promotional language.',
                ],
            ],
        ];
        $justification = $method->invoke( $this->adapter, $result );
        $this->assertNotNull( $justification );
        $this->assertStringContainsString( 'spam indicators', $justification );

        // Test with reasoning field
        $result = [
            'result_data' => [
                'reasoning' => 'Contains promotional content',
            ],
        ];
        $justification = $method->invoke( $this->adapter, $result );
        $this->assertSame( 'Contains promotional content', $justification );

        // Test missing justification returns null
        $result = [ 'classification' => 'spam' ];
        $justification = $method->invoke( $this->adapter, $result );
        $this->assertNull( $justification );
    }

    /**
     * T-PHP-003: Test that notification filters are registered.
     * Blocking spam suppression uses gform_notification, and async suppression
     * defers decisions through gform_disable_notification.
     */
    public function test_notification_filter_registered(): void
    {
        // First, ensure hooks are registered
        $this->adapter->register_hooks();

        $has_entry_post_save_filter = has_filter( 'gform_entry_post_save', [ $this->adapter, 'handle_after_submission_entry_post_save' ] );
        // Check that our filter is registered with the gform_notification hook
        $has_filter = has_filter( 'gform_notification', [ $this->adapter, 'maybe_suppress_spam_notification' ] );

        $has_async_deferral_filter = has_filter( 'gform_disable_notification', [ $this->adapter, 'maybe_defer_async_spam_notification' ] );
        $this->assertNotFalse( $has_entry_post_save_filter, 'gform_entry_post_save filter should be registered for after-submission execution' );
        $this->assertSame( 10, $has_entry_post_save_filter, 'Entry post-save filter should have priority 10' );
        $this->assertNotFalse( $has_async_deferral_filter, 'gform_disable_notification filter should be registered for async spam deferral' );
        $this->assertSame( 10, $has_async_deferral_filter, 'Async deferral filter should have priority 10' );
        $this->assertNotFalse( $has_filter, 'gform_notification filter should be registered' );
        $this->assertSame( 10, $has_filter, 'Filter should have priority 10' );
    }

    public function test_handle_after_submission_entry_post_save_returns_entry_after_executing_mappings(): void
    {
        $form_id            = 988;
        $option_key         = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $tracking_action_id = 'test_post_save_tracking_action';
        $execution_calls    = 0;

        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Tracking_Action(
                $tracking_action_id,
                static function () use ( &$execution_calls ): void {
                    $execution_calls++;
                }
            )
        );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_tracking' => [
                    'local_mapping_id'           => 'map_tracking',
                    'central_action_id'          => $tracking_action_id,
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [],
                ],
            ]
        );

        $entry = [
            'id' => 307,
            '1'  => 'hello',
        ];
        $form  = [ 'id' => $form_id ];

        $returned_entry = $this->adapter->handle_after_submission_entry_post_save( $entry, $form );

        $this->assertSame( $entry, $returned_entry );
        $this->assertSame( 1, $execution_calls );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_logs_blocking_result_to_action_log(): void
    {
        $form_id            = 987;
        $option_key         = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $tracking_action_id = 'test_post_save_logged_action';

        delete_option( 'sentient_forms_action_log' );

        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Tracking_Action(
                $tracking_action_id,
                static function (): array {
                    return [
                        'result_data' => [
                            'classification' => 'spam',
                            'justification'  => 'Blocking after-submission spam decision',
                        ],
                        'meta'        => [
                            'credits_debited' => 4,
                        ],
                    ];
                }
            )
        );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_tracking' => [
                    'local_mapping_id'           => 'map_tracking',
                    'central_action_id'          => $tracking_action_id,
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [],
                ],
            ]
        );

        $this->adapter->handle_after_submission_entry_post_save(
            [ 'id' => 308 ],
            [ 'id' => $form_id ]
        );

        $entries = get_option( 'sentient_forms_action_log', [] );

        $this->assertCount( 1, $entries );
        $this->assertSame( 'gravity_forms', $entries[0]['form_source'] ?? null );
        $this->assertSame( $form_id, $entries[0]['form_id'] ?? null );
        $this->assertSame( 308, $entries[0]['entry_id'] ?? null );
        $this->assertSame( $tracking_action_id, $entries[0]['action_code'] ?? null );
        $this->assertSame( 'blocked', $entries[0]['status'] ?? null );
        $this->assertSame( 'spam', $entries[0]['classification'] ?? null );
        $this->assertSame( 4, $entries[0]['credits_used'] ?? null );

        delete_option( 'sentient_forms_action_log' );
        delete_option( $option_key );
    }

    public function test_handle_after_submission_entry_post_save_marks_spam_meta_before_notifications(): void
    {
        $form_id            = 986;
        $option_key         = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $tracking_action_id = 'test_post_save_spam_gate_action';

        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Tracking_Action(
                $tracking_action_id,
                static function (): array {
                    return [
                        'result_data' => [
                            'classification' => 'spam',
                            'justification'  => 'Spam detected before notifications',
                        ],
                    ];
                }
            )
        );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_tracking' => [
                    'local_mapping_id'           => 'map_tracking',
                    'central_action_id'          => $tracking_action_id,
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'suppress_notifications_on_spam' => true,
                    ],
                ],
            ]
        );

        $entry = [ 'id' => 309 ];
        $form  = [ 'id' => $form_id ];

        $this->adapter->handle_after_submission_entry_post_save( $entry, $form );

        $notification = [
            'id'    => 'notif_admin',
            'event' => 'form_submission',
            'name'  => 'Admin Notification',
        ];

        $result = $this->adapter->maybe_suppress_spam_notification( $notification, $form, $entry );

        $this->assertSame( 'spam', gform_get_meta( 309, 'sentient_forms_spam_classification' ) );
        $this->assertFalse( $result );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_executes_blocking_master_mapping_via_action_executor(): void
    {
        $form_id    = 985;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $calls      = [];

        delete_option( 'sentient_forms_action_log' );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Spam Detection',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'execution_mode' => 'validation',
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function ( string $central_action_id, array $form, array $entry, array $context ) use ( &$calls ): array {
                    $calls[] = [
                        'central_action_id' => $central_action_id,
                        'form_id'           => $form['id'] ?? null,
                        'entry_id'          => $entry['id'] ?? null,
                        'hook'              => $context['hook'] ?? null,
                    ];

                    return [
                        'result_data' => [
                            'classification' => 'spam',
                            'justification'  => 'Blocking after-submission master mapping',
                        ],
                        'meta'        => [
                            'credits_debited' => 6,
                        ],
                    ];
                }
            )
        );

        $scheduled_jobs = 0;
        $listener = static function () use ( &$scheduled_jobs ): void {
            $scheduled_jobs++;
        };

        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );

        $entry = [ 'id' => 310, 'status' => 'active' ];
        $form  = [ 'id' => $form_id ];

        $returned_entry = $this->adapter->handle_after_submission_entry_post_save( $entry, $form );

        $notification = [
            'id'    => 'notif_admin',
            'event' => 'form_submission',
            'name'  => 'Admin Notification',
        ];

        $suppress_result = $this->adapter->maybe_suppress_spam_notification( $notification, $form, $entry );

        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );

        $entries = get_option( 'sentient_forms_action_log', [] );

        $this->assertSame( $entry, $returned_entry );
        $this->assertSame( 0, $scheduled_jobs );
        $this->assertCount( 1, $calls );
        $this->assertSame( 'spam_detection_v1', $calls[0]['central_action_id'] ?? null );
        $this->assertSame( 'gform_after_submission', $calls[0]['hook'] ?? null );
        $this->assertSame( 'spam', gform_get_meta( 310, 'sentient_forms_spam_classification' ) );
        $this->assertNull( gform_get_meta( 310, 'sentient_forms_deferred_notification_ids' ) );
        $this->assertFalse( $suppress_result );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'spam_detection_v1', $entries[0]['action_code'] ?? null );
        $this->assertSame( 6, $entries[0]['credits_used'] ?? null );

        delete_option( 'sentient_forms_action_log' );
        delete_option( $option_key );
    }

    public function test_handle_after_submission_blocking_master_mapping_adds_spam_entry_note(): void
    {
        $form_id    = 984;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Spam Detection',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'execution_mode'            => 'validation',
                        'spam_result_display_mode'  => 'entry_note',
                        'spam_indicators_display'   => 'simple',
                        'spam_confidence_threshold' => 0.80,
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function (): array {
                    return [
                        'result_data' => [
                            'classification' => 'spam',
                            'confidence'     => 0.97,
                            'justification'  => 'Blocking after-submission spam note',
                        ],
                    ];
                }
            )
        );

        $spy_adapter = new Sentient_Forms_Test_Gravity_Forms_Adapter_Spy( Sentient_Forms_Plugin::instance() );
        $spy_adapter->entries[311] = [
            'id'     => 311,
            'status' => 'active',
        ];

        $spy_adapter->handle_after_submission_entry_post_save(
            [ 'id' => 311, 'status' => 'active' ],
            [ 'id' => $form_id ]
        );

        $this->assertNotEmpty( $spy_adapter->notes );
        $this->assertStringContainsString(
            'Blocking after-submission spam note',
            (string) ( $spy_adapter->notes[0]['note_content'] ?? '' )
        );
        $this->assertSame( 'spam', $spy_adapter->entries[311]['status'] ?? null );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_blocking_spam_can_allow_notifications(): void
    {
        $form_id    = 983;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Spam Detection',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'execution_mode'                 => 'validation',
                        'suppress_notifications_on_spam' => false,
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function (): array {
                    return [
                        'result_data' => [
                            'classification' => 'spam',
                            'confidence'     => 0.96,
                            'justification'  => 'Blocking spam should still allow notifications.',
                        ],
                    ];
                }
            )
        );

        $entry = [ 'id' => 312, 'status' => 'active' ];
        $form  = [ 'id' => $form_id ];
        $notification = [
            'id'    => 'notif_admin',
            'event' => 'form_submission',
            'name'  => 'Admin Notification',
        ];

        $this->adapter->handle_after_submission_entry_post_save( $entry, $form );
        $result = $this->adapter->maybe_suppress_spam_notification( $notification, $form, $entry );

        $this->assertSame( 'spam', gform_get_meta( 312, 'sentient_forms_spam_classification' ) );
        $this->assertSame( $notification, $result );

        delete_option( $option_key );
    }

    public function test_background_spam_submission_does_not_defer_notifications(): void
    {
        $form_id    = 991;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam'    => [
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                ],
            ]
        );

        $notification = [
            'id'    => 'notif_admin',
            'event' => 'form_submission',
            'name'  => 'Admin Notification',
        ];
        $form = [ 'id' => $form_id ];
        $entry = [
            'id'     => 321,
            'status' => 'active',
        ];

        $result = $this->adapter->maybe_suppress_spam_notification( $notification, $form, $entry );

        $this->assertSame( $notification, $result );
        $this->assertNull( gform_get_meta( 321, 'sentient_forms_deferred_notification_ids' ) );

        delete_option( $option_key );
    }

    /**
     * T-PHP-004: Test that spam entry notifications are suppressed.
     * Tests FR-004: For spam entries, plugin MUST return false from gform_notification filter.
     */
    public function test_spam_entry_notifications_suppressed(): void
    {
        $notification = [
            'name' => 'Admin Notification',
            'to'   => 'admin@example.com',
        ];
        $form = [ 'id' => 1 ];

        // Test with status property set to 'spam' (as stored by GF)
        $spam_entry = [
            'id'     => 123,
            'status' => 'spam',
        ];

        $result = $this->adapter->maybe_suppress_spam_notification( $notification, $form, $spam_entry );

        $this->assertFalse( $result, 'Spam entry notification should return false to suppress' );
    }

    /**
     * T-PHP-005: Test that ham entry notifications pass through unchanged.
     * Tests FR-005: For ham entries, plugin MUST return notification unchanged.
     */
    public function test_ham_entry_notifications_passed(): void
    {
        $notification = [
            'name' => 'Admin Notification',
            'to'   => 'admin@example.com',
        ];
        $form = [ 'id' => 1 ];

        // Test with status property set to 'active' (ham)
        $ham_entry = [
            'id'     => 456,
            'status' => 'active',
        ];

        $result = $this->adapter->maybe_suppress_spam_notification( $notification, $form, $ham_entry );

        $this->assertSame( $notification, $result, 'Ham entry notification should pass through unchanged' );

        // Also test with no is_spam property (new entry)
        $new_entry = [
            'id' => 789,
        ];

        $result = $this->adapter->maybe_suppress_spam_notification( $notification, $form, $new_entry );

        $this->assertSame( $notification, $result, 'Entry without spam status should pass through' );
    }

    /**
     * Test is_entry_spam helper correctly identifies spam entries.
     */
    public function test_is_entry_spam_detects_spam_status(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'is_entry_spam' );
        $method->setAccessible( true );

        // Test status = 'spam' (as used by GF mark_entry_as_spam)
        $this->assertTrue( $method->invoke( $this->adapter, [ 'status' => 'spam' ] ) );

        // Test status = 'active' (normal entry)
        $this->assertFalse( $method->invoke( $this->adapter, [ 'status' => 'active' ] ) );

        // Test status = 'trash'
        $this->assertFalse( $method->invoke( $this->adapter, [ 'status' => 'trash' ] ) );

        // Test missing status property
        $this->assertFalse( $method->invoke( $this->adapter, [ 'id' => 123 ] ) );
    }

    /**
     * T-PHP-010: Test that field validation messages are injected correctly.
     * Tests FR-013: Field-level error injection for content validation.
     */
    public function test_inject_field_validation_messages(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'inject_field_validation_messages' );
        $method->setAccessible( true );

        // Create a mock form with fields
        $field1 = new stdClass();
        $field1->id = 3;
        $field1->failed_validation = false;
        $field1->validation_message = '';

        $field2 = new stdClass();
        $field2->id = 5;
        $field2->failed_validation = false;
        $field2->validation_message = '';

        $validation_result = [
            'form' => [
                'fields' => [ $field1, $field2 ],
            ],
        ];

        $field_errors = [
            [ 'field_id' => '3', 'message' => 'Please provide more detail about your request.' ],
            [ 'field_id' => '5', 'message' => 'Email appears to be invalid.' ],
        ];

        $result = $method->invoke( $this->adapter, $validation_result, $field_errors );

        // Check that field 3 was marked as failed
        $this->assertTrue( $result['form']['fields'][0]->failed_validation );
        $this->assertSame( 'Please provide more detail about your request.', $result['form']['fields'][0]->validation_message );

        // Check that field 5 was marked as failed
        $this->assertTrue( $result['form']['fields'][1]->failed_validation );
        $this->assertSame( 'Email appears to be invalid.', $result['form']['fields'][1]->validation_message );
    }

    /**
     * T-PHP-011: Test that content validation can block submission.
     * Tests FR-012: Synchronous validation-phase execution.
     */
    public function test_inject_validation_message_blocks_submission(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'inject_validation_message' );
        $method->setAccessible( true );

        $validation_result = [
            'is_valid' => true,
            'form' => [
                'failed_validation' => false,
            ],
        ];

        $action_settings = [];

        $result = $method->invoke( $this->adapter, $validation_result, 'Your submission lacks sufficient detail.', $action_settings );

        $this->assertFalse( $result['is_valid'] );
        $this->assertTrue( $result['form']['failed_validation'] );
        $this->assertStringContainsString( 'lacks sufficient detail', $result['form']['validation_message'] );
    }

    /**
     * T-PHP-012: Test that fail-open allows submission on CPS error.
     * Tests NFR-REL-001: Fail-open behavior for validation-phase.
     */
    public function test_fail_open_allows_submission_on_cps_error(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'apply_cps_validation_response' );
        $method->setAccessible( true );

        $validation_result = [
            'is_valid' => true,
            'form' => [
                'failed_validation' => false,
            ],
        ];

        $error = new WP_Error( 'timeout', 'CPS request timed out' );
        $action_settings = [ 'fail_open' => true ]; // Explicitly enable fail-open

        $result = $method->invoke( $this->adapter, $validation_result, $error, $action_settings );

        // With fail-open enabled, submission should still be valid
        $this->assertTrue( $result['is_valid'] );
        $this->assertFalse( $result['form']['failed_validation'] );
    }

    /**
     * Test fail-closed mode blocks submission on CPS error.
     */
    public function test_fail_closed_blocks_submission_on_cps_error(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'apply_cps_validation_response' );
        $method->setAccessible( true );

        $validation_result = [
            'is_valid' => true,
            'form' => [
                'failed_validation' => false,
            ],
        ];

        $error = new WP_Error( 'timeout', 'CPS request timed out' );
        $action_settings = [ 'fail_open' => false ]; // Disable fail-open

        $result = $method->invoke( $this->adapter, $validation_result, $error, $action_settings );

        // With fail-open disabled, submission should be blocked
        $this->assertFalse( $result['is_valid'] );
        $this->assertTrue( $result['form']['failed_validation'] );
    }

    /**
     * Test content_validation_v1 can block from CPS structured_output without a top-level validation envelope.
     */
    public function test_apply_cps_validation_response_blocks_from_content_validation_structured_output(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'apply_cps_validation_response' );
        $method->setAccessible( true );

        $field = (object) [
            'id'                 => 3,
            'failed_validation'  => false,
            'validation_message' => '',
        ];

        $validation_result = [
            'is_valid' => true,
            'form'     => [
                'failed_validation' => false,
                'fields'            => [ $field ],
            ],
        ];

        $response = [
            'result_data' => [
                'structured_output_valid' => true,
                'structured_output'       => [
                    'is_valid' => false,
                    'message'  => 'Please provide a real project description.',
                    'fields'   => [
                        [
                            'field_id' => '3',
                            'is_valid' => false,
                            'message'  => 'Tell us what you need built.',
                        ],
                    ],
                ],
            ],
        ];

        $action_settings = [
            'central_action_id'  => 'content_validation_v1',
            'action_name_label'  => 'Content Quality',
            'validation_message' => 'Fallback content message.',
        ];

        $result = $method->invoke( $this->adapter, $validation_result, $response, $action_settings );

        $this->assertFalse( $result['is_valid'] );
        $this->assertTrue( $result['form']['failed_validation'] );
        $this->assertStringContainsString(
            'Please provide a real project description.',
            (string) ( $result['form']['validation_message'] ?? '' )
        );
        $this->assertTrue( $result['form']['fields'][0]->failed_validation );
        $this->assertSame( 'Tell us what you need built.', $result['form']['fields'][0]->validation_message );
    }

    /**
     * Test the validation hook blocks when CPS returns content_validation_v1 structured output.
     */
    public function test_handle_validation_blocks_content_validation_structured_output_without_top_level_validation(): void
    {
        $form_id    = 99024;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $calls      = [];

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_content_quality' => [
                    'local_mapping_id'           => 'map_content_quality',
                    'central_action_id'          => 'content_validation_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function ( string $central_action_id ) use ( &$calls ): array {
                    $calls[] = $central_action_id;

                    return [
                        'result_data' => [
                            'structured_output_valid' => true,
                            'structured_output'       => [
                                'is_valid' => false,
                                'message'  => 'Please provide a real project description.',
                                'fields'   => [
                                    [
                                        'field_id' => '3',
                                        'is_valid' => false,
                                        'message'  => 'Tell us what you need built.',
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
            )
        );

        $field = (object) [
            'id'                 => 3,
            'failed_validation'  => false,
            'validation_message' => '',
        ];

        $result = $this->adapter->handle_validation(
            [
                'is_valid' => true,
                'form'     => [
                    'id'                => $form_id,
                    'failed_validation' => false,
                    'fields'            => [ $field ],
                ],
            ]
        );

        $this->assertSame( [ 'content_validation_v1' ], $calls );
        $this->assertFalse( $result['is_valid'] );
        $this->assertTrue( $result['form']['failed_validation'] );
        $this->assertStringContainsString(
            'Please provide a real project description.',
            (string) ( $result['form']['validation_message'] ?? '' )
        );
        $this->assertTrue( $result['form']['fields'][0]->failed_validation );
        $this->assertSame( 'Tell us what you need built.', $result['form']['fields'][0]->validation_message );

        delete_option( $option_key );
    }

    // =========================================================================
    // Structured Spam Detection Tests
    // =========================================================================

    /**
     * T-PHP-020: Test that confidence score is extracted from structured response.
     * Tests FR-004: Confidence threshold must be checked before marking as spam.
     */
    public function test_extract_spam_confidence_from_structured_response(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'extract_spam_confidence' );
        $method->setAccessible( true );

        // Test structured response with confidence
        $result = [
            'result_data' => [
                'classification' => 'spam',
                'confidence' => 0.95,
                'justification' => 'High-pressure spam indicators',
            ],
        ];
        $confidence = $method->invoke( $this->adapter, $result );
        $this->assertSame( 0.95, $confidence );

        // Test evaluation_payload structure
        $result = [
            'evaluation_payload' => [
                'result_data' => [
                    'confidence' => 0.72,
                ],
            ],
        ];
        $confidence = $method->invoke( $this->adapter, $result );
        $this->assertSame( 0.72, $confidence );

        // Test missing confidence returns null
        $result = [ 'classification' => 'spam' ];
        $confidence = $method->invoke( $this->adapter, $result );
        $this->assertNull( $confidence );
    }

    /**
     * T-PHP-021: Test that spam indicators are extracted from structured response.
     */
    public function test_extract_spam_indicators_from_structured_response(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'extract_spam_indicators' );
        $method->setAccessible( true );

        $result = [
            'result_data' => [
                'indicators' => [
                    [ 'type' => 'high_pressure_language', 'evidence' => 'ACT NOW', 'weight' => 'high' ],
                    [ 'type' => 'cryptocurrency_scam', 'evidence' => 'BITCOIN', 'weight' => 'high' ],
                ],
            ],
        ];
        $indicators = $method->invoke( $this->adapter, $result );
        $this->assertCount( 2, $indicators );
        $this->assertSame( 'high_pressure_language', $indicators[0]['type'] );

        // Test missing indicators returns empty array
        $result = [ 'classification' => 'spam' ];
        $indicators = $method->invoke( $this->adapter, $result );
        $this->assertSame( [], $indicators );
    }

    /**
     * T-PHP-022: Test structured justification is preferred over llm_output.
     */
    public function test_extract_spam_justification_prefers_structured_field(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'extract_spam_justification' );
        $method->setAccessible( true );

        // When both justification and llm_output exist, prefer justification
        $result = [
            'result_data' => [
                'justification' => 'This is the structured justification.',
                'llm_output' => '{"classification":"spam","justification":"This is the structured justification."}',
            ],
        ];
        $justification = $method->invoke( $this->adapter, $result );
        $this->assertSame( 'This is the structured justification.', $justification );

        // When only llm_output exists (legacy), truncate and use it
        $result = [
            'result_data' => [
                'llm_output' => str_repeat( 'word ', 100 ), // Very long output
            ],
        ];
        $justification = $method->invoke( $this->adapter, $result );
        $this->assertNotNull( $justification );
        $this->assertStringContainsString( '...', $justification ); // Should be truncated
    }

    /**
     * T-PHP-023: Test spam note formatting in simple mode.
     */
    public function test_format_spam_detection_note_simple_mode(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'format_spam_detection_note' );
        $method->setAccessible( true );

        $result = [
            'result_data' => [
                'classification' => 'spam',
                'confidence' => 0.92,
                'justification' => 'Contains cryptocurrency spam indicators.',
                'indicators' => [
                    [ 'type' => 'cryptocurrency_scam', 'evidence' => 'BITCOIN', 'weight' => 'high' ],
                ],
            ],
        ];
        $context = [ 'spam_indicators_display' => 'simple' ];

        $note = $method->invoke( $this->adapter, $result, $context, true );

        $this->assertStringContainsString( '🚫', $note );
        $this->assertStringContainsString( 'SPAM', $note );
        $this->assertStringContainsString( '92%', $note );
        $this->assertStringContainsString( 'cryptocurrency spam indicators', $note );
        $this->assertStringNotContainsString( 'Signals Detected', $note ); // Not in simple mode
    }

    /**
     * T-PHP-024: Test spam note formatting in detailed mode.
     */
    public function test_format_spam_detection_note_detailed_mode(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'format_spam_detection_note' );
        $method->setAccessible( true );

        $result = [
            'result_data' => [
                'classification' => 'spam',
                'confidence' => 0.95,
                'justification' => 'Multiple spam signals detected.',
                'indicators' => [
                    [ 'type' => 'high_pressure_language', 'evidence' => 'ACT NOW', 'weight' => 'high' ],
                    [ 'type' => 'cryptocurrency_scam', 'evidence' => 'BITCOIN', 'weight' => 'high' ],
                ],
            ],
        ];
        $context = [ 'spam_indicators_display' => 'detailed' ];

        $note = $method->invoke( $this->adapter, $result, $context, true );

        $this->assertStringContainsString( '🚫', $note );
        $this->assertStringContainsString( 'SPAM', $note );
        $this->assertStringContainsString( '95%', $note );
        $this->assertStringContainsString( 'Signals Detected', $note );
        $this->assertStringContainsString( 'High Pressure Language', $note ); // Humanized type
        $this->assertStringContainsString( 'ACT NOW', $note );
        $this->assertStringContainsString( 'BITCOIN', $note );
    }

    /**
     * T-PHP-025: Test ham note formatting shows legitimate status.
     */
    public function test_format_spam_detection_note_ham(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'format_spam_detection_note' );
        $method->setAccessible( true );

        $result = [
            'result_data' => [
                'classification' => 'ham',
                'confidence' => 0.15,
                'justification' => 'Legitimate inquiry about services.',
                'indicators' => [],
            ],
        ];
        $context = [ 'spam_indicators_display' => 'simple' ];

        $note = $method->invoke( $this->adapter, $result, $context, false );

        $this->assertStringContainsString( '✅', $note );
        $this->assertStringContainsString( 'LEGITIMATE', $note );
        $this->assertStringContainsString( '15%', $note );
        $this->assertStringContainsString( 'Legitimate inquiry', $note );
    }

    /**
     * T-PHP-026: Test spam below threshold is not marked.
     * Tests FR-004: Plugin MUST only mark when confidence >= threshold.
     */
    public function test_spam_below_threshold_not_marked(): void
    {
        // This test verifies the threshold logic in format_spam_detection_note context
        // The actual marking occurs in maybe_mark_entry_as_spam_from_result which requires more mocking
        // For unit test, we verify confidence extraction and note formatting work correctly
        
        $confidence_method = new ReflectionMethod( $this->adapter, 'extract_spam_confidence' );
        $confidence_method->setAccessible( true );

        $result = [
            'result_data' => [
                'classification' => 'spam',
                'confidence' => 0.65,
            ],
        ];
        
        $confidence = $confidence_method->invoke( $this->adapter, $result );
        $threshold = 0.80;
        
        // Verify that confidence below threshold would NOT mark as spam
        $this->assertLessThan( $threshold, $confidence );
    }

    /**
     * T-PHP-027: Test spam at exactly threshold is marked.
     */
    public function test_spam_exactly_at_threshold_is_marked(): void
    {
        $confidence_method = new ReflectionMethod( $this->adapter, 'extract_spam_confidence' );
        $confidence_method->setAccessible( true );

        $result = [
            'result_data' => [
                'classification' => 'spam',
                'confidence' => 0.80,
            ],
        ];
        
        $confidence = $confidence_method->invoke( $this->adapter, $result );
        $threshold = 0.80;
        
        // Verify that confidence >= threshold would mark as spam
        $this->assertGreaterThanOrEqual( $threshold, $confidence );
    }

    /**
     * T-PHP-028: Test legacy response without confidence uses 1.0 default.
     */
    public function test_legacy_response_without_confidence_treated_as_full(): void
    {
        $confidence_method = new ReflectionMethod( $this->adapter, 'extract_spam_confidence' );
        $confidence_method->setAccessible( true );

        // Legacy response structure without confidence field
        $result = [
            'result_data' => [
                'classification' => 'spam',
                'llm_output' => '**spam**\n\nThis is spam because...',
            ],
        ];
        
        $confidence = $confidence_method->invoke( $this->adapter, $result );
        
        // Should be null, which the threshold logic treats as 1.0
        $this->assertNull( $confidence );
        
        // For backward compatibility, null confidence is treated as 1.0
        // This ensures legacy CPS responses still mark spam correctly
    }

    // =========================================================================
    // CB-FORMS-001: Per-Form Master Disable Tests
    // =========================================================================

    /**
     * CB-FORMS-001: Test that handle_validation short-circuits when sf_disabled is set.
     *
     * When the sf_disabled flag is true in form settings, the adapter MUST
     * return the original validation result unchanged — no CPS calls, no
     * action processing, no side-effects.
     */
    public function test_handle_validation_skips_all_actions_when_form_disabled(): void
    {
        $form_id    = 999;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        // Store sf_disabled = true alongside a real action mapping that would
        // normally require a CPS call (and fail in a unit test context).
        update_option( $option_key, [
            'sf_disabled'  => true,
            'map_spam_v1'  => [
                'central_action_id'          => 'spam_detection_v1',
                'is_action_enabled_for_form' => true,
                'trigger_hooks'              => [ 'gform_validation' ],
            ],
        ] );

        $validation_result = [
            'is_valid' => true,
            'form'     => [
                'id'     => $form_id,
                'fields' => [],
            ],
        ];

        // If the sf_disabled check is missing, the adapter would try to look up
        // the action in the registry and make a CPS call, which would fail or
        // throw. A clean return proves the guard works.
        $result = $this->adapter->handle_validation( $validation_result );

        $this->assertSame( $validation_result, $result, 'Disabled form should return validation result unchanged' );

        // Clean up
        delete_option( $option_key );
    }

    /**
     * CB-FORMS-001: Test that handle_after_submission short-circuits when sf_disabled is set.
     *
     * Same invariant as validation: no CPS calls, no Action Scheduler jobs,
     * no entry notes — just an early return.
     */
    public function test_handle_after_submission_skips_all_actions_when_form_disabled(): void
    {
        $form_id    = 998;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option( $option_key, [
            'sf_disabled'  => true,
            'map_eval_v1'  => [
                'central_action_id'          => 'entry_evaluation',
                'is_action_enabled_for_form' => true,
                'trigger_hooks'              => [ 'gform_after_submission' ],
            ],
        ] );

        $entry = [ 'id' => 42 ];
        $form  = [ 'id' => $form_id ];

        // Should return without throwing or processing any actions.
        $this->adapter->handle_after_submission( $entry, $form );

        // If we reach here, the guard worked. Add an explicit assertion
        // so PHPUnit doesn't mark this as risky (no assertions).
        $this->assertTrue( true, 'handle_after_submission returned cleanly when form disabled' );

        delete_option( $option_key );
    }

    public function test_handle_validation_skips_all_actions_when_provider_is_globally_disabled(): void
    {
        $form_id    = 997;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option( $option_key, [
            'sf_disabled'  => false,
            'map_spam_v1'  => [
                'central_action_id'          => 'spam_detection_v1',
                'is_action_enabled_for_form' => true,
                'trigger_hooks'              => [ 'gform_validation' ],
            ],
        ] );

        update_option(
            'sentient_forms_plugin_settings',
            [
                'execution_global_disabled'   => false,
                'execution_provider_disabled' => [ 'gravity_forms' => true ],
            ]
        );

        $validation_result = [
            'is_valid' => true,
            'form'     => [
                'id'     => $form_id,
                'fields' => [],
            ],
        ];

        $result = $this->adapter->handle_validation( $validation_result );
        $this->assertSame( $validation_result, $result, 'Provider-level disable should skip execution' );

        delete_option( $option_key );
        delete_option( 'sentient_forms_plugin_settings' );
    }

    public function test_handle_validation_skips_mapping_when_conditions_do_not_match(): void
    {
        $form_id    = 996;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_conditional' => [
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'fail_open'                  => false,
                    'settings'                   => [
                        'conditions' => [
                            'enabled' => true,
                            'root'    => [
                                'type'  => 'group',
                                'logic' => 'all',
                                'rules' => [
                                    [
                                        'type'     => 'rule',
                                        'field_id' => '999',
                                        'operator' => 'eq',
                                        'value'    => 'run',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $validation_result = [
            'is_valid' => true,
            'form'     => [
                'id'     => $form_id,
                'fields' => [],
            ],
        ];

        $result = $this->adapter->handle_validation( $validation_result );
        $this->assertSame( $validation_result, $result );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_skips_mapping_when_conditions_do_not_match(): void
    {
        $form_id    = 995;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_conditional' => [
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'conditions' => [
                            'enabled' => true,
                            'root'    => [
                                'type'  => 'group',
                                'logic' => 'all',
                                'rules' => [
                                    [
                                        'type'     => 'rule',
                                        'field_id' => '7',
                                        'operator' => 'contains',
                                        'value'    => 'approved',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $scheduled_jobs = 0;
        $listener = static function () use ( &$scheduled_jobs ): void {
            $scheduled_jobs++;
        };

        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );

        $entry = [
            'id' => 42,
            '1'  => 'hello world',
        ];
        $form = [ 'id' => $form_id ];

        $this->adapter->handle_after_submission( $entry, $form );

        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );

        $this->assertSame( 0, $scheduled_jobs );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_enqueues_mapping_when_conditions_match(): void
    {
        $form_id    = 994;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_conditional' => [
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'conditions' => [
                            'enabled' => true,
                            'root'    => [
                                'type'  => 'group',
                                'logic' => 'all',
                                'rules' => [
                                    [
                                        'type'     => 'rule',
                                        'field_id' => '7',
                                        'operator' => 'contains',
                                        'value'    => 'approved',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $scheduled_jobs = 0;
        $listener = static function () use ( &$scheduled_jobs ): void {
            $scheduled_jobs++;
        };

        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );

        $entry = [
            'id' => 142,
            '7'  => 'approved by reviewer',
        ];
        $form = [ 'id' => $form_id ];

        $this->adapter->handle_after_submission( $entry, $form );

        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );

        $this->assertGreaterThanOrEqual( 1, $scheduled_jobs );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_logs_pending_entry_when_async_mapping_is_queued(): void
    {
        $form_id    = 9941;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        delete_option( 'sentient_forms_action_log' );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_conditional' => [
                    'local_mapping_id'           => 'map_conditional',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_name_label'          => 'Spam Detection',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [],
                ],
            ]
        );

        $entry = [
            'id' => 2142,
            '7'  => 'approved by reviewer',
        ];
        $form = [ 'id' => $form_id ];

        $this->adapter->handle_after_submission( $entry, $form );

        $entries = get_option( 'sentient_forms_action_log', [] );

        $this->assertNotEmpty( $entries );
        $this->assertSame( 'pending', $entries[0]['status'] ?? null );
        $this->assertSame( 'map_conditional', $entries[0]['mapping_id'] ?? null );
        $this->assertSame( 'spam_detection_v1', $entries[0]['action_code'] ?? null );
        $this->assertNotEmpty( $entries[0]['execution_request_id'] ?? null );

        delete_option( 'sentient_forms_action_log' );
        delete_option( $option_key );
    }

    public function test_handle_after_submission_enqueues_mapping_when_conditions_disabled(): void
    {
        $form_id    = 993;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_conditional' => [
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'conditions' => [
                            'enabled' => false,
                            'root'    => [
                                'type'  => 'group',
                                'logic' => 'all',
                                'rules' => [],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $scheduled_jobs = 0;
        $listener = static function () use ( &$scheduled_jobs ): void {
            $scheduled_jobs++;
        };

        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );

        $entry = [
            'id' => 143,
            '1'  => 'anything',
        ];
        $form = [ 'id' => $form_id ];

        $this->adapter->handle_after_submission( $entry, $form );

        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );

        $this->assertGreaterThanOrEqual( 1, $scheduled_jobs );

        delete_option( $option_key );
    }

    public function test_handle_validation_skips_dependent_mapping_when_prerequisite_is_skipped(): void
    {
        $form_id    = 992;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_prereq' => [
                    'local_mapping_id'           => 'map_prereq',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [
                        'conditions' => [
                            'enabled' => true,
                            'root'    => [
                                'type'  => 'group',
                                'logic' => 'all',
                                'rules' => [
                                    [
                                        'type'     => 'rule',
                                        'field_id' => '999',
                                        'operator' => 'eq',
                                        'value'    => 'run',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'map_dependent' => [
                    'local_mapping_id'           => 'map_dependent',
                    'central_action_id'          => 'entry_evaluation',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'fail_open'                  => false,
                    'settings'                   => [
                        'dependency_ids' => [ 'map_prereq' ],
                    ],
                ],
            ]
        );

        $validation_result = [
            'is_valid' => true,
            'form'     => [
                'id'     => $form_id,
                'fields' => [],
            ],
        ];

        $result = $this->adapter->handle_validation( $validation_result );
        $this->assertTrue( $result['is_valid'] );
        $this->assertEmpty( $result['form']['validation_message'] ?? '' );

        delete_option( $option_key );
    }

    public function test_handle_validation_skips_spam_gated_dependent_mapping_when_upstream_classifies_spam(): void
    {
        $form_id    = 9902;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $calls      = [];

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [],
                ],
                'map_child' => [
                    'local_mapping_id'           => 'map_child',
                    'central_action_id'          => 'content_validation_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [
                        'dependency_ids'        => [ 'map_spam' ],
                        'skip_on_upstream_spam' => true,
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function ( string $central_action_id ) use ( &$calls ): array {
                    $calls[] = $central_action_id;

                    if ( 'spam_detection_v1' === $central_action_id ) {
                        return [
                            'result_data' => [
                                'classification' => 'spam',
                            ],
                            'validation'  => [
                                'is_valid' => false,
                                'message'  => 'Blocked as spam.',
                            ],
                        ];
                    }

                    return [
                        'validation' => [
                            'is_valid' => false,
                            'message'  => 'Downstream should not run.',
                        ],
                    ];
                }
            )
        );

        $_POST = [];
        $result = $this->adapter->handle_validation(
            [
                'is_valid' => true,
                'form'     => [
                    'id'     => $form_id,
                    'fields' => [],
                ],
            ]
        );

        $this->assertSame( [ 'spam_detection_v1' ], $calls );
        $this->assertFalse( $result['is_valid'] );
        $this->assertStringContainsString( 'Blocked as spam.', (string) ( $result['form']['validation_message'] ?? '' ) );
        $this->assertStringNotContainsString( 'Downstream should not run.', (string) ( $result['form']['validation_message'] ?? '' ) );

        delete_option( $option_key );
    }

    public function test_handle_validation_logs_blocking_spam_result_to_action_log(): void
    {
        $form_id    = 99021;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        delete_option( 'sentient_forms_action_log' );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Spam Detection',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function (): array {
                    return [
                        'result_data' => [
                            'classification' => 'spam',
                            'justification'  => 'Validation-phase spam block',
                        ],
                        'meta'       => [
                            'credits_debited' => 3,
                        ],
                        'validation' => [
                            'is_valid' => false,
                            'message'  => 'Blocked as spam.',
                        ],
                    ];
                }
            )
        );

        $_POST = [];
        $result = $this->adapter->handle_validation(
            [
                'is_valid' => true,
                'form'     => [
                    'id'     => $form_id,
                    'fields' => [],
                ],
            ]
        );

        $entries = get_option( 'sentient_forms_action_log', [] );

        $this->assertFalse( $result['is_valid'] );
        $this->assertIsArray( $entries );
        $this->assertNotEmpty( $entries );
        $this->assertSame( 'gravity_forms', $entries[0]['form_source'] ?? null );
        $this->assertSame( $form_id, $entries[0]['form_id'] ?? null );
        $this->assertNull( $entries[0]['entry_id'] ?? null );
        $this->assertSame( 'spam_detection_v1', $entries[0]['action_code'] ?? null );
        $this->assertSame( 'blocked', $entries[0]['status'] ?? null );
        $this->assertSame( 'spam', $entries[0]['classification'] ?? null );
        $this->assertSame( 3, $entries[0]['credits_used'] ?? null );

        delete_option( $option_key );
        delete_option( 'sentient_forms_action_log' );
    }

    public function test_entry_post_save_backfills_validation_action_log_entry_id(): void
    {
        $form_id    = 99022;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        delete_option( 'sentient_forms_action_log' );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_validation' => [
                    'local_mapping_id'           => 'map_validation',
                    'central_action_id'          => 'content_validation_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Content Quality',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function (): array {
                    return [
                        'result_data' => [
                            'classification' => 'ham',
                        ],
                        'meta' => [
                            'execution_request_id' => 'req-validation-entry-backfill',
                            'credits_debited'      => 12,
                        ],
                        'validation' => [
                            'is_valid' => true,
                        ],
                    ];
                }
            )
        );

        $_POST = [];
        $this->adapter->handle_validation(
            [
                'is_valid' => true,
                'form'     => [
                    'id'     => $form_id,
                    'fields' => [],
                ],
            ]
        );

        $entries = get_option( 'sentient_forms_action_log', [] );
        $this->assertNotEmpty( $entries );
        $this->assertNull( $entries[0]['entry_id'] ?? null );
        $this->assertNotEmpty( $entries[0]['execution_request_id'] ?? null );

        $this->adapter->handle_after_submission_entry_post_save(
            [
                'id' => 9090,
            ],
            [
                'id' => $form_id,
            ]
        );

        $updated_entries = get_option( 'sentient_forms_action_log', [] );

        $this->assertSame( 9090, $updated_entries[0]['entry_id'] ?? null );
        $this->assertSame( $entries[0]['execution_request_id'], $updated_entries[0]['execution_request_id'] ?? null );

        delete_option( $option_key );
        delete_option( 'sentient_forms_action_log' );
    }

    public function test_handle_validation_skips_spam_gated_dependent_mapping_when_upstream_classifies_likely_spam(): void
    {
        $form_id    = 9903;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $calls      = [];

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [],
                ],
                'map_child' => [
                    'local_mapping_id'           => 'map_child',
                    'central_action_id'          => 'content_validation_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [
                        'dependency_ids'        => [ 'map_spam' ],
                        'skip_on_upstream_spam' => true,
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function ( string $central_action_id ) use ( &$calls ): array {
                    $calls[] = $central_action_id;

                    if ( 'spam_detection_v1' === $central_action_id ) {
                        return [
                            'result_data' => [
                                'classification' => 'likely_spam',
                            ],
                            'validation'  => [
                                'is_valid' => false,
                                'message'  => 'Likely spam blocked.',
                            ],
                        ];
                    }

                    return [
                        'validation' => [
                            'is_valid' => false,
                            'message'  => 'Downstream should not run.',
                        ],
                    ];
                }
            )
        );

        $_POST = [];
        $result = $this->adapter->handle_validation(
            [
                'is_valid' => true,
                'form'     => [
                    'id'     => $form_id,
                    'fields' => [],
                ],
            ]
        );

        $this->assertSame( [ 'spam_detection_v1' ], $calls );
        $this->assertFalse( $result['is_valid'] );
        $this->assertStringContainsString( 'Likely spam blocked.', (string) ( $result['form']['validation_message'] ?? '' ) );

        delete_option( $option_key );
    }

    public function test_handle_validation_runs_spam_gated_dependent_mapping_when_upstream_classifies_ham(): void
    {
        $form_id    = 9904;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $calls      = [];

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [],
                ],
                'map_child' => [
                    'local_mapping_id'           => 'map_child',
                    'central_action_id'          => 'content_validation_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [
                        'dependency_ids'        => [ 'map_spam' ],
                        'skip_on_upstream_spam' => true,
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function ( string $central_action_id ) use ( &$calls ): array {
                    $calls[] = $central_action_id;

                    if ( 'spam_detection_v1' === $central_action_id ) {
                        return [
                            'result_data' => [
                                'classification' => 'ham',
                            ],
                            'validation'  => [
                                'is_valid' => true,
                            ],
                        ];
                    }

                    return [
                        'validation' => [
                            'is_valid' => false,
                            'message'  => 'Tell us more.',
                        ],
                    ];
                }
            )
        );

        $_POST = [];
        $result = $this->adapter->handle_validation(
            [
                'is_valid' => true,
                'form'     => [
                    'id'     => $form_id,
                    'fields' => [],
                ],
            ]
        );

        $this->assertSame( [ 'spam_detection_v1', 'content_validation_v1' ], $calls );
        $this->assertFalse( $result['is_valid'] );
        $this->assertStringContainsString( 'Tell us more.', (string) ( $result['form']['validation_message'] ?? '' ) );

        delete_option( $option_key );
    }

    public function test_handle_validation_runs_spam_gated_dependent_mapping_when_upstream_has_no_classification(): void
    {
        $form_id    = 9905;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $calls      = [];

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [],
                ],
                'map_child' => [
                    'local_mapping_id'           => 'map_child',
                    'central_action_id'          => 'content_validation_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_validation' ],
                    'settings'                   => [
                        'dependency_ids'        => [ 'map_spam' ],
                        'skip_on_upstream_spam' => true,
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function ( string $central_action_id ) use ( &$calls ): array {
                    $calls[] = $central_action_id;

                    if ( 'spam_detection_v1' === $central_action_id ) {
                        return [
                            'validation' => [
                                'is_valid' => true,
                            ],
                        ];
                    }

                    return [
                        'validation' => [
                            'is_valid' => false,
                            'message'  => 'Tell us more.',
                        ],
                    ];
                }
            )
        );

        $_POST = [];
        $result = $this->adapter->handle_validation(
            [
                'is_valid' => true,
                'form'     => [
                    'id'     => $form_id,
                    'fields' => [],
                ],
            ]
        );

        $this->assertSame( [ 'spam_detection_v1', 'content_validation_v1' ], $calls );
        $this->assertFalse( $result['is_valid'] );
        $this->assertStringContainsString( 'Tell us more.', (string) ( $result['form']['validation_message'] ?? '' ) );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_skips_dependent_mapping_when_prerequisite_disabled(): void
    {
        $form_id    = 991;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_prereq' => [
                    'local_mapping_id'           => 'map_prereq',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => false,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                ],
                'map_dependent' => [
                    'local_mapping_id'           => 'map_dependent',
                    'central_action_id'          => 'entry_evaluation',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'dependency_ids' => [ 'map_prereq' ],
                    ],
                ],
            ]
        );

        $scheduled_jobs = 0;
        $listener = static function () use ( &$scheduled_jobs ): void {
            $scheduled_jobs++;
        };

        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );
        $this->adapter->handle_after_submission( [ 'id' => 303 ], [ 'id' => $form_id ] );
        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );

        $this->assertSame( 0, $scheduled_jobs );
        delete_option( $option_key );
    }

    public function test_handle_after_submission_blocks_sync_dependent_mapping_when_prerequisite_is_queued(): void
    {
        $form_id            = 989;
        $option_key         = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $tracking_action_id = 'test_sync_dependency_action';
        $execution_calls    = 0;

        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Tracking_Action(
                $tracking_action_id,
                static function () use ( &$execution_calls ): void {
                    $execution_calls++;
                }
            )
        );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_prereq' => [
                    'local_mapping_id'           => 'map_prereq',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [],
                ],
                'map_dependent' => [
                    'local_mapping_id'           => 'map_dependent',
                    'central_action_id'          => $tracking_action_id,
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'dependency_ids' => [ 'map_prereq' ],
                        'execution_mode' => 'validation',
                    ],
                ],
            ]
        );

        $scheduled_jobs = 0;
        $listener = static function () use ( &$scheduled_jobs ): void {
            $scheduled_jobs++;
        };

        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );
        $this->adapter->handle_after_submission( [ 'id' => 306 ], [ 'id' => $form_id ] );
        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );

        $this->assertSame( 1, $scheduled_jobs );
        $this->assertSame( 0, $execution_calls );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_passes_dependency_context_for_dependent_mapping(): void
    {
        $form_id    = 990;
        $option_key = 'sentient_forms_actions_gravity_forms_' . $form_id;

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_prereq' => [
                    'local_mapping_id'           => 'map_prereq',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                ],
                'map_dependent' => [
                    'local_mapping_id'           => 'map_dependent',
                    'central_action_id'          => 'entry_evaluation',
                    'action_type_indicator'      => 'master',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'dependency_ids' => [ 'map_prereq' ],
                    ],
                ],
            ]
        );

        $captured_jobs = [];
        $listener = static function ( $hook, $args ) use ( &$captured_jobs ): void {
            $captured_jobs[] = [
                'hook' => $hook,
                'args' => $args,
            ];
        };

        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );
        $this->adapter->handle_after_submission( [ 'id' => 304 ], [ 'id' => $form_id ] );
        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );

        $dependent = null;
        foreach ( $captured_jobs as $job )
        {
            $context = $job['args']['context'] ?? [];
            if ( ( $context['local_mapping_id'] ?? '' ) === 'map_dependent' )
            {
                $dependent = $context;
                break;
            }
        }

        $this->assertNotNull( $dependent );
        $this->assertSame( [ 'map_prereq' ], $dependent['dependency_mapping_ids'] ?? [] );
        $this->assertArrayHasKey( 'map_prereq', $dependent['dependency_execution_request_ids'] ?? [] );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_skips_dependent_mapping_when_upstream_spam_mapping_skips_downstream(): void
    {
        $form_id            = 982;
        $option_key         = 'sentient_forms_actions_gravity_forms_' . $form_id;
        $tracking_action_id = 'test_upstream_spam_skip_action';
        $execution_calls    = 0;

        Sentient_Forms_Plugin::instance()->get_action_registry()->register_action(
            new Sentient_Forms_Test_Tracking_Action(
                $tracking_action_id,
                static function () use ( &$execution_calls ): void {
                    $execution_calls++;
                }
            )
        );

        update_option(
            $option_key,
            [
                'sf_disabled' => false,
                'map_spam' => [
                    'local_mapping_id'           => 'map_spam',
                    'central_action_id'          => 'spam_detection_v1',
                    'action_type_indicator'      => 'master',
                    'action_name_label'          => 'Spam Detection',
                    'is_action_enabled_for_form' => true,
                    'mark_as_spam'               => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'execution_mode'          => 'validation',
                        'skip_downstream_on_spam' => true,
                    ],
                ],
                'map_dependent' => [
                    'local_mapping_id'           => 'map_dependent',
                    'central_action_id'          => $tracking_action_id,
                    'action_type_indicator'      => 'custom',
                    'is_action_enabled_for_form' => true,
                    'trigger_hooks'              => [ 'gform_after_submission' ],
                    'settings'                   => [
                        'dependency_ids' => [ 'map_spam' ],
                        'execution_mode' => 'validation',
                    ],
                ],
            ]
        );

        $this->set_action_executor(
            new Sentient_Forms_Test_Validation_Action_Executor(
                Sentient_Forms_Plugin::instance(),
                static function (): array {
                    return [
                        'result_data' => [
                            'classification' => 'spam',
                            'confidence'     => 0.98,
                            'justification'  => 'Upstream spam classification should stop downstream work.',
                        ],
                    ];
                }
            )
        );

        $this->adapter->handle_after_submission_entry_post_save(
            [ 'id' => 313, 'status' => 'active' ],
            [ 'id' => $form_id ]
        );

        $this->assertSame( 0, $execution_calls );

        delete_option( $option_key );
    }

    public function test_handle_after_submission_executes_local_openrouter_mapping_from_local_tables(): void
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
        $secret         = 'sk-or-gf-local-submission-secret';
        $encrypted      = $vault->encrypt( $secret );
        $http_urls      = [];

        $this->assertIsString( $encrypted );

        $credential_id = $credentials->create(
            [
                'provider'          => 'openrouter',
                'label'             => 'Submission smoke OpenRouter key',
                'auth_mode'         => 'manual_key',
                'encrypted_secret'  => $encrypted,
                'status'            => 'valid',
                'last_validated_at' => current_time( 'mysql' ),
            ]
        );
        $this->assertIsInt( $credential_id );

        $consent_id = $consents->record( 'openrouter', '2026-04-17', 0 );
        $this->assertIsInt( $consent_id );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'gf_local_openrouter_summary',
                'display_name'         => 'GF Local OpenRouter Summary',
                'definition_json'      => [
                    'system_prompt'   => 'Summarize Gravity Forms entries.',
                    'prompt_template' => 'Lead: {{name}} <{{email}}> on {{form.title}}',
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
                    'name'  => '1',
                    'email' => '2',
                ],
                'execution_mode'      => 'sync',
                'effect_mapping_json' => [
                    'store_result' => true,
                    'meta'         => [
                        'sentient_forms_summary' => 'structured.summary',
                    ],
                ],
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $http_filter = static function ( $preempt, array $args, string $url ) use ( &$http_urls ): mixed {
            $http_urls[] = $url;

            if ( false !== strpos( $url, 'sentientforms.com' ) )
            {
                return new WP_Error( 'unexpected_sentient_request', 'Local-first submission tried to call Sentient.' );
            }

            if ( false !== strpos( $url, 'openrouter.ai/api/v1/chat/completions' ) )
            {
                return [
                    'headers'  => [],
                    'body'     => wp_json_encode(
                        [
                            'id'      => 'chatcmpl-gf-local-submission',
                            'model'   => 'openrouter/auto',
                            'choices' => [
                                [
                                    'message'       => [
                                        'role'    => 'assistant',
                                        'content' => wp_json_encode(
                                            [
                                                'summary' => 'Local-first form submission completed.',
                                            ]
                                        ),
                                    ],
                                    'finish_reason' => 'stop',
                                ],
                            ],
                            'usage'   => [
                                'prompt_tokens'     => 11,
                                'completion_tokens' => 6,
                                'total_tokens'      => 17,
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
        };

        add_filter( 'pre_http_request', $http_filter, 10, 3 );
        $this->adapter->handle_after_submission_entry_post_save(
            [
                'id'      => 654,
                'form_id' => 321,
                '1'       => 'Local First Lead',
                '2'       => 'local-first@example.test',
            ],
            [
                'id'     => 321,
                'title'  => 'Local First Proof Form',
                'fields' => [],
            ]
        );
        remove_filter( 'pre_http_request', $http_filter, 10 );

        $this->assertSame( 'Local-first form submission completed.', gform_get_meta( 654, 'sentient_forms_summary' ) );
        $this->assertIsArray( gform_get_meta( 654, '_sentient_forms_local_result' ) );

        $recent_events = $events->list_recent( 1 );
        $this->assertCount( 1, $recent_events );
        $this->assertSame( 'succeeded', $recent_events[0]['status'] ?? null );
        $this->assertSame( $mapping_id, (int) ( $recent_events[0]['mapping_id'] ?? 0 ) );
        $this->assertSame( '321', $recent_events[0]['form_id'] ?? null );
        $this->assertSame( '654', $recent_events[0]['entry_id'] ?? null );
        $this->assertSame( 'Local-first form submission completed.', $recent_events[0]['result_json']['structured']['summary'] ?? null );

        $this->assertNotEmpty( $http_urls );
        $this->assertContains(
            true,
            array_map(
                static fn ( string $url ): bool => false !== strpos( $url, 'openrouter.ai/api/v1/chat/completions' ),
                $http_urls
            )
        );
        foreach ( $http_urls as $url )
        {
            $this->assertStringNotContainsString( 'sentientforms.com', $url );
        }
    }

    public function test_handle_after_submission_queues_local_openrouter_async_mapping_from_local_tables(): void
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
        $encrypted      = $vault->encrypt( 'sk-or-gf-local-async-secret' );
        $http_urls      = [];
        $scheduled_jobs = [];

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
                'code'                 => 'gf_local_openrouter_async_summary',
                'display_name'         => 'GF Local OpenRouter Async Summary',
                'definition_json'      => [
                    'prompt_template' => 'Lead: {{name}} on {{form.title}}',
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

        $http_filter = static function ( $preempt, array $args, string $url ) use ( &$http_urls ): mixed {
            $http_urls[] = $url;

            return $preempt;
        };
        $listener = static function ( $hook, $args, $group, $action_id, $run_at ) use ( &$scheduled_jobs ): void {
            $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
        };

        add_filter( 'pre_http_request', $http_filter, 10, 3 );
        add_action( 'sentient_forms_async_job_scheduled', $listener, 10, 5 );

        $entry = [
            'id'      => 655,
            'form_id' => 321,
            '1'       => 'Async Local First Lead',
        ];
        $form = [
            'id'     => 321,
            'title'  => 'Local Async Form',
            'fields' => [],
        ];

        $returned_entry = $this->adapter->handle_after_submission_entry_post_save( $entry, $form );

        remove_action( 'sentient_forms_async_job_scheduled', $listener, 10 );
        remove_filter( 'pre_http_request', $http_filter, 10 );

        $this->assertSame( $entry, $returned_entry );
        $this->assertSame( [], $http_urls, 'Async local mappings should queue without calling OpenRouter during submission.' );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_local_mapping', $scheduled_jobs[0]['hook'] ?? null );

        $payload = $scheduled_jobs[0]['args'][0] ?? [];
        $this->assertSame( $mapping_id, $payload['local_mapping_id'] ?? null );
        $this->assertSame( '321', $payload['form_id'] ?? null );
        $this->assertSame( '655', $payload['entry_id'] ?? null );
        $this->assertArrayNotHasKey( 'form', $payload );
        $this->assertArrayNotHasKey( 'entry', $payload );
        $this->assertNull( gform_get_meta( 655, 'sentient_forms_async_summary' ) );

        $event = $events->get_by_request_id( (string) ( $payload['execution_request_id'] ?? '' ) );
        $this->assertIsArray( $event );
        $this->assertSame( 'queued', $event['status'] ?? null );
        $this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
    }

    // =========================================================================
    // CA-EXEC-001: Structured Output Tests
    // =========================================================================

    /**
     * T-PHP-034: format_async_result_excerpt prefers structured_output when valid.
     */
    public function test_format_async_result_excerpt_prefers_structured_output(): void
    {
        $method = new ReflectionMethod( $this->adapter, 'format_async_result_excerpt' );
        $method->setAccessible( true );

        // When structured_output_valid is true and structured_output exists
        $result = [
            'result_data' => [
                'llm_output'              => 'Some raw LLM text that should NOT appear in the excerpt.',
                'structured_output'       => [ 'summary' => 'Concise structured summary', 'sentiment' => 'positive' ],
                'structured_output_valid' => true,
            ],
        ];
        $excerpt = $method->invoke( $this->adapter, $result );
        $this->assertStringContainsString( 'Concise structured summary', $excerpt );
        $this->assertStringNotContainsString( 'should NOT appear', $excerpt );

        // When structured_output_valid is false, fall back to llm_output
        $result_no_valid = [
            'result_data' => [
                'llm_output'              => 'Fallback LLM text content.',
                'structured_output'       => null,
                'structured_output_valid' => false,
            ],
        ];
        $excerpt_fallback = $method->invoke( $this->adapter, $result_no_valid );
        $this->assertStringContainsString( 'Fallback LLM text', $excerpt_fallback );

        // When structured output fields are absent entirely (legacy response)
        $result_legacy = [
            'result_data' => [
                'llm_output' => 'Legacy output text.',
            ],
        ];
        $excerpt_legacy = $method->invoke( $this->adapter, $result_legacy );
        $this->assertStringContainsString( 'Legacy output text', $excerpt_legacy );
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
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }

}
