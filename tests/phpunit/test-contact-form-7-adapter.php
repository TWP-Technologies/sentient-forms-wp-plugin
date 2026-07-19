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
    final class Sentient_Forms_Test_Context_Tracking_Action extends Sentient_Forms_Local_Action_Execution_Service
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

        public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
        {
            $result = $this->execute(
                [
                    'form'              => $form,
                    'entry'             => $entry,
                    'hook'              => $context['hook'] ?? '',
                    'form_source'       => $context['form_source'] ?? '',
                    'execution_context' => $context,
                ],
                [],
                $entry['id'] ?? '',
                $form['id'] ?? ''
            );

            return is_bool( $result ) ? [ 'success' => $result ] : $result;
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

if ( ! class_exists( 'Sentient_Forms_Test_Context_Local_Execution_Service' ) )
{
    final class Sentient_Forms_Test_Context_Local_Execution_Service extends Sentient_Forms_Local_Action_Execution_Service
    {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function __construct( Sentient_Forms_Plugin $plugin )
        {
        }

        public function execute( string $central_action_id, array $form, array $entry, array $context = [] )
        {
            $this->calls[] = compact( 'central_action_id', 'form', 'entry', 'context' );

            return [
                'result_data' => [ 'summary' => 'Concrete Action completed.' ],
            ];
        }

        public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
        {
            return $this->execute( (string) ( $context['central_action_id'] ?? $mapping_id ), $form, $entry, $context );
        }
    }
}

if ( ! class_exists( 'Sentient_Forms_Test_CF7_Validation_Action' ) )
{
    final class Sentient_Forms_Test_CF7_Validation_Action extends Sentient_Forms_Local_Action_Execution_Service
    {
        /** @var callable */
        private $on_execute;

        public function __construct( private string $id, callable $on_execute )
        {
            $this->on_execute = $on_execute;
        }

        public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
        {
            return call_user_func(
                $this->on_execute,
                [
                    'form'              => $form,
                    'entry'             => $entry,
                    'hook'              => $context['hook'] ?? '',
                    'form_source'       => $context['form_source'] ?? '',
                    'execution_context' => $context,
                ],
                [],
                $entry['id'] ?? '',
                $form['id'] ?? ''
            );
        }

        public function get_id(): string
        {
            return $this->id;
        }

        public function get_name(): string
        {
            return 'CF7 validation fixture';
        }

        public function get_description(): string
        {
            return 'Exercises the public Contact Form 7 validation hooks.';
        }

        public function get_icon(): string
        {
            return 'dashicons-shield';
        }

        public function get_settings(): array
        {
            return [];
        }

        public function get_hooks(): array
        {
            return [ 'wpcf7_validate' ];
        }

        public function get_compatibility(): array
        {
            return [ 'contact_form_7' ];
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

if ( ! class_exists( 'Sentient_Forms_Test_CF7_Validation_Result' ) )
{
    final class Sentient_Forms_Test_CF7_Validation_Result
    {
        /** @var array<int, array{tag: object, message: string}> */
        public array $invalidations = [];

        public function invalidate( object $tag, string $message ): void
        {
            $this->invalidations[] = compact( 'tag', 'message' );
        }
    }
}

class Tests_Contact_Form_7_Adapter extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        remove_all_filters( 'sentient_forms_contact_form_7_forms' );
        remove_all_filters( 'sentient_forms_contact_form_7_form_object' );
        remove_all_filters( 'sentient_forms_contact_form_7_current_submission' );
        remove_all_actions( 'sentient_forms_async_job_scheduled' );
        remove_all_actions( 'wpcf7_before_send_mail' );
        remove_all_actions( 'wpcf7_mail_sent' );
        remove_all_filters( 'wpcf7_validate' );
        remove_all_filters( 'wpcf7_spam' );
        foreach ( [ '44', '47', '48', '49', '7951', '7952', '7953', '7954', '7955', '7956', '7957' ] as $form_id )
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

    public function test_cf7_content_validation_invalidates_matching_tag_through_two_argument_hook(): void
    {
        $form_id    = 7951;
        $action_id  = 'imported_content_validation_v1_cf7_fixture';
        $option_key = 'sentient_forms_actions_contact_form_7_' . $form_id;
        $executions = 0;
        $seen       = [];
        $tag        = $this->cf7_tag( 'textarea*', 'textarea', 'project-details' );
        $form       = $this->cf7_form( $form_id, 'CF7 Content Validation', [ $tag ] );
        $submission = $this->cf7_submission( $form, [ 'project-details' => 'test' ] );

        $action = new Sentient_Forms_Test_CF7_Validation_Action(
                $action_id,
                static function ( array $form_data ) use ( &$executions, &$seen ): array {
                    ++$executions;
                    $seen = $form_data;

                    return [
                        'result_data' => [
                            'structured_output_valid' => true,
                            'structured_output'       => [
                            'is_valid' => false,
                            'message'  => 'Please add useful project details.',
                            'fields'   => [
                                [
                                    'field_id' => 'project-details',
                                    'is_valid' => false,
                                    'message'  => 'Tell us what you need built.',
                                ],
                            ],
                            ],
                        ],
                    ];
                }
            );
        $runner = $this->configure_validation_mapping( $form_id, $action, [ 'marker' => 'validation-route' ] );
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter( 'sentient_forms_contact_form_7_current_submission', static fn() => $submission );
        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();

        global $wp_filter;
        $accepted_args = null;
        foreach ( (array) ( $wp_filter['wpcf7_validate']->callbacks[10] ?? [] ) as $callback )
        {
            if ( [ $adapter, 'handle_validation' ] === ( $callback['function'] ?? null ) )
            {
                $accepted_args = $callback['accepted_args'] ?? null;
                break;
            }
        }
        $result = apply_filters( 'wpcf7_validate', new Sentient_Forms_Test_CF7_Validation_Result(), [ $tag ] );

        $this->assertSame( 2, $accepted_args );
        $this->assertSame( 1, $executions );
        $this->assertSame( 'validation', $seen['hook'] ?? null );
        $this->assertSame( 'wpcf7_validate', $seen['execution_context']['native_hook'] ?? null );
        $this->assertSame( 'validation-route', $seen['execution_context']['settings']['marker'] ?? null );
        $this->assertSame( 'contact_form_7', $seen['form_source'] ?? null );
        $this->assertSame( 'test', $seen['entry']['project-details'] ?? null );
        $this->assertCount( 1, $result->invalidations );
        $this->assertSame( $tag, $result->invalidations[0]['tag'] ?? null );
        $this->assertSame( 'Tell us what you need built.', $result->invalidations[0]['message'] ?? null );
    }

    public function test_cf7_form_only_validation_error_leaves_field_result_unchanged(): void
    {
        $form_id     = 7956;
        $action_id   = 'imported_content_validation_v1_cf7_form_fixture';
        $submit_tag  = $this->cf7_tag( 'submit', 'submit', 'send' );
        $unnamed_tag = $this->cf7_tag( 'text', 'text', '' );
        $email_tag   = $this->cf7_tag( 'email*', 'email', 'your-email' );
        $form        = $this->cf7_form( $form_id, 'CF7 Form Validation', [ $submit_tag, $unnamed_tag, $email_tag ] );
        $submission  = $this->cf7_submission( $form, [ 'your-email' => 'visitor@example.test' ] );

        $action = new Sentient_Forms_Test_CF7_Validation_Action(
            $action_id,
            static fn(): array => [
                'result_data' => [
                    'structured_output_valid' => true,
                    'structured_output'       => [
                        'is_valid' => false,
                        'message'  => '<strong>Please review this submission.</strong>',
                        'fields'   => [],
                    ],
                ],
            ]
        );
        $runner = $this->configure_validation_mapping( $form_id, $action );
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter( 'sentient_forms_contact_form_7_current_submission', static fn() => $submission );
        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();

        $result = apply_filters(
            'wpcf7_validate',
            new Sentient_Forms_Test_CF7_Validation_Result(),
            [ $submit_tag, $unnamed_tag, $email_tag ]
        );

        $this->assertSame( [], $result->invalidations );
    }

    public function test_cf7_form_only_validation_error_leaves_unnamed_submit_only_result_unchanged(): void
    {
        $form_id    = 7957;
        $action_id  = 'imported_content_validation_v1_cf7_submit_fixture';
        $submit_tag = $this->cf7_tag( 'submit', 'submit', '' );
        $form       = $this->cf7_form( $form_id, 'CF7 Submit-only Validation', [ $submit_tag ] );
        $submission = $this->cf7_submission( $form, [] );

        $action = new Sentient_Forms_Test_CF7_Validation_Action(
            $action_id,
            static fn(): array => [
                'result_data' => [
                    'structured_output_valid' => true,
                    'structured_output'       => [
                        'is_valid' => false,
                        'message'  => 'Please review this submission.',
                        'fields'   => [],
                    ],
                ],
            ]
        );
        $runner = $this->configure_validation_mapping( $form_id, $action );
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter( 'sentient_forms_contact_form_7_current_submission', static fn() => $submission );
        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();

        $result = apply_filters( 'wpcf7_validate', new Sentient_Forms_Test_CF7_Validation_Result(), [ $submit_tag ] );

        $this->assertSame( [], $result->invalidations );
    }

    public function test_cf7_spam_hook_reuses_validation_outcome_without_second_execution(): void
    {
        $form_id    = 7952;
        $action_id  = 'imported_spam_detection_v1_cf7_spam_fixture';
        $option_key = 'sentient_forms_actions_contact_form_7_' . $form_id;
        $executions = 0;
        $tag        = $this->cf7_tag( 'text*', 'text', 'your-name' );
        $form       = $this->cf7_form( $form_id, 'CF7 Spam Validation', [ $tag ] );
        $submission = $this->cf7_submission( $form, [ 'your-name' => 'Buy now' ] );

        $action = new Sentient_Forms_Test_CF7_Validation_Action(
                $action_id,
                static function () use ( &$executions ): array {
                    ++$executions;

                    return [
                        'result_data' => [
                            'structured_output_valid' => true,
                            'structured_output'       => [
                                'classification' => 'spam',
                                'confidence'     => 0.99,
                                'justification'  => 'Known spam fixture.',
                                'indicators'     => [
                                    [
                                        'type'     => 'commercial_solicitation',
                                        'evidence' => 'Buy now',
                                        'weight'   => 'high',
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
            );
        $runner = $this->configure_validation_mapping( $form_id, $action );
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter( 'sentient_forms_contact_form_7_current_submission', static fn() => $submission );
        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();

        apply_filters( 'wpcf7_validate', new Sentient_Forms_Test_CF7_Validation_Result(), [ $tag ] );
        $spam = apply_filters( 'wpcf7_spam', false, $submission );

        $this->assertTrue( $spam );
        $this->assertSame( 1, $executions );
    }

    public function test_cf7_validation_failures_fail_open_and_descriptor_preserves_mail_sent_boundary(): void
    {
        $form_id    = 7953;
        $action_id  = 'cf7_validation_failure_fixture';
        $option_key = 'sentient_forms_actions_contact_form_7_' . $form_id;
        $tag        = $this->cf7_tag( 'email*', 'email', 'your-email' );
        $form       = $this->cf7_form( $form_id, 'CF7 Failure Validation', [ $tag ] );
        $submission = $this->cf7_submission( $form, [ 'your-email' => 'private@example.test' ] );

        $action = new Sentient_Forms_Test_CF7_Validation_Action(
                $action_id,
                static fn(): WP_Error => new WP_Error( 'provider_timeout', 'Private provider failure details.' )
            );
        $runner = $this->configure_validation_mapping( $form_id, $action );
        add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
        add_filter( 'sentient_forms_contact_form_7_current_submission', static fn() => $submission );
        $adapter = new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();

        $result     = apply_filters( 'wpcf7_validate', new Sentient_Forms_Test_CF7_Validation_Result(), [ $tag ] );
        $descriptor = $adapter->get_capability_descriptor();

        $this->assertSame( [], $result->invalidations );
        $this->assertFalse( apply_filters( 'wpcf7_spam', false, $submission ) );
        $this->assertTrue( apply_filters( 'wpcf7_spam', true, $submission ) );
        $this->assertTrue( $descriptor['lifecycles']['validation']['supported'] ?? false );
        $this->assertSame( 'wpcf7_validate', $descriptor['lifecycles']['validation']['native_hook'] ?? null );
        $this->assertSame( 'blocking', $descriptor['lifecycles']['validation']['execution_mode'] ?? null );
        $this->assertTrue( $descriptor['validation_effects']['submission_spam'] ?? false );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] ?? true );
        $this->assertSame( 'wpcf7_mail_sent', $adapter->get_accepted_submission_native_hook() );
        $this->assertSame( 10, has_action( 'wpcf7_mail_sent', [ $adapter, 'handle_mail_sent' ] ) );
    }

    public function test_cf7_ham_and_unstructured_validation_outcomes_preserve_existing_spam_state(): void
    {
        $cases = [
            7954 => [
                'result_data' => [
                    'structured_output_valid' => true,
                    'structured_output'       => [
                        'classification' => 'ham',
                        'confidence'     => 0.98,
                        'justification'  => 'Known legitimate fixture.',
                        'indicators'     => [],
                    ],
                ],
            ],
            7955 => [
                'content' => 'Unstructured provider response.',
            ],
        ];

        foreach ( $cases as $form_id => $action_result )
        {
            remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
            remove_all_filters( 'sentient_forms_contact_form_7_current_submission' );
            remove_all_filters( 'wpcf7_validate' );
            remove_all_filters( 'wpcf7_spam' );
            $tag        = $this->cf7_tag( 'text*', 'text', 'your-name' );
            $form       = $this->cf7_form( $form_id, 'CF7 Spam Preservation', [ $tag ] );
            $submission = $this->cf7_submission( $form, [ 'your-name' => 'Legitimate visitor' ] );
            $action_id  = 'imported_spam_detection_v1_cf7_preservation_' . $form_id;
            $action = new Sentient_Forms_Test_CF7_Validation_Action(
                    $action_id,
                    static fn(): array => $action_result
                );
            $runner = $this->configure_validation_mapping( $form_id, $action );
            add_filter( 'sentient_forms_contact_form_7_is_active', '__return_true' );
            add_filter( 'sentient_forms_contact_form_7_current_submission', static fn() => $submission );
            ( new Sentient_Forms_Contact_Form_7_Adapter( Sentient_Forms_Plugin::instance(), $runner ) )->init();

            apply_filters( 'wpcf7_validate', new Sentient_Forms_Test_CF7_Validation_Result(), [ $tag ] );

            $this->assertFalse( apply_filters( 'wpcf7_spam', false, $submission ) );
            $this->assertTrue( apply_filters( 'wpcf7_spam', true, $submission ) );
        }
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

        $actions   = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings  = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id = $actions->create(
            [
                'code'                 => 'fixture_source_neutral_action',
                'display_name'         => 'Fixture source-neutral action',
                'definition_json'      => [ 'prompt' => 'Evaluate {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );
        $mapping_id = $mappings->create(
            [
                'form_source' => 'fixture_forms', 'form_id' => '99', 'hook' => 'after_submission',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async', 'enabled' => true,
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

        $runner          = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [ 'native' => 'payload' ] );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_local_mapping', $scheduled_jobs[0]['hook'] ?? null );
        $payload = $scheduled_jobs[0]['args'][0] ?? [];
        $this->assertSame( $mapping_id, $payload['local_mapping_id'] ?? null );
        $this->assertSame( 'fixture_forms', $payload['context']['form_source'] ?? null );
        $this->assertSame( 'fixture_forms_submission_accepted', $payload['context']['hook'] ?? null );
        $this->assertSame( $submission_uuid, $payload['context']['submission_uuid'] ?? null );
        $this->assertSame( 'fixture-entry-99', $payload['context']['entry_id'] ?? null );
        $this->assertArrayNotHasKey( 'entry', $payload );

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'fixture-entry-99', $stored['native_entry_id'] ?? null );
        $this->assertSame( 'https://example.test/fixture-forms/entries/fixture-entry-99', $stored['native_entry_url'] ?? null );
        $this->assertSame( '2026-07-10 09:45:00', $stored['source_submitted_at'] ?? null );
        $this->assertSame( 'Fixture Form', $stored['provider_metadata_json']['form_name'] ?? null );
        $this->assertSame( 'Accepted fixture submission', $stored['logical_fields_json']['message'] ?? null );
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
        $action = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static fn (): array => [ 'classification' => 'ham' ]
        );
        $local_action_id = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->create(
            [
                'code'                 => $action_id,
                'display_name'         => 'Unsupported fixture effects',
                'definition_json'      => [ 'prompt' => 'Evaluate {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
            ]
        );
        $this->assertIsInt( $local_action_id );
        $mapping_id = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->create(
            [
                'form_source'         => 'fixture_forms',
                'form_id'             => '99',
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $local_action_id,
                'input_bindings_json' => [],
                'effect_mapping_json' => [
                    'store_result'                   => true,
                    'entry_note'                     => [ 'template' => 'Result: {{classification}}' ],
                    'mark_as_spam'                   => true,
                    'suppress_notifications_on_spam' => true,
                ],
                'execution_mode' => 'sync',
                'enabled'        => true,
            ]
        );
        $this->assertIsInt( $mapping_id );
        $result = ( new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance(), null, null, $action ) )
            ->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'unsupported-effects' ]
            );

        $outcomes = $result->get_native_effect_outcomes( 'local_first_' . $mapping_id );
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


    public function test_concrete_synchronous_action_forwards_distinct_mapping_context_to_local_execution_service(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $local_execution = new Sentient_Forms_Test_Context_Local_Execution_Service( Sentient_Forms_Plugin::instance() );

        $actions   = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings  = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id = $actions->create(
            [
                'code'                 => 'fixture_sync_context_action',
                'display_name'         => 'Fixture sync context action',
                'definition_json'      => [ 'prompt' => 'Evaluate {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_ids = [];
        foreach ( [ 'first', 'second' ] as $marker )
        {
            $mapping_id = $mappings->create(
                [
                    'form_source' => 'fixture_forms', 'form_id' => '99', 'hook' => 'after_submission',
                    'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                    'execution_mode' => 'sync', 'settings_json' => [ 'marker' => $marker ], 'enabled' => true,
                ]
            );
            $this->assertIsInt( $mapping_id );
            $mapping_ids[] = $mapping_id;
        }

        $runner          = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance(), null, null, $local_execution );
        $submission_uuid = $runner->run_accepted_submission(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            [ 'native' => 'payload' ]
        );
        $contexts = array_column( $local_execution->calls, 'context' );

        $this->assertCount( 2, $contexts );
        $this->assertSame( [ 'fixture_forms_submission_accepted', 'fixture_forms_submission_accepted' ], array_column( $contexts, 'hook' ) );
        $this->assertSame( [ 'fixture_forms', 'fixture_forms' ], array_column( $contexts, 'form_source' ) );
        $this->assertSame( [ 'local_first_' . $mapping_ids[0], 'local_first_' . $mapping_ids[1] ], array_column( $contexts, 'local_mapping_id' ) );
        $this->assertSame( [ 'first', 'second' ], array_column( array_column( $contexts, 'settings' ), 'marker' ) );
        $this->assertSame( [ $submission_uuid, $submission_uuid ], array_column( $contexts, 'submission_uuid' ) );
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
        $runner    = null;
        $action    = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function () use ( &$calls, &$reentered, &$runner ): array {
                ++$calls;
                if ( ! $reentered && $runner instanceof Sentient_Forms_Form_Source_Workflow_Runner )
                {
                    $reentered = true;
                    $runner->run_accepted_submission(
                        new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                        [ 'native' => 'active-replay' ]
                    );
                }

                return [ 'classification' => 'ham' ];
            }
        );
        $configured = $this->configure_accepted_mapping( $action_id, $action );
        $runner     = $configured['runner'];

        $first_uuid = $runner->run_accepted_submission(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            [ 'native' => 'first' ]
        );
        $replay_uuid = $runner->run_accepted_submission(
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

    public function test_synchronous_claim_rechecks_global_disable_inside_the_authority_fence(): void
    {
        global $wpdb;

        ( new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb ) )->set_enabled(
            'fixture_forms',
            '99',
            true,
            self::factory()->user->create( [ 'role' => 'administrator' ] )
        );
        $original_plugin_settings = get_option( 'sentient_forms_plugin_settings', false );
        $enabled_plugin_settings  = is_array( $original_plugin_settings ) ? $original_plugin_settings : [];
        $enabled_plugin_settings['execution_global_disabled'] = false;
        $enabled_plugin_settings['execution_provider_disabled'] = [];
        update_option( 'sentient_forms_plugin_settings', $enabled_plugin_settings, false );

        $calls     = 0;
        $action_id = 'fixture_disable_race';
        $action    = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function () use ( &$calls ): array {
                ++$calls;
                return [ 'classification' => 'must-not-run' ];
            }
        );
        $configured = $this->configure_accepted_mapping( $action_id, $action );
        $disable_at_claim = static function ( mixed $settings ): mixed {
            foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame )
            {
                if ( 'execute_claimed_synchronous_mapping' === ( $frame['function'] ?? '' ) )
                {
                    $settings = is_array( $settings ) ? $settings : [];
                    $settings['execution_global_disabled'] = true;
                    return $settings;
                }
            }

            return $settings;
        };
        add_filter( 'option_sentient_forms_plugin_settings', $disable_at_claim );
        try
        {
            $result = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'disable-race' ]
            );
        }
        finally
        {
            remove_filter( 'option_sentient_forms_plugin_settings', $disable_at_claim );
            if ( false === $original_plugin_settings )
            {
                delete_option( 'sentient_forms_plugin_settings' );
            }
            else
            {
                update_option( 'sentient_forms_plugin_settings', $original_plugin_settings, false );
            }
        }

        $this->assertSame( 0, $calls );
        $this->assertSame( 'failed', $result->get_mapping_outcomes()[ $configured['runtime_mapping_id'] ] ?? null );
        $this->assertSame(
            [],
            Sentient_Forms_Plugin::instance()->get_async_request_store()->list(
                [ 'record_type' => 'accepted_sync', 'limit' => 5 ]
            )
        );
    }

    public function test_synchronous_success_becomes_indeterminate_when_terminal_authority_cannot_be_persisted(): void
    {
        global $wpdb;

        ( new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb ) )->set_enabled(
            'fixture_forms',
            '99',
            true,
            self::factory()->user->create( [ 'role' => 'administrator' ] )
        );

        $original_plugin_settings = get_option( 'sentient_forms_plugin_settings', false );
        $enabled_plugin_settings  = is_array( $original_plugin_settings ) ? $original_plugin_settings : [];
        $enabled_plugin_settings['execution_global_disabled'] = false;
        $enabled_plugin_settings['execution_provider_disabled'] = [];
        update_option( 'sentient_forms_plugin_settings', $enabled_plugin_settings, false );

        $calls             = 0;
        $execution_context = [];
        $action_id         = 'fixture_terminal_authority_failure';
        $action            = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function ( array $form_data ) use ( &$calls, &$execution_context ): array {
                ++$calls;
                $execution_context = is_array( $form_data['execution_context'] ?? null )
                    ? $form_data['execution_context']
                    : [];
                return [ 'classification' => 'effect-applied' ];
            }
        );
        $configured = $this->configure_accepted_mapping( $action_id, $action );
        $request_table = $wpdb->prefix . 'sentient_async_requests';
        $failed_success_transition = false;
        $fail_success_transition = static function ( string $query ) use ( $request_table, &$failed_success_transition ): string {
            if (
                ! $failed_success_transition
                && str_contains( $query, $request_table )
                && 1 === preg_match( "/`?status`?\\s*=\\s*'success'/", $query )
            )
            {
                $failed_success_transition = true;
                return 'SENTIENT FORMS FORCED SYNCHRONOUS TERMINAL AUTHORITY FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_success_transition );
        $suppressed = $wpdb->suppress_errors( true );
        try
        {
            $result = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'terminal-authority-failure' ]
            );
        }
        finally
        {
            $wpdb->suppress_errors( $suppressed );
            remove_filter( 'query', $fail_success_transition );
            if ( false === $original_plugin_settings )
            {
                delete_option( 'sentient_forms_plugin_settings' );
            }
            else
            {
                update_option( 'sentient_forms_plugin_settings', $original_plugin_settings, false );
            }
        }

        $request_id = (string) ( $execution_context['execution_request_id'] ?? '' );
        $request    = Sentient_Forms_Plugin::instance()->get_async_request_store()->get( $request_id, 'accepted_sync' );
        $this->assertTrue( $failed_success_transition );
        $this->assertSame( 1, $calls );
        $this->assertSame( 'failed', $result->get_mapping_outcomes()[ $configured['runtime_mapping_id'] ] ?? null );
        $this->assertSame( 'indeterminate', $request['status'] ?? null );

        update_option( 'sentient_forms_plugin_settings', $enabled_plugin_settings, false );
        try
        {
            $replay = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'terminal-authority-failure-replay' ]
            );
        }
        finally
        {
            if ( false === $original_plugin_settings )
            {
                delete_option( 'sentient_forms_plugin_settings' );
            }
            else
            {
                update_option( 'sentient_forms_plugin_settings', $original_plugin_settings, false );
            }
        }
        $this->assertSame( 1, $calls );
        $this->assertSame( 'replayed_active', $replay->get_mapping_outcomes()[ $configured['runtime_mapping_id'] ] ?? null );
    }

    public function test_synchronous_terminal_authority_and_action_log_share_one_reset_fence(): void
    {
        global $wpdb;

        ( new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb ) )->set_enabled(
            'fixture_forms',
            '99',
            true,
            self::factory()->user->create( [ 'role' => 'administrator' ] )
        );
        delete_option( 'sentient_forms_action_log' );
        $original_plugin_settings = get_option( 'sentient_forms_plugin_settings', false );
        $enabled_plugin_settings  = is_array( $original_plugin_settings ) ? $original_plugin_settings : [];
        $enabled_plugin_settings['execution_global_disabled'] = false;
        $enabled_plugin_settings['execution_provider_disabled'] = [];
        update_option( 'sentient_forms_plugin_settings', $enabled_plugin_settings, false );

        $calls             = 0;
        $execution_context = [];
        $action_id         = 'fixture_sync_reset_log_fence';
        $action            = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function ( array $form_data ) use ( &$calls, &$execution_context ): array {
                ++$calls;
                $execution_context = is_array( $form_data['execution_context'] ?? null )
                    ? $form_data['execution_context']
                    : [];
                return [ 'classification' => 'effect-applied' ];
            }
        );
        $configured = $this->configure_accepted_mapping( $action_id, $action );
        $reset_result = null;
        $reset_at_unfenced_log_acquisition = static function ( mixed $timeout ) use ( &$reset_result ): mixed {
            if ( null !== $reset_result )
            {
                return $timeout;
            }
            foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame )
            {
                if (
                    'Sentient_Forms_Action_Log_Controller' === ( $frame['class'] ?? '' )
                    && 'log_execution' === ( $frame['function'] ?? '' )
                )
                {
                    $settings = get_option( 'sentient_forms_plugin_settings', [] );
                    $settings = is_array( $settings ) ? $settings : [];
                    $settings['execution_global_disabled'] = true;
                    update_option( 'sentient_forms_plugin_settings', $settings, false );
                    $reset_result = ( new Sentient_Forms_Local_Cutover_Service() )->approved_reset(
                        Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                        get_current_user_id()
                    );
                    break;
                }
            }

            return $timeout;
        };
        $reset_inside_log_write = static function ( mixed $value ) use ( &$reset_result ): mixed {
            if ( null === $reset_result )
            {
                $settings = get_option( 'sentient_forms_plugin_settings', [] );
                $settings = is_array( $settings ) ? $settings : [];
                $settings['execution_global_disabled'] = true;
                update_option( 'sentient_forms_plugin_settings', $settings, false );
                $reset_result = ( new Sentient_Forms_Local_Cutover_Service() )->approved_reset(
                    Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                    get_current_user_id()
                );
            }

            return $value;
        };
        add_filter( 'sentient_forms_action_authority_writer_lock_timeout', $reset_at_unfenced_log_acquisition );
        add_filter( 'pre_update_option_sentient_forms_action_log', $reset_inside_log_write );
        try
        {
            $result = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'sync-reset-log-fence' ]
            );
        }
        finally
        {
            remove_filter( 'sentient_forms_action_authority_writer_lock_timeout', $reset_at_unfenced_log_acquisition );
            remove_filter( 'pre_update_option_sentient_forms_action_log', $reset_inside_log_write );
            if ( false === $original_plugin_settings )
            {
                delete_option( 'sentient_forms_plugin_settings' );
            }
            else
            {
                update_option( 'sentient_forms_plugin_settings', $original_plugin_settings, false );
            }
        }

        $request_id = (string) ( $execution_context['execution_request_id'] ?? '' );
        $this->assertSame( 1, $calls );
        $this->assertSame( 'succeeded', $result->get_mapping_outcomes()[ $configured['runtime_mapping_id'] ] ?? null );
        $this->assertInstanceOf( WP_Error::class, $reset_result );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $reset_result->get_error_code() );
        $this->assertSame(
            'success',
            Sentient_Forms_Plugin::instance()->get_async_request_store()->get( $request_id, 'accepted_sync' )['status'] ?? null
        );
        $this->assertCount( 1, get_option( 'sentient_forms_action_log', [] ) );

        delete_option( 'sentient_forms_action_log' );
    }

    public function test_queued_accepted_identity_and_pending_log_share_one_reset_fence(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }
        ( new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb ) )->set_enabled(
            'fixture_forms',
            '99',
            true,
            self::factory()->user->create( [ 'role' => 'administrator' ] )
        );
        delete_option( 'sentient_forms_action_log' );
        $original_plugin_settings = get_option( 'sentient_forms_plugin_settings', false );
        $enabled_plugin_settings  = is_array( $original_plugin_settings ) ? $original_plugin_settings : [];
        $enabled_plugin_settings['execution_global_disabled'] = false;
        $enabled_plugin_settings['execution_provider_disabled'] = [];
        update_option( 'sentient_forms_plugin_settings', $enabled_plugin_settings, false );

        $action_id  = 'fixture_queued_reset_log_fence';
        $action     = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static fn (): array => [ 'classification' => 'queued-effect' ]
        );
        $configured = $this->configure_accepted_mapping(
            $action_id,
            $action,
            [ 'execution_mode' => 'async' ]
        );
        $reset_result = null;
        $reset_at_unfenced_log_acquisition = static function ( mixed $timeout ) use ( &$reset_result ): mixed {
            if ( null !== $reset_result )
            {
                return $timeout;
            }
            foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame )
            {
                if (
                    'Sentient_Forms_Action_Log_Controller' === ( $frame['class'] ?? '' )
                    && 'log_execution' === ( $frame['function'] ?? '' )
                )
                {
                    $settings = get_option( 'sentient_forms_plugin_settings', [] );
                    $settings = is_array( $settings ) ? $settings : [];
                    $settings['execution_global_disabled'] = true;
                    update_option( 'sentient_forms_plugin_settings', $settings, false );
                    $reset_result = ( new Sentient_Forms_Local_Cutover_Service() )->approved_reset(
                        Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                        get_current_user_id()
                    );
                    break;
                }
            }

            return $timeout;
        };
        $reset_inside_log_write = static function ( mixed $value ) use ( &$reset_result ): mixed {
            if ( null === $reset_result )
            {
                $settings = get_option( 'sentient_forms_plugin_settings', [] );
                $settings = is_array( $settings ) ? $settings : [];
                $settings['execution_global_disabled'] = true;
                update_option( 'sentient_forms_plugin_settings', $settings, false );
                $reset_result = ( new Sentient_Forms_Local_Cutover_Service() )->approved_reset(
                    Sentient_Forms_Local_Cutover_Service::CONFIRMATION_PHRASE,
                    get_current_user_id()
                );
            }

            return $value;
        };
        add_filter( 'sentient_forms_action_authority_writer_lock_timeout', $reset_at_unfenced_log_acquisition );
        add_filter( 'pre_update_option_sentient_forms_action_log', $reset_inside_log_write );
        try
        {
            $result = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'queued-reset-log-fence' ]
            );
        }
        finally
        {
            remove_filter( 'sentient_forms_action_authority_writer_lock_timeout', $reset_at_unfenced_log_acquisition );
            remove_filter( 'pre_update_option_sentient_forms_action_log', $reset_inside_log_write );
            if ( false === $original_plugin_settings )
            {
                delete_option( 'sentient_forms_plugin_settings' );
            }
            else
            {
                update_option( 'sentient_forms_plugin_settings', $original_plugin_settings, false );
            }
            if ( is_array( $reset_result ) && 'completed' === ( $reset_result['status'] ?? null ) )
            {
                Sentient_Forms_Installer::maybe_upgrade( true );
            }
        }

        $requests   = Sentient_Forms_Plugin::instance()->get_async_request_store()->list(
            [ 'record_type' => 'job', 'limit' => 5 ]
        );
        $request_id = (string) ( $requests[0]['request_hash'] ?? '' );
        $this->assertSame( 'queued', $result->get_mapping_outcomes()[ $configured['runtime_mapping_id'] ] ?? null );
        $this->assertInstanceOf( WP_Error::class, $reset_result );
        $this->assertSame( 'sentient_forms_action_authority_write_locked', $reset_result->get_error_code() );
        $this->assertSame(
            'queued',
            Sentient_Forms_Plugin::instance()->get_async_request_store()->get( $request_id, 'job' )['status'] ?? null
        );
        $this->assertCount( 1, get_option( 'sentient_forms_action_log', [] ) );

        delete_option( 'sentient_forms_action_log' );
    }

    public function test_synchronous_claim_sql_failure_is_reported_as_failed_not_active_replay(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }
        $wpdb->delete(
            $wpdb->prefix . 'sentient_async_requests',
            [ 'record_type' => 'accepted_sync' ],
            [ '%s' ]
        );
        ( new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb ) )->set_enabled(
            'fixture_forms',
            '99',
            true,
            self::factory()->user->create( [ 'role' => 'administrator' ] )
        );
        $calls     = 0;
        $action_id = 'fixture_claim_sql_failure';
        $action    = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function () use ( &$calls ): array {
                ++$calls;
                return [ 'classification' => 'must-not-run' ];
            }
        );
        $configured = $this->configure_accepted_mapping( $action_id, $action );
        $request_table = $wpdb->prefix . 'sentient_async_requests';
        $failed_insert = false;
        $fail_request_insert = static function ( string $query ) use ( $request_table, &$failed_insert ): string {
            if (
                ! $failed_insert
                && str_starts_with( ltrim( $query ), 'INSERT IGNORE' )
                && str_contains( $query, $request_table )
            )
            {
                $failed_insert = true;
                return 'SENTIENT FORMS FORCED ACCEPTED REQUEST INSERT FAILURE';
            }

            return $query;
        };
        add_filter( 'query', $fail_request_insert );
        $suppressed = $wpdb->suppress_errors( true );
        try
        {
            $result = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'claim-sql-failure' ]
            );
        }
        finally
        {
            $wpdb->suppress_errors( $suppressed );
            remove_filter( 'query', $fail_request_insert );
        }

        $execution_result = $result->get_execution_result( $configured['runtime_mapping_id'] );
        $this->assertTrue( $failed_insert );
        $this->assertSame( 0, $calls );
        $this->assertSame( 'failed', $result->get_mapping_outcomes()[ $configured['runtime_mapping_id'] ] ?? null );
        $this->assertInstanceOf( WP_Error::class, $execution_result );
        $this->assertSame( 'sentient_forms_async_request_persistence_failed', $execution_result->get_error_code() );
    }

    public function test_async_enqueue_and_failure_status_sql_errors_are_not_reported_as_active_replay(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }
        ( new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb ) )->set_enabled(
            'fixture_forms',
            '99',
            true,
            self::factory()->user->create( [ 'role' => 'administrator' ] )
        );

        $original_settings = get_option( 'sentient_forms_plugin_settings', false );
        $enabled_settings  = is_array( $original_settings ) ? $original_settings : [];
        $enabled_settings['execution_global_disabled'] = false;
        $enabled_settings['execution_provider_disabled'] = [];
        update_option( 'sentient_forms_plugin_settings', $enabled_settings, false );

        $action_id  = 'fixture_async_enqueue_status_failure';
        $action     = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static fn (): array => [ 'classification' => 'must-not-run-synchronously' ]
        );
        $configured = $this->configure_accepted_mapping(
            $action_id,
            $action,
            [ 'execution_mode' => 'async' ]
        );
        $request_table = $wpdb->prefix . 'sentient_async_requests';
        $failed_status_write = false;
        $reject_local_mapping_schedule = static function ( mixed $pre, int $timestamp, string $hook ): mixed {
            return 'sentient_forms_process_local_mapping' === $hook ? 0 : $pre;
        };
        $fail_status_write = static function ( string $query ) use ( $request_table, &$failed_status_write ): string {
            if (
                ! $failed_status_write
                && str_contains( $query, $request_table )
                && 1 === preg_match( "/`?status`?\\s*=\\s*'failed'/", $query )
            )
            {
                $failed_status_write = true;
                return 'SENTIENT FORMS FORCED ASYNC FAILURE STATUS WRITE FAILURE';
            }

            return $query;
        };

        add_filter( 'pre_as_schedule_single_action', $reject_local_mapping_schedule, 10, 3 );
        add_filter( 'query', $fail_status_write );
        $suppressed = $wpdb->suppress_errors( true );
        try
        {
            $result = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'enqueue-status-write-failure' ]
            );
            $replay = $configured['runner']->run_accepted_submission_with_outcome(
                new Sentient_Forms_Test_Accepted_Submission_Adapter(),
                [ 'native' => 'enqueue-status-write-failure-retry' ]
            );
        }
        finally
        {
            $wpdb->suppress_errors( $suppressed );
            remove_filter( 'pre_as_schedule_single_action', $reject_local_mapping_schedule, 10 );
            remove_filter( 'query', $fail_status_write );
            if ( false === $original_settings )
            {
                delete_option( 'sentient_forms_plugin_settings' );
            }
            else
            {
                update_option( 'sentient_forms_plugin_settings', $original_settings, false );
            }
        }

        $runtime_mapping_id = $configured['runtime_mapping_id'];
        $execution_result   = $result->get_execution_result( $runtime_mapping_id );
        $requests = Sentient_Forms_Plugin::instance()->get_async_request_store()->list(
            [ 'record_type' => 'job', 'limit' => 5 ]
        );
        $this->assertTrue( $failed_status_write );
        $this->assertSame( 'failed', $result->get_mapping_outcomes()[ $runtime_mapping_id ] ?? null );
        $this->assertInstanceOf( WP_Error::class, $execution_result );
        $this->assertSame( 'sentient_forms_async_request_persistence_failed', $execution_result->get_error_code() );
        $this->assertSame( 'failed', $replay->get_mapping_outcomes()[ $runtime_mapping_id ] ?? null );
        $this->assertNotSame( 'replayed_active', $replay->get_mapping_outcomes()[ $runtime_mapping_id ] ?? null );
        $this->assertSame( 'failed', $requests[0]['status'] ?? null );
    }

    public function test_synchronous_success_replay_preserves_stored_effect_outcomes_over_current_preflight(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $action_id = 'fixture_stable_effect_replay';
        $action = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static fn (): array => [ 'classification' => 'ham' ]
        );
        $configured = $this->configure_accepted_mapping(
            $action_id,
            $action,
            [
                'effect_mapping_json' => [
                    'entry_note' => [ 'template' => 'Result: {{classification}}' ],
                ],
            ]
        );

        $runner = $configured['runner'];
        $runtime_mapping_id = $configured['runtime_mapping_id'];
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

        $this->assertSame( 'replayed_success', $replay->get_mapping_outcomes()[ $runtime_mapping_id ] ?? null );
        $this->assertSame(
            [
                [
                    'effect' => 'entry_note',
                    'status' => 'applied',
                    'reason' => 'historical_native_application',
                ],
            ],
            $replay->get_native_effect_outcomes( $runtime_mapping_id )
        );
    }

    public function test_synchronous_accepted_execution_rejects_changed_settings_for_the_same_identity(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $calls           = 0;
        $dependent_calls = 0;
        $action_code     = 'fixture_digest_conflict';
        $dependent_code  = 'fixture_digest_conflict_dependent';
        $dependent_runtime_id = '';
        $execution       = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_code,
            static function ( array $form_data ) use ( &$calls, &$dependent_calls, &$dependent_runtime_id ): array {
                $mapping_id = (string) ( $form_data['execution_context']['mapping_id'] ?? '' );
                if ( '' !== $dependent_runtime_id && $dependent_runtime_id === $mapping_id )
                {
                    ++$dependent_calls;

                    return [ 'summary' => 'Dependent result.' ];
                }

                ++$calls;

                return [ 'classification' => 'ham' ];
            }
        );

        $parent_action_id = $this->create_local_test_action( $action_code );
        $parent_mapping_id = $this->create_local_test_mapping(
            $parent_action_id,
            [ 'settings_json' => [ 'marker' => 'first' ] ]
        );
        $parent_runtime_id = 'local_first_' . $parent_mapping_id;
        $runner = new Sentient_Forms_Form_Source_Workflow_Runner(
            Sentient_Forms_Plugin::instance(),
            null,
            null,
            $execution
        );
        $first = $runner->run_accepted_submission_with_outcome(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            []
        );

        $updated = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->update(
            $parent_mapping_id,
            [ 'settings_json' => [ 'marker' => 'changed' ] ]
        );
        $this->assertIsArray( $updated );
        $dependent_action_id = $this->create_local_test_action( $dependent_code );
        $dependent_mapping_id = $this->create_local_test_mapping(
            $dependent_action_id,
            [
                'settings_json' => [
                    'trigger_sources' => [
                        'after_submission' => [
                            'type'       => 'mapping',
                            'mapping_id' => $parent_runtime_id,
                        ],
                    ],
                ],
            ]
        );
        $dependent_runtime_id = 'local_first_' . $dependent_mapping_id;

        $conflict = $runner->run_accepted_submission_with_outcome(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            []
        );

        $this->assertSame( $first->get_submission_uuid(), $conflict->get_submission_uuid() );
        $this->assertSame( 1, $calls );
        $this->assertSame( 'digest_conflict', $conflict->get_mapping_outcomes()[ $parent_runtime_id ] ?? null );
        $error = $conflict->get_execution_result( $parent_runtime_id );
        $this->assertWPError( $error );
        $this->assertSame( 'sentient_forms_execution_digest_conflict', $error->get_error_code() );
        $this->assertSame( 'skipped', $conflict->get_mapping_outcomes()[ $dependent_runtime_id ] ?? null );
        $this->assertSame( 0, $dependent_calls );
    }

    public function test_async_accepted_execution_rejects_changed_settings_for_the_same_identity(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $parent_action_id = $this->create_local_test_action( 'fixture_async_digest_conflict' );
        $parent_mapping_id = $this->create_local_test_mapping(
            $parent_action_id,
            [
                'execution_mode' => 'async',
                'settings_json'  => [ 'marker' => 'first' ],
            ]
        );
        $parent_runtime_id = 'local_first_' . $parent_mapping_id;

        $runner = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $first  = $runner->run_accepted_submission_with_outcome(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            []
        );

        $updated = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->update(
            $parent_mapping_id,
            [ 'settings_json' => [ 'marker' => 'changed' ] ]
        );
        $this->assertIsArray( $updated );
        $dependent_action_id = $this->create_local_test_action( 'fixture_async_digest_conflict_dependent' );
        $dependent_mapping_id = $this->create_local_test_mapping(
            $dependent_action_id,
            [
                'execution_mode' => 'async',
                'settings_json'  => [
                    'trigger_sources' => [
                        'after_submission' => [
                            'type'       => 'mapping',
                            'mapping_id' => $parent_runtime_id,
                        ],
                    ],
                ],
            ]
        );
        $dependent_runtime_id = 'local_first_' . $dependent_mapping_id;

        $conflict = $runner->run_accepted_submission_with_outcome(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            []
        );

        $this->assertSame( $first->get_submission_uuid(), $conflict->get_submission_uuid() );
        $this->assertSame( 'digest_conflict', $conflict->get_mapping_outcomes()[ $parent_runtime_id ] ?? null );
        $error = $conflict->get_execution_result( $parent_runtime_id );
        $this->assertWPError( $error );
        $this->assertSame( 'sentient_forms_async_request_digest_conflict', $error->get_error_code() );
        $this->assertSame( 'skipped', $conflict->get_mapping_outcomes()[ $dependent_runtime_id ] ?? null );

        $jobs = Sentient_Forms_Plugin::instance()->get_async_request_store()->list( [ 'record_type' => 'job', 'limit' => 10 ] );
        $this->assertCount( 1, $jobs );
    }

    public function test_async_dependency_graph_replays_identical_business_payloads(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $primary_action_id = $this->create_local_test_action( 'fixture_async_replay_primary' );
        $primary_mapping_id = $this->create_local_test_mapping(
            $primary_action_id,
            [ 'execution_mode' => 'async' ]
        );
        $primary_runtime_id = 'local_first_' . $primary_mapping_id;

        $dependent_action_id = $this->create_local_test_action( 'fixture_async_replay_dependent' );
        $dependent_mapping_id = $this->create_local_test_mapping(
            $dependent_action_id,
            [
                'execution_mode' => 'async',
                'settings_json'  => [
                    'trigger_sources' => [
                        'after_submission' => [
                            'type'       => 'mapping',
                            'mapping_id' => $primary_runtime_id,
                        ],
                    ],
                ],
            ]
        );
        $dependent_runtime_id = 'local_first_' . $dependent_mapping_id;

        $runner  = new Sentient_Forms_Form_Source_Workflow_Runner( Sentient_Forms_Plugin::instance() );
        $adapter = new Sentient_Forms_Test_Accepted_Submission_Adapter();
        $first   = $runner->run_accepted_submission_with_outcome( $adapter, [] );
        $replay  = $runner->run_accepted_submission_with_outcome( $adapter, [] );

        $this->assertSame( 'queued', $first->get_mapping_outcomes()[ $primary_runtime_id ] ?? null );
        $this->assertSame( 'queued', $first->get_mapping_outcomes()[ $dependent_runtime_id ] ?? null );
        $this->assertSame( 'replayed_active', $replay->get_mapping_outcomes()[ $primary_runtime_id ] ?? null );
        $this->assertSame( 'replayed_active', $replay->get_mapping_outcomes()[ $dependent_runtime_id ] ?? null );

        $jobs = Sentient_Forms_Plugin::instance()->get_async_request_store()->list( [ 'record_type' => 'job', 'limit' => 10 ] );
        $this->assertCount( 2, $jobs );
    }

    public function test_required_ledger_capture_failure_prevents_accepted_execution(): void
    {
        global $wpdb;

        $capture_service = new class( $wpdb ) extends Sentient_Forms_Submission_Ledger_Capture_Service {
            public function capture( array $payload ): array | WP_Error
            {
                return new WP_Error(
                    'sentient_forms_db_insert_failed',
                    'The required Submission Ledger record could not be created.'
                );
            }
        };
        $runner = new Sentient_Forms_Form_Source_Workflow_Runner(
            Sentient_Forms_Plugin::instance(),
            $capture_service
        );

        $result = $runner->run_accepted_submission_with_outcome(
            new Sentient_Forms_Test_Accepted_Submission_Adapter(),
            []
        );

        $this->assertNull( $result->get_submission_uuid() );
        $this->assertSame( [], $result->get_mapping_outcomes() );
    }

    public function test_synchronous_accepted_failure_is_terminal_without_explicit_safe_retry(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $calls     = 0;
        $action_id = 'fixture_durable_failure';
        $action    = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function () use ( &$calls ): WP_Error {
                ++$calls;

                return new WP_Error( 'fixture_provider_failed', 'Provider failure details.' );
            }
        );
        $runner = $this->configure_accepted_mapping( $action_id, $action )['runner'];

        $first_uuid  = $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
        $replay_uuid = $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );

        $this->assertSame( $first_uuid, $replay_uuid );
        $this->assertSame( 1, $calls );
        $this->assertCount( 1, get_option( 'sentient_forms_action_log', [] ) );
        $events = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->list_for_submission_uuid( (string) $first_uuid );
        $this->assertCount( 1, $events );
        $this->assertSame( 'failed', $events[0]['status'] ?? null );
    }

    public function test_synchronous_accepted_exception_is_recorded_as_terminal_failure(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $action_id = 'fixture_throwing_action';
        $action = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function (): never {
                throw new RuntimeException( 'Private provider exception details.' );
            }
        );
        $configured = $this->configure_accepted_mapping( $action_id, $action );
        $runtime_mapping_id = $configured['runtime_mapping_id'];

        $outcome = $configured['runner']
            ->run_accepted_submission_with_outcome( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );

        $this->assertSame( 'failed', $outcome->get_mapping_outcomes()[ $runtime_mapping_id ] ?? null );
        $error = $outcome->get_execution_result( $runtime_mapping_id );
        $this->assertWPError( $error );
        $this->assertSame( 'sentient_forms_synchronous_execution_exception', $error->get_error_code() );

        $logs = get_option( 'sentient_forms_action_log', [] );
        $this->assertCount( 1, $logs );
        $this->assertSame( 'sentient_forms_synchronous_execution_exception', $logs[0]['error_code'] ?? null );
        $this->assertStringNotContainsString( 'Private provider', wp_json_encode( $logs ) );

        $execution_request_id = (string) ( $logs[0]['execution_request_id'] ?? '' );
        $request = Sentient_Forms_Plugin::instance()->get_async_request_store()->get( $execution_request_id, 'accepted_sync' );
        $this->assertSame( 'failed', $request['status'] ?? null );
        $this->assertStringNotContainsString( 'Private provider', (string) ( $request['last_error'] ?? '' ) );

        $event = ( new Sentient_Forms_Execution_Events_Repository( $wpdb ) )->get_by_request_id( $execution_request_id );
        $this->assertSame( 'failed', $event['status'] ?? null );
        $this->assertSame( 'sentient_forms_synchronous_execution_exception', $event['error_code'] ?? null );
        $this->assertStringNotContainsString( 'Private provider', (string) ( $event['error_message'] ?? '' ) );
    }

    public function test_synchronous_accepted_failure_retries_only_when_explicitly_safe(): void
    {
        global $wpdb;

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'fixture_forms', '99', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        delete_option( 'sentient_forms_action_log' );
        $calls     = 0;
        $action_id = 'fixture_safe_retry';
        $action    = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static function () use ( &$calls ): array | WP_Error {
                ++$calls;

                return 1 === $calls
                    ? new WP_Error( 'fixture_retryable_failure', 'Retryable provider failure.' )
                    : [ 'classification' => 'ham' ];
            }
        );
        $runner = $this->configure_accepted_mapping(
            $action_id,
            $action,
            [
                'settings_json' => [
                    'synchronous_retry_safe' => true,
                ],
            ]
        )['runner'];

        $first_uuid = $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
        $retry_uuid = $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );

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
        $action = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static fn (): array => [
                'classification'    => 'ham',
                'content'           => 'RAW_PROVIDER_SECRET_92A',
                'llm_output'        => 'RAW_MODEL_OUTPUT_17B',
                'model'             => 'private-model-route-44C',
                'submitted_content' => 'PRIVATE_SUBMISSION_63D',
                'result_data'       => [ 'llm_output' => 'NESTED_RAW_OUTPUT_81E' ],
            ]
        );
        $runner = $this->configure_accepted_mapping( $action_id, $action )['runner'];

        $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
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
        $action = new Sentient_Forms_Test_Context_Tracking_Action(
            $action_id,
            static fn (): WP_Error => new WP_Error(
                'provider_HTTP_error!',
                'RAW_PROVIDER_ERROR_71D included PRIVATE_SUBMISSION_82F and RAW_MODEL_OUTPUT_93G.'
            )
        );
        $runner = $this->configure_accepted_mapping( $action_id, $action )['runner'];

        $runner->run_accepted_submission( new Sentient_Forms_Test_Accepted_Submission_Adapter(), [] );
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

        $actions   = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings  = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id = $actions->create(
            [
                'code'                 => 'cf7_ledger_gate_action',
                'display_name'         => 'CF7 ledger gate action',
                'definition_json'      => [ 'prompt' => 'Summarize {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );
        $mapping_id = $mappings->create(
            [
                'form_source' => 'contact_form_7', 'form_id' => '44', 'hook' => 'wpcf7_mail_sent',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async', 'enabled' => true,
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
        $submission_uuid = $scheduled_jobs[0]['args'][0]['submission_uuid'] ?? null;

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );

        $this->assertSame( 'sentient_forms_process_local_mapping', $scheduled_jobs[0]['hook'] ?? null );
        $this->assertSame( 'sentient_forms_async', $scheduled_jobs[0]['group'] ?? null );

        $payload     = $scheduled_jobs[0]['args'][0] ?? [];
        $job_context = $payload['context'] ?? [];
        $this->assertSame( 'contact_form_7', $job_context['form_source'] ?? null );
        $this->assertSame( 'wpcf7_mail_sent', $job_context['hook'] ?? null );
        $this->assertSame( '44', $job_context['form_id'] ?? null );
        $this->assertNull( $job_context['entry_id'] ?? null );
        $this->assertSame( 'local_first_' . $mapping_id, $job_context['local_mapping_id'] ?? null );
        $this->assertSame( 'cf7_ledger_gate_action', $job_context['central_action_id'] ?? null );
        $this->assertSame( $submission_uuid, $job_context['submission_uuid'] ?? null );
        $this->assertSame( $mapping_id, $payload['local_mapping_id'] ?? null );
        $this->assertArrayNotHasKey( 'entry', $payload );
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

        $actions   = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings  = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $action_id = $actions->create(
            [
                'code' => 'cf7_dependency_fixture', 'display_name' => 'CF7 dependency fixture',
                'definition_json' => [ 'prompt' => 'Summarize {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status' => 'active',
            ]
        );
        $this->assertIsInt( $action_id );
        $first_id = $mappings->create(
            [
                'form_source' => 'contact_form_7', 'form_id' => '47', 'hook' => 'wpcf7_mail_sent',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async', 'enabled' => true,
            ]
        );
        $this->assertIsInt( $first_id );
        $first_key = 'local_first_' . $first_id;
        $second_id = $mappings->create(
            [
                'form_source' => 'contact_form_7', 'form_id' => '47', 'hook' => 'wpcf7_mail_sent',
                'action_kind' => 'custom_action', 'action_id' => $action_id, 'input_bindings_json' => [],
                'execution_mode' => 'async',
                'settings_json' => [
                    'trigger_sources' => [ 'after_submission' => [ 'type' => 'mapping', 'mapping_id' => $first_key ] ],
                    'batch_settings' => [ 'max_wait_seconds' => 45 ],
                ],
                'enabled' => true,
            ]
        );
        $this->assertIsInt( $second_id );
        $second_key = 'local_first_' . $second_id;

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
            $context = $job['args'][0]['context'] ?? [];
            if ( is_array( $context ) && isset( $context['local_mapping_id'] ) )
            {
                $contexts[ $context['local_mapping_id'] ] = $context;
            }
        }

        $this->assertArrayHasKey( $first_key, $contexts );
        $this->assertArrayHasKey( $second_key, $contexts );

        $this->assertSame( [ $first_key ], $contexts[ $second_key ]['dependency_mapping_ids'] ?? null );
        $this->assertSame( 'queued', $contexts[ $second_key ]['dependency_initial_outcomes'][ $first_key ] ?? null );
        $this->assertSame(
            $contexts[ $first_key ]['execution_request_id'] ?? null,
            $contexts[ $second_key ]['dependency_execution_request_ids'][ $first_key ] ?? null
        );
        $this->assertSame( 45, $contexts[ $second_key ]['dependency_wait_max_seconds'] ?? null );
        $this->assertSame( 10, $contexts[ $second_key ]['dependency_wait_poll_seconds'] ?? null );
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

        $first_mapping_id = $mappings->create(
            [
                'form_source'         => 'contact_form_7',
                'form_id'             => '48',
                'hook'                => 'wpcf7_mail_sent',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $first_mapping_id );
        $first_mapping_key = 'local_first_' . $first_mapping_id;

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
                            'mapping_id' => $first_mapping_key,
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
            $payload = $job['args'][0] ?? [];
            $context = is_array( $payload ) ? ( $payload['context'] ?? [] ) : [];

            if ( is_array( $context ) && isset( $context['local_mapping_id'] ) )
            {
                $contexts[ $context['local_mapping_id'] ] = $context;
            }
        }

        $this->assertArrayHasKey( $first_mapping_key, $contexts );
        $this->assertArrayHasKey( $local_mapping_key, $contexts );

        $this->assertSame( [ $first_mapping_key ], $contexts[ $local_mapping_key ]['dependency_mapping_ids'] ?? null );
        $this->assertSame( 'queued', $contexts[ $local_mapping_key ]['dependency_initial_outcomes'][ $first_mapping_key ] ?? null );
        $this->assertSame(
            $contexts[ $first_mapping_key ]['execution_request_id'] ?? null,
            $contexts[ $local_mapping_key ]['dependency_execution_request_ids'][ $first_mapping_key ] ?? null
        );
        $this->assertSame( 70, $contexts[ $local_mapping_key ]['dependency_wait_max_seconds'] ?? null );
        $this->assertSame( 10, $contexts[ $local_mapping_key ]['dependency_wait_poll_seconds'] ?? null );
    }

    private function create_local_test_action( string $code, string $display_name = 'Fixture Action' ): int
    {
        global $wpdb;

        $action_id = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->create(
            [
                'code'                 => $code,
                'display_name'         => $display_name,
                'definition_json'      => [ 'prompt' => 'Evaluate {{entry}}.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        return $action_id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function create_local_test_mapping( int $action_id, array $overrides = [] ): int
    {
        global $wpdb;

        $mapping_id = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->create(
            array_replace(
                [
                    'form_source'         => 'fixture_forms',
                    'form_id'             => '99',
                    'hook'                => 'after_submission',
                    'action_kind'         => 'custom_action',
                    'action_id'           => $action_id,
                    'input_bindings_json' => [],
                    'execution_mode'      => 'sync',
                    'enabled'             => true,
                ],
                $overrides
            )
        );
        $this->assertIsInt( $mapping_id );

        return $mapping_id;
    }

    /**
     * @param array<string, mixed> $mapping_overrides
     *
     * @return array{runner: Sentient_Forms_Form_Source_Workflow_Runner, mapping_id: int, runtime_mapping_id: string}
     */
    private function configure_accepted_mapping(
        string $action_code,
        Sentient_Forms_Local_Action_Execution_Service $execution_service,
        array $mapping_overrides = []
    ): array
    {
        $action_id  = $this->create_local_test_action( $action_code );
        $mapping_id = $this->create_local_test_mapping( $action_id, $mapping_overrides );

        return [
            'runner'             => new Sentient_Forms_Form_Source_Workflow_Runner(
                Sentient_Forms_Plugin::instance(),
                null,
                null,
                $execution_service
            ),
            'mapping_id'         => $mapping_id,
            'runtime_mapping_id' => 'local_first_' . $mapping_id,
        ];
    }

    private function configure_validation_mapping(
        int $form_id,
        Sentient_Forms_Test_CF7_Validation_Action $action,
        array $settings = []
    ): Sentient_Forms_Form_Source_Workflow_Runner
    {
        global $wpdb;

        $action_id = ( new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb ) )->create(
            [
                'code'                 => $action->get_id(),
                'display_name'         => $action->get_name(),
                'definition_json'      => [ 'prompt' => 'Validation fixture.' ],
                'model_selection_json' => [ 'provider' => 'openrouter', 'model' => 'openrouter/auto' ],
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = ( new Sentient_Forms_Form_Mappings_Repository( $wpdb ) )->create(
            [
                'form_source'         => 'contact_form_7',
                'form_id'             => (string) $form_id,
                'hook'                => 'validation',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [],
                'execution_mode'      => 'sync',
                'settings_json'       => $settings,
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        return new Sentient_Forms_Form_Source_Workflow_Runner(
            Sentient_Forms_Plugin::instance(),
            null,
            null,
            $action
        );
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

    private function cf7_submission( object $form, array $posted_data ): object
    {
        return new class( $form, $posted_data ) {
            public function __construct( private object $form, private array $posted_data )
            {
            }

            public function get_contact_form(): object
            {
                return $this->form;
            }

            public function get_posted_data(): array
            {
                return $this->posted_data;
            }
        };
    }
}
