# Sentient Forms WordPress Plugin

The Sentient Forms plugin connects WordPress form builders (Gravity Forms first) to the Sentient Forms Central Proxy Server (CPS). Form events make authenticated REST calls to CPS where licensing, credits, and LLM execution live; WordPress remains a thin client that stores local wiring and renders the admin app.

## Feature Snapshot (November 2025)
- **Custom Actions SPA** – The new `/actions/custom` route inside the SvelteKit admin app lets administrators list, create, archive, and reactivate CPS-backed custom actions. The view enforces the tier-based quotas defined in CPS and shows a live capacity bar so users know when they are approaching the per-license cap.
- **Quota-aware notifications** – All mutations return the CPS `{action, quota}` envelope; the SPA surfaces quota errors inline and the PHP REST proxy forwards CPS error codes (e.g., `quota_exceeded`, `cps_missing_proxy_key`).
- **Telemetry settings** – A dedicated SPA route plus WordPress REST controller exposes the CPS `/v1/sites/telemetry` endpoint, allowing opt-in/out flows to stay in sync with CPS and gate downstream logging.

## Development Workflow
1. **WordPress stack** – Boot the local Docker compose environment (`docker compose up -d`) or point the plugin at an existing WordPress instance running Gravity Forms. Activate the plugin with `wp plugin activate sentient-forms` and set the CPS credentials under *Sentient Forms → Settings*.
2. **Admin SPA** – From `admin-app/`, run `bun install` once, then:
   - `bun run dev` for live development (hash router served via Vite).
   - `bun run build:wp` before committing UI changes (copies hashed assets into `assets/dist/`).
   - `bun run preview:ci` spins up a pathname-router preview on port 4173 so Playwright can run against a deterministic build.
3. **Testing** – `bun run qa:full` mirrors the GitHub Actions workflow: lint → type-check → rune guard → Tailwind prefix check → Vitest → Playwright → build/bundle budget. Playwright now relies on the preview server (step 2) instead of the wp-admin iframe mock, ensuring the hash-based navigation limitations do not block CI. When you need to exercise the wp-admin/Gravity Forms flows against the Docker WordPress stack, export `SENTIENT_RUN_WP_E2E=1` (prefer `--workers=1` until parallel stability is proven).
4. **PHP tooling** – Run `composer install`, regenerate the class map with `php build/generate-class-map.php` after adding classes, and execute `vendor/bin/phpunit` for the REST controllers (custom actions, telemetry, licensing, credits).

### Git hooks

This repo also ships a tracked pre-commit hook that parses every `.github/*.yml` file. Enable it once per clone:

```
git config core.hooksPath .githooks
```

Install `PyYAML` (`pip install pyyaml`) if the hook reports that the module is missing.

## Key Paths & Docs
- `AGENTS.md` – cross-repo guardrails plus SPA-specific coding standards.
- `docs/custom-actions-*` – source-of-truth for quotas, telemetry consent, and schema contracts consumed by the admin app.
- `scripts/run-full-qa.sh` – orchestrates PHP linting/tests plus SPA QA; extend this script whenever new QA steps land.
