# WordPress.org Security Scan Evidence - 2026-06-02

## Scope

- Repository: `wp-plugin`
- Baseline scan commit: `b9c21e8 chore(production): release 0.3.0`
- Target release: `0.3.1`
- Scan mode: Codex Security scoped-path scan with threat modeling, finding discovery, validation, and attack-path review.
- Source scan artifacts: `C:\tmp\codex-security-scans\wp-plugin`

This file is the tracked release evidence for the WordPress.org security hardening pass. Reports under `temp/` are supporting artifacts only and are not the release source of truth.

## Threat Model Summary

Sentient Forms runs inside WordPress/PHP, exposes admin UI and REST endpoints, hooks into Gravity Forms submission and entry-processing flows, stores local provider/action/execution/consent data in custom WordPress tables, and can call external services such as OpenRouter, Sentient Forms Managed Execution, optional telemetry endpoints, and administrator-configured webhooks.

Highest-value assets:

- WordPress administrator capabilities and REST/AJAX control-plane access.
- Provider credentials and Sentient Forms managed proxy credentials.
- Form submission contents, saved AI results, execution audit data, and consent records.
- Outbound request boundaries for managed execution, direct provider execution, telemetry, and webhooks.

Primary trust boundaries:

- Public Gravity Forms submissions and realtime suggestion values are attacker-controlled input.
- WordPress REST and AJAX endpoints must enforce capability checks, nonces, input validation, output escaping, and object-level authorization before revealing control-plane details.
- Gravity Forms hooks translate visitor-controlled entry data into prompts, notes, emails, webhooks, and AI execution results.
- Action Scheduler jobs must not turn public submissions into privileged operations outside configured mappings and consented provider paths.
- External services must not receive data without the matching admin configuration and disclosure/consent state.
- Generated admin assets must be local and reviewable for WordPress.org packages.

Severity calibration used by the scan:

- Critical: unauthenticated/low-privileged remote code execution, arbitrary file write leading to code execution, broad credential disclosure, or public visitor access to administrator actions.
- High: missing authorization on credential/managed-service setup, unauthenticated/low-privileged SSRF, arbitrary file read/write, or public input leading to stored administrator XSS.
- Medium: authenticated administrator-only data exposure, unsafe outbound behavior with strong preconditions, limited admin-only XSS, or telemetry/privacy bugs with prior setup conditions.
- Low: hardening issues without direct exploitability, documentation mismatches, development-only concerns, or findings requiring administrator intent without crossing a new security boundary.

## Findings And Remediation

| ID | Severity | Finding | Affected paths | Remediation | Regression coverage |
| --- | --- | --- | --- | --- | --- |
| SF-WPORG-2026-06-02-01 | Medium | Managed Lead Scoring profile generation could call the Sentient managed proxy after latest `sentient_managed` consent was revoked. | `includes/rest-api/controllers/class-lead-value-controller.php` | Gate managed profile-generation augmentation on latest managed-service consent and treat `revoke_managed_proxy` as a hard skip before the managed proxy client can execute. | `tests/phpunit/test-lead-value-controller.php` |
| SF-WPORG-2026-06-02-02 | Medium | Realtime suggestions filtered hidden-field values for `suggestion_context` but still passed raw request values into local-first/CPS execution. | `includes/rest-api/controllers/class-form-suggestions-controller.php` | Derive execution known values from the server-filtered suggestion context and pass those values into both local-first and CPS suggestion execution. | `tests/phpunit/test-form-suggestions-controller.php` |
| SF-WPORG-2026-06-02-03 | Low | Form-actions permission callbacks validated source/form existence before the shared admin permission and nonce check. | `includes/rest-api/controllers/class-form-actions-controller.php` | Run `permission_callback_with_nonce()` before source/form validation so unauthorized callers do not receive source/form existence signals. | `tests/phpunit/test-form-actions-controller.php` |
| SF-WPORG-2026-06-02-04 | Low | Realtime Q&A admin display persisted Gravity Forms grid-column metadata changes on GET/admin render paths without a nonce. | `includes/admin/class-sentient-forms-realtime-qna-admin-display.php` | Remove the metadata-pruning admin hook and render-path mutation. Keep in-memory hiding of storage columns in displayed output. | `tests/phpunit/test-realtime-qna-admin-display.php` |

## Validation

Validation was rerun on the `codex/wporg-security-hardening-0.3.1` branch before PR submission. The final public release must still be produced by Release Please as `v0.3.1`; the public `v0.3.0` ZIP predates these fixes and must not be submitted to WordPress.org after this hardening pass.

Focused DB-backed regressions:

```powershell
vendor\bin\phpunit --filter Tests_Lead_Value_Controller
vendor\bin\phpunit --filter Tests_Form_Suggestions_Controller
vendor\bin\phpunit --filter Tests_Form_Actions_Controller
vendor\bin\phpunit --filter RealtimeQnaAdminDisplayTest
```

Results:

- `Tests_Lead_Value_Controller`: 10 tests, 109 assertions.
- `Tests_Form_Suggestions_Controller`: 12 tests, 66 assertions.
- `Tests_Form_Actions_Controller`: 87 tests, 527 assertions.
- `RealtimeQnaAdminDisplayTest`: 13 tests, 102 assertions.

