# WordPress.org Security Scan Evidence - 2026-06-02

## Scope

- Repository: `wp-plugin`
- Baseline scan commit: `b9c21e8 chore(production): release 0.3.0`
- Target release: `0.3.1`
- Scan mode: Codex Security scoped-path scan with threat modeling, finding discovery, validation, and attack-path review.
- Local supporting scan artifacts: `<local-scan-artifacts>\wp-plugin`

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
| SF-WPORG-2026-06-02-01 | Medium | Managed Lead Scoring profile generation could call the Sentient managed proxy after latest `sentient_managed` consent was revoked. | `includes/rest-api/controllers/class-lead-value-controller.php` | Gate managed profile-generation augmentation on latest managed-service consent, treat `revoke_managed_proxy` as a hard skip before the managed proxy client can execute, and require the latest consent row to be an explicit `setup_managed_proxy` acceptance with `managed_proxy_selected=true`. Checkout-start records after revocation do not re-enable managed proxy calls. | `tests/phpunit/test-lead-value-controller.php` |
| SF-WPORG-2026-06-02-02 | Medium | Realtime suggestions filtered hidden-field values for `suggestion_context` but still passed raw request values into local-first/CPS execution, and initially trusted client-supplied `visible_field_ids` before checking Gravity Forms field metadata. | `includes/rest-api/controllers/class-form-suggestions-controller.php` | Derive execution known values from the server-filtered suggestion context, pass those values into both local-first and CPS suggestion execution, and server-filter client-visible field IDs against form field type/storage metadata before treating values as visible. | `tests/phpunit/test-form-suggestions-controller.php` |
| SF-WPORG-2026-06-02-03 | Low | Form-actions permission callbacks and REST argument validators could validate source/form existence before the shared admin permission and nonce check. WordPress core validates and sanitizes route arguments before `permission_callback`, so object-existence checks in validators were also in scope. | `includes/rest-api/controllers/class-form-actions-controller.php` | Run `permission_callback_with_nonce()` before source/form validation and keep REST argument validators syntactic until the caller is authorized, so unauthorized callers do not receive source, form, entry, or local mapping existence signals. | `tests/phpunit/test-form-actions-controller.php` |
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

- `Tests_Lead_Value_Controller`: 11 tests, 119 assertions.
- `Tests_Form_Suggestions_Controller`: 13 tests, 73 assertions.
- `Tests_Form_Actions_Controller`: 89 tests, 531 assertions.
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

- `vendor\bin\phpunit`: passed, 623 tests, 4778 assertions, 4 skipped.
- `composer phpcs`: passed.
- `composer wporg-release-version-check`: passed with source surfaces still aligned to `0.3.0` before the Release Please `0.3.1` release PR.
- `composer wporg-source-check`: passed.
- `composer wporg-scan`: passed.
- `composer wporg-license-audit`: passed.
- `composer wporg-readme-check`: passed.
- `composer wporg-build-package`: passed.

Exact package checks:

```powershell
php scripts\scan-wporg-package.php "<workspace>\temp\2026\06\02\190020-wporg-package\sentient-forms"
php scripts\audit-wporg-licenses.php --package "<workspace>\temp\2026\06\02\190020-wporg-package\sentient-forms"
php scripts\validate-wporg-readme.php "<workspace>\temp\2026\06\02\190020-wporg-package\sentient-forms\readme.txt"
php scripts\verify-wporg-source.php "<workspace>\temp\2026\06\02\190020-wporg-package\sentient-forms"
```

Exact package results:

- Package directory: `<workspace>\temp\2026\06\02\190020-wporg-package\sentient-forms`
- ZIP candidate: `<workspace>\temp\2026\06\02\190020-wporg-package\sentient-forms-0.3.0-wporg-security-candidate.zip`
- SHA256: `28866790D03C5F3C29BF57DE3555C6CA02EC7598DE562183BF0655E78333FDAE`
- Package scan: passed.
- License audit: passed.
- Readme validation: passed by local and official readme validators.
- Source verification: passed.
- Archive blocker scan: passed; no `.git`, tests, `vendor/bin`, development lockfiles, or `node_modules` found.
- Local Plugin Check against the exact package: passed with 0 errors and 0 warnings. Supporting summary: `<workspace>\temp\2026\06\02\190020-wporg-package\plugin-check-summary.json`

Exact-package dogfood:

- Environment: Docker WordPress/Gravity Forms with the package installed at `/var/www/html/wp-content/plugins/sentient-forms-wporg-check`; active package version verified as `0.3.0`.
- Local health: `./scripts/check-local-health.sh` passed; `/v2/health` reachable, managed service key present, `/v2/billing/state` authenticated.
- Realtime suggestions package browser path: `SENTIENT_RUN_WP_E2E=1`, `SENTIENT_WP_PLUGIN_MODE=package`, `bun run --bun e2e tests/e2e/wp-realtime-suggestions.spec.ts --grep 'checkpoint gating|virtual questions are submitted' --reporter=list`; 2 passed against the `190020` package install.
- Realtime hidden-field bypass smoke: passed against the exact package; a crafted request that supplied hidden storage field `5` in `visible_field_ids` forwarded only visible field `1` into execution. Supporting artifact: `<workspace>\temp\2026\06\02\170716-browser-mcp-package-prep\realtime-hidden-visible-bypass-smoke-190020-package.json`
- Lead Scoring after managed consent revoke: passed; `generation.mode=local_readiness_grounded_profile_v1`, `llm_augmentation_status=skipped`, `llm_augmentation_reason=sentient_forms_external_service_consent_revoked`. Supporting artifact: `<workspace>\temp\2026\06\02\170716-browser-mcp-package-prep\lead-scoring-consent-revoke-smoke-190020-package.json`. PHPUnit also covers the checkout-after-revocation path so a later `managed_checkout_start` row cannot re-enable managed proxy calls.
- Realtime Q&A admin entry display: passed in Browser MCP against form `742`, entry `1534`; cards view rendered, table/json views were hidden by default, stored Q&A question and answer were visible, and the raw storage field label was not visible. Screenshot: `<workspace>\temp\2026\06\02\170716-browser-mcp-package-prep\qna-entry-display-admin-190020-package.png`. Supporting assertion artifact: `<workspace>\temp\2026\06\02\170716-browser-mcp-package-prep\qna-entry-display-admin-190020-package.json`
- Gravity Forms grid metadata non-mutation: passed against form `742`; metadata was seeded with the realtime storage field, the GF entries list was loaded through Browser MCP, and persisted metadata still included the storage field. Supporting artifact: `<workspace>\temp\2026\06\02\170716-browser-mcp-package-prep\qna-grid-meta-after-admin-render-190020-package.json`

## Reviewer Loop Status

- Codex PR review: first targeted review produced one material lead-consent finding. The branch now requires explicit `setup_managed_proxy` consent after revocation and includes a checkout-after-revocation regression.
- CodeRabbit PR review: automatic walkthrough was inspected and was in progress, not paused. No resume comment was required. Material feedback accepted: public release evidence now redacts operator-specific absolute paths, and form-actions REST argument validators now avoid pre-permission object-existence checks.
- Codex follow-up review: accepted a material realtime hidden-field bypass finding. The branch now server-filters client-visible field IDs against Gravity Forms field metadata before local/CPS realtime execution.
- Greptile PR review: targeted review confirmed the four requested hardening areas and flagged the scanner-driven per-form query trade-off. Inline comments document why these reads intentionally avoid dynamic `IN (...)` placeholder assembly for WordPress.org Plugin Check compatibility.
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
