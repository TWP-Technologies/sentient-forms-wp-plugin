# Sentient Forms WordPress Plugin

Sentient Forms is migrating to a local-first WordPress plugin for AI-assisted form automation. Gravity Forms is the first supported adapter.

The new product direction keeps site-owned configuration in WordPress: provider settings, consent records, action templates, custom actions, form mappings, execution events, selected results, migration runs, and model cache data. A reduced Sentient service remains only for optional managed execution, billing, metering, account state, optional OAuth brokering, optional managed manifests, and operational controls.

## Current Local-First Slice

- Local custom tables and repositories for providers, consent, action templates, custom actions, mappings, execution events, migrations, and model cache.
- Direct OpenRouter client using the WordPress HTTP API for key validation and chat completions.
- Consent-gated local provider REST endpoints and redacted credential listing.
- Local workspace REST endpoints for templates, custom actions, mappings, execution events, support bundles, and mapping test runs.
- Local prompt rendering, structured JSON result extraction, idempotent execution-event recording, Gravity Forms-style result effects, and post-execution notes/email/hooks/webhooks with entry-level audit metadata.
- Privacy export/erase hooks, scheduled execution-event retention cleanup, configurable uninstall behavior, and redacted support bundles.
- WordPress.org source/package scanner plus a repeatable package-directory builder.
- CPS `/v2` minimal-service shell with a route allowlist test.

## Development Workflow

1. **WordPress stack**: Boot the local Docker compose environment or point the plugin at an existing WordPress instance running Gravity Forms. Activate the plugin with `wp plugin activate sentient-forms`.
2. **Admin SPA**: From `admin-app/`, run `bun install` once, then use:
   - `bun run dev` for local SPA development.
   - `bun run build:wp` before committing UI changes; this copies hashed assets into `assets/dist/`.
   - `bun run check`, `bun run lint`, and targeted Vitest/Playwright suites for SPA verification.
3. **PHP tooling**: Run `composer install`, regenerate the class map with `php build/generate-class-map.php` after adding classes, and execute focused PHPUnit suites under `tests/phpunit/`.
4. **WordPress.org package checks**:
   - `composer wporg-scan` checks the source tree and built admin assets for early package blockers.
   - `composer wporg-build-package` builds a clean package directory under `../temp/YYYY/MM/DD/<time>-wporg-package/sentient-forms`.
   - `composer wporg-scan-package -- <package-dir>` or `php scripts/scan-wporg-package.php <package-dir>` checks the exact package directory.

## Key Paths

- `sentient-forms.php` boots the plugin and registers activation, deactivation, and uninstall hooks.
- `includes/class-sentient-forms-installer.php` owns local table install/upgrade and retention scheduling.
- `includes/repositories/` contains local-first data access classes.
- `includes/providers/` contains provider-specific clients such as OpenRouter direct execution.
- `includes/services/` contains local execution, prompt rendering, result application, privacy/data governance, support bundle, and legacy CPS-adjacent services.
- `includes/rest-api/controllers/class-local-providers-controller.php` exposes local provider onboarding APIs.
- `includes/rest-api/controllers/class-local-workspace-controller.php` exposes local workspace, execution, support, and test-run APIs.
- `scripts/build-wporg-package.php` builds the package directory used for WordPress.org artifact checks.
- `scripts/scan-wporg-package.php` performs early static checks for source/package cleanliness.
- `docs/plans/option-2-local-first-migration-plan.md` is the authoritative migration plan.

## Still Not Complete

The local-first migration is not finished. The current code proves several core contracts, but the release gates still require the admin SPA rewrite, full Gravity Forms hook integration, local async execution, migration/import or approved reset, managed proxy extraction, Plugin Check, readme validation, license audit, reviewer-perspective install, and beta evidence.

## Git Hooks

This repo ships a tracked pre-commit hook that parses every `.github/*.yml` file. Enable it once per clone:

```bash
git config core.hooksPath .githooks
```

Install `PyYAML` if the hook reports that the module is missing.
