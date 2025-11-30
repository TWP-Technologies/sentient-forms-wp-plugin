# Changelog

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
