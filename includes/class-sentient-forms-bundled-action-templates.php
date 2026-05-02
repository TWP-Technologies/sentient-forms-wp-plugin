<?php
/**
 * Canonical bundled local-first action template definitions.
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_Bundled_Action_Templates
{
    public const MANAGED_CUSTOM_ACTION_PREFIX = 'bundled__';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        static $definitions = null;

        if ( null !== $definitions )
        {
            return $definitions;
        }

        $definitions = [
            'spam_detection_v1' => [
                'source'                   => 'bundled',
                'code'                     => 'spam_detection_v1',
                'display_name'             => 'Spam Detection',
                'description'              => 'Classify submissions as spam or legitimate using local OpenRouter heuristics.',
                'prompt_template'          => <<<'PROMPT'
You are a spam classification system. Analyze the form submission and return a JSON response.

Your response MUST be valid JSON matching this exact schema:
{
  "classification": "spam", "ham", or "likely_spam",
  "confidence": float between 0.0 and 1.0,
  "justification": "one paragraph explaining your reasoning",
  "indicators": [
    {"type": "indicator_type", "evidence": "quoted text from submission", "weight": "high|medium|low"}
  ]
}

INDICATOR TYPES (use exactly these values):
- high_pressure_language
- suspicious_links
- commercial_solicitation
- cryptocurrency_scam
- phishing_attempt
- gibberish_content
- suspicious_email
- excessive_formatting
- adult_content
- other

RULES:
1. Return only valid JSON
2. Classification must be exactly "spam", "ham", or "likely_spam"
3. Confidence must be a decimal between 0.0 and 1.0
4. For spam or likely_spam, include at least one indicator with evidence when possible
5. For ham, indicators can be an empty array
6. Justification should be 1-3 human-readable sentences

Form context:
{{form}}

Submission data:
{{entry}}
PROMPT,
                'default_model'            => 'openrouter/auto',
                'structured_output_schema' => [
                    'type'                 => 'object',
                    'required'             => [ 'classification', 'confidence', 'justification' ],
                    'additionalProperties' => true,
                    'properties'           => [
                        'classification' => [
                            'type' => 'string',
                            'enum' => [ 'ham', 'likely_spam', 'spam' ],
                        ],
                        'confidence'     => [
                            'type'    => 'number',
                            'minimum' => 0,
                            'maximum' => 1,
                        ],
                        'justification' => [
                            'type'      => 'string',
                            'minLength' => 1,
                        ],
                        'indicators'    => [
                            'type'  => 'array',
                            'items' => [
                                'type'                 => 'object',
                                'required'             => [ 'type', 'evidence', 'weight' ],
                                'additionalProperties' => true,
                                'properties'           => [
                                    'type'     => [ 'type' => 'string' ],
                                    'evidence' => [ 'type' => 'string' ],
                                    'weight'   => [
                                        'type' => 'string',
                                        'enum' => [ 'high', 'medium', 'low' ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'override_schema'          => [
                    'strictness'            => [
                        'type'        => 'enum',
                        'options'     => [ 'lenient', 'moderate', 'strict' ],
                        'default'     => 'moderate',
                        'description' => 'How aggressive spam detection should be.',
                    ],
                    'include_justification' => [
                        'type'        => 'boolean',
                        'default'     => true,
                        'description' => 'Include reasoning in the response.',
                    ],
                ],
                'version'                  => '1',
                'is_active'                => true,
                'hooks'                    => [ 'gform_validation', 'gform_after_submission' ],
                'definition_json'          => [
                    'action_kind'               => 'template_override',
                    'version'                   => 1,
                    'response_format'           => [ 'type' => 'json_object' ],
                    'supported_execution_modes' => [ 'validation', 'after_submission' ],
                    'builder_template'          => 'spam_filter',
                ],
                'default_execution_mode'   => 'sync',
                'effect_mapping_json'      => [
                    'store_result' => true,
                    'meta'         => [
                        'sentient_forms_spam_classification' => 'structured.classification',
                        'sentient_forms_spam_confidence'     => 'structured.confidence',
                    ],
                    'spam'         => [
                        'enabled'                         => true,
                        'classification_path'             => 'structured.classification',
                        'confidence_path'                 => 'structured.confidence',
                        'min_confidence'                  => 0.8,
                        'suppress_notifications_on_spam'  => true,
                        'note'                            => [
                            'result_display_mode' => 'spam_only',
                            'indicators_display'  => 'simple',
                        ],
                    ],
                ],
            ],
            'content_validation_v1' => [
                'source'                   => 'bundled',
                'code'                     => 'content_validation_v1',
                'display_name'             => 'Content Quality Validation',
                'description'              => 'Validate whether submitted field content is sufficiently meaningful.',
                'prompt_template'          => <<<'PROMPT'
You are a form content validator. Analyze the form submission provided below against quality requirements.

Your task is to:
1. Check if each field contains sufficient, meaningful content
2. Detect vague or low-effort responses like "test", "asdf", or placeholder text
3. Identify fields that require more detail based on context
4. Be helpful but firm and guide users toward sufficient detail
5. Do not reject content only because it is promotional, suspicious, malicious, or spam-like if the field still contains enough meaningful detail. Spam classification belongs to the Spam Detection action.

Respond only with valid JSON in this exact format:
{
  "is_valid": true,
  "message": "Overall validation result message for the user",
  "fields": [
    {
      "field_id": "3",
      "is_valid": false,
      "message": "Specific guidance for this field if invalid"
    }
  ]
}

If all fields meet quality standards, set is_valid to true and include an empty fields array.
If any field needs more content, set is_valid to false and include specific field-level errors.
Only flag fields that are genuinely insufficient. Do not use content validation as spam moderation.

Form context:
{{form}}

Submission data:
{{entry}}
PROMPT,
                'default_model'            => 'openrouter/auto',
                'structured_output_schema' => [
                    'type'                 => 'object',
                    'required'             => [ 'is_valid', 'message', 'fields' ],
                    'additionalProperties' => true,
                    'properties'           => [
                        'is_valid' => [ 'type' => 'boolean' ],
                        'message'  => [ 'type' => 'string' ],
                        'fields'   => [
                            'type'  => 'array',
                            'items' => [
                                'type'                 => 'object',
                                'required'             => [ 'field_id', 'is_valid', 'message' ],
                                'additionalProperties' => true,
                                'properties'           => [
                                    'field_id' => [ 'type' => 'string' ],
                                    'is_valid' => [ 'type' => 'boolean' ],
                                    'message'  => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                    ],
                ],
                'override_schema'          => [
                    'strictness'          => [
                        'type'        => 'enum',
                        'options'     => [ 'lenient', 'moderate', 'strict' ],
                        'default'     => 'moderate',
                        'description' => 'How strictly to validate content quality.',
                    ],
                    'min_words_per_field' => [
                        'type'        => 'number',
                        'min'         => 0,
                        'max'         => 100,
                        'default'     => 3,
                        'description' => 'Minimum word count for text fields.',
                    ],
                    'allow_test_data'     => [
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => 'Allow test or placeholder content to pass validation.',
                    ],
                ],
                'version'                  => '1',
                'is_active'                => true,
                'hooks'                    => [ 'gform_validation' ],
                'definition_json'          => [
                    'action_kind'               => 'template_override',
                    'version'                   => 1,
                    'response_format'           => [ 'type' => 'json_object' ],
                    'supported_execution_modes' => [ 'validation' ],
                    'builder_template'          => 'content_validation',
                ],
                'default_execution_mode'   => 'sync',
                'effect_mapping_json'      => [
                    'store_result' => true,
                    'entry_note'   => [
                        'path'   => 'structured.message',
                        'prefix' => __( 'Sentient Forms content validation:', 'sentient-forms' ),
                    ],
                ],
            ],
            'entry_summary_v1' => [
                'source'                   => 'bundled',
                'code'                     => 'entry_summary_v1',
                'display_name'             => 'Entry Summary',
                'description'              => 'Generate a concise, human-readable summary of a form submission.',
                'prompt_template'          => <<<'PROMPT'
Provide a brief, human-readable summary of this form submission. Include:
1. A one-sentence overview of what the submission contains
2. Key data points worth highlighting
3. Any notable patterns or concerns

Keep the summary concise (3-5 sentences max). Write in a professional tone suitable for an admin dashboard.

Form context:
{{form}}

Submission data:
{{entry}}
PROMPT,
                'default_model'            => 'openrouter/auto',
                'structured_output_schema' => null,
                'override_schema'          => [
                    'tone'             => [
                        'type'        => 'enum',
                        'options'     => [ 'professional', 'friendly', 'concise', 'detailed' ],
                        'default'     => 'professional',
                        'description' => 'Writing style for the summary.',
                    ],
                    'max_sentences'    => [
                        'type'        => 'number',
                        'min'         => 1,
                        'max'         => 10,
                        'default'     => 5,
                        'description' => 'Maximum number of sentences in the summary.',
                    ],
                    'include_metadata' => [
                        'type'        => 'boolean',
                        'default'     => true,
                        'description' => 'Include submission metadata in the summary.',
                    ],
                ],
                'version'                  => '1',
                'is_active'                => true,
                'hooks'                    => [ 'gform_after_submission' ],
                'definition_json'          => [
                    'action_kind'               => 'template_override',
                    'version'                   => 1,
                    'max_tokens'                => 250,
                    'temperature'               => 0.2,
                    'supported_execution_modes' => [ 'after_submission' ],
                    'builder_template'          => 'summary',
                ],
                'default_execution_mode'   => 'async',
                'effect_mapping_json'      => [
                    'store_result' => true,
                    'meta'         => [
                        'sentient_forms_summary' => 'content',
                    ],
                    'entry_note'   => [
                        'path'   => 'content',
                        'prefix' => __( 'Sentient Forms entry summary:', 'sentient-forms' ),
                    ],
                ],
            ],
            'clarification_assistant_v1' => [
                'source'                   => 'bundled',
                'code'                     => 'clarification_assistant_v1',
                'display_name'             => 'Realtime Clarification Assistant',
                'description'              => 'Analyze visible in-progress answers and ask targeted clarification questions before the visitor leaves the form.',
                'prompt_template'          => <<<'PROMPT'
You are Sentient Forms' realtime clarification assistant. You help a visitor improve a form submission while they are still present.

Your goals are:
1. Decide whether visible answers actually answer the visible questions.
2. Point to existing fields that need better detail, screenshots, URLs, or evidence.
3. Ask only the smallest set of additional questions that would materially improve the site owner's ability to understand and act on the submission.
4. Do not ask about future hidden fields when the supplied future_field_manifest shows the form will already ask for that information later.
5. Keep the visitor's cognitive load low. Prefer 0-3 virtual questions; never exceed 5.

Return only valid JSON in this exact shape:
{
  "suggestions": [
    {
      "field_id": "1",
      "severity": "info|warning|critical",
      "message": "Specific, visitor-facing improvement guidance",
      "jump_target_field_id": "1",
      "depends_on_future_field_ids": [],
      "is_suppressed": false
    }
  ],
  "virtual_questions": [
    {
      "question_id": "stable_slug_or_uuid",
      "question": "A concise follow-up question",
      "reason": "Why the answer helps the form owner",
      "target_field_id": "1",
      "required": false,
      "answer_type": "long_text",
      "choices": []
    }
  ],
  "conditional_decisions": [
    {
      "decision_id": "stable_slug_or_uuid",
      "condition_key": "short_decision_key",
      "met": true,
      "confidence": 0.85,
      "reason": "Short rationale"
    }
  ]
}

Form context:
{{form}}

Known answers:
{{entry}}

Realtime runtime context is supplied to the action as suggestion_context. Use current_page_index, visible_field_ids, all_known_field_values, future_field_manifest, request_reason, and panel_state. panel_state contains existing suggestions, follow-up questions, visitor answers, and completed flags; preserve in-progress answers, avoid asking duplicates, and update prior guidance when that is better than replacing it.
PROMPT,
                'default_model'            => 'openrouter/auto',
                'structured_output_schema' => [
                    'type'                 => 'object',
                    'required'             => [ 'suggestions', 'virtual_questions', 'conditional_decisions' ],
                    'additionalProperties' => true,
                    'properties'           => [
                        'suggestions' => [
                            'type'  => 'array',
                            'items' => [
                                'type'                 => 'object',
                                'required'             => [ 'field_id', 'severity', 'message', 'jump_target_field_id' ],
                                'additionalProperties' => true,
                                'properties'           => [
                                    'field_id'                    => [ 'type' => 'string' ],
                                    'severity'                    => [
                                        'type' => 'string',
                                        'enum' => [ 'info', 'warning', 'critical' ],
                                    ],
                                    'message'                     => [ 'type' => 'string' ],
                                    'jump_target_field_id'        => [ 'type' => 'string' ],
                                    'depends_on_future_field_ids' => [
                                        'type'  => 'array',
                                        'items' => [ 'type' => 'string' ],
                                    ],
                                    'is_suppressed'               => [ 'type' => 'boolean' ],
                                ],
                            ],
                        ],
                        'virtual_questions' => [
                            'type'  => 'array',
                            'items' => [
                                'type'                 => 'object',
                                'required'             => [ 'question_id', 'question', 'required', 'answer_type' ],
                                'additionalProperties' => true,
                                'properties'           => [
                                    'question_id'     => [ 'type' => 'string' ],
                                    'question'        => [ 'type' => 'string' ],
                                    'reason'          => [ 'type' => 'string' ],
                                    'target_field_id' => [ 'type' => 'string' ],
                                    'required'        => [ 'type' => 'boolean' ],
                                    'answer_type'     => [
                                        'type' => 'string',
                                        'enum' => [ 'short_text', 'long_text', 'choice' ],
                                    ],
                                    'choices'         => [
                                        'type'  => 'array',
                                        'items' => [ 'type' => 'string' ],
                                    ],
                                ],
                            ],
                        ],
                        'conditional_decisions' => [
                            'type'  => 'array',
                            'items' => [
                                'type'                 => 'object',
                                'required'             => [ 'decision_id', 'condition_key', 'met' ],
                                'additionalProperties' => true,
                                'properties'           => [
                                    'decision_id'   => [ 'type' => 'string' ],
                                    'condition_key' => [ 'type' => 'string' ],
                                    'met'           => [ 'type' => 'boolean' ],
                                    'confidence'    => [ 'type' => 'number' ],
                                    'reason'        => [ 'type' => 'string' ],
                                ],
                            ],
                        ],
                    ],
                ],
                'override_schema'          => [
                    'clarification_goal' => [
                        'type'        => 'string',
                        'default'     => 'Help the visitor provide complete, actionable answers without unnecessary friction.',
                        'description' => 'Form-specific guidance for what complete answers must include.',
                    ],
                    'max_virtual_questions' => [
                        'type'        => 'number',
                        'min'         => 0,
                        'max'         => 5,
                        'default'     => 3,
                        'description' => 'Maximum number of AI-created follow-up questions.',
                    ],
                    'allow_conditional_decisions' => [
                        'type'        => 'boolean',
                        'default'     => true,
                        'description' => 'Allow this action to return boolean decision keys for conditional form behavior.',
                    ],
                ],
                'version'                  => '1',
                'is_active'                => true,
                'hooks'                    => [ 'real_time' ],
                'definition_json'          => [
                    'action_kind'               => 'template_override',
                    'version'                   => 1,
                    'response_format'           => [ 'type' => 'json_object' ],
                    'max_tokens'                => 900,
                    'temperature'               => 0.2,
                    'supported_execution_modes' => [ 'real_time' ],
                    'builder_template'          => 'clarification_assistant',
                ],
                'default_execution_mode'   => 'real_time',
                'effect_mapping_json'      => [
                    'store_result' => true,
                ],
            ],
        ];

        return $definitions;
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_keys( self::definitions() );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get( string $code ): ?array
    {
        $code        = sanitize_key( $code );
        $definitions = self::definitions();

        return $definitions[ $code ] ?? null;
    }

    public static function has( string $code ): bool
    {
        return null !== self::get( $code );
    }

    public static function build_managed_custom_action_code( string $template_code ): string
    {
        return self::MANAGED_CUSTOM_ACTION_PREFIX . sanitize_key( $template_code );
    }

    public static function is_managed_custom_action_code( string $code ): bool
    {
        return str_starts_with( sanitize_key( $code ), self::MANAGED_CUSTOM_ACTION_PREFIX );
    }

    public static function extract_template_code_from_custom_action_code( string $code ): string
    {
        $code = sanitize_key( $code );
        if ( self::is_managed_custom_action_code( $code ) )
        {
            return sanitize_key( substr( $code, strlen( self::MANAGED_CUSTOM_ACTION_PREFIX ) ) );
        }

        if ( str_starts_with( $code, 'imported_' ) )
        {
            $candidate = sanitize_key( substr( $code, strlen( 'imported_' ) ) );
            if ( self::has( $candidate ) )
            {
                return $candidate;
            }

            foreach ( array_keys( self::definitions() ) as $template_code )
            {
                if ( str_starts_with( $candidate, $template_code . '_' ) )
                {
                    return $template_code;
                }
            }
        }

        return self::has( $code ) ? $code : '';
    }
}
