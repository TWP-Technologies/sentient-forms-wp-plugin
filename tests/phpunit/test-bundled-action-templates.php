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
