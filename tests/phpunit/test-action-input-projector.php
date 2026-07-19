<?php

class Tests_Action_Input_Projector extends WP_UnitTestCase
{
    private Sentient_Forms_Action_Input_Projector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projector = new Sentient_Forms_Action_Input_Projector();
    }

    public function test_selected_mode_projects_only_requested_field_values_and_schema_without_metadata(): void
    {
        $projection = $this->projector->project(
            [
                'mode'             => 'selected',
                'field_ids'        => [ '2', '3' ],
                'include_metadata' => false,
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertIsArray( $projection );
        $this->assertSame( [ 2, '3.1', '3.2' ], array_keys( $projection['entry'] ) );
        $this->assertSame( [], $projection['form'] );
        $this->assertArrayNotHasKey( 'id', $projection['entry'] );
        $this->assertArrayNotHasKey( 'title', $projection['form'] );
        $this->assertSame( [], $projection['bindings'] );
        $this->assertSame(
            [
                'mapping_source'        => 'explicit_mapping',
                'mode'                  => 'selected',
                'include_metadata'      => false,
                'full_entry_sent'       => false,
                'requested_field_ids'   => [ '2', '3' ],
                'applied_entry_keys'    => [ '2', '3.1', '3.2' ],
                'applied_form_field_ids'=> [],
            ],
            $projection['manifest']
        );
    }

    public function test_exclude_mode_removes_requested_fields_but_preserves_metadata_when_enabled(): void
    {
        $projection = $this->projector->project(
            [
                'mode'             => 'exclude',
                'field_ids'        => [ '2' ],
                'include_metadata' => true,
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertIsArray( $projection );
        $this->assertSame( [ 'id', 'source_url', 1, '3.1', '3.2' ], array_keys( $projection['entry'] ) );
        $this->assertSame( [ '1', '3' ], $this->projected_form_field_ids( $projection['form'] ) );
        $this->assertSame( 'Contact Form', $projection['form']['title'] );
        $this->assertFalse( $projection['manifest']['full_entry_sent'] );
    }

    public function test_all_mode_without_metadata_keeps_all_field_values_but_omits_entry_and_form_identity(): void
    {
        $projection = $this->projector->project(
            [
                'mode'             => 'all',
                'include_metadata' => false,
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertIsArray( $projection );
        $this->assertSame( [ 1, 2, '3.1', '3.2' ], array_keys( $projection['entry'] ) );
        $this->assertSame( [], $projection['form'] );
        $this->assertArrayNotHasKey( 'id', $projection['entry'] );
        $this->assertArrayNotHasKey( 'id', $projection['form'] );
        $this->assertFalse( $projection['manifest']['full_entry_sent'] );
    }

    public function test_legacy_variable_bindings_remain_bindings_and_preserve_the_full_input(): void
    {
        $bindings = [
            'name'  => '1',
            'email' => '2',
        ];
        $form  = $this->form();
        $entry = $this->entry();

        $projection = $this->projector->project( $bindings, $form, $entry );

        $this->assertIsArray( $projection );
        $this->assertSame( $bindings, $projection['bindings'] );
        $this->assertSame( $form, $projection['form'] );
        $this->assertSame( $entry, $projection['entry'] );
        $this->assertSame( 'variable_bindings', $projection['manifest']['mapping_source'] );
        $this->assertTrue( $projection['manifest']['full_entry_sent'] );
    }

    public function test_unknown_input_mapping_mode_fails_closed(): void
    {
        $projection = $this->projector->project(
            [
                'mode'      => 'sometimes',
                'field_ids' => [ '2' ],
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertWPError( $projection );
        $this->assertSame( 'sentient_forms_invalid_input_mapping_mode', $projection->get_error_code() );
    }

    public function test_malformed_field_id_list_fails_closed(): void
    {
        $projection = $this->projector->project(
            [
                'mode'      => 'selected',
                'field_ids' => '2',
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertWPError( $projection );
        $this->assertSame( 'sentient_forms_invalid_input_mapping_field_ids', $projection->get_error_code() );
    }

    public function test_non_boolean_metadata_control_fails_closed(): void
    {
        $projection = $this->projector->project(
            [
                'mode'             => 'selected',
                'field_ids'        => [ '2' ],
                'include_metadata' => 'false',
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertWPError( $projection );
        $this->assertSame( 'sentient_forms_invalid_input_mapping_metadata_control', $projection->get_error_code() );
    }

    public function test_boolean_and_empty_field_ids_fail_closed(): void
    {
        foreach ( [ true, ' ' ] as $field_id )
        {
            $projection = $this->projector->project(
                [
                    'mode'      => 'selected',
                    'field_ids' => [ $field_id ],
                ],
                $this->form(),
                $this->entry()
            );

            $this->assertWPError( $projection );
            $this->assertSame( 'sentient_forms_invalid_input_mapping_field_ids', $projection->get_error_code() );
        }
    }

    public function test_selected_mode_without_fields_or_metadata_fails_closed(): void
    {
        $projection = $this->projector->project(
            [
                'mode'             => 'selected',
                'field_ids'        => [],
                'include_metadata' => false,
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertWPError( $projection );
        $this->assertSame( 'sentient_forms_empty_input_projection', $projection->get_error_code() );
    }

    public function test_selected_mode_without_fields_can_send_metadata_only(): void
    {
        $projection = $this->projector->project(
            [
                'mode'             => 'selected',
                'field_ids'        => [],
                'include_metadata' => true,
            ],
            $this->form(),
            $this->entry()
        );

        $this->assertIsArray( $projection );
        $this->assertSame( [], $projection['form']['fields'] ?? null );
        $this->assertSame( 7, $projection['form']['id'] ?? null );
        $this->assertSame( 99, $projection['entry']['id'] ?? null );
    }

    /**
     * @return array<string, mixed>
     */
    private function form(): array
    {
        return [
            'id'     => 7,
            'title'  => 'Contact Form',
            'fields' => [
                [ 'id' => '1', 'label' => 'Name', 'type' => 'text' ],
                [ 'id' => '2', 'label' => 'Email', 'type' => 'email' ],
                [ 'id' => '3', 'label' => 'Address', 'type' => 'address' ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(): array
    {
        return [
            'id'         => 99,
            'source_url' => 'https://example.test/contact',
            '1'          => 'Ada Lovelace',
            '2'          => 'ada@example.test',
            '3.1'        => '123 Computing Lane',
            '3.2'        => 'London',
        ];
    }

    /**
     * @param array<string, mixed> $form
     * @return array<int, string>
     */
    private function projected_form_field_ids( array $form ): array
    {
        return array_values(
            array_map(
                static fn( mixed $field ): string => (string) ( is_array( $field ) ? ( $field['id'] ?? '' ) : '' ),
                is_array( $form['fields'] ?? null ) ? $form['fields'] : []
            )
        );
    }
}
