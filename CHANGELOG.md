# Changelog

## Unreleased
- Security: Hardened all REST API and legacy admin-ajax.php endpoints with nonce verification and added a feature flag to disable checks if necessary.
- Tooling: Added Sentient Forms PHPCS ruleset and Composer scripts; GitHub Actions now runs PHPCS (PHP 8.3) and a licensing PHPUnit smoke test on PHP 8.3/8.4.
- Feature: Completed CPS-backed licensing flow (activation/deactivation, secure credential storage, SPA status card, PHPUnit + Playwright coverage).
