# Sentient Forms E2E Tests (Playwright)

## Quick Start

Run from `wp-plugin/admin-app`:

```bash
SENTIENT_RUN_WP_E2E=1 bunx playwright test --grep "@realtime-suggestions|@wp-smoke" --reporter=list
SENTIENT_RUN_WP_E2E=1 SENTIENT_RUN_LOCAL_OPENROUTER_BROWSER_SMOKE=1 bunx playwright test tests/e2e/wp-local-openrouter-browser-submission.spec.ts --reporter=list
```

## Prerequisites

1. Start the root WordPress and MariaDB services with the repository Compose configuration.
2. Install the Chromium runtime with `bunx playwright install chromium`.
3. Set `SENTIENT_RUN_WP_E2E=1` for tests that use the real WordPress runtime.
4. Set only the narrow additional flag named by a smoke test. The Direct OpenRouter browser assignment uses `SENTIENT_RUN_LOCAL_OPENROUTER_BROWSER_SMOKE=1`.

Managed-service billing and lifecycle assertions belong to CPS repository tests and cross-repository certification. Admin-browser tests do not seed CPS `/v1` tables, mutate customer credit balances, or treat billing-database queries as user-path evidence.

## Preview Host and Port

- `bun run e2e` resolves a local preview origin automatically.
- If `PREVIEW_PORT` is unset, the runner asks the operating system for a free local port.
- Set `PREVIEW_PORT=<port>` to pin the port or `PREVIEW_HOST=<host>` to override the default `127.0.0.1` host.

## Current WordPress Runtime Assignments

Current WordPress tests exercise plugin-local Action authority, canonical local mapping rows, Direct OpenRouter or managed `/v2` boundaries, source adapters, and visible WordPress outcomes. They must not revive remote Action definitions, `/v1` execution, CPS Action templates, or the retired option-backed async-job evidence model.

Reusable Form Source fixtures must be marker-owned and reset through the fixture helpers. Runtime assertions should combine the real browser path with the public evidence surface appropriate to the behavior:

- Gravity Forms entry status, notes, and meta for native effects.
- Submission Ledger and Action Log rows for cross-source or source-neutral outcomes.
- Local execution events for durable execution identity and terminal state.
- Redacted provider-boundary capture for request minimization.

The Direct OpenRouter development mock records only allowlisted outbound body fields needed for assertions. It never records request headers, credentials, provider responses, or arbitrary customer content outside the marker-owned test submission.

## Key Helpers (`utils/wp-e2e-helpers.ts`)

| Function                                                        | Purpose                                                                      |
| --------------------------------------------------------------- | ---------------------------------------------------------------------------- |
| `ensureGravityForm(title)`                                      | Create or locate a deterministic Gravity Forms fixture.                      |
| `getLocalFormMappings(formId)`                                  | Read canonical custom-table mappings for one form.                           |
| `resetLocalFormFixture({ formId, actionCodes?, actionNames? })` | Remove marker-owned mappings, actions, execution evidence, and form entries. |
| `runActionScheduler()`                                          | Process WordPress Action Scheduler work for current local async paths.       |
| `getEntrySpamStatus(id)`                                        | Read Gravity Forms entry status and spam-classification meta.                |
| `waitForGravityEntryNotes(entryId, page, predicate, options?)`  | Poll for a visible Gravity Forms note outcome.                               |
| `waitForEntryMeta(id, key, page, predicate)`                    | Poll for one native entry-meta outcome.                                      |
| `submitGravityForm(page, formId, name, email)`                  | Submit a real Gravity Forms preview through Playwright.                      |

`configureGravityActionMapping()` remains only for current compatibility-oriented adapter tests that intentionally exercise normalized option-backed settings. New executable Action tests should create canonical local Action and form-mapping rows through the admin UI or local REST API.

## Execution and Accounting Ownership

- The plugin owns Action definitions, provider routing, local execution identity, WordPress-native effects, Submission Ledger linkage, and local execution events.
- CPS owns managed `/v2` infrastructure, reservations, settlement, debt, reconciliation, and provider-side operational accounting.
- Browser tests prove the customer-visible path. CPS deterministic tests prove exact debit, reservation, and debt invariants.
- An accepted validation submission can remain accepted after a provider failure because visitor validation intentionally fails open; failure evidence must still be visible and no unauthorized Direct fallback may occur.

## Telemetry Boundary

Current telemetry is consented, allowlisted, local diagnostic logging. The plugin has no remote telemetry queue or flush path, and CPS `/v2` has no telemetry-ingestion route. Browser coverage therefore verifies consent UI behavior; PHP coverage verifies schema and minimization; CPS operational tests verify removed ingress remains unavailable and non-reflective.

## Determinism

When `SENTIENT_RUN_WP_E2E=1`, Playwright uses one worker because WordPress and Form Source fixtures share a database. Tests must reset only their marker-owned records and must not assume an empty developer database.

Use unique, non-`example.com` submission addresses. Gravity Forms rejects reserved `example.com` addresses in the local path; current tests use `example.test`.

## Network Topology

| Service                                   | Container Port | Host Port | Docker Hostname |
| ----------------------------------------- | -------------: | --------: | --------------- |
| WordPress                                 |             80 |      8080 | `wordpress`     |
| MariaDB                                   |           3306 |      3306 | `db`            |
| CPS API (managed integration only)        |           8080 |     10081 | `cps-api`       |
| CPS PostgreSQL (managed integration only) |           5432 |      5432 | `cps-db`        |

WordPress managed requests use the configured CPS `/v2` base. No current plugin path should call CPS `/v1`.

## MU Plugins

Development CORS, URL-policy, and provider mocks auto-load through the repository Compose harness. Opt out of the admin development overrides with:

- `SENTIENT_E2E_DISABLE_DEV_CORS=1`
- `SENTIENT_E2E_DISABLE_ADMIN_DEV_HOST=1`
