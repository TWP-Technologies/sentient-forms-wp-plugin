# Changelog

## [0.3.1](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/compare/v0.3.0...v0.3.1) (2026-06-03)


### Bug Fixes

* **security:** harden wporg submission paths ([2881501](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/28815014fdf1d0c24fc98dc1afb039fd232f0c0b))
* **security:** harden wporg submission paths ([7a6f272](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/7a6f2722d9a3dfc9598e50a8ea192291550ab137))
* **security:** harden wporg submission paths ([8cef6c1](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/8cef6c18d5cc1085b98ee98b81544a70c1ecca55))
* **security:** harden wporg submission paths ([bcd9af0](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/bcd9af0ff5ee2814791c4b2e3ecf3ed03020a9a0))
* **security:** harden wporg submission paths ([0eeaabe](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/0eeaabe3853e0cce463c6a2936d385b5330eead9))

## [0.3.0](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/compare/v0.2.2...v0.3.0) (2026-06-01)


### Bug Fixes

* **admin:** detect security roadblocks without overblaming ([#19](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/issues/19)) ([2499f6a](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/2499f6a1f479079c64981cb6fc47b412f71d3fc0))


### Performance Improvements

* **admin:** collapse WordPress admin bootstrap requests ([#18](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/issues/18)) ([ac249fa](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/ac249fa1216fef1969dd69c530a34c0db325e995))


### Miscellaneous Chores

* **release:** request 0.3.0 ([949ee63](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/949ee63139da833c8a4cea804a9ef5fccf8bd38e))

## [0.2.2](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/compare/v0.2.1...v0.2.2) (2026-05-29)


### Bug Fixes

* **admin:** stabilize privacy apply and generate gating ([47ec91a](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/47ec91ae189185d6b74bac975d1a2a9be7eddcad))

## [0.2.1](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/compare/v0.2.0...v0.2.1) (2026-05-29)


### Bug Fixes

* **ci:** align release please tag format ([a96fc5b](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/a96fc5bf3d84a7ab5e08ee1390b5f2fdc1655445))
* **site-context:** require paid setup for AI generation ([eec653f](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/eec653f77fdd7feb9f87a350973333fb76a7fb03))

## [0.2.0](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/compare/sentient-forms-v0.1.1...sentient-forms-v0.2.0) (2026-05-28)


### Features

* **admin:** add action log context and billing plan updates ([85c2e83](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/85c2e83c6a50ea379819fc9946669f2d1beddb9a))


### Bug Fixes

* **admin:** harden realtime mapping greenlight ([a92da97](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/a92da97f956c6248f8ae3679f5582e7152fc2135))
* **admin:** keep action log actions visible ([d49a93d](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/d49a93d4c15c4d01db7f17260cbd7126ecc472e2))
* **admin:** make release asset build deterministic ([ec4ad79](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/ec4ad79a964d2312ced74c227eb0d71094dfba70))
* **ci:** authenticate release sync push ([b85a0ca](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/b85a0ca62d69326d23ccc9dc197d05df4a1cbb35))
* **ci:** force add release assets during sync ([c1da89e](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/c1da89efbc1fc64ab5b774a019c5b02b3cde760e))
* **ci:** pin plugin check runtime ([7114f5a](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/7114f5a9866c3dad59826be733e73432271cc057))
* **ci:** push release sync with app token remote ([eb7adb7](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/eb7adb7dc5abf06891c007f6195a516341e098a2))
* **managed:** harden launch licensing UI ([ebad7bc](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/ebad7bcb47d9e25752e0a47fad79ca9c11fd9db7))
* **managed:** hide service costs behind credits ([08210a5](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/08210a50b4c0da210225da90263f0f3fe5f8d6ec))
* **managed:** simplify launch licensing ([ad585b3](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/ad585b3841da3308d94ec9f8c368fa1405f9f0ee))
* **wporg:** align package scan disclosure term ([da1addb](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/da1addb473e3e80770f4ee11719d8079ab6a5397))
* **wporg:** harden production release package ([6fda8a4](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/6fda8a4aa2d5d310e0ea86969b0d9ad2fa169294))
* **wporg:** harden telemetry and custom actions ([0fa2a6c](https://github.com/TWP-Technologies/sentient-forms-wp-plugin/commit/0fa2a6c465e2a3779f49f2586d6d7230b67acac8))

## Changelog

## Unreleased
- Security: Hardened all REST API and legacy admin-ajax.php endpoints with nonce verification and added a feature flag to disable checks if necessary.
- Tooling: Added Sentient Forms PHPCS ruleset and Composer scripts; GitHub Actions now runs PHPCS (PHP 8.3) and a licensing PHPUnit smoke test on PHP 8.3/8.4.
- Feature: Completed CPS-backed licensing flow (activation/deactivation, secure credential storage, SPA status card, PHPUnit + Playwright coverage).
- Feature: Added the custom-action admin SPA (`/actions/custom`) with quota-aware create/archive/reactivate flows backed by the CPS REST proxy plus TypeScript/DTO bindings.
- UX: Surfaced per-tier quota usage in the SPA (capacity bar + friendly CPS error codes) so site owners know when they need to archive or upgrade.
- Async: Moved execution/evaluation jobs into the dedicated `sentient_forms_async` Action Scheduler group, kept metadata payloads filterable, and exposed queue stats via new WP-CLI commands (`list`, `requeue`, `purge`, `clear`).
- Async: Added configurable retry/backoff settings (REST + SPA + WP-CLI), persisted idempotent policy metadata on each job, and taught the async handler to honor the configured delays on every retry.
- Async: Added async health monitoring (admin notices, SPA health card, REST `GET /async-health`, `sentient_forms_async_health_warning` hook, and `wp sentient-forms async status`) plus queue/failure thresholds so operators can react before customers notice delays.
- Async: Implemented a dedicated `sentient_async_requests` table + CLI (`wp sentient-forms async-requests ...`) so idempotency survives beyond transient caches and retries never double-bill CPS.
- Async: Evaluation follow-ups now receive deterministic `evaluation_request_id`s, leverage the same ledger (`--record-type=evaluation` in `wp sentient-forms async-requests list`), and refuse duplicate dispatches.
- Async: Duplicate evaluation enqueue attempts now record `skipped` (non-error) with event `evaluation_duplicate_blocked`, keeping health dashboards clean while preserving an audit trail.
- Hooks: Added the `sentient_forms_async_evaluation_jobs` filter + docs so adapters/actions can describe follow-up evaluation work without touching the handler directly.
- QA: Playwright now runs against the pathname-router preview build (`bun run preview:ci`) to exercise the same assets WordPress loads; CI stubs CPS responses so CRUD flows stay deterministic.
- Docs/Infra: Published `docs/async-handler.md` (Action Scheduler contract, retry policy, consent-aware logging) and added the `sentient_forms_async_event` hook gated by telemetry/debug mode.
