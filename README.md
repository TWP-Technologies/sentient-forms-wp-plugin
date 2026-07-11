# Sentient Forms WordPress Plugin

Sentient Forms is a local-first WordPress plugin for AI-assisted form automation. The current public release supports Gravity Forms, Contact Form 7, WPForms, and Elementor Pro Forms, with builder-specific capability boundaries documented in `readme.txt`.

The plugin stores site-owned configuration in WordPress: provider settings, external-service consent records, action templates, custom actions, form mappings, execution events, selected results, migration runs, and model cache data. Sentient Forms Managed Execution remains optional for managed account setup, billing, metering, model execution, support diagnostics, and operational controls. Site owners can also run supported workflows directly through their own OpenRouter account.

## WordPress.org Submission Surface

For WordPress.org, `readme.txt` is the public plugin-directory readme and must stay aligned with the submitted package. `README.md` is a developer-maintenance overview for the GitHub repository.

The clean package builder intentionally excludes this `README.md` and includes `readme.txt`, runtime files, generated admin assets, and bundled runtime dependencies. Generated JavaScript and CSS source/build tooling is documented through the public release source URL in `readme.txt` and `assets/dist/SOURCE.md`; do not include `admin-app/` in the WordPress.org ZIP.

Before submitting a package to WordPress.org, verify the exact ZIP or extracted package, not just the source tree. A submission-ready package must pass:

- WordPress.org package scan.
- WordPress Plugin Check in GitHub Actions.
- GPL-compatible license audit.
- Local and official WordPress.org readme validation.
- Generated admin-asset source verification.
- Release-version surface validation across `sentient-forms.php`, `readme.txt`, `admin-app/package.json`, and the release manifest.

Do not submit an older public release ZIP after security or compliance fixes land. Build and verify a fresh release artifact, then submit the validated ZIP for that release.

## Current Local-First Slice

- Local custom tables and repositories for providers, consent, action templates, custom actions, mappings, execution events, migrations, and model cache.
- Direct OpenRouter client using the WordPress HTTP API for key validation and chat completions.
- Optional Sentient Forms Managed Execution for managed AI execution, account state, billing, metering, and support diagnostics.
- Consent-gated provider setup, managed-service setup, metadata-only local diagnostics, Site Context generation, and realtime assistant flows.
- Local workspace REST endpoints for templates, custom actions, mappings, execution events, support bundles, and mapping test runs.
- Local prompt rendering, structured JSON result extraction, idempotent execution-event recording, Gravity Forms-style result effects, ledger-backed review surfaces for supported non-Gravity builders, and post-execution notes/email/hooks/webhooks where the adapter supports them.
- Content Validation can block invalid submissions on all four Form Sources through the field or form error capabilities each builder provides. Spam Detection uses native spam state for Gravity Forms and Contact Form 7; on WPForms and Elementor Pro Forms it blocks through validation without claiming native spam state. Realtime Clarification Assistant remains Gravity Forms-only. Contact Form 7, WPForms, and Elementor Pro Forms use the Sentient Forms Submission Ledger for supported after-submission review workflows; Elementor Pro Forms requires Elementor Pro Forms APIs. Native submission-entry, webhook-control, and notification-control effects remain builder-specific capabilities rather than universal parity claims.
- Privacy export/erase hooks, scheduled execution-event retention cleanup, configurable uninstall behavior, and redacted support bundles.
- WordPress.org source/package scanners, release-version checks, readme validation, license audit, and a repeatable clean package-directory builder.

## External Services and Consent

The WordPress.org `readme.txt` is the authoritative submission disclosure for external services. Keep it current whenever the plugin adds or changes provider paths, webhooks, managed-service behavior, realtime suggestions, or AI-generated Site Context.

Current external-service categories disclosed in `readme.txt`:

- OpenRouter direct execution.
- Sentient Forms Managed Execution.
- Administrator-configured webhooks.
- Realtime Clarification Assistant.

No OpenRouter or Sentient AI execution request should be sent until an administrator has configured and accepted the relevant provider disclosure. Optional diagnostic consent enables only metadata-safe local hooks; the separate on-site logging setting controls the plugin's masked writer, debug mode cannot bypass consent, and no telemetry leaves the WordPress site in this release.

## Development Workflow

