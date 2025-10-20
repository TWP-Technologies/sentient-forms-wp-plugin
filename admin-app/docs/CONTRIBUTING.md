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
   The app listens on <http://localhost:5173>. Task 0.6 adds a Vite proxy for embedding within `/wp-admin`.

2. Run diagnostics before committing:
   ```bash
   bun run lint
   bun run check
   bun run test
   bun run e2e
   ```

3. Keep Tailwind classes prefixed with `sf-` to avoid clashes inside WordPress admin.

4. Update `wp-plugin/AGENTS.md` whenever workflows or scripts change.

## Mock data
- `src/lib/stores/session.ts` owns temporary mock state for license/credit information.
- Replace `onMount` hydrators in route pages with real API calls as CPS endpoints become available.

## Testing tips
- Unit tests (`tests/unit`) run in Vitest with a `jsdom` environment.
- End-to-end smoke tests (`tests/e2e`) rely on Playwright; ensure browsers are installed via `bunx playwright install` when network access permits.
