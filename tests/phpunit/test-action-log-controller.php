<?php
/**
 * PHPUnit tests for Action Log Controller.
 * Tests T-PHP-006, T-PHP-007, T-PHP-008, T-PHP-009.
 *
 * @package Sentient_Forms
 */

class Tests_Action_Log_Controller extends WP_UnitTestCase
{
    private Sentient_Forms_Action_Log_Controller $controller;
    private const OPTION_KEY = 'sentient_forms_action_log';

    protected function setUp(): void
    {
        parent::setUp();
        Sentient_Forms_Installer::maybe_upgrade();
        $this->truncate_local_workspace_tables();
        delete_option( self::OPTION_KEY );
        Sentient_Forms_Plugin::instance()->clear_license_data();
        $this->controller = new Sentient_Forms_Action_Log_Controller();
    }

    protected function tearDown(): void
    {
        delete_option( self::OPTION_KEY );
        $this->truncate_local_workspace_tables();
        Sentient_Forms_Plugin::instance()->clear_license_data();
        remove_all_filters( 'pre_http_request' );
        parent::tearDown();
    }

    /**
     * T-PHP-006: Test that action log endpoint is registered.
     * Tests FR-007: Action Log REST API endpoints registered.
     */
    public function test_action_log_endpoint_registered(): void
    {
        do_action( 'rest_api_init' );
        $this->controller->register_routes();

        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey(
            '/sentient-forms/v1/actions/log',
            $routes,
            'Action log route should be registered'
        );
    }

