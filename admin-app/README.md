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
| `bun run e2e` | Run Playwright end-to-end tests. |
| `bun run format` | Check formatting with Prettier. |
| `bun run format:write` | Auto-format files with Prettier. |
| `bun run build:wp` | Generate hashed assets and manifest in `../assets/dist`. |
| `bun run bundle:check` | Ensure production JS bundle stays within the size budget. |
| `bun run tailwind:check` | Verify all class names use the `sf-` prefix. |
| `bun run qa:full` | Run lint, type-check, tests, e2e, build, and bundle check sequentially. |

Tailwind CSS (prefixed with `sf-`) and linting are configured; see `tailwind.config.cjs` and `eslint.config.js` for details.
