<?php

if ( ! class_exists( 'Sentient_Forms_Test_WPForms_Validation_Action' ) )
{
    final class Sentient_Forms_Test_WPForms_Validation_Action extends Sentient_Forms_Local_Action_Execution_Service
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
            return 'WPForms validation fixture';
        }

        public function get_description(): string
        {
            return 'Exercises the public WPForms validation hook.';
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
            return [ 'wpforms_process' ];
        }

        public function get_compatibility(): array
        {
            return [ 'wpforms' ];
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

if ( ! class_exists( 'Sentient_Forms_Test_WPForms_Validation_Adapter_Spy' ) )
{
    final class Sentient_Forms_Test_WPForms_Validation_Adapter_Spy extends Sentient_Forms_WPForms_Adapter
    {
        public int $native_spam_calls = 0;

        public function mark_entry_as_spam( mixed $entry_id ): bool
        {
            ++$this->native_spam_calls;

            return true;
        }
    }
}

class Tests_WPForms_Adapter extends WP_UnitTestCase
{
    private bool $created_wpforms_entries_table = false;

    private ?Sentient_Forms_Test_WPForms_Validation_Action $validation_executor = null;

    protected function tearDown(): void
    {
        $this->validation_executor = null;
        remove_all_filters( 'sentient_forms_wpforms_is_active' );
        remove_all_filters( 'sentient_forms_wpforms_native_entry_available' );
        remove_all_filters( 'sentient_forms_wpforms_hidden_field_storage_allowlist' );
        remove_all_filters( 'sentient_forms_wpforms_object' );
        remove_all_actions( 'sentient_forms_async_job_scheduled' );
        remove_all_actions( 'wpforms_process' );
        remove_all_actions( 'wpforms_process_complete' );

        foreach ( [ 44, 48, 49, 50, 7956, 7957, 7958, 7959, 7960, 7962 ] as $form_id )
        {
            delete_option( 'sentient_forms_actions_wpforms_' . $form_id );
        }

        global $wpdb;
        $wpforms_entries_table = $wpdb->prefix . 'wpforms_entries';
        if ( $this->wpforms_entries_table_exists() )
        {
            $wpdb->delete( $wpforms_entries_table, [ 'entry_id' => 779 ], [ '%d' ] );
        }

        if ( $this->created_wpforms_entries_table )
        {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpforms_entries_table}" );
            $this->created_wpforms_entries_table = false;
        }

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

    public function test_wpforms_exposes_only_process_complete_as_accepted_submission_hook(): void
    {
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );

        $this->assertInstanceOf( Sentient_Forms_Accepted_Submission_Adapter_Interface::class, $adapter );
        $this->assertSame( 'wpforms_process_complete', $adapter->get_accepted_submission_native_hook() );

        $adapter->init();

        $this->assertSame( 1, has_action( 'wpforms_process', [ $adapter, 'handle_validation' ] ) );
        $this->assertSame( 10, has_action( 'wpforms_process_complete', [ $adapter, 'handle_process_complete' ] ) );
        $this->assertFalse( has_action( 'wpforms_process', [ $adapter, 'handle_process_complete' ] ) );
    }

    public function test_wpforms_content_validation_writes_matching_field_and_header_errors_through_three_argument_hook(): void
    {
        $form_id    = 7956;
        $action_id  = 'wpforms_content_validation_fixture';
        $option_key = 'sentient_forms_actions_wpforms_' . $form_id;
        $executions = 0;
        $seen       = [];
        $process    = (object) [ 'errors' => [] ];
        $fields     = $this->validation_fields();
        $entry      = [ 'fields' => [ 1 => 'Ada Lovelace', 2 => 'test' ], 'source' => 'frontend' ];
        $form_data  = $this->validation_form_data( $form_id );
        $untrusted_form_error = 'Please <strong>review</strong> <script>alert(1)</script> your submission.';

        $this->configure_validation_mapping(
            $form_id,
            new Sentient_Forms_Test_WPForms_Validation_Action(
                $action_id,
                static function ( array $form_data ) use ( &$executions, &$seen, $untrusted_form_error ): array {
                    ++$executions;
                    $seen = $form_data;

                    return [
                        'validation' => [
                            'is_valid' => false,
                            'message'  => $untrusted_form_error,
                            'fields'   => [
                                [
                                    'field_id' => '2',
                                    'is_valid' => false,
                                    'message'  => 'Tell us what you need built.',
                                ],
                            ],
                        ],
                    ];
                }
            )
        );
        $adapter = $this->initialize_validation_adapter( $process );

        global $wp_filter;
        $accepted_args = null;
        foreach ( (array) ( $wp_filter['wpforms_process']->callbacks[1] ?? [] ) as $callback )
        {
            if ( [ $adapter, 'handle_validation' ] === ( $callback['function'] ?? null ) )
            {
                $accepted_args = $callback['accepted_args'] ?? null;
                break;
            }
        }
        do_action( 'wpforms_process', $fields, $entry, $form_data );

        $this->assertSame( 3, $accepted_args );
        $this->assertSame( 1, $executions );
        $this->assertSame( 'validation', $seen['hook'] ?? null );
        $this->assertSame( 'wpforms_process', $seen['native_hook'] ?? null );
        $this->assertSame( 'wpforms', $seen['form_source'] ?? null );
        $this->assertSame( 'test', $seen['entry']['project_details'] ?? null );
        $this->assertSame( [ 'source' ], $seen['execution_context']['native_validation_context']['entry_keys'] ?? null );
        $this->assertSame( 'Tell us what you need built.', $process->errors[ $form_id ][2] ?? null );
        $this->assertSame( sanitize_text_field( $untrusted_form_error ), $process->errors[ $form_id ]['header'] ?? null );
        $this->assertStringNotContainsString( '<', (string) ( $process->errors[ $form_id ]['header'] ?? '' ) );
    }

    public function test_wpforms_native_form_error_boundary_sanitizes_untrusted_html(): void
    {
        $form_id              = 7961;
        $process              = (object) [ 'errors' => [] ];
        $adapter              = $this->initialize_validation_adapter( $process );
        $untrusted_form_error = 'Please <strong>review</strong> <script>alert(1)</script> your submission.';
        $result               = new Sentient_Forms_Validation_Run_Result(
            [],
            [],
            [],
            [],
            $untrusted_form_error
        );

        $adapter->apply_validation_result(
            [
                'fields'    => $this->validation_fields(),
                'form_data' => $this->validation_form_data( $form_id ),
            ],
            $result
        );

        $header = (string) ( $process->errors[ $form_id ]['header'] ?? '' );
        $this->assertSame( sanitize_text_field( $untrusted_form_error ), $header );
        $this->assertStringNotContainsString( '<', $header );
    }

    public function test_wpforms_validation_blocks_priority_ten_payment_callbacks_before_they_charge(): void
    {
        $form_id       = 7960;
        $action_id     = 'wpforms_payment_order_validation_fixture';
        $process       = (object) [ 'errors' => [] ];
        $payment_count = 0;

        $this->configure_validation_mapping(
            $form_id,
            new Sentient_Forms_Test_WPForms_Validation_Action(
                $action_id,
                static fn(): array => [
                    'validation' => [
                        'is_valid' => false,
                        'message'  => 'Payment must not run for an invalid submission.',
                        'fields'   => [],
                    ],
                ]
            )
        );

        add_action(
            'wpforms_process',
            static function () use ( &$payment_count, $process ): void {
                if ( empty( $process->errors ) )
                {
                    ++$payment_count;
                }
            },
            10,
            3
        );

        $adapter = $this->initialize_validation_adapter( $process );

        do_action( 'wpforms_process', $this->validation_fields(), [], $this->validation_form_data( $form_id ) );

        $this->assertNotEmpty( $process->errors[ $form_id ]['header'] ?? '' );
        $this->assertSame( 0, $payment_count );
        $this->assertSame( 1, has_action( 'wpforms_process', [ $adapter, 'handle_validation' ] ) );
        $this->assertSame( 10, has_action( 'wpforms_process_complete', [ $adapter, 'handle_process_complete' ] ) );
    }

    public function test_wpforms_spam_validation_blocks_with_header_error_without_native_spam_state(): void
    {
        $form_id    = 7957;
        $action_id  = 'spam_analysis';
        $process    = (object) [ 'errors' => [] ];
        $executions = 0;
        $this->configure_validation_mapping(
            $form_id,
            new Sentient_Forms_Test_WPForms_Validation_Action(
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
                                        'evidence' => 'Known spam fixture.',
                                        'weight'   => 'high',
                                    ],
                                ],
                            ],
                        ],
                    ];
                }
            )
        );
        $adapter = $this->initialize_validation_adapter( $process, true );

        do_action( 'wpforms_process', $this->validation_fields(), [], $this->validation_form_data( $form_id ) );
        $descriptor = $adapter->get_capability_descriptor();

        $this->assertSame( 1, $executions );
        $this->assertNotEmpty( $process->errors[ $form_id ]['header'] ?? '' );
        $this->assertSame( 0, $adapter->native_spam_calls );
        $this->assertFalse( $descriptor['native_enrichment']['spam'] ?? true );
        $this->assertFalse( $descriptor['validation_effects']['submission_spam'] ?? true );
        $this->assertFalse( $descriptor['native_entry']['write'] ?? true );
    }

    public function test_wpforms_provider_and_unstructured_failures_leave_process_errors_unchanged(): void
    {
        $cases = [
            7958 => new WP_Error( 'provider_timeout', 'Private provider timeout details.' ),
            7959 => [ 'content' => 'Unstructured provider response.' ],
            7960 => [
                'result_data' => [
                    'structured_output_valid' => false,
                    'structured_output'       => [
                        'is_valid' => false,
                        'message'  => 'Untrusted validation output must not block.',
                        'fields'   => [
                            [
                                'field_id' => '9',
                                'message'  => 'Untrusted field error.',
                            ],
                        ],
                    ],
                ],
            ],
            7962 => [
                'result_data' => [
                    'structured_output_valid' => true,
                    'structured_output'       => [
                        'classification' => 'spam',
                        'confidence'     => 0.99,
                        'justification'  => 'Wrong schema for this custom validation Action.',
                        'indicators'     => [],
                    ],
                ],
            ],
        ];

        foreach ( $cases as $form_id => $action_result )
        {
            remove_all_actions( 'wpforms_process' );
            remove_all_filters( 'sentient_forms_wpforms_is_active' );
            remove_all_filters( 'sentient_forms_wpforms_object' );
            $process   = (object) [ 'errors' => [ $form_id => [ 9 => 'Existing WPForms error.' ] ] ];
            $action_id = 'wpforms_fail_open_' . $form_id;
            $this->configure_validation_mapping(
                $form_id,
                new Sentient_Forms_Test_WPForms_Validation_Action(
                    $action_id,
                    static fn(): WP_Error | array => $action_result
                )
            );
            $adapter = $this->initialize_validation_adapter( $process );
            $before  = $process->errors;

            do_action( 'wpforms_process', $this->validation_fields(), [], $this->validation_form_data( $form_id ) );

            $this->assertSame( $before, $process->errors );
            $this->assertSame( 'wpforms_process_complete', $adapter->get_accepted_submission_native_hook() );
            $this->assertSame( 10, has_action( 'wpforms_process_complete', [ $adapter, 'handle_process_complete' ] ) );
        }
    }

    public function test_wpforms_field_manifest_marks_logical_hidden_and_file_fields(): void
    {
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $form_id = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'Partner Intake',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 42,
                        'settings' => [
                            'form_title' => 'Partner Intake',
                        ],
                        'fields'   => [
                            1 => [
                                'id'       => 1,
                                'type'     => 'name',
                                'label'    => 'Full Name',
                                'required' => '1',
                            ],
                            2 => [
                                'id'    => 2,
                                'type'  => 'email',
                                'label' => 'Email',
                            ],
                            3 => [
                                'id'    => 3,
                                'type'  => 'hidden',
                                'label' => 'Campaign Code',
                            ],
                            4 => [
                                'id'    => 4,
                                'type'  => 'file-upload',
                                'label' => 'Resume',
                            ],
                            5 => [
                                'id'    => 5,
                                'type'  => 'html',
                                'label' => 'Intro Copy',
                            ],
                        ],
                    ]
                ),
            ]
        );

        $adapter = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertCount( 4, $fields );

        $this->assertSame( '1', $fields[0]['id'] );
        $this->assertSame( 'Full Name', $fields[0]['label'] );
        $this->assertSame( 'name', $fields[0]['type'] );
        $this->assertSame( 'WPForms: Full Name', $fields[0]['adminLabel'] );
        $this->assertSame( 'visible', $fields[0]['visibility'] );
        $this->assertTrue( $fields[0]['storage_eligible'] );
        $this->assertFalse( $fields[0]['file_reference_eligible'] );
        $this->assertTrue( $fields[0]['required'] );

        $hidden = $fields[2];
        $this->assertSame( '3', $hidden['id'] );
        $this->assertSame( 'hidden', $hidden['visibility'] );
        $this->assertFalse( $hidden['storage_eligible'] );

        $file = $fields[3];
        $this->assertSame( '4', $file['id'] );
        $this->assertSame( 'file-upload', $file['type'] );
        $this->assertFalse( $file['storage_eligible'] );
        $this->assertTrue( $file['file_reference_eligible'] );
    }

    public function test_get_form_data_returns_prompt_ready_form_title_for_local_async(): void
    {
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $form_id = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'WPForms Runtime Prompt Title',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 43,
                        'settings' => [
                            'form_title' => 'WPForms Runtime Prompt Title',
                        ],
                        'fields'   => [
                            1 => [
                                'id'    => 1,
                                'type'  => 'name',
                                'label' => 'Full Name',
                            ],
                            2 => [
                                'id'    => 2,
                                'type'  => 'email',
                                'label' => 'Email',
                            ],
                        ],
                    ]
                ),
            ]
        );

        $adapter = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $this->assertTrue( method_exists( $adapter, 'get_form_data' ) );

        $form = $adapter->get_form_data( $form_id );
        $this->assertIsArray( $form );
        $this->assertSame( (string) $form_id, $form['id'] ?? null );
        $this->assertSame( 'WPForms Runtime Prompt Title', $form['title'] ?? null );
        $this->assertSame( 'wpforms', $form['form_source'] ?? null );
        $this->assertSame( 'email', $form['fields'][1]['type'] ?? null );

        $renderer  = new Sentient_Forms_Local_Prompt_Renderer();
        $variables = $renderer->build_variables( [], $form, [ 'name' => 'Ada Lovelace', '2' => 'ada@example.test' ] );
        $this->assertNotWPError( $variables );

        $prompt = $renderer->render_template( 'Form: {{form.title}} Name: {{entry.name}} Email: {{field:type:email}}', $variables );
        $this->assertNotWPError( $prompt );
        $this->assertSame( 'Form: WPForms Runtime Prompt Title Name: Ada Lovelace Email: ada@example.test', $prompt );
    }

    public function test_get_entry_data_adds_wpforms_field_id_aliases_for_prompt_rendering(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $form_id = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'WPForms Field Alias Prompt Form',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 0,
                        'settings' => [
                            'form_title' => 'WPForms Field Alias Prompt Form',
                        ],
                        'fields'   => [
                            1 => [
                                'id'    => 1,
                                'type'  => 'name',
                                'label' => 'Full Name',
                            ],
                            2 => [
                                'id'    => 2,
                                'type'  => 'email',
                                'label' => 'Email Address',
                            ],
                            3 => [
                                'id'    => 3,
                                'type'  => 'hidden',
                                'label' => 'Campaign Code',
                            ],
                        ],
                    ]
                ),
            ]
        );

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'wpforms', (string) $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Ada Lovelace',
                ],
                2 => [
                    'id'    => 2,
                    'name'  => 'Email Address',
                    'type'  => 'email',
                    'value' => 'ada@example.test',
                ],
                3 => [
                    'id'    => 3,
                    'name'  => 'Campaign Code',
                    'type'  => 'hidden',
                    'value' => 'internal-campaign',
                ],
            ],
            [],
            [
                'id'       => $form_id,
                'settings' => [
                    'form_title' => 'WPForms Field Alias Prompt Form',
                ],
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );

        $entry = $adapter->get_entry_data( $submission_uuid, (string) $form_id );
        $this->assertIsArray( $entry );
        $this->assertSame( 'ada@example.test', $entry['email_address'] ?? null );
        $this->assertSame( 'ada@example.test', $entry['2'] ?? null );
        $this->assertArrayNotHasKey( '3', $entry );

        $form      = $adapter->get_form_data( $form_id );
        $renderer  = new Sentient_Forms_Local_Prompt_Renderer();
        $variables = $renderer->build_variables( [], $form, $entry );
        $this->assertNotWPError( $variables );

        $prompt = $renderer->render_template( 'Email: {{field:type:email}}', $variables );
        $this->assertNotWPError( $prompt );
        $this->assertSame( 'Email: ada@example.test', $prompt );
    }

    public function test_get_entry_data_resolves_duplicate_label_field_id_aliases(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $form_id = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'WPForms Duplicate Label Alias Form',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 0,
                        'settings' => [
                            'form_title' => 'WPForms Duplicate Label Alias Form',
                        ],
                        'fields'   => [
                            2 => [
                                'id'    => 2,
                                'type'  => 'email',
                                'label' => 'Email',
                            ],
                            5 => [
                                'id'    => 5,
                                'type'  => 'text',
                                'label' => 'Email',
                            ],
                        ],
                    ]
                ),
            ]
        );

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'wpforms', (string) $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                2 => [
                    'id'    => 2,
                    'name'  => 'Email',
                    'type'  => 'email',
                    'value' => 'primary@example.test',
                ],
                5 => [
                    'id'    => 5,
                    'name'  => 'Email',
                    'type'  => 'text',
                    'value' => 'backup@example.test',
                ],
            ],
            [],
            [
                'id'       => $form_id,
                'settings' => [
                    'form_title' => 'WPForms Duplicate Label Alias Form',
                ],
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );

        $entry = $adapter->get_entry_data( $submission_uuid, (string) $form_id );
        $this->assertIsArray( $entry );
        $this->assertSame( 'primary@example.test', $entry['email'] ?? null );
        $this->assertSame( 'primary@example.test', $entry['2'] ?? null );
        $this->assertSame( 'backup@example.test', $entry['email_field_5'] ?? null );
        $this->assertSame( 'backup@example.test', $entry['5'] ?? null );
    }

    public function test_process_complete_stores_redacted_logical_fields_and_file_references_when_ledger_is_enabled_without_native_entry_id(): void
    {
        global $wpdb;

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger   = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $settings->set_enabled( 'wpforms', '42', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Grace Hopper',
                ],
                2 => [
                    'id'    => 2,
                    'name'  => 'Email',
                    'type'  => 'email',
                    'value' => 'grace@example.test',
                ],
                3 => [
                    'id'    => 3,
                    'name'  => 'Captcha Token',
                    'type'  => 'text',
                    'value' => 'do-not-store',
                ],
                4 => [
                    'id'        => 4,
                    'name'      => 'Resume',
                    'type'      => 'file-upload',
                    'value_raw' => 'https://example.test/uploads/resume.pdf',
                    'value'     => 'https://example.test/uploads/resume.pdf',
                ],
                5 => [
                    'id'    => 5,
                    'name'  => 'Campaign Code',
                    'type'  => 'hidden',
                    'value' => 'internal-route',
                ],
            ],
            [],
            [
                'id'       => 42,
                'settings' => [
                    'form_title' => 'WPForms Lite Ledger Capture',
                ],
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'wpforms', $stored['form_source'] ?? null );
        $this->assertSame( '42', $stored['form_id'] ?? null );
        $this->assertNull( $stored['native_entry_id'] ?? null );
        $this->assertNull( $stored['native_entry_url'] ?? null );
        $this->assertSame( 'Grace Hopper', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertSame( 'grace@example.test', $stored['logical_fields_json']['email'] ?? null );
        $this->assertSame( '[redacted]', $stored['logical_fields_json']['captcha_token'] ?? null );
        $this->assertArrayNotHasKey( 'resume', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'campaign_code', $stored['logical_fields_json'] ?? [] );
        $this->assertCount( 1, $stored['file_refs_json'] ?? [] );
        $this->assertSame( '4', $stored['file_refs_json'][0]['field_id'] ?? null );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertSame( 'https://example.test/uploads/resume.pdf', $stored['file_refs_json'][0]['url'] ?? null );
        $this->assertArrayNotHasKey( 'contents', $stored['file_refs_json'][0] ?? [] );
        $this->assertContains( 'captcha_token', $stored['redaction_summary_json']['redacted_fields'] ?? [] );
    }

    public function test_process_complete_stores_allowlisted_hidden_fields_when_ledger_is_enabled(): void
    {
        global $wpdb;

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger   = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );
        add_filter(
            'sentient_forms_wpforms_hidden_field_storage_allowlist',
            static fn (): array => [ 'utm_source' ]
        );

        $fields = [
            1 => [
                'id'    => 1,
                'type'  => 'name',
                'label' => 'Full Name',
            ],
            2 => [
                'id'    => 2,
                'type'  => 'hidden',
                'label' => 'UTM Source',
            ],
            3 => [
                'id'    => 3,
                'type'  => 'hidden',
                'label' => 'Internal Token',
            ],
        ];
        $form_id = self::factory()->post->create(
            [
                'post_type'    => 'wpforms',
                'post_status'  => 'publish',
                'post_title'   => 'WPForms Hidden Allowlist',
                'post_content' => wp_json_encode(
                    [
                        'id'       => 0,
                        'settings' => [
                            'form_title' => 'WPForms Hidden Allowlist',
                        ],
                        'fields'   => $fields,
                    ]
                ),
            ]
        );

        $settings->set_enabled( 'wpforms', (string) $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Katherine Johnson',
                ],
                2 => [
                    'id'    => 2,
                    'name'  => 'UTM Source',
                    'type'  => 'hidden',
                    'value' => 'partner-newsletter',
                ],
                3 => [
                    'id'    => 3,
                    'name'  => 'Internal Token',
                    'type'  => 'hidden',
                    'value' => 'do-not-store',
                ],
            ],
            [],
            [
                'id'       => $form_id,
                'settings' => [
                    'form_title' => 'WPForms Hidden Allowlist',
                ],
                'fields'   => $fields,
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'partner-newsletter', $stored['logical_fields_json']['utm_source'] ?? null );
        $this->assertArrayNotHasKey( 'internal_token', $stored['logical_fields_json'] ?? [] );

        $entry = $adapter->get_entry_data( $submission_uuid, (string) $form_id );
        $this->assertIsArray( $entry );
        $this->assertSame( 'partner-newsletter', $entry['2'] ?? null );
        $this->assertArrayNotHasKey( '3', $entry );

        $fields_by_id = [];
        foreach ( $adapter->get_form_fields( $form_id ) as $field )
        {
            $fields_by_id[ $field['id'] ] = $field;
        }
        $this->assertTrue( $fields_by_id['2']['storage_eligible'] ?? false );
        $this->assertFalse( $fields_by_id['3']['storage_eligible'] ?? true );
    }

    public function test_process_complete_does_not_store_or_schedule_when_ledger_is_disabled(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'wpforms_disabled_ledger_summary',
                'display_name'         => 'WPForms Disabled Ledger Summary',
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
                'form_source'         => 'wpforms',
                'form_id'             => '45',
                'hook'                => Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION,
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'summary_source' => 'full_name',
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

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $result  = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Mary Jackson',
                ],
            ],
            [],
            [
                'id'       => 45,
                'settings' => [
                    'form_title' => 'WPForms Disabled Ledger Gate',
                ],
            ],
            0
        );

        $this->assertNull( $result );
        $this->assertSame( [], $ledger->list_for_form( 'wpforms', '45' ) );
        $this->assertSame( [], $scheduled_jobs );
        $this->assertSame( [], $events->list_recent( 1 ) );
    }

    public function test_process_complete_honors_form_disabled_state_before_scheduling_local_first_mapping(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'wpforms', '49', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'wpforms_disabled_form_summary',
                'display_name'         => 'WPForms Disabled Form Summary',
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
                'form_source'         => 'wpforms',
                'form_id'             => '49',
                'hook'                => Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION,
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'summary_source' => 'full_name',
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        update_option( 'sentient_forms_actions_wpforms_49', [ 'sf_disabled' => true ], false );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Should Not Queue',
                ],
            ],
            [],
            [
                'id'       => 49,
                'settings' => [
                    'form_title' => 'WPForms Disabled Form',
                ],
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );
        $this->assertSame( [], $scheduled_jobs );
        $this->assertSame( [], $events->list_recent( 1 ) );
    }

    public function test_process_complete_honors_local_first_conditions_before_scheduling(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'wpforms', '50', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'wpforms_conditional_summary',
                'display_name'         => 'WPForms Conditional Summary',
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
                'form_source'         => 'wpforms',
                'form_id'             => '50',
                'hook'                => Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION,
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'summary_source' => 'full_name',
                ],
                'conditions_json'     => [
                    'enabled' => true,
                    'root'    => [
                        'type'  => 'group',
                        'logic' => 'all',
                        'rules' => [
                            [
                                'type'     => 'rule',
                                'field_id' => 'full_name',
                                'operator' => 'eq',
                                'value'    => 'Run Summary',
                            ],
                        ],
                    ],
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

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Skip Summary',
                ],
            ],
            [],
            [
                'id'       => 50,
                'settings' => [
                    'form_title' => 'WPForms Conditional Form',
                ],
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );
        $this->assertSame( [], $scheduled_jobs );
        $this->assertSame( [], $events->list_recent( 1 ) );
    }

    public function test_process_complete_stores_native_entry_link_only_when_wpforms_entry_record_exists(): void
    {
        global $wpdb;

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger   = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $settings->set_enabled( 'wpforms', '46', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );

        $unverified_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Sally Ride',
                ],
            ],
            [],
            [
                'id'       => 46,
                'settings' => [
                    'form_title' => 'WPForms Paid Entry Verification',
                ],
            ],
            778
        );

        $this->assertNotNull( $unverified_uuid );

        $unverified = $ledger->get_by_submission_uuid( $unverified_uuid );
        $this->assertSame( '778', $unverified['native_entry_id'] ?? null );
        $this->assertNull( $unverified['native_entry_url'] ?? null );

        $this->create_wpforms_entries_table();

        $missing_native_row_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'No Native Row',
                ],
            ],
            [],
            [
                'id'       => 46,
                'settings' => [
                    'form_title' => 'WPForms Paid Entry Verification',
                ],
            ],
            780
        );

        $this->assertNotNull( $missing_native_row_uuid );

        $missing_native_row = $ledger->get_by_submission_uuid( $missing_native_row_uuid );
        $this->assertSame( '780', $missing_native_row['native_entry_id'] ?? null );
        $this->assertNull( $missing_native_row['native_entry_url'] ?? null );

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'wpforms_entries',
            [
                'entry_id' => 779,
                'form_id'  => 46,
            ],
            [ '%d', '%d' ]
        );
        $this->assertSame( 1, $inserted );

        add_filter(
            'sentient_forms_wpforms_native_entry_available',
            static function ( mixed $available, int $form_id, int $entry_id ) use ( $wpdb ): bool {
                $table = $wpdb->prefix . 'wpforms_entries';
                $count = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(1) FROM {$table} WHERE entry_id = %d AND form_id = %d",
                        $entry_id,
                        $form_id
                    )
                );

                return (int) $count > 0;
            },
            10,
            3
        );

        $native_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Mae Jemison',
                ],
            ],
            [],
            [
                'id'       => 46,
                'settings' => [
                    'form_title' => 'WPForms Paid Entry Verification',
                ],
            ],
            779
        );

        $this->assertNotNull( $native_uuid );

        $native = $ledger->get_by_submission_uuid( $native_uuid );
        $this->assertSame( '779', $native['native_entry_id'] ?? null );
        $this->assertStringContainsString( 'page=wpforms-entries', $native['native_entry_url'] ?? '' );
        $this->assertStringContainsString( 'view=details', $native['native_entry_url'] ?? '' );
        $this->assertStringContainsString( 'entry_id=779', $native['native_entry_url'] ?? '' );

        $entry_snapshot = $adapter->get_entry_data( $native_uuid, '46' );
        $this->assertSame( '779', $entry_snapshot['id'] ?? null );
        $this->assertSame( $native['native_entry_url'], $entry_snapshot['native_entry_url'] ?? null );

        $entry_snapshot_by_native_id = $adapter->get_entry_data( '779', '46' );
        $this->assertIsArray( $entry_snapshot_by_native_id );
        $this->assertSame( '779', $entry_snapshot_by_native_id['id'] ?? null );
        $this->assertSame( $native_uuid, $entry_snapshot_by_native_id['submission_uuid'] ?? null );
        $this->assertSame( 'Mae Jemison', $entry_snapshot_by_native_id['full_name'] ?? null );
        $this->assertSame( $native['native_entry_url'], $entry_snapshot_by_native_id['native_entry_url'] ?? null );
    }

    public function test_get_entry_data_resolves_lite_submission_uuid_from_ledger_without_native_entry_id(): void
    {
        global $wpdb;

        $settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $settings->set_enabled( 'wpforms', '43', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Dorothy Vaughan',
                ],
                2 => [
                    'id'    => 2,
                    'name'  => 'Topic',
                    'type'  => 'checkbox',
                    'value' => [ 'Lead scoring', 'Spam detection' ],
                ],
                3 => [
                    'id'    => 3,
                    'name'  => 'Brief',
                    'type'  => 'file-upload',
                    'value' => 'https://example.test/uploads/brief.pdf',
                ],
            ],
            [],
            [
                'id'       => 43,
                'settings' => [
                    'form_title' => 'WPForms Lite Entry Resolution',
                ],
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );

        $entry = $adapter->get_entry_data( $submission_uuid, '43' );
        $this->assertIsArray( $entry );
        $this->assertNull( $entry['id'] ?? null );
        $this->assertSame( $submission_uuid, $entry['submission_uuid'] ?? null );
        $this->assertSame( 'wpforms', $entry['form_source'] ?? null );
        $this->assertSame( '43', $entry['form_id'] ?? null );
        $this->assertSame( 'Dorothy Vaughan', $entry['full_name'] ?? null );
        $this->assertSame( [ 'Lead scoring', 'Spam detection' ], $entry['topic'] ?? null );
        $this->assertSame( '3', $entry['file_refs'][0]['field_id'] ?? null );
        $this->assertSame( 'brief.pdf', $entry['file_refs'][0]['filename'] ?? null );

        $this->assertNull( $adapter->get_entry_data( $submission_uuid, '999' ) );
    }

    public function test_process_complete_schedules_local_first_mapping_with_submission_uuid_without_native_entry_id(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'wpforms', '44', true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'wpforms_local_summary',
                'display_name'         => 'WPForms Local Summary',
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
                'form_source'         => 'wpforms',
                'form_id'             => '44',
                'hook'                => Sentient_Forms_Form_Source_Lifecycles::AFTER_SUBMISSION,
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'summary_source' => 'full_name',
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

        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );

        $adapter         = new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_process_complete(
            [
                1 => [
                    'id'    => 1,
                    'name'  => 'Full Name',
                    'type'  => 'name',
                    'value' => 'Katherine Johnson',
                ],
                2 => [
                    'id'    => 2,
                    'name'  => 'Message',
                    'type'  => 'textarea',
                    'value' => 'Queue this WPForms Lite summary.',
                ],
            ],
            [],
            [
                'id'       => 44,
                'settings' => [
                    'form_title' => 'WPForms Lite Local First Execution',
                ],
            ],
            0
        );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_local_mapping', $scheduled_jobs[0]['hook'] ?? null );
        $this->assertSame( 'sentient_forms_async', $scheduled_jobs[0]['group'] ?? null );

        $payload = $scheduled_jobs[0]['args'][0] ?? [];
        $this->assertSame( $mapping_id, $payload['local_mapping_id'] ?? null );
        $this->assertSame( 'wpforms', $payload['form_source'] ?? null );
        $this->assertSame( '44', $payload['form_id'] ?? null );
        $this->assertNull( $payload['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $payload['submission_uuid'] ?? null );

        $resolved_entry = $adapter->get_entry_data( $submission_uuid, '44' );
        $this->assertIsArray( $resolved_entry );
        $this->assertNull( $resolved_entry['id'] ?? null );
        $this->assertSame( $submission_uuid, $resolved_entry['submission_uuid'] ?? null );
        $this->assertSame( 'wpforms', $resolved_entry['form_source'] ?? null );
        $this->assertSame( '44', $resolved_entry['form_id'] ?? null );
        $this->assertSame( 'Katherine Johnson', $resolved_entry['full_name'] ?? null );

        $event = $events->list_recent( 1 )[0] ?? null;
        $this->assertIsArray( $event );
        $this->assertSame( 'queued', $event['status'] ?? null );
        $this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
        $this->assertSame( 'wpforms', $event['form_source'] ?? null );
        $this->assertSame( '44', $event['form_id'] ?? null );
        $this->assertNull( $event['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
    }

    private function wpforms_entries_table_exists(): bool
    {
        global $wpdb;

        $table = $wpdb->prefix . 'wpforms_entries';

        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /** @return array<int, array<string, mixed>> */
    private function validation_fields(): array
    {
        return [
            1 => [ 'id' => 1, 'name' => 'Full Name', 'type' => 'name', 'value' => 'Ada Lovelace' ],
            2 => [ 'id' => 2, 'name' => 'Project Details', 'type' => 'textarea', 'value' => 'test' ],
        ];
    }

    /** @return array<string, mixed> */
    private function validation_form_data( int $form_id ): array
    {
        return [
            'id'       => $form_id,
            'settings' => [ 'form_title' => 'WPForms Validation' ],
            'fields'   => [
                1 => [ 'id' => 1, 'label' => 'Full Name', 'type' => 'name' ],
                2 => [ 'id' => 2, 'label' => 'Project Details', 'type' => 'textarea' ],
            ],
        ];
    }

    private function configure_validation_mapping( int $form_id, Sentient_Forms_Test_WPForms_Validation_Action $action ): void
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
                'form_source'    => 'wpforms',
                'form_id'        => (string) $form_id,
                'hook'           => 'validation',
                'action_kind'    => 'custom_action',
                'action_id'      => $action_id,
                'input_bindings_json' => [],
                'execution_mode' => 'sync',
                'enabled'        => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $this->validation_executor = $action;
    }

    private function initialize_validation_adapter( object $process, bool $spy = false ): Sentient_Forms_WPForms_Adapter
    {
        add_filter( 'sentient_forms_wpforms_is_active', '__return_true' );
        add_filter(
            'sentient_forms_wpforms_object',
            static fn( mixed $object, string $name ): mixed => 'process' === $name ? $process : $object,
            10,
            2
        );
        $runner  = new Sentient_Forms_Form_Source_Workflow_Runner(
            Sentient_Forms_Plugin::instance(),
            null,
            null,
            $this->validation_executor
        );
        $adapter = $spy
            ? new Sentient_Forms_Test_WPForms_Validation_Adapter_Spy( Sentient_Forms_Plugin::instance(), $runner )
            : new Sentient_Forms_WPForms_Adapter( Sentient_Forms_Plugin::instance(), $runner );
        $adapter->init();

        return $adapter;
    }

    private function create_wpforms_entries_table(): void
    {
        global $wpdb;

        if ( $this->wpforms_entries_table_exists() )
        {
            return;
        }

        $table = $wpdb->prefix . 'wpforms_entries';
        $wpdb->query(
            "CREATE TABLE {$table} (
                entry_id BIGINT UNSIGNED NOT NULL,
                form_id BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY  (entry_id),
                KEY form_id (form_id)
            )"
        );
        $this->created_wpforms_entries_table = true;
    }
}
