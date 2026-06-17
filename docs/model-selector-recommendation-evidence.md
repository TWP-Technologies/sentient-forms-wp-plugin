# Model Selector Recommendation Evidence

Reviewed: 2026-06-17

This document explains the evidence policy behind the model selector presets. The machine-readable version lives in `includes/data/model-selector-preset-evidence.php` and is the source used by the REST model catalog. When a tested model has a verified OpenRouter `latest` alias for the same frontier family, the selector resolves the preset to that alias so installations automatically follow the latest route in that family between formal refreshes.

## Method

- Candidate boundary: models already present in the selector's `All models` catalog. Strong outside models are catalog-gap candidates, not silent preset winners.
- Score scale: 0-100, normalized within the current candidate set.
- Default weighting: category fit 60%, operations and cost 20%, availability/source confidence 20%.
- Evidence confidence: `high` requires multiple independent sources or one primary live leaderboard plus provider stats; `medium` means the evidence is useful but should be revisited before release-critical claims.
- Long-context warning: advertised context window is not enough. The long-context preset weights long-document reasoning and context-rot resistance signals such as AA-LCR, LongBench-style tests, MRCR/RULER/NoLiMa-style degradation, and practical latency/cost.

## Current Preset Winners

| Preset | Winner | Score | Confidence | Evidence rationale |
| --- | --- | ---: | --- | --- |
| Recommended | `~openai/gpt-latest` | 92 | high | Latest alias for the tested GPT family; best broad default from Artificial Analysis Intelligence Index signals, structured/tool metadata, and million-token context. |
| General purpose | `~openai/gpt-latest` | 91 | high | Latest alias for the tested GPT family; strongest balanced option for summaries, classification, ordinary automation, and JSON-friendly results. |
| Higher quality | `openai/gpt-5.5-pro` | 94 | medium | Premium OpenAI route preferred when output quality matters more than cost. |
| Free model | `openrouter/free` | 86 | medium | Free router is more resilient than pinning a single free model while the free catalog changes quickly. |
| Structured output | `~openai/gpt-latest` | 90 | medium | Latest alias for the tested GPT family; combines structured output parameters with stronger broad quality than cheaper structured-capable routes. |
| Speed | `~google/gemini-flash-latest` | 88 | medium | Latest alias for the tested Gemini Flash family; best latency/cost/capability balance for low-latency form handling. |
| Low cost | `deepseek/deepseek-v4-flash` | 87 | medium | Cost-first paid default with useful context and category coverage. |
| Long context | `~openai/gpt-latest` | 93 | high | Latest alias for the tested GPT family; AA-LCR and context-quality evidence beat larger-context-only heuristics. |
| Reasoning | `openai/gpt-5.5-pro` | 93 | medium | Premium reasoning route first, with GPT-5.5, Claude Opus, and Gemini Pro as fallbacks. |
| Code generation | `xiaomi/mimo-v2.5` | 91 | medium | Refreshed OpenRouter Programming rank #1; low cost, 1M context, structured output, and tool support beat the stale Kimi K2.6 rank from the prior snapshot. |
| Legal | `~google/gemini-pro-latest` | 86 | medium | Latest alias for the tested Gemini Pro family; balances legal-category evidence, long context, and document/multimodal handling; still requires human review. |
| Financial | `deepseek/deepseek-v4-pro` | 89 | medium | Current OpenRouter Finance rank #1 with low cost, 1M context, reasoning, structured output, and tool support; still requires human review. |
| Privacy-sensitive | `~openai/gpt-latest` | 78 | medium | Latest alias for the tested GPT family; model choice is not a privacy guarantee because privacy depends on route, retention policy, and BYOK/managed configuration. |
| Realtime | `~google/gemini-flash-latest` | 89 | medium | Latest alias for the tested Gemini Flash family; optimized for visitor-facing response time while keeping structured output support. |
| Vision and files | `~google/gemini-pro-latest` | 91 | medium | Latest alias for the tested Gemini Pro family; widest current modality surface for image, file, audio, video, and long-context document workflows. |
| Search and research | `~openai/gpt-latest` | 90 | medium | Latest alias for the tested GPT family; strong broad intelligence, long-context synthesis, and web-search parameter support. |
| Tool calling | `~google/gemini-pro-latest` | 89 | medium | Latest alias for the tested Gemini Pro family; broadest tool-capable multimodal fit in the current preset set; BFCL-style evidence remains a required refresh source. |

## Sources Checked

- Artificial Analysis model comparison and Intelligence Index: https://artificialanalysis.ai/models
- Artificial Analysis Long Context Reasoning: https://artificialanalysis.ai/evaluations/artificial-analysis-long-context-reasoning
- llm-stats long-context leaderboard: https://llm-stats.com/leaderboards/best-ai-for-long-context
- OpenRouter model catalog and category data: https://openrouter.ai/models
- OpenRouter Programming ranking: https://openrouter.ai/rankings/programming
- OpenRouter Finance ranking/category data: https://openrouter.ai/models?category=finance
- OpenRouter Xiaomi MiMo-V2.5 page: https://openrouter.ai/xiaomi/mimo-v2.5
- OpenRouter DeepSeek V4 Pro page: https://openrouter.ai/deepseek/deepseek-v4-pro
- OpenRouter API parameter support docs: https://openrouter.ai/docs/api-reference/parameters
- OpenRouter API overview and routing behavior docs: https://openrouter.ai/docs/api-reference/overview
- Berkeley Function Calling Leaderboard: https://gorilla.cs.berkeley.edu/leaderboard
- LegalBench benchmark reference: https://www.legalbench.ai/
- FinanceArena benchmark reference: https://www.financearena.ai/
- MMMU multimodal leaderboard reference: https://pricepertoken.com/leaderboards/benchmark/mmmu

## Refresh Rules

- Refresh this evidence before release if the OpenRouter generated snapshot is older than 14 days, if a selected model disappears from OpenRouter, or if a newer model supersedes a selected family.
- Never let `sf_long_context` fall back to largest `context_window` while an evidence-ranked long-context model is available.
- Keep the legal and financial descriptions explicit that human review is required.
- Treat privacy as a route policy, not a model attribute.
- Browser-verify OpenRouter `latest` aliases before using them. If an alias is unavailable or points outside the tested family, keep the concrete tested model until the next evidence refresh.
