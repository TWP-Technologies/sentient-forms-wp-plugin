# Changelog

## Unreleased
- Security: Hardened all REST API and legacy admin-ajax.php endpoints with nonce verification and added a feature flag to disable checks if necessary.
- Tooling: Added Sentient Forms PHPCS ruleset and Composer scripts; GitHub Actions now runs PHPCS (PHP 8.3) and a licensing PHPUnit smoke test on PHP 8.3/8.4.
- Feature: Completed CPS-backed licensing flow (activation/deactivation, secure credential storage, SPA status card, PHPUnit + Playwright coverage).
- Feature: Added the custom-action admin SPA (`/actions/custom`) with quota-aware create/archive/reactivate flows backed by the CPS REST proxy plus TypeScript/DTO bindings.
- UX: Surfaced per-tier quota usage in the SPA (capacity bar + friendly CPS error codes) so site owners know when they need to archive or upgrade.
- QA: Playwright now runs against the pathname-router preview build (`bun run preview:ci`) to exercise the same assets WordPress loads; CI stubs CPS responses so CRUD flows stay deterministic.
- Docs/Infra: Published `docs/async-handler.md` (Action Scheduler contract, retry policy, consent-aware logging) and added the `sentient_forms_async_event` hook gated by telemetry/debug mode.