    /**
     * T-PHP-007: Test that action log returns entries.
     * Tests FR-006: GET returns paginated list.
     */
    public function test_action_log_returns_entries(): void
    {
        // Seed some test entries
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'  => 'gravity_forms',
            'form_id'      => 1,
            'entry_id'     => 100,
            'action_code'  => 'spam_detection_v1',
            'action_label' => 'Spam Detection',
            'status'       => 'success',
            'classification' => 'ham',
            'credits_used' => 10,
        ] );

        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'  => 'gravity_forms',
            'form_id'      => 1,
            'entry_id'     => 101,
            'action_code'  => 'spam_detection_v1',
            'action_label' => 'Spam Detection',
            'status'       => 'success',
            'classification' => 'spam',
            'credits_used' => 10,
        ] );

        $entries = get_option( self::OPTION_KEY, [] );

        $this->assertCount( 2, $entries );
        $this->assertSame( 'ham', $entries[1]['classification'] ); // First inserted, now at index 1
        $this->assertSame( 'spam', $entries[0]['classification'] ); // Second inserted, now at index 0 (prepended)
    }

    /**
     * T-PHP-008: Test that action log requires authentication.
     * Tests FR-007: Endpoint requires manage_options capability.
     */
    public function test_action_log_requires_auth(): void
    {
        do_action( 'rest_api_init' );
        $this->controller->register_routes();

        // Create request as unauthenticated user
        wp_set_current_user( 0 );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = rest_do_request( $request );

        // Should return 401 or 403 for unauthenticated requests
        $this->assertContains(
            $response->get_status(),
            [ 401, 403 ],
            'Unauthenticated requests should be forbidden'
        );
    }

    /**
     * T-PHP-009: Test that action execution is persisted to log.
     * Tests FR-008: log_execution() persists to storage.
     */
    public function test_action_execution_persisted_to_log(): void
    {
        $result = Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'    => 'gravity_forms',
            'form_id'        => 5,
            'entry_id'       => 500,
            'action_code'    => 'entry_summary_v1',
            'action_label'   => 'Entry Summary',
            'status'         => 'success',
            'result_summary' => 'This is a valid lead inquiry about pricing.',
            'credits_used'   => 8,
        ] );

        $this->assertTrue( $result, 'log_execution should return true on success' );

        $entries = get_option( self::OPTION_KEY, [] );
        $this->assertCount( 1, $entries );

        $entry = $entries[0];
        $this->assertSame( 'gravity_forms', $entry['form_source'] );
        $this->assertSame( 5, $entry['form_id'] );
        $this->assertSame( 500, $entry['entry_id'] );
        $this->assertSame( 'entry_summary_v1', $entry['action_code'] );
        $this->assertSame( 'success', $entry['status'] );
        $this->assertSame( 8, $entry['credits_used'] );
        $this->assertNotEmpty( $entry['id'] ); // UUID should be generated
        $this->assertNotEmpty( $entry['created_at'] ); // Timestamp should be set
    }

    /**
     * Test static log_execution handles errors correctly.
     */
    public function test_log_execution_with_error_status(): void
    {
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'   => 'gravity_forms',
            'form_id'       => 3,
            'entry_id'      => 300,
            'action_code'   => 'spam_detection_v1',
            'action_label'  => 'Spam Detection',
            'status'        => 'error',
            'error_code'    => 'timeout',
            'error_message' => 'CPS request timed out after 30 seconds',
        ] );

        $entries = get_option( self::OPTION_KEY, [] );
        $this->assertCount( 1, $entries );

        $entry = $entries[0];
        $this->assertSame( 'error', $entry['status'] );
        $this->assertSame( 'timeout', $entry['error_code'] );
        $this->assertStringContainsString( 'timed out', $entry['error_message'] );
    }

    public function test_log_execution_accepts_blocked_status(): void
    {
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'    => 'gravity_forms',
            'form_id'        => 7,
            'entry_id'       => 701,
            'action_code'    => 'spam_detection_v1',
            'action_label'   => 'Spam Detection',
            'status'         => 'blocked',
            'classification' => 'spam',
            'result_summary' => 'Submission blocked as spam.',
            'credits_used'   => 3,
        ] );

        $entries = get_option( self::OPTION_KEY, [] );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'blocked', $entries[0]['status'] );
        $this->assertSame( 'spam', $entries[0]['classification'] );
        $this->assertSame( 3, $entries[0]['credits_used'] );
    }

    /**
     * Test log entries are limited to retention limit.
     */
    public function test_log_entries_limited_to_retention(): void
    {
        // Create 505 entries (5 over the 500 limit)
        for ( $i = 0; $i < 505; $i++ )
        {
            Sentient_Forms_Action_Log_Controller::log_execution( [
                'form_source'  => 'gravity_forms',
                'form_id'      => 1,
                'entry_id'     => $i,
                'action_code'  => 'test_action',
                'action_label' => 'Test',
                'status'       => 'success',
            ] );
        }

        $entries = get_option( self::OPTION_KEY, [] );

        // Should be capped at 500
        $this->assertLessThanOrEqual( 500, count( $entries ) );
    }

    /**
     * Test filtering entries by form_id.
     */
    public function test_log_filtering_by_form_id(): void
    {
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source' => 'gravity_forms',
            'form_id' => 1,
            'action_code' => 'spam_detection_v1',
            'action_label' => 'Spam',
            'status' => 'success',
        ] );

        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source' => 'gravity_forms',
            'form_id' => 2,
            'action_code' => 'spam_detection_v1',
            'action_label' => 'Spam',
            'status' => 'success',
        ] );

        // Use reflection to test private get_all_entries + filtering logic
        $method = new ReflectionMethod( $this->controller, 'get_all_entries' );
        $method->setAccessible( true );
        $all_entries = $method->invoke( $this->controller );

        // Filter manually as the controller does
        $filtered = array_filter( $all_entries, fn( $e ) => ( $e['form_id'] ?? 0 ) === 1 );

        $this->assertCount( 1, $filtered );
    }

    /**
     * T-PHP-035: Test that log_execution persists structured_output_valid.
     * Verifies the field is true when provided and defaults to false when omitted.
     */
    public function test_log_execution_persists_structured_output_valid(): void
    {
        // Entry WITH structured_output_valid = true
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'              => 'gravity_forms',
            'form_id'                  => 10,
            'entry_id'                 => 1000,
            'action_code'              => 'custom_action_v1',
            'action_label'             => 'Custom Action',
            'status'                   => 'success',
            'structured_output_valid'  => true,
            'credits_used'             => 5,
        ] );

        // Entry WITHOUT structured_output_valid (should default to false)
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'  => 'gravity_forms',
            'form_id'      => 10,
            'entry_id'     => 1001,
            'action_code'  => 'spam_detection_v1',
            'action_label' => 'Spam Detection',
            'status'       => 'success',
            'credits_used' => 5,
        ] );

        $entries = get_option( self::OPTION_KEY, [] );
        $this->assertCount( 2, $entries );

        // Entries are prepended (newest first), so index 0 = second entry, index 1 = first entry
        $this->assertTrue( $entries[1]['structured_output_valid'], 'structured_output_valid should be true when provided' );
        $this->assertFalse( $entries[0]['structured_output_valid'], 'structured_output_valid should default to false when omitted' );
    }

    public function test_get_log_entries_uses_local_execution_events_even_when_proxy_key_exists(): void
    {
        $mapping_id = $this->seed_local_custom_action_mapping_and_event();

        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-log-123',
            ]
        );

        $captured_requests = 0;
        add_filter(
            'pre_http_request',
            function ( $preempt ) use ( &$captured_requests ) {
                $captured_requests++;
                return $preempt;
            }
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();

        $this->assertSame( 1, $data['total'] );
        $this->assertSame( 0, $captured_requests, 'Action log reads should not call the CPS audit endpoint.' );
        $this->assertStringStartsWith( 'local-event-', $data['entries'][0]['id'] );
        $this->assertSame( 'contact_spam_triage', $data['entries'][0]['action_code'] );
        $this->assertSame( 'Contact Spam Triage', $data['entries'][0]['action_label'] );
        $this->assertSame( 'success', $data['entries'][0]['status'] );
        $this->assertSame( 'ham', $data['entries'][0]['classification'] );
        $this->assertSame( 0, $data['entries'][0]['credits_used'] );
        $this->assertSame( 'req-local-log-1', $data['entries'][0]['execution_request_id'] );
        $this->assertSame( 'local_first_' . $mapping_id, $data['entries'][0]['mapping_id'] );
        $this->assertSame( 'openrouter/auto', $data['entries'][0]['resolved_model_id'] );
        $this->assertSame( 'local_execution_events', $data['entries'][0]['details']['source'] );
        $this->assertSame( 0, $data['entries'][0]['pricing']['debited_credits'] );
    }

    public function test_get_log_entries_filters_unbacked_local_first_legacy_success_rows(): void
    {
        update_option(
            self::OPTION_KEY,
            [
                [
                    'id'                   => 'legacy-local-success',
                    'form_source'          => 'gravity_forms',
                    'form_id'              => 42,
                    'entry_id'             => 113,
                    'action_code'          => 'sentient_forms_local_custom_action',
                    'action_label'         => 'Local OpenRouter action',
                    'status'               => 'success',
                    'result_summary'       => 'Synthetic imported success.',
                    'execution_request_id' => 'req-synthetic-local-success',
                    'mapping_id'           => 'local_first_12',
                    'created_at'           => '2026-04-21T11:00:00+00:00',
                ],
            ],
            false
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();

        $this->assertSame( 0, $data['total'] );
        $this->assertSame( [], $data['entries'] );
    }

    public function test_log_execution_stays_local_when_proxy_key_present(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-log-456',
            ]
        );

        $captured_requests = 0;

        add_filter(
            'pre_http_request',
            function ( $preempt ) use ( &$captured_requests ) {
                $captured_requests++;
                return $preempt;
            }
        );

        $result = Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'          => 'gravity_forms',
            'form_id'              => 5,
            'entry_id'             => 500,
            'action_code'          => 'entry_summary_v1',
            'action_label'         => 'Entry Summary',
            'status'               => 'success',
            'execution_request_id' => 'req-log-mirror',
            'mapping_id'           => 'map-log-mirror',
            'resolved_model_id'    => 'gemini-pro',
            'pricing'              => [ 'debited_credits' => 8 ],
            'details'              => [ 'source' => 'phpunit' ],
        ] );

        $this->assertTrue( $result );
        $this->assertSame( 0, $captured_requests, 'Local option-backed log writes should not mirror to CPS.' );

        $entries = get_option( self::OPTION_KEY, [] );
        $this->assertCount( 1, $entries );
        $this->assertSame( 'req-log-mirror', $entries[0]['execution_request_id'] ?? null );
        $this->assertSame( 'map-log-mirror', $entries[0]['mapping_id'] ?? null );
        $this->assertSame( 'gemini-pro', $entries[0]['resolved_model_id'] ?? null );
        $this->assertSame( 8, $entries[0]['pricing']['debited_credits'] ?? null );
    }

    public function test_backfill_entry_id_for_execution_requests_updates_local_rows_without_cps_mirror(): void
    {
        Sentient_Forms_Plugin::instance()->set_license_data(
            [
                'proxy_api_key' => 'proxy-log-backfill',
            ]
        );

        $captured_requests = 0;

        add_filter(
            'pre_http_request',
            function ( $preempt ) use ( &$captured_requests ) {
                $captured_requests++;
                return $preempt;
            }
        );

        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'          => 'gravity_forms',
            'form_id'              => 77,
            'entry_id'             => null,
            'action_code'          => 'content_validation_v1',
            'action_label'         => 'Content Quality',
            'status'               => 'success',
            'execution_request_id' => 'req-validation-backfill',
            'mapping_id'           => 'map-validation-backfill',
        ] );

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-validation-backfill',
                'mapping_id'           => 12,
                'form_source'          => 'gravity_forms',
                'form_id'              => '77',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
            ]
        );

        $updated = Sentient_Forms_Action_Log_Controller::backfill_entry_id_for_execution_requests(
            [ 'req-validation-backfill' ],
            707,
            'gravity_forms',
            77
        );

        $entries = get_option( self::OPTION_KEY, [] );
        $event   = $events->get_by_request_id( 'req-validation-backfill' );

        $this->assertSame( 2, $updated );
        $this->assertSame( 707, $entries[0]['entry_id'] ?? null );
        $this->assertSame( '707', $event['entry_id'] ?? null );
        $this->assertSame( 0, $captured_requests );
    }

    private function seed_local_custom_action_mapping_and_event(): int
    {
        global $wpdb;

        $actions  = new Sentient_Forms_Local_Custom_Actions_Repository( $wpdb );
        $mappings = new Sentient_Forms_Form_Mappings_Repository( $wpdb );
        $events   = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $action_id = $actions->create(
            [
                'code'            => 'contact_spam_triage',
                'display_name'    => 'Contact Spam Triage',
                'definition_json' => [
                    'prompt' => 'Classify the entry.',
                ],
            ]
        );
        $this->assertIsInt( $action_id );

        $mapping_id = $mappings->create(
            [
                'form_source'         => 'gravity_forms',
                'form_id'             => '7',
                'hook'                => 'gform_after_submission',
                'action_kind'         => 'custom_action',
                'action_id'           => $action_id,
                'input_bindings_json' => [
                    'email' => '3',
                ],
                'effect_mapping_json' => [
                    'entry_note' => true,
                ],
                'execution_mode'      => 'async',
                'enabled'             => true,
            ]
        );
        $this->assertIsInt( $mapping_id );

        $event_id = $events->record(
            [
                'execution_request_id' => 'req-local-log-1',
                'mapping_id'           => $mapping_id,
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '77',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'token_usage_json'     => [
                    'prompt_tokens'     => 11,
                    'completion_tokens' => 5,
                ],
                'result_json'          => [
                    'content'    => 'Submission looks legitimate.',
                    'structured' => [
                        'classification' => 'ham',
                        'summary'        => 'Submission looks legitimate.',
                        'confidence'     => 0.93,
                    ],
                ],
            ]
        );
        $this->assertIsInt( $event_id );

        return $mapping_id;
    }

    private function truncate_local_workspace_tables(): void
    {
        global $wpdb;

        foreach (
            [
                'sentient_action_templates',
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
