#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WORKSPACE_ROOT="$(cd "${PLUGIN_ROOT}/.." && pwd)"
DEV_ENV="${WORKSPACE_ROOT}/dev.env"

info() {
  printf '\n[%s] %s\n' "$(date '+%H:%M:%S')" "$*"
}

load_env() {
  if [[ -f "${DEV_ENV}" ]]; then
    # shellcheck disable=SC1090
    source "${DEV_ENV}"
  fi
  export DATABASE_URL="${DATABASE_URL:-postgres://postgres:postgres@localhost:5432/sentient_forms}"
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
    sqlx migrate run \
      --source "$cps_dir/crates/cps-db/migrations" \
      --database-url "$DATABASE_URL" \
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
  composer encoding-check
  composer phpcs
  php scripts/validate-workflow-path-filters.php
  php scripts/validate-release-workflow.php
  vendor/bin/phpunit
  popd >/dev/null
}

run_admin_spa() {
  info "Running admin SPA QA suite"
  pushd "$PLUGIN_ROOT/admin-app" >/dev/null
  bun install --frozen-lockfile
  bunx playwright install --with-deps

  # Fast checks
  info "SPA fast checks (lint/check/svelte-guard/tailwind/vitest)"
  bunx svelte-kit sync
  bun run lint
  bun run check
  bun run svelte:guard
  bun run tailwind:check
  bun run test

  # Decide whether to run the slow Playwright/build step.
  local run_spa_e2e="${RUN_SPA_E2E:-${CI:+1}}"
  run_spa_e2e="${run_spa_e2e:-0}"
  if [[ "$run_spa_e2e" == "1" ]]; then
    info "SPA E2E/build (Playwright smoke + build:wp + bundle:check)"
    # Prebuild once; preview:serve will reuse.
    bun run build:wp
    bun run --bun e2e:smoke
    bun run bundle:check
  else
    info "Skipping SPA E2E/build (set RUN_SPA_E2E=1 or CI=1 to enable)"
    info "You can run smoke E2E only via: RUN_SPA_SMOKE=1 bun run qa:smoke"
  fi

  popd >/dev/null
}

load_env
run_cps
run_wp_php
run_admin_spa
smoke_staging

info "All checks completed successfully"
