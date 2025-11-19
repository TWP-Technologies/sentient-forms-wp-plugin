#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_ROOT="$(cd "${PLUGIN_ROOT}/.." && pwd)"

info() {
  printf '\n[%s] %s\n' "$(date '+%H:%M:%S')" "$*"
}

smoke_staging() {
  if [[ -z "${SENTIENT_FORMS_STAGING_URL:-}" ]]; then
    info "Skipping staging CPS smoke test (SENTIENT_FORMS_STAGING_URL not set)"
    return
  fi

  local health_url="${SENTIENT_FORMS_STAGING_URL%/}/v1/health"
  info "Validating staging CPS availability at ${health_url}"
  curl --fail --silent "${health_url}" >/dev/null
  info "Staging CPS responded successfully"
}

run_cps() {
  if [[ "${SKIP_CPS:-}" == "1" ]]; then
    info "Skipping CPS checks (SKIP_CPS=1)"
    return
  fi

  local cps_dir="$WORKSPACE_ROOT/Sentient-Forms-Central-Proxy-Server"
  if [[ -d "$cps_dir" ]]; then
    info "Running CPS workspace checks"
    pushd "$cps_dir" >/dev/null
    cargo fmt --all -- --check
    cargo clippy --workspace --all-targets -- -D warnings
    cargo test --workspace
    local database_url="${DATABASE_URL:-postgres://postgres:change_me_local_only@localhost:5432/sentient_forms}"
    sqlx migrate run \
      --source "$cps_dir/crates/cps-db/migrations" \
      --database-url "$database_url" \
      --dry-run
    popd >/dev/null
  else
    info "Skipping CPS checks (Sentient-Forms-Central-Proxy-Server not present)"
  fi
}

run_wp_php() {
  info "Running WordPress plugin PHP checks"
  pushd "$PLUGIN_ROOT" >/dev/null
  composer install --no-interaction --prefer-dist
  composer phpcs
  vendor/bin/phpunit
  popd >/dev/null
}

run_admin_spa() {
  info "Running admin SPA QA suite"
  pushd "$PLUGIN_ROOT/admin-app" >/dev/null
  bun install --frozen-lockfile
  bunx playwright install --with-deps
  local run_wp_e2e="${RUN_WP_E2E:-0}"
  if [[ "$run_wp_e2e" != "1" ]]; then
    info "Skipping wp-admin Playwright flows (set RUN_WP_E2E=1 to enable)"
  else
    info "Including wp-admin Playwright flows (requires Docker WordPress stack)"
  fi
  SENTIENT_RUN_WP_E2E="$run_wp_e2e" bun run qa:full
  popd >/dev/null
}

run_cps
run_wp_php
run_admin_spa
smoke_staging

info "All checks completed successfully"
