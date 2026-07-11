<?php

final class Sentient_Forms_Test_Local_Execution_Seam extends Sentient_Forms_Local_Action_Execution_Service
{
    /** @var array<string,mixed>|null */
    public ?array $call = null;

    public function __construct()
    {
    }

    public function execute_mapping( int $mapping_id, array $form, array $entry, array $context = [] ): array | WP_Error
    {
        $this->call = compact( 'mapping_id', 'form', 'entry', 'context' );
        return [ 'status' => 'success' ];
    }
}

final class Tests_Execution_Identity extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        $property = new ReflectionProperty( Sentient_Forms_Plugin::instance(), 'local_action_execution_service' );
        $property->setValue( Sentient_Forms_Plugin::instance(), null );
        parent::tearDown();
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

    public function test_plugin_delegates_authoritative_local_mapping_to_local_execution_service(): void
    {
        $service  = new Sentient_Forms_Test_Local_Execution_Seam();
        $property = new ReflectionProperty( Sentient_Forms_Plugin::instance(), 'local_action_execution_service' );
        $property->setValue( Sentient_Forms_Plugin::instance(), $service );
        $result = Sentient_Forms_Plugin::instance()->execute_local_action_mapping(
            [ 'id' => 42 ],
            [ 'id' => 99 ],
            [ 'local_form_mapping_id' => 17, 'central_action_id' => 'entry_summary_v1' ]
        );

        $this->assertSame( [ 'status' => 'success' ], $result );
        $this->assertSame( 17, $service->call['mapping_id'] ?? null );
    }

    public function test_plugin_fails_closed_for_legacy_mapping_identifiers(): void
    {
        $result = Sentient_Forms_Plugin::instance()->execute_local_action_mapping(
            [ 'id' => 42 ],
            [ 'id' => 99 ],
            [ 'local_mapping_id' => 17, 'mapping_id' => 17 ]
        );

        $this->assertWPError( $result );
        $this->assertSame( 'sentient_forms_local_mapping_required', $result->get_error_code() );
    }
}
