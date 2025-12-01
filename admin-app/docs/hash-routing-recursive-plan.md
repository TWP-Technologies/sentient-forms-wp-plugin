# Session Prompt (immutable reference)

<original_prompt>
<DATE>2025-12-01</DATE>

[Main Goal]
Ship hash-based routing in the admin SPA (SvelteKit) with tests to prove navigation works in WordPress admin, then fix the action configuration navigation that currently lands on server 404s.

[Context Anchors]
- Router type is build-time: `SENTIENT_FORMS_ROUTER` defaults to hash; pathname is used for preview/CI.
- Current pain: `goto('/actions/...')` and similar links hit Apache/WordPress and 404 instead of staying in the SPA.
- WP admin can’t serve deep links; hash routing avoids rewrites. Asset/base paths must stay aligned with plugin enqueue URL.
- Tests exist (Vitest + Playwright); we can add focused unit tests to validate routing helpers before and after migration.

[Recursive Loop Directives]
- Work in checkpoints: (1) Baseline + test harness, (2) Hash routing helpers wired, (3) Action configuration navigation fixed, (4) Full test pass.
- After each checkpoint: verify with tests, reassess blockers, and loop if any regressions appear.
- Prefer small, reversible changes; keep navigation helpers isolated so they can be toggled per router type.

[Recursion Process Loop]
- Clarify the immediate checkpoint and success criteria.
- Inspect code + tests relevant to that checkpoint.
- Choose the smallest change that satisfies the criteria; implement it.
- Run targeted tests; if red, debug and loop; if green, proceed to next checkpoint.

[Requirements to Satisfy]
- New routing helper(s) that normalize paths for hash vs pathname builds, reused by nav links and programmatic navigation.
- Tests proving helper behavior for both router types and covering the action Configure path.
- Action configuration screen reachable from Actions list without server 404 (hash navigation).
- All local unit tests green after changes.

[Operational Guardrails]
- Use Bun for scripts/tests; no network-dependent steps in tests.
- Keep WP-admin compatibility: no reliance on server rewrites; avoid absolute paths that bypass the hash.
- Avoid widening scope beyond routing/navigation.
</original_prompt>
