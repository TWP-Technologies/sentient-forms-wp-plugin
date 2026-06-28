<?php

class Tests_Elementor_Forms_Adapter extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_elementor_pro_form_submissions_api_available' );
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );
        remove_all_filters( 'sentient_forms_elementor_discovery_post_limit' );
        remove_all_actions( 'sentient_forms_async_job_scheduled' );

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
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'sentient_forms_actions_elementor_forms_' ) . '%'
            )
        );

        parent::tearDown();
    }

    public function test_elementor_pro_forms_widgets_are_discoverable_from_elementor_data(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = $this->create_elementor_form_page();
        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $forms   = $adapter->get_forms();

        $this->assertCount( 1, $forms );
        $this->assertSame( $page_id . ':formabc', $forms[0]['id'] );
        $this->assertSame( 'Quote Request', $forms[0]['title'] );
        $this->assertSame( 'elementor_forms', $forms[0]['adapter'] );
        $this->assertSame( 'Elementor Forms', $forms[0]['adapter_name'] );
        $this->assertTrue( $forms[0]['provider_is_active'] );
        $this->assertStringContainsString( 'post=' . $page_id, $forms[0]['provider_edit_url'] );
        $this->assertStringContainsString( 'action=elementor', $forms[0]['provider_edit_url'] );
    }

    public function test_get_forms_memoizes_elementor_discovery_per_adapter_instance(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = $this->create_elementor_form_page();
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );

        $discovery_calls = 0;
        add_filter(
            'sentient_forms_elementor_posts_with_data',
            static function () use ( &$discovery_calls, $page_id ): array {
                $discovery_calls++;
                return [ $page_id ];
            }
        );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );

        $this->assertCount( 1, $adapter->get_forms() );
        $this->assertCount( 1, $adapter->get_forms() );
        $this->assertSame( 1, $discovery_calls );

        $adapter->reset_discovery_cache();

        $this->assertCount( 1, $adapter->get_forms() );
        $this->assertSame( 2, $discovery_calls );
    }

    public function test_elementor_discovery_post_query_is_bounded_by_filterable_limit(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $this->create_elementor_form_page( null, 'formone', 'First Quote Request' );
        $this->create_elementor_form_page( null, 'formtwo', 'Second Quote Request' );
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );
        add_filter( 'sentient_forms_elementor_discovery_post_limit', static fn() => 1 );

        $used_wp_query_meta_filter = false;
        $capture_query             = static function ( $query ) use ( &$used_wp_query_meta_filter ): void {
            if (
                'ids' !== $query->get( 'fields' )
                || 'any' !== $query->get( 'post_status' )
                || 'any' !== $query->get( 'post_type' )
            )
            {
                return;
            }

            if ( '' !== (string) $query->get( 'meta_key' ) || ! empty( $query->get( 'meta_query' ) ) )
            {
                $used_wp_query_meta_filter = true;
            }
        };
        add_action( 'pre_get_posts', $capture_query );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $forms   = $adapter->get_forms();

        remove_action( 'pre_get_posts', $capture_query );

        $this->assertFalse( $used_wp_query_meta_filter );
        $this->assertCount( 1, $forms );
    }

    public function test_elementor_pro_form_field_manifest_marks_logical_hidden_file_and_security_fields(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = $this->create_elementor_form_page();
        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $page_id . ':formabc' );

        $this->assertCount( 5, $fields );
        $this->assertSame( 'full_name', $fields[0]['id'] );
        $this->assertSame( 'Full name', $fields[0]['label'] );
        $this->assertSame( 'text', $fields[0]['type'] );
        $this->assertSame( 'visible', $fields[0]['visibility'] );
        $this->assertTrue( $fields[0]['storage_eligible'] );
        $this->assertFalse( $fields[0]['file_reference_eligible'] );
        $this->assertTrue( $fields[0]['required'] );

        $hidden = $fields[2];
        $this->assertSame( 'tracking_code', $hidden['id'] );
        $this->assertSame( 'hidden', $hidden['type'] );
        $this->assertSame( 'hidden', $hidden['visibility'] );
        $this->assertFalse( $hidden['storage_eligible'] );

        $file = $fields[3];
        $this->assertSame( 'resume', $file['id'] );
        $this->assertSame( 'upload', $file['type'] );
        $this->assertFalse( $file['storage_eligible'] );
        $this->assertTrue( $file['file_reference_eligible'] );

        $security = $fields[4];
        $this->assertSame( 'captcha', $security['id'] );
        $this->assertSame( 'recaptcha', $security['type'] );
        $this->assertFalse( $security['storage_eligible'] );
        $this->assertFalse( $security['file_reference_eligible'] );
    }

    public function test_elementor_pro_form_field_list_omits_duplicate_field_ids(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'contact_name',
                    'field_label' => 'Primary contact',
                    'field_type'  => 'text',
                    'required'    => 'true',
                ],
                [
                    'custom_id'   => 'contact_name',
                    'field_label' => 'Billing contact',
                    'field_type'  => 'text',
                    'required'    => 'false',
                ],
            ]
        );
        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $page_id . ':formabc' );

        $this->assertSame( [], array_column( $fields, 'id' ) );
    }

    public function test_new_record_does_not_store_logical_fields_when_ledger_is_disabled(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = $this->create_elementor_form_page();
        $form_id = $page_id . ':formabc';
        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $record  = $this->elementor_submission_record();
        $ledger  = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );

        $this->assertNull( $adapter->handle_new_record( $record, null ) );
        $this->assertSame( [], $ledger->list_for_form( 'elementor_forms', $form_id ) );
    }

    public function test_new_record_stores_logical_fields_and_file_references_when_ledger_is_enabled(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'elementor_forms', $stored['form_source'] ?? null );
        $this->assertSame( $form_id, $stored['form_id'] ?? null );
        $this->assertNull( $stored['native_entry_id'] ?? null );
        $this->assertNull( $stored['native_entry_url'] ?? null );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertSame( 'ada@example.test', $stored['logical_fields_json']['email'] ?? null );
        $this->assertArrayNotHasKey( 'tracking_code', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'resume', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'captcha', $stored['logical_fields_json'] ?? [] );
        $this->assertSame( 'resume', $stored['file_refs_json'][0]['field_id'] ?? null );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertArrayNotHasKey( 'contents', $stored['file_refs_json'][0] ?? [] );
        $this->assertSame( 'Quote Request', $stored['provider_metadata_json']['form_name'] ?? null );

        $entry = $adapter->get_entry_data( $submission_uuid, $form_id );
        $this->assertIsArray( $entry );
        $this->assertNull( $entry['id'] ?? null );
        $this->assertSame( $submission_uuid, $entry['submission_uuid'] ?? null );
        $this->assertSame( 'elementor_forms', $entry['form_source'] ?? null );
        $this->assertSame( $form_id, $entry['form_id'] ?? null );
        $this->assertSame( 'Ada Lovelace', $entry['full_name'] ?? null );
        $this->assertSame( 'resume', $entry['file_refs'][0]['field_id'] ?? null );
        $this->assertNull( $adapter->get_entry_data( $submission_uuid, '999:missing' ) );
    }

    public function test_new_record_stores_elementor_upload_array_as_single_safe_file_reference(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name' => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'resume'    => [
                        'id'    => 'resume',
                        'title' => 'Resume',
                        'type'  => 'upload',
                        'value' => [
                            'name'     => 'resume.pdf',
                            'url'      => 'https://example.test/uploads/private/resume.pdf',
                            'type'     => 'application/pdf',
                            'size'     => 12345,
                            'tmp_name' => 'C:\\temp\\php123.tmp',
                            'contents' => 'binary-payload',
                        ],
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertArrayNotHasKey( 'resume', $stored['logical_fields_json'] ?? [] );
        $this->assertCount( 1, $stored['file_refs_json'] ?? [] );
        $this->assertSame( 'resume', $stored['file_refs_json'][0]['field_id'] ?? null );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertSame( 'https://example.test/uploads/private/resume.pdf', $stored['file_refs_json'][0]['url'] ?? null );
        $this->assertSame( 'application/pdf', $stored['file_refs_json'][0]['mime_type'] ?? null );
        $this->assertSame( '12345', $stored['file_refs_json'][0]['size'] ?? null );
        $this->assertArrayNotHasKey( 'tmp_name', $stored['file_refs_json'][0] ?? [] );
        $this->assertArrayNotHasKey( 'contents', $stored['file_refs_json'][0] ?? [] );
    }

    public function test_new_record_strips_query_tokens_from_upload_array_url_reference(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name' => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'resume'    => [
                        'id'    => 'resume',
                        'title' => 'Resume',
                        'type'  => 'upload',
                        'value' => [
                            'name' => 'resume.pdf',
                            'url'  => 'https://example.test/uploads/private/resume.pdf?token=secret-token#download',
                        ],
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertCount( 1, $stored['file_refs_json'] ?? [] );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertSame( 'https://example.test/uploads/private/resume.pdf', $stored['file_refs_json'][0]['url'] ?? null );

        $file_refs_json = wp_json_encode( $stored['file_refs_json'] ?? [] );
        $this->assertIsString( $file_refs_json );
        $this->assertStringNotContainsString( 'secret-token', $file_refs_json );
        $this->assertStringNotContainsString( '#download', $file_refs_json );
    }

    public function test_new_record_strips_query_tokens_from_scalar_upload_url_file_reference(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name' => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'resume'    => [
                        'id'    => 'resume',
                        'title' => 'Resume',
                        'type'  => 'upload',
                        'value' => 'https://example.test/uploads/private/resume.pdf?token=secret-token',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertCount( 1, $stored['file_refs_json'] ?? [] );
        $this->assertSame( 'resume', $stored['file_refs_json'][0]['field_id'] ?? null );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertArrayNotHasKey( 'url', $stored['file_refs_json'][0] ?? [] );

        $file_refs_json = wp_json_encode( $stored['file_refs_json'] ?? [] );
        $this->assertIsString( $file_refs_json );
        $this->assertStringNotContainsString( 'secret-token', $file_refs_json );
    }

    public function test_new_record_splits_comma_separated_scalar_upload_urls_into_file_references(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name' => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'resume'    => [
                        'id'    => 'resume',
                        'title' => 'Resume',
                        'type'  => 'upload',
                        'value' => 'https://example.test/uploads/private/resume.pdf?token=resume-secret, https://example.test/uploads/private/cover-letter.pdf?token=cover-secret#fragment',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertArrayNotHasKey( 'resume', $stored['logical_fields_json'] ?? [] );
        $this->assertCount( 2, $stored['file_refs_json'] ?? [] );
        $this->assertSame( 'resume', $stored['file_refs_json'][0]['field_id'] ?? null );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertArrayNotHasKey( 'url', $stored['file_refs_json'][0] ?? [] );
        $this->assertSame( 'resume', $stored['file_refs_json'][1]['field_id'] ?? null );
        $this->assertSame( 'cover-letter.pdf', $stored['file_refs_json'][1]['filename'] ?? null );
        $this->assertArrayNotHasKey( 'url', $stored['file_refs_json'][1] ?? [] );

        $file_refs_json = wp_json_encode( $stored['file_refs_json'] ?? [] );
        $this->assertIsString( $file_refs_json );
        $this->assertStringNotContainsString( 'resume-secret', $file_refs_json );
        $this->assertStringNotContainsString( 'cover-secret', $file_refs_json );
        $this->assertStringNotContainsString( '#fragment', $file_refs_json );
    }

    public function test_new_record_stores_columnar_multi_upload_array_as_safe_file_references(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name' => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'resume'    => [
                        'id'    => 'resume',
                        'title' => 'Resume',
                        'type'  => 'upload',
                        'value' => [
                            'name'     => [
                                'resume.pdf',
                                'cover-letter.pdf',
                            ],
                            'url'      => [
                                'https://example.test/uploads/private/resume.pdf?token=resume-secret',
                                'https://example.test/uploads/private/cover-letter.pdf?token=cover-secret#fragment',
                            ],
                            'type'     => [
                                'application/pdf',
                                'application/pdf',
                            ],
                            'size'     => [
                                12345,
                                6789,
                            ],
                            'tmp_name' => [
                                'C:\\temp\\php123.tmp',
                                'C:\\temp\\php456.tmp',
                            ],
                            'contents' => [
                                'binary-resume',
                                'binary-cover',
                            ],
                        ],
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertArrayNotHasKey( 'resume', $stored['logical_fields_json'] ?? [] );
        $this->assertCount( 2, $stored['file_refs_json'] ?? [] );

        $this->assertSame( 'resume', $stored['file_refs_json'][0]['field_id'] ?? null );
        $this->assertSame( 'resume.pdf', $stored['file_refs_json'][0]['filename'] ?? null );
        $this->assertSame( 'https://example.test/uploads/private/resume.pdf', $stored['file_refs_json'][0]['url'] ?? null );
        $this->assertSame( 'application/pdf', $stored['file_refs_json'][0]['mime_type'] ?? null );
        $this->assertSame( '12345', $stored['file_refs_json'][0]['size'] ?? null );

        $this->assertSame( 'resume', $stored['file_refs_json'][1]['field_id'] ?? null );
        $this->assertSame( 'cover-letter.pdf', $stored['file_refs_json'][1]['filename'] ?? null );
        $this->assertSame( 'https://example.test/uploads/private/cover-letter.pdf', $stored['file_refs_json'][1]['url'] ?? null );
        $this->assertSame( 'application/pdf', $stored['file_refs_json'][1]['mime_type'] ?? null );
        $this->assertSame( '6789', $stored['file_refs_json'][1]['size'] ?? null );

        $file_refs_json = wp_json_encode( $stored['file_refs_json'] ?? [] );
        $this->assertIsString( $file_refs_json );
        $this->assertStringNotContainsString( 'resume-secret', $file_refs_json );
        $this->assertStringNotContainsString( 'cover-secret', $file_refs_json );
        $this->assertStringNotContainsString( '#fragment', $file_refs_json );
        $this->assertStringNotContainsString( 'php123.tmp', $file_refs_json );
        $this->assertStringNotContainsString( 'binary-resume', $file_refs_json );
    }

    public function test_new_record_stores_field_manifest_metadata_without_submitted_values(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );

        $stored   = $ledger->get_by_submission_uuid( $submission_uuid );
        $manifest = $stored['provider_metadata_json']['field_manifest'] ?? null;

        $this->assertIsArray( $manifest );
        $this->assertSame( 'Full name', $manifest['full_name']['label'] ?? null );
        $this->assertSame( 'text', $manifest['full_name']['type'] ?? null );
        $this->assertTrue( $manifest['full_name']['storage_eligible'] ?? false );
        $this->assertArrayNotHasKey( 'value', $manifest['full_name'] ?? [] );

        $this->assertSame( 'Resume', $manifest['resume']['label'] ?? null );
        $this->assertSame( 'upload', $manifest['resume']['type'] ?? null );
        $this->assertFalse( $manifest['resume']['storage_eligible'] ?? true );
        $this->assertTrue( $manifest['resume']['file_reference_eligible'] ?? false );
        $this->assertArrayNotHasKey( 'value', $manifest['resume'] ?? [] );

        $metadata_json = wp_json_encode( $stored['provider_metadata_json'] ?? [] );
        $this->assertIsString( $metadata_json );
        $this->assertStringNotContainsString( 'Ada Lovelace', $metadata_json );
        $this->assertStringNotContainsString( 'ada@example.test', $metadata_json );
        $this->assertStringNotContainsString( 'resume.pdf', $metadata_json );
    }

    public function test_hidden_elementor_manifest_rows_are_not_stored_with_submission_metadata(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertSame( 'tracking_code', $fields[2]['id'] ?? null );
        $this->assertSame( 'hidden', $fields[2]['type'] ?? null );
        $this->assertFalse( $fields[2]['storage_eligible'] );

        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );

        $stored   = $ledger->get_by_submission_uuid( $submission_uuid );
        $manifest = $stored['provider_metadata_json']['field_manifest'] ?? [];

        $this->assertArrayHasKey( 'full_name', $manifest );
        $this->assertArrayHasKey( 'resume', $manifest );
        $this->assertArrayNotHasKey( 'tracking_code', $manifest );
        $this->assertArrayNotHasKey( 'captcha', $manifest );

        $stored_json = wp_json_encode( $stored );
        $this->assertIsString( $stored_json );
        $this->assertStringNotContainsString( 'Tracking code', $stored_json );
        $this->assertStringNotContainsString( 'do-not-store', $stored_json );
    }

    public function test_new_record_adds_runtime_field_metadata_for_safe_submitted_fields_missing_from_manifest(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name' => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'company'   => [
                        'id'    => 'company',
                        'title' => 'Company',
                        'type'  => 'text',
                        'value' => 'Analytical Engines Ltd.',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored   = $ledger->get_by_submission_uuid( $submission_uuid );
        $manifest = $stored['provider_metadata_json']['field_manifest'] ?? null;

        $this->assertSame( 'Analytical Engines Ltd.', $stored['logical_fields_json']['company'] ?? null );
        $this->assertIsArray( $manifest );
        $this->assertSame( 'Company', $manifest['company']['label'] ?? null );
        $this->assertSame( 'text', $manifest['company']['type'] ?? null );
        $this->assertTrue( $manifest['company']['storage_eligible'] ?? false );
        $this->assertArrayNotHasKey( 'value', $manifest['company'] ?? [] );

        $metadata_json = wp_json_encode( $stored['provider_metadata_json'] ?? [] );
        $this->assertIsString( $metadata_json );
        $this->assertStringNotContainsString( 'Analytical Engines Ltd.', $metadata_json );
    }

    public function test_new_record_does_not_store_submitted_hidden_fields_missing_from_manifest(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'     => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'tracking_code' => [
                        'id'    => 'tracking_code',
                        'title' => 'Tracking code',
                        'type'  => 'hidden',
                        'value' => 'do-not-store-runtime-hidden',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored   = $ledger->get_by_submission_uuid( $submission_uuid );
        $manifest = $stored['provider_metadata_json']['field_manifest'] ?? [];

        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( 'tracking_code', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'tracking_code', $manifest );

        $stored_json = wp_json_encode( $stored );
        $this->assertIsString( $stored_json );
        $this->assertStringNotContainsString( 'do-not-store-runtime-hidden', $stored_json );
    }

    public function test_new_record_ignores_numeric_keyed_submitted_fields_without_explicit_ids(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    [
                        'title' => 'Unstable text',
                        'type'  => 'text',
                        'value' => 'numeric-index-text',
                    ],
                    [
                        'title' => 'Unstable upload',
                        'type'  => 'upload',
                        'value' => 'C:\\private\\unstable.pdf',
                    ],
                    'company' => [
                        'title' => 'Company',
                        'type'  => 'text',
                        'value' => 'Analytical Engines Ltd.',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored   = $ledger->get_by_submission_uuid( $submission_uuid );
        $manifest = $stored['provider_metadata_json']['field_manifest'] ?? [];

        $this->assertSame( 'Analytical Engines Ltd.', $stored['logical_fields_json']['company'] ?? null );
        $this->assertArrayNotHasKey( '0', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( '1', $stored['logical_fields_json'] ?? [] );
        $this->assertCount( 0, $stored['file_refs_json'] ?? [] );
        $this->assertArrayHasKey( 'company', $manifest );
        $this->assertArrayNotHasKey( '0', $manifest );
        $this->assertArrayNotHasKey( '1', $manifest );
    }

    public function test_sensitive_elementor_field_types_are_not_stored_even_with_neutral_ids(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'access_code',
                    'field_label' => 'Access code',
                    'field_type'  => 'password',
                ],
                [
                    'custom_id'   => 'cc',
                    'field_label' => 'Card number',
                    'field_type'  => 'credit-card-number',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertFalse( $fields[1]['storage_eligible'] );
        $this->assertFalse( $fields[2]['storage_eligible'] );

        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'   => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'access_code' => [
                        'id'    => 'access_code',
                        'title' => 'Access code',
                        'type'  => 'password',
                        'value' => 'do-not-store-password',
                    ],
                    'cc'          => [
                        'id'    => 'cc',
                        'title' => 'Card number',
                        'type'  => 'credit-card-number',
                        'value' => '4242 4242 4242 4242',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( 'access_code', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'cc', $stored['logical_fields_json'] ?? [] );
    }

    public function test_sensitive_elementor_upload_field_ids_are_not_file_reference_eligible_or_captured(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'raw_provider_upload',
                    'field_label' => 'Provider upload',
                    'field_type'  => 'upload',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertSame( 'raw_provider_upload', $fields[1]['id'] ?? null );
        $this->assertFalse( $fields[1]['storage_eligible'] );
        $this->assertFalse( $fields[1]['file_reference_eligible'] );

        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'           => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'raw_provider_upload' => [
                        'id'    => 'raw_provider_upload',
                        'title' => 'Provider upload',
                        'type'  => 'upload',
                        'value' => 'C:\\private\\provider-diagnostic.pdf',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( 'raw_provider_upload', $stored['logical_fields_json'] ?? [] );
        $this->assertCount( 0, $stored['file_refs_json'] ?? [] );

        $stored_json = wp_json_encode( $stored );
        $this->assertIsString( $stored_json );
        $this->assertStringNotContainsString( 'provider-diagnostic.pdf', $stored_json );
    }

    public function test_sensitive_elementor_manifest_rows_are_not_stored_with_submission_metadata(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'csrf_token',
                    'field_label' => 'CSRF token',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'raw_provider_upload',
                    'field_label' => 'Provider upload',
                    'field_type'  => 'upload',
                ],
                [
                    'custom_id'   => 'reference',
                    'field_label' => 'Card number',
                    'field_type'  => 'text',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertTrue( $fields[0]['storage_eligible'] );
        $this->assertFalse( $fields[1]['storage_eligible'] );
        $this->assertFalse( $fields[2]['file_reference_eligible'] );
        $this->assertFalse( $fields[3]['storage_eligible'] );

        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'           => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'csrf_token'          => [
                        'id'    => 'csrf_token',
                        'title' => 'CSRF token',
                        'type'  => 'text',
                        'value' => 'do-not-store-token',
                    ],
                    'raw_provider_upload' => [
                        'id'    => 'raw_provider_upload',
                        'title' => 'Provider upload',
                        'type'  => 'upload',
                        'value' => 'C:\\private\\provider-diagnostic.pdf',
                    ],
                    'reference'           => [
                        'id'    => 'reference',
                        'title' => 'Card number',
                        'type'  => 'text',
                        'value' => '4242 4242 4242 4242',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored   = $ledger->get_by_submission_uuid( $submission_uuid );
        $manifest = $stored['provider_metadata_json']['field_manifest'] ?? [];

        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayHasKey( 'full_name', $manifest );
        $this->assertArrayNotHasKey( 'csrf_token', $manifest );
        $this->assertArrayNotHasKey( 'raw_provider_upload', $manifest );
        $this->assertArrayNotHasKey( 'reference', $manifest );

        $stored_json = wp_json_encode( $stored );
        $this->assertIsString( $stored_json );
        $this->assertStringNotContainsString( 'do-not-store-token', $stored_json );
        $this->assertStringNotContainsString( 'provider-diagnostic.pdf', $stored_json );
        $this->assertStringNotContainsString( '4242 4242 4242 4242', $stored_json );
        $this->assertStringNotContainsString( 'CSRF token', $stored_json );
        $this->assertStringNotContainsString( 'Provider upload', $stored_json );
        $this->assertStringNotContainsString( 'Card number', $stored_json );
    }

    public function test_sensitive_elementor_field_ids_are_not_stored_even_with_text_types(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'csrf_token',
                    'field_label' => 'CSRF token',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'payment_reference',
                    'field_label' => 'Payment reference',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'card_number',
                    'field_label' => 'Card number',
                    'field_type'  => 'text',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertTrue( $fields[0]['storage_eligible'] );
        $this->assertFalse( $fields[1]['storage_eligible'] );
        $this->assertFalse( $fields[2]['storage_eligible'] );
        $this->assertFalse( $fields[3]['storage_eligible'] );

        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'         => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'csrf_token'        => [
                        'id'    => 'csrf_token',
                        'title' => 'CSRF token',
                        'type'  => 'text',
                        'value' => 'do-not-store-token',
                    ],
                    'payment_reference' => [
                        'id'    => 'payment_reference',
                        'title' => 'Payment reference',
                        'type'  => 'text',
                        'value' => 'do-not-store-payment-reference',
                    ],
                    'card_number'       => [
                        'id'    => 'card_number',
                        'title' => 'Card number',
                        'type'  => 'text',
                        'value' => '4242 4242 4242 4242',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( 'csrf_token', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'payment_reference', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'card_number', $stored['logical_fields_json'] ?? [] );
    }

    public function test_sensitive_elementor_field_labels_are_not_stored_even_with_neutral_ids_and_text_types(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'reference',
                    'field_label' => 'Card number',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'authorization',
                    'field_label' => 'Payment token',
                    'field_type'  => 'text',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertTrue( $fields[0]['storage_eligible'] );
        $this->assertFalse( $fields[1]['storage_eligible'] );
        $this->assertFalse( $fields[2]['storage_eligible'] );

        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'     => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'reference'     => [
                        'id'    => 'reference',
                        'title' => 'Card number',
                        'type'  => 'text',
                        'value' => '4242 4242 4242 4242',
                    ],
                    'authorization' => [
                        'id'    => 'authorization',
                        'title' => 'Payment token',
                        'type'  => 'text',
                        'value' => 'do-not-store-payment-token',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( 'reference', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'authorization', $stored['logical_fields_json'] ?? [] );
    }

    public function test_payment_credential_elementor_field_labels_are_not_stored_even_with_neutral_ids_and_text_types(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'verification',
                    'field_label' => 'CVV',
                    'field_type'  => 'text',
                ],
                [
                    'custom_id'   => 'renewal',
                    'field_label' => 'Expiry date',
                    'field_type'  => 'text',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $fields  = $adapter->get_form_fields( $form_id );

        $this->assertTrue( $fields[0]['storage_eligible'] );
        $this->assertFalse( $fields[1]['storage_eligible'] );
        $this->assertFalse( $fields[2]['storage_eligible'] );

        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'    => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'verification' => [
                        'id'    => 'verification',
                        'title' => 'CVV',
                        'type'  => 'text',
                        'value' => '123',
                    ],
                    'renewal'      => [
                        'id'    => 'renewal',
                        'title' => 'Expiry date',
                        'type'  => 'text',
                        'value' => '12/34',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( 'verification', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'renewal', $stored['logical_fields_json'] ?? [] );
    }

    public function test_elementor_provider_internal_fields_are_not_stored_as_logical_or_metadata_fields(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page(
            [
                [
                    'custom_id'   => 'full_name',
                    'field_label' => 'Full name',
                    'field_type'  => 'text',
                ],
            ]
        );
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'           => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    '_elementor_form_id'  => [
                        'id'    => '_elementor_form_id',
                        'title' => 'Elementor form ID',
                        'type'  => 'text',
                        'value' => 'formabc',
                    ],
                    'elementor_widget_id' => [
                        'id'    => 'elementor_widget_id',
                        'title' => 'Elementor widget ID',
                        'type'  => 'text',
                        'value' => 'formabc',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( '_elementor_form_id', $stored['logical_fields_json'] ?? [] );
        $this->assertArrayNotHasKey( 'elementor_widget_id', $stored['logical_fields_json'] ?? [] );

        $metadata_json = wp_json_encode( $stored['provider_metadata_json'] ?? [] );
        $this->assertIsString( $metadata_json );
        $this->assertStringNotContainsString( 'Elementor form ID', $metadata_json );
        $this->assertStringNotContainsString( 'Elementor widget ID', $metadata_json );
        $this->assertStringNotContainsString( 'formabc', $metadata_json );
    }

    public function test_new_record_resolves_duplicate_form_names_by_submitted_widget_id(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $first_page_id  = $this->create_elementor_form_page( null, 'formabc', 'Quote Request' );
        $second_page_id = $this->create_elementor_form_page( null, 'targetform', 'Quote Request' );
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );
        add_filter(
            'sentient_forms_elementor_posts_with_data',
            static fn() => [ $first_page_id, $second_page_id ]
        );

        $form_id         = $second_page_id . ':targetform';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record(
            $this->elementor_submission_record(
                [
                    'full_name'           => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'elementor_widget_id' => [
                        'id'    => 'elementor_widget_id',
                        'title' => 'Elementor widget ID',
                        'type'  => 'hidden',
                        'value' => 'targetform',
                    ],
                ]
            ),
            null
        );

        $this->assertNotNull( $submission_uuid );

        $stored = $ledger->get_by_submission_uuid( $submission_uuid );
        $this->assertSame( $form_id, $stored['form_id'] ?? null );
        $this->assertSame( 'Ada Lovelace', $stored['logical_fields_json']['full_name'] ?? null );
        $this->assertArrayNotHasKey( 'elementor_widget_id', $stored['logical_fields_json'] ?? [] );
    }

    public function test_new_record_emits_resolution_failure_action_for_ambiguous_form_name_without_widget_id(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $first_page_id  = $this->create_elementor_form_page( null, 'formabc', 'Quote Request' );
        $second_page_id = $this->create_elementor_form_page( null, 'targetform', 'Quote Request' );
        remove_all_filters( 'sentient_forms_elementor_posts_with_data' );
        add_filter(
            'sentient_forms_elementor_posts_with_data',
            static fn() => [ $first_page_id, $second_page_id ]
        );

        $events = [];
        add_action(
            'sentient_forms_elementor_form_resolution_failed',
            static function ( array $context ) use ( &$events ): void {
                $events[] = $context;
            }
        );

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNull( $submission_uuid );
        $this->assertCount( 1, $events );
        $this->assertSame( 'ambiguous_or_missing_form_id', $events[0]['reason'] ?? null );
        $this->assertSame( 'elementor_forms', $events[0]['form_source'] ?? null );
        $this->assertSame( 'Quote Request', $events[0]['form_name'] ?? null );
        $this->assertSame( '', $events[0]['widget_id'] ?? null );
    }

    public function test_new_record_schedules_after_submission_action_only_after_ledger_capture(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, false );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $this->assertTrue(
            $adapter->update_form_settings(
                $form_id,
                [
                    'map_summary' => [
                        'local_mapping_id'           => 'map_summary',
                        'central_action_id'          => 'entry_evaluation',
                        'action_name_label'          => 'Summarize Elementor submission',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [
                            'async' => true,
                        ],
                    ],
                ]
            )
        );

        $this->assertNull( $adapter->handle_new_record( $this->elementor_submission_record(), null ) );
        $this->assertSame( [], $scheduled_jobs );

        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_action', $scheduled_jobs[0]['hook'] ?? null );
        $this->assertSame( 'sentient_forms_async', $scheduled_jobs[0]['group'] ?? null );

        $job_context = $scheduled_jobs[0]['args']['context'] ?? [];
        $this->assertSame( 'elementor_forms', $job_context['form_source'] ?? null );
        $this->assertSame( 'elementor_pro/forms/new_record', $job_context['hook'] ?? null );
        $this->assertSame( $form_id, $job_context['form_id'] ?? null );
        $this->assertNull( $job_context['entry_id'] ?? null );
        $this->assertSame( 'map_summary', $job_context['local_mapping_id'] ?? null );
        $this->assertSame( 'entry_evaluation', $job_context['central_action_id'] ?? null );
        $this->assertSame( $submission_uuid, $job_context['submission_uuid'] ?? null );

        $job_entry = $scheduled_jobs[0]['args']['data']['entry'] ?? [];
        $this->assertSame( 'elementor_forms', $scheduled_jobs[0]['args']['data']['form_source'] ?? null );
        $this->assertNull( $job_entry['id'] ?? null );
        $this->assertSame( $submission_uuid, $job_entry['submission_uuid'] ?? null );
        $this->assertSame( 'elementor_forms', $job_entry['form_source'] ?? null );
        $this->assertSame( $form_id, $job_entry['form_id'] ?? null );
        $this->assertSame( 'Ada Lovelace', $job_entry['full_name'] ?? null );
        $this->assertSame( 'resume', $job_entry['file_refs'][0]['field_id'] ?? null );
        $this->assertArrayNotHasKey( 'captcha', $job_entry );
    }

    public function test_new_record_passes_dependency_metadata_to_elementor_async_jobs(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $this->assertTrue(
            $adapter->update_form_settings(
                $form_id,
                [
                    'map_first'  => [
                        'local_mapping_id'           => 'map_first',
                        'central_action_id'          => 'entry_evaluation',
                        'action_name_label'          => 'First Elementor async action',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [
                            'async' => true,
                        ],
                    ],
                    'map_second' => [
                        'local_mapping_id'           => 'map_second',
                        'central_action_id'          => 'entry_evaluation',
                        'action_name_label'          => 'Dependent Elementor async action',
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
                ]
            )
        );

        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

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
        $upstream_record = Sentient_Forms_Plugin::instance()->get_async_request_store()->get(
            (string) $contexts['map_second']['dependency_execution_request_ids']['map_first']
        );
        $this->assertIsArray( $upstream_record );
        $this->assertSame( 'queued', $upstream_record['status'] ?? null );
        $this->assertSame( 'entry_evaluation', $upstream_record['action_id'] ?? null );
        $this->assertSame( 'elementor_forms', $upstream_record['adapter'] ?? null );
        $this->assertSame( 45, $contexts['map_second']['dependency_wait_max_seconds'] ?? null );
        $this->assertSame( 10, $contexts['map_second']['dependency_wait_poll_seconds'] ?? null );
    }

    public function test_get_form_settings_reads_controller_saved_provider_native_action_key(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id = $this->create_elementor_form_page();
        $form_id = $page_id . ':formabc';

        $controller = new Sentient_Forms_Form_Actions_Controller();
        $request    = new WP_REST_Request( 'POST', '/sentient-forms/v1/elementor_forms/forms/' . rawurlencode( $form_id ) . '/actions' );
        $request->set_param( 'form_source_slug', 'elementor_forms' );
        $request->set_param( 'form_id', $form_id );
        $request->set_param( 'central_action_id', 'remote_summary_v1' );
        $request->set_param( 'action_type_indicator', 'master' );
        $request->set_param( 'trigger_hooks', [ 'after_submission' ] );

        $response = $controller->add_form_action( $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        $created = $response->get_data();

        $adapter       = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $form_settings = $adapter->get_form_settings( $form_id );

        $this->assertArrayHasKey( $created['local_mapping_id'], $form_settings );
        $this->assertSame( 'remote_summary_v1', $form_settings[ $created['local_mapping_id'] ]['central_action_id'] ?? null );
    }

    public function test_new_record_schedules_action_with_elementor_field_manifest_context(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $this->assertTrue(
            $adapter->update_form_settings(
                $form_id,
                [
                    'map_summary' => [
                        'local_mapping_id'           => 'map_summary',
                        'central_action_id'          => 'entry_evaluation',
                        'action_name_label'          => 'Summarize Elementor submission',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [
                            'async' => true,
                        ],
                    ],
                ]
            )
        );

        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );

        $job_form = $scheduled_jobs[0]['args']['data']['form'] ?? [];
        $this->assertSame( $form_id, $job_form['id'] ?? null );
        $this->assertSame( 'Quote Request', $job_form['title'] ?? null );
        $this->assertIsArray( $job_form['fields'] ?? null );
        $this->assertSame( 'full_name', $job_form['fields'][0]['id'] ?? null );
        $this->assertSame( 'Full name', $job_form['fields'][0]['label'] ?? null );
        $this->assertSame( 'text', $job_form['fields'][0]['type'] ?? null );
        $this->assertTrue( $job_form['fields'][0]['storage_eligible'] ?? false );
        $this->assertSame( 'resume', $job_form['fields'][3]['id'] ?? null );
        $this->assertFalse( $job_form['fields'][3]['storage_eligible'] ?? true );
        $this->assertTrue( $job_form['fields'][3]['file_reference_eligible'] ?? false );
    }

    public function test_new_record_suppresses_stale_native_effects_in_scheduled_option_backed_mapping(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $scheduled_jobs = [];
        add_action(
            'sentient_forms_async_job_scheduled',
            static function ( string $hook, array $args, string $group, mixed $action_id, int $run_at ) use ( &$scheduled_jobs ): void {
                $scheduled_jobs[] = compact( 'hook', 'args', 'group', 'action_id', 'run_at' );
            },
            10,
            5
        );

        $adapter = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $this->assertTrue(
            $adapter->update_form_settings(
                $form_id,
                [
                    'map_spam' => [
                        'local_mapping_id'           => 'map_spam',
                        'central_action_id'          => 'spam_detection_v1',
                        'action_name_label'          => 'Elementor Spam Detection',
                        'action_type_indicator'      => 'master',
                        'is_action_enabled_for_form' => true,
                        'trigger_hooks'              => [ 'after_submission' ],
                        'settings'                   => [
                            'async'                         => true,
                            'effect_mapping_json'           => [
                                'store_result'                   => true,
                                'store_result_meta'              => true,
                                'meta'                           => [ 'spam_confidence' => 'confidence' ],
                                'entry_note'                     => [ 'template' => 'Spam: {{classification}}' ],
                                'mark_as_spam'                   => true,
                                'spam'                           => [
                                    'enabled'                        => true,
                                    'classification_path'            => 'classification',
                                    'confidence_path'                => 'confidence',
                                    'confidence_threshold'           => 0.8,
                                    'note'                           => [ 'template' => 'Spam: {{classification}}' ],
                                    'suppress_notifications_on_spam' => true,
                                    'suppress_webhooks_on_spam'      => true,
                                    'skip_downstream_on_spam'        => true,
                                ],
                                'suppress_notifications_on_spam' => true,
                                'suppress_webhooks_on_spam'      => true,
                            ],
                            'suppress_notifications_on_spam' => true,
                            'suppress_webhooks_on_spam'      => true,
                            'skip_downstream_on_spam'        => true,
                            'spam_confidence_threshold'      => 0.8,
                            'spam_result_display_mode'       => 'all_results',
                            'spam_indicators_display'        => 'detailed',
                        ],
                    ],
                ]
            )
        );

        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_action', $scheduled_jobs[0]['hook'] ?? null );

        $scheduled_settings = $scheduled_jobs[0]['args']['settings']['settings'] ?? [];
        $scheduled_map      = $scheduled_settings['effect_mapping_json'] ?? null;

        $this->assertSame( 'spam_detection_v1', $scheduled_jobs[0]['args']['action_id'] ?? null );
        $this->assertIsArray( $scheduled_map );
        $this->assertArrayNotHasKey( 'store_result', $scheduled_map );
        $this->assertArrayNotHasKey( 'store_result_meta', $scheduled_map );
        $this->assertArrayNotHasKey( 'meta', $scheduled_map );
        $this->assertArrayNotHasKey( 'entry_note', $scheduled_map );
        $this->assertSame( [ 'skip_downstream_on_spam' => true ], $scheduled_map['spam'] ?? null );
        $this->assertArrayNotHasKey( 'mark_as_spam', $scheduled_map );
        $this->assertArrayNotHasKey( 'suppress_notifications_on_spam', $scheduled_map );
        $this->assertArrayNotHasKey( 'suppress_webhooks_on_spam', $scheduled_map );
        $this->assertArrayNotHasKey( 'suppress_notifications_on_spam', $scheduled_settings );
        $this->assertArrayNotHasKey( 'suppress_webhooks_on_spam', $scheduled_settings );
        $this->assertTrue( $scheduled_settings['skip_downstream_on_spam'] ?? false );
        $this->assertArrayNotHasKey( 'spam_confidence_threshold', $scheduled_settings );
        $this->assertArrayNotHasKey( 'spam_result_display_mode', $scheduled_settings );
        $this->assertArrayNotHasKey( 'spam_indicators_display', $scheduled_settings );
    }

    public function test_new_record_schedules_local_first_mapping_with_submission_uuid_without_native_entry_id(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $events         = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => 'elementor_local_summary',
                'display_name'         => 'Elementor Local Summary',
                'definition_json'      => [
                    'prompt' => 'Summarize {{entry}}.',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'elementor_forms',
                'form_id'             => $form_id,
                'hook'                => 'after_submission',
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

        $adapter       = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $runtime_key   = 'local_first_' . $mapping_id;
        $form_settings = $adapter->get_form_settings( $form_id );
        $this->assertArrayHasKey( $runtime_key, $form_settings );
        $this->assertSame( 'elementor_local_summary', $form_settings[ $runtime_key ]['central_action_id'] ?? null );

        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_local_mapping', $scheduled_jobs[0]['hook'] ?? null );
        $this->assertSame( 'sentient_forms_async', $scheduled_jobs[0]['group'] ?? null );

        $payload = $scheduled_jobs[0]['args'][0] ?? [];
        $this->assertSame( $mapping_id, $payload['local_mapping_id'] ?? null );
        $this->assertSame( 'elementor_forms', $payload['form_source'] ?? null );
        $this->assertSame( $form_id, $payload['form_id'] ?? null );
        $this->assertNull( $payload['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $payload['submission_uuid'] ?? null );
        $this->assertSame( $runtime_key, $payload['context']['local_mapping_id'] ?? null );
        $this->assertSame( 'elementor_pro/forms/new_record', $payload['context']['hook'] ?? null );
        $this->assertSame( 'elementor_local_summary', $payload['context']['central_action_id'] ?? null );

        $resolved_entry = $adapter->get_entry_data( $submission_uuid, $form_id );
        $this->assertIsArray( $resolved_entry );
        $this->assertNull( $resolved_entry['id'] ?? null );
        $this->assertSame( $submission_uuid, $resolved_entry['submission_uuid'] ?? null );
        $this->assertSame( 'elementor_forms', $resolved_entry['form_source'] ?? null );
        $this->assertSame( $form_id, $resolved_entry['form_id'] ?? null );
        $this->assertSame( 'Ada Lovelace', $resolved_entry['full_name'] ?? null );
        $this->assertNull( $adapter->get_entry_data( $submission_uuid, '999:missing' ) );

        $event = $events->list_recent( 1 )[0] ?? null;
        $this->assertIsArray( $event );
        $this->assertSame( 'queued', $event['status'] ?? null );
        $this->assertSame( $mapping_id, (int) ( $event['mapping_id'] ?? 0 ) );
        $this->assertSame( 'elementor_forms', $event['form_source'] ?? null );
        $this->assertSame( $form_id, $event['form_id'] ?? null );
        $this->assertNull( $event['entry_id'] ?? null );
        $this->assertSame( $submission_uuid, $event['submission_uuid'] ?? null );
    }

    public function test_new_record_suppresses_stale_native_effects_in_scheduled_local_first_mapping(): void
    {
        global $wpdb;

        if ( function_exists( 'sentient_forms_tests_reset_async_state' ) )
        {
            sentient_forms_tests_reset_async_state();
        }

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $page_id         = $this->create_elementor_form_page();
        $form_id         = $page_id . ':formabc';
        $ledger_settings = new Sentient_Forms_Submission_Ledger_Settings_Repository( $wpdb );
        $ledger_settings->set_enabled( 'elementor_forms', $form_id, true, self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $custom_actions = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings       = new Sentient_Forms_Form_Mappings_Repository( $wpdb );

        $action_id = $custom_actions->create(
            [
                'code'                 => Sentient_Forms_Bundled_Action_Templates::build_managed_custom_action_code( 'spam_detection_v1' ),
                'display_name'         => 'Elementor Spam Detection',
                'definition_json'      => [
                    'template_code' => 'spam_detection_v1',
                ],
                'model_selection_json' => [
                    'provider' => 'openrouter',
                    'model'    => 'openrouter/auto',
                ],
                'status'               => 'active',
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'elementor_forms',
                'form_id'             => $form_id,
                'hook'                => 'after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'classification' => 'spam_result.classification',
                    'confidence'     => 'spam_result.confidence',
                ],
                'execution_mode'      => 'async',
                'effect_mapping_json' => [
                    'store_result'                   => true,
                    'store_result_meta'              => true,
                    'meta'                           => [ 'spam_confidence' => 'confidence' ],
                    'entry_note'                     => [ 'template' => 'Spam: {{classification}}' ],
                    'mark_as_spam'                   => true,
                    'spam'                           => [
                        'enabled'                        => true,
                        'classification_path'            => 'classification',
                        'confidence_path'                => 'confidence',
                        'confidence_threshold'           => 0.8,
                        'note'                           => [ 'template' => 'Spam: {{classification}}' ],
                        'suppress_notifications_on_spam' => true,
                        'suppress_webhooks_on_spam'      => true,
                    ],
                    'suppress_notifications_on_spam' => true,
                    'suppress_webhooks_on_spam'      => true,
                ],
                'settings_json'       => [
                    'suppress_notifications_on_spam' => true,
                    'suppress_webhooks_on_spam'      => true,
                    'spam_confidence_threshold'      => 0.8,
                    'spam_result_display_mode'       => 'all_results',
                    'spam_indicators_display'        => 'detailed',
                ],
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

        $adapter         = new Sentient_Forms_Elementor_Forms_Adapter( Sentient_Forms_Plugin::instance() );
        $submission_uuid = $adapter->handle_new_record( $this->elementor_submission_record(), null );

        $this->assertNotNull( $submission_uuid );
        $this->assertCount( 1, $scheduled_jobs );
        $this->assertSame( 'sentient_forms_process_local_mapping', $scheduled_jobs[0]['hook'] ?? null );

        $payload        = $scheduled_jobs[0]['args'][0] ?? [];
        $scheduled_map  = $payload['context']['settings']['effect_mapping_json'] ?? null;
        $scheduled_conf = $payload['context']['settings'] ?? [];

        $this->assertSame( $mapping_id, $payload['local_mapping_id'] ?? null );
        $this->assertSame( 'spam_detection_v1', $payload['context']['central_action_id'] ?? null );
        $this->assertIsArray( $scheduled_map );
        $this->assertArrayNotHasKey( 'store_result', $scheduled_map );
        $this->assertArrayNotHasKey( 'store_result_meta', $scheduled_map );
        $this->assertArrayNotHasKey( 'meta', $scheduled_map );
        $this->assertArrayNotHasKey( 'entry_note', $scheduled_map );
        $this->assertArrayNotHasKey( 'spam', $scheduled_map );
        $this->assertArrayNotHasKey( 'mark_as_spam', $scheduled_map );
        $this->assertArrayNotHasKey( 'suppress_notifications_on_spam', $scheduled_map );
        $this->assertArrayNotHasKey( 'suppress_webhooks_on_spam', $scheduled_map );
        $this->assertArrayNotHasKey( 'suppress_notifications_on_spam', $scheduled_conf );
        $this->assertArrayNotHasKey( 'suppress_webhooks_on_spam', $scheduled_conf );
        $this->assertArrayNotHasKey( 'spam_confidence_threshold', $scheduled_conf );
        $this->assertArrayNotHasKey( 'spam_result_display_mode', $scheduled_conf );
        $this->assertArrayNotHasKey( 'spam_indicators_display', $scheduled_conf );
    }

    private function create_elementor_form_page( ?array $form_fields = null, string $widget_id = 'formabc', string $form_name = 'Quote Request' ): int
    {
        $page_id = self::factory()->post->create(
            [
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Landing Page',
            ]
        );

        update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $this->elementor_form_tree( $form_fields, $widget_id, $form_name ) ) ) );
        add_filter( 'sentient_forms_elementor_posts_with_data', static fn() => [ $page_id ] );

        return $page_id;
    }

    private function elementor_submission_record( ?array $fields = null ): object
    {
        return new class( $fields ) {
            public function __construct( private ?array $fields )
            {
            }

            public function get( string $key ): mixed
            {
                if ( 'fields' !== $key )
                {
                    return null;
                }

                if ( null !== $this->fields )
                {
                    return $this->fields;
                }

                return [
                    'full_name' => [
                        'id'    => 'full_name',
                        'title' => 'Full name',
                        'type'  => 'text',
                        'value' => 'Ada Lovelace',
                    ],
                    'email'     => [
                        'id'    => 'email',
                        'title' => 'Email',
                        'type'  => 'email',
                        'value' => 'ada@example.test',
                    ],
                    'tracking_code' => [
                        'id'    => 'tracking_code',
                        'title' => 'Tracking code',
                        'type'  => 'hidden',
                        'value' => 'do-not-store',
                    ],
                    'resume'    => [
                        'id'    => 'resume',
                        'title' => 'Resume',
                        'type'  => 'upload',
                        'value' => 'C:\\private\\resume.pdf',
                    ],
                    'captcha'   => [
                        'id'    => 'captcha',
                        'title' => 'Captcha',
                        'type'  => 'recaptcha',
                        'value' => 'do-not-store',
                    ],
                ];
            }

            public function get_form_settings( ?string $key = null ): mixed
            {
                $settings = [
                    'form_name' => 'Quote Request',
                ];

                return null === $key ? $settings : ( $settings[ $key ] ?? null );
            }
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function elementor_form_tree( ?array $form_fields = null, string $widget_id = 'formabc', string $form_name = 'Quote Request' ): array
    {
        $form_fields ??= [
            [
                'custom_id'   => 'full_name',
                'field_label' => 'Full name',
                'field_type'  => 'text',
                'required'    => 'true',
            ],
            [
                'custom_id'   => 'email',
                'field_label' => 'Email',
                'field_type'  => 'email',
                'required'    => 'true',
            ],
            [
                'custom_id'   => 'tracking_code',
                'field_label' => 'Tracking code',
                'field_type'  => 'hidden',
            ],
            [
                'custom_id'   => 'resume',
                'field_label' => 'Resume',
                'field_type'  => 'upload',
            ],
            [
                'custom_id'  => 'captcha',
                'field_type' => 'recaptcha',
            ],
        ];

        return [
            [
                'id'       => 'container1',
                'elType'   => 'container',
                'settings' => [],
                'elements' => [
                    [
                        'id'         => $widget_id,
                        'elType'     => 'widget',
                        'widgetType' => 'form',
                        'settings'   => [
                            'form_name'   => $form_name,
                            'form_fields' => $form_fields,
                        ],
                        'elements'   => [],
                    ],
                ],
            ],
        ];
    }
}
