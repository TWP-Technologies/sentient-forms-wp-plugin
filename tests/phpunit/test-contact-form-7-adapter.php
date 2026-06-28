<?php

class Tests_Contact_Form_7_Adapter extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_contact_form_7_is_active' );
        remove_all_filters( 'sentient_forms_contact_form_7_forms' );
        remove_all_filters( 'sentient_forms_contact_form_7_form_object' );
        remove_all_filters( 'sentient_forms_contact_form_7_current_submission' );
        remove_all_actions( 'sentient_forms_async_job_scheduled' );
        foreach ( [ '44', '47', '48' ] as $form_id )
        {
            delete_option( 'sentient_forms_actions_contact_form_7_' . $form_id );
        }

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

        $submission_uuid = $adapter->handle_mail_sent( $this->cf7_form( 44, 'CF7 Execution Gate' ) );

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
