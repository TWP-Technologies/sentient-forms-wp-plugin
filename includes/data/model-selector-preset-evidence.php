<?php
/**
 * Reviewed model-selector preset evidence.
 *
 * This file is intentionally separate from the generated OpenRouter catalog snapshot. It records the
 * evidence-weighted category policy that decides which cached models become Sentient Forms presets.
 *
 * Reviewed: 2026-05-01.
 *
 * @package SentientForms
 */

if ( ! defined( 'ABSPATH' ) )
{
    exit;
}

return [
    'sf_default' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'anthropic/claude-opus-4.7', 'google/gemini-3-flash-preview' ],
        'score'               => 92,
        'evidence_confidence' => 'high',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 93, 'operations' => 86, 'availability' => 95 ],
        'rationale'           => 'Default favors broad Intelligence Index strength, structured/tool support, million-token context, and production-stable paid routing over single-benchmark peaks.',
        'source_urls'         => [
            'https://openrouter.ai/openai/gpt-5.5',
            'https://openrouter.ai/docs/api-reference/parameters',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 92, 'notes' => 'Top Artificial Analysis Intelligence Index signal with strong structured/tool metadata.' ],
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 88, 'notes' => 'Strong broad quality and multimodal reach; higher preview risk.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.7', 'score' => 86, 'notes' => 'Strong reasoning and agentic profile; higher cost and latency.' ],
        ],
    ],
    'sf_general' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'anthropic/claude-sonnet-4.6', 'openai/gpt-5.4' ],
        'score'               => 91,
        'evidence_confidence' => 'high',
        'evaluated_at'        => '2026-05-01',
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
        'preferred_model_ids' => [ 'openai/gpt-5.5-pro', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'google/gemini-3.1-pro-preview' ],
        'score'               => 94,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 96, 'operations' => 80, 'availability' => 91 ],
        'rationale'           => 'Higher quality prioritizes the highest frontier-quality route first, accepting premium cost for high-stakes production workflows.',
        'source_urls'         => [
            'https://openrouter.ai/openai/gpt-5.5-pro',
            'https://openrouter.ai/openai/gpt-5.5',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5-pro', 'score' => 94, 'notes' => 'Premium OpenAI route for maximum quality when available.' ],
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 91, 'notes' => 'Strong quality fallback at materially lower cost.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.7', 'score' => 89, 'notes' => 'Strong alternate frontier model for deep work.' ],
        ],
    ],
    'sf_free' => [
        'preferred_model_ids' => [ 'openrouter/free', 'nvidia/nemotron-3-super-120b-a12b:free', 'tencent/hy3-preview:free', 'inclusionai/ling-2.6-1t:free' ],
        'score'               => 86,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 82, 'operations' => 91, 'availability' => 84 ],
        'rationale'           => 'The free preset intentionally uses OpenRouter free routing first because free endpoint availability changes faster than bundled single-model picks.',
        'source_urls'         => [
            'https://openrouter.ai/openrouter/free',
            'https://openrouter.ai/models',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openrouter/free', 'score' => 86, 'notes' => 'Most resilient no-cost router for workflow proofing.' ],
            [ 'model_id' => 'nvidia/nemotron-3-super-120b-a12b:free', 'score' => 80, 'notes' => 'Specific free model with structured/tool metadata.' ],
        ],
    ],
    'sf_structured' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3-flash-preview', 'google/gemini-3.1-pro-preview', 'nvidia/nemotron-3-super-120b-a12b:free', 'openrouter/free' ],
        'score'               => 90,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
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
        'preferred_model_ids' => [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-flash', 'openai/gpt-5.4-mini' ],
        'score'               => 88,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 87, 'operations' => 93, 'availability' => 84 ],
        'rationale'           => 'Speed is optimized for visitor-facing form latency while retaining structured output, multimodal inputs, and a million-token ceiling.',
        'source_urls'         => [
            'https://openrouter.ai/google/gemini-3-flash-preview',
            'https://openrouter.ai/deepseek/deepseek-v4-flash',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 88, 'notes' => 'Best latency/quality balance among current cached form-safe choices.' ],
            [ 'model_id' => 'google/gemini-3.1-flash-lite-preview', 'score' => 84, 'notes' => 'Lower-cost/lighter fallback when available.' ],
            [ 'model_id' => 'deepseek/deepseek-v4-flash', 'score' => 81, 'notes' => 'Low-cost fast fallback with narrower multimodal evidence.' ],
        ],
    ],
    'sf_low_cost' => [
        'preferred_model_ids' => [ 'deepseek/deepseek-v4-flash', 'google/gemini-3.1-flash-lite-preview', 'google/gemini-3-flash-preview', 'openai/gpt-5.4-mini' ],
        'score'               => 87,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 84, 'operations' => 93, 'availability' => 83 ],
        'rationale'           => 'Low cost favors paid models with very low blended price that still preserve long context and useful benchmark/category coverage.',
        'source_urls'         => [
            'https://openrouter.ai/deepseek/deepseek-v4-flash',
            'https://openrouter.ai/models',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'deepseek/deepseek-v4-flash', 'score' => 87, 'notes' => 'Best cost-first paid default in the current curated set.' ],
            [ 'model_id' => 'google/gemini-3.1-flash-lite-preview', 'score' => 84, 'notes' => 'Strong low-cost fallback when the preview route is available.' ],
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 82, 'notes' => 'Slightly higher cost for stronger multimodal/quality balance.' ],
        ],
    ],
    'sf_long_context' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'openai/gpt-5.4', 'anthropic/claude-opus-4.7', 'moonshotai/kimi-k2.6' ],
        'score'               => 93,
        'evidence_confidence' => 'high',
        'evaluated_at'        => '2026-05-01',
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
        'preferred_model_ids' => [ 'openai/gpt-5.5-pro', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7', 'google/gemini-3.1-pro-preview', 'z-ai/glm-5.1' ],
        'score'               => 93,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 95, 'operations' => 80, 'availability' => 92 ],
        'rationale'           => 'Reasoning now follows the same frontier evidence as the high-quality preset, with OpenAI first and Claude/Gemini as strong alternate reasoning families.',
        'source_urls'         => [
            'https://openrouter.ai/openai/gpt-5.5-pro',
            'https://openrouter.ai/anthropic/claude-opus-4.7',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5-pro', 'score' => 93, 'notes' => 'Premium reasoning route preferred when available.' ],
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 91, 'notes' => 'Top Intelligence Index signal at lower cost than pro.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.7', 'score' => 89, 'notes' => 'Strong alternate deep-reasoning model.' ],
        ],
    ],
    'sf_code' => [
        'preferred_model_ids' => [ 'moonshotai/kimi-k2.6', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6', 'qwen/qwen3.6-max-preview', 'openai/gpt-5.5' ],
        'score'               => 90,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 93, 'operations' => 86, 'availability' => 88 ],
        'rationale'           => 'Coding uses the newer Kimi K2.6 route, which is the current OpenRouter Programming leader in the bundled snapshot and live ranking, instead of older Kimi K2.5.',
        'source_urls'         => [
            'https://openrouter.ai/rankings/programming',
            'https://openrouter.ai/moonshotai/kimi-k2.6',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'moonshotai/kimi-k2.6', 'score' => 90, 'notes' => 'OpenRouter Programming leader with strong agentic coding positioning.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.7', 'score' => 88, 'notes' => 'Strong frontier coding fallback for long-running engineering work.' ],
            [ 'model_id' => 'anthropic/claude-sonnet-4.6', 'score' => 84, 'notes' => 'Lower-cost Anthropic fallback with broad OpenRouter category strength.' ],
        ],
    ],
    'sf_legal' => [
        'preferred_model_ids' => [ 'google/gemini-3.1-pro-preview', 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.7', 'openai/gpt-5.5', 'google/gemini-3-flash-preview' ],
        'score'               => 86,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
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
        'preferred_model_ids' => [ 'anthropic/claude-sonnet-4.6', 'anthropic/claude-opus-4.7', 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'moonshotai/kimi-k2.6' ],
        'score'               => 87,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 88, 'operations' => 82, 'availability' => 89 ],
        'rationale'           => 'Finance emphasizes numerical consistency, document reasoning, and strong professional-task behavior instead of blindly choosing a single OpenRouter finance rank.',
        'source_urls'         => [
            'https://openrouter.ai/models?category=finance',
            'https://openrouter.ai/anthropic/claude-sonnet-4.6',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'anthropic/claude-sonnet-4.6', 'score' => 87, 'notes' => 'Strong OpenRouter finance category signal and stable professional-work fit.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.7', 'score' => 86, 'notes' => 'Higher-quality Anthropic fallback at higher cost.' ],
            [ 'model_id' => 'moonshotai/kimi-k2.6', 'score' => 80, 'notes' => 'Newer Kimi route is preferred over K2.5, but finance evidence is weaker than Sonnet.' ],
        ],
    ],
    'sf_privacy' => [
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'anthropic/claude-sonnet-4.6', 'google/gemini-3.1-pro-preview' ],
        'score'               => 78,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
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
        'preferred_model_ids' => [ 'google/gemini-3-flash-preview', 'google/gemini-3.1-flash-lite-preview', 'deepseek/deepseek-v4-flash', 'openai/gpt-5.4-mini' ],
        'score'               => 89,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 88, 'operations' => 94, 'availability' => 85 ],
        'rationale'           => 'Realtime prioritizes end-to-end responsiveness for form validation and clarification flows while keeping structured output support.',
        'source_urls'         => [
            'https://openrouter.ai/google/gemini-3-flash-preview',
            'https://openrouter.ai/google/gemini-3.1-flash-lite-preview',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3-flash-preview', 'score' => 89, 'notes' => 'Best visitor-facing latency and capability balance.' ],
            [ 'model_id' => 'google/gemini-3.1-flash-lite-preview', 'score' => 85, 'notes' => 'Low-cost realtime fallback.' ],
            [ 'model_id' => 'deepseek/deepseek-v4-flash', 'score' => 81, 'notes' => 'Cost-first realtime fallback.' ],
        ],
    ],
    'sf_multimodal' => [
        'preferred_model_ids' => [ 'google/gemini-3.1-pro-preview', 'google/gemini-3-flash-preview', 'openai/gpt-5.5', 'anthropic/claude-opus-4.7' ],
        'score'               => 91,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
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
        'preferred_model_ids' => [ 'openai/gpt-5.5', 'google/gemini-3.1-pro-preview', 'anthropic/claude-opus-4.7', 'anthropic/claude-sonnet-4.6' ],
        'score'               => 90,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 91, 'operations' => 84, 'availability' => 91 ],
        'rationale'           => 'Research combines broad intelligence, factual recall, web-search parameter support, long-context synthesis, and citation-friendly structured output.',
        'source_urls'         => [
            'https://openrouter.ai/docs/api-reference/parameters',
            'https://openrouter.ai/openai/gpt-5.5',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 90, 'notes' => 'Best broad research default with web-search pricing metadata.' ],
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 88, 'notes' => 'Strong multimodal research fallback.' ],
            [ 'model_id' => 'anthropic/claude-opus-4.7', 'score' => 86, 'notes' => 'Strong synthesis fallback at higher cost.' ],
        ],
    ],
    'sf_agentic' => [
        'preferred_model_ids' => [ 'google/gemini-3.1-pro-preview', 'openai/gpt-5.5', 'moonshotai/kimi-k2.6', 'anthropic/claude-opus-4.7' ],
        'score'               => 89,
        'evidence_confidence' => 'medium',
        'evaluated_at'        => '2026-05-01',
        'score_breakdown'     => [ 'category_fit' => 90, 'operations' => 84, 'availability' => 91 ],
        'rationale'           => 'Tool calling prioritizes native tools, structured outputs, multimodal context, and agentic benchmark signals; Gemini Pro remains the broadest current tool-capable preset.',
        'source_urls'         => [
            'https://openrouter.ai/docs/api-reference/parameters',
            'https://openrouter.ai/google/gemini-3.1-pro-preview',
            'https://openrouter.ai/moonshotai/kimi-k2.6',
        ],
        'top_candidates'      => [
            [ 'model_id' => 'google/gemini-3.1-pro-preview', 'score' => 89, 'notes' => 'Best broad tool/multimodal fit in current preset set.' ],
            [ 'model_id' => 'openai/gpt-5.5', 'score' => 88, 'notes' => 'Strong tool and structured-output fallback.' ],
            [ 'model_id' => 'moonshotai/kimi-k2.6', 'score' => 85, 'notes' => 'Strong coding-agent candidate with lower context ceiling.' ],
        ],
    ],
];
