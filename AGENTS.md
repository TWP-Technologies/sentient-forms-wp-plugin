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
Sentient Forms is a WordPress plugin that routes form submissions through curated LLM actions. Follow the guidance below to keep contributions predictable.

## Project Structure & Module Organization
The entry point `sentient-forms.php` defines plugin constants and boots `includes/class-sentient-forms-plugin.php`. Domain logic sits in `includes/` with subdirectories for `actions/`, `adapters/`, `llms/`, `rest-api/`, and shared `utilities/`. Admin-facing CSS/JS are transitioning to the SvelteKit SPA located in `admin-app/` (built assets will be emitted into `assets/dist/` in Task 0.6). Legacy PHP-rendered admin scripts persist only until the SPA replaces them. Build scripts, currently `build/generate-class-map.php`, remain isolated from runtime code.

- Async execution details (Action Scheduler integration, retry policy, telemetry hooks) live in `docs/async-handler.md`. Use that doc when wiring new adapters or site-specific logging so you respect consent + retry semantics.

### Svelte 5 SPA Conventions
- SPA modules must follow Svelte 5 idioms: use runes (`$state`, `$derived`, `$effect`, `$props()`), callback props, and `$bindable` instead of `createEventDispatcher`/`on:` directives. Native DOM attributes (e.g., `onclick`) replace the old `on:event` syntax.
- When two-way bindings are required, expose bindable props or callback props rather than dispatchers. Shared stores should only remain in writable form when they orchestrate side effects (e.g., the notifications queue uses `setTimeout`), and such cases should be documented inline.
- Run `bun run svelte:guard` (part of `bun run qa:full`) before opening a PR; it executes `npx sv check` and fails if legacy syntax or `createEventDispatcher` usage slips back in.
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
- `RUN_WP_E2E=1 bun run qa:full`: opt-in flag to exercise the wp-admin/Gravity Forms Playwright suites against the Docker WordPress stack. Without it, the `wp-*` specs skip to keep local CI deterministic when WordPress is unavailable.
- `bun run <script>`: execute admin SPA tasks (e.g., `bun run dev`, `bun run build:wp`, `bun run lint`) from `wp-plugin/admin-app/`; Bun is the mandated runtime for all Node-equivalent tooling within this repository.

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
Automated tests are not yet provisioned, so combine manual QA with lightweight scripting. Run the lint command above, hit critical REST routes with `wp rest get <route>`, and document payloads or UI screenshots in the PR. New test suites should land under `tests/` using filenames `test-<feature>.php` and mirror the plugin bootstrap flow.

## Commit & Pull Request Guidelines
Recent history mixes Conventional Commits (`refactor(rest-api): ...`) with numbered summaries; prefer the conventional `type(scope): summary` format and reference issues, e.g., `feat(actions): add spam scoring (#42)`. Keep commits focused and rerun the class-map script whenever autoload paths change. PRs need a problem statement, testing notes, and evidence (screenshots or REST transcripts) for visible changes, plus at least one maintainer review.

## Security & Configuration Tips
Never commit API keys or tenant secrets; store them in WordPress settings or environment variables. Validate changes against the stated baselines (WordPress 6.8+, PHP 8.2) and ensure any new LLM adapters enforce timeouts and scrub sensitive prompts from logs.

### Admin SPA Asset Overrides
- Production builds must ship the contents of `assets/dist/` generated via `bun run build:wp`.
- For local iteration, the plugin automatically probes `http://localhost:5173/`; if a Vite dev server is running there, assets are served from it. You can tailor the host timeout via filters (`sentient_forms_admin_dev_host`, `sentient_forms_admin_dev_timeout`).
- To explicitly override the asset location (e.g., custom tunnel), define `SENTIENT_FORMS_ADMIN_ASSET_BASE_URL` or filter `sentient_forms_admin_asset_base_url`. Ensure the alternate location serves the same file structure as `assets/dist/`.
- The runtime payload published to `window.sentientFormsConfig` exposes `assetBaseUrl`, enabling client-side fetchers to derive absolute URLs when needed.

> _Last updated: 2025-10-21_
