# Sentient Forms Admin SPA

This directory hosts the SvelteKit-based admin experience for the Sentient Forms WordPress plugin. All JavaScript tooling runs on [Bun](https://bun.sh/).

## Quick start

```bash
bun install
bun run dev
```

> **Note:** Network access is required to install dependencies. In restricted environments, run the commands once connectivity is available.

## Scripts

| Command | Description |
| --- | --- |
| `bun run dev` | Start the SvelteKit dev server on port 5173. |
| `bun run build` | Create a production build (Task 0.6 wires artifacts into WordPress). |
| `bun run preview` | Preview the production build locally. |
| `bun run lint` | Lint the project using ESLint (Flat config). |
| `bun run check` | Run `svelte-check` for type diagnostics. |
| `bun run test` | Execute Vitest unit tests once. |
| `bun run test:watch` | Run Vitest in watch mode. |
| `bun run --bun e2e` | Run Playwright end-to-end tests through the Bun package-script entrypoint backed by the Node wrapper. |
| `bun run format` | Check formatting with Prettier. |
| `bun run format:write` | Auto-format files with Prettier. |
| `bun run build:wp` | Generate hashed assets and manifest in `../assets/dist`. |
| `bun run bundle:check` | Ensure production JS bundle stays within the size budget. |
| `bun run tailwind:check` | Verify all class names use the `sf-` prefix. |
| `../scripts/run-full-qa.sh` | Run the plugin/CPS/admin QA entrypoint, including Playwright through the Bun package-script entrypoint backed by the Node wrapper. |

Tailwind CSS (prefixed with `sf-`) and linting are configured; see `tailwind.config.cjs` and `eslint.config.js` for details.

## End-to-end tests

Playwright tests interact with the Dockerized WordPress stack (`docker compose up -d` from the repo root). Before running `bun run --bun e2e` or `../scripts/run-full-qa.sh`, ensure:

- Environment variables `SENTIENT_WP_ADMIN_USER` and `SENTIENT_WP_ADMIN_PASS` match a valid wp-admin account (defaults to `sentient_admin` / `sentient_admin`).
- The helper script `scripts/wp-playwright-fixtures.php` can run via `docker compose exec wordpress wp eval-file …`; the e2e suite seeds a Gravity Forms form/action mapping automatically using that script.
- The admin email verification prompt can be dismissed (the tests handle it, but leave the modal enabled so the helper logic exercises the flow).