1. **WordPress stack**: Boot the local Docker compose environment or point the plugin at an existing WordPress instance running one of the supported form builders. Activate the plugin with `wp plugin activate sentient-forms`. All four Form Sources support the shipped validation contracts described above. Gravity Forms has the deepest native after-submission effects; Contact Form 7, WPForms, and Elementor Pro Forms require the Sentient Forms Submission Ledger for supported after-submission review workflows. Elementor Pro Forms also requires Elementor Pro Forms APIs.
2. **Admin SPA**: From `admin-app/`, run `bun install` once, then use:
   - `bun run dev` for local SPA development.
   - `bun run build:wp` before committing UI changes; this copies hashed assets into `assets/dist/`.
   - `bun run check`, `bun run lint`, and targeted Vitest/Playwright suites for SPA verification.
3. **PHP tooling**: Run `composer install`, regenerate the class map with `php build/generate-class-map.php` after adding classes, and execute focused PHPUnit suites under `tests/phpunit/`.
4. **WordPress.org package checks**:
   - `composer wporg-release-version-check` checks version surfaces in the source tree.
   - `composer wporg-source-check` verifies generated admin assets document the public release source URL and build commands.
   - `composer wporg-scan` checks the source tree and built admin assets for early package blockers.
   - `composer wporg-license-audit` checks source-tree GPL compatibility signals.
   - `composer wporg-readme-check` validates `readme.txt` locally.
   - `composer wporg-readme-validate` validates `readme.txt` with the official WordPress.org readme validator.
   - `composer wporg-build-package` builds a clean package directory under `../temp/YYYY/MM/DD/<time>-wporg-package/sentient-forms`.
   - `composer wporg-scan-package -- <package-dir>` checks the exact package directory.
   - `composer wporg-license-audit-package -- <package-dir>` checks the exact package directory's license metadata.
   - `php scripts/verify-wporg-source.php <package-dir>` verifies generated admin asset source metadata in the package.

## Key Paths

- `sentient-forms.php` boots the plugin and registers activation, deactivation, and uninstall hooks.
- `readme.txt` is the WordPress.org plugin-directory readme and external-service disclosure.
- `assets/dist/` contains generated admin assets copied from `admin-app/`.
- `assets/dist/SOURCE.md` documents the generated admin asset public source URL and build process for packaged releases.
- `admin-app/` contains the SvelteKit admin SPA source, tests, and build scripts.
- `includes/class-sentient-forms-installer.php` owns local table install/upgrade and retention scheduling.
- `includes/repositories/` contains local-first data access classes.
- `includes/providers/` contains provider-specific clients such as OpenRouter direct execution.
- `includes/services/` contains local execution, prompt rendering, result application, privacy/data governance, support bundle, local diagnostics, and managed-service support.
- `includes/rest-api/controllers/class-local-providers-controller.php` exposes local provider onboarding APIs.
- `includes/rest-api/controllers/class-local-workspace-controller.php` exposes local workspace, execution, support, and test-run APIs.
- `scripts/build-wporg-package.php` builds the clean package directory used for WordPress.org artifact checks.
- `scripts/scan-wporg-package.php` performs early static checks for source/package cleanliness.
- `scripts/audit-wporg-licenses.php` audits GPL-compatible license metadata.
- `scripts/validate-wporg-readme.php` runs local or official WordPress.org readme validation.
- `scripts/verify-wporg-source.php` verifies generated asset source disclosure.
- `../docs/plans/option-2-local-first-migration-plan.md` is the workspace-level local-first migration plan.

## Release Readiness

Release confidence is based on the repository gates and the root Sentient Forms greenlight checklist. A production package should not be promoted until the exact built artifact has passed the WordPress.org package scan, Plugin Check, license audit, readme validation, focused PHPUnit/SPA checks, and browser-path evidence for the supported workflows in that release. For 0.10.0, evidence must cover the declared validation behavior across all four Form Sources, supported after-submission ledger workflows, and Gravity Forms-only realtime behavior without implying universal native-effect parity.

For WordPress.org submission, prefer the latest validated GitHub release ZIP and manifest over an ad hoc local ZIP. The public source tag named in `readme.txt` and `assets/dist/SOURCE.md` must exist before upload. If a local rebuild is necessary, run the same package checks against the rebuilt package and preserve the package path/hash in release evidence.

## License

First-party Sentient Forms plugin and admin app code is licensed under GPL-2.0-or-later. Bundled dependencies retain their own GPL-compatible license notices.

## Git Hooks

This repo ships a tracked pre-commit hook that parses every `.github/*.yml` file. Enable it once per clone:

```bash
git config core.hooksPath .githooks
```

Install `PyYAML` if the hook reports that the module is missing.
