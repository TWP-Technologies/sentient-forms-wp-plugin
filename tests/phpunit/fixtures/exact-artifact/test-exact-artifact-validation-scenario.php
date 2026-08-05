<?php

require_once __DIR__ . '/class-sentient-forms-test-exact-artifact-validation-scenario.php';

final class Tests_Exact_Artifact_Validation_Scenario extends WP_UnitTestCase
{
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
                    $expected_hooks = 'contact_form_7' === $source && 'spam_detection_v1' === $action
                        ? [ 'wpcf7_validate', 'wpcf7_spam' ]
                        : [ $expected_hook ];
                    $this->assertSame( $expected_hooks, $result['native_hooks'] ?? null, $key );
                    $this->assertSame( 1, $result['provider_calls'] ?? null, $key );
                    $expected_rejection = 'reject' === $assignment
                        && ! ( 'spam_detection_v1' === $action
                            && in_array( $source, [ 'gravity_forms', 'contact_form_7' ], true ) );
                    $this->assertSame( $expected_rejection, $result['rejected'] ?? null, $key );
                    $this->assertSame( 'reject' === $assignment, $result['effect_applied'] ?? null, $key );
                    $expected_spam_state = 'reject' === $assignment && 'spam_detection_v1' === $action;
                    $this->assertSame( $expected_spam_state, $result['spam_state_applied'] ?? null, $key );
                    $expected_native_rejection = 'reject' === $assignment
                        && ! ( 'spam_detection_v1' === $action
                            && in_array( $source, [ 'gravity_forms', 'contact_form_7' ], true ) );
                    $this->assertSame( $expected_native_rejection, $result['native_rejected'] ?? null, $key );
                    $this->assertNotEmpty( $result['request_id'] ?? null, $key );

                    if ( $expected_rejection )
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

                    if ( 'reject' === $assignment && 'spam_detection_v1' === $action
                        && in_array( $source, [ 'gravity_forms', 'contact_form_7' ], true ) )
                    {
                        $expected_effect = 'gravity_forms' === $source
                            ? 'gravity_forms_native_spam_state_applied'
                            : 'contact_form_7_spam_flagged';
                        $this->assertSame( $expected_effect, $result['observed_effect'] ?? null, $key );
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
