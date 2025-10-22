# Contributing to the Sentient Forms Admin SPA

## Prerequisites
- Bun 1.3.0 (recorded in `.bun-version`)
- Node 18.x LTS (fallback via `.nvmrc`)
- Access to a WordPress instance running the Sentient Forms plugin (for integration testing)

Install dependencies once network access is available:

```bash
bun install
```

## Development workflow
1. Start the dev server:
   ```bash
   bun run dev
   ```
   The dev server binds to `127.0.0.1:5173` by default; set `SENTIENT_FORMS_DEV_HOST=0.0.0.0` if you need to expose it to other containers (e.g., WordPress running in Docker). Task 0.6 adds a Vite proxy for embedding within `/wp-admin`.

2. Run the full QA sweep before committing or opening a PR:
   ```bash
   bun run qa:full
   ```
   This script executes linting, Svelte type checks, Tailwind prefix enforcement, Vitest, Playwright smoke tests, the WordPress build pipeline, and the bundle-size budget. Individual scripts remain available (`bun run lint`, `bun run tailwind:check`, `bun run bundle:check`) when you need to iterate on a specific failure locally.
- Licensing flows should always be exercised against the staging CPS API before release. Use the activation form in `/wp-admin/admin.php?page=sentient-forms-settings` to validate: successful activation populates status/tier/expiry, while errors surface the CPS error code. When CPS is unavailable, the SPA falls back to cached state and displays an info banner.

3. Keep Tailwind classes prefixed with `sf-` to avoid clashes inside WordPress admin.

4. Update `wp-plugin/AGENTS.md` whenever workflows or scripts change.

## Mock data
- `src/lib/stores/session.ts` owns temporary mock state for license/credit information.
- Replace `onMount` hydrators in route pages with real API calls as CPS endpoints become available.

## Testing tips
- Unit tests (`tests/unit`) run in Vitest with a `jsdom` environment.
- End-to-end smoke tests (`tests/e2e`) rely on Playwright; ensure browsers are installed via `bunx playwright install` when network access permits.
- CI mirrors `bun run qa:full` via `.github/workflows/admin-spa-qa.yml`; fixing breakages locally is the fastest way to keep that workflow green.
- The licensing store (`$lib/stores/license.ts`) orchestrates REST calls to `/license`, `/license/activate`, and `/license/deactivate`. When adding new CPS fields, extend the TypeScript types in `$lib/api/types.ts` and update the PHP controller response to keep parity.

## Design tokens & UI guardrails
- All color/spacing updates must be reflected in [`docs/design-tokens.md`](./design-tokens.md); pursue a design review before merging new tokens.
- Use the primitives in `src/lib/components/ui/` (e.g., `InputField`, `SelectField`, `Toggle`) instead of raw HTML to retain accessibility and theming.
- `bun run tailwind:check` fails builds if unprefixed classes or raw hex colors slip into `src/`.
- Bundle budgets are enforced via `bun run bundle:check` and the CI workflow; raise `BUNDLE_MAX_KB` in the workflow only after discussing with the maintainers.
