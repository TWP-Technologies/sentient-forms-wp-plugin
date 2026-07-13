<?php
/**
 * PHPUnit tests for Action Log Controller.
 * Tests T-PHP-006, T-PHP-007, T-PHP-008, T-PHP-009.
 *
 * @package Sentient_Forms
 */

if ( ! class_exists( 'GFAPI' ) )
{
    class GFAPI
    {
        /** @var array<int,array<string,mixed>> */
        public static array $entries = [];

        /** @var array<int,array<string,mixed>> */
        public static array $forms = [];

        public static int $get_entry_calls = 0;

        public static int $get_form_calls = 0;

        public static bool $skip_field_values_on_full_entry_update = false;

        public static mixed $maybe_process_feeds_result = [];

        public static ?Throwable $maybe_process_feeds_exception = null;

        /** @var array<int,array<string,mixed>> */
        public static array $maybe_process_feeds_calls = [];

        public static function get_entry( $entry_id )
        {
            ++self::$get_entry_calls;
            $entry_id = (int) $entry_id;
            if ( isset( self::$entries[ $entry_id ] ) )
            {
                return self::$entries[ $entry_id ];
            }

            return new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
        }

        public static function get_form( $form_id )
        {
            ++self::$get_form_calls;
            $form_id = (int) $form_id;
            return self::$forms[ $form_id ] ?? false;
        }

        public static function get_forms(): array
        {
            return array_values( self::$forms );
        }

        public static function update_form( $form, $form_id = null )
        {
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

        public static function update_entry_property( $entry_id, $property, $value )
        {
            $entry_id = (int) $entry_id;
            if ( ! isset( self::$entries[ $entry_id ] ) )
            {
                return new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
            }

            self::$entries[ $entry_id ][ (string) $property ] = $value;

            return true;
        }

        public static function update_entry( $entry )
        {
            if ( ! is_array( $entry ) || empty( $entry['id'] ) )
            {
                return new WP_Error( 'missing_entry_id', 'Missing entry id.' );
            }

            if ( self::$skip_field_values_on_full_entry_update && isset( self::$entries[ (int) $entry['id'] ] ) )
            {
                $merged = self::$entries[ (int) $entry['id'] ];
                foreach ( $entry as $key => $value )
                {
                    if ( preg_match( '/^\d+(?:\.\d+)?$/', (string) $key ) )
                    {
                        continue;
                    }

                    $merged[ $key ] = $value;
                }

                self::$entries[ (int) $entry['id'] ] = $merged;

                return true;
            }

            self::$entries[ (int) $entry['id'] ] = $entry;

            return true;
        }

        public static function update_entry_field( $entry_id, $field_id, $value )
        {
            $entry_id = (int) $entry_id;
            if ( ! isset( self::$entries[ $entry_id ] ) )
            {
                return new WP_Error( 'rest_entry_not_found', 'Entry not found.' );
            }

            self::$entries[ $entry_id ][ (string) $field_id ] = $value;

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
        GFAPI::$entries = [];
        GFAPI::$forms   = [];
        $this->reset_gfapi_stub_state();
        Sentient_Forms_Plugin::instance()->clear_license_data();
        $this->controller = new Sentient_Forms_Action_Log_Controller();
    }

    protected function tearDown(): void
    {
        delete_option( self::OPTION_KEY );
        $this->truncate_local_workspace_tables();
        GFAPI::$entries = [];
        GFAPI::$forms   = [];
        $this->reset_gfapi_stub_state();
        Sentient_Forms_Plugin::instance()->clear_license_data();
        remove_all_filters( 'pre_http_request' );
        remove_all_filters( 'sentient_forms_elementor_is_active' );
        remove_all_filters( 'sentient_forms_elementor_pro_forms_api_available' );
        remove_all_filters( 'sentient_forms_elementor_pro_form_submissions_api_available' );
        parent::tearDown();
    }

    private function reset_gfapi_stub_state(): void
    {
        GFAPI::$get_entry_calls                        = 0;
        GFAPI::$get_form_calls                         = 0;
        GFAPI::$skip_field_values_on_full_entry_update = false;
        GFAPI::$maybe_process_feeds_result              = [];
        GFAPI::$maybe_process_feeds_exception           = null;
        GFAPI::$maybe_process_feeds_calls               = [];
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
        $this->assertArrayHasKey(
            '/sentient-forms/v1/actions/log/(?P<log_id>[A-Za-z0-9_-]+)/entry-preview',
            $routes,
            'Action log entry preview route should be registered'
        );
    }

    public function test_gfapi_stub_state_reset_fixture_sets_dirty_state(): void
    {
        GFAPI::$get_entry_calls                        = 7;
        GFAPI::$get_form_calls                         = 9;
        GFAPI::$skip_field_values_on_full_entry_update = true;

        $this->assertSame( 7, GFAPI::$get_entry_calls );
        $this->assertSame( 9, GFAPI::$get_form_calls );
        $this->assertTrue( GFAPI::$skip_field_values_on_full_entry_update );
    }

    /**
     * @depends test_gfapi_stub_state_reset_fixture_sets_dirty_state
     */
    public function test_gfapi_stub_state_is_reset_between_action_log_tests(): void
    {
        $this->assertSame( 0, GFAPI::$get_entry_calls );
        $this->assertSame( 0, GFAPI::$get_form_calls );
        $this->assertFalse( GFAPI::$skip_field_values_on_full_entry_update );
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

    public function test_get_log_entries_serializes_empty_pricing_as_object(): void
    {
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'  => 'gravity_forms',
            'form_id'      => 1,
            'entry_id'     => 102,
            'action_code'  => 'empty_pricing_contract_v1',
            'action_label' => 'Empty Pricing Contract',
            'status'       => 'pending',
        ] );

        $response = $this->controller->get_log_entries( new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' ) );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $wire = json_decode( wp_json_encode( $response->get_data() ) );
        $this->assertIsObject( $wire );
        $matched = null;
        foreach ( $wire->entries ?? [] as $entry )
        {
            if ( 'empty_pricing_contract_v1' === ( $entry->action_code ?? null ) )
            {
                $matched = $entry;
                break;
            }
        }

        $this->assertIsObject( $matched );
        $this->assertIsObject( $matched->pricing ?? null );
        $this->assertIsObject( $matched->details ?? null );
    }

    public function test_get_log_entries_discards_indexed_pricing_and_details_metadata(): void
    {
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'  => 'gravity_forms',
            'form_id'      => 1,
            'entry_id'     => 103,
            'action_code'  => 'indexed_metadata_contract_v1',
            'action_label' => 'Indexed Metadata Contract',
            'status'       => 'pending',
            'pricing'      => [ 'unexpected-pricing-item' ],
            'details'      => [ 'unexpected-details-item' ],
        ] );

        $response = $this->controller->get_log_entries( new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' ) );
        $this->assertInstanceOf( WP_REST_Response::class, $response );

        $wire = json_decode( wp_json_encode( $response->get_data() ) );
        $this->assertIsObject( $wire );
        $matched = null;
        foreach ( $wire->entries ?? [] as $entry )
        {
            if ( 'indexed_metadata_contract_v1' === ( $entry->action_code ?? null ) )
            {
                $matched = $entry;
                break;
            }
        }

        $this->assertIsObject( $matched );
        $this->assertIsObject( $matched->pricing ?? null );
        $this->assertSame( [], array_keys( get_object_vars( $matched->pricing ) ) );
        $this->assertIsObject( $matched->details ?? null );
        $this->assertSame( [], array_keys( get_object_vars( $matched->details ) ) );
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

    public function test_get_log_entries_includes_form_context_and_quick_links(): void
    {
        GFAPI::$forms = [
            5 => [
                'id'     => 5,
                'title'  => 'Lead intake',
                'fields' => [],
            ],
        ];

        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'    => 'gravity_forms',
            'form_id'        => 5,
            'entry_id'       => 500,
            'action_code'    => 'entry_summary_v1',
            'action_label'   => 'Entry Summary',
            'status'         => 'success',
            'result_summary' => 'This is a valid lead inquiry about pricing.',
        ] );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();
        $context  = $data['entries'][0]['form_context'];

        $this->assertSame( 'Gravity Forms', $context['provider_label'] );
        $this->assertSame( 'Lead intake', $context['form_name'] );
        $this->assertSame( 5, $context['form_id'] );
        $this->assertSame( 500, $context['entry_id'] );
        $this->assertTrue( $context['entry_preview_available'] );
        $this->assertStringContainsString( 'page=gf_edit_forms', $context['links']['provider_admin_url'] );
        $this->assertStringContainsString( 'page=gf_edit_forms&id=5', $context['links']['form_admin_url'] );
        $this->assertStringContainsString( 'page=gf_entries&id=5', $context['links']['entries_admin_url'] );
        $this->assertStringContainsString( 'view=entry&id=5&lid=500', $context['links']['entry_admin_url'] );
    }

    public function test_entry_preview_endpoint_returns_safe_visible_field_summary(): void
    {
        GFAPI::$forms = [
            5 => [
                'id'     => 5,
                'title'  => 'Lead intake',
                'fields' => [
                    [
                        'id'    => 1,
                        'label' => 'Name',
                        'type'  => 'text',
                    ],
                    [
                        'id'         => 2,
                        'label'      => 'Internal Routing',
                        'type'       => 'text',
                        'visibility' => 'hidden',
                    ],
                    [
                        'id'    => 3,
                        'label' => 'Attachment',
                        'type'  => 'fileupload',
                    ],
                    [
                        'id'    => 4,
                        'label' => 'Project Details',
                        'type'  => 'textarea',
                    ],
                ],
            ],
        ];
        GFAPI::$entries = [
            500 => [
                'id'           => 500,
                'form_id'      => 5,
                'date_created' => '2026-05-21 09:15:00',
                'status'       => 'active',
                '1'            => 'Grace Buyer',
                '2'            => 'Do not expose this hidden routing value.',
                '3'            => '/private/uploads/contract.pdf',
                '4'            => 'We need help improving our intake workflow.',
            ],
        ];

        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'  => 'gravity_forms',
            'form_id'      => 5,
            'entry_id'     => 500,
            'action_code'  => 'entry_summary_v1',
            'action_label' => 'Entry Summary',
            'status'       => 'success',
        ] );

        $entries = get_option( self::OPTION_KEY, [] );
        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log/' . $entries[0]['id'] . '/entry-preview' );
        $request->set_param( 'log_id', $entries[0]['id'] );

        $response = $this->controller->get_entry_preview( $request );
        $data     = $response->get_data();

        $this->assertSame( 'Lead intake', $data['form_name'] );
        $this->assertSame( 500, $data['entry_id'] );
        $this->assertSame( 'Name', $data['fields'][0]['label'] );
        $this->assertSame( 'Grace Buyer', $data['fields'][0]['value'] );
        $this->assertSame( 'Project Details', $data['fields'][1]['label'] );
        $this->assertCount( 2, $data['fields'], 'Hidden and file-upload fields should not be exposed in previews.' );
        $this->assertStringNotContainsString( 'hidden routing', wp_json_encode( $data['fields'] ) );
        $this->assertStringNotContainsString( 'contract.pdf', wp_json_encode( $data['fields'] ) );
    }

    public function test_entry_preview_endpoint_returns_ledger_snapshot_for_non_gravity_sources(): void
    {
        global $wpdb;

        $submission_uuid = '11111111-2222-4333-8444-555555555555';
        $ledger          = new Sentient_Forms_Submission_Ledger_Repository( $wpdb );
        $ledger_id       = $ledger->create(
            [
                'submission_uuid'        => $submission_uuid,
                'form_source'            => 'contact_form_7',
                'form_id'                => '42',
                'logical_fields_json'    => [
                    'your_name'  => 'Ada Buyer',
                    'your_email' => 'ada@example.test',
                    'message'    => 'I need pricing help.',
                ],
                'provider_metadata_json' => [
                    'source' => 'contact_form_7',
                ],
                'file_refs_json'         => [
                    [
                        'field_id' => 'attachment',
                        'filename' => 'private.pdf',
                    ],
                ],
            ]
        );
        $this->assertIsInt( $ledger_id );

        $events   = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $event_id = $events->record(
            [
                'execution_request_id' => 'cf7-ledger-preview',
                'submission_uuid'      => $submission_uuid,
                'form_source'          => 'contact_form_7',
                'form_id'              => '42',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'summary' => 'Pricing request.',
                    ],
                ],
            ]
        );
        $this->assertIsInt( $event_id );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log/local-event-' . $event_id . '/entry-preview' );
        $request->set_param( 'log_id', 'local-event-' . $event_id );

        $response = $this->controller->get_entry_preview( $request );
        $data     = $response->get_data();

        $this->assertSame( 'ledger', $data['preview_source'] );
        $this->assertSame( $submission_uuid, $data['submission_uuid'] );
        $this->assertSame( 'Contact Form 7', $data['provider_label'] );
        $this->assertNull( $data['entry_id'] );
        $this->assertSame( 'Ada Buyer', $data['fields'][0]['value'] );
        $this->assertStringNotContainsString( 'private.pdf', wp_json_encode( $data['fields'] ) );
        $this->assertFalse( $data['capabilities']['native_entry']['id'] );
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
        $this->assertSame( 'openrouter_direct', $data['entries'][0]['usage_cost']['route'] );
        $this->assertSame( 'OR $0.0002', $data['entries'][0]['usage_cost']['label'] );
        $this->assertSame( 0.0002, $data['entries'][0]['usage_cost']['amount_usd'] );
        $this->assertSame( 'Submission looks legitimate.', $data['entries'][0]['details']['stored_result']['content'] );
    }

    public function test_get_log_entries_prefers_provider_native_event_identity_for_elementor_cps_events(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $events->record(
            [
                'execution_request_id' => 'req-elementor-provider-native-log',
                'mapping_id'           => 0,
                'mapping_key'          => 'map_summary',
                'action_code'          => 'entry_summary_v1',
                'action_label'         => 'Entry Summary',
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => '4:formabc',
                'entry_id'             => null,
                'submission_uuid'      => '44444444-5555-4666-8777-888888888888',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'success',
                'result_json'          => [
                    'content' => 'Summary stored for Elementor.',
                ],
            ]
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();

        $this->assertSame( 1, $data['total'] );
        $this->assertSame( 'entry_summary_v1', $data['entries'][0]['action_code'] );
        $this->assertSame( 'Entry Summary', $data['entries'][0]['action_label'] );
        $this->assertSame( 'map_summary', $data['entries'][0]['mapping_id'] );
        $this->assertSame( '44444444-5555-4666-8777-888888888888', $data['entries'][0]['submission_uuid'] );
    }

    public function test_get_log_entries_includes_submission_uuid_for_grouped_local_events(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $events->record(
            [
                'execution_request_id' => 'req-ledger-grouped-log-1',
                'submission_uuid'      => '55555555-5555-4555-8555-555555555555',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '77',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'structured' => [
                        'classification' => 'ham',
                    ],
                ],
            ]
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();

        $this->assertSame( 'req-ledger-grouped-log-1', $data['entries'][0]['execution_request_id'] );
        $this->assertSame( '55555555-5555-4555-8555-555555555555', $data['entries'][0]['submission_uuid'] );
    }

    public function test_get_log_entries_preserves_elementor_opaque_form_ids_for_grouped_local_events(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $events->record(
            [
                'execution_request_id' => 'req-elementor-grouped-log-1',
                'submission_uuid'      => '66666666-7777-4888-9999-aaaaaaaaaaaa',
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => '123:formabc',
                'entry_id'             => null,
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'content' => 'Lead summary ready.',
                ],
            ]
        );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        do_action( 'rest_api_init' );
        $this->controller->register_routes();

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $request->set_param( 'form_id', '123:formabc' );
        $response = rest_do_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $data['total'] );
        $this->assertSame( 'req-elementor-grouped-log-1', $data['entries'][0]['execution_request_id'] );
        $this->assertSame( 'elementor_pro_forms', $data['entries'][0]['form_source'] );
        $this->assertSame( '123:formabc', $data['entries'][0]['form_id'] );
        $this->assertSame( '66666666-7777-4888-9999-aaaaaaaaaaaa', $data['entries'][0]['submission_uuid'] );
        $this->assertSame( 'Elementor Pro Forms', $data['entries'][0]['form_context']['provider_label'] );
        $this->assertSame( '123:formabc', $data['entries'][0]['form_context']['form_id'] );
        $this->assertSame( 'Form 123:formabc', $data['entries'][0]['form_context']['form_name'] );
        $this->assertFalse( $data['entries'][0]['form_context']['entry_preview_available'] );
        $this->assertNull( $data['entries'][0]['form_context']['links']['entry_admin_url'] );
    }

    public function test_get_log_entries_suppresses_elementor_entry_ids_when_form_submissions_are_unavailable(): void
    {
        global $wpdb;

        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-elementor-stale-native-entry-log',
                'submission_uuid'      => '77777777-7777-4777-8777-777777777777',
                'form_source'          => 'elementor_pro_forms',
                'form_id'              => '123:formabc',
                'entry_id'             => '123',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
                'result_json'          => [
                    'content' => 'Lead summary ready.',
                ],
            ]
        );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        do_action( 'rest_api_init' );
        $this->controller->register_routes();

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $request->set_param( 'form_id', '123:formabc' );
        $response = rest_do_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $data['total'] );
        $this->assertSame( 'elementor_pro_forms', $data['entries'][0]['form_source'] );
        $this->assertNull( $data['entries'][0]['entry_id'] );
        $this->assertNull( $data['entries'][0]['form_context']['entry_id'] );
        $this->assertFalse( $data['entries'][0]['form_context']['entry_preview_available'] );
        $this->assertNull( $data['entries'][0]['form_context']['links']['entry_admin_url'] );
    }

    public function test_create_log_entry_suppresses_elementor_entry_id_response_when_form_submissions_are_unavailable(): void
    {
        add_filter( 'sentient_forms_elementor_is_active', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_forms_api_available', '__return_true' );
        add_filter( 'sentient_forms_elementor_pro_form_submissions_api_available', '__return_false' );

        $request = new WP_REST_Request( 'POST', '/sentient-forms/v1/actions/log' );
        $request->set_param( 'form_source', 'elementor_pro_forms' );
        $request->set_param( 'form_id', '123:formabc' );
        $request->set_param( 'entry_id', 123 );
        $request->set_param( 'action_code', 'entry_summary_v1' );
        $request->set_param( 'action_label', 'Entry Summary' );
        $request->set_param( 'status', 'success' );

        $response = $this->controller->create_log_entry( $request );
        $data     = $response->get_data();

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 'elementor_pro_forms', $data['form_source'] );
        $this->assertSame( '123:formabc', $data['form_id'] );
        $this->assertNull( $data['entry_id'] );
    }

    public function test_get_log_entries_normalizes_submission_uuid_for_option_and_local_event_rows(): void
    {
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'          => 'gravity_forms',
            'form_id'              => 7,
            'entry_id'             => 77,
            'action_code'          => 'entry_summary_v1',
            'action_label'         => 'Entry Summary',
            'status'               => 'success',
            'execution_request_id' => 'req-option-invalid-submission-uuid',
            'submission_uuid'      => 'not-a-uuid',
        ] );
        Sentient_Forms_Action_Log_Controller::log_execution( [
            'form_source'          => 'gravity_forms',
            'form_id'              => 7,
            'entry_id'             => 78,
            'action_code'          => 'entry_summary_v1',
            'action_label'         => 'Entry Summary',
            'status'               => 'success',
            'execution_request_id' => 'req-option-uppercase-submission-uuid',
            'submission_uuid'      => 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE',
        ] );

        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );
        $events->record(
            [
                'execution_request_id' => 'req-local-invalid-submission-uuid',
                'submission_uuid'      => 'also-not-a-uuid',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '79',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
            ]
        );
        $events->record(
            [
                'execution_request_id' => 'req-local-uppercase-submission-uuid',
                'submission_uuid'      => 'FFFFFFFF-1111-4222-8333-444444444444',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '80',
                'provider'             => 'openrouter',
                'model'                => 'openrouter/auto',
                'status'               => 'succeeded',
            ]
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();
        $entries  = [];
        foreach ( $data['entries'] as $entry )
        {
            $entries[ $entry['execution_request_id'] ] = $entry;
        }

        $this->assertNull( $entries['req-option-invalid-submission-uuid']['submission_uuid'] ?? null );
        $this->assertSame( 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $entries['req-option-uppercase-submission-uuid']['submission_uuid'] ?? null );
        $this->assertNull( $entries['req-local-invalid-submission-uuid']['submission_uuid'] ?? null );
        $this->assertSame( 'ffffffff-1111-4222-8333-444444444444', $entries['req-local-uppercase-submission-uuid']['submission_uuid'] ?? null );
    }

    public function test_get_log_entries_sanitizes_managed_currency_from_local_events(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $recorded = $events->record(
            [
                'execution_request_id' => 'req-managed-log-1',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '77',
                'provider'             => 'sentient_managed',
                'model'                => 'openai/gpt-4.1-mini',
                'status'               => 'succeeded',
                'token_usage_json'     => [
                    'input_tokens'  => 11,
                    'output_tokens' => 5,
                    'total_tokens'  => 16,
                ],
                'cost_json'            => [
                    'provider'               => 'sentient_forms',
                    'currency'               => 'USD',
                    'source'                 => 'sentient_forms_metering',
                    'debited_credits'        => 2,
                    'billed_amount_microusd' => 1400,
                ],
                'result_json'          => [
                    'structured' => [
                        'summary' => 'Managed result.',
                    ],
                    'metering'   => [
                        'debited_credits'        => 2,
                        'billed_amount_microusd' => 1400,
                        'currency'               => 'USD',
                    ],
                    'details'    => [
                        'cost'          => [
                            'amount_usd' => 0.0014,
                            'currency'   => 'USD',
                        ],
                        'provider_cost' => [
                            'amount_usd' => 0.0002,
                            'currency'   => 'USD',
                        ],
                    ],
                ],
            ]
        );
        $this->assertIsInt( $recorded );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();
        $entry    = $data['entries'][0];

        $this->assertSame( 'sentient_forms_managed', $entry['usage_cost']['route'] );
        $this->assertSame( 'sentient_forms_managed_action', $entry['action_code'] );
        $this->assertSame( 'Sentient Forms managed action', $entry['action_label'] );
        $this->assertSame( 2, $entry['credits_used'] );
        $this->assertSame( 2, $entry['usage_cost']['credits'] );
        $this->assertArrayNotHasKey( 'amount_usd', $entry['usage_cost'] );
        $this->assertArrayNotHasKey( 'provider_cost', $entry['pricing'] );
        $this->assertArrayNotHasKey( 'cost', $entry['details'] );
        $this->assertArrayNotHasKey( 'billed_amount_microusd', $entry['details']['stored_result']['metering'] );
        $this->assertArrayNotHasKey( 'currency', $entry['details']['stored_result']['metering'] );
        $this->assertArrayNotHasKey( 'details', $entry['details']['stored_result'] );
        $this->assertStringNotContainsString( 'microusd', wp_json_encode( $entry ) );
        $this->assertStringNotContainsString( '"cost"', wp_json_encode( $entry ) );
        $this->assertStringNotContainsString( '"currency"', wp_json_encode( $entry ) );
    }

    public function test_get_log_entries_never_presents_unclassified_currency_as_direct_openrouter(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'sentient_execution_events';
        $now   = current_time( 'mysql' );

        $this->assertNotFalse(
            $wpdb->insert(
                $table,
                [
                    'execution_request_id' => 'req-unclassified-private-cost',
                    'provider'             => 'unclassified',
                    'model'                => 'unknown/model:free',
                    'status'               => 'succeeded',
                    'cost_json'            => wp_json_encode( [ 'currency' => 'USD', 'amount_usd' => 0.1234, 'free' => true ] ),
                    'result_json'          => wp_json_encode( [ 'metering' => [ 'currency' => 'USD', 'billed_amount_microusd' => 123400 ] ] ),
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            )
        );

        $response = $this->controller->get_log_entries( new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' ) );
        $entries  = array_column( $response->get_data()['entries'], null, 'execution_request_id' );
        $entry    = $entries['req-unclassified-private-cost'];

        $this->assertSame( 'unclassified', $entry['usage_cost']['route'] );
        $this->assertSame( 'unknown', $entry['usage_cost']['kind'] );
        $this->assertFalse( $entry['usage_cost']['known'] );
        $this->assertArrayNotHasKey( 'amount_usd', $entry['usage_cost'] );
        $this->assertStringNotContainsString( 'currency', wp_json_encode( $entry ) );
        $this->assertStringNotContainsString( 'microusd', wp_json_encode( $entry ) );
    }

    public function test_get_log_entries_resolves_managed_action_identity_from_event_payload(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $events->record(
            [
                'execution_request_id' => 'req-managed-cf7-summary',
                'mapping_id'           => 0,
                'form_source'          => 'contact_form_7',
                'form_id'              => '42',
                'submission_uuid'      => '66666666-7777-4888-8999-aaaaaaaaaaaa',
                'provider'             => 'sentient_managed',
                'model'                => 'openai/gpt-4.1-mini',
                'status'               => 'succeeded',
                'result_json'          => [
                    'central_action_id' => 'entry_summary_v1',
                    'action_name_label' => 'Entry Summary',
                    'structured'        => [
                        'summary' => 'Ledger summary completed.',
                    ],
                ],
            ]
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();
        $entry    = $data['entries'][0];

        $this->assertSame( 'entry_summary_v1', $entry['action_code'] );
        $this->assertSame( 'Entry Summary', $entry['action_label'] );
        $this->assertNull( $entry['mapping_id'] );
        $this->assertSame( 'req-managed-cf7-summary', $entry['execution_request_id'] );
    }

    public function test_get_log_entries_surfaces_managed_zdr_fallback_and_failure_messages(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $events->record(
            [
                'execution_request_id' => 'req-managed-zdr-fallback-success',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '77',
                'provider'             => 'sentient_managed',
                'model'                => 'google/gemini-3-flash-preview',
                'status'               => 'succeeded',
                'result_json'          => [
                    'privacy_route_fallback' => [
                        'schema'         => 'sentient_forms_privacy_route_fallback.v1',
                        'policy_version' => '2026-06-managed-zdr-fallback-v1',
                        'reason_code'    => 'managed_zdr_primary_route_unavailable',
                        'original_model' => '~openai/gpt-latest',
                        'fallback_model' => 'google/gemini-3-flash-preview',
                        'attempts'       => 2,
                    ],
                    'provider_payload'        => [
                        'raw_error' => 'No ZDR route is available for this model.',
                    ],
                ],
            ]
        );
        $events->record(
            [
                'execution_request_id' => 'req-managed-zdr-failure',
                'form_source'          => 'gravity_forms',
                'form_id'              => '7',
                'entry_id'             => '78',
                'provider'             => 'sentient_managed',
                'model'                => 'google/gemini-3-flash-preview',
                'status'               => 'failed',
                'error_code'           => 'managed_privacy_route_unavailable',
                'error_message'        => 'No ZDR-safe managed route was available, so Sentient Forms did not run this action without ZDR.',
                'result_json'          => [
                    'privacy_route_failure' => [
                        'schema'         => 'sentient_forms_privacy_route_failure.v1',
                        'policy_version' => '2026-06-managed-zdr-fallback-v1',
                        'reason_code'    => 'managed_zdr_route_unavailable',
                        'selected_model' => 'google/gemini-3-flash-preview',
                    ],
                    'provider_payload'       => [
                        'raw_error' => 'No ZDR route is available for this model.',
                    ],
                ],
            ]
        );

        $request  = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();
        $entries  = [];
        foreach ( $data['entries'] as $entry )
        {
            $entries[ $entry['execution_request_id'] ] = $entry;
        }

        $this->assertSame(
            'The selected model was not available on a ZDR-safe route, so Sentient Forms used a comparable ZDR-safe managed model instead.',
            $entries['req-managed-zdr-fallback-success']['result_summary'] ?? null
        );
        $this->assertSame(
            'No ZDR-safe managed route was available, so Sentient Forms did not run this action without ZDR.',
            $entries['req-managed-zdr-failure']['result_summary'] ?? null
        );
        $this->assertStringNotContainsString( 'No ZDR route', wp_json_encode( $entries['req-managed-zdr-fallback-success'] ) );
        $this->assertStringNotContainsString( 'No ZDR route', wp_json_encode( $entries['req-managed-zdr-failure'] ) );
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

    public function test_get_log_entries_prefers_durable_events_over_matching_legacy_pending_rows(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $recorded = $events->record( [
            'execution_request_id' => 'req-durable-over-legacy-pending',
            'mapping_id'           => 12,
            'form_source'          => 'elementor_pro_forms',
            'form_id'              => '42:formabc',
            'provider'             => 'sentient_managed',
            'status'               => 'succeeded',
            'result_json'          => [
                'central_action_id' => 'entry_summary_v1',
                'action_name_label' => 'Entry Summary',
                'structured'        => [ 'summary' => 'Durable summary completed.' ],
            ],
        ] );
        $this->assertIsInt( $recorded );

        update_option(
            self::OPTION_KEY,
            [
                [
                    'id'                   => 'legacy-local-pending',
                    'form_source'          => 'elementor_pro_forms',
                    'form_id'              => '42:formabc',
                    'entry_id'             => null,
                    'action_code'          => 'entry_summary_v1',
                    'action_label'         => 'Entry Summary',
                    'status'               => 'pending',
                    'execution_request_id' => 'req-durable-over-legacy-pending',
                    'mapping_id'           => 'local_first_12',
                    'created_at'           => '2026-07-13T01:20:00+00:00',
                ],
            ],
            false
        );

        $response = $this->controller->get_log_entries( new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' ) );
        $data     = $response->get_data();
        $matches  = array_values(
            array_filter(
                $data['entries'],
                fn ( array $entry ): bool => 'req-durable-over-legacy-pending' === ( $entry['execution_request_id'] ?? null )
            )
        );

        $this->assertCount( 1, $matches );
        $this->assertStringStartsWith( 'local-event-', $matches[0]['id'] );
        $this->assertSame( 'success', $matches[0]['status'] );
    }

    public function test_get_log_entries_sorts_mixed_timestamp_formats_by_instant(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $recorded = $events->record( [
            'execution_request_id' => 'req-newer-mysql-timestamp',
            'mapping_id'           => 21,
            'form_source'          => 'wpforms',
            'form_id'              => '662',
            'provider'             => 'sentient_managed',
            'status'               => 'succeeded',
            'created_at'           => '2026-07-13 06:20:00',
            'updated_at'           => '2026-07-13 06:20:01',
            'result_json'          => [
                'central_action_id' => 'entry_summary_v1',
                'action_name_label' => 'Entry Summary',
                'structured'        => [ 'summary' => 'Newest durable result.' ],
            ],
        ] );
        $this->assertIsInt( $recorded );

        update_option(
            self::OPTION_KEY,
            [
                [
                    'id'                   => 'older-iso-entry',
                    'form_source'          => 'gravity_forms',
                    'form_id'              => 1,
                    'entry_id'             => 1,
                    'action_code'          => 'legacy_remote_action',
                    'action_label'         => 'Legacy Remote Action',
                    'status'               => 'error',
                    'execution_request_id' => 'req-older-iso-timestamp',
                    'created_at'           => '2026-07-13T04:50:00+00:00',
                ],
            ],
            false
        );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $request->set_param( 'per_page', 1 );
        $request->set_param( 'action_code', 'entry_summary_v1' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();

        $this->assertSame( 'req-newer-mysql-timestamp', $data['entries'][0]['execution_request_id'] ?? null );
        $this->assertStringStartsWith( 'local-event-', $data['entries'][0]['id'] ?? '' );
    }

    public function test_get_log_entries_filters_mixed_timestamp_formats_by_instant(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        $recorded = $events->record( [
            'execution_request_id' => 'req-mixed-date-filter',
            'mapping_id'           => 22,
            'form_source'          => 'wpforms',
            'form_id'              => '662',
            'provider'             => 'sentient_managed',
            'status'               => 'succeeded',
            'created_at'           => '2026-07-13 06:20:00',
            'updated_at'           => '2026-07-13 06:20:01',
            'result_json'          => [
                'central_action_id' => 'entry_summary_v1',
                'action_name_label' => 'Entry Summary',
                'structured'        => [ 'summary' => 'Filterable durable result.' ],
            ],
        ] );
        $this->assertIsInt( $recorded );

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $request->set_param( 'action_code', 'entry_summary_v1' );
        $request->set_param( 'date_from', '2026-07-13T05:00:00+00:00' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();

        $this->assertSame( 1, $data['total'] );
        $this->assertSame( 'req-mixed-date-filter', $data['entries'][0]['execution_request_id'] ?? null );
    }

    public function test_get_log_entries_sorts_same_second_event_ids_numerically(): void
    {
        global $wpdb;
        $events = new Sentient_Forms_Execution_Events_Repository( $wpdb );

        for ( $index = 1; $index <= 10; $index++ )
        {
            $recorded = $events->record( [
                'execution_request_id' => 'req-same-second-' . $index,
                'mapping_id'           => $index,
                'form_source'          => 'gravity_forms',
                'form_id'              => '793',
                'provider'             => 'sentient_managed',
                'status'               => 'succeeded',
                'created_at'           => '2026-07-13 06:20:00',
                'updated_at'           => '2026-07-13 06:20:01',
                'result_json'          => [
                    'central_action_id' => 'entry_summary_v1',
                    'action_name_label' => 'Entry Summary',
                    'structured'        => [ 'summary' => 'Same-second result ' . $index . '.' ],
                ],
            ] );
            $this->assertSame( $index, $recorded );
        }

        $request = new WP_REST_Request( 'GET', '/sentient-forms/v1/actions/log' );
        $request->set_param( 'per_page', 1 );
        $request->set_param( 'action_code', 'entry_summary_v1' );
        $response = $this->controller->get_log_entries( $request );
        $data     = $response->get_data();

        $this->assertSame( 'local-event-10', $data['entries'][0]['id'] ?? null );
        $this->assertSame( 'req-same-second-10', $data['entries'][0]['execution_request_id'] ?? null );
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
                'cost_json'            => [
                    'provider'   => 'openrouter',
                    'currency'   => 'USD',
                    'amount_usd' => 0.0002,
                    'source'     => 'openrouter_usage_cost',
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
                'sentient_submission_ledger_settings',
                'sentient_submission_ledger',
            ] as $table
        )
        {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$table}" );
        }
    }
}
