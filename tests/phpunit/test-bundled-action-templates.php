<?php

class Tests_Bundled_Action_Templates extends WP_UnitTestCase
{
    public function test_extract_template_code_from_imported_hashed_custom_action_code(): void
    {
        $this->assertSame(
            'entry_summary_v1',
            Sentient_Forms_Bundled_Action_Templates::extract_template_code_from_custom_action_code(
                'imported_entry_summary_v1_5cfa445eee8f'
            )
        );
    }

    public function test_extract_template_code_from_prefixed_dogfood_custom_action_code(): void
    {
        $this->assertSame(
            'lead_grading_v1',
            Sentient_Forms_Bundled_Action_Templates::extract_template_code_from_custom_action_code(
                'dogfood_lead_grading_v1'
            )
        );
    }

    public function test_bundled_provider_prompts_include_submission_context_placeholders(): void
    {
        foreach ( [ 'spam_detection_v1', 'content_validation_v1', 'entry_summary_v1', 'clarification_assistant_v1' ] as $template_code )
        {
            $definition = Sentient_Forms_Bundled_Action_Templates::get( $template_code );

            $this->assertIsArray( $definition );
            $this->assertStringContainsString( '{{form}}', $definition['prompt_template'] ?? '' );
            $this->assertStringContainsString( '{{entry}}', $definition['prompt_template'] ?? '' );
        }
    }

    public function test_suggested_reply_structured_output_closes_every_object_schema(): void
    {
        $definition = Sentient_Forms_Bundled_Action_Templates::get( 'suggested_reply_v1' );

        $this->assertIsArray( $definition );
        $schema = $definition['structured_output_schema'] ?? null;
        $this->assertIsArray( $schema );
        $this->assertArrayNotHasKey( 'source_action_results', $schema['properties'] ?? [] );
        $this->assertNotContains( 'source_action_results', $schema['required'] ?? [] );
        $this->assertStringNotContainsString( 'source_action_results', $definition['prompt_template'] ?? '' );

        $assert_closed_objects = function ( array $node, string $path = '$' ) use ( &$assert_closed_objects ): void {
            if ( 'object' === ( $node['type'] ?? null ) )
            {
                $this->assertArrayHasKey( 'additionalProperties', $node, $path );
                $this->assertFalse( $node['additionalProperties'], $path );
            }

            foreach ( $node['properties'] ?? [] as $property => $child )
            {
                if ( is_array( $child ) )
                {
                    $assert_closed_objects( $child, $path . '.properties.' . $property );
                }
            }

            if ( is_array( $node['items'] ?? null ) )
            {
                $assert_closed_objects( $node['items'], $path . '.items' );
            }
        };

        $assert_closed_objects( $schema );
    }

    public function test_bundled_actions_expose_canonical_lifecycle_hooks_that_match_their_definitions(): void
    {
        $expected_lifecycles = [
            'spam_detection_v1'         => [ 'validation', 'after_submission' ],
            'content_validation_v1'      => [ 'validation' ],
            'entry_summary_v1'           => [ 'after_submission' ],
            'sentiment_urgency_v1'       => [ 'after_submission' ],
            'missing_information_v1'     => [ 'after_submission' ],
            'pain_point_intent_v1'       => [ 'after_submission' ],
            'routing_recommendation_v1'  => [ 'after_submission' ],
            'toxicity_moderation_v1'     => [ 'after_submission' ],
            'lead_grading_v1'            => [ 'after_submission' ],
            'suggested_reply_v1'          => [ 'after_submission' ],
            'clarification_assistant_v1' => [ 'real_time' ],
        ];

        $this->assertSame( array_keys( $expected_lifecycles ), Sentient_Forms_Bundled_Action_Templates::codes() );

        foreach ( $expected_lifecycles as $template_code => $expected )
        {
            $definition = Sentient_Forms_Bundled_Action_Templates::get( $template_code );

            $this->assertIsArray( $definition, $template_code );
            $this->assertSame( $expected, $definition['hooks'] ?? null, $template_code );
            $this->assertSame(
                $expected,
                $definition['definition_json']['supported_execution_modes'] ?? null,
                $template_code
            );
        }
    }

    public function test_bundled_actions_expose_the_canonical_product_policy_matrix(): void
    {
        $expected = [
            'spam_detection_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'validation', 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [ 'spam_guidance_rationale_generation' ],
                'enabled_facets' => [],
            ],
            'content_validation_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'field_errors' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'validation' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'entry_summary_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'sentiment_urgency_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'missing_information_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'pain_point_intent_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'routing_recommendation_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'toxicity_moderation_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'lead_grading_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'suggested_reply_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'accepted_submission' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'after_submission' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
            'clarification_assistant_v1' => [
                'action_policy' => [
                    'feature_access'                    => 'unrestricted',
                    'execution_requirement'             => 'provider_flexible',
                    'required_form_source_capabilities' => [ 'realtime_qna_storage' ],
                    'required_managed_capabilities'     => [],
                    'eligible_lifecycles'                => [ 'real_time' ],
                    'metering_class'                     => 'standard',
                ],
                'allowed_facets' => [],
                'enabled_facets' => [],
            ],
        ];
        $actual = [];

