# Sentient Forms E2E Tests (Playwright)

## Quick Start

```bash
# From wp-plugin/admin-app
SENTIENT_RUN_WP_E2E=1 bunx playwright test --grep "@spam-e2e|@summary-e2e" --reporter=list
```

## Prerequisites

1. **Docker stack running** — all services from root `docker-compose.yml`:
   ```bash
   docker compose --profile dev up -d
   ```
   Required containers: `wordpress`, `db`, `cps-api`, `cps-db`. Optional: `telemetry-db`.

2. **Playwright browsers installed**:
   ```bash
   bunx playwright install chromium
   ```

3. **Environment variable**: `SENTIENT_RUN_WP_E2E=1` gates all WordPress/CPS tests. Without it, WP tests are skipped.

## Preview Host/Port for Non-WP E2E

- `bun run e2e` now resolves a local preview origin automatically.
- If `PREVIEW_PORT` is unset, the runner first tries `4175`; if unavailable, it auto-selects a free fallback port.
- Set `PREVIEW_PORT=<port>` to pin a specific port. The runner fails fast when the port is invalid or cannot bind.
- Optional override: `PREVIEW_HOST=<host>` (default: `127.0.0.1`).

## How CPS Seeding Works

WP E2E tests require a CPS license, an activated site, and WordPress configured with the proxy API key. The `ensureCpsSeeded()` helper handles all of this **automatically and idempotently**:

1. **License upsert** — inserts `LIC-LOCAL-DEV` into CPS PostgreSQL (uses the `free` tier from CPS migrations)
2. **Site activation** — calls `POST http://localhost:10081/v1/license/activate` to generate a proxy API key
3. **WordPress config** — stores `proxy_api_key`, `cps_base_url` (`http://cps-api:8080/v1`), and `enable_logging` in the `sentient_forms_settings` option

> [!NOTE]
> No manual DB seeding is needed. Just call `ensureCpsSeeded()` in any test that needs CPS access.

## Test Execution Model

When `SENTIENT_RUN_WP_E2E=1`, `playwright.config.ts` forces **`workers: 1`** (serial execution). This is required because WP E2E tests share global state — the same CPS license, credit balance, and Gravity Forms instance. Parallel execution causes credit balance race conditions.

## Key Helper Functions (`utils/wp-e2e-helpers.ts`)

| Function | Purpose |
|---|---|
| `ensureCpsSeeded()` | Seed CPS DB + activate license + configure WP (call first) |
| `ensureGravityForm(title)` | Create or find a GF form by title |
| `configureGravityActionMapping(opts)` | Set per-form action mapping in WP options |
| `ensureCreditBalanceAtLeast(n)` | Apply a test credit adjustment if below threshold |
| `runActionScheduler()` | Trigger WP Action Scheduler queue processing |
| `getEntrySpamStatus(id)` | Read entry status + spam classification meta |
| `waitForEntryMeta(id, key, page, predicate)` | Poll until entry meta matches predicate |
| `fetchCreditBalance(page, apiKey)` | GET credit balance from CPS API |
| `submitGravityForm(page, formId, name, email)` | Fill and submit a GF form via Playwright |

## Action Mapping Format

`configureGravityActionMapping` stores settings as a flat object keyed by `local_mapping_id` at the top level of the GF form settings. Each action uses `trigger_hooks` (not `hooks`) to specify which GF hooks trigger it:

```typescript
configureGravityActionMapping({
  formId: 1,
  actionId: 'spam_analysis',
  centralActionId: 'spam_detection_v1',
  hooks: ['gform_after_submission'],  // stored as trigger_hooks internally
  async: true,
  markAsSpam: true,
  executionPriority: 10
});
```

## Meta Keys Reference

The async finalization path writes these GF entry meta keys:

| Meta Key | Type | Description |
|---|---|---|
| `sentient_forms_spam_classification` | `string` | `'spam'`, `'ham'`, `'reviewed'` |
| `sentient_forms_last_response` | `JSON string` | Full CPS response payload |

> [!WARNING]
> The sync execution path uses `_sentient_forms_spam_analysis` (different key, different format). E2E tests exercise the **async** path.

## Determinism & Overrides

- `CPS_FORCE_VALIDATION_CLASSIFICATION=spam` (in `dev.env`) — force spam classification for validation-block tests
- `setExecutionRequestIdOverride(id)` — pin a specific execution request ID for replay testing
- `resetE2eState()` — apply a test credit adjustment to 120 and clear execution_request_id override

## Network Topology

| Service | Container Port | Host Port | Docker Hostname |
|---|---|---|---|
| WordPress | 80 | 8080 | `wordpress` |
| CPS API | 8080 | 10081 | `cps-api` |
| CPS PostgreSQL | 5432 | 5432 | `cps-db` |
| MariaDB | 3306 | 3306 | `db` |
| Telemetry DB | 5432 | 5543 | `telemetry-db` |

WordPress connects to CPS via Docker internal URL `http://cps-api:8080/v1`. Tests connect to CPS via host URL `http://localhost:10081`.

## Other Test Tags

| Tag | File | Description |
|---|---|---|
| `@spam-e2e` | `wp-spam-e2e.spec.ts` | Core spam detection flow |
| `@summary-e2e` | `wp-summary-e2e.spec.ts` | Spam meta storage + credit debit |
| `@after-submission` | `wp-summary-e2e.spec.ts` | After-submission hook tests |
| `@data-minimization-e2e` | `wp-data-minimization-e2e.spec.ts` | Verifies mapped-field payload minimization + input manifest propagation |
| `@realtime-suggestions` | `wp-realtime-suggestions.spec.ts` | Realtime widget/runtime behavior (checkpoint, visible-only, hotlink, 429 non-blocking) |

## MU Plugins

Dev CORS and dev-host overrides auto-load when mounted via docker-compose. Opt out with:
- `SENTIENT_E2E_DISABLE_DEV_CORS=1`
- `SENTIENT_E2E_DISABLE_ADMIN_DEV_HOST=1`
