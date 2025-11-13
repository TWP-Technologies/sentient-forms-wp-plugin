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
}
