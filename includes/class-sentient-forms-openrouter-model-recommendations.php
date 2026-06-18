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
        $generated_manifest = __DIR__ . '/data/openrouter-model-recommendations.php';
        if ( file_exists( $generated_manifest ) )
        {
            $generated_models = require $generated_manifest;
            if ( is_array( $generated_models ) )
            {
                $keyed = [];
                foreach ( $generated_models as $model )
                {
                    if ( is_array( $model ) && isset( $model['id'] ) && is_scalar( $model['id'] ) )
                    {
                        $keyed[ (string) $model['id'] ] = $model;
                    }
                }

                if ( [] !== $keyed )
                {
                    return self::with_latest_aliases( $keyed );
                }
            }
        }

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
                'recommendation_categories' => [ 'General purpose', 'Research', 'Privacy-sensitive' ],
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
                'recommendation_categories' => [ 'Higher quality', 'Reasoning', 'High-stakes workflows' ],
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
                'id'                   => 'openai/gpt-5.4',
                'name'                 => 'OpenAI: GPT-5.4',
                'free'                 => false,
                'context_length'       => 1050000,
                'input_modalities'     => [ 'file', 'image', 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_completion_tokens', 'max_tokens', 'reasoning', 'response_format', 'seed', 'structured_outputs', 'tool_choice', 'tools' ],
                'pricing'              => [
                    'prompt'           => '0.0000025',
                    'completion'       => '0.000015',
                    'web_search'       => '0.01',
                    'input_cache_read' => '0.00000025',
                ],
                'recommended_for'      => [ 'General purpose', 'Structured output', 'Reasoning', 'Paid model quality' ],
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
                'id'                   => 'deepseek/deepseek-v4-flash',
                'name'                 => 'DeepSeek: DeepSeek V4 Flash',
                'free'                 => false,
                'context_length'       => 1048576,
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'frequency_penalty', 'include_reasoning', 'logit_bias', 'logprobs', 'max_tokens', 'min_p', 'presence_penalty', 'reasoning', 'repetition_penalty', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_k', 'top_logprobs', 'top_p' ],
                'pricing'              => [
                    'prompt'           => '0.00000014',
                    'completion'       => '0.00000028',
                    'input_cache_read' => '0.0000000028',
                ],
                'recommended_for'      => [ 'Speed', 'Low cost', 'Long context', 'Structured output' ],
            ],
            [
                'id'                   => 'deepseek/deepseek-v4-pro',
                'name'                 => 'DeepSeek: DeepSeek V4 Pro',
                'free'                 => false,
                'context_length'       => 1048576,
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'frequency_penalty', 'include_reasoning', 'logit_bias', 'logprobs', 'max_tokens', 'min_p', 'presence_penalty', 'reasoning', 'repetition_penalty', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_k', 'top_logprobs', 'top_p' ],
                'pricing'              => [
                    'prompt'           => '0.000000435',
                    'completion'       => '0.00000087',
                    'input_cache_read' => '0.000000003625',
                ],
                'recommended_for'      => [ 'Low cost', 'Long context', 'Structured output', 'Reasoning' ],
            ],
            [
                'id'                   => 'moonshotai/kimi-k2.6',
                'name'                 => 'MoonshotAI: Kimi K2.6',
                'free'                 => false,
                'context_length'       => 256000,
                'input_modalities'     => [ 'text', 'image' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'frequency_penalty', 'include_reasoning', 'logit_bias', 'logprobs', 'max_tokens', 'min_p', 'parallel_tool_calls', 'presence_penalty', 'reasoning', 'reasoning_effort', 'repetition_penalty', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_k', 'top_logprobs', 'top_p' ],
                'pricing'              => [
                    'prompt'           => '0.0000007448',
                    'completion'       => '0.000004655',
                    'input_cache_read' => '0.0000001463',
                ],
                'recommended_for'      => [ 'Code generation', 'Agentic workflows', 'Structured output', 'Reasoning' ],
                'recommendation_categories' => [ 'Code generation', 'Tool calling' ],
                'benchmark_notes'      => [ 'OpenRouter catalog describes Kimi K2.6 as optimized for long-horizon coding and multi-agent orchestration.' ],
            ],
            [
                'id'                   => 'moonshotai/kimi-k2.5',
                'name'                 => 'MoonshotAI: Kimi K2.5',
                'free'                 => false,
                'context_length'       => 262144,
                'input_modalities'     => [ 'text', 'image' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'frequency_penalty', 'include_reasoning', 'logit_bias', 'logprobs', 'max_tokens', 'min_p', 'parallel_tool_calls', 'presence_penalty', 'reasoning', 'repetition_penalty', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_k', 'top_logprobs', 'top_p' ],
                'pricing'              => [
                    'prompt'           => '0.00000044',
                    'completion'       => '0.000002',
                    'input_cache_read' => '0.00000022',
                ],
                'recommended_for'      => [ 'Long context', 'Code generation', 'Low cost', 'Vision' ],
            ],
            [
                'id'                   => 'qwen/qwen3.6-max-preview',
                'name'                 => 'Qwen: Qwen3.6 Max Preview',
                'free'                 => false,
                'context_length'       => 262144,
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'logprobs', 'max_tokens', 'presence_penalty', 'reasoning', 'response_format', 'seed', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_logprobs', 'top_p' ],
                'pricing'              => [
                    'prompt'            => '0.00000104',
                    'completion'        => '0.00000624',
                    'input_cache_write' => '0.0000013',
                ],
                'recommended_for'      => [ 'Code generation', 'Tool calling', 'Structured output', 'Reasoning' ],
            ],
            [
                'id'                   => 'z-ai/glm-5.1',
                'name'                 => 'Z.ai: GLM 5.1',
                'free'                 => false,
                'context_length'       => 202752,
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'frequency_penalty', 'include_reasoning', 'logit_bias', 'logprobs', 'max_tokens', 'min_p', 'parallel_tool_calls', 'presence_penalty', 'reasoning', 'reasoning_effort', 'repetition_penalty', 'response_format', 'seed', 'stop', 'structured_outputs', 'temperature', 'tool_choice', 'tools', 'top_k', 'top_logprobs', 'top_p' ],
                'pricing'              => [
                    'prompt'           => '0.00000105',
                    'completion'       => '0.0000035',
                    'input_cache_read' => '0.000000525',
                ],
                'recommended_for'      => [ 'Reasoning', 'Code generation', 'Tool calling', 'Price-to-intelligence ratio' ],
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
                'recommendation_categories' => [ 'Legal', 'Financial', 'Reasoning' ],
                'benchmark_notes'      => [ 'LLM Stats April 2026 ranks Claude Sonnet 4.6 first for legal and finance benchmark aggregates.' ],
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
                'recommendation_categories' => [ 'Code generation', 'Reasoning', 'High-stakes workflows' ],
                'benchmark_notes'      => [ 'LLM Stats April 2026 ranks Claude Opus 4.7 second in coding among OpenRouter-available named models in the bundled policy.' ],
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
                'recommended_for'      => [ 'Legacy coding fallback', 'Structured output', 'Reasoning' ],
            ],
            [
                'id'                   => 'poolside/laguna-m.1:free',
                'name'                 => 'Poolside: Laguna M.1 (free)',
                'free'                 => true,
                'context_length'       => 131072,
                'input_modalities'     => [ 'text' ],
                'output_modalities'    => [ 'text' ],
                'supported_parameters' => [ 'include_reasoning', 'max_tokens', 'reasoning', 'temperature', 'tool_choice', 'tools' ],
                'pricing'              => [ 'prompt' => '0', 'completion' => '0' ],
                'recommended_for'      => [ 'Free testing', 'Code generation', 'Reasoning', 'Tool calling' ],
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

        return self::with_latest_aliases( $keyed );
    }

    /**
     * @param array<string, array<string, mixed>> $models
     *
     * @return array<string, array<string, mixed>>
     */
    private static function with_latest_aliases( array $models ): array
    {
        $aliases = [
            '~openai/gpt-latest'              => [ 'openai/gpt-5.5' ],
            '~openai/gpt-mini-latest'         => [ 'openai/gpt-5.4-mini' ],
            '~google/gemini-pro-latest'       => [ 'google/gemini-3.1-pro-preview' ],
            '~google/gemini-flash-latest'     => [ 'google/gemini-3-flash-preview' ],
            '~anthropic/claude-opus-latest'   => [ 'anthropic/claude-opus-4.8', 'anthropic/claude-opus-4.7' ],
            '~anthropic/claude-sonnet-latest' => [ 'anthropic/claude-sonnet-4.6' ],
            '~anthropic/claude-haiku-latest'  => [ 'anthropic/claude-haiku-4.5' ],
        ];

        foreach ( $aliases as $alias => $source_ids )
        {
            if ( isset( $models[ $alias ] ) )
            {
                continue;
            }

            $source_id = null;
            foreach ( $source_ids as $candidate_id )
            {
                if ( isset( $models[ $candidate_id ] ) )
                {
                    $source_id = $candidate_id;
                    break;
                }
            }

            if ( null === $source_id )
            {
                continue;
            }

            $model = $models[ $source_id ];
            $model['id'] = $alias;
            $model['name'] = sprintf(
                /* translators: %s: model display name. */
                __( '%s (latest alias)', 'sentient-forms' ),
                is_scalar( $model['name'] ?? null ) ? (string) $model['name'] : $source_id
            );
            $model['canonical_source_model_id'] = $source_id;
            $model['recommended_for'] = array_values(
                array_unique(
                    array_merge(
                        is_array( $model['recommended_for'] ?? null ) ? $model['recommended_for'] : [],
                        [ __( 'Auto-upgrading latest alias', 'sentient-forms' ) ]
                    )
                )
            );
            $models[ $alias ] = $model;
        }

        return $models;
    }
}