Full and package gates:

```powershell
vendor\bin\phpunit
composer phpcs
composer wporg-release-version-check
composer wporg-source-check
composer wporg-scan
composer wporg-license-audit
composer wporg-readme-check
composer wporg-build-package
```

Results:

- `vendor\bin\phpunit`: passed, 619 tests, 4757 assertions, 4 skipped.
- `composer phpcs`: passed.
- `composer wporg-release-version-check`: passed with source surfaces still aligned to `0.3.0` before the Release Please `0.3.1` release PR.
- `composer wporg-source-check`: passed.
- `composer wporg-scan`: passed.
- `composer wporg-license-audit`: passed.
- `composer wporg-readme-check`: passed.
- `composer wporg-build-package`: passed.

Exact package checks:

```powershell
php scripts\scan-wporg-package.php "C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\172510-wporg-package\sentient-forms"
php scripts\audit-wporg-licenses.php --package "C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\172510-wporg-package\sentient-forms"
php scripts\validate-wporg-readme.php "C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\172510-wporg-package\sentient-forms\readme.txt"
php scripts\verify-wporg-source.php "C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\172510-wporg-package\sentient-forms"
```

Exact package results:

- Package directory: `C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\172510-wporg-package\sentient-forms`
- ZIP candidate: `C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\172510-wporg-package\sentient-forms-0.3.0-wporg-security-candidate.zip`
- SHA256: `4B57F3988DD0BA775A6B2B88DB0A8EFC346B4D16DDD27723A457A1903E45DBD9`
- Package scan: passed.
- License audit: passed.
- Readme validation: passed by local and official readme validators.
- Source verification: passed.
- Archive blocker scan: passed; no `.git`, tests, `vendor/bin`, development lockfiles, or `node_modules` found.
- Local Plugin Check against the exact package: passed with 0 errors and 0 warnings. Supporting summary: `C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\172510-wporg-package\plugin-check-summary.json`

Exact-package dogfood:

- Environment: Docker WordPress/Gravity Forms with the package installed at `/var/www/html/wp-content/plugins/sentient-forms-wporg-check`; active package version verified as `0.3.0`.
- Local health: `./scripts/check-local-health.sh` passed; `/v2/health` reachable, managed service key present, `/v2/billing/state` authenticated.
- Realtime suggestions package browser path: `SENTIENT_RUN_WP_E2E=1`, `SENTIENT_WP_PLUGIN_MODE=package`, `bun run --bun e2e tests/e2e/wp-realtime-suggestions.spec.ts --grep 'checkpoint gating|virtual questions are submitted' --reporter=list`; 2 passed against the latest package install.
- Lead Scoring after managed consent revoke: passed; `generation.mode=local_readiness_grounded_profile_v1`, `llm_augmentation_status=skipped`, `llm_augmentation_reason=sentient_forms_external_service_consent_revoked`. Supporting artifact: `C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\170716-browser-mcp-package-prep\lead-scoring-consent-revoke-smoke-latest-package.json`
- Realtime Q&A admin entry display: passed in Browser MCP against form `736`, entry `1531`; cards panel rendered, table/json panels were hidden by default, stored Q&A question and answer were visible. Screenshot: `C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\170716-browser-mcp-package-prep\qna-entry-display-admin-latest-package.png`
- Gravity Forms grid metadata non-mutation: passed; metadata was seeded with the realtime storage field, the GF entries list was loaded through Browser MCP, and persisted metadata still included the storage field. Supporting artifact: `C:\Users\gavin\Work\Local Files\Sentient Forms\temp\2026\06\02\170716-browser-mcp-package-prep\qna-grid-meta-after-admin-render-latest-package.json`

## Reviewer Loop Status

- Codex PR review: pending targeted security/WP.org review after PR submission.
- CodeRabbit PR review: pending automatic review; inspect the top walkthrough/comment for paused review state and resume if needed.
- Greptile PR review: pending targeted review focused on security boundaries, WordPress.org rejection risk, and package evidence.
- Worthwhile feedback policy: accept correctness, security, WordPress.org compliance, maintainability, or test-gap feedback; reject noisy/nit feedback with a concise in-thread rationale.

## Package Candidate

The accepted public candidate must be generated by the release workflow after this branch merges and Release Please publishes `v0.3.1`. The local package candidate above is useful for PR and WordPress.org preflight evidence, but it is not the final public ZIP because its version surfaces correctly remain `0.3.0` until the Release Please release PR.

The final release evidence must add:

- GitHub release URL for `v0.3.1`.
- Release ZIP filename and SHA256.
- Release manifest asset.
- Release workflow or `WordPress.org Package Preflight` Plugin Check URL.
- Downloaded artifact hash verification result.

## Remaining Risks

- WordPress.org review remains manual and can raise new packaging or disclosure issues beyond this scan.
- Exact Gravity Forms browser-path dogfood depends on a local or staged WordPress environment with Gravity Forms available. Local package dogfood passed for this branch, but the final `v0.3.1` release artifact still needs download/hash verification after Release Please publishes it.
- Administrator-configured webhooks remain intentionally supported outbound destinations; they rely on existing URL-policy and admin-configuration boundaries rather than being disabled.
