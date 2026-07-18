<?php
/**
 * Provider-neutral execution identity tests.
 *
 * @package SentientForms\Tests
 */

final class Tests_Execution_Identity extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option( 'sentient_forms_forced_execution_request_id' );
    }

    public function test_same_submission_and_action_generate_same_identity(): void
    {
        $form    = [ 'id' => 42 ];
        $entry   = [ 'id' => 99, '1' => 'value' ];
        $context = [ 'hook' => 'accepted_submission', 'action_id' => 'mapping-7' ];
        $first   = Sentient_Forms_Execution_Identity::generate( 'entry_summary_v1', $form, $entry, $context );
        $second  = Sentient_Forms_Execution_Identity::generate( 'entry_summary_v1', $form, $entry, $context );

        $this->assertSame( $first, $second );
        $this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $first );
    }

    public function test_action_and_mapping_are_part_of_identity(): void
    {
        $form     = [ 'id' => 42 ];
        $entry    = [ 'id' => 99 ];
        $baseline = Sentient_Forms_Execution_Identity::generate(
            'entry_summary_v1',
            $form,
            $entry,
            [ 'hook' => 'accepted_submission', 'action_id' => 'mapping-7' ]
        );

        $this->assertNotSame(
            $baseline,
            Sentient_Forms_Execution_Identity::generate(
                'spam_detection_v1',
                $form,
                $entry,
                [ 'hook' => 'accepted_submission', 'action_id' => 'mapping-7' ]
            )
        );
        $this->assertNotSame(
            $baseline,
            Sentient_Forms_Execution_Identity::generate(
                'entry_summary_v1',
                $form,
                $entry,
                [ 'hook' => 'accepted_submission', 'action_id' => 'mapping-8' ]
            )
        );
    }

    public function test_filter_can_override_the_generated_identity(): void
    {
        $filter = static fn (): string => 'filtered-request-id-42';

        add_filter( 'sentient_forms_execution_request_id', $filter, 10, 5 );
        $request_id = Sentient_Forms_Execution_Identity::generate(
            'spam_detection_v1',
            [ 'id' => 12, 'title' => 'Contact' ],
            [ 'id' => 456, 'field_1' => 'Hello' ],
            [ 'hook' => 'accepted_submission', 'action_id' => 'map_spam' ]
        );
        remove_filter( 'sentient_forms_execution_request_id', $filter, 10 );

        $this->assertSame( 'filtered-request-id-42', $request_id );
    }

    public function test_submission_uuid_stays_authoritative_when_mutable_fields_change(): void
    {
        $form    = [ 'id' => 48, 'title' => 'Accepted submission identity' ];
        $context = [ 'hook' => 'accepted_submission', 'action_id' => 'map_summary' ];
        $first   = [
            'id'              => null,
            'submission_uuid' => '11111111-1111-4111-8111-111111111111',
            'message'         => 'original snapshot',
        ];
        $second  = $first;
        $second['submission_uuid'] = '22222222-2222-4222-8222-222222222222';
        $mutated = $first;
        $mutated['message'] = 'snapshot changed after capture';

        $first_request = Sentient_Forms_Execution_Identity::generate( 'entry_summary_v1', $form, $first, $context );

        $this->assertNotSame(
            $first_request,
            Sentient_Forms_Execution_Identity::generate( 'entry_summary_v1', $form, $second, $context )
        );
        $this->assertSame(
            $first_request,
            Sentient_Forms_Execution_Identity::generate( 'entry_summary_v1', $form, $mutated, $context )
        );
    }

    public function test_native_entry_payload_change_without_submission_uuid_changes_identity(): void
    {
        $form    = [ 'id' => 48, 'title' => 'Accepted submission identity' ];
        $context = [ 'hook' => 'accepted_submission', 'action_id' => 'map_summary' ];
        $first   = [ 'id' => '781', 'message' => 'original snapshot' ];
        $mutated = [ 'id' => '781', 'message' => 'edited snapshot' ];

        $this->assertNotSame(
            Sentient_Forms_Execution_Identity::generate( 'entry_summary_v1', $form, $first, $context ),
            Sentient_Forms_Execution_Identity::generate( 'entry_summary_v1', $form, $mutated, $context )
        );
    }
}
