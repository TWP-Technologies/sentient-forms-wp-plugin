<?php
/**
 * Reviewed model-selector preset evidence.
 *
 * This file is intentionally separate from the generated OpenRouter catalog snapshot. It records the
 * evidence-weighted category policy that decides which cached models become Sentient Forms presets.
 *
 * Reviewed: 2026-06-17.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

return [
    'sf_default' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'anthropic/claude-opus-4.8', 'google/gemini-3-flash-preview' ],
        'score'               => 92,
        'evidence_confidence' => 'high',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 93, 'operations' => 86, 'availability' => 95 ],
        'rationale'           => 'Default favors broad Intelligence Index strength, structured/tool support, million-token context, and production-stable paid routing over single-benchmark peaks.',
        'source_urls'         => [
            'https://openrouter.ai/openai/gpt-5.5',
            'https://openrouter.ai/docs/api-reference/parameters',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 92, 'notes' => 'Top Artificial Analysis Intelligence Index signal with strong structured/tool metadata.' ],
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 88, 'notes' => 'Strong broad quality and multimodal reach; higher preview risk.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.8', 'score' => 86, 'notes' => 'Newest Opus-family fallback with strong reasoning and agentic profile; higher cost and latency.' ],
        ],
    ],
    'sf_general' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.4' ],
        'score'               => 91,
        'evidence_confidence' => 'high',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 92, 'operations' => 87, 'availability' => 93 ],
        'rationale'           => 'General-purpose actions need dependable summaries, classifications, and JSON-friendly output more than the cheapest or most specialized model.',
        'source_urls'         => [
            'https://openrouter.ai/models',
            'https://openrouter.ai/openai/gpt-5.5',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 91, 'notes' => 'Best broad default among current cached paid candidates.' ],
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 85, 'notes' => 'Better latency/cost trade-off when quality ceiling is less important.' ],
            [ 'model_id' => 'anthropic/claude-sonnet-4.6', 'score' => 83, 'notes' => 'Strong reliable fallback with good OpenRouter category coverage.' ],
        ],
    ],
    'sf_quality' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5-pro', 'openai/gpt-5.5', 'anthropic/claude-opus-4.8', 'google/gemini-3.1-pro-preview' ],
        'score'               => 94,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 96, 'operations' => 80, 'availability' => 91 ],
        'rationale'           => 'Higher quality prioritizes the highest frontier-quality route first, accepting premium cost for high-stakes production workflows.',
        'source_urls'         => [
            'https://openrouter.ai/openai/gpt-5.5-pro',
            'https://openrouter.ai/openai/gpt-5.5',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5-pro', 'score' => 94, 'notes' => 'Premium OpenAI route for maximum quality when available.' ],
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 91, 'notes' => 'Strong quality fallback at materially lower cost.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.8', 'score' => 89, 'notes' => 'Newest Opus-family alternate frontier model for deep work.' ],
        ],
    ],
    'sf_free' => [
        'preferred_model_ids' => [ 'openrouter/free', 'openrouter/owl-alpha', 'nvidia/nemotron-3-super-120b-a12b:free', 'tencent/hy3-preview:free', 'inclusionai/ling-2.6-1t:free' ],
        'score'               => 86,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 82, 'operations' => 91, 'availability' => 84 ],
        'rationale'           => 'The free preset intentionally uses OpenRouter free routing first because free endpoint availability changes faster than bundled single-model picks.',
        'source_urls'         => [
            'https://openrouter.ai/openrouter/free',
            'https://openrouter.ai/models',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openrouter/free', 'score' => 86, 'notes' => 'Most resilient no-cost router for workflow proofing.' ],
            [ 'model_id' => 'openrouter/owl-alpha', 'score' => 82, 'notes' => 'Free structured-output/tool-capable route with several current OpenRouter category ranks.' ],
            [ 'model_id' => 'nvidia/nemotron-3-super-120b-a12b:free', 'score' => 80, 'notes' => 'Specific free model with structured/tool metadata.' ],
        ],
    ],
    'sf_structured' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'google/gemini-3.1-pro-preview', 'openrouter/owl-alpha', 'nvidia/nemotron-3-super-120b-a12b:free', 'openrouter/free' ],
        'score'               => 90,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 92, 'operations' => 86, 'availability' => 91 ],
        'rationale'           => 'Structured output weights native response_format/structured_outputs support plus broad model quality so invalid JSON retries stay rare.',
        'source_urls'         => [
            'https://openrouter.ai/docs/api-reference/parameters',
            'https://openrouter.ai/openai/gpt-5.5',
            'https://openrouter.ai/google/gemini-3-flash-preview',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 90, 'notes' => 'Strong quality with structured output and tool parameters.' ],
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 86, 'notes' => 'Structured support with better latency and cost.' ],
            [ 'model_id' => 'openrouter/free', 'score' => 73, 'notes' => 'Useful no-cost fallback but provider routing can vary.' ],
        ],
    ],
    'sf_fast' => [
        'preferred_model_ids' => [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite', 'deepseek/deepseek-v4-flash', 'openai/gpt-5.4-mini' ],
        'score'               => 88,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 87, 'operations' => 93, 'availability' => 84 ],
        'rationale'           => 'Speed is optimized for visitor-facing form latency while retaining structured output, multimodal inputs, and a million-token ceiling.',
        'source_urls'         => [
            'https://openrouter.ai/google/gemini-3-flash-preview',
            'https://openrouter.ai/deepseek/deepseek-v4-flash',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 88, 'notes' => 'Best latency/quality balance among current cached form-safe choices.' ],
            [ 'model_id' => 'google/gemini-3.1-flash-lite', 'score' => 84, 'notes' => 'Lower-cost/lighter stable fallback when available.' ],
            [ 'model_id' => 'deepseek/deepseek-v4-flash', 'score' => 81, 'notes' => 'Low-cost fast fallback with narrower multimodal evidence.' ],
        ],
    ],
    'sf_low_cost' => [
        'preferred_model_ids' => [ 'deepseek/deepseek-v4-flash', 'xiaomi/mimo-v2.5', 'deepseek/deepseek-v4-pro', 'google/gemini-3.1-flash-lite', 'google/gemini-3-flash-preview', 'openai/gpt-5.4-mini' ],
        'score'               => 87,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 84, 'operations' => 93, 'availability' => 83 ],
        'rationale'           => 'Low cost favors paid models with very low blended price that still preserve long context and useful benchmark/category coverage.',
        'source_urls'         => [
            'https://openrouter.ai/deepseek/deepseek-v4-flash',
            'https://openrouter.ai/models',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'deepseek/deepseek-v4-flash', 'score' => 87, 'notes' => 'Best cost-first paid default in the current curated set.' ],
            [ 'model_id' => 'xiaomi/mimo-v2.5', 'score' => 85, 'notes' => 'Low-cost structured/tool-capable model with current top programming and marketing ranks.' ],
            [ 'model_id' => 'google/gemini-3.1-flash-lite', 'score' => 84, 'notes' => 'Stable low-cost multimodal fallback.' ],
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 82, 'notes' => 'Slightly higher cost for stronger multimodal/quality balance.' ],
        ],
    ],
    'sf_long_context' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'openai/gpt-5.4', 'anthropic/claude-opus-4.8', 'moonshotai/kimi-k2.6' ],
        'score'               => 93,
        'evidence_confidence' => 'high',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 95, 'operations' => 82, 'availability' => 94 ],
        'rationale'           => 'Long context prioritizes effective long-document reasoning and context-rot resistance, not the largest advertised context field. GPT-5.5 leads the current AA-LCR evidence while keeping a million-token window.',
        'source_urls'         => [
            'https://openrouter.ai/openai/gpt-5.5',
            'https://openrouter.ai/moonshotai/kimi-k2.6',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 93, 'notes' => 'AA-LCR leader among current selector candidates with million-token context.' ],
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 90, 'notes' => 'Strong AA-LCR result and multimodal long-context support.' ],
            [ 'model_id' => 'moonshotai/kimi-k2.6', 'score' => 83, 'notes' => 'Newer Kimi route improves on K2.5 for coding, but context is about 256K and AA-LCR trails GPT/Gemini.' ],
        ],
    ],
    'sf_reasoning' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5-pro', 'openai/gpt-5.5', 'anthropic/claude-opus-4.8', 'google/gemini-3.1-pro-preview', 'z-ai/glm-5.1' ],
        'score'               => 93,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 95, 'operations' => 80, 'availability' => 92 ],
        'rationale'           => 'Reasoning now follows the same frontier evidence as the high-quality preset, with OpenAI first and Claude/Gemini as strong alternate reasoning families.',
        'source_urls'         => [
            'https://openrouter.ai/openai/gpt-5.5-pro',
            'https://openrouter.ai/anthropic/claude-opus-4.8',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5-pro', 'score' => 93, 'notes' => 'Premium reasoning route preferred when available.' ],
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 91, 'notes' => 'Top Intelligence Index signal at lower cost than pro.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.8', 'score' => 89, 'notes' => 'Newest Opus-family alternate deep-reasoning model.' ],
        ],
    ],
    'sf_code' => [
        'preferred_model_ids' => [ 'xiaomi/mimo-v2.5', 'minimax/minimax-m3', 'tencent/hy3-preview', 'anthropic/claude-opus-4.7', 'deepseek/deepseek-v4-pro', 'moonshotai/kimi-k2.6' ],
        'score'               => 91,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 96, 'operations' => 88, 'availability' => 86 ],
        'rationale'           => 'Coding follows the refreshed OpenRouter Programming ranks while preserving structured output and tool support. Xiaomi MiMo-V2.5 is the current Programming rank #1 with low cost, one-million-token context, and structured/tool metadata.',
        'source_urls'         => [
            'https://openrouter.ai/rankings/programming',
            'https://openrouter.ai/xiaomi/mimo-v2.5',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'xiaomi/mimo-v2.5', 'score' => 91, 'notes' => 'Current OpenRouter Programming rank #1 with low cost, tools, structured output, and 1M context.' ],
            [ 'model_id' => 'minimax/minimax-m3', 'score' => 89, 'notes' => 'Current Programming rank #2 with structured/tool support and 1M context.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.7', 'score' => 87, 'notes' => 'Higher-cost frontier fallback with current Programming rank #4.' ],
        ],
    ],
    'sf_legal' => [
        'preferred_model_ids' => [ 'google/gemini-3.1-pro-preview', 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.7', 'openai/gpt-5.5', 'google/gemini-3-flash-preview' ],
        'score'               => 86,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 87, 'operations' => 81, 'availability' => 89 ],
        'rationale'           => 'Legal favors a strong reasoning model with long context and legal-category evidence, while preserving the required human-review warning.',
        'source_urls'         => [
            'https://openrouter.ai/models?category=legal',
            'https://openrouter.ai/google/gemini-3.1-pro-preview',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 86, 'notes' => 'Best balance of legal category coverage, long context, and multimodal document handling.' ],
            [ 'model_id' => 'anthropic/claude-sonnet-4.6', 'score' => 84, 'notes' => 'Strong fallback with broad professional-work category coverage.' ],
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 80, 'notes' => 'Good OpenRouter legal rank but lower high-stakes quality ceiling.' ],
        ],
    ],
    'sf_financial' => [
        'preferred_model_ids' => [ 'deepseek/deepseek-v4-pro', 'deepseek/deepseek-v4-flash', 'xiaomi/mimo-v2.5', 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.8' ],
        'score'               => 89,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 94, 'operations' => 90, 'availability' => 84 ],
        'rationale'           => 'Finance emphasizes current finance-category fit, numerical consistency, reasoning support, structured output, and cost. DeepSeek V4 Pro is the refreshed Finance rank #1 and keeps low-cost 1M-context structured/tool support; human review remains required.',
        'source_urls'         => [
            'https://openrouter.ai/models?category=finance',
            'https://openrouter.ai/deepseek/deepseek-v4-pro',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'deepseek/deepseek-v4-pro', 'score' => 89, 'notes' => 'Current OpenRouter Finance rank #1 with reasoning, structured output, tools, and low cost.' ],
            [ 'model_id' => 'deepseek/deepseek-v4-flash', 'score' => 87, 'notes' => 'Current Finance rank #2 with broader category coverage and lower-latency positioning.' ],
            [ 'model_id' => 'anthropic/claude-sonnet-4.6', 'score' => 84, 'notes' => 'Professional-work fallback with higher cost and lower refreshed finance rank.' ],
        ],
    ],
    'sf_privacy' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3.1-pro-preview' ],
        'score'               => 78,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 72, 'operations' => 76, 'availability' => 88 ],
        'rationale'           => 'Privacy-sensitive is a route and retention-policy choice first. The preset keeps a strong model default but does not claim model selection alone guarantees privacy.',
        'source_urls'         => [
            'https://openrouter.ai/docs/api-reference/overview',
            'https://openrouter.ai/openai/gpt-5.5',
            'https://openrouter.ai/anthropic/claude-sonnet-4.6',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 78, 'notes' => 'Capability default; route/provider policy still governs privacy.' ],
            [ 'model_id' => 'anthropic/claude-sonnet-4.6', 'score' => 77, 'notes' => 'Strong alternate provider family for policy-based routing decisions.' ],
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 74, 'notes' => 'Useful for multimodal private workflows when route policy allows.' ],
        ],
    ],
    'sf_realtime' => [
        'preferred_model_ids' => [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite', 'deepseek/deepseek-v4-flash', 'openai/gpt-5.4-mini' ],
        'score'               => 89,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 88, 'operations' => 94, 'availability' => 85 ],
        'rationale'           => 'Realtime prioritizes end-to-end responsiveness for form validation and clarification flows while keeping structured output support.',
        'source_urls'         => [
            'https://openrouter.ai/google/gemini-3-flash-preview',
            'https://openrouter.ai/google/gemini-3.1-flash-lite',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 89, 'notes' => 'Best visitor-facing latency and capability balance.' ],
            [ 'model_id' => 'google/gemini-3.1-flash-lite', 'score' => 85, 'notes' => 'Low-cost stable realtime fallback.' ],
            [ 'model_id' => 'deepseek/deepseek-v4-flash', 'score' => 81, 'notes' => 'Cost-first realtime fallback.' ],
        ],
    ],
    'sf_multimodal' => [
        'preferred_model_ids' => [ 'google/gemini-3.1-pro-preview', 'google/gemini-3-flash-preview', 'openai/gpt-5.5', 'anthropic/claude-opus-4.8' ],
        'score'               => 91,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 94, 'operations' => 84, 'availability' => 90 ],
        'rationale'           => 'Vision and files require image, file, audio/video metadata and strong document reasoning; Gemini Pro keeps the widest current modality surface.',
        'source_urls'         => [
            'https://openrouter.ai/google/gemini-3.1-pro-preview',
            'https://openrouter.ai/google/gemini-3-flash-preview',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 91, 'notes' => 'Best wide-modality fit for files, screenshots, audio, and video context.' ],
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 86, 'notes' => 'Faster/lower-cost multimodal fallback.' ],
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 84, 'notes' => 'Strong file/image fallback with less modality breadth.' ],
        ],
    ],
    'sf_research' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'anthropic/claude-opus-4.8', 'anthropic/claude-sonnet-4.6' ],
        'score'               => 90,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 91, 'operations' => 84, 'availability' => 91 ],
        'rationale'           => 'Research combines broad intelligence, factual recall, web-search parameter support, long-context synthesis, and citation-friendly structured output.',
        'source_urls'         => [
            'https://openrouter.ai/docs/api-reference/parameters',
            'https://openrouter.ai/openai/gpt-5.5',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 90, 'notes' => 'Best broad research default with web-search pricing metadata.' ],
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 88, 'notes' => 'Strong multimodal research fallback.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.8', 'score' => 86, 'notes' => 'Newest Opus-family synthesis fallback at higher cost.' ],
        ],
    ],
    'sf_agentic' => [
        'preferred_model_ids' => [ 'google/gemini-3.1-pro-preview', 'openai/gpt-5.5', 'xiaomi/mimo-v2.5', 'minimax/minimax-m3', 'anthropic/claude-opus-4.8' ],
        'score'               => 89,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-06-17',
        'score_breakdown'     => [ 'category_fit' => 90, 'operations' => 84, 'availability' => 91 ],
        'rationale'           => 'Tool calling prioritizes native tools, structured outputs, multimodal context, and agentic benchmark signals; Gemini Pro remains the broadest current tool-capable preset.',
        'source_urls'         => [
            'https://openrouter.ai/docs/api-reference/parameters',
            'https://openrouter.ai/google/gemini-3.1-pro-preview',
            'https://openrouter.ai/xiaomi/mimo-v2.5',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 89, 'notes' => 'Best broad tool/multimodal fit in current preset set.' ],
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 88, 'notes' => 'Strong tool and structured-output fallback.' ],
            [ 'model_id' => 'xiaomi/mimo-v2.5', 'score' => 86, 'notes' => 'Current high-ranking low-cost tool-capable coding/agentic candidate with 1M context.' ],
        ],
    ],
];
