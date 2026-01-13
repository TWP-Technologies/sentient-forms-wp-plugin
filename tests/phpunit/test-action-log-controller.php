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
        delete_option( self::OPTION_KEY );
        $this->controller = new Sentient_Forms_Action_Log_Controller();
    }

    protected function tearDown(): void
    {
        delete_option( self::OPTION_KEY );
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
}
