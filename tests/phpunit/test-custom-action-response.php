<?php
/**
 * Unit tests for the Sentient_Forms_Custom_Action_Response DTO.
 *
 * @package SentientForms\Tests
 */

class CustomActionResponseTest extends WP_UnitTestCase
{
    /**
     * Test that from_api_payload correctly parses all fields including new definition fields.
     */
    public function test_from_api_payload_parses_all_fields(): void
    {
        $payload = [
            'id'                        => '550e8400-e29b-41d4-a716-446655440000',
            'template_id'               => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'code'                      => 'spam-check',
            'display_name'              => 'Spam Detection',
            'description'               => 'Detects spam entries',
            'prompt_overrides'          => ['strictness' => 'high'],
            'model_hint'                => 'gemini-2.0-flash',
            'base_credit_cost'          => 5,
            'status'                    => 'active',
            'archived_at'               => null,
            'created_at'                => '2026-01-10T10:00:00Z',
            'updated_at'                => '2026-01-12T15:30:00Z',
            // New definition fields (CA-DEF-001)
            'action_kind'               => 'template_override',
            'definition'                => null,
            'definition_version'        => 1,
            'output_contract'           => null,
            'supported_execution_modes' => ['after_submission'],
        ];

        $dto = Sentient_Forms_Custom_Action_Response::from_api_payload( $payload );

        // Original fields
        $this->assertSame( '550e8400-e29b-41d4-a716-446655440000', $dto->to_array()['id'] );
        $this->assertSame( 'spam-check', $dto->to_array()['code'] );
        $this->assertSame( 'Spam Detection', $dto->to_array()['display_name'] );
        $this->assertSame( ['strictness' => 'high'], $dto->get_prompt_overrides() );
        $this->assertSame( 'gemini-2.0-flash', $dto->to_array()['model_hint'] );
        $this->assertSame( 5, $dto->to_array()['base_credit_cost'] );
        $this->assertSame( 'active', $dto->to_array()['status'] );

        // New definition fields
        $this->assertSame( 'template_override', $dto->get_action_kind() );
        $this->assertNull( $dto->get_definition() );
        $this->assertSame( 1, $dto->get_definition_version() );
        $this->assertNull( $dto->get_output_contract() );
        $this->assertSame( ['after_submission'], $dto->get_supported_execution_modes() );
    }

    /**
     * Test that from_api_payload handles custom_definition action_kind with full definition.
     */
    public function test_from_api_payload_handles_custom_definition_kind(): void
    {
        $definition = [
            'meta_prompt'       => 'Analyze the form submission for quality.',
            'goal'              => 'Determine if submission is high quality',
            'success_criteria'  => ['Contains complete information', 'Professional tone'],
            'failure_criteria'  => ['Incomplete data', 'Spam indicators'],
        ];

        $output_contract = [
            'response_type'            => 'structured',
            'json_schema'              => ['type' => 'object'],
            'confidence_score_required' => true,
        ];

        $payload = [
            'id'                        => '550e8400-e29b-41d4-a716-446655440001',
            'template_id'               => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'code'                      => 'quality-check',
            'display_name'              => 'Quality Check',
            'description'               => 'Custom quality assessment',
            'prompt_overrides'          => [],
            'model_hint'                => null,
            'base_credit_cost'          => 10,
            'status'                    => 'active',
            'archived_at'               => null,
            'created_at'                => '2026-01-13T08:00:00Z',
            'updated_at'                => '2026-01-13T08:00:00Z',
            'action_kind'               => 'custom_definition',
            'definition'                => $definition,
            'definition_version'        => 2,
            'output_contract'           => $output_contract,
            'supported_execution_modes' => ['validation', 'after_submission'],
        ];

        $dto = Sentient_Forms_Custom_Action_Response::from_api_payload( $payload );

        $this->assertSame( 'custom_definition', $dto->get_action_kind() );
        $this->assertSame( $definition, $dto->get_definition() );
        $this->assertSame( 2, $dto->get_definition_version() );
        $this->assertSame( $output_contract, $dto->get_output_contract() );
        $this->assertSame( ['validation', 'after_submission'], $dto->get_supported_execution_modes() );
    }

    /**
     * Test that to_array includes all new definition fields.
     */
    public function test_to_array_includes_definition_fields(): void
    {
        $payload = [
            'id'                        => 'test-id',
            'template_id'               => 'tmpl-id',
            'code'                      => 'test-code',
            'display_name'              => 'Test Action',
            'description'               => null,
            'prompt_overrides'          => [],
            'model_hint'                => null,
            'base_credit_cost'          => null,
            'status'                    => 'active',
            'archived_at'               => null,
            'created_at'                => '2026-01-13T00:00:00Z',
            'updated_at'                => '2026-01-13T00:00:00Z',
            'action_kind'               => 'template_override',
            'definition'                => null,
            'definition_version'        => 1,
            'output_contract'           => null,
            'supported_execution_modes' => ['after_submission'],
        ];

        $dto   = Sentient_Forms_Custom_Action_Response::from_api_payload( $payload );
        $array = $dto->to_array();

        $this->assertArrayHasKey( 'action_kind', $array );
        $this->assertArrayHasKey( 'definition', $array );
        $this->assertArrayHasKey( 'definition_version', $array );
        $this->assertArrayHasKey( 'output_contract', $array );
        $this->assertArrayHasKey( 'supported_execution_modes', $array );

        $this->assertSame( 'template_override', $array['action_kind'] );
        $this->assertNull( $array['definition'] );
        $this->assertSame( 1, $array['definition_version'] );
        $this->assertNull( $array['output_contract'] );
        $this->assertSame( ['after_submission'], $array['supported_execution_modes'] );
    }

