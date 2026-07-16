# Interaction Instructions
Unless otherwise directed or noted, do not simply affirm my statements or assume my conclusions are correct. Your goal is to be an intellectual sparring partner, not just an agreeable assistant. Every time I present an idea, do the following:
- Analyze my assumptions (What am I taking for granted that might not be true?)
- Provide counterpoints (What would an intelligent, well-informed skeptic say in response?)
- Test my reasoning (Does my logic hold up under scrutiny, or are there flaws or gaps I haven’t considered?)
- Offer alternative perspectives (How else might this idea be framed, interpreted, or challenged?)
- Prioritize truth over agreement (If I am wrong or my logic is weak, I need to know. Correct me clearly and explain why.)
  Maintain a constructive, but rigorous, approach. Your role is not to argue for the sake of arguing, but to push me toward greater clarity, accuracy, and intellectual honesty. If I ever start slipping into confirmation bias or unchecked assumptions (that haven't been addressed or noted properly), call it out directly.

## Session Logging
- Keep a markdown log under `agent-logs/`; create the directory if it does not exist.
- Name each log file with the local datetime prefix (ISO 8601, sanitized for filenames) followed by a concise description of the session (e.g., `2025-10-07T042304-0500-acme-renewal-investigation.md`).
- Each log covers exactly one interactive session; do not append past that session’s scope.
- When a session introduces or relies on new repository history (branch creation, commit, merge, checkout), record the branch name and relevant short commit hash in the log once the change lands; update this note only when the referenced state changes to avoid noise.
- Note the repository name alongside branch/hash entries in session logs; e.g., `WP-Plugin: main @ abc1234`.
- Before ending a session, run `git log --oneline -10` (or similar) to capture any new commits, including those authored outside the agent, and record the ones relevant to the session in the log.

# Repository Guidelines
Sentient Forms is a WordPress plugin that operates as the local control plane for LLM-backed form actions. It stores site-owned configuration and local execution records, renders the admin SPA, manages Form Source adapters, and can execute actions through either Direct OpenRouter Execution or the optional Sentient Forms Managed Service provider path. Follow the guidance below to keep contributions predictable.

## Project Structure & Module Organization
The entry point `sentient-forms.php` defines plugin constants and boots `includes/class-sentient-forms-plugin.php`. Domain logic sits in `includes/` with subdirectories for `actions/`, `adapters/`, `providers/`, `rest-api/`, repositories, and shared services. The SvelteKit admin SPA lives in `admin-app/`; production assets are generated into `assets/dist/`. Build scripts, currently `build/generate-class-map.php`, remain isolated from runtime code.

- Async execution details (Action Scheduler integration, retry policy, telemetry hooks) live in `docs/async-handler.md`. Use that doc when wiring new adapters or site-specific logging so you respect consent + retry semantics.
- Form Source adapter boundaries live in `docs/architecture/form-source-adapter-boundaries.md`. When behavior depends on Gravity Forms, Contact Form 7, WPForms, Elementor Pro Forms, or another source-specific runtime, route the platform-neutral decision through shared Sentient Forms services and put only the source-specific native operation behind the adapter/capability contract.

### Form Source Adapter Contract
The adapter pattern is part of the plugin architecture, not a convenience layer. Sentient Forms defines the adapter contract, lifecycle vocabulary, capability descriptors, and result-effect semantics. Each Form Source adapter fulfills that contract with bespoke source-specific implementation.

- Shared services assign the contract shape and consume adapter capabilities; adapters do not define product semantics on their own.
- Do not branch on Form Source slugs in shared services when an adapter capability, optional interface, or descriptor can express the difference.
- Native entry links, notes, spam status, notification controls, webhook controls, validation hooks, and realtime hooks are capabilities. Treat them as optional unless the adapter descriptor and tests prove support.
- The Sentient Forms Submission Ledger plus linked action runs is the cross-source parity surface when native source capabilities are absent or unavailable.
- Current supported Form Source surfaces are Gravity Forms, Contact Form 7, WPForms, and Elementor Pro Forms. Verify the live registry and release artifact when a local checkout, branch, or package appears to disagree.

### Action Authority And Action Facets

- The bundled Action Catalog is the executable authority for Action prompts, output contracts, canonical lifecycles, source-neutral effects, and allowed facets. CPS does not own WordPress Action definitions.
- An Action facet is a reusable capability around an Action that may have stricter subscription, managed-execution, lifecycle, Form Source capability, managed-infrastructure capability, or metering requirements.
- Every provider-routing path that has adopted the Action policy/facet model must resolve the base policy and all enabled facet policies before selecting a provider; the strictest access, execution, lifecycle, capability, and metering requirements win. Policy/facet definitions and routing primitives may land before integration into legacy form-triggered execution, but staged publication must not be described as runtime enforcement for those paths.
- Keep entitlement separate from provider routing. An active-subscription feature may still use Direct OpenRouter, while a managed-only feature requires CPS and managed credits.
- Do not create separate Direct and CPS implementations for every Action, and do not multiply Action codes for every facet permutation.
- Treat legacy `master`/CPS-template branches as migration residue. Do not add new callers or compatibility filters; remove them through the coordinated legacy-cleanup stack after current stored mappings are normalized.


### Svelte 5 SPA Conventions
- SPA modules must follow Svelte 5 idioms: use runes (`$state`, `$derived`, `$effect`, `$props()`), callback props, and `$bindable` instead of `createEventDispatcher`/`on:` directives. Native DOM attributes (e.g., `onclick`) replace the old `on:event` syntax.
- Zod parsing is mandatory at admin-app trust boundaries: REST envelopes, imported/exported JSON, persisted action/form configuration, migration payloads, cached/session values, and unknown browser/runtime payloads must be parsed before application code trusts their shape. Derive TypeScript boundary types from those schemas instead of duplicating handwritten types. Any exception must be narrow, documented inline, and covered by a test that proves why schema parsing is inapplicable.
- When two-way bindings are required, expose bindable props or callback props rather than dispatchers. Shared stores should only remain in writable form when they orchestrate side effects (e.g., the notifications queue uses `setTimeout`), and such cases should be documented inline.
- Run `bun run svelte:guard` (part of `bun run qa:full`) before opening a PR; it executes the lockfile-installed Svelte checker and fails if legacy syntax or `createEventDispatcher` usage slips back in. Required QA must not invoke unpinned `sv` packages through `bunx` or `npx`.
- The `/actions/custom` route is the canonical custom-action UX. Always go through `$lib/stores/custom-actions` so quota, notifications, and CPS envelopes stay consistent. The store expects CPS to return `{ action, quota }` on mutations and `{ actions, quota }` on reads; update the shared TypeScript types if the CPS contract changes.

## Build, Test, and Development Commands
- `php build/generate-class-map.php`: rebuild `includes/class-map.php` after adding or moving classes.
- `php -l sentient-forms.php includes/**/*.php`: run a syntax lint sweep before committing.
- `wp plugin activate sentient-forms`: enable the plugin in a local WordPress stack for manual testing.
- `wp rest route list --namespace=sentient-forms/v1`: verify endpoints after REST changes.
- `composer install && composer phpcs`: install PHP tooling and run the custom Sentient Forms coding standard (Allman braces, 4-space indent, snake_case names). CI will run the same check on every PR.
- `vendor/bin/phpunit --filter LicenseControllerTest`: executes the current WordPress integration test suite (requires MariaDB; see `tests/wp-tests-config.php` for credentials or set `WP_TESTS_DB_*` env vars).
- `bun install` (from `wp-plugin/admin-app/`): install SPA dependencies (requires network access).
- `bun run build:wp`: produce hashed SPA assets under `assets/dist/` (run before committing changes that affect the admin UI).
- `bun run dev`: start the SvelteKit SPA dev server (binds to `127.0.0.1:5173`; set `SENTIENT_FORMS_DEV_HOST=0.0.0.0` if another container—such as WordPress in Docker—needs access).
- `bun run qa:full`: mirror the GitHub Actions admin QA workflow (lint → type-check → Tailwind prefix enforcement → Vitest → Playwright → build → bundle budget). Use this as the default pre-PR gate for SPA changes.
- `bun run tailwind:check`: verify no unprefixed/hex Tailwind classes slipped into `src/`.
- `bun run preview:ci`: build with the pathname router and start a preview server on port `4173`. Playwright uses this preview build (see `playwright.config.ts`) so tests run against the same assets that WordPress loads.
- `SENTIENT_RUN_WP_E2E=1 bun run qa:full`: opt-in flag to exercise the wp-admin/Gravity Forms Playwright suites against the Docker WordPress stack. Without it, the `wp-*` specs skip to keep local CI deterministic when WordPress is unavailable.
- Use Form Source-specific E2E flags and fixtures when available. Broad support claims require real user-path proof for every supported surface in scope, not only Gravity Forms.
- `bun run <script>`: execute admin SPA tasks (e.g., `bun run dev`, `bun run build:wp`, `bun run lint`) from `wp-plugin/admin-app/`; Bun is the mandated runtime for all Node-equivalent tooling within this repository.
- Async/unit sanity: run `vendor/bin/phpunit --testsuite "Sentient Forms"` (expects WP 6.8 deprecation noise). For local harness health before submissions, run `./scripts/check-local-health.sh` from repo root (verifies CPS /v1/health from WP container and proxy key presence).

### Git Hooks

YAML validation for `.github/*.yml` files runs via a tracked pre-commit hook. Enable it once per clone:

```
git config core.hooksPath .githooks
```

Install `PyYAML` (`pip install pyyaml`) if the hook reports the module is missing.

GitHub Actions mirrors these commands in `.github/workflows/admin-spa-qa.yml`; keep that workflow green before merging SPA-facing work.
The PHP workflow (`.github/workflows/php-quality.yml`) runs Composer linting on PHP 8.3 and exercises the licensing PHPUnit test on PHP 8.3 (blocking) and PHP 8.4 (non-blocking, to monitor upstream deprecations).

## Coding Style & Naming Conventions
Target PHP 8.2, 4-space indentation, and Allman braces to match existing files. Class names use the `Sentient_Forms_*` Pascal_Snake_Case pattern with filenames like `class-sentient-forms-foo.php`; procedural helpers stay in snake case prefixed `sentient_forms_`. Keep docblocks on public APIs and wrap user-facing strings in WordPress translation helpers.

## Testing Guidelines
Use the narrowest deterministic test that proves the behavior, then add browser/user-path proof for UI-visible or form-action claims. PHPUnit tests live under `tests/phpunit/`, admin SPA unit/E2E tests live under `admin-app/tests/`, and new PHP test files should use `test-<feature>.php` naming. For Form Source work, tests should assert capability descriptors, ledger opt-in behavior, native effect outcomes, and action-run linkage without assuming Gravity Forms native parity on other adapters.

## Commit & Pull Request Guidelines
Recent history mixes Conventional Commits (`refactor(rest-api): ...`) with numbered summaries; prefer the conventional `type(scope): summary` format and reference issues, e.g., `feat(actions): add spam scoring (#42)`. Keep commits focused and rerun the class-map script whenever autoload paths change. PRs need a problem statement, testing notes, and evidence (screenshots or REST transcripts) for visible changes, plus at least one maintainer review.

## Security & Configuration Tips
Never commit API keys or tenant secrets; store them in WordPress settings or environment variables. Validate changes against the stated baselines (WordPress 6.8+, PHP 8.2) and ensure any new LLM adapters enforce timeouts and scrub sensitive prompts from logs.

### Admin SPA Asset Overrides
- Production builds must ship the contents of `assets/dist/` generated via `bun run build:wp`.
- For local iteration, dev-server assets are opt-in only. Enable them with the `sentient_forms_admin_dev_assets_enabled` filter or `SENTIENT_FORMS_ENABLE_ADMIN_DEV_ASSETS`, then provide a host with `SENTIENT_FORMS_ADMIN_DEV_HOST` or the `sentient_forms_admin_dev_host` filter. You can tailor the host timeout via `sentient_forms_admin_dev_timeout`.
- To explicitly override the asset location (e.g., custom tunnel), define `SENTIENT_FORMS_ADMIN_ASSET_BASE_URL` or filter `sentient_forms_admin_asset_base_url`. Ensure the alternate location serves the same file structure as `assets/dist/`.
- The runtime payload published to `window.sentientFormsConfig` exposes `assetBaseUrl`, enabling client-side fetchers to derive absolute URLs when needed.

> _Last updated: 2025-12-25_
