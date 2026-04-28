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
        // Reviewed against OpenRouter's public model catalog on 2026-04-28.
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
                'id'                   => 'nvidia/nemotron-3-super-120b-a12b:free',
                'name'                 => 'NVIDIA: Nemotron 3 Super (free)',
                'free'                 => true,
                'context_length'       => 262144,
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_tokens', 'reasoning', 'response_format', 'seed', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_p' ],
                'pricing'              => [ 'prompt' => '0', 'completion' => '0' ],
                'recommended_for'      => [ 'Free testing', 'Structured output', 'Reasoning' ],
            ],
            [
                'id'                   => 'openai/gpt-5.5',
                'name'                 => 'OpenAI: GPT-5.5',
                'free'                 => false,
                'context_length'       => 1050000,
                'input_modalities'     => [ 'file', 'image', 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_completion_tokens', 'max_tokens', 'reasoning', 'response_format', 'seed', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'           => '0.000005',
                    'completion'       => '0.00003',
                    'web_search'       => '0.01',
                    'input_cache_read' => '0.0000005',
                ],
                'recommended_for'      => [ 'General purpose', 'Structured output', 'Reasoning', 'Paid model quality' ],
            ],
            [
                'id'                   => 'openai/gpt-5.5-pro',
                'name'                 => 'OpenAI: GPT-5.5 Pro',
                'free'                 => false,
                'context_length'       => 1050000,
                'input_modalities'     => [ 'file', 'image', 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_tokens', 'reasoning', 'response_format', 'seed', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'     => '0.00003',
                    'completion' => '0.00018',
                    'web_search' => '0.01',
                ],
                'recommended_for'      => [ 'Highest quality', 'Reasoning', 'High-stakes workflows' ],
            ],
            [
                'id'                   => 'openai/gpt-5.4-mini',
                'name'                 => 'OpenAI: GPT-5.4 Mini',
                'free'                 => false,
                'context_length'       => 400000,
                'input_modalities'     => [ 'file', 'image', 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_completion_tokens', 'max_tokens', 'reasoning', 'response_format', 'seed', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'           => '0.00000075',
                    'completion'       => '0.0000045',
                    'web_search'       => '0.01',
                    'input_cache_read' => '0.000000075',
                ],
                'recommended_for'      => [ 'Low cost', 'Structured output', 'Price-to-intelligence ratio' ],
            ],
            [
                'id'                   => 'google/gemini-3.1-pro-preview',
                'name'                 => 'Google: Gemini 3.1 Pro Preview',
                'free'                 => false,
                'context_length'       => 1048576,
                'input_modalities'     => [ 'audio', 'file', 'image', 'text', 'video' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_tokens', 'reasoning', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_p' ],
                'pricing'              => [
                    'prompt'             => '0.000002',
                    'completion'         => '0.000012',
                    'image'              => '0.000002',
                    'audio'              => '0.000002',
                    'web_search'         => '0.014',
                    'internal_reasoning' => '0.000012',
                    'input_cache_read'   => '0.0000002',
                    'input_cache_write'  => '0.000000375',
                ],
                'recommended_for'      => [ 'Long context', 'Structured output', 'Multimodal workflows', 'Paid model quality' ],
            ],
            [
                'id'                   => 'google/gemini-3-flash-preview',
                'name'                 => 'Google: Gemini 3 Flash Preview',
                'free'                 => false,
                'context_length'       => 1048576,
                'input_modalities'     => [ 'text', 'image', 'file', 'audio', 'video' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_tokens', 'reasoning', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_p' ],
                'pricing'              => [
                    'prompt'             => '0.0000005',
                    'completion'         => '0.000003',
                    'image'              => '0.0000005',
                    'audio'              => '0.000001',
                    'web_search'         => '0.014',
                    'internal_reasoning' => '0.000003',
                    'input_cache_read'   => '0.00000005',
                    'input_cache_write'  => '0.00000008333333333333334',
                ],
                'recommended_for'      => [ 'Speed', 'Low cost', 'Long context', 'Structured output' ],
            ],
            [
                'id'                   => 'google/gemini-3.1-flash-lite-preview',
                'name'                 => 'Google: Gemini 3.1 Flash Lite Preview',
                'free'                 => false,
                'context_length'       => 1048576,
                'input_modalities'     => [ 'text', 'image', 'video', 'file', 'audio' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_tokens', 'reasoning', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_p' ],
                'pricing'              => [
                    'prompt'             => '0.00000025',
                    'completion'         => '0.0000015',
                    'image'              => '0.00000025',
                    'audio'              => '0.0000005',
                    'web_search'         => '0.014',
                    'internal_reasoning' => '0.0000015',
                    'input_cache_read'   => '0.000000025',
                    'input_cache_write'  => '0.00000008333333333333334',
                ],
                'recommended_for'      => [ 'Speed', 'Lowest paid cost', 'Long context' ],
            ],
            [
                'id'                   => 'anthropic/claude-sonnet-4.6',
                'name'                 => 'Anthropic: Claude Sonnet 4.6',
                'free'                 => false,
                'context_length'       => 1000000,
                'input_modalities'     => [ 'text', 'image' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_completion_tokens', 'max_tokens', 'reasoning', 'response_format', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_k', 'top_p', 'verbosity' ],
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
                'id'                   => 'anthropic/claude-opus-4.7',
                'name'                 => 'Anthropic: Claude Opus 4.7',
                'free'                 => false,
                'context_length'       => 1000000,
                'input_modalities'     => [ 'text', 'image' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_tokens', 'reasoning', 'response_format', 'stop', 'structured_outputs', 'tool_choice', 'tools', 'verbosity' ],
                'pricing'              => [
                    'prompt'            => '0.000005',
                    'completion'        => '0.000025',
                    'web_search'        => '0.01',
                    'input_cache_read'  => '0.0000005',
                    'input_cache_write' => '0.00000625',
                ],
                'recommended_for'      => [ 'Highest quality', 'Reasoning', 'High-stakes workflows' ],
            ],
            [
                'id'                   => 'openai/gpt-5.3-codex',
                'name'                 => 'OpenAI: GPT-5.3-Codex',
                'free'                 => false,
                'context_length'       => 400000,
                'input_modalities'     => [ 'text', 'image', 'file' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_completion_tokens', 'max_tokens', 'reasoning', 'response_format', 'seed', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'           => '0.00000175',
                    'completion'       => '0.000014',
                    'web_search'       => '0.01',
                    'input_cache_read' => '0.000000175',
                ],
                'recommended_for'      => [ 'Code generation', 'Structured output', 'Reasoning' ],
            ],
            [
                'id'                   => 'openrouter/auto',
                'name'                 => 'OpenRouter Auto Router',
                'free'                 => false,
                'context_length'       => 2000000,
                'input_modalities'     => [ 'text', 'image', 'audio', 'file', 'video' ],
                'output_modalities'    => [ 'text', 'image' ],
                'supported_parameters' => [ 'frequency_penalty', 'include_reasoning', 'logit_bias', 'logprobs', 'max_completion_tokens', 'max_tokens', 'min_p', 'presence_penalty', 'reasoning', 'repetition_penalty', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_k', 'top_logprobs', 'top_p', 'web_search_options' ],
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
