# Sentient Forms E2E (Playwright) Notes

- **Environment**: `SENTIENT_RUN_WP_E2E=1` to enable WordPress/CPS flows. Prefer `--workers=1` until parallel stability is proven.
- **Credits**: Tests assume credits are available. Use `scripts/cps-topup-credits.sh 200` or call `resetE2eState()` helper to top up and clear forced execution_request_id.
- **Determinism**:
  - `CPS_FORCE_VALIDATION_CLASSIFICATION=spam` (dev.env) for validation-block.
  - Option override `sentient_forms_forced_execution_request_id` can be set via `setExecutionRequestIdOverride`.
- **CPS base URL**: When overriding `SENTIENT_FORMS_CPS_BASE_URL`, include `/v1` (e.g., `http://localhost:10081/v1`) to avoid 404s on telemetry endpoints.
- **Telemetry**:
  - Requires `telemetry-db` container up; helper `isTelemetryDbHealthy()` checks it.
  - Ingest needs `x-api-key` header and `site_url` + RFC3339 `timestamp`.
- **Admin SPA smoke (optional)**: Default is **skipped** to avoid headless flakes. Set `SENTIENT_RUN_WP_SPA_SMOKE=1` to run the lightweight mount check in local runs/CI.
- **CPS base URL**: When overriding `SENTIENT_FORMS_CPS_BASE_URL`, include `/v1` (e.g., `http://localhost:10081/v1`). A global Playwright guard now fails fast if `/v1` is missing.
- **MU plugins**: Dev CORS and dev-host overrides are enabled by default when mounted via docker-compose. Set `SENTIENT_E2E_DISABLE_DEV_CORS=1` or `SENTIENT_E2E_DISABLE_ADMIN_DEV_HOST=1` to opt out.
