<?php

class Tests_Gravity_Forms_Adapter extends WP_UnitTestCase
{
    private Sentient_Forms_Gravity_Forms_Adapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new Sentient_Forms_Gravity_Forms_Adapter( Sentient_Forms_Plugin::instance() );
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
     * T-PHP-003: Test that notification filter is registered.
     * Tests FR-003: Plugin MUST hook add_filter('gform_notification', ...).
     */
    public function test_notification_filter_registered(): void
    {
        // First, ensure hooks are registered
        $this->adapter->register_hooks();

        // Check that our filter is registered with the gform_notification hook
        $has_filter = has_filter( 'gform_notification', [ $this->adapter, 'maybe_suppress_spam_notification' ] );

        $this->assertNotFalse( $has_filter, 'gform_notification filter should be registered' );
        $this->assertSame( 10, $has_filter, 'Filter should have priority 10' );
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

        // Test with is_spam property set to '1' (as stored by GF)
        $spam_entry = [
            'id'      => 123,
            'is_spam' => '1',
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

        // Test with is_spam property set to '0' (ham)
        $ham_entry = [
            'id'      => 456,
            'is_spam' => '0',
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

        // Test string '1' (as stored by GF)
        $this->assertTrue( $method->invoke( $this->adapter, [ 'is_spam' => '1' ] ) );

        // Test integer 1
        $this->assertTrue( $method->invoke( $this->adapter, [ 'is_spam' => 1 ] ) );

        // Test boolean true
        $this->assertTrue( $method->invoke( $this->adapter, [ 'is_spam' => true ] ) );

        // Test string '0'
        $this->assertFalse( $method->invoke( $this->adapter, [ 'is_spam' => '0' ] ) );

        // Test integer 0
        $this->assertFalse( $method->invoke( $this->adapter, [ 'is_spam' => 0 ] ) );

        // Test missing property
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
}