    /**
     * Regression guard: DTO output MUST stay aligned with the custom-action schema contract.
     */
    public function test_dto_to_array_matches_schema_property_contract_for_custom_action_response(): void
    {
        $workspace_root = dirname( __DIR__, 3 );
        $schema_path    = $workspace_root . '/contracts/v1/actions/custom-action-response.schema.json';

        if ( ! file_exists( $schema_path ) ) {
            $this->markTestSkipped( sprintf( 'Shared contract schema not found: %s', $schema_path ) );
        }

        $schema_raw = file_get_contents( $schema_path );
        $this->assertNotFalse( $schema_raw, sprintf( 'Unable to read schema file: %s', $schema_path ) );

        try {
            $schema = json_decode( $schema_raw, true, 512, JSON_THROW_ON_ERROR );
        } catch ( JsonException $exception ) {
            $this->fail(
                sprintf(
                    'Invalid JSON in shared contract schema %s: %s',
                    $schema_path,
                    $exception->getMessage()
                )
            );
        }

        $this->assertIsArray( $schema, 'Custom-action schema root must decode to an array/object.' );

        $schema_properties = $schema['properties'] ?? null;
        $this->assertIsArray( $schema_properties, 'Custom-action schema must include a properties object.' );

        $schema_required = $schema['required'] ?? [];
        $this->assertIsArray( $schema_required, 'Custom-action schema must include a required array.' );

        $payload = [
            'id'                        => '550e8400-e29b-41d4-a716-446655440002',
            'template_id'               => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
            'code'                      => 'contract-shape-check',
            'display_name'              => 'Contract Shape Check',
            'description'               => 'Ensures DTO output follows schema keys',
            'prompt_overrides'          => ['strictness' => 'medium'],
            'model_hint'                => 'gemini-2.0-flash',
            'base_credit_cost'          => 5,
            'status'                    => 'active',
            'archived_at'               => null,
            'created_at'                => '2026-02-17T00:00:00Z',
            'updated_at'                => '2026-02-17T00:00:00Z',
            'action_kind'               => 'custom_definition',
            'definition'                => [
                'meta_prompt'      => 'Evaluate submission quality.',
                'goal'             => 'Score quality',
                'success_criteria' => ['Complete information'],
                'failure_criteria' => ['Missing required data'],
            ],
            'definition_version'        => 1,
            'output_contract'           => [
                'response_type'            => 'structured',
                'json_schema'              => ['type' => 'object'],
                'confidence_score_required' => true,
            ],
            'supported_execution_modes' => ['validation', 'after_submission'],
        ];

        $dto       = Sentient_Forms_Custom_Action_Response::from_api_payload( $payload );
        $dto_array = $dto->to_array();

        foreach ( array_keys( $schema_properties ) as $property_key ) {
            $this->assertArrayHasKey(
                $property_key,
                $dto_array,
                sprintf( 'DTO output missing schema property key: %s', $property_key )
            );
        }

        foreach ( $schema_required as $required_key ) {
            $this->assertIsString( $required_key, 'Schema required entries must be strings.' );
            $this->assertArrayHasKey(
                $required_key,
                $dto_array,
                sprintf( 'DTO output missing required schema key: %s', $required_key )
            );
            $this->assertNotNull(
                $dto_array[ $required_key ],
                sprintf( 'DTO required key should not be null for this fixture: %s', $required_key )
            );
        }

        $this->assertSame( 'custom_definition', $dto_array['action_kind'] );
        $this->assertSame( 1, $dto_array['definition_version'] );
        $this->assertSame( ['validation', 'after_submission'], $dto_array['supported_execution_modes'] );
        $this->assertIsArray( $dto_array['output_contract'] );
        $this->assertIsArray( $dto_array['definition'] );
    }

    /**
     * Test that from_api_payload requires new mandatory fields.
     */
    public function test_from_api_payload_requires_action_kind(): void
    {
        $payload = [
            'id'               => 'test-id',
            'template_id'      => 'tmpl-id',
            'code'             => 'test-code',
            'display_name'     => 'Test Action',
            'prompt_overrides' => [],
            'status'           => 'active',
            'created_at'       => '2026-01-13T00:00:00Z',
            'updated_at'       => '2026-01-13T00:00:00Z',
            // Missing: action_kind, definition_version, supported_execution_modes
        ];

        // The DTO uses Sentient_Forms_Error_Utils::throw_or_die which throws Error
        $this->expectException( Throwable::class );

        Sentient_Forms_Custom_Action_Response::from_api_payload( $payload );
    }
}
