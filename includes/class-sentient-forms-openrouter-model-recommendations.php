<?php
/**
 * Bundled OpenRouter model recommendations for local-first fallback UX.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

class Sentient_Forms_OpenRouter_Model_Recommendations
{
    /**
     * Returns a reviewed fallback manifest used when the local OpenRouter cache is empty.
     *
     * These entries are bundled with the plugin so wp-admin model selectors remain usable before
     * the site owner accepts OpenRouter's external-service disclosure and refreshes the live cache.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $models = [
            [
                'id'                   => 'openrouter/free',
                'name'                 => 'OpenRouter Free Models Router',
                'free'                 => true,
                'context_length'       => 200000,
                'input_modalities'     => [ 'text', 'image' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'reasoning', 'response_format', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [ 'prompt' => '0', 'completion' => '0' ],
                'recommended_for'      => [ 'Free testing', 'Low-risk workflow proof' ],
            ],
            [
                'id'                   => 'openai/gpt-5.1',
                'name'                 => 'OpenAI: GPT-5.1',
                'free'                 => false,
                'context_length'       => 400000,
                'input_modalities'     => [ 'image', 'text', 'file' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'reasoning', 'response_format', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'           => '0.00000125',
                    'completion'       => '0.00001',
                    'input_cache_read' => '0.00000013',
                ],
                'recommended_for'      => [ 'General purpose', 'Structured output', 'Paid model quality' ],
            ],
            [
                'id'                   => 'anthropic/claude-sonnet-4.5',
                'name'                 => 'Anthropic: Claude Sonnet 4.5',
                'free'                 => false,
                'context_length'       => 1000000,
                'input_modalities'     => [ 'text', 'image', 'file' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'reasoning', 'response_format', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'            => '0.000003',
                    'completion'        => '0.000015',
                    'web_search'        => '0.01',
                    'input_cache_read'  => '0.0000003',
                    'input_cache_write' => '0.00000375',
                ],
                'recommended_for'      => [ 'Reasoning', 'High-quality analysis', 'Long context' ],
            ],
            [
                'id'                   => 'google/gemini-2.5-flash',
                'name'                 => 'Google: Gemini 2.5 Flash',
                'free'                 => false,
                'context_length'       => 1048576,
                'input_modalities'     => [ 'file', 'image', 'text', 'audio', 'video' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'reasoning', 'response_format', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'             => '0.0000003',
                    'completion'         => '0.0000025',
                    'image'              => '0.0000003',
                    'audio'              => '0.000001',
                    'web_search'         => '0.014',
                    'internal_reasoning' => '0.0000025',
                    'input_cache_read'   => '0.00000003',
                ],
                'recommended_for'      => [ 'Speed', 'Low cost', 'Long context' ],
            ],
            [
                'id'                   => 'google/gemini-2.5-pro',
                'name'                 => 'Google: Gemini 2.5 Pro',
                'free'                 => false,
                'context_length'       => 1048576,
                'input_modalities'     => [ 'text', 'image', 'file', 'audio', 'video' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'reasoning', 'response_format', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'             => '0.00000125',
                    'completion'         => '0.00001',
                    'image'              => '0.00000125',
                    'audio'              => '0.00000125',
                    'web_search'         => '0.014',
                    'internal_reasoning' => '0.00001',
                    'input_cache_read'   => '0.000000125',
                ],
                'recommended_for'      => [ 'Long context', 'Structured output', 'Paid model quality' ],
            ],
            [
                'id'                   => 'qwen/qwen3-coder',
                'name'                 => 'Qwen: Qwen3 Coder 480B A35B',
                'free'                 => false,
                'context_length'       => 262144,
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'response_format', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [ 'prompt' => '0.00000022', 'completion' => '0.0000018' ],
                'recommended_for'      => [ 'Code generation', 'Low cost' ],
            ],
            [
                'id'                   => 'openrouter/auto',
                'name'                 => 'OpenRouter Auto',
                'free'                 => false,
                'context_length'       => 2000000,
                'input_modalities'     => [ 'text', 'image', 'audio', 'file', 'video' ],
                'output_modalities'    => [ 'text', 'image' ],
                'supported_parameters' => [ 'reasoning', 'response_format', 'structured_outputs', 'tool_choice', 'tools', 'web_search_options' ],
                'pricing'              => [ 'prompt' => '-1', 'completion' => '-1' ],
                'recommended_for'      => [ 'Provider-routed fallback when a specific model is not selected' ],
            ],
        ];

        $keyed = [];
        foreach ( $models as $model )
        {
            $keyed[ (string) $model['id'] ] = $model;
        }

        return $keyed;
    }
}