        foreach ( Sentient_Forms_Bundled_Action_Templates::definitions() as $code => $definition )
        {
            $actual[ $code ] = [
                'action_policy' => $definition['action_policy'] ?? null,
                'allowed_facets' => $definition['allowed_facets'] ?? null,
                'enabled_facets' => $definition['enabled_facets'] ?? null,
            ];
        }

        $this->assertSame( $expected, $actual );

        $facet_catalog = new Sentient_Forms_Action_Facet_Catalog();
        foreach ( $expected as $definition )
        {
            foreach ( $definition['allowed_facets'] as $facet_code )
            {
                $this->assertTrue( $facet_catalog->has( $facet_code ), $facet_code );
            }
        }
    }

    public function test_bundled_effects_include_gravity_forms_entry_note_evidence_for_accepted_submissions(): void
    {
        $spam = Sentient_Forms_Bundled_Action_Templates::get( 'spam_detection_v1' );
        $this->assertIsArray( $spam );
        $this->assertSame( 'spam_only', $spam['effect_mapping_json']['spam']['note']['result_display_mode'] ?? null );

        $content_validation = Sentient_Forms_Bundled_Action_Templates::get( 'content_validation_v1' );
        $this->assertIsArray( $content_validation );
        $this->assertSame( 'structured.message', $content_validation['effect_mapping_json']['entry_note']['path'] ?? null );
        $this->assertSame(
            'Sentient Forms content validation:',
            $content_validation['effect_mapping_json']['entry_note']['prefix'] ?? null
        );

        $entry_summary = Sentient_Forms_Bundled_Action_Templates::get( 'entry_summary_v1' );
        $this->assertIsArray( $entry_summary );
        $this->assertSame( 'content', $entry_summary['effect_mapping_json']['entry_note']['path'] ?? null );
        $this->assertSame(
            'Sentient Forms entry summary:',
            $entry_summary['effect_mapping_json']['entry_note']['prefix'] ?? null
        );
    }

    public function test_content_validation_prompt_keeps_spam_detection_as_the_spam_boundary(): void
    {
        $content_validation = Sentient_Forms_Bundled_Action_Templates::get( 'content_validation_v1' );

        $this->assertIsArray( $content_validation );
        $prompt = $content_validation['prompt_template'] ?? '';

        $this->assertStringContainsString(
            'Spam classification belongs to the Spam Detection action.',
            $prompt
        );
        $this->assertStringContainsString(
            'Do not use content validation as spam moderation.',
            $prompt
        );
    }

    public function test_executable_structured_output_validation_requires_exact_catalog_shapes(): void
    {
        $content_validation = [
            'is_valid' => false,
            'message'  => 'Please add useful detail.',
            'fields'   => [
                [
                    'field_id' => '3',
                    'is_valid' => false,
                    'message'  => 'Project details need more substance.',
                ],
            ],
        ];
        $this->assertTrue(
            Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid(
                'content_validation_v1',
                $content_validation
            )
        );

        $wrong_boolean = $content_validation;
        $wrong_boolean['is_valid'] = 'false';
        $this->assertFalse(
            Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid(
                'content_validation_v1',
                $wrong_boolean
            )
        );

        $unknown_property = $content_validation;
        $unknown_property['raw_provider_payload'] = 'must not cross the executable boundary';
        $this->assertFalse(
            Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid(
                'content_validation_v1',
                $unknown_property
            )
        );

        $spam = [
            'classification' => 'spam',
            'confidence'     => 0.99,
            'justification'  => 'Trusted validation-phase spam classification.',
            'indicators'     => [],
        ];
        $this->assertTrue(
            Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid(
                'spam_detection_v1',
                $spam
            )
        );

        $spam['unknown'] = true;
        $this->assertFalse(
            Sentient_Forms_Bundled_Action_Templates::is_structured_output_valid(
                'spam_detection_v1',
                $spam
            )
        );
    }

    public function test_clarification_assistant_template_is_realtime_json_with_virtual_questions(): void
    {
        $definition = Sentient_Forms_Bundled_Action_Templates::get( 'clarification_assistant_v1' );

        $this->assertIsArray( $definition );
        $this->assertSame( [ 'real_time' ], $definition['hooks'] ?? null );
        $this->assertSame( 'real_time', $definition['default_execution_mode'] ?? null );
        $this->assertSame( 'sf_realtime', $definition['default_model'] ?? null );
        $this->assertSame( [ 'real_time' ], $definition['definition_json']['supported_execution_modes'] ?? null );
        $this->assertArrayNotHasKey( 'response_format', $definition['definition_json'] ?? [] );
        $this->assertStringContainsString( 'Prefer 0-3 virtual questions; never exceed 5.', $definition['prompt_template'] ?? '' );
        $this->assertArrayHasKey( 'virtual_questions', $definition['structured_output_schema']['properties'] ?? [] );
        $this->assertArrayHasKey( 'conditional_decisions', $definition['structured_output_schema']['properties'] ?? [] );
    }
}
