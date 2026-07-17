<?php

require_once __DIR__ . '/class-sentient-forms-test-exact-artifact-validation-scenario.php';

final class Tests_Exact_Artifact_Validation_Scenario extends WP_UnitTestCase
{
    public function test_gravity_forms_native_spam_rejection_uses_saved_entry_state_without_rejection_trace(): void
    {
        $result = Sentient_Forms_Test_Exact_Artifact_Validation_Scenario::run(
            'gravity_forms',
            'spam_detection_v1',
            'reject'
        );

        $this->assertSame( 'native_spam_state', $result['evidence_mode'] ?? null );
        $this->assertTrue( $result['negative_effect_applied'] ?? false );
        $this->assertFalse( $result['rejected'] ?? true );
        $this->assertNull( $result['trace_id'] ?? null );
        $this->assertSame( [ 'gform_validation', 'gform_entry_post_save' ], $result['native_hooks'] ?? null );
    }

    public function test_contact_form_7_native_spam_rejection_uses_state_without_rejection_trace(): void
    {
        $result = Sentient_Forms_Test_Exact_Artifact_Validation_Scenario::run(
            'contact_form_7',
            'spam_detection_v1',
            'reject'
        );

        $this->assertSame( 'native_spam_state', $result['evidence_mode'] ?? null );
        $this->assertTrue( $result['negative_effect_applied'] ?? false );
        $this->assertNull( $result['trace_id'] ?? null );
        $this->assertSame( [ 'wpcf7_validate', 'wpcf7_spam' ], $result['native_hooks'] ?? null );
    }

    public function test_all_canonical_validation_assignments_execute_the_real_native_hooks(): void
    {
        $sources = [ 'gravity_forms', 'contact_form_7', 'wpforms', 'elementor_pro_forms' ];
        $actions = [ 'spam_detection_v1', 'content_validation_v1' ];
        $assignments = [ 'accept', 'reject' ];
        $results = [];

        foreach ( $sources as $source )
        {
            foreach ( $actions as $action )
            {
                foreach ( $assignments as $assignment )
                {
                    $result = Sentient_Forms_Test_Exact_Artifact_Validation_Scenario::run( $source, $action, $assignment );
                    $key = implode( ':', [ $source, $action, $assignment ] );
                    $results[ $key ] = $result;

                    $this->assertSame( $source, $result['form_source'] ?? null, $key );
                    $this->assertSame( $action, $result['action_code'] ?? null, $key );
                    $this->assertSame( $assignment, $result['assignment'] ?? null, $key );
                    $this->assertNotSame( '', $result['observed_effect'] ?? '', $key );
                    $expected_hook = 'contact_form_7' === $source && 'spam_detection_v1' === $action
                        ? 'wpcf7_spam'
                        : [
                            'gravity_forms'       => 'gform_validation',
                            'contact_form_7'      => 'wpcf7_validate',
                            'wpforms'             => 'wpforms_process',
                            'elementor_pro_forms' => 'elementor_pro/forms/validation',
                        ][ $source ];
                    $this->assertSame( $expected_hook, $result['native_hook'] ?? null, $key );
                    $expected_hooks = match ( true )
                    {
                        'gravity_forms' === $source && 'spam_detection_v1' === $action  => [ 'gform_validation', 'gform_entry_post_save' ],
                        'contact_form_7' === $source && 'spam_detection_v1' === $action => [ 'wpcf7_validate', 'wpcf7_spam' ],
                        default => [ $expected_hook ],
                    };
                    $this->assertSame( $expected_hooks, $result['native_hooks'] ?? null, $key );
                    $this->assertSame( 1, $result['provider_calls'] ?? null, $key );
                    $expected_mode = 'spam_detection_v1' === $action && in_array( $source, [ 'gravity_forms', 'contact_form_7' ], true )
                        ? 'native_spam_state'
                        : 'blocking_errors';
                    $this->assertSame( $expected_mode, $result['evidence_mode'] ?? null, $key );
                    $this->assertSame( 'reject' === $assignment, $result['negative_effect_applied'] ?? null, $key );
                    if ( 'blocking_errors' === $expected_mode )
                    {
                        $this->assertSame( 'reject' === $assignment, $result['rejected'] ?? null, $key );
                    }
                    elseif ( 'accept' === $assignment )
                    {
                        $this->assertFalse( $result['rejected'] ?? true, $key );
                    }
                    $this->assertNotEmpty( $result['request_id'] ?? null, $key );

                    if ( 'reject' === $assignment && 'blocking_errors' === $expected_mode )
                    {
                        $this->assertSame(
                            'validation-rejection:' . $result['request_id'],
                            $result['trace_id'] ?? null,
                            $key
                        );
                    }
                    else
                    {
                        $this->assertNull( $result['trace_id'] ?? null, $key );
                    }
                }
            }
        }

        $this->assertCount( 16, $results );
    }

    public function test_unsupported_scenario_fails_closed(): void
    {
        $this->expectException( InvalidArgumentException::class );

        Sentient_Forms_Test_Exact_Artifact_Validation_Scenario::run( 'gravity_forms', 'entry_summary_v1', 'accept' );
    }
}
